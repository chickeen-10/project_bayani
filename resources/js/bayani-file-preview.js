const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[character]);

const officeThumbnailCache = new Map();
let officeThumbnailQueue = Promise.resolve();

export function isRenderableOfficeThumbnailResponse(response) {
    if (!response?.ok) return false;
    const type = response.headers?.get('content-type') || '';
    return /^image\/(jpeg|png|webp)$/i.test(type)
        || (/^image\/svg\+xml(?:;|$)/i.test(type) && response.headers?.get('X-Bayani-Thumbnail') === 'content');
}

function showOfficeThumbnailFallback(image) {
    if (!image?.isConnected || image.dataset.officeCaptureFailed === 'true') return;
    image.dataset.officeCaptureFailed = 'true';
    const cover = image.closest('.file-cover');
    if (!cover) return;
    const label = image.dataset.officeFormat === 'xlsx' ? 'Worksheet preview' : 'Document preview';
    cover.innerHTML = '<div class="file-fallback preview-unavailable-cover"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span class="file-fallback-title">' + label + '</span><span class="file-preview-note">Content thumbnail unavailable in this browser</span></div>';
}

async function captureOfficeThumbnail(image, format, sourceUrl, cacheKey, documentRef) {
    if (!image || image.dataset.officeCaptureStarted) return;
    image.dataset.officeCaptureStarted = 'true';
    const run = async () => {
        if (officeThumbnailCache.has(cacheKey)) { image.src = officeThumbnailCache.get(cacheKey); image.classList.remove('office-thumbnail-pending'); image.classList.add('is-content-thumbnail'); return; }
        const thumbnailUrl = sourceUrl.replace(/\/file(?:\?.*)?$/, '/thumbnail') + '?v=' + encodeURIComponent(cacheKey);
        const cacheUrl = sourceUrl.replace(/\/file(?:\?.*)?$/, '/thumbnail-cache');
        const cached = await fetch(thumbnailUrl, { credentials: 'same-origin', cache: 'no-store' }).catch(() => null);
        if (isRenderableOfficeThumbnailResponse(cached)) {
            image.src = thumbnailUrl;
            image.classList.remove('office-thumbnail-pending');
            image.classList.add('is-content-thumbnail');
            return;
        }
        const controller=new AbortController();
        const abortTimer=setTimeout(()=>controller.abort(),15000);
        let bytes;
        try {
            const response = await fetch(sourceUrl, { credentials: 'same-origin', cache: 'no-store', signal:controller.signal });
            if (!response.ok) throw new Error('Source attachment is not available for thumbnail rendering.');
            if (Number(response.headers.get('content-length') || 0) > 8 * 1024 * 1024) throw new Error('Source is above the thumbnail rendering limit.');
            bytes = await response.arrayBuffer();
        } finally { clearTimeout(abortTimer); }
        if (bytes.byteLength > 8 * 1024 * 1024) throw new Error('Source is above the thumbnail rendering limit.');
        const stage = documentRef.createElement('div');
        stage.setAttribute('aria-hidden','true');
        Object.assign(stage.style,{position:'fixed',left:'-10000px',top:'0',width:'800px',maxHeight:'900px',overflow:'hidden',background:'#fff',color:'#111',zIndex:'-1'});
        documentRef.body.append(stage);
        let target = stage;
        try {
            if (format === 'xlsx') {
                const [{default: ExcelJS}] = await Promise.all([import('exceljs'),import('html2canvas')]);
                await renderXlsxDocument(bytes,ExcelJS,stage,documentRef);
                target = stage.querySelector('[role="tabpanel"]') || stage;
                target.style.maxWidth='800px'; target.style.maxHeight='900px'; target.style.overflow='hidden';
            } else {
                const [{renderAsync}] = await Promise.all([import('docx-preview'),import('html2canvas')]);
                await renderAsync(bytes,stage,undefined,{inWrapper:false,ignoreWidth:false,ignoreHeight:false,breakPages:true,experimental:false});
                await Promise.race([documentRef.fonts?.ready || Promise.resolve(),new Promise(resolve=>setTimeout(resolve,1800))]);
                await Promise.race([
                    Promise.all([...stage.querySelectorAll('img')].map(item=>item.decode ? item.decode().catch(()=>{}) : Promise.resolve())),
                    new Promise(resolve=>setTimeout(resolve,3500)),
                ]);
                target = stage.querySelector('.docx') || stage.querySelector('.docx-wrapper') || stage;
                target.style.maxWidth='800px'; target.style.maxHeight='900px'; target.style.overflow='hidden';
            }
            const frame=documentRef.defaultView?.requestAnimationFrame?.bind(documentRef.defaultView)||(callback=>setTimeout(callback,16));
            await new Promise(resolve=>frame(()=>frame(resolve)));
            const {default: html2canvas} = await import('html2canvas');
            const rect=target.getBoundingClientRect();
            const width=Math.min(800,Math.max(1,Math.ceil(rect.width || target.scrollWidth)));
            const height=Math.min(900,Math.max(1,Math.ceil(rect.height || target.scrollHeight)));
            const captured=await html2canvas(target,{backgroundColor:'#fff',scale:1,useCORS:false,allowTaint:false,logging:false,width,height,windowWidth:800,windowHeight:900});
            const ratio=Math.min(1,600/captured.width,720/captured.height);
            const canvas=documentRef.createElement('canvas'); canvas.width=Math.max(1,Math.floor(captured.width*ratio)); canvas.height=Math.max(1,Math.floor(captured.height*ratio));
            canvas.getContext('2d',{alpha:false}).drawImage(captured,0,0,canvas.width,canvas.height);
            const result=canvas.toDataURL('image/jpeg',0.78);
            officeThumbnailCache.set(cacheKey,result);
            while(officeThumbnailCache.size>12) officeThumbnailCache.delete(officeThumbnailCache.keys().next().value);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.78));
            let saved = false;
            if (blob && blob.size <= 512 * 1024) {
                const form = new FormData();
                form.append('thumbnail', blob, 'thumbnail.jpg');
                const token = documentRef.querySelector('meta[name="csrf-token"]')?.content;
                try {
                    const response = await fetch(cacheUrl, { method: 'POST', credentials: 'same-origin', headers: token ? { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } : { 'Accept': 'application/json' }, body: form });
                    saved = response.ok;
                } catch (_) { /* This visit still uses its in-memory capture. */ }
            }
            image.src=saved ? thumbnailUrl : result;
            image.classList.remove('office-thumbnail-pending');
            image.classList.add('is-content-thumbnail');
            captured.width=0; captured.height=0; canvas.width=0; canvas.height=0;
        } finally { stage.remove(); }
    };
    officeThumbnailQueue=officeThumbnailQueue.catch(()=>{}).then(run);
    try { await officeThumbnailQueue; }
    catch (_) { showOfficeThumbnailFallback(image); }
}

