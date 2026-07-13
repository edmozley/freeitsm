/**
 * System → Roles (RBAC Layer 2).
 *
 * Create roles, tick the settings capabilities they grant, and assign them to
 * analysts and teams. Everything a role touches is saved as one unit by role.php.
 * The capability list, analyst list and team list are embedded server-side
 * (window.RBAC_*) — the page is admin-only, so there's nothing sensitive in
 * shipping them to the browser, and it saves a round-trip.
 */
const Roles = (() => {
    const API = '../../api/system/';
    const CAPS = window.RBAC_CAPS || {};
    const ANALYSTS = window.RBAC_ANALYSTS || [];
    const TEAMS = window.RBAC_TEAMS || [];

    const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

    let roles = [];

    function init() {
        load();
        document.getElementById('createForm').addEventListener('submit', create);
        document.getElementById('editForm').addEventListener('submit', save);
    }

    async function load() {
        const r = await fetch(API + 'roles.php');
        const d = await r.json();
        if (!d.success) { showToast(d.error, 'error'); return; }
        roles = d.data || [];
        render();
    }

    function render() {
        const body = document.getElementById('rolesBody');
        if (!roles.length) {
            body.innerHTML = '<tr><td colspan="5" class="roles-empty">No roles yet. Add one to delegate a slice of administration.</td></tr>';
            return;
        }
        body.innerHTML = roles.map(r => {
            const assigned = [];
            if (+r.analyst_count) assigned.push(`${r.analyst_count} analyst${r.analyst_count == 1 ? '' : 's'}`);
            if (+r.team_count) assigned.push(`${r.team_count} team${r.team_count == 1 ? '' : 's'}`);
            return `<tr>
                <td><strong>${esc(r.name)}</strong>${r.description ? `<br><small style="color:#888;">${esc(r.description)}</small>` : ''}</td>
                <td><span class="roles-chip">${r.capability_count} capabilit${r.capability_count == 1 ? 'y' : 'ies'}</span></td>
                <td>${assigned.length ? esc(assigned.join(', ')) : '<span style="color:#aaa;">nobody</span>'}</td>
                <td>${+r.is_active
                    ? '<span class="status-badge active">Active</span>'
                    : '<span class="status-badge">Inactive</span>'}</td>
                <td>
                    <button class="table-action-btn" title="Edit" onclick="Roles.openEdit(${r.id})">&#9998;</button>
                    <button class="table-action-btn delete" title="Delete" onclick="Roles.remove(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">&#128465;</button>
                </td>
            </tr>`;
        }).join('');
    }

    // ---- create ----
    function openCreate() {
        document.getElementById('cName').value = '';
        document.getElementById('cDescription').value = '';
        open('createModal');
    }

    async function create(e) {
        e.preventDefault();
        const r = await post('roles.php', {
            name: document.getElementById('cName').value.trim(),
            description: document.getElementById('cDescription').value.trim()
        });
        if (!r.success) { showToast(r.error, 'error'); return; }
        close('createModal');
        await load();
        openEdit(r.id);   // straight into the detail so they can grant + assign
    }

    // ---- edit ----
    async function openEdit(id) {
        const r = await fetch(`${API}role.php?id=${id}`);
        const d = await r.json();
        if (!d.success) { showToast(d.error, 'error'); return; }
        const role = d.data;

        document.getElementById('eId').value = role.id;
        document.getElementById('eName').value = role.name;
        document.getElementById('eDescription').value = role.description || '';
        document.getElementById('eActive').checked = +role.is_active === 1;

        renderCapabilities(role.capabilities || []);
        renderPicker('analystPicker', ANALYSTS, role.analyst_ids || [], true);
        renderPicker('teamPicker', TEAMS, role.team_ids || [], false);

        open('editModal');
    }

    function renderCapabilities(selected) {
        const wrap = document.getElementById('capGroups');
        const groups = Object.entries(CAPS);
        if (!groups.length) {
            wrap.innerHTML = '<p style="color:#888;font-size:13px;">No capabilities are defined yet. As modules are wired into the permission system, their settings will appear here to grant.</p>';
            return;
        }
        wrap.innerHTML = groups.map(([mod, group]) => `
            <div class="rl-cap-group">
                <h5>${esc(group.label)}</h5>
                ${Object.entries(group.capabilities).map(([key, cap]) => `
                    <label class="rl-check${cap.umbrella ? ' rl-cap-umbrella' : ''}">
                        <input type="checkbox" class="cap-box" value="${esc(key)}" ${selected.includes(key) ? 'checked' : ''}>
                        <span>${esc(cap.label)}${cap.sensitive ? ' <span class="rl-cap-sensitive" title="Reaches credentials, email or money — grant with care">sensitive</span>' : ''}</span>
                    </label>
                `).join('')}
            </div>
        `).join('');
    }

    function renderPicker(elId, items, selectedIds, showAdmin) {
        const el = document.getElementById(elId);
        if (!items.length) { el.innerHTML = '<p style="color:#888;font-size:13px;margin:4px;">None available.</p>'; return; }
        el.innerHTML = items.map(it => `
            <label class="rl-check">
                <input type="checkbox" class="pick-box" value="${it.id}" ${selectedIds.includes(it.id) ? 'checked' : ''}>
                <span>${esc(it.name)}${showAdmin && it.is_admin ? ' <span class="rl-admin-note">(already an administrator)</span>' : ''}</span>
            </label>
        `).join('');
    }

    async function save(e) {
        e.preventDefault();
        const caps = [...document.querySelectorAll('#capGroups .cap-box:checked')].map(b => b.value);
        const analystIds = [...document.querySelectorAll('#analystPicker .pick-box:checked')].map(b => +b.value);
        const teamIds = [...document.querySelectorAll('#teamPicker .pick-box:checked')].map(b => +b.value);

        const r = await post('role.php', {
            _method: 'PUT',
            id: +document.getElementById('eId').value,
            name: document.getElementById('eName').value.trim(),
            description: document.getElementById('eDescription').value.trim(),
            is_active: document.getElementById('eActive').checked,
            capabilities: caps,
            analyst_ids: analystIds,
            team_ids: teamIds
        });
        if (!r.success) { showToast(r.error, 'error'); return; }
        close('editModal');
        showToast('Role saved', 'success');
        await load();
    }

    async function remove(id, name) {
        const ok = await showConfirm({
            title: 'Delete role',
            message: `Delete the role “${name}”? Anyone who had it loses the access it granted (unless they're a System administrator).`,
            okLabel: 'Delete',
            okClass: 'danger'
        });
        if (!ok) return;
        const r = await post('role.php', { _method: 'DELETE', id });
        if (!r.success) { showToast(r.error, 'error'); return; }
        showToast('Role deleted', 'success');
        await load();
    }

    // ---- plumbing ----
    async function post(endpoint, body) {
        try {
            const r = await fetch(API + endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            return await r.json();
        } catch (err) {
            return { success: false, error: String(err) };
        }
    }

    function open(id) { document.getElementById(id).classList.add('active'); }
    function close(id) { document.getElementById(id).classList.remove('active'); }

    document.addEventListener('DOMContentLoaded', init);
    return { openCreate, openEdit, remove, close };
})();
