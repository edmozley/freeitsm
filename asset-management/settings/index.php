<?php
/**
 * Asset Management - Settings
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
    <title>Service Desk - Asset Settings</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/chart.min.js"></script>
    <style>
        body {
            overflow: auto;
            height: auto;
        }

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

        .tab-content .action-btn:hover {
            background: #f0f0f0;
            border-color: #107c10;
            color: #107c10;
        }

        .tab-content .action-btn.delete {
            color: #d13438;
        }

        .tab-content .action-btn.delete:hover {
            background: #fdf3f3;
            border-color: #d13438;
            color: #a00;
        }

        .tab-content .action-btn svg {
            width: 16px;
            height: 16px;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        /* vCenter section styles */
        .settings-section {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
            overflow: hidden;
        }

        .settings-section-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .settings-section-header svg { color: #107c10; flex-shrink: 0; }
        .settings-section-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: #333; }
        .settings-section-body { padding: 25px; }
        .settings-description { font-size: 13px; color: #666; margin: 0 0 20px 0; line-height: 1.5; }

        .form-group { margin-bottom: 18px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333; }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-input:focus { outline: none; border-color: #107c10; box-shadow: 0 0 0 2px rgba(16, 124, 16, 0.1); }
        .form-hint { font-size: 12px; color: #888; margin-top: 4px; }

        .form-actions {
            display: flex; align-items: center; gap: 12px;
            margin-top: 25px; padding-top: 20px; border-top: 1px solid #eee;
        }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.15s; }
        .btn-primary { background-color: #107c10; color: white; }
        .btn-primary:hover { background-color: #0b5c0b; }
        .btn-primary:disabled { background-color: #999; cursor: not-allowed; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-secondary:hover { background-color: #5a6268; }
        .btn-secondary:disabled { background-color: #b0b6bb; cursor: not-allowed; }

        .intune-progress { margin-top: 18px; }
        .intune-progress-bar { background: #e0e0e0; border-radius: 4px; height: 10px; overflow: hidden; }
        .intune-progress-fill { background: #107c10; height: 100%; width: 0; transition: width 0.3s ease-out; }
        .intune-progress-meta { font-size: 12px; color: #666; margin-top: 6px; }
        .intune-progress.intune-error .intune-progress-fill { background: #d13438; }

        .intune-software-section { margin-top: 30px; padding-top: 25px; border-top: 1px solid #eee; }
        .intune-subsection-title { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 8px 0; }
        .intune-freshness-wrap { margin-top: 22px; padding: 14px 16px; background: #fafbfc; border: 1px solid #eee; border-radius: 6px; }
        .intune-freshness-title { font-size: 12px; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px; }
        .intune-freshness-canvas-wrap { position: relative; height: 180px; }
        .intune-jobs-list { margin-top: 18px; }
        .intune-jobs-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .intune-jobs-table th { text-align: left; padding: 8px 10px; background: #f8f9fa; color: #666; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid #e0e0e0; }
        .intune-jobs-table td { padding: 8px 10px; border-bottom: 1px solid #f0f0f0; color: #333; }
        .intune-jobs-table tbody tr:hover { background: #fafafa; }
        .intune-job-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .intune-job-status.pending { background: #fff3e0; color: #e65100; }
        .intune-job-status.running { background: #e3f2fd; color: #1565c0; }
        .intune-job-status.done    { background: #e8f5e9; color: #2e7d32; }
        .intune-job-status.error   { background: #ffebee; color: #c62828; }


        .password-wrapper { position: relative; }
        .password-wrapper .form-input { padding-right: 45px; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #888; font-size: 13px; padding: 4px; }
        .password-toggle:hover { color: #333; }

        .modal-content {
            padding: 30px;
            max-width: 500px;
        }

        .modal-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
            padding: 0;
            border-bottom: none;
        }

        .modal .form-group { margin-bottom: 15px; }
        .modal .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333; }
        .modal .form-group input, .modal .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .modal .form-group textarea { height: 60px; resize: vertical; }
        .modal .form-group input:focus, .modal .form-group textarea:focus { outline: none; border-color: #107c10; box-shadow: 0 0 0 2px rgba(16, 124, 16, 0.1); }
        .modal .checkbox-label { display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer; }
        .modal .checkbox-label input[type="checkbox"] { width: auto; }

        .modal-actions { margin-top: 20px; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="asset-types" onclick="switchTab('asset-types')">Asset Types</button>
            <button class="tab" data-tab="asset-statuses" onclick="switchTab('asset-statuses')">Asset Statuses</button>
            <button class="tab" data-tab="vcenter" onclick="switchTab('vcenter')">vCenter</button>
            <button class="tab" data-tab="intune" onclick="switchTab('intune')">InTune</button>
        </div>

        <!-- Asset Types Tab -->
        <div class="tab-content active" id="asset-types-tab">
            <div class="section-header">
                <h2>Asset Types</h2>
                <button class="add-btn" onclick="openAddModal('asset-type')">Add</button>
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
                <tbody id="asset-types-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Asset Statuses Tab -->
        <div class="tab-content" id="asset-statuses-tab">
            <div class="section-header">
                <h2>Asset Statuses</h2>
                <button class="add-btn" onclick="openAddModal('asset-status')">Add</button>
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
                <tbody id="asset-statuses-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- vCenter Tab -->
        <div class="tab-content" id="vcenter-tab">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                        <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                        <line x1="6" y1="6" x2="6.01" y2="6"></line>
                        <line x1="6" y1="18" x2="6.01" y2="18"></line>
                    </svg>
                    <h2>vCenter Integration</h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        Connect to a VMware vCenter Server to import virtual machine inventory data.
                    </p>
                    <form id="vcenterForm" onsubmit="saveVcenterSettings(event)">
                        <div class="form-group">
                            <label class="form-label" for="vcenterServer">vCenter Server</label>
                            <input type="text" class="form-input" id="vcenterServer" placeholder="e.g. vcenter.company.local">
                            <div class="form-hint">Hostname or IP address of the vCenter Server</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="vcenterUser">vCenter User</label>
                            <input type="text" class="form-input" id="vcenterUser" placeholder="e.g. administrator@vsphere.local">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="vcenterPassword">vCenter Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-input" id="vcenterPassword" placeholder="Enter password">
                                <button type="button" class="password-toggle" onclick="togglePassword()">Show</button>
                            </div>
                            <div class="form-hint">Leave unchanged to keep the saved password</div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="saveBtn">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- InTune Tab -->
        <div class="tab-content" id="intune-tab">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="12" rx="2" ry="2"></rect>
                        <line x1="8" y1="20" x2="16" y2="20"></line>
                        <line x1="12" y1="16" x2="12" y2="20"></line>
                    </svg>
                    <h2>Microsoft InTune Integration</h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        Connect to Microsoft InTune via Microsoft Graph using an Azure AD app registration to import managed device inventory.
                    </p>
                    <form id="intuneForm" onsubmit="saveIntuneSettings(event)">
                        <div class="form-group">
                            <label class="form-label" for="intuneTenantId">Tenant ID</label>
                            <input type="text" class="form-input" id="intuneTenantId" placeholder="e.g. 00000000-0000-0000-0000-000000000000">
                            <div class="form-hint">Azure AD directory (tenant) ID</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="intuneClientId">Client ID</label>
                            <input type="text" class="form-input" id="intuneClientId" placeholder="Application (client) ID">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="intuneClientSecret">Client Secret</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-input" id="intuneClientSecret" placeholder="Enter client secret">
                                <button type="button" class="password-toggle" onclick="toggleIntuneSecret()">Show</button>
                            </div>
                            <div class="form-hint">Leave unchanged to keep the saved secret</div>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label" style="display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer;">
                                <input type="checkbox" id="intuneVerifySsl" checked style="width: auto;">
                                Verify SSL
                            </label>
                            <div class="form-hint">Disable only for testing against environments with self-signed certificates</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="intuneAppBatchSize">Software sync batch size</label>
                            <input type="number" class="form-input" id="intuneAppBatchSize" min="1" max="500" value="30" style="max-width: 140px;">
                            <div class="form-hint">Number of devices included in a single software-sync job (1–500). Smaller batches finish quicker but need more clicks to cover the estate.</div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="intuneSaveBtn">Save</button>
                            <button type="button" class="btn btn-secondary" id="intuneSyncBtn" onclick="startIntuneSync()">Sync</button>
                            <span id="intuneLastSync" class="form-hint" style="margin-left: auto;"></span>
                        </div>
                        <div id="intuneSyncProgress" class="intune-progress" style="display: none;">
                            <div class="intune-progress-bar"><div class="intune-progress-fill" id="intuneProgressFill"></div></div>
                            <div class="intune-progress-meta" id="intuneProgressMeta">Starting...</div>
                        </div>
                    </form>

                    <div class="intune-software-section">
                        <h3 class="intune-subsection-title">Software inventory sync</h3>
                        <p class="settings-description">
                            Pulls the list of installed applications from Microsoft Graph (<code>$expand=detectedApps</code>) for Intune-managed devices and merges into the existing software inventory. Each click queues one batch — keep clicking <strong>Sync software</strong> to work through the estate over time.
                        </p>
                        <div class="form-actions" style="border-top: none; padding-top: 0;">
                            <button type="button" class="btn btn-secondary" id="intuneAppSyncBtn" onclick="startAppSync()">Sync software</button>
                            <span id="intuneAppEligible" class="form-hint" style="margin-left: auto;"></span>
                        </div>
                        <div id="intuneAppSyncProgress" class="intune-progress" style="display: none;">
                            <div class="intune-progress-bar"><div class="intune-progress-fill" id="intuneAppProgressFill"></div></div>
                            <div class="intune-progress-meta" id="intuneAppProgressMeta">Starting...</div>
                        </div>
                        <div class="intune-freshness-wrap" id="intuneFreshnessWrap" style="display: none;">
                            <div class="intune-freshness-title">Inventory freshness</div>
                            <div class="intune-freshness-canvas-wrap"><canvas id="intuneFreshnessChart"></canvas></div>
                        </div>
                        <div id="intuneAppJobsList" class="intune-jobs-list"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit/Add Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Add Item</div>
            <form id="editForm">
                <input type="hidden" id="itemId">
                <input type="hidden" id="itemType">
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
                    <label class="checkbox-label">
                        <input type="checkbox" id="itemActive" checked>
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
        const API_BASE = '../../api/assets/';
        const API_SETTINGS = '../../api/settings/';
        let currentTab = 'asset-types';
        let allItems = { 'asset-type': [], 'asset-status': [] };

        const endpoints = {
            'asset-type': {
                get: API_BASE + 'get_asset_types.php',
                save: API_BASE + 'save_asset_type.php',
                delete: API_BASE + 'delete_asset_type.php',
                key: 'asset_types',
                listId: 'asset-types-list',
                label: 'Asset Type'
            },
            'asset-status': {
                get: API_BASE + 'get_asset_status_types.php',
                save: API_BASE + 'save_asset_status_type.php',
                delete: API_BASE + 'delete_asset_status_type.php',
                key: 'asset_status_types',
                listId: 'asset-statuses-list',
                label: 'Asset Status'
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            loadItems('asset-type');
            loadItems('asset-status');
            loadIntegrationSettings();
        });

        function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        async function loadItems(type) {
            const ep = endpoints[type];
            try {
                const response = await fetch(ep.get);
                const data = await response.json();
                if (data.success) {
                    allItems[type] = data[ep.key];
                    renderItems(type, data[ep.key]);
                } else {
                    document.getElementById(ep.listId).innerHTML =
                        '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">Error: ' + escapeHtml(data.error) + '</td></tr>';
                }
            } catch (error) {
                console.error('Error loading ' + type + ':', error);
                document.getElementById(ep.listId).innerHTML =
                    '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">Failed to load data</td></tr>';
            }
        }

        function renderItems(type, items) {
            const ep = endpoints[type];
            const tbody = document.getElementById(ep.listId);

            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">No items yet. Click Add to create one.</td></tr>';
                return;
            }

            tbody.innerHTML = items.map(item => `
                <tr>
                    <td><strong>${escapeHtml(item.name)}</strong></td>
                    <td>${escapeHtml(item.description || '-')}</td>
                    <td>${item.display_order}</td>
                    <td><span class="status-badge ${item.is_active ? 'active' : 'inactive'}">${item.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('${type}', ${item.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('${type}', ${item.id}, '${escapeHtml(item.name)}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openAddModal(type) {
            const ep = endpoints[type];
            document.getElementById('modalTitle').textContent = 'Add ' + ep.label;
            document.getElementById('itemId').value = '';
            document.getElementById('itemType').value = type;
            document.getElementById('itemName').value = '';
            document.getElementById('itemDescription').value = '';
            document.getElementById('itemOrder').value = '0';
            document.getElementById('itemActive').checked = true;
            document.getElementById('editModal').classList.add('active');
        }

        function editItem(type, id) {
            const ep = endpoints[type];
            const item = allItems[type].find(i => i.id == id);
            if (!item) return;

            document.getElementById('modalTitle').textContent = 'Edit ' + ep.label;
            document.getElementById('itemId').value = item.id;
            document.getElementById('itemType').value = type;
            document.getElementById('itemName').value = item.name;
            document.getElementById('itemDescription').value = item.description || '';
            document.getElementById('itemOrder').value = item.display_order || 0;
            document.getElementById('itemActive').checked = item.is_active;
            document.getElementById('editModal').classList.add('active');
        }

        async function deleteItem(type, id, name) {
            const ep = endpoints[type];
            if (!confirm('Are you sure you want to delete "' + name + '"? Any assets using this ' + ep.label.toLowerCase() + ' will have it cleared.')) return;

            try {
                const response = await fetch(ep.delete, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await response.json();
                if (data.success) {
                    loadItems(type);
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error deleting:', error);
                alert('Failed to delete item');
            }
        }

        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const type = document.getElementById('itemType').value;
            const ep = endpoints[type];
            const id = document.getElementById('itemId').value;

            const payload = {
                name: document.getElementById('itemName').value.trim(),
                description: document.getElementById('itemDescription').value.trim(),
                display_order: parseInt(document.getElementById('itemOrder').value) || 0,
                is_active: document.getElementById('itemActive').checked ? 1 : 0
            };
            if (id) payload.id = parseInt(id);

            try {
                const response = await fetch(ep.save, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeModal();
                    loadItems(type);
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error saving:', error);
                alert('Failed to save item');
            }
        });

        let modalMouseDownTarget = null;
        document.getElementById('editModal').addEventListener('mousedown', function(e) {
            modalMouseDownTarget = e.target;
        });
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this && modalMouseDownTarget === this) closeModal();
        });

        // Integration settings (vCenter + InTune). Secret fields are left empty;
        // the placeholder tells the user one is already saved. The save endpoint
        // treats blank/asterisk values as "keep existing", so leaving them
        // alone preserves the stored secret.
        async function loadIntegrationSettings() {
            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    document.getElementById('vcenterServer').value = data.settings.vcenter_server || '';
                    document.getElementById('vcenterUser').value = data.settings.vcenter_user || '';
                    const vcPwField = document.getElementById('vcenterPassword');
                    vcPwField.value = '';
                    vcPwField.placeholder = data.settings.vcenter_password
                        ? 'Saved (enter new password to change)'
                        : 'Enter password';

                    document.getElementById('intuneTenantId').value = data.settings.intune_tenant_id || '';
                    document.getElementById('intuneClientId').value = data.settings.intune_client_id || '';
                    const intSecField = document.getElementById('intuneClientSecret');
                    intSecField.value = '';
                    intSecField.placeholder = data.settings.intune_client_secret
                        ? 'Saved (enter new secret to change)'
                        : 'Enter client secret';
                    // verify_ssl: default to true unless explicitly stored as "0"
                    document.getElementById('intuneVerifySsl').checked = data.settings.intune_verify_ssl !== '0';
                    // batch size: default to 30 if not stored
                    const batch = parseInt(data.settings.intune_app_batch_size, 10);
                    document.getElementById('intuneAppBatchSize').value = (batch > 0 ? batch : 30);
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }

        async function saveVcenterSettings(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        settings: {
                            vcenter_server: document.getElementById('vcenterServer').value.trim(),
                            vcenter_user: document.getElementById('vcenterUser').value.trim(),
                            vcenter_password: document.getElementById('vcenterPassword').value
                        }
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Settings saved successfully', 'success');
                    loadIntegrationSettings();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save settings', 'error');
            }

            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        }

        async function saveIntuneSettings(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('intuneSaveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        settings: {
                            intune_tenant_id: document.getElementById('intuneTenantId').value.trim(),
                            intune_client_id: document.getElementById('intuneClientId').value.trim(),
                            intune_client_secret: document.getElementById('intuneClientSecret').value,
                            intune_verify_ssl: document.getElementById('intuneVerifySsl').checked ? '1' : '0',
                            intune_app_batch_size: String(Math.max(1, Math.min(500, parseInt(document.getElementById('intuneAppBatchSize').value, 10) || 30)))
                        }
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Settings saved successfully', 'success');
                    loadIntegrationSettings();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save settings', 'error');
            }

            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        }

        function togglePassword() {
            const input = document.getElementById('vcenterPassword');
            const btn = input.nextElementSibling;
            if (input.type === 'password') { input.type = 'text'; btn.textContent = 'Hide'; }
            else { input.type = 'password'; btn.textContent = 'Show'; }
        }

        function toggleIntuneSecret() {
            const input = document.getElementById('intuneClientSecret');
            const btn = input.nextElementSibling;
            if (input.type === 'password') { input.type = 'text'; btn.textContent = 'Hide'; }
            else { input.type = 'password'; btn.textContent = 'Show'; }
        }

        // InTune sync
        const API_INTUNE = '../../api/intune/';
        let intunePollTimer = null;

        async function startIntuneSync() {
            const btn = document.getElementById('intuneSyncBtn');
            btn.disabled = true;
            btn.textContent = 'Starting...';
            showIntuneProgress(0, 'Starting...', false);

            try {
                const response = await fetch(API_INTUNE + 'sync.php', { method: 'POST' });
                const data = await response.json();
                if (!data.success) {
                    showIntuneProgress(0, 'Error: ' + data.error, true);
                    btn.disabled = false;
                    btn.textContent = 'Sync';
                    return;
                }
                pollIntuneStatus(data.id);
            } catch (e) {
                showIntuneProgress(0, 'Network error starting sync', true);
                btn.disabled = false;
                btn.textContent = 'Sync';
            }
        }

        function pollIntuneStatus(jobId) {
            clearTimeout(intunePollTimer);
            const tick = async () => {
                try {
                    const response = await fetch(API_INTUNE + 'sync_status.php?id=' + encodeURIComponent(jobId));
                    const data = await response.json();
                    if (!data.success || !data.job) {
                        showIntuneProgress(0, 'Status unavailable', true);
                        resetIntuneSyncButton();
                        return;
                    }
                    const job = data.job;
                    showIntuneProgress(job.percent, job.message || job.status, job.status === 'error');

                    if (job.status === 'running') {
                        intunePollTimer = setTimeout(tick, 1500);
                    } else {
                        resetIntuneSyncButton();
                        loadIntuneLastSync();
                    }
                } catch (e) {
                    showIntuneProgress(0, 'Network error polling status', true);
                    resetIntuneSyncButton();
                }
            };
            tick();
        }

        function showIntuneProgress(percent, message, isError) {
            const wrap = document.getElementById('intuneSyncProgress');
            const fill = document.getElementById('intuneProgressFill');
            const meta = document.getElementById('intuneProgressMeta');
            wrap.style.display = '';
            wrap.classList.toggle('intune-error', !!isError);
            fill.style.width = (Math.max(0, Math.min(100, percent || 0))) + '%';
            meta.textContent = message || '';
        }

        function resetIntuneSyncButton() {
            const btn = document.getElementById('intuneSyncBtn');
            btn.disabled = false;
            btn.textContent = 'Sync';
        }

        async function loadIntuneLastSync() {
            try {
                const response = await fetch(API_INTUNE + 'sync_status.php');
                const data = await response.json();
                const last = document.getElementById('intuneLastSync');
                if (data.success && data.job) {
                    const job = data.job;
                    if (job.status === 'running') {
                        last.textContent = '';
                        pollIntuneStatus(job.id);
                        return;
                    }
                    const when = job.finished_datetime || job.started_datetime;
                    const date = when ? new Date(when + 'Z').toLocaleString('en-GB') : '';
                    last.textContent = 'Last sync: ' + date + ' (' + job.status + ')';
                } else {
                    last.textContent = '';
                }
            } catch (e) {
                document.getElementById('intuneLastSync').textContent = '';
            }
        }

        // Pull last-sync info on first load
        document.addEventListener('DOMContentLoaded', loadIntuneLastSync);

        // ─── Software (app) sync ────────────────────────────────────────────
        let appSyncPollTimer = null;

        async function startAppSync() {
            const btn = document.getElementById('intuneAppSyncBtn');
            btn.disabled = true;
            btn.textContent = 'Starting...';
            showAppSyncProgress(0, 'Starting...', false);

            try {
                const response = await fetch(API_INTUNE + 'create_app_sync_job.php', { method: 'POST' });
                const data = await response.json();
                if (!data.success) {
                    showAppSyncProgress(0, 'Error: ' + data.error, true);
                    resetAppSyncButton();
                    return;
                }
                showAppSyncProgress(0, (data.reused ? 'Resuming existing job' : 'Job queued') + ` for ${data.asset_count} asset${data.asset_count === 1 ? '' : 's'}...`, false);
                pollAppSyncStatus(data.id);
            } catch (e) {
                showAppSyncProgress(0, 'Network error starting software sync', true);
                resetAppSyncButton();
            }
        }

        function pollAppSyncStatus(jobId) {
            clearTimeout(appSyncPollTimer);
            const tick = async () => {
                try {
                    const response = await fetch(API_INTUNE + 'app_sync_job_status.php?id=' + encodeURIComponent(jobId));
                    const data = await response.json();
                    if (!data.success || !data.job) {
                        showAppSyncProgress(0, 'Status unavailable', true);
                        resetAppSyncButton();
                        return;
                    }
                    const job = data.job;
                    const r = job.rollup || {};
                    const summary = `${job.processed} of ${job.total} done` +
                                    (job.failed > 0 ? `, ${job.failed} failed` : '') +
                                    ((r.obsolete || 0) > 0 ? `, ${r.obsolete} obsolete` : '');
                    const message = job.message ? `${job.message} (${summary})` : summary;
                    showAppSyncProgress(job.percent, message, job.status === 'error');

                    if (job.status === 'pending' || job.status === 'running') {
                        appSyncPollTimer = setTimeout(tick, 2000);
                    } else {
                        resetAppSyncButton();
                        loadAppSyncJobs();
                        loadIntuneFreshness();
                    }
                } catch (e) {
                    showAppSyncProgress(0, 'Network error polling status', true);
                    resetAppSyncButton();
                }
            };
            tick();
        }

        function showAppSyncProgress(percent, message, isError) {
            const wrap = document.getElementById('intuneAppSyncProgress');
            const fill = document.getElementById('intuneAppProgressFill');
            const meta = document.getElementById('intuneAppProgressMeta');
            wrap.style.display = '';
            wrap.classList.toggle('intune-error', !!isError);
            fill.style.width = (Math.max(0, Math.min(100, percent || 0))) + '%';
            meta.textContent = message || '';
        }

        function resetAppSyncButton() {
            const btn = document.getElementById('intuneAppSyncBtn');
            btn.disabled = false;
            btn.textContent = 'Sync software';
        }

        async function loadAppSyncJobs() {
            try {
                const response = await fetch(API_INTUNE + 'list_app_sync_jobs.php');
                const data = await response.json();
                const list = document.getElementById('intuneAppJobsList');
                const eligible = document.getElementById('intuneAppEligible');

                if (!data.success) {
                    list.innerHTML = '';
                    eligible.textContent = '';
                    return;
                }

                eligible.textContent = data.eligible_assets > 0
                    ? `${data.eligible_assets} asset${data.eligible_assets === 1 ? '' : 's'} eligible for sync`
                    : 'No eligible assets';

                if (!data.jobs || data.jobs.length === 0) {
                    list.innerHTML = '<div class="form-hint" style="margin-top: 12px;">No app-sync jobs yet.</div>';
                    return;
                }

                // If the latest job is still mid-flight, resume polling
                const latest = data.jobs[0];
                if (latest && (latest.status === 'pending' || latest.status === 'running')) {
                    pollAppSyncStatus(latest.id);
                }

                list.innerHTML = `
                    <table class="intune-jobs-table">
                        <thead>
                            <tr><th>Job</th><th>Status</th><th>Started</th><th>Finished</th><th>Result</th></tr>
                        </thead>
                        <tbody>
                            ${data.jobs.map(j => `
                                <tr>
                                    <td>#${j.id}</td>
                                    <td><span class="intune-job-status ${escapeHtml(j.status)}">${escapeHtml(j.status)}</span></td>
                                    <td>${j.started_datetime ? new Date(j.started_datetime + 'Z').toLocaleString('en-GB') : '-'}</td>
                                    <td>${j.finished_datetime ? new Date(j.finished_datetime + 'Z').toLocaleString('en-GB') : '-'}</td>
                                    <td>${j.processed}/${j.total}${j.failed > 0 ? ` (${j.failed} failed)` : ''}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>`;
            } catch (e) {
                console.error('Error loading app sync jobs:', e);
            }
        }

        document.addEventListener('DOMContentLoaded', loadAppSyncJobs);
        document.addEventListener('DOMContentLoaded', loadIntuneFreshness);

        // ─── Inventory freshness chart ──────────────────────────────────────
        let intuneFreshnessChart = null;

        async function loadIntuneFreshness() {
            try {
                const response = await fetch(API_INTUNE + 'app_sync_freshness.php');
                const data = await response.json();
                if (!data.success) return;

                const wrap = document.getElementById('intuneFreshnessWrap');
                const buckets = data.buckets || {};
                const labels = ['<1d', '1d', '2d', '3d', '4d', '5d', '6d', '7+d', 'never'];
                const values = labels.map(k => buckets[k] || 0);
                const total = values.reduce((s, n) => s + n, 0);

                // Hide chart entirely when there's nothing to show (e.g. no
                // Intune-eligible assets — no point rendering an empty chart).
                if (total === 0) {
                    wrap.style.display = 'none';
                    return;
                }
                wrap.style.display = '';

                // Fresh = green, ageing = amber gradient, never = red
                const colours = ['#107c10', '#3fa83f', '#76c043', '#a8c93a', '#d4c537',
                                 '#e6a82e', '#e07a26', '#d65420', '#d13438'];

                const ctx = document.getElementById('intuneFreshnessChart').getContext('2d');
                if (intuneFreshnessChart) {
                    intuneFreshnessChart.data.datasets[0].data = values;
                    intuneFreshnessChart.update();
                    return;
                }

                intuneFreshnessChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Assets',
                            data: values,
                            backgroundColor: colours,
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => `${ctx.parsed.y} asset${ctx.parsed.y === 1 ? '' : 's'}`,
                                },
                            },
                        },
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 } },
                        },
                    },
                });
            } catch (e) {
                console.error('Error loading freshness chart:', e);
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
