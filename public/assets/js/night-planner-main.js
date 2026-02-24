/**
 * night-planner-main.js — Logique principale du Night Planner
 * Dépend de : astronomy-helpers.js, visibility-calc.js, meridian-flip.js,
 *             scheduler.js, night-planner-gantt.js, framing-modal.js
 * Globals attendus :
 *   ALL_TARGETS, ALL_SETUPS, WISHLIST_URL, TARGET_URL, NP_CONFIG, NP_TRANS
 *   NP_CONFIG : { obsLat, obsLon, obsHorizon }
 *   NP_TRANS  : { colFraming, noFramingSection, noFramingBadge }
 */

function getSelectedSetup() {
    const sel = document.getElementById('np-setup');
    if (sel && sel.options.length > 0) {
        const opt = sel.options[sel.selectedIndex];
        return {
            id:            parseInt(opt.value),
            lat:           parseFloat(opt.dataset.lat   || NP_CONFIG.obsLat),
            lon:           parseFloat(opt.dataset.lon   || NP_CONFIG.obsLon),
            horizon:       parseFloat(opt.dataset.horizon || NP_CONFIG.obsHorizon),
            slewMin:       parseInt(opt.dataset.slew    || 5),
            afTimeMin:     parseInt(opt.dataset.afTime  || 10),
            afIntervalMin: parseInt(opt.dataset.afInterval || 60),
            flipMin:       parseInt(opt.dataset.flip    || 5),
            minShootMin:   parseInt(opt.dataset.minShoot || 30),
        };
    }
    return {
        id:            null,
        lat:           NP_CONFIG.obsLat,
        lon:           NP_CONFIG.obsLon,
        horizon:       NP_CONFIG.obsHorizon,
        slewMin:       5,
        afTimeMin:     10,
        afIntervalMin: 60,
        flipMin:       5,
        minShootMin:   30,
    };
}

function getBaseDate() {
    const v = document.getElementById('np-date').value;
    if (!v) return new Date();
    const [y, m, d] = v.split('-').map(Number);
    return new Date(y, m - 1, d, 0, 0, 0);
}

