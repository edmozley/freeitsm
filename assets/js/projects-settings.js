/**
 * Projects -> Settings (3.2.0).
 *
 * Fills every [data-k] control from api/projects/settings.php, shows each
 * default under it, saves a tab at a time (the server checks that tab's
 * capability), and runs the Roles list (add, edit, deactivate, delete, drag to
 * reorder).
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T, esc = P.esc;
    let state = null;

    window.switchTab = function (tab) {
        document.querySelectorAll('.tabs .tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === tab + '-tab'));
        try { history.replaceState(null, '', '?tab=' + tab); } catch (e) { /* ignore */ }
    };

    function defaultLabel(key, value) {
        if (key === 'project_default_method') return T('method.' + value);
        if (key === 'project_create_policy') return T('settings.create_' + value);
        if (key === 'project_change_policy') return T('settings.change_' + value);
        return value;
    }

    function fill() {
        const L = state.lookups;
        const methodSel = document.querySelector('[data-k="project_default_method"]');
        if (methodSel) methodSel.innerHTML = L.methodologies.map(m => '<option value="' + esc(m.key) + '">' + esc(m.label) + '</option>').join('');
        document.querySelectorAll('[data-k]').forEach(el => { el.value = state.settings[el.dataset.k] ?? ''; });
        document.querySelectorAll('[data-d]').forEach(el => {
            const def = state.definitions[el.dataset.d];
            el.textContent = def ? T('settings.default_is', { value: defaultLabel(el.dataset.d, def.default) }) : '';
        });
        renderRoles();
    }

    async function save(tab) {
        const settings = {};
        document.querySelectorAll('[data-settings-tab="' + tab + '"] [data-k]').forEach(el => { settings[el.dataset.k] = el.value; });
        try {
            const r = await P.api('settings.php', { action: 'save', tab: tab, settings: settings });
            state.settings = r.settings;
            fill();
            P.toast(T('settings.saved'));
        } catch (e) { P.toast(e.message, 'error'); }
    }

    // ---- Roles --------------------------------------------------------------------
    function renderRoles() {
        const list = document.getElementById('roleList');
        if (!list) return;
        list.innerHTML = state.roles.map(r =>
            '<li class="prj-role' + (Number(r.is_active) ? '' : ' inactive') + '" draggable="true" data-role="' + r.id + '">'
            + '<span class="prj-grip" aria-hidden="true">&#8942;&#8942;</span>'
            + '<div class="prj-role-text"><strong>' + esc(r.name) + '</strong>' + (r.description ? '<span>' + esc(r.description) + '</span>' : '') + '</div>'
            + (Number(r.in_use) ? '<span class="prj-role-use">' + esc(T('settings.role_in_use', { count: r.in_use })) + '</span>' : '')
            + (Number(r.is_active) ? '' : '<span class="prj-role-use">' + esc(T('settings.role_inactive')) + '</span>')
            + '<button type="button" class="prj-icon-btn" data-role-edit="' + r.id + '" title="' + esc(P.TC('edit')) + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>'
            + '</li>').join('');
    }

    function openRole(role) {
        document.getElementById('rmTitle').textContent = role ? T('settings.role_edit') : T('settings.role_new');
        document.getElementById('rmId').value = role ? role.id : '';
        document.getElementById('rmName').value = role ? role.name : '';
        document.getElementById('rmDesc').value = role ? (role.description || '') : '';
        document.getElementById('rmActive').checked = role ? !!Number(role.is_active) : true;
        document.getElementById('rmDelete').hidden = !role;
        document.getElementById('rmError').hidden = true;
        P.openModal('prjRoleModal');
        setTimeout(() => document.getElementById('rmName').focus(), 60);
    }

    async function roleCall(body) {
        try {
            const r = await P.api('settings.php', body);
            state.roles = r.roles;
            renderRoles();
            return true;
        } catch (e) {
            const er = document.getElementById('rmError');
            if (er && document.getElementById('prjRoleModal').classList.contains('active')) { er.textContent = e.message; er.hidden = false; }
            else P.toast(e.message, 'error');
            return false;
        }
    }

    document.addEventListener('DOMContentLoaded', async () => {
        try {
            const [s, L] = await Promise.all([P.api('settings.php'), P.lookups()]);
            state = { settings: s.settings, definitions: s.definitions, roles: s.roles, lookups: L };
            fill();
        } catch (e) { P.toast(e.message, 'error'); return; }

        document.querySelectorAll('[data-save]').forEach(b => b.addEventListener('click', () => save(b.dataset.save)));
        const add = document.getElementById('roleAdd');
        if (add) add.addEventListener('click', () => openRole(null));
        const list = document.getElementById('roleList');
        if (list) {
            list.addEventListener('click', e => {
                const b = e.target.closest('[data-role-edit]');
                if (b) openRole(state.roles.find(r => String(r.id) === b.dataset.roleEdit));
            });
            // Drag to reorder (desktop).
            let dragEl = null;
            list.addEventListener('dragstart', e => { dragEl = e.target.closest('.prj-role'); if (dragEl) dragEl.classList.add('dragging'); });
            list.addEventListener('dragover', e => {
                if (!dragEl) return;
                e.preventDefault();
                const over = e.target.closest('.prj-role');
                if (!over || over === dragEl) return;
                const r = over.getBoundingClientRect();
                list.insertBefore(dragEl, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over);
            });
            list.addEventListener('dragend', () => {
                if (!dragEl) return;
                dragEl.classList.remove('dragging'); dragEl = null;
                roleCall({ action: 'role_reorder', ids: Array.from(list.children).map(li => Number(li.dataset.role)) });
            });
        }
        const rs = document.getElementById('rmSave');
        if (rs) rs.addEventListener('click', async () => {
            const ok = await roleCall({ action: 'role_save', id: document.getElementById('rmId').value || null,
                name: document.getElementById('rmName').value, description: document.getElementById('rmDesc').value,
                is_active: document.getElementById('rmActive').checked });
            if (ok) { P.closeModal('prjRoleModal'); P.toast(T('settings.saved')); }
        });
        const rd = document.getElementById('rmDelete');
        if (rd) rd.addEventListener('click', async () => {
            const ok = await window.showConfirm({ title: T('settings.role_delete_title'), message: T('settings.role_delete_body'), okLabel: P.TC('delete'), okClass: 'danger' });
            if (!ok) return;
            if (await roleCall({ action: 'role_delete', id: document.getElementById('rmId').value })) P.closeModal('prjRoleModal');
        });
    });
})();
