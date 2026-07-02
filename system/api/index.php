<?php
/**
 * System - API
 * Manage REST API v1 keys: create keys with granular per-resource permissions,
 * company scoping, expiry and rate limits. Links to the interactive docs page.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

$current_page = 'api';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

// The live base URL of the v1 API for this deployment.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$apiBaseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'api/v1';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - API</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .api-container { height: calc(100vh - 48px); overflow-y: auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 600; color: #333; margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: #888; margin: 0 0 30px 0; }

        .settings-card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-bottom: 24px; }
        .settings-card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 4px 0; }
        .settings-card .card-desc { font-size: 13px; color: #888; margin: 0 0 20px 0; line-height: 1.5; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none; }
        .btn-primary { background: #546e7a; color: #fff; }
        .btn-primary:hover { background: #455a64; }
        .btn-secondary { background: #eceff1; color: #455a64; }
        .btn-secondary:hover { background: #cfd8dc; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .info-note { background: #f5f7fa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 14px 16px; font-size: 12px; color: #666; line-height: 1.6; }
        .info-note strong { color: #333; }
        .base-url-box { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .base-url-box code { flex: 1; background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 8px 10px; font-size: 12px; color: #333; overflow-x: auto; white-space: nowrap; }

        .keys-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .add-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #546e7a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .add-btn:hover { background: #455a64; }
        table.keys { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.keys th { text-align: left; color: #888; font-weight: 600; font-size: 12px; padding: 8px 10px; border-bottom: 1px solid #eee; }
        table.keys td { padding: 10px; border-bottom: 1px solid #f2f2f2; color: #444; vertical-align: middle; }
        table.keys tr:last-child td { border-bottom: none; }
        .key-prefix { font-family: Consolas, Monaco, monospace; font-size: 12px; color: #888; }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-badge.on { background: #e8f5e9; color: #2e7d32; }
        .status-badge.off { background: #f0f0f0; color: #999; }
        .status-badge.expired { background: #fff3e0; color: #e65100; }
        .perm-count { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #e3f2fd; color: #1565c0; }
        .table-action-btn { background: none; border: none; cursor: pointer; color: #607d8b; padding: 4px 8px; font-size: 13px; border-radius: 4px; }
        .table-action-btn:hover { background: #eceff1; }
        .table-action-btn.danger:hover { background: #ffebee; color: #c62828; }
        .empty-row td { text-align: center; color: #aaa; padding: 24px; font-style: italic; }

        /* Modal — namespaced (apik-) so it doesn't inherit inbox.css's global
           .modal framework (opacity:0/visibility:hidden by default). */
        .apik-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        .apik-modal-overlay.open { display: flex; }
        .apik-modal { background: #fff; border-radius: 10px; width: 640px; max-width: 92vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .apik-modal-header { padding: 20px 24px; border-bottom: 1px solid #eee; font-size: 16px; font-weight: 600; color: #333; }
        .apik-modal-body { padding: 20px 24px; }
        .apik-modal-footer { padding: 16px 24px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; }
        .form-field { margin-bottom: 16px; }
        .form-field label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 4px; }
        .form-field .hint { font-size: 12px; color: #999; font-weight: 400; margin-bottom: 6px; }
        .form-field input[type=text], .form-field input[type=number], .form-field input[type=date], .form-field select {
            width: 100%; padding: 9px 11px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; font-family: inherit; box-sizing: border-box; background: #fff;
        }
        .form-field input:focus, .form-field select:focus { outline: none; border-color: #546e7a; }
        .form-2col { display: flex; gap: 14px; }
        .form-2col .form-field { flex: 1; }

        /* Permission matrix */
        .perm-matrix { border: 1px solid #eee; border-radius: 6px; overflow: hidden; }
        .perm-row { display: flex; align-items: flex-start; padding: 10px 12px; border-bottom: 1px solid #f2f2f2; gap: 12px; }
        .perm-row:last-child { border-bottom: none; }
        .perm-row:nth-child(even) { background: #fafbfc; }
        .perm-resource { width: 170px; flex: none; font-size: 13px; font-weight: 600; color: #444; padding-top: 2px; }
        .perm-actions { display: flex; flex-wrap: wrap; gap: 4px 16px; flex: 1; }
        .perm-action { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: #555; cursor: pointer; }
        .perm-action input { margin: 0; cursor: pointer; }
        .perm-toolbar { display: flex; justify-content: flex-end; gap: 14px; margin: 0 0 8px 0; font-size: 12px; }
        .perm-toolbar a { color: #546e7a; cursor: pointer; text-decoration: underline; }

        /* Company scope */
        .scope-options { display: flex; flex-direction: column; gap: 8px; }
        .scope-option { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #444; cursor: pointer; }
        .company-checks { margin: 8px 0 0 24px; display: flex; flex-direction: column; gap: 6px; max-height: 140px; overflow-y: auto; }
        .company-checks label { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #555; cursor: pointer; }

        /* New-key reveal */
        .newkey-box { background: #f5f7fa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 14px 16px; margin-top: 10px; }
        .newkey-box code { display: block; background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 10px 12px; font-size: 13px; color: #333; word-break: break-all; margin: 8px 0; }
        .newkey-warning { font-size: 12px; color: #c62828; font-weight: 600; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="api-container">
        <h1 class="page-title">API</h1>
        <p class="page-subtitle">Create and manage keys for the FreeITSM REST API, with granular permissions per key.</p>

        <div class="settings-card">
            <h3>Base URL</h3>
            <p class="card-desc">All v1 endpoints live under this URL. Authenticate with <strong>Authorization: Bearer &lt;key&gt;</strong>.</p>
            <div class="base-url-box">
                <code id="apiBaseUrl"><?php echo htmlspecialchars($apiBaseUrl); ?></code>
                <button class="btn btn-secondary" id="copyBaseBtn">Copy</button>
                <a class="btn btn-primary" href="docs.php">Documentation</a>
            </div>
        </div>

        <div class="settings-card">
            <div class="keys-head">
                <div>
                    <h3 style="margin:0;">API keys</h3>
                    <p class="card-desc" style="margin:4px 0 0;">A key acts as an analyst and can only do what its permissions allow. The full key is shown once, at creation.</p>
                </div>
                <button class="add-btn" id="addKeyBtn">Add</button>
            </div>
            <div class="info-note" id="tableNotReady" style="display:none; margin-bottom:14px;">
                The API key tables haven't been created yet — run <a href="../db-verify/">Database Verification</a> once, then reload this page.
            </div>
            <table class="keys">
                <thead>
                    <tr>
                        <th>Name</th><th>Key</th><th>Acts as</th><th>Permissions</th>
                        <th class="company-col" style="display:none;">Companies</th>
                        <th>Status</th><th>Last used</th><th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="keysBody">
                    <tr class="empty-row"><td colspan="8">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create / edit modal -->
    <div class="apik-modal-overlay" id="keyModal">
        <div class="apik-modal">
            <div class="apik-modal-header" id="modalTitle">New API key</div>
            <div class="apik-modal-body">
                <input type="hidden" id="fKeyId">
                <div class="form-2col">
                    <div class="form-field">
                        <label>Name</label>
                        <div class="hint">What this key is for, e.g. "Monitoring integration".</div>
                        <input type="text" id="fName" placeholder="Monitoring integration">
                    </div>
                    <div class="form-field">
                        <label>Acts as</label>
                        <div class="hint">Tickets, notes and audit entries are attributed to this analyst.</div>
                        <select id="fAnalyst"></select>
                    </div>
                </div>

                <div class="form-field">
                    <label>Permissions</label>
                    <div class="hint">Grant only what the integration needs — everything else is denied.</div>
                    <div class="perm-toolbar"><a id="permAll">Select all</a><a id="permNone">Clear all</a></div>
                    <div class="perm-matrix" id="permMatrix"></div>
                </div>

                <div class="form-field" id="scopeField" style="display:none;">
                    <label>Company access</label>
                    <div class="scope-options">
                        <label class="scope-option"><input type="radio" name="scope" value="all" checked> All companies</label>
                        <label class="scope-option"><input type="radio" name="scope" value="specific"> Specific companies only</label>
                    </div>
                    <div class="company-checks" id="companyChecks" style="display:none;"></div>
                </div>

                <div class="form-2col">
                    <div class="form-field">
                        <label>Expires</label>
                        <div class="hint">Optional. The key stops working after this date.</div>
                        <input type="date" id="fExpires">
                    </div>
                    <div class="form-field">
                        <label>Rate limit</label>
                        <div class="hint">Requests per minute. Blank uses the system default (60).</div>
                        <input type="number" id="fRateLimit" min="1" placeholder="60">
                    </div>
                </div>
            </div>
            <div class="apik-modal-footer">
                <button class="btn btn-secondary" id="cancelBtn">Cancel</button>
                <button class="btn btn-primary" id="saveBtn">Save</button>
            </div>
        </div>
    </div>

    <!-- New-key reveal modal -->
    <div class="apik-modal-overlay" id="revealModal">
        <div class="apik-modal">
            <div class="apik-modal-header">Key created</div>
            <div class="apik-modal-body">
                <div class="newkey-box">
                    Your new API key:
                    <code id="newKeyValue"></code>
                    <div class="newkey-warning">Copy it now — it is stored hashed and can never be shown again.</div>
                </div>
            </div>
            <div class="apik-modal-footer">
                <button class="btn btn-secondary" id="copyKeyBtn">Copy</button>
                <button class="btn btn-primary" id="revealCloseBtn">Close</button>
            </div>
        </div>
    </div>

    <script>
    const API = '../../api/system/api_keys/';
    let catalog = {};
    let analysts = [];
    let companies = [];
    let multiTenant = false;
    let keys = [];

    async function loadKeys() {
        const res = await fetch(API + 'list_keys.php');
        const data = await res.json();
        if (!data.success) { alert(data.error || 'Failed to load keys'); return; }
        catalog = data.catalog; analysts = data.analysts; companies = data.companies;
        multiTenant = data.multi_tenant; keys = data.keys;
        document.getElementById('tableNotReady').style.display = data.table_ready ? 'none' : 'block';
        document.querySelectorAll('.company-col').forEach(el => el.style.display = multiTenant ? '' : 'none');
        renderKeys();
    }

    function permSummary(perms) {
        let n = 0;
        Object.values(perms || {}).forEach(a => n += a.length);
        return n;
    }

    function esc(s) {
        const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML;
    }

    function renderKeys() {
        const body = document.getElementById('keysBody');
        const colspan = multiTenant ? 8 : 7;
        if (!keys.length) {
            body.innerHTML = `<tr class="empty-row"><td colspan="${colspan}">No API keys yet — click Add to create one.</td></tr>`;
            return;
        }
        const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
        body.innerHTML = keys.map(k => {
            const expired = k.expires_at && k.expires_at <= now;
            const status = expired ? '<span class="status-badge expired">Expired</span>'
                : k.active ? '<span class="status-badge on">Active</span>'
                : '<span class="status-badge off">Disabled</span>';
            let companyCell = '';
            if (multiTenant) {
                const names = k.company_ids === null ? 'All'
                    : k.company_ids.map(id => (companies.find(c => c.id === id) || {name: '#' + id}).name).join(', ');
                companyCell = `<td>${esc(names)}</td>`;
            }
            const lastUsed = k.last_used_at ? esc(k.last_used_at.slice(0, 16)) + ' UTC' : 'Never';
            return `<tr>
                <td><strong>${esc(k.name)}</strong></td>
                <td class="key-prefix">${esc(k.key_prefix)}…</td>
                <td>${esc(k.analyst_name || '')}</td>
                <td><span class="perm-count">${permSummary(k.permissions)} granted</span></td>
                ${companyCell}
                <td>${status}</td>
                <td>${lastUsed}</td>
                <td style="text-align:right; white-space:nowrap;">
                    <button class="table-action-btn" onclick="openEdit(${k.id})">Edit</button>
                    <button class="table-action-btn" onclick="toggleKey(${k.id}, ${k.active ? 'false' : 'true'})">${k.active ? 'Disable' : 'Enable'}</button>
                    <button class="table-action-btn danger" onclick="deleteKey(${k.id})">Delete</button>
                </td>
            </tr>`;
        }).join('');
    }

    // ---- Modal ----
    function buildMatrix(selected) {
        const matrix = document.getElementById('permMatrix');
        matrix.innerHTML = Object.entries(catalog).map(([resource, def]) => {
            const actions = Object.entries(def.actions).map(([action, desc]) => {
                const checked = selected && selected[resource] && selected[resource].includes(action) ? 'checked' : '';
                return `<label class="perm-action" title="${esc(desc)}">
                    <input type="checkbox" data-resource="${esc(resource)}" data-action="${esc(action)}" ${checked}> ${esc(action)}
                </label>`;
            }).join('');
            return `<div class="perm-row"><div class="perm-resource">${esc(def.label)}</div><div class="perm-actions">${actions}</div></div>`;
        }).join('');
    }

    function readMatrix() {
        const perms = {};
        document.querySelectorAll('#permMatrix input:checked').forEach(cb => {
            const r = cb.dataset.resource;
            (perms[r] = perms[r] || []).push(cb.dataset.action);
        });
        return perms;
    }

    function buildScope(companyIds) {
        const field = document.getElementById('scopeField');
        field.style.display = multiTenant ? '' : 'none';
        if (!multiTenant) return;
        const specific = Array.isArray(companyIds);
        document.querySelector('input[name=scope][value=all]').checked = !specific;
        document.querySelector('input[name=scope][value=specific]').checked = specific;
        const checks = document.getElementById('companyChecks');
        checks.style.display = specific ? '' : 'none';
        checks.innerHTML = companies.map(c => {
            const checked = specific && companyIds.includes(c.id) ? 'checked' : '';
            return `<label><input type="checkbox" value="${c.id}" ${checked}> ${esc(c.name)}</label>`;
        }).join('');
    }

    function openModal(key) {
        document.getElementById('modalTitle').textContent = key ? 'Edit API key' : 'New API key';
        document.getElementById('fKeyId').value = key ? key.id : '';
        document.getElementById('fName').value = key ? key.name : '';
        document.getElementById('fExpires').value = key && key.expires_at ? key.expires_at.slice(0, 10) : '';
        document.getElementById('fRateLimit').value = key && key.rate_limit_per_minute ? key.rate_limit_per_minute : '';
        const sel = document.getElementById('fAnalyst');
        sel.innerHTML = analysts.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
        if (key) sel.value = key.analyst_id;
        buildMatrix(key ? key.permissions : null);
        buildScope(key ? key.company_ids : null);
        document.getElementById('keyModal').classList.add('open');
    }

    function closeModal() { document.getElementById('keyModal').classList.remove('open'); }

    window.openEdit = function (id) {
        const key = keys.find(k => k.id === id);
        if (key) openModal(key);
    };

    window.toggleKey = async function (id, active) {
        const res = await fetch(API + 'update_key.php', {
            method: 'POST',
            body: JSON.stringify({id: id, active: active === true || active === 'true'})
        });
        const data = await res.json();
        if (!data.success) { alert(data.error || 'Update failed'); return; }
        loadKeys();
    };

    window.deleteKey = async function (id) {
        const key = keys.find(k => k.id === id);
        if (!confirm(`Delete the API key "${key ? key.name : id}"? Integrations using it will stop working immediately.`)) return;
        const res = await fetch(API + 'delete_key.php', {method: 'POST', body: JSON.stringify({id: id})});
        const data = await res.json();
        if (!data.success) { alert(data.error || 'Delete failed'); return; }
        loadKeys();
    };

    async function saveKey() {
        const id = document.getElementById('fKeyId').value;
        const payload = {
            name: document.getElementById('fName').value.trim(),
            analyst_id: parseInt(document.getElementById('fAnalyst').value, 10),
            permissions: readMatrix(),
            expires_at: document.getElementById('fExpires').value || null,
            rate_limit_per_minute: document.getElementById('fRateLimit').value || null
        };
        if (multiTenant) {
            const specific = document.querySelector('input[name=scope][value=specific]').checked;
            payload.company_ids = specific
                ? Array.from(document.querySelectorAll('#companyChecks input:checked')).map(cb => parseInt(cb.value, 10))
                : null;
            if (specific && !payload.company_ids.length) { alert('Choose at least one company, or select "All companies".'); return; }
        }
        if (!payload.name) { alert('A key name is required.'); return; }
        if (!Object.keys(payload.permissions).length) { alert('Grant the key at least one permission.'); return; }

        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        try {
            if (id) payload.id = parseInt(id, 10);
            const res = await fetch(API + (id ? 'update_key.php' : 'create_key.php'), {
                method: 'POST', body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data.success) { alert(data.error || 'Save failed'); return; }
            closeModal();
            if (!id && data.key) {
                document.getElementById('newKeyValue').textContent = data.key;
                document.getElementById('revealModal').classList.add('open');
            }
            loadKeys();
        } finally {
            btn.disabled = false;
        }
    }

    // ---- Wire up ----
    document.getElementById('addKeyBtn').addEventListener('click', () => openModal(null));
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('saveBtn').addEventListener('click', saveKey);
    document.getElementById('permAll').addEventListener('click', () => document.querySelectorAll('#permMatrix input').forEach(cb => cb.checked = true));
    document.getElementById('permNone').addEventListener('click', () => document.querySelectorAll('#permMatrix input').forEach(cb => cb.checked = false));
    document.querySelectorAll('input[name=scope]').forEach(r => r.addEventListener('change', () => {
        document.getElementById('companyChecks').style.display =
            document.querySelector('input[name=scope][value=specific]').checked ? '' : 'none';
    }));
    document.getElementById('copyBaseBtn').addEventListener('click', () => {
        navigator.clipboard.writeText(document.getElementById('apiBaseUrl').textContent);
    });
    document.getElementById('copyKeyBtn').addEventListener('click', () => {
        navigator.clipboard.writeText(document.getElementById('newKeyValue').textContent);
    });
    document.getElementById('revealCloseBtn').addEventListener('click', () => {
        if (confirm('Have you copied the key? It cannot be shown again.')) {
            document.getElementById('revealModal').classList.remove('open');
        }
    });

    loadKeys();
    </script>
</body>
</html>
