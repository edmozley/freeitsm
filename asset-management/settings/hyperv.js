/**
 * Assets -> Settings -> Hyper-V (GH #199 Part A): the connections a read-only collector
 * reports to, their keys, and the identity changes waiting for a person.
 *
 * Every rule is server-side (api/assets/hyperv_connections.php over VirtualEstateService).
 * The key is shown once, straight after it is made, and never again.
 *
 * @wiki Hyper-V-Discovery
 */
(function () {
    'use strict';
    const API = '../../api/assets/hyperv_connections.php';
    const T = (k, p) => window.t('asset-management.hyperv.' + k, p);
    const esc = v => (v === null || v === undefined) ? '' : String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const when = s => { if (!s) return '-'; try { return window.fmtDateTime(window.parseUTCDate(s)); } catch (e) { return s; } };
    let data = { connections: [], companies: [], api_url: '' };

    async function call(body) {
        const r = await fetch(API, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
        return r.json();
    }

    async function load() {
        const d = await call();
        const list = document.getElementById('hvList');
        if (!d.success) { list.innerHTML = `<tr><td colspan="5">${esc(d.error || '')}</td></tr>`; return; }
        data = d;
        document.querySelectorAll('.hv-company-col').forEach(c => { c.style.display = d.companies.length ? '' : 'none'; });
        if (!d.connections.length) { list.innerHTML = `<tr><td colspan="5" style="color:var(--text-muted,#666);">${esc(T('intro'))}</td></tr>`; return; }
        list.innerHTML = d.connections.map(c => {
            const pill = !c.is_active ? T('off') : T(c.freshness === 'never' ? 'never' : c.freshness);
            const cls = !c.is_active ? 'muted' : (c.freshness === 'on_time' ? 'ok' : (c.freshness === 'late' ? 'warn' : 'bad'));
            const reports = `<span class="hv-pill hv-${cls}">${esc(pill)}</span>`
                + (c.last_report_at ? `<div class="form-hint">${esc(T('last_report', { when: when(c.last_report_at) }))} · ${esc(T('expected', { n: c.interval }))}</div>` : '')
                + (c.last_summary ? `<div class="form-hint">${esc(c.last_summary)}</div>` : '');
            const estate = esc(T('estate_line', c.counts))
                + (c.counts.not_confirmed ? `<div class="form-hint">${esc(T('not_confirmed', { n: c.counts.not_confirmed }))}</div>` : '')
                + (c.counts.reviews ? `<div><a href="#" class="hv-link" onclick="hvReviews(${c.id}); return false;">${esc(T('reviews', { n: c.counts.reviews }))}</a></div>` : '');
            return `<tr>
                <td><strong>${esc(c.name)}</strong>${c.key_prefix ? `<div class="form-hint"><code>${esc(c.key_prefix)}...</code></div>` : ''}</td>
                <td class="hv-company-col"${d.companies.length ? '' : ' style="display:none"'}>${esc(c.company_name || '')}</td>
                <td>${reports}</td>
                <td>${estate}</td>
                <td style="white-space:nowrap;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="hvOpenForm(${c.id})">${esc(T('edit'))}</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="hvCmdb(${c.id})">${esc(T('cmdb'))}</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="hvNewKey(${c.id})">${esc(T('new_key'))}</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="hvDelete(${c.id})">${esc(T('delete'))}</button>
                </td></tr>`;
        }).join('');
    }

    window.hvOpenForm = function (id) {
        const c = id ? data.connections.find(x => x.id === id) : null;
        document.getElementById('hvModalTitle').textContent = T(c ? 'form_edit' : 'form_add');
        document.getElementById('hvId').value = c ? c.id : '';
        document.getElementById('hvName').value = c ? c.name : '';
        document.getElementById('hvInterval').value = c ? c.interval : 15;
        document.getElementById('hvGrace').value = c ? c.grace : 15;
        document.getElementById('hvRetention').value = c && c.retention_days ? c.retention_days : '';
        document.getElementById('hvActive').checked = c ? c.is_active : true;
        const sel = document.getElementById('hvCompany');
        document.getElementById('hvCompanyRow').style.display = data.companies.length ? '' : 'none';
        sel.innerHTML = data.companies.map(co => `<option value="${co.id}">${esc(co.name)}</option>`).join('');
        if (c && c.company_id) sel.value = String(c.company_id);
        sel.disabled = !!c;   // a connection's company cannot change
        document.getElementById('hvModal').classList.add('active');
    };
    window.hvCloseForm = () => document.getElementById('hvModal').classList.remove('active');

    window.hvSave = async function (e) {
        e.preventDefault();
        const id = Number(document.getElementById('hvId').value) || 0;
        const d = await call({ action: 'save', id: id || undefined, name: document.getElementById('hvName').value.trim(),
            company_id: data.companies.length ? Number(document.getElementById('hvCompany').value) : undefined,
            interval: Number(document.getElementById('hvInterval').value), grace: Number(document.getElementById('hvGrace').value),
            retention_days: document.getElementById('hvRetention').value || null, is_active: document.getElementById('hvActive').checked });
        if (!d.success) { showToast(d.error || '', 'error'); return; }
        hvCloseForm();
        showToast(window.t('asset-management.toast.saved'), 'success');
        if (d.key) showKey(d.id, d.key);
        load();
    };

    /** The key, once, and the command to schedule - with the connection id and this install's address filled in. */
    function showKey(id, key) {
        document.getElementById('hvKey').textContent = key;
        document.getElementById('hvCommand').textContent =
            'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\\FreeITSM\\Invoke-HyperVInventory.ps1" `\n'
            + '  -ApiUrl "' + (data.api_url || location.origin) + '" -ConnectionId ' + id + ' -ApiKey "' + key + '" `\n'
            + '  -Cluster "CLUSTER1","CLUSTER2" -StandaloneHost "HV-STANDALONE1" -ReportPer Cluster';
        const p = document.getElementById('hvKeyPanel');
        p.style.display = '';
        p.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    window.hvNewKey = async function (id) {
        const c = data.connections.find(x => x.id === id);
        if (!(await showConfirm({ title: T('new_key'), message: T('new_key_confirm', { name: c ? c.name : '' }), okLabel: T('new_key'), okClass: 'danger' }))) return;
        const d = await call({ action: 'new_key', id: id });
        if (!d.success) { showToast(d.error || '', 'error'); return; }
        showKey(id, d.key);
        load();
    };

    window.hvDelete = async function (id) {
        const c = data.connections.find(x => x.id === id);
        if (!(await showConfirm({ title: T('delete'), message: T('delete_confirm', { name: c ? c.name : '' }), okLabel: T('delete'), okClass: 'danger' }))) return;
        const d = await call({ action: 'delete', id: id });
        if (!d.success) { showToast(d.error || '', 'error'); return; }
        document.getElementById('hvKeyPanel').style.display = 'none';
        document.getElementById('hvReviewPanel').style.display = 'none';
        load();
    };

    window.hvCopy = async function (elId) {
        try { await navigator.clipboard.writeText(document.getElementById(elId).textContent); showToast(T('key_copy'), 'success'); }
        catch (e) { /* the text stays selectable */ }
    };

    window.hvReviews = async function (id) {
        const c = data.connections.find(x => x.id === id);
        const r = await fetch(API + '?reviews=1&connection_id=' + id);
        const d = await r.json();
        const panel = document.getElementById('hvReviewPanel');
        document.getElementById('hvReviewTitle').textContent = T('reviews_title', { name: c ? c.name : '' });
        const list = document.getElementById('hvReviewList');
        if (!d.success) { list.textContent = d.error || ''; panel.style.display = ''; return; }
        list.innerHTML = d.reviews.length ? d.reviews.map(v => `
            <div class="hv-review" style="border:1px solid var(--border,#ddd); border-radius:8px; padding:10px 12px; margin:8px 0;">
                <strong>${esc(T(v.kind === 'host' ? 'review_host' : 'review_vm', { name: v.name || '' }))}</strong>
                <div class="form-hint">${esc(T('review_old', { when: when(v.old && v.old.last_seen_observed_at), id: v.old ? v.old.external_id : '-' }))}${v.old && v.old.host_name ? ' · ' + esc(v.old.host_name) : ''}</div>
                <div class="form-hint">${esc(T('review_new', { when: when(v.new && v.new.first_seen_at), id: v.new ? v.new.external_id : '-' }))}${v.new && v.new.host_name ? ' · ' + esc(v.new.host_name) : ''}</div>
                <div style="display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;">
                    <button type="button" class="btn btn-primary btn-sm" title="${esc(T('review_same_hint'))}" onclick="hvDecide(${v.id}, 'same', ${id})">${esc(T('review_same'))}</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="hvDecide(${v.id}, 'different', ${id})">${esc(T('review_different'))}</button>
                </div>
            </div>`).join('') : `<p>${esc(T('reviews_none'))}</p>`;
        panel.style.display = '';
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    window.hvDecide = async function (reviewId, decision, connectionId) {
        const d = await call({ action: 'decide', review_id: reviewId, decision: decision });
        showToast(d.success ? T('review_done') : (d.error || ''), d.success ? 'success' : 'error');
        hvReviews(connectionId);
        load();
    };

    // ── CMDB (GH #199 Part B) ────────────────────────────────────────────
    // Which of YOUR classes and relationship types the estate becomes, a preview of what the
    // sync would do, and the decisions it leaves to a person. Nothing is written until Save
    // with "Keep the CMDB in step" ticked.
    const CMDB_API = '../../api/assets/hyperv_cmdb.php';
    const KIND_LABEL = { cluster: 'kind_cluster', host: 'kind_host', role: 'kind_role', vm: 'kind_vm' };
    let cmdbState = null;

    async function cmdbCall(body) {
        const r = await fetch(CMDB_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        return r.json();
    }

    window.hvCmdb = async function (id) {
        const panel = document.getElementById('hvCmdbPanel');
        panel.style.display = '';
        panel.innerHTML = '<p>' + esc(window.t('common.loading')) + '</p>';
        const d = await (await fetch(CMDB_API + '?connection_id=' + id)).json();
        if (!d.success) { panel.innerHTML = `<p>${esc(d.error || '')}</p>`; return; }
        cmdbState = d;
        renderCmdb();
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    function options(list, selected, labelKey) {
        return `<option value="">${esc(T('cmdb_not_mapped'))}</option>` + list.map(x => `<option value="${x.id}"${Number(selected) === Number(x.id) ? ' selected' : ''}>${esc(x[labelKey])}${x.redundancy && x.redundancy !== 'none' ? ' (' + esc(T('cmdb_redundancy_' + x.redundancy)) + ')' : ''}</option>`).join('');
    }

    function renderCmdb() {
        const d = cmdbState, m = d.preview.mapping;
        const sel = (key, list, lbl) => `<label class="form-group" style="display:block;"><span>${esc(T('cmdb_' + key))}</span><select data-hvc="${key}">${options(list, m[key], lbl)}</select></label>`;
        let h = `<h3 style="margin-top:0;">${esc(T('cmdb_title', { name: d.connection.name }))}</h3>
            <p class="form-hint">${esc(T('cmdb_intro'))}</p>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 4px 16px;">
                ${sel('cluster_class_id', d.classes, 'name')}${sel('host_class_id', d.classes, 'name')}${sel('vm_class_id', d.classes, 'name')}${sel('role_class_id', d.classes, 'name')}
                ${sel('member_type_id', d.types, 'verb')}${sel('runs_on_ha_type_id', d.types, 'verb')}${sel('runs_on_type_id', d.types, 'verb')}${sel('role_in_type_id', d.types, 'verb')}
            </div>
            <p class="form-hint">${esc(T('cmdb_types_hint'))}</p>
            <label style="display:flex; gap:8px; align-items:center; margin:6px 0;"><input type="checkbox" data-hvc="sync_names"${m.sync_names ? ' checked' : ''}> ${esc(T('cmdb_sync_names'))}</label>
            <label style="display:flex; gap:8px; align-items:center; margin:6px 0;"><input type="checkbox" data-hvc="enabled"${m.enabled ? ' checked' : ''}> <strong>${esc(T('cmdb_enabled'))}</strong></label>
            <div style="display:flex; gap:6px; flex-wrap:wrap; margin:10px 0;">
                <button type="button" class="btn btn-primary btn-sm" onclick="hvCmdbSave()">${esc(T('cmdb_save'))}</button>
                ${d.can_suggest ? `<button type="button" class="btn btn-outline btn-sm" onclick="hvCmdbSuggest()">${esc(T('cmdb_suggest'))}</button>` : ''}
                ${m.enabled ? `<button type="button" class="btn btn-outline btn-sm" onclick="hvCmdbSync()">${esc(T('cmdb_sync_now'))}</button>` : ''}
            </div>
            ${m.last_sync_at ? `<p class="form-hint">${esc(T('cmdb_last_sync', { when: when(m.last_sync_at), summary: m.last_sync_summary || '' }))}</p>` : ''}`;
        (d.preview.warnings || []).forEach(w => {
            h += `<div class="hv-pill hv-warn" style="display:block; border-radius:6px; padding:6px 10px; margin:4px 0;">${esc(T('cmdb_warn_required', { kind: T(KIND_LABEL[w.kind]), props: w.properties.join(', ') }))}</div>`;
        });
        h += `<h4 style="margin:14px 0 6px;">${esc(T('cmdb_preview'))}</h4>`;
        const kinds = Object.keys(d.preview.kinds);
        if (!kinds.length) h += `<p class="form-hint">${esc(T('cmdb_preview_none'))}</p>`;
        kinds.forEach(k => {
            const p = d.preview.kinds[k], c = p.counts;
            h += `<div style="margin:8px 0;"><strong>${esc(T(KIND_LABEL[k]))}</strong> <span class="form-hint">${esc(T('cmdb_counts', c))}</span>`;
            p.items.match.forEach(o => {
                h += `<div class="hv-review" style="border:1px solid var(--border,#ddd); border-radius:8px; padding:8px 10px; margin:6px 0;">
                    <div>${esc(T('cmdb_match', { name: o.name }))}</div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:6px;">
                        ${o.matches.map(mm => `<button type="button" class="btn btn-primary btn-sm" onclick="hvCmdbDecide('${k}', ${o.id}, 'bind', ${mm.id})">${esc(T('cmdb_bind_to', { name: mm.name }))}</button>`).join('')}
                        <button type="button" class="btn btn-outline btn-sm" onclick="hvCmdbDecide('${k}', ${o.id}, 'create_new')">${esc(T('cmdb_create_new'))}</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="hvCmdbDecide('${k}', ${o.id}, 'leave')">${esc(T('cmdb_leave'))}</button>
                    </div></div>`;
            });
            p.items.ci_deleted.forEach(o => {
                h += `<div class="hv-review" style="border:1px solid var(--border,#ddd); border-radius:8px; padding:8px 10px; margin:6px 0;">
                    <div>${esc(T('cmdb_ci_deleted', { name: o.name }))}</div>
                    <div style="display:flex; gap:6px; margin-top:6px;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="hvCmdbDecide('${k}', ${o.id}, 'reset')">${esc(T('cmdb_create_again'))}</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="hvCmdbDecide('${k}', ${o.id}, 'leave')">${esc(T('cmdb_leave'))}</button>
                    </div></div>`;
            });
            if (p.items.waiting.length) h += `<div class="form-hint">${esc(T('cmdb_waiting', { names: p.items.waiting.map(o => o.name).join(', ') }))}</div>`;
            if (p.items.left.length) h += `<div class="form-hint">${esc(T('cmdb_left', { names: p.items.left.map(o => o.name).join(', ') }))}</div>`;
            h += '</div>';
        });
        document.getElementById('hvCmdbPanel').innerHTML = h;
    }

    function collect() {
        const out = { action: 'save', connection_id: cmdbState.connection.id };
        document.querySelectorAll('#hvCmdbPanel [data-hvc]').forEach(el => {
            out[el.dataset.hvc] = el.type === 'checkbox' ? el.checked : (el.value ? Number(el.value) : null);
        });
        return out;
    }

    window.hvCmdbSave = async function () {
        const d = await cmdbCall(collect());
        if (!d.success) { showToast(d.error || '', 'error'); return; }
        showToast(d.sync ? T('cmdb_synced', { created: d.sync.created }) : window.t('asset-management.toast.saved'), 'success');
        hvCmdb(cmdbState.connection.id);
    };

    window.hvCmdbSuggest = async function () {
        const d = await cmdbCall({ action: 'suggest' });
        if (!d.success) { showToast(d.error || '', 'error'); return; }
        // Fill the form with them; nothing is mapped until Save.
        const keep = collect();
        const fresh = await (await fetch(CMDB_API + '?connection_id=' + cmdbState.connection.id)).json();
        if (fresh.success) { cmdbState = fresh; }
        Object.assign(cmdbState.preview.mapping, d.suggested, { sync_names: keep.sync_names, enabled: keep.enabled });
        renderCmdb();
        showToast(T('cmdb_suggested'), 'success');
    };

    window.hvCmdbSync = async function () {
        const d = await cmdbCall({ action: 'sync', connection_id: cmdbState.connection.id });
        if (!d.success) { showToast(d.error || '', 'error'); return; }
        showToast(T('cmdb_synced', { created: d.sync.created }), 'success');
        hvCmdb(cmdbState.connection.id);
    };

    window.hvCmdbDecide = async function (kind, id, decision, ciId) {
        const d = await cmdbCall({ action: 'decide', connection_id: cmdbState.connection.id, kind: kind, id: id, decision: decision, ci_id: ciId });
        showToast(d.success ? T('review_done') : (d.error || ''), d.success ? 'success' : 'error');
        hvCmdb(cmdbState.connection.id);
    };

    const style = document.createElement('style');
    style.textContent = '.hv-pill{display:inline-block;padding:2px 9px;border-radius:99px;font-size:var(--font-size-sm, 12px);font-weight:600}'
        + '.hv-ok{background:var(--success-bg,#dcfce7);color:var(--success-text,#15803d)}.hv-warn{background:var(--warning-bg,#fef3c7);color:var(--warning-text,#a16207)}'
        + '.hv-link,#hyperv-tab a{color:var(--accent,#0078d4)}.hv-bad{background:var(--danger-bg,#fee2e2);color:var(--danger-text,#b91c1c)}.hv-muted{background:var(--surface-hover,#f3f4f6);color:var(--text-muted,#666)}';
    document.head.appendChild(style);

    if (document.getElementById('hyperv-tab')) load();
})();
