/**
 * fs-scan.js — FS Change Detection Banner
 *
 * Reads data-scan-url, data-scan-apply-url, data-scan-level from #fs-scan-container
 * and shows a warning banner when FS changes are detected.
 */
(function () {
    'use strict';

    var container = document.getElementById('fs-scan-container');
    if (!container) return;

    var scanUrl  = container.dataset.scanUrl;
    var applyUrl = container.dataset.scanApplyUrl;
    var level    = container.dataset.scanLevel; // targets | sessions | files
    var trans    = JSON.parse(container.dataset.scanTrans || '{}');

    function t(key, replacements) {
        var str = trans[key] || key;
        if (replacements) {
            Object.keys(replacements).forEach(function (k) {
                str = str.replace(k, replacements[k]);
            });
        }
        return str;
    }

    function runScan() {
        container.innerHTML = '<div class="scan-spinner text-muted small py-2"><i class="fa fa-spinner fa-spin me-1"></i> ' + t('scanning') + '</div>';

        fetch(scanUrl)
            .then(function (r) { return r.json(); })
            .then(function (data) { renderBanner(data); })
            .catch(function () { container.innerHTML = ''; });
    }

    function renderBanner(data) {
        var hasNew     = (data.new && data.new.length > 0);
        var hasMissing = (data.missing && data.missing.length > 0);

        if (!hasNew && !hasMissing) {
            container.innerHTML = '';
            return;
        }

        var html = '<div class="scan-banner alert alert-warning py-2 mb-3 small">';
        html += '<div class="d-flex justify-content-between align-items-start">';
        html += '<div>';
        html += '<i class="fa fa-triangle-exclamation me-1"></i> <strong>' + t('changes_detected') + '</strong>';

        if (hasNew) {
            data.new.forEach(function (item) {
                if (level === 'files') {
                    html += '<br><span class="scan-item-new"><i class="fa fa-plus-circle me-1"></i> ' + t('new_files', {'{count}': item.count, '{role}': item.role}) + '</span>';
                } else {
                    var label = level === 'targets' ? t('new_target_item', {'{name}': item.name}) : t('new_session_item', {'{name}': item.name});
                    html += '<br><span class="scan-item-new"><i class="fa fa-plus-circle me-1"></i> ' + label + '</span>';
                }
            });
        }

        if (hasMissing) {
            data.missing.forEach(function (item) {
                if (level === 'files') {
                    html += '<br><span class="scan-item-missing"><i class="fa fa-minus-circle me-1"></i> ' + t('missing_files', {'{count}': item.count, '{role}': item.role}) + '</span>';
                } else {
                    var label = level === 'targets' ? t('missing_target_item', {'{name}': item.name}) : t('missing_session_item', {'{name}': item.name});
                    html += '<br><span class="scan-item-missing"><i class="fa fa-minus-circle me-1"></i> ' + label + '</span>';
                }
            });
        }

        html += '</div>';
        html += '<div class="d-flex gap-2 flex-nowrap align-items-center ms-3">';
        html += '<button class="btn btn-sm btn-warning scan-apply-btn" type="button"><i class="fa fa-check me-1"></i> ' + (level === 'files' ? t('apply_files') : t('apply')) + '</button>';
        html += '<button class="btn btn-sm btn-outline-secondary scan-ignore-btn" type="button">' + t('ignore') + '</button>';
        html += '<button class="btn btn-sm btn-outline-secondary scan-refresh-btn" type="button" title="' + t('refresh') + '"><i class="fa fa-sync"></i></button>';
        html += '</div></div></div>';

        container.innerHTML = html;

        container.querySelector('.scan-ignore-btn').addEventListener('click', function () {
            container.innerHTML = '';
        });

        container.querySelector('.scan-refresh-btn').addEventListener('click', function () {
            runScan();
        });

        container.querySelector('.scan-apply-btn').addEventListener('click', function () {
            applyChanges(data);
        });
    }

    function applyChanges(data) {
        var hasMissing = (data.missing && data.missing.length > 0);
        if (hasMissing && level !== 'files') {
            if (!confirm(t('confirm_remove'))) {
                return;
            }
        }

        var btn = container.querySelector('.scan-apply-btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> ' + t('applying');
        }

        var body = {};
        if (level === 'files') {
            body = {};
        } else {
            body = {
                add: data.new.map(function (item) { return item.path; }),
                remove: data.missing.map(function (item) { return item.id; })
            };
        }

        fetch(applyUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) {
            if (r.ok) {
                location.reload();
            } else {
                alert(t('applied_error'));
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-check me-1"></i> ' + t('apply');
                }
            }
        })
        .catch(function () {
            alert(t('applied_error'));
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-check me-1"></i> ' + t('apply');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        runScan();
    });
})();
