import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/bayani-data.js', import.meta.url), 'utf8');
function setup(saved, role = 'contributor', auth = null) {
    const entries = new Map([['bayani.role', role]]);
    if (saved !== undefined) entries.set('bayani.demo.v1', typeof saved === 'string' ? saved : JSON.stringify(saved));
    const events = [];
    const storage = { fail: false, getItem: key => entries.get(key) ?? null, setItem(key, value) { if (this.fail) throw new Error('QuotaExceededError'); entries.set(key, value); }, removeItem: key => entries.delete(key) };
    const context = vm.createContext({ localStorage: storage, window: { BayaniAuth: auth, dispatchEvent: event => events.push(event) }, CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options?.detail; } } });
    vm.runInContext(source, context);
    return { store: context.window.BayaniStore, storage, entries, events };
}
test('authenticated prototype data is account-scoped while existing demo records stay untouched', () => {
    const legacyDemo = JSON.stringify({ materials: [{ id: 'BY-USER', title: 'Existing browser record', status: 'Approved' }] });
    const { store, entries } = setup(legacyDemo, 'admin', { id: 42, role: 'contributor', name: 'Logged In', email: 'login@example.test' });
    assert.equal(store.role(), 'contributor', 'the server role overrides a stale demo role');
    assert.equal(store.currentProfile().name, 'Logged In');
    assert.equal(store.materials().length, 0, 'an authenticated account does not inherit shared demo records');
    assert.equal(entries.get('bayani.demo.v1'), legacyDemo, 'the old browser data remains intact');
    const item = store.add({ title: 'Account workspace item', description: 'private local prototype', category: 'History', author: 'Logged In' });
    assert.ok(item);
    assert.ok(entries.has('bayani.user.42.v1'));
    assert.deepEqual(JSON.parse(entries.get('bayani.demo.v1')), JSON.parse(legacyDemo));
});
const material = { title: 'QA material', description: 'QA description', category: 'QA category', author: 'Demo Contributor', date: '2026-10-26', fileType: 'PDF' };

