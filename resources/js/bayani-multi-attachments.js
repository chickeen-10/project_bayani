const allowed = new Set(['pdf', 'docx', 'pptx', 'xlsx', 'jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'mp3', 'wav', 'ogg']);
const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

export function enhanceMultiAttachmentForm(form, material = {}, admin = false, maxMb = 20) {
    if (!form || form.dataset.multiAttachmentsReady) return;
    // The controller intentionally passes null for a new material. Default
    // parameters only cover undefined, so normalize nullable create payloads.
    material ||= {};
    form.dataset.multiAttachmentsReady = 'true';
    const doc = form.ownerDocument;
    let input = form.querySelector('#material-file');
    if (!input) return;
    const container = input.closest('.panel') || input.parentElement;
    const attachments = material.attachments || [];
    const basePath = admin ? `/admin/materials/${material.id}/attachments` : `/contributor/submissions/${material.id}/attachments`;
    container.innerHTML = `<h2 class="h5">Attachments</h2><p class="small text-secondary">Add up to 10 PDF, DOCX, PPTX, XLSX, JPG, PNG, WEBP, MP4, WebM, MP3, WAV, or OGG files. ${material.id ? 'Existing files remain attached when you add more.' : 'At least one file is required to submit. Save Draft can be used without a file.'} Limit: ${maxMb} MB each, 38 MB total.</p><div class="existing-attachments">${attachments.map(a => `<article class="attachment-row panel p-3 mb-2 d-flex flex-wrap align-items-center gap-3" data-attachment-row="${a.id}"><img class="attachment-thumb" src="/materials/${material.id}/attachments/${a.id}/thumbnail" alt="Content thumbnail for ${escape(a.original_filename)}"><div class="flex-grow-1"><strong>${escape(a.original_filename)}</strong><span class="small text-secondary d-block">${escape(a.mime_type || 'File')} · ${Number(a.file_size || 0).toLocaleString()} bytes</span><a class="btn btn-sm btn-outline-primary mt-2" target="_blank" rel="noopener" href="/materials/${material.id}/attachments/${a.id}/preview">Preview</a> <a class="btn btn-sm btn-outline-secondary mt-2" href="/materials/${material.id}/attachments/${a.id}/file">Download</a></div><div class="d-flex flex-column gap-2"><label class="form-check"><input class="form-check-input" type="radio" name="primary_attachment_id" value="${a.id}" ${a.is_primary ? 'checked' : ''} data-primary-attachment="${a.id}" data-url="${basePath}/${a.id}"><span class="form-check-label">Primary</span></label><label class="small">Order<input class="form-control form-control-sm" type="number" min="0" max="1000" value="${a.display_order}" data-order-attachment="${a.id}" data-url="${basePath}/${a.id}"></label><button type="button" class="btn btn-sm btn-outline-danger" data-delete-attachment="${a.id}" data-url="${basePath}/${a.id}">Remove</button></div></article>`).join('') || '<p class="text-secondary">No files attached yet.</p>'}</div><label class="form-label" for="material-file">Choose files</label><input class="form-control" type="file" id="material-file" name="attachments[]" multiple accept=".pdf,.docx,.pptx,.xlsx,.jpg,.jpeg,.png,.webp,.mp4,.webm,.mp3,.wav,.ogg"><p class="form-text" id="attachment-help">Choose files again to add to your selection, or drag files here.</p><p class="text-danger small" id="attachment-error" role="alert"></p><ul id="selected-attachments" class="list-group mt-3" aria-live="polite"></ul>`;
    input = form.querySelector('#material-file');
    const list = doc.createElement('ul');
    list.id = 'selected-attachments';
    list.className = 'list-group mt-3';
    list.setAttribute('aria-live', 'polite');
    const error = doc.createElement('p');
    error.id = 'attachment-error';
    error.className = 'text-danger small';
    error.setAttribute('role', 'alert');
    const fieldErrors = doc.defaultView?.BayaniFieldErrors || {};
    error.textContent = [...(fieldErrors.attachments || []), ...(fieldErrors.file || [])].join(' ');
    const help = form.querySelector('#attachment-help');
    input.name = 'attachments[]';
    input.multiple = true;
    input.accept = '.pdf,.docx,.pptx,.xlsx,.jpg,.jpeg,.png,.webp,.mp4,.webm,.mp3,.wav,.ogg';
    input.setAttribute('aria-describedby', `${help.id || 'attachment-help'} ${error.id}`);
    form.querySelector('#attachment-error').replaceWith(error);
    form.querySelector('#selected-attachments').replaceWith(list);
    let selected = [];
    const render = () => {
        const transfer = new DataTransfer();
        selected.forEach(file => transfer.items.add(file));
        input.files = transfer.files;
        list.innerHTML = selected.map((file, index) => `<li class="list-group-item d-flex flex-wrap align-items-center gap-2"><span class="flex-grow-1"><strong>${escape(file.name)}</strong><span class="small text-secondary d-block">${escape(file.type || file.name.split('.').pop().toUpperCase())} · ${(file.size / 1024).toFixed(1)} KB</span></span><button class="btn btn-sm btn-outline-danger" type="button" data-remove-selected="${index}">Remove</button></li>`).join('');
    };
    const add = files => {
        const incoming = Array.from(files || []);
        const key = file => `${file.name}\u0000${file.size}\u0000${file.lastModified}`;
        const known = new Set(selected.map(key));
        const uniqueIncoming = incoming.filter(file => {
            const fileKey = key(file);
            if (known.has(fileKey)) return false;
            known.add(fileKey);
            return true;
        });
        const next = [...selected, ...uniqueIncoming];
        const invalid = incoming.find(file => !allowed.has(file.name.split('.').pop().toLowerCase()) || file.size > maxMb * 1024 * 1024);
        if (invalid) { error.textContent = `“${invalid.name}” is unsupported or exceeds ${maxMb} MB.`; render(); return; }
        if (next.length > 10) { error.textContent = 'Choose no more than 10 attachments.'; render(); return; }
        if (next.reduce((sum, file) => sum + file.size, 0) > 38 * 1024 * 1024) { error.textContent = 'Combined selected files may not exceed 38 MB.'; render(); return; }
        error.textContent = '';
        selected = next;
        render();
    };
    input.addEventListener('change', () => { add(input.files); });
    list.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-selected]');
        if (!button) return;
        selected.splice(Number(button.dataset.removeSelected), 1);
        error.textContent = '';
        render();
    });
    const zone = input.closest('#attachment-dropzone, .upload-dropzone, section');
    if (zone) {
        for (const type of ['dragenter', 'dragover']) zone.addEventListener(type, event => { event.preventDefault(); zone.classList.add('is-dragging'); });
        zone.addEventListener('dragleave', event => { if (!zone.contains(event.relatedTarget)) zone.classList.remove('is-dragging'); });
        zone.addEventListener('drop', event => { event.preventDefault(); zone.classList.remove('is-dragging'); add(event.dataTransfer?.files); });
    }
    const csrf = form.querySelector('[name="_token"]')?.value || '';
    form.addEventListener('change', async event => {
        const primary = event.target.closest('[data-primary-attachment]');
        if (primary) {
            try { await updateAttachmentAction(primary, csrf, {url: primary.dataset.url, body: {is_primary: true}}); location.reload(); }
            catch { error.textContent = 'Could not change the primary attachment. Retry.'; }
        }
        const order = event.target.closest('[data-order-attachment]');
        if (order) {
            try { await updateAttachmentAction(order, csrf, {url: order.dataset.url, body: {display_order: Number(order.value)}}); }
            catch { error.textContent = 'Could not save attachment order. Retry.'; }
        }
    });
    form.addEventListener('click', async event => {
        const remove = event.target.closest('[data-delete-attachment]');
        if (!remove) return;
        if (!window.confirm('Permanently remove this attachment?')) return;
        remove.disabled = true;
        try {
            const response = await fetch(remove.dataset.url, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}});
            if (!response.ok) throw new Error('Remove failed');
            location.reload();
        } catch { remove.disabled = false; error.textContent = 'Could not remove the attachment. Retry.'; }
    });
    form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        const submit = form.querySelector('button[type="submit"],button:not([type])');
        if (submit) { submit.disabled = true; submit.setAttribute('aria-busy', 'true'); submit.dataset.originalLabel = submit.textContent; submit.textContent = 'Uploading attachments…'; }
    }, { once: true });
}

export async function updateAttachmentAction(button, csrf, action) {
    const response = await fetch(action.url, {method: action.method || 'PATCH', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify(action.body || {})});
    if (!response.ok) throw new Error('Attachment update failed');
    return response;
}
