<?php
/**
 * Shared Waffle Menu Component
 * Cross-module navigation menu for ITSM system
 *
 * Required variables before including:
 *   $path_prefix - Path to root (e.g., '../' or '../../')
 *   $current_module - Current module identifier (tickets, assets, knowledge, changes, calendar, morning-checks, reporting)
 *
 * Optional variables:
 *   $analyst_name - User's display name (defaults to 'Analyst')
 */

$path_prefix = $path_prefix ?? '../';
$current_module = $current_module ?? '';
$analyst_name = $analyst_name ?? ($_SESSION['analyst_name'] ?? 'Analyst');

// The waffle renders on every module's header, so guarantee the admin helper is
// available (used below to hide the System launcher from non-admins).
require_once __DIR__ . '/functions.php';

require_once __DIR__ . '/module-colors.php';

// Bootstrap i18n so every module that includes this header gets t() for free.
// Idempotent — pages that already initialised it (tickets, process-mapper) are fine.
// functions.php must load first: I18n::initFromSession() reads the user's
// interface_language preference via connectToDatabase(), which is defined there.
// Without this, pages that don't pre-load functions.php (like index.php) silently
// fall back to Accept-Language → English and ignore the user's saved locale.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/theme.php';
I18n::initFromSession();

// Password expiry guard — force redirect if password is expired
if (!empty($_SESSION['password_expired'])) {
    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($currentUrl, 'force_password_change.php') === false && strpos($currentUrl, 'analyst_logout.php') === false && strpos($currentUrl, 'api/') === false) {
        header('Location: ' . BASE_URL . 'force_password_change.php');
        exit;
    }
}

// Module definitions - add new modules here.
// Display names resolve via t('common.modules.<key>.name') so adding a module means
// one entry here + one entry in lang/<locale>/common.php's 'modules' array per language.
$modules = [
    'watchtower' => [
        'name' => t('common.modules.watchtower.name'),
        'path' => 'watchtower/',
        'icon' => '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>'
    ],
    'tickets' => [
        'name' => t('common.modules.tickets.name'),
        'path' => 'tickets/',
        'icon' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>'
    ],
    'assets' => [
        'name' => t('common.modules.assets.name'),
        'path' => 'asset-management/',
        'icon' => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line>'
    ],
    'knowledge' => [
        'name' => t('common.modules.knowledge.name'),
        'path' => 'knowledge/',
        'icon' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>'
    ],
    'changes' => [
        'name' => t('common.modules.changes.name'),
        'path' => 'change-management/',
        'icon' => '<polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line>'
    ],
    'problems' => [
        // Waffle uses the short one-word label; the full ITIL term
        // "Problem Management" (common.modules.problems.name) is used everywhere else.
        'name' => t('common.modules.problems.name_short'),
        'path' => 'problem-management/',
        'icon' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>'
    ],
    'calendar' => [
        'name' => t('common.modules.calendar.name'),
        'path' => 'calendar/',
        'icon' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>'
    ],
    'morning-checks' => [
        'name' => t('common.modules.morning-checks.name'),
        'path' => 'morning-checks/',
        'icon' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>'
    ],
    'reporting' => [
        'name' => t('common.modules.reporting.name'),
        'path' => 'reporting/',
        'icon' => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>'
    ],
    'software' => [
        'name' => t('common.modules.software.name'),
        'path' => 'software/',
        'icon' => '<rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line>'
    ],
    'forms' => [
        'name' => t('common.modules.forms.name'),
        'path' => 'forms/',
        'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>'
    ],
    'contracts' => [
        'name' => t('common.modules.contracts.name'),
        'path' => 'contracts/',
        'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="12" y1="9" x2="8" y2="9"></line>'
    ],
    'service-status' => [
        'name' => t('common.modules.service-status.name'),
        'path' => 'service-status/',
        'icon' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>'
    ],
    'war-room' => [
        'name' => t('common.modules.war-room.name'),
        'path' => 'war-room/',
        // Speech bubbles. The war room is the one module people need to FIND in
        // a hurry, with their usual chat tool down, so the icon has to read as
        // "talk to people" at a glance rather than as anything clever.
        'icon' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>'
    ],
    'wiki' => [
        'name' => t('common.modules.wiki.name'),
        'path' => 'system-wiki/',
        'icon' => '<circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>'
    ],
    'lms' => [
        'name' => t('common.modules.lms.name'),
        'path' => 'lms/',
        'icon' => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c0 1.66 2.69 3 6 3s6-1.34 6-3v-5"></path>'
    ],
    'process-mapper' => [
        'name' => t('common.modules.process-mapper.name'),
        'path' => 'process-mapper/',
        'icon' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>'
    ],
    'tasks' => [
        'name' => t('common.modules.tasks.name'),
        'path' => 'tasks/',
        'icon' => '<path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>'
    ],
    'cmdb' => [
        'name' => t('common.modules.cmdb.name'),
        'path' => 'cmdb/',
        'icon' => '<path d="M2 22V8l10-6 10 6v14"></path><path d="M2 12h20"></path><path d="M2 17h20"></path><line x1="12" y1="2" x2="12" y2="22"></line>'
    ],
    'network-mapper' => [
        'name' => t('common.modules.network-mapper.name'),
        'path' => 'network-mapper/',
        'icon' => '<circle cx="6" cy="6" r="2.5"></circle><circle cx="18" cy="6" r="2.5"></circle><circle cx="12" cy="18" r="2.5"></circle><line x1="7.5" y1="7.5" x2="11" y2="16"></line><line x1="16.5" y1="7.5" x2="13" y2="16"></line><line x1="8.5" y1="6" x2="15.5" y2="6"></line>'
    ],
    'workflow' => [
        'name' => t('common.modules.workflow.name'),
        'path' => 'workflow/',
        'icon' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline><circle cx="6" cy="12" r="2"></circle><circle cx="18" cy="12" r="2"></circle>'
    ],
    'system' => [
        'name' => t('common.modules.system.name'),
        'path' => 'system/',
        'icon' => '<line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line>'
    ]
];
?>
<style>
    /* Waffle Menu Styles */
    .waffle-menu-container {
        position: relative;
        display: flex;
        align-items: center;
    }

    .waffle-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 8px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.15s;
        margin-right: 15px;
    }

    .waffle-btn:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }

    .waffle-icon {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 3px;
        width: 18px;
        height: 18px;
    }

    .waffle-icon span {
        width: 4px;
        height: 4px;
        background-color: #fff;
        border-radius: 50%;
    }

    .waffle-panel {
        position: absolute;
        top: 100%;
        left: 0;
        margin-top: 8px;
        background: var(--surface);
        border-radius: 8px;
        box-shadow: 0 6px 30px rgba(0, 0, 0, 0.25);
        padding: 20px;
        /* Widened to 460px so the 4-column grid (5 rows for 20 modules)
           has breathing room for the icon + label per cell. */
        min-width: 460px;
        z-index: 1000;
        display: none;
    }

    .waffle-panel.active {
        display: block;
    }

    .waffle-panel-header {
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--border-soft);
    }

    .waffle-modules {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
    }

    /* ====================================================================
       The RECENT TRAIL (#124) — a second pane in this drawer.
       Discussion #124 asked for internal tabs along the top of the page. This
       is the answer to what that request was FOR ("get back to what you were
       doing"), and it lives here rather than in the ⌘K palette on purpose: the
       control you used to LEAVE a module is the one you come back through, it
       is already the mobile drawer at 360px, and it disturbs no keyboard path —
       ⌘K then Enter still means "go to Watchtower", as it always has.
       ==================================================================== */
    .waffle-tabs {
        display: flex;
        gap: 4px;
        margin-bottom: 14px;
    }
    .waffle-tab {
        flex: 1;
        padding: 7px 10px;
        border: none;
        border-radius: 6px;
        background: transparent;
        color: var(--text-muted);
        font-size: 13px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
    }
    .waffle-tab:hover { background: var(--surface-hover); color: var(--text); }
    .waffle-tab.active { background: var(--surface-hover); color: var(--text); }
    .waffle-tabpanel { display: none; }
    .waffle-tabpanel.active { display: block; }

    /* The Recent pane is a column so its search box can sit at the BOTTOM: on a
       phone that is where a thumb already is, and on a desktop it keeps the box
       out of the way of the thing you actually came for. */
    #waffleTabRecent.active {
        display: flex;
        flex-direction: column;
        /* Desktop only — the drawer takes over the height on mobile, below. */
        max-height: 60vh;
    }
    .waffle-trail {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        margin: -4px -6px 0;
        padding: 4px 6px 0;
    }

    /* A level-1 heading: the module, and when that run of records started. */
    .waffle-trail-group + .waffle-trail-group { margin-top: 2px; }
    .waffle-trail-head {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        padding: 6px 6px;
        border: none;
        background: transparent;
        border-radius: 6px;
        cursor: pointer;
        text-align: left;
        font-family: inherit;
    }
    .waffle-trail-head:hover { background: var(--surface-hover); }
    .waffle-trail-head .waffle-trail-icon {
        width: 22px;
        height: 22px;
        flex: 0 0 22px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
    }
    .waffle-trail-head .waffle-trail-icon svg { width: 13px; height: 13px; }
    .waffle-trail-name {
        font-size: 13px;
        font-weight: 600;
        color: var(--text);
        white-space: nowrap;
    }
    .waffle-trail-when {
        font-size: 11px;
        color: var(--text-muted);
        margin-left: auto;
        white-space: nowrap;
    }
    .waffle-trail-caret {
        flex: 0 0 auto;
        color: var(--text-muted);
        transition: transform 0.15s ease;
    }
    .waffle-trail-group.collapsed .waffle-trail-caret { transform: rotate(-90deg); }
    .waffle-trail-group.collapsed .waffle-trail-records { display: none; }
    @media (prefers-reduced-motion: reduce) {
        .waffle-trail-caret { transition: none; }
    }

    /* Level 2: the records themselves. The rule down the left is what makes the
       indent read as an OUTLINE rather than as rows that happen to be inset. */
    .waffle-trail-records {
        margin: 0 0 4px 17px;
        padding-left: 10px;
        border-left: 2px solid var(--border-soft);
    }
    .waffle-trail-record {
        display: flex;
        align-items: baseline;
        gap: 8px;
        padding: 5px 6px;
        border-radius: 6px;
        text-decoration: none;
        color: var(--text);
        font-size: 13px;
    }
    .waffle-trail-record:hover { background: var(--surface-hover); }
    .waffle-trail-label {
        flex: 1 1 auto;
        /* A subject can be a paragraph. One line, clipped — the timestamp beside
           it must never be pushed off the edge of the drawer. */
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .waffle-trail-record .waffle-trail-when { font-size: 11px; }

    /* The sticky footer: search across your own trail. NOT a global search —
       the ⌘K palette already searches every record, and a second one here would
       be the worse copy of it. This filters what is in front of you. */
    .waffle-trail-search {
        flex: 0 0 auto;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid var(--border-soft);
    }
    .waffle-trail-search input {
        width: 100%;
        box-sizing: border-box;
        padding: 8px 10px;
        border: 1px solid var(--border-soft);
        border-radius: 6px;
        background: var(--surface);
        color: var(--text);
        font-size: 13px;
        font-family: inherit;
    }
    .waffle-trail-search input:focus {
        outline: none;
        border-color: var(--primary, #2563eb);
    }
    .waffle-trail-empty {
        padding: 18px 6px;
        font-size: 12px;
        line-height: 1.5;
        color: var(--text-muted);
        text-align: center;
    }

    .waffle-module-link {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 12px 8px;
        border-radius: 8px;
        text-decoration: none;
        color: var(--text);
        transition: background-color 0.15s;
    }

    .waffle-module-link:hover {
        background-color: var(--surface-hover);
    }

    .waffle-module-link.current {
        background-color: var(--accent-soft);
    }

    .waffle-module-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 8px;
        /* Idle shadow is present-but-invisible so hover only animates its
           spread/opacity — no shadow "pop-in" on the first frame. */
        box-shadow: 0 0 0 rgba(0, 0, 0, 0);
        transition: transform 0.16s cubic-bezier(0.34, 1.4, 0.64, 1), box-shadow 0.16s ease;
    }

    .waffle-module-link:hover .waffle-module-icon,
    .waffle-module-link:focus-visible .waffle-module-icon {
        transform: translateY(-2px) scale(1.09);
        box-shadow: 0 5px 12px rgba(0, 0, 0, 0.22);
    }

    /* Settle back down while the click is held. */
    .waffle-module-link:active .waffle-module-icon {
        transform: translateY(-1px) scale(1.03);
        transition-duration: 0.06s;
    }

    .waffle-module-icon svg {
        width: 24px;
        height: 24px;
        color: #fff;
    }

    @media (prefers-reduced-motion: reduce) {
        .waffle-module-icon { transition: none; }
        .waffle-module-link:hover .waffle-module-icon,
        .waffle-module-link:focus-visible .waffle-module-icon,
        .waffle-module-link:active .waffle-module-icon { transform: none; }
    }

    <?php foreach (getModuleColors() as $key => $c): ?>
    .waffle-module-icon.<?php echo $key; ?> { background: linear-gradient(135deg, <?php echo $c[0]; ?>, <?php echo $c[1]; ?>); }
    <?php endforeach; ?>

    .waffle-module-name {
        font-size: 12px;
        font-weight: 500;
        text-align: center;
    }

    /* Overlay to close waffle menu when clicking outside */
    .waffle-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 999;
        display: none;
    }

    .waffle-overlay.active {
        display: block;
    }

    /* Module title in header */
    .module-title {
        font-size: 14px;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.9);
        margin-right: 20px;
        padding: 6px 12px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
    }

    /* Module header colors */
    <?php foreach (getModuleColors() as $key => $c): ?>
    .header.<?php echo $key; ?>-header { background: linear-gradient(135deg, <?php echo $c[0]; ?>, <?php echo $c[1]; ?>); }
    <?php endforeach; ?>

    /* Dark palettes: lay a translucent black wash over the (per-module) coloured
       header via an inset box-shadow, so it reads as dark while keeping a hint of
       the module's colour. One rule covers every module's header; the nav content
       sits above the wash, so labels/icons stay crisp. */
    [data-theme="dark"] .header {
        box-shadow: inset 0 0 0 2000px rgba(0, 0, 0, 0.55), 0 2px 4px rgba(0, 0, 0, 0.4);
    }

    /* Drawer close button — desktop hidden, revealed on mobile below. */
    .waffle-close { display: none; }

    /* ====================================================================
       Mobile: the waffle DROPDOWN becomes a full-height left slide-in DRAWER,
       on every page that uses the shared header (not just the tickets inbox,
       where mobile.css used to carry these rules). Above 768px none of this
       applies, so the desktop dropdown is unchanged. Kept in the DOM and slid
       in via transform; visibility:hidden while closed so the off-screen panel
       can't be tapped or add horizontal scroll.
       ==================================================================== */
    @media (max-width: 768px) {
        .waffle-panel {
            position: fixed;
            top: 0;
            left: 0;
            margin-top: 0;
            height: 100vh;
            height: 100dvh;             /* accounts for mobile browser chrome */
            width: 86vw;
            max-width: 360px;
            min-width: 0;
            border-radius: 0;
            padding: 14px 14px calc(16px + env(safe-area-inset-bottom, 0px));
            overflow-y: auto;
            box-shadow: 4px 0 30px rgba(0, 0, 0, 0.35);
            display: block;
            visibility: hidden;
            transform: translateX(-100%);
            transition: transform 0.24s ease, visibility 0.24s;
            z-index: 3000;
        }
        .waffle-panel.active { visibility: visible; transform: translateX(0); }

        /* Header becomes a row with the title + a tap-friendly close button. */
        .waffle-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 16px;
            margin-bottom: 12px;
            padding-bottom: 12px;
        }
        .waffle-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            margin: -6px -6px -6px 0;
            border: none;
            background: none;
            font-size: 26px;
            line-height: 1;
            color: var(--text-muted);
            cursor: pointer;
            border-radius: 8px;
        }
        .waffle-close:hover { background: var(--surface-hover); }

        /* Adaptive columns + roomier tap targets. */
        .waffle-modules {
            grid-template-columns: repeat(auto-fill, minmax(84px, 1fr));
            gap: 6px;
        }
        .waffle-module-link { padding: 12px 6px; border-radius: 12px; }
        .waffle-module-icon { width: 44px; height: 44px; }
        .waffle-module-name { font-size: 12px; }

        /* Dim backdrop behind the drawer (transparent click-catcher on desktop). */
        .waffle-overlay { z-index: 2999; }
        .waffle-overlay.active { background: rgba(0, 0, 0, 0.4); }

        /* ----------------------------------------------------------------
           Recent trail on a phone. The drawer is already full height here, so
           the scroll moves OUT of the panel and INTO whichever pane is open —
           which is what pins the trail's search box to the bottom of the screen
           instead of letting it scroll away with the list above it.

           ⚠️ `overflow: hidden` on the panel is why the panes must own their own
           scrolling. A container that clips cannot report an overflow, so if the
           panes ever lose `overflow-y: auto` the modules grid silently becomes
           unreachable below the fold rather than failing visibly.
           ---------------------------------------------------------------- */
        /* 🔴 `.waffle-panel.active`, not `.waffle-panel`. A MEDIA QUERY ADDS NO
           SPECIFICITY, so the desktop rule `.waffle-panel.active { display:block }`
           (two classes) outranks a one-class rule here however far down the file
           it sits — the drawer silently stayed block, the pane never became a
           flex column, and the search box fell 53px past the bottom edge where
           the panel's own `overflow: hidden` hid it completely. Measuring the
           BODY said everything was fine, because a container that clips cannot
           report an overflow. */
        .waffle-panel { overflow: hidden; }
        .waffle-panel.active {
            display: flex;
            flex-direction: column;
        }
        .waffle-panel-header,
        .waffle-tabs { flex: 0 0 auto; }
        .waffle-tabpanel.active {
            flex: 1 1 auto;
            min-height: 0;
        }
        #waffleTabModules.active { overflow-y: auto; }
        /* The height cap is a desktop dropdown's problem; here the drawer is the
           height, and the pane simply fills what is left of it. */
        #waffleTabRecent.active { max-height: none; }
        .waffle-tab { padding: 10px; font-size: 14px; }
        .waffle-trail-record { padding: 9px 6px; }
        .waffle-trail-head { padding: 9px 6px; }
        /* 16px, not 13 — anything smaller makes iOS Safari zoom on focus, and
           the zoom does not come back out. That drops the whole phone layout. */
        .waffle-trail-search input { font-size: 16px; padding: 10px 12px; }
        .waffle-trail-search {
            padding-bottom: calc(4px + env(safe-area-inset-bottom, 0px));
        }
    }
