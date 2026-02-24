/**
 * visibility-calc.js — Calculs de visibilité et score de priorité
 * Dépend de : astronomy-helpers.js, astronomy-engine
 */

/**
 * Calcule les données de visibilité d'une cible pour une nuit donnée.
 * @param {number} raH  - RA en heures
 * @param {number} decD - Dec en degrés
 * @param {object} obs  - A.Observer
 * @param {number} altMin - altitude minimale horizon (degrés)
 * @param {Date}   baseDate - date de début (00:00 locale)
 * @param {number} [step=0.25] - pas en heures pour l'échantillonnage
 */
function computeNight(raH, decD, obs, altMin, baseDate, step) {
    step = step || 0.25;
    let usefulH = 0, minSep = 180, phase = 0, sampled = false;
    let windowStart = null, windowEnd = null;

    for (let h = 0; h <= 12; h += step) {
        const t = new Date(baseDate.getTime() + (18 + h) * 3600000);
        const eqS  = Astronomy.Equator('Sun', t, obs, true, true);
        const horS = Astronomy.Horizon(t, obs, eqS.ra, eqS.dec, 'normal');
        if (horS.altitude > -18) continue;

        const horT = Astronomy.Horizon(t, obs, raH, decD, 'normal');
        if (horT.altitude > altMin) {
            usefulH += step;
            if (windowStart === null) windowStart = t;
            windowEnd = t;
            const eqM = Astronomy.Equator('Moon', t, obs, true, true);
            minSep = Math.min(minSep, moonSep(raH, decD, eqM.ra, eqM.dec));
            if (!sampled) { phase = moonIllum(t); sampled = true; }
        }
    }

    return { usefulH, minSep: minSep === 180 ? null : minSep, phase, windowStart, windowEnd };
}

/**
 * Score de priorité d'une cible pour une nuit donnée.
 */
function priorityScore(usefulH, phase, minSep, deficitH, narrow) {
    if (usefulH === 0) return 0;
    const moonW  = narrow ? 0.15 : 1.0;
    const moonF  = Math.max(0, 1 - phase * moonW);
    const sepF   = !minSep ? 0 : minSep < 20 ? 0.1 : minSep < 40 ? 0.5 : minSep < 60 ? 0.8 : 1.0;
    const visScore = usefulH * moonF * sepF;
    return deficitH > 0 ? deficitH * visScore : visScore;
}

/**
 * Calcule le temps de prise de vue effectif après déduction de l'overhead.
 * @param {number} windowMin  - durée totale de la fenêtre en minutes
 * @param {object} setup      - { slewMin, afTimeMin, afIntervalMin, flipMin }
 * @param {boolean} hasFlip   - vrai si retournement méridien prévu
 * @returns {{ effectiveMin, overheadMin }}
 */
function computeEffective(windowMin, setup, hasFlip) {
    const initialOverheadMin = setup.slewMin + setup.afTimeMin + (hasFlip ? (setup.flipMin || 5) : 0);
    const shootMin = Math.max(0, windowMin - initialOverheadMin);
    const afCount = setup.afIntervalMin > 0 ? Math.floor(shootMin / setup.afIntervalMin) : 0;
    const periodicAfMin = afCount * setup.afTimeMin;
    const overheadMin = initialOverheadMin + periodicAfMin;
    const effectiveMin = Math.max(0, windowMin - overheadMin);
    return { effectiveMin, overheadMin };
}
