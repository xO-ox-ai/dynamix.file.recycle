(function () {
    'use strict';

    var RT = window.__recycleRuntime;
    if (!RT) return;
    if (!RT.enabled) {
        console.info('[Dynamix File Recycle Bin] disabled by plugin settings');
        return;
    }
    if (!/(?:^|\/)Browse(?:\/|$)/.test(window.location.pathname)) return;

    var button = null;
    var busy = false;
    var toastEl = null;
    var toastTimer = 0;

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
            if (result.status !== 200 || !result.json || result.json.ok !== true || !result.json.inspection_token) {
                throw new Error(apiError(result));
            }
            return { path: item.path, token: result.json.inspection_token };
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
                        throw new Error(apiError(result));
                    }
                    moved++;
                });
            });
        }, Promise.resolve()).then(function () { return moved; });
    }

    function setBusy(state, label) {
        busy = state;
        if (!button) return;
        button.disabled = state || selectedItems().length === 0;
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
        var items = selectedItems();
        if (items.length === 0) {
            showToast(t('noSelection'), true);
            setBusy(false, t('btnBatch'));
            return;
        }

        setBusy(true, t('stateChecking'));
        mapLimit(items, 4, inspect).then(function (inspected) {
            var confirmed = window.confirm(formatCount(t('confirmBatch'), inspected.length));
            if (!confirmed) {
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
            refreshList();
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
        button.className = 'dfm_control extra recycle-batch-action';
        button.value = t('btnBatch');
        button.disabled = true;
        button.setAttribute('title', t('btnTitle'));
        button.addEventListener('click', handleBatchRecycle);
        deleteButton.parentNode.insertBefore(button, deleteButton.nextSibling);
        console.info('[Dynamix File Recycle Bin] batch control inserted after Delete', RT.version || 'unknown');
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
