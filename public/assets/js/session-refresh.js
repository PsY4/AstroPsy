/**
 * session-refresh.js — Async session refresh with progress modal
 *
 * Globals expected: REFRESH_CONFIG = { sessionId, trans }
 */
(function () {
    'use strict';

    function t(key) {
        return (window.REFRESH_CONFIG && window.REFRESH_CONFIG.trans && window.REFRESH_CONFIG.trans[key]) || key;
    }

    var baseUrl;
    var modalEl;
    var modalBody;
    var modalFooter;
    var bsModal;

    window.openRefreshModal = function (sessionId) {
        baseUrl = '/api/session/' + sessionId + '/refresh';
        modalEl = document.getElementById('refreshModal');
        modalBody = document.getElementById('refreshModalBody');
        modalFooter = document.getElementById('refreshModalFooter');
        bsModal = new bootstrap.Modal(modalEl);

        modalBody.innerHTML = '<div class="text-center py-3"><i class="fa fa-spinner fa-spin me-2"></i>' + t('counting') + '</div>';
        modalFooter.innerHTML = '';
        bsModal.show();

        fetch(baseUrl + '/count')
            .then(function (r) { return r.json(); })
            .then(function (data) { renderCountSummary(data); })
            .catch(function () {
                modalBody.innerHTML = '<div class="text-danger">' + t('error') + '</div>';
            });
    };

    function renderCountSummary(counts) {
        var total = 0;
        Object.keys(counts).forEach(function (k) { total += counts[k]; });

        if (total === 0) {
            modalBody.innerHTML = '<div class="text-muted text-center py-3"><i class="fa fa-info-circle me-1"></i>' + t('no_files') + '</div>';
            modalFooter.innerHTML = '<button class="btn btn-secondary" data-bs-dismiss="modal">' + t('btn_close_text') + '</button>';
            return;
        }

        var html = '<p class="mb-2"><strong>' + t('total_files').replace('%count%', total) + '</strong></p>';
        html += '<table class="table table-sm mb-2">';
        var labels = {
            lights: 'Lights', darks: 'Darks', flats: 'Flats', bias: 'Bias',
            masters: 'Masters', exports: 'Exports', phd2: 'PHD2 Logs'
        };
        Object.keys(labels).forEach(function (k) {
            if (counts[k] > 0) {
                html += '<tr><td>' + labels[k] + '</td><td class="text-end fw-bold">' + counts[k] + '</td></tr>';
            }
        });
        html += '</table>';
        html += '<p class="text-muted small mb-0">' + t('confirm_text') + '</p>';

        modalBody.innerHTML = html;
        modalFooter.innerHTML = '<button class="btn btn-secondary" data-bs-dismiss="modal">' + t('btn_cancel') + '</button>' +
            '<button class="btn btn-primary" id="refreshStartBtn"><i class="fa fa-refresh me-1"></i>' + t('btn_start') + '</button>';

        document.getElementById('refreshStartBtn').addEventListener('click', function () {
            startRefresh();
        });
    }

    function startRefresh() {
        var steps = [
            { key: 'purge',   url: baseUrl + '/purge',   method: 'POST', label: t('step_purge') },
            { key: 'raws',    url: baseUrl + '/raws',    method: 'POST', label: t('step_raws') },
            { key: 'phd2',    url: baseUrl + '/phd2',    method: 'POST', label: t('step_phd2') },
            { key: 'masters', url: baseUrl + '/masters', method: 'POST', label: t('step_masters') },
            { key: 'exports', url: baseUrl + '/exports', method: 'POST', label: t('step_exports') }
        ];

        var html = '<div class="mb-3">';
        html += '<div class="progress mb-3" style="height: 6px"><div class="progress-bar" id="refreshProgress" style="width: 0%"></div></div>';
        steps.forEach(function (step, i) {
            html += '<div class="d-flex align-items-center mb-1" id="step-' + i + '">';
            html += '<span class="me-2 step-icon" style="width:20px;text-align:center"><i class="fa fa-circle text-muted" style="font-size:8px"></i></span>';
            html += '<span class="step-label">' + step.label + '</span>';
            html += '<span class="ms-auto step-status text-muted small"></span>';
            html += '</div>';
        });
        html += '</div>';

        modalBody.innerHTML = html;
        modalFooter.innerHTML = '';

        var progressBar = document.getElementById('refreshProgress');
        var currentStep = 0;

        function runStep() {
            if (currentStep >= steps.length) {
                progressBar.style.width = '100%';
                modalBody.insertAdjacentHTML('beforeend',
                    '<div class="text-center text-success mt-2"><i class="fa fa-check-circle me-1"></i><strong>' + t('complete') + '</strong></div>');
                modalFooter.innerHTML = '<button class="btn btn-primary" id="refreshReloadBtn"><i class="fa fa-refresh me-1"></i>' + t('btn_close') + '</button>';
                document.getElementById('refreshReloadBtn').addEventListener('click', function () {
                    location.reload();
                });
                return;
            }

            var step = steps[currentStep];
            var stepEl = document.getElementById('step-' + currentStep);
            var iconEl = stepEl.querySelector('.step-icon');
            var statusEl = stepEl.querySelector('.step-status');

            iconEl.innerHTML = '<i class="fa fa-spinner fa-spin text-primary"></i>';
            statusEl.textContent = t('processing');

            fetch(step.url, { method: step.method })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    iconEl.innerHTML = '<i class="fa fa-check-circle text-success"></i>';
                    if (data.processed !== undefined && data.processed > 0) {
                        statusEl.innerHTML = '<span class="badge bg-primary">' + data.processed + ' ' + t('files') + '</span>';
                    } else {
                        statusEl.innerHTML = '<span class="text-success">' + t('done') + '</span>';
                    }
                    currentStep++;
                    progressBar.style.width = Math.round((currentStep / steps.length) * 100) + '%';
                    runStep();
                })
                .catch(function () {
                    iconEl.innerHTML = '<i class="fa fa-times-circle text-danger"></i>';
                    statusEl.innerHTML = '<span class="text-danger">' + t('error') + '</span>';
                    modalFooter.innerHTML = '<button class="btn btn-primary" id="refreshReloadBtn">' + t('btn_close') + '</button>';
                    document.getElementById('refreshReloadBtn').addEventListener('click', function () {
                        location.reload();
                    });
                });
        }

        runStep();
    }
})();
