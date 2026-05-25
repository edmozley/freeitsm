<?php
/**
 * Asset Management - Servers (vCenter Environment)
 */
session_start();
require_once '../../config.php';

$current_page = 'servers';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Servers</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .servers-container {
            flex: 1;
            overflow-y: auto;
            background-color: #f5f7fa;
        }

        .servers-content {
            padding: 25px;
        }

        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: #fff;
            border-radius: 8px;
            padding: 18px 20px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .summary-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .summary-icon svg {
            color: #fff;
        }

        .summary-icon.vms { background: linear-gradient(135deg, #0078d4, #106ebe); }
        .summary-icon.active { background: linear-gradient(135deg, #107c10, #0b5c0b); }
        .summary-icon.offline { background: linear-gradient(135deg, #d13438, #a4262c); }
        .summary-icon.cpu { background: linear-gradient(135deg, #8764b8, #6b4fa0); }
        .summary-icon.memory { background: linear-gradient(135deg, #e3008c, #b4009e); }
        .summary-icon.disk { background: linear-gradient(135deg, #ff8c00, #d67200); }
        .summary-icon.hosts { background: linear-gradient(135deg, #00b7c3, #008b94); }
        .summary-icon.clusters { background: linear-gradient(135deg, #498205, #3b6c04); }

        .summary-info {
            display: flex;
            flex-direction: column;
        }

        .summary-value {
            font-size: 22px;
            font-weight: 700;
            color: #333;
            line-height: 1.2;
        }

        .summary-label {
            font-size: 12px;
            color: #888;
            margin-top: 2px;
        }

        /* Toolbar */
        .servers-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            gap: 15px;
        }

        .search-box {
            flex: 1;
            max-width: 400px;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
        }

        .search-box:focus {
            outline: none;
            border-color: #107c10;
            box-shadow: 0 0 0 2px rgba(16, 124, 16, 0.1);
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sync-info {
            font-size: 12px;
            color: #888;
        }

        .btn {
            padding: 9px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.15s;
        }

        .btn-sync {
            background-color: #107c10;
            color: white;
        }

        .btn-sync:hover {
            background-color: #0b5c0b;
        }

        .btn-sync:disabled {
            background-color: #999;
            cursor: not-allowed;
        }

        .btn-sync svg {
            transition: transform 0.3s;
        }

        .btn-sync.syncing svg {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            100% { transform: rotate(360deg); }
        }

        /* Server Table */
        .servers-table-wrapper {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .servers-table {
            width: 100%;
            border-collapse: collapse;
        }

        .servers-table thead th {
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            background-color: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }

        .servers-table thead th:hover {
            background-color: #eef0f2;
        }

        .servers-table thead th .sort-arrow {
            display: inline-block;
            margin-left: 4px;
            font-size: 10px;
            color: #bbb;
        }

        .servers-table thead th.sorted .sort-arrow {
            color: #333;
        }

        .servers-table tbody td {
            padding: 10px 16px;
            font-size: 13px;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
        }

        .servers-table tbody tr:hover {
            background-color: #f9f9f9;
        }

        .server-name {
            font-weight: 600;
            font-family: monospace;
            font-size: 13px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-badge.active {
            background-color: #e8f5e9;
            color: #2e7d32;
        }

        .status-badge.offline {
            background-color: #ffebee;
            color: #c62828;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
        }

        .status-dot.active {
            background-color: #4caf50;
        }

        .status-dot.offline {
            background-color: #ef5350;
        }

        .mono {
            font-family: monospace;
            font-size: 13px;
        }

        .text-muted {
            color: #aaa;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #888;
        }

        .empty-state svg {
            color: #ccc;
            margin-bottom: 15px;
        }

        .empty-state h3 {
            margin: 0 0 8px 0;
            font-size: 16px;
            color: #666;
        }

        .empty-state p {
            margin: 0;
            font-size: 14px;
        }

        .server-count {
            font-size: 13px;
            color: #666;
        }

        /* Sync message */
        .sync-message {
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 15px;
            display: none;
        }

        .sync-message.success {
            display: block;
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }

        .sync-message.error {
            display: block;
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        /* Filter chips */
        .filter-chips {
            display: flex;
            gap: 8px;
            margin-left: 15px;
        }

        .filter-chip {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid #ddd;
            background: #fff;
            color: #666;
            transition: all 0.15s;
        }

        .filter-chip:hover {
            border-color: #107c10;
            color: #107c10;
        }

        .filter-chip.active {
            background-color: #107c10;
            color: white;
            border-color: #107c10;
        }

        /* Clickable rows */
        .servers-table tbody tr {
            cursor: pointer;
        }

        /* Detail Modal */
        .detail-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: flex-start;
            padding: 40px 20px;
            overflow-y: auto;
        }

        .detail-overlay.open {
            display: flex;
        }

        .detail-modal {
            background: #fff;
            border-radius: 10px;
            width: 100%;
            max-width: 900px;
            max-height: calc(100vh - 80px);
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 24px;
            border-bottom: 1px solid #e0e0e0;
            flex-shrink: 0;
        }

        .detail-header h2 {
            margin: 0;
            font-size: 18px;
            font-family: monospace;
            color: #333;
        }

        .detail-header .status-badge {
            margin-left: 12px;
        }

        .detail-close {
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: #888;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .detail-close:hover {
            background: #f0f0f0;
            color: #333;
        }

        .detail-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }

        .detail-section {
            margin-bottom: 20px;
        }

        .detail-section h3 {
            font-size: 13px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin: 0 0 10px 0;
            padding-bottom: 6px;
            border-bottom: 1px solid #eee;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 24px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }

        .detail-item .label {
            font-size: 13px;
            color: #888;
        }

        .detail-item .value {
            font-size: 13px;
            color: #333;
            font-weight: 500;
            text-align: right;
        }

        .raw-section {
            margin-top: 10px;
        }

        .raw-toggle {
            background: #f5f7fa;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 8px 14px;
            font-size: 12px;
            cursor: pointer;
            color: #666;
        }

        .raw-toggle:hover {
            background: #eef0f2;
            color: #333;
        }

        .raw-json {
            display: none;
            margin-top: 10px;
            background: #1e1e1e;
            color: #d4d4d4;
            border-radius: 6px;
            padding: 16px;
            font-family: monospace;
            font-size: 12px;
            line-height: 1.5;
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        .raw-json.open {
            display: block;
        }

        .raw-json .json-key { color: #9cdcfe; }
        .raw-json .json-string { color: #ce9178; }
        .raw-json .json-number { color: #b5cea8; }
        .raw-json .json-bool { color: #569cd6; }
        .raw-json .json-null { color: #569cd6; }

        .detail-disks-table, .detail-nics-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .detail-disks-table th, .detail-nics-table th {
            text-align: left;
            font-size: 11px;
            color: #888;
            font-weight: 600;
            padding: 6px 10px;
            border-bottom: 1px solid #eee;
        }

        .detail-disks-table td, .detail-nics-table td {
            padding: 6px 10px;
            color: #333;
            border-bottom: 1px solid #f5f5f5;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container servers-container">
        <div class="servers-content">
            <!-- Summary Cards -->
            <div class="summary-cards" id="summaryCards">
                <div class="summary-card">
                    <div class="summary-icon vms">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                            <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                            <line x1="6" y1="6" x2="6.01" y2="6"></line>
                            <line x1="6" y1="18" x2="6.01" y2="18"></line>
                        </svg>
                    </div>
                    <div class="summary-info">
                        <span class="summary-value" id="totalVMs">-</span>
                        <span class="summary-label">Total VMs</span>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon active">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    </div>
                    <div class="summary-info">
                        <span class="summary-value" id="activeVMs">-</span>
                        <span class="summary-label">Active</span>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon offline">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="15" y1="9" x2="9" y2="15"></line>
                            <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                    </div>
                    <div class="summary-info">
                        <span class="summary-value" id="offlineVMs">-</span>
                        <span class="summary-label">Offline</span>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon cpu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect>
                            <rect x="9" y="9" width="6" height="6"></rect>
                            <line x1="9" y1="1" x2="9" y2="4"></line>
                            <line x1="15" y1="1" x2="15" y2="4"></line>
                            <line x1="9" y1="20" x2="9" y2="23"></line>
                            <line x1="15" y1="20" x2="15" y2="23"></line>
                            <line x1="20" y1="9" x2="23" y2="9"></line>
                            <line x1="20" y1="14" x2="23" y2="14"></line>
                            <line x1="1" y1="9" x2="4" y2="9"></line>
                            <line x1="1" y1="14" x2="4" y2="14"></line>
                        </svg>
                    </div>
                    <div class="summary-info">
                        <span class="summary-value" id="totalCPU">-</span>
                        <span class="summary-label">Total vCPUs</span>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon memory">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 19v-3"></path>
                            <path d="M10 19v-3"></path>
                            <path d="M14 19v-3"></path>
                            <path d="M18 19v-3"></path>
                            <path d="M8 11V9"></path>
                            <path d="M16 11V9"></path>
                            <path d="M12 11V9"></path>
                            <rect x="2" y="11" width="20" height="5" rx="1"></rect>
                        </svg>
                    </div>
                    <div class="summary-info">
                        <span class="summary-value" id="totalMemory">-</span>
                        <span class="summary-label">Total Memory</span>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon disk">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                            <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                            <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
                        </svg>
                    </div>
                    <div class="summary-info">
                        <span class="summary-value" id="totalDisk">-</span>
                        <span class="summary-label">Total Storage</span>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon hosts">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                            <line x1="6" y1="6" x2="6.01" y2="6"></line>
                            <line x1="10" y1="6" x2="10.01" y2="6"></line>
                        </svg>
                    </div>
                    <div class="summary-info">
                        <span class="summary-value" id="hostCount">-</span>
                        <span class="summary-label">ESXi Hosts</span>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon clusters">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"></circle>
                            <circle cx="19" cy="5" r="2"></circle>
                            <circle cx="5" cy="5" r="2"></circle>
                            <circle cx="19" cy="19" r="2"></circle>
                            <circle cx="5" cy="19" r="2"></circle>
                            <line x1="12" y1="9" x2="12" y2="3"></line>
                            <line x1="14.5" y1="13.5" x2="17.5" y2="17.5"></line>
                            <line x1="9.5" y1="13.5" x2="6.5" y2="17.5"></line>
                        </svg>
                    </div>
                    <div class="summary-info">
                        <span class="summary-value" id="clusterCount">-</span>
                        <span class="summary-label">Clusters</span>
                    </div>
                </div>
            </div>

            <!-- Sync Message -->
            <div class="sync-message" id="syncMessage"></div>

            <!-- Toolbar -->
            <div class="servers-toolbar">
                <input type="text" class="search-box" id="searchBox" placeholder="Search by name, IP, host or cluster...">
                <div class="filter-chips">
                    <span class="filter-chip active" data-filter="all" onclick="setFilter('all')">All</span>
                    <span class="filter-chip" data-filter="active" onclick="setFilter('active')">Active</span>
                    <span class="filter-chip" data-filter="offline" onclick="setFilter('offline')">Offline</span>
                </div>
                <div class="toolbar-right">
                    <span class="sync-info" id="syncInfo"></span>
                    <button class="btn btn-sync" id="syncBtn" onclick="syncVCenter()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                        Sync vCenter
                    </button>
                </div>
            </div>

            <!-- Server Table -->
            <div class="servers-table-wrapper">
                <table class="servers-table" id="serversTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable('name')">Name <span class="sort-arrow">&#9650;</span></th>
                            <th onclick="sortTable('power_state')">Status <span class="sort-arrow">&#9650;</span></th>
                            <th onclick="sortTable('num_cpu')">vCPU <span class="sort-arrow">&#9650;</span></th>
                            <th onclick="sortTable('memory_gb')">Memory <span class="sort-arrow">&#9650;</span></th>
                            <th onclick="sortTable('hard_disk_size_gb')">Storage <span class="sort-arrow">&#9650;</span></th>
                            <th onclick="sortTable('ip_address')">IP Address <span class="sort-arrow">&#9650;</span></th>
                            <th onclick="sortTable('host')">Host <span class="sort-arrow">&#9650;</span></th>
                            <th onclick="sortTable('cluster')">Cluster <span class="sort-arrow">&#9650;</span></th>
                            <th onclick="sortTable('guest_os')">Guest OS <span class="sort-arrow">&#9650;</span></th>
                        </tr>
                    </thead>
                    <tbody id="serversBody">
                        <tr><td colspan="9" style="text-align: center; padding: 40px; color: #888;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Detail Modal -->
    <div class="detail-overlay" id="detailOverlay" onclick="if(event.target===this)closeDetail()">
        <div class="detail-modal">
            <div class="detail-header">
                <div style="display:flex;align-items:center" id="detailTitle"></div>
                <button class="detail-close" onclick="closeDetail()">&times;</button>
            </div>
            <div class="detail-body" id="detailBody"></div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/assets/';
        let allServers = [];
        let filteredServers = [];
        let currentSort = { column: 'name', direction: 'asc' };
        let currentFilter = 'all';
        let searchTimeout = null;

        document.addEventListener('DOMContentLoaded', function() {
            loadServers();

            document.getElementById('searchBox').addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(applyFilters, 300);
            });
        });

        async function loadServers() {
            try {
                const response = await fetch(API_BASE + 'get_servers.php');
                const data = await response.json();

                if (data.success) {
                    allServers = data.servers;
                    updateSummary(data.summary);
                    applyFilters();
                } else {
                    showEmptyState('Error loading servers: ' + data.error);
                }
            } catch (error) {
                console.error('Error loading servers:', error);
                showEmptyState('Failed to load server data');
            }
        }

        function updateSummary(summary) {
            document.getElementById('totalVMs').textContent = summary.total_vms;
            document.getElementById('activeVMs').textContent = summary.active_vms;
            document.getElementById('offlineVMs').textContent = summary.offline_vms;
            document.getElementById('totalCPU').textContent = summary.total_cpu;
            document.getElementById('totalMemory').textContent = formatSize(summary.total_memory_gb);
            document.getElementById('totalDisk').textContent = formatSize(summary.total_disk_gb);
            document.getElementById('hostCount').textContent = summary.host_count;
            document.getElementById('clusterCount').textContent = summary.cluster_count;

            if (summary.last_synced) {
                document.getElementById('syncInfo').textContent = 'Last synced: ' + summary.last_synced;
            }
        }

        function formatSize(gb) {
            if (gb >= 1024) {
                return (gb / 1024).toFixed(1) + ' TB';
            }
            return Math.round(gb) + ' GB';
        }

        function applyFilters() {
            const search = document.getElementById('searchBox').value.toLowerCase();

            filteredServers = allServers.filter(s => {
                if (currentFilter !== 'all' && s.power_state !== currentFilter) return false;
                if (search) {
                    return (s.name || '').toLowerCase().includes(search) ||
                           (s.ip_address || '').toLowerCase().includes(search) ||
                           (s.host || '').toLowerCase().includes(search) ||
                           (s.cluster || '').toLowerCase().includes(search) ||
                           (s.guest_os || '').toLowerCase().includes(search);
                }
                return true;
            });

            sortServers();
            renderTable();
        }

        function setFilter(filter) {
            currentFilter = filter;
            document.querySelectorAll('.filter-chip').forEach(chip => {
                chip.classList.toggle('active', chip.dataset.filter === filter);
            });
            applyFilters();
        }

        function sortTable(column) {
            if (currentSort.column === column) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = column;
                currentSort.direction = 'asc';
            }

            // Update sort arrow UI
            document.querySelectorAll('.servers-table thead th').forEach(th => {
                th.classList.remove('sorted');
            });

            sortServers();
            renderTable();
        }

        function sortServers() {
            const col = currentSort.column;
            const dir = currentSort.direction === 'asc' ? 1 : -1;

            filteredServers.sort((a, b) => {
                let aVal = a[col] ?? '';
                let bVal = b[col] ?? '';

                // Numeric columns
                if (['num_cpu', 'memory_gb', 'hard_disk_size_gb'].includes(col)) {
                    return (parseFloat(aVal) - parseFloat(bVal)) * dir;
                }

                // String comparison
                return String(aVal).localeCompare(String(bVal)) * dir;
            });
        }

        function renderTable() {
            const tbody = document.getElementById('serversBody');

            if (filteredServers.length === 0) {
                if (allServers.length === 0) {
                    tbody.innerHTML = `
                        <tr><td colspan="9">
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                                    <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                                    <line x1="6" y1="6" x2="6.01" y2="6"></line>
                                    <line x1="6" y1="18" x2="6.01" y2="18"></line>
                                </svg>
                                <h3>No servers synced yet</h3>
                                <p>Click "Sync vCenter" to import your virtual machine inventory</p>
                            </div>
                        </td></tr>`;
                } else {
                    tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 30px; color: #888;">No servers match your filters</td></tr>';
                }
                return;
            }

            tbody.innerHTML = filteredServers.map((s, i) => `
                <tr onclick="showDetail(${i})">
                    <td><span class="server-name">${escapeHtml(s.name)}</span></td>
                    <td>
                        <span class="status-badge ${s.power_state}">
                            <span class="status-dot ${s.power_state}"></span>
                            ${s.power_state === 'active' ? 'Active' : 'Offline'}
                        </span>
                    </td>
                    <td>${s.num_cpu || '-'}</td>
                    <td>${s.memory_gb ? parseFloat(s.memory_gb).toFixed(0) + ' GB' : '-'}</td>
                    <td>${s.hard_disk_size_gb ? formatSize(parseFloat(s.hard_disk_size_gb)) : '-'}</td>
                    <td><span class="mono ${s.ip_address ? '' : 'text-muted'}">${escapeHtml(s.ip_address || '-')}</span></td>
                    <td>${escapeHtml(s.host || '-')}</td>
                    <td>${escapeHtml(s.cluster || '-')}</td>
                    <td>${formatGuestOS(s.guest_os)}</td>
                </tr>
            `).join('');
        }

        function showEmptyState(message) {
            document.getElementById('serversBody').innerHTML = `
                <tr><td colspan="9" style="text-align: center; padding: 30px; color: #888;">${escapeHtml(message)}</td></tr>`;
        }

        function formatGuestOS(os) {
            if (!os) return '-';
            // Make vCenter guest OS identifiers more readable
            return escapeHtml(os
                .replace(/_/g, ' ')
                .replace(/GUEST$/, '')
                .replace(/^OTHER /, '')
                .trim()
            );
        }

        async function syncVCenter() {
            const btn = document.getElementById('syncBtn');
            const msgEl = document.getElementById('syncMessage');
            btn.disabled = true;
            btn.classList.add('syncing');

            msgEl.className = 'sync-message';
            msgEl.style.display = 'none';

            try {
                const response = await fetch(API_BASE + 'get_vcenter.php');
                const data = await response.json();

                if (data.success) {
                    msgEl.textContent = data.message;
                    msgEl.className = 'sync-message success';
                    loadServers(); // Refresh the table
                } else {
                    msgEl.textContent = 'Sync failed: ' + data.error;
                    msgEl.className = 'sync-message error';
                }
            } catch (error) {
                console.error('Sync error:', error);
                msgEl.textContent = 'Sync failed: Could not connect to server';
                msgEl.className = 'sync-message error';
            }

            btn.disabled = false;
            btn.classList.remove('syncing');

            // Auto-hide success message after 5 seconds
            setTimeout(() => {
                if (msgEl.classList.contains('success')) {
                    msgEl.style.display = 'none';
                }
            }, 5000);
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function showDetail(index) {
            const s = filteredServers[index];
            if (!s) return;

            // Title
            document.getElementById('detailTitle').innerHTML = `
                <h2>${escapeHtml(s.name)}</h2>
                <span class="status-badge ${s.power_state}">
                    <span class="status-dot ${s.power_state}"></span>
                    ${s.power_state === 'active' ? 'Active' : 'Offline'}
                </span>`;

            let raw = null;
            try { raw = s.raw_data ? JSON.parse(s.raw_data) : null; } catch(e) {}

            let html = '';

            // Overview section
            html += `<div class="detail-section">
                <h3>Overview</h3>
                <div class="detail-grid">
                    <div class="detail-item"><span class="label">VM ID</span><span class="value">${escapeHtml(s.vm_id)}</span></div>
                    <div class="detail-item"><span class="label">Guest OS</span><span class="value">${escapeHtml(s.guest_os || '-')}</span></div>
                    <div class="detail-item"><span class="label">vCPUs</span><span class="value">${s.num_cpu || '-'}</span></div>
                    <div class="detail-item"><span class="label">Memory</span><span class="value">${s.memory_gb ? parseFloat(s.memory_gb).toFixed(0) + ' GB' : '-'}</span></div>
                    <div class="detail-item"><span class="label">Storage</span><span class="value">${s.hard_disk_size_gb ? formatSize(parseFloat(s.hard_disk_size_gb)) : '-'}</span></div>
                    <div class="detail-item"><span class="label">IP Address</span><span class="value">${escapeHtml(s.ip_address || '-')}</span></div>
                    <div class="detail-item"><span class="label">ESXi Host</span><span class="value">${escapeHtml(s.host || '-')}</span></div>
                    <div class="detail-item"><span class="label">Cluster</span><span class="value">${escapeHtml(s.cluster || '-')}</span></div>
                    <div class="detail-item"><span class="label">Last Synced</span><span class="value">${escapeHtml(s.last_synced || '-')}</span></div>
                </div>
            </div>`;

            if (raw) {
                // Guest Identity
                if (raw.guest_identity) {
                    const gi = raw.guest_identity;
                    html += `<div class="detail-section">
                        <h3>Guest Identity (VMware Tools)</h3>
                        <div class="detail-grid">
                            ${detailItem('Full Name', gi.full_name?.default_message)}
                            ${detailItem('Host Name', gi.host_name)}
                            ${detailItem('IP Address', gi.ip_address)}
                            ${detailItem('OS Family', gi.family)}
                            ${detailItem('Tools Version', gi.tools_version)}
                        </div>
                    </div>`;
                }

                // Disks from detail
                const detail = raw.vm_detail;
                if (detail?.disks) {
                    html += `<div class="detail-section"><h3>Disks</h3><table class="detail-disks-table"><thead><tr><th>Disk</th><th>Label</th><th>Capacity</th><th>Type</th><th>Backing</th></tr></thead><tbody>`;
                    for (const [key, disk] of Object.entries(detail.disks)) {
                        const d = disk.value || disk;
                        const cap = d.capacity ? formatSize(d.capacity / (1024*1024*1024)) : '-';
                        const label = d.label || key;
                        const type = d.type || '-';
                        const backing = d.backing?.type || '-';
                        html += `<tr><td>${escapeHtml(key)}</td><td>${escapeHtml(label)}</td><td>${cap}</td><td>${escapeHtml(type)}</td><td>${escapeHtml(backing)}</td></tr>`;
                    }
                    html += '</tbody></table></div>';
                }

                // NICs from detail
                if (detail?.nics) {
                    html += `<div class="detail-section"><h3>Network Adapters</h3><table class="detail-nics-table"><thead><tr><th>NIC</th><th>Label</th><th>Type</th><th>MAC Address</th><th>State</th><th>Network</th></tr></thead><tbody>`;
                    for (const [key, nic] of Object.entries(detail.nics)) {
                        const n = nic.value || nic;
                        html += `<tr><td>${escapeHtml(key)}</td><td>${escapeHtml(n.label || '-')}</td><td>${escapeHtml(n.type || '-')}</td><td style="font-family:monospace">${escapeHtml(n.mac_address || '-')}</td><td>${escapeHtml(n.state || '-')}</td><td>${escapeHtml(n.backing?.network_name || n.backing?.network || '-')}</td></tr>`;
                    }
                    html += '</tbody></table></div>';
                }

                // Guest networking interfaces
                if (raw.guest_networking && Array.isArray(raw.guest_networking)) {
                    html += `<div class="detail-section"><h3>Guest Network Interfaces</h3><table class="detail-nics-table"><thead><tr><th>NIC</th><th>MAC Address</th><th>IP Addresses</th></tr></thead><tbody>`;
                    raw.guest_networking.forEach((iface, i) => {
                        const ips = (iface.ip?.ip_addresses || []).map(a => a.ip_address).join(', ') || '-';
                        html += `<tr><td>NIC ${i}</td><td style="font-family:monospace">${escapeHtml(iface.mac_address || '-')}</td><td style="font-family:monospace">${escapeHtml(ips)}</td></tr>`;
                    });
                    html += '</tbody></table></div>';
                }

                // Guest local filesystem
                if (raw.guest_local_filesystem) {
                    const fs = raw.guest_local_filesystem;
                    const entries = Array.isArray(fs) ? fs : Object.entries(fs).map(([k,v]) => ({key: k, ...(v.value || v)}));
                    if (entries.length > 0) {
                        html += `<div class="detail-section"><h3>Filesystems</h3><table class="detail-disks-table"><thead><tr><th>Drive</th><th>Capacity</th><th>Free Space</th></tr></thead><tbody>`;
                        entries.forEach(f => {
                            const key = f.key || '';
                            const cap = f.capacity ? formatSize(f.capacity / (1024*1024*1024)) : '-';
                            const free = f.free_space ? formatSize(f.free_space / (1024*1024*1024)) : '-';
                            html += `<tr><td>${escapeHtml(key)}</td><td>${cap}</td><td>${free}</td></tr>`;
                        });
                        html += '</tbody></table></div>';
                    }
                }

                // Hardware detail
                if (detail?.hardware) {
                    html += `<div class="detail-section">
                        <h3>Hardware</h3>
                        <div class="detail-grid">
                            ${detailItem('Hardware Version', detail.hardware.version)}
                            ${detailItem('Upgrade Policy', detail.hardware.upgrade_policy)}
                            ${detailItem('Upgrade Status', detail.hardware.upgrade_status)}
                        </div>
                    </div>`;
                }

                // Boot config
                if (detail?.boot) {
                    html += `<div class="detail-section">
                        <h3>Boot Configuration</h3>
                        <div class="detail-grid">
                            ${detailItem('Boot Type', detail.boot.type)}
                            ${detailItem('EFI Legacy Boot', detail.boot.efi_legacy_boot)}
                            ${detailItem('Network Protocol', detail.boot.network_protocol)}
                            ${detailItem('Boot Delay', detail.boot.delay ? detail.boot.delay + 'ms' : null)}
                            ${detailItem('Enter Setup', detail.boot.enter_setup_mode)}
                            ${detailItem('Retry', detail.boot.retry)}
                            ${detailItem('Retry Delay', detail.boot.retry_delay ? detail.boot.retry_delay + 'ms' : null)}
                        </div>
                    </div>`;
                }

                // Raw JSON toggle
                html += `<div class="detail-section raw-section">
                    <button class="raw-toggle" onclick="toggleRawJson()">Show Raw JSON</button>
                    <div class="raw-json" id="rawJsonBlock">${syntaxHighlight(JSON.stringify(raw, null, 2))}</div>
                </div>`;
            }

            document.getElementById('detailBody').innerHTML = html;
            document.getElementById('detailOverlay').classList.add('open');
        }

        function closeDetail() {
            document.getElementById('detailOverlay').classList.remove('open');
        }

        function detailItem(label, value) {
            if (value === undefined || value === null || value === '') return '';
            return `<div class="detail-item"><span class="label">${escapeHtml(label)}</span><span class="value">${escapeHtml(String(value))}</span></div>`;
        }

        function toggleRawJson() {
            document.getElementById('rawJsonBlock').classList.toggle('open');
        }

        function syntaxHighlight(json) {
            json = escapeHtml(json);
            return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function(match) {
                let cls = 'json-number';
                if (/^"/.test(match)) {
                    cls = /:$/.test(match) ? 'json-key' : 'json-string';
                } else if (/true|false/.test(match)) {
                    cls = 'json-bool';
                } else if (/null/.test(match)) {
                    cls = 'json-null';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            });
        }

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDetail();
        });
    </script>
</body>
</html>
