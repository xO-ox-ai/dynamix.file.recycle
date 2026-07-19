(function () {
    'use strict';

    var runtime = window.DynamixFileRecycleSettingsRuntime;
    var form = document.getElementById('recycle-settings-form');
    var notice = document.getElementById('recycle-settings-notice');
    var volumeList = document.getElementById('recycle-settings-volumes');
    var openBin = document.getElementById('recycle-open-bin');
    var clearLogs = document.getElementById('recycle-clear-logs');
    var clearHistory = document.getElementById('recycle-clear-history');
    var downloadDiagnostics = document.getElementById('recycle-download-diagnostics');
    if (!runtime || !form || !notice || !volumeList || !openBin || !clearLogs || !clearHistory || !downloadDiagnostics) return;

    var catalog = runtime.i18n && typeof runtime.i18n === 'object' ? runtime.i18n : {};
    function t(key, fallback) { return typeof catalog[key] === 'string' ? catalog[key] : fallback; }

    document.querySelectorAll('[data-i18n]').forEach(function (element) {
        var key = element.getAttribute('data-i18n');
        element.textContent = t(key, element.textContent);
    });

    function showNotice(kind, message) {
        notice.className = 'recycle-settings-notice is-visible is-' + kind;
        notice.textContent = message;
    }

    function clearNotice() {
        notice.className = 'recycle-settings-notice';
        notice.textContent = '';
    }

    function request(action, fields) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('csrf_token', runtime.csrfToken || '');
        Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });
        return fetch(runtime.apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                if (!response.ok || !payload || payload.ok !== true) {
                    throw new Error(payload && payload.error ? payload.error : t('requestFailed', 'Request failed. Refresh the page and try again.'));
                }
                return payload;
            });
        });
    }

    function boolValue(value) {
        return ['1', 'true', 'yes', 'on'].indexOf(String(value || '').toLowerCase()) >= 0;
    }

    function humanSize(bytes) {
        var value = Math.max(0, Number(bytes || 0));
        var units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        var unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        var digits = unit === 0 ? 0 : (value >= 10 ? 1 : 2);
        return value.toFixed(digits) + ' ' + units[unit];
    }

    function setValue(name, value) {
        var input = form.elements.namedItem(name);
        if (!input) return;
        if (input.type === 'checkbox') input.checked = boolValue(value);
        else input.value = value == null ? '' : value;
    }

    function selectedVolumePolicy(raw) {
        if (raw === '*') return null;
        try {
            var parsed = JSON.parse(raw || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (_) {
            return [];
        }
    }

    function renderVolumes(volumes, allowedRaw) {
        volumeList.replaceChildren();
        if (!Array.isArray(volumes) || volumes.length === 0) {
            var empty = document.createElement('span');
            empty.className = 'hint';
            empty.textContent = t('noVolumes', 'No supported internal disk or dataset was detected.');
            volumeList.appendChild(empty);
            return;
        }
        var allowed = selectedVolumePolicy(allowedRaw);
        var groups = { array: [], zfs: [], unknown: [] };
        volumes.forEach(function (volume) {
            var kind = Object.prototype.hasOwnProperty.call(groups, volume.kind) ? volume.kind : 'unknown';
            groups[kind].push(volume);
        });

        function appendVolumeControl(parent, volume, labelText) {
            var label = document.createElement('label');
            label.className = 'recycle-volume-option';
            var input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'allowed_volumes';
            input.value = volume.path;
            input.checked = allowed === null || allowed.indexOf(volume.path) >= 0;
            var name = document.createElement('span');
            name.className = 'recycle-volume-name';
            name.textContent = labelText || volume.label || volume.path;
            var fs = document.createElement('span');
            fs.className = 'hint';
            fs.textContent = volume.path + ' - ' + String(volume.fs || 'unknown').toUpperCase();
            label.append(input, name, fs);
            parent.appendChild(label);
        }

        function renderGroup(kind, entries) {
            if (entries.length === 0) return;
            var group = document.createElement('section');
            group.className = 'recycle-volume-group';
            var heading = document.createElement('h3');
            heading.textContent = kind === 'array'
                ? t('arrayDisks', 'Array disks')
                : (kind === 'zfs' ? t('zfsDatasets', 'ZFS datasets') : t('volumes', 'Volumes'));
            group.appendChild(heading);

            var root = { children: Object.create(null), volume: null };
            entries.forEach(function (volume) {
                var hierarchy = Array.isArray(volume.hierarchy) && volume.hierarchy.length
                    ? volume.hierarchy : [volume.label || volume.path];
                var node = root;
                hierarchy.forEach(function (segment) {
                    if (!node.children[segment]) node.children[segment] = { children: Object.create(null), volume: null };
                    node = node.children[segment];
                });
                node.volume = volume;
            });

            function renderNodes(nodes) {
                var list = document.createElement('ul');
                list.className = 'recycle-volume-tree';
                Object.keys(nodes).sort().forEach(function (segment) {
                    var node = nodes[segment];
                    var item = document.createElement('li');
                    if (node.volume) appendVolumeControl(item, node.volume, segment);
                    else {
                        var branch = document.createElement('span');
                        branch.className = 'recycle-volume-branch';
                        branch.textContent = segment;
                        item.appendChild(branch);
                    }
                    if (Object.keys(node.children).length) item.appendChild(renderNodes(node.children));
                    list.appendChild(item);
                });
                return list;
            }

            group.appendChild(renderNodes(root.children));
            volumeList.appendChild(group);
        }

        renderGroup('array', groups.array);
        renderGroup('zfs', groups.zfs);
        renderGroup('unknown', groups.unknown);
    }

    function populate(payload) {
        var config = payload.config || {};
        var global = config.global || {};
        var history = config.history || {};
        var maintenance = config.maintenance || {};
        var security = config.security || {};
        var volumes = config.volumes || {};
        setValue('enabled', global.enabled);
        setValue('log_level', global.log_level || 'INFO');
        setValue('log_retention_days', global.log_retention_days || '30');
        setValue('log_max_size_mib', global.log_max_size_mib || '5');
        setValue('history_enabled', history.enabled);
        setValue('history_retention_days', history.retention_days || '365');
        setValue('age_days', maintenance.age_days || '30');
        setValue('capacity_mode', maintenance.capacity_mode || 'percent');
        setValue('capacity_percent', maintenance.capacity_percent || '10');
        setValue('capacity_absolute_gb', maintenance.capacity_absolute_gb || '0');
        setValue('auto_empty_cron', maintenance.auto_empty_cron || '');
        setValue('vacuum_sqlite', maintenance.vacuum_sqlite);
        setValue('preserve_metadata', security.preserve_metadata);
        renderVolumes(payload.supported_volumes || [], volumes.allowed == null ? '*' : volumes.allowed);
        var totals = payload.totals || {};
        document.getElementById('recycle-settings-active-items').textContent =
            String(totals.items || 0) + ' (' + String(totals.size || 0) + ' ' + t('bytes', 'bytes') + ')';
        document.getElementById('recycle-settings-log-size').textContent = humanSize(totals.log_bytes || 0);
        document.getElementById('recycle-settings-clearable-history').textContent =
            String(Number(totals.clearable_history || 0));
    }

    function configPayload() {
        return {
            global: {
                enabled: form.elements.namedItem('enabled').checked ? '1' : '0',
                log_level: form.elements.namedItem('log_level').value,
                log_retention_days: form.elements.namedItem('log_retention_days').value,
                log_max_size_mib: form.elements.namedItem('log_max_size_mib').value
            },
            history: {
                enabled: form.elements.namedItem('history_enabled').checked ? '1' : '0',
                retention_days: form.elements.namedItem('history_retention_days').value
            },
            volumes: {
                allowed: Array.from(form.querySelectorAll('input[name="allowed_volumes"]:checked')).map(function (input) { return input.value; })
            },
            maintenance: {
                age_days: form.elements.namedItem('age_days').value,
                capacity_mode: form.elements.namedItem('capacity_mode').value,
                capacity_percent: form.elements.namedItem('capacity_percent').value,
                capacity_absolute_gb: form.elements.namedItem('capacity_absolute_gb').value,
                auto_empty_cron: form.elements.namedItem('auto_empty_cron').value.trim(),
                vacuum_sqlite: form.elements.namedItem('vacuum_sqlite').checked ? '1' : '0'
            },
            security: {
                preserve_metadata: form.elements.namedItem('preserve_metadata').checked ? '1' : '0'
            }
        };
    }

    function load() {
        showNotice('progress', t('loading', 'Loading settings...'));
        request('config_get').then(function (payload) {
            populate(payload);
            clearNotice();
        }).catch(function (error) {
            showNotice('error', error.message);
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        showNotice('progress', t('saving', 'Saving settings...'));
        request('config_save', { config: JSON.stringify(configPayload()) }).then(function () {
            showNotice('success', t('saved', 'Settings saved.'));
            return request('config_get');
        }).then(populate).catch(function (error) {
            showNotice('error', error.message);
        }).finally(function () {
            submit.disabled = false;
        });
    });

    openBin.addEventListener('click', function () { window.location.assign(runtime.recycleBinUrl); });

    function runCleanup(button, action, confirmKey, confirmFallback, successKey, successFallback) {
        if (!window.confirm(t(confirmKey, confirmFallback))) return;
        button.disabled = true;
        request(action).then(function (payload) {
            var count = payload.cleared;
            if (count && typeof count === 'object') count = Number(count.files || 0) + Number(count.events || 0);
            showNotice('success', t(successKey, successFallback).replace('%d', String(Number(count || 0))));
            return request('config_get');
        }).then(populate).catch(function (error) {
            showNotice('error', error.message);
        }).finally(function () {
            button.disabled = false;
        });
    }

    clearLogs.addEventListener('click', function () {
        runCleanup(
            clearLogs,
            'clear_logs',
            'confirmClearLogs',
            'Clear runtime, audit and operation-event logs?',
            'logsCleared',
            'Cleared %d log entries or files.'
        );
    });

    clearHistory.addEventListener('click', function () {
        runCleanup(
            clearHistory,
            'clear_history',
            'confirmClearHistory',
            'Clear restored and purged history? Active recycle items will be preserved.',
            'historyCleared',
            'Cleared %d historical records.'
        );
    });

    downloadDiagnostics.addEventListener('click', function () {
        downloadDiagnostics.disabled = true;
        showNotice('progress', t('diagnosticsCollecting', 'Collecting diagnostic logs...'));
        var body = new URLSearchParams();
        body.append('action', 'diagnostics');
        body.append('csrf_token', runtime.csrfToken || '');
        fetch(runtime.apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            var type = String(response.headers.get('Content-Type') || '').toLowerCase();
            if (!response.ok || type.indexOf('gzip') < 0) {
                return response.json().catch(function () { return null; }).then(function (payload) {
                    throw new Error(payload && payload.error ? payload.error : t('diagnosticsFailed', 'Unable to download diagnostic logs.'));
                });
            }
            return response.blob().then(function (blob) {
                if (blob.size < 20) throw new Error(t('diagnosticsFailed', 'Unable to download diagnostic logs.'));
                var disposition = response.headers.get('Content-Disposition') || '';
                var match = disposition.match(/filename="([A-Za-z0-9._-]+)"/);
                var filename = match ? match[1] : 'dynamix-file-recycle-diagnostics.tar.gz';
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
            });
        }).then(function () {
            showNotice('success', t('diagnosticsDownloaded', 'Diagnostic logs downloaded.'));
        }).catch(function (error) {
            showNotice('error', error.message);
        }).finally(function () {
            downloadDiagnostics.disabled = false;
        });
    });
    load();
})();
