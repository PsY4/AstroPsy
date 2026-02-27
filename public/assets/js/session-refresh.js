/**
 * session-refresh.js — Async session refresh with per-file progress
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
            masters: 'Masters', exports: 'Exports', phd2: 'PHD2 Logs', wbpp: 'WBPP Logs'
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

    /* ── Helpers ─────────────────────────────────────────── */

    function esc(str) {
        return str.replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function fetchJson(url, opts) {
        return fetch(url, opts).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok) {
                    var err = new Error(data.error || t('error'));
                    err.serverMessage = data.error || null;
                    throw err;
                }
                return data;
            });
        });
    }

    /* ── Main refresh flow ──────────────────────────────── */

    function startRefresh() {
        var steps = [
            { key: 'purge',   type: 'batch', label: t('step_purge') },
            { key: 'raws',    type: 'files', label: t('step_raws') },
            { key: 'phd2',    type: 'batch', label: t('step_phd2') },
            { key: 'wbpp',    type: 'batch', label: t('step_wbpp') },
            { key: 'masters', type: 'files', label: t('step_masters') },
            { key: 'exports', type: 'files', label: t('step_exports') }
        ];

        var html = '<div class="mb-3">';
        html += '<div class="progress mb-3" style="height: 6px"><div class="progress-bar" id="refreshProgress" style="width: 0%"></div></div>';
        steps.forEach(function (step, i) {
            html += '<div class="d-flex align-items-center mb-1" id="step-' + i + '">';
            html += '<span class="me-2 step-icon" style="width:20px;text-align:center"><i class="fa fa-circle text-muted" style="font-size:8px"></i></span>';
            html += '<span class="step-label">' + step.label + '</span>';
            html += '<span class="ms-auto step-status text-muted small"></span>';
            html += '</div>';
            if (step.type === 'files') {
                html += '<div class="ms-4 ps-1 d-none" id="step-detail-' + i + '">';
                html += '<div class="progress mb-1" style="height:3px"><div class="progress-bar bg-info" id="step-progress-' + i + '" style="width:0%"></div></div>';
                html += '<small class="text-muted text-truncate d-block" style="max-width:350px" id="step-filename-' + i + '"></small>';
                html += '</div>';
            }
        });
        html += '</div>';

        modalBody.innerHTML = html;
        modalFooter.innerHTML = '';

        var progressBar = document.getElementById('refreshProgress');
        var currentStep = 0;

        function updateMainProgress() {
            progressBar.style.width = Math.round((currentStep / steps.length) * 100) + '%';
        }

        function showCloseButton() {
            modalFooter.innerHTML = '<button class="btn btn-primary" id="refreshReloadBtn">' + t('btn_close') + '</button>';
            document.getElementById('refreshReloadBtn').addEventListener('click', function () {
                location.reload();
            });
        }

        function markStepDone(i, badge) {
            var stepEl = document.getElementById('step-' + i);
            stepEl.querySelector('.step-icon').innerHTML = '<i class="fa fa-check-circle text-success"></i>';
            stepEl.querySelector('.step-status').innerHTML = badge || '<span class="text-success">' + t('done') + '</span>';
            var detail = document.getElementById('step-detail-' + i);
            if (detail) detail.classList.add('d-none');
        }

        function markStepError(i, errorMsg) {
            var stepEl = document.getElementById('step-' + i);
            stepEl.querySelector('.step-icon').innerHTML = '<i class="fa fa-times-circle text-danger"></i>';
            stepEl.querySelector('.step-status').innerHTML = '<span class="text-danger">' + t('error') + '</span>';
            var detail = document.getElementById('step-detail-' + i);
            if (detail) detail.classList.add('d-none');

            if (errorMsg) {
                var errorId = 'refresh-error-' + i;
                modalBody.insertAdjacentHTML('beforeend',
                    '<div class="mt-2">' +
                    '<a class="text-danger small" data-bs-toggle="collapse" href="#' + errorId + '" role="button" aria-expanded="false">' +
                    '<i class="fa fa-chevron-down me-1"></i>' + t('error_details') + '</a>' +
                    '<div class="collapse mt-1" id="' + errorId + '">' +
                    '<pre class="bg-light text-danger p-2 rounded small mb-0" style="white-space:pre-wrap;word-break:break-word;max-height:200px;overflow-y:auto">' +
                    esc(errorMsg) + '</pre></div></div>'
                );
            }
            showCloseButton();
        }

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
            stepEl.querySelector('.step-icon').innerHTML = '<i class="fa fa-spinner fa-spin text-primary"></i>';
            stepEl.querySelector('.step-status').textContent = t('processing');

            if (step.type === 'batch') {
                runBatchStep(step);
            } else {
                runFileStep(step);
            }
        }

        function runBatchStep(step) {
            var i = currentStep;
            fetchJson(baseUrl + '/' + step.key, { method: 'POST' })
                .then(function (data) {
                    var badge = null;
                    if (data.processed !== undefined && data.processed > 0) {
                        badge = '<span class="badge bg-primary">' + data.processed + ' ' + t('files') + '</span>';
                    }
                    markStepDone(i, badge);
                    currentStep++;
                    updateMainProgress();
                    runStep();
                })
                .catch(function (err) {
                    markStepError(i, err.serverMessage || null);
                });
        }

        function runFileStep(step) {
            var i = currentStep;
            var detailEl = document.getElementById('step-detail-' + i);
            var subProgressEl = document.getElementById('step-progress-' + i);
            var filenameEl = document.getElementById('step-filename-' + i);
            var statusEl = document.getElementById('step-' + i).querySelector('.step-status');

            detailEl.classList.remove('d-none');

            fetchJson(baseUrl + '/' + step.key + '/files')
                .then(function (files) {
                    if (files.length === 0) {
                        markStepDone(i);
                        currentStep++;
                        updateMainProgress();
                        runStep();
                        return;
                    }

                    var fileIndex = 0;
                    var created = 0;
                    var errors = 0;

                    function processNextFile() {
                        if (fileIndex >= files.length) {
                            var badge = '';
                            if (created > 0) {
                                badge += '<span class="badge bg-primary">' + created + ' ' + t('files') + '</span>';
                            }
                            if (errors > 0) {
                                badge += ' <span class="badge bg-warning text-dark">' + errors + ' ' + t('errors') + '</span>';
                            }
                            markStepDone(i, badge || null);
                            currentStep++;
                            updateMainProgress();
                            runStep();
                            return;
                        }

                        var file = files[fileIndex];
                        filenameEl.textContent = file.name;
                        statusEl.textContent = (fileIndex + 1) + '/' + files.length;
                        subProgressEl.style.width = Math.round(((fileIndex + 1) / files.length) * 100) + '%';

                        fetchJson(baseUrl + '/' + step.key + '/file', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ path: file.path, folder: file.folder || null })
                        })
                            .then(function (data) {
                                if (data.status === 'created') created++;
                                fileIndex++;
                                processNextFile();
                            })
                            .catch(function () {
                                errors++;
                                fileIndex++;
                                processNextFile();
                            });
                    }

                    processNextFile();
                })
                .catch(function (err) {
                    markStepError(i, err.serverMessage || null);
                });
        }

        runStep();
    }
})();
