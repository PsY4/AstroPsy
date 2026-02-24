/**
 * notes-editor.js — Éditeur de notes de session (Toast UI Editor)
 * Globals attendus : NOTES_CONFIG
 *   NOTES_CONFIG : { currentNotes, saveUrl, theme, trans: { placeholder, saveLabel } }
 * Nécessite que Toast UI Editor soit chargé avant ce script.
 */

(function () {
    if (typeof NOTES_CONFIG === 'undefined') return;

    let currentNotes = NOTES_CONFIG.currentNotes || '';
    const saveUrl    = NOTES_CONFIG.saveUrl;
    const theme      = NOTES_CONFIG.theme || 'dark';
    const placeholder = NOTES_CONFIG.trans ? NOTES_CONFIG.trans.placeholder : '';
    let editor = null;

    function refreshViewer(md) {
        const body = document.getElementById('notes-card-body');
        if (!body) return;
        if (md) {
            body.innerHTML = '<div id="notes-viewer"></div>';
            toastui.Editor.factory({ el: document.getElementById('notes-viewer'), viewer: true, initialValue: md, theme });
        } else {
            body.innerHTML = `<p class="text-muted fst-italic small mb-0">${placeholder}</p>`;
        }
    }

    // Init viewer au chargement
    if (currentNotes) {
        const viewerEl = document.getElementById('notes-viewer');
        if (viewerEl) {
            toastui.Editor.factory({ el: viewerEl, viewer: true, initialValue: currentNotes, theme });
        }
    }

    // Init éditeur à la première ouverture de la modal
    document.getElementById('notesModal')?.addEventListener('shown.bs.modal', function () {
        if (!editor) {
            editor = new toastui.Editor({
                el: document.getElementById('notes-editor-el'),
                height: '500px',
                usageStatistics: false,
                initialValue: currentNotes,
                theme,
                initialEditType: 'wysiwyg'
            });
        } else {
            editor.setMarkdown(currentNotes);
        }
    });

    document.getElementById('notes-save-btn')?.addEventListener('click', function () {
        if (!editor) return;
        const md      = editor.getMarkdown();
        const spinner = document.getElementById('notes-save-spinner');
        const btn     = this;
        spinner?.classList.remove('d-none');
        btn.disabled = true;
        fetch(saveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'notes=' + encodeURIComponent(md)
        })
        .then(r => r.json())
        .then(() => {
            currentNotes = md;
            refreshViewer(md);
            bootstrap.Modal.getInstance(document.getElementById('notesModal'))?.hide();
        })
        .catch(() => {})
        .finally(() => {
            spinner?.classList.add('d-none');
            btn.disabled = false;
        });
    });
})();
