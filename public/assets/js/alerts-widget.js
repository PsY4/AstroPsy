/**
 * alerts-widget.js — Widget Dashboard Evening Alerts
 * Dépend de : astronomy-helpers.js, visibility-calc.js, scheduler.js
 * Globals attendus : WIDGET_TARGETS, WIDGET_SETUPS, TARGET_URL, ALERTS_TRANS
 *   ALERTS_TRANS : { noTargets, computing, moreTargets }
 */

document.addEventListener('DOMContentLoaded', function () {
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const container = document.getElementById('alerts-setups');
    const COLORS = ['#2ecc71', '#3498db', '#e67e22', '#9b59b6', '#1abc9c', '#e74c3c', '#f39c12'];

    const trans = (typeof ALERTS_TRANS !== 'undefined') ? ALERTS_TRANS : {};
    const noTargetsMsg = trans.noTargets || '';
    const moreTargetsTpl = trans.moreTargets || '+{count}';

    if (!WIDGET_SETUPS || WIDGET_SETUPS.length === 0) {
        container.innerHTML = `<span class="text-muted small">${noTargetsMsg}</span>`;
        return;
    }

    container.innerHTML = '';
    let totalSections = 0;

    for (const setup of WIDGET_SETUPS) {
        if (setup.obsLat == null || setup.obsLon == null) continue;
        const obs = new Astronomy.Observer(setup.obsLat, setup.obsLon, 0);
        const horizon = setup.obsHorizon ?? 20;
        const { dusk, dawn } = getNightBounds(today, obs);

        const setupTargets = WIDGET_TARGETS.filter(t => t.framing && t.framing.setupId === setup.id);
        if (setupTargets.length === 0) continue;

        const results = [];
        for (const t of setupTargets) {
            // Utiliser un pas de 0.5h pour le widget dashboard (plus rapide)
            const data = computeNight(t.ra, t.dec, obs, horizon, today, 0.5);
            if (data.usefulH === 0) continue;
            const narrow = isNarrowband(t.type);
            const score = priorityScore(data.usefulH, data.phase, data.minSep, t.deficitH ?? 0, narrow);
            results.push({ t, usefulH: data.usefulH, phase: data.phase, minSep: data.minSep, wStart: data.windowStart, wEnd: data.windowEnd, score });
        }
        if (results.length === 0) continue;
        results.sort((a, b) => b.score - a.score);
        totalSections++;

        const sec = document.createElement('div');
        sec.className = 'mb-2' + (totalSections > 1 ? ' pt-2 border-top' : '');

        // En-tête setup
        const hdr = document.createElement('div');
        hdr.className = 'd-flex align-items-center gap-2 mb-1';
        hdr.innerHTML = `<span class="small fw-medium"><i class="fa fa-cog text-primary me-1"></i>${setup.name} <span class="badge text-bg-secondary" style="font-size:.65rem">${results.length}</span></span>`
            + `<span class="text-muted ms-auto" style="font-size:.7rem">${fmtTime(dusk)}→${fmtTime(dawn)}</span>`;
        sec.appendChild(hdr);

        // Top 3 cibles
        const top = results.slice(0, 3);
        top.forEach(({ t, usefulH, minSep, phase, wStart, wEnd }) => {
            const win = wStart ? fmtTime(wStart) + '–' + fmtTime(wEnd) : '—';
            const moonPct = Math.round(phase * 100);
            const sep = minSep !== null ? Math.round(minSep) + '°' : '—';
            const moonCls = moonPct > 60 ? 'text-warning' : 'text-muted';
            const row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-1 mb-1';
            row.style.fontSize = '0.78rem';
            row.innerHTML = `<a href="${TARGET_URL.replace('XXXX', t.id)}" class="fw-medium flex-grow-1 text-truncate">${t.name}</a>`
                + `<span class="text-muted text-nowrap">${win}</span>`
                + `<span class="badge text-bg-primary" style="font-size:.65rem">${usefulH.toFixed(1)}h</span>`
                + `<span class="${moonCls} text-nowrap">${sep} <i class="fa fa-moon" style="font-size:.6rem"></i>${moonPct}%</span>`;
            sec.appendChild(row);
        });

        if (results.length > 3) {
            const moreDiv = document.createElement('div');
            moreDiv.className = 'text-muted text-center mb-1';
            moreDiv.style.fontSize = '.72rem';
            moreDiv.textContent = moreTargetsTpl.replace('{count}', results.length - 3);
            sec.appendChild(moreDiv);
        }

        // Mini Gantt
        const ganttEl = wBuildGantt(results, dusk, dawn, COLORS);
        if (ganttEl) sec.appendChild(ganttEl);

        container.appendChild(sec);
    }

    if (totalSections === 0) {
        container.innerHTML = `<span class="text-muted small">${noTargetsMsg}</span>`;
    }
});

function wBuildGantt(results, dusk, dawn, COLORS) {
    // Scheduler glouton simple (sans overhead) pour le mini Gantt
    const schedule = [];
    let cursorMs = dusk.getTime();
    const used = new Set();
    while (cursorMs < dawn.getTime()) {
        let best = null, bestScore = -Infinity;
        for (const row of results) {
            if (used.has(row.t.id) || !row.wStart || !row.wEnd) continue;
            const es = Math.max(cursorMs, row.wStart.getTime());
            if (es >= row.wEnd.getTime()) continue;
            if (row.score > bestScore) { bestScore = row.score; best = row; }
        }
        if (!best) break;
        used.add(best.t.id);
        const start = new Date(Math.max(cursorMs, best.wStart.getTime()));
        const end   = best.wEnd;
        schedule.push({ t: best.t, start, end });
        cursorMs = end.getTime();
    }
    if (schedule.length === 0) return null;

    const nightDur = dawn.getTime() - dusk.getTime();
    function pct(d) { return Math.max(0, Math.min(100, (d.getTime() - dusk.getTime()) / nightDur * 100)); }

    const wrap = document.createElement('div');
    wrap.className = 'mt-1';

    schedule.forEach(({ t, start, end }, i) => {
        const color = COLORS[i % COLORS.length];
        const lPct  = pct(start).toFixed(1);
        const wPct  = Math.max(1, pct(end) - parseFloat(lPct)).toFixed(1);
        const durH  = ((end - start) / 3600000).toFixed(1);

        const row = document.createElement('div');
        row.className = 'd-flex align-items-center gap-1 mb-1';
        row.style.fontSize = '.72rem';

        const nameEl = document.createElement('span');
        nameEl.style.cssText = 'width:72px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:right';
        nameEl.title = t.name; nameEl.textContent = t.name;

        const track = document.createElement('div');
        track.className = 'flex-grow-1 position-relative rounded';
        track.style.cssText = 'height:10px;background:rgba(255,255,255,.06)';

        const bar = document.createElement('div');
        bar.className = 'position-absolute h-100 rounded';
        bar.style.cssText = `left:${lPct}%;width:${wPct}%;background:${color};opacity:.85`;
        bar.title = `${fmtTime(start)} → ${fmtTime(end)} (${durH}h)`;
        track.appendChild(bar);

        const timeEl = document.createElement('span');
        timeEl.style.cssText = 'width:62px;flex-shrink:0;color:#999;white-space:nowrap';
        timeEl.textContent = `${fmtTime(start)}→${fmtTime(end)}`;

        row.appendChild(nameEl); row.appendChild(track); row.appendChild(timeEl);
        wrap.appendChild(row);
    });
    return wrap;
}