function scheduleOfficeThumbnail(image, sourceName, sourceUrl, key, documentRef) {
    const extension=String(sourceName||'').split('.').pop().toLowerCase();
    if(!['docx','xlsx'].includes(extension)) return;
    image.dataset.officeFormat=extension;
    const load=()=>void captureOfficeThumbnail(image,extension,sourceUrl,key,documentRef);
    const Observer=documentRef.defaultView?.IntersectionObserver;
    if(typeof Observer==='undefined'){load();return;}
    const observer=new Observer(entries=>{
        if(entries.some(entry=>entry.isIntersecting)){observer.disconnect();load();}
    },{rootMargin:'160px'});
    observer.observe(image);
}

function scheduleVideoPoster(cover, material, documentRef) {
    if (!cover || cover.dataset.videoPosterStarted === 'true') return;
    cover.dataset.videoPosterStarted = 'true';
    const title = material.title || 'video';
    let video;
    const fallback = (message) => {
        if (!cover.isConnected) return;
        cover.dataset.videoPosterFailed = 'true';
        cover.innerHTML = '<div class="file-fallback video-cover" role="img" aria-label="Video poster unavailable"><i class="bi bi-play-btn" aria-hidden="true"></i><span class="file-fallback-title">Video preview</span><span class="file-preview-note">' + message + '</span></div>';
    };
    const capture = () => {
        if (!video.isConnected || !video.videoWidth || !video.videoHeight) return fallback('Poster frame unavailable in this browser');
        try {
            const ratio = Math.min(1, 480 / video.videoWidth, 270 / video.videoHeight);
            const canvas = documentRef.createElement('canvas');
            canvas.width = Math.max(1, Math.round(video.videoWidth * ratio));
            canvas.height = Math.max(1, Math.round(video.videoHeight * ratio));
            const context = canvas.getContext('2d', { alpha: false });
            if (!context) throw new Error('Canvas capture is unavailable');
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            const image = documentRef.createElement('img');
            image.className = 'file-thumbnail video-poster';
            image.alt = 'Frame from ' + title;
            image.src = canvas.toDataURL('image/jpeg', .82);
            cover.replaceChildren(image);
            canvas.toBlob(async blob => {
                if (!blob || blob.size > 512 * 1024) { canvas.width = 0; canvas.height = 0; return; }
                const form = new FormData();
                form.append('thumbnail', blob, 'poster.jpg');
                const token = documentRef.querySelector('meta[name="csrf-token"]')?.content;
                const base = '/materials/' + encodeURIComponent(material.id);
                try { await fetch(base + '/thumbnail-cache', { method: 'POST', credentials: 'same-origin', headers: token ? { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } : { 'Accept': 'application/json' }, body: form }); } catch (_) { /* Keep the real frame for this visit. */ }
                finally { canvas.width = 0; canvas.height = 0; }
            }, 'image/jpeg', .82);
            video.removeAttribute('src'); video.load(); video.remove();
        } catch (_) { fallback('Poster frame unavailable in this browser'); }
    };
    const start = () => {
        const base = '/materials/' + encodeURIComponent(material.id);
        const cacheKey = 'material:' + material.id + ':' + (material.updated_at || '') + ':' + (material.original_filename || '') + ':' + (material.file_size || '');
        const thumbnailUrl = base + '/thumbnail?v=' + encodeURIComponent(cacheKey);
        fetch(thumbnailUrl, { credentials: 'same-origin' }).then(async response => {
            if (response.ok && /^image\/(jpeg|png|webp)$/i.test(response.headers.get('content-type') || '')) {
                const image = documentRef.createElement('img');
                image.className = 'file-thumbnail video-poster'; image.alt = 'Frame from ' + title; image.src = thumbnailUrl;
                cover.replaceChildren(image); cover.dataset.videoPosterReady = 'true';
                return true;
            }
            return false;
        }).then(cached => { if (!cached && cover.isConnected && !cover.dataset.videoPosterReady) loadVideo(); }).catch(() => loadVideo());
    };
    const loadVideo = () => {
        video = documentRef.createElement('video');
        video.className = 'video-poster-source';
        video.muted = true;
        video.preload = 'metadata';
        video.playsInline = true;
        video.setAttribute('aria-hidden', 'true');
        video.tabIndex = -1;
        let finished = false;
        const fail = () => { if (finished) return; finished = true; clearTimeout(timer); video.removeAttribute('src'); video.load(); video.remove(); fallback('Poster frame unavailable (unsupported codec or capture)'); };
        const done = () => { if (finished) return; finished = true; clearTimeout(timer); capture(); };
        const timer = setTimeout(fail, 15000);
        video.addEventListener('loadedmetadata', () => {
            if (!video.videoWidth || !video.videoHeight) return fail();
            const frameTime = Number.isFinite(video.duration) && video.duration > 0 ? Math.min(1, video.duration / 2) : 0;
            try { video.currentTime = frameTime; } catch (_) { /* loadeddata below may still provide a frame */ }
        }, { once: true });
        video.addEventListener('seeked', done, { once: true });
        video.addEventListener('loadeddata', () => { if (video.currentTime === 0) done(); }, { once: true });
        video.addEventListener('error', fail, { once: true });
        cover.append(video);
        video.src = '/materials/' + encodeURIComponent(material.id) + '/preview?stream=1';
        video.load();
    };
    const Observer = documentRef.defaultView?.IntersectionObserver;
    if (typeof Observer === 'undefined') { start(); return; }
    const observer = new Observer(entries => {
        if (entries.some(entry => entry.isIntersecting)) { observer.disconnect(); start(); }
    }, { rootMargin: '120px' });
    observer.observe(cover);
}

