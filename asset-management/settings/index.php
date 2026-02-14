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

        .save-message { font-size: 13px; font-weight: 500; opacity: 0; transition: opacity 0.3s; }
        .save-message.visible { opacity: 1; }
        .save-message.success { color: #107c10; }
        .save-message.error { color: #dc3545; }

        .password-wrapper { position: relative; }
        .password-wrapper .form-input { padding-right: 45px; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #888; font-size: 13px; padding: 4px; }
        .password-toggle:hover { color: #333; }

        .modal { z-index: 1000; }
        .modal .modal-content { max-width: 500px; }
        .modal .form-group { margin-bottom: 15px; }
        .modal .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333; }
        .modal .form-group input, .modal .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .modal .form-group textarea { height: 60px; resize: vertical; }
        .modal .form-group input:focus, .modal .form-group textarea:focus { outline: none; border-color: #107c10; box-shadow: 0 0 0 2px rgba(16, 124, 16, 0.1); }
        .modal .checkbox-label { display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer; }
        .modal .checkbox-label input[type="checkbox"] { width: auto; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="asset-types" onclick="switchTab('asset-types')">Asset Types</button>
            <button class="tab" data-tab="asset-statuses" onclick="switchTab('asset-statuses')">Asset Statuses</button>
            <button class="tab" data-tab="vcenter" onclick="switchTab('vcenter')">vCenter</button>
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
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="saveBtn">Save Settings</button>
                            <span class="save-message" id="saveMessage"></span>
                        </div>
                    </form>
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
                    <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
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
            loadVcenterSettings();
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

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // vCenter settings
        async function loadVcenterSettings() {
            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    document.getElementById('vcenterServer').value = data.settings.vcenter_server || '';
                    document.getElementById('vcenterUser').value = data.settings.vcenter_user || '';
                    document.getElementById('vcenterPassword').value = data.settings.vcenter_password || '';
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
                    showMessage('Settings saved successfully', 'success');
                } else {
                    showMessage('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showMessage('Failed to save settings', 'error');
            }

            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Settings';
        }

        function showMessage(text, type) {
            const el = document.getElementById('saveMessage');
            el.textContent = text;
            el.className = 'save-message visible ' + type;
            setTimeout(() => el.classList.remove('visible'), 3000);
        }

        function togglePassword() {
            const input = document.getElementById('vcenterPassword');
            const btn = input.nextElementSibling;
            if (input.type === 'password') { input.type = 'text'; btn.textContent = 'Hide'; }
            else { input.type = 'password'; btn.textContent = 'Show'; }
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
