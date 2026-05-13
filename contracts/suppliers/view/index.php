<?php
/**
 * Contracts Module - View Supplier
 */
session_start();
require_once '../../../config.php';

$current_page = 'suppliers';
$path_prefix = '../../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - View Supplier</title>
    <link rel="stylesheet" href="../../../assets/css/inbox.css">
    <style>
        /* Sidebar layout - matches suppliers list */
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
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 12px; border-radius: 6px;
            font-size: 13px; color: #555; cursor: default;
            margin-bottom: 2px;
        }
        .sidebar-stat .stat-value { font-weight: 700; font-size: 14px; color: #333; }
        .sidebar-total {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 12px; border-radius: 6px;
            font-size: 14px; color: #333; cursor: default;
            margin-bottom: 4px; background: #fafafa;
        }
        .sidebar-total .stat-value { font-weight: 700; font-size: 16px; }

        .sidebar-add-btn {
            display: block; width: 100%;
            padding: 10px 16px;
            background: #f59e0b; color: white;
            border: none; border-radius: 6px;
            font-size: 14px; font-weight: 500;
            cursor: pointer; transition: background 0.2s;
            text-align: center; text-decoration: none;
            box-sizing: border-box;
        }
        .sidebar-add-btn:hover { background: #d97706; }

        /* Main card */
        .section-card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
        }
        .section-card .section-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 24px; border-bottom: 1px solid #eee;
        }
        .section-card .section-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: #333; }
        .section-card .section-header .header-actions { display: flex; align-items: center; gap: 10px; }

        .back-link {
            display: inline-flex; align-items: center; gap: 6px;
            color: #888; text-decoration: none; font-size: 13px;
        }
        .back-link:hover { color: #f59e0b; }

        .action-btn {
            background: none; border: 1px solid #ddd; color: #666; cursor: pointer;
            padding: 6px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;
        }
        .action-btn:hover { background: #f0f0f0; border-color: #f59e0b; color: #f59e0b; }
        .action-btn svg { width: 16px; height: 16px; }

        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.inactive { background: #f8d7da; color: #721c24; }

        /* View field grid - mirrors edit modal */
        .view-body { padding: 24px; }

        .view-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px 24px;
        }
        .view-grid .full-width { grid-column: span 2; }

        .view-field label {
            display: block;
            font-size: 12px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .view-value {
            font-size: 14px; color: #333;
            min-height: 1.2em;
            word-wrap: break-word;
        }
        .view-value.empty { color: #bbb; }
        .view-value.multiline { white-space: pre-wrap; }
        .view-value a { color: #b45309; text-decoration: none; }
        .view-value a:hover { color: #d97706; text-decoration: underline; }

        .view-section {
            grid-column: span 2;
            font-size: 13px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            padding: 16px 0 4px 0;
            margin-top: 8px;
            border-top: 1px solid #eee;
        }

        .empty-state { text-align: center; padding: 60px 20px; color: #999; }

        /* Edit modal styles */
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
            font-size: 13px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
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
    <?php include '../../includes/header.php'; ?>

    <div class="contracts-layout">
        <!-- Left Sidebar -->
        <div class="contracts-sidebar">
            <div class="sidebar-section">
                <h3>Overview</h3>
                <div class="sidebar-total">
                    <span>All Suppliers</span>
                    <span class="stat-value" id="sideTotal">-</span>
                </div>
                <div id="overviewBreakdown"></div>
            </div>

            <div class="sidebar-section">
                <a href="../" class="sidebar-add-btn" style="background:#fff;color:#333;border:1px solid #ddd;">← Back to list</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="contracts-main">
            <div class="section-card" id="viewCard">
                <div class="section-header">
                    <h2 id="supplierTitle">Loading...</h2>
                    <div class="header-actions">
                        <a href="../" class="back-link" title="Back to suppliers">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            Back
                        </a>
                        <button class="action-btn" id="editBtn" onclick="openModal(currentSupplier)" title="Edit" style="display:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                    </div>
                </div>
                <div class="view-body" id="viewBody">
                    <div class="empty-state">Loading...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal (same as suppliers list) -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Edit Supplier</div>
            <form id="editForm">
                <input type="hidden" id="itemId">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="legalName">Legal Name</label>
                        <input type="text" id="legalName" required>
                    </div>
                    <div class="form-group full-width">
                        <label for="tradingName">Trading Name</label>
                        <input type="text" id="tradingName" placeholder="If different from legal name">
                    </div>
                    <div class="form-group">
                        <label for="regNumber">Reg Number</label>
                        <input type="text" id="regNumber">
                    </div>
                    <div class="form-group">
                        <label for="vatNumber">VAT/Tax Number</label>
                        <input type="text" id="vatNumber">
                    </div>
                    <div class="form-group">
                        <label for="supplierTypeId">Supplier Type</label>
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
                        <label for="addressLine1">Address Line 1</label>
                        <input type="text" id="addressLine1">
                    </div>
                    <div class="form-group full-width">
                        <label for="addressLine2">Address Line 2</label>
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
                        <label for="questionnaireDateIssued">Date Issued</label>
                        <input type="date" id="questionnaireDateIssued">
                    </div>
                    <div class="form-group">
                        <label for="questionnaireDateReceived">Date Received</label>
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
        const API_BASE = '../../../api/contracts/';
        const supplierId = parseInt(new URLSearchParams(window.location.search).get('id') || '0');
        let suppliers = [];
        let currentSupplier = null;
        let supplierTypes = [];
        let supplierStatuses = [];

        document.addEventListener('DOMContentLoaded', function() {
            if (!supplierId) {
                document.getElementById('supplierTitle').textContent = 'Supplier not specified';
                document.getElementById('viewBody').innerHTML = '<div class="empty-state">No supplier id in URL. <a href="../">Back to suppliers</a>.</div>';
                return;
            }
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
            } catch (e) { console.error(e); }
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
            } catch (e) { console.error(e); }
        }

        async function loadSuppliers() {
            try {
                const response = await fetch(API_BASE + 'get_suppliers.php');
                const data = await response.json();
                if (data.success) {
                    suppliers = data.suppliers;
                    renderOverview();
                    currentSupplier = suppliers.find(s => s.id == supplierId) || null;
                    renderView();
                } else {
                    document.getElementById('viewBody').innerHTML =
                        '<div class="empty-state" style="color:#d13438;">Error: ' + escapeHtml(data.error) + '</div>';
                }
            } catch (e) {
                document.getElementById('viewBody').innerHTML =
                    '<div class="empty-state" style="color:#d13438;">Failed to load supplier</div>';
            }
        }

        function renderOverview() {
            document.getElementById('sideTotal').textContent = suppliers.length;

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

        function renderView() {
            if (!currentSupplier) {
                document.getElementById('supplierTitle').textContent = 'Supplier not found';
                document.getElementById('viewBody').innerHTML =
                    '<div class="empty-state">No supplier with id ' + supplierId + '. <a href="../">Back to suppliers</a>.</div>';
                document.getElementById('editBtn').style.display = 'none';
                return;
            }

            const s = currentSupplier;
            document.getElementById('supplierTitle').textContent = s.legal_name;
            document.getElementById('editBtn').style.display = '';

            const statusBadge = s.is_active
                ? '<span class="status-badge active">Active</span>'
                : '<span class="status-badge inactive">Inactive</span>';

            document.getElementById('viewBody').innerHTML = `
                <div class="view-grid">
                    <div class="view-field full-width">
                        <label>Legal Name</label>
                        ${val(s.legal_name)}
                    </div>
                    <div class="view-field full-width">
                        <label>Trading Name</label>
                        ${val(s.trading_name)}
                    </div>
                    <div class="view-field">
                        <label>Reg Number</label>
                        ${val(s.reg_number)}
                    </div>
                    <div class="view-field">
                        <label>VAT/Tax Number</label>
                        ${val(s.vat_number)}
                    </div>
                    <div class="view-field">
                        <label>Supplier Type</label>
                        ${val(s.supplier_type_name)}
                    </div>
                    <div class="view-field">
                        <label>Status</label>
                        ${val(s.supplier_status_name)}
                    </div>

                    <div class="view-section">Address</div>
                    <div class="view-field full-width">
                        <label>Address Line 1</label>
                        ${val(s.address_line_1)}
                    </div>
                    <div class="view-field full-width">
                        <label>Address Line 2</label>
                        ${val(s.address_line_2)}
                    </div>
                    <div class="view-field">
                        <label>City</label>
                        ${val(s.city)}
                    </div>
                    <div class="view-field">
                        <label>County</label>
                        ${val(s.county)}
                    </div>
                    <div class="view-field">
                        <label>Postcode</label>
                        ${val(s.postcode)}
                    </div>
                    <div class="view-field">
                        <label>Country</label>
                        ${val(s.country)}
                    </div>

                    <div class="view-section">Questionnaire</div>
                    <div class="view-field">
                        <label>Date Issued</label>
                        ${val(formatDate(s.questionnaire_date_issued))}
                    </div>
                    <div class="view-field">
                        <label>Date Received</label>
                        ${val(formatDate(s.questionnaire_date_received))}
                    </div>

                    <div class="view-section">Other</div>
                    <div class="view-field full-width">
                        <label>Comments</label>
                        ${s.comments ? `<div class="view-value multiline">${escapeHtml(s.comments)}</div>` : `<div class="view-value empty">—</div>`}
                    </div>
                    <div class="view-field full-width">
                        <label>Active</label>
                        <div class="view-value">${statusBadge}</div>
                    </div>
                </div>
            `;
        }

        function val(text) {
            if (text === null || text === undefined || text === '') {
                return '<div class="view-value empty">—</div>';
            }
            return `<div class="view-value">${escapeHtml(text)}</div>`;
        }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return '';
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }

        // Edit modal
        function openModal(supplier) {
            if (!supplier) return;
            document.getElementById('modalTitle').textContent = 'Edit Supplier';
            document.getElementById('itemId').value = supplier.id;
            document.getElementById('legalName').value = supplier.legal_name || '';
            document.getElementById('tradingName').value = supplier.trading_name || '';
            document.getElementById('regNumber').value = supplier.reg_number || '';
            document.getElementById('vatNumber').value = supplier.vat_number || '';
            document.getElementById('supplierTypeId').value = supplier.supplier_type_id || '';
            document.getElementById('supplierStatusId').value = supplier.supplier_status_id || '';
            document.getElementById('addressLine1').value = supplier.address_line_1 || '';
            document.getElementById('addressLine2').value = supplier.address_line_2 || '';
            document.getElementById('city').value = supplier.city || '';
            document.getElementById('county').value = supplier.county || '';
            document.getElementById('postcode').value = supplier.postcode || '';
            document.getElementById('country').value = supplier.country || '';
            document.getElementById('questionnaireDateIssued').value = supplier.questionnaire_date_issued || '';
            document.getElementById('questionnaireDateReceived').value = supplier.questionnaire_date_received || '';
            document.getElementById('comments').value = supplier.comments || '';
            document.getElementById('itemActive').checked = !!supplier.is_active;
            document.getElementById('editModal').classList.add('active');
        }

        function closeModal() { document.getElementById('editModal').classList.remove('active'); }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('itemId').value;
            const payload = {
                id: parseInt(id),
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
            try {
                const response = await fetch(API_BASE + 'save_supplier.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeModal();
                    loadSuppliers();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (e) { alert('Failed to save supplier'); }
        });

        let modalMouseDownTarget = null;
        document.getElementById('editModal').addEventListener('mousedown', function(e) { modalMouseDownTarget = e.target; });
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this && modalMouseDownTarget === this) closeModal();
        });

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }
    </script>
</body>
</html>
