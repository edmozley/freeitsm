<?php
/**
 * Software Licences - Manage software licence records
 */
session_start();
require_once '../../config.php';

$current_page = 'licences';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Software Licences</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .licence-container {
            display: flex;
            flex-direction: column;
            flex: 1;
            overflow: hidden;
            background-color: #fff;
        }

        .licence-toolbar {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            background-color: #f8f9fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .licence-toolbar h3 {
            margin: 0;
            font-size: 16px;
            color: #333;
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .search-box {
            width: 300px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .search-box:focus {
            outline: none;
            border-color: #5c6bc0;
            box-shadow: 0 0 0 2px rgba(92, 107, 192, 0.15);
        }

        .licence-count {
            font-size: 13px;
            color: #888;
            white-space: nowrap;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.15s;
        }

        .btn-primary {
            background: #5c6bc0;
            color: #fff;
        }

        .btn-primary:hover {
            background: #3f51b5;
        }

        .btn-secondary {
            background: #f5f5f5;
            color: #333;
            border: 1px solid #ddd;
        }

        .btn-secondary:hover {
            background: #eee;
        }

        .btn-danger {
            background: #dc3545;
            color: #fff;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-export {
            background: #5c6bc0;
            color: white;
            font-size: 13px;
            padding: 8px 14px;
        }

        .btn-export:hover {
            background: #3f51b5;
        }

        .licence-table-container {
            flex: 1;
            overflow-y: auto;
        }

        .licence-table {
            width: 100%;
            border-collapse: collapse;
        }

        .licence-table thead th {
            position: sticky;
            top: 0;
            background-color: #f8f9fa;
            padding: 12px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            user-select: none;
            z-index: 1;
        }

        .licence-table thead th:hover {
            background-color: #eee;
        }

        .licence-table thead th.sort-active {
            color: #5c6bc0;
        }

        .licence-table thead th .sort-icon {
            margin-left: 4px;
            font-size: 10px;
        }

        .licence-table tbody tr {
            cursor: pointer;
            transition: background-color 0.15s;
        }

        .licence-table tbody tr:hover {
            background-color: #f5f5f5;
        }

        .licence-table tbody td {
            padding: 10px 20px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
            color: #333;
        }

        .type-badge {
            display: inline-block;
            background-color: #e8eaf6;
            color: #3f51b5;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-active {
            display: inline-block;
            background: #e8f5e9;
            color: #2e7d32;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-expired {
            display: inline-block;
            background: #f5f5f5;
            color: #999;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-cancelled {
            display: inline-block;
            background: #fce4ec;
            color: #c62828;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .renewal-overdue {
            color: #dc3545;
            font-weight: 600;
        }

        .renewal-warning {
            color: #f57c00;
            font-weight: 600;
        }

        .renewal-ok {
            color: #2e7d32;
        }

        /* Modal overlay */
        .detail-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: flex-start;
            justify-content: center;
            padding: 60px 20px;
        }

        .detail-overlay.open {
            display: flex;
        }

        .detail-box {
            background: #fff;
            border-radius: 8px;
            width: 100%;
            max-width: 650px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e0e0e0;
            background: linear-gradient(135deg, #5c6bc0, #3f51b5);
            border-radius: 8px 8px 0 0;
            color: white;
        }

        .detail-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        .detail-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            width: 32px;
            height: 32px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .detail-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .detail-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-row {
            display: flex;
            gap: 16px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-bottom: 6px;
        }

        .form-input,
        .form-textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
            font-family: inherit;
        }

        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #5c6bc0;
            box-shadow: 0 0 0 2px rgba(92, 107, 192, 0.15);
        }

        .form-textarea {
            resize: vertical;
            min-height: 70px;
        }

        .modal-footer {
            padding: 15px 24px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
        }

        .modal-footer-right {
            display: flex;
            gap: 10px;
        }

        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px;
        }

        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #5c6bc0;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px;
            color: #888;
            font-size: 14px;
            gap: 12px;
        }

        .empty-state svg {
            color: #ccc;
        }

        .publisher-text {
            font-size: 12px;
            color: #888;
        }

        .cost-text {
            font-weight: 500;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container licence-container">
        <div class="licence-toolbar">
            <h3>Software Licences</h3>
            <div class="toolbar-right">
                <input type="text" class="search-box" id="licenceSearch"
                       placeholder="Search licences..." autocomplete="off"
                       oninput="searchLicences()">
                <span class="licence-count" id="licenceCount"></span>
                <button class="btn btn-export" onclick="exportLicencesCSV()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export CSV
                </button>
                <button class="btn btn-primary" onclick="openLicenceModal()">+ Add Licence</button>
            </div>
        </div>
        <div class="licence-table-container">
            <table class="licence-table">
                <thead>
                    <tr>
                        <th onclick="sortBy('app_name')" id="thApp">
                            Application <span class="sort-icon">&#9650;</span>
                        </th>
                        <th onclick="sortBy('licence_type')" id="thType">
                            Type <span class="sort-icon"></span>
                        </th>
                        <th onclick="sortBy('quantity')" id="thQty">
                            Qty <span class="sort-icon"></span>
                        </th>
                        <th onclick="sortBy('renewal_date')" id="thRenewal">
                            Renewal Date <span class="sort-icon"></span>
                        </th>
                        <th onclick="sortBy('status')" id="thStatus">
                            Status <span class="sort-icon"></span>
                        </th>
                        <th onclick="sortBy('cost')" id="thCost">
                            Cost <span class="sort-icon"></span>
                        </th>
                    </tr>
                </thead>
                <tbody id="licenceTableBody">
                    <tr><td colspan="6">
                        <div class="loading-spinner"><div class="spinner"></div></div>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit Licence Modal -->
    <div class="detail-overlay" id="licenceOverlay" onclick="if(event.target===this)closeLicenceModal()">
        <div class="detail-box">
            <div class="detail-header">
                <h3 id="licenceModalTitle">Add Licence</h3>
                <button class="detail-close" onclick="closeLicenceModal()">&times;</button>
            </div>
            <div class="detail-body">
                <input type="hidden" id="licenceId" value="">

                <div class="form-group">
                    <label class="form-label">Application *</label>
                    <select class="form-input" id="licenceAppId">
                        <option value="">-- Select application --</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Licence Type *</label>
                        <select class="form-input" id="licenceType">
                            <option value="">-- Select type --</option>
                            <option value="Per User">Per User</option>
                            <option value="Per Device">Per Device</option>
                            <option value="Site">Site</option>
                            <option value="Concurrent">Concurrent</option>
                            <option value="Subscription">Subscription</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-input" id="licenceQuantity" min="0" placeholder="e.g. 50">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Licence Key</label>
                    <input type="text" class="form-input" id="licenceKey" placeholder="Licence key or serial number">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Cost</label>
                        <input type="number" class="form-input" id="licenceCost" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Currency</label>
                        <select class="form-input" id="licenceCurrency">
                            <option value="GBP">GBP (£)</option>
                            <option value="USD">USD ($)</option>
                            <option value="EUR">EUR (€)</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" class="form-input" id="licencePurchaseDate">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Renewal Date</label>
                        <input type="date" class="form-input" id="licenceRenewalDate">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Notice Period (days)</label>
                        <input type="number" class="form-input" id="licenceNoticeDays" min="0" placeholder="e.g. 30">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-input" id="licenceStatus">
                            <option value="Active">Active</option>
                            <option value="Expired">Expired</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Portal URL</label>
                    <input type="url" class="form-input" id="licencePortalUrl" placeholder="https://...">
                </div>

                <div class="form-group">
                    <label class="form-label">Vendor Contact</label>
                    <input type="text" class="form-input" id="licenceVendorContact" placeholder="Contact name, email, or phone">
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea class="form-textarea" id="licenceNotes" rows="3" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger" id="deleteLicenceBtn" onclick="deleteLicence()" style="display: none;">Delete</button>
                <div class="modal-footer-right">
                    <button class="btn btn-secondary" onclick="closeLicenceModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="saveLicence()">Save</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/software/';
        let allLicences = [];
        let filteredLicences = [];
        let allApps = [];
        let searchTimeout = null;
        let sortColumn = 'app_name';
        let sortDirection = 'asc';

        document.addEventListener('DOMContentLoaded', function() {
            loadApps();
            loadLicences();
        });

        async function loadApps() {
            try {
                const response = await fetch(API_BASE + 'get_apps.php');
                const data = await response.json();
                if (data.success) {
                    allApps = data.apps;
                }
            } catch (error) {
                console.error('Error loading apps:', error);
            }
        }

        async function loadLicences() {
            try {
                const response = await fetch(API_BASE + 'get_licences.php');
                const data = await response.json();
                if (data.success) {
                    allLicences = data.licences;
                    filteredLicences = [...allLicences];
                    applySortAndRender();
                } else {
                    document.getElementById('licenceTableBody').innerHTML =
                        '<tr><td colspan="6"><div class="empty-state">Error: ' + escapeHtml(data.error) + '</div></td></tr>';
                }
            } catch (error) {
                console.error('Error loading licences:', error);
                document.getElementById('licenceTableBody').innerHTML =
                    '<tr><td colspan="6"><div class="empty-state">Failed to load licence data</div></td></tr>';
            }
        }

        function searchLicences() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const search = document.getElementById('licenceSearch').value.toLowerCase().trim();
                if (search === '') {
                    filteredLicences = [...allLicences];
                } else {
                    filteredLicences = allLicences.filter(l =>
                        (l.app_name || '').toLowerCase().includes(search) ||
                        (l.app_publisher || '').toLowerCase().includes(search) ||
                        (l.licence_type || '').toLowerCase().includes(search) ||
                        (l.licence_key || '').toLowerCase().includes(search) ||
                        (l.vendor_contact || '').toLowerCase().includes(search) ||
                        (l.notes || '').toLowerCase().includes(search)
                    );
                }
                applySortAndRender();
            }, 300);
        }

        function sortBy(column) {
            if (sortColumn === column) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                sortColumn = column;
                sortDirection = 'asc';
            }
            applySortAndRender();
        }

        function applySortAndRender() {
            filteredLicences.sort((a, b) => {
                let valA, valB;
                if (sortColumn === 'quantity' || sortColumn === 'cost') {
                    valA = parseFloat(a[sortColumn]) || 0;
                    valB = parseFloat(b[sortColumn]) || 0;
                } else {
                    valA = (a[sortColumn] || '').toString().toLowerCase();
                    valB = (b[sortColumn] || '').toString().toLowerCase();
                }
                if (valA < valB) return sortDirection === 'asc' ? -1 : 1;
                if (valA > valB) return sortDirection === 'asc' ? 1 : -1;
                return 0;
            });
            renderTable();
            updateSortIndicators();
        }

        function renderTable() {
            const tbody = document.getElementById('licenceTableBody');
            const countEl = document.getElementById('licenceCount');

            countEl.textContent = filteredLicences.length + ' licence' + (filteredLicences.length !== 1 ? 's' : '');

            if (filteredLicences.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6"><div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path>
                    </svg>
                    No licences found
                </div></td></tr>`;
                return;
            }

            tbody.innerHTML = filteredLicences.map(l => {
                const renewalClass = getRenewalClass(l.renewal_date, l.notice_period_days);
                const statusClass = 'status-' + (l.status || 'active').toLowerCase();
                return `
                <tr onclick="openLicenceModal(${l.id})">
                    <td>
                        ${escapeHtml(l.app_name)}
                        ${l.app_publisher ? '<div class="publisher-text">' + escapeHtml(l.app_publisher) + '</div>' : ''}
                    </td>
                    <td><span class="type-badge">${escapeHtml(l.licence_type)}</span></td>
                    <td>${l.quantity != null ? escapeHtml(String(l.quantity)) : '\u2014'}</td>
                    <td class="${renewalClass}">${l.renewal_date ? formatDate(l.renewal_date) : '\u2014'}</td>
                    <td><span class="${statusClass}">${escapeHtml(l.status)}</span></td>
                    <td class="cost-text">${formatCost(l.cost, l.currency)}</td>
                </tr>`;
            }).join('');
        }

        function getRenewalClass(renewalDate, noticeDays) {
            if (!renewalDate) return '';
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const renewal = new Date(renewalDate + 'T00:00:00');
            if (renewal < today) return 'renewal-overdue';
            const notice = noticeDays || 30;
            const warningDate = new Date(renewal);
            warningDate.setDate(warningDate.getDate() - notice);
            if (today >= warningDate) return 'renewal-warning';
            return 'renewal-ok';
        }

        function formatCost(cost, currency) {
            if (cost == null || cost === '') return '\u2014';
            const symbols = { GBP: '\u00A3', USD: '$', EUR: '\u20AC' };
            const symbol = symbols[currency] || currency + ' ';
            return symbol + parseFloat(cost).toFixed(2);
        }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d)) return dateStr;
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }

        function openLicenceModal(licenceId) {
            // Reset form
            document.getElementById('licenceId').value = '';
            document.getElementById('licenceAppId').value = '';
            document.getElementById('licenceType').value = '';
            document.getElementById('licenceQuantity').value = '';
            document.getElementById('licenceKey').value = '';
            document.getElementById('licenceCost').value = '';
            document.getElementById('licenceCurrency').value = 'GBP';
            document.getElementById('licencePurchaseDate').value = '';
            document.getElementById('licenceRenewalDate').value = '';
            document.getElementById('licenceNoticeDays').value = '';
            document.getElementById('licenceStatus').value = 'Active';
            document.getElementById('licencePortalUrl').value = '';
            document.getElementById('licenceVendorContact').value = '';
            document.getElementById('licenceNotes').value = '';
            document.getElementById('deleteLicenceBtn').style.display = 'none';

            // Populate app dropdown
            const appSelect = document.getElementById('licenceAppId');
            appSelect.innerHTML = '<option value="">-- Select application --</option>';
            allApps.forEach(app => {
                const opt = document.createElement('option');
                opt.value = app.id;
                opt.textContent = app.display_name + (app.publisher ? ' (' + app.publisher + ')' : '');
                appSelect.appendChild(opt);
            });

            if (licenceId) {
                // Edit mode
                document.getElementById('licenceModalTitle').textContent = 'Edit Licence';
                const licence = allLicences.find(l => l.id == licenceId);
                if (licence) {
                    document.getElementById('licenceId').value = licence.id;
                    document.getElementById('licenceAppId').value = licence.app_id;
                    document.getElementById('licenceType').value = licence.licence_type;
                    document.getElementById('licenceQuantity').value = licence.quantity != null ? licence.quantity : '';
                    document.getElementById('licenceKey').value = licence.licence_key || '';
                    document.getElementById('licenceCost').value = licence.cost != null ? licence.cost : '';
                    document.getElementById('licenceCurrency').value = licence.currency || 'GBP';
                    document.getElementById('licencePurchaseDate').value = licence.purchase_date || '';
                    document.getElementById('licenceRenewalDate').value = licence.renewal_date || '';
                    document.getElementById('licenceNoticeDays').value = licence.notice_period_days != null ? licence.notice_period_days : '';
                    document.getElementById('licenceStatus').value = licence.status || 'Active';
                    document.getElementById('licencePortalUrl').value = licence.portal_url || '';
                    document.getElementById('licenceVendorContact').value = licence.vendor_contact || '';
                    document.getElementById('licenceNotes').value = licence.notes || '';
                    document.getElementById('deleteLicenceBtn').style.display = 'inline-flex';
                }
            } else {
                document.getElementById('licenceModalTitle').textContent = 'Add Licence';
            }

            document.getElementById('licenceOverlay').classList.add('open');
        }

        function closeLicenceModal() {
            document.getElementById('licenceOverlay').classList.remove('open');
        }

        async function saveLicence() {
            const appId = document.getElementById('licenceAppId').value;
            const licenceType = document.getElementById('licenceType').value;

            if (!appId) {
                alert('Please select an application.');
                return;
            }
            if (!licenceType) {
                alert('Please select a licence type.');
                return;
            }

            const payload = {
                id: document.getElementById('licenceId').value || null,
                app_id: parseInt(appId),
                licence_type: licenceType,
                licence_key: document.getElementById('licenceKey').value,
                quantity: document.getElementById('licenceQuantity').value,
                cost: document.getElementById('licenceCost').value,
                currency: document.getElementById('licenceCurrency').value,
                purchase_date: document.getElementById('licencePurchaseDate').value,
                renewal_date: document.getElementById('licenceRenewalDate').value,
                notice_period_days: document.getElementById('licenceNoticeDays').value,
                status: document.getElementById('licenceStatus').value,
                portal_url: document.getElementById('licencePortalUrl').value,
                vendor_contact: document.getElementById('licenceVendorContact').value,
                notes: document.getElementById('licenceNotes').value
            };

            try {
                const response = await fetch(API_BASE + 'save_licence.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeLicenceModal();
                    loadLicences();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error saving licence:', error);
                alert('Failed to save licence.');
            }
        }

        async function deleteLicence() {
            const id = document.getElementById('licenceId').value;
            if (!id) return;
            if (!confirm('Are you sure you want to delete this licence?')) return;

            try {
                const response = await fetch(API_BASE + 'delete_licence.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: parseInt(id) })
                });
                const data = await response.json();
                if (data.success) {
                    closeLicenceModal();
                    loadLicences();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error deleting licence:', error);
                alert('Failed to delete licence.');
            }
        }

        function exportLicencesCSV() {
            if (!filteredLicences.length) return;

            const headers = ['Application', 'Publisher', 'Type', 'Quantity', 'Licence Key',
                'Renewal Date', 'Notice Period (days)', 'Status', 'Cost', 'Currency',
                'Purchase Date', 'Portal URL', 'Vendor Contact', 'Notes'];
            const rows = [headers.map(h => csvCell(h)).join(',')];

            filteredLicences.forEach(l => {
                rows.push([
                    csvCell(l.app_name || ''),
                    csvCell(l.app_publisher || ''),
                    csvCell(l.licence_type || ''),
                    csvCell(l.quantity != null ? String(l.quantity) : ''),
                    csvCell(l.licence_key || ''),
                    csvCell(l.renewal_date || ''),
                    csvCell(l.notice_period_days != null ? String(l.notice_period_days) : ''),
                    csvCell(l.status || ''),
                    csvCell(l.cost != null ? String(l.cost) : ''),
                    csvCell(l.currency || ''),
                    csvCell(l.purchase_date || ''),
                    csvCell(l.portal_url || ''),
                    csvCell(l.vendor_contact || ''),
                    csvCell(l.notes || '')
                ].join(','));
            });

            const csv = '\uFEFF' + rows.join('\r\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'Software Licences.csv';
            a.click();
            URL.revokeObjectURL(url);
        }

        function csvCell(text) {
            text = String(text);
            if (text.includes(',') || text.includes('"') || text.includes('\n')) {
                return '"' + text.replace(/"/g, '""') + '"';
            }
            return text;
        }

        function updateSortIndicators() {
            const columns = {
                'app_name': 'thApp',
                'licence_type': 'thType',
                'quantity': 'thQty',
                'renewal_date': 'thRenewal',
                'status': 'thStatus',
                'cost': 'thCost'
            };

            Object.entries(columns).forEach(([col, id]) => {
                const th = document.getElementById(id);
                const icon = th.querySelector('.sort-icon');
                if (col === sortColumn) {
                    th.classList.add('sort-active');
                    icon.textContent = sortDirection === 'asc' ? '\u25B2' : '\u25BC';
                } else {
                    th.classList.remove('sort-active');
                    icon.textContent = '';
                }
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeLicenceModal();
        });
    </script>
</body>
</html>
