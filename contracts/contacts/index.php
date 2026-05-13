<?php
/**
 * Contracts Module - Contacts
 */
session_start();
require_once '../../config.php';

$current_page = 'contacts';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Contacts</title>
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

        /* Modal */
        .modal-content { padding: 30px; max-width: 500px; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: #333; padding: 0; border-bottom: none; }
        .modal .form-group { margin-bottom: 15px; }
        .modal .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333; }
        .modal .form-group input, .modal .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .modal .form-group input:focus, .modal .form-group select:focus { outline: none; border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.1); }
        .modal .checkbox-label { display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer; }
        .modal .checkbox-label input[type="checkbox"] { width: auto; }
        .modal-actions { margin-top: 20px; }

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
                    <span>All Contacts</span>
                    <span class="stat-value" id="sideTotal">-</span>
                </div>
                <div id="overviewBreakdown"></div>
            </div>

            <div class="sidebar-section">
                <a href="#" class="sidebar-add-btn" onclick="openModal(); return false;">+ Add Contact</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="contracts-main">
            <div class="section-card">
                <div class="section-header">
                    <h2>Contacts</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Job Title</th>
                            <th>Email</th>
                            <th>Mobile</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="contactsList">
                        <tr><td colspan="7" class="empty-state">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Add Contact</div>
            <form id="editForm">
                <input type="hidden" id="itemId">
                <div class="form-group">
                    <label for="firstName">First Name</label>
                    <input type="text" id="firstName" required>
                </div>
                <div class="form-group">
                    <label for="surname">Surname</label>
                    <input type="text" id="surname" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email">
                </div>
                <div class="form-group">
                    <label for="jobTitle">Job Title</label>
                    <input type="text" id="jobTitle">
                </div>
                <div class="form-group">
                    <label for="mobile">Mobile</label>
                    <input type="text" id="mobile">
                </div>
                <div class="form-group">
                    <label for="directDial">Direct Dial</label>
                    <input type="text" id="directDial">
                </div>
                <div class="form-group">
                    <label for="switchboard">Switchboard</label>
                    <input type="text" id="switchboard">
                </div>
                <div class="form-group">
                    <label for="supplierId">Supplier</label>
                    <select id="supplierId">
                        <option value="">-- None --</option>
                    </select>
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
        const API_BASE = '../../api/contracts/';
        let contacts = [];
        let suppliers = [];

        document.addEventListener('DOMContentLoaded', function() {
            loadSuppliers();
            loadContacts();
        });

        async function loadSuppliers() {
            try {
                const response = await fetch(API_BASE + 'get_suppliers.php');
                const data = await response.json();
                if (data.success) {
                    suppliers = data.suppliers;
                    const select = document.getElementById('supplierId');
                    select.innerHTML = '<option value="">-- None --</option>' +
                        suppliers.filter(s => s.is_active).map(s =>
                            `<option value="${s.id}">${escapeHtml(s.legal_name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error:', error); }
        }

        async function loadContacts() {
            try {
                const response = await fetch(API_BASE + 'get_contacts.php');
                const data = await response.json();
                if (data.success) {
                    contacts = data.contacts;
                    renderContacts();
                    renderOverview();
                }
            } catch (error) { console.error('Error:', error); }
        }

        function renderOverview() {
            document.getElementById('sideTotal').textContent = contacts.length;

            // Group by supplier, then split Active / Inactive
            const groups = {};
            contacts.forEach(c => {
                const supplierName = c.supplier_name || 'No supplier';
                const statusName = c.is_active ? 'Active' : 'Inactive';
                if (!groups[supplierName]) groups[supplierName] = {};
                groups[supplierName][statusName] = (groups[supplierName][statusName] || 0) + 1;
            });

            const supplierOrder = Object.keys(groups).sort((a, b) => {
                if (a === 'No supplier') return 1;
                if (b === 'No supplier') return -1;
                return a.localeCompare(b);
            });

            const container = document.getElementById('overviewBreakdown');
            if (supplierOrder.length === 0) {
                container.innerHTML = '<div style="font-size:13px;color:#999;padding:8px 12px;">No contacts yet</div>';
                return;
            }

            container.innerHTML = supplierOrder.map(supplierName => {
                const statuses = groups[supplierName];
                const statusOrder = ['Active', 'Inactive'].filter(s => statuses[s]);
                const rows = statusOrder.map(statusName =>
                    `<div class="sidebar-stat">
                        <span>${escapeHtml(statusName)}</span>
                        <span class="stat-value">${statuses[statusName]}</span>
                    </div>`
                ).join('');
                return `<h4>${escapeHtml(supplierName)}</h4>${rows}`;
            }).join('');
        }

        function renderContacts() {
            const tbody = document.getElementById('contactsList');
            if (contacts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No contacts yet. Click "+ Add Contact" to create one.</td></tr>';
                return;
            }
            tbody.innerHTML = contacts.map(c => `
                <tr>
                    <td><strong>${escapeHtml(c.first_name + ' ' + c.surname)}</strong></td>
                    <td>${escapeHtml(c.job_title || '-')}</td>
                    <td>${escapeHtml(c.email || '-')}</td>
                    <td>${escapeHtml(c.mobile || '-')}</td>
                    <td>${escapeHtml(c.supplier_name || '-')}</td>
                    <td><span class="status-badge ${c.is_active ? 'active' : 'inactive'}">${c.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editContact(${c.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteContact(${c.id}, '${escapeHtml(c.first_name + ' ' + c.surname).replace(/'/g, "\\'")}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openModal(contact = null) {
            document.getElementById('modalTitle').textContent = contact ? 'Edit Contact' : 'Add Contact';
            document.getElementById('itemId').value = contact ? contact.id : '';
            document.getElementById('firstName').value = contact ? contact.first_name : '';
            document.getElementById('surname').value = contact ? contact.surname : '';
            document.getElementById('jobTitle').value = contact ? (contact.job_title || '') : '';
            document.getElementById('email').value = contact ? (contact.email || '') : '';
            document.getElementById('mobile').value = contact ? (contact.mobile || '') : '';
            document.getElementById('directDial').value = contact ? (contact.direct_dial || '') : '';
            document.getElementById('switchboard').value = contact ? (contact.switchboard || '') : '';
            document.getElementById('supplierId').value = contact ? (contact.supplier_id || '') : '';
            document.getElementById('itemActive').checked = contact ? contact.is_active : true;
            document.getElementById('editModal').classList.add('active');
        }

        function closeModal() { document.getElementById('editModal').classList.remove('active'); }

        function editContact(id) {
            const c = contacts.find(x => x.id == id);
            if (c) openModal(c);
        }

        async function deleteContact(id, name) {
            if (!confirm('Delete contact "' + name + '"?')) return;
            try {
                const response = await fetch(API_BASE + 'delete_contact.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await response.json();
                if (data.success) loadContacts();
                else alert('Error: ' + data.error);
            } catch (error) { alert('Failed to delete contact'); }
        }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('itemId').value;
            const payload = {
                first_name: document.getElementById('firstName').value.trim(),
                surname: document.getElementById('surname').value.trim(),
                job_title: document.getElementById('jobTitle').value.trim(),
                email: document.getElementById('email').value.trim(),
                mobile: document.getElementById('mobile').value.trim(),
                direct_dial: document.getElementById('directDial').value.trim(),
                switchboard: document.getElementById('switchboard').value.trim(),
                supplier_id: document.getElementById('supplierId').value || null,
                is_active: document.getElementById('itemActive').checked ? 1 : 0
            };
            if (id) payload.id = parseInt(id);
            try {
                const response = await fetch(API_BASE + 'save_contact.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) { closeModal(); loadContacts(); }
                else alert('Error: ' + data.error);
            } catch (error) { alert('Failed to save contact'); }
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
