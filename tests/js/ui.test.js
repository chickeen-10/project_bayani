import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM, VirtualConsole } from 'jsdom';
import { createCanvas, loadImage } from '@napi-rs/canvas';
import { fileUrl, fileLabel, validateFile, thumbnailMarkup } from '../../resources/js/bayani-files.js';
import { enhanceDatabasePreviews, renderFilePreview } from '../../resources/js/bayani-file-preview.js';
import { enhanceMultiAttachmentForm } from '../../resources/js/bayani-multi-attachments.js';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const fixture = { id: 'BY-QA', title: 'Community archive', description: 'A contributor-created source record.', category: 'Archives', author: 'Demo Contributor', ownerId: 'demo-contributor', date: '2026-10-27', status: 'Approved', fileType: 'PDF', fileName: 'archive.pdf' };
function app(page, role = 'visitor', materials = [fixture], itemId = '', files = {}, preferences = {}, systemSettings = {}, directTheme) {
    const errors = [];
    const vc = new VirtualConsole();
    vc.on('jsdomError', e => { if (!e.message.includes('navigation')) errors.push(e); });
    const dom = new JSDOM(`<head><title>Project BAYANI</title><meta name="description"></head><body data-page="${page}" data-item-id="${itemId}"><span class="brand-name"></span><span class="brand-sub"></span><span class="footer-title">Project BAYANI</span><span id="role-label"></span><span id="nav-avatar"></span><span id="notification-count"></span><div id="notification-preview"></div><main id="app-content"></main><div id="toast-host"></div><div id="confirm-modal" class="modal"><div class="modal-dialog"><div class="modal-content"><p id="confirm-message"></p><button id="confirm-action"></button></div></div></div></body>`, { url: 'http://localhost/', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: vc });
    const w = dom.window;
    for (const [token, source] of Object.entries(files)) w.sessionStorage.setItem(`bayani.file.${token}`, source);
    w.HTMLImageElement.prototype.decode = async function () { await loadImage(this.src); };
    w.localStorage.setItem('bayani.role', role);
    w.enhanceDatabasePreviews = enhanceDatabasePreviews;
    w.renderFilePreview = renderFilePreview;
    w.localStorage.setItem('bayani.demo.v1', JSON.stringify({ materials, categories: ['Archives'], accountPreferences: preferences, systemSettings }));
    if (directTheme !== undefined) w.localStorage.setItem('bayani.theme', directTheme);
    w.eval(read('node_modules/bootstrap/dist/js/bootstrap.bundle.js'));
    w.eval(read('resources/views/components/theme-init.blade.php').replace(/^\s*<script>\s*|\s*<\/script>\s*$/g, ''));
    w.eval(read('resources/js/bayani-data.js'));
    w.eval(read('resources/js/bayani-pdf.js').replace(/export /g, '') + '\n' + read('resources/js/bayani-files.js').replace(/^import .*;$/gm, '').replace(/export /g, '') + '\n' +
        read('resources/js/bayani-upload.js').replace(/^import .*;$/gm, '').replace(/export /g, '') + '\n' +
        'const {Modal,Toast}=bootstrap;\n' + read('resources/js/bayani-phase12.js').replace(/^import .*;$/gm, ''));
    return { dom, w, doc: w.document, store: w.BayaniStore, errors };
}

test('file adapter handles expiration, blocked storage, labels and validation', () => {
    assert.equal(fileUrl({ fileToken: 'missing' }, { getItem: () => null }), '');
    assert.equal(fileUrl({ fileToken: 'blocked' }, { getItem() { throw Error('Blocked'); } }), '');
    assert.equal(fileUrl({ fileToken: 'bad' }, { getItem: () => 'javascript:alert(1)' }), '');
    assert.equal(fileUrl({ fileToken: 'ok' }, { getItem: () => 'data:image/png;base64,AA==' }), 'data:image/png;base64,AA==');
    assert.equal(fileLabel({ fileName: 'source.DOCX' }), 'DOCX');
    assert.match(validateFile({ name: 'x.pdf', size: 0 }, { allowedTypes: 'PDF', maxUploadMb: 10 }), /empty/);
    assert.match(validateFile({ name: 'x.exe', size: 5 }, { allowedTypes: 'PDF', maxUploadMb: 10 }), /not allowed/);
    assert.match(validateFile({ name: 'x.pdf', size: 3 * 1024 * 1024 }, { allowedTypes: 'PDF', maxUploadMb: 2 }), /2 MB/);
    assert.equal(validateFile({ name: 'x.JPEG', size: 100 }, { allowedTypes: 'JPG', maxUploadMb: 2 }), '');
    assert.ok(!thumbnailMarkup({ title: '<script>bad</script>', category: '<img>', fileType: 'DOCX' }).includes('<script>'));
});

