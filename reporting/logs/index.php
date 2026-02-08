<?php
/**
 * System Logs - View login attempts, email imports, etc.
 */
session_start();
require_once '../../config.php';

$current_page = 'logs';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - System Logs</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .logs-outer {
            flex: 1;
            overflow-y: auto;
            background: #f5f7fa;
        }

        .logs-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .logs-header h2 {
            font-size: 24px;
            color: #333;
            margin: 0;
        }

        .log-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #ddd;
        }

        .log-tab {
            padding: 12px 24px;
            background: white;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }

        .log-tab:hover {
            color: #ca5010;
        }

        .log-tab.active {
            color: #ca5010;
            border-bottom-color: #ca5010;
        }

        .logs-content {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logs-table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #ddd;
            white-space: nowrap;
        }

        .logs-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            color: #444;
            vertical-align: top;
        }

        .logs-table tr:hover {
            background: #f8f8f8;
        }

        .log-datetime {
            white-space: nowrap;
            color: #666;
            font-size: 13px;
        }

        .log-status {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            display: inline-block;
        }

        .log-status.success {
            background: #d4edda;
            color: #155724;
        }

        .log-status.failed {
            background: #f8d7da;
            color: #721c24;
        }

        .log-details {
            font-size: 13px;
            color: #666;
        }

        .log-details code {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }

        .attachment-list {
            margin: 5px 0 0 0;
            padding-left: 20px;
            font-size: 12px;
        }

        .attachment-list li {
            margin: 3px 0;
        }

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #888;
        }

        .loading {
            padding: 60px 20px;
            text-align: center;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #ca5010;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 20px;
            border-top: 1px solid #eee;
        }

        .pagination button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .pagination button:hover:not(:disabled) {
            background: #f0f0f0;
            border-color: #ca5010;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination span {
            color: #666;
            font-size: 14px;
        }

        .refresh-btn {
            background: #ca5010;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .refresh-btn:hover {
            background: #a5410a;
        }

        /* JSON Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: flex;
            justify-content: center;
            align-items: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: white;
            border-radius: 8px;
            max-width: 700px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            transform: scale(0.95) translateY(-10px);
            transition: transform 0.2s ease;
        }

        .modal-overlay.active .modal-content {
            transform: scale(1) translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            background: #f8f9fa;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 16px;
            color: #333;
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
            max-height: calc(80vh - 60px);
        }

        .json-display {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 4px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
            overflow-x: auto;
        }

        .logs-table tbody tr {
            cursor: pointer;
        }

        .logs-table tbody tr:hover {
            background: #fff3e0;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <!-- JSON Details Modal -->
    <div class="modal-overlay" id="jsonModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Log Details (JSON)</h3>
                <button class="modal-close" onclick="closeJsonModal()">&times;</button>
            </div>
            <div class="modal-body">
                <pre class="json-display" id="jsonContent"></pre>
            </div>
        </div>
    </div>

    <div class="main-container logs-outer">
        <div class="logs-container">
            <div class="logs-header">
                <h2>System Logs</h2>
                <button class="refresh-btn" onclick="loadLogs()">Refresh</button>
            </div>

            <div class="log-tabs">
                <button class="log-tab active" onclick="switchLogType('login')">User Logins</button>
                <button class="log-tab" onclick="switchLogType('email_import')">Email Imports</button>
            </div>

            <div class="logs-content">
                <div id="logsTableContainer">
                    <div class="loading">
                        <div class="spinner"></div>
                        <div>Loading logs...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/reporting/';
        let currentLogType = 'login';
        let currentOffset = 0;
        const limit = 50;
        let totalLogs = 0;
        let currentLogs = [];

        document.addEventListener('DOMContentLoaded', function() {
            loadLogs();
        });

        function switchLogType(type) {
            currentLogType = type;
            currentOffset = 0;

            document.querySelectorAll('.log-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');

            loadLogs();
        }

        async function loadLogs() {
            const container = document.getElementById('logsTableContainer');
            container.innerHTML = '<div class="loading"><div class="spinner"></div><div>Loading logs...</div></div>';

            try {
                const response = await fetch(`${API_BASE}get_system_logs.php?type=${currentLogType}&limit=${limit}&offset=${currentOffset}`);
                const data = await response.json();

                if (data.success) {
                    totalLogs = data.total;
                    currentLogs = data.logs;
                    renderLogs(data.logs);
                } else {
                    container.innerHTML = `<div class="empty-state">Error loading logs: ${data.error}</div>`;
                }
            } catch (error) {
                container.innerHTML = `<div class="empty-state">Error loading logs: ${error.message}</div>`;
            }
        }

        function renderLogs(logs) {
            const container = document.getElementById('logsTableContainer');

            if (logs.length === 0) {
                container.innerHTML = '<div class="empty-state">No logs found</div>';
                return;
            }

            let tableHtml = '';

            if (currentLogType === 'login') {
                tableHtml = renderLoginLogs(logs);
            } else if (currentLogType === 'email_import') {
                tableHtml = renderEmailImportLogs(logs);
            }

            const totalPages = Math.ceil(totalLogs / limit);
            const currentPage = Math.floor(currentOffset / limit) + 1;

            tableHtml += `
                <div class="pagination">
                    <button onclick="prevPage()" ${currentOffset === 0 ? 'disabled' : ''}>Previous</button>
                    <span>Page ${currentPage} of ${totalPages} (${totalLogs} total)</span>
                    <button onclick="nextPage()" ${currentOffset + limit >= totalLogs ? 'disabled' : ''}>Next</button>
                </div>
            `;

            container.innerHTML = tableHtml;
        }

        function renderLoginLogs(logs) {
            return `
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Username</th>
                            <th>Status</th>
                            <th>IP Address</th>
                            <th>User Agent</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${logs.map((log, index) => `
                            <tr onclick="showLogJson(${index})" title="Click to view JSON details">
                                <td class="log-datetime">${formatDateTime(log.created_datetime)}</td>
                                <td><strong>${escapeHtml(log.details?.username || 'Unknown')}</strong></td>
                                <td>
                                    <span class="log-status ${log.details?.success ? 'success' : 'failed'}">
                                        ${log.details?.success ? 'Success' : 'Failed'}
                                    </span>
                                </td>
                                <td class="log-details"><code>${escapeHtml(log.details?.ip_address || '-')}</code></td>
                                <td class="log-details">${escapeHtml(truncate(log.details?.user_agent || '-', 50))}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }

        function renderEmailImportLogs(logs) {
            return `
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>From</th>
                            <th>Subject</th>
                            <th>Type</th>
                            <th>Attachments</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${logs.map((log, index) => {
                            const attachments = log.details?.attachments || [];
                            const attachmentHtml = attachments.length > 0
                                ? `<ul class="attachment-list">${attachments.map(a =>
                                    `<li>${escapeHtml(a.name)} (${escapeHtml(a.type)}, ${formatFileSize(a.size)})</li>`
                                  ).join('')}</ul>`
                                : '<span style="color: #888;">None</span>';

                            return `
                                <tr onclick="showLogJson(${index})" title="Click to view JSON details">
                                    <td class="log-datetime">${formatDateTime(log.created_datetime)}</td>
                                    <td>
                                        <strong>${escapeHtml(log.details?.from_name || '')}</strong><br>
                                        <span class="log-details">${escapeHtml(log.details?.from || '')}</span>
                                    </td>
                                    <td>${escapeHtml(log.details?.subject || '(No Subject)')}</td>
                                    <td>
                                        <span class="log-status ${log.details?.is_new_ticket ? 'success' : ''}">
                                            ${log.details?.is_new_ticket ? 'New Ticket' : 'Reply'}
                                        </span>
                                    </td>
                                    <td>${attachmentHtml}</td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            `;
        }

        function prevPage() {
            if (currentOffset > 0) {
                currentOffset -= limit;
                loadLogs();
            }
        }

        function nextPage() {
            if (currentOffset + limit < totalLogs) {
                currentOffset += limit;
                loadLogs();
            }
        }

        function formatDateTime(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }

        function formatFileSize(bytes) {
            if (!bytes) return '0 B';
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function truncate(text, maxLength) {
            if (!text || text.length <= maxLength) return text;
            return text.substring(0, maxLength) + '...';
        }

        function showLogJson(index) {
            const log = currentLogs[index];
            if (!log) return;

            const jsonContent = document.getElementById('jsonContent');
            jsonContent.textContent = JSON.stringify(log.details, null, 2);

            document.getElementById('jsonModal').classList.add('active');
        }

        function closeJsonModal() {
            document.getElementById('jsonModal').classList.remove('active');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeJsonModal();
        });

        document.getElementById('jsonModal').addEventListener('click', function(e) {
            if (e.target === this) closeJsonModal();
        });
    </script>
</body>
</html>
