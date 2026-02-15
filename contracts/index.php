<?php
/**
 * Contracts Module - Dashboard with sidebar
 */
session_start();
require_once '../config.php';

$current_page = 'dashboard';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Contracts</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        /* Sidebar layout */
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

        .sidebar-search-btn {
            width: 100%;
            padding: 10px 16px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            color: #888;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .sidebar-search-btn:hover { border-color: #f59e0b; color: #333; }
        .sidebar-search-btn svg { width: 16px; height: 16px; flex-shrink: 0; }

        .sidebar-stat {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 14px;
            color: #333;
            cursor: default;
            margin-bottom: 4px;
        }
        .sidebar-stat .stat-value {
            font-weight: 700;
            font-size: 16px;
        }
        .sidebar-stat.warning .stat-value { color: #f59e0b; }

        .sidebar-links { display: flex; flex-direction: column; gap: 4px; }
        .sidebar-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 6px;
            font-size: 14px; color: #333;
            text-decoration: none; transition: all 0.15s;
        }
        .sidebar-link:hover { background: #fff7ed; color: #f59e0b; }
        .sidebar-link svg { width: 18px; height: 18px; flex-shrink: 0; }

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

        /* Main content */
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

        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.expired { background: #f8d7da; color: #721c24; }
        .status-badge.expiring { background: #fff3cd; color: #856404; }

        .action-btn {
            background: none; border: 1px solid #ddd; color: #666; cursor: pointer;
            padding: 6px; margin-right: 4px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;
        }
        .action-btn:hover { background: #f0f0f0; border-color: #f59e0b; color: #f59e0b; }
        .action-btn svg { width: 16px; height: 16px; }

        .empty-state { text-align: center; padding: 40px; color: #999; }

        /* Search Modal - amber overrides */
        .search-modal-header { background: #f59e0b; }
        .search-field input:focus { border-color: #f59e0b; }

        .search-result-item { cursor: pointer; }
        .search-result-item:hover { background: #fff7ed; border-color: #f59e0b; }
        .search-result-type {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;
            margin-right: 8px;
        }
        .search-result-type.contract { background: #fff3cd; color: #856404; }
        .search-result-type.supplier { background: #d4edda; color: #155724; }
        .search-result-type.contact { background: #d6e9f8; color: #1a5276; }
        .search-result-title { font-weight: 600; color: #333; font-size: 14px; }
        .search-result-meta { font-size: 12px; color: #888; margin-top: 4px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="contracts-layout">
        <!-- Left Sidebar -->
        <div class="contracts-sidebar">
            <div class="sidebar-section">
                <button class="sidebar-search-btn" onclick="openSearchModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    Search...
                </button>
            </div>

            <div class="sidebar-section">
                <h3>Overview</h3>
                <div class="sidebar-stat">
                    <span>Contracts</span>
                    <span class="stat-value" id="sideContracts">-</span>
                </div>
                <div class="sidebar-stat">
                    <span>Active</span>
                    <span class="stat-value" id="sideActive">-</span>
                </div>
                <div class="sidebar-stat warning">
                    <span>Expiring (90d)</span>
                    <span class="stat-value" id="sideExpiring">-</span>
                </div>
                <div class="sidebar-stat">
                    <span>Suppliers</span>
                    <span class="stat-value" id="sideSuppliers">-</span>
                </div>
                <div class="sidebar-stat">
                    <span>Contacts</span>
                    <span class="stat-value" id="sideContacts">-</span>
                </div>
            </div>

            <div class="sidebar-section">
                <h3>Quick Links</h3>
                <div class="sidebar-links">
                    <a href="suppliers/" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                        Suppliers
                    </a>
                    <a href="contacts/" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        Contacts
                    </a>
                    <a href="settings/" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        Settings
                    </a>
                </div>
            </div>

            <div class="sidebar-section">
                <a href="edit.php" class="sidebar-add-btn">+ New Contract</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="contracts-main">
            <div class="section-card">
                <div class="section-header">
                    <h2>Contracts</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Contract #</th>
                            <th>Title</th>
                            <th>Supplier</th>
                            <th>Owner</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="contractsList">
                        <tr><td colspan="7" class="empty-state">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Search Modal -->
    <div class="search-modal" id="searchModal">
        <div class="search-modal-header">
            <span>Search Contracts Module</span>
            <button class="search-modal-close" onclick="closeSearchModal()">&times;</button>
        </div>
        <div class="search-modal-body">
            <div class="search-field">
                <input type="text" id="searchInput" placeholder="Search contracts, suppliers, contacts..." oninput="debounceSearch()">
            </div>
            <div id="searchResults" style="margin-top: 16px;"></div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/contracts/';
        let searchTimer = null;

        document.addEventListener('DOMContentLoaded', function() {
            loadStats();
            loadContracts();
        });

        // Stats
        async function loadStats() {
            try {
                const response = await fetch(API_BASE + 'get_dashboard_stats.php');
                const data = await response.json();
                if (data.success) {
                    document.getElementById('sideContracts').textContent = data.stats.contracts;
                    document.getElementById('sideActive').textContent = data.stats.active_contracts;
                    document.getElementById('sideExpiring').textContent = data.stats.expiring_soon;
                    document.getElementById('sideSuppliers').textContent = data.stats.suppliers;
                    document.getElementById('sideContacts').textContent = data.stats.contacts;
                }
            } catch (error) { console.error('Error loading stats:', error); }
        }

        // Contract list
        async function loadContracts() {
            try {
                const response = await fetch(API_BASE + 'get_contracts.php');
                const data = await response.json();
                if (data.success) {
                    renderContracts(data.contracts);
                } else {
                    document.getElementById('contractsList').innerHTML =
                        '<tr><td colspan="7" class="empty-state" style="color:#d13438;">Error: ' + escapeHtml(data.error) + '</td></tr>';
                }
            } catch (error) {
                document.getElementById('contractsList').innerHTML =
                    '<tr><td colspan="7" class="empty-state" style="color:#d13438;">Failed to load contracts</td></tr>';
            }
        }

        function renderContracts(contracts) {
            const tbody = document.getElementById('contractsList');
            if (contracts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No contracts yet. Click "+ New Contract" to create one.</td></tr>';
                return;
            }
            tbody.innerHTML = contracts.map(c => {
                const status = getContractStatus(c);
                return `
                    <tr>
                        <td><strong>${escapeHtml(c.contract_number)}</strong></td>
                        <td>${escapeHtml(c.title)}</td>
                        <td>${escapeHtml(c.supplier_name || '-')}</td>
                        <td>${escapeHtml(c.owner_name || '-')}</td>
                        <td>${formatDate(c.contract_end)}</td>
                        <td><span class="status-badge ${status.class}">${status.label}</span></td>
                        <td>
                            <a href="view.php?id=${c.id}" class="action-btn" title="View">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </a>
                            <a href="edit.php?id=${c.id}" class="action-btn" title="Edit">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </a>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function getContractStatus(c) {
            if (c.contract_status_name) return { class: 'active', label: c.contract_status_name };
            if (!c.is_active) return { class: 'expired', label: 'Inactive' };
            if (!c.contract_end) return { class: 'active', label: 'Active' };
            const end = new Date(c.contract_end);
            const today = new Date(); today.setHours(0,0,0,0);
            const daysLeft = Math.ceil((end - today) / (1000*60*60*24));
            if (daysLeft < 0) return { class: 'expired', label: 'Expired' };
            if (daysLeft <= 90) return { class: 'expiring', label: 'Expiring' };
            return { class: 'active', label: 'Active' };
        }

        // Search
        function openSearchModal() {
            document.getElementById('searchModal').classList.add('active');
            document.getElementById('searchInput').value = '';
            document.getElementById('searchResults').innerHTML = '';
            setTimeout(() => document.getElementById('searchInput').focus(), 100);
        }

        function closeSearchModal() {
            document.getElementById('searchModal').classList.remove('active');
        }

        function debounceSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(performSearch, 300);
        }

        async function performSearch() {
            const query = document.getElementById('searchInput').value.trim();
            const resultsDiv = document.getElementById('searchResults');

            if (query.length < 2) {
                resultsDiv.innerHTML = '<div class="search-results-empty">Type at least 2 characters to search</div>';
                return;
            }

            resultsDiv.innerHTML = '<div class="search-results-empty">Searching...</div>';

            try {
                const [contractsRes, suppliersRes, contactsRes] = await Promise.all([
                    fetch(API_BASE + 'get_contracts.php?search=' + encodeURIComponent(query)),
                    fetch(API_BASE + 'get_suppliers.php?search=' + encodeURIComponent(query)),
                    fetch(API_BASE + 'get_contacts.php?search=' + encodeURIComponent(query))
                ]);

                const [contractsData, suppliersData, contactsData] = await Promise.all([
                    contractsRes.json(), suppliersRes.json(), contactsRes.json()
                ]);

                let results = [];

                if (contractsData.success) {
                    contractsData.contracts.forEach(c => {
                        results.push({
                            type: 'contract',
                            title: c.contract_number + ' — ' + c.title,
                            meta: [c.supplier_name, c.owner_name].filter(Boolean).join(' | ') || 'No supplier',
                            url: 'view.php?id=' + c.id
                        });
                    });
                }

                if (suppliersData.success) {
                    const q = query.toLowerCase();
                    suppliersData.suppliers.filter(s =>
                        (s.legal_name && s.legal_name.toLowerCase().includes(q)) ||
                        (s.trading_name && s.trading_name.toLowerCase().includes(q)) ||
                        (s.city && s.city.toLowerCase().includes(q))
                    ).forEach(s => {
                        results.push({
                            type: 'supplier',
                            title: s.legal_name + (s.trading_name ? ' (t/a ' + s.trading_name + ')' : ''),
                            meta: [s.supplier_type_name, s.city].filter(Boolean).join(' | ') || 'Supplier',
                            url: 'suppliers/'
                        });
                    });
                }

                if (contactsData.success) {
                    const q = query.toLowerCase();
                    contactsData.contacts.filter(c =>
                        (c.first_name + ' ' + c.surname).toLowerCase().includes(q) ||
                        (c.email && c.email.toLowerCase().includes(q)) ||
                        (c.job_title && c.job_title.toLowerCase().includes(q))
                    ).forEach(c => {
                        results.push({
                            type: 'contact',
                            title: c.first_name + ' ' + c.surname + (c.job_title ? ' — ' + c.job_title : ''),
                            meta: [c.supplier_name, c.email].filter(Boolean).join(' | ') || 'Contact',
                            url: 'contacts/'
                        });
                    });
                }

                if (results.length === 0) {
                    resultsDiv.innerHTML = '<div class="search-results-empty">No results found</div>';
                    return;
                }

                resultsDiv.innerHTML =
                    '<div class="search-results-count">' + results.length + ' result' + (results.length !== 1 ? 's' : '') + '</div>' +
                    '<div class="search-results">' +
                    results.map(r => `
                        <div class="search-result-item" onclick="window.location.href='${r.url}'">
                            <div>
                                <span class="search-result-type ${r.type}">${r.type}</span>
                                <span class="search-result-title">${escapeHtml(r.title)}</span>
                            </div>
                            <div class="search-result-meta">${escapeHtml(r.meta)}</div>
                        </div>
                    `).join('') +
                    '</div>';

            } catch (error) {
                resultsDiv.innerHTML = '<div class="search-results-empty" style="color:#d13438;">Search failed</div>';
            }
        }

        // Close search modal on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSearchModal();
        });

        // Helpers
        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
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