test('database attachment picker accumulates files across selections and removes one without dropping others', () => {
    const dom = new JSDOM('<form id="db-material-form"><section class="panel"><input id="material-file" type="file" name="file"></section><button type="submit">Submit</button></form>', {url:'http://localhost'});
    const {window} = dom, form = window.document.querySelector('form');
    class Transfer { constructor(){ this.files=[]; this.items={add:file=>this.files.push(file)}; } }
    window.DataTransfer = Transfer;
    const priorTransfer = globalThis.DataTransfer;
    globalThis.DataTransfer = Transfer;
    enhanceMultiAttachmentForm(form,null,false,20);
    const input = window.document.querySelector('#material-file');
    let selected = [];
    Object.defineProperty(input,'files',{configurable:true,get:()=>selected,set:value=>{selected=Array.from(value);}});
    const file = name => new window.File(['valid'],name,{type:'application/pdf',lastModified:123});
    selected=[file('one.pdf')]; input.dispatchEvent(new window.Event('change',{bubbles:true}));
    selected=[file('two.pdf'),file('one.pdf')]; input.dispatchEvent(new window.Event('change',{bubbles:true}));
    assert.deepEqual([...form.querySelectorAll('#selected-attachments strong')].map(x=>x.textContent),['one.pdf','two.pdf']);
    form.querySelector('[data-remove-selected="0"]').click();
    assert.deepEqual([...form.querySelectorAll('#selected-attachments strong')].map(x=>x.textContent),['two.pdf']);
    assert.equal(input.files.length,1);
    if(priorTransfer===undefined) delete globalThis.DataTransfer; else globalThis.DataTransfer=priorTransfer;
    dom.window.close();
});

test('individual PPTX preview shows the supported download-only fallback and targets that attachment', () => {
    const dom = new JSDOM(renderFilePreview({
        format:'pptx', material:{id:42,title:'Lesson',original_filename:'slides.pptx',file_size:1200},
        download_url:'/materials/42/attachments/99/file',
    }));
    assert.match(dom.window.document.body.textContent,/available as a download only/i);
    assert.equal(dom.window.document.querySelector('a[href="/materials/42/attachments/99/file"]')?.textContent,'Download original');
    dom.window.close();
});

test('all page renderers work with empty and populated storage', () => {
    const pages = ['home','repository','about','login','profile','account-settings','notifications','help','feedback','favorites','recently-viewed','download-history','contributor-dashboard','upload','edit','submissions','drafts','submission-detail','admin-dashboard','review-queue','review','manage-materials','categories','reports','system-settings','users','user-detail','activity','data-cleanup','material','preview'];
    for (const page of pages) for (const populated of [false,true]) {
        const role = ['contributor-dashboard','upload','edit','submissions','drafts','submission-detail'].includes(page) ? 'contributor' : 'admin';
        const { dom, doc, errors } = app(page, role, populated ? [fixture] : [], fixture.id);
        assert.ok(doc.querySelector('#app-content').textContent.trim(), page);
        assert.equal(errors.length, 0, `${page}: ${errors}`);
        assert.equal(doc.querySelectorAll('a[href="#"]').length, 0, page);
        dom.window.close();
    }
});

