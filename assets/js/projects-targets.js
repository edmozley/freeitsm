/**
 * Projects - asset targets on a project's Overview (3.2.0): live progress
 * measured from Assets ("12 of 44 Latitude 5430s retired"), each with a
 * burn-up line, plus the dialog that sets one up and the "what's left" list.
 *
 * projects-view.js draws the Overview with an empty #pvTargets and then calls
 * PrjTargets.render({data, projectId, refresh}). Every rule - the whitelisted
 * fields, the company, Assets access - is server-side in
 * includes/projects/targets.php; this file only draws and asks.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T, esc = P.esc;
    let ctx = null, opts = null, wired = false, previewTimer = null, listTarget = null, listShow = 'left';

    const canChange = () => !(ctx.data.permissions && !ctx.data.permissions.can_change);
    const canAssets = () => !!ctx.data.can_assets;
    const get = (q) => P.api('tools.php?project_id=' + ctx.projectId + '&' + q);
    const call = (body) => P.api('tools.php', Object.assign({ project_id: ctx.projectId }, body));
    const $ = (id) => document.getElementById(id);
    const PENCIL = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';

    // ---- Words for a rule -------------------------------------------------------
    function scopePhrase(t) {
        if (t.scope === 'linked') return T('targets.scope_linked');
        const bits = [];
        if (t.scope_type_name) bits.push(t.scope_type_name);
        if (t.scope_field) bits.push(T('targets.sfield.' + t.scope_field) + ' ' + T('targets.op.contains') + ' "' + t.scope_value + '"');
        return bits.join(' · ');
    }
    function rulePhrase(t) {
        const v = t.done_value_name !== undefined ? t.done_value_name : t.done_value;
        return T('targets.field.' + t.done_field) + ' ' + T('targets.op.' + t.done_op) + ' ' + (v === '' || v === null ? T('targets.blank') : v);
    }

    // ---- The burn-up line ------------------------------------------------------
    function spark(t) {
        const pts = t.points || [];
        if (pts.length < 2) return '<div class="prj-tg-spark empty">' + esc(T('targets.line_soon')) + '</div>';
        const w = 240, h = 44, n = pts.length;
        const xy = pts.map((p, i) => [(i * w / (n - 1)).toFixed(1), (h - 3 - (p.total ? p.done / p.total : 0) * (h - 6)).toFixed(1)]);
        const line = xy.map(p => p.join(',')).join(' ');
        const id = 'tg' + t.id;
        return '<svg class="prj-tg-spark" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" aria-hidden="true">'
            + '<defs><linearGradient id="' + id + '" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="currentColor" stop-opacity=".35"/><stop offset="1" stop-color="currentColor" stop-opacity="0"/></linearGradient></defs>'
            + '<polygon fill="url(#' + id + ')" points="0,' + h + ' ' + line + ' ' + w + ',' + h + '"/>'
            + '<polyline fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="' + line + '"/></svg>';
    }

    function card(t) {
        const finished = t.total > 0 && t.done >= t.total;
        const today = P.todayStr();
        const when = finished ? T('targets.complete')
            : !t.due ? '' : (t.due < today ? T('targets.late', { date: P.fmtDate(t.due) }) : T('targets.by', { date: P.fmtDate(t.due) }));
        return '<div class="prj-tg' + (t.health ? ' h-' + esc(t.health) : '') + '" data-tg="' + t.id + '">'
            + '<div class="prj-tg-head"><span class="prj-tg-name">' + esc(t.name) + '</span>'
            + (t.health ? '<span class="prj-tg-dot" title="' + esc(T('health.' + t.health)) + '"></span>' : '')
            + (canChange() && canAssets() ? '<button type="button" class="prj-tg-edit" data-tg-edit="' + t.id + '" title="' + esc(P.TC('edit')) + '" aria-label="' + esc(P.TC('edit')) + '">' + PENCIL + '</button>' : '')
            + '</div>'
            + '<div class="prj-tg-big"><strong>' + t.done.toLocaleString() + '</strong><span>' + esc(T('targets.of', { total: t.total.toLocaleString() })) + '</span><em>' + t.pct + '%</em></div>'
            + '<div class="prj-tg-rule">' + esc(rulePhrase(t)) + '</div>'
            + '<span class="prj-bar"><span style="width:' + t.pct + '%;background:' + P.gradient(ctx.data.project.colour) + '"></span></span>'
            + spark(t)
            + '<div class="prj-tg-foot"><span class="prj-tg-scope" title="' + esc(scopePhrase(t)) + '">' + esc(scopePhrase(t)) + '</span>'
            + (when ? '<span class="prj-tg-when' + (t.health === 'red' ? ' late' : '') + '">' + esc(when) + '</span>' : '') + '</div>'
            + (canAssets() ? '<button type="button" class="prj-link prj-tg-left" data-tg-list="' + t.id + '">' + esc(finished ? T('targets.show_done') : T('targets.show_left', { n: (t.total - t.done).toLocaleString() })) + '</button>' : '')
            + '</div>';
    }

    function render() {
        const box = $('pvTargets');
        if (!box) return;
        const list = ctx.data.targets || [];
        const mayAdd = canChange() && canAssets();
        if (!list.length && !mayAdd) { box.hidden = true; box.innerHTML = ''; return; }
        box.hidden = false;
        let html = '<div class="prj-panel prj-tg-panel"><h3>' + esc(T('targets.title'))
            + (mayAdd ? ' <button type="button" class="prj-link" data-tg-add>+ ' + esc(T('targets.add')) + '</button>' : '') + '</h3>';
        if (!list.length) {
            html += '<button type="button" class="prj-tg-empty" data-tg-add>' + P.icon('laptop', 22) + '<span><strong>' + esc(T('targets.empty_title')) + '</strong>' + esc(T('targets.empty_body')) + '</span></button>';
        } else {
            html += '<div class="prj-tg-grid" style="--tg-c:' + esc(P.gradient(ctx.data.project.colour).match(/#[0-9a-f]{3,8}/i)[0]) + '">' + list.map(card).join('') + '</div>';
        }
        box.innerHTML = html + '</div>';
    }

    // ---- The dialog ------------------------------------------------------------
    async function options() {
        if (!opts) opts = (await get('target_options=1')).options;
        return opts;
    }
    function fillOps(field, sel) {
        const f = opts.fields[field];
        $('ptOp').innerHTML = f.ops.map(o => '<option value="' + o + '">' + esc(T('targets.op.' + o)) + '</option>').join('');
        $('ptOp').value = sel && f.ops.includes(sel) ? sel : f.ops[0];
        const list = field === 'status' ? opts.statuses : field === 'location' ? opts.locations : null;
        $('ptValueList').hidden = !list; $('ptValue').hidden = !!list;
        if (list) $('ptValueList').innerHTML = list.map(o => '<option value="' + o.id + '">' + esc(o.name) + '</option>').join('');
    }
    function setScope(s) {
        $('ptScope').dataset.scope = s;
        document.querySelectorAll('#ptScope [data-scope]').forEach(b => b.classList.toggle('active', b.dataset.scope === s));
        $('ptFilter').hidden = s !== 'filter';
        $('ptLinkedNote').hidden = s !== 'linked';
        preview();
    }
    function rule() {
        const field = $('ptField').value;
        const isList = field === 'status' || field === 'location';
        return {
            name: $('ptName').value, scope: $('ptScope').dataset.scope,
            scope_type_id: $('ptType').value || null, scope_field: $('ptSField').value || null, scope_value: $('ptSValue').value,
            done_field: field, done_op: $('ptOp').value, done_value: isList ? $('ptValueList').value : $('ptValue').value,
            target_date: $('ptDue').value || null,
        };
    }
    function preview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(async () => {
            const r = rule(); const out = $('ptPreview');
            const q = Object.keys(r).filter(k => r[k] !== null).map(k => encodeURIComponent(k) + '=' + encodeURIComponent(r[k])).join('&');
            try {
                const d = await get('target_preview=1&' + q + (r.name ? '' : '&name=x'));
                out.className = 'prj-tg-preview' + (d.total ? '' : ' none');
                out.textContent = d.total ? T('targets.preview', { total: d.total.toLocaleString(), done: d.done.toLocaleString() }) : T('targets.preview_none');
            } catch (e) { out.className = 'prj-tg-preview none'; out.textContent = e.message; }
        }, 250);
    }
    async function open(t) {
        try { await options(); } catch (e) { P.toast(e.message, 'error'); return; }
        $('ptTitle').textContent = t ? T('targets.edit') : T('targets.new');
        $('ptId').value = t ? t.id : '';
        $('ptName').value = t ? t.name : '';
        $('ptType').innerHTML = '<option value="">' + esc(T('targets.any_type')) + '</option>' + opts.types.map(o => '<option value="' + o.id + '">' + esc(o.name) + '</option>').join('');
        $('ptType').value = t && t.scope_type_id ? t.scope_type_id : '';
        $('ptSField').innerHTML = '<option value="">' + esc(T('targets.no_field')) + '</option>' + opts.scope_fields.map(k => '<option value="' + k + '">' + esc(T('targets.sfield.' + k)) + '</option>').join('');
        $('ptSField').value = t && t.scope_field ? t.scope_field : 'model';
        $('ptSValue').value = t && t.scope_value ? t.scope_value : '';
        $('ptField').innerHTML = Object.keys(opts.fields).map(k => '<option value="' + k + '">' + esc(T('targets.field.' + k)) + '</option>').join('');
        $('ptField').value = t ? t.done_field : 'status';
        fillOps($('ptField').value, t ? t.done_op : null);
        if (t) { if (t.done_field === 'status' || t.done_field === 'location') $('ptValueList').value = t.done_value; else $('ptValue').value = t.done_value || ''; }
        else $('ptValue').value = '';
        $('ptDue').value = t && t.target_date ? t.target_date : '';
        $('ptDue').placeholder = ctx.data.project.target_end_date || '';
        $('ptDelete').hidden = !t;
        $('ptError').hidden = true;
        setScope(t ? t.scope : 'filter');
        P.openModal('prjTargetModal');
        setTimeout(() => $('ptName').focus(), 60);
    }
    async function save() {
        try {
            await call(Object.assign({ action: 'target_save', id: $('ptId').value || null }, rule()));
            P.closeModal('prjTargetModal');
            await ctx.refresh();
        } catch (e) { $('ptError').textContent = e.message; $('ptError').hidden = false; }
    }

    // ---- What's left -----------------------------------------------------------
    async function openList(t, show) {
        listTarget = t; listShow = show || (t.total > 0 && t.done >= t.total ? 'done' : 'left');
        $('ptlTitle').textContent = t.name;
        $('ptlSub').textContent = scopePhrase(t) + ' - ' + rulePhrase(t);
        document.querySelectorAll('#ptlSeg [data-show]').forEach(b => {
            b.classList.toggle('active', b.dataset.show === listShow);
            b.textContent = b.dataset.show === 'left' ? T('targets.left_n', { n: (t.total - t.done).toLocaleString() }) : T('targets.done_n', { n: t.done.toLocaleString() });
        });
        const body = $('ptlBody');
        body.innerHTML = '<p class="prj-muted">' + esc(P.TC('loading')) + '</p>';
        P.openModal('prjTargetListModal');
        try {
            const d = await get('target_assets=' + t.id + '&show=' + listShow);
            const rows = d.assets || [];
            if (!rows.length) { body.innerHTML = '<p class="prj-muted">' + esc(T(listShow === 'left' ? 'targets.list_empty_left' : 'targets.list_empty_done')) + '</p>'; return; }
            body.innerHTML = '<div class="prj-tg-table-wrap"><table class="prj-tg-table"><thead><tr><th>' + esc(T('targets.col_asset')) + '</th><th>' + esc(T('targets.col_model')) + '</th><th>' + esc(T('targets.col_os')) + '</th><th>' + esc(T('targets.col_status')) + '</th><th>' + esc(T('targets.col_location')) + '</th></tr></thead><tbody>'
                + rows.map(r => '<tr><td data-label="' + esc(T('targets.col_asset')) + '"><a href="' + esc(window.PRJ_BASE + r.url) + '">' + esc(r.hostname || r.asset_tag || ('#' + r.id)) + '</a></td>'
                    + '<td data-label="' + esc(T('targets.col_model')) + '">' + esc(r.model || '') + '</td>'
                    + '<td data-label="' + esc(T('targets.col_os')) + '">' + esc((r.operating_system || '') + (r.feature_release ? ' ' + r.feature_release : '')) + '</td>'
                    + '<td data-label="' + esc(T('targets.col_status')) + '">' + esc(r.status_name || '') + '</td>'
                    + '<td data-label="' + esc(T('targets.col_location')) + '">' + esc(r.location_name || '') + '</td></tr>').join('')
                + '</tbody></table></div>' + (rows.length >= 200 ? '<p class="prj-muted">' + esc(T('targets.cap', { n: (listShow === 'left' ? t.total - t.done : t.done).toLocaleString() })) + '</p>' : '');
        } catch (e) { body.innerHTML = '<p class="prj-form-error">' + esc(e.message) + '</p>'; }
    }

    function find(id) { return (ctx.data.targets || []).find(t => String(t.id) === String(id)); }

    function wire() {
        if (wired) return;
        wired = true;
        document.addEventListener('click', e => {
            if (e.target.closest('[data-tg-add]')) { e.preventDefault(); open(null); return; }
            const ed = e.target.closest('[data-tg-edit]'); if (ed) { open(find(ed.dataset.tgEdit)); return; }
            const ls = e.target.closest('[data-tg-list]'); if (ls) { openList(find(ls.dataset.tgList)); return; }
        });
        $('ptScope').addEventListener('click', e => { const b = e.target.closest('[data-scope]'); if (b) setScope(b.dataset.scope); });
        $('ptField').addEventListener('change', () => { fillOps($('ptField').value, null); preview(); });
        ['ptType', 'ptSField', 'ptOp', 'ptValueList'].forEach(id => $(id).addEventListener('change', preview));
        ['ptSValue', 'ptValue'].forEach(id => $(id).addEventListener('input', preview));
        $('ptSave').addEventListener('click', save);
        $('ptName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); save(); } });
        $('ptDelete').addEventListener('click', async () => {
            const ok = await window.showConfirm({ title: T('targets.delete_title'), message: T('targets.delete_body'), okLabel: P.TC('delete'), okClass: 'danger' });
            if (!ok) return;
            try { await call({ action: 'target_delete', id: $('ptId').value }); P.closeModal('prjTargetModal'); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); }
        });
        $('ptlSeg').addEventListener('click', e => { const b = e.target.closest('[data-show]'); if (b && listTarget) openList(listTarget, b.dataset.show); });
    }

    window.PrjTargets = {
        render(c) { ctx = c; wire(); render(); },
    };
})();