async function compute() {
    const setup = getSelectedSetup();
    const baseDate = getBaseDate();
    const astroObs = new Astronomy.Observer(setup.lat, setup.lon, 0);
    const wishlistOnly = document.getElementById('np-wishlist-only').checked;

    document.getElementById('np-loading').classList.remove('d-none');
    document.getElementById('np-empty').classList.add('d-none');
    document.getElementById('np-tbody').innerHTML = '';
    document.getElementById('np-plan-card').style.display = 'none';

    await new Promise(r => setTimeout(r, 10));

    const targets = setup.id !== null
        ? ALL_TARGETS.filter(t => t.framing && t.framing.setupId === setup.id)
        : ALL_TARGETS;

    const rows = [];
    for (const t of targets) {
        if (wishlistOnly && !t.wishlist) continue;
        const data = computeNight(t.ra, t.dec, astroObs, setup.horizon, baseDate);
        if (data.usefulH === 0) continue;

        const windowMin = data.usefulH * 60;
        const { effectiveMin, overheadMin } = computeEffective(windowMin, setup);
        if (effectiveMin < setup.minShootMin) continue;

        const narrow = isNarrowband(t.type);
        const effectiveH = effectiveMin / 60;
        const score = priorityScore(effectiveH, data.phase, data.minSep, t.deficitH, narrow);
        rows.push({ t, data, score, narrow, effectiveH, overheadMin });
    }
    rows.sort((a, b) => b.score - a.score);

    // Cibles wish list sans cadrage (bas de tableau)
    const noFramingRows = [];
    if (setup.id !== null) {
        const framedIds = new Set(rows.map(r => r.t.id));
        for (const t of ALL_TARGETS) {
            if (!t.wishlist || t.framing || framedIds.has(t.id)) continue;
            if (!t.ra || !t.dec) continue;
            const data = computeNight(t.ra, t.dec, astroObs, setup.horizon, baseDate);
            if (data.usefulH === 0) continue;
            noFramingRows.push({ t, data });
        }
        noFramingRows.sort((a, b) => b.data.usefulH - a.data.usefulH);
    }

    const { dusk, dawn } = getNightBounds(baseDate, astroObs);

    document.getElementById('np-loading').classList.add('d-none');

    if (rows.length === 0 && noFramingRows.length === 0) {
        document.getElementById('np-empty').classList.remove('d-none');
        return;
    }

    const maxScore = rows.length > 0 ? rows[0].score : 1;
    const tbody = document.getElementById('np-tbody');
    const colFramingTitle = (typeof NP_TRANS !== 'undefined') ? NP_TRANS.colFraming : '';

    function renderRow(t, data, score, narrow, effectiveH) {
        const pct   = maxScore > 0 ? Math.round(score / maxScore * 100) : 0;
        const color = scoreColor(pct);
        const sepTxt = data.minSep !== null
            ? `${Math.round(data.minSep)}° ${moonEmoji(data.phase)} ${Math.round(data.phase * 100)}%`
            : '—';
        const winTxt = data.windowStart
            ? `${fmtTime(data.windowStart)} → ${fmtTime(data.windowEnd)}`
            : '—';
        const defTxt = t.deficitH > 0
            ? `<span class="text-warning">${fmtDur(t.deficitH)}</span>`
            : '<span class="text-muted">—</span>';
        const wlIcon = t.wishlist
            ? '<i class="fa fa-star text-warning"></i>'
            : '<i class="fa fa-star text-muted"></i>';
        const framingIconClass = t.framing ? 'text-info' : 'text-muted';
        const framingBtn = t.wishlist
            ? `<button class="btn btn-link p-0 btn-framing" data-id="${t.id}" title="${colFramingTitle}">
                   <i class="fa fa-crop ${framingIconClass}"></i>
               </button>`
            : '';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><button class="btn btn-link p-0 btn-wishlist" data-id="${t.id}" title="Wish list">${wlIcon}</button></td>
            <td>
                <a href="${TARGET_URL.replace('XXXX', t.id)}" class="fw-medium">${t.name}</a>
                ${narrow ? '<span class="badge bg-danger ms-1 py-0">NB</span>' : ''}
            </td>
            <td class="text-muted small">${winTxt}</td>
            <td>${fmtDur(effectiveH)}</td>
            <td class="small">${sepTxt}</td>
            <td>${defTxt}</td>
            <td style="min-width:100px">
                <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:6px">
                        <div class="progress-bar bg-${color}" style="width:${pct}%"></div>
                    </div>
                    <span class="small text-muted">${pct}%</span>
                </div>
            </td>
            <td>${framingBtn}</td>`;
        tbody.appendChild(tr);
    }

    rows.forEach(({ t, data, score, narrow, effectiveH }) => renderRow(t, data, score, narrow, effectiveH));

    // Séparateur + cibles sans cadrage
    if (noFramingRows.length > 0) {
        const noFramingSection = (typeof NP_TRANS !== 'undefined') ? NP_TRANS.noFramingSection : 'No framing configured';
        const noFramingBadge  = (typeof NP_TRANS !== 'undefined') ? NP_TRANS.noFramingBadge  : 'No framing';
        const sep = document.createElement('tr');
        sep.innerHTML = `<td colspan="8" class="py-1 px-3 text-muted" style="font-size:.75rem;background:rgba(255,165,0,.06)">
            <i class="fa fa-exclamation-triangle text-warning me-1"></i>${noFramingSection}
        </td>`;
        tbody.appendChild(sep);

        noFramingRows.forEach(({ t, data }) => {
            const narrow = isNarrowband(t.type);
            const winTxt = data.windowStart ? `${fmtTime(data.windowStart)} → ${fmtTime(data.windowEnd)}` : '—';
            const sepTxt = data.minSep !== null
                ? `${Math.round(data.minSep)}° ${moonEmoji(data.phase)} ${Math.round(data.phase * 100)}%` : '—';
            const tr = document.createElement('tr');
            tr.style.opacity = '0.6';
            tr.innerHTML = `
                <td><button class="btn btn-link p-0 btn-wishlist" data-id="${t.id}" title="Wish list">
                    <i class="fa fa-star text-warning"></i>
                </button></td>
                <td>
                    <a href="${TARGET_URL.replace('XXXX', t.id)}" class="fw-medium fst-italic">${t.name}</a>
                    ${narrow ? '<span class="badge bg-danger ms-1 py-0">NB</span>' : ''}
                </td>
                <td class="text-muted small">${winTxt}</td>
                <td class="text-muted">${fmtDur(data.usefulH)}</td>
                <td class="small">${sepTxt}</td>
                <td><span class="text-muted">—</span></td>
                <td><span class="badge bg-warning text-dark" style="font-size:.65rem">
                    <i class="fa fa-crop me-1"></i>${noFramingBadge}
                </span></td>
                <td><button class="btn btn-link p-0 btn-framing" data-id="${t.id}" title="${colFramingTitle}">
                    <i class="fa fa-crop text-warning"></i>
                </button></td>`;
            tbody.appendChild(tr);
        });
    }

    if (rows.length > 0) {
        buildNightPlan(rows, baseDate, dusk, dawn, setup);
    }

    // Update Export NINA button href (always visible if rendered server-side)
    const exportBtn = document.getElementById('np-export-nina');
    if (exportBtn && typeof NINA_EXPORT_URL !== 'undefined' && setup.id !== null) {
        const dateVal = document.getElementById('np-date').value;
        exportBtn.href = NINA_EXPORT_URL + '?date=' + dateVal + '&setup_id=' + setup.id;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Date par défaut = ce soir
    const today = new Date();
    document.getElementById('np-date').value =
        today.getFullYear() + '-' +
        String(today.getMonth() + 1).padStart(2, '0') + '-' +
        String(today.getDate()).padStart(2, '0');

    document.getElementById('np-compute').addEventListener('click', compute);

    const npSetup = document.getElementById('np-setup');
    if (npSetup) npSetup.addEventListener('change', compute);

    // Toggle wishlist (délégation)
    document.getElementById('np-tbody').addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-wishlist');
        if (!btn) return;
        const id = btn.dataset.id;
        fetch(WISHLIST_URL.replace('XXXX', id), { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                const icon = btn.querySelector('i');
                icon.className = data.wishlist ? 'fa fa-star text-warning' : 'fa fa-star text-muted';
                const tgt = ALL_TARGETS.find(t => t.id == id);
                if (tgt) tgt.wishlist = data.wishlist;
                const framingCell = btn.closest('tr').querySelector('td:last-child');
                const colFramingTitle = (typeof NP_TRANS !== 'undefined') ? NP_TRANS.colFraming : '';
                if (data.wishlist) {
                    const iconCls = (tgt && tgt.framing) ? 'text-info' : 'text-muted';
                    framingCell.innerHTML = `<button class="btn btn-link p-0 btn-framing" data-id="${id}" title="${colFramingTitle}"><i class="fa fa-crop ${iconCls}"></i></button>`;
                } else {
                    framingCell.innerHTML = '';
                }
            });
    });

    // Bouton framing (délégation)
    document.getElementById('np-tbody').addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-framing');
        if (!btn) return;
        openFramingModal(parseInt(btn.dataset.id));
    });

    // Init framing modal handlers
    initFramingModalHandlers();

    // Auto-compute au chargement
    compute();
});