test('repository search, filters, layout and bookmarks retain working controls', () => {
    const { dom, w, doc, store } = app('repository', 'visitor', [fixture, { ...fixture, id: 'BY-pending', status: 'Pending' }]);
    assert.equal(doc.querySelectorAll('.material-card').length, 1);
    assert.equal(doc.querySelectorAll('[data-download-material]').length, 0);
    doc.querySelector('[data-favorite]').click();
    assert.equal(store.favorites().length, 1);
    assert.match(doc.querySelector('.collection-shortcuts').textContent, /Bookmarks \(1\)/);
    assert.ok(doc.querySelector('[data-favorite]').classList.contains('saved'));
    const search = doc.querySelector('#search-query'); search.value = 'no match'; search.dispatchEvent(new w.Event('input'));
    assert.equal(doc.querySelectorAll('.material-card').length, 0);
    doc.querySelector('[data-empty-reset]').click();
    assert.equal(doc.querySelectorAll('.material-card').length, 1);
    doc.querySelector('[data-repository-layout="list"]').click();
    assert.ok(doc.querySelector('.material-list-card'));
    dom.window.close();
});

test('share copies an actual material URL and unavailable records expose no file controls', async () => {
    const { dom, w, doc } = app('repository');
    let copied;
    Object.defineProperty(w.navigator, 'clipboard', { value: { writeText: async value => { copied = value; } } });
    doc.querySelector('[data-share-material]').click();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(copied, 'http://localhost/materials/BY-QA');
    dom.window.close();
});

test('upload rejects invalid files and removing selection preserves stored metadata', async () => {
    const { dom, w, doc, store } = app('upload', 'contributor');
    const input = doc.querySelector('#attachment');
    Object.defineProperty(input, 'files', { configurable:true, value:[new w.File(['bad'], 'bad.exe', { type:'application/octet-stream' })] });
    input.dispatchEvent(new w.Event('change', { bubbles:true }));
    assert.match(doc.querySelector('#upload-error').textContent, /not allowed/);
    Object.defineProperty(input, 'files', { configurable:true, value:[new w.File(['doc'], 'source.docx', { type:'application/vnd.openxmlformats-officedocument.wordprocessingml.document' })] });
    input.dispatchEvent(new w.Event('change', { bubbles:true }));
    assert.match(doc.querySelector('#selected-file-preview').textContent, /source.docx/);
    doc.querySelector('#remove-selected-file').click();
    assert.equal(doc.querySelector('#selected-file-preview').textContent, '');
    assert.equal(store.find(fixture.id).fileName, 'archive.pdf');
    dom.window.close();
});

test('image cards decode the actual attachment and expose its real download', async () => {
    const canvas = createCanvas(20,10); const ctx = canvas.getContext('2d'); ctx.fillStyle = '#168a91'; ctx.fillRect(0,0,20,10);
    const source = canvas.toDataURL('image/png');
    const { dom, doc } = app('repository','visitor',[{...fixture,fileMime:'image/png',fileName:'source.png',fileType:'Image',fileToken:'image'}],'',{image:source});
    for(let i=0;i<20&&!doc.querySelector('.has-preview');i++) await new Promise(resolve=>setTimeout(resolve,5));
    assert.equal(doc.querySelector('.file-thumbnail')?.src, source);
    assert.equal(doc.querySelector('[data-download-material]')?.href, source);
    assert.match(doc.querySelector('.file-preview-caption').textContent,/Original image/);
    dom.window.close();
});

test('contributor form edits a draft and admin decision control persists a review', async () => {
    const draft = {...fixture,status:'Draft'};
    const edit = app('edit','contributor',[draft],draft.id);
    edit.doc.querySelector('[name="title"]').value = 'Edited archive';
    edit.doc.querySelector('#submission-form').dispatchEvent(new edit.w.Event('submit',{bubbles:true,cancelable:true}));
    assert.equal(edit.store.find(draft.id).title,'Edited archive');
    assert.equal(edit.store.find(draft.id).status,'Draft');
    edit.store.submitDraft(draft.id);
    const review = app('review','admin',JSON.parse(JSON.stringify(edit.store.materials())),draft.id);
    review.doc.querySelector('#review-remarks').value = 'Checked source';
    review.doc.querySelector('[data-review="Approved"]').click();
    review.doc.querySelector('#confirm-action').click();
    assert.equal(review.store.find(draft.id).status,'Approved');
    assert.ok(review.store.notifications('contributor').some(n=>n.type==='approved'));
    assert.ok(review.store.activity().some(a=>a.action==='Material approved'));
    edit.dom.window.close(); review.dom.window.close();
});

