/**
 * Projects - the tools on a project's page (3.2.0 phase 2): People, Scope (the
 * MoSCoW board) and the RACI matrix.
 *
 * projects-view.js owns the page and its data; after every redraw it calls
 * PrjTools.render({data, L, projectId, refresh, page}) and this file draws the
 * three tool panels from that same data, so nothing here can disagree with the
 * banner or the plan. Every change goes to api/projects/tools.php and then asks
 * the view to refresh.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T, esc = P.esc;
    let ctx = null;          // the latest {data, L, projectId, refresh, page}
    let wired = false;
    const MOSCOW = ['must', 'should', 'could', 'wont'];

    function canChange() { return !(ctx.data.permissions && !ctx.data.permissions.can_change); }
    async function call(body) { return P.api('tools.php', Object.assign({ project_id: ctx.projectId }, body)); }

    // ---- People ---------------------------------------------------------------------
    function kindIcon(kind) { return P.icon(kind === 'team' ? 'users' : (kind === 'person' ? 'heart' : 'laptop'), 14); }

    function renderPeople() {
        const box = document.getElementById('pvPeople');
        const d = ctx.data, L = ctx.L;
        const roleOpts = (sel) => '<option value="">' + esc(T('people.no_role')) + '</option>'
            + (L.roles || []).map(r => '<option value="' + r.id + '"' + (String(r.id) === String(sel) ? ' selected' : '') + '>' + esc(r.name) + '</option>').join('');
        const pm = d.project.owner_analyst_id;
        let html = '<div class="prj-plan-head"><p class="prj-muted">' + esc(T('people.intro')) + '</p>'
            + (canChange() ? '<button type="button" class="btn btn-primary prj-btn" data-pm-add>+ ' + esc(T('people.add')) + '</button>' : '') + '</div>';
        if (!d.members.length) {
            html += '<div class="prj-plan-empty">' + esc(T('people.empty')) + '</div>';
        } else {
            html += '<div class="prj-people-grid">' + d.members.map(m =>
                '<div class="prj-person">'
                + '<span class="prj-avatar lg">' + esc(P.initials(m.name)) + '</span>'
                + '<div class="prj-person-main">'
                +   '<div class="prj-person-name">' + esc(m.name) + (m.kind === 'analyst' && String(m.analyst_id) === String(pm) ? ' <span class="prj-pm-badge">' + esc(T('people.pm_badge')) + '</span>' : '') + '</div>'
                +   '<div class="prj-person-kind">' + kindIcon(m.kind) + esc(T('people.kind_label_' + m.kind)) + (m.job_title ? ' &middot; ' + esc(m.job_title) : '') + '</div>'
                +   (canChange()
                        ? '<select class="prj-role-select" data-member-role="' + m.id + '" aria-label="' + esc(T('people.role')) + '">' + roleOpts(m.role_id) + '</select>'
                        : '<div class="prj-person-role">' + esc(m.role_name || T('people.no_role')) + '</div>')
                + '</div>'
                + (canChange() ? '<button type="button" class="prj-task-remove" data-member-remove="' + m.id + '" title="' + esc(T('people.remove')) + '" aria-label="' + esc(T('people.remove')) + '">&times;</button>' : '')
                + '</div>').join('') + '</div>';
        }
        box.innerHTML = html;
    }

    let memberKind = 'analyst';
    function openMember() {
        const L = ctx.L;
        memberKind = 'analyst';
        document.querySelectorAll('#pmKind [data-kind]').forEach(b => b.classList.toggle('active', b.dataset.kind === 'analyst'));
        document.getElementById('pmRole').innerHTML = '<option value="">' + esc(T('people.no_role')) + '</option>' + (L.roles || []).map(r => '<option value="' + r.id + '">' + esc(r.name) + '</option>').join('');
        document.getElementById('pmError').hidden = true;
        fillMemberPick();
        P.openModal('prjMemberModal');
    }
    function fillMemberPick() {
        const L = ctx.L, taken = ctx.data.members;
        const person = memberKind === 'person';
        document.getElementById('pmPickWrap').hidden = person;
        document.getElementById('pmPersonWrap').hidden = !person;
        document.getElementById('pmPersonId').value = '';
        document.getElementById('pmPerson').value = '';
        document.getElementById('pmPersonResults').hidden = true;
        if (person) return;
        const list = memberKind === 'team' ? (L.teams || []).map(t => ({ id: t.id, name: t.name })) : L.analysts.map(a => ({ id: a.id, name: a.full_name }));
        const col = memberKind === 'team' ? 'team_id' : 'analyst_id';
        document.getElementById('pmPickLabel').textContent = T('people.pick_' + memberKind);
        document.getElementById('pmPick').innerHTML = list.filter(x => !taken.some(m => String(m[col]) === String(x.id)))
            .map(x => '<option value="' + x.id + '">' + esc(x.name) + '</option>').join('');
    }
    let peopleTimer = null;
    function searchPeople() {
        clearTimeout(peopleTimer);
        const q = document.getElementById('pmPerson').value.trim();
        const list = document.getElementById('pmPersonResults');
        peopleTimer = setTimeout(async () => {
            try {
                const d = await P.api('tools.php?project_id=' + ctx.projectId + '&people=' + encodeURIComponent(q));
                const taken = ctx.data.members.map(m => String(m.user_id));
                const rows = (d.people || []).filter(p => !taken.includes(String(p.id)));
                list.innerHTML = rows.length ? rows.map(p => '<li><button type="button" data-person="' + p.id + '" data-person-name="' + esc(p.name) + '"><span class="prj-conn-label">' + esc(p.name) + '</span>'
                    + '<span class="prj-conn-sub">' + esc([p.job_title, p.email].filter(Boolean).join(' - ')) + '</span></button></li>').join('')
                    : '<li class="prj-conn-noresult">' + esc(T('links.no_results')) + '</li>';
                list.hidden = false;
            } catch (e) { P.toast(e.message, 'error'); }
        }, 180);
    }
    async function saveMember() {
        const body = { action: 'member_add', role_id: document.getElementById('pmRole').value || null };
        if (memberKind === 'person') body.user_id = document.getElementById('pmPersonId').value;
        else body[memberKind === 'team' ? 'team_id' : 'analyst_id'] = document.getElementById('pmPick').value;
        const pick = body.user_id || body.team_id || body.analyst_id;
        const err = document.getElementById('pmError');
        if (!pick) { err.textContent = T('people.choose'); err.hidden = false; return; }
        try {
            await call(body);
            P.closeModal('prjMemberModal');
            P.toast(T('people.added'));
            await ctx.refresh();
        } catch (e) { err.textContent = e.message; err.hidden = false; }
    }

    // ---- Scope / MoSCoW ----------------------------------------------------------------
    function itemCard(it) {
        const stage = it.stage_name ? '<span class="prj-item-stage">' + esc(it.stage_name) + '</span>' : '';
        return '<div class="prj-item' + (it.status === 'dropped' ? ' dropped' : '') + (it.status === 'accepted' ? ' accepted' : '') + '"' + (canChange() ? ' draggable="true"' : '') + ' data-item="' + it.id + '">'
            + '<div class="prj-item-title">' + esc(it.title) + '</div>'
            + '<div class="prj-item-meta"><span class="prj-item-status st-' + esc(it.status) + '">' + esc(T('scope.status_' + it.status)) + '</span>' + stage + '</div>'
            + '</div>';
    }

    function renderScope() {
        const box = document.getElementById('pvScope');
        const items = ctx.data.items;
        const cols = [...MOSCOW, ''];
        const counts = {}; MOSCOW.forEach(k => { counts[k] = items.filter(i => i.moscow === k).length; });
        let html = '<div class="prj-plan-head"><p class="prj-muted">' + esc(T('scope.intro')) + '</p>'
            + '<span class="prj-scope-summary">' + esc(T('scope.summary', { must: counts.must, should: counts.should, could: counts.could })) + '</span></div>';
        html += '<div class="prj-moscow">' + cols.map(k => {
            const rows = items.filter(i => (i.moscow || '') === k);
            return '<section class="prj-moscow-col mc-' + (k || 'none') + '" data-moscow="' + k + '">'
                + '<header><h4>' + esc(k ? T('scope.' + k) : T('scope.unsorted')) + ' <span class="prj-conn-count">' + rows.length + '</span></h4>'
                + (k ? '<small>' + esc(T('scope.' + k + '_hint')) + '</small>' : '') + '</header>'
                + '<div class="prj-moscow-cards" data-drop-moscow="' + k + '">' + rows.map(itemCard).join('') + '</div>'
                + (canChange() ? '<form class="prj-moscow-add" data-add-moscow="' + k + '"><input type="text" maxlength="255" placeholder="' + esc(T('scope.add_ph')) + '" aria-label="' + esc(T('scope.add')) + '"><button type="submit" class="prj-icon-btn" aria-label="' + esc(T('scope.add')) + '">+</button></form>' : '')
                + '</section>';
        }).join('') + '</div>';
        box.innerHTML = html;
    }

    function openItem(it) {
        const d = ctx.data;
        document.getElementById('piTitle').textContent = it ? T('scope.item_edit') : T('scope.item_new');
        document.getElementById('piId').value = it ? it.id : '';
        document.getElementById('piName').value = it ? it.title : '';
        document.getElementById('piMoscow').innerHTML = '<option value="">' + esc(T('scope.unsorted')) + '</option>' + MOSCOW.map(k => '<option value="' + k + '">' + esc(T('scope.' + k)) + '</option>').join('');
        document.getElementById('piMoscow').value = it ? (it.moscow || '') : '';
        document.getElementById('piStatus').innerHTML = ['proposed', 'agreed', 'in_progress', 'accepted', 'dropped'].map(s => '<option value="' + s + '">' + esc(T('scope.status_' + s)) + '</option>').join('');
        document.getElementById('piStatus').value = it ? it.status : 'proposed';
        const kind = (ctx.L.methodologies.find(m => m.key === d.project.methodology) || {}).timebox || 'phase';
        document.getElementById('piStageLabel').textContent = T('timebox.' + kind);
        document.getElementById('piStage').innerHTML = '<option value="">' + esc(T('scope.no_stage')) + '</option>' + d.stages.map(s => '<option value="' + s.id + '">' + esc(s.name) + '</option>').join('');
        document.getElementById('piStage').value = it ? (it.stage_id || '') : '';
        document.getElementById('piDesc').value = it ? (it.description || '') : '';
        document.getElementById('piAcc').value = it ? (it.acceptance_criteria || '') : '';
        document.getElementById('piDelete').hidden = !it || !canChange();
        document.getElementById('piSave').hidden = !canChange();
        document.getElementById('piError').hidden = true;
        P.openModal('prjItemModal');
    }
    async function saveItem() {
        const id = document.getElementById('piId').value;
        try {
            await call({ action: 'item_save', id: id || null, title: document.getElementById('piName').value,
                moscow: document.getElementById('piMoscow').value || null, status: document.getElementById('piStatus').value,
                stage_id: document.getElementById('piStage').value || null, description: document.getElementById('piDesc').value,
                acceptance_criteria: document.getElementById('piAcc').value });
            P.closeModal('prjItemModal');
            await ctx.refresh();
        } catch (e) { const er = document.getElementById('piError'); er.textContent = e.message; er.hidden = false; }
    }

    // ---- RACI ---------------------------------------------------------------------------
    function renderRaci() {
        const box = document.getElementById('pvRaci');
        const d = ctx.data, members = d.members, items = d.items.filter(i => i.status !== 'dropped');
        const legend = '<div class="prj-raci-legend">' + ['r', 'a', 'c', 'i'].map(k =>
            '<span><b class="prj-raci-letter l-' + k + '">' + k.toUpperCase() + '</b>' + esc(T('raci.' + k)) + ' <small>' + esc(T('raci.' + k + '_hint')) + '</small></span>').join('') + '</div>';
        if (!members.length || !items.length) {
            const key = !members.length && !items.length ? 'need_both' : (!members.length ? 'need_people' : 'need_items');
            box.innerHTML = '<p class="prj-muted">' + esc(T('raci.intro')) + '</p>' + legend + '<div class="prj-plan-empty">' + esc(T('raci.' + key)) + '</div>';
            return;
        }
        const raci = d.raci || {};
        let html = '<p class="prj-muted">' + esc(T('raci.intro')) + '</p>' + legend
            + '<div class="prj-raci-wrap"><table class="prj-raci"><thead><tr><th class="prj-raci-item">' + esc(T('raci.deliverable')) + '</th>'
            + members.map(m => '<th><span class="prj-avatar sm">' + esc(P.initials(m.name)) + '</span><span class="prj-raci-name">' + esc(m.name) + '</span>'
                + (m.role_name ? '<span class="prj-raci-role">' + esc(m.role_name) + '</span>' : '') + '</th>').join('')
            + '</tr></thead><tbody>';
        items.forEach(it => {
            const row = raci[it.id] || {};
            const letters = Object.values(row);
            const warn = !letters.includes('A') ? T('raci.no_a') : (!letters.includes('R') ? T('raci.no_r') : '');
            html += '<tr><th class="prj-raci-item"><span>' + esc(it.title) + '</span>' + (warn ? '<small class="prj-raci-warn">' + esc(warn) + '</small>' : '') + '</th>'
                + members.map(m => {
                    const l = row[m.id] || '';
                    return '<td><button type="button" class="prj-raci-cell' + (l ? ' l-' + l.toLowerCase() : '') + '" data-raci="' + it.id + ':' + m.id + '"'
                        + (canChange() ? '' : ' disabled') + ' aria-label="' + esc(it.title + ' - ' + m.name + ': ' + (l ? T('raci.' + l.toLowerCase()) : '-')) + '">' + esc(l) + '</button></td>';
                }).join('') + '</tr>';
        });
        html += '</tbody></table></div>';
        box.innerHTML = html;
    }

    async function cycleRaci(btn) {
        const [itemId, memberId] = btn.dataset.raci.split(':');
        const order = ['', 'R', 'A', 'C', 'I'];
        const now = (btn.textContent || '').trim();
        const next = order[(order.indexOf(now) + 1) % order.length];
        // Optimistic: show it at once, then take the server's row (a demoted A).
        btn.textContent = next; btn.className = 'prj-raci-cell' + (next ? ' l-' + next.toLowerCase() : '');
        try {
            const r = await call({ action: 'raci_set', item_id: itemId, member_id: memberId, letter: next });
            const before = Object.assign({}, (ctx.data.raci || {})[itemId] || {});
            ctx.data.raci = ctx.data.raci || {};
            ctx.data.raci[itemId] = r.row || {};
            if (next === 'A' && Object.entries(before).some(([mid, l]) => l === 'A' && String(mid) !== String(memberId))) P.toast(T('raci.demoted'), 'info');
            renderRaci();
        } catch (e) { P.toast(e.message, 'error'); await ctx.refresh(); }
    }

    // ---- Wiring (once) --------------------------------------------------------------------
    function wire() {
        if (wired) return;
        wired = true;
        const page = ctx.page;
        page.addEventListener('click', async e => {
            if (e.target.closest('[data-pm-add]')) { openMember(); return; }
            const rm = e.target.closest('[data-member-remove]');
            if (rm) {
                const ok = await window.showConfirm({ title: T('people.remove_title'), message: T('people.remove_body'), okLabel: T('people.remove'), okClass: 'danger' });
                if (!ok) return;
                try { await call({ action: 'member_remove', member_id: rm.dataset.memberRemove }); P.toast(T('people.removed')); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); }
                return;
            }
            const card = e.target.closest('.prj-item');
            if (card) { openItem(ctx.data.items.find(i => String(i.id) === card.dataset.item)); return; }
            const cell = e.target.closest('[data-raci]');
            if (cell && !cell.disabled) { cycleRaci(cell); }
        });
        page.addEventListener('change', async e => {
            const sel = e.target.closest('[data-member-role]');
            if (!sel) return;
            try { await call({ action: 'member_update', member_id: sel.dataset.memberRole, role_id: sel.value || null }); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); }
        });
        page.addEventListener('submit', async e => {
            const f = e.target.closest('[data-add-moscow]');
            if (!f) return;
            e.preventDefault();
            const input = f.querySelector('input');
            const title = input.value.trim();
            if (!title) return;
            const col = f.dataset.addMoscow;
            try {
                await call({ action: 'item_save', title: title, moscow: col || null });
                await ctx.refresh();
                const again = document.querySelector('[data-add-moscow="' + col + '"] input');
                if (again) again.focus();
            } catch (err) { P.toast(err.message, 'error'); }
        });
        // Drag a card between MoSCoW columns (desktop).
        let dragId = null;
        page.addEventListener('dragstart', e => { const c = e.target.closest('.prj-item'); if (c) { dragId = c.dataset.item; c.classList.add('dragging'); } });
        page.addEventListener('dragend', e => { const c = e.target.closest('.prj-item'); if (c) c.classList.remove('dragging'); document.querySelectorAll('.prj-moscow-col.drop-over').forEach(x => x.classList.remove('drop-over')); });
        page.addEventListener('dragover', e => {
            const col = e.target.closest('.prj-moscow-col'); if (!col || !dragId) return;
            e.preventDefault();
            document.querySelectorAll('.prj-moscow-col.drop-over').forEach(x => { if (x !== col) x.classList.remove('drop-over'); });
            col.classList.add('drop-over');
            const zone = col.querySelector('.prj-moscow-cards');
            const dragged = document.querySelector('.prj-item[data-item="' + dragId + '"]');
            const over = e.target.closest('.prj-item');
            if (dragged && zone) {
                if (over && over !== dragged) { const r = over.getBoundingClientRect(); zone.insertBefore(dragged, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over); }
                else if (!over && dragged.parentNode !== zone) zone.appendChild(dragged);
            }
        });
        page.addEventListener('drop', async e => {
            const col = e.target.closest('.prj-moscow-col'); if (!col || !dragId) return;
            e.preventDefault();
            col.classList.remove('drop-over');
            const id = dragId; dragId = null;
            const order = Array.from(col.querySelectorAll('.prj-item')).map(c => Number(c.dataset.item));
            try { await call({ action: 'item_move', id: id, moscow: col.dataset.moscow || null, order: order }); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); await ctx.refresh(); }
        });
        // Dialogs
        document.getElementById('piSave').addEventListener('click', saveItem);
        document.getElementById('piName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); saveItem(); } });
        document.getElementById('piDelete').addEventListener('click', async () => {
            const ok = await window.showConfirm({ title: T('scope.delete_title'), message: T('scope.delete_body'), okLabel: P.TC('delete'), okClass: 'danger' });
            if (!ok) return;
            try { await call({ action: 'item_delete', id: document.getElementById('piId').value }); P.closeModal('prjItemModal'); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); }
        });
        document.getElementById('pmKind').addEventListener('click', e => {
            const b = e.target.closest('[data-kind]'); if (!b) return;
            memberKind = b.dataset.kind;
            document.querySelectorAll('#pmKind [data-kind]').forEach(x => x.classList.toggle('active', x === b));
            fillMemberPick();
        });
        document.getElementById('pmPerson').addEventListener('input', searchPeople);
        document.getElementById('pmPerson').addEventListener('focus', searchPeople);
        document.getElementById('pmPersonResults').addEventListener('click', e => {
            const b = e.target.closest('[data-person]'); if (!b) return;
            document.getElementById('pmPersonId').value = b.dataset.person;
            document.getElementById('pmPerson').value = b.dataset.personName;
            document.getElementById('pmPersonResults').hidden = true;
        });
        document.getElementById('pmSave').addEventListener('click', saveMember);
    }

    window.PrjTools = {
        render(c) {
            ctx = c;
            wire();
            const tools = c.data.project.tools || [];
            if (tools.includes('people')) renderPeople();
            if (tools.includes('scope')) renderScope();
            if (tools.includes('raci')) renderRaci();
        },
    };
})();
