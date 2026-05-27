<?php
/**
 * Contracts Module - Suppliers
 */
session_start();
require_once '../../config.php';

$current_page = 'suppliers';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Suppliers</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* Sidebar layout - matches contracts dashboard */
        .contracts-layout {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        .contracts-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            overflow-y: auto;
            flex-shrink: 0;
        }

        .contracts-main {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }

        .sidebar-section { margin-bottom: 24px; }
        .sidebar-section h3 {
            font-size: 14px; font-weight: 600; color: #333;
            margin: 0 0 12px 0;
        }
        .sidebar-section h4 {
            font-size: 13px; font-weight: 600; color: #555;
            margin: 14px 0 6px 0;
        }
        .sidebar-section h4:first-of-type { margin-top: 0; }

        .sidebar-stat {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
            cursor: default;
            margin-bottom: 2px;
        }
        .sidebar-stat .stat-value {
            font-weight: 700;
            font-size: 14px;
            color: #333;
        }
        .sidebar-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 14px;
            color: #333;
            cursor: default;
            margin-bottom: 4px;
            background: #fafafa;
        }
        .sidebar-total .stat-value {
            font-weight: 700;
            font-size: 16px;
        }

        .sidebar-add-btn {
            display: block;
            width: 100%;
            padding: 10px 16px;
            background: #f59e0b;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            text-align: center;
            text-decoration: none;
            box-sizing: border-box;
        }
        .sidebar-add-btn:hover { background: #d97706; }

        /* Main content - matches contracts dashboard */
        .section-card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
        }
        .section-card .section-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 24px; border-bottom: 1px solid #eee;
        }
        .section-card .section-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: #333; }

        .section-card table { width: 100%; border-collapse: collapse; }
        .section-card table th {
            text-align: left; padding: 12px 24px; font-size: 12px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid #eee; background: #fafafa;
        }
        .section-card table td {
            padding: 14px 24px; font-size: 14px; color: #333; border-bottom: 1px solid #f0f0f0;
        }
        .section-card table tr:last-child td { border-bottom: none; }
        .section-card table tr:hover { background: #fafafa; }
        .section-card table td .supplier-link { color: #333; text-decoration: none; }
        .section-card table td .supplier-link:hover { color: #b45309; text-decoration: underline; }

        .empty-state { text-align: center; padding: 40px; color: #999; }

        .action-btn {
            background: none; border: 1px solid #ddd; color: #666; cursor: pointer;
            padding: 6px; margin-right: 4px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;
        }
        .action-btn:hover { background: #f0f0f0; border-color: #f59e0b; color: #f59e0b; }
        .action-btn.delete { color: #d13438; }
        .action-btn.delete:hover { background: #fdf3f3; border-color: #d13438; color: #a00; }
        .action-btn svg { width: 16px; height: 16px; }

        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.inactive { background: #f8d7da; color: #721c24; }

        /* Modal styles */
        .modal-content {
            padding: 30px;
            max-width: 750px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: #333; padding: 0; border-bottom: none; }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 20px;
        }
        .form-grid .full-width { grid-column: span 2; }

        .form-section {
            grid-column: span 2;
            font-size: 13px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 0 6px 0;
            margin-top: 5px;
            border-top: 1px solid #eee;
        }
        .form-section:first-child { border-top: none; margin-top: 0; padding-top: 0; }

        .modal .form-group { margin-bottom: 15px; }
        .modal .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333; }
        .modal .form-group input,
        .modal .form-group select,
        .modal .form-group textarea {
            width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;
            font-size: 14px; box-sizing: border-box; font-family: inherit;
        }
        .modal .form-group textarea { height: 70px; resize: vertical; }
        .modal .form-group input:focus,
        .modal .form-group select:focus,
        .modal .form-group textarea:focus { outline: none; border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.1); }
        .modal .checkbox-label { display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer; }
        .modal .checkbox-label input[type="checkbox"] { width: auto; }
        .modal-actions { margin-top: 20px; grid-column: span 2; }

        .btn-primary { background-color: #f59e0b; color: white; }
        .btn-primary:hover { background-color: #d97706; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="contracts-layout">
        <!-- Left Sidebar -->
        <div class="contracts-sidebar">
            <div class="sidebar-section">
                <h3>Overview</h3>
                <div class="sidebar-total">
                    <span>All suppliers</span>
                    <span class="stat-value" id="sideTotal">-</span>
                </div>
                <div id="overviewBreakdown"></div>
            </div>

            <div class="sidebar-section">
                <a href="#" class="sidebar-add-btn" onclick="openModal(); return false;">+ Add supplier</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="contracts-main">
            <div class="section-card">
                <div class="section-header">
                    <h2>Suppliers</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Legal name</th>
                            <th>Trading name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>City</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="suppliersList">
                        <tr><td colspan="6" class="empty-state">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Add supplier</div>
            <form id="editForm">
                <input type="hidden" id="itemId">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="legalName">Legal name</label>
                        <input type="text" id="legalName" required>
                    </div>
                    <div class="form-group full-width">
                        <label for="tradingName">Trading name</label>
                        <input type="text" id="tradingName" placeholder="If different from legal name">
                    </div>
                    <div class="form-group">
                        <label for="regNumber">Reg number</label>
                        <input type="text" id="regNumber">
                    </div>
                    <div class="form-group">
                        <label for="vatNumber">VAT/Tax number</label>
                        <input type="text" id="vatNumber">
                    </div>
                    <div class="form-group">
                        <label for="supplierTypeId">Supplier type</label>
                        <select id="supplierTypeId">
                            <option value="">-- None --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="supplierStatusId">Status</label>
                        <select id="supplierStatusId">
                            <option value="">-- None --</option>
                        </select>
                    </div>

                    <div class="form-section">Address</div>
                    <div class="form-group full-width">
                        <label for="addressLine1">Address line 1</label>
                        <input type="text" id="addressLine1">
                    </div>
                    <div class="form-group full-width">
                        <label for="addressLine2">Address line 2</label>
                        <input type="text" id="addressLine2">
                    </div>
                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city">
                    </div>
                    <div class="form-group">
                        <label for="county">County</label>
                        <input type="text" id="county">
                    </div>
                    <div class="form-group">
                        <label for="postcode">Postcode</label>
                        <input type="text" id="postcode">
                    </div>
                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country">
                    </div>

                    <div class="form-section">Questionnaire</div>
                    <div class="form-group">
                        <label for="questionnaireDateIssued">Date issued</label>
                        <input type="date" id="questionnaireDateIssued">
                    </div>
                    <div class="form-group">
                        <label for="questionnaireDateReceived">Date received</label>
                        <input type="date" id="questionnaireDateReceived">
                    </div>

                    <div class="form-section">Other</div>
                    <div class="form-group full-width">
                        <label for="comments">Comments</label>
                        <textarea id="comments"></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label class="checkbox-label">
                            <input type="checkbox" id="itemActive" checked>
                            Active
                        </label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/contracts/';
        let suppliers = [];
        let supplierTypes = [];
        let supplierStatuses = [];

        document.addEventListener('DOMContentLoaded', function() {
            loadSupplierTypes();
            loadSupplierStatuses();
            loadSuppliers();
        });

        async function loadSupplierTypes() {
            try {
                const response = await fetch(API_BASE + 'get_supplier_types.php');
                const data = await response.json();
                if (data.success) {
                    supplierTypes = data.supplier_types;
                    const select = document.getElementById('supplierTypeId');
                    select.innerHTML = '<option value="">-- None --</option>' +
                        supplierTypes.filter(t => t.is_active).map(t =>
                            `<option value="${t.id}">${escapeHtml(t.name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error loading supplier types:', error); }
        }

        async function loadSupplierStatuses() {
            try {
                const response = await fetch(API_BASE + 'get_supplier_statuses.php');
                const data = await response.json();
                if (data.success) {
                    supplierStatuses = data.supplier_statuses;
                    const select = document.getElementById('supplierStatusId');
                    select.innerHTML = '<option value="">-- None --</option>' +
                        supplierStatuses.filter(s => s.is_active).map(s =>
                            `<option value="${s.id}">${escapeHtml(s.name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error loading supplier statuses:', error); }
        }

        async function loadSuppliers() {
            try {
                const response = await fetch(API_BASE + 'get_suppliers.php');
                const data = await response.json();
                if (data.success) {
                    suppliers = data.suppliers;
                    renderSuppliers();
                    renderOverview();
                } else {
                    document.getElementById('suppliersList').innerHTML =
                        '<tr><td colspan="6" class="empty-state" style="color:#d13438;">Error: ' + escapeHtml(data.error) + '</td></tr>';
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        function renderOverview() {
            document.getElementById('sideTotal').textContent = suppliers.length;

            // Group by supplier type, then by status
            const groups = {};
            suppliers.forEach(s => {
                const typeName = s.supplier_type_name || 'Uncategorised';
                const statusName = s.supplier_status_name || 'No status';
                if (!groups[typeName]) groups[typeName] = {};
                groups[typeName][statusName] = (groups[typeName][statusName] || 0) + 1;
            });

            const typeOrder = Object.keys(groups).sort((a, b) => {
                if (a === 'Uncategorised') return 1;
                if (b === 'Uncategorised') return -1;
                return a.localeCompare(b);
            });

            const container = document.getElementById('overviewBreakdown');
            if (typeOrder.length === 0) {
                container.innerHTML = '<div style="font-size:13px;color:#999;padding:8px 12px;">No suppliers yet</div>';
                return;
            }

            container.innerHTML = typeOrder.map(typeName => {
                const statuses = groups[typeName];
                const statusOrder = Object.keys(statuses).sort((a, b) => {
                    if (a === 'No status') return 1;
                    if (b === 'No status') return -1;
                    return a.localeCompare(b);
                });
                const rows = statusOrder.map(statusName =>
                    `<div class="sidebar-stat">
                        <span>${escapeHtml(statusName)}</span>
                        <span class="stat-value">${statuses[statusName]}</span>
                    </div>`
                ).join('');
                return `<h4>${escapeHtml(typeName)}</h4>${rows}`;
            }).join('');
        }

        function renderSuppliers() {
            const tbody = document.getElementById('suppliersList');
            if (suppliers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="empty-state">No suppliers yet. Click "+ Add supplier" to create one.</td></tr>';
                return;
            }
            tbody.innerHTML = suppliers.map(s => `
                <tr>
                    <td><a href="view/?id=${s.id}" class="supplier-link"><strong>${escapeHtml(s.legal_name)}</strong></a></td>
                    <td>${escapeHtml(s.trading_name || '-')}</td>
                    <td>${escapeHtml(s.supplier_type_name || '-')}</td>
                    <td>${escapeHtml(s.supplier_status_name || '-')}</td>
                    <td>${escapeHtml(s.city || '-')}</td>
                    <td>
                        <a href="view/?id=${s.id}" class="action-btn" title="View">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </a>
                        <button class="action-btn" onclick="editSupplier(${s.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteSupplier(${s.id}, '${escapeHtml(s.legal_name).replace(/'/g, "\\'")}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openModal(supplier = null) {
            document.getElementById('modalTitle').textContent = supplier ? 'Edit supplier' : 'Add supplier';
            document.getElementById('itemId').value = supplier ? supplier.id : '';
            document.getElementById('legalName').value = supplier ? supplier.legal_name : '';
            document.getElementById('tradingName').value = supplier ? (supplier.trading_name || '') : '';
            document.getElementById('regNumber').value = supplier ? (supplier.reg_number || '') : '';
            document.getElementById('vatNumber').value = supplier ? (supplier.vat_number || '') : '';
            document.getElementById('supplierTypeId').value = supplier ? (supplier.supplier_type_id || '') : '';
            document.getElementById('supplierStatusId').value = supplier ? (supplier.supplier_status_id || '') : '';
            document.getElementById('addressLine1').value = supplier ? (supplier.address_line_1 || '') : '';
            document.getElementById('addressLine2').value = supplier ? (supplier.address_line_2 || '') : '';
            document.getElementById('city').value = supplier ? (supplier.city || '') : '';
            document.getElementById('county').value = supplier ? (supplier.county || '') : '';
            document.getElementById('postcode').value = supplier ? (supplier.postcode || '') : '';
            document.getElementById('country').value = supplier ? (supplier.country || '') : '';
            document.getElementById('questionnaireDateIssued').value = supplier ? (supplier.questionnaire_date_issued || '') : '';
            document.getElementById('questionnaireDateReceived').value = supplier ? (supplier.questionnaire_date_received || '') : '';
            document.getElementById('comments').value = supplier ? (supplier.comments || '') : '';
            document.getElementById('itemActive').checked = supplier ? supplier.is_active : true;
            document.getElementById('editModal').classList.add('active');
        }

        function closeModal() { document.getElementById('editModal').classList.remove('active'); }

        function editSupplier(id) {
            const s = suppliers.find(x => x.id == id);
            if (s) openModal(s);
        }

        async function deleteSupplier(id, name) {
            if (!(await showConfirm({ title: 'Delete', message: 'Delete "' + name + '"? Contracts and contacts linked to this supplier will have the supplier cleared.', okLabel: 'Delete', okClass: 'danger' }))) return;
            try {
                const response = await fetch(API_BASE + 'delete_supplier.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await response.json();
                if (data.success) { showToast('Supplier deleted', 'success'); loadSuppliers(); }
                else showToast('Error: ' + data.error, 'error');
            } catch (error) { showToast('Failed to delete supplier', 'error'); }
        }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('itemId').value;
            const payload = {
                legal_name: document.getElementById('legalName').value.trim(),
                trading_name: document.getElementById('tradingName').value.trim(),
                reg_number: document.getElementById('regNumber').value.trim(),
                vat_number: document.getElementById('vatNumber').value.trim(),
                supplier_type_id: document.getElementById('supplierTypeId').value || null,
                supplier_status_id: document.getElementById('supplierStatusId').value || null,
                address_line_1: document.getElementById('addressLine1').value.trim(),
                address_line_2: document.getElementById('addressLine2').value.trim(),
                city: document.getElementById('city').value.trim(),
                county: document.getElementById('county').value.trim(),
                postcode: document.getElementById('postcode').value.trim(),
                country: document.getElementById('country').value.trim(),
                questionnaire_date_issued: document.getElementById('questionnaireDateIssued').value || null,
                questionnaire_date_received: document.getElementById('questionnaireDateReceived').value || null,
                comments: document.getElementById('comments').value.trim(),
                is_active: document.getElementById('itemActive').checked ? 1 : 0
            };
            if (id) payload.id = parseInt(id);
            try {
                const response = await fetch(API_BASE + 'save_supplier.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) { closeModal(); showToast('Supplier saved', 'success'); loadSuppliers(); }
                else showToast('Error: ' + data.error, 'error');
            } catch (error) { showToast('Failed to save supplier', 'error'); }
        });

        let modalMouseDownTarget = null;
        document.getElementById('editModal').addEventListener('mousedown', function(e) { modalMouseDownTarget = e.target; });
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