test('profile, account settings, and notification controls save actual changes', () => {
    const profile = app('profile','contributor');
    profile.doc.querySelector('[name="name"]').value='Edited contributor';
    profile.doc.querySelector('#profile-form').dispatchEvent(new profile.w.Event('submit',{bubbles:true,cancelable:true}));
    assert.equal(profile.store.currentProfile().name,'Edited contributor');
    profile.dom.window.close();
    const settings=app('account-settings','contributor');
    settings.doc.querySelector('[value="dark"]').checked=true;
    settings.doc.querySelector('#account-settings-form').dispatchEvent(new settings.w.Event('submit',{bubbles:true,cancelable:true}));
    assert.equal(settings.store.preferences().theme,'dark');
    assert.equal(settings.doc.documentElement.dataset.theme,'dark');
    settings.dom.window.close();
    const notes=app('notifications','contributor');
    notes.store.addNotification('contributor','submission','Submission received','Local status');
    assert.equal(notes.store.unreadCount(),1);
    notes.doc.querySelector('#mark-all-read').click();
    assert.equal(notes.store.unreadCount(),0);
    notes.dom.window.close();
});

test('deleting one notification requires confirmation and persists only after confirmation', () => {
    const notes = app('notifications', 'contributor');
    notes.store.addNotification('contributor', 'submission', 'Keep until confirmed', 'Review notice');
    const id = notes.store.notifications()[0].id;
    notes.doc.querySelector(`[data-delete-notification="${id}"]`).click();
    assert.equal(notes.store.notifications().length, 1);
    notes.doc.querySelector('#confirm-action').click();
    assert.equal(notes.store.notifications().length, 0);
    notes.dom.window.close();
});

test('appearance changes immediately, cancel restores saved choice, and save persists across page loads', async () => {
    const settings = app('account-settings', 'contributor', [fixture]);
    const dark = settings.doc.querySelector('[name="theme"][value="dark"]');
    dark.checked = true;
    dark.dispatchEvent(new settings.w.Event('change', { bubbles: true }));
    assert.equal(settings.doc.documentElement.dataset.theme, 'dark');
    assert.equal(settings.doc.documentElement.dataset.bsTheme, 'dark');
    assert.equal(settings.w.localStorage.getItem('bayani.theme'), 'dark');
    settings.doc.querySelector('#cancel-account-settings').click();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(settings.doc.documentElement.dataset.theme, 'light');
    assert.equal(settings.w.localStorage.getItem('bayani.theme'), 'light');
    dark.checked = true;
    dark.dispatchEvent(new settings.w.Event('change', { bubbles: true }));
    settings.doc.querySelector('#account-settings-form').dispatchEvent(new settings.w.Event('submit', { bubbles: true, cancelable: true }));
    assert.equal(settings.store.preferences().theme, 'dark');
    const saved = JSON.parse(settings.w.localStorage.getItem('bayani.demo.v1'));
    assert.equal(saved.accountPreferences.theme, 'dark');
    assert.equal(settings.w.localStorage.getItem('bayani.theme'), 'dark');
    settings.dom.window.close();
    const refreshed = app('repository', 'visitor', [fixture], '', {}, saved.accountPreferences, {}, 'dark');
    assert.equal(refreshed.doc.documentElement.dataset.theme, 'dark');
    assert.equal(refreshed.doc.documentElement.dataset.bsTheme, 'dark');
    refreshed.dom.window.close();
});

