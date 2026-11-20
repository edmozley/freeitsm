/*
 * VMware Cloud Director servers on the asset-management settings page: list, add/edit,
 * delete, test, sync now, and the VMs a sync found. Talks to api/assets/vcloud_*.php.
 * Passwords are never read back: the form shows a placeholder and a blank field
 * keeps the stored password.
 */
(function () {
    'use strict';
    const API = '../../api/assets/';
    /** Translates a key under asset-management.vcloud, or returns the key when no translator is loaded. */
    const T = (k, p) => window.t ? window.t('asset-management.vcloud.' + k, p) : k;
    let servers = [];

    /** Fetches a JSON endpoint under api/assets/ with the session cookie and returns the parsed body. */
    async function call(path, opts) {
        const res = await fetch(API + path, Object.assign({ credentials: 'same-origin' }, opts || {}));
        return res.json();
    }

    /** Returns the coloured badge HTML for a last-sync status (ok, warning, error, or never synced). */
    function statusBadge(s) {
        const map = { ok: ['var(--success-bg)', 'var(--success-text)'], warning: ['var(--warning-bg)', 'var(--warning-text)'], error: ['var(--danger-bg)', 'var(--danger-text)'] };
        const c = map[s] || ['var(--surface-2)', 'var(--text-muted)'];
        const label = s ? T('status_' + s) : T('status_never');
        return `<span class="status-badge" style="background:${c[0]};color:${c[1]};">${escapeHtml(label)}</span>`;
    }

    /** Loads the Cloud Director servers from vcloud_connections.php and draws the settings table with each row's buttons. */
    window.vcloudLoad = async function () {
        const body = document.getElementById('vcloudList');
        if (!body) return;
        try {
            const data = await call('vcloud_connections.php');
            if (!data.success) { body.innerHTML = `<tr><td colspan="5" style="color:var(--danger-text);">${escapeHtml(data.error || '')}</td></tr>`; return; }
            servers = data.connections;
            if (!servers.length) {
                body.innerHTML = `<tr><td colspan="5" style="text-align:center;">${escapeHtml(T('none_yet'))}</td></tr>`;
                return;
            }
            body.innerHTML = servers.map(s => `
                <tr>
                    <td><strong>${escapeHtml(s.name)}</strong>${s.is_active ? '' : ` <span class="status-badge status-inactive">${escapeHtml(T('inactive'))}</span>`}</td>
                    <td><code style="font-size:var(--font-size-sm, 12px);">${escapeHtml(s.host)}</code></td>
                    <td>${s.last_sync_datetime ? escapeHtml(s.last_sync_datetime) : '—'}</td>
                    <td>${statusBadge(s.last_sync_status)}<div style="font-size:var(--font-size-sm, 12px);color:var(--text-muted, #666);margin-top:3px;">${escapeHtml(s.last_sync_message || '')}</div></td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-secondary" onclick="vcloudTest(${s.id}, this)">${escapeHtml(T('test'))}</button>
                        <button class="btn btn-secondary" onclick="vcloudSync(${s.id}, this)">${escapeHtml(T('sync_now'))}</button>
                        <button class="btn btn-secondary" onclick="vcloudShowVms(${s.id})">${escapeHtml(T('show_vms'))}</button>
                        <button class="btn btn-secondary" onclick="vcloudOpenForm(${s.id})">${escapeHtml(T('edit'))}</button>
                        <button class="btn btn-secondary" onclick="vcloudDelete(${s.id})">${escapeHtml(T('delete'))}</button>
                    </td>
                </tr>`).join('');
        } catch (e) {
            body.innerHTML = `<tr><td colspan="5" style="color:var(--danger-text);">${escapeHtml(T('load_failed'))}</td></tr>`;
        }
    };

    /**
     * Opens the add/edit modal, filled from the server with this id or with defaults for a new one; the password field is always left blank.
     */
    window.vcloudOpenForm = function (id) {
        const s = id ? servers.find(x => x.id === id) : null;
        document.getElementById('vcloudModalTitle').textContent = s ? T('edit_title') : T('add');
        document.getElementById('vcloudId').value = s ? s.id : '';
        document.getElementById('vcloudName').value = s ? s.name : '';
        document.getElementById('vcloudHost').value = s ? s.host : '';
        document.getElementById('vcloudUser').value = s ? s.username : '';
        document.getElementById('vcloudOrg').value = s ? s.org : 'System';
        document.getElementById('vcloudVersion').value = s ? s.api_version : '38.0';
        document.getElementById('vcloudPassword').value = '';
        document.getElementById('vcloudPasswordHelp').textContent = s && s.has_password ? T('password_keep') : '';
        document.getElementById('vcloudInterval').value = s ? s.sync_interval_minutes : 60;
        document.getElementById('vcloudVerify').checked = s ? s.verify_ssl : true;
        document.getElementById('vcloudActive').checked = s ? s.is_active : true;
        document.getElementById('vcloudModal').classList.add('active');
    };
    /** Closes the add/edit server modal. */
    window.vcloudCloseForm = function () {
        document.getElementById('vcloudModal').classList.remove('active');
    };

    /** Saves the modal's server through vcloud_connections.php; a blank password keeps the stored one. */
    window.vcloudSave = async function (e) {
        e.preventDefault();
        const payload = {
            action: 'save',
            id: document.getElementById('vcloudId').value || null,
            name: document.getElementById('vcloudName').value.trim(),
            host: document.getElementById('vcloudHost').value.trim(),
            username: document.getElementById('vcloudUser').value.trim(),
            org: document.getElementById('vcloudOrg').value.trim(),
            api_version: document.getElementById('vcloudVersion').value.trim() || '38.0',
            password: document.getElementById('vcloudPassword').value,
            sync_interval_minutes: parseInt(document.getElementById('vcloudInterval').value, 10) || 60,
            verify_ssl: document.getElementById('vcloudVerify').checked,
            is_active: document.getElementById('vcloudActive').checked,
        };
        const data = await call('vcloud_connections.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        if (data.success) {
            window.showToast(T('saved'), 'success');
            vcloudCloseForm();
            vcloudLoad();
        } else {
            window.showToast(data.error || T('save_failed'), 'error');
        }
    };

    /** Asks for confirmation, then deletes the server through vcloud_connections.php and reloads the table. */
    window.vcloudDelete = async function (id) {
        const s = servers.find(x => x.id === id);
        if (!confirm(T('confirm_delete', { name: s ? s.name : '' }))) return;
        const data = await call('vcloud_connections.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id }) });
        if (data.success) { window.showToast(T('removed'), 'success'); vcloudLoad(); }
        else window.showToast(data.error || T('save_failed'), 'error');
    };

    /**
     * Runs a test or a sync for one server through vcloud_sync.php, disabling its button meanwhile, toasting the result and reloading the table.
     */
    async function run(id, btn, action) {
        const original = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = `<span>${escapeHtml(T('working'))}</span>`; }
        try {
            const data = await call('vcloud_sync.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, action }) });
            if (action === 'test') {
                window.showToast(data.success ? data.message : (data.error || T('test_failed')), data.success ? 'success' : 'error');
            } else if (data.success) {
                window.showToast(data.summary.message, data.summary.status === 'warning' ? 'warning' : 'success');
            } else {
                window.showToast(data.error || T('sync_failed'), 'error');
            }
        } catch (e) {
            window.showToast(T('sync_failed'), 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = original; }
            vcloudLoad();
        }
    }
    /** Tests the connection to one server. */
    window.vcloudTest = (id, btn) => run(id, btn, 'test');
    /** Syncs one server now. */
    window.vcloudSync = (id, btn) => run(id, btn, 'sync');

    /** Shows the VMs (and edge gateways) the last sync of one server found, from vcloud_vms.php. */
    window.vcloudShowVms = async function (id) {
        const s = servers.find(x => x.id === id);
        const panel = document.getElementById('vcloudVmsPanel');
        document.getElementById('vcloudVmsTitle').textContent = T('vms_title', { name: s ? s.name : '' });
        const body = document.getElementById('vcloudVmsBody');
        body.innerHTML = `<tr><td colspan="6" style="text-align:center;">${escapeHtml(T('working'))}</td></tr>`;
        panel.style.display = '';
        const data = await call('vcloud_vms.php?connection_id=' + encodeURIComponent(id));
        if (!data.success) { body.innerHTML = `<tr><td colspan="6" style="color:var(--danger-text);">${escapeHtml(data.error || '')}</td></tr>`; return; }
        if (!data.vms.length) { body.innerHTML = `<tr><td colspan="6" style="text-align:center;">${escapeHtml(T('no_vms'))}</td></tr>`; renderEdges(data.edges); return; }
        body.innerHTML = data.vms.map(v => `
            <tr>
                <td>${escapeHtml(v.name || '')}</td>
                <td>${escapeHtml(v.vapp_name || '')}</td>
                <td>${escapeHtml(v.org_vdc || '')}</td>
                <td>${escapeHtml(v.status || '')}</td>
                <td>${escapeHtml(String(v.vcpus || 0))} vCPU · ${escapeHtml(String(v.memory_mb || 0))} MB · ${escapeHtml(String(v.disk_gb || 0))} GB</td>
                <td style="font-size:var(--font-size-sm, 12px);">${escapeHtml(v.ip_addresses || '—')}<br><span style="color:var(--text-muted, #666);">${escapeHtml(v.mac_addresses || '')}</span></td>
            </tr>`).join('');
        renderEdges(data.edges);
    };

    // Edge gateways were synced and returned, but nothing showed them (added at merge).
    function renderEdges(edges) {
        const body = document.getElementById('vcloudEdgesBody');
        if (!body) return;
        if (!Array.isArray(edges) || !edges.length) {
            body.innerHTML = `<tr><td colspan="4" style="text-align:center;">${escapeHtml(T('no_edges'))}</td></tr>`;
            return;
        }
        body.innerHTML = edges.map(e => `
            <tr>
                <td>${escapeHtml(e.name || '')}</td>
                <td>${escapeHtml(e.org_vdc || '')}</td>
                <td>${escapeHtml(e.status || '')}</td>
                <td style="font-size:var(--font-size-sm, 12px);">${escapeHtml(e.uplink_ips || '—')}</td>
            </tr>`).join('');
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (document.getElementById('vcloudList')) vcloudLoad();
    });
})();