</style>

<div class="waffle-overlay" id="waffleOverlay" onclick="closeWaffleMenu()"></div>

<!-- Waffle Menu Button and Panel - to be placed inside .waffle-menu-container -->
<?php
/**
 * Output the waffle menu button and panel
 */
function renderWaffleMenuButton() {
    ?>
    <button class="waffle-btn" onclick="toggleWaffleMenu()" title="<?php echo htmlspecialchars(t('common.waffle.title')); ?>">
        <div class="waffle-icon">
            <span></span><span></span><span></span>
            <span></span><span></span><span></span>
            <span></span><span></span><span></span>
        </div>
    </button>
    <?php
}

function renderWaffleMenuPanel($modules, $current_module, $path_prefix) {
    $allowed = $_SESSION['allowed_modules'] ?? null;
    ?>
    <div class="waffle-panel" id="wafflePanel">
        <div class="waffle-panel-header">
            <span id="wafflePanelTitle"
                  data-title-modules="<?php echo htmlspecialchars(t('common.waffle.title')); ?>"
                  data-title-recent="<?php echo htmlspecialchars(t('common.waffle.tab_recent')); ?>"><?php echo htmlspecialchars(t('common.waffle.title')); ?></span>
            <button type="button" class="waffle-close" onclick="closeWaffleMenu()" aria-label="Close">&times;</button>
        </div>
        <!--
            Two panes: the module launcher this drawer has always been, and the
            recent trail (#124). The trail markup ships EMPTY and is filled the
            first time the tab is opened — this drawer is on all 91 screens, and
            resolving a trail into every page render would put that work on every
            page load in the product to serve a pane most of them never open.
        -->
        <div class="waffle-tabs" role="tablist">
            <button type="button" class="waffle-tab active" role="tab" aria-selected="true"
                    aria-controls="waffleTabModules" data-waffle-tab="modules"
                    onclick="waffleShowTab('modules')"><?php echo htmlspecialchars(t('common.waffle.tab_modules')); ?></button>
            <button type="button" class="waffle-tab" role="tab" aria-selected="false"
                    aria-controls="waffleTabRecent" data-waffle-tab="recent"
                    onclick="waffleShowTab('recent')"><?php echo htmlspecialchars(t('common.waffle.tab_recent')); ?></button>
        </div>

        <div class="waffle-tabpanel active" id="waffleTabModules" role="tabpanel">
        <div class="waffle-modules">
            <?php foreach ($modules as $key => $module):
                // System visibility is governed by admin status alone (not the per-analyst
                // module list) — so an admin with module restrictions still sees it, and a
                // non-admin never does. All other modules honour the allowed-modules list.
                if ($key === 'system') {
                    if (!sessionIsAdmin()) continue;
                } elseif ($allowed !== null && !in_array($key, $allowed)) {
                    continue;
                }
            ?>
            <a href="<?php echo BASE_URL . $module['path']; ?>" class="waffle-module-link <?php echo $key === $current_module ? 'current' : ''; ?>">
                <div class="waffle-module-icon <?php echo $key; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <?php echo $module['icon']; ?>
                    </svg>
                </div>
                <span class="waffle-module-name"><?php echo $module['name']; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        </div>

        <div class="waffle-tabpanel" id="waffleTabRecent" role="tabpanel">
            <div class="waffle-trail" id="waffleTrail">
                <div class="waffle-trail-empty"><?php echo htmlspecialchars(t('common.waffle.trail_loading')); ?></div>
            </div>
            <div class="waffle-trail-search">
                <input type="search" id="waffleTrailSearch" autocomplete="off"
                       placeholder="<?php echo htmlspecialchars(t('common.waffle.trail_search')); ?>"
                       aria-label="<?php echo htmlspecialchars(t('common.waffle.trail_search')); ?>"
                       oninput="waffleTrailFilter(this.value)">
            </div>
        </div>
    </div>
    <?php
}

function renderWaffleMenuJS() {
    // Pre-fetch the analyst's toast notification preferences so toast.js
    // doesn't have to AJAX for them on every page. Keys mirror the
    // ones the preferences page writes to (toast_position,
    // toast_animation). Defaults match toast.js's built-in fallbacks.
    //
    // notification_sound rides along on the same query rather than adding a
    // second one: it is read on every analyst page for exactly the same reason
    // (the bell must not have to ask before it can chime).
    $toastPos = 'bottom-right';
    $toastAnim = 'slide';
    $notifSound = 'off';                // silence unless this analyst asked for a chime
    if (isset($_SESSION['analyst_id'])) {
        try {
            if (!function_exists('connectToDatabase')) {
                require_once __DIR__ . '/functions.php';
            }
            $conn = connectToDatabase();
            $stmt = $conn->prepare(
                "SELECT preference_key, preference_value FROM user_preferences
                 WHERE analyst_id = ? AND preference_key IN ('toast_position', 'toast_animation', 'notification_sound')"
            );
            $stmt->execute([(int)$_SESSION['analyst_id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['preference_key'] === 'toast_position' && $row['preference_value']) {
                    $toastPos = $row['preference_value'];
                } elseif ($row['preference_key'] === 'toast_animation' && $row['preference_value']) {
                    $toastAnim = $row['preference_value'];
                } elseif ($row['preference_key'] === 'notification_sound' && $row['preference_value']) {
                    $notifSound = $row['preference_value'];
                }
            }
        } catch (Exception $e) {
            // Defaults stand
        }
    }
    ?>
    <!-- Notification chime (per-analyst, off by default). The bell and the
         war-room alerts both call window.playNotificationSound(); the value
         below is what decides whether anything is heard. -->
    <script>window.NOTIFICATION_SOUND = <?php echo json_encode($notifSound); ?>;</script>
    <script src="<?php echo BASE_URL; ?>assets/js/notification-sound.js?v=1"></script>
    <!-- App-wide notification primitives (#451). showToast + showConfirm are
         available on every page that includes the waffle menu (i.e. every
         analyst-facing module page). Individual pages no longer need their
         own <script src="toast.js"> tag. -->
    <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/confirm.js?v=3"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/clipboard.js?v=1"></script>
    <?php
    // Command palette (#932). ⌘/Ctrl-K launcher on every analyst page. We hand
    // it BASE_URL plus the module list already filtered to what this analyst may
    // see — the same visibility rule the waffle panel applies above (system is
    // admin-only; every other module honours allowed_modules) — so the palette
    // can never offer a destination the launcher wouldn't.
    // $modules is defined at this file's top level (global scope) when a header
    // requires it; pull it in here since we're inside a function.
    global $modules;
    $cpAllowed = $_SESSION['allowed_modules'] ?? null;
    $cpModules = [];
    if (isset($modules) && is_array($modules)) {
        foreach ($modules as $cpKey => $cpMod) {
            if ($cpKey === 'system') {
                if (!sessionIsAdmin()) continue;
            } elseif ($cpAllowed !== null && !in_array($cpKey, $cpAllowed)) {
                continue;
            }
            $cpModules[] = [
                'key'  => $cpKey,
                'name' => $cpMod['name'],
                'path' => $cpMod['path'],
                'icon' => $cpMod['icon'],
            ];
        }
    }
    ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/command-palette.css?v=1">
    <script>
        window.CP_BASE = <?php echo json_encode(BASE_URL); ?>;
        window.CP_MODULES = <?php echo json_encode($cpModules, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/command-palette.js?v=7"></script>
    <?php
    // The palette can return documents, and a document row offers an ⓘ that opens
    // the "attached to" dialogue — which lives in the documents component. The
    // palette is on every page, so the component has to be too. Emitted with
    // BASE_URL (absolute), and documentsPanelAssets() is guarded per request, so
    // a module page that also renders a panel does not load it twice.
    require_once __DIR__ . '/documents_panel.php';
    documentsPanelAssets(BASE_URL);
    ?>
    <script>
    // Per-analyst toast preferences pushed from PHP — toast.js reads
    // these before falling back to localStorage / default.
    window.TOAST_POSITION  = <?php echo json_encode($toastPos); ?>;
    window.TOAST_ANIMATION = <?php echo json_encode($toastAnim); ?>;

    function toggleWaffleMenu() {
        const panel = document.getElementById('wafflePanel');
        const overlay = document.getElementById('waffleOverlay');
        const isActive = panel.classList.contains('active');

        if (isActive) {
            closeWaffleMenu();
        } else {
            panel.classList.add('active');
            overlay.classList.add('active');
        }
    }

    function closeWaffleMenu() {
        document.getElementById('wafflePanel').classList.remove('active');
        document.getElementById('waffleOverlay').classList.remove('active');
    }

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeWaffleMenu();
        }
    });

    /* ====================================================================
       THE RECENT TRAIL (#124)

       An outline of the records this analyst has opened, grouped under the
       module each run of them happened in — the way headings and body text sit
       in a word processor. Three tickets read one after another are one
       "Tickets" heading with three rows beneath it; a detour into Knowledge
       opens its own heading; coming BACK to tickets opens a SECOND Tickets
       heading rather than reopening the first. That repetition is the whole
       point — it is what turns a list of records into a picture of an
       afternoon's work.

       🔑 THE HEADINGS BORROW THEIR IDENTITY FROM THE TILES ABOVE. Icon, colour,
       and translated name are cloned out of the module grid in the other pane
       rather than shipped again by the API. That keeps one definition of what
       "Tickets" looks like, gets the analyst's own language for free, and means
       a module whose tile is absent (no access) also has no heading — which is
       the same answer the server-side gate has already given for its records.
       ==================================================================== */
    window.WAFFLE_TRAIL_TEXT = <?php echo json_encode([
        'empty'       => t('common.waffle.trail_empty'),
        'noMatches'   => t('common.waffle.trail_no_matches'),
        'unavailable' => t('common.waffle.trail_unavailable'),
        'loading'     => t('common.waffle.trail_loading'),
    ], JSON_UNESCAPED_UNICODE); ?>;

    var waffleTrailLoaded = false;

    /**
     * "I have just opened this record" — call it from any module that opens one
     * WITHOUT a page load, which is most of them.
     *
     * 🔑 IT LIVES HERE BECAUSE THE WAFFLE IS ON ALL 91 SCREENS. That makes this
     * the one place a helper can be defined once and be callable from every
     * module's own JS without another script tag on every page.
     *
     * Fire-and-forget by design: nothing on the page waits for it, a failure is
     * swallowed, and `keepalive` lets the ping survive the navigation that
     * sometimes immediately follows it. Repeats are cheap — opening the same
     * record twice in a row moves a timestamp rather than adding a row, so a
     * module may call this as often as it likes without polluting the trail.
     */
    window.trailVisit = function (type, id) {
        id = parseInt(id, 10);
        if (!type || !id || id <= 0) return;
        try {
            fetch('<?php echo BASE_URL; ?>api/system/recent_trail_visit.php', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: type, id: id })
            }).catch(function () {});
        } catch (e) { /* never breaks the page it is hung off */ }

        // The drawer may already be showing a trail from before this visit. Drop
        // the cache so the next open re-reads it, rather than quietly showing a
        // list that is missing the record you are looking at right now.
        waffleTrailLoaded = false;
    };

    function waffleShowTab(name) {
        document.querySelectorAll('.waffle-tab').forEach(function (b) {
            var on = b.getAttribute('data-waffle-tab') === name;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        // The drawer names what it is showing. "ITSM Modules" sitting above an
        // open Recent pane reads as a mislabelled window; both strings are
        // rendered by PHP so this stays translated.
        var title = document.getElementById('wafflePanelTitle');
        if (title) title.textContent = title.getAttribute('data-title-' + name) || title.textContent;

        var modules = document.getElementById('waffleTabModules');
        var recent  = document.getElementById('waffleTabRecent');
        if (modules) modules.classList.toggle('active', name === 'modules');
        if (recent)  recent.classList.toggle('active', name === 'recent');

        // Fetched on FIRST open only, and never on page load: this drawer is on
        // every screen in the product, and a trail nobody looks at should cost
        // nothing at all.
        if (name === 'recent' && !waffleTrailLoaded) {
            waffleTrailLoaded = true;
            waffleTrailLoad();
        }
    }

    function waffleTrailLoad() {
        fetch('<?php echo BASE_URL; ?>api/system/recent_trail.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) { waffleTrailMessage(window.WAFFLE_TRAIL_TEXT.empty); return; }
                if (d.unavailable)    { waffleTrailMessage(window.WAFFLE_TRAIL_TEXT.unavailable); return; }
                waffleTrailRender(d.groups || []);
            })
            .catch(function () {
                // A failed fetch must not render as "you have looked at nothing":
                // an empty state is a claim about the analyst's history, and a
                // dropped request is not evidence for it.
                waffleTrailMessage(window.WAFFLE_TRAIL_TEXT.unavailable);
                waffleTrailLoaded = false;   // let the next open try again
            });
    }

    function waffleTrailMessage(text) {
        var box = document.getElementById('waffleTrail');
        if (!box) return;
        box.textContent = '';
        var p = document.createElement('div');
        p.className = 'waffle-trail-empty';
        p.textContent = text;
        box.appendChild(p);
    }

    function waffleTrailRender(groups) {
        var box = document.getElementById('waffleTrail');
        if (!box) return;
        box.textContent = '';
        if (!groups.length) { waffleTrailMessage(window.WAFFLE_TRAIL_TEXT.empty); return; }

        groups.forEach(function (g, i) {
            var tile = waffleModuleTile(g.module);

            var group = document.createElement('div');
            group.className = 'waffle-trail-group';
            // ⭐ The newest run is open and everything above it is closed. Without
            // this the pane is a wall of rows and the outline gets in the way of
            // the one thing it is for — which is a short hop back to what you
            // were just doing.
            if (i > 0) group.classList.add('collapsed');

            var head = document.createElement('button');
            head.type = 'button';
            head.className = 'waffle-trail-head';
            head.setAttribute('aria-expanded', i === 0 ? 'true' : 'false');
            head.onclick = function () {
                var closed = group.classList.toggle('collapsed');
                head.setAttribute('aria-expanded', closed ? 'false' : 'true');
            };

            var caret = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            caret.setAttribute('class', 'waffle-trail-caret');
            caret.setAttribute('width', '12');
            caret.setAttribute('height', '12');
            caret.setAttribute('viewBox', '0 0 24 24');
            caret.setAttribute('fill', 'none');
            caret.setAttribute('stroke', 'currentColor');
            caret.setAttribute('stroke-width', '3');
            caret.setAttribute('stroke-linecap', 'round');
            caret.setAttribute('stroke-linejoin', 'round');
            var caretPath = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
            caretPath.setAttribute('points', '6 9 12 15 18 9');
            caret.appendChild(caretPath);
            head.appendChild(caret);

            var icon = document.createElement('div');
            // Reuses the tile's own gradient class, so a module is the same
            // colour here as it is everywhere else it appears.
            icon.className = 'waffle-trail-icon waffle-module-icon ' + g.module;
            if (tile && tile.svg) icon.appendChild(tile.svg.cloneNode(true));
            head.appendChild(icon);

            var name = document.createElement('span');
            name.className = 'waffle-trail-name';
            name.textContent = tile ? tile.name : g.module;
            head.appendChild(name);

            var when = document.createElement('span');
            when.className = 'waffle-trail-when';
            when.textContent = waffleTrailWhen(g.latest);
            when.title = waffleTrailFull(g.latest);
            head.appendChild(when);

            group.appendChild(head);

            var list = document.createElement('div');
            list.className = 'waffle-trail-records';
            (g.records || []).forEach(function (rec) {
                var a = document.createElement('a');
                a.className = 'waffle-trail-record';
                a.href = '<?php echo BASE_URL; ?>' + rec.url;

                var label = document.createElement('span');
                label.className = 'waffle-trail-label';
                // textContent, not innerHTML: a ticket subject is whatever a
                // requester typed into an email.
                label.textContent = rec.label;
                label.title = rec.label;
                a.appendChild(label);

                var t = document.createElement('span');
                t.className = 'waffle-trail-when';
                t.textContent = waffleTrailWhen(rec.visited);
                t.title = waffleTrailFull(rec.visited);
                a.appendChild(t);

                // Lower-cased once here so filtering does not re-do it per keystroke.
                a.setAttribute('data-trail-search', (rec.label || '').toLowerCase());
                list.appendChild(a);
            });
            group.appendChild(list);
            box.appendChild(group);
        });
    }

    /** Icon, colour and translated name for a module — read from its own tile. */
    function waffleModuleTile(key) {
        var safe = (window.CSS && CSS.escape) ? CSS.escape(key) : key;
        var icon = document.querySelector('#waffleTabModules .waffle-module-icon.' + safe);
        if (!icon) return null;
        var link = icon.closest('.waffle-module-link');
        var name = link ? link.querySelector('.waffle-module-name') : null;
        return {
            svg:  icon.querySelector('svg'),
            name: name ? name.textContent.trim() : key
        };
    }

    /**
     * Filter the trail — YOUR history, not every record in the product. The ⌘K
     * palette already searches all records and a second, weaker copy of it here
     * would be the wrong tool in the right place.
     *
     * A group survives if any record in it matches, and is forced OPEN so the
     * match is visible: a collapsed heading that happens to contain the answer is
     * indistinguishable from one that does not.
     */
    function waffleTrailFilter(term) {
        var box = document.getElementById('waffleTrail');
        if (!box) return;
        var q = (term || '').trim().toLowerCase();
        var groups = box.querySelectorAll('.waffle-trail-group');
        if (!groups.length) return;

        var visible = 0;
        groups.forEach(function (group, i) {
            var hits = 0;
            group.querySelectorAll('.waffle-trail-record').forEach(function (rec) {
                var hit = !q || (rec.getAttribute('data-trail-search') || '').indexOf(q) !== -1;
                rec.hidden = !hit;
                if (hit) hits++;
            });
            group.hidden = hits === 0;
            if (hits) visible++;
            // Searching opens everything that matched; clearing the box puts the
            // pane back exactly as it was found — newest open, the rest closed.
            group.classList.toggle('collapsed', q ? false : i > 0);
            var head = group.querySelector('.waffle-trail-head');
            if (head) head.setAttribute('aria-expanded', group.classList.contains('collapsed') ? 'false' : 'true');
        });

        var none = box.querySelector('.waffle-trail-none');
        if (!visible && q) {
            if (!none) {
                none = document.createElement('div');
                none.className = 'waffle-trail-empty waffle-trail-none';
                none.textContent = window.WAFFLE_TRAIL_TEXT.noMatches;
                box.appendChild(none);
            }
        } else if (none) {
            none.remove();
        }
    }

    /** A trail is read in glances, so the stamp is relative near the top and a
     *  plain date once it is old enough for "3 days ago" to stop meaning much. */
    function waffleTrailWhen(iso) {
        var d = new Date(iso);
        if (isNaN(d)) return '';
        var mins = Math.floor((Date.now() - d.getTime()) / 60000);
        if (mins < 1)  return 'now';
        if (mins < 60) return mins + ' min ago';

        var opts = { hour: '2-digit', minute: '2-digit' };
        if (window.USER_TIMEZONE) opts.timeZone = window.USER_TIMEZONE;
        var clock = d.toLocaleTimeString([], opts);

        var days = waffleTrailDayGap(d);
        if (days === 0) return clock;
        if (days === 1) return 'Yesterday ' + clock;

        var dOpts = { day: 'numeric', month: 'short' };
        if (window.USER_TIMEZONE) dOpts.timeZone = window.USER_TIMEZONE;
        return d.toLocaleDateString([], dOpts);
    }

    /** Whole days between then and now IN THE READER'S ZONE — "yesterday" is a
     *  question about calendar days, not about how many hours have passed. */
    function waffleTrailDayGap(d) {
        var opts = { year: 'numeric', month: '2-digit', day: '2-digit' };
        if (window.USER_TIMEZONE) opts.timeZone = window.USER_TIMEZONE;
        var key = function (x) { return x.toLocaleDateString('en-CA', opts); };
        var a = new Date(key(d) + 'T00:00:00Z').getTime();
        var b = new Date(key(new Date()) + 'T00:00:00Z').getTime();
        return Math.round((b - a) / 86400000);
    }

    function waffleTrailFull(iso) {
        var d = new Date(iso);
        if (isNaN(d)) return '';
        if (window.fmtDateTime) return window.fmtDateTime(iso);   // tz.js, where it is loaded
        var opts = { dateStyle: 'medium', timeStyle: 'short' };
        if (window.USER_TIMEZONE) opts.timeZone = window.USER_TIMEZONE;
        return d.toLocaleString([], opts);
    }
    </script>
    <?php
}

