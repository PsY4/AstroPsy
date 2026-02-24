/**
 * meridian-flip.js — Détection du retournement méridien
 */

/**
 * GMST en degrés (formule USNO).
 */
function gmstDeg(date) {
    const JD = date.getTime() / 86400000 + 2440587.5;
    let gmst = 280.46061837 + 360.98564736629 * (JD - 2451545.0);
    return ((gmst % 360) + 360) % 360;
}

/**
 * Angle horaire en heures (-12..12) : positif = cible à l'ouest du méridien.
 */
function hourAngle(date, raH, lonDeg) {
    const lst = (gmstDeg(date) + lonDeg) / 15;
    let ha = ((lst - raH) % 24 + 24) % 24;
    if (ha > 12) ha -= 24;
    return ha;
}

/**
 * Retourne la Date du passage au méridien si elle se produit dans [startMs, endMs], sinon null.
 * Détecte uniquement le passage est→ouest (HA passe de négatif à positif).
 */
function getMeridianFlipTime(raH, lonDeg, startMs, endMs) {
    const haStart = hourAngle(new Date(startMs), raH, lonDeg);
    const haEnd   = hourAngle(new Date(endMs),   raH, lonDeg);
    if (haStart >= 0 || haEnd <= 0) return null; // pas de passage
    // Bisection (20 itérations → précision < 1 s)
    let lo = startMs, hi = endMs;
    for (let i = 0; i < 20; i++) {
        const mid = (lo + hi) / 2;
        if (hourAngle(new Date(mid), raH, lonDeg) < 0) lo = mid; else hi = mid;
    }
    return new Date((lo + hi) / 2);
}
