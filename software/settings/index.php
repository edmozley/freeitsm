<?php
/**
 * Software Settings - API Key Management
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
    <title>Service Desk - Software Settings</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .settings-container {
            flex: 1;
            overflow-y: auto;
            background: #f5f7fa;
            padding: 30px;
        }

        .settings-content {
            max-width: 900px;
            margin: 0 auto;
        }

        .settings-section {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .section-header {
            padding: 18px 24px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h3 {
            margin: 0;
            font-size: 16px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-header h3 svg {
            color: #5c6bc0;
        }

        .section-description {
            padding: 14px 24px;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
            font-size: 13px;
            color: #666;
            line-height: 1.5;
        }

        .section-body {
            padding: 0;
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

        .btn-warning {
            background: #f57c00;
            color: #fff;
        }

        .btn-warning:hover {
            background: #e65100;
        }

        .btn-success {
            background: #2e7d32;
            color: #fff;
        }

        .btn-success:hover {
            background: #1b5e20;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
        }

        .key-table {
            width: 100%;
            border-collapse: collapse;
        }

        .key-table thead th {
            background: #f8f9fa;
            padding: 10px 24px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e0e0e0;
        }

        .key-table tbody td {
            padding: 12px 24px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
            color: #333;
            vertical-align: middle;
        }

        .key-table tbody tr:last-child td {
            border-bottom: none;
        }

        .key-table tbody tr:hover {
            background: #fafafa;
        }

        .api-key-value {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 13px;
            background: #f5f5f5;
            padding: 4px 10px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            max-width: 360px;
            word-break: break-all;
        }

        .api-key-masked {
            color: #888;
        }

        .api-key-full {
            color: #333;
        }

        .copy-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px;
            color: #888;
            display: inline-flex;
        }

        .copy-btn:hover {
            color: #5c6bc0;
        }

        .status-active {
            display: inline-block;
            background: #e8f5e9;
            color: #2e7d32;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-revoked {
            display: inline-block;
            background: #fce4ec;
            color: #c62828;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .key-actions {
            display: flex;
            gap: 8px;
        }

        .empty-state {
            padding: 40px;
            text-align: center;
            color: #888;
            font-size: 14px;
        }

        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
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

        .new-key-banner {
            display: none;
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            border-radius: 6px;
            padding: 16px 20px;
            margin: 16px 24px;
        }

        .new-key-banner p {
            margin: 0 0 10px 0;
            font-size: 13px;
            color: #2e7d32;
            font-weight: 500;
        }

        .new-key-banner .key-display {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 14px;
            background: #fff;
            padding: 10px 14px;
            border-radius: 4px;
            border: 1px solid #c8e6c9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            word-break: break-all;
        }

        .new-key-banner .hint {
            margin: 10px 0 0 0;
            font-size: 12px;
            color: #666;
        }

        /* Modal for delete confirmation */
        .confirm-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .confirm-overlay.open {
            display: flex;
        }

        .confirm-box {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }

        .confirm-box h4 {
            margin: 0 0 12px 0;
            font-size: 16px;
            color: #333;
        }

        .confirm-box p {
            margin: 0 0 20px 0;
            font-size: 14px;
            color: #666;
        }

        .confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container settings-container">
        <div class="settings-content">
            <div class="settings-section">
                <div class="section-header">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path>
                        </svg>
                        API Keys
                    </h3>
                    <button class="btn btn-primary" onclick="generateKey()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Generate New Key
                    </button>
                </div>
                <div class="section-description">
                    API keys are used to authenticate requests to the software inventory submission endpoint.<br>
                    Clients send the key in the <code>Authorization</code> header when submitting inventory data to <code>/api/external/software-inventory/submit/</code>
                </div>
                <div id="newKeyBanner" class="new-key-banner">
                    <p>New API key generated. Copy it now — it won't be shown in full again.</p>
                    <div class="key-display">
                        <span id="newKeyValue"></span>
                        <button class="copy-btn" onclick="copyNewKey()" title="Copy to clipboard">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                        </button>
                    </div>
                    <p class="hint">Use this key as the Authorization header value when calling the inventory API.</p>
                </div>
                <div class="section-body">
                    <table class="key-table">
                        <thead>
                            <tr>
                                <th>API Key</th>
                                <th>Created</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="keyTableBody">
                            <tr><td colspan="4">
                                <div class="loading-spinner"><div class="spinner"></div></div>
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete confirmation -->
    <div class="confirm-overlay" id="confirmOverlay" onclick="if(event.target===this)closeConfirm()">
        <div class="confirm-box">
            <h4>Delete API Key</h4>
            <p>Are you sure you want to permanently delete this API key? Any clients using it will immediately lose access.</p>
            <div class="confirm-actions">
                <button class="btn btn-secondary" onclick="closeConfirm()">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/software/';
        let allKeys = [];
        let deleteKeyId = null;
        let newlyGeneratedKey = null;

        document.addEventListener('DOMContentLoaded', function() {
            loadKeys();
        });

        async function loadKeys() {
            try {
                const response = await fetch(API_BASE + 'get_apikeys.php');
                const data = await response.json();
                if (data.success) {
                    allKeys = data.keys;
                    renderTable();
                } else {
                    document.getElementById('keyTableBody').innerHTML =
                        '<tr><td colspan="4"><div class="empty-state">Error: ' + escapeHtml(data.error) + '</div></td></tr>';
                }
            } catch (error) {
                console.error('Error loading keys:', error);
                document.getElementById('keyTableBody').innerHTML =
                    '<tr><td colspan="4"><div class="empty-state">Failed to load API keys</div></td></tr>';
            }
        }

        function renderTable() {
            const tbody = document.getElementById('keyTableBody');

            if (allKeys.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="empty-state">No API keys configured. Generate one to enable inventory submissions.</div></td></tr>';
                return;
            }

            tbody.innerHTML = allKeys.map(key => {
                const masked = maskKey(key.apikey);
                const isActive = key.active == 1;
                const statusClass = isActive ? 'status-active' : 'status-revoked';
                const statusText = isActive ? 'Active' : 'Revoked';
                const toggleBtnClass = isActive ? 'btn-warning' : 'btn-success';
                const toggleBtnText = isActive ? 'Revoke' : 'Activate';

                return `
                <tr>
                    <td>
                        <span class="api-key-value">
                            <span class="api-key-masked">${escapeHtml(masked)}</span>
                            <button class="copy-btn" onclick="copyKey('${escapeHtml(key.apikey)}')" title="Copy full key">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                            </button>
                        </span>
                    </td>
                    <td>${formatDate(key.created_at)}</td>
                    <td><span class="${statusClass}">${statusText}</span></td>
                    <td>
                        <div class="key-actions">
                            <button class="btn ${toggleBtnClass} btn-sm" onclick="toggleKey(${key.id})">${toggleBtnText}</button>
                            <button class="btn btn-danger btn-sm" onclick="promptDelete(${key.id})">Delete</button>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        function maskKey(key) {
            if (!key || key.length < 8) return key || '';
            return key.substring(0, 6) + '\u2022'.repeat(20) + key.substring(key.length - 6);
        }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            if (isNaN(d)) return dateStr;
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
                + ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        }

        async function generateKey() {
            try {
                const response = await fetch(API_BASE + 'generate_apikey.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({})
                });
                const data = await response.json();
                if (data.success) {
                    newlyGeneratedKey = data.apikey;
                    document.getElementById('newKeyValue').textContent = data.apikey;
                    document.getElementById('newKeyBanner').style.display = 'block';
                    loadKeys();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error generating key:', error);
                alert('Failed to generate API key.');
            }
        }

        async function toggleKey(id) {
            try {
                const response = await fetch(API_BASE + 'toggle_apikey.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) {
                    loadKeys();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error toggling key:', error);
                alert('Failed to update API key.');
            }
        }

        function promptDelete(id) {
            deleteKeyId = id;
            document.getElementById('confirmOverlay').classList.add('open');
        }

        function closeConfirm() {
            deleteKeyId = null;
            document.getElementById('confirmOverlay').classList.remove('open');
        }

        async function confirmDelete() {
            if (!deleteKeyId) return;
            try {
                const response = await fetch(API_BASE + 'delete_apikey.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: deleteKeyId })
                });
                const data = await response.json();
                if (data.success) {
                    closeConfirm();
                    loadKeys();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error deleting key:', error);
                alert('Failed to delete API key.');
            }
        }

        function copyKey(key) {
            navigator.clipboard.writeText(key).then(() => {
                // Brief visual feedback - could enhance later
            }).catch(() => {
                // Fallback for older browsers
                const ta = document.createElement('textarea');
                ta.value = key;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            });
        }

        function copyNewKey() {
            if (newlyGeneratedKey) {
                copyKey(newlyGeneratedKey);
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeConfirm();
        });
    </script>
</body>
</html>
