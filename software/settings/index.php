<?php
/**
 * Software Settings - API Key Management
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('software');

$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'software'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('software.settings.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* Module accent (indigo) — tabs, toggles, focus rings, shared buttons. */
        body { --accent: var(--sw-accent, #5c6bc0); --accent-hover: var(--sw-accent-hover, #3f51b5); }
        body { padding-top: 0; }
        /* Full-width settings page matching the canonical layout used by
           other modules' settings pages. */
        .settings-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
            margin: 0;
            padding: 16px 30px 24px;
        }

        /* Purple theme for Software tabs */
        .tab:hover { color: var(--accent, #5c6bc0); }
        .tab.active { color: var(--accent, #5c6bc0); border-bottom-color: var(--accent, #5c6bc0); }

        .section-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h3 {
            margin: 0;
            font-size: 16px;
            color: var(--text, #333);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-header h3 svg {
            color: var(--sw-accent, #5c6bc0);
        }

        .section-description {
            padding: 14px 24px;
            background: var(--surface-2, #f8f9fa);
            border-bottom: 1px solid var(--border-soft, #eee);
            font-size: 13px;
            color: var(--text-muted, #666);
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
            background: var(--accent, #5c6bc0);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--accent-hover, #3f51b5);
        }

        .btn-secondary {
            background: var(--surface-hover, #f5f5f5);
            color: var(--text, #333);
            border: 1px solid var(--border, #ddd);
        }

        .btn-secondary:hover {
            background: var(--border-soft, #eee);
        }

        /* Semantic action buttons — saturated fills with white text that read
           on both light and dark, so left hardcoded (like the status badges). */
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
            background: var(--surface-2, #f8f9fa);
            padding: 10px 24px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted, #555);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border, #e0e0e0);
        }

        .key-table tbody td {
            padding: 12px 24px;
            border-bottom: 1px solid var(--border-soft, #eee);
            font-size: 14px;
            color: var(--text, #333);
            vertical-align: middle;
        }

        .key-table tbody tr:last-child td {
            border-bottom: none;
        }

        .key-table tbody tr:hover {
            background: var(--surface-hover, #fafafa);
        }

        .api-key-value {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 13px;
            background: var(--surface-hover, #f5f5f5);
            padding: 4px 10px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            max-width: 360px;
            word-break: break-all;
        }

        .api-key-masked {
            color: var(--text-dim, #888);
        }

        .api-key-full {
            color: var(--text, #333);
        }

        .copy-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px;
            color: var(--text-dim, #888);
            display: inline-flex;
        }

        .copy-btn:hover {
            color: var(--sw-accent, #5c6bc0);
        }

        .status-active {
            display: inline-block;
            background: var(--success-bg, #e8f5e9);
            color: var(--success-text, #2e7d32);
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-revoked {
            display: inline-block;
            background: var(--danger-bg, #fce4ec);
            color: var(--danger-text, #c62828);
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .key-actions {
            display: flex;
            gap: 4px;
        }
        /* Icon-only row actions — matches the canonical settings tables
           in change-management / calendar / morning-checks. Module
           accent (Software purple) drives the hover state; danger
           variant goes red. */
        .action-btn {
            background: none;
            border: none;
            padding: 4px;
            color: var(--text-muted, #666);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            vertical-align: middle;
            cursor: pointer;
        }
        .action-btn:hover { background: none; border: none; color: var(--sw-accent, #5c6bc0); }
        .action-btn.delete:hover { color: var(--danger-text, #c62828); }
        .action-btn svg { width: 16px; height: 16px; }

        .empty-state {
            padding: 40px;
            text-align: center;
            color: var(--text-dim, #888);
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
            border: 3px solid var(--border-soft, #f3f3f3);
            border-top: 3px solid var(--sw-accent, #5c6bc0);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .new-key-banner {
            display: none;
            background: var(--success-bg, #e8f5e9);
            border: 1px solid var(--success-border, #a5d6a7);
            border-radius: 6px;
            padding: 16px 20px;
            margin: 16px 24px;
        }

        .new-key-banner p {
            margin: 0 0 10px 0;
            font-size: 13px;
            color: var(--success-text, #2e7d32);
            font-weight: 500;
        }

        .new-key-banner .key-display {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 14px;
            background: var(--surface, #fff);
            padding: 10px 14px;
            border-radius: 4px;
            border: 1px solid var(--success-border, #c8e6c9);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            word-break: break-all;
        }

        .new-key-banner .hint {
            margin: 10px 0 0 0;
            font-size: 12px;
            color: var(--text-muted, #666);
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
            background: var(--surface, #fff);
            border-radius: 8px;
            padding: 24px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 8px 30px var(--shadow, rgba(0,0,0,0.2));
        }

        .confirm-box h4 {
            margin: 0 0 12px 0;
            font-size: 16px;
            color: var(--text, #333);
        }

        .confirm-box p {
            margin: 0 0 20px 0;
            font-size: 14px;
            color: var(--text-muted, #666);
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

    <div class="settings-container">
        <div class="tabs">
            <button class="tab active" data-tab="api-keys" onclick="switchTab('api-keys')"><?php echo htmlspecialchars(t('software.settings.tab_api_keys')); ?></button>
        </div>

        <div class="tab-content active" id="api-keys-tab">
            <div class="section-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path>
                    </svg>
                    <?php echo htmlspecialchars(t('software.settings.heading')); ?>
                </h3>
                <div style="display:flex;align-items:center;gap:8px;">
                    <input type="text" id="keyLabelInput" placeholder="<?php echo htmlspecialchars(t('software.settings.label_input')); ?>" maxlength="100"
                           style="padding:7px 12px;border:1px solid var(--border, #ddd);border-radius:4px;font-size:13px;width:180px;background:var(--surface, #fff);color:var(--text, #333);">
                    <button class="btn btn-primary" onclick="generateKey()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        <?php echo htmlspecialchars(t('software.settings.generate')); ?>
                    </button>
                </div>
            </div>
            <div class="section-description">
                <?php echo htmlspecialchars(t('software.settings.intro_line1')); ?><br>
                <?php echo t('software.settings.intro_line2', ['header' => '<code>Authorization</code>']); ?>
            </div>
            <div id="newKeyBanner" class="new-key-banner">
                <p><?php echo htmlspecialchars(t('software.settings.banner_new')); ?></p>
                <div class="key-display">
                    <span id="newKeyValue"></span>
                    <button class="copy-btn" onclick="copyNewKey()" title="<?php echo htmlspecialchars(t('software.settings.banner_copy')); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                    </button>
                </div>
                <p class="hint"><?php echo htmlspecialchars(t('software.settings.banner_hint')); ?></p>
            </div>
            <div class="section-body">
                <table class="key-table">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('software.settings.col_key')); ?></th>
                            <th><?php echo htmlspecialchars(t('software.settings.col_label')); ?></th>
                            <th><?php echo htmlspecialchars(t('software.settings.col_owner')); ?></th>
                            <th><?php echo htmlspecialchars(t('software.settings.col_created')); ?></th>
                            <th><?php echo htmlspecialchars(t('software.settings.col_status')); ?></th>
                            <th><?php echo htmlspecialchars(t('software.settings.col_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="keyTableBody">
                        <tr><td colspan="6">
                            <div class="loading-spinner"><div class="spinner"></div></div>
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        const API_BASE = '../../api/software/';
        let allKeys = [];

        // Row-action SVGs. Power icon for the toggle (revoke / activate
        // — title attr distinguishes the two states) and the canonical
        // trash icon for delete.
        const ICON_TOGGLE = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path><line x1="12" y1="2" x2="12" y2="12"></line></svg>';
        const ICON_DELETE = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
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
                        '<tr><td colspan="6"><div class="empty-state">' + window.t('software.settings.load_error', { message: escapeHtml(data.error) }) + '</div></td></tr>';
                }
            } catch (error) {
                console.error('Error loading keys:', error);
                document.getElementById('keyTableBody').innerHTML =
                    '<tr><td colspan="6"><div class="empty-state">' + window.t('software.settings.load_failed') + '</div></td></tr>';
            }
        }

        function renderTable() {
            const tbody = document.getElementById('keyTableBody');

            if (allKeys.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state">' + window.t('software.settings.none') + '</div></td></tr>';
                return;
            }

            tbody.innerHTML = allKeys.map(key => {
                const masked = maskKey(key.apikey);
                const isActive = key.active == 1;
                const statusClass = isActive ? 'status-active' : 'status-revoked';
                const statusText = isActive ? window.t('software.settings.status_active') : window.t('software.settings.status_revoked');
                // Power icon is the same in both states; title attr tells
                // the user which way the click will toggle. delete class
                // is only used for the trash icon (red on hover).
                const toggleTitle = isActive ? window.t('software.settings.action_revoke') : window.t('software.settings.action_activate');
                const label = key.label || '<span style="color:var(--text-faint, #bbb)">—</span>';
                const owner = key.analyst_name || '<span style="color:var(--text-faint, #bbb)">—</span>';

                return `
                <tr>
                    <td>
                        <span class="api-key-value">
                            <span class="api-key-masked">${escapeHtml(masked)}</span>
                            <button class="copy-btn" onclick="copyKey('${escapeHtml(key.apikey)}')" title="${window.t('software.settings.copy_full')}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                            </button>
                        </span>
                    </td>
                    <td>${label}</td>
                    <td>${owner}</td>
                    <td>${formatDate(key.created_at)}</td>
                    <td><span class="${statusClass}">${statusText}</span></td>
                    <td>
                        <div class="key-actions">
                            <button class="action-btn" onclick="toggleKey(${key.id})" title="${toggleTitle}">${ICON_TOGGLE}</button>
                            <button class="action-btn delete" onclick="promptDelete(${key.id})" title="${window.t('software.settings.action_delete')}">${ICON_DELETE}</button>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        function maskKey(key) {
            if (!key || key.length < 8) return key || '';
            return key.substring(0, 6) + '\u2022'.repeat(20) + key.substring(key.length - 6);
        }

        // created_at is a server-stamped UTC timestamp — parse as UTC and render
        // in the analyst's display zone (kind 1).
        function formatDate(dateStr) {
            if (!dateStr) return '';
            const d = parseUTCDate(dateStr);
            if (!d || isNaN(d)) return dateStr;
            return d.toLocaleDateString('en-GB', tzOpts({ day: '2-digit', month: 'short', year: 'numeric' }))
                + ' ' + d.toLocaleTimeString('en-GB', tzOpts({ hour: '2-digit', minute: '2-digit' }));
        }

        async function generateKey() {
            const labelInput = document.getElementById('keyLabelInput');
            const label = labelInput.value.trim();
            try {
                const response = await fetch(API_BASE + 'generate_apikey.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ label: label || null })
                });
                const data = await response.json();
                if (data.success) {
                    newlyGeneratedKey = data.apikey;
                    document.getElementById('newKeyValue').textContent = data.apikey;
                    document.getElementById('newKeyBanner').style.display = 'block';
                    labelInput.value = '';
                    loadKeys();
                } else {
                    showToast(window.t('software.settings.generate_error', { message: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error generating key:', error);
                showToast(window.t('software.settings.generate_failed'), 'error');
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
                    showToast(window.t('software.settings.key_updated'), 'success');
                    loadKeys();
                } else {
                    showToast(window.t('software.settings.toggle_error', { message: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error toggling key:', error);
                showToast(window.t('software.settings.toggle_failed'), 'error');
            }
        }

        async function promptDelete(id) {
            const ok = await showConfirm({
                title: window.t('software.settings.delete_title'),
                message: window.t('software.settings.delete_confirm'),
                okLabel: window.t('software.settings.delete_ok'),
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const response = await fetch(API_BASE + 'delete_apikey.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('software.settings.key_deleted'), 'success');
                    loadKeys();
                } else {
                    showToast(window.t('software.settings.delete_error', { message: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error deleting key:', error);
                showToast(window.t('software.settings.delete_failed'), 'error');
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

    </script>
</body>
</html>
