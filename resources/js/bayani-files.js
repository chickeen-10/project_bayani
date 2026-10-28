import { renderPdfCover } from "./bayani-pdf.js";
// Browser-only file adapter. Never substitute a bundled sample for missing bytes.
export function fileUrl(material, storage) {
    if (!material?.fileToken) return '';
    try {
        const value = (storage || globalThis.sessionStorage).getItem(`bayani.file.${material.fileToken}`) || '';
        return /^data:(application\/pdf|image\/(png|jpeg|webp));base64,/.test(value) ? value : '';
    } catch { return ''; }
}

export function fileLabel(material) {
    const extension = (material?.fileName || '').split('.').pop().toUpperCase();
    if (['PDF', 'PNG', 'JPG', 'JPEG', 'WEBP', 'DOC', 'DOCX', 'ODT'].includes(extension)) return extension;
    return { 'application/pdf': 'PDF', 'image/png': 'PNG', 'image/jpeg': 'JPG', 'image/webp': 'WEBP' }[material?.fileMime] || material?.fileType || 'FILE';
}

export function validateFile(file, settings) {
    if (!file) return '';
    const ext = file.name.split('.').pop().toUpperCase();
    const allowed = String(settings.allowedTypes).toUpperCase().split(/[,\s]+/);
    if (!allowed.includes(ext) && !(ext === 'JPEG' && allowed.includes('JPG'))) return 'This file type is not allowed by the repository settings.';
    if (!file.size) return 'This file is empty. Choose a file with content.';
    if (file.size > (Number(settings.maxUploadMb) || 10) * 1024 * 1024) return `Choose a file no larger than ${settings.maxUploadMb} MB.`;
    return '';
}

export const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

export function thumbnailMarkup(material, detail = false) {
    const label = fileLabel(material);
    return `<div class="file-cover ${detail ? 'file-cover-detail' : ''}" data-file-thumbnail="${escapeHtml(material.id || '')}">
        <div class="file-fallback"><span class="file-fallback-kicker">${escapeHtml(material.category || 'Learning resource')}</span><span class="file-fallback-type">${escapeHtml(label)}</span><span class="file-fallback-title">${escapeHtml(material.title || material.fileName || 'Selected document')}</span><span class="file-preview-note">Document cover · preview unavailable</span></div>
    </div>`;
}

const cache = new Map();
let pdfModule;
let queue = Promise.resolve();
async function firstPage(source) {
    pdfModule ||= Promise.all([import('pdfjs-dist/build/pdf.mjs'), import('pdfjs-dist/build/pdf.worker.min.mjs?url')]);
    const [pdfjs, worker] = await pdfModule;
    pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
    return renderPdfCover(source,pdfjs);
}

export async function paintThumbnail(host, source, title = 'File preview') {
    if (!host || !source) return;
    const request = Symbol();
    host.previewRequest = request;
    const pdf = source.startsWith('data:application/pdf;');
    host.classList.add('is-loading');
    host.setAttribute('aria-busy', 'true');
    try {
        let result = source;
        if (pdf) {
            if (!cache.has(source)) {
                // Serialize canvas work so a repository page cannot exhaust memory.
                const pending = queue.then(() => firstPage(source));
                queue = pending.catch(() => {});
                cache.set(source, pending);
                if (cache.size > 24) cache.delete(cache.keys().next().value);
            }
            result = await cache.get(source);
        }
        const image = new Image();
        image.alt = `${pdf ? 'First page of' : 'Image from'} ${title}`;
        image.className = `file-thumbnail ${pdf ? 'pdf-thumbnail' : ''}`;
        image.src = result;
        await image.decode();
        if (!host.isConnected || host.previewRequest !== request) return;
        host.querySelector('.file-thumbnail')?.remove();
        host.prepend(image);
        host.classList.add('has-preview');
        host.querySelector('.file-preview-caption')?.remove();
        const caption = document.createElement('span');
        caption.className = 'file-preview-caption';
        caption.textContent = pdf ? 'PDF · Page 1' : 'Original image';
        host.append(caption);
    } catch {
        if (host.previewRequest === request) {
            const note = host.querySelector('.file-preview-note');
            if (note) note.textContent = 'Preview unavailable · original file unchanged';
        }
    } finally {
        if (host.previewRequest === request) { host.classList.remove('is-loading'); host.removeAttribute('aria-busy'); }
    }
}

export function hydrateThumbnails(root, store) {
    root.querySelectorAll('[data-file-thumbnail]').forEach(host => {
        const material = store.find(host.dataset.fileThumbnail);
        if (material) void paintThumbnail(host, fileUrl(material), material.title);
    });
}
