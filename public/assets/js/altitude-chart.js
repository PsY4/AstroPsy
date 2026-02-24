/**
 * altitude-chart.js — Graphique d'altitude partagé (target + session)
 * Dépend de : astronomy-engine, Chart.js, chartjs-plugin-annotation
 *
 * Usage :
 *   initAltitudeChart({
 *     canvasId: 'altChart',
 *     metaId:   'meta',
 *     name:     'NGC 1234',
 *     raH:      5.5,
 *     decD:     22.0,
 *     lat:      43.6,
 *     lon:      5.5,
 *     dateStr:  '2025-10-11',  // null = today
 *   });
 */
function initAltitudeChart(cfg) {
    const { canvasId, metaId, name, raH, decD, lat, lon, dateStr } = cfg;

    function fmtAngle(x) { return `${Math.round(x)}°`; }
    function azToCardinal(az) {
        const dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW','N'];
        const i = Math.round(((az % 360) + 360) % 360 / 22.5);
        return dirs[i];
    }
    function sunColor(altDeg) {
        if (altDeg > 0)    return 'rgba(255,255,255,0)';
        if (altDeg > -6)   return 'rgba(255,240,180,0.12)';
        if (altDeg > -12)  return 'rgba(70,110,190,0.22)';
        if (altDeg > -18)  return 'rgba(30,70,150,0.38)';
        return 'rgba(0,0,0,0.64)';
    }

    let chartInst = null;

    (function computeAndPlot() {
        const obs = new Astronomy.Observer(lat, lon, 0);

        // Déterminer la date de base (midi)
        let startNoon;
        if (dateStr) {
            startNoon = new Date(dateStr + 'T12:00:00');
        } else {
            startNoon = new Date();
            startNoon.setHours(12, 0, 0, 0);
        }

        const times = [];
        for (let m = 0; m <= 24 * 60; m += 5) {
            times.push(new Date(startNoon.getTime() + m * 60000));
        }

        // Indice de minuit
        let midnightIdx = times.findIndex(t => t.getHours() === 0 && t.getMinutes() === 0);
        if (midnightIdx < 0) midnightIdx = Math.floor((12 * 60) / 5);

        // Indice "maintenant"
        const todayStr = new Date().toISOString().slice(0, 10);
        let nowIdx;
        if (!dateStr || dateStr === todayStr) {
            nowIdx = Math.floor((Date.now() - startNoon.getTime()) / (5 * 60 * 1000));
        } else {
            nowIdx = Math.floor(times.length / 2);
        }
        nowIdx = Math.max(0, Math.min(nowIdx, times.length - 1));

        const labels    = [];
        const altTarget = [];
        const altMoon   = [];
        const separation = [];
        const azTarget  = [];
        let bestIdx = 0, bestAlt = -999;

        for (let i = 0; i < times.length; i++) {
            const t = times[i];
            labels.push(t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));

            const horT = Astronomy.Horizon(t, obs, raH, decD, 'normal');
            const eqM  = Astronomy.Equator('Moon', t, obs, true, true);
            const horM = Astronomy.Horizon(t, obs, eqM.ra, eqM.dec, 'normal');

            const ra1 = raH * 15, ra2 = eqM.ra, dec1 = decD, dec2 = eqM.dec;
            const cosSep = Math.sin(rad(dec1))*Math.sin(rad(dec2)) + Math.cos(rad(dec1))*Math.cos(rad(dec2))*Math.cos(rad(ra1-ra2));
            const sep = deg(Math.acos(Math.min(1, Math.max(-1, cosSep))));

            altTarget.push(horT.altitude);
            azTarget.push(horT.azimuth);
            altMoon.push(horM.altitude);
            separation.push(sep);

            if (horT.altitude > bestAlt) { bestAlt = horT.altitude; bestIdx = i; }
        }

        bestAlt = Math.max(...altTarget);
        bestIdx = altTarget.indexOf(bestAlt);

        const nowAlt = altTarget[nowIdx];
        const nowSep = separation[nowIdx];
        const metaEl = document.getElementById(metaId);
        if (metaEl) {
            metaEl.textContent = `alt ${fmtAngle(nowAlt)} · Moon ${fmtAngle(nowSep)} away · transit ${labels[bestIdx]} @ ${fmtAngle(bestAlt)}`;
        }

        const sunAlt = times.map(t => {
            const eqS  = Astronomy.Equator('Sun', t, obs, true, true);
            const horS = Astronomy.Horizon(t, obs, eqS.ra, eqS.dec, 'normal');
            return horS.altitude;
        });
        const lightGradient = {
            label: 'Light', order: 0,
            data: times.map(() => 95),
            fill: {
                target: 'start',
                above: (ctx) => {
                    const { chartArea, ctx: c } = ctx.chart;
                    if (!chartArea) return;
                    const g = c.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
                    const n = sunAlt.length;
                    for (let i = 0; i < n; i++) g.addColorStop(i / (n - 1), sunColor(sunAlt[i]));
                    return g;
                },
            },
            borderWidth: 0, pointRadius: 0, tension: 0,
        };

        const canvasEl = document.getElementById(canvasId);
        if (!canvasEl) return;
        const ctx = canvasEl.getContext('2d');
        const data = {
            labels,
            datasets: [
                { label: 'Azimuth', data: azTarget, hidden: true, showLine: false, pointRadius: 0, borderWidth: 0, parsing: true },
                { label: name, data: altTarget, borderWidth: 2.5, tension: 0.22, pointRadius: 0, pointHitRadius: 6, borderColor: '#256f77', fill: false },
                { label: 'Moon', data: altMoon, borderWidth: 1, tension: 0.22, pointRadius: 0, pointHitRadius: 6, borderColor: '#aaaaaa', borderDash: [4, 4], fill: false },
                lightGradient,
            ]
        };
        const options = {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            spanGaps: true,
            layout: { padding: { top: 8, right: 8, bottom: 4, left: 8 } },
            plugins: {
                legend: { display: false },
                title:  { display: false },
                annotation: {
                    annotations: {
                        midnightLine: {
                            type: 'line',
                            xMin: labels[midnightIdx], xMax: labels[midnightIdx],
                            borderColor: '#a1a1a1', borderDash: [3, 3], borderWidth: 1,
                            label: { display: false },
                        },
                    },
                },
                tooltip: {
                    enabled: true, mode: 'index', intersect: false,
                    filter: (item) => item.dataset.label !== 'Azimuth (hidden)',
                    callbacks: {
                        title: (items) => labels[items[0].dataIndex],
                        label: (item) => {
                            if (item.dataset.label === name)   return `Alt: ${Math.round(item.parsed.y)}°`;
                            if (item.dataset.label === 'Moon') return `Moon alt: ${Math.round(item.parsed.y)}°`;
                            return '';
                        },
                        afterBody: (items) => {
                            const i = items[0].dataIndex;
                            return `Az: ${Math.round(azTarget[i])}° (${azToCardinal(azTarget[i])}) · Moon sep: ${Math.round(separation[i])}°`;
                        }
                    }
                },
            },
            scales: {
                y: {
                    min: 0, max: 90,
                    ticks: { display: false, autoSkip: true, maxTicksLimit: 7, color: '#bdbdbd', padding: 4 },
                    grid: { display: false }, border: { display: false }, title: { display: false },
                },
                x: {
                    ticks: { display: true, maxTicksLimit: 12, color: '#bdbdbd', padding: 4 },
                    grid: { display: false }, border: { display: false }, title: { display: false },
                }
            }
        };

        if (chartInst) chartInst.destroy();
        chartInst = new Chart(canvasEl, { type: 'line', data, options });
    })();
}
