<?php
/**
 * System - Analysts
 *
 * Analyst account management, promoted out of Tickets → Settings. Analysts are
 * the app's users — they work across every module — so their accounts belong at
 * the install level, not inside one module's settings.
 *
 * This page owns: analyst CRUD, password reset, single sign-on provider
 * assignment, per-analyst team membership, and (multi-company installs only)
 * per-company access grants. The endpoints stay under api/tickets/ — moving the
 * UI here doesn't require moving the API.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

$current_page = 'analysts';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('tickets.settings.headings.analysts'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../../assets/css/grouped-list.css?v=1">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <style>
        /* Full-width scrolling shell — the canonical settings pattern
           (tickets/settings): a plain flex wrapper pins the header and lets the
           scroll region below fill the screen. Own class (NOT inbox.css's
           .container, whose max-width:1200px + margin:auto would centre and cap
           the page) so it fills the full width. */
        /* System's accent is blue-grey. Pin the generic accent trio to the module
           tokens so the shared inbox.css primitives (.add-btn, .btn-primary,
           focus rings, the action-icon hover) pick it up. --on-accent MUST be
           pinned too: in dark mode --sys-accent is a LIGHT blue-grey, so text on
           an accent fill has to go dark (--sys-on-accent: #263238), not white. */
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        .settings-shell { display: flex; flex-direction: column; height: 100vh; }
        .settings-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; width: 100%; margin: 0; box-sizing: border-box; padding: 30px 24px 24px; }
        .page-title { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-muted, #888); margin: 0 0 24px 0; line-height: 1.5; }
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .section-header h2 { font-size: 16px; font-weight: 600; color: var(--text, #333); margin: 0; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead th { text-align: left; color: var(--text-muted, #888); font-weight: 600; font-size: 12px; padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #eee); }
        tbody td { padding: 10px; border-bottom: 1px solid var(--border-soft, #f2f2f2); color: var(--text, #444); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        /* Status / role / company-access pills are DATA — they encode meaning and
           read the same in both modes, so their colours stay hardcoded. */
        .status-badge.status-active { background: #e8f5e9; color: #2e7d32; }
        .status-badge.status-inactive { background: #f0f0f0; color: #999; }

        /* List controls (GH #208). Labels sit above their dropdowns so the row
           wraps cleanly however many controls the install shows. */
        .av-toolbar, .av-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 12px; margin-bottom: 12px; }
        .av-filters { padding: 12px; border: 1px solid var(--border-soft, #eee); border-radius: 8px; background: var(--surface-2, #f7f9fa); }
        .av-filters[hidden], .av-field[hidden] { display: none; }
        .av-field { display: flex; flex-direction: column; gap: 3px; font-size: 11px; font-weight: 600; color: var(--text-muted, #888); }
        .av-field select, .av-search {
            padding: 7px 8px; border: 1px solid var(--border, #ccc); border-radius: 5px; font: inherit; font-size: 13px; font-weight: 400;
            background: var(--surface, #fff); color: var(--text, #333);
        }
        .av-search { min-width: 240px; flex: 0 1 280px; }
        .av-search::placeholder { color: var(--text-faint, #999); }
        .av-check { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text, #333); padding-bottom: 7px; }
        .av-check input { accent-color: var(--accent, #546e7a); }
        .av-btn { padding: 7px 14px; font-size: 13px; }
        .av-badge { display: inline-block; min-width: 18px; margin-left: 6px; padding: 0 5px; border-radius: 9px; font-size: 11px; font-weight: 700;
            background: var(--accent, #546e7a); color: var(--on-accent, #fff); text-align: center; }
        .av-badge[hidden] { display: none; }
        .av-titlebar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .av-menu-wrap { position: relative; }
        .av-menu { position: absolute; top: calc(100% + 4px); left: 0; z-index: 20; min-width: 280px; padding: 4px;
            background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,.12); }
        .av-menu button { display: block; width: 100%; text-align: left; padding: 8px 10px; border: none; border-radius: 5px; background: none;
            font: inherit; font-size: 13px; color: var(--text, #333); cursor: pointer; }
        .av-menu button:hover { background: var(--surface-hover, #f5f5f5); }
        .av-shown { font-size: 12px; color: var(--text-muted, #888); margin: 0 0 10px; min-height: 16px; }
        .av-loading, .av-empty { text-align: center; color: var(--text-muted, #888); padding: 20px; }
        .av-via { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 10px; font-size: 11px;
            background: var(--surface-2, #f0f2f4); color: var(--text-muted, #666); }
        /* Every group's table gets the same column widths, so the columns line up
           from one group to the next instead of each table sizing itself. */
        .av-table { table-layout: fixed; }
        .av-table td { overflow-wrap: anywhere; }

        /* Compact table action icons — canonical tickets/settings style
           (inbox.css's bare .action-btn is a big block button, so scope a
           compact override here or the icons balloon). */
        .settings-scroll .action-btn {
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
        .settings-scroll .action-btn:hover { background: var(--surface-hover, #f0f0f0); border-color: var(--accent, #0078d4); color: var(--accent, #0078d4); }
        .settings-scroll .action-btn.delete { color: var(--danger-accent, #d13438); }
        .settings-scroll .action-btn.delete:hover { background: var(--danger-bg, #fdf3f3); border-color: var(--danger-accent, #d13438); color: var(--danger-text, #a00); }
        .settings-scroll .action-btn svg { width: 16px; height: 16px; }

        /* ── Sign-in conflict (GH #41) ─────────────────────────────────────────
           The badge in the list is a button; the modal draws the analyst, a line to
           each of their teams, and each team's method, then what happens now and
           the ways out. Theme variables only, so it follows light and dark. */
        .signin-conflict-badge {
            display: inline-flex; align-items: center; gap: 4px; margin-left: 6px;
            padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;
            background: var(--warning-bg, #fff3e0); color: var(--warning-text, #e65100);
            border: 1px solid var(--warning-border, #ffcc80); cursor: pointer; font-family: inherit;
        }
        .signin-conflict-badge:hover, .signin-conflict-badge:focus-visible { filter: brightness(0.96); outline: 2px solid var(--warning-border, #ffcc80); outline-offset: 1px; }
        .signin-conflict-badge svg { width: 12px; height: 12px; }

        #signinConflictModal .modal-content { max-width: 600px; padding-bottom: 0; }
        .sc-head { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 18px; }
        .sc-head-icon { flex: 0 0 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            background: var(--warning-bg, #fff3e0); color: var(--warning-text, #e65100); border: 1px solid var(--warning-border, #ffcc80); }
        .sc-head-icon svg { width: 22px; height: 22px; }
        .sc-head h3 { margin: 0 0 4px; font-size: 19px; color: var(--text, #333); }
        .sc-head p { margin: 0; font-size: 13px; line-height: 1.5; color: var(--text-muted, #666); }

        /* The picture: analyst on top, a branch down to each team. */
        .sc-tree { background: var(--surface-2, #f7f9fa); border: 1px solid var(--border-soft, #eee); border-radius: 10px; padding: 16px 12px 14px; margin-bottom: 16px; }
        .sc-tree-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-faint, #999); text-align: center; margin-bottom: 10px; }
        .sc-person { display: flex; flex-direction: column; align-items: center; gap: 6px; }
        .sc-avatar { width: 46px; height: 46px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 16px; background: var(--accent, #546e7a); color: var(--on-accent, #fff); }
        .sc-person-name { font-weight: 600; font-size: 14px; color: var(--text, #333); }
        .sc-stem { width: 2px; height: 16px; margin: 6px auto 0; background: var(--border, #ccc); }
        .sc-branches { display: flex; justify-content: center; }
        .sc-branch { position: relative; flex: 1 1 0; max-width: 200px; padding: 16px 6px 0; display: flex; justify-content: center; }
        /* Org-chart connectors: each branch draws its share of the rail plus a drop. */
        .sc-branch::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: var(--border, #ccc); }
        .sc-branch:first-child::before { left: 50%; }
        .sc-branch:last-child::before { right: 50%; }
        .sc-branch:only-child::before { display: none; }
        .sc-branch::after { content: ''; position: absolute; top: 0; left: calc(50% - 1px); width: 2px; height: 16px; background: var(--border, #ccc); }
        .sc-team { width: 100%; background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-top: 3px solid var(--sc-tone, var(--border, #ccc));
            border-radius: 8px; padding: 10px 10px 12px; text-align: center; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        .sc-team-name { font-weight: 600; font-size: 13px; color: var(--text, #333); margin-bottom: 8px; overflow-wrap: anywhere; }
        .sc-method { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 12px; font-size: 12px; font-weight: 600;
            background: var(--sc-tone-bg, var(--surface-2, #f0f0f0)); color: var(--sc-tone, var(--text, #333)); }
        .sc-method svg { width: 13px; height: 13px; flex: 0 0 13px; }
        .sc-vs { text-align: center; margin-top: 10px; font-size: 12px; font-weight: 600; color: var(--warning-text, #e65100); }

        .sc-box { border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; display: flex; gap: 10px; align-items: flex-start; font-size: 13px; line-height: 1.5; }
        .sc-box svg { width: 18px; height: 18px; flex: 0 0 18px; margin-top: 1px; }
        .sc-box strong { display: block; margin-bottom: 2px; }
        .sc-box.info { background: var(--info-bg, #e3f2fd); color: var(--info-text, #0d47a1); border: 1px solid var(--info-border, #90caf9); }

        .sc-fix-title { font-size: 13px; font-weight: 600; color: var(--text, #333); margin: 0 0 8px; }
        .sc-fix { display: flex; gap: 12px; align-items: center; padding: 10px 12px; border: 1px solid var(--border-soft, #eee); border-radius: 8px; margin-bottom: 8px; background: var(--surface, #fff); }
        .sc-fix-num { flex: 0 0 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;
            background: var(--success-bg, #e8f5e9); color: var(--success-text, #2e7d32); border: 1px solid var(--success-border, #a5d6a7); }
        .sc-fix-text { flex: 1; font-size: 13px; line-height: 1.4; color: var(--text-muted, #666); }
        .sc-fix-text strong { display: block; color: var(--text, #333); }
        .sc-fix .btn { flex: 0 0 auto; padding: 6px 14px; font-size: 13px; }
        @media (max-width: 520px) {
            .sc-fix { flex-wrap: wrap; }
            .sc-fix .btn { margin-left: 38px; }
        }

        /* Canonical settings-modal overrides (match tickets/settings). */
        .modal-content { padding: 20px 20px 0; max-width: 500px; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: var(--text, #333); padding: 0; border-bottom: none; }
        /* The Save/Cancel bar is sticky; make it a full-width opaque strip flush to
           the modal's bottom (bleeding over the modal-content side/bottom padding),
           so once a modal grows tall enough to scroll, fields never show through or
           beneath the pinned buttons. */
        .modal-actions {
            position: sticky;
            bottom: 0;
            margin: 16px -20px 0;
            padding: 14px 20px;
            background: var(--surface, #fff);
            border-top: 1px solid var(--border, #e0e0e0);
            z-index: 2;
        }

        /* Native controls don't inherit a theme — inbox.css sets only border/size,
           so without these the inputs stay white-on-black in dark mode. */
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group select {
            background: var(--surface, #fff);
            color: var(--text, #333);
        }
        .form-group input::placeholder { color: var(--text-faint, #999); }
        .form-group input[type="checkbox"] { accent-color: var(--accent, #546e7a); }

        /* When someone leaves (GH #178) - Deactivate / Anonymise / Delete.
           .btn-danger is page-local, the same rule calendar.css carries. */
        .btn.btn-danger { background: var(--danger-accent, #d13438); color: #fff; border: none; }
        .btn.btn-danger:hover { filter: brightness(1.08); }
        #leaveModal .modal-content { max-width: 640px; }
        .lv-section { margin-bottom: 20px; }
        .lv-section h4 { font-size: 14px; font-weight: 600; color: var(--text, #333); margin: 0 0 8px; }
        .lv-muted { font-size: 13px; color: var(--text-muted, #666); line-height: 1.5; }
        .lv-list { list-style: none; padding: 0; margin: 0 0 10px; font-size: 13px; color: var(--text, #333); }
        .lv-list li { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; border-bottom: 1px solid var(--border, #eee); }
        .lv-list li span:last-child { font-weight: 600; }
        .lv-move { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; color: var(--text, #333); }
        .lv-move select { padding: 6px 8px; border: 1px solid var(--border, #ccc); border-radius: 5px; background: var(--surface, #fff); color: var(--text, #333); }
        .lv-note { font-size: 12.5px; padding: 9px 12px; border-radius: 6px; line-height: 1.5;
                   background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e); border: 1px solid var(--warning-border, #f0d9a8); }
        .lv-option { display: flex; gap: 10px; align-items: flex-start; padding: 12px; margin-bottom: 8px; cursor: pointer;
                     border: 1px solid var(--border, #e0e0e0); border-radius: 8px; background: var(--surface, #fff); }
        .lv-option:hover { border-color: var(--accent, #546e7a); }
        .lv-option.is-chosen { border-color: var(--accent, #546e7a); background: var(--accent-soft, #eef2f5); }
        .lv-option.is-disabled { cursor: not-allowed; opacity: .6; }
        .lv-option input { margin-top: 3px; accent-color: var(--accent, #546e7a); }
        .lv-option strong { display: block; font-size: 14px; color: var(--text, #333); margin-bottom: 3px; }
        .lv-option .lv-muted { display: block; }
        .lv-failed { font-size: 12.5px; color: var(--danger-text, #991b1b); background: var(--danger-bg, #fee2e2);
                     border: 1px solid var(--danger-border, #f0c2c2); border-radius: 6px; padding: 9px 12px; margin-top: 10px; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="settings" data-mobile-shell="own">
    <div class="settings-shell">
    <?php include '../includes/header.php'; ?>

    <div class="settings-scroll">
        <div class="av-titlebar">
            <h1 class="page-title"><?php echo htmlspecialchars(t('tickets.settings.headings.analysts')); ?></h1>
            <button class="add-btn" onclick="openAnalystModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
        </div>
        <p class="page-subtitle"><?php echo t('tickets.settings.intros.analysts'); ?></p>

        <?php $av = fn($k) => htmlspecialchars(t('tickets.settings.analyst_view.' . $k)); ?>
        <!-- The list's controls (GH #208). Layout ones are remembered per analyst
             (api/system/analysts_list_view.php); the search and filters start
             empty each visit, apart from Status. -->
        <div class="av-toolbar">
            <input type="search" id="avSearch" class="av-search" placeholder="<?php echo $av('search_placeholder'); ?>" aria-label="<?php echo $av('search_placeholder'); ?>">
            <label class="av-field"><span><?php echo $av('group_by'); ?></span>
                <select id="avGroup1" data-view="group1"></select></label>
            <label class="av-field" id="avGroup2Wrap"><span><?php echo $av('then_by'); ?></span>
                <select id="avGroup2" data-view="group2"></select></label>
            <label class="av-field" id="avLayoutWrap"><span><?php echo $av('layout'); ?></span>
                <select id="avLayout" data-view="layout">
                    <option value="tree"><?php echo $av('layout_tree'); ?></option>
                    <option value="tabs"><?php echo $av('layout_tabs'); ?></option>
                    <option value="jump"><?php echo $av('layout_jump'); ?></option>
                </select></label>
            <label class="av-field"><span><?php echo $av('sort'); ?></span>
                <select id="avSort" data-view="sort">
                    <option value="full_name"><?php echo $av('sort_full_name'); ?></option>
                    <option value="username"><?php echo $av('sort_username'); ?></option>
                    <option value="last_login"><?php echo $av('sort_last_login'); ?></option>
                </select></label>
            <label class="av-field"><span><?php echo $av('per_page'); ?></span>
                <select id="avPageSize" data-view="page_size">
                    <option value="0"><?php echo $av('all'); ?></option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select></label>
            <button type="button" class="btn btn-secondary av-btn" id="avFiltersBtn" aria-expanded="false" onclick="toggleAnalystFilters()"><?php echo $av('filters'); ?><span class="av-badge" id="avFilterCount" hidden></span></button>
            <div class="av-menu-wrap">
                <button type="button" class="btn btn-secondary av-btn" id="avDefaultBtn" aria-haspopup="true" aria-expanded="false" onclick="toggleAnalystDefaultMenu(event)"><?php echo $av('default_btn'); ?></button>
                <div class="av-menu" id="avDefaultMenu" hidden>
                    <button type="button" onclick="saveAnalystViewAsDefault()"><?php echo $av('make_default'); ?></button>
                    <button type="button" onclick="resetAnalystView()"><?php echo $av('use_default'); ?></button>
                </div>
            </div>
        </div>
        <div class="av-filters" id="avFilters" hidden>
            <label class="av-field" id="avFCompanyWrap" hidden><span><?php echo $av('g_company'); ?></span>
                <select id="avFCompany" data-filter="company"><option value=""><?php echo $av('any'); ?></option></select></label>
            <label class="av-field"><span><?php echo $av('g_team'); ?></span>
                <select id="avFTeam" data-filter="team"><option value=""><?php echo $av('any'); ?></option></select></label>
            <label class="av-field"><span><?php echo $av('g_status'); ?></span>
                <select id="avFStatus" data-view="status">
                    <option value="all"><?php echo $av('all'); ?></option>
                    <option value="active"><?php echo $av('status_active'); ?></option>
                    <option value="inactive"><?php echo $av('status_inactive'); ?></option>
                </select></label>
            <label class="av-field"><span><?php echo $av('g_admin'); ?></span>
                <select id="avFAdmin" data-filter="admin">
                    <option value=""><?php echo $av('any'); ?></option>
                    <option value="1"><?php echo $av('admin_yes'); ?></option>
                    <option value="0"><?php echo $av('admin_no'); ?></option>
                </select></label>
            <label class="av-field"><span><?php echo $av('g_signin'); ?></span>
                <select id="avFSignin" data-filter="signin"><option value=""><?php echo $av('any'); ?></option></select></label>
            <label class="av-check"><input type="checkbox" id="avFNever" data-filter="never"> <?php echo $av('never'); ?></label>
            <button type="button" class="btn btn-secondary av-btn" onclick="clearAnalystFilters()"><?php echo $av('clear'); ?></button>
        </div>
        <div class="av-shown" id="avShown" aria-live="polite"></div>
        <div id="analysts-list">
            <p class="av-loading"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></p>
        </div>
    </div>
    </div><!-- /.settings-shell -->

    <!-- Analyst add/edit modal -->
    <div class="modal" id="analystModal">
        <div class="modal-content">
            <div class="modal-header" id="analystModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.add_title')); ?></div>
            <form id="analystForm">
                <input type="hidden" id="analystId">

                <div class="form-group">
                    <label for="analystUsername"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.username')); ?> *</label>
                    <input type="text" id="analystUsername" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.username_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="analystFullName"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.full_name')); ?> *</label>
                    <input type="text" id="analystFullName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.full_name_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="analystEmail"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.email')); ?> *</label>
                    <input type="email" id="analystEmail" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.email_placeholder')); ?>">
                </div>

                <div class="form-group" id="analystPasswordGroup">
                    <label for="analystPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.password')); ?> *</label>
                    <input type="password" id="analystPassword" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.password_placeholder')); ?>">
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.password_help')); ?></small>
                </div>

                <div class="form-group">
                    <label for="analystAuthProvider"><?php echo htmlspecialchars(t('tickets.settings.analyst_extra.signin_method')); ?></label>
                    <select id="analystAuthProvider" onchange="updateAnalystSigninNote()">
                        <!-- Follow team (GH #41). Removed by JS on an install whose Database
                             Verification has not added the column yet. -->
                        <option value="team" id="analystFollowTeamOpt"><?php echo htmlspecialchars(t('tickets.settings.team_signin.follow_team')); ?></option>
                        <option value=""><?php echo t('tickets.settings.analyst_extra.signin_local'); ?></option>
                        <?php
                        // Single sign-on providers — assigning one makes this analyst an SSO user
                        // (strict isolation: they can only sign in via the chosen provider).
                        try {
                            $apConn = connectToDatabase();
                            foreach ($apConn->query("SELECT id, display_name FROM auth_providers ORDER BY sort_order, display_name") as $ap) {
                                echo '<option value="' . (int)$ap['id'] . '">' . htmlspecialchars($ap['display_name']) . '</option>';
                            }
                        } catch (Exception $e) { /* table may not exist yet */ }
                        ?>
                    </select>
                    <small id="analystTeamSigninNote" style="display:none; margin: 4px 0; padding: 6px 8px; border-radius: 4px; font-size: 12px;"></small>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.analyst_extra.signin_help')); ?> <?php echo htmlspecialchars(t('tickets.settings.team_signin.follow_help')); ?></small>
                </div>

                <!-- Multi-tenancy: company access. Hidden on a single-company install
                     (shown by JS only when more than one company exists). -->
                <div class="form-group" id="analystAccessGroup" style="display: none;">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="analystAllAccess" checked onchange="syncAnalystAccess()">
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('tickets.settings.analyst_extra.access_all')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666); display: block; margin-top: 4px;"><?php echo htmlspecialchars(t('tickets.settings.analyst_extra.access_all_help')); ?></small>
                    <div id="analystCompanyList" style="display: none; margin-top: 8px; max-height: 180px; overflow-y: auto; border: 1px solid var(--border-soft, #eee); border-radius: 6px; padding: 8px;"></div>
                    <div id="analystTeamAccessNote" style="display: none; margin-top: 8px; font-size: 12px; color: var(--text-muted, #666); line-height: 1.5;"></div>
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="analystActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('tickets.settings.modals.analyst.active')); ?>
                    </label>
                    <small style="display:block; margin-top:6px; font-size:12px; color:var(--text-muted, #666);">Somebody leaving? Use the bin icon on their row instead - it can hand their open work to someone else first.</small>
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="analystIsAdmin">
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('tickets.settings.modals.analyst.is_admin')); ?>
                    </label>
                    <small style="display:block;color:var(--text-muted,#666);margin-top:4px;"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.is_admin_help')); ?></small>
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="analystAllModules" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('tickets.settings.modals.analyst.all_modules')); ?>
                    </label>
                    <small style="display:block;color:var(--text-muted,#666);margin-top:4px;"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.all_modules_help')); ?></small>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAnalystModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Reset Modal -->
    <div class="modal" id="passwordResetModal">
        <div class="modal-content">
            <div class="modal-header"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.title')); ?></div>
            <form id="passwordResetForm">
                <input type="hidden" id="resetAnalystId">

                <p style="margin-bottom: 20px;"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.resetting_for')); ?> <strong id="resetAnalystName"></strong></p>

                <div class="form-group">
                    <label for="newPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.new_password')); ?> *</label>
                    <input type="password" id="newPassword" required minlength="8" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.new_password_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="confirmPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.confirm_password')); ?> *</label>
                    <input type="password" id="confirmPassword" required minlength="8" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.confirm_password_placeholder')); ?>">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closePasswordResetModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.submit')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- When someone leaves (GH #178): what they hold, who takes it, and
         Deactivate / Anonymise / Delete. Filled in by openLeaveModal(). -->
    <div class="modal" id="leaveModal" role="dialog" aria-modal="true" aria-labelledby="lvTitle">
        <div class="modal-content">
            <div class="modal-header" id="lvTitle">When someone leaves</div>
            <div class="modal-body" id="lvBody"><p class="lv-muted">Loading…</p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeLeaveModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="lvGo" onclick="runLeaveAction()" disabled>Deactivate</button>
            </div>
        </div>
    </div>

    <!-- Team Assignment Modal (analyst → teams) -->
    <!-- Sign-in conflict explanation (GH #41) - filled in by openSigninConflict() -->
    <div class="modal" id="signinConflictModal" role="dialog" aria-modal="true" aria-labelledby="scTitle">
        <div class="modal-content">
            <div class="sc-head">
                <div class="sc-head-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
                <div>
                    <h3 id="scTitle"><?php echo htmlspecialchars(t('tickets.settings.team_signin.cx_title')); ?></h3>
                    <p id="scLead"></p>
                </div>
            </div>

            <div class="sc-tree">
                <div class="sc-tree-label" id="scTreeLabel"></div>
                <div class="sc-person">
                    <div class="sc-avatar" id="scAvatar"></div>
                    <div class="sc-person-name" id="scName"></div>
                </div>
                <div class="sc-stem"></div>
                <div class="sc-branches" id="scBranches"></div>
                <div class="sc-vs" id="scVs"></div>
            </div>

            <div class="sc-box info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <div><strong><?php echo htmlspecialchars(t('tickets.settings.team_signin.cx_until_title')); ?></strong><span id="scUntil"></span></div>
            </div>

            <p class="sc-fix-title"><?php echo htmlspecialchars(t('tickets.settings.team_signin.cx_fix_title')); ?></p>
            <div class="sc-fix">
                <div class="sc-fix-num">1</div>
                <div class="sc-fix-text"><strong id="scFixOwn"></strong><?php echo htmlspecialchars(t('tickets.settings.team_signin.cx_fix_own_body')); ?></div>
                <button type="button" class="btn btn-primary" id="scBtnEdit"><?php echo htmlspecialchars(t('common.edit')); ?></button>
            </div>
            <div class="sc-fix">
                <div class="sc-fix-num">2</div>
                <div class="sc-fix-text"><strong><?php echo htmlspecialchars(t('tickets.settings.team_signin.cx_fix_teams_title')); ?></strong><?php echo htmlspecialchars(t('tickets.settings.team_signin.cx_fix_teams_body')); ?></div>
                <a class="btn btn-secondary" href="../teams/"><?php echo htmlspecialchars(t('tickets.settings.team_signin.cx_btn_teams')); ?></a>
            </div>
            <div class="sc-fix">
                <div class="sc-fix-num">3</div>
                <div class="sc-fix-text"><strong><?php echo htmlspecialchars(t('tickets.settings.team_signin.cx_fix_member_title')); ?></strong><span id="scFixMember"></span></div>
                <button type="button" class="btn btn-secondary" id="scBtnChange"><?php echo htmlspecialchars(t('tickets.settings.team_signin.cx_btn_change')); ?></button>
            </div>

            <div class="modal-actions" style="margin-top: 16px;">
                <button type="button" class="btn btn-secondary" onclick="closeSigninConflict()"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

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

    <script src="../../assets/js/grouped-list.js?v=2"></script>
    <script>
        const API_BASE = '../../api/tickets/';
        const t = (window.t) || ((k) => k);
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const d = document.createElement('div');
            d.textContent = String(text);
            return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        let analysts = [];
        let teams = [];                 // for the team-assignment picker

        // Map a company (tenant) id to its name for the effective-access chips.
        // Reads the companies cache populated by loadAnalystCompanies() on load.
        function companyName(id) {
            const c = (analystCompaniesCache || []).find(x => Number(x.id) === Number(id));
            return c ? c.name : ('#' + id);
        }

        // Populate the teams global (no render) so the assignment picker has data.
        async function loadTeams() {
            try {
                const r = await fetch(API_BASE + 'get_teams.php');
                const d = await r.json();
                teams = d.success ? d.teams : [];
            } catch (e) { teams = []; }
        }

        async function loadAnalysts() {
            try {
                // ?teams=1 brings every analyst's teams in this one request (GH #208).
                const response = await fetch(API_BASE + 'get_analysts.php?signin=1&teams=1');
                const data = await response.json();
                if (data.success) {
                    analysts = data.analysts;
                    fillAnalystFilterOptions();
                    renderAnalysts();
                } else {
                    document.getElementById('analysts-list').innerHTML =
                        '<p class="av-empty" style="color: red;">Error: ' + escapeHtml(data.error) + '</p>';
                }
            } catch (error) {
                console.error('Error loading analysts:', error);
                document.getElementById('analysts-list').innerHTML =
                    '<p class="av-empty" style="color: red;">Failed to load analysts.</p>';
            }
        }

        // ── The list: view, filters, grouping (GH #208) ─────────────────────
        // The layout lives in assets/js/grouped-list.js; this part decides what
        // each group is and which analysts survive the filters.
        const av = (k, vars) => t('tickets.settings.analyst_view.' + k, vars);
        const BUILTIN_VIEW = { group1: '', group2: '', layout: 'tree', sort: 'full_name', page_size: 0, status: 'all', open: null, tab: '' };
        let analystView = Object.assign({}, BUILTIN_VIEW);
        const analystFilters = { search: '', company: '', team: '', admin: '', signin: '', never: false };
        let analystList = null;
        let viewSaveTimer = null;

        function isMultiCompany() { return (analystCompaniesCache || []).length > 1; }

        // Sign-in methods by id, read from the edit modal's own list so the two
        // can never disagree. Blank = local.
        function signinProviders() {
            return Array.from(document.querySelectorAll('#analystAuthProvider option'))
                .filter(o => o.value !== '' && o.value !== 'team')
                .map(o => ({ id: Number(o.value), name: o.textContent }));
        }
        function signinLabel(a) {
            const p = a.auth_provider_id ? signinProviders().find(x => x.id === Number(a.auth_provider_id)) : null;
            return p ? p.name : av('local');
        }

        // Companies an analyst can work in: their own grants plus their teams'.
        // null = every company (their own flag, or an all-access team).
        function effectiveCompanies(a) {
            if (a.can_access_all_tenants || a.team_all_access) return null;
            return Array.from(new Set([...(a.tenant_ids || []), ...(a.team_tenant_ids || [])].map(Number)));
        }

        // Each grouper returns EVERY group the analyst belongs to - grouped-list.js
        // shows them under all of them. sort: 0 first, 1 normal, 2 last.
        const analystGroupers = {
            company: a => {
                const eff = effectiveCompanies(a);
                if (eff === null) return [{ key: 'all', label: av('all_companies'), sort: 0 }];
                if (!eff.length) return [{ key: 'none', label: av('no_companies'), sort: 2 }];
                return eff.map(id => ({ key: 'c' + id, label: companyName(id) }));
            },
            team: a => (a.teams && a.teams.length)
                ? a.teams.map(tm => ({ key: 't' + tm.id, label: tm.name, sort: 1 + teamOrder(tm.id) / 100000 }))
                : [{ key: 'none', label: av('no_team'), sort: 9 }],
            department: a => textGroup(a.department),
            job_title: a => textGroup(a.job_title),
            status: a => [a.is_active ? { key: 'active', label: av('active'), sort: 0 } : { key: 'inactive', label: av('inactive'), sort: 1 }],
            admin: a => [a.is_admin ? { key: 'admin', label: av('admin_yes'), sort: 0 } : { key: 'other', label: av('admin_no'), sort: 1 }],
            signin: a => [a.auth_provider_id ? { key: 'p' + a.auth_provider_id, label: signinLabel(a) } : { key: 'local', label: av('local'), sort: 0 }],
        };
        // Teams keep the order set on System -> Teams rather than going alphabetical.
        function teamOrder(id) { const i = teams.findIndex(x => Number(x.id) === Number(id)); return i < 0 ? 99999 : i; }
        // Free text (department, job title): "IT" and "it " are the same group.
        function textGroup(v) {
            const s = (v || '').trim();
            return s ? [{ key: 'x' + s.toLowerCase(), label: s }] : [{ key: 'none', label: av('not_set'), sort: 2 }];
        }

        function groupOptions(includeNone) {
            const opts = [['', av('none')]];
            if (isMultiCompany()) opts.push(['company', av('g_company')]);
            opts.push(['team', av('g_team')], ['department', av('g_department')], ['job_title', av('g_job_title')],
                      ['status', av('g_status')], ['admin', av('g_admin')], ['signin', av('g_signin')]);
            return includeNone ? opts : opts.slice(1);
        }

        // Put the view into the controls, and hide the ones that do nothing in it.
        function syncAnalystControls() {
            const v = analystView;
            const g1 = document.getElementById('avGroup1'), g2 = document.getElementById('avGroup2');
            g1.innerHTML = groupOptions(true).map(([k, l]) => `<option value="${k}">${escapeHtml(l)}</option>`).join('');
            g2.innerHTML = groupOptions(true).filter(([k]) => k !== v.group1).map(([k, l]) => `<option value="${k}">${escapeHtml(l)}</option>`).join('');
            // A saved Company grouping on an install that is down to one company falls back to none.
            if (!Array.from(g1.options).some(o => o.value === v.group1)) v.group1 = '';
            if (!v.group1 || !Array.from(g2.options).some(o => o.value === v.group2)) v.group2 = '';
            g1.value = v.group1;
            g2.value = v.group2;
            document.getElementById('avGroup2Wrap').hidden = !v.group1;
            document.getElementById('avLayoutWrap').hidden = !v.group1;
            document.getElementById('avLayout').value = v.layout;
            document.getElementById('avSort').value = v.sort;
            document.getElementById('avPageSize').value = String(v.page_size);
            document.getElementById('avFStatus').value = v.status;
            updateFilterBadge();
        }

        function fillAnalystFilterOptions() {
            const keep = (id, html) => { const s = document.getElementById(id); const was = s.value; s.innerHTML = html; s.value = was; if (s.value !== was) s.value = ''; };
            const any = `<option value="">${escapeHtml(av('any'))}</option>`;
            document.getElementById('avFCompanyWrap').hidden = !isMultiCompany();
            keep('avFCompany', any + (analystCompaniesCache || []).map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join(''));
            keep('avFTeam', any + `<option value="none">${escapeHtml(av('no_team'))}</option>`
                + teams.filter(tm => tm.is_active).map(tm => `<option value="${tm.id}">${escapeHtml(tm.name)}</option>`).join(''));
            keep('avFSignin', any + `<option value="local">${escapeHtml(av('local'))}</option>`
                + signinProviders().map(p => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join(''));
        }

        function activeFilterCount() {
            const f = analystFilters;
            return ['company', 'team', 'admin', 'signin'].filter(k => f[k] !== '').length + (f.never ? 1 : 0) + (analystView.status !== 'all' ? 1 : 0);
        }
        function updateFilterBadge() {
            const n = activeFilterCount(), b = document.getElementById('avFilterCount');
            b.textContent = n; b.hidden = !n;
        }

        function analystMatches(a) {
            const f = analystFilters, v = analystView;
            if (v.status === 'active' && !a.is_active) return false;
            if (v.status === 'inactive' && a.is_active) return false;
            if (f.search) {
                const hay = [a.full_name, a.username, a.email].join(' ').toLowerCase();
                if (!f.search.toLowerCase().split(/\s+/).filter(Boolean).every(w => hay.includes(w))) return false;
            }
            if (f.company && isMultiCompany()) {
                const eff = effectiveCompanies(a);
                if (eff !== null && !eff.includes(Number(f.company))) return false;
            }
            if (f.team === 'none' && a.teams && a.teams.length) return false;
            if (f.team && f.team !== 'none' && !(a.teams || []).some(tm => Number(tm.id) === Number(f.team))) return false;
            if (f.admin !== '' && Boolean(a.is_admin) !== (f.admin === '1')) return false;
            if (f.signin === 'local' && a.auth_provider_id) return false;
            if (f.signin && f.signin !== 'local' && Number(a.auth_provider_id) !== Number(f.signin)) return false;
            if (f.never && a.last_login_datetime) return false;
            return true;
        }

        function sortAnalysts(list) {
            const by = analystView.sort;
            const cmp = (x, y) => String(x || '').localeCompare(String(y || ''), undefined, { sensitivity: 'base', numeric: true });
            return list.slice().sort((a, b) => {
                // Most recent first; never signed in goes to the bottom.
                if (by === 'last_login') return cmp(b.last_login_datetime, a.last_login_datetime) || cmp(a.full_name, b.full_name);
                if (by === 'username') return cmp(a.username, b.username);
                return cmp(a.full_name, b.full_name) || cmp(a.username, b.username);
            });
        }

        function renderAnalysts() {
            if (!analystList) {
                analystList = createGroupedList({
                    mount: document.getElementById('analysts-list'),
                    scrollEl: document.querySelector('.settings-scroll'),
                    groupers: analystGroupers,
                    renderTable: analystTable,
                    emptyHtml: `<p class="av-empty">${escapeHtml(av('no_match'))}</p>`,
                    labels: { expand: av('expand'), collapse: av('collapse'), prev: av('prev'), next: av('next'),
                              range: av('range'), of: av('of'), jumpTo: av('jump_to') },
                    onStateChange: s => { analystView.open = s.open; analystView.tab = s.tab; queueViewSave(); },
                });
            }
            const all = sortAnalysts(analysts);
            const shown = all.filter(analystMatches);
            const filtered = shown.length !== all.length;
            document.getElementById('avShown').textContent = filtered ? av('shown', { shown: shown.length, total: all.length }) : '';
            analystList.render(shown, {
                allRows: all, filtered, view: analystView,
                filterSig: JSON.stringify([analystFilters, analystView.status]),
            });
        }

        // One group's analysts. Same column widths in every table so the columns
        // line up down the page: the short columns are fixed, the four text
        // columns share what is left (blank = share).
        function analystTable(list, ctx) {
            const cols = [
                ['username', ''], ['full_name', ''], ['email', ''], ['teams', ''],
                ['status', '90px'], ['is_admin', '80px'], ['last_login', '120px'], ['actions', '170px'],
            ];
            return `<table class="av-table">
                <colgroup>${cols.map(([, w]) => w ? `<col style="width:${w}">` : '<col>').join('')}</colgroup>
                <thead><tr>${cols.map(([k]) => `<th>${escapeHtml(t('tickets.settings.columns.' + k))}</th>`).join('')}</tr></thead>
                <tbody>${list.map(a => analystRow(a, ctx)).join('')}</tbody>
            </table>`;
        }

        // Under a company group: say so when they are only there because of a team.
        function viaTeamChip(a, ctx) {
            const level = [analystView.group1, analystView.group2].indexOf('company');
            const key = level >= 0 && ctx && ctx.keys ? ctx.keys[level] : '';
            if (!key || key[0] !== 'c' || a.can_access_all_tenants) return '';
            const id = Number(key.slice(1));
            if ((a.tenant_ids || []).map(Number).includes(id)) return '';
            return `<span class="av-via" title="${escapeHtml(av('via_team_tip'))}">${escapeHtml(av('via_team'))}</span>`;
        }

        // ── Controls ─────────────────────────────────────────────────────────
        function onAnalystViewChange(e) {
            const key = e.target.dataset.view;
            const val = e.target.value;
            analystView[key] = key === 'page_size' ? Number(val) : val;
            // A new grouping is a new set of groups: open the first one again.
            if (key === 'group1' || key === 'group2') { analystView.open = null; analystView.tab = ''; }
            syncAnalystControls();
            renderAnalysts();
            queueViewSave();
        }

        function onAnalystFilterChange(e) {
            const key = e.target.dataset.filter;
            analystFilters[key] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
            updateFilterBadge();
            renderAnalysts();
        }

        function toggleAnalystFilters(force) {
            const panel = document.getElementById('avFilters');
            panel.hidden = force === undefined ? !panel.hidden : !force;
            document.getElementById('avFiltersBtn').setAttribute('aria-expanded', String(!panel.hidden));
        }

        function clearAnalystFilters() {
            Object.assign(analystFilters, { company: '', team: '', admin: '', signin: '', never: false });
            ['avFCompany', 'avFTeam', 'avFAdmin', 'avFSignin'].forEach(id => { document.getElementById(id).value = ''; });
            document.getElementById('avFNever').checked = false;
            if (analystView.status !== 'all') { analystView.status = 'all'; queueViewSave(); }
            syncAnalystControls();
            renderAnalysts();
        }

        function toggleAnalystDefaultMenu(e) {
            e.stopPropagation();
            const menu = document.getElementById('avDefaultMenu');
            menu.hidden = !menu.hidden;
            document.getElementById('avDefaultBtn').setAttribute('aria-expanded', String(!menu.hidden));
        }
        document.addEventListener('click', e => {
            const menu = document.getElementById('avDefaultMenu');
            if (!menu.hidden && !e.target.closest('.av-menu-wrap')) toggleAnalystDefaultMenu({ stopPropagation() {} });
        });

        // ── Remembering the view ─────────────────────────────────────────────
        const VIEW_API = '../../api/system/analysts_list_view.php';

        async function loadAnalystView() {
            try {
                const r = await fetch(VIEW_API + '?action=get');
                const d = await r.json();
                if (d.success) analystView = Object.assign({}, BUILTIN_VIEW, d.mine || d.default || {});
            } catch (e) { /* the built-in flat list, as before #208 */ }
            if (analystView.status !== 'all') toggleAnalystFilters(true);
        }

        // Typing in a dropdown fires several changes; one save once it settles.
        function queueViewSave() {
            clearTimeout(viewSaveTimer);
            viewSaveTimer = setTimeout(() => postView('save_mine').catch(() => {}), 600);
        }

        async function postView(action) {
            const r = await fetch(VIEW_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, view: analystView }),
            });
            const d = await r.json();
            if (!d.success) throw new Error(d.error || 'Save failed');
            return d;
        }

        async function saveAnalystViewAsDefault() {
            document.getElementById('avDefaultMenu').hidden = true;
            try { await postView('save_default'); showToast(av('saved_default'), 'success'); }
            catch (e) { showToast(e.message, 'error'); }
        }

        async function resetAnalystView() {
            document.getElementById('avDefaultMenu').hidden = true;
            clearTimeout(viewSaveTimer);
            try {
                const d = await postView('reset_mine');
                analystView = Object.assign({}, BUILTIN_VIEW, d.view || {});
                syncAnalystControls();
                renderAnalysts();
                showToast(av('reset_done'), 'success');
            } catch (e) { showToast(e.message, 'error'); }
        }

        function analystRow(a, ctx) {
            const statusBadge = a.is_active
                ? '<span class="status-badge status-active">Active</span>'
                : '<span class="status-badge status-inactive">Inactive</span>';

            const adminBadge = a.is_admin
                ? '<span class="status-badge" style="background:#ede7f6; color:#5e35b1;" title="Can access the System module">Admin</span>'
                : '<span style="color: var(--text-faint, #999);">&mdash;</span>';

            const lastLogin = a.last_login_datetime
                ? fmtDateTime(a.last_login_datetime)
                : 'Never';

            const aTeams = a.teams || [];
            const teamsText = aTeams.length > 0
                ? aTeams.map(t => `<span class="status-badge" style="background: #e8f5e9; color: #2e7d32; margin-right: 4px;">${escapeHtml(t.name)}</span>`).join('')
                : '<span style="color: var(--text-faint, #999);">None</span>';

            const safeName = escapeHtml(a.full_name).replace(/'/g, "\\'");
            const safeUsername = escapeHtml(a.username).replace(/'/g, "\\'");

            // Multi-tenancy: show EFFECTIVE company access — the analyst's own
            // grants unioned with any they inherit from a team. All-access via
            // their own flag shows nothing (unrestricted, as before).
            let accessChip = '';
            if (!a.can_access_all_tenants) {
                const direct = (a.tenant_ids || []).map(Number);
                const viaTeam = (a.team_tenant_ids || []).map(Number);
                if (a.team_all_access) {
                    accessChip = `<span class="status-badge" style="background:#fff3e0; color:#e65100; margin-left:6px;" title="All companies — via team membership">All companies (via team)</span>`;
                } else {
                    const eff = Array.from(new Set([...direct, ...viaTeam]));
                    if (eff.length) {
                        const names = eff.map(companyName).join(', ');
                        const viaOnly = viaTeam.filter(id => !direct.includes(id)).length;
                        const suffix = viaOnly ? ` (${viaOnly} via team)` : '';
                        accessChip = `<span class="status-badge" style="background:#fff3e0; color:#e65100; margin-left:6px;" title="${escapeHtml(names)}">${eff.length} compan${eff.length === 1 ? 'y' : 'ies'}${suffix}</span>`;
                    } else {
                        accessChip = `<span class="status-badge" style="background:#ffebee; color:#c62828; margin-left:6px;" title="This analyst has no company access — they won't see any tickets on a multi-company install">no companies</span>`;
                    }
                }
            }

            return `
                <tr>
                    <td><strong>${escapeHtml(a.username)}</strong></td>
                    <td>${escapeHtml(a.full_name)}${accessChip}${signinConflictChip(a)}${viaTeamChip(a, ctx)}</td>
                    <td>${escapeHtml(a.email || '')}</td>
                    <td>${teamsText}</td>
                    <td>${statusBadge}</td>
                    <td>${adminBadge}</td>
                    <td>${lastLogin}</td>
                    <td>
                        <button class="action-btn" onclick="editAnalyst(${a.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn" onclick="openTeamAssignment('analyst', ${a.id}, '${safeName}')" title="${t('tickets.settings.tooltips.assign_teams')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </button>
                        <button class="action-btn" onclick="openPasswordResetModal(${a.id}, '${safeName}')" title="${t('tickets.settings.tooltips.reset_password')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteAnalyst(${a.id}, '${safeUsername}')" title="Remove">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `;
        }

        // Multi-tenancy: companies for the analyst access control (cached, active only).
        let analystCompaniesCache = null;
        async function loadAnalystCompanies() {
            if (analystCompaniesCache !== null) return analystCompaniesCache;
            try {
                const r = await fetch('../../api/system/get_tenants.php');
                const d = await r.json();
                analystCompaniesCache = (d.success ? d.companies : []).filter(c => c.is_active);
            } catch (e) { analystCompaniesCache = []; }
            return analystCompaniesCache;
        }
        function syncAnalystAccess() {
            const all = document.getElementById('analystAllAccess').checked;
            document.getElementById('analystCompanyList').style.display = all ? 'none' : '';
        }
        function renderAnalystCompanyList(companies, grantedIds) {
            const granted = new Set((grantedIds || []).map(Number));
            document.getElementById('analystCompanyList').innerHTML = companies.map(c => `
                <label style="display:flex; align-items:center; gap:8px; padding:4px 2px; font-size:13px; cursor:pointer;">
                    <input type="checkbox" class="analyst-company-cb" value="${c.id}" ${granted.has(Number(c.id)) ? 'checked' : ''}>
                    ${escapeHtml(c.name)}
                </label>`).join('');
        }

        // ── Follow team (GH #41) ──────────────────────────────────────────────
        // get_analysts.php?signin=1 only adds auth_follow_team once Database
        // Verification has added the column; until then the option is hidden.
        function signinFeatureReady() {
            return analysts.length > 0 && Object.prototype.hasOwnProperty.call(analysts[0], 'auth_follow_team');
        }

        // The name of a sign-in method, as the select shows it.
        function signinMethodName(providerId) {
            if (!providerId) return t('tickets.settings.team_signin.local');
            const opt = document.querySelector(`#analystAuthProvider option[value="${Number(providerId)}"]`);
            return opt ? opt.textContent : '#' + providerId;
        }

        function signinConflictChip(a) {
            if (!a.auth_follow_team || !a.team_signin || a.team_signin.status !== 'conflict') return '';
            return `<button type="button" class="signin-conflict-badge" onclick="openSigninConflict(${Number(a.id)})" title="${escapeHtml(t('tickets.settings.team_signin.badge_tooltip'))}">`
                 + `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`
                 + `${escapeHtml(t('tickets.settings.team_signin.badge_conflict'))}</button>`;
        }

        // One colour per sign-in METHOD, not per team - so when three teams split two
        // ways, the two that agree visibly match. Module accents, so they follow the theme.
        const SC_TONES = [
            ['var(--kb-accent, #1565c0)',  'var(--kb-accent-soft, #e3f2fd)'],
            ['var(--cm-accent, #6a1b9a)',  'var(--cm-accent-soft, #f3e5f5)'],
            ['var(--tsk-accent, #00838f)', 'var(--tsk-accent-soft, #e0f7fa)'],
            ['var(--con-accent, #ad1457)', 'var(--con-accent-soft, #fce4ec)'],
        ];
        const SC_ICON_KEY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
        const SC_ICON_SSO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>';

        function scInitials(name) {
            const parts = String(name || '?').trim().split(/\s+/);
            return ((parts[0] || '')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
        }

        function openSigninConflict(id) {
            const a = analysts.find(x => Number(x.id) === Number(id));
            if (!a || !a.team_signin) return;
            const name = a.full_name || a.username;
            const methods = a.team_signin.teams;
            const methodName = m => m.key === 0 ? t('tickets.settings.team_signin.local') : m.label;

            document.getElementById('scLead').textContent = t('tickets.settings.team_signin.cx_lead', { name: name });
            document.getElementById('scTreeLabel').textContent = t('tickets.settings.team_signin.cx_teams', { name: name });
            document.getElementById('scAvatar').textContent = scInitials(name);
            document.getElementById('scName').textContent = name;

            const toneOf = {};
            let next = 0;
            document.getElementById('scBranches').innerHTML = methods.map(m => {
                if (!(m.key in toneOf)) toneOf[m.key] = SC_TONES[next++ % SC_TONES.length];
                const [tone, bg] = toneOf[m.key];
                return `<div class="sc-branch"><div class="sc-team" style="--sc-tone:${tone}; --sc-tone-bg:${bg};">
                            <div class="sc-team-name">${escapeHtml(m.team_name)}</div>
                            <span class="sc-method">${m.key === 0 ? SC_ICON_KEY : SC_ICON_SSO}${escapeHtml(methodName(m))}</span>
                        </div></div>`;
            }).join('');
            // "Google ≠ Local password" under the picture, one entry per distinct method.
            const distinct = [...new Map(methods.map(m => [m.key, methodName(m)])).values()];
            document.getElementById('scVs').textContent = distinct.join('  ≠  ');

            document.getElementById('scUntil').textContent = t('tickets.settings.team_signin.cx_until_body', { name: name, current: signinMethodName(a.auth_provider_id) });
            document.getElementById('scFixOwn').textContent = t('tickets.settings.team_signin.cx_fix_own_title', { name: name });
            document.getElementById('scFixMember').textContent = t('tickets.settings.team_signin.cx_fix_member_body', { name: name });

            document.getElementById('scBtnEdit').onclick = () => { closeSigninConflict(); openAnalystModal(a); };
            document.getElementById('scBtnChange').onclick = () => { closeSigninConflict(); openTeamAssignment('analyst', a.id, name); };
            document.getElementById('signinConflictModal').classList.add('active');
        }

        function closeSigninConflict() {
            document.getElementById('signinConflictModal').classList.remove('active');
        }

        // Under the select: where Follow team gets its answer, or why it has none.
        let signinModalAnalyst = null;
        function updateAnalystSigninNote() {
            const note = document.getElementById('analystTeamSigninNote');
            const a = signinModalAnalyst;
            if (document.getElementById('analystAuthProvider').value !== 'team') {
                note.style.display = 'none';
                return;
            }
            let text, warn = false;
            if (!a) {
                text = t('tickets.settings.team_signin.new_follow');
            } else {
                const ts = a.team_signin || { status: 'none', teams: [] };
                // What they sign in with now - kept whenever the teams give no single answer.
                const current = signinMethodName(a.auth_provider_id);
                const label = m => m.key === 0 ? t('tickets.settings.team_signin.local') : m.label;
                if (ts.status === 'agreed') {
                    text = t('tickets.settings.team_signin.from_team', {
                        method: label(ts.teams[0]),
                        teams: ts.teams.map(m => m.team_name).join(', ')
                    });
                } else if (ts.status === 'conflict') {
                    warn = true;
                    text = t('tickets.settings.team_signin.conflict', {
                        detail: ts.teams.map(m => `${m.team_name} (${label(m)})`).join(', '),
                        current: current
                    });
                } else {
                    text = t('tickets.settings.team_signin.none', { current: current });
                }
            }
            note.textContent = text;
            note.style.background = warn ? 'var(--warning-bg, #fff3e0)' : 'var(--surface-2, #f5f7f8)';
            note.style.color = warn ? 'var(--warning-text, #e65100)' : 'var(--text-muted, #555)';
            note.style.display = 'block';
        }

        // After a save that can move people between sign-in methods.
        function showSigninOutcome(signin) {
            if (!signin) return;
            if (signin.changed > 0) showToast(t('tickets.settings.team_signin.summary_changed', { count: signin.changed }), 'info', 6000);
            if (signin.conflict > 0) showToast(t('tickets.settings.team_signin.summary_conflict', { count: signin.conflict }), 'warning', 9000);
        }

        function openAnalystModal(analyst = null) {
            document.getElementById('analystModalTitle').textContent = analyst ? t('tickets.settings.modals.analyst.edit_title') : t('tickets.settings.modals.analyst.add_title');
            document.getElementById('analystId').value = analyst ? analyst.id : '';
            document.getElementById('analystUsername').value = analyst ? analyst.username : '';
            document.getElementById('analystFullName').value = analyst ? analyst.full_name : '';
            document.getElementById('analystEmail').value = analyst ? (analyst.email || '') : '';
            document.getElementById('analystPassword').value = '';
            document.getElementById('analystActive').checked = analyst ? analyst.is_active : true;
            document.getElementById('analystIsAdmin').checked = analyst ? !!analyst.is_admin : false;
            document.getElementById('analystAllModules').checked = analyst ? (analyst.can_access_all_modules === undefined ? true : !!Number(analyst.can_access_all_modules)) : true;
            const followOpt = document.getElementById('analystFollowTeamOpt');
            const ready = signinFeatureReady();
            if (followOpt) { followOpt.hidden = !ready; followOpt.disabled = !ready; }
            signinModalAnalyst = analyst;
            // A new analyst follows their team by default - that is the point of
            // setting it on the team (GH #41). Existing analysts show what they have.
            document.getElementById('analystAuthProvider').value =
                (ready && (!analyst || analyst.auth_follow_team)) ? 'team'
                : (analyst && analyst.auth_provider_id) ? String(analyst.auth_provider_id) : '';
            updateAnalystSigninNote();

            // Password is required only for new analysts
            const passwordInput = document.getElementById('analystPassword');
            const passwordGroup = document.getElementById('analystPasswordGroup');
            if (analyst) {
                passwordInput.removeAttribute('required');
                passwordGroup.querySelector('small').textContent = 'Leave blank to keep existing password.';
            } else {
                passwordInput.setAttribute('required', 'required');
                passwordGroup.querySelector('small').textContent = 'Required for new analysts.';
            }

            // Multi-tenancy company access — shown only when more than one company exists.
            const accessGroup = document.getElementById('analystAccessGroup');
            loadAnalystCompanies().then(companies => {
                if (companies.length > 1) {
                    accessGroup.style.display = '';
                    document.getElementById('analystAllAccess').checked = analyst ? !!analyst.can_access_all_tenants : true;
                    renderAnalystCompanyList(companies, analyst ? (analyst.tenant_ids || []) : []);
                    syncAnalystAccess();

                    // Read-only note: company access inherited via team membership,
                    // which can't be edited here (it's set on System → Teams). Makes
                    // clear that unticking a box won't remove team-granted access.
                    const note = document.getElementById('analystTeamAccessNote');
                    const direct = new Set((analyst && analyst.tenant_ids ? analyst.tenant_ids : []).map(Number));
                    const viaOnly = (analyst && analyst.team_tenant_ids ? analyst.team_tenant_ids : []).map(Number).filter(id => !direct.has(id));
                    if (analyst && analyst.team_all_access) {
                        note.style.display = '';
                        note.innerHTML = 'This analyst is in a team with access to <strong>all companies</strong>, so they can reach every company regardless of the selection above.';
                    } else if (viaOnly.length) {
                        note.style.display = '';
                        note.innerHTML = 'Also reachable via team membership: <strong>' + viaOnly.map(id => escapeHtml(companyName(id))).join(', ') + '</strong> — manage that on System → Teams.';
                    } else {
                        note.style.display = 'none';
                        note.innerHTML = '';
                    }
                } else {
                    accessGroup.style.display = 'none';
                }
            });

            document.getElementById('analystModal').classList.add('active');
        }

        function closeAnalystModal() {
            document.getElementById('analystModal').classList.remove('active');
        }

        function editAnalyst(id) {
            const analyst = analysts.find(a => a.id == id);
            if (analyst) {
                openAnalystModal(analyst);
            } else {
                showToast('Analyst not found.', 'error');
            }
        }

        // The Delete button opens "When someone leaves" (GH #178). Deleting is only
        // one of three answers, and for anybody who has done any work it is not
        // possible at all - their name is on history. The rules are server-side
        // (includes/analyst_lifecycle.php); this window explains them.
        function deleteAnalyst(id, username) { openLeaveModal(id); }

        let lvState = null;

        async function openLeaveModal(id) {
            lvState = { id: id, action: null, data: null };
            const body = document.getElementById('lvBody');
            body.innerHTML = '<p class="lv-muted">Loading…</p>';
            document.getElementById('lvGo').disabled = true;
            document.getElementById('leaveModal').classList.add('active');
            try {
                const r = await fetch(API_BASE + 'analyst_leaving.php?id=' + encodeURIComponent(id));
                const d = await r.json();
                if (!d.success) { body.innerHTML = '<p class="lv-muted">' + escapeHtml(d.error || 'Something went wrong.') + '</p>'; return; }
                lvState.data = d;
                renderLeaveModal();
            } catch (e) {
                body.innerHTML = '<p class="lv-muted">Could not load what this analyst holds.</p>';
            }
        }

        function closeLeaveModal() {
            document.getElementById('leaveModal').classList.remove('active');
            lvState = null;
        }

        function renderLeaveModal() {
            const d = lvState.data;
            const name = escapeHtml(d.analyst.name);
            document.getElementById('lvTitle').textContent = 'When ' + d.analyst.name + ' leaves';
            const body = document.getElementById('lvBody');

            if (d.is_self) {
                body.innerHTML = '<p class="lv-muted">This is your own account. Ask another administrator to do this.</p>';
                return;
            }
            if (d.is_last_admin) {
                body.innerHTML = '<p class="lv-muted">' + name + ' is the last active administrator. Make another analyst an administrator first, or nobody will be able to manage FreeITSM.</p>';
                return;
            }

            const live = Object.values(d.live || {});
            const setup = Object.entries(d.setup || {});
            let html = '';

            // 1. Their open work, and who takes it.
            html += '<div class="lv-section"><h4>Their open work</h4>';
            if (!live.length) {
                html += '<p class="lv-muted">They hold no open work.</p>';
            } else {
                html += '<ul class="lv-list">' + live.map(g => '<li><span>' + escapeHtml(g.label) + '</span><span>' + g.count + '</span></li>').join('') + '</ul>';
                html += '<label class="lv-move"><input type="checkbox" id="lvMove" checked> Move it to '
                     +  '<select id="lvTo"><option value="">Nobody (leave it unassigned)</option>'
                     +  (d.candidates || []).map(c => '<option value="' + c.id + '">' + escapeHtml(c.name) + '</option>').join('')
                     +  '</select></label>'
                     +  '<p class="lv-muted" style="margin-top:6px">Each item is moved the same way as doing it by hand, so its history shows the change. Closed items keep their name: that is history.</p>';
            }
            html += '</div>';

            // 2. Things routed to them that a person has to rethink.
            if (setup.length) {
                html += '<div class="lv-section"><h4>Also set up for them</h4>'
                     +  '<ul class="lv-list">' + setup.map(([l, n]) => '<li><span>' + escapeHtml(l) + '</span><span>' + n + '</span></li>').join('') + '</ul>'
                     +  '<div class="lv-note">Change these by hand. FreeITSM does not change who approves or is notified for you: clearing an approver could let requests through unchecked.</div></div>';
            }

            // 3. What happens to them.
            const histWords = d.history ? (d.history + ' record' + (d.history === 1 ? '' : 's') + ' carry their name') : '';
            const delReason = !d.can_delete
                ? (d.history ? histWords + ', such as notes, time and audit entries. Deleting would leave holes in that history, so choose Deactivate or Anonymise.'
                             : 'Something is still set up for them (above). Change that first.')
                : 'Removes the account, with their personal settings (dashboard, preferences, sign-in links). Cannot be undone.';
            html += '<div class="lv-section"><h4>What happens to ' + name + '</h4>'
                 +  leaveOption('deactivate', 'Deactivate', d.analyst.is_active
                        ? 'They can no longer sign in, and their API keys stop working. Their name stays on everything they did. Undo it any time by ticking Active again.'
                        : 'Already deactivated.', !d.analyst.is_active)
                 +  leaveOption('anonymise', 'Anonymise', 'Everything Deactivate does, then their name and contact details are removed: their records stay, credited to "Former analyst #' + d.analyst.id + '". Use this for a request to be forgotten. Cannot be undone. Names typed into notes or emails are not changed.', false)
                 +  leaveOption('delete', 'Delete', delReason, !d.can_delete)
                 +  '</div>';
            html += '<p class="lv-muted"><a href="../help/analysts.php#leavers" target="_blank" rel="noopener">How this works</a></p>';
            html += '<div id="lvResult"></div>';
            body.innerHTML = html;

            // The sensible default: Deactivate, or Anonymise when that is already done.
            pickLeaveOption(d.analyst.is_active ? 'deactivate' : 'anonymise');
        }

        function leaveOption(key, title, text, disabled) {
            return '<label class="lv-option' + (disabled ? ' is-disabled' : '') + '" data-lv="' + key + '">'
                 + '<input type="radio" name="lvAction" value="' + key + '"' + (disabled ? ' disabled' : '') + ' onchange="pickLeaveOption(\'' + key + '\')">'
                 + '<span><strong>' + title + '</strong><span class="lv-muted">' + escapeHtml(text) + '</span></span></label>';
        }

        function pickLeaveOption(key) {
            const radio = document.querySelector('#lvBody input[name="lvAction"][value="' + key + '"]');
            if (!radio || radio.disabled) return;
            radio.checked = true;
            lvState.action = key;
            document.querySelectorAll('#lvBody .lv-option').forEach(o => o.classList.toggle('is-chosen', o.dataset.lv === key));
            const go = document.getElementById('lvGo');
            go.disabled = false;
            go.textContent = key === 'deactivate' ? 'Deactivate' : key === 'anonymise' ? 'Anonymise' : 'Delete';
            go.className = 'btn ' + (key === 'deactivate' ? 'btn-primary' : 'btn-danger');
        }

        async function runLeaveAction() {
            if (!lvState || !lvState.action) return;
            const d = lvState.data, action = lvState.action;
            if (action !== 'deactivate') {
                const ok = await showConfirm({
                    title: action === 'anonymise' ? 'Anonymise ' + d.analyst.name + '?' : 'Delete ' + d.analyst.name + '?',
                    message: action === 'anonymise'
                        ? 'Their name and contact details will be removed for good. This cannot be undone.'
                        : 'The account will be removed for good. This cannot be undone.',
                    okLabel: action === 'anonymise' ? 'Anonymise' : 'Delete',
                    okClass: 'danger'
                });
                if (!ok) return;
            }
            const move = document.getElementById('lvMove');
            const payload = { id: d.analyst.id, action: action, reassign: !!(move && move.checked) };
            if (payload.reassign) payload.reassign_to = document.getElementById('lvTo').value || null;
            const go = document.getElementById('lvGo');
            go.disabled = true;
            try {
                const r = await fetch(API_BASE + 'analyst_leaving.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
                });
                const res = await r.json();
                if (!res.success) {
                    document.getElementById('lvResult').innerHTML = '<div class="lv-failed">' + escapeHtml(res.error || 'Something went wrong.') + '</div>';
                    go.disabled = false;
                    return;
                }
                const done = action === 'deactivate' ? 'deactivated' : action === 'anonymise' ? 'anonymised' : 'deleted';
                if (res.failed && res.failed.length) {
                    // Done, but some work would not move - say which, rather than a toast that vanishes.
                    document.getElementById('lvResult').innerHTML = '<div class="lv-failed"><strong>' + escapeHtml(d.analyst.name) + ' was ' + done
                        + ', but ' + res.failed.length + ' item(s) could not be moved - open each to reassign it:</strong><br>'
                        + res.failed.map(escapeHtml).join('<br>') + '</div>';
                    go.textContent = 'Done';
                    go.onclick = () => { closeLeaveModal(); go.onclick = runLeaveAction; };
                    go.disabled = false;
                } else {
                    showToast('Analyst ' + done, 'success');
                    closeLeaveModal();
                }
                loadAnalysts();
            } catch (e) {
                document.getElementById('lvResult').innerHTML = '<div class="lv-failed">Something went wrong.</div>';
                go.disabled = false;
            }
        }

        function openPasswordResetModal(id, name) {
            document.getElementById('resetAnalystId').value = id;
            document.getElementById('resetAnalystName').textContent = name;
            document.getElementById('newPassword').value = '';
            document.getElementById('confirmPassword').value = '';
            document.getElementById('passwordResetModal').classList.add('active');
        }

        function closePasswordResetModal() {
            document.getElementById('passwordResetModal').classList.remove('active');
        }

        // Analyst → teams assignment picker
        async function openTeamAssignment(entityType, entityId, entityName) {
            document.getElementById('assignmentEntityType').value = entityType;
            document.getElementById('assignmentEntityId').value = entityId;
            document.getElementById('teamAssignmentTitle').textContent = `Assign Teams to "${entityName}"`;
            document.getElementById('teamAssignmentDesc').textContent = 'Select which teams this analyst belongs to:';

            const listContainer = document.getElementById('teamAssignmentList');
            listContainer.innerHTML = '<div style="padding: 15px; text-align: center; color: var(--text-faint, #999);">Loading teams...</div>';

            let currentTeamIds = [];
            try {
                const response = await fetch(`${API_BASE}get_analyst_teams.php?analyst_id=${entityId}`);
                const data = await response.json();
                if (data.success) currentTeamIds = data.teams.map(t => t.id);
            } catch (e) {
                console.error('Error loading current assignments:', e);
            }

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

        function closeTeamAssignmentModal() {
            document.getElementById('teamAssignmentModal').classList.remove('active');
        }

        document.getElementById('teamAssignmentForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const entityId = document.getElementById('assignmentEntityId').value;
            const checkboxes = document.querySelectorAll('#teamAssignmentList input[name="team_ids"]:checked');
            const teamIds = Array.from(checkboxes).map(cb => parseInt(cb.value));

            try {
                const response = await fetch(API_BASE + 'save_analyst_teams.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ analyst_id: entityId, team_ids: teamIds })
                });
                const data = await response.json();
                if (data.success) {
                    closeTeamAssignmentModal();
                    showToast('Saved', 'success');
                    showSigninOutcome(data.signin);
                    loadAnalysts();
                } else {
                    showToast('Error saving team assignments: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to save team assignments', 'error');
            }
        });

        // Analyst form submission
        document.getElementById('analystForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = {
                id: document.getElementById('analystId').value || null,
                username: document.getElementById('analystUsername').value,
                full_name: document.getElementById('analystFullName').value,
                email: document.getElementById('analystEmail').value || null,
                password: document.getElementById('analystPassword').value || null,
                is_active: document.getElementById('analystActive').checked,
                is_admin: document.getElementById('analystIsAdmin').checked,
                can_access_all_modules: document.getElementById('analystAllModules').checked,
                auth_provider_id: ['', 'team'].includes(document.getElementById('analystAuthProvider').value) ? null : document.getElementById('analystAuthProvider').value
            };
            if (signinFeatureReady()) {
                formData.auth_follow_team = document.getElementById('analystAuthProvider').value === 'team' ? 1 : 0;
            }

            // Multi-tenancy: send company access only when the control is shown.
            const accessGroup = document.getElementById('analystAccessGroup');
            if (accessGroup.style.display !== 'none') {
                const allAccess = document.getElementById('analystAllAccess').checked;
                formData.can_access_all_tenants = allAccess ? 1 : 0;
                formData.tenant_ids = allAccess ? [] :
                    Array.from(document.querySelectorAll('.analyst-company-cb:checked')).map(cb => parseInt(cb.value, 10));
            }

            try {
                const response = await fetch(API_BASE + 'save_analyst.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();
                if (data.success) {
                    closeAnalystModal();
                    showToast('Analyst saved', 'success');
                    showSigninOutcome(data.signin);
                    loadAnalysts();
                } else {
                    showToast('Error saving analyst: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to save analyst', 'error');
            }
        });

        // Password reset form submission
        document.getElementById('passwordResetForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;

            if (newPassword !== confirmPassword) {
                showToast('Passwords do not match.', 'error');
                return;
            }
            if (newPassword.length < 8) {   // includes/password_policy.php has the full rule
                showToast('Password must be at least 8 characters.', 'error');
                return;
            }

            try {
                const response = await fetch(API_BASE + 'reset_analyst_password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: document.getElementById('resetAnalystId').value,
                        password: newPassword
                    })
                });
                const data = await response.json();
                if (data.success) {
                    closePasswordResetModal();
                    showToast('Password reset successfully.', 'success');
                } else {
                    showToast('Error resetting password: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to reset password', 'error');
            }
        });

        document.addEventListener('DOMContentLoaded', async function() {
            document.querySelectorAll('[data-view]').forEach(el => el.addEventListener('change', onAnalystViewChange));
            document.querySelectorAll('[data-filter]').forEach(el => el.addEventListener('change', onAnalystFilterChange));
            let searchTimer = null;
            document.getElementById('avSearch').addEventListener('input', e => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => { analystFilters.search = e.target.value.trim(); renderAnalysts(); }, 150);
            });

            // Teams, companies and the saved view all shape the first draw, so
            // they load together before it.
            await Promise.all([loadTeams(), loadAnalystCompanies(), loadAnalystView()]);
            syncAnalystControls();
            loadAnalysts();
        });
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
