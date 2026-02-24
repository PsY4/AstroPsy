/**
 * astronomy-helpers.js — Fonctions utilitaires partagées (coords, lune, format)
 * Dépend de : astronomy-engine (window.Astronomy → aliasé globalement en A dans les templates)
 */

function rad(x) { return x * Math.PI / 180; }
function deg(x) { return x * 180 / Math.PI; }

function moonSep(raH, decD, moonRa, moonDec) {
    const r1 = rad(raH * 15), d1 = rad(decD), r2 = rad(moonRa * 15), d2 = rad(moonDec);
    const cos = Math.sin(d1)*Math.sin(d2) + Math.cos(d1)*Math.cos(d2)*Math.cos(r1-r2);
    return deg(Math.acos(Math.min(1, Math.max(-1, cos))));
}

function moonIllum(t) {
    const lon = Astronomy.MoonPhase(t);
    return (1 - Math.cos(lon * Math.PI / 180)) / 2;
}

function moonEmoji(phase) {
    if (phase < 0.10) return '🌑';
    if (phase < 0.25) return '🌒';
    if (phase < 0.50) return '🌓';
    if (phase < 0.75) return '🌔';
    return '🌕';
}

function isNarrowband(type) {
    if (!type) return false;
    return /emission|hii|supernova|remnant|planetary|eneb|pneb|snr/i.test(type);
}

function scoreColor(pct) {
    if (pct >= 75) return 'success';
    if (pct >= 40) return 'primary';
    if (pct >= 15) return 'warning';
    return 'danger';
}

function fmtTime(d) {
    if (!d) return '—';
    return d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
}

function fmtDur(h) {
    const total = Math.round(h * 60);
    const hh = Math.floor(total / 60);
    const mm = total % 60;
    if (hh === 0) return `${mm}m`;
    return mm === 0 ? `${hh}h` : `${hh}h${String(mm).padStart(2, '0')}m`;
}
