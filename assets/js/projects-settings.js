/**
 * Projects -> Settings (3.2.0).
 *
 * Fills every [data-k] control from api/projects/settings.php, shows each
 * default under it, saves a tab at a time (the server checks that tab's
 * capability), runs the Roles list (add, edit, deactivate, delete, drag to
 * reorder) and the Templates list (show or hide a built-in; rename, switch off
 * or delete a saved one) over api/projects/templates.php.
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
        if (key === 'project_calendar') return T('settings.calendar_' + value);
        if (key === 'project_disruption') return T('settings.disruption_' + value);
        if (key === 'project_labour_mode') return T('settings.labour_' + value);
        if (key === 'project_baseline_auto') return T('settings.baseline_auto_' + value);
        if (key === 'project_change_approver') return T('settings.approver_' + value);
        if (key === 'project_change_apply') return T('settings.apply_' + value);
        if (key === 'project_cost_basis') return T('settings.basis_' + value);
        if (key === 'project_currency_per_project' || key === 'project_capacity_desk' || key === 'project_change_self' || key === 'project_forecast_labour') return value === '1' ? P.TC('yes') : P.TC('no');
        // 3.3.0
        if (key === 'project_health_milestones' || key === 'project_health_raid_late') return T('settings.effect_' + value);
        if (key === 'project_portfolio_sort') return T('portfolio.sort_' + value);
        if (key === 'project_burnup_measure') return T('settings.measure_' + value);
        if (key === 'project_capacity_days') return String(value).split(',').map(d => T('settings.day_' + d)).join(', ');
        if (Array.isArray(value)) return value.join(', ');
        return value;
    }

    function fill() {
        const L = state.lookups;
        const methodSel = document.querySelector('[data-k="project_default_method"]');
        if (methodSel) methodSel.innerHTML = L.methodologies.map(m => '<option value="' + esc(m.key) + '">' + esc(m.label) + '</option>').join('');
        document.querySelectorAll('[data-k]').forEach(el => {
            const v = state.settings[el.dataset.k];
            if (el.classList.contains('prj-days')) {
                // Working days (3.3.0): one box per weekday.
                const on = String(v || '').split(',');
                el.querySelectorAll('[data-day]').forEach(cb => { cb.checked = on.includes(cb.dataset.day); });
                return;
            }
            if (el.classList.contains('prj-scale')) {
                // A risk scale: one box per step, the default word as a hint.
                const def = (state.definitions[el.dataset.k] || {}).default || [];
                el.querySelectorAll('[data-step]').forEach(inp => {
                    inp.value = (v || [])[inp.dataset.step] ?? '';
                    inp.placeholder = def[inp.dataset.step] || '';
                });
            } else {
                el.value = v ?? '';
            }
        });
        document.querySelectorAll('[data-d]').forEach(el => {
            const def = state.definitions[el.dataset.d];
            el.textContent = def ? T('settings.default_is', { value: defaultLabel(el.dataset.d, def.default) }) : '';
        });
        renderRoles();
    }

    async function save(tab) {
        const settings = {};
        document.querySelectorAll('[data-settings-tab="' + tab + '"] [data-k]').forEach(el => {
            settings[el.dataset.k] = el.classList.contains('prj-scale')
                ? Array.from(el.querySelectorAll('[data-step]')).map(i => i.value)
                : el.classList.contains('prj-days')
                    ? Array.from(el.querySelectorAll('[data-day]:checked')).map(cb => cb.dataset.day).join(',')
                    : el.value;
        });
        try {
            const r = await P.api('settings.php', { action: 'save', tab: tab, settings: settings });
            state.settings = r.settings;
            fill();
            P.toast(T('settings.saved'));
        } catch (e) { P.toast(e.message, 'error'); }
    }

    // ---- Templates ----------------------------------------------------------------
    let templates = [];
    function renderTemplates() {
        const b = document.getElementById('tplBuiltin');
        if (!b) return;
        const row = (t, control) =>
            '<li class="prj-role' + (t.active ? '' : ' inactive') + '">'
            + '<span class="prj-tpl-icon" style="background:' + P.gradient(t.colour) + '">' + P.icon(t.icon, 16) + '</span>'
            + '<div class="prj-role-text"><strong>' + esc(t.name) + '</strong><span>' + esc(P.templateMeta(t))
            + (t.created_by_name ? ' · ' + esc(T('settings.template_by', { name: t.created_by_name })) : '') + '</span></div>'
            + (t.active ? '' : '<span class="prj-role-use">' + esc(T('settings.role_inactive')) + '</span>')
            + control + '</li>';
        b.innerHTML = templates.filter(t => t.builtin).map(t => row(t,
            '<label class="prj-check prj-tpl-toggle"><input type="checkbox" data-tpl-show="' + esc(t.key) + '"' + (t.active ? ' checked' : '') + '> ' + esc(T('settings.template_offered')) + '</label>')).join('');
        const saved = templates.filter(t => !t.builtin);
        document.getElementById('tplSaved').innerHTML = saved.map(t => row(t,
            '<button type="button" class="prj-icon-btn" data-tpl-edit="' + t.id + '" title="' + esc(P.TC('edit')) + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>')).join('');
        document.getElementById('tplNone').hidden = saved.length > 0;
    }
    async function tplCall(body) {
        try {
            const r = await P.api('templates.php', body);
            templates = r.templates;
            renderTemplates();
            return true;
        } catch (e) {
            const er = document.getElementById('tmError');
            if (er && document.getElementById('prjTplModal').classList.contains('active')) { er.textContent = e.message; er.hidden = false; }
            else P.toast(e.message, 'error');
            return false;
        }
    }
    async function wireTemplates() {
        if (!document.getElementById('tplBuiltin')) return;
        try {
            // The colour keys need the palette, which only the lookups carry.
            const [r, L] = await Promise.all([P.api('templates.php'), P.lookups()]);
            P.setPalette(L.colours);
            templates = r.templates;
        } catch (e) { P.toast(e.message, 'error'); return; }
        renderTemplates();
        document.getElementById('tplBuiltin').addEventListener('change', e => {
            const c = e.target.closest('[data-tpl-show]');
            if (c) tplCall({ action: 'builtin_hidden', key: c.dataset.tplShow, hidden: !c.checked });
        });
        document.getElementById('tplSaved').addEventListener('click', e => {
            const b = e.target.closest('[data-tpl-edit]'); if (!b) return;
            const t = templates.find(x => String(x.id) === b.dataset.tplEdit); if (!t) return;
            document.getElementById('tmId').value = t.id;
            document.getElementById('tmName').value = t.name;
            document.getElementById('tmDesc').value = t.description || '';
            document.getElementById('tmActive').checked = !!t.active;
            document.getElementById('tmError').hidden = true;
            P.openModal('prjTplModal');
        });
        document.getElementById('tmSave').addEventListener('click', async () => {
            if (await tplCall({ action: 'update', id: document.getElementById('tmId').value, name: document.getElementById('tmName').value,
                description: document.getElementById('tmDesc').value, is_active: document.getElementById('tmActive').checked })) {
                P.closeModal('prjTplModal'); P.toast(T('settings.saved'));
            }
        });
        document.getElementById('tmDelete').addEventListener('click', async () => {
            const ok = await window.showConfirm({ title: T('settings.template_delete_title'), message: T('settings.template_delete_body'), okLabel: P.TC('delete'), okClass: 'danger' });
            if (ok && await tplCall({ action: 'delete', id: document.getElementById('tmId').value })) P.closeModal('prjTplModal');
        });
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

    // ---- Hourly rates (Budget tab, 3.2.0) ----------------------------------
    // Each from a date: a new rate never re-prices time already logged.
    function renderRates() {
        const box = document.getElementById('rateList');
        if (!box) return;
        const cur = state.settings.project_currency || 'GBP';
        const money = v => { try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur }).format(v); } catch (e) { return cur + ' ' + v; } };
        if (!state.rates.length) { box.innerHTML = '<p class="prj-muted">' + esc(T('settings.no_rates')) + '</p>'; return; }
        box.innerHTML = '<table class="prj-budget-table"><tbody>' + state.rates.map(r =>
            '<tr><td>' + esc(r.scope === 'default' ? T('settings.rate_default') : (r.analyst_name || '#' + r.ref_id)) + '</td>'
            + '<td class="num">' + esc(money(Number(r.hourly_rate))) + '</td><td>' + esc(P.fmtDate(r.effective_from)) + '</td>'
            + '<td class="num"><button type="button" class="prj-task-remove" style="opacity:1" data-rate-del="' + r.id + '" aria-label="' + esc(P.TC('delete')) + '">&times;</button></td></tr>').join('') + '</tbody></table>';
    }
    async function rateCall(body) {
        try { const r = await P.api('settings.php', body); state.rates = r.rates || []; renderRates(); return true; }
        catch (e) { P.toast(e.message, 'error'); return false; }
    }
    function wireRates() {
        const sel = document.getElementById('rtScope');
        if (!sel) return;
        sel.innerHTML = '<option value="default">' + esc(T('settings.rate_default')) + '</option>'
            + state.analysts.map(a => '<option value="a' + a.id + '">' + esc(a.full_name) + '</option>').join('');
        document.getElementById('rtFrom').value = P.todayStr();
        renderRates();
        document.getElementById('rtAdd').addEventListener('click', async () => {
            const v = sel.value;
            const ok = await rateCall({ action: 'rate_save', scope: v === 'default' ? 'default' : 'analyst', analyst_id: v === 'default' ? null : parseInt(v.slice(1), 10),
                rate: document.getElementById('rtRate').value, from: document.getElementById('rtFrom').value });
            if (ok) { document.getElementById('rtRate').value = ''; P.toast(T('settings.saved')); }
        });
        document.getElementById('rateList').addEventListener('click', e => {
            const b = e.target.closest('[data-rate-del]');
            if (b) rateCall({ action: 'rate_delete', id: parseInt(b.dataset.rateDel, 10) });
        });
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
            state = { settings: s.settings, definitions: s.definitions, roles: s.roles, lookups: L, rates: s.rates || [], analysts: s.analysts || [] };
            fill();
            wireRates();
        } catch (e) { P.toast(e.message, 'error'); return; }

        document.querySelectorAll('[data-save]').forEach(b => b.addEventListener('click', () => save(b.dataset.save)));
        wireTemplates();
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
