import test from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { enhanceDatabasePreviews, enhanceOfficeAttachmentThumbnails, installPreviewInteractions, renderFilePreview } from '../../resources/js/bayani-file-preview.js';
import ExcelJS from 'exceljs';
import { renderXlsxDocument } from '../../resources/js/bayani-file-preview.js';

test('spreadsheet preview loads the original workbook and keeps download separate', () => {
    const html = renderFilePreview({
        format: 'xlsx',
        material: { id: 7, title: 'Quarter report', original_filename: 'report.xlsx', file_size: 1234 },
        download_url: '/materials/7/file',
        preview_data: { sheets: [
            { name: 'Results', rows: [['Quarter', 'Score'], ['Q1', '98']] },
            { name: 'Notes', rows: [['Teacher notes']] },
        ] },
    });

    assert.match(html, /Loading browser preview of original file contents/);
    assert.match(html, /href="\/materials\/7\/file">Download original/);
    assert.doesNotMatch(html, /href="[^\"]+" download=/);
});

test('spreadsheet payload is not copied into markup before safe workbook parsing', () => {
    const html = renderFilePreview({
        format: 'xlsx',
        material: { id: 8, title: 'Safe sheet', original_filename: 'safe.xlsx' },
        preview_data: { sheets: [{ name: 'Cells', rows: [['<img src=x onerror=alert(1)>', 'cached result']] }] },
    });

    assert.doesNotMatch(html, /img src=x/);
    assert.doesNotMatch(html, /<img src=x/);
});

test('native media controls do not autoplay and use an explicit streaming URL and separate download', () => {
    for(const [format,tag,mime] of [['webm','video','video/webm'],['ogg','audio','audio/ogg']]) {
        const html=renderFilePreview({format,material:{id:4,title:'Lesson',original_filename:`lesson.${format}`},preview_url:'/materials/4/attachments/2/preview',download_url:'/materials/4/attachments/2/file'});
        assert.match(html,new RegExp(`<${tag}[^>]*controls`));
        assert.doesNotMatch(html,new RegExp(`<${tag}[^>]*autoplay`));
        assert.match(html,new RegExp(`type="${mime}"`));
        assert.match(html,/href="\/materials\/4\/attachments\/2\/file">Download original/);
    }
});

test('ExcelJS renders actual worksheet values, tab changes, style, and merged cells safely', async () => {
    const workbook=new ExcelJS.Workbook();
    const sheet=workbook.addWorksheet('Actual values');
    sheet.getCell('A1').value='Real heading';sheet.getCell('A1').font={bold:true};sheet.getCell('A1').fill={type:'pattern',pattern:'solid',fgColor:{argb:'FFFFCC00'}};
    sheet.mergeCells('A1:B1');sheet.getCell('A2').value={formula:'1+1',result:2};
    const second=workbook.addWorksheet('Second');second.getCell('A1').value='Second sheet content';
    const bytes=await workbook.xlsx.writeBuffer();
    const dom=new JSDOM('<div id="host"></div>');
    await renderXlsxDocument(bytes,ExcelJS,dom.window.document.querySelector('#host'),dom.window.document);
    assert.equal(dom.window.document.querySelectorAll('[role="tab"]').length,2);
    assert.equal(dom.window.document.querySelector('tbody tr:first-child th').textContent,'Real heading');
    assert.equal(dom.window.document.querySelector('tbody tr:first-child th').colSpan,2);
    assert.equal(dom.window.document.querySelector('tbody tr:first-child th').style.fontWeight,'700');
    assert.equal(dom.window.document.querySelectorAll('[role="tabpanel"]')[1].hidden,true);
    dom.window.document.querySelectorAll('[role="tab"]')[1].click();
    assert.equal(dom.window.document.querySelectorAll('[role="tabpanel"]')[1].hidden,false);
});

test('DOCX preview loads original content without placing document markup into the page', () => {
    const html = renderFilePreview({
        format: 'docx',
        material: { id: 9, title: 'Teaching guide', original_filename: 'guide.docx' },
        preview_data: { blocks: [
            { type: 'heading', level: 1, text: 'Objectives' },
            { type: 'paragraph', text: 'Read <this> safely' },
            { type: 'list-item', text: 'First item' },
            { type: 'table', rows: [['Term', 'Meaning']] },
        ] },
    });

    assert.match(html, /Loading browser preview of original file contents/);
    assert.doesNotMatch(html, /Read &lt;this&gt; safely/);
    assert.doesNotMatch(html, /<this>/);
});

test('real database Office card schedules content capture from its authorized original route', () => {
    const dom = new JSDOM('<main><article class="material-card"><div class="material-cover-wrap"><div class="file-cover"><div class="file-fallback"><span>Generic type</span></div></div></div><h3><a href="/materials/12">Spreadsheet resource</a></h3></article></main>', { url: 'http://127.0.0.1:8000/repository' });
    const observers = [];
    dom.window.IntersectionObserver = class { constructor(callback) { this.callback=callback; observers.push(this); } observe(target) { this.target=target; } disconnect() {} };
    const backend = { kind: 'repository', materials: { data: [{ id: 12, title: 'Spreadsheet resource', original_filename: 'lesson.xlsx', has_file: true }] } };

    enhanceDatabasePreviews(dom.window.document.querySelector('main'), backend);

    const image = dom.window.document.querySelector('.file-cover img');
    assert.equal(image.getAttribute('src'), null);
    assert.equal(image.dataset.officeFormat, 'xlsx');
    assert.equal(image.classList.contains('office-thumbnail-pending'), true);
    assert.equal(observers.length, 1);
    assert.equal(observers[0].target, image);
    assert.equal(dom.window.document.querySelector('.file-cover img[src*="/thumbnail"]'), null);
    assert.equal(new URL(dom.window.document.querySelector('.material-cover-link').href).pathname, '/materials/12');
});