test('appearance choice persists across route loads and remains selected in settings', async () => {
    const settings = app('account-settings', 'visitor', [fixture]);
    const dark = settings.doc.querySelector('[name="theme"][value="dark"]');
    dark.checked = true;
    dark.dispatchEvent(new settings.w.Event('change', { bubbles: true }));
    assert.equal(settings.w.localStorage.getItem('bayani.theme'), 'dark');
    settings.dom.window.close();

    for (const [page, role, marker] of [['home', 'visitor', '.hero'], ['repository', 'visitor', '.material-card'], ['contributor-dashboard', 'contributor', '.stat-card'], ['admin-dashboard', 'admin', '.stat-card']]) {
        const route = app(page, role, [fixture], '', {}, { theme: 'light' }, {}, 'dark');
        assert.equal(route.doc.documentElement.dataset.bsTheme, 'dark', page);
        assert.ok(route.doc.querySelector(`#app-content ${marker}`), page);
        route.dom.window.close();
    }

    const reloadedSettings = app('account-settings', 'visitor', [fixture], '', {}, { theme: 'light' }, {}, 'dark');
    assert.equal(reloadedSettings.doc.querySelector('[name="theme"][value="dark"]').checked, true);
    assert.equal(reloadedSettings.doc.querySelector('[name="theme"][value="light"]').checked, false);
    reloadedSettings.doc.querySelector('#cancel-account-settings').click();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(reloadedSettings.doc.documentElement.dataset.bsTheme, 'dark');
    assert.equal(reloadedSettings.doc.querySelector('[name="theme"][value="dark"]').checked, true);
    const light = reloadedSettings.doc.querySelector('[name="theme"][value="light"]');
    light.checked = true;
    light.dispatchEvent(new reloadedSettings.w.Event('change', { bubbles: true }));
    assert.equal(reloadedSettings.w.localStorage.getItem('bayani.theme'), 'light');
    reloadedSettings.dom.window.close();
    const lightHome = app('home', 'visitor', [fixture], '', {}, { theme: 'dark' }, {}, 'light');
    assert.equal(lightHome.doc.documentElement.dataset.bsTheme, 'light');
    assert.ok(lightHome.doc.querySelector('#app-content .hero'));
    lightHome.dom.window.close();
});

test('shared prepaint theme bootstrap applies saved theme on every rendered page', () => {
    const pages = ['home','repository','about','login','profile','account-settings','notifications','help','feedback','favorites','recently-viewed','download-history','contributor-dashboard','upload','submissions','drafts','submission-detail','admin-dashboard','review-queue','review','manage-materials','categories','reports','system-settings','users','user-detail','activity','data-cleanup','material','preview'];
    for (const page of pages) {
        const { dom, doc } = app(page, 'admin', [fixture], fixture.id, {}, { theme: 'light' }, {}, 'dark');
        assert.equal(doc.documentElement.dataset.bsTheme, 'dark', page);
        assert.equal(doc.documentElement.dataset.theme, 'dark', page);
        dom.window.close();
    }
});

test('administrator system presentation settings update shared branding and repository defaults', () => {
    const admin = app('system-settings', 'admin');
    const form = admin.doc.querySelector('#system-settings-form');
    form.elements.websiteName.value = 'Local Archive';
    form.elements.description.value = 'Community history collection';
    form.elements.repositoryInfo.value = 'Browse local sources';
    form.elements.defaultDisplay.value = 'list';
    form.elements.materialsPerPage.value = '6';
    form.dispatchEvent(new admin.w.Event('submit', { bubbles: true, cancelable: true }));
    assert.equal(admin.store.systemSettings().websiteName, 'Local Archive');
    assert.equal(admin.doc.querySelector('.brand-name').textContent, 'Local Archive');
    assert.equal(admin.doc.querySelector('.brand-sub').textContent, 'Community history collection');
    const saved = JSON.parse(admin.w.localStorage.getItem('bayani.demo.v1')).systemSettings;
    admin.dom.window.close();
    const repository = app('repository', 'visitor', [fixture], '', {}, {}, saved);
    assert.ok(repository.doc.querySelector('.material-list-card'));
    assert.match(repository.doc.querySelector('#app-content').textContent, /Browse local sources/);
    repository.dom.window.close();
});
