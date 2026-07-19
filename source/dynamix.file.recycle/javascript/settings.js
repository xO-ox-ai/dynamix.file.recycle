(function () {
    'use strict';

    var runtime = window.DynamixFileRecycleSettingsRuntime;
    var form = document.getElementById('recycle-settings-form');
    var notice = document.getElementById('recycle-settings-notice');
    var volumeList = document.getElementById('recycle-settings-volumes');
    var openBin = document.getElementById('recycle-open-bin');
    if (!runtime || !form || !notice || !volumeList || !openBin) return;

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
        volumes.forEach(function (volume) {
            var label = document.createElement('label');
            label.className = 'recycle-volume-option';
            var input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'allowed_volumes';
            input.value = volume.path;
            input.checked = allowed === null || allowed.indexOf(volume.path) >= 0;
            var path = document.createElement('code');
            path.textContent = volume.path;
            var fs = document.createElement('span');
            fs.className = 'hint';
            fs.textContent = String(volume.fs || 'unknown').toUpperCase();
            label.append(input, path, fs);
            volumeList.appendChild(label);
        });
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
    load();
})();