/**
 * War room notifications — the bell in the header, on EVERY page.
 *
 * 🔑 WHY THIS IS NOT IN THE WAR ROOM MODULE. A mention is only worth anything if
 * it reaches somebody who is not looking at the war room; a badge that only shows
 * on the page you are already reading is decoration. This is one shared function
 * rather than 166 edits because every module header already calls into here.
 *
 * ⚠️ COST BUDGET, because this now runs app-wide. One request per analyst per 60
 * seconds, one indexed lookup, nothing rendered at all when the count is zero, no
 * request while the tab is hidden, and NO request on the war room page itself —
 * that page already polls every 3 seconds and would otherwise ask twice. During an
 * incident every page is loaded and the server is at its busiest, which is the
 * same reasoning that ruled out SSE for the chat.
 *
 * Degrades to nothing: an analyst without the module gets an empty answer rather
 * than a 403, and any failure renders no bell rather than breaking the page it is
 * embedded in.
 */
/**
 * The global notification bell (discussion #55).
 *
 * Rendered from the waffle menu, which is on every module's header — which is
 * what makes it module-independent without any module knowing about it.
 *
 * Polls a COUNT for the badge and only fetches the list when the panel opens:
 * this runs in every open tab of every analyst, so the idle cost is the cost
 * that matters, not the cost of opening it.
 */
