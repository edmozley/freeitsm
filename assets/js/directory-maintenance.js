/**
 * Directory maintenance - the three lists of issue #261, on System -> Imported people
 * (system/directory-maintenance/), for every kind of source (LDAP, CardDAV, Entra ID).
 *
 *   DirectoryMaintenance.mount(el, { kind: 'orphans' })
 *   DirectoryMaintenance.mount(el, { kind: 'stale',    provider: 7 })
 *   DirectoryMaintenance.mount(el, { kind: 'imported', provider: 7 })
 *
 * And the "what if" of #261 - everything a write would do, changing nothing,
 * shown in a dialog that can save it as a PDF (assets/js/pdf-writer.js):
 *
 *   await DirectoryMaintenance.previewDelete({ provider: 7, name: 'Contoso' })
 *       -> { markLeft } when Delete is pressed, null when not: on System ->
 *          Authentication it is the delete question itself
 *   DirectoryMaintenance.previewRemove({ provider: 7, run: 0, ids: [1, 2] })
 *       -> what Remove imported people would do to the ones picked (or everybody)
 *
 * Draws the list, lets people be picked, and sends what to do with them to
 * api/system/directory_maintenance.php. It decides nothing: which list may do
 * what, who is in it right now and who is skipped are the server's
 * (includes/services/directory_maintenance.php), and the page shows its answer.
 *
 * Needs i18n.js, toast.js and confirm.js, which every System page loads.
 */