export function enhanceOfficeAttachmentThumbnails(root) {
    if (!root) return;
    const documentRef = root.ownerDocument || root;
    for (const image of root.querySelectorAll('.attachment-thumb')) {
        const row = image.closest('[data-attachment-row]');
        const filename = row?.querySelector('strong')?.textContent || '';
        const sourceUrl = image.src.replace(/\/thumbnail(?:\?.*)?$/, '/file');
        const attachmentId = row?.getAttribute('data-attachment-row') || sourceUrl;
        const size = image.dataset.fileSize || '';
        const updated = image.dataset.sourceUpdated || '';
        const cacheKey = `attachment:${attachmentId}:${filename}:${size}:${updated}`;
        const thumbnailUrl = sourceUrl.replace(/\/file(?:\?.*)?$/, '/thumbnail');
        image.src = thumbnailUrl + '?v=' + encodeURIComponent(cacheKey);
        scheduleOfficeThumbnail(image, filename, sourceUrl, cacheKey, documentRef);
    }
}

function knownMaterials(backend) {
    const collections = [backend?.featured, backend?.materials?.data, backend?.materials, backend?.material];
    return collections.flatMap(items => Array.isArray(items) ? items : items?.id ? [items] : []);
}

function extensionOf(material) {
    return String(material?.original_filename || '').split('.').pop().toLowerCase();
}

