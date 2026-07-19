(function () {
    'use strict';

    var runtime = window.DynamixFileRecycleBinRuntime;
    var body = document.getElementById('recycle-bin-body');
    var notice = document.getElementById('recycle-bin-notice');
    var summary = document.getElementById('recycle-bin-summary');
    var refreshButton = document.getElementById('recycle-bin-refresh');
    var settingsButton = document.getElementById('recycle-bin-settings');
    var selectAll = document.getElementById('recycle-bin-select-all');
    var sortControl = document.getElementById('recycle-bin-sort');
    var orderControl = document.getElementById('recycle-bin-order');
    var pageSizeControl = document.getElementById('recycle-bin-page-size');
    var restoreButton = document.getElementById('recycle-bin-batch-restore');
    var purgeButton = document.getElementById('recycle-bin-batch-purge');
    var selectedLabel = document.getElementById('recycle-bin-selected');
    var paginationTop = document.getElementById('recycle-bin-pagination-top');
    var paginationBottom = document.getElementById('recycle-bin-pagination-bottom');
    if (!runtime || !body || !notice || !summary || !refreshButton || !settingsButton
        || !selectAll || !sortControl || !orderControl || !pageSizeControl
        || !restoreButton || !purgeButton || !selectedLabel || !paginationTop || !paginationBottom) return;

    var catalog = runtime.i18n && typeof runtime.i18n === 'object' ? runtime.i18n : {};
    var page = 1;
    var totalPages = 1;
    var totalRecords = 0;
    var items = [];
    var selected = new Set();
    var loading = false;

    function t(key, fallback) { return typeof catalog[key] === 'string' ? catalog[key] : fallback; }
    function format(key, fallback, values) {
        var value = t(key, fallback);
        (values || []).forEach(function (replacement) { value = value.replace('%d', String(replacement)); });
        return value;
    }

    document.querySelectorAll('[data-i18n]').forEach(function (element) {
        var key = element.getAttribute('data-i18n');
        element.textContent = t(key, element.textContent);
    });
    selectAll.title = t('selectAll', 'Select all actionable items on this page');

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
        var value = Math.max(0, Number(bytes || 0));
        var units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        var unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        var digits = unit === 0 ? 0 : (value >= 100 ? 0 : (value >= 10 ? 1 : 2));
        return value.toFixed(digits) + ' ' + units[unit];
    }

    function appendCell(row, value, className) {
        var cell = document.createElement('td');
        if (className) cell.className = className;
        cell.textContent = value;
        row.appendChild(cell);
        return cell;
    }

    function itemName(item) {
        if (item.display_name) return String(item.display_name);
        var original = String(item.original_path || '');
        var parts = original.split('/');
        return parts[parts.length - 1] || original;
    }

    function actionable(item) {
        return String(item.state || '') === 'active'
            && item.management_enabled === true
            && item.recycle_exists === true;
    }

    function detail(item) {
        var state = String(item.state || '');
        if (state === 'active' && !item.management_enabled) {
            return t('managementDisabled', 'Management is disabled for the owning disk or dataset. Enable it in Settings.');
        }
        if (state === 'active' && !item.recycle_exists) {
            return t('missing', 'The recycled file is missing, possibly because .RecycleBin was changed manually.');
        }
        if (state === 'active') return t('detailActive', 'The item can be restored or permanently deleted.');
        if (state === 'restored') return t('detailRestored', 'Restored to the original path; this row is retained as history.');
        if (state === 'pending') return t('detailPending', 'The recycle operation was interrupted before final confirmation.');
        if (state === 'restoring') return t('detailRestoring', 'Restore is in progress or awaiting interruption recovery.');
        if (state === 'purging') return t('detailPurging', 'Permanent deletion is in progress or awaiting interruption recovery.');
        if (state === 'purged') {
            var reasons = {
                manual: ['detailPurgedManual', 'Permanently deleted by a user.'],
                age: ['detailPurgedAge', 'Permanently deleted by the configured age limit.'],
                capacity: ['detailPurgedCapacity', 'Permanently deleted to enforce the configured capacity limit.'],
                empty: ['detailPurgedEmpty', 'Permanently deleted by an empty-bin operation.'],
                missing: ['detailPurgedMissing', 'Marked purged because the tracked recycle file was already missing.'],
                recovered_purge: ['detailRecoveredPurge', 'An interrupted permanent deletion was recovered and finalized.']
            };
            var reason = reasons[String(item.purged_reason || '')] || ['detailPurgedManual', 'Permanently deleted and no longer recoverable.'];
            return t(reason[0], reason[1]);
        }
        return t('detailUnknown', 'This record is not currently actionable.');
    }

    function originalPathCell(row, item) {
        var original = String(item.original_path || '');
        var cell = appendCell(row, '', 'recycle-bin-path');
        if (String(item.state || '') !== 'restored' || item.original_exists !== true) {
            cell.textContent = original;
            return;
        }
        var slash = original.lastIndexOf('/');
        var browsePath = Number(item.is_dir || 0) === 1 ? original : (slash > 0 ? original.slice(0, slash) : '/');
        var link = document.createElement('a');
        link.href = '/Main/Browse?dir=' + encodeURIComponent(browsePath);
        link.title = t('browseOriginal', 'Open this location in the built-in file browser');
        link.textContent = original;
        cell.appendChild(link);
    }

    function updateSelection() {
        var actionableIds = items.filter(actionable).map(function (item) { return String(item.id || ''); });
        selected.forEach(function (id) { if (actionableIds.indexOf(id) < 0) selected.delete(id); });
        var count = selected.size;
        selectedLabel.textContent = format('selected', '%d selected', [count]);
        restoreButton.disabled = loading || count === 0;
        purgeButton.disabled = loading || count === 0;
        selectAll.disabled = loading || actionableIds.length === 0;
        selectAll.checked = actionableIds.length > 0 && actionableIds.every(function (id) { return selected.has(id); });
        selectAll.indeterminate = count > 0 && !selectAll.checked;
    }

    function renderRows(payload) {
        body.replaceChildren();
        items = Array.isArray(payload.items) ? payload.items : [];
        selected.clear();
        var totals = payload.totals || {};
        summary.textContent = String(Number(totals.items || 0)) + ' ' + t('activeItems', 'active items')
            + ' - ' + String(totalRecords) + ' ' + t('records', 'records')
            + ' - ' + (payload.history_enabled ? t('historyOn', 'history enabled') : t('historyOff', 'history disabled'));

        if (items.length === 0) {
            var emptyRow = document.createElement('tr');
            var emptyCell = appendCell(emptyRow, t('empty', 'Recycle Bin is empty.'), 'recycle-bin-empty');
            emptyCell.colSpan = 8;
            body.appendChild(emptyRow);
            updateSelection();
            return;
        }

        items.forEach(function (item) {
            var row = document.createElement('tr');
            var id = String(item.id || '');
            row.setAttribute('data-id', id);
            var selectCell = appendCell(row, '', 'recycle-bin-select-column');
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'recycle-bin-row-select';
            checkbox.value = id;
            checkbox.disabled = !actionable(item);
            checkbox.title = t('selectAll', 'Select this actionable item');
            selectCell.appendChild(checkbox);

            var state = String(item.state || '');
            var stateCell = appendCell(row, '', 'recycle-bin-state');
            var badge = document.createElement('span');
            badge.className = 'recycle-bin-badge is-' + state;
            badge.textContent = t(state, state);
            stateCell.appendChild(badge);

            var nameCell = appendCell(row, itemName(item));
            if (Number(item.is_dir || 0) === 1) {
                var directory = document.createElement('span');
                directory.className = 'hint recycle-bin-directory';
                directory.textContent = t('directory', 'directory');
                nameCell.appendChild(directory);
            }
            originalPathCell(row, item);
            appendCell(row, String(item.volume || ''), 'recycle-bin-path');
            appendCell(row, humanSize(item.size), 'recycle-bin-size');
            appendCell(row, item.deleted_at ? new Date(Number(item.deleted_at) * 1000).toLocaleString() : '', 'recycle-bin-time');
            appendCell(row, detail(item), 'recycle-bin-details');
            body.appendChild(row);
        });
        updateSelection();
    }

    function renderPagination(container) {
        container.replaceChildren();
        var previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'recycle-bin-page-button';
        previous.title = t('previous', 'Previous page');
        previous.disabled = loading || page <= 1;
        previous.innerHTML = '<i class="fa fa-chevron-left" aria-hidden="true"></i>';
        previous.addEventListener('click', function () { if (page > 1) { page--; load(); } });
        var status = document.createElement('span');
        status.className = 'recycle-bin-page-status';
        status.textContent = format('pageStatus', 'Page %d of %d', [page, totalPages]);
        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'recycle-bin-page-button';
        next.title = t('next', 'Next page');
        next.disabled = loading || page >= totalPages;
        next.innerHTML = '<i class="fa fa-chevron-right" aria-hidden="true"></i>';
        next.addEventListener('click', function () { if (page < totalPages) { page++; load(); } });
        container.append(previous, status, next);
    }

    function setLoading(value) {
        loading = value;
        refreshButton.disabled = value;
        sortControl.disabled = value;
        orderControl.disabled = value;
        pageSizeControl.disabled = value;
        renderPagination(paginationTop);
        renderPagination(paginationBottom);
        updateSelection();
    }

    function load() {
        setLoading(true);
        showNotice('progress', t('loading', 'Loading recycle records...'));
        var pageSize = Number(pageSizeControl.value || 50);
        return request('list', {
            state: 'all',
            limit: String(pageSize),
            offset: String((page - 1) * pageSize),
            sort: sortControl.value,
            order: orderControl.value
        }).then(function (payload) {
            totalRecords = Math.max(0, Number(payload.total_records || 0));
            totalPages = Math.max(1, Math.ceil(totalRecords / pageSize));
            if (page > totalPages) {
                page = totalPages;
                setLoading(false);
                return load();
            }
            renderRows(payload);
            clearNotice();
        }).catch(function (error) {
            showNotice('error', error.message);
        }).finally(function () {
            setLoading(false);
        });
    }

    function runBatch(action) {
        var ids = Array.from(selected);
        if (ids.length === 0) return;
        var restore = action === 'restore';
        var question = restore
            ? format('confirmBatchRestore', 'Restore %d selected item(s) to their original paths?', [ids.length])
            : format('confirmBatchPurge', 'Permanently delete %d selected item(s)? This cannot be undone.', [ids.length]);
        if (!window.confirm(question)) return;
        setLoading(true);
        var succeeded = 0;
        var failures = [];
        var chain = Promise.resolve();
        ids.forEach(function (id) {
            chain = chain.then(function () {
                return request(action, { id: id }).then(function () { succeeded++; }).catch(function (error) {
                    failures.push(error.message);
                });
            });
        });
        chain.then(function () {
            return load();
        }).then(function () {
            if (failures.length) {
                showNotice('error', format('batchFailed', '%d succeeded; %d failed. First error: ', [succeeded, failures.length]) + failures[0]);
            } else {
                showNotice('success', restore
                    ? format('batchRestoreDone', 'Restored %d item(s).', [succeeded])
                    : format('batchPurgeDone', 'Permanently deleted %d item(s).', [succeeded]));
            }
        }).finally(function () { setLoading(false); });
    }

    body.addEventListener('change', function (event) {
        var checkbox = event.target.closest && event.target.closest('.recycle-bin-row-select');
        if (!checkbox) return;
        if (checkbox.checked) selected.add(checkbox.value);
        else selected.delete(checkbox.value);
        updateSelection();
    });

    selectAll.addEventListener('change', function () {
        items.filter(actionable).forEach(function (item) {
            var id = String(item.id || '');
            if (selectAll.checked) selected.add(id);
            else selected.delete(id);
        });
        body.querySelectorAll('.recycle-bin-row-select:not(:disabled)').forEach(function (checkbox) {
            checkbox.checked = selectAll.checked;
        });
        updateSelection();
    });

    [sortControl, orderControl, pageSizeControl].forEach(function (control) {
        control.addEventListener('change', function () { page = 1; load(); });
    });
    restoreButton.addEventListener('click', function () { runBatch('restore'); });
    purgeButton.addEventListener('click', function () { runBatch('purge'); });
    refreshButton.addEventListener('click', load);
    settingsButton.addEventListener('click', function () { window.location.assign(runtime.settingsUrl); });
    sortControl.value = 'deleted_at';
    orderControl.value = 'desc';
    pageSizeControl.value = '50';
    renderPagination(paginationTop);
    renderPagination(paginationBottom);
    load();
})();
