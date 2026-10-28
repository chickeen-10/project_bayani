import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM, VirtualConsole } from 'jsdom';

const manifest = JSON.parse(readFileSync(new URL('../../public/build/manifest.json', import.meta.url), 'utf8'));
const assets = entry => [entry.file, ...(entry.css || [])];
const appEntry = manifest['resources/js/app.js'];
const cssFiles = [...new Set([...assets(manifest['resources/css/app.css']), ...assets(appEntry)].filter(file => file.endsWith('.css')))];
const productionScript = readFileSync(new URL(`../../public/build/${appEntry.file}`, import.meta.url), 'utf8');
const styles = cssFiles.map(file => readFileSync(new URL(`../../public/build/${file}`, import.meta.url), 'utf8')).join('\n');
const themeScript = readFileSync(new URL('../../resources/views/components/theme-init.blade.php', import.meta.url), 'utf8').replace(/^\s*<script>\s*|\s*<\/script>\s*$/g, '');
const phase12Source = readFileSync(new URL('../../resources/js/bayani-phase12.js', import.meta.url), 'utf8');
const phase14Source = readFileSync(new URL('../../resources/css/phase14.css', import.meta.url), 'utf8');
const appCssSource = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const material = { id: 'BY-PROD', title: 'Community archive', description: 'A contributor-created source record.', category: 'Archives', author: 'Maria Santos', ownerId: 'demo-contributor', date: '2026-10-27', status: 'Approved', fileType: 'PDF', fileName: 'archive.pdf' };

test('built Vite bundle renders real page content visibly in both themes', () => {
    const pages = [
        ['home', 'visitor', '.hero'], ['repository', 'visitor', '.material-card'], ['material', 'visitor', '.meta-list'],
        ['about', 'visitor', 'h1'], ['help', 'visitor', 'h1'], ['admin-dashboard', 'admin', '.stat-card'],
        ['contributor-dashboard', 'contributor', '.stat-card'], ['upload', 'contributor', '#submission-form'],
        ['profile', 'visitor', '#profile-form'], ['account-settings', 'visitor', '#account-settings-form'],
        ['notifications', 'visitor', '.empty-state'],
    ];

    for (const theme of ['light', 'dark']) for (const [page, role, expected] of pages) {
        const errors = [];
        const virtualConsole = new VirtualConsole();
        virtualConsole.on('jsdomError', error => { if (!error.message.includes('navigation')) errors.push(error.message); });
        const dom = new JSDOM(`<html><head><style>${styles}</style></head><body data-page="${page}" data-item-id="${material.id}"><span class="brand-name"></span><span class="brand-sub"></span><span class="footer-title">Project BAYANI</span><span id="role-label"></span><span id="nav-avatar"></span><span id="notification-count"></span><div id="notification-preview"></div><main class="container bayani-main" id="app-content"></main><div id="toast-host"></div><div id="confirm-modal" class="modal"><div class="modal-dialog"><div class="modal-content"><p id="confirm-message"></p><button id="confirm-action"></button></div></div></div></body></html>`, { url: 'http://localhost/', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole });
        const { window } = dom;
        window.localStorage.setItem('bayani.role', role);
        window.localStorage.setItem('bayani.theme', theme);
        window.localStorage.setItem('bayani.demo.v1', JSON.stringify({ materials: [material], categories: ['Archives'], accountPreferences: { theme, notifications: { submission: true, decisions: true, announcements: true } } }));
        window.eval(themeScript);
        try { window.eval(productionScript); } catch (error) { errors.push(error.stack || String(error)); }

        const content = window.document.querySelector('#app-content');
        const expectedContent = content.querySelector(expected);
        assert.ok(content.textContent.trim(), `${theme} ${page}: content is empty`);
        assert.ok(expectedContent, `${theme} ${page}: expected ${expected} in rendered content`);
        if (page === 'home') assert.ok(content.textContent.includes('LEARNING RESOURCES') && content.textContent.includes('SHARED LEARNING'), `${theme} home: readable hero kicker`);
        const style = window.getComputedStyle(expectedContent);
        assert.notEqual(style.display, 'none', `${theme} ${page}: rendered content is display:none`);
        assert.notEqual(style.visibility, 'hidden', `${theme} ${page}: rendered content is hidden`);
        assert.notEqual(style.opacity, '0', `${theme} ${page}: rendered content is transparent`);
        assert.equal(window.document.documentElement.getAttribute('data-bs-theme'), theme, `${page}: theme root`);
        assert.deepEqual(errors, [], `${theme} ${page}: runtime errors`);
        dom.window.close();
    }
});

