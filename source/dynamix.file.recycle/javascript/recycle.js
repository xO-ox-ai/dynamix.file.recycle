(function () {
    'use strict';

    var RT = window.__recycleRuntime;
    if (!RT) return;
    if (!RT.enabled) {
        console.info('[Dynamix File Recycle Bin] disabled by plugin settings');
        return;
    }
    if (!/(?:^|\/)Main\/Browse\/?$/.test(window.location.pathname)) return;

    var button = null;
    var busy = false;
    var toastEl = null;
    var toastTimer = 0;

    function currentBrowsePath() {
        try {
            return new URLSearchParams(window.location.search || '').get('dir') || '';
        } catch (_) {
            return '';
        }
    }

    function knownUnsupportedBrowsePath() {
        var path = currentBrowsePath().replace(/\/+$/, '') || '/';
        return path === '/' || path === '/mnt' || path === '/boot' || path.indexOf('/boot/') === 0
            || path === '/mnt/user' || path.indexOf('/mnt/user/') === 0
            || path === '/mnt/user0' || path.indexOf('/mnt/user0/') === 0
            || /^\/mnt\/cache[^/]*(?:\/|$)/.test(path)
            || path === '/mnt/disks' || path.indexOf('/mnt/disks/') === 0
            || path === '/mnt/remotes' || path.indexOf('/mnt/remotes/') === 0;
    }

    function removeLegacyRowControls() {
        var legacy = document.querySelectorAll('.recycle-slot, .recycle-action');
        for (var index = 0; index < legacy.length; index++) {
            var element = legacy[index];
            var target = element.classList && element.classList.contains('recycle-slot')
                ? element : element.parentNode;
            if (target && target.classList && target.classList.contains('recycle-slot')) {
                target.parentNode.removeChild(target);
            } else if (element.parentNode) {
                element.parentNode.removeChild(element);
            }
        }
    }

    function t(key) {
        return RT.i18n && typeof RT.i18n[key] === 'string' ? RT.i18n[key] : key;
    }

    function formatCount(template, count) {
        return String(template).replace('%d', String(count));
    }

    function makeElement(tag, className, text) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    }

    function confirmInspectedItems(items) {
        if (RT.testMode === true && typeof window.__recycleConfirmReview === 'function') {
            return Promise.resolve(window.__recycleConfirmReview(items));
        }
        return new Promise(function (resolve) {
            var overlay = makeElement('div', 'recycle-confirm-overlay');
            var dialog = makeElement('div', 'recycle-confirm-dialog');
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-labelledby', 'recycle-confirm-title');

            var header = makeElement('div', 'recycle-confirm-header');
            var title = makeElement('h2', '', t('confirmTitle'));
            title.id = 'recycle-confirm-title';
            var close = makeElement('button', 'recycle-confirm-close', '\u00d7');
            close.type = 'button';
            close.setAttribute('aria-label', t('confirmCancel'));
            close.setAttribute('title', t('confirmCancel'));
            header.appendChild(title);
            header.appendChild(close);

            var summary = makeElement(
                'p',
                'recycle-confirm-summary',
                formatCount(t('confirmSummary'), items.length)
            );
            var details = makeElement('details', 'recycle-confirm-details');
            details.open = true;
            var detailsSummary = makeElement(
                'summary',
                '',
                formatCount(t('confirmList'), items.length)
            );
            var scroll = makeElement('div', 'recycle-confirm-scroll');
            scroll.setAttribute('role', 'list');

            items.forEach(function (item) {
                var row = makeElement('div', 'recycle-confirm-item');
                row.setAttribute('role', 'listitem');
                var sourceLabel = makeElement('strong', 'recycle-confirm-label', t('confirmSource'));
                var source = makeElement('code', 'recycle-confirm-path', item.path);
                var destinationLabel = makeElement('strong', 'recycle-confirm-label', t('confirmDestination'));
                var destination = makeElement('code', 'recycle-confirm-path', item.recycleDirectory);
                row.appendChild(sourceLabel);
                row.appendChild(source);
                row.appendChild(destinationLabel);
                row.appendChild(destination);
                scroll.appendChild(row);
            });
            details.appendChild(detailsSummary);
            details.appendChild(scroll);

            var actions = makeElement('div', 'recycle-confirm-actions');
            var cancel = makeElement('button', '', t('confirmCancel'));
            cancel.type = 'button';
            var approve = makeElement('button', 'recycle-confirm-approve', t('confirmApprove'));
            approve.type = 'button';
            actions.appendChild(cancel);
            actions.appendChild(approve);

            dialog.appendChild(header);
            dialog.appendChild(summary);
            dialog.appendChild(details);
            dialog.appendChild(actions);
            overlay.appendChild(dialog);

            var finish = function (approved) {
                document.removeEventListener('keydown', onKeyDown);
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                resolve(approved);
            };
            var onKeyDown = function (event) {
                if (event.key === 'Escape') finish(false);
            };
            close.addEventListener('click', function () { finish(false); });
            cancel.addEventListener('click', function () { finish(false); });
            approve.addEventListener('click', function () { finish(true); });
            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) finish(false);
            });
            document.addEventListener('keydown', onKeyDown);
            document.body.appendChild(overlay);
            if (typeof approve.focus === 'function') approve.focus();
        });
    }

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
        toastTimer = setTimeout(function () { toastEl.classList.remove('is-visible'); }, 4000);
    }

    function apiRequest(payload) {
        var body = new URLSearchParams();
        Object.keys(payload).forEach(function (key) { body.append(key, payload[key]); });
        if (RT.csrfToken) body.append('csrf_token', RT.csrfToken);
        return fetch(RT.apiBase, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (json) {
                return { status: response.status, json: json };
            });
        });
    }

    function apiError(result) {
        var json = result && result.json;
        var code = json && json.code;
        var errors = RT.i18n && RT.i18n.errors;
        if (code && errors && errors[code]) return errors[code];
        return json && json.error ? json.error : t('errorMessage');
    }

    function selectedItems() {
        var selected = document.querySelectorAll('i[id^="check_"].fa-check-square-o');
        var items = [];
        for (var index = 0; index < selected.length; index++) {
            var check = selected[index];
            if (check.id === 'check_0') continue;
            var suffix = check.id.substring('check_'.length);
            var action = document.getElementById('row_' + suffix);
            if (!action) continue;
            var path = action.getAttribute('data');
            if (path) items.push({ path: path, type: action.getAttribute('type') || '' });
        }
        return items;
    }

    function mapLimit(values, limit, worker) {
        var results = new Array(values.length);
        var next = 0;
        function run() {
            var index = next++;
            if (index >= values.length) return Promise.resolve();
            return worker(values[index], index).then(function (result) {
                results[index] = result;
                return run();
            });
        }
        var runners = [];
        for (var i = 0; i < Math.min(limit, values.length); i++) runners.push(run());
        return Promise.all(runners).then(function () { return results; });
    }

    function inspect(item) {
        return apiRequest({ action: 'inspect', path: item.path }).then(function (result) {
            if (result.status !== 200 || !result.json || result.json.ok !== true
                || !result.json.inspection_token
                || typeof result.json.path !== 'string'
                || typeof result.json.recycle_directory !== 'string') {
                throw new Error(apiError(result));
            }
            return {
                path: result.json.path,
                token: result.json.inspection_token,
                recycleDirectory: result.json.recycle_directory
            };
        });
    }

    function recycleInspected(items) {
        var moved = 0;
        return items.reduce(function (chain, item) {
            return chain.then(function () {
                return apiRequest({
                    action: 'recycle',
                    path: item.path,
                    inspection_token: item.token
                }).then(function (result) {
                    if (result.status !== 200 || !result.json || result.json.ok !== true) {
                        var error = new Error(apiError(result));
                        error.recycleMoved = moved;
                        throw error;
                    }
                    moved++;
                });
            });
        }, Promise.resolve()).then(function () { return moved; });
    }

    function setBusy(state, label) {
        busy = state;
        if (!button) return;
        var blocked = knownUnsupportedBrowsePath();
        button.classList.toggle('extra', !blocked);
        button.disabled = blocked || state || selectedItems().length === 0;
        button.setAttribute('title', blocked ? t('btnTitleBlocked') : t('btnTitle'));
        button.value = label || t('btnBatch');
    }

    function refreshList() {
        busy = false;
        if (button) {
            button.value = t('btnBatch');
            button.disabled = true;
        }
        if (typeof window.loadList === 'function') window.loadList();
    }

    function handleBatchRecycle() {
        if (busy) return;
        if (knownUnsupportedBrowsePath()) {
            setBusy(false, t('btnBatch'));
            return;
        }
        var items = selectedItems();
        if (items.length === 0) {
            showToast(t('noSelection'), true);
            setBusy(false, t('btnBatch'));
            return;
        }

        setBusy(true, t('stateChecking'));
        mapLimit(items, 4, inspect).then(function (inspected) {
            return confirmInspectedItems(inspected).then(function (confirmed) {
                if (!confirmed) return null;
                return inspected;
            });
        }).then(function (inspected) {
            if (inspected === null) {
                setBusy(false, t('btnBatch'));
                return null;
            }
            if (button) button.value = t('stateRunning');
            return recycleInspected(inspected);
        }).then(function (moved) {
            if (moved === null || moved === undefined) return;
            showToast(formatCount(t('doneBatch'), moved), false);
            refreshList();
        }).catch(function (error) {
            showToast(error && error.message ? error.message : t('errorMessage'), true);
            if (Number(error && error.recycleMoved || 0) > 0) {
                refreshList();
            } else {
                setBusy(false, t('btnBatch'));
            }
        });
    }

    function installButton() {
        if (button && document.body.contains(button)) return true;
        var container = document.getElementById('buttons');
        if (!container) return false;
        var controls = container.querySelectorAll('input[type="button"]');
        var deleteButton = null;
        for (var i = 0; i < controls.length; i++) {
            if ((controls[i].getAttribute('onclick') || '').indexOf('doActions(1,') >= 0) {
                deleteButton = controls[i];
                break;
            }
        }
        if (!deleteButton) return false;

        button = document.createElement('input');
        button.type = 'button';
        button.id = 'recycle-selected-button';
        button.className = 'dfm_control recycle-batch-action';
        if (!knownUnsupportedBrowsePath()) button.classList.add('extra');
        button.value = t('btnBatch');
        button.disabled = true;
        button.setAttribute('title', knownUnsupportedBrowsePath() ? t('btnTitleBlocked') : t('btnTitle'));
        button.addEventListener('click', handleBatchRecycle);
        deleteButton.parentNode.insertBefore(button, deleteButton);
        console.info('[Dynamix File Recycle Bin] batch control inserted before Delete', RT.version || 'unknown');
        return true;
    }

    function boot(retries) {
        removeLegacyRowControls();
        if (installButton()) return;
        if (retries > 0) {
            setTimeout(function () { boot(retries - 1); }, 100);
        } else {
            console.error('[Dynamix File Recycle Bin] DFM #buttons/Delete control was not found');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(50); });
    } else {
        boot(50);
    }
})();
