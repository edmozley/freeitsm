/**
 * Assets -> Settings -> Asset labels: the label designs and label fonts (GH #229).
 *
 * Lists the company's designs with a preview of each, and makes, changes, defaults
 * and deletes them through api/assets/label_designs.php; uploads and deletes fonts.
 * Slice 1 has no visual designer: a design is made from a ready-made layout, drawn
 * from the label settings further down this tab. The previews use the same drawing
 * code as printing (render.js), with a sample asset.
 *
 * @wiki Asset-Label-Designer-Design
 */
(function () {
    'use strict';
    const API = '../../api/assets/label_designs.php';
    const T = (k, p) => window.t('asset-management.label_designs.' + k, p);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let D = null, editing = null;

    async function api(body, isForm) {
        const opts = body === undefined ? {} : { method: 'POST', headers: isForm ? {} : { 'Content-Type': 'application/json' }, body: isForm ? body : JSON.stringify(body) };
        const r = await fetch(API, Object.assign({ credentials: 'same-origin' }, opts));
        const d = await r.json();
        if (!d.success) throw new Error(d.error || T('error'));
        return d;
    }
    const open = id => document.getElementById(id).classList.add('active');
    const close = () => document.querySelectorAll('#ldModal, #lfModal').forEach(m => m.classList.remove('active'));

    async function load() {
        const list = document.getElementById('ldList');
        if (!list) return;
        try { D = await api(); } catch (e) { list.innerHTML = '<div class="form-hint">' + esc(e.message) + '</div>'; return; }
        const stock = s => (D.stocks.find(x => x.key === s.key) || {}).name || T('size_custom', { w: s.w, h: s.h });
        const fontName = ref => { const m = /^font:(\d+)$/.exec(ref || ''); const f = m && D.fonts.find(x => +x.id === +m[1]); return f ? f.name : 'Noto Sans'; };
        list.innerHTML = '<table class="settings-table" style="width:100%"><thead><tr><th></th><th>' + esc(T('name')) + '</th><th>' + esc(T('template')) + '</th><th>'
            + esc(T('size')) + '</th><th>' + esc(T('font')) + '</th><th></th></tr></thead><tbody>'
            + D.designs.map(d => '<tr data-id="' + d.id + '"><td><canvas class="ld-prev" data-id="' + d.id + '"></canvas></td><td><strong>' + esc(d.name) + '</strong>'
                + (d.is_default ? ' <span class="status-badge status-active">' + esc(T('default')) + '</span>' : '') + '</td>'
                + '<td>' + esc(T('tpl_' + (d.design.template || 'standard'))) + '</td><td>' + esc(stock(d.stock)) + '</td><td>' + esc(fontName(d.design.font)) + '</td>'
                + '<td style="white-space:nowrap">' + (D.can_manage ? '<a class="btn btn-primary" href="../label-designer.php?id=' + d.id + '">' + esc(T('open_designer')) + '</a> '
                + '<button type="button" class="btn btn-secondary" data-ld-edit>' + esc(window.t('common.edit')) + '</button> '
                + (d.is_default ? '' : '<button type="button" class="btn btn-secondary" data-ld-default>' + esc(T('set_default')) + '</button> ')
                + '<button type="button" class="btn btn-secondary delete" data-ld-del>' + esc(window.t('common.delete')) + '</button>' : '') + '</td></tr>').join('')
            + '</tbody></table>';
        document.getElementById('ldNew').style.display = D.can_manage ? '' : 'none';
        document.getElementById('lfUpload').style.display = D.can_manage ? '' : 'none';
        document.getElementById('lfList').innerHTML = D.fonts.length
            ? '<table class="settings-table" style="width:100%"><tbody>' + D.fonts.map(f => '<tr data-id="' + f.id + '"><td><strong style="font-family:\'FITSM Label Font ' + f.id + '\'">' + esc(f.name) + '</strong>'
                + '<div class="form-hint">' + esc(f.original_name || '') + ' - ' + esc(f.format.toUpperCase()) + ', ' + esc(Math.round(f.size_bytes / 1024)) + ' KB</div></td>'
                + '<td style="text-align:right">' + (D.can_manage ? '<button type="button" class="btn btn-secondary delete" data-lf-del>' + esc(window.t('common.delete')) + '</button>' : '') + '</td></tr>').join('') + '</tbody></table>'
            : '<div class="form-hint">' + esc(T('fonts_none')) + '</div>';
        drawPreviews();
    }

    /** A small preview of each design, with a sample asset, from the same code that prints. */
    async function drawPreviews() {
        if (!window.LabelRender) return;
        await window.LabelRender.loadFonts(window.LD_BASE, D.designs.map(d => d.design.font).concat(D.fonts.map(f => 'font:' + f.id)));
        const L = window.LD_LOGO;
        if (L && !L.complete) await new Promise(r => { L.onload = r; L.onerror = r; });
        const sample = window.LD_SAMPLE;
        document.querySelectorAll('canvas.ld-prev').forEach(cv => {
            const d = D.designs.find(x => x.id === +cv.dataset.id);
            const items = (window.LD_ITEMS || {})[d.id + ':' + d.stock.key];
            if (!items) { cv.width = 1; cv.height = 1; return; }
            const px = Math.min(120 / d.stock.w, 50 / d.stock.h) * (window.devicePixelRatio || 1);
            cv.width = Math.round(d.stock.w * px); cv.height = Math.round(d.stock.h * px);
            cv.style.width = (cv.width / (window.devicePixelRatio || 1)) + 'px';
            cv.style.border = '1px solid var(--border, #ddd)';
            const g = cv.getContext('2d');
            g.fillStyle = '#fff'; g.fillRect(0, 0, cv.width, cv.height);
            window.LabelRender.drawLabel(g, { items, values: sample.values, url: sample.url, captions: window.LD_CAPTIONS, noTag: sample.noTag, font: d.design.font, logo: window.LD_LOGO,
                                              mono: window.LabelRender.monoFor(d.design, d.stock) }, px);
        });
    }

    function fillStock(sel, key) {
        sel.innerHTML = D.stocks.map(s => '<option value="' + esc(s.key) + '">' + esc(s.name) + '</option>').join('')
            + '<option value="custom">' + esc(T('size_own')) + '</option>';
        sel.value = key;
    }
    function stockChanged() {
        const k = document.getElementById('ldStock').value;
        const st = D.stocks.find(s => s.key === k);
        document.getElementById('ldCustomRow').style.display = k === 'custom' ? '' : 'none';
        document.getElementById('ldOffsetRow').style.display = st && st.kind === 'sheet' ? '' : 'none';
        // Black-only follows the size (tape and roll yes, sheets no) until somebody ticks it themselves.
        const mono = document.getElementById('ldMono');
        if (!mono.dataset.touched) mono.checked = !st || st.kind !== 'sheet';
    }
    function openDesign(d) {
        editing = d;
        document.getElementById('ldModalTitle').textContent = T(d ? 'edit' : 'new_title');
        document.getElementById('ldName').value = d ? d.name : '';
        document.getElementById('ldTemplateRow').style.display = d ? 'none' : '';
        document.getElementById('ldTemplate').innerHTML = D.templates.map(t => '<option value="' + t + '">' + esc(T('tpl_' + t)) + '</option>').join('');
        const tpl = document.getElementById('ldTemplate');
        tpl.value = 'tape_logo_left';
        const hint = () => { document.getElementById('ldTemplateHint').textContent = T('tpl_hint_' + tpl.value); };
        tpl.onchange = () => { hint(); if (!editing) fillStock(document.getElementById('ldStock'), tpl.value.startsWith('tape') ? 'tze24' : (tpl.value === 'sheet_large' ? '12' : '65')); stockChanged(); };
        hint();
        fillStock(document.getElementById('ldStock'), d ? d.stock.key : 'tze24');
        document.getElementById('ldW').value = d ? d.stock.w : 60;
        document.getElementById('ldH').value = d ? d.stock.h : 24;
        document.getElementById('ldOx').value = d ? (d.stock.offset_x || 0) : 0;
        document.getElementById('ldOy').value = d ? (d.stock.offset_y || 0) : 0;
        document.getElementById('ldFont').innerHTML = '<option value="noto">Noto Sans</option>' + D.fonts.map(f => '<option value="font:' + f.id + '">' + esc(f.name) + '</option>').join('');
        document.getElementById('ldFont').value = d ? (d.design.font || 'noto') : 'noto';
        document.getElementById('ldDefaultRow').style.display = d ? 'none' : '';
        document.getElementById('ldDefault').checked = false;
        // A design that has a setting keeps it; one that never had one shows what it prints with today.
        const mono = document.getElementById('ldMono');
        mono.checked = d ? window.LabelRender.monoFor(d.design, d.stock) : true;
        if (d && typeof d.design.mono === 'boolean') mono.dataset.touched = '1'; else delete mono.dataset.touched;
        stockChanged();
        open('ldModal');
        document.getElementById('ldName').focus();
    }
    async function saveDesign() {
        const stock = { key: document.getElementById('ldStock').value, w: +document.getElementById('ldW').value, h: +document.getElementById('ldH').value,
                        offset_x: +document.getElementById('ldOx').value || 0, offset_y: +document.getElementById('ldOy').value || 0 };
        // Sent only once somebody has ticked or unticked it, so an untouched design keeps following its size.
        const monoEl = document.getElementById('ldMono');
        const mono = monoEl.dataset.touched ? { mono: monoEl.checked } : {};
        const body = editing
            ? Object.assign({ action: 'update', id: editing.id, version: editing.version, name: document.getElementById('ldName').value, stock, font: document.getElementById('ldFont').value }, mono)
            : Object.assign({ action: 'create', name: document.getElementById('ldName').value, template: document.getElementById('ldTemplate').value, stock,
                font: document.getElementById('ldFont').value, default: document.getElementById('ldDefault').checked }, mono);
        try { await api(body); close(); location.reload(); } catch (e) { showToast(e.message, 'error'); }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (!document.getElementById('ldList')) return;
        load();
        document.getElementById('ldNew').addEventListener('click', () => openDesign(null));
        document.getElementById('ldStock').addEventListener('change', stockChanged);
        document.getElementById('ldMono').addEventListener('change', e => { e.target.dataset.touched = '1'; });
        document.getElementById('ldSave').addEventListener('click', saveDesign);
        document.querySelectorAll('[data-ld-close]').forEach(b => b.addEventListener('click', close));
        document.getElementById('lfUpload').addEventListener('click', () => {
            document.getElementById('lfName').value = ''; document.getElementById('lfFile').value = ''; document.getElementById('lfLicence').checked = false;
            open('lfModal');
        });
        document.getElementById('lfFile').addEventListener('change', e => {
            const f = e.target.files[0];
            if (f && !document.getElementById('lfName').value) document.getElementById('lfName').value = f.name.replace(/\.(ttf|otf|woff2?)$/i, '');
        });
        document.getElementById('lfSave').addEventListener('click', async () => {
            const fd = new FormData();
            fd.append('action', 'upload_font');
            fd.append('name', document.getElementById('lfName').value);
            fd.append('licence', document.getElementById('lfLicence').checked ? '1' : '');
            const f = document.getElementById('lfFile').files[0];
            if (f) fd.append('font', f);
            try { await api(fd, true); close(); showToast(T('font_saved'), 'success'); load(); } catch (e) { showToast(e.message, 'error'); }
        });
        document.getElementById('ldList').addEventListener('click', async e => {
            const tr = e.target.closest('tr[data-id]'); if (!tr) return;
            const d = D.designs.find(x => x.id === +tr.dataset.id);
            if (e.target.closest('[data-ld-edit]')) openDesign(d);
            if (e.target.closest('[data-ld-default]')) { try { await api({ action: 'set_default', id: d.id }); load(); } catch (err) { showToast(err.message, 'error'); } }
            if (e.target.closest('[data-ld-del]')) {
                if (!(await showConfirm({ title: T('delete'), message: T('delete_body', { name: d.name }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;
                try { await api({ action: 'delete', id: d.id }); location.reload(); } catch (err) { showToast(err.message, 'error'); }
            }
        });
        document.getElementById('lfList').addEventListener('click', async e => {
            const tr = e.target.closest('tr[data-id]'); if (!tr || !e.target.closest('[data-lf-del]')) return;
            const f = D.fonts.find(x => +x.id === +tr.dataset.id);
            if (!(await showConfirm({ title: T('font_delete'), message: T('font_delete_body', { name: f.name }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;
            try { await api({ action: 'delete_font', id: f.id }); load(); } catch (err) { showToast(err.message, 'error'); }
        });
    });
})();