function renderSpreadsheet(data) {
    const sheets = data?.sheets || [];
    if (!sheets.length) return '<div class="alert alert-warning">No readable worksheets were found.</div>';
    let html = '<div class="alert alert-warning" role="status"><strong>Text-only worksheet fallback.</strong> This server has no configured Office document renderer, so cell formatting, merged-cell appearance, images, and print pagination may differ from the workbook. Saved values are shown without executing formulas.</div><div class="preview-toolbar d-flex flex-wrap align-items-center gap-2 mb-3"><div class="btn-group" role="tablist" aria-label="Worksheet tabs">';
    sheets.forEach((sheet, index) => {
        html += '<button class="btn btn-sm ' + (index === 0 ? 'btn-primary' : 'btn-light border') + '" type="button" role="tab" id="sheet-tab-' + index + '" aria-controls="sheet-panel-' + index + '" aria-selected="' + String(index === 0) + '" data-sheet-index="' + index + '">' + escape(sheet.name || ('Worksheet ' + (index + 1))) + '</button>';
    });
    html += '</div><div class="btn-group ms-auto" role="group" aria-label="Preview zoom"><button type="button" class="btn btn-sm btn-light border" data-preview-zoom="-1" aria-label="Zoom out">A−</button><button type="button" class="btn btn-sm btn-light border" data-preview-zoom="reset" aria-label="Reset zoom">100%</button><button type="button" class="btn btn-sm btn-light border" data-preview-zoom="1" aria-label="Zoom in">A+</button></div></div>';
    sheets.forEach((sheet, index) => {
        html += '<section class="spreadsheet-panel" role="tabpanel" id="sheet-panel-' + index + '" aria-labelledby="sheet-tab-' + index + '"' + (index === 0 ? '' : ' hidden') + '><div class="table-responsive spreadsheet-scroll" tabindex="0" aria-label="' + escape(sheet.name || 'Worksheet') + ' cell values"><table class="table table-sm table-bordered spreadsheet-grid" data-preview-scale><tbody>';
        (sheet.rows || []).forEach(row => {
            html += '<tr>';
            row.forEach((value, column) => {
                html += column === 0 ? '<th scope="row">' + escape(value) + '</th>' : '<td>' + escape(value) + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        if (sheet.truncated) html += '<p class="small text-secondary">This worksheet preview is limited to the first 140 rows and 28 columns.</p>';
        html += '</section>';
    });
    if (data?.truncated) html += '<p class="small text-secondary">Large workbook preview is limited to 12 worksheets and 18,000 cells.</p>';
    return html;
}

function renderDocument(data) {
    const blocks = data?.blocks || [];
    if (!blocks.length) return '<div class="alert alert-warning">No readable document content was found.</div>';
    let html = '<div class="alert alert-warning" role="status"><strong>Text-only document fallback.</strong> This server has no configured Office document renderer, so page layout, fonts, images, and pagination may differ from the original. Embedded scripts and active content are not executed.</div><div class="preview-toolbar d-flex justify-content-end gap-2 mb-3" role="group" aria-label="Preview zoom"><button type="button" class="btn btn-sm btn-light border" data-preview-zoom="-1" aria-label="Zoom out">A−</button><button type="button" class="btn btn-sm btn-light border" data-preview-zoom="reset" aria-label="Reset zoom">100%</button><button type="button" class="btn btn-sm btn-light border" data-preview-zoom="1" aria-label="Zoom in">A+</button></div><article class="docx-content" data-preview-scale>';
    blocks.forEach(block => {
        if (block.type === 'table') {
            html += '<div class="table-responsive mb-4"><table class="table table-bordered docx-table"><tbody>';
            (block.rows || []).forEach(row => {
                html += '<tr>' + row.map(cell => '<td>' + escape(cell) + '</td>').join('') + '</tr>';
            });
            html += '</tbody></table></div>';
        } else if (block.type === 'heading') {
            const level = Math.min(6, Math.max(1, Number(block.level) || 2));
            html += '<h' + level + ' class="docx-heading">' + escape(block.text) + '</h' + level + '>';
        } else if (block.type === 'list-item') {
            html += '<ul class="docx-list"><li>' + escape(block.text) + '</li></ul>';
        } else {
            html += '<p>' + escape(block.text || '') + '</p>';
        }
    });
    if (data?.truncated) html += '<p class="small text-secondary">Preview limited to the first 500 KB of readable text.</p>';
    return html + '</article>';
}

export function renderFilePreview(backend) {
    const material = backend?.material || {};
    const filename = escape(material.original_filename || 'Attachment');
    const title = escape(material.title || 'Educational resource');
    const format = escape(String(backend?.format || 'file').toUpperCase());
    const size = Number(material.file_size || 0).toLocaleString();
    const mediaUrl = escape(backend?.preview_url ? (backend.preview_url.includes('stream=') ? backend.preview_url : backend.preview_url + (backend.preview_url.includes('?') ? '&' : '?') + 'stream=1') : '');
    const video = ['mp4','webm'].includes(backend?.format);
    const audio = ['mp3','wav','ogg'].includes(backend?.format);
    const preview = backend?.preview_error
        ? '<div class="alert alert-warning" role="status">' + escape(backend.preview_error) + '</div>'
        : ['xlsx','docx'].includes(backend?.format) ? '<div class="alert alert-info" role="status">Loading browser preview of original file contents…</div>'
                : video ? '<div class="media-preview"><video class="media-video-player" controls playsinline preload="metadata" aria-label="Video preview"><source src="' + mediaUrl + '" type="' + (backend.format === 'mp4' ? 'video/mp4' : 'video/webm') + '"></video><p class="media-preview-error small text-secondary" role="status" hidden>This video could not be played. Its codec may not be supported by this browser. Download the original to try another player.</p></div>'
                    : audio ? '<div class="media-preview"><audio class="media-audio-player" controls preload="metadata" aria-label="Audio preview"><source src="' + mediaUrl + '" type="' + ({mp3:'audio/mpeg',wav:'audio/wav',ogg:'audio/ogg'}[backend.format]) + '"></audio><p class="media-preview-error small text-secondary" role="status" hidden>This audio could not be played. Its codec may not be supported by this browser. Download the original to try another player.</p></div>'
                        : '<div class="alert alert-warning">This file format is available as a download only.</div>';
    return '<header class="page-heading"><div><p class="eyebrow">RESOURCE PREVIEW</p><h1 class="page-title">' + title + '</h1><p class="text-secondary mb-0">' + format + ' · ' + filename + '</p></div><a class="btn btn-light border" href="/materials/' + encodeURIComponent(material.id) + '">Back to resource</a></header><section class="panel p-3 p-lg-4 file-preview-viewer"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">' + filename + '</h2><span class="small-label">' + format + ' preview · ' + size + ' bytes</span></div><a class="btn btn-primary" href="' + escape(backend?.download_url || ('/materials/' + encodeURIComponent(material.id) + '/file')) + '">Download original</a></div>' + preview + '</section>';
}

export async function renderPdfDocument(sourceUrl, pdfjs, host, documentRef = document) {
    const task = pdfjs.getDocument({ url: sourceUrl, isEvalSupported: false, useSystemFonts: true, rangeChunkSize: 65536, disableAutoFetch: true, disableStream: false });
    const pdf = await task.promise;
    let pageNumber = 1;
    let scale = 1;
    const toolbar = documentRef.createElement('div');
    toolbar.className = 'd-flex align-items-center justify-content-end gap-2 mb-2';
    toolbar.innerHTML = '<button type="button" class="btn btn-sm btn-light border" data-pdf-prev aria-label="Previous page">Previous</button><span class="small" data-pdf-page aria-live="polite"></span><button type="button" class="btn btn-sm btn-light border" data-pdf-next aria-label="Next page">Next</button><button type="button" class="btn btn-sm btn-light border" data-pdf-zoom="-1" aria-label="Zoom out">−</button><button type="button" class="btn btn-sm btn-light border" data-pdf-zoom="1" aria-label="Zoom in">+</button>';
    const canvas = documentRef.createElement('canvas');
    canvas.className = 'pdf-preview-page';
    host.replaceChildren(toolbar, canvas);
    async function draw() {
        const page = await pdf.getPage(pageNumber);
        const base = page.getViewport({ scale: 1 });
        const width = Math.max(240, host.clientWidth - 24);
        const viewport = page.getViewport({ scale: Math.min(2.5, Math.max(.5, width / base.width) * scale) });
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.ceil(viewport.width * ratio);
        canvas.height = Math.ceil(viewport.height * ratio);
        canvas.style.width = `${Math.ceil(viewport.width)}px`;
        canvas.style.height = `${Math.ceil(viewport.height)}px`;
        const context = canvas.getContext('2d');
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        await page.render({ canvasContext: context, viewport }).promise;
        toolbar.querySelector('[data-pdf-page]').textContent = `Page ${pageNumber} of ${pdf.numPages}`;
        toolbar.querySelector('[data-pdf-prev]').disabled = pageNumber <= 1;
        toolbar.querySelector('[data-pdf-next]').disabled = pageNumber >= pdf.numPages;
    }
    toolbar.addEventListener('click', event => {
        if (event.target.closest('[data-pdf-prev]')) pageNumber = Math.max(1, pageNumber - 1);
        if (event.target.closest('[data-pdf-next]')) pageNumber = Math.min(pdf.numPages, pageNumber + 1);
        if (event.target.closest('[data-pdf-zoom]')) scale = Math.min(2, Math.max(.6, scale + Number(event.target.closest('[data-pdf-zoom]').dataset.pdfZoom * .2)));
        void draw();
    });
    await draw();
    return () => task.destroy();
}

export async function renderXlsxDocument(arrayBuffer, ExcelJS, host, documentRef = document) {
    const workbook = new ExcelJS.Workbook();
    const bytes = await arrayBuffer;
    await workbook.xlsx.load(bytes);
    host.replaceChildren();
    const tabs = documentRef.createElement('div');
    tabs.className = 'd-flex flex-wrap gap-2 mb-3';
    tabs.setAttribute('role','tablist');
    tabs.setAttribute('aria-label','Worksheets');
    const panels = [];
    workbook.worksheets.slice(0,12).forEach((sheet,index) => {
        const button = documentRef.createElement('button');
        button.type = 'button'; button.className = 'btn btn-sm '+(index===0?'btn-primary':'btn-light border');
        button.textContent = sheet.name; button.setAttribute('role','tab'); button.setAttribute('aria-selected',String(index===0)); button.setAttribute('aria-controls',`xlsx-panel-${index}`);
        tabs.append(button);
        const panel = documentRef.createElement('div'); panel.id=`xlsx-panel-${index}`; panel.setAttribute('role','tabpanel'); panel.hidden = index!==0; panel.className='table-responsive spreadsheet-scroll';
        const table = documentRef.createElement('table'); table.className='table table-sm table-bordered spreadsheet-grid';
        const body = documentRef.createElement('tbody');
        const maxRow = Math.min(sheet.rowCount,140), maxCol = Math.min(sheet.columnCount,28);
        for(let r=1;r<=maxRow;r++) {
            const row=sheet.getRow(r), tr=documentRef.createElement('tr');
            if(row.height) tr.style.height=`${Math.min(row.height,120)}pt`;
            for(let c=1;c<=maxCol;c++) {
                const cell=row.getCell(c); const td=documentRef.createElement(r===1?'th':'td');
                let value=cell.text ?? '';
                const sourceValue=cell.value && typeof cell.value==='object' && 'result' in cell.value ? cell.value.result : cell.value;
                if(typeof sourceValue==='number' && typeof cell.numFmt==='string') {
                    const decimals=(cell.numFmt.match(/\.([0#]+)/)||[])[1]?.length ?? 0;
                    const formatted=sourceValue.toLocaleString(undefined,{minimumFractionDigits:decimals,maximumFractionDigits:decimals});
                    if(cell.numFmt.includes('%')) value=(sourceValue*100).toLocaleString(undefined,{minimumFractionDigits:decimals,maximumFractionDigits:decimals})+'%';
                    else if(/[$₱€£¥]/.test(cell.numFmt)) value=cell.numFmt.match(/[$₱€£¥]/)[0]+formatted;
                    else if(cell.numFmt.includes(',')) value=formatted;
                }
                td.textContent=value;
                if(cell.numFmt) td.title=`Number format: ${cell.numFmt}`;
                if(cell.fill?.type==='pattern' && typeof cell.fill.fgColor?.argb==='string') td.style.backgroundColor='#'+cell.fill.fgColor.argb.slice(-6);
                if(cell.font?.bold) td.style.fontWeight='700';
                if(cell.font?.italic) td.style.fontStyle='italic';
                if(cell.alignment?.horizontal) td.style.textAlign=cell.alignment.horizontal;
                for(const side of ['top','right','bottom','left']) {
                    const border=cell.border?.[side];
                    const color=border?.color?.argb?.slice(-6);
                    const safeColor=typeof color==='string' && /^[0-9a-f]{6}$/i.test(color) ? '#'+color : '#8a96a3';
                    const borderStyle={dashed:'dashed',dotted:'dotted',double:'double',hair:'solid',thin:'solid',medium:'solid',thick:'solid'}[border?.style];
                    if(borderStyle) td.style[`border${side[0].toUpperCase()+side.slice(1)}`]=`${border.style==='thick'?2:border.style==='medium'?1.5:1}px ${borderStyle} ${safeColor}`;
                }
                tr.append(td);
            }
            body.append(tr);
        }
        table.append(body); panel.append(table);
        const merged = sheet.model.merges || [];
        for (const range of merged) {
            const match=range.match(/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/i);
            if (!match) continue;
            const colIndex=letters=>[...letters.toUpperCase()].reduce((n,ch)=>n*26+ch.charCodeAt(0)-64,0)-1;
            const c0=colIndex(match[1]), r0=Number(match[2])-1, c1=colIndex(match[3]), r1=Number(match[4])-1;
            if(r0>=0&&r0<maxRow&&c0>=0&&c0<maxCol){const master=body.rows[r0]?.cells[c0];if(master){master.rowSpan=Math.min(r1-r0+1,maxRow-r0);master.colSpan=Math.min(c1-c0+1,maxCol-c0);}}
            for(let rr=r0;rr<=Math.min(r1,maxRow-1);rr++)for(let cc=c0;cc<=Math.min(c1,maxCol-1);cc++)if(rr!==r0||cc!==c0){const cell=body.rows[rr]?.cells[cc];cell?.remove();}
        }
        for(let c=1;c<=maxCol;c++){const width=sheet.getColumn(c).width;if(width)table.querySelectorAll(`tr > :nth-child(${c})`).forEach(cell=>{cell.style.width=`${Math.min(width*7,420)}px`;cell.style.minWidth=`${Math.min(width*7,420)}px`;});}
        if(sheet.rowCount>maxRow || sheet.columnCount>maxCol) { const note=documentRef.createElement('p'); note.className='small text-secondary'; note.textContent='Preview limited to 140 rows and 28 columns.'; panel.append(note); }
        panels.push(panel);
        button.addEventListener('click',()=>{panels.forEach((p,i)=>p.hidden=i!==index);[...tabs.children].forEach((b,i)=>{b.setAttribute('aria-selected',String(i===index));b.className='btn btn-sm '+(i===index?'btn-primary':'btn-light border');});});
    });
    host.append(tabs,...panels);
}

export function enhanceDatabasePreviews(root, backend) {
    if (!root || !backend?.kind) return;
    const documentRef = root.ownerDocument || root;

    for (const card of root.querySelectorAll('.material-card')) {
        const detailLink = card.querySelector('h3 a[href^="/materials/"]');
        const id = detailLink?.getAttribute('href')?.match(/^\/materials\/(\d+)/)?.[1];
        const material = knownMaterials(backend).find(item => String(item.id) === String(id));
        if (!material?.has_file) continue;
        const cover = card.querySelector('.file-cover');
        if (!cover) continue;
        const extension = extensionOf(material);
        if (['mp3','wav','ogg'].includes(extension)) {
            cover.innerHTML = '<div class="file-fallback audio-cover" role="img" aria-label="Audio file"><i class="bi bi-file-music" aria-hidden="true"></i><span class="file-fallback-kicker">Audio</span><span class="file-fallback-title">' + escape(material.title || 'Audio attachment') + '</span><span class="file-preview-note">Audio preview</span></div>';
            continue;
        }
        if (extension === 'pptx') {
            cover.innerHTML = '<div class="file-fallback download-only-cover"><span class="file-fallback-kicker">PowerPoint</span><span class="file-fallback-title">Slide preview is unavailable</span><span class="file-preview-note">Download to view this presentation</span></div>';
        } else if (['xlsx', 'docx'].includes(extension)) {
            const image = documentRef.createElement('img');
            image.className = 'file-thumbnail';
            image.alt = 'Rendered ' + (extension === 'xlsx' ? 'worksheet' : 'document') + ' content for ' + (material.title || 'educational resource');
            image.decoding = 'async';
            image.classList.add('office-thumbnail-pending');
            image.dataset.officeFormat = extension;
            cover.replaceChildren(image);
            scheduleOfficeThumbnail(image,material.original_filename,'/materials/' + encodeURIComponent(material.id) + '/file',`material:${material.id}:${material.updated_at||''}:${material.original_filename}:${material.file_size}`,documentRef);
        } else if (extension === 'mp4' || extension === 'webm') {
            cover.innerHTML = '<div class="file-fallback video-cover" role="img" aria-label="Video preview is loading"><i class="bi bi-play-btn" aria-hidden="true"></i><span class="file-fallback-title">Video preview</span></div>';
            scheduleVideoPoster(cover, material, documentRef);
        } else {
            const image = documentRef.createElement('img');
            image.className = 'file-thumbnail';
            image.alt = 'Content preview for ' + (material.title || 'educational resource');
            image.loading = 'lazy';
            image.decoding = 'async';
            image.src = '/materials/' + encodeURIComponent(material.id) + '/thumbnail?v=' + encodeURIComponent(`material:${material.id}:${material.updated_at||''}:${material.original_filename||''}:${material.file_size||''}`);
            image.addEventListener('error', () => {
                cover.innerHTML = '<div class="file-fallback preview-unavailable-cover"><span class="file-fallback-title">Content thumbnail unavailable</span><span class="file-preview-note">Open the resource preview or download the original</span></div>';
            }, { once: true });
            cover.replaceChildren(image);
        }
        if (detailLink && !cover.closest('a')) {
            const link = documentRef.createElement('a');
            link.className = 'material-cover-link';
            link.href = detailLink.href;
            link.setAttribute('aria-label', 'Open resource: ' + (material.title || 'educational resource'));
            cover.before(link);
            link.append(cover);
        }
    }

    if (backend.kind === 'material' && backend.material?.has_file) {
        const material = backend.material;
        const extension = extensionOf(material);
        const placeWidePreview = node => {
            const row = root.querySelector('main .row.g-4, .row.g-4');
            if (!row || !node) return;
            const section = documentRef.createElement('section');
            section.className = 'resource-preview resource-preview-wide';
            section.setAttribute('aria-label', 'Resource preview');
            section.append(node);
            row.after(section);
        };
        const detailCover = root.querySelector('.file-cover-detail');
        if (detailCover && ['mp3','wav','ogg'].includes(extension)) {
            detailCover.innerHTML = '<div class="file-fallback audio-cover" role="img" aria-label="Audio file"><i class="bi bi-file-music" aria-hidden="true"></i><span class="file-fallback-kicker">Audio</span><span class="file-fallback-title">' + escape(material.title || 'Audio attachment') + '</span><span class="file-preview-note">Audio preview</span></div>';
        } else if (detailCover && extension !== 'pptx' && !detailCover.querySelector('.file-thumbnail')) {
            const image = documentRef.createElement('img');
            image.className = 'file-thumbnail';
            image.alt = 'Actual file content thumbnail for ' + (material.original_filename || material.title || 'resource');
            image.loading = 'eager';
            image.decoding = 'async';
            image.src = '/materials/' + encodeURIComponent(material.id) + '/thumbnail?v=' + encodeURIComponent(`material:${material.id}:${material.updated_at||''}:${material.original_filename||''}:${material.file_size||''}`);
            image.addEventListener('error', () => detailCover.replaceChildren(), { once: true });
            detailCover.replaceChildren(image);
            if(['xlsx','docx'].includes(extension)) { image.classList.add('office-thumbnail-pending'); scheduleOfficeThumbnail(image,material.original_filename,image.src.replace(/\/thumbnail(?:\?.*)?$/,'/file'),`material:${material.id}:${material.updated_at||''}:${material.original_filename}:${material.file_size}`,documentRef); }
        }
        if (extension === 'mp4' || extension === 'webm') {
            const cover = detailCover || root.querySelector('.file-cover');
            if (cover) scheduleVideoPoster(cover, material, documentRef);
        }
        const downloadLink = [...root.querySelectorAll('a[href]')].find(link => {
            try {
                return /\/materials\/\d+(?:\/attachments\/\d+)?\/file$/.test(new URL(link.href, documentRef.baseURI).pathname);
            } catch {
                return false;
            }
        });
        const actions = downloadLink?.closest('.d-flex');
        if (actions && !actions.querySelector('[data-resource-preview-link]') && extension !== 'pptx') {
            const previewLink = documentRef.createElement('a');
            previewLink.className = 'btn btn-primary';
            previewLink.href = '/materials/' + encodeURIComponent(material.id) + '/preview';
            previewLink.textContent = 'Preview resource';
            previewLink.dataset.resourcePreviewLink = '';
            actions.prepend(previewLink);
            downloadLink.className = 'btn btn-outline-primary';
            downloadLink.textContent = 'Download original';
            downloadLink.removeAttribute('download');
        }
        if (extension === 'pdf') {
            const frame = root.querySelector('.doc-frame');
            if (frame && !frame.closest('.resource-preview-wide')) placeWidePreview(frame);
        } else if (['png', 'jpg', 'jpeg', 'webp'].includes(extension)) {
            const image = root.querySelector('.panel img[src*="/preview"]');
            if (image && !image.closest('.resource-preview-wide')) placeWidePreview(image);
        } else if (extension === 'pptx') {
            const placeholder = root.querySelector('.alert-light.border');
            if (placeholder) placeholder.textContent = 'PowerPoint slide rendering is not enabled; download the original presentation to view it.';
        }
    }

    enhanceOfficeAttachmentThumbnails(root);
}

export function installPreviewInteractions(documentRef) {
documentRef.addEventListener('click', event => {
    const tab = event.target.closest('[data-sheet-index]');
    if (tab) {
        const viewer = tab.closest('.file-preview-viewer');
        const index = tab.dataset.sheetIndex;
        viewer?.querySelectorAll('[data-sheet-index]').forEach(button => {
            const selected = button === tab;
            button.setAttribute('aria-selected', String(selected));
            button.classList.toggle('btn-primary', selected);
            button.classList.toggle('btn-light', !selected);
            button.classList.toggle('border', !selected);
        });
        viewer?.querySelectorAll('[role="tabpanel"]').forEach(panel => {
            panel.hidden = panel.id !== 'sheet-panel-' + index;
        });
        return;
    }

    const zoomButton = event.target.closest('[data-preview-zoom]');
    if (zoomButton) {
        const viewer = zoomButton.closest('.file-preview-viewer');
        const target = viewer?.querySelector('[data-preview-scale]');
        if (!target) return;
        const current = Number(target.dataset.zoom || 1);
        const next = zoomButton.dataset.previewZoom === 'reset' ? 1 : Math.max(0.75, Math.min(1.75, current + Number(zoomButton.dataset.previewZoom) * 0.15));
        target.dataset.zoom = String(next);
        target.style.setProperty('--preview-scale', String(next));
        return;
    }
});

documentRef.addEventListener('error', event => {
    if (event.target.matches?.('.media-preview video, .media-preview audio, .media-preview source')) {
        const host = event.target.closest('.media-preview');
        const message = host?.querySelector('.media-preview-error');
        if (message) message.hidden = false;
    }
}, true);

documentRef.addEventListener('load', event => {
    if (event.target.matches?.('[data-preview-frame]')) {
        event.target.parentElement?.querySelector('.preview-frame-status')?.remove();
    }
}, true);
}

if (typeof document !== 'undefined') installPreviewInteractions(document);