test('homepage video card lazily creates a non-autoplay metadata stream source', async () => {
    const dom = new JSDOM('<main><article class="material-card"><div class="material-cover-wrap"><div class="file-cover"><div class="file-fallback">Movie</div></div></div><h3><a href="/materials/6">Movie</a></h3></article></main>', {url:'http://127.0.0.1:8000/'});
    dom.window.HTMLMediaElement.prototype.load = function() {};
    let observer;
    dom.window.IntersectionObserver = class { constructor(callback) { this.callback=callback; observer=this; } observe(target) { this.target=target; } disconnect() {} };
    enhanceDatabasePreviews(dom.window.document.querySelector('main'), {kind:'home',featured:[{id:6,title:'Movie',original_filename:'clip.mp4',has_file:true}]});
    assert.equal(dom.window.document.querySelector('video'), null);
    observer.callback([{isIntersecting:true}]);
    await new Promise(resolve => setTimeout(resolve, 0));
    const video=dom.window.document.querySelector('video');
    assert.ok(video);
    assert.equal(new URL(video.src).pathname, '/materials/6/preview');
    assert.equal(new URL(video.src).searchParams.get('stream'), '1');
    assert.equal(video.preload, 'metadata');
    assert.equal(video.autoplay, false);
    assert.equal(video.muted, true);
    video.dispatchEvent(new dom.window.Event('error'));
    assert.match(dom.window.document.querySelector('.video-cover').textContent, /unsupported codec or capture/i);
    dom.window.close();
});

test('detail preview remains a view action while original attachment uses an explicit download route', () => {
    const dom = new JSDOM('<main><div class="file-cover-detail"><div class="file-fallback">XLSX</div></div><div class="row g-4"><aside><div class="alert-light border">Browser preview is not available</div><div class="d-flex gap-2"><a href="/materials/3/file">Download file</a></div></aside></div></main>', { url: 'http://127.0.0.1:8000/materials/3' });
    enhanceDatabasePreviews(dom.window.document.querySelector('main'), {
        kind: 'material',
        material: { id: 3, title: 'Workbook', original_filename: 'workbook.xlsx', has_file: true },
    });

    assert.equal(dom.window.document.querySelector('[data-preview-frame]'), null);
    const thumbnail = new URL(dom.window.document.querySelector('.file-cover-detail img').getAttribute('src'), 'http://127.0.0.1:8000');
    assert.equal(thumbnail.pathname, '/materials/3/thumbnail');
    assert.equal(thumbnail.searchParams.has('v'), true);
    assert.equal(dom.window.document.querySelector('.row.g-4').nextElementSibling, null);
    assert.equal(dom.window.document.querySelector('[data-resource-preview-link]').getAttribute('href'), '/materials/3/preview');
    const download = [...dom.window.document.querySelectorAll('a')].find(link => link.textContent === 'Download original');
    assert.equal(download.getAttribute('href'), '/materials/3/file');
    assert.equal(download.hasAttribute('download'), false);
});

test('Office attachment thumbnails schedule capture from their authorized original-file route', () => {
    const dom = new JSDOM('<main><article data-attachment-row="8"><img class="attachment-thumb" data-file-size="128" src="/materials/6/attachments/8/thumbnail"><strong>worksheet.xlsx</strong></article></main>', {url:'http://127.0.0.1:8000/materials/6'});
    const image = dom.window.document.querySelector('img');
    enhanceOfficeAttachmentThumbnails(dom.window.document.querySelector('main'));
    assert.equal(image.dataset.officeCaptureStarted, 'true');
});

test('worksheet tabs switch panels and zoom controls change readable preview scale', () => {
    const dom = new JSDOM('<section class="file-preview-viewer"><button type="button" role="tab" data-sheet-index="0" aria-selected="true">One</button><button type="button" role="tab" data-sheet-index="1" aria-selected="false">Two</button><section id="sheet-panel-0" role="tabpanel"></section><section id="sheet-panel-1" role="tabpanel" hidden></section><button type="button" data-preview-zoom="1">Zoom in</button><div data-preview-scale></div></section>');
    installPreviewInteractions(dom.window.document);
    const tabs = dom.window.document.querySelectorAll('[data-sheet-index]');
    tabs[1].click();
    assert.equal(tabs[1].getAttribute('aria-selected'), 'true');
    assert.equal(dom.window.document.querySelector('#sheet-panel-0').hidden, true);
    assert.equal(dom.window.document.querySelector('#sheet-panel-1').hidden, false);

    dom.window.document.querySelector('[data-preview-zoom="1"]').click();
    assert.equal(dom.window.document.querySelector('[data-preview-scale]').dataset.zoom, '1.15');
});
