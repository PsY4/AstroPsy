/**
 * scheduler.js — Calcul des bornes de nuit et scheduler glouton
 * Dépend de : astronomy-helpers.js, visibility-calc.js, meridian-flip.js
 */

/**
 * Trouve les bornes astronomiques de la nuit (crépuscule/aube, soleil < -18°).
 */
function getNightBounds(baseDate, astroObs) {
    let dusk = null, dawn = null;
    // Recherche par pas de 5 min depuis 14h jusqu'à +20h
    for (let h = 0; h <= 20; h += 5 / 60) {
        const t = new Date(baseDate.getTime() + (14 + h) * 3600000);
        const eqS  = Astronomy.Equator('Sun', t, astroObs, true, true);
        const horS = Astronomy.Horizon(t, astroObs, eqS.ra, eqS.dec, 'normal');
        if (dusk === null && horS.altitude < -18) { dusk = t; }
        if (dusk !== null && dawn === null && horS.altitude >= -18) { dawn = t; break; }
    }
    if (!dusk) { const d = new Date(baseDate); d.setHours(20, 0, 0, 0); dusk = d; }
    if (!dawn) { const d = new Date(baseDate); d.setDate(d.getDate() + 1); d.setHours(5, 0, 0, 0); dawn = d; }
    return { dusk, dawn };
}

/**
 * Scheduler glouton : à chaque step, choisit la cible au meilleur score
 * disponible depuis le curseur, la planifie jusqu'à sa fin de fenêtre, avance.
 *
 * @param {Array}  rows       - rows avec .t (target), .data (computeNight result), .score
 * @param {Date}   nightStart - crépuscule
 * @param {Date}   nightEnd   - aube
 * @param {object} setup      - { lon, slewMin, afTimeMin, afIntervalMin, flipMin, minShootMin }
 * @returns {Array} schedule
 */
function computeSchedule(rows, nightStart, nightEnd, setup) {
    const schedule = [];
    let cursorMs = nightStart.getTime();
    const used = new Set();

    while (cursorMs < nightEnd.getTime()) {
        let best = null, bestScore = -Infinity;
        for (const row of rows) {
            if (used.has(row.t.id) || !row.data.windowStart || !row.data.windowEnd) continue;
            const effStart = Math.max(cursorMs, row.data.windowStart.getTime());
            const effEnd   = row.data.windowEnd.getTime();
            if (effStart >= effEnd) continue;

            const hasFlip = getMeridianFlipTime(row.t.ra, setup.lon, effStart, effEnd) !== null;
            const blockMin = (effEnd - effStart) / 60000;
            const { effectiveMin } = computeEffective(blockMin, setup, hasFlip);
            if (effectiveMin < setup.minShootMin) continue;

            if (row.score > bestScore) { bestScore = row.score; best = row; }
        }
        if (!best) break;

        used.add(best.t.id);
        const startMs = Math.max(cursorMs, best.data.windowStart.getTime());
        const endMs   = best.data.windowEnd.getTime();
        const start   = new Date(startMs);
        const end     = best.data.windowEnd;

        const flipTime = getMeridianFlipTime(best.t.ra, setup.lon, startMs, endMs);
        const flipMs   = flipTime ? (setup.flipMin || 5) * 60000 : 0;
        const blockMin = (endMs - startMs) / 60000;
        const { effectiveMin, overheadMin: blockOverheadMin } = computeEffective(blockMin, setup, flipTime !== null);

        const initialOverheadMs = (setup.slewMin + setup.afTimeMin) * 60000;
        const shootStart = new Date(startMs + initialOverheadMs);
        const shootEnd   = end;

        schedule.push({
            t: best.t, data: best.data, score: best.score,
            start, end, shootStart, shootEnd,
            initialOverheadMs, effectiveMin, blockOverheadMin,
            flipTime, flipMs
        });
        cursorMs = endMs;
    }
    return schedule;
}
