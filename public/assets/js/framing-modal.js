/**
 * framing-modal.js — Modal de cadrage (HiPS2FITS / SkyView preview)
 * Dépend de : astronomy-helpers.js
 * Globals attendus : ALL_TARGETS, ALL_SETUPS, FRAMING_URL, HIPS_BASE, FM_TRANS
 *   FM_TRANS : { setupNoOptics }
 */

let currentFramingTargetId = null;
let fmDragging = false, fmDragStartX = 0, fmDragStartY = 0, fmDragStartRa = 0, fmDragStartDec = 0;
let fmFov = null;
let fmDebounceTimer = null;

/**
 * Render filter checkboxes for the selected setup.
 * @param {number[]|null} selected - array of selected positions, or null = all
 */
function fmRenderFilters(selected) {
    const container = document.getElementById('fm-filters');
    if (!container) return;

    const setupId = document.getElementById('fm-setup').value;
    const setup   = ALL_SETUPS.find(s => s.id == setupId);
    const filters = setup ? (setup.filtersConfig || []) : [];

    if (!setup || filters.length === 0) {
        const noSetup = (typeof FM_TRANS !== 'undefined') ? FM_TRANS.filtersNoSetup : '—';
        container.innerHTML = `<span class="text-muted small">${noSetup}</span>`;
        return;
    }

    // null selected = all checked
    const allSelected = selected === null || selected === undefined;

    container.innerHTML = filters.map(f => {
        const pos     = parseInt(f.position ?? 0);
        const checked = allSelected || selected.includes(pos) ? 'checked' : '';
        const label   = f.label || f.ninaName || ('Pos ' + pos);
        return `<div class="form-check form-check-inline">
            <input class="form-check-input fm-filter-cb" type="checkbox" value="${pos}" id="fm-f-${pos}" ${checked}>
            <label class="form-check-label small" for="fm-f-${pos}">${label}</label>
        </div>`;
    }).join('');
}

function fmGetSelectedFilters() {
    const boxes = document.querySelectorAll('.fm-filter-cb');
    if (!boxes.length) return null;
    const total    = boxes.length;
    const checked  = [...boxes].filter(cb => cb.checked);
    if (checked.length === 0 || checked.length === total) return null; // null = all
    return checked.map(cb => parseInt(cb.value));
}

function computeFov(setup) {
    if (!setup || !setup.focalMm || !setup.pixelSizeUm || !setup.sensorWPx || !setup.sensorHPx) return null;
    const fovW = (setup.sensorWPx * setup.pixelSizeUm) / setup.focalMm * 206.265;
    const fovH = (setup.sensorHPx * setup.pixelSizeUm) / setup.focalMm * 206.265;
    return { w: fovW, h: fovH };
}

function fmFormatFov(fov) {
    if (!fov) return '—';
    const fmt = v => v >= 3600 ? (v/3600).toFixed(2)+'°' : v >= 60 ? (v/60).toFixed(1)+'\'' : v.toFixed(0)+'"';
    return `${fmt(fov.w)} × ${fmt(fov.h)}`;
}

function fmUpdateFov() {
    const setup = ALL_SETUPS.find(s => s.id == document.getElementById('fm-setup').value);
    fmFov = computeFov(setup);
    document.getElementById('fm-fov').textContent = fmFormatFov(fmFov);
    const warn = document.getElementById('fm-no-optics-warn');
    if (setup && !fmFov) {
        warn.classList.remove('d-none');
    } else {
        warn.classList.add('d-none');
    }
}

function fmLoadPreview() {
    const ra  = parseFloat(document.getElementById('fm-ra').value);
    const dec = parseFloat(document.getElementById('fm-dec').value);
    const rot = parseFloat(document.getElementById('fm-rot').value);

    if (isNaN(ra) || isNaN(dec) || !fmFov) {
        document.getElementById('fm-placeholder').classList.remove('d-none');
        document.getElementById('fm-preview').style.setProperty('display', 'none', 'important');
        return;
    }

    const raDeg = ra * 15;
    const w = 640;
    const h = Math.max(1, Math.round(640 * fmFov.h / fmFov.w));
    const fovDeg = fmFov.w / 3600;

    const survey = document.getElementById('fm-survey').value;
    let url;
    if (survey.startsWith('skyview-')) {
        const sv = survey === 'skyview-dss2r' ? 'DSS2+Red' : 'SDSSr';
        url = `https://skyview.gsfc.nasa.gov/current/cgi/runquery.pl?Survey=${sv}&Position=${raDeg},${dec}&Size=${fovDeg}&Pixels=${w}&Return=JPEG`;
    } else {
        url = `${HIPS_BASE}?hips=CDS/P/DSS2/color&ra=${raDeg}&dec=${dec}&fov=${fovDeg}&rotation_angle=${rot}&width=${w}&height=${h}&projection=TAN&format=jpg`;
    }

    document.getElementById('fm-loading').classList.remove('d-none');
    document.getElementById('fm-placeholder').classList.add('d-none');

    const img = document.getElementById('fm-preview');
    img.onload = () => {
        document.getElementById('fm-loading').classList.add('d-none');
        img.style.removeProperty('display');
    };
    img.onerror = () => {
        document.getElementById('fm-loading').classList.add('d-none');
        document.getElementById('fm-placeholder').classList.remove('d-none');
    };
    img.src = url;
}

function fmDebouncePreview() {
    clearTimeout(fmDebounceTimer);
    fmDebounceTimer = setTimeout(fmLoadPreview, 400);
}

