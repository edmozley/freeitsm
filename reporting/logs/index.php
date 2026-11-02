<?php
/**
 * System Logs - View login attempts, email imports, etc.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('reporting');

// Background jobs (#182) is a tab only for those who may see job health - its
// messages name the install's directories, servers and tracker connections.
require_once '../../includes/job_health.php';
$jobsVisible = jobHealthMayView(connectToDatabase(), (int)$_SESSION['analyst_id']);
$openJobs = $jobsVisible && (($_GET['tab'] ?? '') === 'jobs');

$current_page = 'logs';
$path_prefix = '../../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('reporting', t('reporting.nav.logs_title'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
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

        /* A NEUTRAL tone for a .log-status carrying neither state — this
           applies at every width, and it is a fix rather than a mobile
           change. The email-import tab renders `New Ticket` with `.success`
           and `Reply` with no class at all, so `Reply` was drawn as a pill
           with padding, a radius and no fill: sized like its neighbour,
           dressed like its neighbour, and invisible as a badge. Nothing
           chose that — the class list is simply empty on one branch. */
        .log-status:not(.success):not(.failed) {
            background: var(--surface-2, #f0f0f0);
            color: var(--text-muted, #666);
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

        /* ---- Background jobs (#182) ------------------------------------- */
        .jobs-meta { font-size: 13px; color: var(--text-muted, #666); padding: 12px 15px 0; }
        .jobs-scheduler { margin: 12px 15px 0; padding: 10px 12px; border-radius: 4px; font-size: 13px; background: var(--surface-2, #f0f0f0); color: var(--text, #333); }
        .jobs-scheduler.ok { background: var(--success-bg, #d4edda); color: var(--success-text, #155724); }
        .jobs-scheduler.warn { background: var(--warning-bg, #fff3cd); color: var(--warning-text, #856404); }
        .jobs-scheduler code { display: block; margin-top: 6px; font-size: 12px; background: var(--surface, #fff); color: var(--text, #333); padding: 4px 8px; border-radius: 3px; overflow-x: auto; white-space: nowrap; user-select: all; }
        .jobs-section-title { font-size: 15px; font-weight: 600; color: var(--text, #333); padding: 16px 15px 8px; margin: 0; }
        .jobs-table td { vertical-align: top; }
        .jobs-table tbody tr { cursor: default; }
        .jobs-table tbody tr.job-row-clickable { cursor: pointer; }
        .job-name { font-weight: 600; }
        .job-affects { font-size: 12px; color: var(--text-muted, #666); margin-top: 2px; }
        .job-pill { padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: 500; white-space: nowrap; display: inline-block; }
        .job-pill.healthy, .job-pill.ok { background: var(--success-bg, #d4edda); color: var(--success-text, #155724); }
        .job-pill.failing, .job-pill.stuck, .job-pill.failed, .job-pill.unknown { background: var(--danger-bg, #f8d7da); color: var(--danger-text, #721c24); }
        .job-pill.overdue, .job-pill.never_seen, .job-pill.partial { background: var(--warning-bg, #fff3cd); color: var(--warning-text, #856404); }
        .job-pill.not_needed, .job-pill.not_used, .job-pill.skipped, .job-pill.running { background: var(--surface-2, #f0f0f0); color: var(--text-muted, #666); }
        .job-problem { font-size: 12px; margin-top: 4px; color: var(--text, #444); }
        .job-problem code { font-size: 11px; background: var(--surface-2, #f0f0f0); padding: 1px 5px; border-radius: 3px; }
        .job-sources { margin: 4px 0 0; padding-left: 18px; font-size: 12px; }
        .job-sub { font-size: 12px; color: var(--text-muted, #666); }
        .jobs-filters { display: flex; flex-wrap: wrap; gap: 8px; padding: 0 15px 10px; }
        .jobs-filters select { padding: 6px 8px; border: 1px solid var(--border, #ddd); border-radius: 4px; background: var(--surface, #fff); color: var(--text, #333); font-size: 13px; }
        .jobs-pager { display: flex; justify-content: center; align-items: center; gap: 10px; padding: 12px; }
        .jobs-pager button, .job-edit-btn { padding: 5px 12px; border: 1px solid var(--border, #ddd); background: var(--surface, #fff); border-radius: 4px; cursor: pointer; font-size: 13px; color: var(--text, #333); }
        .jobs-pager button:disabled { opacity: .5; cursor: not-allowed; }
        .job-detail dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; margin: 0 0 12px; font-size: 13px; }
        .job-detail dt { color: var(--text-muted, #666); }
        .job-detail dd { margin: 0; }
        .job-form label { display: block; font-size: 13px; margin: 10px 0 4px; color: var(--text, #333); }
        .job-form input[type=number] { width: 120px; padding: 6px 8px; border: 1px solid var(--border, #ddd); border-radius: 4px; background: var(--surface, #fff); color: var(--text, #333); }
        .job-form .hint { font-size: 12px; color: var(--text-muted, #666); margin-top: 4px; }
        .job-form label.job-mode { margin: 12px 0 4px; font-weight: 500; }
        .job-form .job-mode-detail { margin-left: 24px; }
        .job-form input[type=time] { padding: 6px 8px; border: 1px solid var(--border, #ddd); border-radius: 4px; background: var(--surface, #fff); color: var(--text, #333); }
        .job-form .job-mode-footnote { margin-top: 14px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid var(--border, #ddd); }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> block, or a rule at
         equal specificity loses on document order (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="reporting" data-mobile-page="rep-logs">
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
                <button class="log-tab<?php echo $openJobs ? '' : ' active'; ?>" onclick="switchLogType('login')"><?php echo htmlspecialchars(t('reporting.logs.tab_login')); ?></button>
                <button class="log-tab" onclick="switchLogType('email_import')"><?php echo htmlspecialchars(t('reporting.logs.tab_email_import')); ?></button>
                <?php if ($jobsVisible): ?>
                <button class="log-tab<?php echo $openJobs ? ' active' : ''; ?>" onclick="switchLogType('jobs')"><?php echo htmlspecialchars(t('reporting.jobs.tab')); ?></button>
                <?php endif; ?>
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

    <!-- Background jobs (#182): one run's detail, and a job's expectations -->
    <div class="modal-overlay" id="jobModal">
        <div class="modal-content">
            <div class="modal-header"><h3 id="jobModalTitle"></h3></div>
            <div class="modal-body job-detail" id="jobModalBody"></div>
            <div class="modal-footer" id="jobModalFooter"></div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/reporting/';
        let currentLogType = <?php echo json_encode($openJobs ? 'jobs' : 'login'); ?>;
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
            if (currentLogType === 'jobs') return loadJobs();
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

        // `data-log-type` tells the two tables apart. Both render into the same
        // container with the same class, and the mobile card feed needs to know
        // which is which: the columns that want a harvested heading differ
        // (an IP and a user agent here, attachments there). It carries no
        // styling at any width, so the desktop table is unchanged.
        function renderLoginLogs(logs) {
            return `
                <table class="logs-table" data-log-type="login">
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
                <table class="logs-table" data-log-type="email">
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
            return fmtDateTime(date);
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
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        // Every datetime in FreeITSM is stored UTC and rendered in the analyst's
        // own zone - which this dump was not doing, because it printed the record
        // exactly as stored. Sitting directly under a Date/time column that HAD
        // been converted, the raw value read as simply wrong: an email sent at
        // 22:37 UK showed as 23:37 in the column (correct, Vienna) and 21:36:52
        // in the JSON (also correct, UTC). Two right answers look like one bug.
        //
        // So annotate rather than convert. The stored value stays first and
        // unaltered, because a diagnostic dump that quietly rewrites what is in
        // the database is worse than one that is hard to read - it just moves the
        // confusion to whoever compares this against the table. Reported by Ed.
        const UTC_STAMP = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/;

        function annotateStamps(value) {
            if (Array.isArray(value)) return value.map(annotateStamps);
            if (value && typeof value === 'object') {
                const out = {};
                for (const k of Object.keys(value)) out[k] = annotateStamps(value[k]);
                return out;
            }
            if (typeof value !== 'string' || !UTC_STAMP.test(value)) return value;

            const d = parseUTCDate(value);
            if (!d || isNaN(d.getTime())) return value;

            const local = fmtDateTime(d);
            // Say nothing extra when the analyst is on UTC anyway - the note would
            // be pure noise, and repeating the same time twice invites the reader
            // to hunt for a difference that is not there.
            return local === value ? value + ' UTC' : value + ' UTC  (' + local + ' your time)';
        }

        function showLogJson(index) {
            const log = currentLogs[index];
            if (!log) return;

            const jsonContent = document.getElementById('jsonContent');
            jsonContent.textContent = JSON.stringify(annotateStamps(log.details), null, 2);

            document.getElementById('jsonModal').classList.add('active');
        }

        function closeJsonModal() {
            document.getElementById('jsonModal').classList.remove('active');
        }

        // ================================================================
        //  Background jobs (#182) - health first, then the run history.
        //  Worked out by the server when the tab is opened; nothing here
        //  runs a job. See includes/job_health.php for the rules.
        // ================================================================
        let jobsData = null;
        const jobsHist = { job: '', outcome: '', trigger: '', days: '30', offset: 0, limit: 50, total: 0 };
        const JT = (k, p) => t('reporting.jobs.' + k, p);

        function fmtUtc(s) { return s ? fmtDateTime(parseUTCDate(s)) : '-'; }

        function jobProblemHtml(j, p) {
            const trig = p.other_trigger ? JT('trigger_' + p.other_trigger) : '';
            switch (p.code) {
                case 'stuck':
                    return `<div class="job-problem">${escapeHtml(JT('problem_stuck', { since: fmtUtc(p.since) }))}</div>`;
                case 'failing':
                    return `<div class="job-problem">${escapeHtml(JT('problem_failing', { since: fmtUtc(p.since) }))}${p.message ? '<br><span class="job-sub">' + escapeHtml(p.message) + '</span>' : ''}</div>`;
                case 'overdue':
                    return `<div class="job-problem">${escapeHtml(JT('problem_overdue', { since: fmtUtc(p.since) }))}${p.other_runs ? '<br><span class="job-sub">' + escapeHtml(JT('problem_other_runs', { trigger: trig })) + '</span>' : ''}</div>`;
                case 'never_seen':
                    return `<div class="job-problem">${escapeHtml(JT(p.other_runs ? 'problem_never_seen_other' : 'problem_never_seen', { trigger: trig }))}<br><span class="job-sub">${escapeHtml(JT('schedule_hint'))} <code>${escapeHtml(j.command)}</code></span></div>`;
                case 'partial':
                    return `<div class="job-problem">${escapeHtml(JT('problem_partial'))}${p.sources && p.sources.length
                        ? '<ul class="job-sources">' + p.sources.map(s => `<li><strong>${escapeHtml(s.label || s.key)}</strong>${s.message ? ' - ' + escapeHtml(s.message) : ''} <span class="job-sub">(${escapeHtml(fmtUtc(s.at))})</span></li>`).join('') + '</ul>'
                        : (p.message ? '<br><span class="job-sub">' + escapeHtml(p.message) + '</span>' : '')}</div>`;
                case 'unknown':
                    // #182 review: something needed to judge the job could not be read.
                    return `<div class="job-problem">${escapeHtml(JT('problem_unknown', { what: JT('unknown_' + String(p.what || 'history').replace(/ /g, '_')) }))}<br><span class="job-sub">${escapeHtml(JT('problem_unknown_hint'))}</span></div>`;
            }
            return '';
        }

        function intervalText(min) {
            if (min === 1440) return JT('every_day');
            if (min === 60) return JT('every_hour');
            if (min % 1440 === 0) return JT('every_days', { n: min / 1440 });
            if (min % 60 === 0) return JT('every_hours', { n: min / 60 });
            return JT('every_minutes', { n: min });
        }

        // #233: is the one-line scheduler (cron/run.php) running?
        function schedulerHtml(s) {
            if (!s) return '';
            // Docker on its UTC default: daily times would be UTC, which nobody expects.
            const tz = s.tz_unset ? `<div class="jobs-scheduler warn">${escapeHtml(JT('scheduler_tz_unset'))}</div>` : '';
            if (s.running) return `<div class="jobs-scheduler ok">${escapeHtml(JT('scheduler_running', { when: fmtUtc(s.last_beat) }))}</div>` + tz;
            // The Docker image runs it itself: there is no line to add, only a container to look at.
            if (s.builtin) return `<div class="jobs-scheduler warn">${escapeHtml(JT('scheduler_container_silent'))}</div>` + tz;
            const why = s.last_beat ? JT('scheduler_stopped', { when: fmtUtc(s.last_beat) }) : JT('scheduler_none');
            return `<div class="jobs-scheduler${s.last_beat ? ' warn' : ''}">${escapeHtml(why)}<code>${escapeHtml(s.line)}</code></div>` + tz + fallbackHtml(s);
        }

        // #233: the fallback - due jobs started from analysts' pages when no scheduled task runs.
        // Off by default; its limits are said here, where it is switched on, not discovered later.
        function fallbackHtml(s) {
            const can = jobsData && jobsData.can_configure;
            if (!can && !s.fallback) return '';
            let state = '';
            if (s.fallback) state = s.fallback_beat ? JT('fallback_last', { when: fmtUtc(s.fallback_beat) }) : JT('fallback_not_yet');
            const err = s.fallback && s.fallback_error ? `<div class="hint">${escapeHtml(JT('fallback_error', { when: fmtUtc(s.fallback_error) }))}</div>` : '';
            return `<div class="jobs-scheduler job-form${s.fallback ? ' warn' : ''}">
                <label><input type="checkbox" id="jobFallback" ${s.fallback ? 'checked' : ''} ${can ? '' : 'disabled'} onchange="saveFallback(this.checked)"> ${escapeHtml(JT('fallback_label'))}</label>
                <div class="hint">${escapeHtml(JT('fallback_hint'))}</div>
                ${state ? `<div class="hint">${escapeHtml(state)}</div>` : ''}${err}
            </div>`;
        }

        async function saveFallback(on) {
            const res = await fetch(API_BASE + 'job_health.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'fallback', on })
            });
            const d = await res.json();
            if (!d.success) { alert(d.error || ''); return; }
            loadJobs();
        }

        // A job's schedule, under its expected interval - only once the scheduler has run.
        function scheduleText(d, j) {
            const s = (d.schedules || {})[j.key];
            if (!s || !d.scheduler || !d.scheduler.last_beat) return '';
            let what = JT('starts_off');
            if (s.mode === 'interval') what = JT('starts', { when: intervalText(+s.interval_minutes) });
            if (s.mode === 'daily') what = JT('starts_daily', { time: s.at_time, tz: d.scheduler.timezone });
            // A job that is not needed is not started, so it has no next start to show.
            const waiting = j.status === 'not_needed' || j.status === 'not_used';
            const next = s.mode !== 'off' && s.next_due_at && !waiting ? ' · ' + JT('next_start', { when: fmtUtc(s.next_due_at) }) : '';
            return '<br>' + escapeHtml(what + next);
        }

        async function loadJobs() {
            const container = document.getElementById('logsTableContainer');
            container.innerHTML = `<div class="loading"><div class="spinner"></div><div>${escapeHtml(t('reporting.logs.loading'))}</div></div>`;
            clearPagination();
            try {
                const res = await fetch(API_BASE + 'job_health.php?view=health');
                jobsData = await res.json();
                if (!jobsData.success) throw new Error(jobsData.error || 'error');
                renderJobs();
                loadJobRuns();
            } catch (e) {
                container.innerHTML = `<div class="empty-state">${escapeHtml(t('reporting.logs.load_error', { error: e.message }))}</div>`;
            }
        }

        function renderJobs() {
            const d = jobsData;
            const order = { stuck: 0, failing: 1, overdue: 2, never_seen: 3, partial: 4, healthy: 5, not_used: 6, not_needed: 7 };
            const jobs = d.jobs.slice().sort((a, b) => (order[a.status] - order[b.status]) || a.name.localeCompare(b.name));
            const options = d.jobs.map(j => `<option value="${escapeHtml(j.key)}"${jobsHist.job === j.key ? ' selected' : ''}>${escapeHtml(j.name)}</option>`).join('');
            document.getElementById('logsTableContainer').innerHTML = `
                <div class="jobs-meta">${escapeHtml(JT('meta', { checked: fmtUtc(d.checked_utc), months: d.retention_months, since: fmtUtc(d.recording_since) }))}</div>
                ${schedulerHtml(d.scheduler)}
                <h3 class="jobs-section-title">${escapeHtml(JT('health_heading'))}</h3>
                <table class="logs-table jobs-table" data-log-type="jobs">
                    <thead><tr>
                        <th>${escapeHtml(JT('col_job'))}</th>
                        <th>${escapeHtml(JT('col_health'))}</th>
                        <th>${escapeHtml(JT('col_last_scheduled'))}</th>
                        <th>${escapeHtml(JT('col_last_run'))}</th>
                        <th>${escapeHtml(JT('col_expected'))}</th>
                        ${d.can_configure ? '<th></th>' : ''}
                    </tr></thead>
                    <tbody>${jobs.map(j => `
                        <tr>
                            <td><div class="job-name">${escapeHtml(j.name)}</div><div class="job-affects">${escapeHtml(j.affects)}</div></td>
                            <td><span class="job-pill ${escapeHtml(j.status)}">${escapeHtml(JT('status_' + j.status))}</span>
                                ${(j.problems || []).map(p => jobProblemHtml(j, p)).join('')}
                                ${j.not_used ? `<div class="job-sub">${escapeHtml(JT('not_used_by', { who: j.not_used.by || '-', when: fmtUtc(j.not_used.at) }))}</div>` : ''}
                                ${j.refused ? `<div class="job-sub">${escapeHtml(JT('refused', { reason: j.refused.reason, when: fmtUtc(j.refused.at) }))}</div>` : ''}</td>
                            <td class="log-datetime">${escapeHtml(fmtUtc(j.last_scheduled_success))}</td>
                            <td>${j.last_run ? `<span class="job-pill ${escapeHtml(j.last_run.outcome)}">${escapeHtml(JT('outcome_' + j.last_run.outcome))}</span>
                                <div class="job-sub">${escapeHtml(JT('trigger_' + j.last_run.trigger))} · ${escapeHtml(fmtUtc(j.last_run.started))}</div>` : '<span class="job-sub">-</span>'}</td>
                            <td class="job-sub">${escapeHtml(intervalText(j.interval))}${j.interval !== j.default_interval ? '<br>' + escapeHtml(JT('changed_from', { d: intervalText(j.default_interval) })) : ''}${scheduleText(d, j)}</td>
                            ${d.can_configure ? `<td><button class="job-edit-btn" onclick="editJob('${escapeHtml(j.key)}')">${escapeHtml(JT('edit'))}</button></td>` : ''}
                        </tr>`).join('')}
                    </tbody>
                </table>
                <h3 class="jobs-section-title">${escapeHtml(JT('history_heading'))}</h3>
                <div class="jobs-filters">
                    <select onchange="jobsFilter('job', this.value)" aria-label="${escapeHtml(JT('col_job'))}"><option value="">${escapeHtml(JT('all_jobs'))}</option>${options}</select>
                    <select onchange="jobsFilter('outcome', this.value)" aria-label="${escapeHtml(JT('col_outcome'))}">
                        <option value="">${escapeHtml(JT('all_outcomes'))}</option>
                        ${['ok', 'partial', 'failed', 'skipped', 'running'].map(o => `<option value="${o}"${jobsHist.outcome === o ? ' selected' : ''}>${escapeHtml(JT('outcome_' + o))}</option>`).join('')}
                    </select>
                    <select onchange="jobsFilter('trigger', this.value)" aria-label="${escapeHtml(JT('col_trigger'))}">
                        <option value="">${escapeHtml(JT('all_triggers'))}</option>
                        ${['cron', 'web', 'page', 'manual'].map(o => `<option value="${o}"${jobsHist.trigger === o ? ' selected' : ''}>${escapeHtml(JT('trigger_' + o))}</option>`).join('')}
                    </select>
                    <select onchange="jobsFilter('days', this.value)" aria-label="${escapeHtml(JT('period'))}">
                        ${[['1', 'period_day'], ['7', 'period_week'], ['30', 'period_month'], ['365', 'period_year'], ['', 'period_all']].map(([v, k]) => `<option value="${v}"${jobsHist.days === v ? ' selected' : ''}>${escapeHtml(JT(k))}</option>`).join('')}
                    </select>
                </div>
                <div id="jobRunsContainer"></div>`;
        }

        function jobsFilter(k, v) { jobsHist[k] = v; jobsHist.offset = 0; loadJobRuns(); }

        async function loadJobRuns() {
            const box = document.getElementById('jobRunsContainer');
            if (!box) return;
            const q = new URLSearchParams({ view: 'runs', job: jobsHist.job, outcome: jobsHist.outcome, trigger: jobsHist.trigger,
                                            days: jobsHist.days, limit: jobsHist.limit, offset: jobsHist.offset });
            const res = await fetch(API_BASE + 'job_health.php?' + q);
            const d = await res.json();
            if (!d.success) { box.innerHTML = `<div class="empty-state">${escapeHtml(d.error || '')}</div>`; return; }
            jobsHist.total = d.total;
            if (!d.runs.length) { box.innerHTML = `<div class="empty-state">${escapeHtml(JT('no_runs'))}</div>`; return; }
            const page = Math.floor(jobsHist.offset / jobsHist.limit) + 1, pages = Math.ceil(d.total / jobsHist.limit);
            box.innerHTML = `
                <table class="logs-table jobs-table" data-log-type="job-runs">
                    <thead><tr>
                        <th>${escapeHtml(JT('col_started'))}</th><th>${escapeHtml(JT('col_job'))}</th><th>${escapeHtml(JT('col_trigger'))}</th>
                        <th>${escapeHtml(JT('col_outcome'))}</th><th>${escapeHtml(JT('col_duration'))}</th><th>${escapeHtml(JT('col_summary'))}</th>
                    </tr></thead>
                    <tbody>${d.runs.map(r => `
                        <tr class="job-row-clickable" onclick="showJobRun(${r.id})" title="${escapeHtml(JT('row_title'))}">
                            <td class="log-datetime">${escapeHtml(fmtUtc(r.started_datetime))}</td>
                            <td>${escapeHtml(r.job_name)}</td>
                            <td class="job-sub">${escapeHtml(JT('trigger_' + r.trigger_kind))}${r.analyst_name ? '<br>' + escapeHtml(r.analyst_name) : ''}</td>
                            <td><span class="job-pill ${escapeHtml(r.outcome)}">${escapeHtml(JT('outcome_' + r.outcome))}</span>${+r.failed_sources ? '<div class="job-sub">' + escapeHtml(JT('failed_sources', { n: r.failed_sources })) + '</div>' : ''}</td>
                            <td class="job-sub">${r.duration_ms !== null ? escapeHtml(JT('ms', { n: r.duration_ms })) : '-'}</td>
                            <td class="log-details">${escapeHtml(r.summary || '')}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>
                <div class="jobs-pager">
                    <button onclick="jobsPage(-1)" ${jobsHist.offset === 0 ? 'disabled' : ''}>${escapeHtml(t('reporting.logs.prev'))}</button>
                    <span class="job-sub">${escapeHtml(t('reporting.logs.pagination', { current: page, total: pages, count: d.total }))}</span>
                    <button onclick="jobsPage(1)" ${jobsHist.offset + jobsHist.limit >= d.total ? 'disabled' : ''}>${escapeHtml(t('reporting.logs.next'))}</button>
                </div>`;
        }

        function jobsPage(dir) { jobsHist.offset = Math.max(0, jobsHist.offset + dir * jobsHist.limit); loadJobRuns(); }

        function openJobModal(title, body, footer) {
            document.getElementById('jobModalTitle').textContent = title;
            document.getElementById('jobModalBody').innerHTML = body;
            document.getElementById('jobModalFooter').innerHTML = footer;
            document.getElementById('jobModal').classList.add('active');
        }
        function closeJobModal() { document.getElementById('jobModal').classList.remove('active'); }
        const CLOSE_BTN = () => `<button class="btn btn-secondary" onclick="closeJobModal()">${escapeHtml(t('reporting.logs.close'))}</button>`;

        async function showJobRun(id) {
            const res = await fetch(API_BASE + 'job_health.php?view=run&id=' + id);
            const d = await res.json();
            if (!d.success) return;
            const r = d.run;
            const counts = Object.entries(r.counts || {}).map(([k, v]) => `${escapeHtml(k)}: ${escapeHtml(String(v))}`).join(', ');
            const name = (jobsData.jobs.find(j => j.key === r.job_key) || {}).name || r.job_key;
            openJobModal(name + ' #' + r.id, `
                <dl>
                    <dt>${escapeHtml(JT('col_outcome'))}</dt><dd><span class="job-pill ${escapeHtml(r.outcome)}">${escapeHtml(JT('outcome_' + r.outcome))}</span></dd>
                    <dt>${escapeHtml(JT('col_trigger'))}</dt><dd>${escapeHtml(JT('trigger_' + r.trigger_kind))}${r.analyst_name ? ' - ' + escapeHtml(r.analyst_name) : ''}</dd>
                    <dt>${escapeHtml(JT('col_started'))}</dt><dd>${escapeHtml(fmtUtc(r.started_datetime))}</dd>
                    <dt>${escapeHtml(JT('col_finished'))}</dt><dd>${escapeHtml(fmtUtc(r.finished_datetime))}</dd>
                    <dt>${escapeHtml(JT('col_summary'))}</dt><dd>${escapeHtml(r.summary || '-')}</dd>
                    ${counts ? `<dt>${escapeHtml(JT('col_counts'))}</dt><dd>${counts}</dd>` : ''}
                </dl>
                ${d.sources.length ? `<table class="logs-table"><thead><tr><th>${escapeHtml(JT('col_source'))}</th><th>${escapeHtml(JT('col_outcome'))}</th><th>${escapeHtml(JT('col_message'))}</th></tr></thead><tbody>
                    ${d.sources.map(s => `<tr><td>${escapeHtml(s.source_label || s.source_key)}</td><td><span class="job-pill ${+s.ok ? 'ok' : 'failed'}">${escapeHtml(JT(+s.ok ? 'outcome_ok' : 'outcome_failed'))}</span></td><td class="log-details">${escapeHtml(s.message || '')}</td></tr>`).join('')}
                </tbody></table>` : ''}`, CLOSE_BTN());
        }

        function editJob(key) {
            const j = jobsData.jobs.find(x => x.key === key);
            if (!j) return;
            if (!j.pushed) return editJobSchedule(j);
            openJobModal(j.name, `
                <div class="job-form">
                    <label for="jobInterval">${escapeHtml(JT('interval_label'))}</label>
                    <input type="number" id="jobInterval" min="1" value="${j.interval !== j.default_interval ? j.interval : ''}" placeholder="${j.default_interval}">
                    <div class="hint">${escapeHtml(JT('interval_hint', { d: intervalText(j.default_interval) }))}</div>
                    <label><input type="checkbox" id="jobNotUsed" ${j.not_used ? 'checked' : ''}> ${escapeHtml(JT('not_used_label'))}</label>
                    <div class="hint">${escapeHtml(JT('not_used_hint'))}</div>
                </div>`,
                `<button class="btn btn-secondary" onclick="closeJobModal()">${escapeHtml(JT('cancel'))}</button>
                 <button class="btn btn-primary" onclick="saveJob('${escapeHtml(key)}')">${escapeHtml(JT('save'))}</button>`);
        }

        // #233: a job FreeITSM runs - its schedule, which is also what health expects of it.
        function editJobSchedule(j) {
            const s = (jobsData.schedules || {})[j.key] || {};
            const mode = j.not_used ? 'off' : (s.mode === 'daily' ? 'daily' : 'interval');
            const sch = jobsData.scheduler || {};
            const radio = (m, label) => `<label class="job-mode"><input type="radio" name="jobMode" value="${m}" ${mode === m ? 'checked' : ''} onchange="jobModeChanged()"> ${escapeHtml(label)}</label>`;
            openJobModal(j.name, `
                <div class="job-form">
                    ${radio('interval', JT('mode_interval'))}
                    <div class="job-mode-detail" data-mode="interval">
                        <input type="number" id="jobInterval" min="1" value="${j.interval !== j.default_interval && mode === 'interval' ? j.interval : ''}" placeholder="${j.default_interval}" aria-label="${escapeHtml(JT('mode_interval'))}">
                        <div class="hint">${escapeHtml(JT('interval_default', { d: intervalText(j.default_interval) }))}</div>
                    </div>
                    ${radio('daily', JT('mode_daily'))}
                    <div class="job-mode-detail" data-mode="daily">
                        <input type="time" id="jobAtTime" value="${escapeHtml(s.at_time || '06:00')}" aria-label="${escapeHtml(JT('mode_daily'))}">
                        <span class="hint">${escapeHtml(sch.timezone || '')}</span>
                        <div class="hint">${escapeHtml(JT('daily_hint', { tz: sch.timezone || '' }))}</div>
                    </div>
                    ${radio('off', JT('mode_off'))}
                    <div class="job-mode-detail" data-mode="off"><div class="hint">${escapeHtml(JT('not_used_hint'))}</div></div>
                    <div class="hint job-mode-footnote">${escapeHtml(JT(sch.running ? 'schedule_hint_running' : 'schedule_hint_cron'))}</div>
                </div>`,
                `<button class="btn btn-secondary" onclick="closeJobModal()">${escapeHtml(JT('cancel'))}</button>
                 <button class="btn btn-primary" onclick="saveJobSchedule('${escapeHtml(j.key)}')">${escapeHtml(JT('save'))}</button>`);
            jobModeChanged();
        }

        function jobModeChanged() {
            const m = (document.querySelector('input[name="jobMode"]:checked') || {}).value;
            document.querySelectorAll('.job-mode-detail').forEach(el => { el.hidden = el.dataset.mode !== m; });
        }

        async function saveJobSchedule(key) {
            const mode = (document.querySelector('input[name="jobMode"]:checked') || {}).value || 'interval';
            const res = await fetch(API_BASE + 'job_health.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'config', job: key, mode, interval: document.getElementById('jobInterval').value,
                                       at_time: document.getElementById('jobAtTime').value })
            });
            const d = await res.json();
            if (!d.success) { alert(d.error || ''); return; }
            closeJobModal();
            loadJobs();
        }

        async function saveJob(key) {
            const res = await fetch(API_BASE + 'job_health.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'config', job: key, interval: document.getElementById('jobInterval').value,
                                       not_used: document.getElementById('jobNotUsed').checked })
            });
            const d = await res.json();
            if (!d.success) { alert(d.error || ''); return; }
            closeJobModal();
            loadJobs();
        }

        document.getElementById('jobModal').addEventListener('click', function(e) {
            if (e.target === this) closeJobModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closeJsonModal(); closeJobModal(); }
        });

        document.getElementById('jsonModal').addEventListener('click', function(e) {
            if (e.target === this) closeJsonModal();
        });
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
