import { escapeHtml, fileLabel, fileUrl, paintThumbnail, thumbnailMarkup, validateFile } from './bayani-files';

export function enhanceUpload(form, store) {
    if (!form) return;
    const input = form.querySelector('#attachment');
    const section = input.closest('section');
    const settings = store.systemSettings();
    const existing = store.find(form.dataset.editId);
    section.classList.add('upload-section');
    section.innerHTML = `<h2 class="h5">Attach your source material</h2><div class="upload-dropzone" id="upload-dropzone"><h3><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Choose a file or drop it here</h3><p>Images, PDFs and documents. Allowed: ${escapeHtml(settings.allowedTypes)} · up to ${escapeHtml(settings.maxUploadMb)} MB.</p><label class="visually-hidden" for="attachment">Select source file</label><input class="form-control" type="file" id="attachment" accept="${String(settings.allowedTypes).split(/[,\s]+/).filter(Boolean).map(x => '.' + escapeHtml(x.toLowerCase())).join(',')}"><p id="upload-error" class="upload-validation" role="alert"></p><div id="selected-file-preview" aria-live="polite"></div></div><p class="form-text mt-2">Files are not uploaded to a server. Only metadata is permanent in this browser. PDF/image files up to 2 MB may remain available in this tab until it closes; larger files and Word documents retain metadata only.</p>`;
    const field = section.querySelector('#attachment');
    const preview = section.querySelector('#selected-file-preview');
    const error = section.querySelector('#upload-error');
    const dropzone = section.querySelector('#upload-dropzone');
    let revision = 0;
    const showExisting = () => {
        preview.innerHTML = existing?.fileName ? `<p class="upload-file-info mt-3 mb-0">Current attachment: <strong>${escapeHtml(existing.fileName)}</strong>${fileUrl(existing) ? 'Available in this tab.' : 'Metadata only; file bytes are unavailable.'} Selecting a new file replaces this attachment when saved.</p>` : '';
    };
    showExisting();
    async function selected() {
        const version = ++revision;
        const file = field.files?.[0];
        error.textContent = validateFile(file, settings);
        if (error.textContent) { field.value = ''; showExisting(); return; }
        if (!file) { showExisting(); return; }
        const material = { fileName: file.name, fileMime: file.type, title: file.name, category: 'Selected file' };
        preview.innerHTML = `<div class="upload-selection">${thumbnailMarkup(material)}<div class="upload-file-info"><strong>${escapeHtml(file.name)}</strong><p>${escapeHtml(fileLabel(material))} · ${(file.size / 1024).toFixed(1)} KB<br>${file.size <= 2 * 1024 * 1024 && /^(image\/(png|jpeg|webp)|application\/pdf)$/.test(file.type) ? 'Temporary preview; retained only in this tab if storage permits.' : 'Metadata only after saving. This preview is temporary.'}</p><button type="button" class="btn btn-sm btn-outline-danger" id="remove-selected-file"><i class="bi bi-x-lg" aria-hidden="true"></i>Remove selected file</button></div></div>`;
        preview.querySelector('#remove-selected-file').addEventListener('click', () => { ++revision; field.value = ''; error.textContent = ''; showExisting(); });
        const type = form.querySelector('[name="fileType"]');
        if (type) type.value = file.type === 'application/pdf' ? 'PDF' : file.type.startsWith('image/') ? 'Image' : 'Document';
        if (!/^(image\/(png|jpeg|webp)|application\/pdf)$/.test(file.type)) return;
        try {
            const source = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(file); });
            if (revision === version) await paintThumbnail(preview.querySelector('.file-cover'), source, file.name);
        } catch { if (revision === version) error.textContent = 'Could not read the preview. You can still save the file metadata.'; }
    }
    field.addEventListener('change', selected);
    ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, e => { e.preventDefault(); dropzone.classList.add('is-dragging'); }));
    dropzone.addEventListener('dragleave', e => { if (!dropzone.contains(e.relatedTarget)) dropzone.classList.remove('is-dragging'); });
    dropzone.addEventListener('drop', e => {
        e.preventDefault(); dropzone.classList.remove('is-dragging');
        if (e.dataTransfer.files.length !== 1) { error.textContent = 'Choose one file per material.'; return; }
        try { field.files = e.dataTransfer.files; void selected(); } catch { error.textContent = 'Use the file chooser to select this file.'; }
    });
}
