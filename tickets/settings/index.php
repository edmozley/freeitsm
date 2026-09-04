<?php
/**
 * Admin Settings - Manage Departments, Ticket Types, and Exchange Integration
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/ai_settings_panel.php';
I18n::initFromSession();
Tz::init();

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

// This page checked ONLY that you were logged in — it never checked module access, so any
// analyst could open the service desk's settings by typing the URL.
require_once '../../includes/settings_manifest.php';
requireModuleAccess('tickets');

// RBAC Layer 2: only the tabs this analyst may see are rendered — a tab they lack is never
// emitted, so there is no hidden mailbox panel to un-hide. Administrators hold everything.
$settingsManifest = settingsManifestFor('tickets');
$conn             = connectToDatabase();
$visibleTabs      = settingsVisibleTabs($conn, (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);

// Row display (discussion #61) — what this analyst's own ticket rows show.
// Resolved server-side so the tab opens on the values actually in force, rather
// than on the shipped defaults with the real ones arriving a moment later.
require_once '../../includes/inbox_display.php';
require_once '../../includes/rbac.php';
$rdConfig   = inboxDisplayForAnalyst($conn, (int) $_SESSION['analyst_id']);
$rdPersonal = inboxDisplayIsPersonal($conn, (int) $_SESSION['analyst_id']);

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';

// Check for OAuth success message
$oauthSuccess = isset($_GET['oauth']) && $_GET['oauth'] === 'success';
$oauthMailboxId = $_GET['mailbox_id'] ?? null;
// Set by oauth_callback.php when the account that signed in differs from the
// configured target mailbox — i.e. it would read the WRONG inbox.
$oauthMismatch = isset($_GET['auth_mismatch']) && $_GET['auth_mismatch'] === '1';

$current_page = 'settings';
$path_prefix = '../../';  // Two levels up from tickets/settings/

// Namespaces the inline JS needs (action-button tooltips, etc.)
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.settings.page_title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script src="../../assets/js/ai-settings.js?v=2"></script>
    <!-- Reply templates are rich text, so this page needs the same editor the reply
         box uses. Loaded for every tab because the tab bar is server-rendered and
         there is no navigation event to lazy-load on. -->
    <script src="../../assets/js/tinymce/tinymce.min.js"></script>
    <style>
        /* Page-specific overrides for settings page */

        /* Pin the header WITHOUT making it (or <body>) a positioned or flex
         * element:
         *   - a flex <body> turned extension-injected nodes (e.g. LastPass)
         *     into flex items and wrecked the layout;
         *   - a sticky/positioned header became a stacking context that trapped
         *     the waffle menu panel behind its own fixed close-overlay.
         * Instead a plain flex wrapper (.settings-shell) holds the header + the
         * scrolling container: the header pins at the top, .container is the
         * sole scroll region (no fragile `height: calc(100vh - 48px)`), and the
         * header stays a normal static element so the waffle menu's z-index
         * behaviour is unchanged. <body> keeps the inbox.css default (no flex),
         * so injected extension nodes can't disrupt the layout.
         * .container also drops the shared 1200px cap so settings fills the full
         * width (#268-#270); padding-bottom clears the viewport bottom edge.
         *
         * NOTE the `width: 100%; margin: 0` — dropping max-width is NOT enough on its
         * own. inbox.css sets `.container { margin: 30px auto }`, and inside a flex
         * column an AUTO CROSS-AXIS MARGIN cancels the default stretch: the item shrinks
         * to fit its content and centres itself. So the page kept its 1200-ish gutters
         * even with `max-width: none` applied, which reads exactly like the cap was still
         * there. Reset the margin too. (LMS and Problem Management already did.) */
        .settings-shell {
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        .container {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            max-width: none;
            width: 100%;
            margin: 0;
            box-sizing: border-box;
            padding: 24px 32px 40px;
        }

        /* Intro-paragraph spacing on tabs that lead with a description block.
         * The * { margin: 0 } reset in inbox.css strips default <p> spacing,
         * so without this consecutive paragraphs collide visually. */
        .tab-content > p {
            margin-bottom: 14px;
        }

        /* ─── Row display tab (discussion #61) ────────────────────────────────
           Controls on the left, a live sample row on the right. The sample is
           the point: "block, top right" and "pill" are hard to choose between
           from words alone. */
        .rd-layout {
            display: grid;
            grid-template-columns: minmax(0, 460px) minmax(0, 1fr);
            gap: 32px;
            align-items: start;
        }
        @media (max-width: 900px) { .rd-layout { grid-template-columns: 1fr; } }

        .rd-field { margin-bottom: 22px; }
        .rd-field-label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 8px;
            color: var(--text, #333);
        }
        .rd-options { display: flex; flex-wrap: wrap; gap: 8px; }
        .rd-option {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border: 1px solid var(--border, #d5dbe1);
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            background: var(--surface, #fff);
            transition: border-color .15s, background .15s;
        }
        .rd-option:hover { border-color: var(--accent, #0078d4); }
        .rd-option:has(input:checked) {
            border-color: var(--accent, #0078d4);
            background: var(--accent-soft, #e8f4fd);
            font-weight: 600;
        }
        .rd-help {
            display: block;
            margin-top: 7px;
            color: var(--text-muted, #666);
            font-size: 12px;
            line-height: 1.45;
        }

        .rd-preview-wrap { position: sticky; top: 12px; }
        .rd-preview-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted, #666);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        /* Deliberately the real inbox classes, on a real-width column, so the
           sample cannot flatter itself. */
        .rd-preview {
            border: 1px solid var(--border, #d5dbe1);
            border-radius: 8px;
            overflow: hidden;
            max-width: 420px;
            background: var(--surface, #fff);
        }
        .rd-admin-sep {
            width: 1px;
            height: 26px;
            background: var(--border, #d5dbe1);
            margin: 0 4px;
        }

        /* Reply cleanup tab: two-column layout — form on the left, the
         * system-prompt panel on the right to put the empty space to use. */
        .reply-cleanup-layout {
            display: grid;
            grid-template-columns: minmax(0, 600px) minmax(0, 1fr);
            gap: 28px;
            align-items: start;
            margin-top: 24px;
        }
        @media (max-width: 1100px) {
            .reply-cleanup-layout {
                grid-template-columns: 1fr;
            }
        }
        .reply-cleanup-layout > form {
            margin-top: 0 !important;
            max-width: none !important;
        }
        .reply-cleanup-prompt-panel details {
            border: 1px solid var(--border, #e5e5e5);
            border-radius: 4px;
            background: var(--surface-2, #fafafa);
        }
        .reply-cleanup-prompt-panel summary {
            padding: 12px 16px;
            cursor: pointer;
            font-weight: 600;
            color: var(--text, #333);
        }

        /* Settings page uses .action-btn for table buttons */
        .tab-content .action-btn {
            background: none;
            border: 1px solid var(--border, #ddd);
            color: var(--text-muted, #666);
            cursor: pointer;
            padding: 6px;
            margin-right: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .tab-content .action-btn:hover {
            background: var(--surface-hover, #f0f0f0);
            border-color: var(--accent, #0078d4);
            color: var(--accent, #0078d4);
        }

        .tab-content .action-btn.delete {
            color: var(--danger-accent, #d13438);
        }

        .tab-content .action-btn.delete:hover {
            background: var(--danger-bg, #fdf3f3);
            border-color: var(--danger-accent, #d13438);
            color: var(--danger-text, #a00);
        }

        .tab-content .action-btn svg {
            width: 16px;
            height: 16px;
        }

        /* Exchange status boxes — semantic token pairs so they flip in dark mode. */
        .exchange-status {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .exchange-status.authenticated {
            background: var(--success-bg, #d4edda);
            border: 1px solid var(--success-accent, #c3e6cb);
            color: var(--success-text, #155724);
        }

        .exchange-status.not-authenticated {
            background: var(--warning-bg, #fff3cd);
            border: 1px solid var(--warning-border, #ffeaa7);
            color: var(--warning-text, #856404);
        }

        .exchange-status .status-icon {
            font-size: 24px;
        }

        /* Exchange result messages */
        .exchange-result {
            padding: 20px;
            border-radius: 8px;
            display: none;
        }

        .exchange-result.success {
            display: block;
            background: var(--success-bg, #d4edda);
            border: 1px solid var(--success-accent, #c3e6cb);
            color: var(--success-text, #155724);
        }

        .exchange-result.error {
            display: block;
            background: var(--danger-bg, #f8d7da);
            border: 1px solid var(--danger-accent, #f5c6cb);
            color: var(--danger-text, #721c24);
        }

        .exchange-result.info {
            display: block;
            background: var(--accent-soft, #d1ecf1);
            border: 1px solid var(--accent, #bee5eb);
            color: var(--accent-hover, #0c5460);
        }

        .exchange-result pre {
            background: rgba(0, 0, 0, 0.05);
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            margin-top: 10px;
            font-size: 12px;
        }

        /* Modal content override for settings modals */
        .modal-content {
            padding: 20px;
            max-width: 500px;
        }

        .modal-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text, #333);
            padding: 0;
            border-bottom: none;
        }

        /* Email-template body: Edit / Preview tabs */
        /* Sender rules: scope editor, simulator, warning (#80). */
        .tpl-scope-warn {
            display: flex; gap: 12px; align-items: flex-start; justify-content: space-between;
            background: var(--warning-bg, #fef3c7);
            color: var(--warning-text, #92400e);
            border: 1px solid var(--warning-border, #f0d9a8);
            border-radius: 6px; padding: 10px 12px; margin-bottom: 12px;
            font-size: 13px; line-height: 1.5;
        }
        .tpl-scope-warn button { flex: 0 0 auto; }
        .tpl-sim {
            border: 1px solid var(--border, #ddd); border-radius: 8px;
            padding: 14px 16px; margin-bottom: 18px; background: var(--surface-2, #f9f9f9);
        }
        .tpl-sim label { display: block; font-weight: 600; margin-bottom: 6px; }
        .tpl-sim-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .tpl-sim-row select, .tpl-sim-row input {
            padding: 10px; border: 1px solid var(--border, #ddd); border-radius: 4px;
            font-size: 14px; font-family: inherit;
        }
        .tpl-sim-row input { flex: 1; min-width: 220px; }
        .tpl-sim-result {
            margin-top: 10px; padding: 10px 12px; border-radius: 6px;
            font-size: 13px; line-height: 1.5;
            background: var(--surface, #fff); border: 1px solid var(--border, #ddd);
        }
        .tpl-sim-result.none { background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e); border-color: var(--warning-border, #f0d9a8); }
        .tpl-scope-choice { display: inline-flex !important; align-items: center; gap: 6px; margin-right: 18px; font-weight: 400 !important; }
        .tpl-rules-list { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0; }
        .tpl-rule-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--surface-2, #f1f1f1); border: 1px solid var(--border, #ddd);
            border-radius: 14px; padding: 3px 6px 3px 10px; font-size: 12px;
        }
        .tpl-rule-chip button {
            border: none; background: none; cursor: pointer; font-size: 14px; line-height: 1;
            color: var(--text-muted, #666); padding: 0 4px;
        }
        .tpl-rule-add { display: flex; gap: 8px; align-items: center; max-width: 520px; }
        .tpl-rule-add input {
            flex: 1; min-width: 0; padding: 10px; border: 1px solid var(--border, #ddd);
            border-radius: 4px; font-size: 14px; font-family: inherit;
        }
        .tpl-scope-badge {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; background: var(--surface-2, #f1f1f1);
            border: 1px solid var(--border, #ddd); color: var(--text-muted, #666);
        }
        /* Public web address panel — the setting [ticket_url] depends on (#80). */
        .tpl-baseurl {
            border: 1px solid var(--border, #ddd);
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 18px;
            background: var(--surface-2, #f9f9f9);
        }
        /* ⚠️ A DISABLED BUTTON THAT LOOKS LIVE IS WORSE THAN NO GUARD AT ALL.
           inbox.css styles .btn but has no disabled state, so `Renumber` — which
           stays off until a preview has been looked at — rendered as a bright,
           inviting primary button that silently did nothing when clicked. Scoped
           to this tab rather than made global, so no other settings page changes
           appearance for a fix that belongs to one destructive control. */
        #numbering-tab .btn:disabled,
        #numbering-tab .btn[disabled] {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .tpl-baseurl label { display: block; font-weight: 600; margin-bottom: 6px; }
        .tpl-baseurl-row { display: flex; gap: 8px; align-items: center; max-width: 620px; }
        /* Matches .form-group input in inbox.css deliberately — including leaving
           background and colour unset, so this field inherits the same way every
           other input on the page does and cannot drift in dark mode. */
        .tpl-baseurl-row input {
            flex: 1;
            min-width: 0;
            padding: 10px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }
        .tpl-baseurl-example {
            margin-top: 8px;
            font-size: 12px;
            font-family: Consolas, Monaco, monospace;
            color: var(--text-muted, #666);
            word-break: break-all;
        }
        .tpl-baseurl-warn {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            justify-content: space-between;
            background: var(--warning-bg, #fef3c7);
            color: var(--warning-text, #92400e);
            border: 1px solid var(--warning-border, #f0d9a8);
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: 13px;
            line-height: 1.5;
        }
        .tpl-baseurl-warn button { flex: 0 0 auto; }
        .tpl-body-tabs { display: flex; gap: 4px; margin-bottom: 6px; }
        .tpl-body-tab {
            padding: 6px 16px;
            border: 1px solid var(--border, #ddd);
            border-bottom: none;
            background: var(--surface-2, #f5f5f5);
            border-radius: 6px 6px 0 0;
            cursor: pointer;
            font-size: 13px;
            color: var(--text-muted, #666);
        }
        .tpl-body-tab.active {
            background: var(--surface, #fff);
            color: var(--text, #333);
            font-weight: 600;
        }
        /* Faithful render of how the email will look (always on white, like an inbox). */
        .tpl-preview-frame {
            border: 1px solid var(--border, #ddd);
            border-radius: 6px;
            padding: 22px;
            min-height: 200px;
            max-height: 360px;
            overflow: auto;
            background: #ffffff;
            color: #333333;
            font-family: Arial, sans-serif;
            line-height: 1.6;
        }

        /* Inbound / Outbound tabs on the mailbox activity modal. Theme tokens only —
           --border-color / --text-muted / --surface all exist in theme.css. */
        .mbx-log-tab {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted, #666);
            cursor: pointer;
        }
        .mbx-log-tab:hover { color: var(--text, #333); }
        .mbx-log-tab.active {
            color: #0078d4;
            border-bottom-color: #0078d4;
        }
        .mbx-fail-badge {
            display: inline-block;
            min-width: 18px;
            padding: 1px 6px;
            margin-left: 4px;
            background: #d13438;
            color: #fff;
            border-radius: 9px;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
        }
        .mbx-result {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            white-space: nowrap;
        }
        .mbx-result.sent   { background: #d4edda; color: #155724; }
        .mbx-result.failed { background: #f8d7da; color: #721c24; }
        /* Deliberately not sent (#80) — neutral, because it is not a fault. */
        .mbx-result.skipped { background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e); }

        /* The log panes size to the window rather than a fixed 450px, so a tall screen
           shows far more rows than a laptop instead of both showing the same handful.
           The subtracted 300px is the modal chrome above and below (header, tabs,
           search row, pagination, actions); min-height keeps it usable if that
           arithmetic is ever wrong on an unusual screen. */
        .activity-log-pane {
            max-height: calc(100vh - 300px);
            min-height: 260px;
            overflow-y: auto;
        }
        .activity-modal-content { max-height: 94vh; overflow-y: auto; }

        /* The log tables carry an address and a subject that both want to be long, so
           left to themselves the browser spreads five columns across 1500px and the
           date ends up touching the sender. Fixed layout + explicit widths keeps them
           readable at any modal width; widening the modal without this made it worse,
           not better. */
        .activity-log-pane table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            font-size: 13px;
        }
        .activity-log-pane th,
        .activity-log-pane td {
            padding: 8px 14px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid var(--border, #e6e6e6);
        }
        .activity-log-pane th {
            position: sticky;      /* headings stay put while a long log scrolls */
            top: 0;
            z-index: 1;
            background: var(--surface, #fff);
            font-weight: 600;
            white-space: nowrap;
        }
        .activity-log-pane tbody tr:hover td { background: var(--surface-hover, rgba(127,127,127,0.07)); }
        /* Long unbroken addresses (microsoftexchange329e…@) must wrap rather than
           force the column wider than its share. */
        .activity-log-pane td { overflow-wrap: anywhere; }

        /* Date/time · From/To · Subject · Action/Sent by · Reason/Result */
        #inboundPane  th:nth-child(1), #inboundPane  td:nth-child(1),
        #outboundPane th:nth-child(1), #outboundPane td:nth-child(1) { width: 150px; white-space: nowrap; }
        #inboundPane  th:nth-child(2), #inboundPane  td:nth-child(2) { width: 27%; }
        #outboundPane th:nth-child(2), #outboundPane td:nth-child(2) { width: 22%; }
        #inboundPane  th:nth-child(4), #inboundPane  td:nth-child(4) { width: 110px; }
        #outboundPane th:nth-child(4), #outboundPane td:nth-child(4) { width: 150px; }
        #inboundPane  th:nth-child(5), #inboundPane  td:nth-child(5) { width: 150px; }
        #outboundPane th:nth-child(5), #outboundPane td:nth-child(5) { width: 90px; }

        /* On a phone the modal is the screen; the width cap and the tall pane both
           get out of the way and the table scrolls sideways as it already does. */
        @media (max-width: 768px) {
            .activity-modal-content { width: 100%; max-width: none; }
            .activity-log-pane { max-height: calc(100vh - 260px); }
        }
    </style>
    <!-- Mobile: LAYER 15e (container, tab strip, fields, all tables scroll).
         data-mobile-shell="own" opts OUT of LAYER 2's flex body — this page
         deliberately keeps <body> unstyled (see the .settings-shell comment
         above) and builds its own scroll shell one level down. -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=130">
</head>
<body data-mobile-page="settings" data-mobile-shell="own">
    <div class="settings-shell">
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <?php renderSettingsTabBar($visibleTabs, $activeTabId); ?>

        <!-- Departments Tab -->
        <?php if (settingsTabVisible($visibleTabs, 'departments')): ?>
        <div class="tab-content<?php echo $activeTabId === 'departments' ? ' active' : ''; ?>" id="departments-tab" data-capability="<?php echo Cap::TICKETS_DEPARTMENTS; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.departments')); ?></h2>
                <button class="add-btn" onclick="openAddModal('department')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.departments'); ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.teams')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="departments-list">
                    <tr><td colspan="7" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Ticket Types Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'ticket-types')): ?>
        <div class="tab-content<?php echo $activeTabId === 'ticket-types' ? ' active' : ''; ?>" id="ticket-types-tab" data-capability="<?php echo Cap::TICKETS_TICKET_TYPES; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.ticket_types')); ?></h2>
                <button class="add-btn" onclick="openAddModal('ticket-type')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.ticket_types'); ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="ticket-types-list">
                    <tr><td colspan="5" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Ticket Origins Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'ticket-origins')): ?>
        <div class="tab-content<?php echo $activeTabId === 'ticket-origins' ? ' active' : ''; ?>" id="ticket-origins-tab" data-capability="<?php echo Cap::TICKETS_TICKET_ORIGINS; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.ticket_origins')); ?></h2>
                <button class="add-btn" onclick="openAddModal('ticket-origin')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.ticket_origins'); ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="ticket-origins-list">
                    <tr><td colspan="5" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Statuses Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'statuses')): ?>
        <div class="tab-content<?php echo $activeTabId === 'statuses' ? ' active' : ''; ?>" id="statuses-tab" data-capability="<?php echo Cap::TICKETS_STATUSES; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.statuses')); ?></h2>
                <button class="add-btn" onclick="openAddModal('status')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.statuses'); ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.colour')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.closed')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.cols.pause_sla')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.default')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="statuses-list">
                    <tr><td colspan="8" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Priorities Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'priorities')): ?>
        <div class="tab-content<?php echo $activeTabId === 'priorities' ? ' active' : ''; ?>" id="priorities-tab" data-capability="<?php echo Cap::TICKETS_PRIORITIES; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.priorities')); ?></h2>
                <button class="add-btn" onclick="openAddModal('priority')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.priorities'); ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.colour')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.default')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="priorities-list">
                    <tr><td colspan="7" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- SLA Tab — see docs/sla.md -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'sla')): ?>
        <div class="tab-content<?php echo $activeTabId === 'sla' ? ' active' : ''; ?>" id="sla-tab" data-capability="<?php echo Cap::TICKETS_SLA; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.sla.heading')); ?></h2>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);">
                <?php echo t('tickets.settings.sla.intro'); ?>
            </p>

            <!-- ===== Global SLA settings ===== -->
            <div class="settings-group">
                <h3><?php echo htmlspecialchars(t('tickets.settings.sla.global_heading')); ?></h3>
                <form id="slaGlobalForm" style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                    <div class="form-group" style="grid-column:span 2;">
                        <label for="slaEnforceFrom"><?php echo htmlspecialchars(t('tickets.settings.sla.enforce_from')); ?></label>
                        <input type="datetime-local" id="slaEnforceFrom" style="max-width:260px;">
                        <small style="display:block;color:var(--text-muted, #666);margin-top:4px;">
                            <?php echo t('tickets.settings.sla.enforce_from_help'); ?>
                        </small>
                    </div>

                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('tickets.settings.sla.priority_change_label')); ?></label>
                        <label style="display:block;margin-top:6px;font-weight:400;">
                            <input type="radio" name="slaPriorityChange" value="forward"> <?php echo htmlspecialchars(t('tickets.settings.sla.priority_change_forward')); ?>
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="radio" name="slaPriorityChange" value="recompute"> <?php echo htmlspecialchars(t('tickets.settings.sla.priority_change_recompute')); ?>
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="radio" name="slaPriorityChange" value="reset"> <?php echo htmlspecialchars(t('tickets.settings.sla.priority_change_reset')); ?>
                        </label>
                    </div>

                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('tickets.settings.sla.reopen_label')); ?></label>
                        <label style="display:block;margin-top:6px;font-weight:400;">
                            <input type="radio" name="slaReopen" value="reset"> <?php echo htmlspecialchars(t('tickets.settings.sla.reopen_reset')); ?>
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="radio" name="slaReopen" value="continue"> <?php echo htmlspecialchars(t('tickets.settings.sla.reopen_continue')); ?>
                        </label>
                    </div>

                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('tickets.settings.sla.first_response_label')); ?></label>
                        <label style="display:block;margin-top:6px;font-weight:400;">
                            <input type="radio" name="slaFirstResponse" value="outbound_email"> <?php echo htmlspecialchars(t('tickets.settings.sla.first_response_outbound')); ?>
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="radio" name="slaFirstResponse" value="status_change"> <?php echo htmlspecialchars(t('tickets.settings.sla.first_response_status')); ?>
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="radio" name="slaFirstResponse" value="either"> <?php echo htmlspecialchars(t('tickets.settings.sla.first_response_either')); ?>
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="slaWarningThreshold"><?php echo htmlspecialchars(t('tickets.settings.sla.warning_threshold')); ?></label>
                        <input type="number" id="slaWarningThreshold" min="1" max="100" style="max-width:120px;">
                        <small style="display:block;color:var(--text-muted, #666);margin-top:4px;"><?php echo htmlspecialchars(t('tickets.settings.sla.warning_threshold_help')); ?></small>
                    </div>

                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('tickets.settings.sla.notifications_label')); ?></label>
                        <label style="display:block;margin-top:6px;font-weight:400;">
                            <input type="checkbox" id="slaNotifyAssignee"> <?php echo htmlspecialchars(t('tickets.settings.sla.notify_assignee')); ?>
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="checkbox" id="slaNotifyLead"> <?php echo htmlspecialchars(t('tickets.settings.sla.notify_lead')); ?>
                        </label>
                    </div>

                    <div style="grid-column:span 2;margin-top:8px;">
                        <button type="button" class="btn btn-primary" onclick="saveSlaGlobalSettings()"><?php echo htmlspecialchars(t('tickets.settings.sla.save_global')); ?></button>
                    </div>
                </form>
            </div>

            <!-- ===== SLA Targets per priority ===== -->
            <div class="settings-group">
                <h3><?php echo htmlspecialchars(t('tickets.settings.sla.targets_heading')); ?></h3>
                <p style="color:var(--text-muted, #666);margin-bottom:14px;"><?php echo htmlspecialchars(t('tickets.settings.sla.targets_intro')); ?></p>
                <table>
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_priority')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_response_mins')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_resolution_mins')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_calendar')); ?></th>
                            <th style="width:90px;"><?php echo htmlspecialchars(t('tickets.settings.sla.col_save')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="slaTargetsList">
                        <tr><td colspan="5" style="text-align:center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ===== Business Calendars ===== -->
            <div class="settings-group">
                <div class="section-header">
                    <h3><?php echo htmlspecialchars(t('tickets.settings.sla.calendars_heading')); ?></h3>
                    <button class="add-btn" onclick="openSlaCalendarModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
                </div>
                <p style="color:var(--text-muted, #666);margin-bottom:14px;"><?php echo htmlspecialchars(t('tickets.settings.sla.calendars_intro')); ?></p>
                <table>
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_name')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_timezone')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_hours')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_holidays')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_default')); ?></th>
                            <th style="width:120px;"><?php echo htmlspecialchars(t('tickets.settings.sla.col_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="slaCalendarsList">
                        <tr><td colspan="6" style="text-align:center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ===== Breach Notifications ===== -->
            <div class="settings-group">
                <div class="section-header">
                    <h3><?php echo htmlspecialchars(t('tickets.settings.sla.notifs_heading')); ?></h3>
                    <button class="add-btn" onclick="openSlaNotifModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
                </div>

                <div style="background:var(--accent-soft, #eff6ff);border-left:4px solid var(--accent, #2563eb);padding:14px 16px;border-radius:4px;margin-bottom:18px;font-size:13px;line-height:1.6;color:var(--accent-hover, #1e3a8a);">
                    <?php echo t('tickets.settings.sla.notifs_info'); ?>
                </div>

                <p style="color:var(--text-muted, #666);margin-bottom:14px;">
                    <?php echo t('tickets.settings.sla.notifs_dedup'); ?>
                </p>
                <table>
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_scope')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_trigger')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_target')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_recipients')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_active')); ?></th>
                            <th style="width:120px;"><?php echo htmlspecialchars(t('tickets.settings.sla.col_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="slaNotifRulesList">
                        <tr><td colspan="6" style="text-align:center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                    </tbody>
                </table>
                <p style="color:var(--text-dim, #888);margin-top:14px;font-size:12px;">
                    <?php echo t('tickets.settings.sla.notifs_cron_note'); ?>
                </p>
            </div>

            <!-- ===== Cron Activity ===== -->
            <div class="settings-group">
                <div class="section-header">
                    <h3><?php echo htmlspecialchars(t('tickets.settings.sla.cron_heading')); ?></h3>
                    <button class="add-btn" onclick="loadSlaCronRuns()" title="<?php echo htmlspecialchars(t('tickets.settings.sla.cron_refresh')); ?>">&#x21bb;</button>
                </div>
                <p style="color:var(--text-muted, #666);margin-bottom:14px;">
                    <?php echo t('tickets.settings.sla.cron_intro'); ?>
                </p>
                <table>
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_when')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_source')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_duration')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_sent')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_skipped')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_errors')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.sla.col_outcome')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="slaCronRunsList">
                        <tr><td colspan="7" style="text-align:center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ===== Breach Notification rule modal ===== -->
        <div id="slaNotifModal" class="modal">
            <div class="modal-content" style="max-width:640px;">
                <div class="modal-header" id="slaNotifModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.add_title')); ?></div>
                <div class="modal-body">
                    <input type="hidden" id="slaNotifId" value="">

                    <div class="form-group">
                        <label for="slaNotifDept"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.scope')); ?></label>
                        <select id="slaNotifDept" class="form-control">
                            <option value=""><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.scope_default_opt')); ?></option>
                        </select>
                    </div>

                    <div class="form-group" style="display:flex;gap:12px;">
                        <div style="flex:1;">
                            <label for="slaNotifTrigger"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.trigger')); ?></label>
                            <select id="slaNotifTrigger" class="form-control">
                                <option value="warning"><?php echo t('tickets.settings.modals.sla_notif.trigger_warning_opt'); ?></option>
                                <option value="breach"><?php echo t('tickets.settings.modals.sla_notif.trigger_breach_opt'); ?></option>
                            </select>
                        </div>
                        <div style="flex:1;">
                            <label for="slaNotifTarget"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.target')); ?></label>
                            <select id="slaNotifTarget" class="form-control">
                                <option value="both"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.target_both_opt')); ?></option>
                                <option value="response"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.target_response_opt')); ?></option>
                                <option value="resolution"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.target_resolution_opt')); ?></option>
                            </select>
                        </div>
                    </div>

                    <fieldset style="border:1px solid #ddd;padding:12px 16px;border-radius:4px;margin-bottom:14px;">
                        <legend style="padding:0 6px;font-weight:600;font-size:13px;"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.recipients')); ?></legend>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label><input type="checkbox" id="slaNotifAssignee"> <?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.recipient_assignee')); ?></label>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label><input type="checkbox" id="slaNotifTeams"> <?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.recipient_teams')); ?></label>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label for="slaNotifAnalyst"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.recipient_analyst')); ?></label>
                            <select id="slaNotifAnalyst" class="form-control">
                                <option value=""><?php echo t('tickets.settings.modals.sla_notif.recipient_analyst_none'); ?></option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label for="slaNotifEmails"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.recipient_emails')); ?></label>
                            <textarea id="slaNotifEmails" class="form-control" rows="2" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.recipient_emails_placeholder')); ?>"></textarea>
                            <small><?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.recipient_emails_help')); ?></small>
                        </div>
                    </fieldset>

                    <div class="form-group">
                        <label class="toggle-label">
                            <span class="toggle-switch">
                                <input type="checkbox" id="slaNotifActive" checked>
                                <span class="toggle-slider"></span>
                            </span>
                            <?php echo htmlspecialchars(t('tickets.settings.modals.sla_notif.active')); ?>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeSlaNotifModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button class="btn btn-primary" onclick="saveSlaNotifRule()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </div>
        </div>

        <!-- Rota Locations Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'rota-locations')): ?>
        <div class="tab-content<?php echo $activeTabId === 'rota-locations' ? ' active' : ''; ?>" id="rota-locations-tab" data-capability="<?php echo Cap::TICKETS_ROTA_LOCATIONS; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.rota_locations')); ?></h2>
                <button class="add-btn" onclick="openAddModal('rota-location')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.rota_locations'); ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.colour')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.default')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="rota-locations-list">
                    <tr><td colspan="7" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Mailboxes Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'mailboxes')): ?>
        <div class="tab-content<?php echo $activeTabId === 'mailboxes' ? ' active' : ''; ?>" id="mailboxes-tab" data-capability="<?php echo Cap::TICKETS_MAILBOXES; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.mailboxes')); ?></h2>
                <div>
                    <button class="btn btn-secondary" onclick="window.location.href='../activity.php'" style="margin-right: 10px;"><?php echo htmlspecialchars(t('tickets.settings.buttons.logs')); ?></button>
                    <button class="btn btn-primary" onclick="checkAllMailboxes()" style="margin-right: 10px;"><?php echo htmlspecialchars(t('tickets.settings.buttons.check_all')); ?></button>
                    <button class="add-btn" onclick="openMailboxModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
                </div>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.mailboxes'); ?></p>

            <?php if ($oauthSuccess && $oauthMailboxId && !$oauthMismatch): ?>
            <div class="exchange-status authenticated" id="oauth-success-msg">
                <span class="status-icon">&#10003;</span>
                <div>
                    <strong><?php echo htmlspecialchars(t('tickets.settings.oauth.success_title')); ?></strong><br>
                    <?php echo htmlspecialchars(t('tickets.settings.oauth.success_body')); ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($oauthSuccess && $oauthMailboxId && $oauthMismatch): ?>
            <div class="exchange-status" id="oauth-mismatch-msg" style="background:#ffebee;border:1px solid #ef9a9a;color:#c62828;display:flex;align-items:flex-start;gap:10px;padding:12px;border-radius:6px;">
                <span class="status-icon">&#9888;</span>
                <div>
                    <strong><?php echo htmlspecialchars(t('tickets.settings.oauth.mismatch_title')); ?></strong><br>
                    <?php echo htmlspecialchars(t('tickets.settings.oauth.mismatch_body')); ?>
                </div>
            </div>
            <?php endif; ?>

            <div id="mailboxesResult" class="exchange-result"></div>

            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.mailbox')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.last_checked')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="mailboxes-list">
                    <tr><td colspan="5" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Messaging Tab (WhatsApp etc.) -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'messaging')): ?>
        <div class="tab-content<?php echo $activeTabId === 'messaging' ? ' active' : ''; ?>" id="messaging-tab" data-capability="<?php echo Cap::TICKETS_MESSAGING; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.messaging.channels_heading')); ?></h2>
                <button class="add-btn" onclick="openChannelModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p>
                <?php echo t('tickets.settings.messaging.channels_intro'); ?>
            </p>
            <div class="form-group" style="margin-bottom:18px;">
                <label for="messagingBaseUrl"><?php echo t('tickets.settings.messaging.base_url_label'); ?></label>
                <div style="display:flex; gap:8px; max-width:640px;">
                    <input type="text" id="messagingBaseUrl" style="flex:1;" placeholder="<?php echo htmlspecialchars(t('tickets.settings.messaging.base_url_placeholder')); ?>">
                    <button class="btn btn-primary" type="button" onclick="saveMessagingBaseUrl()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
                <small style="color:var(--text-muted, #666); display:block; margin-top:4px; max-width:none;">
                    <?php echo t('tickets.settings.messaging.base_url_help'); ?>
                </small>
            </div>
            <div id="channelsResult" style="margin-bottom:12px;"></div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.cols.number')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.cols.webhook_url')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="channels-list">
                    <tr><td colspan="5" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>

            <div class="section-header" style="margin-top:32px;">
                <h2><?php echo htmlspecialchars(t('tickets.settings.messaging.templates_heading')); ?></h2>
                <button class="add-btn" onclick="openMsgTemplateModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p>
                <?php echo t('tickets.settings.messaging.templates_intro'); ?>
            </p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.cols.provider')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.cols.reference')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="msg-templates-list">
                    <tr><td colspan="5" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Web chat Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'webchat')): ?>
        <div class="tab-content<?php echo $activeTabId === 'webchat' ? ' active' : ''; ?>" id="webchat-tab" data-capability="<?php echo Cap::TICKETS_WEBCHAT; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.webchat.heading')); ?></h2>
                <button class="add-btn" onclick="openWidgetModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p>
                <?php echo htmlspecialchars(t('tickets.settings.webchat.intro')); ?>
            </p>
            <div id="widgetsResult" style="margin-bottom:12px;"></div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.webchat.col_origins')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.webchat.col_key')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="widgets-list">
                    <tr><td colspan="5" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Email Templates Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'email-templates')): ?>
        <div class="tab-content<?php echo $activeTabId === 'email-templates' ? ' active' : ''; ?>" id="email-templates-tab" data-capability="<?php echo Cap::TICKETS_EMAIL_TEMPLATES; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.email_templates')); ?></h2>
                <button class="add-btn" onclick="openTemplateModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 15px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.email_templates'); ?></p>

            <!-- Public web address (discussion #80).
                 Sits here rather than in a general settings page because this is where
                 [ticket_url] is written, and a setting you are told about at the moment
                 you need it is one you actually set. -->
            <div class="tpl-baseurl">
                <div class="tpl-baseurl-warn" id="tplBaseUrlWarning" style="display: none;">
                    <div>
                        <strong><?php echo htmlspecialchars(t('tickets.settings.base_url.warn_title')); ?></strong>
                        <div id="tplBaseUrlWarningBody" style="margin-top: 4px;"></div>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="dismissBaseUrlWarning()"><?php echo htmlspecialchars(t('common.dismiss')); ?></button>
                </div>
                <label for="tplBaseUrl"><?php echo htmlspecialchars(t('tickets.settings.base_url.label')); ?></label>
                <div class="tpl-baseurl-row">
                    <input type="text" id="tplBaseUrl" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.settings.base_url.placeholder')); ?>">
                    <button type="button" class="btn btn-secondary" onclick="savePublicBaseUrl()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
                <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.base_url.help')); ?></small>
                <div id="tplBaseUrlExample" class="tpl-baseurl-example"></div>
            </div>

            <!-- No catch-all warning + simulator (discussion #80).
                 The warning catches the mistake; the simulator answers "what actually
                 happens for this sender", which is the question an admin has to think
                 to ask. Neither helps twelve months later when nobody visits this
                 screen — that is what the Not sent rows in the send log are for. -->
            <div class="tpl-scope-warn" id="tplScopeWarning" style="display: none;">
                <div>
                    <strong><?php echo htmlspecialchars(t('tickets.settings.scope.warn_title')); ?></strong>
                    <div id="tplScopeWarningBody" style="margin-top: 4px;"></div>
                </div>
                <button type="button" class="btn btn-secondary" onclick="dismissScopeWarning()"><?php echo htmlspecialchars(t('common.dismiss')); ?></button>
            </div>

            <div class="tpl-sim">
                <label for="tplSimEmail"><?php echo htmlspecialchars(t('tickets.settings.scope.sim_label')); ?></label>
                <div class="tpl-sim-row">
                    <select id="tplSimEvent">
                        <option value="new_ticket_email"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_new_ticket')); ?></option>
                        <option value="ticket_assigned"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_assigned')); ?></option>
                        <option value="ticket_closed"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_closed')); ?></option>
                        <option value="note_shared"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_note_shared')); ?></option>
                        <option value="csat_request"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_csat_request')); ?></option>
                    </select>
                    <input type="text" id="tplSimEmail" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.settings.scope.sim_placeholder')); ?>" onkeydown="if(event.key==='Enter'){event.preventDefault();runTemplateSimulator();}">
                    <button type="button" class="btn btn-secondary" onclick="runTemplateSimulator()"><?php echo htmlspecialchars(t('tickets.settings.scope.sim_button')); ?></button>
                </div>
                <div id="tplSimResult" class="tpl-sim-result" style="display: none;"></div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.event')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.sends_to')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.subject')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="email-templates-list">
                    <tr><td colspan="7" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Merge Behaviour Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'indexing')): ?>
        <div class="tab-content<?php echo $activeTabId === 'indexing' ? ' active' : ''; ?>" id="indexing-tab" data-capability="<?php echo Cap::TICKETS_INDEXING; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.indexing')); ?></h2>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.indexing'); ?></p>
            <form id="indexingForm" style="max-width: 820px;">

                <div class="form-group">
                    <label style="display:block;font-weight:400;">
                        <input type="checkbox" id="extractCron">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.indexing.cron_label')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.indexing.cron_help')); ?></small>
                    </label>

                    <label style="display:block;margin-top:16px;font-weight:400;">
                        <input type="checkbox" id="extractOpportunistic">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.indexing.opportunistic_label')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.indexing.opportunistic_help')); ?></small>
                    </label>
                </div>

                <p style="margin-top:22px;color: var(--text-muted, #666);font-size:13px;">
                    <?php echo t('tickets.settings.indexing.where_service'); ?>
                </p>

                <div class="form-actions" style="margin-top:18px;">
                    <?php /* `btn btn-primary`, both classes: .btn carries the padding,
                             radius and weight, .btn-primary only the colours. On its own
                             it renders a bare coloured rectangle. Every other button on
                             this page uses the pair. */ ?>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'merge-behaviour')): ?>
        <div class="tab-content<?php echo $activeTabId === 'merge-behaviour' ? ' active' : ''; ?>" id="merge-behaviour-tab" data-capability="<?php echo Cap::TICKETS_MERGE; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.merge_behaviour')); ?></h2>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.merge_behaviour'); ?></p>
            <form id="mergeBehaviourForm" style="max-width: 820px;">

                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('tickets.settings.merge.reference_label')); ?></label>

                    <label style="display:block;margin-top:10px;font-weight:400;">
                        <input type="radio" name="mergeReferenceMode" value="survivor">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.merge.ref_survivor')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.merge.ref_survivor_help')); ?></small>
                    </label>

                    <label style="display:block;margin-top:10px;font-weight:400;">
                        <input type="radio" name="mergeReferenceMode" value="new">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.merge.ref_new')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.merge.ref_new_help')); ?></small>
                    </label>
                </div>

                <div class="form-group" style="margin-top:28px;">
                    <label><?php echo htmlspecialchars(t('tickets.settings.merge.originals_label')); ?></label>

                    <label style="display:block;margin-top:10px;font-weight:400;">
                        <input type="radio" name="mergeOriginalsMode" value="thread">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.merge.orig_thread')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.merge.orig_thread_help')); ?></small>
                    </label>

                    <label style="display:block;margin-top:10px;font-weight:400;">
                        <input type="radio" name="mergeOriginalsMode" value="thread_html">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.merge.orig_thread_html')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.merge.orig_thread_html_help')); ?></small>
                    </label>

                    <label style="display:block;margin-top:10px;font-weight:400;">
                        <input type="radio" name="mergeOriginalsMode" value="html">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.merge.orig_html')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.merge.orig_html_help')); ?></small>
                    </label>
                </div>

                <div class="form-group" style="margin-top:28px;">
                    <label style="display:flex;align-items:center;gap:10px;font-weight:500;cursor:pointer;">
                        <input type="checkbox" id="mergeAiSummary" style="width:auto;margin:0;">
                        <span><?php echo htmlspecialchars(t('tickets.settings.merge.ai_label')); ?></span>
                    </label>
                    <small style="display:block;margin-left:26px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.merge.ai_help')); ?></small>
                </div>

                <div class="info-box" style="margin-top:22px;padding:12px 14px;border-radius:6px;background: var(--accent-soft, #eff6ff);border-left:4px solid var(--accent, #0078d4);">
                    <small><?php echo t('tickets.settings.merge.note'); ?></small>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-start; margin-top: 30px;">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>

        <!-- Reply Templates Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'reply-templates')): ?>
        <div class="tab-content<?php echo $activeTabId === 'reply-templates' ? ' active' : ''; ?>" id="reply-templates-tab" data-capability="<?php echo Cap::TICKETS_REPLY_TEMPLATES; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.reply_templates')); ?></h2>
                <button class="add-btn" onclick="openReplyTemplateModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 15px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.reply_templates'); ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.preview')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="reply-templates-list">
                    <tr><td colspan="5" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Rota Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'rota')): ?>
        <div class="tab-content<?php echo $activeTabId === 'rota' ? ' active' : ''; ?>" id="rota-tab" data-capability="<?php echo Cap::TICKETS_ROTA; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.rota_shifts')); ?></h2>
                <button class="add-btn" onclick="openRotaShiftModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.rota_shifts'); ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.start')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.end')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="rota-shifts-list">
                    <tr><td colspan="7" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                </tbody>
            </table>
            <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                <h2 style="font-size: 16px; margin-bottom: 12px;"><?php echo htmlspecialchars(t('tickets.settings.headings.rota_settings')); ?></h2>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" id="rotaIncludeWeekends" onchange="saveRotaWeekendSetting()">
                    <?php echo htmlspecialchars(t('tickets.settings.rota_weekends')); ?>
                </label>
            </div>
        </div>

        <!-- General Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'general')): ?>
        <?php /* Ticket numbering (GH #71).
                 Ordered so the consequential decisions come first: what the
                 number LOOKS like, then what each counter counts, then the
                 renumbering tool last and clearly separated — it rewrites the
                 reference on every existing ticket. */ ?>
        <div class="tab-content<?php echo $activeTabId === 'numbering' ? ' active' : ''; ?>" id="numbering-tab" data-capability="<?php echo Cap::TICKETS_NUMBERING; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.numbering.heading')); ?></h2>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.numbering.intro'); ?></p>

            <form id="numberingForm" style="max-width: 700px;">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('tickets.settings.numbering.style')); ?></label>
                    <label style="display:block;margin-top:10px;font-weight:400;">
                        <input type="radio" name="numStyle" value="sequential" onchange="numSync()">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.numbering.style_sequential')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.numbering.style_sequential_help')); ?></small>
                    </label>
                    <label style="display:block;margin-top:10px;font-weight:400;">
                        <input type="radio" name="numStyle" value="random" onchange="numSync()">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.numbering.style_random')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.numbering.style_random_help')); ?></small>
                    </label>
                </div>

                <div id="numSequentialOnly">
                    <div class="form-group">
                        <label for="numFormat"><?php echo htmlspecialchars(t('tickets.settings.numbering.format')); ?></label>
                        <input type="text" id="numFormat" oninput="numPreview()" autocomplete="off" spellcheck="false">
                        <small style="color: var(--text-muted, #666);"><?php echo t('tickets.settings.numbering.format_help'); ?></small>
                        <div id="numFormatError" style="display:none;margin-top:6px;color: var(--danger-text, #c0392b);font-size:13px;"></div>
                    </div>

                    <?php /* The live preview. Somebody should never have to create
                             a ticket to find out what a format does. */ ?>
                    <div class="info-box" style="margin:6px 0 18px 0;padding:12px 14px;border-radius:6px;background: var(--accent-soft, #eff6ff);border-left:4px solid var(--accent, #0078d4);">
                        <small style="display:block;margin-bottom:6px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.numbering.preview_label')); ?></small>
                        <strong id="numPreviewOut" style="font-family: ui-monospace, Consolas, monospace;">&mdash;</strong>
                    </div>

                    <div class="form-group">
                        <label for="numStart"><?php echo htmlspecialchars(t('tickets.settings.numbering.start')); ?></label>
                        <input type="number" id="numStart" min="1" step="1" oninput="numPreview()" style="max-width:200px;">
                        <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.numbering.start_help')); ?></small>
                    </div>

                    <div class="form-group">
                        <label for="numScope"><?php echo htmlspecialchars(t('tickets.settings.numbering.scope')); ?></label>
                        <select id="numScope" style="max-width:340px;" onchange="numPreview()">
                            <option value="global"><?php echo htmlspecialchars(t('tickets.settings.numbering.scope_global')); ?></option>
                            <option value="per_type"><?php echo htmlspecialchars(t('tickets.settings.numbering.scope_per_type')); ?></option>
                            <option value="per_company"><?php echo htmlspecialchars(t('tickets.settings.numbering.scope_per_company')); ?></option>
                        </select>
                        <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.numbering.scope_help')); ?></small>
                    </div>

                    <div class="form-group">
                        <label for="numReset"><?php echo htmlspecialchars(t('tickets.settings.numbering.reset')); ?></label>
                        <select id="numReset" style="max-width:340px;">
                            <option value="never"><?php echo htmlspecialchars(t('tickets.settings.numbering.reset_never')); ?></option>
                            <option value="yearly"><?php echo htmlspecialchars(t('tickets.settings.numbering.reset_yearly')); ?></option>
                            <option value="monthly"><?php echo htmlspecialchars(t('tickets.settings.numbering.reset_monthly')); ?></option>
                        </select>
                        <small style="color: var(--text-muted, #666);"><?php echo t('tickets.settings.numbering.reset_help'); ?></small>
                    </div>
                </div>

                <?php /* ⚠️ The one thing an administrator MUST understand before
                         changing anything, so it sits above the Save button and
                         not in a help page. */ ?>
                <div class="info-box" style="margin:18px 0;padding:12px 14px;border-radius:6px;background: var(--accent-soft, #eff6ff);border-left:4px solid var(--accent, #0078d4);">
                    <small><?php echo t('tickets.settings.numbering.existing_note'); ?></small>
                </div>

                <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </form>

            <?php /* ── Renumbering ─────────────────────────────────────────
                     Deliberately below a rule and its own heading: it is a
                     MIGRATION tool, not part of choosing a format. */ ?>
            <hr style="margin:32px 0;border:0;border-top:1px solid var(--border, #e0e0e0);">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.numbering.renumber_heading')); ?></h2>
            </div>
            <p style="margin-bottom: 16px; color: var(--text-muted, #666); max-width:700px;"><?php echo t('tickets.settings.numbering.renumber_intro'); ?></p>

            <div class="info-box" style="max-width:700px;margin-bottom:16px;padding:12px 14px;border-radius:6px;background: var(--warning-bg, #fff8e6);border-left:4px solid var(--warning-border, #d18b00);color: var(--warning-text, #6b4e00);">
                <small><?php echo t('tickets.settings.numbering.renumber_safety'); ?></small>
            </div>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <button type="button" class="btn btn-outline" onclick="numRenumber('preview')"><?php echo htmlspecialchars(t('tickets.settings.numbering.renumber_preview')); ?></button>
                <button type="button" class="btn btn-primary" id="numRenumberGo" onclick="numRenumber('live')" disabled><?php echo htmlspecialchars(t('tickets.settings.numbering.renumber_go')); ?></button>
                <small id="numRenumberHint" style="color: var(--text-dim, #888);"><?php echo htmlspecialchars(t('tickets.settings.numbering.renumber_preview_first')); ?></small>
            </div>
            <div id="numRenumberOut" style="margin-top:16px;max-width:700px;"></div>
        </div>

        <div class="tab-content<?php echo $activeTabId === 'general' ? ' active' : ''; ?>" id="general-tab" data-capability="<?php echo Cap::TICKETS_GENERAL; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.general_settings')); ?></h2>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.general'); ?></p>
            <form id="generalSettingsForm" style="max-width: 600px;">
                <div class="form-group">
                    <label for="systemName"><?php echo htmlspecialchars(t('tickets.settings.general.system_name')); ?></label>
                    <input type="text" id="systemName" placeholder="<?php echo htmlspecialchars(t('tickets.settings.general.system_name_placeholder')); ?>">
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.system_name_help')); ?></small>
                </div>

                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('tickets.settings.general.reopen_label')); ?></label>
                    <label style="display:block;margin-top:6px;font-weight:400;">
                        <input type="checkbox" id="reopenOnCustomerReply"> <?php echo htmlspecialchars(t('tickets.settings.general.reopen_toggle')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.reopen_help')); ?></small>
                </div>

                <div class="form-group">
                    <label for="snoozeWakeHour"><?php echo htmlspecialchars(t('tickets.settings.general.snooze_hour_label')); ?></label>
                    <select id="snoozeWakeHour">
                        <?php for ($h = 0; $h < 24; $h++): ?>
                        <option value="<?php echo $h; ?>"><?php echo sprintf('%02d:00', $h); ?></option>
                        <?php endfor; ?>
                    </select>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.snooze_hour_help')); ?></small>
                </div>

                <!-- Long messages (discussion #104). Five knobs rather than one
                     switch, because a service desk that lives in email and one
                     that barely touches it want opposite defaults. -->
                <h3 style="margin-top: 34px;"><?php echo htmlspecialchars(t('tickets.settings.general.collapse_heading')); ?></h3>
                <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('tickets.settings.general.collapse_desc')); ?></p>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="collapseEnabled">
                        <?php echo htmlspecialchars(t('tickets.settings.general.collapse_enabled_label')); ?>
                    </label>
                </div>

                <div class="form-group">
                    <label for="collapseLines"><?php echo htmlspecialchars(t('tickets.settings.general.collapse_lines_label')); ?></label>
                    <input type="number" id="collapseLines" min="4" max="80" step="1" style="max-width: 120px;">
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.collapse_lines_help')); ?></small>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="collapseExpandNewest">
                        <?php echo htmlspecialchars(t('tickets.settings.general.collapse_newest_label')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.collapse_newest_help')); ?></small>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="collapseQuoted">
                        <?php echo htmlspecialchars(t('tickets.settings.general.collapse_quoted_label')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.collapse_quoted_help')); ?></small>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="collapseRemember">
                        <?php echo htmlspecialchars(t('tickets.settings.general.collapse_remember_label')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.collapse_remember_help')); ?></small>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="groupOlder">
                        <?php echo htmlspecialchars(t('tickets.settings.general.group_older_label')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.group_older_help')); ?></small>
                </div>

                <div class="form-group">
                    <label for="groupShow"><?php echo htmlspecialchars(t('tickets.settings.general.group_show_label')); ?></label>
                    <input type="number" id="groupShow" min="2" max="80" step="1" style="max-width: 120px;">
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="flagDuplicates">
                        <?php echo htmlspecialchars(t('tickets.settings.general.flag_duplicates_label')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.flag_duplicates_help')); ?></small>
                </div>

                <!-- The two AI reading aids (#104, ideas 7 and 12).
                     ⚠️ Both are OFF by default and that is deliberate. Every other
                     setting on this page changes how something already free is
                     displayed; these two spend money with the administrator's own
                     API key, and a feature that starts billing on upgrade is not a
                     good surprise. The provider itself is configured once, on the
                     Reply cleanup tab. -->
                <h3 style="margin-top: 34px;"><?php echo htmlspecialchars(t('tickets.settings.general.ai_heading')); ?></h3>
                <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('tickets.settings.general.ai_desc')); ?></p>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="aiSummaryEnabled">
                        <?php echo htmlspecialchars(t('tickets.settings.general.ai_summary_label')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.ai_summary_help')); ?></small>
                </div>

                <div class="form-group">
                    <label for="aiSummaryAutoAfter"><?php echo htmlspecialchars(t('tickets.settings.general.ai_auto_label')); ?></label>
                    <input type="number" id="aiSummaryAutoAfter" min="0" max="100" step="1" style="max-width: 120px;">
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.ai_auto_help')); ?></small>
                </div>

                <div class="form-group">
                    <label for="aiSummaryMaxMessages"><?php echo htmlspecialchars(t('tickets.settings.general.ai_max_label')); ?></label>
                    <input type="number" id="aiSummaryMaxMessages" min="5" max="200" step="1" style="max-width: 120px;">
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.ai_max_help')); ?></small>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="aiSummaryIncludeNotes">
                        <?php echo htmlspecialchars(t('tickets.settings.general.ai_notes_label')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.ai_notes_help')); ?></small>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="aiReadEnabled">
                        <?php echo htmlspecialchars(t('tickets.settings.general.ai_read_label')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.general.ai_read_help')); ?></small>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-start; margin-top: 30px;">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>

        </div>

        <?php endif; ?>

        <!-- Privacy Tab -->
        <?php if (settingsTabVisible($visibleTabs, 'privacy')): ?>
        <div class="tab-content<?php echo $activeTabId === 'privacy' ? ' active' : ''; ?>" id="privacy-tab" data-capability="<?php echo Cap::TICKETS_PRIVACY; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.privacy')); ?></h2>
            </div>
            <p style="margin-bottom: 20px; color: var(--text-muted, #666);"><?php echo t('tickets.settings.intros.privacy'); ?></p>
            <form id="privacySettingsForm" style="max-width: 760px;">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('tickets.settings.privacy.third_party_label')); ?></label>

                    <label style="display:block;margin-top:10px;font-weight:400;">
                        <input type="radio" name="thirdPartyVisibility" value="hide">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.privacy.opt_hide')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.privacy.opt_hide_help')); ?></small>
                    </label>

                    <label style="display:block;margin-top:10px;font-weight:400;">
                        <input type="radio" name="thirdPartyVisibility" value="no_attachments">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.privacy.opt_no_attachments')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.privacy.opt_no_attachments_help')); ?></small>
                    </label>

                    <label style="display:block;margin-top:10px;font-weight:400;">
                        <input type="radio" name="thirdPartyVisibility" value="show">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.privacy.opt_show')); ?></strong>
                        <small style="display:block;margin-left:22px;color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.privacy.opt_show_help')); ?></small>
                    </label>
                </div>

                <!-- --accent-soft, not --ss-accent-soft: this is an analyst page, and
                     the ss- tokens are the portal's green. -->
                <div class="info-box" style="margin-top:18px;padding:12px 14px;border-radius:6px;background: var(--accent-soft, #eff6ff);border-left:4px solid var(--accent, #0078d4);">
                    <small><?php echo t('tickets.settings.privacy.always_visible_note'); ?></small>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-start; margin-top: 30px;">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Reply Cleanup Tab -->
        <?php if (settingsTabVisible($visibleTabs, 'reply-cleanup')): ?>
        <div class="tab-content<?php echo $activeTabId === 'reply-cleanup' ? ' active' : ''; ?>" id="reply-cleanup-tab" data-capability="<?php echo Cap::TICKETS_REPLY_CLEANUP; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.reply_cleanup_ai')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #555);">
                <?php echo t('tickets.settings.reply_cleanup.intro1'); ?>
            </p>
            <p style="color: var(--text-muted, #555);">
                <?php echo t('tickets.settings.reply_cleanup.intro2'); ?>
            </p>

            <div class="reply-cleanup-layout">
                <div>
                    <!-- Provider / model / key — shared reusable panel (Anthropic / OpenAI / OpenRouter). -->
                    <?php renderAiSettingsPanel('tickets_reply_cleanup'); ?>

                    <!-- Tone + custom instructions (reply-cleanup specific), saved separately. -->
                    <form id="replyCleanupForm" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                        <div class="form-group">
                            <label for="rcTone"><?php echo htmlspecialchars(t('tickets.settings.reply_cleanup.tone')); ?></label>
                            <select id="rcTone" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                                <option value="Friendly"><?php echo htmlspecialchars(t('tickets.settings.reply_cleanup.tone_friendly')); ?></option>
                                <option value="Formal"><?php echo htmlspecialchars(t('tickets.settings.reply_cleanup.tone_formal')); ?></option>
                                <option value="Brief"><?php echo htmlspecialchars(t('tickets.settings.reply_cleanup.tone_brief')); ?></option>
                            </select>
                            <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.reply_cleanup.tone_help')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="rcCustomInstructions"><?php echo t('tickets.settings.reply_cleanup.custom_label'); ?></label>
                            <textarea id="rcCustomInstructions" rows="6" maxlength="4000"
                                      style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-family: inherit; resize: vertical;"
                                      placeholder="<?php echo htmlspecialchars(t('tickets.settings.reply_cleanup.custom_placeholder')); ?>"></textarea>
                            <small style="color: var(--text-muted, #666);">
                                <?php echo htmlspecialchars(t('tickets.settings.reply_cleanup.custom_help')); ?>
                            </small>
                        </div>

                        <div style="display: flex; gap: 10px; justify-content: flex-start; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                        </div>
                    </form>
                </div>

                <aside class="reply-cleanup-prompt-panel">
                    <details open>
                        <summary><?php echo htmlspecialchars(t('tickets.settings.reply_cleanup.prompt_summary')); ?></summary>
                        <div style="padding: 0 16px 16px 16px; color: var(--text-muted, #555);">
                            <p style="margin: 0 0 12px 0; font-size: 13px;">
                                <?php echo htmlspecialchars(t('tickets.settings.reply_cleanup.prompt_panel_intro')); ?>
                            </p>
                            <pre id="rcPromptPreview" style="white-space: pre-wrap; word-wrap: break-word; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; line-height: 1.5; background: var(--surface, white); padding: 14px; border: 1px solid var(--border, #e0e0e0); border-radius: 4px; max-height: calc(100vh - 280px); overflow-y: auto; color: var(--text, #333); margin: 0;"></pre>
                        </div>
                    </details>
                </aside>
            </div>
        </div>

        <!-- CSAT Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'csat')): ?>
        <div class="tab-content<?php echo $activeTabId === 'csat' ? ' active' : ''; ?>" id="csat-tab" data-capability="<?php echo Cap::TICKETS_CSAT; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.csat')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #555);">
                <?php echo t('tickets.settings.csat_tab.intro1'); ?>
            </p>
            <p style="color: var(--text-muted, #555);">
                <?php echo t('tickets.settings.csat_tab.intro2'); ?>
            </p>

            <form id="csatSettingsForm" style="max-width: 700px; margin-top: 24px;">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('tickets.settings.csat.mode_label')); ?></label>
                    <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 6px;">
                        <label style="display: flex; gap: 10px; align-items: flex-start; cursor: pointer;">
                            <input type="radio" name="csatMode" value="off" style="margin-top: 3px;">
                            <span><strong><?php echo htmlspecialchars(t('tickets.settings.csat_tab.mode_off')); ?></strong><br><small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.csat_tab.mode_off_help')); ?></small></span>
                        </label>
                        <label style="display: flex; gap: 10px; align-items: flex-start; cursor: pointer;">
                            <input type="radio" name="csatMode" value="auto" style="margin-top: 3px;">
                            <span><strong><?php echo htmlspecialchars(t('tickets.settings.csat_tab.mode_auto')); ?></strong><br><small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.csat_tab.mode_auto_help')); ?></small></span>
                        </label>
                        <label style="display: flex; gap: 10px; align-items: flex-start; cursor: pointer;">
                            <input type="radio" name="csatMode" value="manual" style="margin-top: 3px;">
                            <span><strong><?php echo htmlspecialchars(t('tickets.settings.csat_tab.mode_manual')); ?></strong><br><small style="color: var(--text-muted, #666);"><?php echo t('tickets.settings.csat_tab.mode_manual_help'); ?></small></span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="csatDelay"><?php echo htmlspecialchars(t('tickets.settings.csat.delay_label')); ?></label>
                    <input type="number" id="csatDelay" min="0" max="10080" step="1" style="max-width: 160px;">
                    <small style="display: block; color: var(--text-muted, #666); margin-top: 4px;"><?php echo t('tickets.settings.csat_tab.delay_help'); ?></small>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="csatOnePerTicket">
                        <span><?php echo htmlspecialchars(t('tickets.settings.csat_tab.one_per_ticket')); ?></span>
                    </label>
                    <small style="display: block; color: var(--text-muted, #666); margin-top: 4px; margin-left: 26px;"><?php echo t('tickets.settings.csat_tab.one_per_ticket_help'); ?></small>
                </div>

                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('tickets.settings.csat.scale_label')); ?></label>
                    <div style="display: flex; gap: 20px; margin-top: 6px;">
                        <label style="display: flex; gap: 8px; align-items: center; cursor: pointer;">
                            <input type="radio" name="csatScale" value="stars">
                            <span style="font-size: 18px;">&starf;&starf;&starf;&starf;&starf;</span>
                            <span style="color: var(--text-muted, #666); font-size: 13px;"><?php echo htmlspecialchars(t('tickets.settings.csat_tab.scale_stars')); ?></span>
                        </label>
                        <label style="display: flex; gap: 8px; align-items: center; cursor: pointer;">
                            <input type="radio" name="csatScale" value="emojis">
                            <span style="font-size: 18px;">😡 🙁 😐 🙂 😀</span>
                            <span style="color: var(--text-muted, #666); font-size: 13px;"><?php echo htmlspecialchars(t('tickets.settings.csat_tab.scale_emojis')); ?></span>
                        </label>
                    </div>
                    <small style="display: block; color: var(--text-muted, #666); margin-top: 4px;"><?php echo t('tickets.settings.csat_tab.scale_help'); ?></small>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-start; margin-top: 30px;">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'time-tracking')): ?>
        <!-- Time tracking (discussion #72). Two switches, because hiding the panel
             and closing the API are different decisions: an install can tidy its
             screens without breaking a billing export that nobody told it about. -->
        <div class="tab-content<?php echo $activeTabId === 'time-tracking' ? ' active' : ''; ?>" id="time-tracking-tab" data-capability="<?php echo Cap::TICKETS_MANAGE; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.time_tracking')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #555);">
                <?php echo htmlspecialchars(t('tickets.settings.time_tracking.intro')); ?>
            </p>

            <?php /* Shown when the settings could not be read. Without it, an
                     unreachable endpoint renders as "both switches off" — which is
                     how this very tab shipped broken for ten minutes. */ ?>
            <div class="tt-load-error" id="ttLoadError" hidden>
                <strong><?php echo htmlspecialchars(t('tickets.settings.time_tracking.load_failed')); ?></strong>
                <span><?php echo htmlspecialchars(t('tickets.settings.time_tracking.load_failed_desc')); ?></span>
            </div>

            <div class="tt-block">
                <h3 class="tt-sub" id="ttDefaultHeading"><?php echo htmlspecialchars(t('tickets.settings.time_tracking.default_heading')); ?></h3>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.time_tracking.ui_label')); ?></strong>
                        <?php echo htmlspecialchars(t('tickets.settings.time_tracking.ui_desc')); ?>
                    </div>
                    <label class="switch"><input type="checkbox" id="ttDefaultUi"><span class="slider"></span></label>
                </div>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.time_tracking.api_label')); ?></strong>
                        <?php echo htmlspecialchars(t('tickets.settings.time_tracking.api_desc')); ?>
                    </div>
                    <label class="switch"><input type="checkbox" id="ttDefaultApi"><span class="slider"></span></label>
                </div>
            </div>

            <?php /* Only rendered when there is more than one company. At N=1 this
                     whole block is absent and the page is two switches, which is
                     exactly what discussion #72 asked for. */ ?>
            <div class="tt-block" id="ttCompaniesBlock" hidden>
                <h3 class="tt-sub"><?php echo htmlspecialchars(t('tickets.settings.time_tracking.companies_heading')); ?></h3>
                <p style="color: var(--text-muted, #555); font-size: 13px;">
                    <?php echo htmlspecialchars(t('tickets.settings.time_tracking.companies_intro')); ?>
                </p>
                <table class="settings-table" id="ttCompaniesTable">
                    <thead><tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.time_tracking.col_company')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.time_tracking.col_ui')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.time_tracking.col_api')); ?></th>
                    </tr></thead>
                    <tbody id="ttCompaniesBody"></tbody>
                </table>
            </div>

            <p class="tt-note"><?php echo htmlspecialchars(t('tickets.settings.time_tracking.preserved_note')); ?></p>

            <div class="save-area">
                <button class="btn btn-primary" id="ttSaveBtn"><?php echo htmlspecialchars(t('tickets.settings.time_tracking.save')); ?></button>
            </div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'row-display')): ?>
        <!-- Row display (discussion #61). cap => null: a personal view preference,
             so no data-capability attribute — there is nothing here to grant. The
             one administrative control inside is gated separately, below. -->
        <div class="tab-content<?php echo $activeTabId === 'row-display' ? ' active' : ''; ?>" id="row-display-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.row_display')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #555);">
                <?php echo htmlspecialchars(t('tickets.settings.row_display.intro')); ?>
            </p>

            <div class="rd-layout">
                <div class="rd-controls">
                    <?php
                    // The registry is the single source of truth for which styles a
                    // field accepts — the form is generated from it so the UI cannot
                    // drift from what the server will actually store.
                    $rdRegistry = inboxDisplayRegistry();
                    foreach ($rdRegistry as $rdField => $rdSpec):
                    ?>
                    <div class="rd-field">
                        <label class="rd-field-label"><?php echo htmlspecialchars(t('tickets.settings.row_display.field.' . $rdField)); ?></label>
                        <div class="rd-options" data-field="<?php echo htmlspecialchars($rdField); ?>">
                            <?php foreach ($rdSpec['styles'] as $rdStyle): ?>
                            <label class="rd-option">
                                <input type="radio"
                                       name="rd_<?php echo htmlspecialchars($rdField); ?>"
                                       value="<?php echo htmlspecialchars($rdStyle); ?>"
                                       <?php echo ($rdConfig[$rdField] ?? '') === $rdStyle ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars(t('tickets.settings.row_display.style.' . $rdStyle)); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="rd-help"><?php echo htmlspecialchars(t('tickets.settings.row_display.help.' . $rdField)); ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- A live sample rather than a description. Every combination here
                     is legal, including two stripes or two blocks at once, and the
                     only honest way to choose between them is to look. -->
                <div class="rd-preview-wrap">
                    <div class="rd-preview-label"><?php echo htmlspecialchars(t('tickets.settings.row_display.preview')); ?></div>
                    <div class="rd-preview" id="rdPreview"></div>
                </div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; margin-top: 26px; flex-wrap: wrap;">
                <button type="button" class="btn btn-primary" onclick="rdSave('me')"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <button type="button" class="action-btn" onclick="rdSave('reset')"><?php echo htmlspecialchars(t('tickets.settings.row_display.reset')); ?></button>
                <?php if (analystHasCapability($conn, (int)$_SESSION['analyst_id'], Cap::TICKETS_MANAGE)): ?>
                <span class="rd-admin-sep"></span>
                <button type="button" class="action-btn" onclick="rdSave('install')"><?php echo htmlspecialchars(t('tickets.settings.row_display.set_default')); ?></button>
                <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.row_display.set_default_help')); ?></small>
                <?php endif; ?>
            </div>
            <p id="rdFollowing" style="color: var(--text-muted, #666); font-size: 12px; margin-top: 12px;">
                <?php echo htmlspecialchars($rdPersonal
                    ? t('tickets.settings.row_display.using_personal')
                    : t('tickets.settings.row_display.using_default')); ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal for Add/Edit -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.add.fallback')); ?></div>
            <form id="editForm">
                <input type="hidden" id="itemId">
                <input type="hidden" id="itemType">

                <div class="form-group">
                    <label for="itemName"><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></label>
                    <input type="text" id="itemName" required>
                </div>

                <div class="form-group" id="itemDescriptionGroup">
                    <label for="itemDescription"><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></label>
                    <textarea id="itemDescription"></textarea>
                </div>

                <div class="form-group" id="itemColourGroup" style="display: none;">
                    <label for="itemColour"><?php echo htmlspecialchars(t('tickets.settings.columns.colour')); ?></label>
                    <input type="color" id="itemColour" value="#2563eb" style="width: 60px; height: 32px; padding: 2px;">
                    <small style="color: var(--text-muted, #666); margin-left: 8px;"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.colour_help')); ?></small>
                </div>

                <div class="form-group" id="itemClosedGroup" style="display: none;">
                    <label>
                        <input type="checkbox" id="itemClosed"> <?php echo htmlspecialchars(t('tickets.settings.modals.lookup.closed_label')); ?>
                    </label>
                    <small style="display: block; color: var(--text-muted, #666); margin-top: 4px;"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.closed_help')); ?></small>
                </div>

                <div class="form-group" id="itemPausesSlaGroup" style="display: none;">
                    <label>
                        <input type="checkbox" id="itemPausesSla"> <?php echo htmlspecialchars(t('tickets.settings.pauses_sla.label')); ?>
                    </label>
                    <small style="display: block; color: var(--text-muted, #666); margin-top: 4px;"><?php echo t('tickets.settings.pauses_sla.help'); ?></small>
                </div>

                <div class="form-group" id="itemDefaultGroup" style="display: none;">
                    <label>
                        <input type="checkbox" id="itemDefault"> <?php echo htmlspecialchars(t('tickets.settings.modals.lookup.default_label')); ?>
                    </label>
                    <small style="display: block; color: var(--text-muted, #666); margin-top: 4px;"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.default_help')); ?></small>
                </div>

                <div class="form-group">
                    <label for="itemOrder"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.display_order_label')); ?></label>
                    <input type="number" id="itemOrder" value="0">
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="itemActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('tickets.settings.modals.lookup.active_label')); ?>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mailbox Modal -->
    <div class="modal" id="mailboxModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header" id="mailboxModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.add_title')); ?></div>
            <div class="modal-body">
            <form id="mailboxForm" autocomplete="off">
                <input type="hidden" id="mailboxId">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="mailboxProvider"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.provider')); ?> *</label>
                        <select id="mailboxProvider" onchange="toggleProviderFields()">
                            <option value="microsoft"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.provider_microsoft')); ?></option>
                            <option value="google"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.provider_google')); ?></option>
                            <option value="imap"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.provider_imap')); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="mailboxName"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.display_name')); ?> *</label>
                        <input type="text" id="mailboxName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.display_name_placeholder')); ?>">
                    </div>

                    <div class="form-group">
                        <label for="mailboxEmail"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.target_mailbox')); ?> *</label>
                        <input type="email" id="mailboxEmail" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.target_mailbox_placeholder')); ?>">
                    </div>

                    <div class="form-group provider-microsoft">
                        <label for="mailboxAuthMode"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.auth_mode')); ?> *</label>
                        <select id="mailboxAuthMode" onchange="toggleAuthModeFields()">
                            <option value="delegated"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.auth_mode_delegated')); ?></option>
                            <option value="app_only"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.auth_mode_app_only')); ?></option>
                        </select>
                        <small style="color: var(--text-muted, #666); display: block; margin-top: 4px;" id="mailboxAuthModeHelp"></small>
                    </div>

                    <!-- Multi-tenancy: only shown when more than one company exists (populated by JS). -->
                    <div class="form-group" id="mailboxCompanyGroup" style="display: none; grid-column: span 2;">
                        <label for="mailboxCompany"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.company_label')); ?></label>
                        <select id="mailboxCompany"></select>
                        <small style="color: var(--text-muted, #666); display: block; margin-top: 4px;"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.company_help')); ?></small>
                    </div>

                    <!-- #79: where tickets opened by THIS mailbox say they came from. -->
                    <div class="form-group" id="mailboxOriginGroup" style="grid-column: span 2;">
                        <label for="mailboxOrigin"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.origin_label')); ?></label>
                        <select id="mailboxOrigin"></select>
                        <small style="color: var(--text-muted, #666); display: block; margin-top: 4px;"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.origin_help')); ?></small>
                    </div>

                    <div class="form-group provider-microsoft">
                        <label for="mailboxTenantId"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.azure_tenant_id')); ?> *</label>
                        <input type="text" id="mailboxTenantId" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    </div>

                    <div class="form-group provider-oauth" id="clientIdGroup">
                        <label for="mailboxClientId" id="clientIdLabel"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.client_id')); ?> *</label>
                        <input type="text" id="mailboxClientId" required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    </div>

                    <div class="form-group provider-oauth" style="grid-column: span 2;">
                        <label for="mailboxClientSecret" id="clientSecretLabel"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.client_secret')); ?> *</label>
                        <input type="password" id="mailboxClientSecret" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.client_secret_placeholder')); ?>">
                        <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.client_secret_help')); ?></small>
                    </div>

                    <div class="form-group provider-oauth" style="grid-column: span 2;">
                        <label for="mailboxRedirectUri"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.oauth_redirect_uri')); ?> *</label>
                        <input type="url" id="mailboxRedirectUri" required placeholder="https://yoursite.com/oauth_callback.php">
                    </div>

                    <div class="form-group provider-microsoft" style="grid-column: span 2;">
                        <label for="mailboxScopes"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.oauth_scopes')); ?></label>
                        <input type="text" id="mailboxScopes" value="openid email offline_access User.Read Mail.Read Mail.ReadWrite Mail.Send">
                    </div>

                    <!-- IMAP receive settings (basic mailboxes only) -->
                    <div class="form-group provider-imap">
                        <label for="mailboxImapServer"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imap_server')); ?> *</label>
                        <input type="text" id="mailboxImapServer" value="outlook.office365.com" placeholder="imap.example.com">
                    </div>

                    <div class="form-group provider-imap">
                        <label for="mailboxImapPort"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imap_port')); ?></label>
                        <input type="number" id="mailboxImapPort" value="993">
                    </div>

                    <div class="form-group provider-imap">
                        <label for="mailboxImapEncryption"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imap_encryption')); ?></label>
                        <select id="mailboxImapEncryption">
                            <option value="ssl"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.encryption_ssl')); ?></option>
                            <option value="tls"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.encryption_tls')); ?></option>
                            <option value="none"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.encryption_none')); ?></option>
                        </select>
                    </div>

                    <div class="form-group provider-imap">
                        <label for="mailboxImapUsername"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imap_username')); ?> *</label>
                        <input type="text" id="mailboxImapUsername" autocomplete="off" placeholder="you@example.com">
                    </div>

                    <div class="form-group provider-imap" style="grid-column: span 2;">
                        <label for="mailboxImapPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imap_password')); ?> *</label>
                        <input type="password" id="mailboxImapPassword" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imap_password_placeholder')); ?>">
                        <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imap_password_help')); ?></small>
                    </div>

                    <!-- SMTP send settings (basic mailboxes only) -->
                    <div class="form-group provider-imap">
                        <label for="mailboxSmtpServer"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.smtp_server')); ?> *</label>
                        <input type="text" id="mailboxSmtpServer" placeholder="smtp.example.com">
                    </div>

                    <div class="form-group provider-imap">
                        <label for="mailboxSmtpPort"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.smtp_port')); ?></label>
                        <input type="number" id="mailboxSmtpPort" value="587">
                    </div>

                    <div class="form-group provider-imap">
                        <label for="mailboxSmtpEncryption"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.smtp_encryption')); ?></label>
                        <select id="mailboxSmtpEncryption">
                            <option value="tls"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.encryption_starttls')); ?></option>
                            <option value="ssl"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.encryption_ssl')); ?></option>
                            <option value="none"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.encryption_none')); ?></option>
                        </select>
                    </div>

                    <!-- Sending credentials, asked the way an online checkout asks about a
                         billing address: a toggle that says "same as the IMAP login", and
                         the two fields only when it is turned off. Stating the default
                         beats inferring it from two empty boxes, which cannot tell
                         "nothing is set" apart from "this failed to load". -->
                    <div class="form-group provider-imap" style="grid-column: span 2;">
                        <label style="display: flex; align-items: center; gap: 10px;"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.smtp_same_as_imap')); ?>
                            <label class="toggle-switch" style="margin: 0;">
                                <input type="checkbox" id="mailboxSmtpSameAsImap" checked onchange="toggleSmtpCredentials()">
                                <span class="toggle-slider"></span>
                            </label>
                        </label>
                        <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.smtp_same_as_imap_help')); ?></small>
                    </div>

                    <!-- ⚠️ These two carry `smtp-creds` and NOT `provider-imap`:
                         toggleProviderFields() writes `display` on every .provider-imap
                         element, so a row in both classes would have two functions
                         fighting over it. toggleSmtpCredentials() owns them outright and
                         checks the provider itself. -->
                    <div class="form-group smtp-creds" style="display: none;">
                        <label for="mailboxSmtpUsername"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.smtp_username')); ?></label>
                        <input type="text" id="mailboxSmtpUsername" autocomplete="off" placeholder="you@example.com">
                    </div>

                    <div class="form-group smtp-creds" style="display: none;">
                        <label for="mailboxSmtpPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.smtp_password')); ?></label>
                        <input type="password" id="mailboxSmtpPassword" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imap_password_placeholder')); ?>">
                    </div>

                    <div class="form-group">
                        <label for="mailboxFolder"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.email_folder')); ?></label>
                        <!-- The folder mail is READ from had no Verify, while the folder
                             mail is MOVED to did — so the screen could confirm a folder
                             directly below the field that could not use it (GH #77). -->
                        <div style="display: flex; gap: 8px; align-items: start;">
                            <input type="text" id="mailboxFolder" value="INBOX" style="flex: 1;">
                            <button type="button" class="btn btn-secondary" id="verifyIntakeFolderBtn" onclick="verifyIntakeFolder()" style="padding: 8px 12px; white-space: nowrap;"><?php echo htmlspecialchars(t('tickets.settings.buttons.verify')); ?></button>
                        </div>
                        <small id="verifyIntakeFolderResult" style="display: none; margin-top: 5px;"></small>
                    </div>

                    <div class="form-group">
                        <label for="mailboxMaxEmails"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.max_emails_per_check')); ?></label>
                        <input type="number" id="mailboxMaxEmails" value="10" min="1" max="50">
                    </div>

                    <div class="form-group">
                        <label for="mailboxRejectedAction"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.rejected_emails')); ?></label>
                        <select id="mailboxRejectedAction">
                            <option value="delete"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.rejected_delete')); ?></option>
                            <option value="move_to_deleted"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.rejected_move_to_deleted')); ?></option>
                            <option value="mark_read"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.rejected_mark_read')); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="mailboxImportedAction"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imported_emails')); ?></label>
                        <select id="mailboxImportedAction" onchange="toggleImportedFolder()">
                            <option value="delete"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imported_delete')); ?></option>
                            <option value="move_to_folder"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imported_move_to_folder')); ?></option>
                        </select>
                    </div>

                    <div class="form-group" id="importedFolderGroup" style="display: none; grid-column: span 2;">
                        <label for="mailboxImportedFolder"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.move_to_folder_label')); ?></label>
                        <div style="display: flex; gap: 8px; align-items: start;">
                            <input type="text" id="mailboxImportedFolder" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.move_to_folder_placeholder')); ?>" style="flex: 1;">
                            <button type="button" class="btn btn-secondary" id="verifyFolderBtn" onclick="verifyFolder()" style="padding: 8px 12px; white-space: nowrap;"><?php echo htmlspecialchars(t('tickets.settings.buttons.verify')); ?></button>
                        </div>
                        <small id="verifyFolderResult" style="display: none; margin-top: 5px;"></small>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.active')); ?>
                            <label class="toggle-switch" style="margin: 0;">
                                <input type="checkbox" id="mailboxActive" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </label>
                    </div>
                </div>

                <div style="grid-column: span 2; margin-top: 10px; border-top: 1px solid #e0e0e0; padding-top: 15px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.whitelist_label')); ?></label>
                    <small style="color: var(--text-muted, #666); display: block; margin-bottom: 10px;"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.whitelist_help')); ?></small>

                    <div style="display: flex; gap: 8px; margin-bottom: 10px;">
                        <select id="whitelistType" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;">
                            <option value="domain"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.whitelist_domain')); ?></option>
                            <option value="email"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.whitelist_email')); ?></option>
                        </select>
                        <input type="text" id="whitelistValue" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.whitelist_value_placeholder')); ?>" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;" onkeydown="if(event.key==='Enter'){event.preventDefault();addWhitelistEntry();}">
                        <button type="button" class="btn btn-primary" onclick="addWhitelistEntry()" style="padding: 8px 12px;"><?php echo htmlspecialchars(t('common.add')); ?></button>
                    </div>

                    <div id="whitelistEntries" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
                </div>

            </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeMailboxModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="submit" form="mailboxForm" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Messaging Channel Modal (WhatsApp etc.) -->
    <div class="modal" id="channelModal">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header" id="channelModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.add_title')); ?></div>
            <div class="modal-body">
            <form id="channelForm" autocomplete="off">
                <input type="hidden" id="channelId">
                <input type="hidden" id="channelType" value="whatsapp">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="channelName"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.name')); ?> *</label>
                        <input type="text" id="channelName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.channel.name_placeholder')); ?>">
                    </div>

                    <div class="form-group">
                        <label for="channelProvider"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.provider')); ?> *</label>
                        <select id="channelProvider" onchange="toggleChannelProviderFields()">
                            <option value="twilio">Twilio</option>
                            <option value="meta"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.provider_meta')); ?></option>
                        </select>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="channelPhone"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.phone')); ?></label>
                        <input type="text" id="channelPhone" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.channel.phone_placeholder')); ?>">
                        <small style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.phone_help')); ?></small>
                    </div>

                    <!-- Multi-tenancy: only shown when more than one company exists (populated by JS). -->
                    <div class="form-group" id="channelCompanyGroup" style="display:none; grid-column: span 2;">
                        <label for="channelCompany"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.company')); ?></label>
                        <select id="channelCompany"></select>
                        <small style="color:var(--text-muted, #666); display:block; margin-top:4px;"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.company_help')); ?></small>
                    </div>

                    <!-- Twilio credentials -->
                    <div class="form-group provider-twilio">
                        <label for="channelAccountSid"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.account_sid')); ?> *</label>
                        <input type="text" id="channelAccountSid" placeholder="ACxxxxxxxx">
                    </div>
                    <div class="form-group provider-twilio">
                        <label for="channelAuthToken"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.auth_token')); ?> *</label>
                        <input type="password" id="channelAuthToken" placeholder="••••••••">
                    </div>

                    <!-- Meta credentials -->
                    <div class="form-group provider-meta">
                        <label for="channelPhoneNumberId"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.phone_number_id')); ?> *</label>
                        <input type="text" id="channelPhoneNumberId" placeholder="1234567890">
                    </div>
                    <div class="form-group provider-meta">
                        <label for="channelAccessToken"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.access_token')); ?> *</label>
                        <input type="password" id="channelAccessToken" placeholder="••••••••">
                    </div>
                    <div class="form-group provider-meta">
                        <label for="channelAppSecret"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.app_secret')); ?> *</label>
                        <input type="password" id="channelAppSecret" placeholder="••••••••">
                    </div>
                    <div class="form-group provider-meta">
                        <label for="channelVerifyToken"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.verify_token')); ?></label>
                        <input type="text" id="channelVerifyToken" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.channel.verify_token_placeholder')); ?>">
                        <small style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.verify_token_help')); ?></small>
                    </div>
                    <div class="form-group provider-meta">
                        <label for="channelGraphVersion"><?php echo t('tickets.settings.modals.channel.graph_version'); ?></label>
                        <input type="text" id="channelGraphVersion" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.channel.graph_version_placeholder')); ?>">
                        <small style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.graph_version_help')); ?></small>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="channelIngress"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.ingress')); ?></label>
                        <select id="channelIngress" onchange="toggleChannelIngressFields()">
                            <option value="direct"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.ingress_direct')); ?></option>
                            <option value="relay"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.ingress_relay')); ?></option>
                        </select>
                    </div>
                    <div class="form-group provider-relay" style="grid-column: span 2; display:none;">
                        <label for="channelRelaySecret"><?php echo htmlspecialchars(t('tickets.settings.modals.channel.relay_secret')); ?></label>
                        <input type="text" id="channelRelaySecret" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.channel.relay_secret_placeholder')); ?>">
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label><input type="checkbox" id="channelActive" checked> <?php echo htmlspecialchars(t('tickets.settings.modals.channel.active')); ?></label>
                    </div>

                    <div class="form-group" id="channelWebhookHintGroup" style="grid-column: span 2; display:none;">
                        <label><?php echo htmlspecialchars(t('tickets.settings.modals.channel.webhook_hint_label')); ?></label>
                        <input type="text" id="channelWebhookHint" readonly onclick="this.select()">
                    </div>
                </div>
            </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeChannelModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="submit" form="channelForm" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Web chat Widget Modal -->
    <div class="modal" id="widgetModal">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header" id="widgetModalTitle"><?php echo htmlspecialchars(t('tickets.settings.webchat.add_title')); ?></div>
            <div class="modal-body">
            <form id="widgetForm" autocomplete="off">
                <input type="hidden" id="widgetId">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="widgetName"><?php echo htmlspecialchars(t('tickets.settings.webchat.name')); ?> *</label>
                        <input type="text" id="widgetName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.webchat.name_placeholder')); ?>">
                    </div>

                    <div class="form-group">
                        <label for="widgetAccent"><?php echo htmlspecialchars(t('tickets.settings.webchat.accent')); ?></label>
                        <input type="text" id="widgetAccent" placeholder="#2563eb">
                    </div>

                    <!-- Multi-tenancy: only shown when more than one company exists (populated by JS). -->
                    <div class="form-group" id="widgetCompanyGroup" style="display:none; grid-column: span 2;">
                        <label for="widgetCompany"><?php echo htmlspecialchars(t('tickets.settings.webchat.company')); ?></label>
                        <select id="widgetCompany"></select>
                        <small style="color:var(--text-muted, #666); display:block; margin-top:4px;"><?php echo htmlspecialchars(t('tickets.settings.webchat.company_help')); ?></small>
                    </div>

                    <div class="form-group">
                        <label for="widgetGreeting"><?php echo htmlspecialchars(t('tickets.settings.webchat.greeting')); ?></label>
                        <input type="text" id="widgetGreeting" placeholder="<?php echo htmlspecialchars(t('tickets.settings.webchat.greeting_placeholder')); ?>">
                    </div>

                    <div class="form-group">
                        <label for="widgetLauncher"><?php echo htmlspecialchars(t('tickets.settings.webchat.launcher')); ?></label>
                        <input type="text" id="widgetLauncher" placeholder="<?php echo htmlspecialchars(t('tickets.settings.webchat.launcher_placeholder')); ?>">
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="widgetOrigins"><?php echo htmlspecialchars(t('tickets.settings.webchat.origins')); ?></label>
                        <textarea id="widgetOrigins" rows="3" placeholder="https://example.com"></textarea>
                        <small style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.webchat.origins_help')); ?></small>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="widgetOffline"><?php echo htmlspecialchars(t('tickets.settings.webchat.offline')); ?></label>
                        <textarea id="widgetOffline" rows="2" placeholder="<?php echo htmlspecialchars(t('tickets.settings.webchat.offline_placeholder')); ?>"></textarea>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label><input type="checkbox" id="widgetRequireEmail" checked> <?php echo htmlspecialchars(t('tickets.settings.webchat.require_email')); ?></label>
                    </div>

                    <!-- Availability -->
                    <div class="form-group" style="grid-column: span 2; border-top:1px solid var(--border, #e5e7eb); padding-top:12px; margin-bottom:0;">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.webchat.avail_heading')); ?></strong>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="widgetCalendar"><?php echo htmlspecialchars(t('tickets.settings.webchat.business_hours')); ?></label>
                        <select id="widgetCalendar"><option value=""><?php echo htmlspecialchars(t('tickets.settings.webchat.always_open')); ?></option></select>
                        <small style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.webchat.business_hours_help')); ?></small>
                    </div>

                    <!-- Email delivery -->
                    <div class="form-group" style="grid-column: span 2; border-top:1px solid var(--border, #e5e7eb); padding-top:12px; margin-bottom:0;">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.webchat.email_heading')); ?></strong>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label><input type="checkbox" id="widgetEmailWhenAway"> <?php echo htmlspecialchars(t('tickets.settings.webchat.email_when_away')); ?></label>
                        <small style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.webchat.email_when_away_help')); ?></small>
                    </div>

                    <!-- AI answers -->
                    <div class="form-group" style="grid-column: span 2; border-top:1px solid var(--border, #e5e7eb); padding-top:12px; margin-bottom:0;">
                        <strong><?php echo htmlspecialchars(t('tickets.settings.webchat.ai_heading')); ?></strong>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label><input type="checkbox" id="widgetAiEnabled" onchange="toggleWidgetAiFields()"> <?php echo htmlspecialchars(t('tickets.settings.webchat.ai_enabled')); ?></label>
                        <small style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.webchat.ai_enabled_help')); ?></small>
                    </div>
                    <div class="form-group widget-ai-fields" style="grid-column: span 2;">
                        <label for="widgetAiMode"><?php echo htmlspecialchars(t('tickets.settings.webchat.ai_mode')); ?></label>
                        <select id="widgetAiMode">
                            <option value="assist"><?php echo htmlspecialchars(t('tickets.settings.webchat.ai_mode_assist')); ?></option>
                            <option value="deflect"><?php echo htmlspecialchars(t('tickets.settings.webchat.ai_mode_deflect')); ?></option>
                        </select>
                    </div>
                    <div class="form-group widget-ai-fields" style="grid-column: span 2; margin-bottom:0;">
                        <label><input type="checkbox" id="widgetAiOfferAgent"> <?php echo htmlspecialchars(t('tickets.settings.webchat.ai_offer_agent')); ?></label>
                    </div>
                    <div class="form-group widget-ai-fields" style="grid-column: span 2;">
                        <label><input type="checkbox" id="widgetAiOfferEmail"> <?php echo htmlspecialchars(t('tickets.settings.webchat.ai_offer_email')); ?></label>
                    </div>

                    <div class="form-group">
                        <label><input type="checkbox" id="widgetActive" checked> <?php echo htmlspecialchars(t('tickets.settings.webchat.active')); ?></label>
                    </div>

                    <!-- Embed snippet: populated by JS after the widget is saved (or on edit). -->
                    <div class="form-group" id="widgetEmbedGroup" style="grid-column: span 2;">
                        <label for="widgetEmbed"><?php echo htmlspecialchars(t('tickets.settings.webchat.embed_label')); ?></label>
                        <textarea id="widgetEmbed" rows="7" readonly onclick="this.select()" style="font-family: monospace; font-size: 12px;"></textarea>
                        <small style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.webchat.embed_help')); ?></small>
                    </div>
                </div>
            </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeWidgetModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="submit" form="widgetForm" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Messaging Template Modal -->
    <div class="modal" id="msgTemplateModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header" id="msgTemplateModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.add_title')); ?></div>
            <div class="modal-body">
            <form id="msgTemplateForm" autocomplete="off">
                <input type="hidden" id="msgTemplateId">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label for="msgTemplateName"><?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.name')); ?> *</label>
                        <input type="text" id="msgTemplateName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.name_placeholder')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="msgTemplateProvider"><?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.provider')); ?> *</label>
                        <select id="msgTemplateProvider" onchange="msgTemplateProviderHint()">
                            <option value="twilio">Twilio</option>
                            <option value="meta"><?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.provider_meta')); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="msgTemplateRef" id="msgTemplateRefLabel"><?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.ref_label')); ?> *</label>
                        <input type="text" id="msgTemplateRef" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.ref_placeholder')); ?>">
                        <small id="msgTemplateRefHint" style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.ref_help')); ?></small>
                    </div>
                    <div class="form-group">
                        <label for="msgTemplateLang"><?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.lang')); ?></label>
                        <input type="text" id="msgTemplateLang" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.lang_placeholder')); ?>" value="en">
                        <small style="color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.lang_help')); ?></small>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="msgTemplateBody"><?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.body')); ?> *</label>
                        <textarea id="msgTemplateBody" rows="3" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.body_placeholder')); ?>"></textarea>
                        <small style="color:var(--text-muted, #666);"><?php echo t('tickets.settings.modals.msg_template.body_help'); ?></small>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label><input type="checkbox" id="msgTemplateActive" checked> <?php echo htmlspecialchars(t('tickets.settings.modals.msg_template.active')); ?></label>
                    </div>
                </div>
            </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeMsgTemplateModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="submit" form="msgTemplateForm" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Activity Log Modal -->
    <!-- #79: what is quietly wrong with one mailbox. Opened from the ! beside its
         name. A mailbox can be authenticated and collecting mail and still be
         mis-configured in ways nothing else in this screen would mention. -->
    <div class="modal" id="mailboxProblemsModal">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header" id="mailboxProblemsTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.problems_title')); ?></div>
            <div id="mailboxProblemsBody" style="padding: 4px 0 8px;"></div>
            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="closeMailboxProblems()"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <div class="modal" id="activityModal">
        <!-- Deliberately the widest modal in Settings: these are log tables with a
             subject, an address and an error message competing for the same row, and
             at 850px the error wrapped onto four lines. Viewport units rather than a
             fixed size so it still fits a laptop and a phone. -->
        <div class="modal-content activity-modal-content" style="max-width: 1500px; width: 94vw;">
            <div class="modal-header" id="activityModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.activity.title')); ?></div>

            <!-- Inbound / Outbound. The inbound log existed on its own for a long time,
                 which meant you could see everything that arrived and nothing that left. -->
            <div class="mbx-log-tabs" style="display:flex; gap:4px; border-bottom:1px solid var(--border-color,#e0e0e0); margin-bottom:15px;">
                <button type="button" id="mbxTabInbound" class="mbx-log-tab active" onclick="switchMailboxLogTab('inbound')"><?php echo htmlspecialchars(t('tickets.settings.modals.activity.tab_inbound')); ?></button>
                <button type="button" id="mbxTabOutbound" class="mbx-log-tab" onclick="switchMailboxLogTab('outbound')"><?php echo htmlspecialchars(t('tickets.settings.modals.activity.tab_outbound')); ?> <span id="mbxOutboundBadge" class="mbx-fail-badge" style="display:none;"></span></button>
            </div>

            <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                <input type="text" id="activitySearch" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.activity.search_placeholder')); ?>" style="flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;" oninput="debounceActivitySearch()">
                <select id="outboundStatus" onchange="loadMailboxLog(1)" style="display:none; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;">
                    <option value=""><?php echo htmlspecialchars(t('tickets.settings.modals.activity.status_all')); ?></option>
                    <option value="failed"><?php echo htmlspecialchars(t('tickets.settings.modals.activity.status_failed')); ?></option>
                    <option value="sent"><?php echo htmlspecialchars(t('tickets.settings.modals.activity.status_sent')); ?></option>
                    <option value="skipped"><?php echo htmlspecialchars(t('tickets.settings.modals.activity.status_skipped')); ?></option>
                </select>
            </div>

            <div id="inboundPane" class="activity-log-pane">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.date_time')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.from')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.subject')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.action')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.reason')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="activityList">
                        <tr><td colspan="5" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div id="outboundPane" class="activity-log-pane" style="display:none;">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.date_time')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.to')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.subject')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.sent_by')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.result')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="outboundList">
                        <tr><td colspan="5" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div id="activityPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; font-size: 13px; color: var(--text-muted, #666);"></div>

            <div id="processingLogPanel" style="display: none; margin-top: 15px; border-top: 1px solid #e0e0e0; padding-top: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <strong style="font-size: 14px;"><?php echo htmlspecialchars(t('tickets.settings.modals.activity.processing_log')); ?></strong>
                    <button type="button" class="btn btn-secondary" style="padding: 3px 10px; font-size: 12px;" onclick="closeProcessingLog()"><?php echo htmlspecialchars(t('common.close')); ?></button>
                </div>
                <pre id="processingLogContent" style="background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; padding: 12px; font-size: 12px; max-height: 250px; overflow-y: auto; white-space: pre-wrap; word-break: break-word; margin: 0;"></pre>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeActivityModal()"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <!-- Team Assignment Modal -->
    <div class="modal" id="teamAssignmentModal">
        <div class="modal-content">
            <div class="modal-header" id="teamAssignmentTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.team_assignment.title')); ?></div>
            <form id="teamAssignmentForm">
                <input type="hidden" id="assignmentEntityType">
                <input type="hidden" id="assignmentEntityId">

                <p style="margin-bottom: 15px; color: var(--text-muted, #666);" id="teamAssignmentDesc"><?php echo htmlspecialchars(t('tickets.settings.modals.team_assignment.description')); ?></p>

                <div id="teamAssignmentList" style="max-height: 300px; overflow-y: auto; border: 1px solid var(--border, #ddd); border-radius: 4px;">
                    <div style="padding: 15px; text-align: center; color: var(--text-faint, #999);"><?php echo htmlspecialchars(t('tickets.settings.modals.team_assignment.loading')); ?></div>
                </div>

                <div class="modal-actions" style="margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeTeamAssignmentModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Template Modal -->
    <div class="modal" id="templateModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header" id="templateModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.template.add_title')); ?></div>
            <div class="modal-body">
            <form id="templateForm">
                <input type="hidden" id="templateId">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="templateName"><?php echo htmlspecialchars(t('tickets.settings.modals.template.name')); ?> *</label>
                        <input type="text" id="templateName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.template.name_placeholder')); ?>" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="templateEvent"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_trigger')); ?> *</label>
                        <select id="templateEvent" required>
                            <option value=""><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_select')); ?></option>
                            <option value="new_ticket_email"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_new_ticket')); ?></option>
                            <option value="ticket_assigned"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_assigned')); ?></option>
                            <option value="ticket_closed"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_closed')); ?></option>
                            <option value="note_shared"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_note_shared')); ?></option>
                            <option value="csat_request"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_csat_request')); ?></option>
                        </select>
                    </div>

                    <!-- Which senders this template applies to (#80). Everyone is the
                         default for a new template on purpose: narrowing has to be a
                         deliberate act, so an install always has a catch-all unless
                         somebody removes it. -->
                    <div class="form-group" style="grid-column: span 2;">
                        <label><?php echo htmlspecialchars(t('tickets.settings.scope.field_label')); ?></label>
                        <label class="tpl-scope-choice"><input type="radio" name="tplScope" value="everyone" onchange="switchTemplateScope()"> <?php echo htmlspecialchars(t('tickets.settings.scope.everyone')); ?></label>
                        <label class="tpl-scope-choice"><input type="radio" name="tplScope" value="restricted" onchange="switchTemplateScope()"> <?php echo htmlspecialchars(t('tickets.settings.scope.restricted')); ?></label>
                        <div id="tplRulesBox" style="display: none;">
                            <div id="tplRulesList" class="tpl-rules-list"></div>
                            <div class="tpl-rule-add">
                                <input type="text" id="tplRuleInput" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.settings.scope.rule_placeholder')); ?>" onkeydown="if(event.key==='Enter'){event.preventDefault();addTemplateRule();}">
                                <button type="button" class="btn btn-secondary" onclick="addTemplateRule()"><?php echo htmlspecialchars(t('common.add')); ?></button>
                            </div>
                            <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.scope.rule_help')); ?></small>
                        </div>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="templateSubject"><?php echo htmlspecialchars(t('tickets.settings.modals.template.subject')); ?> *</label>
                        <input type="text" id="templateSubject" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.template.subject_placeholder')); ?>" autocomplete="off">
                        <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.template.subject_help')); ?></small>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="templateBody"><?php echo htmlspecialchars(t('tickets.settings.modals.template.body')); ?> *</label>
                        <div class="tpl-body-tabs">
                            <button type="button" class="tpl-body-tab active" data-tpltab="edit" onclick="switchTemplateBodyTab('edit')"><?php echo htmlspecialchars(t('tickets.settings.modals.template.tab_edit')); ?></button>
                            <button type="button" class="tpl-body-tab" data-tpltab="preview" onclick="switchTemplateBodyTab('preview')"><?php echo htmlspecialchars(t('tickets.settings.modals.template.tab_preview')); ?></button>
                        </div>
                        <div id="tplBodyEdit">
                            <textarea id="templateBody" rows="10" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.template.body_placeholder')); ?>"></textarea>
                            <small style="color: var(--text-muted, #666);">
                                <?php echo htmlspecialchars(t('tickets.settings.modals.template.body_help')); ?>
                            </small>
                        </div>
                        <div id="tplBodyPreview" style="display: none;">
                            <div class="tpl-preview-frame" id="templatePreview"></div>
                            <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.template.preview_note')); ?></small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="templateOrder"><?php echo htmlspecialchars(t('tickets.settings.modals.template.display_order')); ?></label>
                        <input type="number" id="templateOrder" value="0" autocomplete="off">
                    </div>

                    <div class="form-group" style="display: flex; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 10px; margin: 0;">
                            <?php echo htmlspecialchars(t('tickets.settings.modals.template.active')); ?>
                            <label class="toggle-switch" style="margin: 0;">
                                <input type="checkbox" id="templateActive" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </label>
                    </div>
                </div>

            </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeTemplateModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="submit" form="templateForm" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Reply Template Modal -->
    <div class="modal" id="replyTemplateModal">
        <div class="modal-content" style="max-width: 780px;">
            <div class="modal-header" id="replyTemplateModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.reply_template.add_title')); ?></div>
            <div class="modal-body">
            <form id="replyTemplateForm">
                <input type="hidden" id="replyTemplateId">

                <div class="form-group">
                    <label for="replyTemplateName"><?php echo htmlspecialchars(t('tickets.settings.modals.reply_template.name')); ?> *</label>
                    <input type="text" id="replyTemplateName" required maxlength="100" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.reply_template.name_placeholder')); ?>" autocomplete="off">
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.reply_template.name_help')); ?></small>
                </div>

                <div class="form-group">
                    <label for="replyTemplateBody"><?php echo htmlspecialchars(t('tickets.settings.modals.reply_template.body')); ?> *</label>
                    <textarea id="replyTemplateBody" rows="12"></textarea>
                </div>

                <div class="form-group">
                    <label style="margin-bottom: 6px;"><?php echo htmlspecialchars(t('tickets.settings.modals.reply_template.merge_codes')); ?></label>
                    <p style="margin: 0 0 8px; color: var(--text-muted, #666); font-size: 12px;"><?php echo htmlspecialchars(t('tickets.settings.modals.reply_template.merge_help')); ?></p>
                    <div id="replyTemplateMergeCodes" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="replyTemplateOrder"><?php echo htmlspecialchars(t('tickets.settings.modals.reply_template.display_order')); ?></label>
                        <input type="number" id="replyTemplateOrder" value="0" autocomplete="off">
                    </div>

                    <div class="form-group" style="display: flex; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 10px; margin: 0;">
                            <?php echo htmlspecialchars(t('tickets.settings.modals.reply_template.active')); ?>
                            <label class="toggle-switch" style="margin: 0;">
                                <input type="checkbox" id="replyTemplateActive" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </label>
                    </div>
                </div>
            </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeReplyTemplateModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="submit" form="replyTemplateForm" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Rota Shift Modal -->
    <div class="modal" id="rotaShiftModal">
        <div class="modal-content">
            <div class="modal-header" id="rotaShiftModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.add_title')); ?></div>
            <form id="rotaShiftForm">
                <input type="hidden" id="rotaShiftId">

                <div class="form-group">
                    <label for="rotaShiftName"><?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.name')); ?> *</label>
                    <input type="text" id="rotaShiftName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.name_placeholder')); ?>">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="rotaShiftStart"><?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.start_time')); ?> *</label>
                        <input type="time" id="rotaShiftStart" required>
                    </div>

                    <div class="form-group">
                        <label for="rotaShiftEnd"><?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.end_time')); ?> *</label>
                        <input type="time" id="rotaShiftEnd" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="rotaShiftOrder"><?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.display_order')); ?></label>
                    <input type="number" id="rotaShiftOrder" value="0">
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="rotaShiftActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.active')); ?>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRotaShiftModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- SLA Calendar Modal -->
    <div class="modal" id="slaCalendarModal">
        <div class="modal-content" style="max-width:680px;">
            <div class="modal-header" id="slaCalendarModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.add_title')); ?></div>
            <div class="modal-body">
                <form id="slaCalendarForm">
                    <input type="hidden" id="slaCalendarId">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="slaCalendarName"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.name')); ?> *</label>
                            <input type="text" id="slaCalendarName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.name_placeholder')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="slaCalendarTimezone"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.timezone')); ?> *</label>
                            <select id="slaCalendarTimezone" required></select>
                            <small><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.timezone_help')); ?></small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="toggle-label">
                            <span class="toggle-switch">
                                <input type="checkbox" id="slaCalendarIsDefault">
                                <span class="toggle-slider"></span>
                            </span>
                            <?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.default_label')); ?>
                        </label>
                        <small><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.default_help')); ?></small>
                    </div>

                    <h4 style="margin:24px 0 8px;"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.hours_heading')); ?></h4>
                    <p style="color:var(--text-muted, #666);font-size:13px;margin:0 0 10px;"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.hours_help')); ?></p>
                    <div id="slaCalendarHoursGrid" style="display:grid;grid-template-columns:90px 80px 1fr 1fr;gap:8px 12px;align-items:center;">
                        <!-- rows injected by JS: 7 weekdays -->
                    </div>

                    <h4 style="margin:24px 0 8px;"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.holidays_heading')); ?></h4>
                    <p style="color:var(--text-muted, #666);font-size:13px;margin:0 0 10px;"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.holidays_help')); ?></p>
                    <div id="slaCalendarHolidaysList" style="margin-bottom:10px;"></div>
                    <div style="display:flex;gap:8px;">
                        <input type="date" id="slaCalendarHolidayDate" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;">
                        <input type="text" id="slaCalendarHolidayName" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.holiday_name_placeholder')); ?>" style="flex:1;padding:6px 10px;border:1px solid #ddd;border-radius:4px;">
                        <button type="button" class="btn btn-secondary" onclick="addSlaHoliday()"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.add_holiday')); ?></button>
                    </div>
                    <small style="color:var(--text-muted, #666);display:block;margin-top:4px;"><?php echo htmlspecialchars(t('tickets.settings.modals.sla_calendar.holidays_note')); ?></small>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="slaCalendarDeleteBtn" onclick="deleteSlaCalendar()" style="display:none;margin-right:auto;"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" onclick="closeSlaCalendarModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="submit" form="slaCalendarForm" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>
    </div><!-- /.settings-shell -->

    <script>
        const API_BASE = '../../api/tickets/';
        const API_SETTINGS = '../../api/settings/';
        let currentTab = 'departments';

        let mailboxes = [];
        let whitelistEntries = [];
        let teams = [];
        let departmentTeams = {}; // Cache for department->teams mapping
        let analystTeams = {}; // Cache for analyst->teams mapping

        // Load data on page load
        document.addEventListener('DOMContentLoaded', function() {
            // loadTeams still runs — it populates the `teams` global the
            // Departments tab's team-assignment picker reads. Team and analyst
            // management moved to System → Teams / System → Analysts.
            loadTeams().then(() => {
                loadDepartments();
            });
            loadTicketTypes();
            loadTicketOrigins();
            loadTicketStatuses();
            loadTicketPriorities();
            loadRotaLocations();
            loadMailboxes();
            loadChannels();
            loadWidgets();
            loadMsgTemplates();
            loadEmailTemplates();
            loadReplyTemplates();
            loadRotaShifts();
            loadRotaWeekendSetting();
            loadSlaTab();

            // Auto-switch to mailboxes tab if OAuth success
            <?php if ($oauthSuccess && $oauthMailboxId): ?>
            switchTab('mailboxes');
            <?php endif; ?>
        });

        // Switch tabs
        function switchTab(tab) {
            currentTab = tab;

            // Update tab buttons
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');

            // Update tab content
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        // Load departments
        async function loadDepartments() {
            try {
                const response = await fetch(API_BASE + 'get_departments.php');
                const data = await response.json();

                if (data.success) {
                    renderDepartments(data.departments);
                } else {
                    showToast('Error loading departments: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Load ticket types
        async function loadTicketTypes() {
            try {
                const response = await fetch(API_BASE + 'get_ticket_types.php?manage=1');
                const data = await response.json();

                if (data.success) {
                    renderTicketTypes(data);
                } else {
                    showToast('Error loading ticket types: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Load ticket origins
        async function loadTicketOrigins() {
            try {
                const response = await fetch(API_BASE + 'get_ticket_origins.php?manage=1');
                const data = await response.json();

                if (data.success) {
                    renderTicketOrigins(data);
                } else {
                    showToast('Error loading ticket origins: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Load ticket statuses
        let ticketStatusesCache = [];
        async function loadTicketStatuses() {
            try {
                const response = await fetch(API_BASE + 'get_ticket_statuses.php');
                const data = await response.json();
                if (data.success) {
                    ticketStatusesCache = data.statuses;
                    renderTicketStatuses(data.statuses);
                } else {
                    showToast('Error loading statuses: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        function renderTicketStatuses(statuses) {
            const tbody = document.getElementById('statuses-list');
            if (!statuses || statuses.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">No statuses found</td></tr>';
                return;
            }
            tbody.innerHTML = statuses.map(s => {
                const safeName = escapeHtml(s.name).replace(/'/g, "\\'");
                const swatch = s.colour
                    ? `<span style="display:inline-block; width:20px; height:20px; border-radius:4px; background:${escapeHtml(s.colour)}; vertical-align:middle; border:1px solid #ddd; margin-right:6px;"></span><code style="font-size:12px;">${escapeHtml(s.colour)}</code>`
                    : '<span style="color:var(--text-faint, #999);">—</span>';
                const closed  = s.is_closed  ? '<span class="status-badge status-active">Yes</span>' : '<span style="color:var(--text-faint, #999);">No</span>';
                const def     = s.is_default ? '<span class="status-badge status-active">Yes</span>' : '<span style="color:var(--text-faint, #999);">No</span>';
                const pauseCell = s.pauses_sla
                    ? '<span class="status-badge status-active" title="SLA clock pauses while a ticket is in this status">&#9208; Yes</span>'
                    : '<span style="color:var(--text-faint, #999);">No</span>';
                return `
                <tr>
                    <td><strong>${escapeHtml(s.name)}</strong></td>
                    <td>${swatch}</td>
                    <td>${closed}</td>
                    <td>${pauseCell}</td>
                    <td>${def}</td>
                    <td>${s.display_order}</td>
                    <td><span class="status-badge status-${s.is_active ? 'active' : 'inactive'}">${s.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('status', ${s.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('status', ${s.id}, '${safeName}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        // Load ticket priorities
        let ticketPrioritiesCache = [];
        async function loadTicketPriorities() {
            try {
                const response = await fetch(API_BASE + 'get_ticket_priorities.php');
                const data = await response.json();
                if (data.success) {
                    ticketPrioritiesCache = data.priorities;
                    renderTicketPriorities(data.priorities);
                } else {
                    showToast('Error loading priorities: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        function renderTicketPriorities(priorities) {
            const tbody = document.getElementById('priorities-list');
            if (!priorities || priorities.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No priorities found</td></tr>';
                return;
            }
            tbody.innerHTML = priorities.map(p => {
                const safeName = escapeHtml(p.name).replace(/'/g, "\\'");
                const swatch = p.colour
                    ? `<span style="display:inline-block; width:20px; height:20px; border-radius:4px; background:${escapeHtml(p.colour)}; vertical-align:middle; border:1px solid #ddd; margin-right:6px;"></span><code style="font-size:12px;">${escapeHtml(p.colour)}</code>`
                    : '<span style="color:var(--text-faint, #999);">—</span>';
                const def = p.is_default ? '<span class="status-badge status-active">Yes</span>' : '<span style="color:var(--text-faint, #999);">No</span>';
                return `
                <tr>
                    <td><strong>${escapeHtml(p.name)}</strong></td>
                    <td>${swatch}</td>
                    <td>${def}</td>
                    <td>${p.display_order}</td>
                    <td><span class="status-badge status-${p.is_active ? 'active' : 'inactive'}">${p.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('priority', ${p.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('priority', ${p.id}, '${safeName}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        // Load rota locations
        let rotaLocationsCache = [];
        async function loadRotaLocations() {
            try {
                const response = await fetch(API_BASE + 'get_rota_locations.php');
                const data = await response.json();
                if (data.success) {
                    rotaLocationsCache = data.locations;
                    renderRotaLocations(data.locations);
                } else {
                    showToast('Error loading rota locations: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        function renderRotaLocations(locations) {
            const tbody = document.getElementById('rota-locations-list');
            if (!locations || locations.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No rota locations found</td></tr>';
                return;
            }
            tbody.innerHTML = locations.map(l => {
                const safeName = escapeHtml(l.name).replace(/'/g, "\\'");
                const swatch = l.colour
                    ? `<span style="display:inline-block; width:20px; height:20px; border-radius:4px; background:${escapeHtml(l.colour)}; vertical-align:middle; border:1px solid #ddd; margin-right:6px;"></span><code style="font-size:12px;">${escapeHtml(l.colour)}</code>`
                    : '<span style="color:var(--text-faint, #999);">—</span>';
                const def = l.is_default ? '<span class="status-badge status-active">Yes</span>' : '<span style="color:var(--text-faint, #999);">No</span>';
                return `
                <tr>
                    <td><strong>${escapeHtml(l.name)}</strong></td>
                    <td>${swatch}</td>
                    <td>${def}</td>
                    <td>${l.display_order}</td>
                    <td><span class="status-badge status-${l.is_active ? 'active' : 'inactive'}">${l.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('rota-location', ${l.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('rota-location', ${l.id}, '${safeName}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        // Configure modal field visibility for the entity being edited
        function configureModalFields(type) {
            const isStatus       = type === 'status';
            const isPriority     = type === 'priority';
            const isRotaLocation = type === 'rota-location';
            const isColouredLookup = isStatus || isPriority || isRotaLocation;
            document.getElementById('itemDescriptionGroup').style.display = isColouredLookup ? 'none' : '';
            document.getElementById('itemColourGroup').style.display      = isColouredLookup ? '' : 'none';
            document.getElementById('itemClosedGroup').style.display      = isStatus ? '' : 'none';
            document.getElementById('itemPausesSlaGroup').style.display   = isStatus ? '' : 'none';
            document.getElementById('itemDefaultGroup').style.display     = isColouredLookup ? '' : 'none';
        }

        // Load teams
        async function loadTeams() {
            try {
                const response = await fetch(API_BASE + 'get_teams.php');
                const data = await response.json();

                if (data.success) {
                    teams = data.teams;
                    renderTeams(teams);
                    return teams;
                } else {
                    console.error('Error loading teams:', data.error);
                    // No teams table on this page anymore (moved to System → Teams);
                    // loadTeams still runs only to feed the Departments team-picker.
                    const el = document.getElementById('teams-list');
                    if (el) el.innerHTML = '<tr><td colspan="7" style="text-align: center; color: red;">Error: ' + data.error + '</td></tr>';
                    return [];
                }
            } catch (error) {
                console.error('Error loading teams:', error);
                const el = document.getElementById('teams-list');
                if (el) el.innerHTML = '<tr><td colspan="7" style="text-align: center; color: red;">Failed to load teams.</td></tr>';
                return [];
            }
        }

        // Render departments
        async function renderDepartments(departments) {
            const tbody = document.getElementById('departments-list');

            if (departments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No departments found</td></tr>';
                return;
            }

            // Load team assignments for all departments
            for (const dept of departments) {
                if (!departmentTeams[dept.id]) {
                    try {
                        const response = await fetch(`${API_BASE}get_department_teams.php?department_id=${dept.id}`);
                        const data = await response.json();
                        departmentTeams[dept.id] = data.success ? data.teams : [];
                    } catch (e) {
                        departmentTeams[dept.id] = [];
                    }
                }
            }

            tbody.innerHTML = departments.map(dept => {
                const deptTeams = departmentTeams[dept.id] || [];
                const teamsText = deptTeams.length > 0
                    ? deptTeams.map(t => `<span class="status-badge" style="background: #e3f2fd; color: #1565c0; margin-right: 4px;">${escapeHtml(t.name)}</span>`).join('')
                    : '<span style="color: var(--text-faint, #999);">None</span>';

                return `
                <tr>
                    <td><strong>${escapeHtml(dept.name)}</strong></td>
                    <td>${escapeHtml(dept.description || '')}</td>
                    <td>${teamsText}</td>
                    <td>${dept.display_order}</td>
                    <td><span class="status-badge status-${dept.is_active ? 'active' : 'inactive'}">${dept.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('department', ${dept.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn" onclick="openTeamAssignment('department', ${dept.id}, '${escapeHtml(dept.name).replace(/'/g, "\\'")}')" title="${t('tickets.settings.tooltips.assign_teams')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('department', ${dept.id}, '${escapeHtml(dept.name)}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `}).join('');
        }

        // Render ticket types
        // SVG markup reused across rows.
        const TT_EDIT_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const TT_DELETE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
        // Open eye = currently visible to this company; slashed eye = hidden from it.
        const TT_EYE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
        const TT_EYE_OFF_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

        function ticketTypeRowEditable(type) {
            return `
                <tr>
                    <td><strong>${escapeHtml(type.name)}</strong></td>
                    <td>${escapeHtml(type.description || '')}</td>
                    <td>${type.display_order}</td>
                    <td><span class="status-badge status-${type.is_active ? 'active' : 'inactive'}">${type.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('ticket-type', ${type.id})" title="${t('common.edit')}">${TT_EDIT_SVG}</button>
                        <button class="action-btn delete" onclick="deleteItem('ticket-type', ${type.id}, '${escapeHtml(type.name)}')" title="${t('common.delete')}">${TT_DELETE_SVG}</button>
                    </td>
                </tr>`;
        }

        function renderTicketTypes(data) {
            const tbody = document.getElementById('ticket-types-list');

            // Multi-company, inside a client company's context → the two-group
            // "shared defaults (add/hide) + this company's own types" view.
            if (data && data.scoped && data.scoped.is_default === false) {
                renderTicketTypesScoped(tbody, data.scoped);
                return;
            }

            // Otherwise: a flat list — single-company install, or the MSP/Default
            // context where you manage the shared defaults themselves (as before).
            const types = (data && data.ticket_types) ? data.ticket_types : [];
            if (types.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No ticket types found</td></tr>';
                return;
            }
            tbody.innerHTML = types.map(ticketTypeRowEditable).join('');
        }

        // Per-company view (design §7 add+hide): the company's own types, then the
        // shared defaults it inherits, each with a Hide/Show toggle.
        function renderTicketTypesScoped(tbody, scoped) {
            const groupRow = (label, hint) =>
                `<tr class="tt-group-row"><td colspan="5" style="background:#f7f9fa;border-top:1px solid #e3e8ea;font-size:12px;font-weight:600;color:#455a64;padding:10px;">${escapeHtml(label)}${hint ? ` <span style="font-weight:400;color:#90a4ae;">— ${escapeHtml(hint)}</span>` : ''}</td></tr>`;

            let html = '';

            // Group 1 — this company's own types.
            html += groupRow(`${scoped.company.name}’s own types`);
            if (!scoped.own.length) {
                html += '<tr><td colspan="5" style="color:#aaa;font-style:italic;padding:10px;">None yet — use Add to create a type just for this company.</td></tr>';
            } else {
                html += scoped.own.map(ticketTypeRowEditable).join('');
            }

            // Group 2 — shared defaults, with a per-company Hide/Show toggle.
            html += groupRow('Shared defaults', `inherited by ${scoped.company.name}`);
            html += scoped.globals.map(tp => {
                const dim = tp.hidden ? 'opacity:0.5;' : '';
                const statusCell = tp.hidden
                    ? '<span class="status-badge status-inactive">Hidden here</span>'
                    : `<span class="status-badge status-${tp.is_active ? 'active' : 'inactive'}">${tp.is_active ? 'Active' : 'Inactive'}</span>`;
                const toggle = tp.hidden
                    ? `<button class="action-btn" onclick="toggleTicketTypeHidden(${tp.id}, false)" title="Hidden from this company — click to show">${TT_EYE_OFF_SVG}</button>`
                    : `<button class="action-btn" onclick="toggleTicketTypeHidden(${tp.id}, true)" title="Visible to this company — click to hide">${TT_EYE_SVG}</button>`;
                return `
                    <tr style="${dim}">
                        <td><strong>${escapeHtml(tp.name)}</strong></td>
                        <td>${escapeHtml(tp.description || '')}</td>
                        <td>${tp.display_order}</td>
                        <td>${statusCell}</td>
                        <td>${toggle}</td>
                    </tr>`;
            }).join('');

            tbody.innerHTML = html;
        }

        // Hide / show a shared default type for the active company (add+hide model).
        async function toggleTicketTypeHidden(id, hidden) {
            try {
                const response = await fetch(API_BASE + 'set_ticket_type_hidden.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ticket_type_id: id, hidden: hidden })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(hidden ? 'Hidden from this company' : 'Shown for this company', 'success');
                    loadTicketTypes();
                } else {
                    showToast(data.error || 'Could not update', 'error');
                }
            } catch (error) {
                showToast('Could not update', 'error');
            }
        }

        // Render ticket origins
        function ticketOriginRowEditable(origin) {
            return `
                <tr>
                    <td><strong>${escapeHtml(origin.name)}</strong></td>
                    <td>${escapeHtml(origin.description || '')}</td>
                    <td>${origin.display_order}</td>
                    <td><span class="status-badge status-${origin.is_active ? 'active' : 'inactive'}">${origin.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('ticket-origin', ${origin.id})" title="${t('common.edit')}">${TT_EDIT_SVG}</button>
                        <button class="action-btn delete" onclick="deleteItem('ticket-origin', ${origin.id}, '${escapeHtml(origin.name)}')" title="${t('common.delete')}">${TT_DELETE_SVG}</button>
                    </td>
                </tr>`;
        }

        function renderTicketOrigins(data) {
            const tbody = document.getElementById('ticket-origins-list');

            // Multi-company, client context → the two-group add/hide view.
            if (data && data.scoped && data.scoped.is_default === false) {
                renderTicketOriginsScoped(tbody, data.scoped);
                return;
            }

            const origins = (data && data.origins) ? data.origins : [];
            if (origins.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No ticket origins found</td></tr>';
                return;
            }
            tbody.innerHTML = origins.map(ticketOriginRowEditable).join('');
        }

        function renderTicketOriginsScoped(tbody, scoped) {
            const groupRow = (label, hint) =>
                `<tr class="tt-group-row"><td colspan="5" style="background:#f7f9fa;border-top:1px solid #e3e8ea;font-size:12px;font-weight:600;color:#455a64;padding:10px;">${escapeHtml(label)}${hint ? ` <span style="font-weight:400;color:#90a4ae;">— ${escapeHtml(hint)}</span>` : ''}</td></tr>`;

            let html = '';
            html += groupRow(`${scoped.company.name}’s own origins`);
            if (!scoped.own.length) {
                html += '<tr><td colspan="5" style="color:#aaa;font-style:italic;padding:10px;">None yet — use Add to create an origin just for this company.</td></tr>';
            } else {
                html += scoped.own.map(ticketOriginRowEditable).join('');
            }

            html += groupRow('Shared defaults', `inherited by ${scoped.company.name}`);
            html += scoped.globals.map(o => {
                const dim = o.hidden ? 'opacity:0.5;' : '';
                const statusCell = o.hidden
                    ? '<span class="status-badge status-inactive">Hidden here</span>'
                    : `<span class="status-badge status-${o.is_active ? 'active' : 'inactive'}">${o.is_active ? 'Active' : 'Inactive'}</span>`;
                const toggle = o.hidden
                    ? `<button class="action-btn" onclick="toggleTicketOriginHidden(${o.id}, false)" title="Hidden from this company — click to show">${TT_EYE_OFF_SVG}</button>`
                    : `<button class="action-btn" onclick="toggleTicketOriginHidden(${o.id}, true)" title="Visible to this company — click to hide">${TT_EYE_SVG}</button>`;
                return `
                    <tr style="${dim}">
                        <td><strong>${escapeHtml(o.name)}</strong></td>
                        <td>${escapeHtml(o.description || '')}</td>
                        <td>${o.display_order}</td>
                        <td>${statusCell}</td>
                        <td>${toggle}</td>
                    </tr>`;
            }).join('');

            tbody.innerHTML = html;
        }

        async function toggleTicketOriginHidden(id, hidden) {
            try {
                const response = await fetch(API_BASE + 'set_ticket_origin_hidden.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ticket_origin_id: id, hidden: hidden })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(hidden ? 'Hidden from this company' : 'Shown for this company', 'success');
                    loadTicketOrigins();
                } else {
                    showToast(data.error || 'Could not update', 'error');
                }
            } catch (error) {
                showToast('Could not update', 'error');
            }
        }

        // Render teams
        async function renderTeams(teamsList) {
            const tbody = document.getElementById('teams-list');
            // The Teams tab moved to System → Teams; there's no table to paint
            // here anymore. loadTeams() still runs to populate the `teams` global
            // for the Departments team-assignment picker, so just bail out.
            if (!tbody) return;

            if (teamsList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No teams found. Click "Add" to create your first team.</td></tr>';
                return;
            }

            // For each team, we need to get department and analyst counts
            const teamsWithCounts = await Promise.all(teamsList.map(async (team) => {
                let deptCount = 0;
                let analystCount = 0;

                try {
                    // Get departments linked to this team
                    const deptResponse = await fetch(`${API_BASE}get_team_departments.php?team_id=${team.id}`);
                    const deptData = await deptResponse.json();
                    deptCount = deptData.success ? deptData.departments.length : 0;
                } catch (e) { }

                try {
                    // Get analysts linked to this team
                    const analystResponse = await fetch(`${API_BASE}get_team_analysts.php?team_id=${team.id}`);
                    const analystData = await analystResponse.json();
                    analystCount = analystData.success ? analystData.analysts.length : 0;
                } catch (e) { }

                return { ...team, deptCount, analystCount };
            }));

            tbody.innerHTML = teamsWithCounts.map(team => {
                const safeName = escapeHtml(team.name).replace(/'/g, "\\'");

                return `
                <tr>
                    <td><strong>${escapeHtml(team.name)}</strong></td>
                    <td>${escapeHtml(team.description || '')}</td>
                    <td>${team.deptCount} department(s)</td>
                    <td>${team.analystCount} analyst(s)</td>
                    <td>${team.display_order}</td>
                    <td><span class="status-badge status-${team.is_active ? 'active' : 'inactive'}">${team.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('team', ${team.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('team', ${team.id}, '${safeName}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `}).join('');
        }

        // Open add modal
        function openAddModal(type) {
            const titles = {
                'department':    t('tickets.settings.modals.lookup.add.department'),
                'ticket-type':   t('tickets.settings.modals.lookup.add.ticket_type'),
                'ticket-origin': t('tickets.settings.modals.lookup.add.ticket_origin'),
                'team':          t('tickets.settings.modals.lookup.add.team'),
                'status':        t('tickets.settings.modals.lookup.add.status'),
                'priority':      t('tickets.settings.modals.lookup.add.priority'),
                'rota-location': t('tickets.settings.modals.lookup.add.rota_location')
            };
            document.getElementById('modalTitle').textContent = titles[type] || t('tickets.settings.modals.lookup.add.fallback');
            document.getElementById('itemType').value = type;
            document.getElementById('itemId').value = '';
            document.getElementById('itemName').value = '';
            document.getElementById('itemDescription').value = '';
            document.getElementById('itemOrder').value = '0';
            document.getElementById('itemActive').checked = true;
            document.getElementById('itemColour').value = type === 'status' ? '#2563eb' : '#2563eb';
            document.getElementById('itemClosed').checked = false;
            document.getElementById('itemPausesSla').checked = false;
            document.getElementById('itemDefault').checked = false;
            configureModalFields(type);
            document.getElementById('editModal').classList.add('active');
        }

        // Edit item
        async function editItem(type, id) {
            const endpoints = {
                'department': API_BASE + 'get_departments.php',
                'ticket-type': API_BASE + 'get_ticket_types.php',
                'ticket-origin': API_BASE + 'get_ticket_origins.php',
                'team': API_BASE + 'get_teams.php',
                'status': API_BASE + 'get_ticket_statuses.php',
                'priority': API_BASE + 'get_ticket_priorities.php',
                'rota-location': API_BASE + 'get_rota_locations.php'
            };
            const titles = {
                'department':    t('tickets.settings.modals.lookup.edit.department'),
                'ticket-type':   t('tickets.settings.modals.lookup.edit.ticket_type'),
                'ticket-origin': t('tickets.settings.modals.lookup.edit.ticket_origin'),
                'team':          t('tickets.settings.modals.lookup.edit.team'),
                'status':        t('tickets.settings.modals.lookup.edit.status'),
                'priority':      t('tickets.settings.modals.lookup.edit.priority'),
                'rota-location': t('tickets.settings.modals.lookup.edit.rota_location')
            };
            const endpoint = endpoints[type];

            try {
                const response = await fetch(endpoint);
                const data = await response.json();

                if (data.success) {
                    let items;
                    if (type === 'department') items = data.departments;
                    else if (type === 'ticket-type') items = data.ticket_types;
                    else if (type === 'ticket-origin') items = data.origins;
                    else if (type === 'team') items = data.teams;
                    else if (type === 'status') items = data.statuses;
                    else if (type === 'priority') items = data.priorities;
                    else if (type === 'rota-location') items = data.locations;

                    const item = items.find(i => i.id == id);

                    if (item) {
                        document.getElementById('modalTitle').textContent = titles[type] || t('tickets.settings.modals.lookup.edit.fallback');
                        document.getElementById('itemType').value = type;
                        document.getElementById('itemId').value = item.id;
                        document.getElementById('itemName').value = item.name;
                        document.getElementById('itemDescription').value = item.description || '';
                        document.getElementById('itemOrder').value = item.display_order;
                        document.getElementById('itemActive').checked = item.is_active;
                        document.getElementById('itemColour').value = item.colour || '#2563eb';
                        document.getElementById('itemClosed').checked = !!item.is_closed;
                        document.getElementById('itemPausesSla').checked = !!item.pauses_sla;
                        document.getElementById('itemDefault').checked = !!item.is_default;
                        configureModalFields(type);
                        document.getElementById('editModal').classList.add('active');
                    }
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Delete item
        async function deleteItem(type, id, name) {
            const ok = await showConfirm({
                title: 'Delete',
                message: `Are you sure you want to delete "${name}"?`,
                okLabel: 'Delete',
                okClass: 'danger'
            });
            if (!ok) return;

            const endpoints = {
                'department': API_BASE + 'delete_department.php',
                'ticket-type': API_BASE + 'delete_ticket_type.php',
                'ticket-origin': API_BASE + 'delete_ticket_origin.php',
                'team': API_BASE + 'delete_team.php',
                'status': API_BASE + 'delete_ticket_status.php',
                'priority': API_BASE + 'delete_ticket_priority.php',
                'rota-location': API_BASE + 'delete_rota_location.php'
            };
            const endpoint = endpoints[type];

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();

                if (data.success) {
                    showToast('Deleted', 'success');
                    if (type === 'department') {
                        loadDepartments();
                    } else if (type === 'ticket-type') {
                        loadTicketTypes();
                    } else if (type === 'ticket-origin') {
                        loadTicketOrigins();
                    } else if (type === 'team') {
                        loadTeams().then(() => {
                            loadDepartments();
                        });
                    } else if (type === 'status') {
                        loadTicketStatuses();
                    } else if (type === 'priority') {
                        loadTicketPriorities();
                    } else if (type === 'rota-location') {
                        loadRotaLocations();
                    }
                } else {
                    showToast('Error deleting item: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to delete item', 'error');
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        // Open team assignment modal
        async function openTeamAssignment(entityType, entityId, entityName) {
            document.getElementById('assignmentEntityType').value = entityType;
            document.getElementById('assignmentEntityId').value = entityId;

            if (entityType === 'department') {
                document.getElementById('teamAssignmentTitle').textContent = `Assign Teams to "${entityName}"`;
                document.getElementById('teamAssignmentDesc').textContent = 'Select which teams should have access to this department:';
            } else if (entityType === 'analyst') {
                document.getElementById('teamAssignmentTitle').textContent = `Assign Teams to "${entityName}"`;
                document.getElementById('teamAssignmentDesc').textContent = 'Select which teams this analyst belongs to:';
            }

            const listContainer = document.getElementById('teamAssignmentList');
            listContainer.innerHTML = '<div style="padding: 15px; text-align: center; color: var(--text-faint, #999);">Loading teams...</div>';

            // Get current assignments
            let currentTeamIds = [];
            try {
                const endpoint = entityType === 'department'
                    ? `${API_BASE}get_department_teams.php?department_id=${entityId}`
                    : `${API_BASE}get_analyst_teams.php?analyst_id=${entityId}`;
                const response = await fetch(endpoint);
                const data = await response.json();
                if (data.success) {
                    currentTeamIds = data.teams.map(t => t.id);
                }
            } catch (e) {
                console.error('Error loading current assignments:', e);
            }

            // Render checkboxes for all active teams
            const activeTeams = teams.filter(t => t.is_active);
            if (activeTeams.length === 0) {
                listContainer.innerHTML = '<div style="padding: 15px; text-align: center; color: var(--text-faint, #999);">No active teams available. Create teams first.</div>';
            } else {
                listContainer.innerHTML = activeTeams.map(team => `
                    <label style="display: flex; align-items: center; padding: 12px 15px; border-bottom: 1px solid var(--border-soft, #eee); cursor: pointer; transition: background 0.2s;"
                           onmouseover="this.style.background='var(--surface-hover, #f5f5f5)'" onmouseout="this.style.background=''">
                        <input type="checkbox" name="team_ids" value="${team.id}" ${currentTeamIds.includes(team.id) ? 'checked' : ''}
                               style="margin-right: 12px; width: 18px; height: 18px;">
                        <div>
                            <strong>${escapeHtml(team.name)}</strong>
                            ${team.description ? `<div style="font-size: 12px; color: var(--text-muted, #666); margin-top: 2px;">${escapeHtml(team.description)}</div>` : ''}
                        </div>
                    </label>
                `).join('');
            }

            document.getElementById('teamAssignmentModal').classList.add('active');
        }

        // Close team assignment modal
        function closeTeamAssignmentModal() {
            document.getElementById('teamAssignmentModal').classList.remove('active');
        }

        // Team assignment form submission
        document.getElementById('teamAssignmentForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const entityType = document.getElementById('assignmentEntityType').value;
            const entityId = document.getElementById('assignmentEntityId').value;

            // Get selected team IDs
            const checkboxes = document.querySelectorAll('#teamAssignmentList input[name="team_ids"]:checked');
            const teamIds = Array.from(checkboxes).map(cb => parseInt(cb.value));

            const endpoint = entityType === 'department'
                ? API_BASE + 'save_department_teams.php'
                : API_BASE + 'save_analyst_teams.php';

            const payload = entityType === 'department'
                ? { department_id: entityId, team_ids: teamIds }
                : { analyst_id: entityId, team_ids: teamIds };

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();

                if (data.success) {
                    closeTeamAssignmentModal();
                    showToast('Saved', 'success');
                    // Clear cache and reload. Only department assignment happens
                    // here now — analyst→team assignment moved to System → Analysts.
                    delete departmentTeams[entityId];
                    loadDepartments();
                    // Also reload teams to keep the picker's `teams` global fresh.
                    loadTeams();
                } else {
                    showToast('Error saving team assignments: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to save team assignments', 'error');
            }
        });

        // Handle form submission
        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const type = document.getElementById('itemType').value;
            const id = document.getElementById('itemId').value;
            const endpoints = {
                'department': API_BASE + 'save_department.php',
                'ticket-type': API_BASE + 'save_ticket_type.php',
                'ticket-origin': API_BASE + 'save_ticket_origin.php',
                'team': API_BASE + 'save_team.php',
                'status': API_BASE + 'save_ticket_status.php',
                'priority': API_BASE + 'save_ticket_priority.php',
                'rota-location': API_BASE + 'save_rota_location.php'
            };
            const endpoint = endpoints[type];

            let formData;
            if (type === 'status') {
                formData = {
                    id: id || null,
                    name: document.getElementById('itemName').value,
                    colour: document.getElementById('itemColour').value,
                    is_closed: document.getElementById('itemClosed').checked ? 1 : 0,
                    pauses_sla: document.getElementById('itemPausesSla').checked ? 1 : 0,
                    is_default: document.getElementById('itemDefault').checked ? 1 : 0,
                    display_order: parseInt(document.getElementById('itemOrder').value),
                    is_active: document.getElementById('itemActive').checked ? 1 : 0
                };
            } else if (type === 'priority' || type === 'rota-location') {
                formData = {
                    id: id || null,
                    name: document.getElementById('itemName').value,
                    colour: document.getElementById('itemColour').value,
                    is_default: document.getElementById('itemDefault').checked ? 1 : 0,
                    display_order: parseInt(document.getElementById('itemOrder').value),
                    is_active: document.getElementById('itemActive').checked ? 1 : 0
                };
            } else {
                formData = {
                    id: id || null,
                    name: document.getElementById('itemName').value,
                    description: document.getElementById('itemDescription').value,
                    display_order: parseInt(document.getElementById('itemOrder').value),
                    is_active: document.getElementById('itemActive').checked ? 1 : 0
                };
            }

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    closeModal();
                    showToast('Saved', 'success');
                    if (type === 'department') {
                        loadDepartments();
                    } else if (type === 'ticket-type') {
                        loadTicketTypes();
                    } else if (type === 'ticket-origin') {
                        loadTicketOrigins();
                    } else if (type === 'team') {
                        loadTeams().then(() => {
                            loadDepartments();
                        });
                    } else if (type === 'status') {
                        loadTicketStatuses();
                    } else if (type === 'priority') {
                        loadTicketPriorities();
                    } else if (type === 'rota-location') {
                        loadRotaLocations();
                    }
                } else {
                    showToast('Error saving: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to save', 'error');
            }
        });

        // Utility function
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Mailbox Functions
        // Multi-tenancy: a name lookup so the list can badge each mailbox with the
        // company it's pinned to. mailboxMultiCompany stays false (badge hidden) on
        // single-company installs.
        let mailboxCompaniesById = {};
        let mailboxMultiCompany = false;
        async function loadMailboxCompanies() {
            try {
                const r = await fetch('../../api/system/get_tenants.php');
                const d = await r.json();
                const companies = d.success ? d.companies : [];
                mailboxCompaniesById = {};
                companies.forEach(c => { mailboxCompaniesById[c.id] = c.name; });
                mailboxMultiCompany = companies.length > 1;
            } catch (e) { mailboxCompaniesById = {}; mailboxMultiCompany = false; }
        }

        // ===== Messaging channels (WhatsApp etc.) =====
        const MSG_API = '../../api/messaging/';
        let channels = [];

        async function loadChannels() {
            try {
                await loadMailboxCompanies(); // reuse the company lookup
                const res = await fetch(MSG_API + 'get_channels.php');
                const data = await res.json();
                if (data.success) {
                    channels = data.channels;
                    const baseInput = document.getElementById('messagingBaseUrl');
                    if (baseInput && document.activeElement !== baseInput) {
                        baseInput.value = data.public_base_url || '';
                    }
                    renderChannels(channels);
                } else {
                    document.getElementById('channels-list').innerHTML =
                        `<tr><td colspan="5" style="text-align:center;color:red;">Error: ${escapeHtml(data.error || '')}</td></tr>`;
                }
            } catch (e) {
                document.getElementById('channels-list').innerHTML =
                    '<tr><td colspan="5" style="text-align:center;color:red;">Failed to load channels.</td></tr>';
            }
        }

        async function saveMessagingBaseUrl() {
            const val = document.getElementById('messagingBaseUrl').value.trim();
            try {
                const res = await fetch(MSG_API + 'save_base_url.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ base_url: val })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Base URL saved — webhook URLs updated', 'success');
                    loadChannels(); // rebuild the displayed webhook URLs
                } else {
                    showToast('Error: ' + (data.error || ''), 'error');
                }
            } catch (e) { showToast('Failed to save base URL', 'error'); }
        }

        function renderChannels(list) {
            const tbody = document.getElementById('channels-list');
            if (!list.length) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No channels yet. Click Add to connect WhatsApp.</td></tr>';
                return;
            }
            tbody.innerHTML = list.map(c => {
                const providerBadge = c.provider === 'meta'
                    ? ' <span class="status-badge" style="background:#e3f2fd;color:#1565c0;">Meta</span>'
                    : ' <span class="status-badge" style="background:#e8f5e9;color:#2e7d32;">Twilio</span>';
                const activeBadge = c.is_active ? '' : ' <span class="status-badge status-inactive">Inactive</span>';
                const credBadge = c.has_credentials
                    ? '<span class="status-badge status-active">Configured</span>'
                    : '<span class="status-badge status-inactive">No credentials</span>';
                let companyBadge = '';
                if (mailboxMultiCompany) {
                    companyBadge = (c.tenant_id && mailboxCompaniesById[c.tenant_id])
                        ? ` <span class="status-badge" style="background:#ede7f6;color:#5e35b1;">${escapeHtml(mailboxCompaniesById[c.tenant_id])}</span>`
                        : ` <span class="status-badge" style="background:#fff3e0;color:#ef6c00;">Shared</span>`;
                }
                const safeName = escapeHtml(c.name).replace(/'/g, "\\'");
                const actions = `
                    <button class="action-btn" onclick="testChannel(${c.id}, this)" title="Test connection">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </button>
                    <button class="action-btn" onclick="editChannel(${c.id})" title="${t('common.edit')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </button>
                    <button class="action-btn delete" onclick="deleteChannel(${c.id}, '${safeName}')" title="${t('common.delete')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </button>`;
                return `
                    <tr>
                        <td><strong>${escapeHtml(c.name)}</strong>${providerBadge}${activeBadge}${companyBadge}</td>
                        <td>${escapeHtml(c.phone_number || '')}</td>
                        <td><code style="font-size:11px;cursor:pointer;" title="Click to select" onclick="const r=document.createRange();r.selectNodeContents(this);const s=getSelection();s.removeAllRanges();s.addRange(r);">${escapeHtml(c.webhook_url)}</code></td>
                        <td>${credBadge}</td>
                        <td>${actions}</td>
                    </tr>`;
            }).join('');
        }

        function populateChannelCompanies(selectedTenantId) {
            const group = document.getElementById('channelCompanyGroup');
            const sel = document.getElementById('channelCompany');
            if (!mailboxMultiCompany) { group.style.display = 'none'; return; }
            group.style.display = '';
            let opts = '<option value="">Shared intake (route by sender number)</option>';
            Object.keys(mailboxCompaniesById).forEach(id => {
                opts += `<option value="${id}" ${String(selectedTenantId) === String(id) ? 'selected' : ''}>${escapeHtml(mailboxCompaniesById[id])}</option>`;
            });
            sel.innerHTML = opts;
        }

        function toggleChannelProviderFields() {
            const p = document.getElementById('channelProvider').value;
            document.querySelectorAll('.provider-twilio').forEach(el => el.style.display = (p === 'twilio') ? '' : 'none');
            document.querySelectorAll('.provider-meta').forEach(el => el.style.display = (p === 'meta') ? '' : 'none');
        }

        function toggleChannelIngressFields() {
            const relay = document.getElementById('channelIngress').value === 'relay';
            document.querySelectorAll('.provider-relay').forEach(el => el.style.display = relay ? '' : 'none');
        }

        function openChannelModal(channel = null) {
            document.getElementById('channelForm').reset();
            document.getElementById('channelId').value = channel ? channel.id : '';
            document.getElementById('channelModalTitle').textContent = channel ? 'Edit channel' : 'Add channel';
            document.getElementById('channelName').value = channel ? channel.name : '';
            document.getElementById('channelProvider').value = channel ? channel.provider : 'twilio';
            document.getElementById('channelPhone').value = channel ? (channel.phone_number || '') : '';
            document.getElementById('channelIngress').value = channel ? (channel.ingress_mode || 'direct') : 'direct';
            document.getElementById('channelActive').checked = channel ? !!channel.is_active : true;
            // Secrets are write-only; show a masked placeholder on edit if configured.
            const mask = (channel && channel.has_credentials) ? '********' : '';
            ['channelAuthToken','channelAccessToken','channelAppSecret'].forEach(idv => document.getElementById(idv).value = mask);
            ['channelAccountSid','channelPhoneNumberId','channelVerifyToken','channelRelaySecret'].forEach(idv => document.getElementById(idv).value = '');
            document.getElementById('channelGraphVersion').value = channel ? (channel.graph_version || '') : '';

            const hintGroup = document.getElementById('channelWebhookHintGroup');
            if (channel && channel.webhook_url) {
                hintGroup.style.display = '';
                document.getElementById('channelWebhookHint').value = channel.webhook_url;
            } else {
                hintGroup.style.display = 'none';
            }

            populateChannelCompanies(channel ? channel.tenant_id : null);
            toggleChannelProviderFields();
            toggleChannelIngressFields();
            document.getElementById('channelModal').classList.add('active');
        }

        function editChannel(id) {
            const c = channels.find(x => x.id === id);
            if (c) openChannelModal(c);
        }

        function closeChannelModal() {
            document.getElementById('channelModal').classList.remove('active');
        }

        async function testChannel(id, btn) {
            const original = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }
            const out = document.getElementById('channelsResult');
            showToast('Running channel tests… (reachability can take a few seconds)', 'info');
            if (out) {
                out.innerHTML = '<div style="padding:10px 12px;color:var(--text-muted, #555);">Running channel tests…</div>';
                out.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            try {
                const res = await fetch(MSG_API + 'test_channel.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, mode: 'all' })
                });
                const data = await res.json();
                if (!data.success) {
                    showToast('Test failed: ' + (data.error || 'unknown error'), 'error');
                    if (out) out.innerHTML = `<div style="padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;">Test failed: ${escapeHtml(data.error || 'unknown error')}</div>`;
                    return;
                }
                const labels = { credentials: 'Credentials', reachability: 'Webhook reachability', simulation: 'Inbound handling' };
                const keys = Object.keys(data.results);
                const failed = keys.filter(k => !data.results[k].ok).length;
                const rows = keys.map(k => {
                    const r = data.results[k];
                    const icon = r.ok ? '✅' : '❌';
                    const color = r.ok ? '#166534' : '#b91c1c';
                    return `<div style="display:flex;gap:8px;padding:6px 0;">
                        <span>${icon}</span>
                        <span><strong>${labels[k] || k}:</strong> <span style="color:${color};">${escapeHtml(r.detail || '')}</span></span>
                    </div>`;
                }).join('');
                if (out) {
                    out.innerHTML = `<div style="padding:12px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">${rows}</div>`;
                    out.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                showToast(failed === 0 ? 'All channel tests passed ✓' : `${failed} of ${keys.length} checks failed — see details above the table`, failed === 0 ? 'success' : 'error');
            } catch (e) {
                showToast('Channel test request failed', 'error');
                if (out) out.innerHTML = `<div style="padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;">Channel test request failed.</div>`;
            } finally {
                if (btn) { btn.disabled = false; btn.style.opacity = ''; btn.innerHTML = original; }
            }
        }

        async function deleteChannel(id, name) {
            if (!confirm(`Delete channel "${name}"? Past tickets are kept; only new messages on this channel stop.`)) return;
            try {
                const res = await fetch(MSG_API + 'delete_channel.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.success) { showToast('Channel deleted', 'success'); loadChannels(); }
                else showToast('Error: ' + (data.error || ''), 'error');
            } catch (e) { showToast('Failed to delete channel', 'error'); }
        }

        document.getElementById('channelForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const payload = {
                id: document.getElementById('channelId').value || null,
                name: document.getElementById('channelName').value.trim(),
                channel_type: document.getElementById('channelType').value,
                provider: document.getElementById('channelProvider').value,
                phone_number: document.getElementById('channelPhone').value.trim(),
                ingress_mode: document.getElementById('channelIngress').value,
                relay_secret: document.getElementById('channelRelaySecret').value.trim(),
                verify_token: document.getElementById('channelVerifyToken').value.trim(),
                tenant_id: document.getElementById('channelCompany').value || null,
                is_active: document.getElementById('channelActive').checked,
                account_sid: document.getElementById('channelAccountSid').value.trim(),
                auth_token: document.getElementById('channelAuthToken').value,
                phone_number_id: document.getElementById('channelPhoneNumberId').value.trim(),
                access_token: document.getElementById('channelAccessToken').value,
                app_secret: document.getElementById('channelAppSecret').value,
                graph_version: document.getElementById('channelGraphVersion').value.trim()
            };
            if (!payload.name) { showToast('Name is required', 'error'); return; }
            try {
                const res = await fetch(MSG_API + 'save_channel.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || 'Saved', 'success');
                    closeChannelModal();
                    loadChannels();
                } else {
                    showToast('Error: ' + (data.error || ''), 'error');
                }
            } catch (err) { showToast('Failed to save channel', 'error'); }
        });

        // ---- Web chat widgets ------------------------------------------------
        const WEBCHAT_API = '../../api/webchat/';
        let widgets = [];
        let webchatCalendars = [];

        async function loadWidgets() {
            const tbody = document.getElementById('widgets-list');
            if (!tbody) return; // tab not visible for this analyst
            try {
                await loadMailboxCompanies(); // reuse the company lookup
                const res = await fetch(WEBCHAT_API + 'get_widgets.php');
                const data = await res.json();
                if (data.success) {
                    widgets = data.widgets;
                    webchatCalendars = data.calendars || [];
                    renderWidgets(widgets);
                } else {
                    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:red;">Error: ${escapeHtml(data.error || '')}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:red;">Failed to load widgets.</td></tr>';
            }
        }

        function renderWidgets(list) {
            const tbody = document.getElementById('widgets-list');
            if (!tbody) return;
            if (!list.length) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;">${escapeHtml(t('tickets.settings.webchat.intro'))}</td></tr>`;
                return;
            }
            tbody.innerHTML = list.map(w => {
                const activeBadge = w.is_active ? '' : ' <span class="status-badge status-inactive">Inactive</span>';
                let companyBadge = '';
                if (mailboxMultiCompany) {
                    companyBadge = (w.tenant_id && mailboxCompaniesById[w.tenant_id])
                        ? ` <span class="status-badge" style="background:#ede7f6;color:#5e35b1;">${escapeHtml(mailboxCompaniesById[w.tenant_id])}</span>`
                        : ` <span class="status-badge" style="background:#fff3e0;color:#ef6c00;">Shared</span>`;
                }
                const origins = (w.allowed_origins || '').split(/[\r\n,]+/).map(s => s.trim()).filter(Boolean);
                const originsCell = origins.length
                    ? origins.map(o => escapeHtml(o)).join('<br>')
                    : `<span style="color:var(--text-muted, #999);">${escapeHtml(t('tickets.settings.webchat.any_origin'))}</span>`;
                const statusBadge = w.is_active
                    ? '<span class="status-badge status-active">Active</span>'
                    : '<span class="status-badge status-inactive">Inactive</span>';
                const safeName = escapeHtml(w.name).replace(/'/g, "\\'");
                const actions = `
                    <button class="action-btn" onclick="editWidget(${w.id})" title="${t('common.edit')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </button>
                    <button class="action-btn delete" onclick="deleteWidget(${w.id}, '${safeName}')" title="${t('common.delete')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </button>`;
                return `
                    <tr>
                        <td><strong>${escapeHtml(w.name)}</strong>${activeBadge}${companyBadge}</td>
                        <td>${originsCell}</td>
                        <td><code style="font-size:11px;cursor:pointer;" title="Click to select" onclick="const r=document.createRange();r.selectNodeContents(this);const s=getSelection();s.removeAllRanges();s.addRange(r);">${escapeHtml(w.widget_key)}</code></td>
                        <td>${statusBadge}</td>
                        <td>${actions}</td>
                    </tr>`;
            }).join('');
        }

        function populateWidgetCompanies(selectedTenantId) {
            const group = document.getElementById('widgetCompanyGroup');
            const sel = document.getElementById('widgetCompany');
            if (!mailboxMultiCompany) { group.style.display = 'none'; return; }
            group.style.display = '';
            let opts = '<option value="">Shared (no company)</option>';
            Object.keys(mailboxCompaniesById).forEach(id => {
                opts += `<option value="${id}" ${String(selectedTenantId) === String(id) ? 'selected' : ''}>${escapeHtml(mailboxCompaniesById[id])}</option>`;
            });
            sel.innerHTML = opts;
        }

        function openWidgetModal(widget = null) {
            document.getElementById('widgetForm').reset();
            document.getElementById('widgetId').value = widget ? widget.id : '';
            document.getElementById('widgetModalTitle').textContent = widget
                ? t('tickets.settings.webchat.edit_title')
                : t('tickets.settings.webchat.add_title');
            document.getElementById('widgetName').value = widget ? (widget.name || '') : '';
            document.getElementById('widgetAccent').value = widget ? (widget.accent_colour || '') : '';
            document.getElementById('widgetGreeting').value = widget ? (widget.greeting || '') : '';
            document.getElementById('widgetLauncher').value = widget ? (widget.launcher_text || '') : '';
            document.getElementById('widgetOrigins').value = widget ? (widget.allowed_origins || '') : '';
            document.getElementById('widgetOffline').value = widget ? (widget.offline_message || '') : '';
            document.getElementById('widgetRequireEmail').checked = widget ? !!widget.require_email : true;
            document.getElementById('widgetActive').checked = widget ? !!widget.is_active : true;

            // Availability calendar dropdown.
            const calSel = document.getElementById('widgetCalendar');
            let calOpts = '<option value="">' + escapeHtml(t('tickets.settings.webchat.always_open')) + '</option>';
            webchatCalendars.forEach(c => {
                const sel = widget && String(widget.business_calendar_id) === String(c.id) ? ' selected' : '';
                calOpts += '<option value="' + c.id + '"' + sel + '>' + escapeHtml(c.name) + '</option>';
            });
            calSel.innerHTML = calOpts;

            // Email + AI controls.
            document.getElementById('widgetEmailWhenAway').checked = widget ? !!widget.email_when_away : false;
            document.getElementById('widgetAiEnabled').checked = widget ? !!widget.ai_enabled : false;
            document.getElementById('widgetAiMode').value = (widget && widget.ai_mode) ? widget.ai_mode : 'assist';
            document.getElementById('widgetAiOfferAgent').checked = widget ? !!widget.ai_offer_agent : true;
            document.getElementById('widgetAiOfferEmail').checked = widget ? !!widget.ai_offer_email : true;
            toggleWidgetAiFields();

            const embedGroup = document.getElementById('widgetEmbedGroup');
            const embedField = document.getElementById('widgetEmbed');
            if (widget && widget.embed_snippet) {
                embedGroup.style.display = '';
                embedField.value = widget.embed_snippet;
            } else {
                embedGroup.style.display = 'none';
                embedField.value = '';
            }

            populateWidgetCompanies(widget ? widget.tenant_id : null);
            document.getElementById('widgetModal').classList.add('active');
        }

        function toggleWidgetAiFields() {
            const on = document.getElementById('widgetAiEnabled').checked;
            document.querySelectorAll('.widget-ai-fields').forEach(el => el.style.display = on ? '' : 'none');
        }

        function editWidget(id) {
            const w = widgets.find(x => x.id === id);
            if (w) openWidgetModal(w);
        }

        function closeWidgetModal() {
            document.getElementById('widgetModal').classList.remove('active');
        }

        async function deleteWidget(id, name) {
            if (!confirm(`Delete web chat widget "${name}"? Past tickets are kept; only new conversations on this widget stop.`)) return;
            try {
                const res = await fetch(WEBCHAT_API + 'delete_widget.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.success) { showToast('Widget deleted', 'success'); loadWidgets(); }
                else showToast('Error: ' + (data.error || ''), 'error');
            } catch (e) { showToast('Failed to delete widget', 'error'); }
        }

        document.getElementById('widgetForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const payload = {
                id: document.getElementById('widgetId').value || null,
                name: document.getElementById('widgetName').value.trim(),
                accent_colour: document.getElementById('widgetAccent').value.trim(),
                greeting: document.getElementById('widgetGreeting').value.trim(),
                launcher_text: document.getElementById('widgetLauncher').value.trim(),
                allowed_origins: document.getElementById('widgetOrigins').value.trim(),
                offline_message: document.getElementById('widgetOffline').value.trim(),
                require_email: document.getElementById('widgetRequireEmail').checked,
                is_active: document.getElementById('widgetActive').checked,
                tenant_id: document.getElementById('widgetCompany').value || null,
                business_calendar_id: document.getElementById('widgetCalendar').value || null,
                email_when_away: document.getElementById('widgetEmailWhenAway').checked,
                ai_enabled: document.getElementById('widgetAiEnabled').checked,
                ai_mode: document.getElementById('widgetAiMode').value,
                ai_offer_agent: document.getElementById('widgetAiOfferAgent').checked,
                ai_offer_email: document.getElementById('widgetAiOfferEmail').checked
            };
            if (!payload.name) { showToast('Name is required', 'error'); return; }
            try {
                const res = await fetch(WEBCHAT_API + 'save_widget.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || 'Saved', 'success');
                    loadWidgets();
                    // On first save, keep the modal open and reveal the embed snippet so
                    // the admin can copy it straight away; flip the form into edit mode.
                    if (data.embed_snippet) {
                        document.getElementById('widgetId').value = data.id;
                        document.getElementById('widgetModalTitle').textContent = t('tickets.settings.webchat.edit_title');
                        const embedGroup = document.getElementById('widgetEmbedGroup');
                        embedGroup.style.display = '';
                        document.getElementById('widgetEmbed').value = data.embed_snippet;
                        embedGroup.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    } else {
                        closeWidgetModal();
                    }
                } else {
                    showToast('Error: ' + (data.error || ''), 'error');
                }
            } catch (err) { showToast('Failed to save widget', 'error'); }
        });

        // ===== Messaging templates =====
        let msgTemplates = [];

        async function loadMsgTemplates() {
            try {
                const res = await fetch(MSG_API + 'get_templates.php?manage=1');
                const data = await res.json();
                if (data.success) {
                    msgTemplates = data.templates;
                    renderMsgTemplates(msgTemplates);
                } else {
                    document.getElementById('msg-templates-list').innerHTML =
                        `<tr><td colspan="5" style="text-align:center;color:red;">Error: ${escapeHtml(data.error || '')}</td></tr>`;
                }
            } catch (e) {
                document.getElementById('msg-templates-list').innerHTML =
                    '<tr><td colspan="5" style="text-align:center;color:red;">Failed to load templates.</td></tr>';
            }
        }

        function renderMsgTemplates(list) {
            const tbody = document.getElementById('msg-templates-list');
            if (!list.length) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No templates yet. Add one to reply after the 24-hour window.</td></tr>';
                return;
            }
            tbody.innerHTML = list.map(tpl => {
                const prov = tpl.provider === 'meta'
                    ? '<span class="status-badge" style="background:#e3f2fd;color:#1565c0;">Meta</span>'
                    : '<span class="status-badge" style="background:#e8f5e9;color:#2e7d32;">Twilio</span>';
                const active = tpl.is_active ? '' : ' <span class="status-badge status-inactive">Inactive</span>';
                const vars = tpl.var_count ? ` <span class="status-badge" style="background:#f3e5f5;color:#6a1b9a;">${tpl.var_count} var${tpl.var_count > 1 ? 's' : ''}</span>` : '';
                const safeName = escapeHtml(tpl.name).replace(/'/g, "\\'");
                const actions = `
                    <button class="action-btn" onclick="editMsgTemplate(${tpl.id})" title="${t('common.edit')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </button>
                    <button class="action-btn delete" onclick="deleteMsgTemplate(${tpl.id}, '${safeName}')" title="${t('common.delete')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </button>`;
                return `<tr>
                    <td><strong>${escapeHtml(tpl.name)}</strong>${active}${vars}</td>
                    <td>${prov}</td>
                    <td><code style="font-size:11px;">${escapeHtml(tpl.provider_ref)}</code></td>
                    <td>${tpl.is_active ? '<span class="status-badge status-active">Active</span>' : '<span class="status-badge status-inactive">Inactive</span>'}</td>
                    <td>${actions}</td>
                </tr>`;
            }).join('');
        }

        function msgTemplateProviderHint() {
            const meta = document.getElementById('msgTemplateProvider').value === 'meta';
            document.getElementById('msgTemplateRefLabel').textContent = meta ? 'Meta template name *' : 'Twilio Content SID *';
            document.getElementById('msgTemplateRef').placeholder = meta ? 'appointment_update' : 'HXxxxxxxxxxxxxxxxx';
            document.getElementById('msgTemplateRefHint').textContent = meta
                ? 'The exact name of the approved template in Meta Business Manager.'
                : 'The Content SID of the approved template in your Twilio console.';
        }

        function openMsgTemplateModal(tpl = null) {
            document.getElementById('msgTemplateForm').reset();
            document.getElementById('msgTemplateId').value = tpl ? tpl.id : '';
            document.getElementById('msgTemplateModalTitle').textContent = tpl ? 'Edit template' : 'Add template';
            document.getElementById('msgTemplateName').value = tpl ? tpl.name : '';
            document.getElementById('msgTemplateProvider').value = tpl ? tpl.provider : 'twilio';
            document.getElementById('msgTemplateRef').value = tpl ? tpl.provider_ref : '';
            document.getElementById('msgTemplateLang').value = tpl ? (tpl.language || 'en') : 'en';
            document.getElementById('msgTemplateBody').value = tpl ? tpl.body : '';
            document.getElementById('msgTemplateActive').checked = tpl ? !!tpl.is_active : true;
            msgTemplateProviderHint();
            document.getElementById('msgTemplateModal').classList.add('active');
        }

        function editMsgTemplate(id) {
            const tpl = msgTemplates.find(x => x.id === id);
            if (tpl) openMsgTemplateModal(tpl);
        }

        function closeMsgTemplateModal() {
            document.getElementById('msgTemplateModal').classList.remove('active');
        }

        async function deleteMsgTemplate(id, name) {
            if (!confirm(`Delete template "${name}"?`)) return;
            try {
                const res = await fetch(MSG_API + 'delete_template.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.success) { showToast('Template deleted', 'success'); loadMsgTemplates(); }
                else showToast('Error: ' + (data.error || ''), 'error');
            } catch (e) { showToast('Failed to delete template', 'error'); }
        }

        document.getElementById('msgTemplateForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const payload = {
                id: document.getElementById('msgTemplateId').value || null,
                name: document.getElementById('msgTemplateName').value.trim(),
                provider: document.getElementById('msgTemplateProvider').value,
                provider_ref: document.getElementById('msgTemplateRef').value.trim(),
                language: document.getElementById('msgTemplateLang').value.trim() || 'en',
                body: document.getElementById('msgTemplateBody').value.trim(),
                is_active: document.getElementById('msgTemplateActive').checked
            };
            if (!payload.name || !payload.provider_ref || !payload.body) { showToast('Name, reference and body are required', 'error'); return; }
            try {
                const res = await fetch(MSG_API + 'save_template.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) { showToast(data.message || 'Saved', 'success'); closeMsgTemplateModal(); loadMsgTemplates(); }
                else showToast('Error: ' + (data.error || ''), 'error');
            } catch (err) { showToast('Failed to save template', 'error'); }
        });

        async function loadMailboxes() {
            try {
                await loadMailboxCompanies();
                const response = await fetch(API_BASE + 'get_mailboxes.php');
                const data = await response.json();

                if (data.success) {
                    mailboxes = data.mailboxes;
                    renderMailboxes(mailboxes);
                } else {
                    console.error('Error loading mailboxes:', data.error);
                    document.getElementById('mailboxes-list').innerHTML =
                        '<tr><td colspan="5" style="text-align: center; color: red;">Error: ' + data.error + '</td></tr>';
                }
            } catch (error) {
                console.error('Error loading mailboxes:', error);
                document.getElementById('mailboxes-list').innerHTML =
                    '<tr><td colspan="5" style="text-align: center; color: red;">Failed to load mailboxes. Check console for details.</td></tr>';
            }
        }

        function renderMailboxes(mailboxes) {
            const tbody = document.getElementById('mailboxes-list');

            if (mailboxes.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align: center;">${escapeHtml(t('tickets.settings.modals.mailbox.empty_state'))}</td></tr>`;
                return;
            }

            tbody.innerHTML = mailboxes.map(mb => {
                // Auth status reflects WHERE the mailbox actually reads from (computed
                // server-side in get_mailboxes.php) so a wrong/unverified account is obvious.
                let statusBadge;
                switch (mb.auth_status) {
                    case 'app_only':
                        statusBadge = '<span class="status-badge" style="background:#e3f2fd;color:#1565c0;">App-only</span>';
                        break;
                    case 'imap':
                        statusBadge = '<span class="status-badge status-active">Connected</span>';
                        break;
                    case 'mismatch':
                        statusBadge = '<span class="status-badge" style="background:#ffebee;color:#c62828;">⚠ Wrong account</span>';
                        break;
                    case 'unverified':
                        statusBadge = '<span class="status-badge" style="background:#fff3e0;color:#ef6c00;">Unverified</span>';
                        break;
                    case 'ok':
                        statusBadge = '<span class="status-badge status-active">Authenticated</span>';
                        break;
                    default:
                        statusBadge = '<span class="status-badge status-inactive">Not authenticated</span>';
                }

                const activeBadge = mb.is_active
                    ? ''
                    : ' <span class="status-badge status-inactive">Inactive</span>';

                const lastChecked = mb.last_checked_datetime
                    ? fmtDateTime(mb.last_checked_datetime)
                    : 'Never';

                let actions = `<button class="action-btn" onclick="editMailbox(${mb.id})" title="${t('common.edit')}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </button>`;

                actions += `<button class="action-btn" onclick="openActivityModal(${mb.id})" title="${t('tickets.settings.tooltips.activity')}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </button>`;

                const checkEmailsBtn = `<button class="action-btn" onclick="checkMailboxEmails(${mb.id})" title="${t('tickets.settings.tooltips.check_emails')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </button>`;
                // App-only and Basic-IMAP mailboxes never use the interactive sign-in flow —
                // they read the target directly (client credentials / stored password), so
                // there's no Authenticate / Logout button, just Check emails.
                if (mb.auth_mode === 'app_only' || mb.provider === 'imap') {
                    actions += checkEmailsBtn;
                } else if (mb.is_authenticated) {
                    actions += checkEmailsBtn;
                    actions += `<button class="action-btn" onclick="logoutMailbox(${mb.id})" title="${t('tickets.settings.tooltips.logout')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    </button>`;
                } else {
                    actions += `<button class="action-btn" onclick="authenticateMailbox(${mb.id})" title="${t('tickets.settings.tooltips.authenticate')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                    </button>`;
                }

                const safeName = escapeHtml(mb.name).replace(/'/g, "\\'");
                actions += `<button class="action-btn delete" onclick="deleteMailbox(${mb.id}, '${safeName}')" title="${t('common.delete')}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>`;

                let providerBadge;
                if (mb.provider === 'google') {
                    providerBadge = ' <span class="status-badge" style="background:#e8f5e9;color:#2e7d32;">Google</span>';
                } else if (mb.provider === 'imap') {
                    providerBadge = ' <span class="status-badge" style="background:#ede7f6;color:#5e35b1;">IMAP</span>';
                } else {
                    providerBadge = ' <span class="status-badge" style="background:#e3f2fd;color:#1565c0;">Microsoft</span>';
                }

                // Multi-tenancy: show the routing target — pinned company, or shared intake.
                let companyBadge = '';
                if (mailboxMultiCompany) {
                    if (mb.tenant_id && mailboxCompaniesById[mb.tenant_id]) {
                        companyBadge = ` <span class="status-badge" style="background:#ede7f6;color:#5e35b1;">${escapeHtml(mailboxCompaniesById[mb.tenant_id])}</span>`;
                    } else {
                        companyBadge = ` <span class="status-badge" style="background:#fff3e0;color:#ef6c00;">${escapeHtml(t('tickets.settings.modals.mailbox.company_shared_badge'))}</span>`;
                    }
                }

                // Plain-language "where is this reading from?" line so it's crystal clear
                // which inbox is actually being pulled — and flags a wrong/unverified account.
                let authLine = '';
                if (mb.auth_status === 'ok') {
                    authLine = `<div style="font-size:12px;color:#2e7d32;margin-top:3px;">✓ ${escapeHtml(t('tickets.settings.modals.mailbox.reading_from', {addr: mb.target_mailbox}))}</div>`;
                } else if (mb.auth_status === 'imap') {
                    authLine = `<div style="font-size:12px;color:#5e35b1;margin-top:3px;">✓ ${escapeHtml(t('tickets.settings.modals.mailbox.status_imap', {addr: mb.target_mailbox}))}</div>`;
                } else if (mb.auth_status === 'app_only') {
                    authLine = `<div style="font-size:12px;color:#1565c0;margin-top:3px;">✓ ${escapeHtml(t('tickets.settings.modals.mailbox.status_app_only', {addr: mb.target_mailbox}))}</div>`;
                } else if (mb.auth_status === 'unverified') {
                    authLine = `<div style="font-size:12px;color:#ef6c00;margin-top:3px;">${escapeHtml(t('tickets.settings.modals.mailbox.status_unverified'))}</div>`;
                } else if (mb.auth_status === 'mismatch') {
                    authLine = `<div style="font-size:12px;color:#c62828;margin-top:3px;font-weight:600;">⚠ ${escapeHtml(t('tickets.settings.modals.mailbox.status_mismatch', {authed: mb.authenticated_as || '?', target: mb.target_mailbox}))}</div>`;
                }

                // Everything that could quietly be wrong with this mailbox, behind one
                // mark (#79). A mailbox can be connected, green and collecting mail and
                // still not be doing what you think — the point of this is that you
                // find that out here rather than weeks later on the tickets.
                // Acknowledged warnings don't count towards the mark — a deliberate
                // choice must be able to stop nagging, or the mark becomes wallpaper.
                const problems = (mb.problems || []).filter(p => !p.dismissed);
                let problemMark = '';
                if (problems.length) {
                    const worst = problems.some(p => p.severity === 'error') ? 'error' : 'warning';
                    const colour = worst === 'error' ? '#c62828' : '#ef6c00';
                    problemMark = ` <button type="button" class="mailbox-problem-mark"
                        onclick="showMailboxProblems(${mb.id})"
                        title="${escapeHtml(t('tickets.settings.modals.mailbox.problems_tooltip'))}"
                        style="background:none;border:none;cursor:pointer;color:${colour};font-weight:700;font-size:15px;padding:0 4px;line-height:1;">!</button>`;
                }

                return `
                    <tr>
                        <td><strong>${escapeHtml(mb.name)}</strong>${problemMark}${providerBadge}${activeBadge}${companyBadge}</td>
                        <td>${escapeHtml(mb.target_mailbox)}${authLine}</td>
                        <td>${statusBadge}</td>
                        <td>${lastChecked}</td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');
        }

        // Multi-tenancy: populate the mailbox "Company" picker. The whole field
        // stays hidden until a second company exists, so single-company installs
        // never see it. value "" = shared intake (route inbound by sender domain).
        async function populateMailboxCompanies(selectedTenantId) {
            const group = document.getElementById('mailboxCompanyGroup');
            const select = document.getElementById('mailboxCompany');
            let companies = [];
            try {
                const r = await fetch('../../api/system/get_tenants.php');
                const d = await r.json();
                companies = d.success ? d.companies : [];
            } catch (e) { companies = []; }

            if (companies.length < 2) {
                group.style.display = 'none';
                select.innerHTML = '';
                return;
            }

            let html = '<option value="">' + escapeHtml(t('tickets.settings.modals.mailbox.company_shared')) + '</option>';
            companies.forEach(c => {
                // Hide inactive companies unless this mailbox is currently pinned to one.
                if (!c.is_active && c.id != selectedTenantId) return;
                html += '<option value="' + c.id + '">' + escapeHtml(c.name) + '</option>';
            });
            select.innerHTML = html;
            select.value = (selectedTenantId === null || selectedTenantId === undefined) ? '' : String(selectedTenantId);
            group.style.display = '';
        }

        // #79: the origins this mailbox may stamp on the tickets it opens. A mailbox
        // pinned to a company gets that company's own origins as well as the global
        // ones; a shared-intake mailbox gets global origins ONLY, because its tickets
        // land in whichever company the sender's domain matches and one company's
        // private origin must not end up on another's ticket. Global origins always
        // exist, so the list is never empty — every mailbox can always set one.
        //
        // Re-runs when the company picker changes, since that changes the answer.
        async function populateMailboxOrigins(selectedOriginId, tenantId) {
            const select = document.getElementById('mailboxOrigin');
            let origins = [];
            try {
                const r = await fetch('../../api/tickets/get_ticket_origins.php');
                const d = await r.json();
                origins = d.success ? (d.origins || []) : [];
            } catch (e) { origins = []; }

            const pinned = (tenantId !== null && tenantId !== undefined && tenantId !== '');
            const usable = origins.filter(o => {
                if (!o.is_active && o.id != selectedOriginId) return false;
                if (o.tenant_id === null || o.tenant_id === undefined) return true;   // global
                return pinned && String(o.tenant_id) === String(tenantId);            // this company's own
            });

            let html = '<option value="">' + escapeHtml(t('tickets.settings.modals.mailbox.origin_none')) + '</option>';
            usable.forEach(o => {
                html += '<option value="' + o.id + '">' + escapeHtml(o.name) + '</option>';
            });
            select.innerHTML = html;
            // If the stored origin isn't offered any more (the mailbox moved company,
            // or the origin was deleted), fall back to blank rather than silently
            // showing the first entry as though it were the saved value.
            select.value = (selectedOriginId === null || selectedOriginId === undefined) ? '' : String(selectedOriginId);
            if (select.value !== String(selectedOriginId ?? '')) select.value = '';
        }

        async function openMailboxModal(mailbox = null) {
            document.getElementById('mailboxModalTitle').textContent = mailbox ? t('tickets.settings.modals.mailbox.edit_title') : t('tickets.settings.modals.mailbox.add_title');
            document.getElementById('mailboxId').value = mailbox ? mailbox.id : '';
            document.getElementById('mailboxProvider').value = mailbox ? (mailbox.provider || 'microsoft') : 'microsoft';
            document.getElementById('mailboxName').value = mailbox ? mailbox.name : '';
            document.getElementById('mailboxEmail').value = mailbox ? mailbox.target_mailbox : '';
            document.getElementById('mailboxAuthMode').value = mailbox ? (mailbox.auth_mode || 'delegated') : 'delegated';
            document.getElementById('mailboxTenantId').value = mailbox ? mailbox.azure_tenant_id : '';
            document.getElementById('mailboxClientId').value = mailbox ? mailbox.azure_client_id : '';
            document.getElementById('mailboxClientSecret').value = '';
            document.getElementById('mailboxRedirectUri').value = mailbox ? mailbox.oauth_redirect_uri : getDefaultOAuthRedirectUri(document.getElementById('mailboxProvider').value);
            document.getElementById('mailboxScopes').value = mailbox ? mailbox.oauth_scopes : 'openid email offline_access User.Read Mail.Read Mail.ReadWrite Mail.Send';
            document.getElementById('mailboxImapServer').value = mailbox ? mailbox.imap_server : 'outlook.office365.com';
            document.getElementById('mailboxImapPort').value = mailbox ? mailbox.imap_port : 993;
            // Basic IMAP / SMTP fields.
            document.getElementById('mailboxImapEncryption').value = mailbox ? (mailbox.imap_encryption || 'ssl') : 'ssl';
            document.getElementById('mailboxImapUsername').value = mailbox ? (mailbox.imap_username || '') : '';
            document.getElementById('mailboxImapPassword').value = '';
            // Show a masked hint when a password is already stored (edit) so it's clear
            // that leaving it blank keeps the existing one.
            document.getElementById('mailboxImapPassword').placeholder = (mailbox && mailbox.imap_password_set)
                ? '••••••••  (' + t('tickets.settings.modals.mailbox.imap_password_kept') + ')'
                : t('tickets.settings.modals.mailbox.imap_password_placeholder');
            document.getElementById('mailboxSmtpServer').value = mailbox ? (mailbox.smtp_server || '') : '';
            document.getElementById('mailboxSmtpPort').value = mailbox ? (mailbox.smtp_port || 587) : 587;
            document.getElementById('mailboxSmtpEncryption').value = mailbox ? (mailbox.smtp_encryption || 'tls') : 'tls';
            // 🔴 The SMTP username MUST be redisplayed. The save writes what this field
            // holds, so a box that came up empty on an edit would quietly replace a
            // working SMTP login with nothing — and because sending then falls back to
            // the IMAP credentials, it would go on looking like it worked.
            const smtpUser = mailbox ? (mailbox.smtp_username || '') : '';
            document.getElementById('mailboxSmtpUsername').value = smtpUser;
            document.getElementById('mailboxSmtpSameAsImap').checked = smtpUser.trim() === '';
            document.getElementById('mailboxSmtpPassword').value = '';
            // Same masked hint as the IMAP password: say when one is already stored, so
            // a blank box reads as "unchanged" rather than "none set".
            document.getElementById('mailboxSmtpPassword').placeholder = (mailbox && mailbox.smtp_password_set)
                ? '••••••••  (' + t('tickets.settings.modals.mailbox.imap_password_kept') + ')'
                : t('tickets.settings.modals.mailbox.imap_password_placeholder');
            toggleProviderFields();
            toggleAuthModeFields();
            document.getElementById('mailboxFolder').value = mailbox ? mailbox.email_folder : 'INBOX';
            document.getElementById('mailboxMaxEmails').value = mailbox ? mailbox.max_emails_per_check : 10;
            document.getElementById('mailboxRejectedAction').value = mailbox ? (mailbox.rejected_action || 'delete') : 'delete';
            document.getElementById('mailboxImportedAction').value = mailbox ? (mailbox.imported_action || 'delete') : 'delete';
            document.getElementById('mailboxImportedFolder').value = mailbox ? (mailbox.imported_folder || '') : '';
            toggleImportedFolder();
            document.getElementById('verifyFolderResult').style.display = 'none';
            document.getElementById('verifyIntakeFolderResult').style.display = 'none';
            document.getElementById('mailboxActive').checked = mailbox ? mailbox.is_active : true;
            await populateMailboxCompanies(mailbox ? (mailbox.tenant_id ?? null) : null);
            await populateMailboxOrigins(
                mailbox ? (mailbox.default_origin_id ?? null) : null,
                mailbox ? (mailbox.tenant_id ?? null) : null
            );
            // Changing the company changes which origins are on offer, so rebuild the
            // list — keeping the current choice if it survives the move.
            const mbCompanySelect = document.getElementById('mailboxCompany');
            mbCompanySelect.onchange = () => populateMailboxOrigins(
                document.getElementById('mailboxOrigin').value || null,
                mbCompanySelect.value || null
            );

            // Load whitelist
            whitelistEntries = [];
            if (mailbox && mailbox.id) {
                try {
                    const res = await fetch(API_BASE + 'get_mailbox_whitelist.php?mailbox_id=' + mailbox.id);
                    const data = await res.json();
                    if (data.success) {
                        whitelistEntries = data.entries.map(e => ({ entry_type: e.entry_type, entry_value: e.entry_value }));
                    }
                } catch (err) {
                    console.error('Failed to load whitelist:', err);
                }
            }
            renderWhitelistEntries();

            document.getElementById('mailboxModal').classList.add('active');
        }

        function closeMailboxModal() {
            document.getElementById('mailboxModal').classList.remove('active');
        }

        // Delegated vs app-only: the redirect URI + delegated scopes only apply to the
        // interactive sign-in flow, so hide them for app-only and update the help text.
        function toggleAuthModeFields() {
            // Basic IMAP has no OAuth redirect / scopes at all — toggleProviderFields
            // already hides them; don't let this function re-show them.
            if (document.getElementById('mailboxProvider').value === 'imap') return;
            // App-only is Microsoft-only — for Google it never applies, so the redirect
            // URI / scopes must stay visible regardless of the (hidden) selector's value.
            const isMicrosoft = document.getElementById('mailboxProvider').value === 'microsoft';
            const isAppOnly = isMicrosoft && document.getElementById('mailboxAuthMode').value === 'app_only';
            const help = document.getElementById('mailboxAuthModeHelp');
            if (help) {
                help.textContent = t(isAppOnly
                    ? 'tickets.settings.modals.mailbox.auth_mode_help_app_only'
                    : 'tickets.settings.modals.mailbox.auth_mode_help_delegated');
            }
            const redirectInput = document.getElementById('mailboxRedirectUri');
            const redirectGroup = redirectInput.closest('.form-group');
            if (redirectGroup) redirectGroup.style.display = isAppOnly ? 'none' : '';
            redirectInput.required = !isAppOnly;
            // A hidden field carrying a custom-validity message can still block submit —
            // clear it so an app-only mailbox can save without a redirect URI.
            if (isAppOnly) redirectInput.setCustomValidity('');
            const scopesGroup = document.getElementById('mailboxScopes').closest('.form-group');
            if (scopesGroup) scopesGroup.style.display = isAppOnly ? 'none' : '';
        }

        /* Show the SMTP credential fields only when the toggle above them is off —
           and only on a basic IMAP mailbox, since it owns their `display` outright
           (see the markup note). Called from toggleProviderFields() as well as from
           the toggle itself, because that function rewrites the whole IMAP block
           every time the provider changes. */
        function toggleSmtpCredentials() {
            const isImap = document.getElementById('mailboxProvider').value === 'imap';
            const sameAsImap = document.getElementById('mailboxSmtpSameAsImap').checked;
            document.querySelectorAll('.smtp-creds').forEach(el => {
                el.style.display = (isImap && !sameAsImap) ? '' : 'none';
            });
        }

        function toggleProviderFields() {
            const provider = document.getElementById('mailboxProvider').value;
            const isMicrosoft = provider === 'microsoft';
            const isImap = provider === 'imap';
            const mailboxId = document.getElementById('mailboxId').value;

            // Microsoft-only fields (auth mode, tenant, scopes).
            document.querySelectorAll('.provider-microsoft').forEach(el => {
                el.style.display = isMicrosoft ? '' : 'none';
            });
            // OAuth fields (client id/secret/redirect) — Microsoft AND Google, not IMAP.
            document.querySelectorAll('.provider-oauth').forEach(el => {
                el.style.display = isImap ? 'none' : '';
            });
            // Basic-IMAP fields (host/login/SMTP).
            document.querySelectorAll('.provider-imap').forEach(el => {
                el.style.display = isImap ? '' : 'none';
            });
            // The SMTP credential rows are deliberately NOT in that class — they
            // answer to the "same as the IMAP login" toggle as well as to the
            // provider, so one function owns them rather than two.
            toggleSmtpCredentials();

            // A hidden `required` input still blocks form submit — toggle required to match
            // visibility so, e.g., an IMAP mailbox can save without OAuth client id/secret.
            const clientIdInput = document.getElementById('mailboxClientId');
            clientIdInput.required = !isImap;
            document.getElementById('mailboxImapServer').required = isImap;
            document.getElementById('mailboxImapUsername').required = isImap;
            document.getElementById('mailboxSmtpServer').required = isImap;
            // Password required only when creating a new IMAP mailbox (blank = keep on edit).
            document.getElementById('mailboxImapPassword').required = isImap && !mailboxId;

            if (isImap) {
                // Clear the Microsoft IMAP-server default when switching to a real IMAP box.
                const imapServer = document.getElementById('mailboxImapServer');
                if (imapServer.value === 'outlook.office365.com') imapServer.value = '';
                // The redirect URI is hidden for IMAP — clear its required flag + any custom
                // validity so a hidden field can't block submit. (Don't call
                // toggleAuthModeFields here: it would re-show the OAuth redirect/scopes.)
                const redirectInput = document.getElementById('mailboxRedirectUri');
                redirectInput.required = false;
                redirectInput.setCustomValidity('');
                return;
            }

            // Update labels
            document.getElementById('clientIdLabel').textContent = isMicrosoft ? 'Azure Client ID *' : 'Google Client ID *';
            document.getElementById('clientSecretLabel').textContent = isMicrosoft ? 'Azure Client Secret *' : 'Google Client Secret *';

            // Update placeholders
            clientIdInput.placeholder = isMicrosoft
                ? 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'
                : 'xxxxxxxxxx-xxxxxxxxx.apps.googleusercontent.com';

            const redirectInput = document.getElementById('mailboxRedirectUri');
            const expectedCallback = isMicrosoft ? 'oauth_callback.php' : 'google_oauth_callback.php';
            const otherCallback = isMicrosoft ? 'google_oauth_callback.php' : 'oauth_callback.php';
            redirectInput.placeholder = getDefaultOAuthRedirectUri(provider);

            if (!mailboxId || redirectInput.value.includes(otherCallback)) {
                redirectInput.value = getDefaultOAuthRedirectUri(provider);
                redirectInput.setCustomValidity('');
            } else if (redirectInput.value && !redirectInput.value.includes(expectedCallback)) {
                redirectInput.setCustomValidity('OAuth redirect URI must use ' + expectedCallback + ' for this provider.');
            } else {
                redirectInput.setCustomValidity('');
            }

            // Re-apply auth-mode visibility (app-only hides redirect/scopes) now that the
            // provider — and the auth-mode selector's own visibility — has changed.
            toggleAuthModeFields();
        }

        function getDefaultOAuthRedirectUri(provider) {
            const callback = provider === 'google' ? 'google_oauth_callback.php' : 'oauth_callback.php';
            const appRoot = new URL('../../', window.location.href);
            return appRoot.origin + appRoot.pathname + callback;
        }

        function toggleImportedFolder() {
            const action = document.getElementById('mailboxImportedAction').value;
            document.getElementById('importedFolderGroup').style.display = action === 'move_to_folder' ? '' : 'none';
        }

        // Both folder fields verify through one function. Two copies is what let the
        // move-to folder and the read-from folder disagree in the first place (GH #77).
        function verifyFolder() {
            return runFolderVerify('mailboxImportedFolder', 'verifyFolderResult', 'verifyFolderBtn');
        }
        function verifyIntakeFolder() {
            return runFolderVerify('mailboxFolder', 'verifyIntakeFolderResult', 'verifyIntakeFolderBtn');
        }

        async function runFolderVerify(inputId, resultId, btnId) {
            const folderName = document.getElementById(inputId).value.trim();
            const mailboxId = document.getElementById('mailboxId').value;
            const resultEl = document.getElementById(resultId);
            const btn = document.getElementById(btnId);

            if (!folderName) {
                resultEl.style.display = '';
                resultEl.style.color = '#856404';
                resultEl.textContent = window.t('tickets.settings.verify_result.enter_folder');
                return;
            }
            if (!mailboxId) {
                resultEl.style.display = '';
                resultEl.style.color = '#856404';
                resultEl.textContent = window.t('tickets.settings.verify_result.save_first');
                return;
            }

            btn.disabled = true;
            btn.textContent = window.t('tickets.settings.buttons.verifying');
            resultEl.style.display = 'none';

            try {
                const res = await fetch(API_BASE + 'verify_mailbox_folder.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mailbox_id: parseInt(mailboxId), folder_name: folderName })
                });
                const data = await res.json();

                resultEl.style.display = '';
                if (data.success) {
                    // No escapeHtml: textContent below escapes already, so this
                    // double-encoded any folder with an & or a quote in its name.
                    let msg = window.t('tickets.settings.verify_result.found', { name: data.folder.displayName });
                    if (data.folder.totalItemCount !== null && data.folder.totalItemCount !== undefined) {
                        msg += ' ' + window.t('tickets.settings.verify_result.counts', {
                            total: data.folder.totalItemCount, unread: data.folder.unreadItemCount
                        });
                    }
                    // A Gmail label that exists but sits outside the Inbox verifies
                    // fine and collects nothing. Green would read as "working".
                    if (data.folder.note) {
                        msg += ' - ' + data.folder.note;
                        resultEl.style.color = '#856404';
                    } else {
                        resultEl.style.color = '#155724';
                    }
                    resultEl.textContent = msg;
                } else {
                    resultEl.style.color = '#721c24';
                    resultEl.textContent = data.error || window.t('tickets.settings.verify_result.not_found');
                }
            } catch (err) {
                resultEl.style.display = '';
                resultEl.style.color = '#721c24';
                resultEl.textContent = window.t('tickets.settings.verify_result.failed');
            } finally {
                btn.disabled = false;
                // Was a literal 'Verify', so a German button read "Prüfen" until
                // the first click and "Verify" ever after.
                btn.textContent = window.t('tickets.settings.buttons.verify');
            }
        }

        async function editMailbox(id) {
            const mailbox = mailboxes.find(m => m.id == id);
            if (mailbox) {
                openMailboxModal(mailbox);
            } else {
                showToast('Mailbox not found. ID: ' + id, 'error');
            }
        }

        async function deleteMailbox(id, name) {
            const ok = await showConfirm({
                title: 'Delete mailbox',
                message: `Are you sure you want to delete the mailbox "${name}"?`,
                okLabel: 'Delete',
                okClass: 'danger'
            });
            if (!ok) return;

            try {
                const response = await fetch(API_BASE + 'delete_mailbox.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();

                if (data.success) {
                    showToast('Mailbox deleted', 'success');
                    loadMailboxes();
                } else {
                    showToast('Error deleting mailbox: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to delete mailbox', 'error');
            }
        }

        function authenticateMailbox(id) {
            const mailbox = mailboxes.find(m => m.id == id);
            if (!mailbox) {
                showToast('Mailbox not found. ID: ' + id, 'error');
                return;
            }

            const provider = mailbox.provider || 'microsoft';

            if (provider === 'google') {
                // Google OAuth flow
                const state = 'google_mailbox_' + id + '_' + Math.random().toString(36).substring(2, 18);
                const params = new URLSearchParams({
                    client_id: mailbox.azure_client_id,
                    redirect_uri: mailbox.oauth_redirect_uri,
                    response_type: 'code',
                    scope: 'https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/gmail.send',
                    access_type: 'offline',
                    prompt: 'consent',
                    state: state
                });
                window.location.href = 'https://accounts.google.com/o/oauth2/v2/auth?' + params.toString();
            } else {
                // Microsoft OAuth flow
                const state = 'mailbox_' + id + '_' + Math.random().toString(36).substring(2, 18);
                const params = new URLSearchParams({
                    client_id: mailbox.azure_client_id,
                    response_type: 'code',
                    redirect_uri: mailbox.oauth_redirect_uri,
                    response_mode: 'query',
                    scope: mailbox.oauth_scopes,
                    state: state
                });
                window.location.href = 'https://login.microsoftonline.com/' + mailbox.azure_tenant_id + '/oauth2/v2.0/authorize?' + params.toString();
            }
        }

        async function logoutMailbox(id) {
            const ok = await showConfirm({
                title: 'Sign out mailbox',
                message: 'This will remove authentication for this mailbox. You will need to re-authenticate. Continue?',
                okLabel: 'Continue',
                okClass: 'primary'
            });
            if (!ok) return;

            try {
                const response = await fetch(API_BASE + 'mailbox_logout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mailbox_id: id })
                });
                const data = await response.json();

                if (data.success) {
                    showToast('Mailbox signed out', 'success');
                    loadMailboxes();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to logout mailbox', 'error');
            }
        }

        async function checkMailboxEmails(id) {
            const result = document.getElementById('mailboxesResult');
            const mailbox = mailboxes.find(m => m.id == id);

            result.className = 'exchange-result info';
            result.innerHTML = `<span class="spinner"></span> Checking emails for ${escapeHtml(mailbox?.name || 'mailbox')}...`;

            try {
                const response = await fetch(API_BASE + 'check_mailbox_email.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mailbox_id: id })
                });
                const data = await response.json();

                if (data.success) {
                    result.className = 'exchange-result success';
                    result.innerHTML = `
                        <strong>&#10003; Success!</strong>
                        <p>${data.message}</p>
                        ${data.details ? '<pre>' + JSON.stringify(data.details, null, 2) + '</pre>' : ''}
                    `;
                    loadMailboxes(); // Refresh to update last checked time
                } else {
                    result.className = 'exchange-result error';
                    result.innerHTML = `
                        <strong>&#10007; Error</strong>
                        <p>${data.error || data.message}</p>
                    `;
                }
            } catch (error) {
                result.className = 'exchange-result error';
                result.innerHTML = `
                    <strong>&#10007; Connection Error</strong>
                    <p>Failed to connect to the server: ${error.message}</p>
                `;
            }
        }

        async function checkAllMailboxes() {
            const result = document.getElementById('mailboxesResult');
            const authenticatedMailboxes = mailboxes.filter(m => m.is_authenticated && m.is_active);

            if (authenticatedMailboxes.length === 0) {
                result.className = 'exchange-result error';
                result.innerHTML = 'No authenticated and active mailboxes to check.';
                return;
            }

            result.className = 'exchange-result info';
            result.innerHTML = `<span class="spinner"></span> Checking ${authenticatedMailboxes.length} mailbox(es)...`;

            let successCount = 0;
            let errorCount = 0;
            let totalEmails = 0;
            const results = [];

            for (const mb of authenticatedMailboxes) {
                try {
                    const response = await fetch(API_BASE + 'check_mailbox_email.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ mailbox_id: mb.id })
                    });
                    const data = await response.json();

                    if (data.success) {
                        successCount++;
                        totalEmails += data.details?.emails_saved || 0;
                        results.push('&#10003; ' + escapeHtml(window.t('tickets.settings.check_results.mailbox_ok', {
                            name: mb.name, count: data.details?.emails_saved || 0
                        })));
                    } else {
                        errorCount++;
                        results.push('&#10007; ' + escapeHtml(window.t('tickets.settings.check_results.mailbox_failed', {
                            name: mb.name, error: data.error || window.t('tickets.settings.check_results.unknown_error')
                        })));
                    }
                } catch (error) {
                    errorCount++;
                    results.push('&#10007; ' + escapeHtml(window.t('tickets.settings.check_results.mailbox_failed', {
                        name: mb.name, error: window.t('tickets.settings.check_results.connection_error')
                    })));
                }
            }

            if (errorCount === 0) {
                result.className = 'exchange-result success';
            } else if (successCount === 0) {
                result.className = 'exchange-result error';
            } else {
                result.className = 'exchange-result info';
            }

            result.innerHTML = `
                <strong>${escapeHtml(window.t('tickets.settings.check_results.complete'))}</strong>
                <p>${escapeHtml(window.t('tickets.settings.check_results.summary', { count: successCount, emails: totalEmails }))}</p>
                <ul style="margin-top: 10px; padding-left: 20px;">
                    ${results.map(r => '<li>' + r + '</li>').join('')}
                </ul>
            `;

            loadMailboxes(); // Refresh to update last checked times
        }

        // Mailbox form submission
        document.getElementById('mailboxForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = {
                id: document.getElementById('mailboxId').value || null,
                provider: document.getElementById('mailboxProvider').value,
                name: document.getElementById('mailboxName').value,
                target_mailbox: document.getElementById('mailboxEmail').value,
                auth_mode: document.getElementById('mailboxAuthMode').value,
                azure_tenant_id: document.getElementById('mailboxTenantId').value,
                azure_client_id: document.getElementById('mailboxClientId').value,
                azure_client_secret: document.getElementById('mailboxClientSecret').value,
                oauth_redirect_uri: document.getElementById('mailboxRedirectUri').value,
                oauth_scopes: document.getElementById('mailboxScopes').value,
                imap_server: document.getElementById('mailboxImapServer').value,
                imap_port: parseInt(document.getElementById('mailboxImapPort').value),
                imap_encryption: document.getElementById('mailboxImapEncryption').value,
                imap_username: document.getElementById('mailboxImapUsername').value,
                imap_password: document.getElementById('mailboxImapPassword').value,
                smtp_server: document.getElementById('mailboxSmtpServer').value,
                smtp_port: parseInt(document.getElementById('mailboxSmtpPort').value) || 587,
                smtp_encryption: document.getElementById('mailboxSmtpEncryption').value,
                smtp_same_as_imap: document.getElementById('mailboxSmtpSameAsImap').checked,
                smtp_username: document.getElementById('mailboxSmtpUsername').value,
                smtp_password: document.getElementById('mailboxSmtpPassword').value,
                email_folder: document.getElementById('mailboxFolder').value,
                max_emails_per_check: parseInt(document.getElementById('mailboxMaxEmails').value),
                rejected_action: document.getElementById('mailboxRejectedAction').value,
                imported_action: document.getElementById('mailboxImportedAction').value,
                imported_folder: document.getElementById('mailboxImportedFolder').value || null,
                is_active: document.getElementById('mailboxActive').checked,
                // Multi-tenancy: "" (shared intake) when the picker is hidden/unset → NULL server-side.
                tenant_id: document.getElementById('mailboxCompany').value || null,
                default_origin_id: document.getElementById('mailboxOrigin').value || null
            };

            try {
                const response = await fetch(API_BASE + 'save_mailbox.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    // Save whitelist entries
                    const mailboxId = data.id || formData.id;
                    if (mailboxId) {
                        try {
                            await fetch(API_BASE + 'save_mailbox_whitelist.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ mailbox_id: mailboxId, entries: whitelistEntries })
                            });
                        } catch (wErr) {
                            console.error('Failed to save whitelist:', wErr);
                        }
                    }

                    closeMailboxModal();
                    showToast('Mailbox saved', 'success');
                    loadMailboxes();
                } else {
                    showToast('Error saving mailbox: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to save mailbox', 'error');
            }
        });

        // Whitelist Functions
        function addWhitelistEntry() {
            const type = document.getElementById('whitelistType').value;
            const value = document.getElementById('whitelistValue').value.trim().toLowerCase();

            if (!value) return;

            // Validate
            if (type === 'email' && !value.includes('@')) {
                showToast('Please enter a valid email address', 'warning');
                return;
            }
            if (type === 'domain' && value.includes('@')) {
                showToast('Enter a domain without @, e.g. company.com', 'warning');
                return;
            }

            // Check for duplicates
            if (whitelistEntries.some(e => e.entry_type === type && e.entry_value === value)) {
                showToast('Entry already exists', 'warning');
                return;
            }

            whitelistEntries.push({ entry_type: type, entry_value: value });
            renderWhitelistEntries();
            document.getElementById('whitelistValue').value = '';
        }

        function removeWhitelistEntry(index) {
            whitelistEntries.splice(index, 1);
            renderWhitelistEntries();
        }

        function renderWhitelistEntries() {
            const container = document.getElementById('whitelistEntries');
            if (whitelistEntries.length === 0) {
                container.innerHTML = '<span style="color: var(--text-faint, #999); font-size: 12px;">No whitelist entries — all senders allowed</span>';
                return;
            }

            container.innerHTML = whitelistEntries.map((e, i) => {
                const color = e.entry_type === 'domain' ? '#0078d4' : '#6c757d';
                return `<span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: ${color}15; border: 1px solid ${color}40; border-radius: 20px; font-size: 12px; color: ${color};">
                    <strong>${e.entry_type === 'domain' ? '@' : ''}${escapeHtml(e.entry_value)}</strong>
                    <button type="button" onclick="removeWhitelistEntry(${i})" style="background: none; border: none; cursor: pointer; color: ${color}; font-size: 14px; padding: 0 2px; line-height: 1;">&times;</button>
                </span>`;
            }).join('');
        }

        // Activity Log Functions
        let activityMailboxId = null;
        let activitySearchTimer = null;

        let mailboxLogTab = 'inbound';

        // #79: list what is wrong with one mailbox, in plain terms, each item saying
        // what the consequence is rather than just naming a field. Errors first —
        // "not collecting any mail at all" outranks "not stamping an origin".
        function showMailboxProblems(mailboxId) {
            const mb = mailboxes.find(m => m.id == mailboxId);
            const problems = (mb && mb.problems) ? mb.problems.slice() : [];
            document.getElementById('mailboxProblemsTitle').textContent =
                t('tickets.settings.modals.mailbox.problems_title') + (mb ? ' — ' + mb.name : '');

            problems.sort((a, b) => (a.severity === b.severity) ? 0 : (a.severity === 'error' ? -1 : 1));

            const live      = problems.filter(p => !p.dismissed);
            const acked     = problems.filter(p =>  p.dismissed);
            const body      = document.getElementById('mailboxProblemsBody');

            const card = (p) => {
                const isError = p.severity === 'error';
                const colour  = isError ? '#c62828' : '#ef6c00';
                const bg      = isError ? '#ffebee' : '#fff3e0';
                // Errors carry no Dismiss button: reading the wrong inbox is a fault,
                // not a preference, and silencing it is the one thing this modal
                // must never help anybody do.
                const btn = p.dismissible
                    ? `<button type="button" class="btn btn-secondary" style="margin-top:8px;padding:3px 10px;font-size:12px;"
                         onclick="setMailboxProblemDismissed(${mailboxId}, '${escapeHtml(p.key)}', true)">${escapeHtml(t('tickets.settings.modals.mailbox.problems_dismiss'))}</button>`
                    : '';
                return `<div style="border-left:3px solid ${colour}; background:${bg}; padding:10px 12px; margin-bottom:10px; border-radius:0 4px 4px 0;">
                    <div style="font-weight:600; color:${colour}; margin-bottom:3px;">${escapeHtml(p.title)}</div>
                    <div style="font-size:13px; color:var(--text-color,#333);">${escapeHtml(p.detail)}</div>
                    ${btn}
                </div>`;
            };

            let html = '';
            if (!live.length) {
                html += '<p style="color:#2e7d32;margin:0 0 10px;">✓ '
                     + escapeHtml(t('tickets.settings.modals.mailbox.problems_none')) + '</p>';
            } else {
                html += live.map(card).join('');
            }

            // Acknowledged items stay visible and reversible. Dismissing is meant to
            // say "I know", not to delete the fact.
            if (acked.length) {
                html += `<div style="margin-top:14px; padding-top:10px; border-top:1px solid var(--border-color,#e0e0e0);">
                    <div style="font-size:12px; font-weight:600; color:var(--text-muted,#666); margin-bottom:8px;">
                        ${escapeHtml(t('tickets.settings.modals.mailbox.problems_dismissed_heading'))}
                    </div>
                    ${acked.map(p => `<div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                        <span style="flex:1; font-size:13px; color:var(--text-muted,#666);">${escapeHtml(p.title)}</span>
                        <button type="button" class="btn btn-secondary" style="padding:3px 10px;font-size:12px;"
                            onclick="setMailboxProblemDismissed(${mailboxId}, '${escapeHtml(p.key)}', false)">${escapeHtml(t('tickets.settings.modals.mailbox.problems_restore'))}</button>
                    </div>`).join('')}
                </div>`;
            }

            body.innerHTML = html;
            document.getElementById('mailboxProblemsModal').classList.add('active');
        }

        // Acknowledge a warning, or put it back. Reloads the list so the mark on the
        // row updates, then re-opens the modal so the change is visible where it was made.
        async function setMailboxProblemDismissed(mailboxId, key, dismissed) {
            try {
                const res = await fetch(API_BASE + 'set_mailbox_health_dismissed.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mailbox_id: mailboxId, key: key, dismissed: dismissed })
                });
                const data = await res.json();
                if (!data.success) { showToast('Error: ' + (data.error || ''), 'error'); return; }
                await loadMailboxes();
                showMailboxProblems(mailboxId);
            } catch (e) {
                showToast('Failed to update', 'error');
            }
        }

        function closeMailboxProblems() {
            document.getElementById('mailboxProblemsModal').classList.remove('active');
        }

        function openActivityModal(mailboxId) {
            activityMailboxId = mailboxId;
            const mb = mailboxes.find(m => m.id == mailboxId);
            document.getElementById('activityModalTitle').textContent = 'Activity — ' + (mb ? mb.name : 'Mailbox');
            document.getElementById('activitySearch').value = '';
            closeProcessingLog();
            switchMailboxLogTab('inbound');
            // Fetch the outbound failure count straight away: the whole point is that a
            // failed send is noticed without anybody going looking for it, so the badge
            // has to be there before the tab is ever opened.
            refreshOutboundBadge();
            document.getElementById('activityModal').classList.add('active');
        }

        function switchMailboxLogTab(tab) {
            mailboxLogTab = tab;
            const inbound = tab === 'inbound';
            document.getElementById('mbxTabInbound').classList.toggle('active', inbound);
            document.getElementById('mbxTabOutbound').classList.toggle('active', !inbound);
            document.getElementById('inboundPane').style.display  = inbound ? '' : 'none';
            document.getElementById('outboundPane').style.display = inbound ? 'none' : '';
            document.getElementById('outboundStatus').style.display = inbound ? 'none' : '';
            if (inbound) closeProcessingLog();
            document.getElementById('activitySearch').value = '';
            loadMailboxLog(1);
        }

        function loadMailboxLog(page) {
            const search = document.getElementById('activitySearch').value;
            if (mailboxLogTab === 'inbound') loadActivity(activityMailboxId, search, page);
            else loadOutbound(activityMailboxId, search, page);
        }

        async function refreshOutboundBadge() {
            const badge = document.getElementById('mbxOutboundBadge');
            badge.style.display = 'none';
            try {
                const res = await fetch(API_BASE + 'get_mailbox_outbound.php?mailbox_id=' + activityMailboxId + '&page=1');
                const data = await res.json();
                if (data.success && data.failed > 0) {
                    badge.textContent = data.failed;
                    badge.style.display = '';
                }
            } catch (e) { /* a missing badge must never break the modal */ }
        }

        async function loadOutbound(mailboxId, search, page) {
            const tbody = document.getElementById('outboundList');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Loading...</td></tr>';
            try {
                let url = API_BASE + 'get_mailbox_outbound.php?mailbox_id=' + mailboxId + '&page=' + page;
                if (search) url += '&search=' + encodeURIComponent(search);
                const status = document.getElementById('outboundStatus').value;
                if (status) url += '&status=' + encodeURIComponent(status);

                const res = await fetch(url);
                const data = await res.json();
                if (!data.success) {
                    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#d13438;">${escapeHtml(data.error || 'Could not load the send log')}</td></tr>`;
                    return;
                }
                if (!data.entries.length) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--text-muted,#666);">Nothing sent from this mailbox yet.</td></tr>';
                } else {
                    tbody.innerHTML = data.entries.map(e => {
                        const failed  = e.status === 'failed';
                        // A deliberate non-send (#80): no template covered this sender.
                        // It MUST NOT render as "Sent" — this row exists precisely
                        // because an email did not go, and labelling it Sent would put
                        // the wrong answer in the one place somebody comes looking.
                        const skipped = e.status === 'skipped';
                        // The error, or the reason nothing was sent, is why anybody
                        // opens this tab — shown in the row rather than behind a click.
                        const note = (failed || skipped) && e.error_message
                            ? `<div style="margin-top:4px; font-size:11px; color:${failed ? '#a4262c' : 'var(--text-muted,#666)'}; white-space:normal;">${escapeHtml(e.error_message)}</div>` : '';
                        const ticket = e.ticket_id
                            ? ` <a href="../?ticket_id=${e.ticket_id}" style="font-size:11px;">#${e.ticket_id}</a>` : '';
                        const cls   = failed ? 'failed' : (skipped ? 'skipped' : 'sent');
                        const label = failed ? 'Failed' : (skipped ? 'Not sent' : 'Sent');
                        return `<tr>
                            <td style="white-space:nowrap;">${fmtDateTime(e.created_datetime)}</td>
                            <td>${escapeHtml(e.to_address || '')}${ticket}</td>
                            <td>${escapeHtml(e.subject || '')}${note}</td>
                            <td style="white-space:nowrap;">${escapeHtml(e.route_label || e.route)}</td>
                            <td><span class="mbx-result ${cls}">${label}</span></td>
                        </tr>`;
                    }).join('');
                }
                renderLogPagination(data, page, 'outbound');
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#d13438;">${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderLogPagination(data, page, which) {
            const pag = document.getElementById('activityPagination');
            const totalPages = Math.max(1, Math.ceil(data.total / data.per_page));
            const from = data.total === 0 ? 0 : ((page - 1) * data.per_page) + 1;
            const to = Math.min(page * data.per_page, data.total);
            pag.innerHTML = `<span>${from}-${to} of ${data.total}</span>
                <span>
                    <button type="button" class="btn btn-secondary" style="padding:3px 10px; font-size:12px;" ${page <= 1 ? 'disabled' : ''} onclick="loadMailboxLog(${page - 1})">Previous</button>
                    <button type="button" class="btn btn-secondary" style="padding:3px 10px; font-size:12px;" ${page >= totalPages ? 'disabled' : ''} onclick="loadMailboxLog(${page + 1})">Next</button>
                </span>`;
        }

        function closeActivityModal() {
            document.getElementById('activityModal').classList.remove('active');
        }

        function showProcessingLog(logJson) {
            const panel = document.getElementById('processingLogPanel');
            const content = document.getElementById('processingLogContent');
            if (!logJson) {
                content.textContent = 'No processing log available for this entry.';
            } else {
                try {
                    const parsed = typeof logJson === 'string' ? JSON.parse(logJson) : logJson;
                    content.textContent = JSON.stringify(parsed, null, 2);
                } catch (e) {
                    content.textContent = logJson;
                }
            }
            panel.style.display = '';
        }

        function closeProcessingLog() {
            document.getElementById('processingLogPanel').style.display = 'none';
        }

        function debounceActivitySearch() {
            clearTimeout(activitySearchTimer);
            activitySearchTimer = setTimeout(() => {
                const search = document.getElementById('activitySearch').value;
                loadActivity(activityMailboxId, search, 1);
            }, 300);
        }

        async function loadActivity(mailboxId, search, page) {
            const tbody = document.getElementById('activityList');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Loading...</td></tr>';

            try {
                let url = API_BASE + 'get_mailbox_activity.php?mailbox_id=' + mailboxId + '&page=' + page;
                if (search) url += '&search=' + encodeURIComponent(search);

                const res = await fetch(url);
                const data = await res.json();

                if (!data.success) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: red;">' + escapeHtml(data.error) + '</td></tr>';
                    return;
                }

                if (data.entries.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--text-faint, #999);">No activity found</td></tr>';
                    document.getElementById('activityPagination').innerHTML = '';
                    return;
                }

                // Store logs for click handler
                window._activityLogs = data.entries.map(e => e.processing_log || null);

                tbody.innerHTML = data.entries.map((e, idx) => {
                    const dt = fmtDateTime(e.created_datetime);
                    const badge = e.action === 'imported'
                        ? '<span style="display: inline-block; padding: 2px 8px; background: #d4edda; color: #155724; border-radius: 10px; font-size: 11px;">Imported</span>'
                        : '<span style="display: inline-block; padding: 2px 8px; background: #f8d7da; color: #721c24; border-radius: 10px; font-size: 11px;">Rejected</span>';
                    const fromAddr = (e.from_address || '').trim();
                    const fromNm   = (e.from_name || '').trim();
                    // A portal requester with no mailbox has a name and no address.
                    // Plain + concatenation would render the literal text "null" here,
                    // since this is not passed through escapeHtml first.
                    const from = escapeHtml(
                        fromNm && fromAddr ? fromNm + ' <' + fromAddr + '>' : (fromNm || fromAddr)
                    );
                    return `<tr style="cursor: pointer;" onclick="showProcessingLog(window._activityLogs[${idx}])">
                        <td style="white-space: nowrap;">${dt}</td>
                        <td>${from}</td>
                        <td>${escapeHtml(e.subject || '')}</td>
                        <td>${badge}</td>
                        <td>${escapeHtml(e.reason || '')}</td>
                    </tr>`;
                }).join('');

                // Pagination
                const totalPages = Math.ceil(data.total / data.per_page);
                const currentSearch = document.getElementById('activitySearch').value;
                let paginationHtml = `<span>Showing ${data.entries.length} of ${data.total} entries</span>`;

                if (totalPages > 1) {
                    paginationHtml += '<div>';
                    if (page > 1) {
                        paginationHtml += `<button class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;" onclick="loadActivity(${mailboxId}, '${currentSearch.replace(/'/g, "\\'")}', ${page - 1})">${t('common.calendar.previous')}</button>`;
                    }
                    paginationHtml += `<span style="margin: 0 8px;">Page ${page} of ${totalPages}</span>`;
                    if (page < totalPages) {
                        paginationHtml += `<button class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-left: 4px;" onclick="loadActivity(${mailboxId}, '${currentSearch.replace(/'/g, "\\'")}', ${page + 1})">${t('common.calendar.next')}</button>`;
                    }
                    paginationHtml += '</div>';
                }

                document.getElementById('activityPagination').innerHTML = paginationHtml;

            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: red;">Failed to load activity</td></tr>';
            }
        }


        // Load remaining settings on page load (analyst management moved to
        // System → Analysts).
        document.addEventListener('DOMContentLoaded', function() {
            loadGeneralSettings();
            loadPrivacySettings();
            loadMergeSettings();
            loadReplyCleanupSettings();
            loadCsatSettings();
        });

        // ============================
        // Reply Cleanup AI settings
        // ============================
        const API_TICKETS = '../../api/tickets/';

        async function loadReplyCleanupSettings() {
            try {
                const res = await fetch(API_TICKETS + 'get_reply_cleanup_settings.php');
                const data = await res.json();
                if (!data.success) return;

                // Provider / model / key are handled by the shared AI panel.
                document.getElementById('rcTone').value  = data.tone  || 'Friendly';
                document.getElementById('rcCustomInstructions').value = data.custom_instructions || '';
                document.getElementById('rcPromptPreview').textContent = data.prompt_preview || '';
            } catch (err) {
                console.error('Failed to load reply cleanup settings:', err);
            }
        }

        // ============================
        // CSAT settings
        // ============================
        async function loadCsatSettings() {
            try {
                const res = await fetch(API_TICKETS + 'get_csat_settings.php');
                const data = await res.json();
                if (!data.success) return;

                const mode = document.querySelector(`input[name="csatMode"][value="${data.mode || 'off'}"]`);
                if (mode) mode.checked = true;

                document.getElementById('csatDelay').value = data.delay_minutes ?? 0;
                document.getElementById('csatOnePerTicket').checked = data.one_per_ticket !== '0';

                const scale = document.querySelector(`input[name="csatScale"][value="${data.scale || 'stars'}"]`);
                if (scale) scale.checked = true;
            } catch (err) {
                console.error('Failed to load CSAT settings:', err);
            }
        }

        document.getElementById('csatSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const payload = {
                mode:           document.querySelector('input[name="csatMode"]:checked')?.value || 'off',
                delay_minutes:  parseInt(document.getElementById('csatDelay').value || '0', 10),
                one_per_ticket: document.getElementById('csatOnePerTicket').checked ? '1' : '0',
                scale:          document.querySelector('input[name="csatScale"]:checked')?.value || 'stars',
            };
            try {
                const res = await fetch(API_TICKETS + 'save_csat_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('CSAT settings saved', 'success');
                } else {
                    showToast('Error: ' + (data.error || 'Save failed'), 'error');
                }
            } catch (err) {
                showToast('Failed to save settings', 'error');
            }
        });

        // Re-fetch the prompt preview when the tone selection changes so the
        // read-only panel always reflects the currently-chosen tone clause.
        document.addEventListener('DOMContentLoaded', function() {
            const toneSelect = document.getElementById('rcTone');
            if (toneSelect) {
                toneSelect.addEventListener('change', loadReplyCleanupSettings);
            }
        });

        document.getElementById('replyCleanupForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const payload = {
                tone:                document.getElementById('rcTone').value,
                custom_instructions: document.getElementById('rcCustomInstructions').value,
            };
            try {
                const res = await fetch(API_TICKETS + 'save_reply_cleanup_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Reply Cleanup settings saved', 'success');
                    loadReplyCleanupSettings();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (err) {
                showToast('Failed to save settings', 'error');
            }
        });

        // Connection testing is handled by the shared AI panel's Test button.

        // General Settings Functions
        async function loadGeneralSettings() {
            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();

                if (data.success) {
                    document.getElementById('systemName').value = data.settings.system_name || '';
                    // Absent (never saved) means ON — the requester email template has
                    // always promised a reply reopens the ticket, so the stored default
                    // matches what customers were already told. Mirrors
                    // customerReplyReopensTickets() in includes/ticket_reply.php.
                    const reopen = data.settings.reopen_on_customer_reply;
                    document.getElementById('reopenOnCustomerReply').checked =
                        (reopen === null || reopen === undefined || reopen === '') ? true : (reopen === '1');
                    // Snooze wake hour (#933) — 09:00 when never saved, matching
                    // snoozeWakeHour() in includes/ticket_snooze.php.
                    // Long-message display (#104). Defaults mirror
                    // ticketDisplaySettings() so an install that has never saved
                    // these sees the same thing the reading pane is doing.
                    const cb = (id, key, dflt) => document.getElementById(id).checked =
                        data.settings[key] === undefined ? dflt : data.settings[key] === '1';
                    cb('collapseEnabled',      'ticket_collapse_enabled',       true);
                    // ⚠️ default FALSE, unlike everything around it. An unloaded
                    // checkbox looks exactly like OFF, and for these two that is
                    // the safe direction to be wrong in.
                    cb('aiSummaryEnabled',     'ticket_ai_summary_enabled',     false);
                    cb('aiSummaryIncludeNotes','ticket_ai_summary_include_notes', true);
                    cb('aiReadEnabled',        'ticket_ai_read_enabled',        false);
                    cb('collapseExpandNewest', 'ticket_collapse_expand_newest', true);
                    cb('collapseQuoted',       'ticket_collapse_quoted',        true);
                    cb('collapseRemember',     'ticket_collapse_remember',      true);
                    cb('groupOlder',          'ticket_group_older',            true);
                    cb('flagDuplicates',      'ticket_flag_duplicates',        true);
                    document.getElementById('groupShow').value = parseInt(data.settings.ticket_group_show, 10) || 6;
                    // ⚠️ '|| N' would turn a saved 0 into N, and 0 is the setting that
                    // means "never refresh by itself" — the one an administrator is most
                    // likely to have chosen deliberately.
                    const autoAfter = parseInt(data.settings.ticket_ai_summary_auto_after, 10);
                    document.getElementById('aiSummaryAutoAfter').value = Number.isInteger(autoAfter) ? String(autoAfter) : '0';
                    document.getElementById('aiSummaryMaxMessages').value = parseInt(data.settings.ticket_ai_summary_max_messages, 10) || 60;
                    document.getElementById('collapseLines').value =
                        parseInt(data.settings.ticket_collapse_lines, 10) || 12;

                    const wakeHour = parseInt(data.settings.snooze_wake_hour, 10);
                    document.getElementById('snoozeWakeHour').value =
                        (Number.isInteger(wakeHour) && wakeHour >= 0 && wakeHour <= 23) ? String(wakeHour) : '9';
                } else {
                    console.error('Error loading settings:', data.error);
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }

        // Merge behaviour — install-wide policy for what a merge does.
        // Defaults here MUST match mergeSettings() in includes/ticket_merge.php:
        // survivor + thread, the pair that keeps the requester's reference alive and
        // the conversation searchable. A screen that defaulted differently from the
        // engine would show the wrong answer on a fresh install.
        async function loadMergeSettings() {
            const form = document.getElementById('mergeBehaviourForm');
            if (!form) return;                       // tab not visible to this analyst
            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();
                if (!data.success) return;

                const ref  = data.settings.merge_reference_mode || 'survivor';
                const orig = data.settings.merge_originals_mode || 'thread';
                const ai   = data.settings.merge_ai_summary;

                const refEl = document.querySelector('input[name="mergeReferenceMode"][value="' + ref + '"]')
                           || document.querySelector('input[name="mergeReferenceMode"][value="survivor"]');
                if (refEl) refEl.checked = true;

                const origEl = document.querySelector('input[name="mergeOriginalsMode"][value="' + orig + '"]')
                            || document.querySelector('input[name="mergeOriginalsMode"][value="thread"]');
                if (origEl) origEl.checked = true;

                // Absent = on: the summary is the point of the feature, and it is
                // harmless when no AI provider is configured (it simply doesn't run).
                document.getElementById('mergeAiSummary').checked = (ai === undefined || ai === null || ai === '' || ai === '1');
            } catch (e) {
                console.error('Error loading merge settings:', e);
            }
        }

        // ── Attachment indexing (discussion #53) ────────────────────────────
        // Both switches default ON when the setting has never been written, so a
        // fresh install reads attachments without anyone turning anything on.
        async function loadIndexingSettings() {
            const cronEl = document.getElementById('extractCron');
            if (!cronEl) return;
            try {
                // get_system_settings.php returns the whole set; there is no key
                // filter, so don't pass one and imply there is.
                const d = await (await fetch(API_SETTINGS + 'get_system_settings.php')).json();
                const s = (d && d.settings) || {};
                cronEl.checked = s.attachment_extract_cron !== '0';
                document.getElementById('extractOpportunistic').checked = s.attachment_extract_opportunistic !== '0';
            } catch (e) {
                cronEl.checked = true;
                document.getElementById('extractOpportunistic').checked = true;
            }
        }
        loadIndexingSettings();

        const indexingForm = document.getElementById('indexingForm');
        if (indexingForm) {
            indexingForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                try {
                    const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ settings: {
                            attachment_extract_cron:          document.getElementById('extractCron').checked ? '1' : '0',
                            attachment_extract_opportunistic: document.getElementById('extractOpportunistic').checked ? '1' : '0'
                        } })
                    });
                    const data = await response.json();
                    showToast(data.success ? t('tickets.settings.indexing.saved') : ('Error: ' + data.error), data.success ? 'success' : 'error');
                } catch (err) {
                    showToast('Failed to save settings', 'error');
                }
            });
        }

        const mergeForm = document.getElementById('mergeBehaviourForm');
        if (mergeForm) {
            mergeForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                const ref  = document.querySelector('input[name="mergeReferenceMode"]:checked');
                const orig = document.querySelector('input[name="mergeOriginalsMode"]:checked');
                try {
                    const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ settings: {
                            merge_reference_mode: ref  ? ref.value  : 'survivor',
                            merge_originals_mode: orig ? orig.value : 'thread',
                            merge_ai_summary:     document.getElementById('mergeAiSummary').checked ? '1' : '0'
                        } })
                    });
                    const data = await response.json();
                    showToast(data.success ? t('tickets.settings.merge.saved') : ('Error: ' + data.error), data.success ? 'success' : 'error');
                } catch (err) {
                    showToast('Failed to save settings', 'error');
                }
            });
        }

        // Privacy settings (what a requester sees of their own ticket in the portal)
        async function loadPrivacySettings() {
            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();
                if (!data.success) return;
                // Absent = hide. Mirrors portalThirdPartyPolicy() in
                // includes/portal_visibility.php — a fresh install is protected
                // before anyone finds this screen.
                const v = data.settings.portal_third_party_visibility || 'hide';
                const el = document.querySelector('input[name="thirdPartyVisibility"][value="' + v + '"]')
                        || document.querySelector('input[name="thirdPartyVisibility"][value="hide"]');
                if (el) el.checked = true;
            } catch (e) {
                console.error('Error loading privacy settings:', e);
            }
        }

        const privacyForm = document.getElementById('privacySettingsForm');
        if (privacyForm) {
            privacyForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                const chosen = document.querySelector('input[name="thirdPartyVisibility"]:checked');
                try {
                    const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ settings: { portal_third_party_visibility: chosen ? chosen.value : 'hide' } })
                    });
                    const data = await response.json();
                    showToast(data.success ? 'Settings saved' : ('Error: ' + data.error), data.success ? 'success' : 'error');
                } catch (err) {
                    showToast('Failed to save settings', 'error');
                }
            });
        }

        // General settings form submission
        // ════════════════════════════════════════════════════════════════
        //  Ticket numbering (GH #71)
        //
        //  ⚠️ Every DOM touch is guarded: this <script> runs even when the tab
        //  is not rendered, because the TAB is capability-gated and the script
        //  is not. One addEventListener on a null element throws and silently
        //  kills every function defined after it.
        // ════════════════════════════════════════════════════════════════

        function numT(k, v) { return window.t('tickets.settings.numbering.' + k, v); }
        function numEl(id)  { return document.getElementById(id); }
        function numPresent() { return !!numEl('numbering-tab'); }

        function numStyle() {
            const r = document.querySelector('input[name="numStyle"]:checked');
            return r ? r.value : 'random';
        }

        /** Hide the format controls when the style is random — they do nothing there. */
        function numSync() {
            const box = numEl('numSequentialOnly');
            if (box) box.style.display = numStyle() === 'sequential' ? '' : 'none';
            numPreview();
        }

        let numPreviewTimer = null;
        function numPreview() {
            if (!numPresent()) return;
            // Debounced: this fires on every keystroke in the format box.
            clearTimeout(numPreviewTimer);
            numPreviewTimer = setTimeout(numPreviewNow, 220);
        }

        async function numPreviewNow() {
            const out = numEl('numPreviewOut');
            const err = numEl('numFormatError');
            if (!out) return;
            try {
                const res = await fetch(API_TICKETS + 'numbering_preview.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        style:  numStyle(),
                        format: numEl("numFormat").value,
                        start:  parseInt(numEl("numStart").value, 10) || 1,
                        // The scope changes what counts as a valid format, so the
                        // preview has to know about it or it would pass a format
                        // that Save then rejects.
                        scope:  numEl("numScope").value
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                if (data.problems && data.problems.length) {
                    err.textContent = data.problems.join(' ');
                    err.style.display = '';
                    out.textContent = '—';
                } else {
                    err.style.display = 'none';
                    out.textContent = (data.examples || []).join('   ');
                }
            } catch (e) {
                out.textContent = '—';
            }
        }

        async function numLoad() {
            if (!numPresent()) return;
            try {
                const res  = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await res.json();
                const s    = (data && data.settings) ? data.settings : {};

                const style = s.ticket_number_style || 'random';
                const radio = document.querySelector(`input[name="numStyle"][value="${style}"]`);
                if (radio) radio.checked = true;
                numEl('numFormat').value = s.ticket_number_format || 'TICKET-{######}';
                numEl('numStart').value  = s.ticket_number_start  || '1';
                numEl('numScope').value  = s.ticket_number_scope  || 'global';
                numEl('numReset').value  = s.ticket_number_reset  || 'never';
                numSync();
            } catch (e) {
                // ⚠️ Leave the controls as they are rather than showing defaults
                // as if they were the saved values — an unloaded setting that
                // looks like a real one gets saved back as fact.
            }
        }

        if (numPresent()) {
            numEl('numberingForm').addEventListener('submit', async function (e) {
                e.preventDefault();
                const settings = {
                    ticket_number_style:  numStyle(),
                    ticket_number_format: numEl('numFormat').value.trim(),
                    ticket_number_start:  String(parseInt(numEl('numStart').value, 10) || 1),
                    ticket_number_scope:  numEl('numScope').value,
                    ticket_number_reset:  numEl('numReset').value
                };
                // ⚠️ Refuse to save a format the preview has already said is
                // wrong. Live ticket creation would survive it — it proves each
                // number unique against the table — but the per-type counting
                // that was just asked for would silently do nothing.
                const check = await fetch(API_TICKETS + 'numbering_preview.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        style: settings.ticket_number_style,
                        format: settings.ticket_number_format,
                        start: parseInt(settings.ticket_number_start, 10) || 1,
                        scope: settings.ticket_number_scope
                    })
                }).then(r => r.json()).catch(() => null);
                if (check && check.problems && check.problems.length) {
                    showToast(check.problems.join(' '), 'error');
                    return;
                }
                try {
                    const res = await fetch(API_SETTINGS + 'save_system_settings.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ settings })
                    });
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error);
                    showToast(numT('saved'), 'success');
                    // The renumber tool reads the SAVED settings, so a preview
                    // taken before this save is stale.
                    numSetRenumbered(false);
                } catch (err) {
                    showToast(err.message || 'Error', 'error');
                }
            });
            document.addEventListener('DOMContentLoaded', numLoad);
        }

        /** Renumbering stays disabled until a preview has been looked at. */
        function numSetRenumbered(ready) {
            const btn  = numEl('numRenumberGo');
            const hint = numEl('numRenumberHint');
            if (!btn) return;
            btn.disabled = !ready;
            if (hint) hint.style.display = ready ? 'none' : '';
        }

        async function numRenumber(mode) {
            if (!numPresent()) return;
            if (mode === 'live') {
                const ok = await showConfirm({
                    title:   numT('renumber_confirm_title'),
                    message: numT('renumber_confirm'),
                    okLabel: numT('renumber_go'),
                    okClass: 'danger'
                });
                if (!ok) return;
            }
            const out = numEl('numRenumberOut');
            out.innerHTML = '<small style="color: var(--text-muted, #666);">…</small>';
            try {
                const res = await fetch(API_TICKETS + 'numbering_renumber.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mode })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                const row = p => `<div style="font-family: ui-monospace, Consolas, monospace; font-size:12px;">
                        ${escapeHtml(p.from)} &rarr; <strong>${escapeHtml(p.to)}</strong></div>`;
                const gap = data.changing > 10
                    ? `<div style="color: var(--text-dim, #888); font-size:12px; margin:6px 0;">…</div>` : '';

                out.innerHTML = `
                    <div class="info-box" style="padding:12px 14px;border-radius:6px;background: var(--surface-2, #fafafa);border:1px solid var(--border-soft, #eee);">
                        <strong>${mode === 'preview' ? numT('renumber_preview_heading') : numT('renumber_done_heading')}</strong>
                        <div style="margin:8px 0;">${numT('renumber_summary', {
                            changing: data.changing, total: data.total, skipped: data.skipped })}</div>
                        ${(data.first || []).map(row).join('')}
                        ${gap}
                        ${data.changing > 5 ? (data.last || []).map(row).join('') : ''}
                        ${data.next_after ? `<div style="margin-top:10px;color: var(--text-muted, #666);font-size:12px;">${numT("renumber_next_after", { number: escapeHtml(data.next_after) })}</div>` : ""}
                    </div>`;

                if (mode === 'preview') {
                    numSetRenumbered(data.changing > 0);
                } else {
                    numSetRenumbered(false);
                    showToast(numT('renumber_done_heading'), 'success');
                }
            } catch (e) {
                out.innerHTML = `<div style="color: var(--danger-text, #c0392b);">${escapeHtml(e.message || 'Error')}</div>`;
                numSetRenumbered(false);
            }
        }

        document.getElementById('generalSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const settings = {
                system_name: document.getElementById('systemName').value,
                reopen_on_customer_reply: document.getElementById('reopenOnCustomerReply').checked ? '1' : '0',
                snooze_wake_hour: document.getElementById('snoozeWakeHour').value,
                ticket_collapse_enabled:       document.getElementById('collapseEnabled').checked ? '1' : '0',
                ticket_ai_summary_enabled:       document.getElementById('aiSummaryEnabled').checked ? '1' : '0',
                ticket_ai_summary_auto_after:    document.getElementById('aiSummaryAutoAfter').value,
                ticket_ai_summary_max_messages:  document.getElementById('aiSummaryMaxMessages').value,
                ticket_ai_summary_include_notes: document.getElementById('aiSummaryIncludeNotes').checked ? '1' : '0',
                ticket_ai_read_enabled:          document.getElementById('aiReadEnabled').checked ? '1' : '0',
                ticket_collapse_lines:         String(document.getElementById('collapseLines').value || 12),
                ticket_collapse_expand_newest: document.getElementById('collapseExpandNewest').checked ? '1' : '0',
                ticket_collapse_quoted:        document.getElementById('collapseQuoted').checked ? '1' : '0',
                ticket_collapse_remember:      document.getElementById('collapseRemember').checked ? '1' : '0',
                ticket_group_older:            document.getElementById('groupOlder').checked ? '1' : '0',
                ticket_group_show:             String(document.getElementById('groupShow').value || 6),
                ticket_flag_duplicates:        document.getElementById('flagDuplicates').checked ? '1' : '0'
            };

            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: settings })
                });
                const data = await response.json();

                if (data.success) {
                    showToast('Settings saved', 'success');
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save settings', 'error');
            }
        });

        // ============================
        // Email Templates
        // ============================

        const EVENT_LABELS = {
            'new_ticket_email': 'New ticket from email',
            'ticket_assigned': 'Ticket assigned',
            'ticket_closed': 'Ticket closed',
            'note_shared': 'Note shared with requester',
            'csat_request': 'CSAT survey'
        };

        let emailTemplates = [];

        async function loadEmailTemplates() {
            initTemplateBodyEditor();
            try {
                const response = await fetch(API_BASE + 'get_email_templates.php');
                const data = await response.json();
                if (data.success) {
                    emailTemplates = data.templates;
                    renderEmailTemplates(data.templates);
                }
            } catch (error) {
                console.error('Error loading templates:', error);
            }
            await loadPublicBaseUrl();
            refreshBaseUrlWarning();
            refreshScopeWarning();
        }

        // ==================== Public web address (#80) ====================
        // What [ticket_url] resolves to. Kept here because this is the tab where
        // [ticket_url] gets typed.

        // ⚠️ `loaded` is not decoration. If the fetch fails we know NOTHING about
        // whether an address is configured, and "not configured" is exactly what an
        // empty field and a missing flag look like. Showing the warning on a failed
        // load would tell an administrator who has set this up correctly that they
        // have not — so the warning stays hidden until we have actually been told.
        let baseUrlState = { loaded: false, configured: false, dismissed: false, scopeDismissed: false, example: '' };

        async function loadPublicBaseUrl() {
            const input = document.getElementById('tplBaseUrl');
            if (!input) return;                        // tab not visible to this analyst
            try {
                const resp = await fetch(API_BASE + 'get_public_base_url.php');
                const data = await resp.json();
                if (!data.success) throw new Error(data.error || 'load failed');
                input.value = data.base_url || '';
                baseUrlState = {
                    loaded: true,
                    configured: !!data.is_configured,
                    dismissed: !!data.warning_dismissed,
                    scopeDismissed: !!data.scope_warning_dismissed,
                    example: data.effective_url || ''
                };
                const ex = document.getElementById('tplBaseUrlExample');
                if (ex && baseUrlState.example) {
                    ex.textContent = (data.inherited ? t('tickets.settings.base_url.inherited') + ' ' : '')
                                   + t('tickets.settings.base_url.example') + ' ' + baseUrlState.example;
                }
                // Preview the link on THIS install's address. The other sample values
                // are invented and should be — nobody checks a preview to find out
                // what the requester is called — but a made-up host in the one field
                // whose whole purpose is "will this link work?" answers the question
                // wrongly every time. Left as the example.com placeholder only when
                // the address could not be read, where obviously-fake is the honest
                // thing to show.
                if (data.effective_root) {
                    TPL_PREVIEW_SAMPLES.ticket_url = data.effective_root + '/self-service/tickets.php?id=409';
                }
            } catch (e) {
                console.error('Error loading public base URL:', e);
                baseUrlState = { loaded: false, configured: false, dismissed: false, scopeDismissed: false, example: '' };
            }
        }

        // Shown only when the two facts are both true: something actually uses
        // [ticket_url], and nothing is configured for it to resolve against. A
        // warning about a merge code nobody has used would be noise on every
        // install that never wanted the feature.
        function refreshBaseUrlWarning() {
            const box = document.getElementById('tplBaseUrlWarning');
            if (!box) return;

            const users = (emailTemplates || []).filter(tpl =>
                ((tpl.body_template || '') + (tpl.subject_template || '')).includes('[ticket_url]'));

            const show = baseUrlState.loaded && !baseUrlState.configured
                      && !baseUrlState.dismissed && users.length > 0;

            box.style.display = show ? '' : 'none';
            if (show) {
                document.getElementById('tplBaseUrlWarningBody').textContent =
                    t('tickets.settings.base_url.warn_body').replace('{count}', users.length);
            }
        }

        async function savePublicBaseUrl() {
            const input = document.getElementById('tplBaseUrl');
            if (!input) return;
            try {
                const resp = await fetch(API_BASE + 'save_public_base_url.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ base_url: input.value })
                });
                const data = await resp.json();
                if (!data.success) { showToast(data.error, 'error'); return; }
                showToast(t('tickets.settings.base_url.saved'), 'success');
                // Re-read rather than assume: the server normalises what was typed,
                // and the example line has to show what will really be sent.
                await loadPublicBaseUrl();
                refreshBaseUrlWarning();
            } catch (e) {
                showToast(t('tickets.settings.base_url.save_failed'), 'error');
            }
        }

        async function dismissBaseUrlWarning() {
            try {
                await fetch(API_BASE + 'set_base_url_warning_dismissed.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ dismissed: true })
                });
            } catch (e) { /* dismissing is a convenience; a failure just leaves it showing */ }
            baseUrlState.dismissed = true;
            refreshBaseUrlWarning();
        }
        function renderEmailTemplates(templates) {
            const tbody = document.getElementById('email-templates-list');
            if (templates.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--text-faint, #999);">No email templates configured</td></tr>';
                return;
            }

            tbody.innerHTML = templates.map(tpl => `
                <tr>
                    <td>${escapeHtml(tpl.name)}</td>
                    <td>${EVENT_LABELS[tpl.event_trigger] || tpl.event_trigger}</td>
                    <td>${templateScopeBadge(tpl)}</td>
                    <td>${escapeHtml(tpl.subject_template)}</td>
                    <td>${tpl.display_order}</td>
                    <td><span class="status-badge status-${tpl.is_active == 1 ? 'active' : 'inactive'}">${tpl.is_active == 1 ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editTemplate(${tpl.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteTemplate(${tpl.id}, '${escapeHtml(tpl.name)}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }


        // ==================== Sender rules (#80) ====================
        // Which senders a template applies to. The editor holds them in memory while
        // the modal is open and posts the whole list on save.

        let templateRules = [];        // [{match_type, match_value}]

        const TPL_EVENT_LABELS = {
            new_ticket_email: t('tickets.settings.modals.template.event_new_ticket'),
            ticket_assigned:  t('tickets.settings.modals.template.event_assigned'),
            ticket_closed:    t('tickets.settings.modals.template.event_closed'),
            note_shared:      t('tickets.settings.modals.template.event_note_shared'),
            csat_request:     t('tickets.settings.modals.template.event_csat_request')
        };

        function switchTemplateScope() {
            const picked = document.querySelector('input[name="tplScope"]:checked');
            const restricted = picked && picked.value === 'restricted';
            document.getElementById('tplRulesBox').style.display = restricted ? '' : 'none';
        }

        function renderTemplateRules() {
            const box = document.getElementById('tplRulesList');
            if (!box) return;
            if (!templateRules.length) {
                box.innerHTML = '<span style="font-size:12px;color:var(--text-muted,#666);">'
                              + escapeHtml(t('tickets.settings.scope.rule_placeholder')) + '</span>';
                return;
            }
            box.innerHTML = templateRules.map(function (r, i) {
                const shown = r.match_type === 'address' ? r.match_value : '@' + r.match_value;
                return '<span class="tpl-rule-chip">' + escapeHtml(shown)
                     + '<button type="button" onclick="removeTemplateRule(' + i + ')">&times;</button></span>';
            }).join('');
        }

        // The @ decides the type, rather than a dropdown asking the admin to classify
        // what they just typed. "someone@a.com" is an address, "a.com" is a domain,
        // and "@a.com" is the domain somebody naturally writes for one.
        function addTemplateRule() {
            const input = document.getElementById('tplRuleInput');
            const raw = (input.value || '').trim().toLowerCase();
            if (!raw) return;

            let type, value;
            if (raw.indexOf('@') > 0) {
                type = 'address';
                value = raw;
                if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value)) {
                    showToast(t('tickets.settings.scope.rule_invalid'), 'error');
                    return;
                }
            } else {
                type = 'domain';
                value = raw.replace(/^@/, '');
                if (!/^[^@\s]+\.[^@\s]+$/.test(value)) {
                    showToast(t('tickets.settings.scope.rule_invalid'), 'error');
                    return;
                }
            }
            const dupe = templateRules.some(function (r) {
                return r.match_type === type && r.match_value === value;
            });
            if (dupe) {
                showToast(t('tickets.settings.scope.rule_duplicate'), 'error');
                return;
            }
            templateRules.push({ match_type: type, match_value: value });
            input.value = '';
            renderTemplateRules();
        }

        function removeTemplateRule(i) {
            templateRules.splice(i, 1);
            renderTemplateRules();
        }

        // What the list column shows for each template.
        function templateScopeBadge(tpl) {
            const rules = tpl.rules || [];
            if (!rules.length) {
                return '<span class="tpl-scope-badge">'
                     + escapeHtml(t('tickets.settings.scope.badge_everyone')) + '</span>';
            }
            const shown = rules.slice(0, 2).map(function (r) {
                return escapeHtml(r.match_type === 'address' ? r.match_value : '@' + r.match_value);
            }).join(', ');
            const more = rules.length > 2 ? ' +' + (rules.length - 2) : '';
            const title = t('tickets.settings.scope.badge_senders').replace('{count}', rules.length);
            return '<span class="tpl-scope-badge" title="' + escapeHtml(title) + '">' + shown + more + '</span>';
        }

        // The mistake this catches: every template for an event restricted, so a
        // sender matching none of them gets silence. Per event, because a gap in one
        // event says nothing about the others.
        function refreshScopeWarning() {
            const box = document.getElementById('tplScopeWarning');
            if (!box) return;

            const gaps = [];
            for (const ev in TPL_EVENT_LABELS) {
                const active = (emailTemplates || []).filter(function (tpl) {
                    return tpl.event_trigger === ev && tpl.is_active == 1;
                });
                const hasCatchAll = active.some(function (tpl) { return !(tpl.rules || []).length; });
                // No templates at all is not a gap — nobody is expecting an email.
                if (active.length && !hasCatchAll) gaps.push(TPL_EVENT_LABELS[ev]);
            }

            // baseUrlState.loaded gates this for the same reason it gates the other
            // warning: until the settings have actually been read we do not know
            // whether it was dismissed, and guessing "not dismissed" nags somebody
            // who already said they know.
            const show = gaps.length > 0 && baseUrlState.loaded && !baseUrlState.scopeDismissed;
            box.style.display = show ? '' : 'none';
            if (show) {
                document.getElementById('tplScopeWarningBody').textContent =
                    t('tickets.settings.scope.warn_body').replace('{events}', gaps.join(', '));
            }
        }

        async function dismissScopeWarning() {
            try {
                await fetch(API_BASE + 'set_base_url_warning_dismissed.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ warning: 'template_scope', dismissed: true })
                });
            } catch (e) { /* dismissing is a convenience; a failure leaves it showing */ }
            baseUrlState.scopeDismissed = true;
            refreshScopeWarning();
        }

        // Runs the REAL selection on the server rather than re-implementing the
        // matching here, so the answer cannot drift from what actually gets sent.
        async function runTemplateSimulator() {
            const out = document.getElementById('tplSimResult');
            const email = document.getElementById('tplSimEmail').value.trim();
            const event = document.getElementById('tplSimEvent').value;
            out.style.display = '';
            try {
                const resp = await fetch(API_BASE + 'simulate_email_template.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ event_trigger: event, email: email })
                });
                const data = await resp.json();
                if (!data.success) {
                    out.className = 'tpl-sim-result none';
                    out.textContent = data.error;
                    return;
                }
                if (!data.template_id) {
                    out.className = 'tpl-sim-result none';
                    out.textContent = data.reason === 'no_active_template'
                        ? t('tickets.settings.scope.sim_no_template')
                        : t('tickets.settings.scope.sim_none');
                    return;
                }
                const value = data.matched_type === 'domain' ? '@' + data.matched_value : (data.matched_value || '');
                const why = t('tickets.settings.scope.sim_' + data.reason).replace('{value}', value);
                out.className = 'tpl-sim-result';
                out.textContent = '"' + data.template_name + '" ' + why;
            } catch (e) {
                out.className = 'tpl-sim-result none';
                out.textContent = t('tickets.settings.base_url.save_failed');
            }
        }

        // ==================== Email template body editor (#80) ====================
        // Requested alongside [ticket_url]: the body was a bare textarea, so anyone
        // wanting bold text or a link had to hand-write HTML into it. The editor is
        // deliberately a cut-down one — this is an email body, and the formatting
        // that survives a mail client is a short list. Fonts, colours, images and
        // tables are left out on purpose rather than forgotten.
        //
        // The merge codes are typed as literal [ticket_url] text and pass through
        // untouched: TinyMCE has no reason to rewrite square brackets.
        let templateBodyEditor = null;

        function initTemplateBodyEditor() {
            if (templateBodyEditor || !document.getElementById('templateBody')) return;
            const isDark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';
            tinymce.init({
                selector: '#templateBody',
                license_key: 'gpl',
                height: 300,
                menubar: false,
                skin: isDark ? 'oxide-dark' : 'oxide',
                content_css: isDark ? 'dark' : 'default',
                plugins: ['autolink', 'lists', 'link', 'code'],
                toolbar: 'undo redo | bold italic underline | bullist numlist | link | removeformat | code',
                // `code` stays available because these bodies legitimately contain
                // hand-written HTML — the styled call-to-action buttons the help text
                // already suggests — and taking that away would be a regression for
                // anyone who has written one.
                content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; } @media (pointer: coarse) { body { font-size: 16px; } }',
                setup: function (editor) { templateBodyEditor = editor; }
            });
        }

        // Every read and write of the body goes through these two, so that the
        // editor and the underlying textarea can never disagree about what the
        // template says. Falling back to the textarea matters: if TinyMCE failed to
        // load, editing must still work rather than silently saving an empty body.
        function getTemplateBody() {
            return templateBodyEditor ? templateBodyEditor.getContent()
                                      : document.getElementById('templateBody').value;
        }

        function setTemplateBody(html) {
            if (templateBodyEditor) {
                templateBodyEditor.setContent(html || '');
            } else {
                document.getElementById('templateBody').value = html || '';
            }
        }

        function openTemplateModal(template = null) {
            document.getElementById('templateId').value = template ? template.id : '';
            document.getElementById('templateName').value = template ? template.name : '';
            document.getElementById('templateEvent').value = template ? template.event_trigger : '';
            document.getElementById('templateSubject').value = template ? template.subject_template : '';
            setTemplateBody(template ? template.body_template : '');
            // A NEW template starts as Everyone — narrowing has to be deliberate, which
            // is what keeps a catch-all present unless somebody removes it on purpose.
            templateRules = template && Array.isArray(template.rules)
                ? template.rules.map(function (r) { return { match_type: r.match_type, match_value: r.match_value }; })
                : [];
            document.querySelector('input[name="tplScope"][value="' + (templateRules.length ? 'restricted' : 'everyone') + '"]').checked = true;
            switchTemplateScope();
            renderTemplateRules();
            document.getElementById('templateOrder').value = template ? template.display_order : 0;
            document.getElementById('templateActive').checked = template ? template.is_active == 1 : true;
            document.getElementById('templateModalTitle').textContent = template ? t('tickets.settings.modals.template.edit_title') : t('tickets.settings.modals.template.add_title');
            switchTemplateBodyTab('edit'); // always open on the editor, not a stale preview
            document.getElementById('templateModal').classList.add('active');
        }

        // Sample values for the body preview — mirrors the merge codes resolved in
        // includes/template_email.php so "what you preview" matches "what's sent".
        const TPL_PREVIEW_SAMPLES = {
            ticket_reference: 'ABC-123-4567',
            ticket_url: 'https://itsm.example.com/self-service/tickets.php?id=409',
            ticket_subject: "Laptop won't turn on",
            ticket_status: 'Resolved',
            ticket_priority: 'High',
            requester_name: 'Ed Mozley',
            requester_first_name: 'Ed',
            requester_email: 'ed.mozley@example.com',
            analyst_name: 'Sam Carter',
            analyst_email: 'sam.carter@example.com',
            department_name: 'IT Support',
            created_date: '14 Feb 2026 09:15',
            closed_date: '14 Feb 2026 16:40',
            csat_link: '#'
        };

        function buildTemplatePreviewHtml(body) {
            let html = body || '';
            for (const code in TPL_PREVIEW_SAMPLES) {
                html = html.split('[' + code + ']').join(TPL_PREVIEW_SAMPLES[code]);
            }
            // Mirror buildTemplateEmailBody(): plain text (no tags) is escaped + line-broken;
            // anything with HTML tags is rendered as-is (so styled buttons show).
            if (!/<[a-z][\s\S]*>/i.test(html)) {
                const d = document.createElement('div');
                d.textContent = html;
                html = d.innerHTML.replace(/\n/g, '<br>');
            }
            return html;
        }

        function switchTemplateBodyTab(tab) {
            document.querySelectorAll('.tpl-body-tab').forEach(b =>
                b.classList.toggle('active', b.dataset.tpltab === tab));
            const isPreview = tab === 'preview';
            if (isPreview) {
                document.getElementById('templatePreview').innerHTML =
                    buildTemplatePreviewHtml(getTemplateBody());
            }
            document.getElementById('tplBodyEdit').style.display = isPreview ? 'none' : '';
            document.getElementById('tplBodyPreview').style.display = isPreview ? '' : 'none';
        }

        function editTemplate(id) {
            const template = emailTemplates.find(t => t.id == id);
            if (template) openTemplateModal(template);
        }

        function closeTemplateModal() {
            document.getElementById('templateModal').classList.remove('active');
        }

        async function deleteTemplate(id, name) {
            const ok = await showConfirm({
                title: 'Delete template',
                message: `Delete template "${name}"?`,
                okLabel: 'Delete',
                okClass: 'danger'
            });
            if (!ok) return;

            try {
                const response = await fetch(API_BASE + 'delete_email_template.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Template deleted', 'success');
                    loadEmailTemplates();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to delete template', 'error');
            }
        }

        // ------------------------------------------------------------------
        // Reply templates (canned responses) — the SHARED ones only.
        //
        // An analyst's private templates never appear on this tab, by design: they
        // are saved from the reply box and managed in its picker, so that having a
        // personal template does not require this settings permission. Everything
        // saved here posts scope 'shared', which the endpoint re-checks against
        // Cap::TICKETS_REPLY_TEMPLATES — the client naming its own scope proves
        // nothing.
        // ------------------------------------------------------------------
        let replyTemplateEditor = null;

        function initReplyTemplateEditor() {
            if (replyTemplateEditor || !document.getElementById('replyTemplateBody')) return;
            const isDark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';
            tinymce.init({
                selector: '#replyTemplateBody',
                license_key: 'gpl',
                height: 300,
                menubar: false,
                skin: isDark ? 'oxide-dark' : 'oxide',
                content_css: isDark ? 'dark' : 'default',
                plugins: ['advlist', 'autolink', 'lists', 'link', 'charmap', 'code', 'table'],
                toolbar: 'undo redo | blocks | bold italic forecolor backcolor | ' +
                         'bullist numlist outdent indent | link | removeformat | code',
                content_style: 'body { font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; } @media (pointer: coarse) { body { font-size: 16px; } }',
                setup: function(editor) { replyTemplateEditor = editor; }
            });
        }

        async function loadReplyTemplates() {
            // The tab is capability-gated, so on most analysts' pages this element
            // does not exist at all. Leaving early is the whole guard.
            const tbody = document.getElementById('reply-templates-list');
            if (!tbody) return;

            initReplyTemplateEditor();
            try {
                const response = await fetch(API_BASE + 'get_reply_templates.php?all=1');
                const data = await response.json();
                if (data.success) {
                    renderReplyTemplateMergeCodes(data.merge_codes || {});
                    // Only the shared ones belong on a settings tab. The endpoint also
                    // returns this analyst's own; filtering here keeps one endpoint
                    // serving both callers.
                    renderReplyTemplates(data.templates.filter(tpl => tpl.scope === 'shared'));
                } else {
                    showToast('Error loading reply templates: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error loading reply templates:', error);
            }
        }

        function renderReplyTemplates(templates) {
            const tbody = document.getElementById('reply-templates-list');
            if (!tbody) return;

            if (templates.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--text-faint, #999);">' +
                    escapeHtml(t('tickets.settings.reply_templates_empty')) + '</td></tr>';
                return;
            }

            tbody.innerHTML = templates.map(tpl => {
                // Strip the markup for the list preview: the column is a reminder of
                // which template this is, not a rendering of it.
                const plain = tpl.body.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                const snippet = plain.length > 70 ? plain.slice(0, 70) + '…' : plain;
                return `
                <tr>
                    <td>${escapeHtml(tpl.name)}</td>
                    <td style="color: var(--text-muted, #666);">${escapeHtml(snippet)}</td>
                    <td>${tpl.display_order}</td>
                    <td><span class="status-badge status-${tpl.is_active == 1 ? 'active' : 'inactive'}">${tpl.is_active == 1 ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editReplyTemplate(${tpl.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteReplyTemplate(${tpl.id}, ${JSON.stringify(tpl.name).replace(/"/g, '&quot;')})" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        // Clickable chips that insert a merge code at the cursor — nobody remembers
        // the exact spelling of [requester_first_name], and a typo fails silently by
        // sending the literal text to a customer.
        function renderReplyTemplateMergeCodes(codes) {
            const wrap = document.getElementById('replyTemplateMergeCodes');
            if (!wrap) return;
            wrap.innerHTML = Object.keys(codes).map(code => `
                <button type="button" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;"
                        onclick="insertReplyTemplateMergeCode('${escapeHtml(code)}')"
                        title="[${escapeHtml(code)}]">${escapeHtml(codes[code])}</button>
            `).join('');
        }

        function insertReplyTemplateMergeCode(code) {
            if (replyTemplateEditor) replyTemplateEditor.insertContent('[' + code + ']');
        }

        let replyTemplatesCache = [];

        function openReplyTemplateModal(tpl = null) {
            initReplyTemplateEditor();
            document.getElementById('replyTemplateId').value      = tpl ? tpl.id : '';
            document.getElementById('replyTemplateName').value    = tpl ? tpl.name : '';
            document.getElementById('replyTemplateOrder').value   = tpl ? tpl.display_order : 0;
            document.getElementById('replyTemplateActive').checked = tpl ? tpl.is_active == 1 : true;
            document.getElementById('replyTemplateModalTitle').textContent = tpl
                ? t('tickets.settings.modals.reply_template.edit_title')
                : t('tickets.settings.modals.reply_template.add_title');
            if (replyTemplateEditor) replyTemplateEditor.setContent(tpl ? tpl.body : '');
            document.getElementById('replyTemplateModal').classList.add('active');
        }

        function closeReplyTemplateModal() {
            document.getElementById('replyTemplateModal').classList.remove('active');
        }

        async function editReplyTemplate(id) {
            // Re-read rather than trusting a stale render: another admin may have
            // changed it in the meantime.
            const response = await fetch(API_BASE + 'get_reply_templates.php?all=1');
            const data = await response.json();
            if (!data.success) { showToast('Could not load template', 'error'); return; }
            replyTemplatesCache = data.templates;
            const tpl = replyTemplatesCache.find(x => x.id == id);
            if (tpl) openReplyTemplateModal(tpl);
        }

        async function deleteReplyTemplate(id, name) {
            const ok = await showConfirm({
                title: t('tickets.settings.modals.reply_template.delete_title'),
                message: t('tickets.settings.modals.reply_template.delete_message').replace('%s', name),
                okLabel: t('common.delete'),
                okClass: 'danger'
            });
            if (!ok) return;

            try {
                const response = await fetch(API_BASE + 'delete_reply_template.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(t('tickets.settings.reply_template_deleted'), 'success');
                    loadReplyTemplates();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to delete template', 'error');
            }
        }

        const replyTemplateForm = document.getElementById('replyTemplateForm');
        if (replyTemplateForm) {
            replyTemplateForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                const body = replyTemplateEditor ? replyTemplateEditor.getContent() : '';
                // Validated here rather than with `required`: the real input is the
                // TinyMCE iframe, which native form validation cannot see.
                if (!body.replace(/<[^>]*>/g, '').trim()) {
                    showToast(t('tickets.settings.modals.reply_template.body_required'), 'error');
                    return;
                }

                const payload = {
                    id: document.getElementById('replyTemplateId').value || null,
                    name: document.getElementById('replyTemplateName').value,
                    body: body,
                    scope: 'shared',
                    display_order: parseInt(document.getElementById('replyTemplateOrder').value) || 0,
                    is_active: document.getElementById('replyTemplateActive').checked ? 1 : 0
                };

                try {
                    const response = await fetch(API_BASE + 'save_reply_template.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await response.json();
                    if (data.success) {
                        showToast(t('tickets.settings.reply_template_saved'), 'success');
                        closeReplyTemplateModal();
                        loadReplyTemplates();
                    } else {
                        showToast('Error: ' + data.error, 'error');
                    }
                } catch (error) {
                    showToast('Failed to save template', 'error');
                }
            });
        }

        document.getElementById('templateForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const templateData = {
                id: document.getElementById('templateId').value || null,
                name: document.getElementById('templateName').value,
                event_trigger: document.getElementById('templateEvent').value,
                subject_template: document.getElementById('templateSubject').value,
                body_template: getTemplateBody(),
                // Always sent, so "Everyone" (an empty list) is saved as a real choice
                // rather than read as "leave the rules alone".
                rules: (document.querySelector('input[name="tplScope"]:checked') && document.querySelector('input[name="tplScope"]:checked').value === 'restricted') ? templateRules : [],
                display_order: parseInt(document.getElementById('templateOrder').value) || 0,
                is_active: document.getElementById('templateActive').checked ? 1 : 0
            };

            // Body is validated here (not via the `required` attribute) because the
            // textarea is hidden on the Preview tab, which would otherwise break
            // native form validation.
            //
            // ⚠️ Emptiness has to be judged on the TEXT, not the markup. A body the
            // author has cleared comes back from TinyMCE as `<p>&nbsp;</p>` or
            // `<br>`, all of which are truthy strings — so a plain .trim() check
            // would wave an empty template straight through and send blank emails.
            const bodyText = templateData.body_template
                .replace(/<[^>]*>/g, '')
                .replace(/&nbsp;/gi, ' ')
                .trim();
            if (!bodyText) {
                switchTemplateBodyTab('edit');
                if (templateBodyEditor) { templateBodyEditor.focus(); } else { document.getElementById('templateBody').focus(); }
                showToast(t('tickets.settings.modals.template.body_required'), 'error');
                return;
            }

            try {
                const response = await fetch(API_BASE + 'save_email_template.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(templateData)
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Template saved', 'success');
                    closeTemplateModal();
                    loadEmailTemplates();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save template', 'error');
            }
        });

        // ==================== Rota Shifts ====================

        async function loadRotaShifts() {
            try {
                const response = await fetch(API_BASE + 'get_rota_shifts.php');
                const data = await response.json();
                if (data.success) {
                    renderRotaShifts(data.shifts);
                }
            } catch (error) {
                console.error('Error loading rota shifts:', error);
            }
        }

        function renderRotaShifts(shifts) {
            const tbody = document.getElementById('rota-shifts-list');
            if (!shifts || shifts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-faint, #999);">No shifts defined. Click Add to create one.</td></tr>';
                return;
            }

            tbody.innerHTML = shifts.map(s => `
                <tr>
                    <td>${escapeHtml(s.name)}</td>
                    <td>${s.start_time ? s.start_time.substring(0, 5) : ''}</td>
                    <td>${s.end_time ? s.end_time.substring(0, 5) : ''}</td>
                    <td>${s.display_order}</td>
                    <td><span class="status-badge status-${s.is_active == 1 ? 'active' : 'inactive'}">${s.is_active == 1 ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editRotaShift(${s.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteRotaShift(${s.id}, '${escapeHtml(s.name)}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        let rotaShiftsCache = [];

        async function openRotaShiftModal(id) {
            document.getElementById('rotaShiftId').value = '';
            document.getElementById('rotaShiftName').value = '';
            document.getElementById('rotaShiftStart').value = '';
            document.getElementById('rotaShiftEnd').value = '';
            document.getElementById('rotaShiftOrder').value = '0';
            document.getElementById('rotaShiftActive').checked = true;
            document.getElementById('rotaShiftModalTitle').textContent = t('tickets.settings.modals.rota_shift.add_title');

            if (id) {
                document.getElementById('rotaShiftModalTitle').textContent = t('tickets.settings.modals.rota_shift.edit_title');
                try {
                    const response = await fetch(API_BASE + 'get_rota_shifts.php');
                    const data = await response.json();
                    if (data.success) {
                        const shift = data.shifts.find(s => s.id == id);
                        if (shift) {
                            document.getElementById('rotaShiftId').value = shift.id;
                            document.getElementById('rotaShiftName').value = shift.name;
                            document.getElementById('rotaShiftStart').value = shift.start_time ? shift.start_time.substring(0, 5) : '';
                            document.getElementById('rotaShiftEnd').value = shift.end_time ? shift.end_time.substring(0, 5) : '';
                            document.getElementById('rotaShiftOrder').value = shift.display_order || 0;
                            document.getElementById('rotaShiftActive').checked = shift.is_active == 1;
                        }
                    }
                } catch (error) {
                    console.error('Error loading shift:', error);
                }
            }

            document.getElementById('rotaShiftModal').classList.add('active');
        }

        function editRotaShift(id) {
            openRotaShiftModal(id);
        }

        function closeRotaShiftModal() {
            document.getElementById('rotaShiftModal').classList.remove('active');
        }

        document.getElementById('rotaShiftForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const shiftData = {
                id: document.getElementById('rotaShiftId').value || null,
                name: document.getElementById('rotaShiftName').value,
                start_time: document.getElementById('rotaShiftStart').value,
                end_time: document.getElementById('rotaShiftEnd').value,
                display_order: parseInt(document.getElementById('rotaShiftOrder').value) || 0,
                is_active: document.getElementById('rotaShiftActive').checked ? 1 : 0
            };

            try {
                const response = await fetch(API_BASE + 'save_rota_shift.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(shiftData)
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Shift saved', 'success');
                    closeRotaShiftModal();
                    loadRotaShifts();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save shift', 'error');
            }
        });

        async function deleteRotaShift(id, name) {
            const ok = await showConfirm({
                title: 'Delete shift',
                message: 'Delete shift "' + name + '"?',
                okLabel: 'Delete',
                okClass: 'danger'
            });
            if (!ok) return;

            try {
                const response = await fetch(API_BASE + 'delete_rota_shift.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Shift deleted', 'success');
                    loadRotaShifts();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to delete shift', 'error');
            }
        }

        // ==================== Rota Weekend Setting ====================

        async function loadRotaWeekendSetting() {
            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    document.getElementById('rotaIncludeWeekends').checked = data.settings.rota_include_weekends == '1';
                }
            } catch (error) {
                console.error('Error loading weekend setting:', error);
            }
        }

        async function saveRotaWeekendSetting() {
            const val = document.getElementById('rotaIncludeWeekends').checked ? '1' : '0';
            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: { rota_include_weekends: val } })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Setting saved', 'success');
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save setting', 'error');
            }
        }

        // ==================== SLA Tab ====================
        // Single in-memory state object; populated by loadSlaTab(), used by
        // the per-section render functions + the calendar edit modal.
        let slaData = { settings: {}, priorities: [], calendars: [] };
        let slaTimezones = null; // lazy-loaded on first calendar modal open
        const SLA_WEEKDAYS = [
            { num: 1, label: 'Monday' },
            { num: 2, label: 'Tuesday' },
            { num: 3, label: 'Wednesday' },
            { num: 4, label: 'Thursday' },
            { num: 5, label: 'Friday' },
            { num: 6, label: 'Saturday' },
            { num: 7, label: 'Sunday' },
        ];

        async function loadSlaTab() {
            try {
                const res = await fetch(API_BASE + 'get_sla_settings.php');
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'load failed');
                slaData = { settings: data.settings || {}, priorities: data.priorities || [], calendars: data.calendars || [] };
                renderSlaGlobalSettings();
                renderSlaTargets();
                renderSlaCalendars();
                loadSlaNotifRules();
                loadSlaCronRuns();
            } catch (e) {
                console.error('SLA load failed:', e);
            }
        }

        function renderSlaGlobalSettings() {
            const s = slaData.settings;
            // Datetime-local input format: YYYY-MM-DDTHH:MM
            const ef = s.sla_enforce_from;
            document.getElementById('slaEnforceFrom').value = ef ? ef.replace(' ', 'T').substring(0, 16) : '';
            document.getElementById('slaWarningThreshold').value = s.sla_warning_threshold_percent || '80';
            document.getElementById('slaNotifyAssignee').checked = s.sla_notify_assignee_at_warning === '1';
            document.getElementById('slaNotifyLead').checked = s.sla_notify_lead_at_breach === '1';

            const setRadio = (name, val) => {
                document.querySelectorAll(`input[name="${name}"]`).forEach(r => r.checked = (r.value === val));
            };
            setRadio('slaPriorityChange', s.sla_priority_change_behaviour || 'forward');
            setRadio('slaReopen', s.sla_reopen_behaviour || 'reset');
            setRadio('slaFirstResponse', s.sla_first_response_definition || 'either');
        }

        async function saveSlaGlobalSettings() {
            const getRadio = name => {
                const r = document.querySelector(`input[name="${name}"]:checked`);
                return r ? r.value : null;
            };
            const payload = {
                sla_enforce_from:               document.getElementById('slaEnforceFrom').value || null,
                sla_priority_change_behaviour:  getRadio('slaPriorityChange'),
                sla_reopen_behaviour:           getRadio('slaReopen'),
                sla_warning_threshold_percent:  document.getElementById('slaWarningThreshold').value || null,
                sla_notify_assignee_at_warning: document.getElementById('slaNotifyAssignee').checked,
                sla_notify_lead_at_breach:      document.getElementById('slaNotifyLead').checked,
                sla_first_response_definition:  getRadio('slaFirstResponse'),
            };
            try {
                const res = await fetch(API_BASE + 'save_sla_global_settings.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'save failed');
                showToast('SLA settings saved', 'success');
                // Refresh local copy from server (normalised values)
                loadSlaTab();
            } catch (e) {
                showToast('Failed to save SLA settings: ' + e.message, 'error');
            }
        }

        function renderSlaTargets() {
            const tbody = document.getElementById('slaTargetsList');
            if (slaData.priorities.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-faint, #999);">No active priorities — add some on the Priorities tab.</td></tr>';
                return;
            }
            const calOptions = slaData.calendars.map(c =>
                `<option value="${c.id}">${escapeHtml(c.name)}</option>`
            ).join('');
            tbody.innerHTML = slaData.priorities.map(p => `
                <tr>
                    <td><strong style="color:${escapeHtml(p.colour || '#333')};">${escapeHtml(p.name)}</strong></td>
                    <td><input type="number" min="0" value="${p.sla_response_minutes || ''}" data-pid="${p.id}" data-field="response" style="width:90px;padding:4px 8px;"></td>
                    <td><input type="number" min="0" value="${p.sla_resolution_minutes || ''}" data-pid="${p.id}" data-field="resolution" style="width:90px;padding:4px 8px;"></td>
                    <td>
                        <select data-pid="${p.id}" data-field="calendar" style="padding:4px 8px;">
                            <option value="">— None —</option>
                            ${calOptions.replace(`value="${p.sla_calendar_id}"`, `value="${p.sla_calendar_id}" selected`)}
                        </select>
                    </td>
                    <td><button type="button" class="btn btn-secondary" style="padding:4px 12px;" onclick="savePrioritySla(${p.id})">Save</button></td>
                </tr>
            `).join('');
        }

        async function savePrioritySla(priorityId) {
            const row = document.querySelector(`#slaTargetsList tr [data-pid="${priorityId}"]`).closest('tr');
            const payload = {
                id: priorityId,
                sla_response_minutes:   row.querySelector('input[data-field="response"]').value,
                sla_resolution_minutes: row.querySelector('input[data-field="resolution"]').value,
                sla_calendar_id:        row.querySelector('select[data-field="calendar"]').value,
            };
            try {
                const res = await fetch(API_BASE + 'save_priority_sla.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'save failed');
                showToast('Priority SLA saved', 'success');
                loadSlaTab();
            } catch (e) {
                showToast('Failed to save: ' + e.message, 'error');
            }
        }

        function renderSlaCalendars() {
            const tbody = document.getElementById('slaCalendarsList');
            if (slaData.calendars.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-faint, #999);">No calendars defined yet. Add one above.</td></tr>';
                return;
            }
            tbody.innerHTML = slaData.calendars.map(c => {
                const openDays = (c.hours || []).length;
                const hoursLabel = openDays > 0
                    ? `${openDays} open day${openDays === 1 ? '' : 's'}`
                    : '<span style="color:#c62828;">No hours set</span>';
                return `
                    <tr>
                        <td><strong>${escapeHtml(c.name)}</strong></td>
                        <td><code style="font-size:12px;">${escapeHtml(c.timezone)}</code></td>
                        <td>${hoursLabel}</td>
                        <td>${c.holiday_count || 0}</td>
                        <td>${c.is_default ? '<span class="status-badge status-active">Default</span>' : ''}</td>
                        <td>
                            <button class="action-btn" onclick="openSlaCalendarModal(${c.id})" title="${escapeHtml(t('common.edit'))}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // ---------- Calendar modal ----------
        let slaModalHolidays = []; // in-flight list for the open modal

        async function openSlaCalendarModal(calendarId) {
            // Lazy-load timezones once
            if (!slaTimezones) {
                try {
                    const res = await fetch(API_BASE + 'list_timezones.php');
                    const data = await res.json();
                    if (data.success) slaTimezones = data.groups;
                } catch (e) { slaTimezones = {}; }
            }
            // Populate the timezone select (only the first time, or if it's empty)
            const tzSelect = document.getElementById('slaCalendarTimezone');
            if (tzSelect.options.length === 0 && slaTimezones) {
                Object.keys(slaTimezones).sort().forEach(region => {
                    const og = document.createElement('optgroup');
                    og.label = region;
                    slaTimezones[region].forEach(tz => {
                        const opt = document.createElement('option');
                        opt.value = tz;
                        opt.textContent = tz;
                        og.appendChild(opt);
                    });
                    tzSelect.appendChild(og);
                });
            }

            // Build the 7-day hours grid
            const grid = document.getElementById('slaCalendarHoursGrid');
            grid.innerHTML = SLA_WEEKDAYS.map(w => `
                <label style="font-weight:500;">${w.label}</label>
                <label style="display:flex;align-items:center;gap:6px;font-weight:400;">
                    <input type="checkbox" class="sla-hours-open" data-wd="${w.num}"> Open
                </label>
                <input type="time" class="sla-hours-start" data-wd="${w.num}" style="padding:4px 8px;" disabled>
                <input type="time" class="sla-hours-end" data-wd="${w.num}" style="padding:4px 8px;" disabled>
            `).join('');
            // Wire the open-checkbox to enable/disable the time inputs
            grid.querySelectorAll('.sla-hours-open').forEach(cb => {
                cb.addEventListener('change', e => {
                    const wd = e.target.dataset.wd;
                    grid.querySelector(`.sla-hours-start[data-wd="${wd}"]`).disabled = !e.target.checked;
                    grid.querySelector(`.sla-hours-end[data-wd="${wd}"]`).disabled = !e.target.checked;
                });
            });

            // Default values for a fresh calendar OR load existing
            const reset = () => {
                document.getElementById('slaCalendarId').value = '';
                document.getElementById('slaCalendarName').value = '';
                tzSelect.value = 'Europe/London';
                document.getElementById('slaCalendarIsDefault').checked = false;
                slaModalHolidays = [];
                // Default: Mon-Fri 09:00-17:00 open, Sat/Sun closed
                SLA_WEEKDAYS.forEach(w => {
                    const open = w.num <= 5;
                    grid.querySelector(`.sla-hours-open[data-wd="${w.num}"]`).checked = open;
                    grid.querySelector(`.sla-hours-start[data-wd="${w.num}"]`).value = open ? '09:00' : '';
                    grid.querySelector(`.sla-hours-end[data-wd="${w.num}"]`).value = open ? '17:00' : '';
                    grid.querySelector(`.sla-hours-start[data-wd="${w.num}"]`).disabled = !open;
                    grid.querySelector(`.sla-hours-end[data-wd="${w.num}"]`).disabled = !open;
                });
                document.getElementById('slaCalendarModalTitle').textContent = 'Add business calendar';
                document.getElementById('slaCalendarDeleteBtn').style.display = 'none';
            };
            reset();

            if (calendarId) {
                try {
                    const res = await fetch(API_BASE + 'get_sla_calendar.php?id=' + calendarId);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'load failed');
                    const c = data.calendar;
                    document.getElementById('slaCalendarId').value = c.id;
                    document.getElementById('slaCalendarName').value = c.name;
                    tzSelect.value = c.timezone;
                    document.getElementById('slaCalendarIsDefault').checked = c.is_default;
                    document.getElementById('slaCalendarModalTitle').textContent = 'Edit business calendar';
                    document.getElementById('slaCalendarDeleteBtn').style.display = '';
                    // Reset all days closed first, then apply
                    SLA_WEEKDAYS.forEach(w => {
                        grid.querySelector(`.sla-hours-open[data-wd="${w.num}"]`).checked = false;
                        grid.querySelector(`.sla-hours-start[data-wd="${w.num}"]`).value = '';
                        grid.querySelector(`.sla-hours-end[data-wd="${w.num}"]`).value = '';
                        grid.querySelector(`.sla-hours-start[data-wd="${w.num}"]`).disabled = true;
                        grid.querySelector(`.sla-hours-end[data-wd="${w.num}"]`).disabled = true;
                    });
                    (c.hours || []).forEach(h => {
                        grid.querySelector(`.sla-hours-open[data-wd="${h.weekday}"]`).checked = true;
                        grid.querySelector(`.sla-hours-start[data-wd="${h.weekday}"]`).value = h.start_time;
                        grid.querySelector(`.sla-hours-end[data-wd="${h.weekday}"]`).value = h.end_time;
                        grid.querySelector(`.sla-hours-start[data-wd="${h.weekday}"]`).disabled = false;
                        grid.querySelector(`.sla-hours-end[data-wd="${h.weekday}"]`).disabled = false;
                    });
                    slaModalHolidays = (c.holidays || []).map(h => ({ holiday_date: h.holiday_date, name: h.name || '' }));
                } catch (e) {
                    showToast('Failed to load calendar: ' + e.message, 'error');
                    return;
                }
            }

            renderSlaModalHolidays();
            document.getElementById('slaCalendarModal').classList.add('active');
        }

        function closeSlaCalendarModal() {
            document.getElementById('slaCalendarModal').classList.remove('active');
        }

        function renderSlaModalHolidays() {
            const container = document.getElementById('slaCalendarHolidaysList');
            if (slaModalHolidays.length === 0) {
                container.innerHTML = '<div style="color:var(--text-faint, #999);font-size:13px;font-style:italic;">No holidays added yet.</div>';
                return;
            }
            container.innerHTML = slaModalHolidays.map((h, i) => `
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;padding:6px 10px;background:#f5f5f5;border-radius:4px;">
                    <code style="font-size:12px;">${escapeHtml(h.holiday_date)}</code>
                    ${h.name ? `<span style="color:var(--text-muted, #555);flex:1;">${escapeHtml(h.name)}</span>` : '<span style="flex:1;color:var(--text-faint, #999);font-style:italic;">(no name)</span>'}
                    <button type="button" class="action-btn delete" onclick="removeSlaHoliday(${i})" title="${escapeHtml(t('common.delete'))}" style="padding:2px 8px;">&times;</button>
                </div>
            `).join('');
        }

        function addSlaHoliday() {
            const date = document.getElementById('slaCalendarHolidayDate').value;
            const name = document.getElementById('slaCalendarHolidayName').value.trim();
            if (!date) { showToast('Pick a date first', 'error'); return; }
            if (slaModalHolidays.some(h => h.holiday_date === date)) {
                showToast('That date is already in the list', 'error');
                return;
            }
            slaModalHolidays.push({ holiday_date: date, name });
            slaModalHolidays.sort((a, b) => a.holiday_date.localeCompare(b.holiday_date));
            renderSlaModalHolidays();
            document.getElementById('slaCalendarHolidayDate').value = '';
            document.getElementById('slaCalendarHolidayName').value = '';
        }

        function removeSlaHoliday(idx) {
            slaModalHolidays.splice(idx, 1);
            renderSlaModalHolidays();
        }

        // Wire the calendar form submit
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('slaCalendarForm');
            if (!form) return;
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                const grid = document.getElementById('slaCalendarHoursGrid');
                const hours = [];
                SLA_WEEKDAYS.forEach(w => {
                    const open = grid.querySelector(`.sla-hours-open[data-wd="${w.num}"]`).checked;
                    if (!open) return;
                    const start = grid.querySelector(`.sla-hours-start[data-wd="${w.num}"]`).value;
                    const end   = grid.querySelector(`.sla-hours-end[data-wd="${w.num}"]`).value;
                    if (!start || !end) return;
                    hours.push({ weekday: w.num, start_time: start, end_time: end });
                });

                const idRaw = document.getElementById('slaCalendarId').value;
                const payload = {
                    name:       document.getElementById('slaCalendarName').value.trim(),
                    timezone:   document.getElementById('slaCalendarTimezone').value,
                    is_default: document.getElementById('slaCalendarIsDefault').checked,
                    hours,
                    holidays:   slaModalHolidays,
                };
                if (idRaw) payload.id = parseInt(idRaw, 10);

                try {
                    const res = await fetch(API_BASE + 'save_sla_calendar.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'save failed');
                    showToast('Calendar saved', 'success');
                    closeSlaCalendarModal();
                    loadSlaTab();
                } catch (e) {
                    showToast('Failed to save calendar: ' + e.message, 'error');
                }
            });
        });

        async function deleteSlaCalendar() {
            const id = parseInt(document.getElementById('slaCalendarId').value, 10);
            if (!id) return;
            const ok = await showConfirm({
                title: 'Delete calendar',
                message: 'Delete this calendar? This cannot be undone.',
                okLabel: 'Delete',
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const res = await fetch(API_BASE + 'delete_sla_calendar.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'delete failed');
                showToast('Calendar deleted', 'success');
                closeSlaCalendarModal();
                loadSlaTab();
            } catch (e) {
                showToast(e.message, 'error');
            }
        }

        // ===== Breach Notification rules =====

        // Cache the auxiliary lists alongside the rules so the modal can populate
        // dept + analyst dropdowns without a second round-trip on every open.
        let slaNotifData = { rules: [], departments: [], analysts: [] };

        async function loadSlaNotifRules() {
            try {
                const res = await fetch(API_BASE + 'get_sla_notification_rules.php');
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'load failed');
                slaNotifData = {
                    rules: data.rules || [],
                    departments: data.departments || [],
                    analysts: data.analysts || [],
                };
                renderSlaNotifRules();
            } catch (e) {
                console.error('SLA notif load failed:', e);
            }
        }

        function renderSlaNotifRules() {
            const tbody = document.getElementById('slaNotifRulesList');
            if (!slaNotifData.rules.length) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-faint, #999);">No rules configured &mdash; SLA breach emails are disabled until you add at least one.</td></tr>';
                return;
            }
            const targetLabel = { response: 'Response', resolution: 'Resolution', both: 'Both' };
            const triggerLabel = { warning: 'Warning', breach: 'Breach' };
            const triggerColour = { warning: '#f59e0b', breach: '#dc2626' };

            tbody.innerHTML = slaNotifData.rules.map(r => {
                const scope = r.department_id
                    ? escapeHtml(r.department_name || ('Department #' + r.department_id))
                    : '<em>Default (all departments)</em>';
                const recipients = [];
                if (r.notify_assignee)         recipients.push('Assignee');
                if (r.notify_department_teams) recipients.push('Dept teams');
                if (r.notify_analyst_name)     recipients.push(escapeHtml(r.notify_analyst_name));
                if (r.notify_emails) {
                    const list = r.notify_emails.split(',').map(s => s.trim()).filter(Boolean);
                    if (list.length === 1) recipients.push(escapeHtml(list[0]));
                    else if (list.length > 1) recipients.push(escapeHtml(list[0]) + ' <span style="color:var(--text-dim, #888);">+' + (list.length - 1) + ' more</span>');
                }
                return `
                    <tr>
                        <td>${scope}</td>
                        <td><span style="display:inline-block;padding:2px 8px;border-radius:10px;background:${triggerColour[r.trigger_type]}1A;color:${triggerColour[r.trigger_type]};font-size:11px;font-weight:600;">${triggerLabel[r.trigger_type]}</span></td>
                        <td>${targetLabel[r.target_type] || r.target_type}</td>
                        <td>${recipients.join(', ') || '<span style="color:#c00;">none</span>'}</td>
                        <td>${r.is_active ? 'Yes' : '<span style="color:var(--text-dim, #888);">No</span>'}</td>
                        <td>
                            <button class="action-btn" onclick="openSlaNotifModal(${r.id})" title="Edit">&#9998;</button>
                            <button class="action-btn" onclick="deleteSlaNotifRule(${r.id})" title="Delete">&times;</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function openSlaNotifModal(id) {
            // Populate the department dropdown (default option is hardcoded in markup)
            const dept = document.getElementById('slaNotifDept');
            dept.innerHTML = '<option value="">Default (applies to every department without a specific rule)</option>'
                + slaNotifData.departments.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');

            // Populate the analyst dropdown
            const an = document.getElementById('slaNotifAnalyst');
            an.innerHTML = '<option value="">&mdash; none &mdash;</option>'
                + slaNotifData.analysts.map(a => `<option value="${a.id}">${escapeHtml(a.full_name)}</option>`).join('');

            if (id) {
                const r = slaNotifData.rules.find(x => x.id === id);
                if (!r) return;
                document.getElementById('slaNotifModalTitle').textContent = 'Edit notification rule';
                document.getElementById('slaNotifId').value = r.id;
                dept.value = r.department_id || '';
                document.getElementById('slaNotifTrigger').value = r.trigger_type;
                document.getElementById('slaNotifTarget').value = r.target_type;
                document.getElementById('slaNotifAssignee').checked = !!r.notify_assignee;
                document.getElementById('slaNotifTeams').checked = !!r.notify_department_teams;
                an.value = r.notify_analyst_id || '';
                document.getElementById('slaNotifEmails').value = r.notify_emails || '';
                document.getElementById('slaNotifActive').checked = !!r.is_active;
            } else {
                document.getElementById('slaNotifModalTitle').textContent = 'Add notification rule';
                document.getElementById('slaNotifId').value = '';
                dept.value = '';
                document.getElementById('slaNotifTrigger').value = 'warning';
                document.getElementById('slaNotifTarget').value = 'both';
                document.getElementById('slaNotifAssignee').checked = true;
                document.getElementById('slaNotifTeams').checked = false;
                an.value = '';
                document.getElementById('slaNotifEmails').value = '';
                document.getElementById('slaNotifActive').checked = true;
            }
            document.getElementById('slaNotifModal').classList.add('active');
        }

        function closeSlaNotifModal() {
            document.getElementById('slaNotifModal').classList.remove('active');
        }

        async function saveSlaNotifRule() {
            const idVal = document.getElementById('slaNotifId').value;
            const payload = {
                id: idVal ? parseInt(idVal, 10) : null,
                department_id: document.getElementById('slaNotifDept').value || null,
                trigger_type: document.getElementById('slaNotifTrigger').value,
                target_type: document.getElementById('slaNotifTarget').value,
                notify_assignee: document.getElementById('slaNotifAssignee').checked,
                notify_department_teams: document.getElementById('slaNotifTeams').checked,
                notify_analyst_id: document.getElementById('slaNotifAnalyst').value || null,
                notify_emails: document.getElementById('slaNotifEmails').value,
                is_active: document.getElementById('slaNotifActive').checked,
            };
            try {
                const res = await fetch(API_BASE + 'save_sla_notification_rule.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'save failed');
                showToast('Rule saved', 'success');
                closeSlaNotifModal();
                loadSlaNotifRules();
            } catch (e) {
                showToast(e.message, 'error');
            }
        }

        async function deleteSlaNotifRule(id) {
            const ok = await showConfirm({
                title: 'Delete notification rule',
                message: 'Delete this notification rule? This cannot be undone.',
                okLabel: 'Delete',
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const res = await fetch(API_BASE + 'delete_sla_notification_rule.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'delete failed');
                showToast('Rule deleted', 'success');
                loadSlaNotifRules();
            } catch (e) {
                showToast(e.message, 'error');
            }
        }

        // ===== Cron Activity =====

        async function loadSlaCronRuns() {
            try {
                const res = await fetch(API_BASE + 'get_sla_cron_runs.php?limit=20');
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'load failed');
                renderSlaCronRuns(data.runs || [], data.settings || {});
            } catch (e) {
                console.error('SLA cron runs load failed:', e);
            }
        }

        function renderSlaCronRuns(runs, settings) {
            // Update the settings echo line
            if (typeof settings.min_interval_seconds === 'number') {
                document.getElementById('slaCronMinInterval').textContent = settings.min_interval_seconds;
            }
            if (typeof settings.retention_days === 'number') {
                document.getElementById('slaCronRetentionDays').textContent = settings.retention_days;
            }

            const tbody = document.getElementById('slaCronRunsList');
            if (!runs.length) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-faint, #999);">No runs yet &mdash; once you set up the scheduled task they\'ll appear here.</td></tr>';
                return;
            }
            const outcomeColour = {
                ok:             { bg: '#dcfce7', fg: '#166534' },
                rate_limited:   { bg: '#fef3c7', fg: '#92400e' },
                auth_failed:    { bg: '#fee2e2', fg: '#991b1b' },
                config_missing: { bg: '#fee2e2', fg: '#991b1b' },
                error:          { bg: '#fee2e2', fg: '#991b1b' },
            };
            const outcomeLabel = {
                ok: 'OK',
                rate_limited: 'Rate limited',
                auth_failed: 'Auth failed',
                config_missing: 'Config missing',
                error: 'Error',
            };

            tbody.innerHTML = runs.map(r => {
                const c = outcomeColour[r.outcome] || { bg: '#f3f4f6', fg: '#555' };
                const label = outcomeLabel[r.outcome] || r.outcome;
                const duration = r.duration_ms != null ? r.duration_ms + ' ms' : '&mdash;';
                const source = r.invocation === 'http'
                    ? `HTTP <span style="color:var(--text-dim, #888);">${escapeHtml(r.client_ip || 'unknown')}</span>`
                    : 'CLI';
                const notesAttr = r.notes ? ` title="${escapeHtml(r.notes)}"` : '';
                return `
                    <tr${notesAttr}>
                        <td>${escapeHtml(r.started_at || '')}</td>
                        <td>${source}</td>
                        <td>${duration}</td>
                        <td>${r.sent_count ?? 0}</td>
                        <td>${r.skipped_count ?? 0}</td>
                        <td>${r.error_count ?? 0}</td>
                        <td><span style="display:inline-block;padding:2px 8px;border-radius:10px;background:${c.bg};color:${c.fg};font-size:11px;font-weight:600;">${label}</span></td>
                    </tr>
                `;
            }).join('');
        }

        /* ─── Row display (discussion #61) ────────────────────────────────────
         * ⚠️ The preview below deliberately emits the REAL inbox class names
         * (.email-item, .email-stripes, .email-chip-pill, …) against the real
         * inbox.css, so what you see is what the inbox will do. It is a second
         * small renderer rather than a call into inbox.js, which is a monolith
         * with its own bootstrap — so if a class name changes in one place it
         * must change in both. That is the cost, stated rather than hidden. */
        function rdCurrent() {
            const cfg = {};
            document.querySelectorAll('#row-display-tab .rd-options').forEach(group => {
                const field = group.dataset.field;
                const sel = group.querySelector('input:checked');
                cfg[field] = sel ? sel.value : 'off';
            });
            return cfg;
        }

        const RD_SAMPLE = {
            ticket_number: 'SD-1042',
            from: 'Priya Raman',
            subject: 'Laptop will not wake from sleep',
            preview: 'Tried a hard restart twice this morning and it still…',
            priority: 'High',       priority_colour: '#d97706',
            status: 'In Progress',  status_colour: '#2563eb',
            assignee: 'Ed Mozley',
            time: '09:24'
        };

        function rdEsc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));
        }

        function rdInitials(name) {
            const p = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (!p.length) return '';
            if (p.length === 1) return p[0].substring(0, 2).toUpperCase();
            return (p[0][0] + p[p.length - 1][0]).toUpperCase();
        }

        function rdRenderPreview() {
            const el = document.getElementById('rdPreview');
            if (!el) return;
            const cfg = rdCurrent();
            const s = RD_SAMPLE;

            const stripes = ['priority', 'status']
                .filter(f => cfg[f] === 'stripe')
                .map(f => `<span class="email-stripe" style="background:${f === 'priority' ? s.priority_colour : s.status_colour}"></span>`)
                .join('');
            const blocks = ['priority', 'status']
                .filter(f => cfg[f] === 'block')
                .map(f => `<span class="email-block" style="background:${f === 'priority' ? s.priority_colour : s.status_colour}"></span>`)
                .join('');

            let chips = '';
            ['priority', 'status'].forEach(f => {
                const style = cfg[f];
                const label = f === 'priority' ? s.priority : s.status;
                const col   = f === 'priority' ? s.priority_colour : s.status_colour;
                if (style === 'dot') {
                    chips += `<span class="email-chip-dot" style="background:${col}"></span>`;
                } else if (style === 'pill') {
                    chips += `<span class="email-chip-pill" style="border-color:${col}">`
                           + `<span class="email-chip-dot" style="background:${col}"></span>`
                           + `<span class="email-chip-label">${rdEsc(label)}</span></span>`;
                }
            });
            if (cfg.agent === 'name') {
                chips += `<span class="email-chip-agent">${rdEsc(s.assignee)}</span>`;
            } else if (cfg.agent === 'initials') {
                chips += `<span class="email-chip-agent is-initials">${rdEsc(rdInitials(s.assignee))}</span>`;
            }

            el.innerHTML = `
                <div class="email-item unread">
                    ${stripes ? `<span class="email-stripes">${stripes}</span>` : ''}
                    ${blocks ? `<span class="email-blocks">${blocks}</span>` : ''}
                    <div class="email-from">${rdEsc(s.ticket_number)} - ${rdEsc(s.from)}</div>
                    <div class="email-subject">${rdEsc(s.subject)}</div>
                    <div class="email-preview">${rdEsc(s.preview)}</div>
                    <div class="email-footer-row">
                        <div class="email-time">${rdEsc(s.time)}</div>
                        ${chips}
                        <div class="email-sla-slot"></div>
                    </div>
                </div>`;
        }

        async function rdSave(scope) {
            try {
                const res = await fetch('../../api/tickets/save_inbox_display.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ scope: scope, config: rdCurrent() })
                });
                const data = await res.json();
                if (!data.success) {
                    showToast(data.error || 'Could not save', 'error');
                    return;
                }
                // A reset changes the radios, because the answer comes back from
                // the install default rather than from what is on screen.
                if (scope === 'reset' && data.config) {
                    Object.keys(data.config).forEach(f => {
                        const input = document.querySelector(`#row-display-tab input[name="rd_${f}"][value="${data.config[f]}"]`);
                        if (input) input.checked = true;
                    });
                    rdRenderPreview();
                }
                const note = document.getElementById('rdFollowing');
                if (note && typeof data.personal !== 'undefined') {
                    note.textContent = data.personal
                        ? <?php echo json_encode(t('tickets.settings.row_display.using_personal')); ?>
                        : <?php echo json_encode(t('tickets.settings.row_display.using_default')); ?>;
                }
                showToast(
                    scope === 'install'
                        ? <?php echo json_encode(t('tickets.settings.row_display.saved_default')); ?>
                        : <?php echo json_encode(t('tickets.settings.row_display.saved')); ?>,
                    'success'
                );
            } catch (e) {
                showToast('Could not save: ' + e.message, 'error');
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const tab = document.getElementById('row-display-tab');
            if (!tab) return;
            tab.addEventListener('change', function (e) {
                if (e.target && e.target.type === 'radio') rdRenderPreview();
            });
            rdRenderPreview();
        });

        /* ─── Time tracking (discussion #72) ────────────────────────────────
           Two switches per company, plus the install default they fall back to.
           A company row has THREE states, not two: on, off, or "follow the
           default" — which is why each cell is a <select> rather than a
           checkbox. A tri-state you cannot express is a company you can never
           hand back to the default once you have given it an answer. */
        (function () {
            const block = document.getElementById('time-tracking-tab');
            if (!block) return;

            const $ = id => document.getElementById(id);
            let companies = [];

            function optionsFor(value) {
                // value: true | false | null (null = follow the default)
                const sel = v => (v === value ? ' selected' : '');
                return '<option value=""' + sel(null) + '>' + ttT('inherit') + '</option>' +
                       '<option value="1"' + sel(true) + '>' + ttT('on') + '</option>' +
                       '<option value="0"' + sel(false) + '>' + ttT('off') + '</option>';
            }
            function ttT(k) {
                const map = {
                    inherit: <?php echo json_encode(t('tickets.settings.time_tracking.inherit')); ?>,
                    on:      <?php echo json_encode(t('tickets.settings.time_tracking.on')); ?>,
                    off:     <?php echo json_encode(t('tickets.settings.time_tracking.off')); ?>
                };
                return map[k] || k;
            }

            /* ⚠️ A FAILED LOAD MUST NOT LOOK LIKE "BOTH OFF".
               Both switches are drawn unticked in the markup, so an unreachable
               endpoint renders exactly like time tracking being disabled — and
               pressing Save would then write that guess back as fact, turning the
               feature off install-wide from a screen that never read anything.
               This is not hypothetical: the first version of this tab fetched a
               doubled path (API_BASE already ends in api/tickets/), 404'd, and
               showed both boxes unticked while time recording worked perfectly.
               Ed spotted it. It is the same failure the Authentication page had
               this morning, which is why the answer here is the same one. */
            let ttLoaded = false;
            function ttSetFailed(failed) {
                ttLoaded = !failed;
                $('ttLoadError').hidden = !failed;
                $('ttDefaultUi').disabled  = failed;
                $('ttDefaultApi').disabled = failed;
                $('ttSaveBtn').disabled    = failed;
            }

            async function load() {
                try {
                    const r = await fetch(API_BASE + 'get_time_tracking_settings.php');
                    const d = await r.json();
                    if (!d.success) throw new Error(d.error || 'unsuccessful response');
                    $('ttDefaultUi').checked  = !!d.default.ui;
                    $('ttDefaultApi').checked = !!d.default.api;
                    ttSetFailed(false);
                    companies = d.companies || [];
                    if (d.multi_tenant && companies.length) {
                        $('ttCompaniesBlock').hidden = false;
                        $('ttCompaniesBody').innerHTML = companies.map(c =>
                            '<tr data-id="' + c.id + '">' +
                                '<td>' + escapeHtml(c.name) + '</td>' +
                                '<td><select class="tt-ui">'  + optionsFor(c.ui)  + '</select></td>' +
                                '<td><select class="tt-api">' + optionsFor(c.api) + '</select></td>' +
                            '</tr>').join('');
                    }
                } catch (e) {
                    console.error(e);
                    ttSetFailed(true);
                }
            }

            $('ttSaveBtn').addEventListener('click', async function () {
                if (!ttLoaded) return;      // never save a state we never read
                this.disabled = true;
                const payload = {
                    default: { ui: $('ttDefaultUi').checked, api: $('ttDefaultApi').checked },
                    companies: [...document.querySelectorAll('#ttCompaniesBody tr')].map(tr => {
                        const val = s => s.value === '' ? null : (s.value === '1');
                        return {
                            id:  parseInt(tr.dataset.id, 10),
                            ui:  val(tr.querySelector('.tt-ui')),
                            api: val(tr.querySelector('.tt-api'))
                        };
                    })
                };
                try {
                    const r = await fetch(API_BASE + 'save_time_tracking_settings.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const d = await r.json();
                    showToast(d.success ? <?php echo json_encode(t('tickets.settings.time_tracking.saved')); ?>
                                        : (d.error || 'Failed'), d.success ? 'success' : 'error');
                } catch (e) {
                    showToast('Failed', 'error');
                }
                this.disabled = !ttLoaded;
            });

            load();
        })();

    </script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/mobile.js?v=53"></script>
</body>
</html>