test('live repository renderer uses only Laravel database payload', () => {
    const record = { id: 912, title: 'MariaDB Visible Resource', description: 'Database record body', status: 'Approved', has_file: false, published_at: '2026-10-27T00:00:00Z', created_at: '2026-10-27T00:00:00Z', contributor: { name: 'DB Contributor' }, curriculum: null, material_type: null, subjects: [{ name: 'Science' }], grade_levels: [{ name: 'Grade 6' }] };
    const dom = new JSDOM(`<html><head><meta name="csrf-token" content="token"></head><body data-page="repository"><main id="app-content"></main><div id="toast-host"></div></body></html>`, { url: 'http://localhost/repository?q=MariaDB', runScripts: 'outside-only' });
    dom.window.BayaniBackend = { kind: 'repository', materials: { data: [record], total: 1, current_page: 1, last_page: 1 }, subjects: [{ id: 1, name: 'Science' }], grade_levels: [], curricula: [], material_types: [], tracks: [], strands: [], categories: [] };
    dom.window.BayaniAuth = null;
    dom.window.BayaniStore = { systemSettings: () => ({}), materials: () => [{ id: 1, title: 'Local fake record', status: 'Approved' }], role: () => 'visitor', preferences: () => ({}) };
    dom.window.eval(productionScript);
    const content = dom.window.document.querySelector('#app-content').textContent;
    assert.match(content, /MariaDB Visible Resource/);
    assert.doesNotMatch(content, /Local fake record/);
    assert.equal(dom.window.document.querySelector('.cover-category'), null, 'missing material type does not render a placeholder badge');
    assert.equal(dom.window.document.querySelector('.file-chip'), null, 'missing curriculum does not render an Unspecified chip');
    assert.match(dom.window.document.querySelector('form[method="GET"]').outerHTML, /name="subject"/);
    dom.window.close();
});

test('repository keeps simple search visible, advanced filters expandable, and query state removable', () => {
    const dom = new JSDOM(`<html><head></head><body data-page="repository"><main id="app-content"></main><div id="toast-host"></div></body></html>`, { url: 'http://localhost/repository?q=fractions&subject=2&grade=12&curriculum=3&category=4&sort=title', runScripts: 'outside-only' });
    dom.window.BayaniBackend = {
        kind: 'repository', materials: { data: [], total: 0, current_page: 1, last_page: 2 },
        subjects: [{ id: 2, name: 'Mathematics' }], grade_levels: [{ id: 12, name: 'Grade 11' }],
        curricula: [{ id: 3, name: 'MATATAG' }], material_types: [{ id: 5, name: 'Worksheet' }],
        categories: [{ id: 4, name: 'Lesson resources' }], contributors: [{ id: 8, name: 'Contributor' }],
        tracks: [{ id: 1, name: 'Academic' }], strands: [{ id: 6, name: 'STEM' }],
        grade_metadata: [{ id: 12, stage: 'senior_high' }],
    };
    dom.window.BayaniAuth = null;
    dom.window.BayaniStore = { systemSettings: () => ({}), materials: () => [], role: () => 'visitor', preferences: () => ({ theme: 'light' }) };
    dom.window.eval(themeScript);
    dom.window.eval(productionScript);
    const content = dom.window.document.querySelector('#app-content');
    assert.ok(content.querySelector('.bayani-page-heading'), 'repository uses the shared editorial page heading');
    assert.equal(content.querySelector('#search-query').value, 'fractions');
    assert.ok(content.querySelector('[name="subject"]'));
    assert.ok(content.querySelector('[name="grade"]'));
    assert.equal(content.querySelector('.repository-more-filters').open, true);
    assert.equal(content.querySelector('.repository-more-filters summary').textContent, 'More filters');
    assert.ok(content.querySelector('.repository-search.toolbar'));
    assert.ok(content.querySelector('.repository-actions .clear-filters-action'));
    assert.equal(content.querySelector('[name="category"]').value, '4');
    assert.ok(content.querySelector('[name="track"]'), 'SHS filters appear for a Senior High grade');
    assert.ok(content.querySelectorAll('.active-filter-chip').length >= 5);
    assert.match(content.querySelector('.repository-results-bar').textContent, /0 approved resources/);
    assert.match(content.querySelector('.repository-sort-summary').textContent, /Title/);
    const removeCurriculum = [...content.querySelectorAll('.active-filter-chip')].find(chip => chip.getAttribute('aria-label').includes('Curriculum'));
    assert.match(removeCurriculum.href, /q=fractions/);
    assert.doesNotMatch(removeCurriculum.href, /curriculum=/);
    assert.match(content.querySelector('.repository-search a[href="/repository"]').textContent, /Clear All/);
    assert.match(content.querySelector('[aria-label="Pages"] a[href*="curriculum=3"]').href, /sort=title/);
    content.querySelector('[data-open-sort]').click();
    assert.equal(content.querySelector('.repository-more-filters').open, true);
    assert.equal(dom.window.document.activeElement.id, 'sort-materials', 'results sort shortcut opens and focuses the advanced control');
    dom.window.close();
});

