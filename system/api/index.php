<?php
/**
 * System - API
 * Manage REST API v1 keys: create keys with granular per-resource permissions,
 * company scoping, expiry and rate limits. Links to the interactive docs page.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/tenancy.php';

$current_page = 'api';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

// The live base URL of the v1 API for this deployment.
$scheme = requestScheme();
$apiBaseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'api/v1';
// The MCP server (3.2.0) - api/mcp/, same keys, its own permission.
$mcpUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'api/mcp/';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.api.browser_title'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <style>
        /* System module accent (blue-grey) — pin the generic --accent to it. */
        body {
            /* System is the FIRST module whose DARK accent is a LIGHT colour (#90a4ae).
               inbox.css renders .btn-primary/.add-btn as background:var(--accent) +
               color:var(--on-accent) — and the global --on-accent stays WHITE in dark.
               So pinning --accent alone would put white text on a light button. Pin
               --on-accent too: it flips to near-black in dark. */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        /* Page layout, title and subtitle: assets/css/system-page.css (.sys-page). */
        .api-container { height: calc(100vh - 48px); overflow-y: auto; }

        .settings-card { background: var(--surface, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08)); margin-bottom: 24px; }
        .settings-card h3 { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 4px 0; }
        .settings-card .card-desc { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 20px 0; line-height: 1.5; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: #455a64; }
        .btn-secondary { background: var(--sys-accent-soft, #eceff1); color: #455a64; }
        .btn-secondary:hover { background: #cfd8dc; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .info-note { background: #f5f7fa; border: 1px solid var(--border, #e0e0e0); border-radius: 6px; padding: 14px 16px; font-size: 12px; color: var(--text-muted, #666); line-height: 1.6; }
        .info-note strong { color: var(--text, #333); }
        .base-url-box { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .base-url-box code { flex: 1; background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-radius: 4px; padding: 8px 10px; font-size: 12px; color: var(--text, #333); overflow-x: auto; white-space: nowrap; }

        .keys-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .add-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .add-btn:hover { background: #455a64; }
        table.keys { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.keys th { text-align: left; color: var(--text-dim, #888); font-weight: 600; font-size: 12px; padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #eee); }
        table.keys td { padding: 10px; border-bottom: 1px solid var(--border-soft, #f2f2f2); color: var(--text, #444); vertical-align: middle; }
        table.keys tr:last-child td { border-bottom: none; }
        .key-prefix { font-family: Consolas, Monaco, monospace; font-size: 12px; color: var(--text-dim, #888); }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-badge.on { background: #e8f5e9; color: #2e7d32; }
        .status-badge.off { background: #f0f0f0; color: #999; }
        .status-badge.expired { background: #fff3e0; color: #e65100; }
        .perm-count { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #e3f2fd; color: #1565c0; }
        .table-action-btn { background: none; border: none; cursor: pointer; color: #607d8b; padding: 4px 8px; font-size: 13px; border-radius: 4px; }
        .table-action-btn:hover { background: var(--sys-accent-soft, #eceff1); }
        .table-action-btn.danger:hover { background: #ffebee; color: #c62828; }
        .empty-row td { text-align: center; color: var(--text-faint, #aaa); padding: 24px; font-style: italic; }

        /* Modal — namespaced (apik-) so it doesn't inherit inbox.css's global
           .modal framework (opacity:0/visibility:hidden by default). */
        .apik-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        .apik-modal-overlay.open { display: flex; }
        .apik-modal { background: var(--surface, #fff); border-radius: 10px; width: 640px; max-width: 92vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .apik-modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border-soft, #eee); font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .apik-modal-body { padding: 20px 24px; }
        .apik-modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-soft, #eee); display: flex; justify-content: flex-end; gap: 10px; }
        .form-field { margin-bottom: 16px; }
        .form-field label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #444); margin-bottom: 4px; }
        .form-field .hint { font-size: 12px; color: var(--text-faint, #999); font-weight: 400; margin-bottom: 6px; }
        .form-field input[type=text], .form-field input[type=number], .form-field input[type=date], .form-field select {
            width: 100%; padding: 9px 11px; border: 1px solid var(--border, #ddd); border-radius: 5px; font-size: 13px; font-family: inherit; box-sizing: border-box; background: var(--surface, #fff); color: var(--text, #333);
        }
        .form-field input:focus, .form-field select:focus { outline: none; border-color: var(--sys-accent, #546e7a); }
        .form-2col { display: flex; gap: 14px; }
        .form-2col .form-field { flex: 1; }

        /* Permission matrix */
        .perm-matrix { border: 1px solid var(--border-soft, #eee); border-radius: 6px; overflow: hidden; }
        .perm-row { display: flex; align-items: flex-start; padding: 10px 12px; border-bottom: 1px solid var(--border-soft, #f2f2f2); gap: 12px; }
        .perm-row:last-child { border-bottom: none; }
        .perm-row:nth-child(even) { background: #fafbfc; }
        .perm-resource { width: 170px; flex: none; font-size: 13px; font-weight: 600; color: var(--text, #444); padding-top: 2px; }
        .perm-actions { display: flex; flex-wrap: wrap; gap: 4px 16px; flex: 1; }
        .perm-action { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-muted, #555); cursor: pointer; }
        .perm-action input { margin: 0; cursor: pointer; }
        .perm-toolbar { display: flex; justify-content: flex-end; gap: 14px; margin: 0 0 8px 0; font-size: 12px; }
        .perm-toolbar a { color: var(--sys-accent, #546e7a); cursor: pointer; text-decoration: underline; }

        /* Company scope */
        .scope-options { display: flex; flex-direction: column; gap: 8px; }
        .scope-option { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text, #444); cursor: pointer; }
        .company-checks { margin: 8px 0 0 24px; display: flex; flex-direction: column; gap: 6px; max-height: 140px; overflow-y: auto; }
        .company-checks label { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-muted, #555); cursor: pointer; }

        /* New-key reveal */
        .newkey-box { background: #f5f7fa; border: 1px solid var(--border, #e0e0e0); border-radius: 6px; padding: 14px 16px; margin-top: 10px; color: var(--text, #333); }
        .newkey-box code { display: block; background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-radius: 4px; padding: 10px 12px; font-size: 13px; color: var(--text, #333); word-break: break-all; margin: 8px 0; }
        .newkey-warning { font-size: 12px; color: #c62828; font-weight: 600; }

        /* GH #287: tabs, restrictions and Threat protection */
        .api-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border, #e0e0e0); margin: 0 0 24px 0; }
        .api-tab { background: none; border: none; border-bottom: 2px solid transparent; padding: 10px 16px; font-size: 13px; font-weight: 600; color: var(--text-dim, #888); cursor: pointer; margin-bottom: -1px; }
        .api-tab.active { color: var(--text, #333); border-bottom-color: var(--sys-accent, #546e7a); }
        .restrict-box { border: 1px solid var(--border-soft, #eee); border-radius: 6px; padding: 14px; margin-bottom: 16px; }
        .restrict-box h4 { margin: 0 0 2px; font-size: 13px; color: var(--text, #333); }
        .restrict-box .hint { font-size: 12px; color: var(--text-faint, #999); margin-bottom: 10px; }
        .form-field textarea { width: 100%; padding: 9px 11px; border: 1px solid var(--border, #ddd); border-radius: 5px; font-size: 13px; font-family: Consolas, Monaco, monospace; box-sizing: border-box; background: var(--surface, #fff); color: var(--text, #333); min-height: 64px; }
        .day-checks { display: flex; flex-wrap: wrap; gap: 4px 12px; margin: 6px 0 10px; }
        .day-checks label { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; color: var(--text-muted, #555); font-weight: 400; }
        .key-note { display: block; font-size: 11px; color: var(--text-dim, #888); margin-top: 3px; }
        .key-note.bad { color: #c62828; }
        .perm-action.sensitive { color: #b45309; font-weight: 600; }
        .tp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0 14px; }
        .tp-section { border-top: 1px solid var(--border-soft, #eee); padding-top: 16px; margin-top: 16px; }
        .tp-section:first-of-type { border-top: none; padding-top: 0; margin-top: 0; }
        .tp-section h4 { margin: 0 0 2px; font-size: 14px; color: var(--text, #333); }
        .tp-section p { margin: 0 0 12px; font-size: 12.5px; color: var(--text-dim, #888); line-height: 1.5; }
        .tp-filters { display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
        .tp-filters select { padding: 7px 10px; border: 1px solid var(--border, #ddd); border-radius: 5px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #333); }
        .tp-pager { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
        [data-theme-mode="dark"] .key-note.bad { color: #fca5a5; }
        [data-theme-mode="dark"] .perm-action.sensitive { color: #fbbf24; }
        .key-admin { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 10px; font-size: 10.5px; font-weight: 600; background: #fff3e0; color: #b45309; vertical-align: middle; }
        .admin-warn { display: none; margin-top: 6px; font-size: 12px; color: #b45309; }
        .ac-status { font-family: Consolas, Monaco, monospace; font-weight: 600; }
        .ac-status.bad { color: #c62828; }
        .ac-filters { display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
        .ac-filters select, .ac-filters input[type=text] { padding: 7px 10px; border: 1px solid var(--border, #ddd); border-radius: 5px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #333); }
        .ac-filters label { font-size: 13px; color: var(--text-muted, #555); display: inline-flex; gap: 6px; align-items: center; }
        [data-theme-mode="dark"] .key-admin { background: #3a2e12; color: #fcd34d; }
        [data-theme-mode="dark"] .admin-warn { color: #fbbf24; }
        [data-theme-mode="dark"] .ac-status.bad { color: #fca5a5; }
        /* ---------- Dark mode overrides ----------
           Light values above are deliberately unchanged; these only apply in dark. */
        [data-theme-mode="dark"] .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        [data-theme-mode="dark"] .btn-secondary { color: #cfd8dc; }
        [data-theme-mode="dark"] .btn-secondary:hover { background: #37474f; }
        [data-theme-mode="dark"] .add-btn:hover { background: var(--sys-accent-hover, #455a64); }
        [data-theme-mode="dark"] .info-note { background: #20242b; }
        [data-theme-mode="dark"] .newkey-box { background: #20242b; }
        [data-theme-mode="dark"] .newkey-warning { color: #fca5a5; }
        [data-theme-mode="dark"] .perm-row:nth-child(even) { background: #20242b; }
        [data-theme-mode="dark"] .status-badge.on { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .status-badge.off { background: #2a3039; color: #8b95a3; }
        [data-theme-mode="dark"] .status-badge.expired { background: #3a2e12; color: #fcd34d; }
        [data-theme-mode="dark"] .perm-count { background: #14324a; color: #7fc4f5; }
        [data-theme-mode="dark"] .table-action-btn { color: #90a4ae; }
        [data-theme-mode="dark"] .table-action-btn.danger:hover { background: #3a1a1d; color: #fca5a5; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
</head>
<body data-mobile-module="system" data-mobile-page="api">
    <?php include '../includes/header.php'; ?>

    <div class="api-container sys-page">
        <div class="sys-page-head"><div>
            <h1 class="sys-title"><?php echo htmlspecialchars(t('system.api.title')); ?></h1>
            <p class="sys-subtitle"><?php echo htmlspecialchars(t('system.api.subtitle')); ?></p>
        </div></div>
        <!-- GH #287: Keys | Threat protection -->
        <div class="api-tabs" role="tablist">
            <button class="api-tab active" data-tab="keys" role="tab"><?php echo htmlspecialchars(t('system.api.tab_keys')); ?></button>
            <button class="api-tab" data-tab="threats" role="tab"><?php echo htmlspecialchars(t('system.api.tab_threats')); ?></button>
            <button class="api-tab" data-tab="activity" role="tab"><?php echo htmlspecialchars(t('system.api.tab_activity')); ?></button>
        </div>
        <div id="tabKeys">

        <div class="settings-card">
            <h3 id="base-url"><?php echo htmlspecialchars(t('system.api.base_url')); ?></h3>
            <p class="card-desc"><?php echo t('system.api.base_url_desc'); ?></p>
            <div class="base-url-box">
                <code id="apiBaseUrl"><?php echo htmlspecialchars($apiBaseUrl); ?></code>
                <button class="btn btn-secondary" id="copyBaseBtn"><?php echo htmlspecialchars(t('common.copy')); ?></button>
                <a class="btn btn-primary" href="docs.php"><?php echo htmlspecialchars(t('system.api.documentation')); ?></a>
            </div>
            <p class="card-desc" style="margin-top:14px;">
                <?php echo t('system.api.docs_desc'); ?>
            </p>
        </div>

        <div class="settings-card">
            <h3 id="openapi"><?php echo htmlspecialchars(t('system.api.openapi')); ?></h3>
            <p class="card-desc">
                <?php echo t('system.api.openapi_desc'); ?>
            </p>
            <div class="base-url-box">
                <code><?php echo htmlspecialchars($apiBaseUrl); ?>/openapi.json</code>
                <a class="btn btn-secondary" href="<?php echo htmlspecialchars($apiBaseUrl); ?>/openapi.json" target="_blank" rel="noopener"><?php echo htmlspecialchars(t('system.api.view_json')); ?></a>
                <a class="btn btn-secondary" href="<?php echo htmlspecialchars($apiBaseUrl); ?>/openapi.yaml" target="_blank" rel="noopener"><?php echo htmlspecialchars(t('system.api.view_yaml')); ?></a>
            </div>
        </div>

        <div class="settings-card">
            <h3 id="mcp"><?php echo htmlspecialchars(t('system.api.mcp')); ?></h3>
            <p class="card-desc"><?php echo t('system.api.mcp_desc'); ?></p>
            <div class="base-url-box">
                <code id="mcpUrl"><?php echo htmlspecialchars($mcpUrl); ?></code>
            </div>
            <p class="card-desc" style="margin-top:14px;"><?php echo htmlspecialchars(t('system.api.mcp_claude_code')); ?></p>
            <div class="base-url-box">
                <code style="white-space:pre-wrap;word-break:break-all;">claude mcp add --transport http freeitsm <?php echo htmlspecialchars($mcpUrl); ?> --header "Authorization: Bearer YOUR_KEY"</code>
            </div>
        </div>

        <div class="settings-card">
            <div class="keys-head">
                <div>
                    <h3 id="api-keys" style="margin:0;"><?php echo htmlspecialchars(t('system.api.keys')); ?></h3>
                    <p class="card-desc" style="margin:4px 0 0;"><?php echo htmlspecialchars(t('system.api.keys_desc')); ?></p>
                </div>
                <button class="add-btn" id="addKeyBtn"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <div class="info-note" id="tableNotReady" style="display:none; margin-bottom:14px;">
                <?php echo t('system.api.not_ready'); ?>
            </div>
            <table class="keys">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('system.api.col_name')); ?></th><th><?php echo htmlspecialchars(t('system.api.col_key')); ?></th><th><?php echo htmlspecialchars(t('system.api.col_acts_as')); ?></th><th><?php echo htmlspecialchars(t('system.api.col_permissions')); ?></th>
                        <th class="company-col" style="display:none;"><?php echo htmlspecialchars(t('system.api.col_companies')); ?></th>
                        <th><?php echo htmlspecialchars(t('system.api.col_status')); ?></th><th><?php echo htmlspecialchars(t('system.api.col_last_used')); ?></th><th style="text-align:right;"><?php echo htmlspecialchars(t('system.api.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="keysBody">
                    <tr class="empty-row"><td colspan="8"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
        </div>
        <!-- GH #287: Threat protection -->
        <div id="tabThreats" style="display:none;">
            <div class="info-note" id="tpNotReady" style="display:none; margin-bottom:14px;"><?php echo htmlspecialchars(t('system.api.tp_not_ready')); ?></div>
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.api.tab_threats')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.api.tp_intro')); ?></p>
                <div class="tp-section">
                    <h4><?php echo htmlspecialchars(t('system.api.tp_downloads')); ?></h4>
                    <p><?php echo htmlspecialchars(t('system.api.tp_downloads_desc')); ?></p>
                    <div class="tp-grid">
                        <div class="form-field"><label for="tp_api_download_alert_files"><?php echo htmlspecialchars(t('system.api.tp_alert_files')); ?></label><input type="number" min="1" id="tp_api_download_alert_files"></div>
                        <div class="form-field"><label for="tp_api_download_alert_mb"><?php echo htmlspecialchars(t('system.api.tp_alert_mb')); ?></label><input type="number" min="1" id="tp_api_download_alert_mb"></div>
                        <div class="form-field"><label for="tp_api_download_limit_files"><?php echo htmlspecialchars(t('system.api.tp_limit_files')); ?></label><input type="number" min="1" id="tp_api_download_limit_files"></div>
                        <div class="form-field"><label for="tp_api_download_limit_mb"><?php echo htmlspecialchars(t('system.api.tp_limit_mb')); ?></label><input type="number" min="1" id="tp_api_download_limit_mb"></div>
                    </div>
                    <div class="form-field"><label for="tp_api_download_limit_action"><?php echo htmlspecialchars(t('system.api.tp_action')); ?></label>
                        <select id="tp_api_download_limit_action"><option value="refuse"><?php echo htmlspecialchars(t('system.api.tp_action_refuse')); ?></option><option value="suspend"><?php echo htmlspecialchars(t('system.api.tp_action_suspend')); ?></option></select></div>
                </div>
                <div class="tp-section">
                    <h4><?php echo htmlspecialchars(t('system.api.tp_probing')); ?></h4>
                    <p><?php echo htmlspecialchars(t('system.api.tp_probing_desc')); ?></p>
                    <div class="tp-grid">
                        <div class="form-field"><label for="tp_api_refusal_alert"><?php echo htmlspecialchars(t('system.api.tp_refusal_alert')); ?></label><input type="number" min="0" id="tp_api_refusal_alert"></div>
                        <div class="form-field"><label for="tp_api_refusal_limit"><?php echo htmlspecialchars(t('system.api.tp_refusal_limit')); ?></label><input type="number" min="0" id="tp_api_refusal_limit"></div>
                    </div>
                </div>
                <div class="tp-section">
                    <h4><?php echo htmlspecialchars(t('system.api.tp_guessing')); ?></h4>
                    <p><?php echo htmlspecialchars(t('system.api.tp_guessing_desc')); ?></p>
                    <div class="tp-grid">
                        <div class="form-field"><label for="tp_api_failed_auth_limit"><?php echo htmlspecialchars(t('system.api.tp_failed_limit')); ?></label><input type="number" min="0" id="tp_api_failed_auth_limit"></div>
                        <div class="form-field"><label for="tp_api_failed_auth_minutes"><?php echo htmlspecialchars(t('system.api.tp_failed_minutes')); ?></label><input type="number" min="1" id="tp_api_failed_auth_minutes"></div>
                    </div>
                </div>
                <div class="tp-section">
                    <h4><?php echo htmlspecialchars(t('system.api.tp_admin')); ?></h4>
                    <p><?php echo htmlspecialchars(t('system.api.tp_admin_desc')); ?></p>
                    <div class="form-field" style="max-width:360px;"><select id="tp_api_admin_keys" aria-label="<?php echo htmlspecialchars(t('system.api.tp_admin')); ?>"><option value="allow"><?php echo htmlspecialchars(t('system.api.tp_admin_allow')); ?></option><option value="require_ips"><?php echo htmlspecialchars(t('system.api.tp_admin_ips')); ?></option><option value="forbid"><?php echo htmlspecialchars(t('system.api.tp_admin_forbid')); ?></option></select></div>
                </div>
                <div class="tp-section">
                    <h4><?php echo htmlspecialchars(t('system.api.tp_new_ip')); ?></h4>
                    <p><?php echo htmlspecialchars(t('system.api.tp_new_ip_desc')); ?></p>
                    <div class="form-field" style="max-width:320px;"><label for="tp_api_new_ip_alert"><?php echo htmlspecialchars(t('system.api.tp_new_ip_alert')); ?></label>
                        <select id="tp_api_new_ip_alert"><option value="bell_email"><?php echo htmlspecialchars(t('system.api.tp_new_ip_both')); ?></option><option value="bell"><?php echo htmlspecialchars(t('system.api.tp_new_ip_bell')); ?></option><option value="off"><?php echo htmlspecialchars(t('system.api.tp_new_ip_off')); ?></option></select></div>
                </div>
                <div style="display:flex; justify-content:flex-end; align-items:center; gap:12px;"><span id="tpSaved" class="card-desc" style="margin:0;"></span><button class="btn btn-primary" id="tpSaveBtn"><?php echo htmlspecialchars(t('common.save')); ?></button></div>
            </div>
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.api.tp_log')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.api.tp_log_desc')); ?></p>
                <div class="tp-filters"><select id="tpKey"></select><select id="tpEvent"></select></div>
                <table class="keys"><thead><tr><th><?php echo htmlspecialchars(t('system.api.tp_col_when')); ?></th><th><?php echo htmlspecialchars(t('system.api.tp_col_key')); ?></th><th><?php echo htmlspecialchars(t('system.api.tp_col_event')); ?></th><th><?php echo htmlspecialchars(t('system.api.tp_col_address')); ?></th><th><?php echo htmlspecialchars(t('system.api.tp_col_detail')); ?></th></tr></thead>
                    <tbody id="tpLogBody"></tbody></table>
                <div class="tp-pager"><button class="btn btn-secondary" id="tpPrev"><?php echo htmlspecialchars(t('system.api.previous')); ?></button><button class="btn btn-secondary" id="tpNext"><?php echo htmlspecialchars(t('system.api.next')); ?></button></div>
            </div>
        </div>
        <!-- GH #287: Activity -->
        <div id="tabActivity" style="display:none;">
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.api.tab_activity')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.api.ac_intro')); ?> <strong id="acToday"></strong></p>
                <div class="ac-filters"><select id="acKey"></select><label><input type="checkbox" id="acFailures"> <?php echo htmlspecialchars(t('system.api.ac_failures_only')); ?></label><input type="text" id="acPath" placeholder="<?php echo htmlspecialchars(t('system.api.ac_path_ph')); ?>"></div>
                <table class="keys"><thead><tr><th><?php echo htmlspecialchars(t('system.api.tp_col_when')); ?></th><th><?php echo htmlspecialchars(t('system.api.tp_col_key')); ?></th><th><?php echo htmlspecialchars(t('system.api.ac_col_call')); ?></th><th><?php echo htmlspecialchars(t('system.api.ac_col_status')); ?></th><th style="text-align:right;"><?php echo htmlspecialchars(t('system.api.ac_col_ms')); ?></th><th style="text-align:right;"><?php echo htmlspecialchars(t('system.api.ac_col_size')); ?></th><th><?php echo htmlspecialchars(t('system.api.tp_col_address')); ?></th></tr></thead>
                    <tbody id="acBody"></tbody></table>
                <div class="tp-pager"><button class="btn btn-secondary" id="acPrev"><?php echo htmlspecialchars(t('system.api.previous')); ?></button><button class="btn btn-secondary" id="acNext"><?php echo htmlspecialchars(t('system.api.next')); ?></button></div>
            </div>
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.api.ac_settings')); ?></h3>
                <div class="tp-grid">
                    <div class="form-field"><label for="tp_api_log_successes"><?php echo htmlspecialchars(t('system.api.ac_successes')); ?></label><select id="tp_api_log_successes"><option value="1"><?php echo htmlspecialchars(t('system.api.ac_successes_yes')); ?></option><option value="0"><?php echo htmlspecialchars(t('system.api.ac_successes_no')); ?></option></select></div>
                    <div class="form-field"><label for="tp_api_log_keep_days"><?php echo htmlspecialchars(t('system.api.ac_keep_days')); ?></label><input type="number" min="1" max="3650" id="tp_api_log_keep_days"></div>
                    <div class="form-field"><label for="tp_api_log_old_rows"><?php echo htmlspecialchars(t('system.api.ac_old_rows')); ?></label><select id="tp_api_log_old_rows"><option value="delete"><?php echo htmlspecialchars(t('system.api.ac_old_delete')); ?></option><option value="archive"><?php echo htmlspecialchars(t('system.api.ac_old_archive')); ?></option></select></div>
                    <div class="form-field" id="acPeriodField"><label for="tp_api_log_archive_period"><?php echo htmlspecialchars(t('system.api.ac_period')); ?></label><select id="tp_api_log_archive_period"><option value="day"><?php echo htmlspecialchars(t('system.api.ac_period_day')); ?></option><option value="week"><?php echo htmlspecialchars(t('system.api.ac_period_week')); ?></option><option value="month"><?php echo htmlspecialchars(t('system.api.ac_period_month')); ?></option></select></div>
                </div>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.api.ac_job_note')); ?></p>
                <div style="display:flex; justify-content:flex-end; align-items:center; gap:12px;"><span id="acSaved" class="card-desc" style="margin:0;"></span><button class="btn btn-primary" id="acSaveBtn"><?php echo htmlspecialchars(t('common.save')); ?></button></div>
            </div>
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.api.ac_archives')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.api.ac_archives_desc')); ?></p>
                <table class="keys"><tbody id="acArchives"></tbody></table>
            </div>
        </div>
    </div>

    <!-- Create / edit modal -->
    <div class="apik-modal-overlay" id="keyModal">
        <div class="apik-modal">
            <div class="apik-modal-header" id="modalTitle"><?php echo htmlspecialchars(t('system.api.new_key')); ?></div>
            <div class="apik-modal-body">
                <input type="hidden" id="fKeyId">
                <div class="form-2col">
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.api.col_name')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.api.name_hint')); ?></div>
                        <input type="text" id="fName" placeholder="<?php echo htmlspecialchars(t('system.api.name_ph')); ?>">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.api.col_acts_as')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.api.acts_as_hint')); ?></div>
                        <select id="fAnalyst"></select>
                        <div class="admin-warn" id="fAdminWarn"><?php echo htmlspecialchars(t('system.api.admin_warning')); ?></div>
                    </div>
                </div>

                <div class="form-field">
                    <label><?php echo htmlspecialchars(t('system.api.col_permissions')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.api.perms_hint')); ?></div>
                    <div class="perm-toolbar"><a id="permAll"><?php echo htmlspecialchars(t('system.api.select_all')); ?></a><a id="permNone"><?php echo htmlspecialchars(t('system.api.clear_all')); ?></a></div>
                    <div class="perm-matrix" id="permMatrix"></div>
                </div>

                <div class="form-field" id="scopeField" style="display:none;">
                    <label><?php echo htmlspecialchars(t('system.api.scope')); ?></label>
                    <div class="scope-options">
                        <label class="scope-option"><input type="radio" name="scope" value="all" checked> <?php echo htmlspecialchars(t('system.api.scope_all')); ?></label>
                        <label class="scope-option"><input type="radio" name="scope" value="specific"> <?php echo htmlspecialchars(t('system.api.scope_specific')); ?></label>
                    </div>
                    <div class="company-checks" id="companyChecks" style="display:none;"></div>
                </div>

                <div class="form-2col">
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.api.expires')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.api.expires_hint')); ?></div>
                        <input type="date" id="fExpires">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.api.rate_limit')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.api.rate_limit_hint')); ?></div>
                        <input type="number" id="fRateLimit" min="1" placeholder="60">
                    </div>
                </div>
                <!-- GH #287: where and when, and this key's download limits -->
                <div class="restrict-box">
                    <h4><?php echo htmlspecialchars(t('system.api.restrict_title')); ?></h4>
                    <div class="hint"><?php echo htmlspecialchars(t('system.api.restrict_hint')); ?></div>
                    <div class="form-field">
                        <label for="fAllowedIps"><?php echo htmlspecialchars(t('system.api.allowed_ips')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.api.allowed_ips_hint')); ?></div>
                        <textarea id="fAllowedIps" placeholder="203.0.113.7&#10;10.20.0.0/16"></textarea>
                    </div>
                    <div class="form-field">
                        <label><input type="checkbox" id="fHoursOn"> <?php echo htmlspecialchars(t('system.api.hours_on')); ?></label>
                        <div id="fHoursBox" style="display:none;">
                            <div class="day-checks" id="fDays"></div>
                            <div class="form-2col">
                                <div class="form-field"><label for="fHoursFrom"><?php echo htmlspecialchars(t('system.api.hours_from')); ?></label><input type="text" id="fHoursFrom" placeholder="07:00"></div>
                                <div class="form-field"><label for="fHoursTo"><?php echo htmlspecialchars(t('system.api.hours_to')); ?></label><input type="text" id="fHoursTo" placeholder="19:00"></div>
                                <div class="form-field"><label for="fHoursMode"><?php echo htmlspecialchars(t('system.api.hours_mode')); ?></label><select id="fHoursMode"><option value="refuse"><?php echo htmlspecialchars(t('system.api.hours_refuse')); ?></option><option value="alert"><?php echo htmlspecialchars(t('system.api.hours_alert')); ?></option></select></div>
                            </div>
                            <div class="hint"><?php echo htmlspecialchars(t('system.api.hours_tz', ['tz' => date_default_timezone_get()])); ?></div>
                        </div>
                    </div>
                    <h4><?php echo htmlspecialchars(t('system.api.dl_title')); ?></h4>
                    <div class="hint"><?php echo htmlspecialchars(t('system.api.dl_hint')); ?></div>
                    <div class="form-2col">
                        <div class="form-field"><label for="fDlAlert"><?php echo htmlspecialchars(t('system.api.dl_alert_files')); ?></label><input type="number" min="1" id="fDlAlert"></div>
                        <div class="form-field"><label for="fDlLimit"><?php echo htmlspecialchars(t('system.api.dl_limit_files')); ?></label><input type="number" min="1" id="fDlLimit"></div>
                        <div class="form-field"><label for="fDlMb"><?php echo htmlspecialchars(t('system.api.dl_limit_mb')); ?></label><input type="number" min="1" id="fDlMb"></div>
                    </div>
                </div>
            </div>
            <div class="apik-modal-footer">
                <button class="btn btn-secondary" id="cancelBtn"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" id="saveBtn"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- New-key reveal modal -->
    <div class="apik-modal-overlay" id="revealModal">
        <div class="apik-modal">
            <div class="apik-modal-header"><?php echo htmlspecialchars(t('system.api.created')); ?></div>
            <div class="apik-modal-body">
                <div class="newkey-box">
                    <?php echo htmlspecialchars(t('system.api.your_new_key')); ?>
                    <code id="newKeyValue"></code>
                    <div class="newkey-warning"><?php echo htmlspecialchars(t('system.api.copy_now')); ?></div>
                </div>
            </div>
            <div class="apik-modal-footer">
                <button class="btn btn-secondary" id="copyKeyBtn"><?php echo htmlspecialchars(t('common.copy')); ?></button>
                <button class="btn btn-primary" id="revealCloseBtn"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <script>
    const API = '../../api/system/api_keys/';
    /** Shorthand for this page's namespace; window.t() comes from i18n.js. */
    const ap = (k, p) => t('system.api.' + k, p);
    let catalog = {};
    let analysts = [];
    let companies = [];
    let multiTenant = false;
    let keys = [];

    async function loadKeys() {
        const res = await fetch(API + 'list_keys.php');
        const data = await res.json();
        if (!data.success) { alert(data.error || 'Failed to load keys'); return; }
        catalog = data.catalog; analysts = data.analysts; companies = data.companies;
        multiTenant = data.multi_tenant; keys = data.keys;
        document.getElementById('tableNotReady').style.display = data.table_ready ? 'none' : 'block';
        document.querySelectorAll('.company-col').forEach(el => el.style.display = multiTenant ? '' : 'none');
        renderKeys();
    }

    function permSummary(perms) {
        let n = 0;
        Object.values(perms || {}).forEach(a => n += a.length);
        return n;
    }

    function esc(s) {
        const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function renderKeys() {
        const body = document.getElementById('keysBody');
        const colspan = multiTenant ? 8 : 7;
        if (!keys.length) {
            body.innerHTML = `<tr class="empty-row"><td colspan="${colspan}">${esc(ap('empty'))}</td></tr>`;
            return;
        }
        const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
        body.innerHTML = keys.map(k => {
            const expired = k.expires_at && k.expires_at <= now;
            const status = expired ? '<span class="status-badge expired">' + esc(ap('status_expired')) + '</span>'
                : k.active ? '<span class="status-badge on">' + esc(ap('status_active')) + '</span>'
                : '<span class="status-badge off">' + esc(ap('status_disabled')) + '</span>';
            let companyCell = '';
            if (multiTenant) {
                const names = k.company_ids === null ? ap('all')
                    : k.company_ids.map(id => (companies.find(c => c.id === id) || {name: '#' + id}).name).join(', ');
                companyCell = `<td>${esc(names)}</td>`;
            }
            // last_used_at is a server-stamped UTC timestamp (kind-1) — show in the analyst's zone.
        const lu = parseUTCDate(k.last_used_at);
        const lastUsed = lu ? esc(fmtDateTime(lu)) : esc(ap('never'));
            return `<tr>
                <td><strong>${esc(k.name)}</strong></td>
                <td class="key-prefix">${esc(k.key_prefix)}…</td>
                <td>${esc(k.analyst_name || '')}${k.analyst_is_admin ? `<span class="key-admin">${esc(ap('admin_badge'))}</span>` : ''}</td>
                <td><span class="perm-count">${esc(ap('perm_granted', { n: permSummary(k.permissions) }))}</span></td>
                ${companyCell}
                <td>${status}${k.suspended_reason && !k.active ? `<span class="key-note bad">${esc(ap('suspended', { reason: k.suspended_reason }))}</span>` : ''}${k.downloads_hour ? `<span class="key-note">${esc(ap('downloads_hour', { n: k.downloads_hour }))}</span>` : ''}${(k.allowed_ips || k.allowed_hours) ? `<span class="key-note">${esc(ap('restricted'))}</span>` : ''}${k.calls_today ? `<span class="key-note${k.failures_today ? ' bad' : ''}">${esc(ap('calls_today', { calls: k.calls_today, failures: k.failures_today }))}</span>` : ''}</td>
                <td>${lastUsed}</td>
                <td style="text-align:right; white-space:nowrap;">
                    <button class="table-action-btn" onclick="openEdit(${k.id})">${esc(t('common.edit'))}</button>
                    <button class="table-action-btn" onclick="toggleKey(${k.id}, ${k.active ? 'false' : 'true'})">${esc(k.active ? ap('disable') : ap('enable'))}</button>
                    <button class="table-action-btn danger" onclick="deleteKey(${k.id})">${esc(t('common.delete'))}</button>
                </td>
            </tr>`;
        }).join('');
    }

    // ---- Modal ----
    function buildMatrix(selected) {
        const matrix = document.getElementById('permMatrix');
        matrix.innerHTML = Object.entries(catalog).map(([resource, def]) => {
            const actions = Object.entries(def.actions).map(([action, desc]) => {
                const checked = selected && selected[resource] && selected[resource].includes(action) ? 'checked' : '';
                // GH #287: downloading documents is never ticked by Select all.
                const sensitive = resource === 'documents' && action === 'download';
                return `<label class="perm-action${sensitive ? ' sensitive' : ''}" title="${esc(desc + (sensitive ? ' - ' + ap('sensitive') : ''))}">
                    <input type="checkbox" data-resource="${esc(resource)}" data-action="${esc(action)}"${sensitive ? ' data-sensitive="1"' : ''} ${checked}> ${esc(action)}
                </label>`;
            }).join('');
            return `<div class="perm-row"><div class="perm-resource">${esc(def.label)}</div><div class="perm-actions">${actions}</div></div>`;
        }).join('');
    }

    function readMatrix() {
        const perms = {};
        document.querySelectorAll('#permMatrix input:checked').forEach(cb => {
            const r = cb.dataset.resource;
            (perms[r] = perms[r] || []).push(cb.dataset.action);
        });
        return perms;
    }

    function buildScope(companyIds) {
        const field = document.getElementById('scopeField');
        field.style.display = multiTenant ? '' : 'none';
        if (!multiTenant) return;
        const specific = Array.isArray(companyIds);
        document.querySelector('input[name=scope][value=all]').checked = !specific;
        document.querySelector('input[name=scope][value=specific]').checked = specific;
        const checks = document.getElementById('companyChecks');
        checks.style.display = specific ? '' : 'none';
        checks.innerHTML = companies.map(c => {
            const checked = specific && companyIds.includes(c.id) ? 'checked' : '';
            return `<label><input type="checkbox" value="${c.id}" ${checked}> ${esc(c.name)}</label>`;
        }).join('');
    }

    function openModal(key) {
        document.getElementById('modalTitle').textContent = key ? ap('edit_key') : ap('new_key');
        document.getElementById('fKeyId').value = key ? key.id : '';
        document.getElementById('fName').value = key ? key.name : '';
        document.getElementById('fExpires').value = key && key.expires_at ? key.expires_at.slice(0, 10) : '';
        document.getElementById('fRateLimit').value = key && key.rate_limit_per_minute ? key.rate_limit_per_minute : '';
        const sel = document.getElementById('fAnalyst');
        sel.innerHTML = analysts.map(a => `<option value="${a.id}">${esc(a.name)}${a.is_admin ? ' (' + esc(ap('admin_badge')) + ')' : ''}</option>`).join('');
        if (key) sel.value = key.analyst_id;
        const warnAdmin = () => { const a = analysts.find(x => String(x.id) === sel.value); document.getElementById('fAdminWarn').style.display = a && a.is_admin ? 'block' : 'none'; };
        sel.onchange = warnAdmin; warnAdmin();
        buildMatrix(key ? key.permissions : null);
        buildScope(key ? key.company_ids : null);
        // GH #287: where and when, and its download limits.
        document.getElementById('fAllowedIps').value = key && key.allowed_ips ? key.allowed_ips : '';
        const h = key && key.allowed_hours ? key.allowed_hours : null;
        document.getElementById('fHoursOn').checked = !!h;
        document.getElementById('fHoursBox').style.display = h ? '' : 'none';
        const dayNames = (window.DATE_FMT && DATE_FMT.weekdays_short) || ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        document.getElementById('fDays').innerHTML = [1,2,3,4,5,6,7].map(d => `<label><input type="checkbox" value="${d}" ${!h ? (d <= 5 ? 'checked' : '') : (h.days.includes(d) ? 'checked' : '')}> ${esc(dayNames[(d - 1) % 7] || d)}</label>`).join('');
        document.getElementById('fHoursFrom').value = h ? h.from : '07:00';
        document.getElementById('fHoursTo').value = h ? h.to : '19:00';
        document.getElementById('fHoursMode').value = h && h.mode === 'alert' ? 'alert' : 'refuse';
        document.getElementById('fDlAlert').value = key && key.download_alert_files ? key.download_alert_files : '';
        document.getElementById('fDlLimit').value = key && key.download_limit_files ? key.download_limit_files : '';
        document.getElementById('fDlMb').value = key && key.download_limit_mb ? key.download_limit_mb : '';
        document.getElementById('keyModal').classList.add('open');
    }

    function closeModal() { document.getElementById('keyModal').classList.remove('open'); }

    window.openEdit = function (id) {
        const key = keys.find(k => k.id === id);
        if (key) openModal(key);
    };

    window.toggleKey = async function (id, active) {
        const res = await fetch(API + 'update_key.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id, active: active === true || active === 'true'})
        });
        const data = await res.json();
        if (!data.success) { alert(data.error || ap('update_failed')); return; }
        loadKeys();
    };

    window.deleteKey = async function (id) {
        const key = keys.find(k => k.id === id);
        if (!confirm(ap('confirm_delete', { name: key ? key.name : id }))) return;
        const res = await fetch(API + 'delete_key.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: id})});
        const data = await res.json();
        if (!data.success) { alert(data.error || ap('delete_failed')); return; }
        loadKeys();
    };

    async function saveKey() {
        const id = document.getElementById('fKeyId').value;
        const payload = {
            name: document.getElementById('fName').value.trim(),
            analyst_id: parseInt(document.getElementById('fAnalyst').value, 10),
            permissions: readMatrix(),
            expires_at: document.getElementById('fExpires').value || null,
            rate_limit_per_minute: document.getElementById('fRateLimit').value || null,
            // GH #287
            allowed_ips: document.getElementById('fAllowedIps').value,
            allowed_hours: document.getElementById('fHoursOn').checked ? {
                days: Array.from(document.querySelectorAll('#fDays input:checked')).map(cb => parseInt(cb.value, 10)),
                from: document.getElementById('fHoursFrom').value.trim(), to: document.getElementById('fHoursTo').value.trim(),
                mode: document.getElementById('fHoursMode').value
            } : null,
            download_alert_files: document.getElementById('fDlAlert').value || null,
            download_limit_files: document.getElementById('fDlLimit').value || null,
            download_limit_mb: document.getElementById('fDlMb').value || null
        };
        if (multiTenant) {
            const specific = document.querySelector('input[name=scope][value=specific]').checked;
            payload.company_ids = specific
                ? Array.from(document.querySelectorAll('#companyChecks input:checked')).map(cb => parseInt(cb.value, 10))
                : null;
            if (specific && !payload.company_ids.length) { alert(ap('need_company')); return; }
        }
        if (!payload.name) { alert(ap('need_name')); return; }
        if (!Object.keys(payload.permissions).length) { alert(ap('need_permission')); return; }

        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        try {
            if (id) payload.id = parseInt(id, 10);
            const res = await fetch(API + (id ? 'update_key.php' : 'create_key.php'), {
                method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data.success) { alert(data.error || ap('save_failed')); return; }
            closeModal();
            if (!id && data.key) {
                document.getElementById('newKeyValue').textContent = data.key;
                document.getElementById('revealModal').classList.add('open');
            }
            loadKeys();
        } finally {
            btn.disabled = false;
        }
    }

    // ---- Wire up ----
    document.getElementById('addKeyBtn').addEventListener('click', () => openModal(null));
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('saveBtn').addEventListener('click', saveKey);
    document.getElementById('permAll').addEventListener('click', () => document.querySelectorAll('#permMatrix input:not([data-sensitive])').forEach(cb => cb.checked = true));
    document.getElementById('permNone').addEventListener('click', () => document.querySelectorAll('#permMatrix input').forEach(cb => cb.checked = false));
    document.querySelectorAll('input[name=scope]').forEach(r => r.addEventListener('change', () => {
        document.getElementById('companyChecks').style.display =
            document.querySelector('input[name=scope][value=specific]').checked ? '' : 'none';
    }));
    // ⚠️ These two copied in silence and reported nothing either way - and one
    // of them is an API KEY shown exactly once. copyToClipboard says whether it
    // worked; see clipboard.js.
    function copyInto(btnId, sourceId, failMsg) {
        document.getElementById(btnId).addEventListener('click', () => {
            const b = document.getElementById(btnId);
            copyToClipboard(document.getElementById(sourceId).textContent).then(ok => {
                const old = b.textContent;
                b.textContent = ok ? t('common.copied') : ap('copy_failed');
                setTimeout(() => { b.textContent = old; }, 1600);
                if (!ok && failMsg) alert(failMsg);
            });
        });
    }
    copyInto('copyBaseBtn', 'apiBaseUrl', null);
    copyInto('copyKeyBtn', 'newKeyValue',
             ap('copy_key_failed'));
    document.getElementById('revealCloseBtn').addEventListener('click', () => {
        if (confirm(ap('confirm_copied'))) {
            document.getElementById('revealModal').classList.remove('open');
        }
    });

    // ---- GH #287: tabs and Threat protection ----
    document.getElementById('fHoursOn').addEventListener('change', e => { document.getElementById('fHoursBox').style.display = e.target.checked ? '' : 'none'; });
    const TP_KEYS = ['api_admin_keys','api_download_alert_files','api_download_alert_mb','api_download_limit_files','api_download_limit_mb','api_download_limit_action','api_refusal_alert','api_refusal_limit','api_failed_auth_limit','api_failed_auth_minutes','api_new_ip_alert'];
    const TP_EVENTS = ['download_alert','download_limit','key_suspended','refused','ip_refused','outside_hours','admin_key_refused','new_ip','auth_failed','address_blocked','alert_not_emailed'];
    let tpPage = 1, tpLoaded = false, acPage = 1, acLoaded = false;
    const AC_KEYS = ['api_log_successes', 'api_log_keep_days', 'api_log_old_rows', 'api_log_archive_period'];
    // "One zip per" only matters when old rows are archived.
    const acPeriodShown = () => { document.getElementById('acPeriodField').style.display = document.getElementById('tp_api_log_old_rows').value === 'archive' ? '' : 'none'; };
    document.getElementById('tp_api_log_old_rows').addEventListener('change', acPeriodShown);
    async function loadActivitySettings() {
        const r = await fetch(API + 'threat_settings.php'); const d = await r.json();
        if (!d.success) return;
        AC_KEYS.forEach(k => { document.getElementById('tp_' + k).value = d.settings[k]; });
        acPeriodShown();
        document.getElementById('acKey').innerHTML = '<option value="">' + esc(ap('tp_all_keys')) + '</option><option value="none">' + esc(ap('ac_no_key')) + '</option>' + keys.map(k => `<option value="${k.id}">${esc(k.name)}</option>`).join('');
    }
    async function loadActivity() {
        const q = new URLSearchParams({ page: acPage });
        const kk = document.getElementById('acKey').value; if (kk) q.set('key_id', kk);
        if (document.getElementById('acFailures').checked) q.set('failures', '1');
        const p = document.getElementById('acPath').value.trim(); if (p) q.set('path', p);
        const r = await fetch(API + 'activity_log.php?' + q); const d = await r.json();
        const body = document.getElementById('acBody');
        if (!d.success) { body.innerHTML = `<tr class="empty-row"><td colspan="7">${esc(d.error || '')}</td></tr>`; return; }
        if (!d.ready) { body.innerHTML = `<tr class="empty-row"><td colspan="7">${esc(ap('tp_not_ready'))}</td></tr>`; return; }
        document.getElementById('acToday').textContent = ap('ac_today', { calls: d.summary.calls_today, failures: d.summary.failures_today });
        const size = b => b === null || b === undefined ? '' : (b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : b >= 1024 ? Math.round(b / 1024) + ' KB' : b + ' B');
        body.innerHTML = d.rows.length ? d.rows.map(e => {
            const when = parseUTCDate(e.created_datetime);
            const bad = Number(e.status) >= 400;
            return `<tr><td style="white-space:nowrap;">${esc(when ? fmtDateTime(when) : e.created_datetime)}</td><td>${esc(e.key_name || ap('ac_no_key'))}</td><td><span class="key-prefix">${esc(e.method)}</span> ${esc(e.path)}${e.channel === 'mcp' ? ' <span class="key-note" style="display:inline">MCP</span>' : ''}</td><td><span class="ac-status${bad ? ' bad' : ''}">${esc(e.status)}</span>${e.error_code ? ' <span class="key-note" style="display:inline">' + esc(e.error_code) + '</span>' : ''}</td><td style="text-align:right;">${esc(e.duration_ms ?? '')}</td><td style="text-align:right;">${esc(size(e.bytes_out))}</td><td class="key-prefix">${esc(e.ip_address || '')}</td></tr>`;
        }).join('') : `<tr class="empty-row"><td colspan="7">${esc(ap('ac_empty'))}</td></tr>`;
        document.getElementById('acPrev').disabled = acPage <= 1;
        document.getElementById('acNext').disabled = (d.page * d.per_page) >= d.total;
    }
    async function loadArchives() {
        const r = await fetch(API + 'activity_archive.php'); const d = await r.json();
        const body = document.getElementById('acArchives');
        if (!d.success || !d.archives.length) { body.innerHTML = `<tr class="empty-row"><td>${esc(ap('ac_no_archives'))}</td></tr>`; return; }
        body.innerHTML = d.archives.map(a => `<tr><td class="key-prefix">${esc(a.name)}</td><td style="text-align:right;">${esc((a.bytes / 1024).toFixed(1))} KB</td><td style="text-align:right; white-space:nowrap;"><a class="table-action-btn" href="${API}activity_archive.php?download=${encodeURIComponent(a.name)}">${esc(ap('ac_download'))}</a><button class="table-action-btn danger" data-archive="${esc(a.name)}">${esc(t('common.delete'))}</button></td></tr>`).join('');
        body.querySelectorAll('[data-archive]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm(ap('ac_confirm_delete', { name: b.dataset.archive }))) return;
            const rr = await fetch(API + 'activity_archive.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ delete: b.dataset.archive }) });
            const dd = await rr.json(); if (!dd.success) { alert(dd.error || ap('delete_failed')); return; }
            loadArchives();
        }));
    }
    document.getElementById('acKey').addEventListener('change', () => { acPage = 1; loadActivity(); });
    document.getElementById('acFailures').addEventListener('change', () => { acPage = 1; loadActivity(); });
    let acTimer; document.getElementById('acPath').addEventListener('input', () => { clearTimeout(acTimer); acTimer = setTimeout(() => { acPage = 1; loadActivity(); }, 300); });
    document.getElementById('acPrev').addEventListener('click', () => { if (acPage > 1) { acPage--; loadActivity(); } });
    document.getElementById('acNext').addEventListener('click', () => { acPage++; loadActivity(); });
    document.getElementById('acSaveBtn').addEventListener('click', async () => {
        const settings = {}; AC_KEYS.forEach(k => { settings[k] = document.getElementById('tp_' + k).value; });
        const btn = document.getElementById('acSaveBtn'); btn.disabled = true;
        try {
            const r = await fetch(API + 'threat_settings.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ settings }) });
            const d = await r.json();
            if (!d.success) { alert(d.error || ap('save_failed')); return; }
            document.getElementById('acSaved').textContent = ap('tp_saved');
            setTimeout(() => { document.getElementById('acSaved').textContent = ''; }, 2000);
        } finally { btn.disabled = false; }
    });
    function showTab(name) {
        document.querySelectorAll('.api-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
        document.getElementById('tabKeys').style.display = name === 'keys' ? '' : 'none';
        document.getElementById('tabThreats').style.display = name === 'threats' ? '' : 'none';
        document.getElementById('tabActivity').style.display = name === 'activity' ? '' : 'none';
        if (name === 'threats' && !tpLoaded) { tpLoaded = true; loadThreats(); }
        if (name === 'activity' && !acLoaded) { acLoaded = true; loadActivitySettings(); loadActivity(); loadArchives(); }
        try { history.replaceState(null, '', name === 'keys' ? location.pathname : '?tab=' + name); } catch (e) {}
    }
    document.querySelectorAll('.api-tab').forEach(b => b.addEventListener('click', () => showTab(b.dataset.tab)));
    async function loadThreats() {
        const r = await fetch(API + 'threat_settings.php'); const d = await r.json();
        if (!d.success) { alert(d.error || ap('save_failed')); return; }
        document.getElementById('tpNotReady').style.display = d.ready ? 'none' : 'block';
        TP_KEYS.forEach(k => { const el = document.getElementById('tp_' + k); if (el) el.value = d.settings[k]; });
        document.getElementById('tpKey').innerHTML = '<option value="">' + esc(ap('tp_all_keys')) + '</option>' + keys.map(k => `<option value="${k.id}">${esc(k.name)}</option>`).join('');
        document.getElementById('tpEvent').innerHTML = '<option value="">' + esc(ap('tp_all_events')) + '</option>' + TP_EVENTS.map(e => `<option value="${e}">${esc(ap('ev_' + e))}</option>`).join('');
        loadLog();
    }
    async function loadLog() {
        const q = new URLSearchParams({ page: tpPage });
        const kk = document.getElementById('tpKey').value, ev = document.getElementById('tpEvent').value;
        if (kk) q.set('key_id', kk); if (ev) q.set('event', ev);
        const r = await fetch(API + 'threat_log.php?' + q); const d = await r.json();
        const body = document.getElementById('tpLogBody');
        if (!d.success) { body.innerHTML = `<tr class="empty-row"><td colspan="5">${esc(d.error || '')}</td></tr>`; return; }
        body.innerHTML = d.events.length ? d.events.map(e => {
            const when = parseUTCDate(e.created_datetime);
            return `<tr><td style="white-space:nowrap;">${esc(when ? fmtDateTime(when) : e.created_datetime)}</td><td>${esc(e.key_name || ap('tp_no_key'))}</td><td>${esc(ap('ev_' + e.event))}</td><td class="key-prefix">${esc(e.ip_address || '')}</td><td>${esc(e.detail || '')}</td></tr>`;
        }).join('') : `<tr class="empty-row"><td colspan="5">${esc(ap('tp_no_events'))}</td></tr>`;
        document.getElementById('tpPrev').disabled = tpPage <= 1;
        document.getElementById('tpNext').disabled = (d.page * d.per_page) >= d.total;
    }
    document.getElementById('tpKey').addEventListener('change', () => { tpPage = 1; loadLog(); });
    document.getElementById('tpEvent').addEventListener('change', () => { tpPage = 1; loadLog(); });
    document.getElementById('tpPrev').addEventListener('click', () => { if (tpPage > 1) { tpPage--; loadLog(); } });
    document.getElementById('tpNext').addEventListener('click', () => { tpPage++; loadLog(); });
    document.getElementById('tpSaveBtn').addEventListener('click', async () => {
        const settings = {}; TP_KEYS.forEach(k => { const el = document.getElementById('tp_' + k); if (el) settings[k] = el.value; });
        const btn = document.getElementById('tpSaveBtn'); btn.disabled = true;
        try {
            const r = await fetch(API + 'threat_settings.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ settings }) });
            const d = await r.json();
            if (!d.success) { alert(d.error || ap('save_failed')); return; }
            document.getElementById('tpSaved').textContent = ap('tp_saved');
            setTimeout(() => { document.getElementById('tpSaved').textContent = ''; }, 2000);
        } finally { btn.disabled = false; }
    });

    loadKeys().then(() => { const tb = new URLSearchParams(location.search).get('tab'); if (tb === 'threats' || tb === 'activity') showTab(tb); });
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