test('fresh state has no records, counters, or synthetic activity', () => {
    const { store } = setup();
    for (const key of ['materials', 'users', 'activity', 'notifications', 'favorites', 'recentlyViewed', 'downloadHistory']) assert.equal(store[key]().length, 0, key);
    assert.ok(Object.values(store.stats()).every(value => value === 0));
});
test('draft, edit, submit, revision, resubmit, approval and collections persist', () => {
    const { store, entries } = setup();
    const draft = store.add({ ...material, status: 'Draft' });
    assert.equal(store.notifications('admin').length, 0);
    assert.equal(store.drafts().length, 1);
    assert.ok(store.update(draft.id, { title: 'Edited QA material' }));
    assert.ok(store.submitDraft(draft.id));
    assert.equal(store.stats().pending, 1);
    store.setRole('admin');
    assert.equal(store.notifications().length, 1);
    assert.ok(store.update(draft.id, { status: 'Revision Requested', remarks: 'Add sources' }));
    store.setRole('contributor');
    assert.ok(store.notifications().some(n => n.type === 'revision'));
    assert.ok(store.update(draft.id, { status: 'Pending', description: 'With sources' }));
    store.setRole('admin');
    assert.ok(store.update(draft.id, { status: 'Approved', remarks: 'Reviewed' }));
    assert.ok(store.toggleFavorite(draft.id));
    store.recordView(draft.id);
    assert.equal(store.recordDownload(draft.id), false, 'unavailable files are not downloads');
    const reloaded = setup(entries.get('bayani.demo.v1')).store;
    assert.equal(reloaded.find(draft.id).status, 'Approved');
    assert.equal(reloaded.find(draft.id).timeline.length, 5);
    assert.equal(reloaded.favorites().length, 1);
    assert.equal(reloaded.recentlyViewed().length, 1);
    assert.ok(reloaded.activity().some(a => a.action === 'Material approved'));
});
test('legacy cleanup is explicit and preserves user records and matching taxonomy names', () => {
    const saved = { materials: [{ ...material, id: 'BY-001', status: 'Approved' }, { ...material, id: 'BY-user', status: 'Draft' }], categories: ['Oral Histories'], users: [{ id: 'U-own', name: 'Local profile', email: 'local@example.invalid' }] };
    const { store, entries } = setup(saved);
    assert.equal(store.materials().length, 1);
    assert.equal(JSON.parse(entries.get('bayani.demo.v1')).materials.length, 2, 'reading never deletes stored records');
    assert.ok(store.cleanLegacySampleData());
    assert.equal(store.get().materials.length, 1);
    assert.equal(store.users().length, 1);
    assert.equal(store.get().categories[0], 'Oral Histories');
    assert.equal('barangays' in store.get(), false);
});
test('storage failure rolls back entire account transaction and records', () => {
    const { store, storage } = setup();
    const before = JSON.stringify(store.get());
    storage.fail = true;
    assert.equal(store.updateAccount('contributor', { name: 'Unsaved' }, { theme: 'dark' }), false);
    assert.equal(JSON.stringify(store.get()), before);
    assert.equal(store.add(material), null);
    assert.equal(JSON.stringify(store.get()), before);
    assert.equal(store.reset(), false);
    assert.equal(JSON.stringify(store.get()), before);
});
test('malformed storage remains intact and cannot be overwritten', () => {
    for (const saved of ['{broken', '{"materials":[],"categories":null}', '{"materials":[],"profiles":{"contributor":null}}', '{"materials":[],"accountPreferences":{"notifications":{"decisions":"yes"}}}', '{"materials":[],"systemSettings":{"maxUploadMb":-1}}']) {
        const { store, entries } = setup(saved);
        assert.equal(store.storageHealthy(), false);
        assert.equal(store.add(material), null);
        assert.equal(entries.get('bayani.demo.v1'), saved);
    }
});
test('failed state-changing operations roll back all in-memory side effects', () => {
    const { store, storage } = setup();
    const item = store.add({ ...material, status: 'Draft' });
    const before = JSON.stringify(store.get());
    storage.fail = true;
    assert.equal(store.submitDraft(item.id), false);
    assert.equal(JSON.stringify(store.get()), before);
    assert.equal(store.updateAccount('contributor', { name: 'Unsaved' }, { theme: 'dark' }), false);
    assert.equal(JSON.stringify(store.get()), before);
    assert.equal(store.saveUser({ name: 'Missing role', email: 'x@example.invalid', role: '' }), false);
    assert.equal(store.update(item.id, { status: 'Pending' }), false);
    assert.equal(JSON.stringify(store.get()), before);
});
test('material status transitions reject arbitrary and skipped states', () => {
    const { store } = setup();
    const draft = store.add({ ...material, status: 'Draft' });
    assert.equal(store.update(draft.id, { status: 'Approved' }), false);
    assert.equal(store.update(draft.id, { status: 'Unknown' }), false);
    assert.equal(store.find(draft.id).status, 'Draft');
});
test('taxonomy rename updates linked materials and duplicate/delete protections work', () => {
    const { store } = setup();
    assert.ok(store.addList('categories', 'QA category'));
    assert.equal(store.addList('categories', 'qa CATEGORY'), false);
    const item = store.add({ ...material, status: 'Draft' });
    assert.ok(store.renameList('categories', 'QA category', 'Renamed'));
    assert.equal(store.find(item.id).category, 'Renamed');
    assert.equal(store.deleteList('categories', 'Renamed'), false);
    assert.ok(store.deleteDraft(item.id));
    assert.ok(store.deleteList('categories', 'Renamed'));
});
test('users, preferences, notifications, feedback, and profile survive reload', () => {
    const { store, entries } = setup();
    assert.ok(store.saveUser({ name: 'QA', email: 'qa@example.invalid', role: 'Contributor' }));
    assert.equal(store.saveUser({ name: 'Duplicate', email: 'QA@example.invalid' }), false);
    const user = store.users()[0];
    assert.ok(store.toggleUser(user.id));
    assert.equal(store.users()[0].active, false);
    store.updateAccount('contributor', { name: 'Updated profile', avatar: '' }, { theme: 'dark', notifications: { announcements: false } });
    store.addNotification('contributor', 'announcement', 'Muted', 'Not displayed');
    assert.equal(store.notifications().length, 0);
    store.addNotification('contributor', 'submission', 'Submitted', 'Local event');
    store.markAllRead();
    assert.equal(store.unreadCount(), 0);
    store.clearRoleNotifications();
    store.addFeedback({ message: 'Local feedback' });
    const reload = setup(entries.get('bayani.demo.v1')).store;
    assert.equal(reload.preferences().theme, 'dark');
    assert.equal(reload.currentProfile().name, 'Updated profile');
    assert.equal(reload.feedback().length, 1);
    assert.equal(reload.notifications().length, 0);
});
test('invalid persisted role falls back to visitor', () => assert.equal(setup(undefined, 'unknown').store.role(), 'visitor'));

test('a stale tab cannot overwrite newer saved records', () => {
    const { store, entries } = setup();
    const newer = JSON.stringify({ materials: [{ ...material, id: 'BY-other-tab', status: 'Draft' }] });
    entries.set('bayani.demo.v1', newer);
    assert.equal(store.add(material), null);
    assert.equal(entries.get('bayani.demo.v1'), newer);
    assert.equal(store.materials().length, 0);
});

test('legacy contributor-owned records remain editable after profile migration', () => {
    const { store } = setup({ materials: [{ ...material, id: 'BY-created', author: 'Previous contributor', ownerDemo: true, status: 'Draft' }] });
    assert.equal(store.drafts().length, 1);
    assert.equal(store.find('BY-created').ownerId, store.currentProfile().id);
});