function renderNotificationBell($path_prefix) {
    if (!isset($_SESSION['analyst_id'])) return;
    ?>
    <style>
        .nb-wrap { position: relative; margin-right: 6px; }
        .nb-btn {
            display: flex; align-items: center;
            background: none; border: none;
            color: rgba(255,255,255,0.75);
            cursor: pointer; padding: 4px 6px; border-radius: 4px; position: relative;
            transition: color 150ms ease, background 150ms ease, transform 140ms cubic-bezier(0.23,1,0.32,1);
        }
        .nb-btn:hover { color: #fff; background: rgba(255,255,255,0.1); }
        .nb-btn:active { transform: scale(0.94); }
        .nb-count {
            position: absolute; top: -1px; right: -2px;
            min-width: 16px; padding: 0 4px; border-radius: 8px;
            background: #dc2626; color: #fff;
            font-size: 10px; font-weight: 700; line-height: 16px; text-align: center;
        }
        .nb-count[hidden] { display: none; }
        .nb-panel {
            display: none; position: absolute; top: 34px; right: 0;
            width: 360px; max-height: 60vh; overflow-y: auto;
            background: var(--surface, #fff); color: var(--text, #333);
            border: 1px solid var(--border, #e0e0e0); border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            z-index: 2000; text-align: left;
        }
        .nb-panel.open { display: block; }
        .nb-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 14px; border-bottom: 1px solid var(--border, #eee);
            font-size: 12px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.4px; color: var(--text-muted, #666);
            position: sticky; top: 0; background: var(--surface, #fff);
        }
        .nb-markall {
            border: none; background: none; cursor: pointer;
            font-size: 11px; text-transform: none; letter-spacing: 0;
            color: var(--accent, #0078d4); font-weight: 600;
        }
        .nb-markall:hover { text-decoration: underline; }
        .nb-head-actions { display: flex; align-items: center; gap: 10px; }
        /* Clear is destructive, so it does not get the accent colour that says
           "safe primary action" on Mark all read. */
        .nb-clearall { color: var(--text-muted, #666); }
        .nb-clearall:hover { color: #c62828; }
        .nb-item {
            display: block; width: 100%; padding: 10px 14px;
            border: none; border-bottom: 1px solid var(--border-soft, #f2f2f2);
            background: none; color: inherit; font: inherit; text-align: left;
            cursor: pointer; text-decoration: none;
        }
        .nb-item:hover { background: var(--surface-hover, #f6f6f6); }
        /* Unread carries a left edge rather than a bold background: the panel is
           mostly unread, so colouring them all just makes a solid block. */
        .nb-item.unread { border-left: 3px solid var(--accent, #0078d4); padding-left: 11px; }
        .nb-item-top { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 2px; }
        .nb-item-ref { font-size: 12px; font-weight: 600; color: var(--accent, #0078d4); }
        /* The age sits on the BOTTOM row, hard right, rather than up beside the
           reference. Against a variable-length ticket number it never landed in
           the same place twice, so a column of rows had timestamps scattered
           across the panel. Pinned to the right edge they line up into a column
           you can read down. */
        .nb-item-bottom { display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; }
        .nb-item-meta { flex: none; font-size: 11px; color: var(--text-dim, #999); white-space: nowrap; }
        /* Not a <button>: the whole row is an <a>, and a button inside an anchor
           is invalid HTML that browsers re-parent, which breaks the row. */
        /* ⚠️ ALWAYS VISIBLE, and drawn as a real button — a bordered circle, not a
           bare glyph. It started as a × that faded in on hover, which was legible
           but read as decoration rather than a control: a mark that appears only
           when the pointer is already over it never announces that it can be
           clicked. A circular outlined × is the universal "remove this one", and
           it needs no hover to say so. It also removes the desktop/touch split —
           there is no hover on a phone, so the reveal made it unreachable there. */
        .nb-clear {
            flex: none; display: flex; align-items: center; justify-content: center;
            width: 20px; height: 20px; margin: -1px -2px -1px 0;
            border: 1px solid var(--border, #e0e0e0); border-radius: 50%;
            background: var(--surface-2, #fafafa); color: var(--text-muted, #666);
            font-size: 13px; font-weight: 600; line-height: 1; cursor: pointer;
            transition: color 120ms ease, background 120ms ease, border-color 120ms ease;
        }
        /* Filling it red on hover is the confirmation that it destroys something —
           the same colour the Clear dialog's OK button uses. --danger does not
           exist as a token, so this is the literal confirm.js value. */
        .nb-clear:hover { background: #c62828; border-color: #c62828; color: #fff; }
        .nb-clear:focus-visible { outline: 2px solid var(--accent, #0078d4); outline-offset: 1px; }
        .nb-item-title { font-size: 13px; font-weight: 600; line-height: 1.35; overflow-wrap: anywhere; }
        .nb-item-body { flex: 1; min-width: 0; font-size: 12px; line-height: 1.4; color: var(--text-muted, #555); overflow-wrap: anywhere; }
        .nb-badge-count {
            display: inline-block; margin-left: 6px; padding: 0 5px;
            border-radius: 7px; background: var(--surface-hover, #eef1f4);
            color: var(--text-muted, #666); font-size: 10px; font-weight: 700; line-height: 15px;
        }
        .nb-empty { padding: 26px 14px; text-align: center; color: var(--text-muted, #666); font-size: 13px; }
        @media (max-width: 768px) {
            /* Same reasoning as the war-room panel: anything wider than the
               viewport makes iOS reflow the whole page to desktop. */
            .nb-panel { position: fixed; top: 48px; right: 4px; left: 4px; width: auto; max-height: 70vh; }
            .nb-btn { padding: 8px; }
            /* Same button, sized as a real tap target. */
            .nb-clear { width: 28px; height: 28px; margin: -4px -4px -4px 0; font-size: 16px; }
        }
    </style>

    <div class="nb-wrap">
        <button class="nb-btn" id="nbBtn" type="button" aria-label="<?php echo htmlspecialchars(t('common.notifications.aria')); ?>" title="<?php echo htmlspecialchars(t('common.notifications.title')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
            <span class="nb-count" id="nbCount" hidden>0</span>
        </button>
        <div class="nb-panel" id="nbPanel">
            <div class="nb-head">
                <span><?php echo htmlspecialchars(t('common.notifications.title')); ?></span>
                <span class="nb-head-actions">
                    <button type="button" class="nb-markall" id="nbMarkAll"><?php echo htmlspecialchars(t('common.notifications.mark_all')); ?></button>
                    <button type="button" class="nb-markall nb-clearall" id="nbClearAll"><?php echo htmlspecialchars(t('common.notifications.clear_all')); ?></button>
                </span>
            </div>
            <div id="nbList"></div>
        </div>
    </div>

    <script>
    (function () {
        const API = '<?php echo $path_prefix; ?>api/notifications/';
        const PREFIX = '<?php echo $path_prefix; ?>';
        const btn   = document.getElementById('nbBtn');
        const panel = document.getElementById('nbPanel');
        const badge = document.getElementById('nbCount');
        const list  = document.getElementById('nbList');
        if (!btn) return;

        const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
            ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));

        // Stored UTC without a zone marker; left as-is, Safari and Firefox read it
        // as local time and every notification looks hours old.
        function ago(utc) {
            if (!utc) return '';
            const then = new Date(String(utc).replace(' ', 'T') + 'Z');
            if (isNaN(then)) return '';
            const mins = Math.floor((Date.now() - then.getTime()) / 60000);
            if (mins < 1)  return window.t('common.notifications.just_now');
            if (mins < 60) return window.t('common.notifications.minutes', { n: mins });
            const hrs = Math.floor(mins / 60);
            if (hrs < 24)  return window.t('common.notifications.hours', { n: hrs });
            return window.t('common.notifications.days', { n: Math.floor(hrs / 24) });
        }

        function describe(n) {
            // Translated at render time, never baked into the row — otherwise a
            // notification written today reads forever in whoever wrote it's language.
            const key = 'common.notifications.event.' + n.event_type;
            const txt = window.t(key, { actor: n.actor_name || window.t('common.notifications.someone') });
            return txt === key ? (n.actor_name || '') : txt;
        }

        function render(items) {
            if (!items.length) {
                list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.empty')) + '</div>';
                return;
            }
            list.innerHTML = items.map(n => {
                const count = n.event_count > 1
                    ? '<span class="nb-badge-count">' + n.event_count + '</span>' : '';
                const ref = n.entity_ref ? esc(n.entity_ref) : '';
                return `<a class="nb-item${n.is_read ? '' : ' unread'}" href="${n.link ? esc(PREFIX + n.link) : '#'}"
                           data-id="${n.id}">
                    <div class="nb-item-top">
                        <span class="nb-item-ref">${ref}${count}</span>
                        <span class="nb-clear" role="button" tabindex="0"
                              title="${esc(window.t('common.notifications.clear_one'))}"
                              aria-label="${esc(window.t('common.notifications.clear_one'))}">&times;</span>
                    </div>
                    <div class="nb-item-title">${esc(n.title || '')}</div>
                    <div class="nb-item-bottom">
                        <span class="nb-item-body">${esc(describe(n))}</span>
                        <span class="nb-item-meta">${esc(ago(n.updated_datetime))}</span>
                    </div>
                </a>`;
            }).join('');
        }

        let lastUnread = 0;
        function paintBadge(unread) {
            lastUnread = unread;
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.hidden = unread === 0;
        }

        // ===== Chime (preference notification_sound, off by default) =====
        // The unread count is normally non-zero when a page loads, so the count
        // alone cannot say "something new arrived" — sounding on it would chime
        // on every navigation for notifications you were told about yesterday.
        // The last count seen is therefore kept per tab, and the first poll of a
        // tab only records a baseline. Private-mode browsers throw on
        // sessionStorage, which costs nothing worse than a silent chime.
        const SEEN_KEY = 'nbSeenUnread';
        function recordSeen(unread) {
            try { sessionStorage.setItem(SEEN_KEY, String(unread)); } catch (e) { /* ignore */ }
        }
        function chimeIfNew(unread) {
            let prev = null;
            try { prev = sessionStorage.getItem(SEEN_KEY); } catch (e) { /* ignore */ }
            recordSeen(unread);
            if (prev === null) return;                      // first poll in this tab
            if (unread > parseInt(prev, 10) && typeof window.playNotificationSound === 'function') {
                window.playNotificationSound();
            }
        }

        async function poll() {
            try {
                const d = await (await fetch(API + 'get_notifications.php?count_only=1')).json();
                if (d.success) { paintBadge(d.unread); chimeIfNew(d.unread); }
            } catch (e) { /* a failed poll is not worth telling anyone about */ }
        }

        async function open() {
            panel.classList.add('open');
            list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.loading')) + '</div>';
            try {
                const d = await (await fetch(API + 'get_notifications.php')).json();
                // Opening the panel re-baselines rather than chiming: you are
                // looking straight at the list, so anything in it is not news.
                if (d.success) { render(d.notifications || []); paintBadge(d.unread); recordSeen(d.unread); }
            } catch (e) {
                list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.load_failed')) + '</div>';
            }
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.classList.contains('open') ? panel.classList.remove('open') : open();
        });
        document.addEventListener('click', function (e) {
            if (!panel.contains(e.target) && !btn.contains(e.target)) panel.classList.remove('open');
        });

        // Follow the link AND mark read. Not awaited: making somebody wait on a
        // bookkeeping write before their ticket opens would be the wrong trade.
        list.addEventListener('click', function (e) {
            const item = e.target.closest('.nb-item');
            if (!item) return;
            const id = parseInt(item.dataset.id, 10);

            // The clear button sits INSIDE the row's anchor, so without stopping
            // the event here, clearing a notification would also navigate to the
            // ticket it was about.
            if (e.target.closest('.nb-clear')) {
                e.preventDefault();
                e.stopPropagation();
                if (id) clearOne(id, item);
                return;
            }

            if (id) {
                fetch(API + 'mark_read.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: [id] }), keepalive: true
                }).catch(() => {});
            }
        });

        // role="button" is a promise that Enter and Space work. The span is inside
        // an anchor, so Enter would otherwise follow the link instead.
        list.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const btn = e.target.closest('.nb-clear');
            if (!btn) return;
            const item = btn.closest('.nb-item');
            const id = item && parseInt(item.dataset.id, 10);
            e.preventDefault();
            e.stopPropagation();
            if (id) clearOne(id, item);
        });

        // Awaited, unlike mark-read: this one removes something from the screen,
        // so a row must not vanish before the server has agreed that it is gone.
        async function clearOne(id, item) {
            // Asks first, for the same reason Clear all does: the delete is real
            // and there is no undo. The X sits a few pixels from the row itself,
            // so a mis-aimed click would otherwise silently destroy the thing you
            // were reaching for. No tick box here — a single row has no narrower
            // and wider reading for one to choose between.
            const ok = await showConfirm({
                title:   window.t('common.notifications.clear_one_title'),
                message: window.t('common.notifications.clear_one_msg'),
                okLabel: window.t('common.notifications.clear_ok'),
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const d = await (await fetch(API + 'clear.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: [id] })
                })).json();
                if (!d.success) throw new Error(d.error || 'failed');
                item.remove();
                paintBadge(d.unread);
                // Re-baseline, or the chime goes quiet: chimeIfNew only fires when
                // the count rises above the stored figure, and clearing lowers it.
                recordSeen(d.unread);
                if (!list.querySelector('.nb-item')) {
                    list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.empty')) + '</div>';
                }
            } catch (err) {
                if (typeof window.showToast === 'function') {
                    window.showToast(window.t('common.notifications.clear_failed'), 'error');
                }
            }
        }

        document.getElementById('nbMarkAll').addEventListener('click', async function (e) {
            e.stopPropagation();
            try {
                const d = await (await fetch(API + 'mark_read.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ all: true })
                })).json();
                if (d.success) { paintBadge(d.unread); recordSeen(d.unread); open(); }
            } catch (err) { /* leave the panel as it is */ }
        });

        // Clear all deletes for good, so it asks first — and by default it spares
        // anything still unread. The tick box is the safety catch: emptying the
        // panel should not be able to bin news nobody has looked at yet, unless
        // that is deliberately asked for.
        document.getElementById('nbClearAll').addEventListener('click', function (e) {
            e.stopPropagation();

            const unread = lastUnread;
            const opts = {
                title:    window.t('common.notifications.clear_title'),
                message:  unread > 0
                            ? window.t('common.notifications.clear_msg_read')
                            : window.t('common.notifications.clear_msg'),
                okLabel:  window.t('common.notifications.clear_ok'),
                okClass:  'danger',
                onConfirm: state => doClearAll(!!state.checked)
            };
            // No unread rows means there is nothing for the catch to protect, and
            // an option that cannot change the outcome is just a thing to read.
            if (unread > 0) {
                opts.checkbox = {
                    label: unread === 1
                        ? window.t('common.notifications.clear_unread_one')
                        : window.t('common.notifications.clear_unread', { n: unread }),
                    checked: false
                };
            }
            showConfirm(opts);
        });

        async function doClearAll(includeUnread) {
            try {
                const d = await (await fetch(API + 'clear.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ all: true, include_unread: includeUnread })
                })).json();
                if (!d.success) throw new Error(d.error || 'failed');
                paintBadge(d.unread);
                recordSeen(d.unread);
                // Everything unread and the box left unticked: the honest outcome
                // is that nothing happened, and saying so beats a panel that looks
                // like it ignored the click.
                if (d.cleared === 0 && typeof window.showToast === 'function') {
                    window.showToast(window.t('common.notifications.clear_nothing'), 'info');
                }
                open();
            } catch (err) {
                if (typeof window.showToast === 'function') {
                    window.showToast(window.t('common.notifications.clear_failed'), 'error');
                }
            }
        }

        poll();
        setInterval(poll, 60000);
    })();
    </script>
    <?php
}

function renderWarRoomAlerts($path_prefix) {
    if (!isset($_SESSION['analyst_id'])) return;

    // Desktop notifications are OFF unless this analyst turned them on. A per-user
    // preference rather than an install-wide switch, because whether a popup is
    // welcome or infuriating is a personal answer, not an administrator's.
    $__wraDesktop = false;
    try {
        if (!function_exists('connectToDatabase')) require_once __DIR__ . '/functions.php';
        $__s = connectToDatabase()->prepare(
            "SELECT preference_value FROM user_preferences
              WHERE analyst_id = :a AND preference_key = 'warroom_desktop_alerts' LIMIT 1"
        );
        $__s->execute([':a' => (int) $_SESSION['analyst_id']]);
        $__wraDesktop = ((string) $__s->fetchColumn()) === '1';
    } catch (Throwable $e) {
        $__wraDesktop = false;              // fail quiet, never noisy
    }
    ?>
    <script>window.WRA_DESKTOP = <?php echo $__wraDesktop ? 'true' : 'false'; ?>;</script>
    <style>
        .wra-wrap { position: relative; margin-right: 6px; }
        .wra-btn {
            display: none;              /* shown by JS only when something is waiting */
            align-items: center;
            background: none;
            border: none;
            color: rgba(255,255,255,0.75);
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 4px;
            position: relative;
        }
        .wra-btn:hover { color: #fff; background: rgba(255,255,255,0.1); }
        .wra-count {
            position: absolute;
            top: -1px; right: -2px;
            min-width: 16px;
            padding: 0 4px;
            border-radius: 8px;
            background: #ea580c;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            line-height: 16px;
            text-align: center;
        }
        .wra-panel {
            display: none;
            position: absolute;
            top: 34px; right: 0;
            width: 340px;
            max-height: 60vh;
            overflow-y: auto;
            background: var(--surface, #fff);
            color: var(--text, #333);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            z-index: 2000;
            text-align: left;
        }
        .wra-panel.open { display: block; }
        .wra-head {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border, #eee);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--text-muted, #666);
        }
        .wra-item {
            display: block;
            width: 100%;
            padding: 10px 14px;
            border: none;
            border-bottom: 1px solid var(--border-soft, #f2f2f2);
            background: none;
            color: inherit;
            font: inherit;
            text-align: left;
            cursor: pointer;
        }
        .wra-item:hover { background: var(--surface-hover, #f6f6f6); }
        .wra-item-top { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 2px; }
        .wra-item-chan { font-size: 12px; font-weight: 600; color: #ea580c; }
        .wra-item-meta { font-size: 11px; color: var(--text-dim, #999); }
        .wra-item-body { font-size: 13px; line-height: 1.4; color: var(--text-muted, #555); overflow-wrap: anywhere; }
        @media (max-width: 768px) {
            /* A 340px dropdown on a 360px screen would sit against both edges and
               overflow — and anything wider than the screen makes iOS reflow the
               whole page to desktop. Pin it to the viewport instead. */
            .wra-panel { position: fixed; top: 48px; right: 4px; left: 4px; width: auto; max-height: 70vh; }
            .wra-btn { padding: 8px; }
        }
    </style>

    <div class="wra-wrap">
        <button class="wra-btn" id="wraBtn" type="button" aria-label="War room notifications">
            <!-- Speech bubble, not a bell: the global notification bell (discussion
                 #55) now sits beside this one, and two identical bells in the same
                 header is a puzzle rather than a UI. This one is chat, and reads
                 more accurately as chat anyway. -->
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
            <span class="wra-count" id="wraCount">0</span>
        </button>
        <div class="wra-panel" id="wraPanel">
            <div class="wra-head" id="wraHead">War room</div>
            <div id="wraList"></div>
        </div>
    </div>

    <script>
    (function () {
        'use strict';
        var base  = <?php echo json_encode(($path_prefix ?: './') . 'api/war-room/'); ?>;
        var room  = <?php echo json_encode(($path_prefix ?: './') . 'war-room/'); ?>;
        // The war room polls for itself; asking again from the header would double
        // the traffic on the one page that least needs it.
        var onWarRoom = /\/war-room\//.test(window.location.pathname);
        var btn   = document.getElementById('wraBtn');
        var panel = document.getElementById('wraPanel');
        var list  = document.getElementById('wraList');
        var count = document.getElementById('wraCount');
        if (!btn || onWarRoom) return;

        var seen = parseInt(sessionStorage.getItem('wraSeenId') || '0', 10);

        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            // 🔒 textContent, never innerHTML — this renders other people's chat
            // messages into the header of every page in the application.
            if (text !== undefined) n.textContent = String(text);
            return n;
        }

        function draw(d) {
            btn.style.display = d.count > 0 ? 'flex' : 'none';
            count.textContent = d.count > 99 ? '99+' : d.count;
            if (!d.count) { panel.classList.remove('open'); return; }

            list.textContent = '';
            d.mentions.forEach(function (m) {
                var item = el('button', 'wra-item');
                item.type = 'button';
                var top = el('div', 'wra-item-top');
                top.appendChild(el('span', 'wra-item-chan', m.channel));
                top.appendChild(el('span', 'wra-item-meta', m.author));
                item.appendChild(top);
                item.appendChild(el('div', 'wra-item-body', m.snippet));
                item.addEventListener('click', function () {
                    window.location.href = room + '?channel=' + encodeURIComponent(m.channel_id);
                });
                list.appendChild(item);
            });

            // Desktop notification, if this analyst asked for one. Only for genuinely
            // new mentions — re-notifying on every poll for the same message would
            // train people to dismiss it, which is how a fallback tool stops working.
            var newest = d.mentions.length ? d.mentions[0].id : 0;
            if (newest > seen) {
                if (window.WRA_DESKTOP && 'Notification' in window && Notification.permission === 'granted') {
                    try {
                        new Notification(d.mentions[0].channel, { body: d.mentions[0].author + ': ' + d.mentions[0].snippet, tag: 'freeitsm-warroom' });
                    } catch (e) { /* a browser that refuses is not an error worth showing */ }
                }
                // Chime on the same trigger as the popup — one without the other
                // is the odd outcome. Guarded on seen > 0 so the first draw of a
                // tab only baselines: the desktop popup dedupes on its tag, a
                // sound would not, so opening four tabs would mean four chimes.
                if (seen > 0 && typeof window.playNotificationSound === 'function') {
                    window.playNotificationSound();
                }
                seen = newest;
                sessionStorage.setItem('wraSeenId', String(seen));
            }
        }

        function check() {
            if (document.hidden) return;
            fetch(base + 'alerts.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(draw)
                .catch(function () { /* never break the host page */ });
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.classList.toggle('open');
        });
        document.addEventListener('click', function () { panel.classList.remove('open'); });
        panel.addEventListener('click', function (e) { e.stopPropagation(); });

        check();
        setInterval(check, 60000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
    })();
    </script>
    <?php
}

function renderHeaderRight($analyst_name, $path_prefix) {
    // Extract initials from analyst name
    $parts = explode(' ', trim($analyst_name));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= strtoupper(substr(end($parts), 0, 1));
    }
    $analyst_username = $_SESSION['analyst_username'] ?? '';

    /**
     * Does this analyst also have a self-service portal account they could
     * actually sign in to? (discussion #81)
     *
     * An analyst and a requester are separate identities in separate tables:
     * the portal's guard wants $_SESSION['ss_user_id'] and an analyst session
     * only carries analyst_id. So a link offered unconditionally would drop
     * most analysts on the portal's sign-in page with credentials they do not
     * have — on a typical installation the great majority of analysts have no
     * `users` row at all.
     *
     * The test is deliberately "could sign in", not "a row exists". A portal
     * row auto-created from an inbound email has no password and no provider,
     * so its owner cannot get in either, and offering them the link would be
     * the same dead end wearing a match.
     *
     * Failure renders nothing. This is the header on every page, and a menu
     * entry is never worth an exception.
     */
    $__portalAccount = false;
    if (!empty($_SESSION['analyst_email'])) {
        try {
            if (!function_exists('connectToDatabase')) require_once __DIR__ . '/functions.php';
            $__pa = connectToDatabase()->prepare(
                "SELECT 1
                   FROM users u
              LEFT JOIN auth_providers p ON p.id = u.auth_provider_id
                  WHERE LOWER(u.email) = LOWER(?)
                    AND ( (u.password_hash IS NOT NULL AND u.password_hash <> '')
                       OR (p.id IS NOT NULL AND p.enabled = 1) )
                  LIMIT 1"
            );
            $__pa->execute([$_SESSION['analyst_email']]);
            $__portalAccount = (bool)$__pa->fetchColumn();
        } catch (Throwable $e) {
            $__portalAccount = false;
        }
    }

    // Company switcher (multi-tenancy). Captured defensively — it renders an
    // empty string unless a second company exists, so single-company installs
    // see no change to the header at all. Any error renders nothing.
    $__tenantSwitcherHtml = '';
    if (isset($_SESSION['analyst_id'])) {
        try {
            require_once __DIR__ . '/tenancy-switcher.php';
            if (!function_exists('connectToDatabase')) {
                require_once __DIR__ . '/functions.php';
            }
            ob_start();
            renderTenantSwitcher(connectToDatabase(), (int) $_SESSION['analyst_id']);
            $__tenantSwitcherHtml = ob_get_clean();
        } catch (Exception $e) {
            $__tenantSwitcherHtml = '';
        }
    }
    ?>
    <style>
        /* Avatar & User Menu */
        .header-right { position: relative; }

        .mail-check-btn {
            background: none;
            border: none;
            color: rgba(255,255,255,0.7);
            cursor: pointer;
            padding: 4px;
            margin-right: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            transition: color 0.15s, background 0.15s;
            position: relative;
        }

        .mail-check-btn:hover { color: #fff; background: rgba(255,255,255,0.1); }

        .mail-check-btn.checking svg {
            animation: mail-spin 1s linear infinite;
        }

        .mail-check-btn.checking { color: #80cbc4; }

        @keyframes mail-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #546e7a;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid rgba(255,255,255,0.3);
            transition: border-color 0.15s;
            user-select: none;
        }

        .user-avatar:hover {
            border-color: rgba(255,255,255,0.6);
        }

        .user-menu-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 1099;
            display: none;
        }

        .user-menu-overlay.active { display: block; }

        .user-menu {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--surface);
            border-radius: 8px;
            box-shadow: 0 6px 30px rgba(0,0,0,0.25);
            min-width: 240px;
            z-index: 1100;
            display: none;
            overflow: hidden;
        }

        .user-menu.active { display: block; }

        .user-menu-header {
            padding: 16px;
            border-bottom: 1px solid var(--border-soft);
        }

        .user-menu-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }

        .user-menu-username {
            font-size: 12px;
            color: var(--text-faint);
            margin-top: 2px;
        }

        .user-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 16px;
            cursor: pointer;
            font-size: 13px;
            color: var(--text);
            transition: background 0.15s;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
        }

        .user-menu-item:hover { background: var(--surface-hover); }

        .user-menu-item svg {
            width: 16px;
            height: 16px;
            color: var(--text-muted);
            flex-shrink: 0;
        }

        .user-menu-divider {
            height: 1px;
            background: var(--border-soft);
            margin: 0;
        }

        .user-menu-item.logout-item {
            color: var(--danger-accent);
        }

        .user-menu-item.logout-item svg { color: var(--danger-accent); }

        /* Palette / theme picker in the account menu */
        .user-menu-section-label {
            padding: 8px 16px 2px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-faint);
        }
        .theme-picker { padding: 2px 8px 8px; }
        .theme-swatch {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 7px 8px;
            border: none;
            background: none;
            border-radius: 6px;
            font-size: 13px;
            color: var(--text);
            text-align: left;
            cursor: pointer;
        }
        .theme-swatch:hover { background: var(--surface-hover); }
        .theme-swatch.active { background: var(--accent-soft); font-weight: 600; }
        .theme-swatch-dot {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 1px solid var(--border);
            flex-shrink: 0;
        }
        .theme-swatch-check { margin-left: auto; color: var(--accent); font-weight: 700; }
        /* Per-palette preview swatch — extend as palettes are added */
        .theme-swatch-default { background: #ffffff; }
        .theme-swatch-dark { background: #1e2228; }

        .mfa-badge {
            margin-left: auto;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 3px;
        }

        .mfa-badge.enabled { background: var(--success-bg); color: var(--success-text); }
        .mfa-badge.disabled { background: var(--surface-2); color: var(--text-faint); }

        /* Account modals */
        .account-modal {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .account-modal.active { display: flex; }

        .account-modal-box {
            background: var(--surface);
            border-radius: 8px;
            width: 90%;
            max-width: 460px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }

        .account-modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            font-size: 18px;
            font-weight: 600;
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .account-modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: var(--text-faint);
            font-size: 20px;
            line-height: 1;
        }

        .account-modal-close:hover { color: var(--text); }

        .account-modal-body { padding: 24px; }

        .account-modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .acct-form-group { margin-bottom: 16px; }

        .acct-form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--text);
            font-size: 13px;
        }

        .acct-form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit;
            background: var(--surface);
            color: var(--text);
        }

        .acct-form-input:focus { outline: none; border-color: var(--accent); }

        .acct-btn {
            padding: 9px 18px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
        }

        .acct-btn-primary { background: #546e7a; color: #fff; }
        .acct-btn-primary:hover { background: #455a64; }
        .acct-btn-secondary { background: var(--surface-2); color: var(--text); border: 1px solid var(--border); }
        .acct-btn-secondary:hover { background: var(--surface-hover); }
        .acct-btn-danger { background: var(--surface); color: var(--danger-accent); border: 1px solid var(--danger-accent); }
        .acct-btn-danger:hover { background: var(--danger-bg); }
        .acct-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .acct-msg {
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 13px;
            margin-bottom: 16px;
            display: none;
        }

        .acct-msg.success { display: block; background: var(--success-bg); color: var(--success-text); border: 1px solid var(--success-border); }
        .acct-msg.error { display: block; background: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-border); }

        /* MFA specific */
        .mfa-status-card {
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 16px;
        }

        .mfa-status-card.enabled {
            background: var(--success-bg);
            border: 1px solid var(--success-border);
        }

        .mfa-status-card.not-enabled {
            background: var(--surface-2);
            border: 1px solid var(--border);
        }

        .mfa-status-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .mfa-status-desc {
            font-size: 12px;
            color: var(--text-muted);
        }

        .mfa-setup-area { margin-top: 16px; }

        .qr-container {
            text-align: center;
            padding: 16px;
            background: #fff;
            border: 1px solid #eee;
            border-radius: 6px;
            margin-bottom: 16px;
        }

        .qr-container img { image-rendering: pixelated; }

        .secret-display {
            text-align: center;
            margin-bottom: 16px;
        }

        .secret-display code {
            background: var(--surface-2);
            color: var(--text);
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Consolas', monospace;
            letter-spacing: 2px;
            user-select: all;
        }

        .secret-display p {
            font-size: 11px;
            color: var(--text-faint);
            margin-top: 6px;
        }

        .verify-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .verify-row .acct-form-group { flex: 1; margin-bottom: 0; }

        .otp-input {
            font-size: 18px;
            letter-spacing: 6px;
            text-align: center;
            font-family: 'Consolas', monospace;
        }

        .mfa-disable-area { margin-top: 16px; }
    </style>

    <div class="header-right">
        <?php echo $__tenantSwitcherHtml; ?>
        <?php renderNotificationBell($path_prefix); ?>
        <?php renderWarRoomAlerts($path_prefix); ?>
        <button class="mail-check-btn" id="mailCheckBtn" onclick="triggerMailCheck()" title="<?php echo htmlspecialchars(t('common.account.mail_check')); ?>" style="display:none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
        </button>
        <div class="user-menu-overlay" id="userMenuOverlay" onclick="closeUserMenu()"></div>
        <div class="user-avatar" onclick="toggleUserMenu()" title="<?php echo htmlspecialchars($analyst_name); ?>">
            <?php echo htmlspecialchars($initials); ?>
        </div>
        <div class="user-menu" id="userMenu">
            <div class="user-menu-header">
                <div class="user-menu-name"><?php echo htmlspecialchars($analyst_name); ?></div>
                <div class="user-menu-username"><?php echo htmlspecialchars($analyst_username); ?></div>
            </div>
            <button class="user-menu-item" onclick="window.location.href='<?php echo BASE_URL; ?>system/preferences/'">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                <span><?php echo htmlspecialchars(t('common.account.preferences')); ?></span>
            </button>
            <button class="user-menu-item" onclick="openPasswordModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                <span><?php echo htmlspecialchars(t('common.account.change_password')); ?></span>
            </button>
            <button class="user-menu-item" onclick="openMfaModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                <span><?php echo htmlspecialchars(t('common.account.mfa')); ?></span>
                <span class="mfa-badge disabled" id="mfaBadgeMenu"><?php echo htmlspecialchars(t('common.account.badge_off')); ?></span>
            </button>
            <button class="user-menu-item" id="trustDeviceItem" onclick="toggleTrustDevice()" style="display:none;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                <span><?php echo htmlspecialchars(t('common.account.trusted_device')); ?></span>
                <span class="mfa-badge disabled" id="trustBadgeMenu"><?php echo htmlspecialchars(t('common.account.badge_off')); ?></span>
            </button>
            <?php /* Switch to the portal (#81). Shown only when this analyst has a portal
                     account they could actually sign in to — see $__portalAccount above.
                     New tab on purpose: the analyst keeps whatever they were working on,
                     and the two sessions coexist because they use different session keys. */ ?>
            <?php if (!empty($__portalAccount)): ?>
            <button class="user-menu-item" onclick="window.open('<?php echo BASE_URL; ?>self-service/', '_blank', 'noopener');">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                <span><?php echo htmlspecialchars(t('common.account.portal')); ?></span>
            </button>
            <?php endif; ?>
            <div class="user-menu-divider"></div>
            <?php /* Appearance picker — always shown in the account menu (global theme). */ ?>
            <?php if (class_exists('Theme')): ?>
            <div class="user-menu-section-label"><?php echo htmlspecialchars(t('common.account.appearance')); ?></div>
            <div class="theme-picker">
                <?php $themePickerActive = Theme::active($theme_module ?? null);
                foreach (Theme::all() as $themeId => $themeMeta): ?>
                <button type="button" class="theme-swatch<?php echo $themeId === $themePickerActive ? ' active' : ''; ?>" onclick="setTheme('<?php echo htmlspecialchars($themeId, ENT_QUOTES); ?>')">
                    <span class="theme-swatch-dot theme-swatch-<?php echo htmlspecialchars($themeId); ?>"></span>
                    <span><?php echo htmlspecialchars($themeMeta['label']); ?></span>
                    <?php if ($themeId === $themePickerActive): ?><span class="theme-swatch-check">&#10003;</span><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="user-menu-divider"></div>
            <?php endif; ?>
            <button class="user-menu-item logout-item" onclick="showConfirm({title:<?php echo htmlspecialchars(json_encode(t('common.account.logout')), ENT_QUOTES); ?>,message:<?php echo htmlspecialchars(json_encode(t('common.account.logout_confirm')), ENT_QUOTES); ?>,okLabel:<?php echo htmlspecialchars(json_encode(t('common.account.logout')), ENT_QUOTES); ?>,okClass:'primary',onConfirm:()=>{window.location.href='<?php echo BASE_URL; ?>analyst_logout.php';}});">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                <span><?php echo htmlspecialchars(t('common.account.logout')); ?></span>
            </button>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="account-modal" id="passwordModal">
        <div class="account-modal-box">
            <div class="account-modal-header">
                <?php echo htmlspecialchars(t('common.password_modal.title')); ?>
            </div>
            <div class="account-modal-body">
                <div id="pwMsg" class="acct-msg"></div>
                <div class="acct-form-group">
                    <label class="acct-form-label"><?php echo htmlspecialchars(t('common.password_modal.current_password')); ?></label>
                    <input type="password" class="acct-form-input" id="pwCurrent" autocomplete="current-password">
                </div>
                <div class="acct-form-group">
                    <label class="acct-form-label"><?php echo htmlspecialchars(t('common.password_modal.new_password')); ?></label>
                    <input type="password" class="acct-form-input" id="pwNew" autocomplete="new-password">
                </div>
                <div class="acct-form-group">
                    <label class="acct-form-label"><?php echo htmlspecialchars(t('common.password_modal.confirm_password')); ?></label>
                    <input type="password" class="acct-form-input" id="pwConfirm" autocomplete="new-password">
                </div>
            </div>
            <div class="account-modal-footer">
                <button class="acct-btn acct-btn-secondary" onclick="closePasswordModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="acct-btn acct-btn-primary" id="pwSaveBtn" onclick="savePassword()"><?php echo htmlspecialchars(t('common.password_modal.submit')); ?></button>
            </div>
        </div>
    </div>

    <!-- MFA Modal -->
    <div class="account-modal" id="mfaModal">
        <div class="account-modal-box">
            <div class="account-modal-header">
                <?php echo htmlspecialchars(t('common.mfa_modal.title')); ?>
            </div>
            <div class="account-modal-body">
                <div id="mfaMsg" class="acct-msg"></div>
                <div id="mfaContent"><?php echo htmlspecialchars(t('common.loading')); ?></div>
            </div>
            <div class="account-modal-footer">
                <button class="acct-btn acct-btn-secondary" onclick="closeMfaModal()"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <script src="<?php echo BASE_URL; ?>assets/js/qrcode.min.js"></script>
    <script>
    const _pathPrefix = '<?php echo BASE_URL; ?>';

    /* --- User Menu --- */
    function toggleUserMenu() {
        const menu = document.getElementById('userMenu');
        const overlay = document.getElementById('userMenuOverlay');
        const active = menu.classList.contains('active');
        closeWaffleMenu();
        if (active) {
            closeUserMenu();
        } else {
            menu.classList.add('active');
            overlay.classList.add('active');
            loadMfaBadge();
        }
    }

    function closeUserMenu() {
        document.getElementById('userMenu').classList.remove('active');
        document.getElementById('userMenuOverlay').classList.remove('active');
    }

    /* --- Theme / palette picker ---
       Saves the palette for this module (or globally if the page didn't declare a
       module) and reloads so the server re-renders <html data-theme>. */
    function setTheme(themeId) {
        fetch(_pathPrefix + 'api/system/set_user_preference.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'theme', value: themeId })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d && d.success) { location.reload(); }
        }).catch(function () {});
    }

    /* --- Password Modal --- */
    function openPasswordModal() {
        closeUserMenu();
        document.getElementById('pwCurrent').value = '';
        document.getElementById('pwNew').value = '';
        document.getElementById('pwConfirm').value = '';
        hidePwMsg();
        document.getElementById('passwordModal').classList.add('active');
        setTimeout(() => document.getElementById('pwCurrent').focus(), 100);
    }

    function closePasswordModal() {
        document.getElementById('passwordModal').classList.remove('active');
    }

    function hidePwMsg() {
        const el = document.getElementById('pwMsg');
        el.className = 'acct-msg';
        el.textContent = '';
    }

    function showPwMsg(msg, type) {
        const el = document.getElementById('pwMsg');
        el.className = 'acct-msg ' + type;
        el.textContent = msg;
    }

    async function savePassword() {
        hidePwMsg();
        const btn = document.getElementById('pwSaveBtn');
        btn.disabled = true;

        const current = document.getElementById('pwCurrent').value;
        const newPw = document.getElementById('pwNew').value;
        const confirm = document.getElementById('pwConfirm').value;

        if (!current || !newPw || !confirm) {
            showPwMsg('All fields are required', 'error');
            btn.disabled = false;
            return;
        }

        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/change_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ current_password: current, new_password: newPw, confirm_password: confirm })
            });
            const data = await resp.json();
            if (data.success) {
                showPwMsg('Password changed successfully', 'success');
                document.getElementById('pwCurrent').value = '';
                document.getElementById('pwNew').value = '';
                document.getElementById('pwConfirm').value = '';
                setTimeout(() => closePasswordModal(), 1500);
            } else {
                showPwMsg(data.error, 'error');
            }
        } catch (e) {
            showPwMsg('Failed to change password', 'error');
        }
        btn.disabled = false;
    }

    /* --- MFA Badge & Trust Device Badge --- */
    async function loadMfaBadge() {
        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/get_mfa_status.php');
            const data = await resp.json();
            const badge = document.getElementById('mfaBadgeMenu');
            if (data.success && data.mfa_enabled) {
                badge.className = 'mfa-badge enabled';
                badge.textContent = 'On';
            } else {
                badge.className = 'mfa-badge disabled';
                badge.textContent = 'Off';
            }

            // Trust device badge
            const trustItem = document.getElementById('trustDeviceItem');
            const trustBadge = document.getElementById('trustBadgeMenu');
            if (data.success && data.trusted_device_days > 0) {
                trustItem.style.display = '';
                if (data.trust_device_enabled) {
                    trustBadge.className = 'mfa-badge enabled';
                    trustBadge.textContent = 'On';
                } else {
                    trustBadge.className = 'mfa-badge disabled';
                    trustBadge.textContent = 'Off';
                }
            } else {
                trustItem.style.display = 'none';
            }
        } catch (e) {}
    }

    /* --- Trust Device Toggle --- */
    async function toggleTrustDevice() {
        closeUserMenu();
        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/toggle_trust_device.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });
            const data = await resp.json();
            if (data.success) {
                const trustBadge = document.getElementById('trustBadgeMenu');
                if (data.enabled) {
                    trustBadge.className = 'mfa-badge enabled';
                    trustBadge.textContent = 'On';
                } else {
                    trustBadge.className = 'mfa-badge disabled';
                    trustBadge.textContent = 'Off';
                }
            }
        } catch (e) {}
    }

    /* --- MFA Modal --- */
    let mfaEnabled = false;

    async function openMfaModal() {
        closeUserMenu();
        document.getElementById('mfaMsg').className = 'acct-msg';
        document.getElementById('mfaContent').innerHTML = 'Loading...';
        document.getElementById('mfaModal').classList.add('active');
        await loadMfaContent();
    }

    function closeMfaModal() {
        document.getElementById('mfaModal').classList.remove('active');
    }

    function showMfaMsg(msg, type) {
        const el = document.getElementById('mfaMsg');
        el.className = 'acct-msg ' + type;
        el.textContent = msg;
    }

    async function loadMfaContent() {
        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/get_mfa_status.php');
            const data = await resp.json();
            mfaEnabled = data.success && data.mfa_enabled;
            renderMfaContent();
        } catch (e) {
            document.getElementById('mfaContent').innerHTML = '<p>Failed to load MFA status</p>';
        }
    }

    function renderMfaContent() {
        const container = document.getElementById('mfaContent');
        if (mfaEnabled) {
            container.innerHTML = `
                <div class="mfa-status-card enabled">
                    <div class="mfa-status-title" style="color:var(--success-text);">MFA is enabled</div>
                    <div class="mfa-status-desc">Your account is protected with a time-based one-time password (TOTP). You will be asked for a code from your authenticator app each time you log in.</div>
                </div>
                <div class="mfa-disable-area">
                    <p style="font-size:13px;color:var(--text-muted);margin:0 0 12px 0;">To disable MFA, enter your password below:</p>
                    <div class="acct-form-group">
                        <input type="password" class="acct-form-input" id="mfaDisablePw" placeholder="Enter your password">
                    </div>
                    <button class="acct-btn acct-btn-danger" onclick="disableMfa()">Disable MFA</button>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="mfa-status-card not-enabled">
                    <div class="mfa-status-title">MFA is not enabled</div>
                    <div class="mfa-status-desc">Add an extra layer of security by setting up a time-based one-time password (TOTP) with an authenticator app like Google Authenticator or Microsoft Authenticator.</div>
                </div>
                <button class="acct-btn acct-btn-primary" onclick="startMfaSetup()">Set Up MFA</button>
            `;
        }
    }

    async function startMfaSetup() {
        const container = document.getElementById('mfaContent');
        container.innerHTML = '<p style="color:var(--text-dim);">Generating secret...</p>';

        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/setup_mfa.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });
            const data = await resp.json();
            if (!data.success) {
                showMfaMsg(data.error, 'error');
                renderMfaContent();
                return;
            }

            // Generate QR code
            let qrHtml = '';
            try {
                const qr = qrcode(0, 'M');
                qr.addData(data.uri);
                qr.make();
                qrHtml = qr.createImgTag(5, 0);
            } catch (e) {
                qrHtml = '<p style="color:#c62828;">QR generation failed. Use the manual key below.</p>';
            }

            container.innerHTML = `
                <p style="font-size:13px;color:var(--text);margin:0 0 16px 0;"><strong>Step 1:</strong> Scan this QR code with your authenticator app</p>
                <div class="qr-container">${qrHtml}</div>
                <div class="secret-display">
                    <code>${data.secret}</code>
                    <p>Or enter this key manually in your authenticator app</p>
                </div>
                <p style="font-size:13px;color:var(--text);margin:0 0 12px 0;"><strong>Step 2:</strong> Enter the 6-digit code from your app to verify</p>
                <div class="verify-row">
                    <div class="acct-form-group">
                        <input type="text" class="acct-form-input otp-input" id="mfaVerifyCode" maxlength="6" placeholder="000000" inputmode="numeric" autocomplete="one-time-code">
                    </div>
                    <button class="acct-btn acct-btn-primary" id="mfaVerifyBtn" onclick="verifyMfaSetup()" style="margin-bottom:0;height:40px;">Verify</button>
                </div>
            `;
            setTimeout(() => document.getElementById('mfaVerifyCode').focus(), 100);
        } catch (e) {
            showMfaMsg('Failed to start MFA setup', 'error');
            renderMfaContent();
        }
    }

    async function verifyMfaSetup() {
        const code = document.getElementById('mfaVerifyCode').value.trim();
        if (!code || code.length !== 6) {
            showMfaMsg('Please enter a 6-digit code', 'error');
            return;
        }

        const btn = document.getElementById('mfaVerifyBtn');
        btn.disabled = true;

        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/verify_mfa.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: code })
            });
            const data = await resp.json();
            if (data.success) {
                showMfaMsg('MFA has been enabled successfully', 'success');
                mfaEnabled = true;
                loadMfaBadge();
                setTimeout(() => {
                    document.getElementById('mfaMsg').className = 'acct-msg';
                    renderMfaContent();
                }, 2000);
            } else {
                showMfaMsg(data.error, 'error');
                btn.disabled = false;
            }
        } catch (e) {
            showMfaMsg('Verification failed', 'error');
            btn.disabled = false;
        }
    }

    async function disableMfa() {
        const pw = document.getElementById('mfaDisablePw').value;
        if (!pw) {
            showMfaMsg('Password is required', 'error');
            return;
        }

        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/disable_mfa.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: pw })
            });
            const data = await resp.json();
            if (data.success) {
                showMfaMsg('MFA has been disabled', 'success');
                mfaEnabled = false;
                loadMfaBadge();
                setTimeout(() => {
                    document.getElementById('mfaMsg').className = 'acct-msg';
                    renderMfaContent();
                }, 2000);
            } else {
                showMfaMsg(data.error, 'error');
            }
        } catch (e) {
            showMfaMsg('Failed to disable MFA', 'error');
        }
    }

    /* --- Keyboard & click handlers --- */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeUserMenu();
            closePasswordModal();
            closeMfaModal();
        }
    });

    document.getElementById('passwordModal').addEventListener('click', function(e) {
        if (e.target === this) closePasswordModal();
    });

    document.getElementById('mfaModal').addEventListener('click', function(e) {
        if (e.target === this) closeMfaModal();
    });
    </script>
    <?php
}
?>
