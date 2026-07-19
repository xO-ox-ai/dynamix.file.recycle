#!/usr/bin/env python3
"""Cheap PHP sanity check (no PHP runtime needed).

Uses a small state machine to strip line comments, block comments, single-
quoted strings and double-quoted strings, then counts {}, (), [].

Also checks:
  - the file starts with `<?php`
  - namespace declaration is well-formed
"""
import os
import re
import sys


def strip(src):
    out = []
    i = 0
    n = len(src)
    state = 'code'  # code | line | block | sq | dq
    while i < n:
        c = src[i]
        nxt = src[i + 1] if i + 1 < n else ''
        if state == 'code':
            if c == '/' and nxt == '/':
                state = 'line'; i += 2; continue
            if c == '/' and nxt == '*':
                state = 'block'; i += 2; continue
            if c == '#':
                state = 'line'; i += 1; continue
            if c == "'":
                state = 'sq'; i += 1; continue
            if c == '"':
                state = 'dq'; i += 1; continue
            out.append(c); i += 1; continue
        if state == 'line':
            if c == '\n':
                state = 'code'; out.append(c)
            i += 1; continue
        if state == 'block':
            if c == '*' and nxt == '/':
                state = 'code'; i += 2; continue
            i += 1; continue
        if state == 'sq':
            if c == '\\':
                i += 2; continue
            if c == "'":
                state = 'code'
            i += 1; continue
        if state == 'dq':
            if c == '\\':
                i += 2; continue
            if c == '"':
                state = 'code'
            i += 1; continue
    return ''.join(out)


def main():
    root = os.path.join(os.path.dirname(__file__), '..', 'source')
    problems = []
    for r, _, files in os.walk(root):
        for f in files:
            if not f.endswith('.php'):
                continue
            path = os.path.join(r, f)
            src = open(path, 'r', encoding='utf-8').read()
            stripped = strip(src)
            counts = {ch: stripped.count(ch) for ch in '{}()[]'}
            if counts['{'] != counts['}']:
                problems.append("%s: brace open=%d close=%d" % (path, counts['{'], counts['}']))
            if counts['('] != counts[')']:
                problems.append("%s: paren open=%d close=%d" % (path, counts['('], counts[')']))
            if counts['['] != counts[']']:
                problems.append("%s: bracket open=%d close=%d" % (path, counts['['], counts[']']))
            if not src.lstrip().startswith('<?php'):
                problems.append("%s: missing <?php at top" % path)
            if 'namespace ' in src and not re.search(r'^namespace\s+[\w\\]+;', src, re.M):
                problems.append("%s: namespace decl malformed" % path)
    if problems:
        print("PROBLEMS:")
        for p in problems:
            print("  " + p)
        sys.exit(1)
    print("OK: brace / paren / bracket balance and namespace sanity for all PHP files")


if __name__ == '__main__':
    main()