function openFramingModal(targetId) {
    currentFramingTargetId = targetId;
    const tgt = ALL_TARGETS.find(t => t.id == targetId);
    document.getElementById('fm-target-name').textContent = tgt ? tgt.name : '#' + targetId;

    // Remplir le sélecteur de setup
    const sel = document.getElementById('fm-setup');
    const noOpticsLabel = (typeof FM_TRANS !== 'undefined' && FM_TRANS.setupNoOptics) ? FM_TRANS.setupNoOptics : '— no optics —';
    sel.innerHTML = `<option value="">— ${noOpticsLabel} —</option>`;
    ALL_SETUPS.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.name + (computeFov(s) ? '' : ' (sans optique)');
        sel.appendChild(opt);
    });

    // Charger les données de cadrage existantes
    fetch(FRAMING_URL.replace('XXXX', targetId))
        .then(r => r.json())
        .then(data => {
            document.getElementById('fm-ra').value  = data.ra  !== null ? data.ra  : (tgt ? tgt.ra  : '');
            document.getElementById('fm-dec').value = data.dec !== null ? data.dec : (tgt ? tgt.dec : '');
            const rot = data.rotation ?? 0;
            document.getElementById('fm-rot').value    = rot;
            document.getElementById('fm-rot-val').textContent = rot + '°';
            if (data.setupId) sel.value = data.setupId;
            fmUpdateFov();
            fmRenderFilters(data.filtersSelected ?? null);
            fmLoadPreview();
        });

    const modal = new bootstrap.Modal(document.getElementById('framingModal'));
    modal.show();
}

// Initialisation des drag handlers — appelée après DOMContentLoaded
function initFramingModalHandlers() {
    const wrap = document.getElementById('fm-preview-wrap');
    if (!wrap) return;

    // Setup change → FOV + preview + filters
    document.getElementById('fm-setup').addEventListener('change', function () {
        fmUpdateFov();
        fmRenderFilters(null); // reset to all when setup changes
        fmDebouncePreview();
    });

    // RA/Dec inputs → debounce preview
    document.getElementById('fm-ra').addEventListener('input', fmDebouncePreview);
    document.getElementById('fm-dec').addEventListener('input', fmDebouncePreview);

    // Rotation slider
    document.getElementById('fm-rot').addEventListener('input', function () {
        document.getElementById('fm-rot-val').textContent = this.value + '°';
        fmDebouncePreview();
    });

    // Survey selector
    document.getElementById('fm-survey').addEventListener('change', fmLoadPreview);

    // Reload button
    document.getElementById('fm-reload').addEventListener('click', fmLoadPreview);

    // Drag-to-pan
    wrap.addEventListener('mousedown', function (e) {
        if (!fmFov) return;
        fmDragging = true;
        fmDragStartX   = e.clientX;
        fmDragStartY   = e.clientY;
        fmDragStartRa  = parseFloat(document.getElementById('fm-ra').value) || 0;
        fmDragStartDec = parseFloat(document.getElementById('fm-dec').value) || 0;
        wrap.style.cursor = 'grabbing';
        e.preventDefault();
    });

    document.addEventListener('mousemove', function (e) {
        if (!fmDragging || !fmFov) return;
        const img = document.getElementById('fm-preview');
        const imgW = img.offsetWidth  || 640;
        const imgH = img.offsetHeight || Math.round(640 * fmFov.h / fmFov.w);
        const deltaX = e.clientX - fmDragStartX;
        const deltaY = e.clientY - fmDragStartY;
        const asPerPxW = fmFov.w / imgW;
        const asPerPxH = fmFov.h / imgH;
        const rotRad = parseFloat(document.getElementById('fm-rot').value) * Math.PI / 180;
        const cosR = Math.cos(rotRad), sinR = Math.sin(rotRad);
        const dxRot = deltaX * cosR - deltaY * sinR;
        const dyRot = deltaX * sinR + deltaY * cosR;
        document.getElementById('fm-ra').value  = (fmDragStartRa  + (dxRot * asPerPxW) / 3600 / 15).toFixed(4);
        document.getElementById('fm-dec').value = (fmDragStartDec + (dyRot * asPerPxH) / 3600).toFixed(4);
        fmDebouncePreview();
    });

    document.addEventListener('mouseup', function () {
        if (fmDragging) {
            fmDragging = false;
            document.getElementById('fm-preview-wrap').style.cursor = 'grab';
        }
    });

    // Sauvegarde du cadrage
    document.getElementById('fm-save').addEventListener('click', function () {
        const ra       = parseFloat(document.getElementById('fm-ra').value);
        const dec      = parseFloat(document.getElementById('fm-dec').value);
        const rotation = parseFloat(document.getElementById('fm-rot').value);
        const setupId  = document.getElementById('fm-setup').value || null;

        const filtersSelected = fmGetSelectedFilters();

        fetch(FRAMING_URL.replace('XXXX', currentFramingTargetId), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ra, dec, rotation, setupId: setupId ? parseInt(setupId) : null, filtersSelected })
        })
        .then(r => r.json())
        .then(() => {
            const tgt = ALL_TARGETS.find(t => t.id == currentFramingTargetId);
            if (tgt) tgt.framing = { ra, dec, rotation, setupId: setupId ? parseInt(setupId) : null, filtersSelected };
            const btn = document.querySelector(`.btn-framing[data-id="${currentFramingTargetId}"] i`);
            if (btn) btn.className = 'fa fa-crop text-info';
            bootstrap.Modal.getInstance(document.getElementById('framingModal'))?.hide();
        });
    });
}