test('database homepage has distinct learning steps and dashboard metrics stay balanced', () => {
    const render = (page, backend, role) => {
        const dom = new JSDOM(`<html><head><meta name="csrf-token" content="token"></head><body data-page="${page}"><main id="app-content"></main><div id="toast-host"></div></body></html>`, { url: 'http://localhost/', runScripts: 'outside-only' });
        dom.window.BayaniAuth = { id: 1, name: 'Test User', role };
        dom.window.BayaniBackend = backend;
        dom.window.BayaniStore = { systemSettings: () => ({}), materials: () => [], role: () => role, currentProfile: () => ({ name: 'Test User' }), preferences: () => ({}) };
        dom.window.eval(themeScript);
        dom.window.eval(productionScript);
        return dom;
    };
    const home = render('home', { kind:'home', featured:[], stats:{ approved:18, subjects:49, curricula:3, material_types:22 } }, 'visitor');
    const steps = [...home.window.document.querySelectorAll('.home-step p')].map(node => node.textContent);
    assert.equal(new Set(steps).size, 3, 'each home step explains a distinct action');
    assert.equal(home.window.document.querySelectorAll('.hero-actions a[href="/repository"]').length, 1);
    assert.ok(home.window.document.querySelector('.hero-art-panel'));
    assert.equal(home.window.document.querySelectorAll('.hero-panel-stats strong').length, 3);
    assert.deepEqual([...home.window.document.querySelectorAll('.hero-editorial h1 span')].map(node => node.textContent), ['Find resources', 'for every learner.']);
    assert.equal(home.window.document.querySelector('.hero-summary-stats'), null, 'hero has no nested statistics panel');
    assert.equal(home.window.document.querySelector('.hero-actions .btn-primary')?.getAttribute('href'), '/repository');
    assert.equal(home.window.document.querySelector('.hero-actions .btn-outline-primary')?.getAttribute('href'), '/about');
    home.window.close();

    const admin = render('admin-dashboard', { kind:'records', materials:{data:[],total:0}, stats:{total:12,approved:8,pending:2,archived:2}, catalog_stats:{subjects:49} }, 'admin');
    assert.equal(admin.window.document.querySelectorAll('.dashboard-metrics .stat-card').length, 4);
    assert.equal(admin.window.document.querySelector('.dashboard-catalog-summary[href="/admin/education/subjects"] strong')?.textContent, '49 subjects');
    assert.ok(admin.window.document.querySelector('.dashboard-overview-note .dashboard-catalog-summary'));
    assert.ok(admin.window.document.querySelector('.dashboard-recent .section-heading'));
    admin.window.close();
});