(function () {
    'use strict';

    // From where this script was loaded, so it works at any depth and under any base URL.
    const ROOT = (document.currentScript ? document.currentScript.src : '').replace(/assets\/js\/directory-maintenance\.js.*$/, '');
    const API = ROOT + 'api/system/directory_maintenance.php';
    const DAYS = [30, 90, 180, 365];
    const ACTIONS = { orphans: ['keep', 'left', 'delete'], stale: ['keep', 'left', 'delete'], imported: ['remove'] };

    const T = (k, p) => window.t('system.sso.maint_' + k, p);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (text, type) => { if (typeof window.showToast === 'function') window.showToast(text, type); };

    // Its own few rules, once per page, the way confirm.js brings its own.
    function style() {
        if (document.getElementById('dmStyle')) return;
        const s = document.createElement('style');
        s.id = 'dmStyle';
        s.textContent = '.dm-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0;}'
            + '.dm-bar select{padding:5px 8px;}'
            + '.dm-table{width:100%;border-collapse:collapse;font-size:12.5px;}'
            + '.dm-table th{text-align:left;padding:7px 9px;color:var(--text-dim,#888);font-weight:600;border-bottom:1px solid var(--border-soft,#eee);white-space:nowrap;}'
            + '.dm-table td{padding:7px 9px;border-bottom:1px solid var(--border-soft,#f4f4f4);color:var(--text,#444);}'
            + '.dm-dim{color:var(--text-dim,#888);font-size:12.5px;}'
            + '.dm-warn{padding:10px 12px;border-radius:6px;background:var(--warning-bg,#fff7e6);color:var(--warning-text,#92400e);margin:8px 0;font-size:13px;}'
            + '.dm-skipped{margin-top:10px;font-size:12.5px;}.dm-skipped li{margin:2px 0;}'
            + '.btn.dm-danger{color:var(--danger,#c62828);border-color:currentColor;}.btn[disabled]{opacity:.5;cursor:not-allowed;}'
            // The "what if" dialog.
            + '.dm-pv{width:min(860px,calc(100vw - 32px));max-height:calc(100vh - 48px);margin:auto;padding:0;border:1px solid var(--border,#ddd);border-radius:10px;background:var(--surface,#fff);color:var(--text,#333);box-shadow:0 16px 40px rgba(0,0,0,.2);}'
            + '.dm-pv::backdrop{background:rgba(0,0,0,.35);}'
            + '.dm-pv-in{display:flex;flex-direction:column;max-height:calc(100vh - 48px);}'
            + '.dm-pv-head{padding:16px 20px 8px;}.dm-pv-head h3{margin:0 0 4px;font-size:17px;}'
            + '.dm-pv-body{padding:4px 20px 12px;overflow:auto;}'
            + '.dm-pv-body h4{margin:16px 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-dim,#888);}'
            + '.dm-pv-body p{margin:4px 0;font-size:13px;line-height:1.5;}'
            + '.dm-pv-choice{display:flex;gap:16px;flex-wrap:wrap;margin:8px 0 4px;font-size:13px;}'
            + '.dm-pv-foot{display:flex;gap:8px;justify-content:flex-end;align-items:center;padding:12px 20px;border-top:1px solid var(--border-soft,#eee);}'
            + '.dm-pv-foot .dm-dim{margin-right:auto;}';
        document.head.appendChild(s);
    }

    async function getJson(qs) {
        const r = await fetch(API + '?' + qs, { credentials: 'same-origin' });
        return r.json();
    }

    function mount(el, opts) {
        style();
        // Mounted again on the same element (another source picked): the old
        // handlers go, or every click would act twice.
        if (el._dmAbort) el._dmAbort.abort();
        const ac = new AbortController();
        el._dmAbort = ac;
        const kind = opts.kind, provider = opts.provider || 0;
        const state = { days: 90, run: 0, last: null };

        el.innerHTML = '<div class="dm-bar" data-dm="bar"></div><div data-dm="note"></div>'
            + '<div style="overflow-x:auto;" data-dm="list"></div><div data-dm="actions" class="dm-bar"></div><div data-dm="skipped"></div>';
        const part = n => el.querySelector('[data-dm="' + n + '"]');

        // The choices above the list: how long, for stale; which import, for imported.
        async function drawBar() {
            if (kind === 'stale') {
                part('bar').innerHTML = '<label>' + esc(T('days_label')) + ' <select data-dm="days">'
                    + DAYS.map(d => '<option value="' + d + '"' + (d === state.days ? ' selected' : '') + '>' + esc(T('days_n', { n: d })) + '</option>').join('')
                    + '</select></label>';
            } else if (kind === 'imported') {
                const d = await getJson('action=runs&provider=' + provider);
                const runs = d.success ? d.runs : [];
                part('bar').innerHTML = '<label>' + esc(T('run_label')) + ' <select data-dm="run"><option value="0">' + esc(T('run_all')) + '</option>'
                    + runs.map(r => '<option value="' + r.id + '">' + esc(T('run_option', { date: window.fmtDateTime ? window.fmtDateTime(r.started) : r.started, created: r.created, remaining: r.remaining })) + '</option>').join('')
                    + '</select></label>';
            }
        }

        async function load() {
            part('list').innerHTML = '<p class="dm-dim">' + esc(T('loading')) + '</p>';
            part('note').innerHTML = '';
            let qs = 'action=' + kind;
            if (kind !== 'orphans') qs += '&provider=' + provider;
            if (kind === 'stale') qs += '&days=' + state.days;
            if (kind === 'imported' && state.run) qs += '&run=' + state.run;
            const d = await getJson(qs);
            if (!d.success) { part('list').innerHTML = '<p class="dm-dim">' + esc(d.error) + '</p>'; part('actions').innerHTML = ''; return; }
            state.last = d;

            // Only with somebody to remove: with nobody, there is nothing to warn about.
            if (kind === 'imported' && d.source_imports && d.people.length) part('note').innerHTML = '<div class="dm-warn">' + esc(T('source_imports')) + '</div>';
            if (!d.people.length) {
                part('list').innerHTML = '<p class="dm-dim">' + esc(T('none_' + kind)) + '</p>';
                part('actions').innerHTML = '';
                return;
            }
            part('list').innerHTML = '<table class="dm-table"><thead><tr>'
                + '<th><input type="checkbox" data-dm="all" aria-label="' + esc(T('pick_all')) + '"></th>'
                + '<th>' + esc(T('col_name')) + '</th><th>' + esc(T('col_status')) + '</th>'
                + (kind === 'stale' ? '<th>' + esc(T('col_last_seen')) + '</th>' : '')
                + '<th>' + esc(T('col_tickets')) + '</th><th>' + esc(T('col_assets')) + '</th></tr></thead><tbody>'
                + d.people.map(p => '<tr><td><input type="checkbox" data-dm="pick" value="' + p.id + '"></td>'
                    + '<td>' + esc(p.name) + (p.email && p.email !== p.name ? ' <span class="dm-dim">' + esc(p.email) + '</span>' : '') + '</td>'
                    + '<td>' + esc(T(p.is_active ? 'status_current' : 'status_left')) + '</td>'
                    + (kind === 'stale' ? '<td>' + esc(p.last_seen ? (window.fmtDateTime ? window.fmtDateTime(p.last_seen) : p.last_seen) : T('never')) + '</td>' : '')
                    + '<td>' + p.tickets + '</td><td>' + p.assets + '</td></tr>').join('')
                + '</tbody></table>'
                + (d.total > d.people.length ? '<p class="dm-dim">' + esc(T('more', { n: d.people.length, total: d.total })) + '</p>' : '');
            part('actions').innerHTML = (kind === 'imported' ? '<button type="button" class="btn btn-test" data-dm-preview>' + esc(T('pv_button')) + '</button>' : '')
                + ACTIONS[kind].map(a =>
                '<button type="button" class="btn btn-test' + (a === 'delete' || a === 'remove' ? ' dm-danger' : '') + '" data-dm-do="' + a + '"'
                + (a === 'remove' && d.source_imports ? ' disabled' : '') + '>' + esc(T('do_' + a)) + '</button>').join('')
                + '<span class="dm-dim">' + esc(T('do_hint_' + kind)) + '</span>';
        }

        async function apply(what) {
            const ids = Array.from(el.querySelectorAll('[data-dm="pick"]:checked')).map(c => parseInt(c.value, 10));
            if (!ids.length) { toast(T('pick_first'), 'error'); return; }
            const ok = await showConfirm({
                title: T('confirm_' + what + '_title'),
                message: T('confirm_' + what, { n: ids.length }),
                okLabel: T('do_' + what),
                okClass: what === 'delete' || what === 'remove' ? 'danger' : 'primary'
            });
            if (!ok) return;
            const r = await fetch(API, {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'apply', list: kind, do: what, ids, provider, days: state.days, run: state.run })
            });
            const d = await r.json();
            if (!d.success) { toast(d.error, 'error'); return; }
            toast(T('result', { done: d.done.length, skipped: d.skipped.length }), d.skipped.length ? 'warning' : 'success');
            part('skipped').innerHTML = d.skipped.length
                ? '<div class="dm-skipped"><strong>' + esc(T('skipped_heading')) + '</strong><ul>'
                    + d.skipped.map(s => '<li>' + esc(s.name || ('#' + s.id)) + ': ' + esc(s.reason) + '</li>').join('') + '</ul></div>'
                : '';
            if (kind === 'imported') await drawBar();
            const run = el.querySelector('[data-dm="run"]'); if (run) run.value = String(state.run);
            load();
        }

        el.addEventListener('change', e => {
            if (e.target.matches('[data-dm="all"]')) el.querySelectorAll('[data-dm="pick"]').forEach(c => { c.checked = e.target.checked; });
            if (e.target.matches('[data-dm="days"]')) { state.days = parseInt(e.target.value, 10); part('skipped').innerHTML = ''; load(); }
            if (e.target.matches('[data-dm="run"]')) { state.run = parseInt(e.target.value, 10); part('skipped').innerHTML = ''; load(); }
        }, { signal: ac.signal });
        el.addEventListener('click', e => {
            // What Remove would do: to the ones picked, or to everybody listed when nobody is.
            if (e.target.closest('[data-dm-preview]')) {
                previewRemove({ provider, run: state.run, ids: Array.from(el.querySelectorAll('[data-dm="pick"]:checked')).map(c => parseInt(c.value, 10)) });
                return;
            }
            const b = e.target.closest('[data-dm-do]');
            if (b && !b.disabled) apply(b.dataset.dmDo);
        }, { signal: ac.signal });

        drawBar().then(load);
        return { reload: load };
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  What if (#261)
    // ─────────────────────────────────────────────────────────────────────────

    const when = () => (window.fmtDateTime ? window.fmtDateTime(new Date().toISOString()) : new Date().toLocaleString());

    /**
     * The report as plain data - a title, lines, and tables - so the dialog and the
     * PDF draw the same thing and cannot say different things.
     */
    function deleteModel(pv, by) {
        const out = { title: T('pv_delete_title', { name: pv.source.name }), lines: [T('pv_prepared', { who: by, when: when() }), T('pv_nothing_changed')], parts: [] };
        if (pv.people_total) {
            const fields = pv.unlocks.map(f => T('field_' + f)).join(', ');
            out.parts.push({
                heading: T('pv_people_heading', { n: pv.people_total }),
                lines: [T(pv.choice === 'left' ? 'pv_people_left' : 'pv_people_keep'), fields ? T('pv_unlocks', { fields }) : T('pv_unlocks_none')]
                    .concat(pv.people_total > pv.people.length ? [T('more', { n: pv.people.length, total: pv.people_total })] : []),
                head: [T('col_name'), T('pv_col_email'), T('pv_col_company'), T('pv_col_outcome')],
                rows: pv.people.map(p => [p.name, p.email || '', p.company || '', T('pv_out_' + p.outcome)])
            });
        } else {
            out.parts.push({ heading: T('pv_people_heading', { n: 0 }), lines: [T('pv_people_none')] });
        }
        if (pv.unlinked) out.parts[0].lines.push(T('pv_unlinked', { n: pv.unlinked }));
        out.parts.push(pv.records.length ? {
            heading: T('pv_records_heading'),
            head: [T('pv_col_what'), T('pv_col_count'), T('pv_col_outcome'), T('pv_col_details')],
            rows: pv.records.map(r => [T('pv_rec_' + r.key), String(r.count), T('pv_rec_out_' + r.outcome), r.items.join(', ') + (r.count > r.items.length && r.items.length ? ', ...' : '')])
        } : { heading: T('pv_records_heading'), lines: [T('pv_records_none')] });
        out.parts.push({ heading: T('pv_schedule_heading'), lines: [
            !pv.schedule.imports ? T('pv_schedule_not') : (pv.schedule.others ? T('pv_schedule_others', { n: pv.schedule.others }) : T('pv_schedule_last'))
        ] });
        return out;
    }

    function removeModel(pv, by) {
        const removed = pv.people.filter(p => p.outcome === 'removed').length;
        return {
            title: T('pv_remove_title', { name: pv.source.name }),
            lines: [T('pv_prepared', { who: by, when: when() }), T('pv_nothing_changed')]
                .concat(pv.source_imports ? [T('source_imports')] : []),
            parts: [{
                heading: T('pv_remove_heading', { removed, skipped: pv.people.length - removed }),
                lines: [T('pv_remove_goes')].concat(pv.total > pv.people.length ? [T('more', { n: pv.people.length, total: pv.total })] : []),
                head: [T('col_name'), T('pv_col_email'), T('pv_col_company'), T('pv_col_outcome'), T('pv_col_signins'), T('pv_col_groups'), T('pv_col_reports')],
                rows: pv.people.map(p => [p.name, p.email || '', p.company || '',
                    p.outcome === 'removed' ? T('pv_out_removed') : T('pv_out_skipped', { reason: p.reason }),
                    String(p.signins), String(p.groups), String(p.reports)])
            }]
        };
    }

    function modelHtml(m) {
        return m.parts.map(part => '<h4>' + esc(part.heading) + '</h4>'
            + (part.lines || []).map(l => '<p>' + esc(l) + '</p>').join('')
            + (part.rows ? '<div style="overflow-x:auto;"><table class="dm-table"><thead><tr>' + part.head.map(h => '<th>' + esc(h) + '</th>').join('') + '</tr></thead><tbody>'
                + part.rows.map(r => '<tr>' + r.map(c => '<td>' + esc(c) + '</td>').join('') + '</tr>').join('') + '</tbody></table></div>' : '')
        ).join('');
    }

    // pdf-writer.js is loaded the first time a PDF is asked for: most visits never do.
    function pdfWriter() {
        if (window.FitsmPdf) return Promise.resolve(window.FitsmPdf);
        return new Promise((ok, fail) => {
            const s = document.createElement('script');
            s.src = ROOT + 'assets/js/pdf-writer.js?v=2';
            s.onload = () => ok(window.FitsmPdf);
            s.onerror = fail;
            document.head.appendChild(s);
        });
    }

    async function savePdf(m, fileName) {
        const all = [m.title].concat(m.lines, ...m.parts.map(p => [p.heading].concat(p.lines || [], p.head || [], ...(p.rows || [])))).join(' ');
        const doc = await (await pdfWriter()).create({ unit: 'pt', format: 'a4' }, all);
        doc.setProperties({ title: m.title });
        const left = 40, width = doc.internal.pageSize.getWidth() - 80, bottom = doc.internal.pageSize.getHeight() - 50;
        let y = 54;
        const para = (text, size, style) => {
            doc.setFont(undefined, style || 'normal').setFontSize(size);
            doc.splitTextToSize(text, width).forEach(line => {
                if (y > bottom) { doc.addPage(); y = 50; }
                doc.text(line, left, y); y += size * 1.35;
            });
        };
        para(m.title, 16, 'bold'); y += 4;
        m.lines.forEach(l => para(l, 10));
        m.parts.forEach(part => {
            y += 10;
            para(part.heading, 12, 'bold');
            (part.lines || []).forEach(l => para(l, 10));
            if (part.rows && part.rows.length) {
                doc.autoTable({ startY: y + 4, head: [part.head], body: part.rows, margin: { left, right: 40 },
                    styles: { fontSize: 8.5 }, headStyles: { fillColor: [84, 110, 122] }, alternateRowStyles: { fillColor: [245, 247, 248] } });
                y = doc.lastAutoTable.finalY + 8;
            }
        });
        await doc.save(fileName.replace(/[\\/:*?"<>|]+/g, '-') + '.pdf');
    }

    /**
     * Opens the dialog. load(state) fetches the preview; build(pv) makes the model;
     * opts.onDelete, when given, adds Delete (and the keep / mark-as-left choice).
     */
    function previewDialog(opts) {
        style();
        return new Promise(resolve => {
            const dlg = document.createElement('dialog');
            dlg.className = 'dm-pv';
            dlg.innerHTML = '<div class="dm-pv-in"><div class="dm-pv-head"><h3></h3><div class="dm-dim" data-pv="lines"></div>'
                + (opts.choice ? '<div class="dm-pv-choice" data-pv="choice" hidden>'
                    + '<label><input type="radio" name="dmPvChoice" value="keep" checked> ' + esc(T('pv_choice_keep')) + '</label>'
                    + '<label><input type="radio" name="dmPvChoice" value="left"> ' + esc(T('pv_choice_left')) + '</label>'
                    + '<div class="dm-dim" style="flex-basis:100%;">' + esc(T('pv_choice_hint')) + '</div></div>' : '')
                + '</div><div class="dm-pv-body" data-pv="body"><p class="dm-dim">' + esc(T('loading')) + '</p></div>'
                + '<div class="dm-pv-foot"><span class="dm-dim">' + esc(T(opts.choice ? 'pv_foot_delete' : 'pv_foot')) + '</span>'
                + '<button type="button" class="btn btn-test" data-pv="pdf" disabled>' + esc(T('pv_pdf')) + '</button>'
                + '<button type="button" class="btn btn-test" data-pv="close">' + esc(T(opts.choice ? 'pv_cancel' : 'pv_close')) + '</button>'
                + (opts.choice ? '<button type="button" class="btn btn-test dm-danger" data-pv="delete" disabled>' + esc(T('pv_delete')) + '</button>' : '')
                + '</div></div>';
            document.body.appendChild(dlg);
            const q = n => dlg.querySelector('[data-pv="' + n + '"]');
            let model = null, result = null;

            async function draw() {
                q('pdf').disabled = true; if (q('delete')) q('delete').disabled = true;
                const left = !!dlg.querySelector('input[name="dmPvChoice"][value="left"]:checked');
                let d;
                try { d = await getJson(opts.query(left)); } catch (e) { d = { success: false }; }
                if (!d.success) { q('body').innerHTML = '<p class="dm-dim">' + esc(d.error || T('pv_failed')) + '</p>'; return; }
                model = opts.build(d.preview, d.prepared_by || '');
                dlg.querySelector('h3').textContent = model.title;
                q('lines').innerHTML = model.lines.map(l => '<div>' + esc(l) + '</div>').join('');
                if (q('choice')) q('choice').hidden = !d.preview.people_total;
                q('body').innerHTML = modelHtml(model);
                q('pdf').disabled = false; if (q('delete')) q('delete').disabled = false;
            }
            dlg.addEventListener('change', e => { if (e.target.name === 'dmPvChoice') draw(); });
            dlg.addEventListener('click', async e => {
                const b = e.target.closest('[data-pv]');
                if (e.target === dlg || (b && b.dataset.pv === 'close')) { dlg.close(); return; }
                if (!b) return;
                if (b.dataset.pv === 'delete') {
                    result = { markLeft: !!dlg.querySelector('input[name="dmPvChoice"][value="left"]:checked') };
                    dlg.close();
                } else if (b.dataset.pv === 'pdf' && model) {
                    b.disabled = true;
                    try { await savePdf(model, model.title + ' - ' + new Date().toISOString().slice(0, 10)); }
                    catch (err) { console.error(err); toast(T('pv_pdf_failed'), 'error'); }
                    finally { b.disabled = false; }
                }
            });
            dlg.addEventListener('close', () => { dlg.remove(); resolve(result); });
            dlg.showModal();
            draw();
        });
    }

    /** Deleting a source: the full effect, and the question. Resolves { markLeft } or null. */
    function previewDelete(o) {
        return previewDialog({
            choice: true,
            query: left => 'action=preview_delete&provider=' + o.provider + '&people=' + (left ? 'left' : 'keep'),
            build: deleteModel
        });
    }

    /** Remove imported people: what it would do to the ones picked, or everybody. */
    function previewRemove(o) {
        return previewDialog({
            query: () => 'action=preview_remove&provider=' + o.provider + (o.run ? '&run=' + o.run : '') + (o.ids && o.ids.length ? '&ids=' + o.ids.join(',') : ''),
            build: removeModel
        });
    }

    window.DirectoryMaintenance = { mount, previewDelete, previewRemove };
})();
