// ============================================================
// public/assets/api-client.js
// Drop-in API client for NKTI BIOMED MedTracker
// Replaces all localStorage / in-memory operations with
// real HTTP calls to the PHP/MySQL backend.
// ============================================================

const API_BASE = '/api'; // Change to 'http://192.168.X.X/nkti-biomed/api' for LAN

// ─── Generic fetch wrapper ───────────────────────────────────
async function apiCall(endpoint, method = 'GET', body = null) {
    const opts = {
        method,
        credentials: 'include',             // send session cookie
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    };
    if (body && method !== 'GET') opts.body = JSON.stringify(body);

    let res;
    try {
        res = await fetch(`${API_BASE}/${endpoint}`, opts);
    } catch (err) {
        showToast('⚠ Network error — is the server running?');
        throw err;
    }

    const json = await res.json().catch(() => ({ error: 'Invalid server response' }));
    if (!res.ok && json.error) {
        showToast('⛔ ' + json.error);
        throw new Error(json.error);
    }
    return json;
}

// ═══════════════════════════════════════════════════════════════
// AUTH API
// ═══════════════════════════════════════════════════════════════
const Auth = {
    async login(username, password) {
        return apiCall('auth.php?action=login', 'POST', { username, password });
    },
    async logout() {
        return apiCall('auth.php?action=logout', 'POST');
    },
    async me() {
        return apiCall('auth.php?action=me', 'GET');
    },
    async listUsers() {
        return apiCall('auth.php?action=users', 'GET');
    },
    async createUser(data) {
        return apiCall('auth.php?action=register', 'POST', data);
    },
    async updateUser(data) {
        return apiCall('auth.php?action=update', 'PUT', data);
    },
    async deleteUser(id) {
        return apiCall('auth.php?action=delete', 'DELETE', { id });
    }
};

// ═══════════════════════════════════════════════════════════════
// EQUIPMENT API
// ═══════════════════════════════════════════════════════════════
const Equipment = {
    async list() {
        const res = await apiCall('equipment.php', 'GET');
        return res.data || [];
    },
    async get(id) {
        const res = await apiCall(`equipment.php?id=${id}`, 'GET');
        return res.data;
    },
    async create(data) {
        return apiCall('equipment.php', 'POST', data);
    },
    async update(data) {
        return apiCall('equipment.php', 'PUT', data);
    },
    async delete(id) {
        return apiCall('equipment.php', 'DELETE', { id });
    },
    async bulkImport(items, mode = 'add') {
        return apiCall('equipment.php?action=import', 'POST', { items, mode });
    }
};

// ═══════════════════════════════════════════════════════════════
// REPLACE IN-MEMORY OPERATIONS
// These functions mirror what the original frontend JS did with
// localStorage — now they talk to the database instead.
// ═══════════════════════════════════════════════════════════════

// Called on page load — check session, load data
async function initApp() {
    try {
        const me = await Auth.me();
        currentUser = me.data;
        applyRoleUI();
        document.getElementById('authScreen').style.display = 'none';
        await refreshEquipment();
        renderAll();
    } catch {
        // Not logged in — show auth screen
        document.getElementById('authScreen').style.display = 'flex';
        document.getElementById('authScreen').classList.remove('hidden');
    }
}

// Fetch all equipment from server and cache locally
async function refreshEquipment() {
    equipment = await Equipment.list();
    // Map DB column names to frontend field names
    equipment = equipment.map(normalizeRow);
}

// DB uses snake_case; frontend uses camelCase — normalize
function normalizeRow(row) {
    return {
        id:        row.id,
        name:      row.name,
        brand:     row.brand,
        model:     row.model,
        serial:    row.serial_no,
        date:      row.date_acquired ? row.date_acquired.slice(0, 10) : '',
        section:   row.section,
        status:    row.status,
        supplier:  row.supplier,
        area:      row.area,
        category:  row.category,
        building:  row.building,
        wattage:   parseFloat(row.wattage) || 0,
        hours:     parseFloat(row.hours_per_day) || 0,
    };
}

