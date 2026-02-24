/**
 * shooting-advice.js — Conseils de prise de vue (filtres, meilleures nuits/mois)
 * Dépend de : astronomy-helpers.js
 * Globals attendus : ADVICE_LAT, ADVICE_LON, ADVICE_ALT, FILTER_CONFIG, ADVICE_CONFIG
 *   ADVICE_CONFIG : { raH, decD, monthNames, trans: { filters, bestNights, bestMonths, usefulHours } }
 */

(function () {
    if (typeof ADVICE_CONFIG === 'undefined' || ADVICE_LAT === null) return;

    const { raH, decD, monthNames, trans } = ADVICE_CONFIG;

    // Build label→{color,band} map from server config
    const _fcMap = {};
    FILTER_CONFIG.forEach(f => { _fcMap[f.label] = f; });
    const _nbLabels = FILTER_CONFIG.filter(f => f.band === 'NB').map(f => f.label);
    const _bbLabels = FILTER_CONFIG.filter(f => f.band === 'BB').map(f => f.label);

    function getFilterAdvice(type) {
        if (!type) return null;
        const t = type.toLowerCase();
        if (/emission|hii|supernova|remnant|planetary|eneb|pneb|snr/.test(t))
            return { filters: _nbLabels, label: 'Narrowband', mode: 'narrowband' };
        if (/reflection|rneb|dkneb/.test(t))
            return { filters: _bbLabels, label: 'Broadband', mode: 'broadband' };
        if (/galax|gxy|sgx|bgx|egx/.test(t))
            return { filters: _bbLabels, label: 'Broadband LRGB', mode: 'broadband' };
        if (/cluster|opcl|glcl|stcl/.test(t)) {
            const rgb = _bbLabels.filter(l => l !== 'L');
            return { filters: rgb.length ? rgb : _bbLabels, label: 'RGB', mode: 'broadband' };
        }
        return { filters: _bbLabels, label: 'Broadband', mode: 'broadband' };
    }

    function filterBadge(label) {
        const fc = _fcMap[label];
        const color = fc ? fc.color : '#6c757d';
        const band  = fc ? fc.band  : '';
        const sup   = band ? `<sup style="font-size:.6em;opacity:.8">${band}</sup>` : '';
        return `<span class="badge me-1" style="background:${color}">${label}${sup}</span>`;
    }

    function nightScore(durationH, phase, minSep, mode) {
        const moonW = mode === 'narrowband' ? 0.1 : 1.0;
        const moonF = Math.max(0, 1 - phase * moonW);
        const sepF  = minSep < 20 ? 0.1 : minSep < 40 ? 0.5 : minSep < 60 ? 0.8 : 1.0;
        return durationH * moonF * sepF;
    }

    function starsHtml(score, maxH) {
        const n = Math.round(Math.min(1, score / maxH) * 5);
        return '<span class="text-warning">' + '★'.repeat(n) + '</span>'
             + '<span class="text-muted">' + '☆'.repeat(5 - n) + '</span>';
    }

    async function computeTop3Nights(obs, altMin) {
        const results = [];
        const today = new Date(); today.setHours(0, 0, 0, 0);
        for (let d = 0; d < 30; d++) {
            const base = new Date(today.getTime() + d * 86400000);
            let usefulH = 0, minSep = 180, phase = 0, sampled = false;
            for (let h = 0; h < 12; h += 0.5) {
                const t = new Date(base.getTime() + (18 + h) * 3600000);
                const eqS = Astronomy.Equator('Sun', t, obs, true, true);
                const horS = Astronomy.Horizon(t, obs, eqS.ra, eqS.dec, 'normal');
                if (horS.altitude > -18) continue;
                const horT = Astronomy.Horizon(t, obs, raH, decD, 'normal');
                if (horT.altitude > altMin) {
                    usefulH += 0.5;
                    const eqM = Astronomy.Equator('Moon', t, obs, true, true);
                    minSep = Math.min(minSep, moonSep(raH, decD, eqM.ra, eqM.dec));
                    if (!sampled) { phase = moonIllum(t); sampled = true; }
                }
            }
            results.push({
                date: base, usefulH, phase, minSep,
                scoreBB: nightScore(usefulH, phase, minSep, 'broadband'),
                scoreNB: nightScore(usefulH, phase, minSep, 'narrowband'),
            });
        }
        return results.sort((a, b) => b.scoreBB - a.scoreBB).slice(0, 3);
    }

    async function computeTop3Months(obs, altMin) {
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const months = [];
        for (let m = 0; m < 12; m++) {
            let repDay = new Date(today.getFullYear(), m, 15, 0, 0, 0);
            if (repDay < today) repDay = new Date(today.getFullYear() + 1, m, 15, 0, 0, 0);
            let usefulH = 0;
            for (let h = 0; h < 12; h += 0.5) {
                const t = new Date(repDay.getTime() + (18 + h) * 3600000);
                const eqS = Astronomy.Equator('Sun', t, obs, true, true);
                const horS = Astronomy.Horizon(t, obs, eqS.ra, eqS.dec, 'normal');
                if (horS.altitude > -18) continue;
                const horT = Astronomy.Horizon(t, obs, raH, decD, 'normal');
                if (horT.altitude > altMin) usefulH += 0.5;
            }
            months.push({ monthIdx: m, usefulH });
        }
        return months.sort((a, b) => b.usefulH - a.usefulH).slice(0, 3);
    }

    async function renderShootingAdvice() {
        const container = document.getElementById('shootingAdvice');
        if (!container) return;
        const obs    = new Astronomy.Observer(ADVICE_LAT, ADVICE_LON, 0);
        const altMin = ADVICE_ALT;

        const targetType = (typeof TARGET_TYPE !== 'undefined') ? TARGET_TYPE : null;
        const [top3nights, top3months] = await Promise.all([
            computeTop3Nights(obs, altMin),
            computeTop3Months(obs, altMin),
        ]);

        const filterAdv = getFilterAdvice(targetType);
        const isNB = filterAdv && filterAdv.mode === 'narrowband';
        const maxScore = Math.max(...top3nights.map(n => Math.max(n.scoreBB, n.scoreNB)), 1);

        let html = '';

        // Filtres recommandés
        if (filterAdv) {
            html += `<div class="mb-2 px-1">`;
            html += `<div class="small text-muted mb-1">${trans.filters}</div>`;
            html += filterAdv.filters.map(filterBadge).join('');
            html += `<span class="ms-1 small text-muted">${filterAdv.label}</span>`;
            html += `</div><hr class="my-2">`;
        }

        // Top 3 nuits
        html += `<div class="small text-muted mb-1 px-1">${trans.bestNights}</div>`;
        if (top3nights.length === 0 || top3nights[0].usefulH === 0) {
            html += `<p class="text-muted small px-1">—</p>`;
        } else {
            html += `<table class="table table-sm table-borderless mb-0" style="font-size:.8rem">`;
            if (isNB) {
                html += `<thead><tr><th></th><th class="text-end">BB</th><th class="text-end">NB</th><th></th></tr></thead>`;
            }
            html += `<tbody>`;
            top3nights.forEach(n => {
                const dateStr = n.date.toLocaleDateString([], { day: '2-digit', month: '2-digit' });
                const sep = n.minSep < 180 ? Math.round(n.minSep) + '°' : '—';
                if (isNB) {
                    html += `<tr>
                        <td>${dateStr} ${moonEmoji(n.phase)} <span class="text-muted">${Math.round(n.phase*100)}%</span></td>
                        <td class="text-end">${starsHtml(n.scoreBB, maxScore)}</td>
                        <td class="text-end">${starsHtml(n.scoreNB, maxScore)}</td>
                        <td class="text-end text-muted">${n.usefulH.toFixed(1)}h</td>
                    </tr>`;
                } else {
                    html += `<tr>
                        <td>${dateStr} ${moonEmoji(n.phase)} <span class="text-muted">${Math.round(n.phase*100)}%</span></td>
                        <td>${starsHtml(n.scoreBB, maxScore)}</td>
                        <td class="text-end text-muted">${n.usefulH.toFixed(1)}h · ${sep}</td>
                    </tr>`;
                }
            });
            html += `</tbody></table>`;
        }

        // Top 3 mois
        html += `<hr class="my-2"><div class="small text-muted mb-1 px-1">${trans.bestMonths}</div>`;
        if (top3months.length === 0 || top3months[0].usefulH === 0) {
            html += `<p class="text-muted small px-1">—</p>`;
        } else {
            const maxH = top3months[0].usefulH;
            html += `<div class="px-1">`;
            top3months.forEach(m => {
                const pct = Math.round((m.usefulH / maxH) * 100);
                html += `<div class="d-flex align-items-center gap-2 mb-1" style="font-size:.8rem">
                    <span style="width:2rem;text-align:center">${monthNames[m.monthIdx]}</span>
                    <div class="progress flex-grow-1" style="height:8px">
                        <div class="progress-bar bg-primary" style="width:${pct}%"></div>
                    </div>
                    <span class="text-muted" style="width:4rem;text-align:right">${m.usefulH.toFixed(1)} ${trans.usefulHours}</span>
                </div>`;
            });
            html += `</div>`;
        }

        container.innerHTML = html;
    }

    renderShootingAdvice();
})();