test('shared semantic theme tokens and dark utility overrides are included in the production CSS', () => {
    for (const token of ['--bayani-bg', '--bayani-surface', '--bayani-text', '--bayani-text-muted', '--bayani-border', '--bayani-primary', '--bayani-input-bg', '--bayani-hover']) assert.ok(styles.includes(token), `missing ${token}`);
    assert.match(styles, /\[data-theme=dark\]\s*\.text-dark/);
    assert.match(styles, /\[data-theme=dark\]\s*\.bg-white/);
});

test('repository and home styling removes decorative boxes while keeping control focus', () => {
    assert.match(appCssSource, /\.form-control, \.form-select \{[^}]*border: 1px solid transparent/);
    assert.match(appCssSource, /\.form-control:focus, \.form-select:focus \{[^}]*border-color: var\(--teal\)/);
    assert.doesNotMatch(phase14Source, /\.panel,\s*\.toolbar,\s*\.stat-card,\s*\.card\s*\{\s*border:\s*0\s*!important/);
    assert.doesNotMatch(phase14Source, /html\[data-theme="dark"\] \.topbar[^{}]*\{[^}]*border-color: transparent !important/);
    assert.match(phase14Source, /\.bayani-main \.panel, \.bayani-main \.toolbar, \.bayani-main \.stat-card, \.bayani-main \.card \{ border: 0;/);
    assert.match(styles, /\.repository-search\.toolbar\{[^}]*border:0[^}]*background:transparent/);
    assert.match(styles, /\.bayani-page-heading \.page-title\{margin:0;font:500[^}]*Georgia/);
    assert.match(styles, /\.repository-results-bar\{[^}]*border:0/);
    assert.match(styles, /\.repository-more-filters summary:focus-visible/);
    assert.match(styles, /\.repository-sort-summary:focus-visible/);
    assert.match(styles, /\.hero\.hero-editorial\{position:relative;isolation:isolate;overflow:visible;[^}]*background:transparent!important/);
    assert.match(styles, /\.hero-art-panel\{[^}]*background:#214b36/);
    assert.match(styles, /\.hero-art-panel:before,\.hero-art-panel:after\{content:none/);
    assert.match(styles, /\.cover-category\{[^}]*border:0/);
});

test('archived route renders the archived list even when the page marker is stale', () => {
    const dom = new JSDOM(`<html><head><meta name="csrf-token" content="token"></head><body data-page="submissions"><main id="app-content"></main><div id="toast-host"></div><div id="confirm-modal"><div id="confirm-message"></div><button id="confirm-action"></button></div></body></html>`, { url: 'http://localhost/contributor/archived', runScripts: 'outside-only' });
    dom.window.BayaniAuth = { id: 1, name: 'Contributor', role: 'contributor' };
    dom.window.BayaniBackend = { kind: 'records', archived_count: 1, can_permanently_delete: false, materials: { data: [{ id: 77, title: 'Archived fixture', description: 'Private archive record', status: 'Archived', deleted_at: '2026-10-27T00:00:00Z', attachments_count: 2, contributor: { name: 'Contributor' } }], current_page: 1, last_page: 1, total: 1 } };
    dom.window.BayaniStore = { systemSettings: () => ({}), role: () => 'contributor', currentProfile: () => ({ name: 'Contributor' }), preferences: () => ({ theme: 'light' }) };
    dom.window.eval(themeScript);
    dom.window.eval(productionScript);
    const content = dom.window.document.querySelector('#app-content');
    assert.match(content.querySelector('h1').textContent, /Archived materials/);
    assert.match(content.textContent, /Archived fixture/);
    assert.ok(content.querySelector('form[action="/contributor/archived/77/restore"]'));
    assert.equal(content.querySelector('.toolbar'), null);
    assert.equal(content.querySelector('[data-permanent-delete]'), null);
    dom.window.close();
});

test('profile picture labels render clean UTF-8 punctuation and upload limits', () => {
    for (const [page, backend] of [
        ['profile', null],
        ['profile', { kind: 'profile', profile: { id: 1, name: 'Test User', email: 'test@example.test', role: 'contributor' } }],
    ]) {
        const dom = new JSDOM(`<html><head></head><body data-page="${page}"><main id="app-content"></main></body></html>`, { url: 'http://localhost/', runScripts: 'outside-only' });
        dom.window.BayaniBackend = backend;
        dom.window.BayaniAuth = backend ? backend.profile : null;
        dom.window.BayaniStore = { systemSettings: () => ({}), materials: () => [], users: () => [], role: () => 'visitor', currentProfile: () => ({ id: 'guest', name: 'Guest', email: 'guest@example.test' }), preferences: () => ({ theme: 'light', notifications: {} }) };
        dom.window.eval(productionScript);
        const label = [...dom.window.document.querySelectorAll('label')].find(x => x.textContent.includes('Profile picture'))?.textContent;
        assert.equal(label, 'Profile picture \u2014 PNG/JPG/WEBP, max 10 MB');
        assert.ok(dom.window.document.querySelector('#profile-avatar'));
        assert.match(dom.window.document.querySelector('#app-content').textContent, /Maximum 10 MB|up to 10 MB/);
        dom.window.close();
    }
});

test('built authenticated pages render real account forms without exposing demo role switching', () => {
    for (const page of ['login', 'register', 'profile', 'account-settings', 'contributor-dashboard']) {
        const errors = [];
        const virtualConsole = new VirtualConsole();
        virtualConsole.on('jsdomError', error => { if (!error.message.includes('navigation')) errors.push(error.message); });
        const dom = new JSDOM(`<html><head><meta name="csrf-token" content="test-token"><style>${styles}</style></head><body data-page="${page}" data-item-id=""><span class="brand-name"></span><span class="brand-sub"></span><span class="footer-title">Project BAYANI</span><span id="role-label"></span><span id="nav-avatar"></span><span id="notification-count"></span><div id="notification-preview"></div><main class="container bayani-main" id="app-content"></main><div id="toast-host"></div><div id="confirm-modal" class="modal"><div class="modal-dialog"><div class="modal-content"><p id="confirm-message"></p><button id="confirm-action"></button></div></div></div></body></html>`, { url: 'http://localhost/', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole });
        const { window } = dom;
        window.BayaniAuth = { id: 17, name: 'Authenticated Person', email: 'person@example.test', role: 'contributor', organization: 'Community School', contact: '', bio: 'Educator', avatar: '' };
        window.BayaniAuthErrors = [];
        window.BayaniOldInput = {};
        window.localStorage.setItem('bayani.role', 'admin');
        window.localStorage.setItem('bayani.theme', 'dark');
        const legacy = JSON.stringify({ materials: [{ id: 'BY-OLD', title: 'Legacy browser record', status: 'Approved' }] });
        window.localStorage.setItem('bayani.demo.v1', legacy);
        window.eval(themeScript);
        try { window.eval(productionScript); } catch (error) { errors.push(error.stack || String(error)); }

        const content = window.document.querySelector('#app-content');
        assert.ok(content.textContent.trim(), `${page}: content is empty`);
        assert.equal(window.document.documentElement.getAttribute('data-bs-theme'), 'dark', `${page}: saved theme remains applied after authentication`);
        if (page === 'login') assert.equal(content.querySelector('form[action="/login"] input[name="password"]')?.type, 'password');
        if (page === 'register') {
            assert.equal(content.querySelector('form[action="/register"] input[name="password_confirmation"]')?.type, 'password');
            assert.equal(content.querySelector('form[action="/register"] input[name="role"]'), null, 'registration has no client role field');
        }
        if (page === 'profile') assert.equal(content.querySelector('#profile-form[data-real-profile="true"] input[name="email"]')?.value, 'person@example.test');
        assert.match(window.document.getElementById('role-label').textContent, /Authenticated Person.*Contributor/);
        assert.equal(window.localStorage.getItem('bayani.role'), 'admin', 'the old demo role preference is preserved');
        assert.equal(window.localStorage.getItem('bayani.demo.v1'), legacy, 'the existing demo record is preserved');
        assert.deepEqual(errors, [], `${page}: runtime errors`);
        dom.window.close();
    }
});

test('built material form posts real multipart files and exposes authorized attachment controls', () => {
    assert.match(phase12Source, /name="attachments\[\]" multiple/);
    const virtualConsole = new VirtualConsole();
    const errors = [];
    virtualConsole.on('jsdomError', error => { if (!error.message.includes('navigation')) errors.push(error.message); });
    const dom = new JSDOM(`<html><head><meta name="csrf-token" content="test-token"><style>${styles}</style></head><body data-page="edit" data-item-id="42"><span class="brand-name"></span><span class="brand-sub"></span><span class="footer-title">Project BAYANI</span><span id="role-label"></span><span id="nav-avatar"></span><span id="notification-count"></span><div id="notification-preview"></div><main class="container bayani-main" id="app-content"></main><div id="toast-host"></div><div id="confirm-modal" class="modal"><div class="modal-dialog"><div class="modal-content"><p id="confirm-message"></p><button id="confirm-action"></button></div></div></div></body></html>`, { url: 'http://localhost/', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole });
    const { window } = dom;
    window.BayaniAuth = { id: 1, name: 'Contributor', role: 'contributor' };
    window.BayaniAuthErrors = [];
    window.BayaniOldInput = {};
    window.BayaniBackend = { kind:'material-form', max_upload_mb:20, material:{ id:42, title:'Lesson', description:'A resource', status:'Revision Requested', has_file:true, original_filename:'cover.png', file_type:'image/png', file_size:300, subjects:[{id:1,name:'Mathematics'}], grade_levels:[{id:2,name:'Grade 2'}], attachments:[{id:99,original_filename:'cover.png',mime_type:'image/png',file_size:300,display_order:0,is_primary:true}] }, curricula:[], subjects:[{id:1,name:'Mathematics'},{id:3,name:'Science'}], grade_levels:[{id:2,name:'Grade 2'},{id:4,name:'Grade 4'}], material_types:[], tracks:[], strands:[], categories:[], subject_mappings:[], grade_metadata:[], track_metadata:[], strand_metadata:[] };
    window.eval(themeScript);
    window.eval(productionScript);
    const form = window.document.querySelector('#db-material-form');
    assert.equal(form?.getAttribute('enctype'), 'multipart/form-data');
    assert.equal(form?.querySelector('input[type=file][name="attachments[]"][multiple]') !== null, true);
    assert.ok(form?.querySelector('details.material-additional'), 'optional metadata starts in a collapsible section');
    assert.equal(form?.querySelector('[name="description"]')?.required, false);
    assert.equal(form?.querySelector('[name="title"]')?.required, true);
    assert.equal(form?.querySelectorAll('[data-required-group="grade_levels"], [data-required-group="subjects"]').length, 2);
    assert.equal(form?.querySelector('[name="description"]')?.value, 'A resource', 'collapsed edit fields retain existing values');
    assert.match(form?.textContent || '', /SHS Track/);
    assert.ok(form?.querySelector('#selected-attachments'));
    assert.match(form?.textContent || '', /Existing files remain attached/);
    const classification = [...form.querySelectorAll('#field-curriculum_id, #subjects-toggle, #grade_levels-toggle, #field-material_type_id')];
    assert.deepEqual(classification.map(field => field.id), ['field-curriculum_id', 'subjects-toggle', 'grade_levels-toggle', 'field-material_type_id']);
    assert.equal(form.querySelector('#subjects-options').hidden, true, 'multi-select starts collapsed');
    assert.equal(form.querySelector('#subjects-1').checked, true, 'existing subject remains selected when editing');
    const subjectsToggle = form.querySelector('#subjects-toggle');
    subjectsToggle.click();
    assert.equal(form.querySelector('#subjects-options').hidden, false);
    assert.equal(window.document.activeElement.id, 'subjects-search', 'opening moves focus into the searchable menu');
    const science = form.querySelector('#subjects-3');
    science.checked = true;
    science.dispatchEvent(new window.Event('change', { bubbles: true }));
    assert.match(form.querySelector('#subjects-toggle').textContent, /2 selected/);
    assert.equal(form.querySelectorAll('[data-multi-select="subjects"] .bayani-multiselect-selected .bayani-selection-chip').length, 2);
    form.querySelector('[data-remove-option="3"]').click();
    assert.equal(science.checked, false, 'selected chip removes its checkbox value');
    assert.equal(form.querySelectorAll('[data-multi-select="subjects"] .bayani-multiselect-selected .bayani-selection-chip').length, 1);
    subjectsToggle.click();
    window.document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(form.querySelector('#subjects-options').hidden, true);
    assert.equal(window.document.activeElement.id, 'subjects-toggle', 'Escape closes and restores trigger focus');
    subjectsToggle.click();
    window.document.body.click();
    assert.equal(form.querySelector('#subjects-options').hidden, true, 'outside click closes the menu');
    assert.equal(form?.querySelectorAll('form').length, 0, 'removal controls do not nest forms');
    assert.equal(form?.querySelector('[data-delete-attachment="99"]')?.dataset.url, '/contributor/submissions/42/attachments/99');
    assert.equal(form?.querySelector('[data-primary-attachment="99"]')?.checked, true);
    assert.equal(form?.querySelector('a[href="/materials/42/attachments/99/preview"]')?.textContent, 'Preview');
    assert.equal(form?.querySelector('a[href="/materials/42/attachments/99/file"]')?.textContent, 'Download');
    assert.deepEqual(errors, []);
    dom.window.close();
});

test('built database catalog and educational resource forms render real backend records', () => {
    const catalogs = [
        { page: 'education-subjects', role: 'admin', backend: { kind:'education-catalog', catalog:'subjects', heading:'Subjects', records:{data:[{id:1,name:'Mathematics',code:'MATH',status:'active'}],current_page:1,last_page:1,total:1}, curricula:[], subjects:[], grade_levels:[], tracks:[] }, expected:'Mathematics' },
        { page: 'home', role: 'visitor', backend:{kind:'home',featured:[],stats:{approved:0,subjects:49,curricula:3,material_types:22}}, expected:'Find resources' },
        { page: 'repository', role: 'visitor', backend: { kind:'repository', materials:{data:[],current_page:1,last_page:1,total:0}, curricula:[{id:1,name:'MATATAG'}], subjects:[{id:1,name:'Science'}], grade_levels:[{id:4,name:'Grade 4'}], material_types:[],tracks:[],strands:[],categories:[] }, expected:'Subject' },
        { page: 'material', role: 'visitor', backend:{kind:'material',material:{id:7,title:'Science worksheet',description:'A saved resource',status:'Approved',contributor:{name:'Educator'},curriculum:{name:'MATATAG'},material_type:{name:'Worksheet'},subjects:[{name:'Science'}],grade_levels:[{name:'Grade 4'}],track:null,strand:null}}, expected:'Science' },
        { page: 'admin-dashboard', role: 'admin', backend:{kind:'records',materials:[],stats:{total:0,approved:0,pending:0},catalog_stats:{subjects:49}}, expected:'49' },
        { page: 'reports', role: 'admin', backend: { kind:'reports', statuses:{Approved:2,Pending:1}, total:3, curricula:1, types:4, by_subject:[{name:'Science',total:2}], by_grade:[{name:'Grade 4',total:1}] }, expected:'Resources by subject' },
        { page: 'upload', role: 'contributor', backend: { kind:'material-form', curricula:[{id:1,name:'MATATAG'}], subjects:[{id:1,name:'Reading and Literacy'}], grade_levels:[{id:2,name:'Grade 1'}], material_types:[{id:3,name:'Worksheet'}], tracks:[], strands:[], categories:[],subject_mappings:[{curriculum_id:1,subject_id:1,grade_level_id:2}],grade_metadata:[{id:2,stage:'elementary'}],track_metadata:[],strand_metadata:[] }, expected:'Reading and Literacy' },
    ];
    for (const testCase of catalogs) {
        const errors=[]; const vc=new VirtualConsole(); vc.on('jsdomError',e=>{if(!e.message.includes('navigation'))errors.push(e.message);});
        const dom=new JSDOM(`<html><head><meta name="csrf-token" content="token"><style>${styles}</style></head><body data-page="${testCase.page}"><div id="app-content"></div><div id="toast-host"></div><div id="confirm-modal"><div id="confirm-message"></div><button id="confirm-action"></button></div></body></html>`,{url:'http://localhost/',runScripts:'outside-only',pretendToBeVisual:true,virtualConsole:vc});
        dom.window.BayaniAuth={id:1,name:'Admin',email:'admin@example.test',role:testCase.role};
        dom.window.BayaniBackend=testCase.backend;
        dom.window.localStorage.setItem('bayani.theme','dark'); dom.window.eval(themeScript);
        try { dom.window.eval(productionScript); } catch(e) { errors.push(e.stack||String(e)); }
        assert.ok(dom.window.document.querySelector('#app-content').textContent.includes(testCase.expected),`${testCase.page}: expected DB-backed content`);
        assert.deepEqual(errors,[],`${testCase.page}: runtime errors`); dom.window.close();
    }
});

test('built review screens show append-only history and database workflow notifications', () => {
    const cases = [
        { page:'review', role:'admin', backend:{kind:'material',transitions:['Approved','Rejected','Revision Requested'],material:{id:18,title:'Review target',description:'Needs approval',status:'Pending',contributor:{name:'Contributor'},reviews:[{decision:'Revision Requested',remarks:'Add a source citation.',reviewed_at:'2026-10-27T10:00:00Z',reviewer:{name:'Administrator'}}]}}, expected:['Review history','Add a source citation.','Administrator','Approve and publish'] },
        { page:'notifications', role:'contributor', backend:{kind:'notifications',notifications:{data:[{id:'n1',read_at:null,created_at:'2026-10-27T10:00:00Z',data:{title:'Material approved',message:'Your material was approved and published.',remarks:'Approved for publication.',url:'/contributor/submissions/18'}}]}}, expected:['Material approved','Approved for publication.','Open'] },
    ];
    for (const testCase of cases) {
        const errors=[]; const vc=new VirtualConsole(); vc.on('jsdomError',e=>{if(!e.message.includes('navigation'))errors.push(e.message);});
        const dom=new JSDOM(`<html><head><meta name="csrf-token" content="token"><style>${styles}</style></head><body data-page="${testCase.page}" data-item-id="18"><div id="app-content"></div><div id="toast-host"></div><div id="confirm-modal"><div id="confirm-message"></div><button id="confirm-action"></button></div></body></html>`,{url:'http://localhost/',runScripts:'outside-only',pretendToBeVisual:true,virtualConsole:vc});
        dom.window.BayaniAuth={id:1,name:'Test account',role:testCase.role}; dom.window.BayaniBackend=testCase.backend;
        dom.window.localStorage.setItem('bayani.theme','dark'); dom.window.eval(themeScript); dom.window.eval(productionScript);
        const content=dom.window.document.querySelector('#app-content');
        for(const text of testCase.expected) assert.ok(content.textContent.includes(text),`${testCase.page}: expected ${text}`);
        assert.deepEqual(errors,[],`${testCase.page}: runtime errors`); dom.window.close();
    }
});

test('built administrator queue exposes database filter controls', () => {
    const dom=new JSDOM(`<html><head><meta name="csrf-token" content="token"><style>${styles}</style></head><body data-page="review-queue"><div id="app-content"></div><div id="toast-host"></div><div id="confirm-modal"><div id="confirm-message"></div><button id="confirm-action"></button></div></body></html>`,{url:'http://localhost/admin/review?status=Pending',runScripts:'outside-only',pretendToBeVisual:true});
    dom.window.BayaniAuth={id:1,name:'Admin',role:'admin'};
    dom.window.BayaniBackend={kind:'records',materials:{data:[],current_page:1,last_page:1,total:0},categories:[],curricula:[{id:1,name:'MATATAG'}],subjects:[{id:2,name:'Science'}],grade_levels:[{id:4,name:'Grade 4'}],contributors:[{id:3,name:'Contributor'}],material_types:[],tracks:[],strands:[]};
    dom.window.eval(themeScript); dom.window.eval(productionScript);
    const form=dom.window.document.querySelector('.toolbar');
    for(const name of ['status','subject','grade','curriculum','contributor']) assert.ok(form.querySelector(`[name="${name}"]`),`missing ${name} filter`);
    assert.match(dom.window.document.querySelector('#app-content').textContent,/No matching materials/);
    dom.window.close();
});
