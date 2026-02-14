<?php
/**
 * Assets - View and manage IT assets and their user assignments
 */
session_start();
require_once '../config.php';

$current_page = 'assets';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Assets</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .assets-container {
            display: flex;
            flex: 1;
            overflow: hidden;
            gap: 1px;
            background-color: #e0e0e0;
        }

        .assets-list-container {
            width: 400px;
            min-width: 300px;
            background-color: #fff;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .assets-list-header {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            background-color: #f8f9fa;
        }

        .assets-list-header h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #333;
        }

        .search-box {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .search-box:focus {
            outline: none;
            border-color: #0078d4;
            box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.1);
        }

        .assets-list {
            flex: 1;
            overflow-y: auto;
        }

        .asset-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background-color 0.15s;
        }

        .asset-item:hover {
            background-color: #f5f5f5;
        }

        .asset-item.selected {
            background-color: #e8f4fc;
            border-left: 3px solid #0078d4;
        }

        .asset-hostname {
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
            font-family: monospace;
            font-size: 14px;
        }

        .asset-meta {
            font-size: 12px;
            color: #888;
            display: flex;
            gap: 15px;
        }

        .asset-assigned {
            color: #2e7d32;
        }

        .asset-unassigned {
            color: #888;
        }

        .asset-detail-container {
            flex: 1;
            background-color: #fff;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .asset-detail-sticky {
            flex-shrink: 0;
        }

        .asset-detail-scroll {
            flex: 1;
            overflow-y: auto;
        }

        .asset-detail-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
            background-color: #f8f9fa;
        }

        .asset-detail-hostname {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            margin: 0 0 4px 0;
        }

        .asset-detail-subtitle {
            font-size: 14px;
            color: #666;
            margin: 0;
        }

        .asset-assigned-bar {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e0e0e0;
        }

        .asset-assigned-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
            min-width: 0;
        }

        .asset-assigned-info .user-name {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .asset-assigned-info .user-email {
            color: #666;
            font-size: 13px;
        }

        .asset-assigned-info .user-assigned-date {
            color: #999;
            font-size: 12px;
        }

        .asset-assigned-info .unassigned-text {
            color: #999;
            font-size: 13px;
            font-style: italic;
        }

        #assignButtons {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .asset-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 12px;
            color: #888;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .info-value {
            font-size: 14px;
            color: #333;
        }

        .info-value-select {
            font-size: 14px;
            color: #333;
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
            cursor: pointer;
            max-width: 200px;
        }

        .info-value-select:focus {
            outline: none;
            border-color: #107c10;
            box-shadow: 0 0 0 2px rgba(16, 124, 16, 0.1);
        }

        .assigned-users-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .section-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            background-color: #f8f9fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-title {
            font-weight: 600;
            color: #333;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: background-color 0.15s;
        }

        .btn-primary {
            background-color: #0078d4;
            color: white;
        }

        .btn-primary:hover {
            background-color: #106ebe;
        }

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 12px;
        }

        .assigned-users-list {
            flex: 1;
            overflow-y: auto;
        }

        .user-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid #eee;
        }

        .user-row:hover {
            background-color: #f5f5f5;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 500;
            color: #333;
        }

        .user-email {
            font-size: 13px;
            color: #666;
        }

        .user-assigned-date {
            font-size: 12px;
            color: #888;
            margin-top: 2px;
        }

        .empty-state {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            color: #888;
            font-size: 14px;
            padding: 40px;
            text-align: center;
        }

        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #0078d4;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .asset-count {
            font-size: 12px;
            color: #888;
            margin-top: 8px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 8px;
            width: 500px;
            max-width: 90%;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            line-height: 1;
        }

        .modal-close:hover {
            color: #333;
        }

        .modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }

        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-select:focus {
            outline: none;
            border-color: #0078d4;
        }

        .user-search-results {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-top: 10px;
        }

        .user-search-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }

        .user-search-item:last-child {
            border-bottom: none;
        }

        .user-search-item:hover {
            background-color: #f5f5f5;
        }

        .user-search-item.selected {
            background-color: #e8f4fc;
        }

        .user-search-name {
            font-weight: 500;
        }

        .user-search-email {
            font-size: 13px;
            color: #666;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        /* Installed Software Section */
        .software-section {
            border-top: 1px solid #e0e0e0;
        }

        .software-list {
            padding: 0;
        }

        .software-table {
            width: 100%;
            border-collapse: collapse;
        }

        .software-table thead th {
            position: sticky;
            top: 0;
            background-color: #f0f0f0;
            padding: 8px 20px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            z-index: 1;
        }

        .software-table tbody td {
            padding: 7px 20px;
            font-size: 13px;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
        }

        .software-table tbody tr:hover {
            background-color: #f9f9f9;
        }

        .software-count-badge {
            display: inline-block;
            background-color: #e8eaf6;
            color: #3f51b5;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container assets-container">
        <!-- Assets List -->
        <div class="assets-list-container">
            <div class="assets-list-header">
                <h3>Assets</h3>
                <input type="text" class="search-box" id="assetSearch" placeholder="Search by hostname..." oninput="searchAssets()" autocomplete="off">
                <div class="asset-count" id="assetCount"></div>
            </div>
            <div class="assets-list" id="assetsList">
                <div class="loading">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>

        <!-- Asset Detail -->
        <div class="asset-detail-container" id="assetDetail">
            <div class="empty-state">
                Select an asset to view details and assigned users
            </div>
        </div>
    </div>

    <!-- Assign User Modal -->
    <div class="modal" id="assignUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Assign User to Asset</span>
                <button class="modal-close" onclick="closeAssignModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Search for User</label>
                    <input type="text" class="search-box" id="userSearchInput" placeholder="Search by name or email..." oninput="searchUsersForAssign()">
                </div>
                <div class="user-search-results" id="userSearchResults">
                    <div class="empty-state" style="padding: 20px;">Type to search for users</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeAssignModal()">Cancel</button>
                <button class="btn btn-primary" onclick="confirmAssignUser()" id="assignBtn" disabled>Assign User</button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/assets/';
        const API_TICKETS = '../api/tickets/';
        let assets = [];
        let selectedAssetId = null;
        let selectedAsset = null;
        let searchTimeout = null;
        let selectedUserForAssign = null;
        let currentAssignedUserId = null;
        let assetTypes = [];
        let assetStatusTypes = [];

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadAssets();
            loadAssetTypesForDropdown();
            loadAssetStatusTypesForDropdown();
        });

        async function loadAssetTypesForDropdown() {
            try {
                const response = await fetch(API_BASE + 'get_asset_types.php');
                const data = await response.json();
                if (data.success) assetTypes = data.asset_types.filter(t => t.is_active);
            } catch (e) { console.error('Error loading asset types:', e); }
        }

        async function loadAssetStatusTypesForDropdown() {
            try {
                const response = await fetch(API_BASE + 'get_asset_status_types.php');
                const data = await response.json();
                if (data.success) assetStatusTypes = data.asset_status_types.filter(t => t.is_active);
            } catch (e) { console.error('Error loading asset status types:', e); }
        }

        async function updateAssetField(field, value) {
            if (!selectedAssetId) return;
            try {
                const response = await fetch(API_BASE + 'update_asset_field.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        asset_id: selectedAssetId,
                        field: field,
                        value: value || null
                    })
                });
                const data = await response.json();
                if (data.success) {
                    const asset = assets.find(a => a.id == selectedAssetId);
                    if (asset) asset[field] = value || null;
                } else {
                    alert('Error updating asset: ' + data.error);
                }
            } catch (error) {
                console.error('Error updating asset:', error);
            }
        }

        // Load assets from API
        async function loadAssets(search = '') {
            try {
                const url = search ? `${API_BASE}get_assets.php?search=${encodeURIComponent(search)}` : API_BASE + 'get_assets.php';
                const response = await fetch(url);
                const data = await response.json();

                if (data.success) {
                    assets = data.assets;
                    renderAssetsList();
                } else {
                    console.error('Error loading assets:', data.error);
                }
            } catch (error) {
                console.error('Error loading assets:', error);
            }
        }

        // Render assets list
        function renderAssetsList() {
            const container = document.getElementById('assetsList');
            const countEl = document.getElementById('assetCount');

            if (assets.length === 0) {
                container.innerHTML = '<div class="empty-state">No assets found</div>';
                countEl.textContent = '0 assets';
                return;
            }

            countEl.textContent = `${assets.length} asset${assets.length !== 1 ? 's' : ''}`;

            container.innerHTML = assets.map(asset => `
                <div class="asset-item ${selectedAssetId == asset.id ? 'selected' : ''}" onclick="selectAsset(${asset.id})">
                    <div class="asset-hostname">${escapeHtml(asset.hostname)}</div>
                    <div class="asset-meta">
                        <span class="${asset.user_count > 0 ? 'asset-assigned' : 'asset-unassigned'}">
                            ${asset.user_count > 0 ? 'Assigned' : 'Unassigned'}
                        </span>
                    </div>
                </div>
            `).join('');
        }

        // Search assets with debounce
        function searchAssets() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const search = document.getElementById('assetSearch').value;
                loadAssets(search);
            }, 300);
        }

        // Select an asset and show details
        async function selectAsset(assetId) {
            selectedAssetId = assetId;
            selectedAsset = assets.find(a => a.id == assetId);
            renderAssetsList();

            if (!selectedAsset) return;

            const detailContainer = document.getElementById('assetDetail');
            detailContainer.innerHTML = `
                <div class="asset-detail-sticky">
                    <div class="asset-detail-header">
                        <h2 class="asset-detail-hostname">${escapeHtml(selectedAsset.hostname)}</h2>
                        <div class="asset-detail-subtitle">Service Tag: ${escapeHtml(selectedAsset.service_tag) || '-'}</div>
                        <div class="asset-assigned-bar" id="assignedBar">
                            <div class="asset-assigned-info" id="assignedInfo">
                                <span class="unassigned-text">Loading...</span>
                            </div>
                            <span id="assignButtons"></span>
                        </div>
                    </div>
                    <div class="asset-info-grid">
                        <div class="info-item">
                            <span class="info-label">Type</span>
                            <select class="info-value-select" onchange="updateAssetField('asset_type_id', this.value)">
                                <option value="">-- None --</option>
                                ${assetTypes.map(t => `<option value="${t.id}" ${t.id == selectedAsset.asset_type_id ? 'selected' : ''}>${escapeHtml(t.name)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Status</span>
                            <select class="info-value-select" onchange="updateAssetField('asset_status_id', this.value)">
                                <option value="">-- None --</option>
                                ${assetStatusTypes.map(s => `<option value="${s.id}" ${s.id == selectedAsset.asset_status_id ? 'selected' : ''}>${escapeHtml(s.name)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Manufacturer</span>
                            <span class="info-value">${escapeHtml(selectedAsset.manufacturer) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Model</span>
                            <span class="info-value">${escapeHtml(selectedAsset.model) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">CPU</span>
                            <span class="info-value">${escapeHtml(selectedAsset.cpu_name) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">CPU Speed</span>
                            <span class="info-value">${escapeHtml(selectedAsset.speed) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Memory</span>
                            <span class="info-value">${escapeHtml(selectedAsset.memory) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Operating System</span>
                            <span class="info-value">${escapeHtml(selectedAsset.operating_system) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Feature Release</span>
                            <span class="info-value">${escapeHtml(selectedAsset.feature_release) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Build Number</span>
                            <span class="info-value">${escapeHtml(selectedAsset.build_number) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">BIOS Version</span>
                            <span class="info-value">${escapeHtml(selectedAsset.bios_version) || '-'}</span>
                        </div>
                    </div>
                </div>
                <div class="asset-detail-scroll">
                    <div class="software-section">
                        <div class="section-header">
                            <span class="section-title">Installed Software <span class="software-count-badge" id="softwareCountBadge">...</span></span>
                        </div>
                        <div class="software-list" id="installedSoftwareList">
                            <div class="loading"><div class="spinner"></div></div>
                        </div>
                    </div>
                </div>
            `;

            // Load assigned users and installed software
            loadAssignedUsers(assetId);
            loadInstalledSoftware(assetId);
        }

        // Load assigned users for an asset
        async function loadAssignedUsers(assetId) {
            try {
                const response = await fetch(`${API_BASE}get_asset_users.php?asset_id=${assetId}`);
                const data = await response.json();

                const infoSpan = document.getElementById('assignedInfo');
                const buttonsSpan = document.getElementById('assignButtons');

                if (data.success) {
                    const user = data.users.length > 0 ? data.users[0] : null;

                    if (user) {
                        currentAssignedUserId = user.user_id;
                        infoSpan.innerHTML = `
                            <span class="user-name">${escapeHtml(user.display_name || 'Unknown')}</span>
                            <span class="user-email">${escapeHtml(user.email || '')}</span>
                            <span class="user-assigned-date">Assigned: ${formatDate(user.assigned_datetime)}</span>
                        `;
                        buttonsSpan.innerHTML = `
                            <button class="btn btn-primary btn-sm" onclick="reassignUser()">Re-assign</button>
                            <button class="btn btn-danger btn-sm" onclick="unassignUser(${user.user_id})">Remove</button>
                        `;
                    } else {
                        currentAssignedUserId = null;
                        infoSpan.innerHTML = '<span class="unassigned-text">Unassigned</span>';
                        buttonsSpan.innerHTML = `
                            <button class="btn btn-primary btn-sm" onclick="openAssignModal()">Assign</button>
                        `;
                    }
                } else {
                    infoSpan.innerHTML = '<span class="unassigned-text">Error loading assignment</span>';
                }
            } catch (error) {
                console.error('Error loading assigned users:', error);
            }
        }

        // Load installed software for an asset
        async function loadInstalledSoftware(assetId) {
            try {
                const response = await fetch(`${API_BASE}get_asset_software.php?asset_id=${assetId}`);
                const data = await response.json();

                const container = document.getElementById('installedSoftwareList');
                const badge = document.getElementById('softwareCountBadge');

                if (data.success) {
                    badge.textContent = data.software.length;

                    if (data.software.length === 0) {
                        container.innerHTML = '<div class="empty-state" style="padding: 20px;">No software inventory data for this asset</div>';
                        return;
                    }

                    container.innerHTML = `
                        <table class="software-table">
                            <thead>
                                <tr>
                                    <th>Application</th>
                                    <th>Publisher</th>
                                    <th>Version</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.software.map(sw => `
                                    <tr>
                                        <td>${escapeHtml(sw.display_name)}</td>
                                        <td>${escapeHtml(sw.publisher || '\u2014')}</td>
                                        <td>${escapeHtml(sw.display_version || '\u2014')}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                } else {
                    badge.textContent = '0';
                    container.innerHTML = '<div class="empty-state" style="padding: 20px;">Error loading software</div>';
                }
            } catch (error) {
                console.error('Error loading installed software:', error);
                document.getElementById('installedSoftwareList').innerHTML = '<div class="empty-state" style="padding: 20px;">Error loading software</div>';
                document.getElementById('softwareCountBadge').textContent = '0';
            }
        }

        // Open assign user modal
        function openAssignModal() {
            selectedUserForAssign = null;
            document.getElementById('userSearchInput').value = '';
            document.getElementById('userSearchResults').innerHTML = '<div class="empty-state" style="padding: 20px;">Type to search for users</div>';
            document.getElementById('assignBtn').disabled = true;
            document.getElementById('assignUserModal').classList.add('active');
            document.getElementById('userSearchInput').focus();
        }

        // Close assign modal
        function closeAssignModal() {
            document.getElementById('assignUserModal').classList.remove('active');
            selectedUserForAssign = null;
        }

        // Search users for assignment
        async function searchUsersForAssign() {
            const search = document.getElementById('userSearchInput').value;

            if (search.length < 2) {
                document.getElementById('userSearchResults').innerHTML = '<div class="empty-state" style="padding: 20px;">Type at least 2 characters to search</div>';
                return;
            }

            try {
                const response = await fetch(`${API_TICKETS}get_users.php?search=${encodeURIComponent(search)}`);
                const data = await response.json();

                const container = document.getElementById('userSearchResults');

                if (data.success && data.users.length > 0) {
                    container.innerHTML = data.users.map(user => `
                        <div class="user-search-item ${selectedUserForAssign == user.id ? 'selected' : ''}" onclick="selectUserForAssign(${user.id}, '${escapeHtml(user.display_name)}')">
                            <div class="user-search-name">${escapeHtml(user.display_name || 'Unknown')}</div>
                            <div class="user-search-email">${escapeHtml(user.email || '')}</div>
                        </div>
                    `).join('');
                } else {
                    container.innerHTML = '<div class="empty-state" style="padding: 20px;">No users found</div>';
                }
            } catch (error) {
                console.error('Error searching users:', error);
            }
        }

        // Select a user for assignment
        function selectUserForAssign(userId, userName) {
            selectedUserForAssign = userId;
            document.getElementById('assignBtn').disabled = false;

            // Update UI to show selection
            document.querySelectorAll('.user-search-item').forEach(item => {
                item.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
        }

        // Re-assign: open the assign modal (will remove current user on confirm)
        function reassignUser() {
            openAssignModal();
        }

        // Confirm user assignment (handles both assign and re-assign)
        async function confirmAssignUser() {
            if (!selectedUserForAssign || !selectedAssetId) return;

            try {
                // If re-assigning, remove current user first
                if (currentAssignedUserId) {
                    await fetch(API_BASE + 'unassign_asset_user.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            asset_id: selectedAssetId,
                            user_id: currentAssignedUserId
                        })
                    });
                }

                const response = await fetch(API_BASE + 'assign_asset_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        asset_id: selectedAssetId,
                        user_id: selectedUserForAssign
                    })
                });
                const data = await response.json();

                if (data.success) {
                    closeAssignModal();
                    // Refresh the asset details and list
                    loadAssets(document.getElementById('assetSearch').value);
                    selectAsset(selectedAssetId);
                } else {
                    alert('Error assigning user: ' + data.error);
                }
            } catch (error) {
                console.error('Error assigning user:', error);
                alert('Error assigning user');
            }
        }

        // Unassign a user from the asset
        async function unassignUser(userId) {
            if (!confirm('Are you sure you want to remove this user from the asset?')) return;

            try {
                const response = await fetch(API_BASE + 'unassign_asset_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        asset_id: selectedAssetId,
                        user_id: userId
                    })
                });
                const data = await response.json();

                if (data.success) {
                    // Refresh the asset details and list
                    loadAssets(document.getElementById('assetSearch').value);
                    selectAsset(selectedAssetId);
                } else {
                    alert('Error removing user: ' + data.error);
                }
            } catch (error) {
                console.error('Error removing user:', error);
                alert('Error removing user');
            }
        }

        // Escape HTML for safe display
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Format date for display
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }

        // Close modal on outside click
        document.getElementById('assignUserModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAssignModal();
            }
        });
    </script>
</body>
</html>
