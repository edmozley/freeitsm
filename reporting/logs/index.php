<?php
/**
 * System Logs - View login attempts, email imports, etc.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'logs';
$path_prefix = '../../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('reporting.logs.heading')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=16">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        /* Module accent (rust-orange) — tabs, spinner, refresh button, focus. */
        body { --accent: var(--rep-accent, #ca5010); --accent-hover: var(--rep-accent-hover, #a5410a); }

        .logs-outer {
            flex: 1;
            overflow: hidden;       /* the inner .logs-content scrolls now, not this */
            background: var(--app-bg, #f5f7fa);
        }

        /* Full-width logs view, laid out as a flex column so the
           pagination footer pins to the bottom and only the table
           scrolls. */
        .logs-container {
            flex: 1;
            min-width: 0;
            max-width: none;
            margin: 0;
            padding: 16px 30px 0;   /* bottom padding moves into the pagination row */
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-shrink: 0;
        }

        .logs-header h2 {
            font-size: 24px;
            color: var(--text, #333);
            margin: 0;
        }

        .log-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border, #ddd);
            flex-shrink: 0;
        }

        .log-tab {
            padding: 12px 24px;
            background: var(--surface, white);
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-muted, #666);
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }

        .log-tab:hover {
            color: var(--rep-accent, #ca5010);
        }

        .log-tab.active {
            color: var(--rep-accent, #ca5010);
            border-bottom-color: var(--rep-accent, #ca5010);
        }

        .logs-content {
            background: var(--surface, white);
            border-radius: 8px;
            box-shadow: 0 2px 8px var(--shadow, rgba(0,0,0,0.1));
            overflow-y: auto;
            flex: 1;
            min-height: 0;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logs-table th {
            background: var(--surface-2, #f8f9fa);
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text, #333);
            border-bottom: 2px solid var(--border, #ddd);
            white-space: nowrap;
        }

        .logs-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-soft, #eee);
            color: var(--text, #444);
            vertical-align: top;
        }

        .logs-table tr:hover {
            background: var(--surface-hover, #f8f8f8);
        }

        .log-datetime {
            white-space: nowrap;
            color: var(--text-muted, #666);
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
            background: var(--success-bg, #d4edda);
            color: var(--success-text, #155724);
        }

        .log-status.failed {
            background: var(--danger-bg, #f8d7da);
            color: var(--danger-text, #721c24);
        }

        .log-details {
            font-size: 13px;
            color: var(--text-muted, #666);
        }

        .log-details code {
            background: var(--surface-2, #f0f0f0);
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
            color: var(--text-dim, #888);
        }

        .loading {
            padding: 60px 20px;
            text-align: center;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid var(--border-soft, #f3f3f3);
            border-top: 4px solid var(--rep-accent, #ca5010);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Sticky footer pagination. Sits as the last flex child of
           .logs-container so the scrollbar inside .logs-content stops
           at the top of this strip. */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 9px 0 19px;   /* shaved 3px off the top, kept the same overall row height */
            border-top: 1px solid var(--border-soft, #eee);
            background: var(--app-bg, #f5f7fa);
            flex-shrink: 0;
        }

        .pagination button {
            min-width: 100px;      /* match Previous + Next widths */
            padding: 8px 16px;
            border: 1px solid var(--border, #ddd);
            background: var(--surface, white);
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-align: center;
        }

        .pagination button:hover:not(:disabled) {
            background: var(--surface-hover, #f0f0f0);
            border-color: var(--rep-accent, #ca5010);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination span {
            color: var(--text-muted, #666);
            font-size: 14px;
        }

        .refresh-btn {
            background: var(--rep-accent, #ca5010);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .refresh-btn:hover {
            background: var(--rep-accent-hover, #a5410a);
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
            background: var(--surface, white);
            border-radius: 8px;
            max-width: 700px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 10px 40px var(--shadow, rgba(0,0,0,0.3));
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
            border-bottom: 1px solid var(--border, #ddd);
            background: var(--surface-2, #f8f9fa);
        }

        .modal-header h3 {
            margin: 0;
            font-size: 16px;
            color: var(--text, #333);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted, #666);
            line-height: 1;
        }

        .modal-close:hover {
            color: var(--text, #333);
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

        /* Dark mode: the pale-orange row hover would glow on the dark table,
           so sink it to a dark rust tint (light mode keeps #fff3e0). The
           .json-display code viewer is intentionally dark in both modes. */
        [data-theme-mode="dark"] .logs-table tbody tr:hover {
            background: var(--rep-accent-soft, #3a2416);
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <!-- JSON Details Modal -->
    <div class="modal-overlay" id="jsonModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><?php echo htmlspecialchars(t('reporting.logs.modal_title')); ?></h3>
            </div>
            <div class="modal-body">
                <pre class="json-display" id="jsonContent"></pre>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeJsonModal()"><?php echo htmlspecialchars(t('reporting.logs.close')); ?></button>
            </div>
        </div>
    </div>

    <div class="main-container logs-outer">
        <div class="logs-container">
            <div class="logs-header">
                <h2><?php echo htmlspecialchars(t('reporting.logs.heading')); ?></h2>
                <button class="refresh-btn" onclick="loadLogs()"><?php echo htmlspecialchars(t('reporting.logs.refresh')); ?></button>
            </div>

            <div class="log-tabs">
                <button class="log-tab active" onclick="switchLogType('login')"><?php echo htmlspecialchars(t('reporting.logs.tab_login')); ?></button>
                <button class="log-tab" onclick="switchLogType('email_import')"><?php echo htmlspecialchars(t('reporting.logs.tab_email_import')); ?></button>
            </div>

            <div class="logs-content">
                <div id="logsTableContainer">
                    <div class="loading">
                        <div class="spinner"></div>
                        <div><?php echo htmlspecialchars(t('reporting.logs.loading')); ?></div>
                    </div>
                </div>
            </div>

            <!-- Sticky-footer pagination. Populated separately from the
                 table via #paginationContainer so it stays pinned while
                 the table scrolls behind it. -->
            <div id="paginationContainer"></div>
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

        // Wipe the sticky-footer pagination — used by loading / error /
        // empty states so stale Prev/Next buttons don't linger.
        function clearPagination() {
            const pager = document.getElementById('paginationContainer');
            if (pager) pager.innerHTML = '';
        }

        async function loadLogs() {
            const container = document.getElementById('logsTableContainer');
            container.innerHTML = `<div class="loading"><div class="spinner"></div><div>${escapeHtml(t('reporting.logs.loading'))}</div></div>`;
            clearPagination();

            try {
                const response = await fetch(`${API_BASE}get_system_logs.php?type=${currentLogType}&limit=${limit}&offset=${currentOffset}`);
                const data = await response.json();

                if (data.success) {
                    totalLogs = data.total;
                    currentLogs = data.logs;
                    renderLogs(data.logs);
                } else {
                    container.innerHTML = `<div class="empty-state">${escapeHtml(t('reporting.logs.load_error', { error: data.error }))}</div>`;
                }
            } catch (error) {
                container.innerHTML = `<div class="empty-state">${escapeHtml(t('reporting.logs.load_error', { error: error.message }))}</div>`;
            }
        }

        function renderLogs(logs) {
            const container = document.getElementById('logsTableContainer');

            if (logs.length === 0) {
                container.innerHTML = `<div class="empty-state">${escapeHtml(t('reporting.logs.no_logs'))}</div>`;
                clearPagination();
                return;
            }

            let tableHtml = '';

            if (currentLogType === 'login') {
                tableHtml = renderLoginLogs(logs);
            } else if (currentLogType === 'email_import') {
                tableHtml = renderEmailImportLogs(logs);
            }

            container.innerHTML = tableHtml;

            // Render pagination into its own sibling so it sits as the
            // sticky footer at the bottom of .logs-container instead of
            // scrolling away with the table.
            const totalPages = Math.ceil(totalLogs / limit);
            const currentPage = Math.floor(currentOffset / limit) + 1;
            const pager = document.getElementById('paginationContainer');
            if (pager) {
                pager.innerHTML = `
                    <div class="pagination">
                        <button onclick="prevPage()" ${currentOffset === 0 ? 'disabled' : ''}>${escapeHtml(t('reporting.logs.prev'))}</button>
                        <span>${escapeHtml(t('reporting.logs.pagination', { current: currentPage, total: totalPages, count: totalLogs }))}</span>
                        <button onclick="nextPage()" ${currentOffset + limit >= totalLogs ? 'disabled' : ''}>${escapeHtml(t('reporting.logs.next'))}</button>
                    </div>
                `;
            }
        }

        function renderLoginLogs(logs) {
            return `
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>${escapeHtml(t('reporting.logs.col_datetime'))}</th>
                            <th>${escapeHtml(t('reporting.logs.col_username'))}</th>
                            <th>${escapeHtml(t('reporting.logs.col_status'))}</th>
                            <th>${escapeHtml(t('reporting.logs.col_ip'))}</th>
                            <th>${escapeHtml(t('reporting.logs.col_user_agent'))}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${logs.map((log, index) => `
                            <tr onclick="showLogJson(${index})" title="${escapeHtml(t('reporting.logs.row_title'))}">
                                <td class="log-datetime">${formatDateTime(log.created_datetime)}</td>
                                <td><strong>${escapeHtml(log.details?.username || t('reporting.logs.unknown'))}</strong></td>
                                <td>
                                    <span class="log-status ${log.details?.success ? 'success' : 'failed'}">
                                        ${log.details?.success ? escapeHtml(t('reporting.logs.status_success')) : escapeHtml(t('reporting.logs.status_failed'))}
                                    </span>
                                </td>
                                <td class="log-details"><code>${escapeHtml(log.details?.ip_address || '-')}</code></td>
                                <td class="log-details" style="word-break: break-word;">${escapeHtml(log.details?.user_agent || '-')}</td>
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
                            <th>${escapeHtml(t('reporting.logs.col_datetime'))}</th>
                            <th>${escapeHtml(t('reporting.logs.col_from'))}</th>
                            <th>${escapeHtml(t('reporting.logs.col_subject'))}</th>
                            <th>${escapeHtml(t('reporting.logs.col_type'))}</th>
                            <th>${escapeHtml(t('reporting.logs.col_attachments'))}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${logs.map((log, index) => {
                            const attachments = log.details?.attachments || [];
                            const attachmentHtml = attachments.length > 0
                                ? `<ul class="attachment-list">${attachments.map(a =>
                                    `<li>${escapeHtml(a.name)} (${escapeHtml(a.type)}, ${formatFileSize(a.size)})</li>`
                                  ).join('')}</ul>`
                                : `<span style="color: var(--text-dim, #888);">${escapeHtml(t('reporting.logs.none'))}</span>`;

                            return `
                                <tr onclick="showLogJson(${index})" title="${escapeHtml(t('reporting.logs.row_title'))}">
                                    <td class="log-datetime">${formatDateTime(log.created_datetime)}</td>
                                    <td>
                                        <strong>${escapeHtml(log.details?.from_name || '')}</strong><br>
                                        <span class="log-details">${escapeHtml(log.details?.from || '')}</span>
                                    </td>
                                    <td>${escapeHtml(log.details?.subject || t('reporting.logs.no_subject'))}</td>
                                    <td>
                                        <span class="log-status ${log.details?.is_new_ticket ? 'success' : ''}">
                                            ${log.details?.is_new_ticket ? escapeHtml(t('reporting.logs.new_ticket')) : escapeHtml(t('reporting.logs.reply'))}
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
            const date = parseUTCDate(dateString);
            return date.toLocaleString('en-GB', tzOpts({
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }));
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
