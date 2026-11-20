/*
 * Proxmox VE servers on the asset-management settings page: list, add/edit,
 * delete, test, sync now, and the VMs a sync found. Talks to api/assets/proxmox_*.php.
 * Passwords are never read back: the form shows a placeholder and a blank field
 * keeps the stored password.
 *
 * @wiki Proxmox-and-Cloud-Director-Developer-Guide
 */
(function () {
    'use strict';
    const API = '../../api/assets/';
    /**
     * Translates a key under asset-management.proxmox.*, or returns the key when the i18n helper is
     * not loaded.
     */
    const T = (k, p) => window.t ? window.t('asset-management.proxmox.' + k, p) : k;
    let servers = [];

    /** Fetches an api/assets/ endpoint with the session cookie and returns the parsed JSON reply. */
    async function call(path, opts) {
        const res = await fetch(API + path, Object.assign({ credentials: 'same-origin' }, opts || {}));
        return res.json();
    }

    /**
     * Returns a coloured badge for a sync status (ok, warning, error), or "never" when there has been
     * no sync.
     */
    function statusBadge(s) {
        const map = { ok: ['var(--success-bg)', 'var(--success-text)'], warning: ['var(--warning-bg)', 'var(--warning-text)'], error: ['var(--danger-bg)', 'var(--danger-text)'] };
        const c = map[s] || ['var(--surface-2)', 'var(--text-muted)'];
        const label = s ? T('status_' + s) : T('status_never');
        return `<span class="status-badge" style="background:${c[0]};color:${c[1]};">${escapeHtml(label)}</span>`;
    }

    /**
     * Loads the Proxmox servers from proxmox_connections.php and renders the settings table, with
     * Test, Sync, VMs, Edit and Delete buttons per row.
     */
    window.proxmoxLoad = async function () {
        const body = document.getElementById('proxmoxList');
        if (!body) return;
        try {
            const data = await call('proxmox_connections.php');
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
                        <button class="btn btn-secondary" onclick="proxmoxTest(${s.id}, this)">${escapeHtml(T('test'))}</button>
                        <button class="btn btn-secondary" onclick="proxmoxSync(${s.id}, this)">${escapeHtml(T('sync_now'))}</button>
                        <button class="btn btn-secondary" onclick="proxmoxShowVms(${s.id})">${escapeHtml(T('show_vms'))}</button>
                        <button class="btn btn-secondary" onclick="proxmoxOpenForm(${s.id})">${escapeHtml(T('edit'))}</button>
                        <button class="btn btn-secondary" onclick="proxmoxDelete(${s.id})">${escapeHtml(T('delete'))}</button>
                    </td>
                </tr>`).join('');
        } catch (e) {
            body.innerHTML = `<tr><td colspan="5" style="color:var(--danger-text);">${escapeHtml(T('load_failed'))}</td></tr>`;
        }
    };

    /**
     * Opens the add / edit server dialog, filled from the cached server when id is given; the password
     * field always starts blank.
     */
    window.proxmoxOpenForm = function (id) {
        const s = id ? servers.find(x => x.id === id) : null;
        document.getElementById('proxmoxModalTitle').textContent = s ? T('edit_title') : T('add');
        document.getElementById('proxmoxId').value = s ? s.id : '';
        document.getElementById('proxmoxName').value = s ? s.name : '';
        document.getElementById('proxmoxHost').value = s ? s.host : '';
        document.getElementById('proxmoxUser').value = s ? s.username : '';
        document.getElementById('proxmoxPassword').value = '';
        document.getElementById('proxmoxPasswordHelp').textContent = s && s.has_password ? T('password_keep') : '';
        document.getElementById('proxmoxInterval').value = s ? s.sync_interval_minutes : 60;
        document.getElementById('proxmoxVerify').checked = s ? s.verify_ssl : true;
        document.getElementById('proxmoxActive').checked = s ? s.is_active : true;
        document.getElementById('proxmoxModal').classList.add('active');
    };
    /** Closes the add / edit server dialog. */
    window.proxmoxCloseForm = function () {
        document.getElementById('proxmoxModal').classList.remove('active');
    };

    /**
     * Saves the server dialog to proxmox_connections.php (action save), then closes it and reloads the
     * list; a blank password keeps the stored one.
     */
    window.proxmoxSave = async function (e) {
        e.preventDefault();
        const payload = {
            action: 'save',
            id: document.getElementById('proxmoxId').value || null,
            name: document.getElementById('proxmoxName').value.trim(),
            host: document.getElementById('proxmoxHost').value.trim(),
            username: document.getElementById('proxmoxUser').value.trim(),
            password: document.getElementById('proxmoxPassword').value,
            sync_interval_minutes: parseInt(document.getElementById('proxmoxInterval').value, 10) || 60,
            verify_ssl: document.getElementById('proxmoxVerify').checked,
            is_active: document.getElementById('proxmoxActive').checked,
        };
        const data = await call('proxmox_connections.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        if (data.success) {
            window.showToast(T('saved'), 'success');
            proxmoxCloseForm();
            proxmoxLoad();
        } else {
            window.showToast(data.error || T('save_failed'), 'error');
        }
    };

    /** Asks for confirmation, then deletes a server through proxmox_connections.php and reloads the list. */
    window.proxmoxDelete = async function (id) {
        const s = servers.find(x => x.id === id);
        if (!confirm(T('confirm_delete', { name: s ? s.name : '' }))) return;
        const data = await call('proxmox_connections.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id }) });
        if (data.success) { window.showToast(T('removed'), 'success'); proxmoxLoad(); }
        else window.showToast(data.error || T('save_failed'), 'error');
    };

    /**
     * Runs a test or sync for one server through proxmox_sync.php, disabling the button meanwhile,
     * toasting the result and reloading the list.
     */
    async function run(id, btn, action) {
        const original = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = `<span>${escapeHtml(T('working'))}</span>`; }
        try {
            const data = await call('proxmox_sync.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, action }) });
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
            proxmoxLoad();
        }
    }
    /** Tests the connection to one server. */
    window.proxmoxTest = (id, btn) => run(id, btn, 'test');
    /** Syncs one server's VMs now. */
    window.proxmoxSync = (id, btn) => run(id, btn, 'sync');

    /** Shows the panel of VMs the last sync found for one server, from proxmox_vms.php. */
    window.proxmoxShowVms = async function (id) {
        const s = servers.find(x => x.id === id);
        const panel = document.getElementById('proxmoxVmsPanel');
        document.getElementById('proxmoxVmsTitle').textContent = T('vms_title', { name: s ? s.name : '' });
        const body = document.getElementById('proxmoxVmsBody');
        body.innerHTML = `<tr><td colspan="6" style="text-align:center;">${escapeHtml(T('working'))}</td></tr>`;
        panel.style.display = '';
        const data = await call('proxmox_vms.php?connection_id=' + encodeURIComponent(id));
        if (!data.success) { body.innerHTML = `<tr><td colspan="6" style="color:var(--danger-text);">${escapeHtml(data.error || '')}</td></tr>`; return; }
        if (!data.vms.length) { body.innerHTML = `<tr><td colspan="6" style="text-align:center;">${escapeHtml(T('no_vms'))}</td></tr>`; return; }
        body.innerHTML = data.vms.map(v => `
            <tr>
                <td>${escapeHtml(String(v.vmid))} <span style="color:var(--text-muted, #666);font-size:var(--font-size-sm, 12px);">${escapeHtml(v.vm_type)}</span></td>
                <td>${escapeHtml(v.name || '')}</td>
                <td>${escapeHtml(v.node_name || '')}</td>
                <td>${escapeHtml(v.status || '')}</td>
                <td>${escapeHtml(String(v.vcpus || 0))} vCPU · ${escapeHtml(String(v.memory_mb || 0))} MB · ${escapeHtml(String(v.disk_gb || 0))} GB</td>
                <td style="font-size:var(--font-size-sm, 12px);">${escapeHtml(v.ip_addresses || '—')}<br><span style="color:var(--text-muted, #666);">${escapeHtml(v.mac_addresses || '')}</span></td>
            </tr>`).join('');
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (document.getElementById('proxmoxList')) proxmoxLoad();
    });
})();