// Reverse: frontend object → DB payload
function denormalizeRow(obj) {
    return {
        id:       obj.id,
        name:     obj.name,
        brand:    obj.brand,
        model:    obj.model,
        serial:   obj.serial,
        date:     obj.date,
        section:  obj.section,
        status:   obj.status,
        supplier: obj.supplier,
        area:     obj.area,
        category: obj.category,
        building: obj.building,
        wattage:  obj.wattage,
        hours:    obj.hours,
    };
}

// ─── Replace saveEquipment (called from modal save button) ────
async function saveEquipmentToServer(formData) {
    try {
        if (editId) {
            await Equipment.update({ id: editId, ...denormalizeRow(formData) });
            showToast('✓ Equipment updated');
        } else {
            await Equipment.create(denormalizeRow(formData));
            showToast('✓ Equipment added');
        }
        await refreshEquipment();
        closeModal();
        renderAll();
    } catch { /* error already toasted */ }
}

// ─── Replace deleteEquipment ──────────────────────────────────
async function deleteEquipmentFromServer(id) {
    if (!confirm('Delete this equipment record?')) return;
    try {
        await Equipment.delete(id);
        showToast('🗑 Equipment deleted');
        await refreshEquipment();
        renderAll();
    } catch { /* error already toasted */ }
}

// ─── Replace confirmImport ────────────────────────────────────
async function confirmImportToServer(items, mode) {
    try {
        const res = await Equipment.bulkImport(items, mode);
        showToast(`✓ Imported ${res.data.inserted} records, skipped ${res.data.skipped}`);
        await refreshEquipment();
        closeImportModal();
        renderAll();
    } catch { /* error already toasted */ }
}

// ─── Replace doLogin ──────────────────────────────────────────
async function doLoginServer() {
    const username = document.getElementById('loginUser').value.trim();
    const password = document.getElementById('loginPass').value;
    const errEl    = document.getElementById('loginError');
    if (!username || !password) { errEl.textContent = 'Enter username and password.'; return; }
    try {
        const res = await Auth.login(username, password);
        currentUser = res.data;
        errEl.textContent = '';
        applyRoleUI();
        document.getElementById('authScreen').classList.add('hidden');
        setTimeout(() => { document.getElementById('authScreen').style.display = 'none'; }, 400);
        await refreshEquipment();
        renderAll();
        showToast(`✓ Welcome, ${currentUser.username}! (${currentUser.role})`);
    } catch {
        errEl.textContent = '✗ Invalid username or password.';
    }
}

// ─── Replace doLogout ─────────────────────────────────────────
async function doLogoutServer() {
    if (!confirm('Sign out?')) return;
    await Auth.logout().catch(() => {});
    currentUser = null;
    equipment   = [];
    document.getElementById('authScreen').style.display = 'flex';
    setTimeout(() => document.getElementById('authScreen').classList.remove('hidden'), 10);
    document.getElementById('loginUser').value  = '';
    document.getElementById('loginPass').value  = '';
    document.getElementById('loginError').textContent = '';
}

// ─── Replace user management CRUD calls ───────────────────────
async function addUserServer() {
    const uname    = document.getElementById('nu_user').value.trim();
    const pass     = document.getElementById('nu_pass').value;
    const role     = document.getElementById('nu_role').value;
    const building = role === 'viewer' ? (document.getElementById('nu_building')?.value || '') : '';
    const section  = role === 'viewer' ? (document.getElementById('nu_section')?.value.trim() || '') : '';
    if (!uname)            { showToast('⚠ Enter a username'); return; }
    if (pass.length < 6)   { showToast('⚠ Password must be at least 6 characters'); return; }
    try {
        await Auth.createUser({ username: uname, password: pass, role,
            assigned_building: building, assigned_section: section });
        showToast(`✓ User "${uname}" added`);
        document.getElementById('nu_user').value = '';
        document.getElementById('nu_pass').value = '';
        const fresh = await Auth.listUsers();
        users = fresh.data;
        renderUsersTable();
    } catch { /* error toasted */ }
}

// ─── Bootstrap on DOM ready ───────────────────────────────────
document.addEventListener('DOMContentLoaded', initApp);
