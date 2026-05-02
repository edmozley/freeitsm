<?php
/**
 * Contracts Module - Settings
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
    <title>Service Desk - Contract Settings</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; }

        /* Amber theme for Contracts tabs */
        .tab:hover { color: #f59e0b; }
        .tab.active { color: #f59e0b; border-bottom-color: #f59e0b; }

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

        .tab-content .action-btn:hover { background: #f0f0f0; border-color: #f59e0b; color: #f59e0b; }
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
        .modal .form-group input:focus, .modal .form-group textarea:focus { outline: none; border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.1); }
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
        .toggle-switch input:checked + .toggle-slider { background: #f59e0b; }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }
        .toggle-label { display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer; }

        .modal-actions { margin-top: 20px; }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.15s; }
        .btn-primary { background-color: #f59e0b; color: white; }
        .btn-primary:hover { background-color: #d97706; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="supplier-types" onclick="switchTab('supplier-types')">Supplier Types</button>
            <button class="tab" data-tab="supplier-statuses" onclick="switchTab('supplier-statuses')">Supplier Statuses</button>
            <button class="tab" data-tab="contract-statuses" onclick="switchTab('contract-statuses')">Contract Statuses</button>
            <button class="tab" data-tab="payment-schedules" onclick="switchTab('payment-schedules')">Payment Schedules</button>
            <button class="tab" data-tab="contract-term-tabs" onclick="switchTab('contract-term-tabs')">Contract Terms</button>
            <button class="tab" data-tab="rfp-departments" onclick="switchTab('rfp-departments')">RFP Departments</button>
        </div>

        <!-- Supplier Types Tab -->
        <div class="tab-content active" id="supplier-types-tab">
            <div class="section-header">
                <h2>Supplier Types</h2>
                <button class="add-btn" onclick="openAddModal('supplier-type')">Add</button>
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
                <tbody id="supplier-types-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Supplier Statuses Tab -->
        <div class="tab-content" id="supplier-statuses-tab">
            <div class="section-header">
                <h2>Supplier Statuses</h2>
                <button class="add-btn" onclick="openAddModal('supplier-status')">Add</button>
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
                <tbody id="supplier-statuses-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Contract Statuses Tab -->
        <div class="tab-content" id="contract-statuses-tab">
            <div class="section-header">
                <h2>Contract Statuses</h2>
                <button class="add-btn" onclick="openAddModal('contract-status')">Add</button>
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
                <tbody id="contract-statuses-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Payment Schedules Tab -->
        <div class="tab-content" id="payment-schedules-tab">
            <div class="section-header">
                <h2>Payment Schedules</h2>
                <button class="add-btn" onclick="openAddModal('payment-schedule')">Add</button>
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
                <tbody id="payment-schedules-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <!-- Contract Term Tabs Tab -->
        <div class="tab-content" id="contract-term-tabs-tab">
            <div class="section-header">
                <h2>Contract Terms</h2>
                <button class="add-btn" onclick="openAddModal('contract-term-tab')">Add</button>
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
                <tbody id="contract-term-tabs-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- RFP Departments Tab -->
        <div class="tab-content" id="rfp-departments-tab">
            <div class="section-header">
                <h2>RFP Departments</h2>
                <button class="add-btn" onclick="openAddRfpDept()">Add</button>
            </div>
            <p style="color:#888; font-size:13px; margin: 0 0 16px 0;">
                The internal departments that contribute requirements documents to RFPs (e.g. IT, Finance, HR). Used as a tag when uploading source documents.
            </p>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Colour</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="rfp-departments-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit/Add Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Add Item</div>
            <form id="editForm" autocomplete="off">
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

    <!-- RFP Department Modal -->
    <div class="modal" id="rfpDeptModal">
        <div class="modal-content">
            <div class="modal-header" id="rfpDeptModalTitle">Add RFP Department</div>
            <form id="rfpDeptForm" autocomplete="off">
                <input type="hidden" id="rfpDeptId">
                <div class="form-group">
                    <label for="rfpDeptName">Name</label>
                    <input type="text" id="rfpDeptName" required maxlength="100" placeholder="e.g. IT">
                </div>
                <div class="form-group">
                    <label for="rfpDeptColour">Colour</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="color" id="rfpDeptColour" value="#6c757d" style="width:60px; height:36px; padding:0; cursor:pointer;">
                        <span id="rfpDeptColourHex" style="font-family:monospace; font-size:13px; color:#666;">#6c757d</span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="rfpDeptOrder">Display Order</label>
                    <input type="number" id="rfpDeptOrder" value="0" min="0">
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="rfpDeptActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        Active
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRfpDeptModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/contracts/';
        let allItems = { 'supplier-type': [], 'supplier-status': [], 'contract-status': [], 'payment-schedule': [], 'contract-term-tab': [] };

        const endpoints = {
            'supplier-type': {
                get: API_BASE + 'get_supplier_types.php',
                save: API_BASE + 'save_supplier_type.php',
                delete: API_BASE + 'delete_supplier_type.php',
                key: 'supplier_types',
                listId: 'supplier-types-list',
                label: 'Supplier Type'
            },
            'supplier-status': {
                get: API_BASE + 'get_supplier_statuses.php',
                save: API_BASE + 'save_supplier_status.php',
                delete: API_BASE + 'delete_supplier_status.php',
                key: 'supplier_statuses',
                listId: 'supplier-statuses-list',
                label: 'Supplier Status'
            },
            'contract-status': {
                get: API_BASE + 'get_contract_statuses.php',
                save: API_BASE + 'save_contract_status.php',
                delete: API_BASE + 'delete_contract_status.php',
                key: 'contract_statuses',
                listId: 'contract-statuses-list',
                label: 'Contract Status'
            },
            'payment-schedule': {
                get: API_BASE + 'get_payment_schedules.php',
                save: API_BASE + 'save_payment_schedule.php',
                delete: API_BASE + 'delete_payment_schedule.php',
                key: 'payment_schedules',
                listId: 'payment-schedules-list',
                label: 'Payment Schedule'
            },
            'contract-term-tab': {
                get: API_BASE + 'get_contract_term_tabs.php',
                save: API_BASE + 'save_contract_term_tab.php',
                delete: API_BASE + 'delete_contract_term_tab.php',
                key: 'contract_term_tabs',
                listId: 'contract-term-tabs-list',
                label: 'Contract Term'
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            loadItems('supplier-type');
            loadItems('supplier-status');
            loadItems('contract-status');
            loadItems('payment-schedule');
            loadItems('contract-term-tab');
            loadRfpDepartments();
        });

        // ============================================================
        // RFP Departments — separate flow because the schema differs
        // (colour + sort_order, no description)
        // ============================================================
        const RFP_DEPT_API = '../../api/rfp-builder/';
        let rfpDepartments = [];

        async function loadRfpDepartments() {
            try {
                const response = await fetch(RFP_DEPT_API + 'get_rfp_departments.php');
                const data = await response.json();
                if (data.success) {
                    rfpDepartments = data.rfp_departments;
                    renderRfpDepartments(rfpDepartments);
                } else {
                    document.getElementById('rfp-departments-list').innerHTML =
                        '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">Error: ' + escapeHtml(data.error) + '</td></tr>';
                }
            } catch (error) {
                document.getElementById('rfp-departments-list').innerHTML =
                    '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">Failed to load RFP departments</td></tr>';
            }
        }

        function renderRfpDepartments(items) {
            const tbody = document.getElementById('rfp-departments-list');
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">No departments yet. Click Add to create one.</td></tr>';
                return;
            }
            tbody.innerHTML = items.map(item => `
                <tr>
                    <td><strong>${escapeHtml(item.name)}</strong></td>
                    <td>
                        <span style="display:inline-flex; align-items:center; gap:8px;">
                            <span style="width:18px; height:18px; border-radius:4px; border:1px solid #ddd; background:${escapeHtml(item.colour)};"></span>
                            <span style="font-family:monospace; font-size:12px; color:#666;">${escapeHtml(item.colour)}</span>
                        </span>
                    </td>
                    <td>${item.sort_order}</td>
                    <td><span class="status-badge ${item.is_active ? 'active' : 'inactive'}">${item.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editRfpDept(${item.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteRfpDept(${item.id}, ${JSON.stringify(item.name)})" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openAddRfpDept() {
            document.getElementById('rfpDeptModalTitle').textContent = 'Add RFP Department';
            document.getElementById('rfpDeptId').value = '';
            document.getElementById('rfpDeptName').value = '';
            document.getElementById('rfpDeptColour').value = '#6c757d';
            document.getElementById('rfpDeptColourHex').textContent = '#6c757d';
            document.getElementById('rfpDeptOrder').value = '0';
            document.getElementById('rfpDeptActive').checked = true;
            document.getElementById('rfpDeptModal').classList.add('active');
            setTimeout(() => document.getElementById('rfpDeptName').focus(), 50);
        }

        function editRfpDept(id) {
            const item = rfpDepartments.find(d => d.id == id);
            if (!item) return;
            document.getElementById('rfpDeptModalTitle').textContent = 'Edit RFP Department';
            document.getElementById('rfpDeptId').value = item.id;
            document.getElementById('rfpDeptName').value = item.name;
            document.getElementById('rfpDeptColour').value = item.colour;
            document.getElementById('rfpDeptColourHex').textContent = item.colour;
            document.getElementById('rfpDeptOrder').value = item.sort_order;
            document.getElementById('rfpDeptActive').checked = item.is_active;
            document.getElementById('rfpDeptModal').classList.add('active');
            setTimeout(() => document.getElementById('rfpDeptName').focus(), 50);
        }

        async function deleteRfpDept(id, name) {
            if (!confirm('Delete RFP department "' + name + '"? Any uploaded documents tagged with this department will have the tag cleared.')) return;
            try {
                const response = await fetch(RFP_DEPT_API + 'delete_rfp_department.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id})
                });
                const data = await response.json();
                if (data.success) {
                    loadRfpDepartments();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                alert('Failed to delete department');
            }
        }

        function closeRfpDeptModal() {
            document.getElementById('rfpDeptModal').classList.remove('active');
        }

        document.getElementById('rfpDeptColour').addEventListener('input', function() {
            document.getElementById('rfpDeptColourHex').textContent = this.value;
        });

        document.getElementById('rfpDeptForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('rfpDeptId').value;
            const payload = {
                name: document.getElementById('rfpDeptName').value.trim(),
                colour: document.getElementById('rfpDeptColour').value,
                sort_order: parseInt(document.getElementById('rfpDeptOrder').value) || 0,
                is_active: document.getElementById('rfpDeptActive').checked ? 1 : 0
            };
            if (id) payload.id = parseInt(id);

            try {
                const response = await fetch(RFP_DEPT_API + 'save_rfp_department.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeRfpDeptModal();
                    loadRfpDepartments();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                alert('Failed to save department');
            }
        });

        // Click-outside-to-close for the RFP department modal (matches existing pattern)
        let rfpDeptModalMouseDownTarget = null;
        document.getElementById('rfpDeptModal').addEventListener('mousedown', function(e) {
            rfpDeptModalMouseDownTarget = e.target;
        });
        document.getElementById('rfpDeptModal').addEventListener('click', function(e) {
            if (e.target === this && rfpDeptModalMouseDownTarget === this) closeRfpDeptModal();
        });

        function switchTab(tab) {
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
            if (!confirm('Are you sure you want to delete "' + name + '"? Any records using this ' + ep.label.toLowerCase() + ' will have it cleared.')) return;

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

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
