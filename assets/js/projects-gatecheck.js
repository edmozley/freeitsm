/**
 * Projects - stage gate checklists (3.3.0). Rules: includes/projects/gatecheck.php.
 *
 * Fills the checklist into each gate the Gates tab draws (projects-tools.js
 * renderGates() leaves a [data-gc] box per stage and calls fill()), and puts
 * what is still open into the decide box (openBox()). Whether an item is done
 * comes from the server - a change item is done when the change is approved,
 * a document item while that document is still on the project.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = (k, p) => P.T('gatecheck.' + k, p);
    const esc = P.esc;
    const $ = id => document.getElementById(id);
    let ctx = null;
    let wired = false;
    let editing = null;     // {stageId, item|null}

    const call = body => P.api('tools.php', Object.assign({ project_id: ctx.projectId }, body));
    const canChange = () => !!(ctx.data.permissions && ctx.data.permissions.can_change);
    const gate = () => ctx.data.gate || { items: {}, documents: [], changes: [], mode: 'block', me: 0 };
    const itemsOf = stageId => gate().items[stageId] || [];
    const when = dt => dt ? (window.fmtDateTime ? window.fmtDateTime(dt) : dt) : '';

    function itemRow(i) {
        const g = gate();
        let detail = '', control = '';
        if (i.kind === 'check') {
            if (canChange()) control = '<input type="checkbox" data-gc-tick="' + i.id + '"' + (i.done ? ' checked' : '') + ' aria-label="' + esc(T('tick')) + '">';
            if (i.done) detail = T('done_by', { name: i.done_by_name || T('someone'), when: when(i.done_datetime) });
        } else if (i.kind === 'signoff') {
            detail = i.analyst_name ? T('signoff_by', { name: i.analyst_name }) : T('signoff_nobody');
            if (i.done) detail = T('signed_by', { name: i.done_by_name || i.analyst_name || T('someone'), when: when(i.done_datetime) });
            if (i.analyst_id && Number(i.analyst_id) === Number(g.me)) {
                control = '<button type="button" class="btn ' + (i.done ? 'btn-secondary' : 'btn-primary prj-btn') + ' sm" data-gc-sign="' + i.id + '" data-done="' + (i.done ? '0' : '1') + '">' + esc(T(i.done ? 'unsign' : 'sign')) + '</button>';
            }
        } else if (i.kind === 'document') {
            if (canChange()) {
                control = '<select data-gc-doc="' + i.id + '"><option value="">' + esc(g.documents.length ? T('choose_document') : T('no_documents')) + '</option>'
                    + g.documents.map(d => '<option value="' + d.id + '"' + (d.id === i.document_id ? ' selected' : '') + '>' + esc(d.title) + '</option>').join('') + '</select>';
            } else detail = i.document_title ? T('document_is', { name: i.document_title }) : T('no_document_yet');
        } else if (i.kind === 'change') {
            detail = i.change_id ? (i.change_label + (i.change_title ? ' ' + i.change_title : '') + ' - ' + T(i.done ? 'change_approved' : 'change_not_approved')) : T('no_change_yet');
            if (canChange() && g.changes.length) {
                control = '<select data-gc-change="' + i.id + '"><option value="">' + esc(T('choose_change')) + '</option>'
                    + g.changes.map(c => '<option value="' + c.id + '"' + (c.id === i.change_id ? ' selected' : '') + '>' + esc(c.label + ' ' + c.title) + '</option>').join('') + '</select>';
            }
        }
        return '<li class="prj-gc-item' + (i.done ? ' done' : '') + '">'
            + '<span class="prj-gc-mark" aria-hidden="true">' + (i.done ? '&#10003;' : '') + '</span>'
            + '<div class="prj-gc-text"><span class="prj-gc-kind">' + esc(T('kind_' + i.kind)) + '</span> '
            + (canChange() ? '<button type="button" class="prj-gc-title" data-gc-edit="' + i.id + '">' + esc(i.title) + '</button>' : '<strong>' + esc(i.title) + '</strong>')
            + '<span class="sr-only">' + esc(T(i.done ? 'is_done' : 'is_open')) + '</span>'
            + (detail ? '<div class="prj-muted sm">' + esc(detail) + '</div>' : '') + '</div>'
            + '<div class="prj-gc-control">' + control
            + (canChange() ? '<button type="button" class="prj-task-remove" data-gc-del="' + i.id + '" aria-label="' + esc(P.TC('delete')) + '">&times;</button>' : '') + '</div></li>';
    }

    /** Every gate's checklist, into the boxes renderGates() left. */
    function fill(c) {
        ctx = c;
        wire();
        document.querySelectorAll('#pvGates [data-gc]').forEach(box => {
            const sid = Number(box.dataset.gc);
            const stage = (ctx.data.stages || []).find(s => s.id === sid) || {};
            const items = itemsOf(sid);
            const done = items.filter(i => i.done).length;
            let html = '<div class="prj-gc-head"><span class="prj-gc-count' + (items.length && done < items.length ? ' open' : '') + '">'
                + esc(items.length ? T('count', { done: done, total: items.length }) : T('none')) + '</span>';
            if (stage.gate_kind === 'golive') html += '<span class="prj-gc-golive">' + esc(T('golive')) + '</span>';
            if (canChange()) {
                html += '<select data-gc-kind="' + sid + '" aria-label="' + esc(T('kind_label')) + '"><option value="standard"' + (stage.gate_kind !== 'golive' ? ' selected' : '') + '>' + esc(T('gate_standard')) + '</option>'
                    + '<option value="golive"' + (stage.gate_kind === 'golive' ? ' selected' : '') + '>' + esc(T('gate_golive')) + '</option></select>'
                    + '<button type="button" class="prj-link" data-gc-add="' + sid + '">+ ' + esc(T('add')) + '</button>';
            }
            html += '</div>';
            if (items.length) html += '<ul class="prj-gc-list">' + items.map(itemRow).join('') + '</ul>';
            box.innerHTML = html;
        });
    }

    /** What is still open at a gate, for the decide box - and whether it blocks a go. */
    function openBox(stageId) {
        const open = itemsOf(stageId).filter(i => !i.done);
        if (!open.length) return '';
        const block = gate().mode !== 'warn';
        return '<div class="prj-gate-warn"><div class="prj-gate-warn-head">' + P.icon('flag', 16) + '<strong>' + esc(T('open_title', { count: open.length })) + '</strong></div><ul>'
            + open.map(i => '<li>' + esc(T('kind_' + i.kind)) + ': ' + esc(i.title) + '</li>').join('') + '</ul><p>' + esc(T(block ? 'open_block' : 'open_warn')) + '</p></div>';
    }

    // ---- The item box -------------------------------------------------------------------------
    function openItem(stageId, item) {
        editing = { stageId: stageId, item: item };
        $('pgiTitle').textContent = item ? item.title : T('add_title');
        $('pgiKind').value = item ? item.kind : 'check';
        $('pgiKind').disabled = !!item;
        $('pgiName').value = item ? item.title : '';
        $('pgiPerson').innerHTML = '<option value="">' + esc(T('choose_person')) + '</option>'
            + (ctx.L.analysts || []).map(a => '<option value="' + a.id + '"' + (item && Number(item.analyst_id) === Number(a.id) ? ' selected' : '') + '>' + esc(a.full_name) + '</option>').join('');
        $('pgiChange').innerHTML = '<option value="">' + esc(gate().changes.length ? T('choose_change') : T('no_changes')) + '</option>'
            + gate().changes.map(c => '<option value="' + c.id + '"' + (item && item.change_id === c.id ? ' selected' : '') + '>' + esc(c.label + ' ' + c.title) + '</option>').join('');
        $('pgiNotes').value = item ? (item.notes || '') : '';
        showKind();
        $('pgiError').hidden = true;
        P.openModal('prjGateItemModal');
        setTimeout(() => $('pgiName').focus(), 60);
    }
    function showKind() {
        const k = $('pgiKind').value;
        $('pgiPersonWrap').hidden = k !== 'signoff';
        $('pgiChangeWrap').hidden = k !== 'change';
        $('pgiHint').textContent = T('hint_' + k);
    }
    async function saveItem() {
        const k = $('pgiKind').value;
        const body = { action: 'gate_item_save', id: editing.item ? editing.item.id : undefined, stage_id: editing.stageId, kind: k, title: $('pgiName').value.trim(), notes: $('pgiNotes').value };
        if (k === 'signoff') body.analyst_id = $('pgiPerson').value || null;
        if (k === 'change') body.change_id = $('pgiChange').value || null;
        try { await call(body); P.closeModal('prjGateItemModal'); P.toast(T('saved')); await ctx.refresh(); }
        catch (e) { $('pgiError').textContent = e.message; $('pgiError').hidden = false; }
    }

    function find(id) {
        for (const list of Object.values(gate().items)) { const i = list.find(x => x.id === Number(id)); if (i) return i; }
        return null;
    }
    const stageOfItem = id => { for (const [sid, list] of Object.entries(gate().items)) if (list.some(x => x.id === Number(id))) return Number(sid); return 0; };

    function wire() {
        if (wired) return;
        wired = true;
        const box = $('pvGates');
        const act = async (body, toast) => { try { await call(body); if (toast) P.toast(toast); await ctx.refresh(); } catch (e) { P.toast(e.message, 'error'); await ctx.refresh(); } };
        box.addEventListener('click', async e => {
            const add = e.target.closest('[data-gc-add]'); if (add) { openItem(Number(add.dataset.gcAdd), null); return; }
            const ed = e.target.closest('[data-gc-edit]'); if (ed) { const i = find(ed.dataset.gcEdit); openItem(stageOfItem(i.id), i); return; }
            const sg = e.target.closest('[data-gc-sign]'); if (sg) { act({ action: 'gate_item_tick', id: Number(sg.dataset.gcSign), done: sg.dataset.done === '1' }, T(sg.dataset.done === '1' ? 'signed' : 'unsigned')); return; }
            const del = e.target.closest('[data-gc-del]');
            if (del) {
                const ok = await window.showConfirm({ title: T('delete_title'), message: T('delete_body'), okLabel: P.TC('delete'), okClass: 'danger' });
                if (ok) act({ action: 'gate_item_delete', id: Number(del.dataset.gcDel) });
            }
        });
        box.addEventListener('change', e => {
            const t = e.target;
            if (t.dataset.gcTick) act({ action: 'gate_item_tick', id: Number(t.dataset.gcTick), done: t.checked });
            else if (t.dataset.gcDoc) act({ action: 'gate_item_tick', id: Number(t.dataset.gcDoc), document_id: t.value || null });
            else if (t.dataset.gcChange) act({ action: 'gate_item_save', id: Number(t.dataset.gcChange), change_id: t.value || null });
            else if (t.dataset.gcKind) {
                act({ action: 'gate_kind', stage_id: Number(t.dataset.gcKind), kind: t.value }, t.value === 'golive' ? T('golive_added') : null);
            }
        });
        $('pgiKind').addEventListener('change', showKind);
        $('pgiSave').addEventListener('click', saveItem);
    }

    window.PrjGateCheck = { fill: fill, openBox: openBox };
})();
