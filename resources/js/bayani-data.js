const authUser = window.BayaniAuth && ["admin", "contributor"].includes(window.BayaniAuth.role) ? window.BayaniAuth : null;
const STORAGE_KEY = authUser?.id ? `bayani.user.${authUser.id}.v1` : "bayani.demo.v1";
const LEGACY_MATERIAL_IDS = new Set(["BY-001", "BY-002", "BY-003", "BY-004", "BY-005", "BY-006", "BY-007", "BY-008", "BY-009", "BY-010", "BY-011", "BY-012"]);
const LEGACY_USER_IDS = new Set(["U-001", "U-002", "U-003", "U-004", "U-005"]);
const LEGACY_NOTIFICATION_IDS = new Set(["NT-SAMPLE-1", "NT-SAMPLE-2", "NT-SAMPLE-3"]);
const LEGACY_ACTIVITY_IDS = new Set(["ACT-SAMPLE-1"]);
const seed = {
    categories: [],
    materials: [],
};
const supplementalSeed = {
    profiles: {
        contributor: { id: "demo-contributor", name: "Demo Contributor", email: "contributor@example.invalid", role: "Contributor", bio: "", organization: "", contact: "", avatar: "" },
        admin: { id: "demo-admin", name: "Demo Administrator", email: "administrator@example.invalid", role: "Administrator", bio: "", organization: "", contact: "", avatar: "" },
        visitor: { id: "demo-visitor", name: "Demo Visitor", email: "visitor@example.invalid", role: "Public Visitor", bio: "", organization: "", contact: "", avatar: "" },
    },
    accountPreferences: { theme: "light", notifications: { submission: true, decisions: true, announcements: true } },
    systemSettings: { websiteName: "Project BAYANI", description: "Digital Educational Resource Repository & CMS", repositoryInfo: "Learning resources contributed by the community.", defaultDisplay: "grid", materialsPerPage: 9, allowedTypes: "PDF, PNG, JPG, WEBP, DOC, DOCX, ODT", maxUploadMb: 10, announcement: "", announcementEnabled: false },
    users: [],
    notifications: [],
    activity: [],
    favorites: [], recentlyViewed: [], downloadHistory: [], feedback: [],
};
Object.assign(seed, supplementalSeed);
if (authUser) {
    const authRole = authUser.role;
    seed.profiles[authRole] = {
        ...seed.profiles[authRole],
        id: String(authUser.id),
        name: authUser.name,
        email: authUser.email,
        role: authRole === "admin" ? "Administrator" : "Contributor",
        bio: authUser.bio || "",
        organization: authUser.organization || "",
        contact: authUser.contact || "",
        avatar: authUser.avatar || "",
    };
}
const clone = (value) => JSON.parse(JSON.stringify(value));
const statuses = ["Draft", "Pending", "Approved", "Rejected", "Revision Requested"];
const isObject = value => !!value && typeof value === "object" && !Array.isArray(value);
function validateSaved(saved) {
    for (const key of ["materials", "users", "notifications", "activity", "downloadHistory", "feedback"]) {
        if (saved[key] === undefined) continue;
        if (!Array.isArray(saved[key]) || saved[key].some(item => !isObject(item))) throw new Error(`Invalid ${key}`);
    }
    const ids = new Set();
    for (const material of saved.materials) {
        if (typeof material.id !== "string" || !material.id || ids.has(material.id) || !statuses.includes(material.status)) throw new Error("Invalid material identity or status");
        ids.add(material.id);
        for (const key of ["title", "description", "author", "category", "fileName", "fileMime", "fileType"]) if (material[key] !== undefined && typeof material[key] !== "string") throw new Error(`Invalid material ${key}`);
        if (material.timeline !== undefined && (!Array.isArray(material.timeline) || material.timeline.some(item => !isObject(item)))) throw new Error("Invalid timeline");
    }
    for (const key of ["favorites", "recentlyViewed"]) if (saved[key] !== undefined && (!Array.isArray(saved[key]) || saved[key].some(id => typeof id !== "string"))) throw new Error(`Invalid ${key}`);
    for (const key of ["profiles", "accountPreferences", "systemSettings"]) if (saved[key] !== undefined && !isObject(saved[key])) throw new Error(`Invalid ${key}`);
    for (const [role, profile] of Object.entries(saved.profiles || {})) {
        if (!isObject(profile)) throw new Error("Invalid profile");
        for (const key of Object.keys(seed.profiles[role] || {})) if (profile[key] !== undefined && typeof profile[key] !== "string") throw new Error("Invalid profile field");
    }
    for (const [key, value] of Object.entries(saved.systemSettings || {})) if (key in seed.systemSettings && typeof value !== typeof seed.systemSettings[key]) throw new Error("Invalid system setting");
    if (saved.accountPreferences?.notifications !== undefined && (!isObject(saved.accountPreferences.notifications) || Object.values(saved.accountPreferences.notifications).some(value => typeof value !== "boolean"))) throw new Error("Invalid notification preferences");
    for (const key of ["submission", "decisions", "announcements"]) if (saved.accountPreferences?.notifications?.[key] !== undefined && typeof saved.accountPreferences.notifications[key] !== "boolean") throw new Error("Invalid notification preference");
    if (saved.accountPreferences?.theme !== undefined && !["light", "dark", "system"].includes(saved.accountPreferences.theme)) throw new Error("Invalid theme preference");
    for (const role of Object.keys(seed.profiles)) if (saved.profiles?.[role] !== undefined && !isObject(saved.profiles[role])) throw new Error("Invalid profile shape");
    for (const key of ["id", "name", "email", "role", "bio", "organization", "contact", "avatar"]) if (saved.profiles && Object.values(saved.profiles).some(profile => profile[key] !== undefined && typeof profile[key] !== "string")) throw new Error("Invalid profile shape");
    for (const key of ["materialsPerPage", "maxUploadMb"]) if (saved.systemSettings?.[key] !== undefined && (!Number.isFinite(saved.systemSettings[key]) || saved.systemSettings[key] <= 0)) throw new Error("Invalid numeric system setting");
    if (saved.systemSettings?.defaultDisplay !== undefined && !["grid", "list"].includes(saved.systemSettings.defaultDisplay)) throw new Error("Invalid display setting");
}
const validMaterial = item => ["title", "description", "category", "author"].every(key => typeof item[key] === "string" && item[key].trim());
let storageReadError = false;
let storageSnapshot = null;
function readRole() { if (authUser) return authUser.role; try { const role = localStorage.getItem("bayani.role"); return ["admin", "contributor", "visitor"].includes(role) ? role : "visitor"; } catch (_) { return "visitor"; } }
function load() {
    try {
        storageSnapshot = localStorage.getItem(STORAGE_KEY);
        const saved = JSON.parse(storageSnapshot);
        if (saved && Array.isArray(saved.materials)) {
            validateSaved(saved);
            const merged = { ...clone(seed), ...saved };
            for (const key of ["categories"]) {
                if (saved[key] !== undefined && (!Array.isArray(saved[key]) || saved[key].some(value => typeof value !== "string"))) throw new Error("Invalid saved list");
            }
            merged.profiles = { ...clone(seed.profiles), ...(saved.profiles || {}) };
            for (const role of Object.keys(seed.profiles)) merged.profiles[role] = { ...clone(seed.profiles[role]), ...(saved.profiles?.[role] || {}) };
            if (authUser) Object.assign(merged.profiles[authUser.role], {
                id: String(authUser.id), name: authUser.name, email: authUser.email,
                bio: authUser.bio || "", organization: authUser.organization || "",
                contact: authUser.contact || "", avatar: authUser.avatar || "",
            });
            merged.accountPreferences = { ...clone(seed.accountPreferences), ...(saved.accountPreferences || {}), notifications: { ...clone(seed.accountPreferences.notifications), ...(saved.accountPreferences?.notifications || {}) } };
            merged.systemSettings = { ...clone(seed.systemSettings), ...(saved.systemSettings || {}) };
            merged.notifications = Array.isArray(saved.notifications) ? saved.notifications : clone(seed.notifications);
            merged.activity = Array.isArray(saved.activity) ? saved.activity : clone(seed.activity);
            merged.users = Array.isArray(saved.users) ? saved.users : clone(seed.users);
            merged.favorites = Array.isArray(saved.favorites) ? saved.favorites : [];
            merged.recentlyViewed = Array.isArray(saved.recentlyViewed) ? saved.recentlyViewed : [];
            merged.downloadHistory = Array.isArray(saved.downloadHistory) ? saved.downloadHistory : [];
            merged.feedback = Array.isArray(saved.feedback) ? saved.feedback : [];
            const oldProfiles = {
                contributor: { id: "demo-contributor", name: "Maria Santos", email: "maria.santos@example.test", role: "Contributor", bio: "Araling Panlipunan teacher interested in community learning and oral histories.", organization: "Binalonan Demo High School", contact: "", avatar: "" },
                admin: { id: "demo-admin", name: "Alex Rivera", email: "alex.rivera@example.test", role: "Administrator", bio: "Demo coordinator helping organize educational resources.", organization: "Project BAYANI Demo Team", contact: "", avatar: "" },
                visitor: { id: "demo-visitor", name: "Guest Visitor", email: "guest@example.test", role: "Public Visitor", bio: "Exploring the illustrative learning collection.", organization: "", contact: "", avatar: "" },
            };
            for (const key of Object.keys(oldProfiles)) if (JSON.stringify(saved.profiles?.[key]) === JSON.stringify(oldProfiles[key])) merged.profiles[key] = clone(seed.profiles[key]);
            merged.materials = merged.materials.map(m => ({ ...m, legacySample: m.legacySample || LEGACY_MATERIAL_IDS.has(m.id), ownerId: m.ownerId || ((m.ownerDemo || m.author === (saved.profiles?.contributor?.name || merged.profiles.contributor.name)) ? merged.profiles.contributor.id : undefined), timeline: Array.isArray(m.timeline) ? m.timeline : [{ status: m.status, at: `${m.date || new Date().toISOString().slice(0, 10)}T09:00:00.000Z`, remarks: m.remarks || "" }] }));
            merged.notifications = merged.notifications.map(n => ({ ...n, legacySample: n.legacySample || LEGACY_NOTIFICATION_IDS.has(n.id) || LEGACY_MATERIAL_IDS.has(n.materialId) }));
            merged.activity = merged.activity.map(a => ({ ...a, legacySample: a.legacySample || LEGACY_ACTIVITY_IDS.has(a.id) || LEGACY_MATERIAL_IDS.has(a.materialId) }));
            merged.users = merged.users.map(u => ({ ...u, legacySample: u.legacySample || LEGACY_USER_IDS.has(u.id) }));
            return merged;
        }
        if (saved !== null) storageReadError = true;
    } catch (_) { storageReadError = true; }
    return clone(seed);
}
let state = load();
let committedState = JSON.stringify(state);
const persist = () => {
    try {
        if (storageReadError) throw new Error("Existing storage could not be read; it has been preserved.");
        if (localStorage.getItem(STORAGE_KEY) !== storageSnapshot) throw new Error("Records changed in another tab. Reload before saving.");
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        storageSnapshot = JSON.stringify(state);
        committedState = JSON.stringify(state);
        window.dispatchEvent(new CustomEvent("bayani:change"));
        window.dispatchEvent(new CustomEvent("bayani:storage-status", { detail: { ok: true } }));
        return true;
    } catch (error) {
        state = JSON.parse(committedState);
        window.dispatchEvent(new CustomEvent("bayani:storage-status", { detail: { ok: false, error: error?.name || "StorageError" } }));
        return false;
    }
};
const now = () => new Date().toISOString();
const actor = () => state.profiles[readRole()] || state.profiles.visitor;
const activity = (action, material = null) => { state.activity.unshift({ id: `ACT-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`, action, user: actor().name, role: actor().role, materialId: material?.id || "", materialTitle: material?.title || "", at: now() }); state.activity = state.activity.slice(0, 250); };
const notify = (role, type, title, message, material = null) => { const prefs = state.accountPreferences.notifications; const pref = type === "announcement" ? "announcements" : type === "submission" ? "submission" : "decisions"; if (prefs[pref] === false) return; state.notifications.unshift({ id: `NT-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`, role, type, title, message, materialId: material?.id || "", read: false, at: now() }); state.notifications = state.notifications.slice(0, 150); };
const newMaterialId = () => { let id; do { id = `BY-${Date.now().toString(36).toUpperCase()}-${Math.random().toString(36).slice(2, 6).toUpperCase()}`; } while (state.materials.some(m => m.id === id)); return id; };
window.BayaniStore = {
    get: () => state,
    materials: () => state.materials.filter((m) => !m.legacySample && !LEGACY_MATERIAL_IDS.has(m.id)),
    find: (id) => state.materials.find((m) => m.id === id && !m.legacySample && !LEGACY_MATERIAL_IDS.has(m.id)),
    legacySampleCounts: () => ({ materials: state.materials.filter(m => m.legacySample || LEGACY_MATERIAL_IDS.has(m.id)).length, users: state.users.filter(u => u.legacySample || LEGACY_USER_IDS.has(u.id)).length, notifications: state.notifications.filter(n => n.legacySample || LEGACY_NOTIFICATION_IDS.has(n.id) || LEGACY_MATERIAL_IDS.has(n.materialId)).length, activity: state.activity.filter(a => a.legacySample || LEGACY_ACTIVITY_IDS.has(a.id) || LEGACY_MATERIAL_IDS.has(a.materialId)).length }),
    cleanLegacySampleData: () => {
        const oldIds = new Set(state.materials.filter(m => m.legacySample || LEGACY_MATERIAL_IDS.has(m.id)).map(m => m.id));
        state.materials = state.materials.filter(m => !oldIds.has(m.id));
        state.notifications = state.notifications.filter(n => !n.legacySample && !LEGACY_NOTIFICATION_IDS.has(n.id) && !oldIds.has(n.materialId));
        state.activity = state.activity.filter(a => !a.legacySample && !LEGACY_ACTIVITY_IDS.has(a.id) && !oldIds.has(a.materialId));
        state.users = state.users.filter(u => !u.legacySample && !LEGACY_USER_IDS.has(u.id));
        state.favorites = state.favorites.filter(id => !oldIds.has(id));
        state.recentlyViewed = state.recentlyViewed.filter(id => !oldIds.has(id));
        state.downloadHistory = state.downloadHistory.filter(item => !oldIds.has(item.id));
        activity("Legacy seeded demo data cleaned");
        return persist();
    },
    add: (item) => {
        item = { ...item, status: item.status || "Pending" };
        if (!validMaterial(item) || !["Draft", "Pending"].includes(item.status)) return null;
        const material = { ...item, id: newMaterialId(), ownerDemo: true, ownerId: actor().id, timeline: [{ status: item.status || "Pending", at: now(), remarks: "" }] };
        state.materials.unshift(material);
        activity(item.status === "Draft" ? "Draft created" : "Material submitted", material);
        if (item.status !== "Draft") { notify("admin", "submission", "New material submitted", `${material.title} was submitted by ${actor().name}.`, material); notify("contributor", "submission", "Submission received", `${material.title} is now waiting for review.`, material); }
        return persist() ? material : null;
    },
    update: (id, values) => {
        const item = state.materials.find((m) => m.id === id);
        if (!item) return false;
        const previous = item.status;
        const original = clone(item), previousActivity = clone(state.activity), previousNotifications = clone(state.notifications);
        const transitions = { Draft: ["Pending"], Pending: ["Approved", "Rejected", "Revision Requested"], Rejected: ["Pending"], "Revision Requested": ["Pending"], Approved: [] };
        if (values.id !== undefined && values.id !== id) return false;
        if (!validMaterial({ ...item, ...values })) return false;
        if (values.status !== undefined && values.status !== previous && !transitions[previous]?.includes(values.status)) return false;
        if (values.status !== previous && ["Rejected", "Revision Requested"].includes(values.status) && !String(values.remarks || "").trim()) return false;
        Object.assign(item, values);
        if (values.status && values.status !== previous) {
            item.timeline = [...(item.timeline || []), { status: values.status, at: now(), remarks: values.remarks || "" }];
            const action = values.status === "Approved" ? "Material approved" : values.status === "Rejected" ? "Material rejected" : values.status === "Revision Requested" ? "Revision requested" : values.status === "Pending" && previous !== "Pending" ? "Material resubmitted" : "Material updated";
            activity(action, item);
            if (values.status === "Pending" && previous !== "Pending") {
                notify("admin", "submission", "Material resubmitted", `${item.title} was returned to the review queue by ${actor().name}.`, item);
                notify("contributor", "submission", "Material resubmitted", `${item.title} was returned to the review queue.`, item);
            } else if (values.status !== "Pending") notify("contributor", values.status === "Approved" ? "approved" : values.status === "Rejected" ? "rejected" : "revision", `${values.status}: ${item.title}`, values.remarks || `Your material is now ${values.status.toLowerCase()}.`, item);
        } else if (Object.keys(values).some(k => !["timeline", "remarks"].includes(k))) activity("Material edited", item);
        if (persist()) return true;
        Object.assign(item, original); state.activity = previousActivity; state.notifications = previousNotifications;
        return false;
    },
    remove: (id) => {
        const item = state.materials.find((m) => m.id === id);
        if (item && !item.legacySample) activity("Material removed", item);
        state.materials = state.materials.filter((m) => m.id !== id);
        return persist();
    },
    addList: (key, value) => {
        if (!["categories"].includes(key)) return false;
        const normalized = String(value || "").trim();
        if (!normalized || !Array.isArray(state[key]) || state[key].some(v => v.toLowerCase() === normalized.toLowerCase())) return false;
        state[key].push(normalized);
        return persist();
    },
    editList: (key, oldValue, value) => {
        if (
            !value ||
            state.materials.some(
                (m) =>
                    m[
                        {
                            categories: "category",
                        }[key]
                    ] === oldValue,
            )
        )
            return false;
        const i = state[key].indexOf(oldValue);
        if (i < 0) return false;
        state[key][i] = value;
        return persist();
    },
    deleteList: (key, value) => {
        const prop = {
            categories: "category",
        }[key];
        if (!prop || !state[key].includes(value)) return false;
        if (state.materials.some((m) => !m.legacySample && m[prop] === value)) return false;
        state[key] = state[key].filter((v) => v !== value);
        return persist();
    },
    renameList: (key, oldValue, value) => {
        const prop = { categories: "category" }[key];
        const next = String(value || "").trim();
        if (!prop || !next || next === oldValue || state[key].some(v => v.toLowerCase() === next.toLowerCase()) || !state[key].includes(oldValue)) return false;
        state[key] = state[key].map(item => item === oldValue ? next : item);
        state.materials.forEach(m => { if (m[prop] === oldValue) m[prop] = next; });
        activity(`${key.slice(0, -1)} renamed`);
        return persist();
    },
    listValues: key => (state[key] || []).filter(v => typeof v === "string"),
    stats: () => ({
        total: state.materials.filter((m) => !m.legacySample && !LEGACY_MATERIAL_IDS.has(m.id)).length,
        approved: state.materials.filter((m) => !m.legacySample && !LEGACY_MATERIAL_IDS.has(m.id) && m.status === "Approved").length,
        pending: state.materials.filter((m) => !m.legacySample && !LEGACY_MATERIAL_IDS.has(m.id) && m.status === "Pending").length,
        rejected: state.materials.filter((m) => !m.legacySample && !LEGACY_MATERIAL_IDS.has(m.id) && m.status === "Rejected").length,
        revisions: state.materials.filter(
            (m) => !m.legacySample && !LEGACY_MATERIAL_IDS.has(m.id) && m.status === "Revision Requested",
        ).length,
        categories: state.categories.length,
    }),
    role: () => readRole(),
    setRole: (role) => {
        if (!state.profiles[role]) return false;
        try { localStorage.setItem("bayani.role", role); } catch (_) { window.dispatchEvent(new CustomEvent("bayani:storage-status", {detail:{ok:false}})); return false; }
        window.dispatchEvent(new CustomEvent("bayani:role"));
        return true;
    },
    storageHealthy: () => !storageReadError,
    reset: () => { const previous = state; state = clone(seed); if (persist()) return true; state = previous; return false; },
    commit: persist,
    currentProfile: () => state.profiles[readRole()] || state.profiles.visitor,
    profiles: () => state.profiles,
    updateProfile: (role, values) => {
        if (!state.profiles[role]) return false;
        const previousProfile = clone(state.profiles[role]);
        const previousUsers = clone(state.users);
        const previousActivity = clone(state.activity);
        Object.assign(state.profiles[role], values);
        const user = state.users.find(u => u.id === state.profiles[role].id);
        if (user) Object.assign(user, { name: state.profiles[role].name, email: state.profiles[role].email, organization: state.profiles[role].organization });
        activity("Profile updated");
        if (persist()) return true;
        Object.assign(item, JSON.parse(JSON.stringify(state.materials.find(m => m.id === id) || {})));
        state.profiles[role] = previousProfile;
        state.users = previousUsers;
        state.activity = previousActivity;
        return false;
    },
    updateAccount: (role, values, preferences) => {
        if (!state.profiles[role]) return false;
        const previousProfile = clone(state.profiles[role]), previousUsers = clone(state.users), previousPreferences = clone(state.accountPreferences), previousActivity = clone(state.activity);
        Object.assign(state.profiles[role], values);
        const user = state.users.find(u => u.id === state.profiles[role].id);
        if (user) Object.assign(user, { name: state.profiles[role].name, email: state.profiles[role].email, organization: state.profiles[role].organization });
        state.accountPreferences = { ...state.accountPreferences, ...preferences, notifications: { ...state.accountPreferences.notifications, ...preferences.notifications } };
        activity("Account settings updated");
        if (persist()) return true;
        state.profiles[role] = previousProfile; state.users = previousUsers; state.accountPreferences = previousPreferences; state.activity = previousActivity;
        return false;
    },
    resetProfile: (role) => { if (!seed.profiles[role]) return false; const previousProfile=clone(state.profiles[role]), previousUsers=clone(state.users), previousActivity=clone(state.activity); state.profiles[role] = clone(seed.profiles[role]); const user = state.users.find(u => u.id === state.profiles[role].id); if (user) Object.assign(user, { name: state.profiles[role].name, email: state.profiles[role].email, organization: state.profiles[role].organization }); activity("Demo profile reset"); if(persist())return true; state.profiles[role]=previousProfile;state.users=previousUsers;state.activity=previousActivity;return false; },
    preferences: () => state.accountPreferences,
    updatePreferences: (values) => { const previous=clone(state.accountPreferences), previousActivity=clone(state.activity); state.accountPreferences = { ...state.accountPreferences, ...values, notifications: { ...state.accountPreferences.notifications, ...(values.notifications || {}) } }; activity("Account settings updated"); if(persist())return true;state.accountPreferences=previous;state.activity=previousActivity;return false; },
    systemSettings: () => state.systemSettings,
    updateSystemSettings: (values) => { const before = state.systemSettings; const postAnnouncement = values.announcementEnabled && values.announcement && (values.announcement !== before.announcement || !before.announcementEnabled); Object.assign(state.systemSettings, values); activity("Demo system settings updated"); if (postAnnouncement) { notify("contributor", "announcement", "Project BAYANI announcement", values.announcement); notify("visitor", "announcement", "Project BAYANI announcement", values.announcement); } return persist(); },
    resetSystemSettings: () => { state.systemSettings = clone(seed.systemSettings); activity("Demo system settings reset"); return persist(); },
    notifications: (role = (readRole())) => state.notifications.filter(n => !n.legacySample && !LEGACY_NOTIFICATION_IDS.has(n.id) && !LEGACY_MATERIAL_IDS.has(n.materialId) && n.role === role),
    unreadCount: (role = (readRole())) => state.notifications.filter(n => !n.legacySample && !LEGACY_NOTIFICATION_IDS.has(n.id) && !LEGACY_MATERIAL_IDS.has(n.materialId) && n.role === role && !n.read).length,
    markRead: id => { const item = state.notifications.find(n => n.id === id); if (item) item.read = true; return persist(); },
    markAllRead: (role = readRole()) => { state.notifications.filter(n => n.role === role).forEach(n => n.read = true); return persist(); },
    clearRoleNotifications: (role = readRole()) => { state.notifications = state.notifications.filter(n => n.role !== role || n.legacySample || LEGACY_NOTIFICATION_IDS.has(n.id)); return persist(); },
    deleteNotification: id => { if (!state.notifications.some(n => n.id === id)) return false; const previous = state.notifications; state.notifications = state.notifications.filter(n => n.id !== id); if (persist()) return true; state.notifications = previous; return false; },
    addNotification: (role, type, title, message, material = null) => { notify(role, type, title, message, material); return persist(); },
    activity: () => state.activity.filter(a => !a.legacySample && !LEGACY_ACTIVITY_IDS.has(a.id) && !LEGACY_MATERIAL_IDS.has(a.materialId)),
    log: (action, material = null) => { activity(action, material); return persist(); },
    users: () => state.users.filter(u => !u.legacySample && !LEGACY_USER_IDS.has(u.id)),
    saveUser: (user) => { if (!String(user.name || "").trim() || !String(user.email || "").trim() || !String(user.role || "").trim()) return false; const visibleUsers = state.users.filter(u => !u.legacySample && !LEGACY_USER_IDS.has(u.id)); const email = String(user.email || "").trim().toLowerCase(); if (visibleUsers.some(u => u.id !== user.id && u.email.trim().toLowerCase() === email)) return false; const existing = user.id && visibleUsers.find(u => u.id === user.id); const previous = clone(state.users), previousActivity = clone(state.activity); if (user.id && !existing) return false; if (existing) Object.assign(existing, { ...user, email }); else state.users.push({ ...user, email, id: `U-${Date.now()}-${Math.random().toString(36).slice(2,6)}`, active: true }); activity(existing ? "Demo user modified" : "Demo user added"); if (persist()) return true; state.users = previous; state.activity = previousActivity; return false; },
    toggleUser: (id) => { const user = state.users.find(u => u.id === id); if (!user) return false; user.active = !user.active; activity("Demo user modified"); return persist(); },
    removeProfileAvatar: role => { if (!state.profiles[role]) return false; return window.BayaniStore.updateProfile(role, { avatar: "" }); },
    resetPreferences: () => { state.accountPreferences = clone(seed.accountPreferences); activity("Account preferences reset"); return persist(); },
    isFavorite: id => state.favorites.includes(id) && !!window.BayaniStore.find(id),
    toggleFavorite: id => { if (!window.BayaniStore.find(id) || window.BayaniStore.find(id).status !== "Approved") return false; state.favorites = state.favorites.includes(id) ? state.favorites.filter(v => v !== id) : [id, ...state.favorites]; return persist(); },
    favorites: () => state.favorites.filter(id => { const material=window.BayaniStore.find(id); return material?.status === "Approved"; }),
    recordView: id => { if (window.BayaniStore.find(id)?.status !== "Approved") return; state.recentlyViewed = [id, ...state.recentlyViewed.filter(v => v !== id)].slice(0, 20); persist(); },
    recentlyViewed: () => state.recentlyViewed.filter(id => window.BayaniStore.find(id)?.status === "Approved"),
    recordDownload: id => { const m = window.BayaniStore.find(id); if (!m || m.status !== "Approved" || !(m.fileRef || m.fileToken)) return false; state.downloadHistory.unshift({ id, title: m.title, at: now() }); state.downloadHistory = state.downloadHistory.slice(0, 50); return persist(); },
    downloadHistory: () => state.downloadHistory.filter(item => !!window.BayaniStore.find(item.id)),
    feedback: () => state.feedback,
    addFeedback: (item) => { state.feedback.unshift({ ...item, id: `FB-${Date.now()}`, at: now() }); return persist(); },
    drafts: () => state.materials.filter(m => !m.legacySample && m.status === "Draft" && (m.ownerId === actor().id || (!m.ownerId && m.author === actor().name))),
    submitDraft: id => { const item = state.materials.find(m => m.id === id && m.status === "Draft"); if (!item) return false; const previous = clone(item), previousActivity = clone(state.activity), previousNotifications = clone(state.notifications); item.status = "Pending"; item.date = new Date().toISOString().slice(0, 10); item.timeline = [...(item.timeline || []), { status: "Pending", at: now(), remarks: "Submitted for review" }]; activity("Material submitted", item); notify("admin", "submission", "New material submitted", `${item.title} was submitted by ${actor().name}.`, item); notify("contributor", "submission", "Submission received", `${item.title} is now waiting for review.`, item); if (persist()) return true; Object.assign(item, previous); state.activity = previousActivity; state.notifications = previousNotifications; return false; },
    deleteDraft: id => { const item = state.materials.find(m => m.id === id && m.status === "Draft"); if (!item) return false; activity("Draft deleted", item); state.materials = state.materials.filter(m => m.id !== id); return persist(); },
};
