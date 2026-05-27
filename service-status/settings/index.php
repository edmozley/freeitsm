<?php
/**
 * Service Status Module - Settings
 * Manage the list of services
 */
session_start();
require_once '../../config.php';

$current_page = 'settings';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Service Status Settings</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* Override the shared .container max-width so this page uses the full screen */
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; }

        .tab:hover { color: #10b981; }
        .tab.active { color: #10b981; border-bottom-color: #10b981; }

        .tab-content .action-btn {
            background: none;
            border: 1px solid #ddd;
            color: #666;
            cursor: pointer;
            padding: 6px;
            margin-right: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .tab-content .action-btn:hover { background: #f0f0f0; border-color: #10b981; color: #10b981; }
        .tab-content .action-btn.delete { color: #d13438; }
        .tab-content .action-btn.delete:hover { background: #fdf3f3; border-color: #d13438; color: #a00; }
        .tab-content .action-btn svg { width: 16px; height: 16px; }

        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.inactive { background: #f8d7da; color: #721c24; }

        .modal-content { padding: 30px; max-width: 500px; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: #333; padding: 0; border-bottom: none; }

        .modal .form-group { margin-bottom: 15px; }
        .modal .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333; }
        .modal .form-group input, .modal .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .modal .form-group textarea { height: 60px; resize: vertical; }
        .modal .form-group input:focus, .modal .form-group textarea:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.1); }
        .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #ccc; border-radius: 24px; transition: background 0.2s;
        }
        .toggle-slider::before {
            content: ''; position: absolute;
            height: 18px; width: 18px; left: 3px; bottom: 3px;
            background: white; border-radius: 50%; transition: transform 0.2s;
        }
        .toggle-switch input:checked + .toggle-slider { background: #10b981; }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }
        .toggle-label { display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer; }

        .modal-actions { margin-top: 20px; }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.15s; }
        .btn-primary { background-color: #10b981; color: white; }
        .btn-primary:hover { background-color: #059669; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="services" onclick="switchTab('services')">Services</button>
            <button class="tab" data-tab="statuses" onclick="switchTab('statuses')">Statuses</button>
            <button class="tab" data-tab="impacts" onclick="switchTab('impacts')">Impact Levels</button>
        </div>

        <div class="tab-content active" id="services-tab">
            <div class="section-header">
                <h2>Services</h2>
                <button class="add-btn" onclick="openAddModal()">Add</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="services-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Statuses Tab -->
        <div class="tab-content" id="statuses-tab">
            <div class="section-header">
                <h2>Incident Statuses</h2>
                <button class="add-btn" onclick="openLookupModal('status')">Add</button>
            </div>
            <p style="color: #666; margin-bottom: 16px;">Workflow states for service incidents. Statuses flagged as <em>Resolved</em> close the incident — auto-stamping <code>resolved_datetime</code> and removing the incident from the active dashboard. Exactly one status is the default for new incidents.</p>
            <table>
                <thead><tr><th>Name</th><th>Colour</th><th>Resolved</th><th>Default</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="statuses-list"><tr><td colspan="7" style="text-align:center;padding:20px;color:#999;">Loading...</td></tr></tbody>
            </table>
        </div>

        <!-- Impact Levels Tab -->
        <div class="tab-content" id="impacts-tab">
            <div class="section-header">
                <h2>Impact Levels</h2>
                <button class="add-btn" onclick="openLookupModal('impact')">Add</button>
            </div>
            <p style="color: #666; margin-bottom: 16px;">Severity bands shown as the badge on each service card. <strong>Severity order</strong> drives the "worst current impact" ordering on the dashboard — lower = worse (1 = Major Outage, 5 = Operational). Two rows can share an order.</p>
            <table>
                <thead><tr><th>Name</th><th>Colour</th><th>Severity</th><th>Default</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="impacts-list"><tr><td colspan="7" style="text-align:center;padding:20px;color:#999;">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- Lookup edit modal (Statuses + Impact Levels share this) -->
    <div class="modal" id="lookupModal">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header" id="lookupModalTitle">Add Item</div>
            <form id="lookupForm">
                <input type="hidden" id="lookupItemKind">
                <input type="hidden" id="lookupItemId">

                <div class="form-group">
                    <label for="lookupItemName">Name</label>
                    <input type="text" id="lookupItemName" required>
                </div>

                <div class="form-group">
                    <label for="lookupItemColour">Colour</label>
                    <input type="color" id="lookupItemColour" value="#10b981" style="width: 60px; height: 32px; padding: 2px;">
                </div>

                <div class="form-group" id="lookupItemResolvedGroup" style="display: none;">
                    <label class="toggle-label">
                        <span class="toggle-switch"><input type="checkbox" id="lookupItemResolved"><span class="toggle-slider"></span></span>
                        Counts as resolved
                    </label>
                    <small style="display:block; color:#666; margin-top:4px;">Incidents in this status auto-stamp <code>resolved_datetime</code> and drop off the active dashboard.</small>
                </div>

                <div class="form-group" id="lookupItemSeverityGroup" style="display: none;">
                    <label for="lookupItemSeverityOrder">Severity Order</label>
                    <input type="number" id="lookupItemSeverityOrder" value="99" min="1">
                    <small style="display:block; color:#666; margin-top:4px;">1 = worst (Major Outage). Higher = less severe.</small>
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch"><input type="checkbox" id="lookupItemDefault"><span class="toggle-slider"></span></span>
                        Default
                    </label>
                </div>

                <div class="form-group">
                    <label for="lookupItemOrder">Display Order</label>
                    <input type="number" id="lookupItemOrder" value="0">
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch"><input type="checkbox" id="lookupItemActive" checked><span class="toggle-slider"></span></span>
                        Active
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeLookupModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit/Add Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Add Service</div>
            <form id="editForm" autocomplete="off">
                <input type="hidden" id="itemId">
                <div class="form-group">
                    <label for="itemName">Name</label>
                    <input type="text" id="itemName" required>
                </div>
                <div class="form-group">
                    <label for="itemDescription">Description</label>
                    <textarea id="itemDescription"></textarea>
                </div>
                <div class="form-group">
                    <label for="itemOrder">Display Order</label>
                    <input type="number" id="itemOrder" value="0" min="0">
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="itemActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        Active
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/service-status/';
        let allServices = [];

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadServices();
            loadLookup('status');
            loadLookup('impact');
        });

        async function loadServices() {
            try {
                const response = await fetch(API_BASE + 'get_services.php');
                const data = await response.json();
                if (data.success) {
                    allServices = data.services;
                    renderServices(data.services);
                } else {
                    document.getElementById('services-list').innerHTML =
                        '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">Error: ' + escapeHtml(data.error) + '</td></tr>';
                }
            } catch (error) {
                document.getElementById('services-list').innerHTML =
                    '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">Failed to load data</td></tr>';
            }
        }

        function renderServices(items) {
            const tbody = document.getElementById('services-list');

            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">No services yet. Click Add to create one.</td></tr>';
                return;
            }

            tbody.innerHTML = items.map(item => `
                <tr>
                    <td><strong>${escapeHtml(item.name)}</strong></td>
                    <td>${escapeHtml(item.description || '-')}</td>
                    <td>${item.display_order}</td>
                    <td><span class="status-badge ${item.is_active ? 'active' : 'inactive'}">${item.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editService(${item.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteService(${item.id}, '${escapeHtml(item.name)}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Service';
            document.getElementById('itemId').value = '';
            document.getElementById('itemName').value = '';
            document.getElementById('itemDescription').value = '';
            document.getElementById('itemOrder').value = '0';
            document.getElementById('itemActive').checked = true;
            document.getElementById('editModal').classList.add('active');
        }

        function editService(id) {
            const item = allServices.find(i => i.id == id);
            if (!item) return;

            document.getElementById('modalTitle').textContent = 'Edit Service';
            document.getElementById('itemId').value = item.id;
            document.getElementById('itemName').value = item.name;
            document.getElementById('itemDescription').value = item.description || '';
            document.getElementById('itemOrder').value = item.display_order || 0;
            document.getElementById('itemActive').checked = item.is_active;
            document.getElementById('editModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const payload = {
                id: document.getElementById('itemId').value || null,
                name: document.getElementById('itemName').value,
                description: document.getElementById('itemDescription').value,
                display_order: parseInt(document.getElementById('itemOrder').value) || 0,
                is_active: document.getElementById('itemActive').checked ? 1 : 0
            };

            try {
                const response = await fetch(API_BASE + 'save_service.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeModal();
                    showToast('Saved', 'success');
                    loadServices();
                } else {
                    showToast(data.error || 'Failed to save', 'error');
                }
            } catch (error) {
                showToast('Failed to save service', 'error');
            }
        });

        async function deleteService(id, name) {
            if (!(await showConfirm({ title: 'Delete', message: 'Delete "' + name + '"?', okLabel: 'Delete', okClass: 'danger' }))) return;

            try {
                const response = await fetch(API_BASE + 'delete_service.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Deleted', 'success');
                    loadServices();
                } else {
                    showToast(data.error || 'Failed to delete', 'error');
                }
            } catch (error) {
                showToast('Failed to delete service', 'error');
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal on outside click
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // ============================================================
        // Lookup tabs (Statuses + Impact Levels)
        // ============================================================

        const LOOKUP_KINDS = {
            'status': { get: 'get_incident_statuses.php', save: 'save_incident_status.php', del: 'delete_incident_status.php', listKey: 'statuses',      tableId: 'statuses-list', colspan: 7, hasResolved: true,  hasSeverity: false, label: 'Status'       },
            'impact': { get: 'get_impact_levels.php',     save: 'save_impact_level.php',    del: 'delete_impact_level.php',    listKey: 'impact_levels', tableId: 'impacts-list',  colspan: 7, hasResolved: false, hasSeverity: true,  label: 'Impact Level' }
        };

        const lookupCache = { status: [], impact: [] };

        async function loadLookup(kind) {
            const cfg = LOOKUP_KINDS[kind];
            try {
                const res = await fetch(API_BASE + cfg.get);
                const data = await res.json();
                if (data.success) {
                    lookupCache[kind] = data[cfg.listKey] || [];
                    renderLookup(kind);
                }
            } catch (e) { console.error(e); }
        }

        function renderLookup(kind) {
            const cfg = LOOKUP_KINDS[kind];
            const rows = lookupCache[kind];
            const tbody = document.getElementById(cfg.tableId);
            if (!rows || rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="${cfg.colspan}" style="text-align:center;padding:20px;color:#999;">No items found</td></tr>`;
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const safeName = escapeHtml(r.name).replace(/'/g, "\\'");
                const swatch = r.colour
                    ? `<span style="display:inline-block;width:18px;height:18px;border-radius:3px;background:${escapeHtml(r.colour)};vertical-align:middle;border:1px solid #ddd;margin-right:6px;"></span><code style="font-size:12px;">${escapeHtml(r.colour)}</code>`
                    : '<span style="color:#999;">—</span>';
                const flagCol = cfg.hasResolved
                    ? `<td>${r.is_resolved ? '<span class="status-badge active">Yes</span>' : '<span style="color:#999;">No</span>'}</td>`
                    : `<td>${r.severity_order}</td>`;
                return `
                <tr>
                    <td><strong>${escapeHtml(r.name)}</strong></td>
                    <td>${swatch}</td>
                    ${flagCol}
                    <td>${r.is_default ? '<span class="status-badge active">Yes</span>' : '<span style="color:#999;">No</span>'}</td>
                    <td>${r.display_order}</td>
                    <td><span class="status-badge ${r.is_active ? 'active' : 'inactive'}">${r.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editLookup('${kind}', ${r.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteLookup('${kind}', ${r.id}, '${safeName}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        function openLookupModal(kind) {
            const cfg = LOOKUP_KINDS[kind];
            document.getElementById('lookupModalTitle').textContent = `Add ${cfg.label}`;
            document.getElementById('lookupItemKind').value = kind;
            document.getElementById('lookupItemId').value = '';
            document.getElementById('lookupItemName').value = '';
            document.getElementById('lookupItemColour').value = '#10b981';
            document.getElementById('lookupItemResolved').checked = false;
            document.getElementById('lookupItemDefault').checked = false;
            document.getElementById('lookupItemSeverityOrder').value = '99';
            document.getElementById('lookupItemOrder').value = '0';
            document.getElementById('lookupItemActive').checked = true;
            document.getElementById('lookupItemResolvedGroup').style.display = cfg.hasResolved ? '' : 'none';
            document.getElementById('lookupItemSeverityGroup').style.display = cfg.hasSeverity ? '' : 'none';
            document.getElementById('lookupModal').classList.add('active');
        }

        function editLookup(kind, id) {
            const cfg = LOOKUP_KINDS[kind];
            const item = (lookupCache[kind] || []).find(r => r.id == id);
            if (!item) return;
            document.getElementById('lookupModalTitle').textContent = `Edit ${cfg.label}`;
            document.getElementById('lookupItemKind').value = kind;
            document.getElementById('lookupItemId').value = item.id;
            document.getElementById('lookupItemName').value = item.name;
            document.getElementById('lookupItemColour').value = item.colour || '#10b981';
            document.getElementById('lookupItemResolved').checked = !!item.is_resolved;
            document.getElementById('lookupItemDefault').checked = !!item.is_default;
            document.getElementById('lookupItemSeverityOrder').value = item.severity_order ?? 99;
            document.getElementById('lookupItemOrder').value = item.display_order;
            document.getElementById('lookupItemActive').checked = !!item.is_active;
            document.getElementById('lookupItemResolvedGroup').style.display = cfg.hasResolved ? '' : 'none';
            document.getElementById('lookupItemSeverityGroup').style.display = cfg.hasSeverity ? '' : 'none';
            document.getElementById('lookupModal').classList.add('active');
        }

        function closeLookupModal() {
            document.getElementById('lookupModal').classList.remove('active');
        }

        async function deleteLookup(kind, id, name) {
            if (!(await showConfirm({ title: 'Delete', message: `Delete "${name}"?`, okLabel: 'Delete', okClass: 'danger' }))) return;
            const cfg = LOOKUP_KINDS[kind];
            try {
                const res = await fetch(API_BASE + cfg.del, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: id})
                });
                const data = await res.json();
                if (data.success) { showToast('Deleted', 'success'); loadLookup(kind); }
                else showToast(data.error || 'Failed to delete', 'error');
            } catch (e) { showToast('Failed to delete', 'error'); }
        }

        document.getElementById('lookupForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const kind = document.getElementById('lookupItemKind').value;
            const cfg = LOOKUP_KINDS[kind];
            const payload = {
                id: document.getElementById('lookupItemId').value || null,
                name: document.getElementById('lookupItemName').value,
                colour: document.getElementById('lookupItemColour').value,
                is_default: document.getElementById('lookupItemDefault').checked ? 1 : 0,
                display_order: parseInt(document.getElementById('lookupItemOrder').value || '0', 10),
                is_active: document.getElementById('lookupItemActive').checked ? 1 : 0
            };
            if (cfg.hasResolved) payload.is_resolved = document.getElementById('lookupItemResolved').checked ? 1 : 0;
            if (cfg.hasSeverity) payload.severity_order = parseInt(document.getElementById('lookupItemSeverityOrder').value || '99', 10);

            try {
                const res = await fetch(API_BASE + cfg.save, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) { closeLookupModal(); showToast('Saved', 'success'); loadLookup(kind); }
                else showToast(data.error || 'Failed to save', 'error');
            } catch (e) { showToast('Failed to save', 'error'); }
        });

        document.getElementById('lookupModal').addEventListener('click', function(e) {
            if (e.target === this) closeLookupModal();
        });
    </script>
</body>
</html>
