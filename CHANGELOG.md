# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows a calver scheme: `YYYY.MM.DD{a,b,c,...}`.

## [2026.07.19a] — 2026-07-19

First public release.

### Added
- "Move to Recycle Bin" button injected into the Dynamix File Manager Browse
  page via the official `Menu='Buttons'` channel — **no Unraid core file is
  modified**.
- Per-volume `.RecycleBin` directory for `/mnt/disk*` data drives and ZFS
  datasets. `/mnt/user` and `/mnt/cache*` are intentionally out of scope for
  this version.
- Original owner/group/mode preserved on every recycled item so restores are
  byte-identical.
- **Two-step recycle protocol with cross-filesystem confirmation.** When the
  source and its destination `.RecycleBin` live on different filesystems, the
  server returns `202 need_confirm` with the item size; the front-end shows a
  warning dialog and only proceeds with `confirm=1` after the user explicitly
  opts in. Same-filesystem moves remain atomic and require no extra prompt.
- SQLite history store (WAL mode) with `state` field
  (`active | restored | purged`) so Tools → Recycle Bin can show both current
  and historical items.
- Settings page (English + 中文) covering: feature toggle, log level, log
  retention, age-based eviction, capacity threshold eviction (percent or
  absolute, LRU), scheduled cleanup interval, optional auto-empty cron,
  history toggle, language picker.
- Tools → Recycle Bin browser page for browsing / restoring / purging /
  batch-selecting items.
- `scripts/recycle-maintain` invoked by Unraid cron (every hour, throttled
  by `interval_hours`) for age + capacity + log + history maintenance.
- CSRF protection delegated to Unraid's `auto_prepend`; the plugin never
  re-implements CSRF.
- Bilingual UI and documentation (English + 中文) following the Unraid system
  language.
- Build script (`tools/build.sh`) that produces the `.txz` package and
  patches the `.plg` MD5 placeholder for CA submission.

### Security
- All write operations require an authenticated admin session.
- All paths are normalised and confined to their original volume; `..`
  traversal is rejected.
- Operations on the recycle bin itself (or any path inside it) are rejected.
- Front-end item tokens are HMAC-signed to prevent path forgery; the server
  always re-validates on every call.

## [Unreleased]

_Nothing yet._

