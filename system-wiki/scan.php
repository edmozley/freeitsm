<?php
/**
 * System Wiki - Scan Management Page
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
require_once '../includes/theme.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('wiki');

$current_page = 'scan';
$path_prefix = '../';

$translationNamespaces = ['common', 'system-wiki'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('system-wiki.scan.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=68">
    <style>
        /* Pin the generic accent to the System Wiki red for this page */
        body { --accent: var(--wiki-accent, #c62828); }

        .wiki-scan {
            height: calc(100vh - 48px);
            overflow-y: auto;
            background: var(--app-bg, #f5f7fa);
        }
        .scan-content {
            max-width: 900px;
            margin: 0 auto;
            padding: 24px 20px;
        }
        .page-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text, #333);
            margin-bottom: 8px;
        }
        .page-subtitle {
            font-size: 13px;
            color: var(--text-dim, #888);
            margin-bottom: 20px;
        }

        .scan-actions {
            background: var(--surface, #fff);
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.06));
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .scan-btn {
            padding: 10px 24px;
            background: var(--wiki-accent, #c62828);
            color: var(--wiki-on-accent, #fff);
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.15s;
        }
        .scan-btn:hover { background: var(--wiki-accent-hover, #b71c1c); }
        .scan-btn:disabled { background: #ccc; cursor: not-allowed; }
        .scan-info {
            font-size: 13px;
            color: var(--text-dim, #888);
            line-height: 1.5;
        }
        .scan-info code {
            background: var(--surface-3, #f5f5f5);
            color: var(--text-muted, #666);
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 12px;
        }

        .history-card {
            background: var(--surface, #fff);
            border-radius: 8px;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.06));
            overflow: hidden;
        }
        .history-title {
            padding: 14px 20px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text, #333);
            border-bottom: 1px solid var(--border-soft, #eee);
        }
        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .history-table th {
            text-align: left;
            padding: 8px 16px;
            background: var(--surface-2, #f9f9f9);
            color: var(--text-muted, #666);
            font-weight: 600;
        }
        .history-table td {
            padding: 8px 16px;
            border-bottom: 1px solid var(--border-soft, #f5f5f5);
            color: var(--text, #333);
        }
        /* Scan state badges (completed / running / failed) are DATA — same in both modes */
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-badge.completed { background: #e8f5e9; color: #2e7d32; }
        .status-badge.running { background: #fff3e0; color: #e65100; }
        .status-badge.failed { background: #fce4ec; color: #c62828; }
        .no-data { text-align: center; padding: 40px; color: var(--text-faint, #aaa); font-size: 14px; }

        /* Dark-mode overrides for the few colours that stay hardcoded */
        [data-theme-mode="dark"] .scan-btn:disabled { background: #3a3f4a; color: #8b919c; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> block, or a rule at
         equal specificity loses on document order (Techniques §9).
         WARNING: includes/header.php emits a <style> INSIDE the BODY, which is
         later still (§24) - the hover-rail rules there need !important. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=138">
</head>
<body data-mobile-module="wiki" data-mobile-page="wiki-scan">
    <?php include 'includes/header.php'; ?>

    <div class="wiki-scan">
        <div class="scan-content">
            <div class="page-title"><?php echo htmlspecialchars(t('system-wiki.scan.heading')); ?></div>
            <div class="page-subtitle"><?php echo htmlspecialchars(t('system-wiki.scan.subtitle')); ?></div>

            <div class="scan-actions">
                <button class="scan-btn" id="scanBtn" onclick="triggerScan()"><?php echo htmlspecialchars(t('system-wiki.scan.run_now')); ?></button>
                <div class="scan-info">
                    <?php echo t('system-wiki.scan.info_html'); ?> <code>powershell -File system-wiki\scanner\Scan-Codebase.ps1</code>
                </div>
            </div>

            <div class="history-card">
                <div class="history-title"><?php echo htmlspecialchars(t('system-wiki.scan.history')); ?></div>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('system-wiki.scan.col_date')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.scan.col_status')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.scan.col_duration')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.scan.col_files')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.scan.col_functions')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.scan.col_classes')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.scan.col_scanned_by')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="historyBody">
                        <tr><td colspan="7" class="no-data"><?php echo htmlspecialchars(t('system-wiki.scan.loading')); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/wiki/';

        document.addEventListener('DOMContentLoaded', loadHistory);

        async function loadHistory() {
            try {
                const res = await fetch(API_BASE + 'get_scan_history.php');
                const data = await res.json();
                const tbody = document.getElementById('historyBody');

                if (!data.success || !data.scans.length) {
                    tbody.innerHTML = '<tr><td colspan="7" class="no-data">' + esc(window.t('system-wiki.scan.no_scans')) + '</td></tr>';
                    return;
                }

                tbody.innerHTML = data.scans.map(s => {
                    const started = new Date(s.started_at);
                    const dateStr = fmtDateTime(started);
                    const duration = s.duration_seconds !== null ? formatDuration(s.duration_seconds) : '-';

                    return `
                        <tr>
                            <td>${dateStr}</td>
                            <td><span class="status-badge ${s.status}">${s.status}</span></td>
                            <td>${duration}</td>
                            <td>${s.files_scanned}</td>
                            <td>${s.functions_found}</td>
                            <td>${s.classes_found}</td>
                            <td>${esc(s.scanned_by || '-')}</td>
                        </tr>
                        ${s.error_message ? `<tr><td colspan="7" style="color:var(--wiki-accent,#c62828);font-size:12px;padding:4px 16px 8px;">${esc(s.error_message)}</td></tr>` : ''}
                    `;
                }).join('');
            } catch (e) { console.error(e); }
        }

        async function triggerScan() {
            const btn = document.getElementById('scanBtn');
            btn.disabled = true;
            btn.textContent = window.t('system-wiki.scan.starting');

            try {
                const res = await fetch(API_BASE + 'trigger_scan.php', { method: 'POST' });
                const data = await res.json();

                if (data.success) {
                    btn.textContent = window.t('system-wiki.scan.triggered');
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.textContent = window.t('system-wiki.scan.run_now');
                        loadHistory();
                    }, 3000);
                } else {
                    btn.textContent = window.t('system-wiki.scan.error_prefix') + data.error;
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.textContent = window.t('system-wiki.scan.run_now');
                    }, 3000);
                }
            } catch (e) {
                console.error(e);
                btn.disabled = false;
                btn.textContent = window.t('system-wiki.scan.run_now');
            }
        }

        function formatDuration(seconds) {
            if (seconds < 60) return seconds + 's';
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return mins + 'm ' + secs + 's';
        }

        function esc(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
    <script src="../assets/js/mobile.js?v=57"></script>
</body>
</html>
