/*
 * recycle.js — front-end for the Dynamix File Recycle Bin plugin.
 *
 * Implementation follows the 10-rule design guide (see docs/DESIGN.md):
 *
 *   1.  Button is part of the list render output, not an after-add.
 *   2.  Prefer a formal extension interface; fall back to wrapping DFM's own
 *       render / action function (doAction / doActions). Last resort:
 *       MutationObserver as a reconciliation layer.
 *   3.  Button is statically present (data-state machine), never toggled
 *       on/off by async eligibility checks.
 *   4.  Stable DOM identity per itemId (HMAC token from the server).
 *   5.  Idempotent ensureActionButton(): safe to call many times.
 *   6.  Fixed-footprint placeholder so column widths never shift.
 *   7.  Event delegation on the table container (survives row re-renders).
 *   8.  Async race control via per-item generation counters.
 *   9.  On error, button stays in place and becomes "error" state.
 *   10. Overall pipeline: decorate-before-commit -> placeholder ->
 *       delegate -> click-time check -> state update only.
 *
 * No external dependencies. ES5-safe for old browsers; uses native Promises
 * and fetch (both available in Unraid's bundled browsers).
 */
(function () {
    'use strict';

    var RT = window.__recycleRuntime;
    if (!RT) return;                 // plugin not properly bootstrapped.
    if (!RT.enabled) return;          // master switch off; nothing to do.
    if (!RT.browse) return;           // we only operate on the Browse page.

    var STATE_IDLE = 'idle';
    var STATE_CHECKING = 'checking';
    var STATE_RUNNING = 'running';
    var STATE_BLOCKED = 'blocked';
    var STATE_ERROR = 'error';
    var STATE_DONE = 'done';

    var generations = {};             // itemId -> generation counter
    var DFM_WRAPPED = false;
    var observer = null;
    var observeReentry = 0;           // guards against our own DOM writes

    // ---------- i18n ------------------------------------------------------
    function t(key) {
        var v = (RT.i18n && RT.i18n[key]) || key;
        return v;
    }

    // ---------- toast (lightweight, non-blocking) -------------------------
    var toastEl = null;
    var toastTimer = 0;
    function showToast(message, isError) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'recycle-toast';
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = message;
        toastEl.classList.toggle('is-error', !!isError);
        toastEl.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toastEl.classList.remove('is-visible');
        }, 3500);
    }

    // ---------- DFM integration ------------------------------------------
    // The DFM table is server-side rendered. The most reliable stable hook
    // is to wrap `window.doAction` / `window.doActions` so that AFTER DFM
    // updates the DOM, we decorate before paint. If wrapping fails, we fall
    // back to a MutationObserver (rule 2.3).
    function getTable() {
        // The DFM table can be a real <table> tbody or a generic container;
        // we look for the most common parents in order.
        return document.querySelector('.file_table tbody')
            || document.querySelector('#file_table tbody')
            || document.querySelector('table.filemanager tbody')
            || document.querySelector('.file_table')
            || document.querySelector('#file_table')
            || document.querySelector('[data-file-table]')
            || null;
    }

    function getRows(container) {
        if (!container) return [];
        // DFM rows typically carry data-path or data-id attributes. We pick
        // any <tr> with a path-like attribute.
        var rows = container.querySelectorAll(
            'tr[data-path], tr[data-id], tr[data-href], tr[data-item-id]'
        );
        if (rows.length) return rows;
        // Fallback: any <tr> that has at least one cell and lives under a
        // table that looks like the file list.
        return container.querySelectorAll('tbody > tr');
    }

    // Extract the absolute path of a row. We try every known attribute DFM
    // might use, then fall back to the row's first link.
    function rowPath(row) {
        if (!row) return null;
        var attrs = ['data-path', 'data-id', 'data-href', 'data-item-id', 'data-url'];
        for (var i = 0; i < attrs.length; i++) {
            var v = row.getAttribute(attrs[i]);
            if (v) {
                // data-href is often URL-encoded; normalise.
                if (v.indexOf('%2F') !== -1 || v.indexOf('%5C') !== -1) {
                    try { v = decodeURIComponent(v); } catch (_) {}
                }
                return v;
            }
        }
        var link = row.querySelector('a[href]');
        if (link) return link.getAttribute('href');
        return null;
    }

    // Build a stable itemId (opaque token). We use the absolute path hashed
    // with a tiny FNV-1a — the server re-verifies on the request anyway.
    function stableId(absPath) {
        if (!absPath) return null;
        var hash = 0x811c9dc5;
        for (var i = 0; i < absPath.length; i++) {
            hash ^= absPath.charCodeAt(i);
            hash = (hash + ((hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24))) >>> 0;
        }
        return 'r' + hash.toString(36) + '_' + absPath.length.toString(36);
    }

    function pathInScope(absPath) {
        if (!absPath) return false;
        var roots = RT.scopeRoots || [];
        for (var i = 0; i < roots.length; i++) {
            var r = roots[i];
            if (absPath === r || absPath.indexOf(r + '/') === 0) return true;
        }
        return false;
    }

    // ---------- button creation ------------------------------------------
    function makeButton(id, absPath) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'recycle-action';
        b.setAttribute('data-item-id', id);
        b.setAttribute('data-recycle-path', absPath);
        b.setAttribute('aria-label', t('btnTitle'));
        b.setAttribute('title', t('btnTitle'));
        b.setAttribute('data-state', pathInScope(absPath) ? STATE_IDLE : STATE_BLOCKED);
        if (!pathInScope(absPath)) {
            b.setAttribute('title', t('btnTitleBlocked'));
            b.setAttribute('aria-disabled', 'true');
        }
        var icon = document.createElement('span');
        icon.className = 'recycle-icon';
        icon.setAttribute('aria-hidden', 'true');
        b.appendChild(icon);
        return b;
    }

    function ensureSlot(row) {
        // Slot lives in the LAST cell of the row so the layout reads naturally.
        var cells = row.children;
        var last = cells[cells.length - 1];
        if (!last) {
            // Row without cells; skip rather than guess.
            return null;
        }
        if (!last.classList.contains('recycle-cell-reserve')) {
            last.classList.add('recycle-cell-reserve');
        }
        var slot = last.querySelector('.recycle-slot');
        if (!slot) {
            slot = document.createElement('span');
            slot.className = 'recycle-slot';
            last.appendChild(slot);
        }
        return slot;
    }

    // Idempotent injection. Safe to call any number of times.
    function ensureButton(row) {
        if (!row || row.getAttribute('data-recycle-injected') === '1') return;
        var path = rowPath(row);
        if (!path) return;
        var id = stableId(path);
        var slot = ensureSlot(row);
        if (!slot) return;
        if (slot.querySelector('.recycle-action[data-item-id="' + cssEscape(id) + '"]')) {
            // Already there (e.g. observer caught a partial re-render).
            row.setAttribute('data-recycle-injected', '1');
            return;
        }
        var btn = makeButton(id, path);
        slot.appendChild(btn);
        row.setAttribute('data-recycle-injected', '1');
    }

    function decorateRows() {
        // Re-entry guard: we run inside MutationObserver; ignore our own writes.
        observeReentry++;
        try {
            var container = getTable();
            var rows = getRows(container);
            for (var i = 0; i < rows.length; i++) {
                ensureButton(rows[i]);
            }
            // If a row was replaced (data-recycle-injected lost), it will be
            // re-decorated here.
        } finally {
            observeReentry--;
        }
    }

    // ---------- state machine --------------------------------------------
    function setState(btn, s, message) {
        if (!btn) return;
        btn.setAttribute('data-state', s);
        var isInteractive = (s === STATE_IDLE || s === STATE_ERROR || s === STATE_DONE);
        if (isInteractive) {
            btn.removeAttribute('disabled');
        } else {
            btn.setAttribute('disabled', 'disabled');
        }
        if (s === STATE_CHECKING) {
            btn.setAttribute('title', t('stateChecking'));
        } else if (s === STATE_RUNNING) {
            btn.setAttribute('title', t('stateRunning'));
        } else if (s === STATE_BLOCKED) {
            btn.setAttribute('title', t('btnTitleBlocked'));
        } else if (s === STATE_ERROR) {
            btn.setAttribute('title', t('btnTitleError') + (message ? ': ' + message : ''));
        } else if (s === STATE_IDLE) {
            btn.setAttribute('title', t('btnTitle'));
        } else if (s === STATE_DONE) {
            btn.setAttribute('title', message || t('doneMessage'));
        }
    }

    // ---------- API client -----------------------------------------------
    // Returns a Promise that resolves with {status, json}. The caller decides
    // what to do with non-ok responses; this keeps the two-step recycle
    // protocol (need_confirm -> confirm=1) clean.
    function apiRequest(payload) {
        var body = new URLSearchParams();
        body.append('action', payload.action);
        for (var k in payload) {
            if (k === 'action') continue;
            body.append(k, payload[k]);
        }
        // CSRF: Unraid's auto_prepend verifies $_POST['csrf_token'] for every
        // POST. Include it in the body (NOT in the URL, never in JS source
        // code as a hardcoded literal).
        if (RT.csrfToken) {
            body.append('csrf_token', RT.csrfToken);
        }
        return fetch(RT.apiBase, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (resp) {
            // Try to parse JSON regardless of status; some error bodies are JSON.
            return resp.json().then(function (json) {
                return { status: resp.status, json: json };
            }).catch(function () {
                return { status: resp.status, json: { ok: false, error: 'HTTP ' + resp.status } };
            });
        });
    }

    function formatBytes(n) {
        if (!n || n < 0) return '0 B';
        var units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        var i = Math.floor(Math.log(n) / Math.log(1024));
        if (i >= units.length) i = units.length - 1;
        var v = n / Math.pow(1024, i);
        return (i === 0 ? v.toString() : v.toFixed(i === 1 ? 1 : 2)) + ' ' + units[i];
    }

    // ---------- click handler (event delegation) -------------------------
    // Two-step recycle protocol:
    //   1. POST action=recycle  (no confirm)
    //        -> 200 ok            : same-fs move done, all good
    //        -> 202 need_confirm  : cross-fs detected, ask the user
    //        -> 4xx/5xx           : hard error, surface to user
    //   2. Only after the user confirms the cross-fs prompt do we re-POST
    //      with confirm=1, which actually performs the copy+rm.
    function handleClick(btn) {
        var id = btn.getAttribute('data-item-id');
        var path = btn.getAttribute('data-recycle-path') || '';
        if (!id || !path) return;
        if (btn.getAttribute('data-state') === STATE_BLOCKED) return;

        // First-stage generic confirmation (always asked).
        try {
            if (typeof window.confirm === 'function' && !window.confirm(t('confirmRecycle'))) {
                return;
            }
        } catch (_) {}

        // Bump generation so stale responses are ignored across both stages.
        var gen = (generations[id] || 0) + 1;
        generations[id] = gen;
        setState(btn, STATE_CHECKING);

        apiRequest({ action: 'recycle', path: path })
            .then(function (res) {
                if (generations[id] !== gen) return; // stale

                // Stage 1 success: same-fs move already done.
                if (res.status === 200 && res.json && res.json.ok) {
                    onRecycleDone(btn, gen);
                    return;
                }

                // Stage 1 needs cross-fs confirmation.
                if ((res.status === 202 || (res.json && res.json.need_confirm)) && res.json) {
                    var info = res.json;
                    var sizeText = info.is_dir
                        ? (t('crossFsDirPrompt') + ' (' + formatBytes(info.size) + ')')
                        : (t('crossFsFilePrompt') + ' (' + formatBytes(info.size) + ')');
                    var promptMsg =
                        t('crossFsTitle') + '\n\n' +
                        info.original_path + '\n\n' +
                        sizeText + '\n' +
                        t('crossFsBody') + '\n\n' +
                        t('crossFsQuestion');
                    var confirmed = false;
                    try {
                        confirmed = typeof window.confirm === 'function' && window.confirm(promptMsg);
                    } catch (_) { confirmed = false; }
                    if (!confirmed) {
                        // User declined: revert to idle, no error.
                        if (generations[id] === gen) setState(btn, STATE_IDLE);
                        return;
                    }
                    // Stage 2: actually do the cross-fs move.
                    setState(btn, STATE_RUNNING);
                    return apiRequest({ action: 'recycle', path: path, confirm: '1' });
                }

                // Anything else is a hard error.
                var err = new Error((res.json && res.json.error) || ('HTTP ' + res.status));
                throw err;
            })
            .then(function (secondRes) {
                if (!secondRes) return;            // stage 1 already finalised
                if (generations[id] !== gen) return; // stale
                if (secondRes.json && secondRes.json.ok) {
                    onRecycleDone(btn, gen);
                } else {
                    setState(btn, STATE_ERROR, (secondRes.json && secondRes.json.error) || t('errorMessage'));
                    showToast((secondRes.json && secondRes.json.error) || t('errorMessage'), true);
                }
            })
            .catch(function (err) {
                if (generations[id] !== gen) return; // stale
                setState(btn, STATE_ERROR, err && err.message);
                showToast((err && err.message) || t('errorMessage'), true);
            });
    }

    function onRecycleDone(btn, gen) {
        setState(btn, STATE_DONE, t('doneMessage'));
        var row = btn.closest('tr');
        if (row && row.style) {
            row.style.opacity = '0.45';
        }
        showToast(t('doneMessage'), false);
        setTimeout(function () {
            if (generations[btn.getAttribute('data-item-id')] === gen) {
                setState(btn, STATE_IDLE);
            }
        }, 2500);
    }

    function onClickEvent(event) {
        var target = event.target;
        if (!target || !target.closest) return;
        var btn = target.closest('.recycle-action[data-item-id]');
        if (!btn) return;
        // Ensure the button still lives in our document.
        if (!document.body.contains(btn)) return;
        event.preventDefault();
        event.stopPropagation();
        handleClick(btn);
    }

    // ---------- wrap DFM (rule 2.2) --------------------------------------
    function wrapDfmFunctions() {
        if (DFM_WRAPPED) return true;
        var wrappedSomething = false;
        var names = ['doAction', 'doActions', 'refreshList', 'renderList'];
        for (var i = 0; i < names.length; i++) {
            var n = names[i];
            if (typeof window[n] !== 'function') continue;
            (function (name) {
                var orig = window[name];
                if (!orig || orig.__recycleWrapped) return;
                var wrapper = function () {
                    var rv = orig.apply(this, arguments);
                    try { decorateRows(); } catch (_) {}
                    return rv;
                };
                wrapper.__recycleWrapped = true;
                window[name] = wrapper;
                wrappedSomething = true;
            }(n));
        }
        if (wrappedSomething) {
            DFM_WRAPPED = true;
        }
        return DFM_WRAPPED;
    }

    // ---------- MutationObserver fallback (rule 2.3) --------------------
    function startObserver() {
        if (observer) return;
        var container = getTable();
        if (!container) return;
        observer = new MutationObserver(function (mutations) {
            if (observeReentry > 0) return;     // we caused this; ignore
            var external = false;
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
                    external = true;
                    break;
                }
            }
            if (external) decorateRows();
        });
        observer.observe(container, { childList: true, subtree: true });
    }

    // ---------- helpers ---------------------------------------------------
    function cssEscape(s) {
        if (typeof window.CSS !== 'undefined' && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(s);
        }
        return String(s).replace(/[^a-zA-Z0-9_-]/g, function (c) {
            return '\\' + c;
        });
    }

    function boot() {
        // Wait for the table to exist before attaching observer / wrapping.
        function tryBoot(retries) {
            var container = getTable();
            wrapDfmFunctions();
            decorateRows();
            if (!DFM_WRAPPED) {
                startObserver();
            } else {
                // Even when wrapped, also keep the observer as a safety net
                // for full-table re-renders that bypass the wrapped function.
                startObserver();
            }
            if (!container && retries > 0) {
                setTimeout(function () { tryBoot(retries - 1); }, 200);
            }
        }
        tryBoot(25); // up to 5 seconds

        // Event delegation on document (survives any re-render).
        document.addEventListener('click', onClickEvent, true);
        // Also cover keyboard activation for accessibility.
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            var btn = event.target && event.target.closest && event.target.closest('.recycle-action[data-item-id]');
            if (btn) {
                event.preventDefault();
                handleClick(btn);
            }
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
