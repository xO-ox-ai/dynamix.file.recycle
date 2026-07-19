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
 *   4.  Stable DOM identity per itemId (local hash; never trusted by server).
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
    var STATE_ERROR = 'error';
    var STATE_DONE = 'done';

    var generations = {};             // itemId -> generation counter
    var DFM_WRAPPED = false;
    var observer = null;
    var observeReentry = 0;           // guards against our own DOM writes
    var initialized = false;

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
    // is to wrap loadList()'s Browse.php response and decorate the returned
    // HTML before DFM appends it. MutationObserver is recovery-only fallback.
    function getTable() {
        // The DFM table can be a real <table> tbody or a generic container;
        // we look for the most common parents in order.
        return document.querySelector('table.indexer')
            || null;
    }

    function getRows(container) {
        if (!container) return [];
        // DFM rows typically carry data-path or data-id attributes. We pick
        // any <tr> with a path-like attribute.
        return container.querySelectorAll('tbody:not(.tablesorter-infoOnly) > tr');
    }

    // Extract the absolute path of a row. We try every known attribute DFM
    // might use, then fall back to the row's first link.
    function rowPath(row) {
        if (!row) return null;
        var action = row.querySelector('i[id^="row_"][data][type]');
        return action ? action.getAttribute('data') : null;
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

    // ---------- button creation ------------------------------------------
    function makeButton(id, absPath) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'recycle-action';
        b.setAttribute('data-item-id', id);
        b.setAttribute('data-recycle-path', absPath);
        b.setAttribute('aria-label', t('btnTitle'));
        b.setAttribute('title', t('btnTitle'));
        b.setAttribute('data-state', STATE_IDLE);
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

    function decorateRows(container) {
        // Re-entry guard: we run inside MutationObserver; ignore our own writes.
        observeReentry++;
        try {
            container = container || getTable();
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
        var isInteractive = (s === STATE_IDLE || s === STATE_ERROR);
        if (isInteractive) {
            btn.removeAttribute('disabled');
        } else {
            btn.setAttribute('disabled', 'disabled');
        }
        if (s === STATE_CHECKING) {
            btn.setAttribute('title', t('stateChecking'));
        } else if (s === STATE_RUNNING) {
            btn.setAttribute('title', t('stateRunning'));
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
    // inspect -> explicit confirmation -> signed recycle sequence clean.
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

    function apiError(res) {
        var json = res && res.json;
        var code = json && json.code;
        var errors = RT.i18n && RT.i18n.errors;
        if (code && errors && errors[code]) return errors[code];
        return (json && json.error) || ('HTTP ' + ((res && res.status) || 0));
    }

    // ---------- click handler (event delegation) -------------------------
    // Two-step protocol: inspect is non-mutating; recycle requires the short-
    // lived signed inspection token returned for the same inode and metadata.
    function handleClick(btn) {
        var id = btn.getAttribute('data-item-id');
        var path = btn.getAttribute('data-recycle-path') || '';
        if (!id || !path) return;
        // Inspect first. Unsupported virtual, cache, remote, removable and USB
        // paths fail before the user is asked to confirm anything.
        var gen = (generations[id] || 0) + 1;
        generations[id] = gen;
        setState(btn, STATE_CHECKING);

        apiRequest({ action: 'inspect', path: path })
            .then(function (res) {
                if (generations[id] !== gen) return; // stale
                if (res.status !== 200 || !res.json || !res.json.ok || !res.json.inspection_token) {
                    throw new Error(apiError(res));
                }
                var confirmed = false;
                try {
                    confirmed = typeof window.confirm === 'function'
                        && window.confirm(t('confirmRecycle') + '\n\n' + res.json.path);
                } catch (_) { confirmed = false; }
                if (!confirmed) {
                    setState(btn, STATE_IDLE);
                    return;
                }
                setState(btn, STATE_RUNNING);
                return apiRequest({
                    action: 'recycle',
                    path: path,
                    inspection_token: res.json.inspection_token
                });
            })
            .then(function (secondRes) {
                if (!secondRes) return;            // stage 1 already finalised
                if (generations[id] !== gen) return; // stale
                if (secondRes.json && secondRes.json.ok) {
                    onRecycleDone(btn, gen);
                } else {
                    var message = apiError(secondRes) || t('errorMessage');
                    setState(btn, STATE_ERROR, message);
                    showToast(message, true);
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
        showToast(t('doneMessage'), false);
        setTimeout(function () {
            if (generations[btn.getAttribute('data-item-id')] === gen) {
                if (typeof window.loadList === 'function') window.loadList();
            }
        }, 300);
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
    function decorateHtml(html) {
        if (typeof html !== 'string' || html.indexOf('id="row_') === -1) return html;
        var table = document.createElement('table');
        table.innerHTML = html;
        decorateRows(table);
        return table.innerHTML;
    }

    function wrapDfmFunctions() {
        if (DFM_WRAPPED) return true;
        var orig = window.loadList;
        if (typeof orig !== 'function') return false;
        if (orig.__recycleWrapped) return DFM_WRAPPED = true;
        var wrapper = function () {
            var jq = window.jQuery;
            var originalGet = jq && jq.get;
            if (typeof originalGet !== 'function') return orig.apply(this, arguments);
            jq.get = function (url, data, success, dataType) {
                if (url === '/webGui/include/Browse.php' && typeof success === 'function') {
                    var originalSuccess = success;
                    success = function (html) {
                        var args = Array.prototype.slice.call(arguments);
                        args[0] = decorateHtml(html);
                        return originalSuccess.apply(this, args);
                    };
                }
                return originalGet.call(this, url, data, success, dataType);
            };
            try {
                return orig.apply(this, arguments);
            } finally {
                jq.get = originalGet;
            }
        };
        wrapper.__recycleWrapped = true;
        window.loadList = wrapper;
        DFM_WRAPPED = true;
        return DFM_WRAPPED;
    }

    // ---------- MutationObserver fallback (rule 2.3) --------------------
    function startObserver() {
        if (observer) return;
        var container = getTable();
        if (!container) {
            observer = new MutationObserver(function () {
                if (getTable()) {
                    observer.disconnect();
                    observer = null;
                    startObserver();
                    decorateRows();
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
            return;
        }
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
        if (initialized) return;
        initialized = true;
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
    }

    if (document.readyState === 'loading') {
        // readyState becomes "interactive" before DOMContentLoaded callbacks;
        // DFM functions are defined by then, so loadList is wrapped before its
        // initial jQuery-ready invocation.
        document.addEventListener('readystatechange', function () {
            if (document.readyState === 'interactive') boot();
        });
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
