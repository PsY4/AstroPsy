/**
 * night-planner-gantt.js — Gantt du planning de nuit
 * Dépend de : astronomy-helpers.js, scheduler.js
 * Globals attendus : TARGET_URL
 */

const NP_GANTT_COLORS = ['#2ecc71', '#3498db', '#e67e22', '#9b59b6', '#1abc9c', '#e74c3c', '#f39c12', '#1a9e8f'];

function buildNightPlan(rows, baseDate, nightStart, nightEnd, setup) {
    const card = document.getElementById('np-plan-card');
    const schedule = computeSchedule(rows, nightStart, nightEnd, setup);
    if (schedule.length === 0) { card.style.display = 'none'; return; }

    const nightDuration = (nightEnd - nightStart) / 60000;
    function toPct(date) {
        return Math.max(0, Math.min(100, (date - nightStart) / 60000 / nightDuration * 100));
    }

    // En-tête : date + bornes nuit
    const dd = String(baseDate.getDate()).padStart(2, '0');
    const mm = String(baseDate.getMonth() + 1).padStart(2, '0');
    document.getElementById('np-plan-date').textContent =
        `${dd}/${mm}/${baseDate.getFullYear()} · ${fmtTime(nightStart)} → ${fmtTime(nightEnd)}`;

    const gantt = document.getElementById('np-gantt');
    gantt.innerHTML = '';

    // Axe temporel : 5 graduations
    const axisRow = document.createElement('div');
    axisRow.className = 'd-flex mb-1';
    const axisLabel = document.createElement('span');
    axisLabel.style.cssText = 'width:122px;flex-shrink:0';
    axisRow.appendChild(axisLabel);
    const axisTrack = document.createElement('div');
    axisTrack.className = 'flex-grow-1 position-relative';
    axisTrack.style.cssText = 'height:16px;margin-right:100px';
    for (let i = 0; i <= 4; i++) {
        const t = new Date(nightStart.getTime() + i * (nightEnd - nightStart) / 4);
        const s = document.createElement('span');
        s.className = 'position-absolute text-muted';
        s.style.cssText = `left:${i * 25}%;font-size:.68rem;transform:translateX(-50%)`;
        s.textContent = fmtTime(t);
        axisTrack.appendChild(s);
    }
    axisRow.appendChild(axisTrack);
    gantt.appendChild(axisRow);

    // Une ligne par cible planifiée
    schedule.forEach(({ t, data, start, end, shootStart, shootEnd, initialOverheadMs, effectiveMin, flipTime, flipMs }, i) => {
        const color = NP_GANTT_COLORS[i % NP_GANTT_COLORS.length];
        const wLPct = toPct(data.windowStart);
        const wWPct = Math.max(0.5, toPct(data.windowEnd) - wLPct);
        const oLPct = toPct(start);
        const oWPct = Math.max(0.5, toPct(new Date(start.getTime() + initialOverheadMs)) - oLPct);
        const sLPct = toPct(shootStart);
        const sWPct = Math.max(0.5, toPct(shootEnd) - sLPct);
        const effectH = fmtDur(effectiveMin / 60);

        const row = document.createElement('div');
        row.className = 'd-flex align-items-center gap-2 mb-1';

        const nameEl = document.createElement('span');
        nameEl.style.cssText = 'width:120px;font-size:.75rem;text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex-shrink:0';
        nameEl.title = t.name;
        nameEl.textContent = t.name;

        const track = document.createElement('div');
        track.className = 'flex-grow-1 position-relative rounded';
        track.style.cssText = 'height:16px;background:rgba(255,255,255,.06)';

        // Fenêtre visible (fond faint)
        const bgBar = document.createElement('div');
        bgBar.className = 'position-absolute h-100 rounded';
        bgBar.style.cssText = `left:${wLPct.toFixed(1)}%;width:${wWPct.toFixed(1)}%;background:${color};opacity:.18`;
        bgBar.title = `Visible ${fmtTime(data.windowStart)} → ${fmtTime(data.windowEnd)}`;
        track.appendChild(bgBar);

        // Barre grise : overhead initial (slew + AF)
        if (oWPct > 0.3) {
            const overheadBar = document.createElement('div');
            overheadBar.className = 'position-absolute h-100 rounded';
            overheadBar.style.cssText = `left:${oLPct.toFixed(1)}%;width:${oWPct.toFixed(1)}%;background:#aaa;opacity:.5`;
            overheadBar.title = `Overhead (slew + AF) ${fmtTime(start)} → ${fmtTime(shootStart)}`;
            track.appendChild(overheadBar);
        }

        if (flipTime) {
            // Segment shoot 1 : shootStart → flipTime
            const s1LPct = sLPct;
            const s1WPct = Math.max(0.3, toPct(flipTime) - s1LPct);
            const bar1 = document.createElement('div');
            bar1.className = 'position-absolute h-100 rounded';
            bar1.style.cssText = `left:${s1LPct.toFixed(1)}%;width:${s1WPct.toFixed(1)}%;background:${color};opacity:.88`;
            bar1.title = `Shoot ${fmtTime(shootStart)} → ${fmtTime(flipTime)}`;
            track.appendChild(bar1);

            // Barre grise : retournement méridien
            const fLPct = toPct(flipTime);
            const fWPct = Math.max(0.5, (flipMs / 60000) / nightDuration * 100);
            const flipBar = document.createElement('div');
            flipBar.className = 'position-absolute h-100 rounded';
            flipBar.style.cssText = `left:${fLPct.toFixed(1)}%;width:${fWPct.toFixed(1)}%;background:#aaa;opacity:.5`;
            flipBar.title = `Retournement méridien ${fmtTime(flipTime)} (${setup.flipMin || 5} min)`;
            track.appendChild(flipBar);

            // Segment shoot 2 : après flip → shootEnd
            const afterFlip = new Date(flipTime.getTime() + flipMs);
            const s2LPct = toPct(afterFlip);
            const s2WPct = Math.max(0.3, toPct(shootEnd) - s2LPct);
            const bar2 = document.createElement('div');
            bar2.className = 'position-absolute h-100 rounded';
            bar2.style.cssText = `left:${s2LPct.toFixed(1)}%;width:${s2WPct.toFixed(1)}%;background:${color};opacity:.88`;
            bar2.title = `Shoot ${fmtTime(afterFlip)} → ${fmtTime(shootEnd)} (${effectH} effectif)`;
            track.appendChild(bar2);
        } else {
            const bar = document.createElement('div');
            bar.className = 'position-absolute h-100 rounded';
            bar.style.cssText = `left:${sLPct.toFixed(1)}%;width:${sWPct.toFixed(1)}%;background:${color};opacity:.88`;
            bar.title = `Shoot ${fmtTime(shootStart)} → ${fmtTime(shootEnd)} (${effectH} effectif)`;
            track.appendChild(bar);
        }

        const timeEl = document.createElement('span');
        timeEl.style.cssText = 'width:96px;font-size:.7rem;white-space:nowrap;flex-shrink:0;color:#999';
        timeEl.textContent = `${fmtTime(start)} → ${fmtTime(end)}`;

        row.appendChild(nameEl);
        row.appendChild(track);
        row.appendChild(timeEl);
        gantt.appendChild(row);
    });

    // Liste séquence
    const list = document.getElementById('np-plan-list');
    list.innerHTML = schedule.map(({ t, start, end, effectiveMin, flipTime }, i) => {
        const color   = NP_GANTT_COLORS[i % NP_GANTT_COLORS.length];
        const effectH = fmtDur(effectiveMin / 60);
        const def     = t.deficitH > 0 ? `<span class="text-warning"> ${fmtDur(t.deficitH)}↓</span>` : '';
        const flip    = flipTime ? `<span class="text-danger ms-1" title="Retournement méridien ${fmtTime(flipTime)}"><i class="fa fa-rotate fa-xs"></i></span>` : '';
        return `<span class="me-2 text-nowrap small">
            <span class="rounded-circle d-inline-block me-1" style="width:8px;height:8px;background:${color};vertical-align:middle"></span>
            <a href="${TARGET_URL.replace('XXXX', t.id)}" class="fw-medium" style="color:${color}">${i+1}. ${t.name}</a>
            <span class="text-muted">&nbsp;${fmtTime(start)}→${fmtTime(end)} (${effectH} shoot)</span>${def}${flip}
        </span>`;
    }).join('<span class="text-muted me-2 small">›</span>');

    card.style.display = '';
}
