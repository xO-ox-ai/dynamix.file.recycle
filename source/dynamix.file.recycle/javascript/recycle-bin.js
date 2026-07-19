(function () {
    'use strict';

    var runtime = window.DynamixFileRecycleBinRuntime;
    var body = document.getElementById('recycle-bin-body');
    var notice = document.getElementById('recycle-bin-notice');
    var summary = document.getElementById('recycle-bin-summary');
    var refreshButton = document.getElementById('recycle-bin-refresh');
    var settingsButton = document.getElementById('recycle-bin-settings');
    if (!runtime || !body || !notice || !summary || !refreshButton || !settingsButton) return;

    var catalog = runtime.i18n && typeof runtime.i18n === 'object' ? runtime.i18n : {};
    function t(key, fallback) { return typeof catalog[key] === 'string' ? catalog[key] : fallback; }

    document.querySelectorAll('[data-i18n]').forEach(function (element) {
        var key = element.getAttribute('data-i18n');
        element.textContent = t(key, element.textContent);
    });

    function showNotice(kind, message) {
        notice.className = 'recycle-bin-notice is-visible is-' + kind;
        notice.textContent = message;
    }

    function clearNotice() {
        notice.className = 'recycle-bin-notice';
        notice.textContent = '';
    }

    function request(action, fields) {
        var payload = new URLSearchParams();
        payload.append('action', action);
        payload.append('csrf_token', runtime.csrfToken || '');
        Object.keys(fields || {}).forEach(function (key) { payload.append(key, fields[key]); });
        return fetch(runtime.apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: payload.toString()
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (json) {
                if (!response.ok || !json || json.ok !== true) {
                    throw new Error(json && json.error ? json.error : t('loadFailed', 'Unable to load recycle records.'));
                }
                return json;
            });
        });
    }

    function humanSize(bytes) {
        var value = Number(bytes || 0);
        var units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        var unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        return (unit === 0 ? String(Math.round(value)) : value.toFixed(value >= 10 ? 1 : 2)) + ' ' + units[unit];
    }

    function appendCell(row, value, className) {
        var cell = document.createElement('td');
        if (className) cell.className = className;
        cell.textContent = value;
        row.appendChild(cell);
        return cell;
    }

    function actionButton(label, icon, action, id) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'recycle-bin-action recycle-bin-' + action;
        button.setAttribute('data-action', action);
        button.setAttribute('data-id', id);
        var iconElement = document.createElement('i');
        iconElement.className = 'fa ' + icon;
        iconElement.setAttribute('aria-hidden', 'true');
        button.appendChild(iconElement);
        button.appendChild(document.createTextNode(' ' + label));
        return button;
    }

    function render(payload) {
        body.replaceChildren();
        var items = Array.isArray(payload.items) ? payload.items : [];
        var totals = payload.totals || {};
        summary.textContent = String(Number(totals.items || 0)) + ' ' + t('activeItems', 'active items')
            + ' - ' + String(items.length) + ' ' + t('records', 'records')
            + ' - ' + (payload.history_enabled ? t('historyOn', 'history enabled') : t('historyOff', 'history disabled'));

        if (items.length === 0) {
            var emptyRow = document.createElement('tr');
            var emptyCell = appendCell(emptyRow, t('empty', 'Recycle Bin is empty.'), 'recycle-bin-empty');
            emptyCell.colSpan = 7;
            body.appendChild(emptyRow);
            return;
        }

        items.forEach(function (item) {
            var row = document.createElement('tr');
            row.setAttribute('data-id', item.id || '');
            var state = String(item.state || '');
            var stateCell = appendCell(row, '', 'recycle-bin-state');
            var badge = document.createElement('span');
            badge.className = 'recycle-bin-badge is-' + state;
            badge.textContent = t(state, state);
            stateCell.appendChild(badge);

            var original = String(item.original_path || '');
            var parts = original.split('/');
            var name = parts[parts.length - 1] || original;
            var nameCell = appendCell(row, name);
            if (Number(item.is_dir || 0) === 1) {
                var directory = document.createElement('span');
                directory.className = 'hint recycle-bin-directory';
                directory.textContent = t('directory', 'directory');
                nameCell.appendChild(directory);
            }
            appendCell(row, original, 'recycle-bin-path');
            appendCell(row, String(item.volume || ''), 'recycle-bin-path');
            appendCell(row, humanSize(item.size));
            appendCell(row, item.deleted_at ? new Date(Number(item.deleted_at) * 1000).toLocaleString() : '');

            var actions = appendCell(row, '', 'recycle-bin-actions');
            if (state === 'active' && item.management_enabled && item.recycle_exists) {
                actions.appendChild(actionButton(t('restore', 'Restore'), 'fa-undo', 'restore', item.id));
                actions.appendChild(actionButton(t('purge', 'Permanently delete'), 'fa-trash', 'purge', item.id));
            } else if (state === 'active' && !item.management_enabled) {
                actions.textContent = t('managementDisabled', 'Management disabled');
            } else if (state === 'active' && !item.recycle_exists) {
                actions.textContent = t('missing', 'File missing');
            } else {
                actions.textContent = String(item.purged_reason || '');
            }
            body.appendChild(row);
        });
    }

    function load() {
        refreshButton.disabled = true;
        showNotice('progress', t('loading', 'Loading recycle records...'));
        request('list', { state: 'all', limit: '2000' }).then(function (payload) {
            render(payload);
            clearNotice();
        }).catch(function (error) {
            showNotice('error', error.message);
        }).finally(function () {
            refreshButton.disabled = false;
        });
    }

    body.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('button[data-action]');
        if (!button) return;
        var action = button.getAttribute('data-action');
        var question = action === 'restore'
            ? t('confirmRestore', 'Restore this item to its original path?')
            : t('confirmPurge', 'Permanently delete this item? This cannot be undone.');
        if (!window.confirm(question)) return;
        button.disabled = true;
        request(action, { id: button.getAttribute('data-id') }).then(function () {
            showNotice('success', action === 'restore' ? t('restoreDone', 'Item restored.') : t('purgeDone', 'Item permanently deleted.'));
            return request('list', { state: 'all', limit: '2000' });
        }).then(render).catch(function (error) {
            showNotice('error', error.message);
            button.disabled = false;
        });
    });

    refreshButton.addEventListener('click', load);
    settingsButton.addEventListener('click', function () { window.location.assign(runtime.settingsUrl); });
    load();
})();
