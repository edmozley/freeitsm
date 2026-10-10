<?php
/**
 * System - Preferences (per-browser + per-analyst settings)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'preferences';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
// ⚠️ 'watchtower' is here for ONE section — the "Mine" view's handling of cards
// with no owner (#58). The setting is a personal preference, so it lives on this
// screen rather than in Watchtower's own admin settings; its strings still
// belong to that module. Without the namespace the labels render as raw keys.
$translationNamespaces = ['common', 'system', 'watchtower'];
$locales = I18n::getSupportedLocales();
$currentLocale = I18n::getLocale();

// Pre-fetch every per-analyst preference this page surfaces so the
// initial render is already in sync with the database. Avoids the
// flicker we used to get from initial localStorage / default values
// being replaced by AJAX-fetched values a moment later.
$prefDefaults = [
    // Display timezone. Defaults to the server zone (config.php) until the
    // analyst picks one; every date across the app is stored UTC and shown
    // in this zone (see includes/timezone.php).
    'timezone'                   => date_default_timezone_get(),
    // How a date and a time are WRITTEN, as opposed to which instant the zone
    // above selects. '' means "follow whatever the administrator chose in
    // System > Date and time formats" — the same idiom default_landing_page
    // uses below, and the only value that is not itself a format key.
    'date_format'                => '',
    'time_format'                => '',
    'toast_position'             => 'bottom-right',
    'toast_animation'            => 'slide',
    // Chime when a notification arrives. 'off' by default — whether a sound at
    // your desk is welcome or infuriating is a personal answer, so it is opt-in
    // per analyst rather than something an administrator turns on for everyone.
    // Read on every analyst page by renderWaffleMenuJS(); played by
    // assets/js/notification-sound.js.
    'notification_sound'         => 'off',
    // Desktop notifications (2.10.0): a Windows/macOS notification from the
    // browser - in Edge, a Windows notification - for each new item in the bell,
    // while FreeITSM is open in a tab. 'on' or ''. The browser's own permission
    // is asked for when it is switched on. Read by renderWaffleMenuJS().
    'desktop_notifications'      => '',
    // Email me when a task is assigned to me (2.10.0). 'on' or ''. Read by
    // notificationsTaskAssignedEmail() in includes/notifications_router.php.
    'task_assigned_email'        => '',
    // How a task opens: 'panel' is the right-hand drawer, 'modal' is a large
    // near-full-screen window that lays the same content out in two columns.
    // Per analyst rather than per install, because it is a working-style
    // preference — the drawer keeps the board visible behind it, the modal gives
    // the description and comments room to breathe.
    'tasks_detail_view'          => 'panel',
    // Whether clicking away closes the draggable search panel (#144, asked for
    // by mbsouth). '' — only the ✕ or Escape — because the panel is draggable:
    // it is meant to be parked somewhere useful while you work, and a
    // click-anywhere dismiss makes that pointless for the people who do that.
    // Opt-in per analyst rather than per install, because which of the two you
    // want is a working style, not a property of the service desk.
    'search_panel_close_outside' => '',
    // Whether the task calendar shows subtasks as well as tasks (#90). Written
    // from two places that must agree: here, and the calendar's own Show
    // control. '' is parent tasks only, which is what the calendar always did.
    'tasks_calendar_subtasks'    => '',
    // Whether the board stops listing a task's subtasks under its card once
    // every one of them is complete. '' shows them regardless; 'on' tidies them
    // away. Read by tasks/index.php into window.TASK_HIDE_DONE_SUBTASKS.
    'tasks_hide_done_subtasks'   => '',
    // Left-panel visibility — one key per module that has a left panel.
    // Each module's header reads its key; module settings pages (where one
    // exists) edit the same key. Surfaced together below.
    'knowledge_sidebar_mode'         => 'always',
    'process_mapper_sidebar_mode'    => 'always',
    'contracts_sidebar_mode'         => 'always',
    'calendar_sidebar_mode'          => 'always',
    'tasks_sidebar_mode'             => 'always',
    'cmdb_sidebar_mode'              => 'always',
    'change_management_sidebar_mode' => 'always',
    'asset_management_sidebar_mode'  => 'always',
    'system_wiki_sidebar_mode'       => 'always',
    'mc_chart_fill_style'        => 'plain',
    // Tickets inbox: what the screen does when several tickets are selected at
    // once. Read by assets/js/inbox.js. 'summary' is the default because it puts
    // what you are about to act on, and the actions themselves, on screen.
    'tickets_multiselect_pane'   => 'summary',
    // Which front door "/" sends you to (discussion #63). Empty string means
    // "whatever the administrator chose" — the only value that is NOT a landing
    // key, because "follow the install default" is a real third choice here.
    'default_landing_page'       => '',
];
$prefs = $prefDefaults;
if (isset($_SESSION['analyst_id'])) {
    try {
        $conn = connectToDatabase();
        $keys = array_keys($prefDefaults);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $conn->prepare(
            "SELECT preference_key, preference_value FROM user_preferences
             WHERE analyst_id = ? AND preference_key IN ($placeholders)"
        );
        $stmt->execute(array_merge([(int)$_SESSION['analyst_id']], $keys));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists($row['preference_key'], $prefs) && $row['preference_value'] !== null && $row['preference_value'] !== '') {
                $prefs[$row['preference_key']] = $row['preference_value'];
            }
        }
    } catch (Exception $e) {
        // Defaults stand
    }
}

// The install-wide format, read separately from the per-analyst values above so
// the "follow the install default" option can SAY what that currently is. A
// choice you cannot see the consequence of is not really a choice.
$installDate = DateFmt::DEFAULT_DATE;
$installTime = DateFmt::DEFAULT_TIME;
try {
    $conn = $conn ?? connectToDatabase();
    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings
         WHERE setting_key IN ('date_format','time_format')"
    );
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['setting_value'] === null || $row['setting_value'] === '') continue;
        if ($row['setting_key'] === 'date_format' && isset(DateFmt::DATE_TEMPLATES[$row['setting_value']])) {
            $installDate = $row['setting_value'];
        }
        if ($row['setting_key'] === 'time_format' && isset(DateFmt::TIME_TEMPLATES[$row['setting_value']])) {
            $installTime = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // Built-in defaults stand
}

// One sample instant for every example on this page — a single-digit day and an
// afternoon time are what actually tell the formats apart. Rendered through
// DateFmt itself, so the examples cannot drift from what the app will do.
$fmtSample = new DateTime('2026-08-05 14:30:00', new DateTimeZone(Tz::current()));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars(t('system.preferences.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=78">
    <link rel="stylesheet" href="../../assets/css/subscribe.css?v=1">
    <style>
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

        /* Same shell as the other System pages (system/analysts) and the module
           Settings pages: a flex wrapper pins the header and .settings-scroll is the
           only scroll region, rather than a fragile `height: calc(100vh - 48px)`.
           <body> stays unstyled so browser-extension nodes can't join the flex. */
        .settings-shell { display: flex; flex-direction: column; height: 100vh; }
        .settings-scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            width: 100%;
            margin: 0;
            box-sizing: border-box;
            padding: 30px 24px 24px;
        }
        .page-title { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-muted, #888); margin: 0 0 24px 0; line-height: 1.5; }

        /* .tabs / .tab / .tab-content all come from inbox.css — .tab-content already
           carries the card (surface, radius, shadow, 30px padding), so the panel IS
           the card and there is no wrapper of our own. Only the first section's top
           margin needs taming, since each panel now starts with one. */
        .tab-content > .pref-section:first-child > h3 { margin-top: 0; }

        /* My details + signatures (#80). */
        .sig-details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .sig-details-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--text, #333); }
        .sig-details-grid input, .sig-field input[type="text"] {
            padding: 9px 10px; border: 1px solid var(--border, #ddd); border-radius: 4px;
            font-size: 14px; font-family: inherit;
            background: var(--surface, #fff);
            color: var(--text, #333);
            box-sizing: border-box;
            transition: border-color 0.15s, background-color 0.15s;
        }
        .sig-details-grid input:focus, .sig-field input[type="text"]:focus {
            outline: none;
            border-color: var(--sys-accent, #546e7a);
        }
        .sig-details-grid input:disabled, .sig-field input[type="text"]:disabled {
            background: var(--surface-2, #f5f5f5);
            color: var(--text-muted, #888);
            cursor: not-allowed;
            border-color: var(--border, #e0e0e0);
            opacity: 0.8;
        }
        .sig-details-grid label.is-disabled {
            cursor: not-allowed;
            opacity: 0.85;
        }
        .pref-managed-note {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            line-height: 1.4;
            background: var(--surface-2, rgba(84, 110, 122, 0.1));
            color: var(--text, #333);
            border-left: 3px solid var(--sys-accent, #546e7a);
        }
        .pref-managed-note svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            stroke: var(--sys-accent, #546e7a);
        }
        .sig-btn { padding: 8px 16px; border-radius: 4px; border: 1px solid var(--border, #ddd); cursor: pointer; font-size: 13px; font-family: inherit; }
        .sig-btn-primary { background: var(--accent, #2d6a4f); color: #fff; border-color: transparent; }
        .sig-btn-secondary { background: var(--surface-2, #f1f1f1); color: var(--text, #333); }
        .sig-btn-danger { background: var(--danger-bg, #f8d7da); color: var(--danger-text, #721c24); border-color: transparent; }
        .sig-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
        .sig-empty { color: var(--text-muted, #666); font-size: 13px; padding: 10px 0; }
        .sig-error { color: var(--danger-text, #721c24); }
        .sig-card { border: 1px solid var(--border, #ddd); border-radius: 6px; overflow: hidden; }
        .sig-card-head {
            display: flex; align-items: center; gap: 10px; padding: 8px 12px;
            background: var(--surface-2, #f9f9f9); border-bottom: 1px solid var(--border, #ddd); font-size: 13px;
        }
        .sig-card-actions { margin-left: auto; display: flex; gap: 6px; }
        .sig-default-badge {
            font-size: 11px; padding: 2px 8px; border-radius: 10px;
            background: var(--success-bg, #d4edda); color: var(--success-text, #155724);
        }
        .sig-card-body { padding: 12px; font-size: 13px; line-height: 1.5; background: var(--surface, #fff); color: var(--text, #333); }
        .sig-editor { margin-top: 14px; padding: 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--surface-2, #f9f9f9); }
        .sig-field { display: flex; flex-direction: column; gap: 4px; font-size: 13px; margin-bottom: 10px; max-width: 420px; }
        .sig-default-row { flex-direction: row !important; align-items: center; gap: 8px; }
        .sig-codes { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 10px 0; }
        .sig-codes-label { font-size: 12px; color: var(--text-muted, #666); margin-right: 4px; }
        .sig-code {
            font-family: Consolas, Monaco, monospace; font-size: 11px; cursor: pointer;
            padding: 3px 8px; border-radius: 10px;
            border: 1px solid var(--border, #ddd); background: var(--surface, #fff); color: var(--text-muted, #666);
        }
        .sig-preview-wrap { margin-top: 10px; }
        .sig-preview-label { font-size: 12px; color: var(--text-muted, #666); margin-bottom: 4px; }
        .sig-preview {
            border: 1px solid var(--border, #ddd); border-radius: 4px; padding: 12px; min-height: 60px;
            background: #ffffff; color: #333333; font-family: Arial, sans-serif; font-size: 13px; line-height: 1.5;
        }
        .sig-editor-actions { display: flex; gap: 8px; margin-top: 12px; }
        .pref-section {
            margin-bottom: 32px;
        }

        .pref-section:last-child { margin-bottom: 0; }

        .pref-section h3 {
            margin: 0 0 6px 0;
            font-size: 15px;
            color: var(--text, #333);
        }

        .pref-section p {
            margin: 0 0 16px 0;
            font-size: 13px;
            color: var(--text-muted, #666);
        }

        .position-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 4px;
            width: 192px;
            height: 128px;
            background: var(--surface-hover, #f0f0f0);
            border: 2px solid var(--border, #ddd);
            border-radius: 8px;
            padding: 8px;
        }

        .position-cell {
            background: #e0e0e0;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .position-cell:hover { background: #d0d0d0; }
        .position-cell.active { background: var(--sys-accent, #546e7a); }
        .position-cell.active .position-dot { background: var(--sys-on-accent, #fff); }

        .position-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #bbb;
            transition: all 0.15s;
        }

        .anim-toggle {
            display: flex;
            gap: 0;
            border: 2px solid var(--border, #ddd);
            border-radius: 6px;
            overflow: hidden;
            width: fit-content;
        }

        .anim-option {
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted, #666);
            background: var(--surface-3, #f5f5f5);
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }

        .anim-option:not(:last-child) { border-right: 1px solid var(--border, #ddd); }
        .anim-option:hover { background: #e8e8e8; }
        .anim-option.active { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }

        .pref-language-select {
            font-size: 14px;
            padding: 8px 12px;
            border: 2px solid var(--border, #ddd);
            border-radius: 6px;
            background: var(--surface, #fff);
            color: var(--text, #333);
            min-width: 240px;
            cursor: pointer;
        }
        .pref-language-select:focus { outline: none; border-color: var(--sys-accent, #546e7a); }

        .pref-saving-hint {
            margin-left: 10px;
            font-size: 12px;
            color: var(--text-dim, #888);
            opacity: 0;
            transition: opacity 0.2s;
        }
        .pref-saving-hint.show { opacity: 1; }

        .sidebar-panels-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 520px;
        }

        .sidebar-panel-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
        }

        .sidebar-panel-row:last-child { border-bottom: none; }

        .sidebar-panel-label {
            font-size: 14px;
            color: var(--text, #333);
        }

        /* ---- Dark mode overrides (pale greys that would glow) ---- */
        [data-theme-mode="dark"] .position-cell { background: #3a4250; }
        [data-theme-mode="dark"] .position-cell:not(.active):hover { background: #46505f; }
        [data-theme-mode="dark"] .position-dot { background: #8b95a5; }
        [data-theme-mode="dark"] .anim-option:not(.active):hover { background: var(--surface-hover, #39414f); }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=192">
</head>
<body data-mobile-module="system" data-mobile-page="settings" data-mobile-shell="own">
    <div class="settings-shell">
    <?php include '../includes/header.php'; ?>

    <div class="settings-scroll">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.preferences.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.preferences.subtitle')); ?></p>

        <?php
            // The tab bar. Not rendered from a settings manifest like a module's
            // Settings page: a manifest binds every tab to a CAPABILITY, and there is
            // nothing here to grant — every panel on this page is one analyst's own
            // preference. So the bar is written out directly, using the same .tabs /
            // .tab / .tab-content classes from inbox.css that the module pages use.
            $prefTabs = [
                'general'       => t('system.preferences.tab_general'),
                'notifications' => t('system.preferences.tab_notifications'),
                'display'       => t('system.preferences.tab_display'),
                'details'       => t('system.preferences.tab_details'),
            ];
        ?>
        <div class="tabs">
            <?php $first = true; foreach ($prefTabs as $tabId => $tabLabel): ?>
                <button class="tab<?php echo $first ? ' active' : ''; ?>" data-tab="<?php echo htmlspecialchars($tabId); ?>" onclick="switchTab('<?php echo htmlspecialchars($tabId); ?>')"><?php echo htmlspecialchars($tabLabel); ?></button>
            <?php $first = false; endforeach; ?>
        </div>

        <div class="tab-content active" id="general-tab">
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.language_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.language_desc')); ?></p>
                <select id="languageSelect" class="pref-language-select">
                    <?php foreach ($locales as $code => $native): ?>
                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $code === $currentLocale ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($native); ?> (<?php echo htmlspecialchars($code); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="pref-saving-hint" id="langSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>

            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.timezone_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.timezone_desc')); ?></p>
                <?php
                    // Group IANA zones by region for the dropdown (Europe/London → "Europe").
                    $tzGroups = [];
                    foreach (timezone_identifiers_list() as $tzId) {
                        $parts = explode('/', $tzId, 2);
                        $region = count($parts) === 2 ? $parts[0] : 'Other';
                        $tzGroups[$region][] = $tzId;
                    }
                    ksort($tzGroups);
                    $currentTz = $prefs['timezone'];
                ?>
                <select id="timezoneSelect" class="pref-language-select">
                    <?php foreach ($tzGroups as $region => $zones): ?>
                        <optgroup label="<?php echo htmlspecialchars($region); ?>">
                            <?php foreach ($zones as $tzId): ?>
                                <option value="<?php echo htmlspecialchars($tzId); ?>" <?php echo $tzId === $currentTz ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tzId); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <span class="pref-saving-hint" id="tzSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>

            <?php /* Date and time FORMAT — sits under Timezone because that is where
                     someone looks next, but answers a different question: the zone above
                     picks WHICH INSTANT, this picks HOW IT IS WRITTEN. Neither changes
                     what is stored. Each option is labelled with its rendered example. */ ?>
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.dateformat_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.dateformat_desc')); ?></p>
                <select id="dateFormatSelect" class="pref-language-select">
                    <option value="" <?php echo $prefs['date_format'] === '' ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(t('system.preferences.format_follow_default', [
                            'example' => DateFmt::render($fmtSample, DateFmt::DATE_TEMPLATES[$installDate]),
                        ])); ?>
                    </option>
                    <?php foreach (DateFmt::DATE_TEMPLATES as $key => $tpl): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $prefs['date_format'] === $key ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(DateFmt::render($fmtSample, $tpl)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="pref-saving-hint" id="dateFormatSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>

            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.timeformat_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.timeformat_desc')); ?></p>
                <select id="timeFormatSelect" class="pref-language-select">
                    <option value="" <?php echo $prefs['time_format'] === '' ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(t('system.preferences.format_follow_default', [
                            'example' => DateFmt::render($fmtSample, DateFmt::TIME_TEMPLATES[$installTime]),
                        ])); ?>
                    </option>
                    <?php foreach (DateFmt::TIME_TEMPLATES as $key => $tpl): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $prefs['time_format'] === $key ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(DateFmt::render($fmtSample, $tpl)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="pref-saving-hint" id="timeFormatSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>

            <?php
                // Start page (discussion #63). Shown with the administrator's choice
                // named, so "use the site default" is not a guess.
                require_once __DIR__ . '/../../includes/landing.php';
                $landingDefaultKey = 'analyst';
                try {
                    $landingDefaultKey = landingInstallDefault(connectToDatabase());
                } catch (Exception $e) {
                    // Falls back to 'analyst', which is what index.php would do too.
                }
                $landingDefaultName = $landingDefaultKey === 'portal'
                    ? t('system.preferences.landing_portal')
                    : t('system.preferences.landing_analyst');
            ?>
            <!-- How a task opens (Ed's request). A working-style choice, so it is
                 per analyst: the drawer keeps the board visible behind it, the
                 modal gives the description and comments room. -->
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.task_view_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.task_view_desc')); ?></p>
                <select id="taskViewSelect" class="pref-language-select">
                    <option value="panel" <?php echo $prefs['tasks_detail_view'] !== 'modal' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('system.preferences.task_view_panel')); ?></option>
                    <option value="modal" <?php echo $prefs['tasks_detail_view'] === 'modal' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('system.preferences.task_view_modal')); ?></option>
                </select>
                <span class="pref-saving-hint" id="taskViewSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>

            <!-- Closing the search panel (#144). Sits beside "Opening a task"
                 because it is the same kind of choice: how a shared UI surface
                 behaves for YOU. Off by default — see the note by the default. -->
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.search_dismiss_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.search_dismiss_desc')); ?></p>
                <label class="toggle-group">
                    <span class="toggle-switch">
                        <input type="checkbox" id="searchDismissToggle" <?php echo $prefs['search_panel_close_outside'] === 'on' ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </span>
                    <span class="toggle-label"><?php echo htmlspecialchars(t('system.preferences.search_dismiss_label')); ?></span>
                </label>
                <span class="pref-saving-hint" id="searchDismissSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>

            <!-- Subtasks on the task calendar (#90). The calendar's own Show control
                 writes the same preference, so the two stay in step; this is here
                 because that is where somebody looks for a setting they half
                 remember changing, and its neighbour above already is. -->
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.cal_subtasks_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.cal_subtasks_desc')); ?></p>
                <select id="calSubtaskSelect" class="pref-language-select">
                    <option value=""     <?php echo $prefs['tasks_calendar_subtasks'] === ''     ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('tasks.filter.parents_only')); ?></option>
                    <option value="both" <?php echo $prefs['tasks_calendar_subtasks'] === 'both' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('tasks.filter.parents_and_subtasks')); ?></option>
                    <option value="only" <?php echo $prefs['tasks_calendar_subtasks'] === 'only' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('tasks.filter.subtasks_only')); ?></option>
                </select>
                <span class="pref-saving-hint" id="calSubtaskSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>

            <!-- Subtasks listed under their card on the board. -->
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.board_subtasks_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.board_subtasks_desc')); ?></p>
                <label class="toggle-group">
                    <span class="toggle-switch">
                        <input type="checkbox" id="boardSubtasksToggle" <?php echo $prefs['tasks_hide_done_subtasks'] === 'on' ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </span>
                    <span class="toggle-label"><?php echo htmlspecialchars(t('system.preferences.board_subtasks_label')); ?></span>
                </label>
                <span class="pref-saving-hint" id="boardSubtasksSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>

            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.landing_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.landing_desc')); ?></p>
                <select id="landingSelect" class="pref-language-select">
                    <option value=""><?php echo htmlspecialchars(t('system.preferences.landing_default', ['name' => $landingDefaultName])); ?></option>
                    <option value="analyst" <?php echo $prefs['default_landing_page'] === 'analyst' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('system.preferences.landing_analyst')); ?></option>
                    <option value="portal" <?php echo $prefs['default_landing_page'] === 'portal' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('system.preferences.landing_portal')); ?></option>
                </select>
                <span class="pref-saving-hint" id="landingSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
                <p class="pref-hint" style="margin-top:8px;color:var(--text-muted,#666);font-size:12px;"><?php echo htmlspecialchars(t('system.preferences.landing_note')); ?></p>
            </div>

            <?php /* Watchtower's "Mine" view (#58). Six of the ten cards have no
                     owner to narrow to, and what should happen to them is a
                     genuine judgement call rather than something to decide for
                     everybody — so it is a choice. Default is to keep them. */ ?>
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('watchtower.scope.impersonal_heading')); ?></h3>
                <div class="anim-toggle" id="wtImpersonalToggle">
                    <button class="anim-option" data-wtimp="show"><?php echo htmlspecialchars(t('watchtower.scope.impersonal_show')); ?></button>
                    <button class="anim-option" data-wtimp="hide"><?php echo htmlspecialchars(t('watchtower.scope.impersonal_hide')); ?></button>
                </div>
                <p class="pref-hint" style="margin-top:8px;color:var(--text-muted,#666);font-size:12px;">
                    <?php echo htmlspecialchars(t('watchtower.scope.impersonal_note')); ?>
                </p>
            </div>

            <?php
                // My work calendar (GH #75). Today the only option is a subscribe
                // link; when the Graph push lands it becomes a third choice in the
                // SAME control rather than a second switch — with both live you
                // would see every scheduled ticket twice.
                require_once __DIR__ . '/../../includes/calendar_sync/calendar_sync.php';
                $feedAllowed = false;
                try { $feedAllowed = scheduleFeedAllowed(connectToDatabase()); } catch (Exception $e) {}
            ?>
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.workcal_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.workcal_desc')); ?></p>

                <?php if (!$feedAllowed): ?>
                    <?php /* Says WHY rather than showing nothing. A control that is
                             simply absent reads as a bug; one that explains itself
                             does not generate a support ticket. */ ?>
                    <p class="pref-hint" style="color:var(--text-muted,#666);font-size:13px;">
                        <?php echo htmlspecialchars(t('system.preferences.workcal_disabled')); ?>
                    </p>
                <?php else: ?>
                    <div class="anim-toggle" id="workCalToggle">
                        <button class="anim-option" data-workcal="off"><?php echo htmlspecialchars(t('system.preferences.workcal_off')); ?></button>
                        <?php /* One control, three options — never two switches.
                                 With a push AND a subscribed feed both live you
                                 would see every scheduled ticket twice, once as a
                                 real event and once from the subscription. */ ?>
                        <button class="anim-option" data-workcal="push" id="workCalPushBtn"><?php echo htmlspecialchars(t('system.preferences.workcal_push')); ?></button>
                        <button class="anim-option" data-workcal="feed"><?php echo htmlspecialchars(t('system.preferences.workcal_feed')); ?></button>
                    </div>

                    <?php /* Says WHY the direct option is unavailable. An option
                             that is simply missing reads as a bug, and one that is
                             greyed out with no reason is worse — this is the
                             screen where somebody is deciding. */ ?>
                    <p class="pref-hint" id="workCalPushWhy" style="display:none;margin-top:8px;color:var(--text-muted,#666);font-size:12px;"></p>

                    <div id="workCalPushPanel" style="display:none;margin-top:12px;">
                        <?php /* Which connection (#133), when the system has more
                                 than one: Microsoft 365 and a CalDAV server side
                                 by side, say. Hidden when there is only one - a
                                 choice of one is not a choice. */ ?>
                        <div id="workCalConnRow" style="display:none;margin-bottom:10px;">
                            <div style="font-size:13px;margin-bottom:6px;"><?php echo htmlspecialchars(t('system.preferences.workcal_conn')); ?></div>
                            <select id="workCalConn" class="pref-language-select"></select>
                        </div>
                        <p class="pref-hint" style="color:var(--text-muted,#666);font-size:12px;" id="workCalPushInfo"></p>

                        <?php /* CalDAV (#133). The analyst signs in as themselves
                                 and chooses their own calendar: a CalDAV server has
                                 no way for FreeITSM to write into everybody's, and
                                 nobody else has the password that reaches theirs.
                                 Shown only when the system's connection is CalDAV. */ ?>
                        <div id="calDavPanel" style="display:none;margin-top:12px;max-width:560px;">
                            <p class="pref-hint" id="calDavIntro" style="color:var(--text-muted,#666);font-size:12px;"></p>

                            <div id="calDavCurrent" style="display:none;margin:8px 0 12px;">
                                <span id="calDavCurrentText" style="font-size:13px;"></span>
                                <button type="button" class="sig-btn sig-btn-secondary" id="calDavChange" style="margin-left:8px;"><?php echo htmlspecialchars(t('system.preferences.caldav_change')); ?></button>
                                <button type="button" class="sig-btn sig-btn-danger" id="calDavForget" style="margin-left:4px;"><?php echo htmlspecialchars(t('system.preferences.caldav_forget')); ?></button>
                            </div>

                            <div id="calDavForm" style="display:none;">
                                <div class="sig-details-grid">
                                    <label><span><?php echo htmlspecialchars(t('system.preferences.caldav_username')); ?></span>
                                        <input type="text" id="calDavUser" autocomplete="username" maxlength="190"></label>
                                    <label><span><?php echo htmlspecialchars(t('system.preferences.caldav_password')); ?></span>
                                        <input type="password" id="calDavPass" autocomplete="current-password"></label>
                                </div>
                                <p class="pref-hint" style="color:var(--text-muted,#666);font-size:12px;margin-top:0;"><?php echo htmlspecialchars(t('system.preferences.caldav_password_hint')); ?></p>
                                <button type="button" class="sig-btn sig-btn-secondary" id="calDavFind"><?php echo htmlspecialchars(t('system.preferences.caldav_find')); ?></button>

                                <div id="calDavPick" style="display:none;margin-top:12px;">
                                    <div style="font-size:13px;margin-bottom:6px;"><?php echo htmlspecialchars(t('system.preferences.caldav_choose')); ?></div>
                                    <select id="calDavCal" class="pref-language-select"></select>
                                    <button type="button" class="sig-btn sig-btn-primary" id="calDavSave" style="margin-left:8px;"><?php echo htmlspecialchars(t('common.save')); ?></button>
                                </div>
                            </div>
                            <p class="pref-hint" id="calDavMsg" style="display:none;margin-top:10px;font-size:12px;"></p>
                        </div>

                        <?php /* Tasks (#75). Deliberately INSIDE the push panel:
                                 task events are real appointments, so the choice
                                 is meaningless until the direct route is chosen,
                                 and showing it beforehand would offer something
                                 that quietly does nothing.

                                 Four options in one control, not two switches,
                                 for the same reason the mode above is one value:
                                 a task has two datable things and you should be
                                 able to say "the deadline but not the slot". */ ?>
                        <div style="margin-top:16px;">
                            <div style="font-size:13px;margin-bottom:6px;"><?php echo htmlspecialchars(t('system.preferences.taskcal_heading')); ?></div>
                            <div class="anim-toggle" id="taskCalToggle">
                                <button class="anim-option" data-taskcal="off"><?php echo htmlspecialchars(t('system.preferences.taskcal_off')); ?></button>
                                <button class="anim-option" data-taskcal="work"><?php echo htmlspecialchars(t('system.preferences.taskcal_work')); ?></button>
                                <button class="anim-option" data-taskcal="due"><?php echo htmlspecialchars(t('system.preferences.taskcal_due')); ?></button>
                                <button class="anim-option" data-taskcal="both"><?php echo htmlspecialchars(t('system.preferences.taskcal_both')); ?></button>
                            </div>
                            <p class="pref-hint" style="margin-top:8px;color:var(--text-muted,#666);font-size:12px;">
                                <?php echo htmlspecialchars(t('system.preferences.taskcal_note')); ?>
                            </p>
                        </div>
                    </div>

                    <div id="workCalPanel" style="display:none;margin-top:14px;">
                        <?php /* The SAME dialogue the Calendar module opens — QR code,
                                 editable host for a LAN address, iOS/Android hints and
                                 all. Two different feeds, one identical act, so one
                                 component: includes/subscribe_modal.php. */ ?>
                        <button type="button" class="sig-btn sig-btn-primary" onclick="FreeITSMSubscribe.open('workCal')">
                            <?php echo htmlspecialchars(t('system.preferences.workcal_show')); ?>
                        </button>

                        <div style="margin-top:14px;">
                            <div style="font-size:13px;margin-bottom:6px;"><?php echo htmlspecialchars(t('system.preferences.workcal_detail')); ?></div>
                            <div class="anim-toggle" id="workCalDetailToggle">
                                <button class="anim-option" data-workcaldetail="full"><?php echo htmlspecialchars(t('system.preferences.workcal_detail_full')); ?></button>
                                <button class="anim-option" data-workcaldetail="ref"><?php echo htmlspecialchars(t('system.preferences.workcal_detail_ref')); ?></button>
                            </div>
                            <p class="pref-hint" id="workCalDetailLocked" style="display:none;margin-top:8px;color:var(--text-muted,#666);font-size:12px;">
                                <?php echo htmlspecialchars(t('system.preferences.workcal_detail_locked')); ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- /#general-tab -->

        <div class="tab-content" id="notifications-tab">
            <?php
                // Notification types (discussion #55). Rendered from the service's
                // own registry so adding a type there adds it here — there is no
                // second list to keep in step.
                require_once __DIR__ . '/../../includes/services/notifications.php';
                $notifPrefs = [];
                try {
                    $notifPrefs = NotificationsService::effectivePreferences(connectToDatabase(), (int)$_SESSION['analyst_id']);
                } catch (Exception $e) {
                    // Section simply renders empty rather than breaking the page.
                }
            ?>
            <?php if ($notifPrefs): ?>
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.notif_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.notif_desc')); ?></p>
                <div id="notifTypes" style="display:flex;flex-direction:column;gap:10px;margin-top:12px;max-width:520px;">
                    <?php foreach ($notifPrefs as $key => $on): ?>
                        <!-- No inline layout: .toggle-group in inbox.css is the shared
                             switch component and deliberately puts the caption above the
                             switch (via order:1). Overriding align-items here centred
                             every row against the rest of the page. -->
                        <label class="toggle-group">
                            <span class="toggle-switch">
                                <input type="checkbox" class="notif-type" data-type="<?php echo htmlspecialchars($key); ?>" <?php echo $on ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </span>
                            <span class="toggle-label"><?php echo htmlspecialchars(t('common.notifications.pref.' . $key)); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <span class="pref-saving-hint" id="notifSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>
            <?php endif; ?>

            <!-- Notification chime. Separate from the types above because it is
                 not about WHICH notifications you get, it is about how you are
                 told about the ones you already chose. -->
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.sound_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.sound_desc')); ?></p>
                <select id="notifSoundSelect" class="pref-language-select">
                    <?php foreach (['off', 'chime', 'ping', 'knock'] as $soundKey): ?>
                        <option value="<?php echo htmlspecialchars($soundKey); ?>" <?php echo $prefs['notification_sound'] === $soundKey ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(t('system.preferences.sound_' . $soundKey)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <!-- Hidden rather than disabled when Off is chosen: .btn:disabled
                     is only styled inside lms.css, so a disabled control on this
                     page looks live and reads as a broken button. -->
                <button type="button" class="sig-btn sig-btn-secondary" id="notifSoundPreview" style="margin-left:8px;vertical-align:middle;"><?php echo htmlspecialchars(t('system.preferences.sound_play')); ?></button>
                <span class="pref-saving-hint" id="notifSoundHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
                <p class="pref-hint" style="margin-top:8px;color:var(--text-muted,#666);font-size:12px;"><?php echo htmlspecialchars(t('system.preferences.sound_note')); ?></p>
            </div>

            <!-- Desktop notifications. Beside the chime because it is the same
                 kind of choice: how you are told about what reaches the bell. -->
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.desktop_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.desktop_desc')); ?></p>
                <label class="toggle-group">
                    <span class="toggle-switch">
                        <input type="checkbox" id="desktopNotifToggle" <?php echo $prefs['desktop_notifications'] === 'on' ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </span>
                    <span class="toggle-label"><?php echo htmlspecialchars(t('system.preferences.desktop_label')); ?></span>
                </label>
                <button type="button" class="sig-btn sig-btn-secondary" id="desktopNotifTest" style="margin-top:10px;"><?php echo htmlspecialchars(t('system.preferences.desktop_test')); ?></button>
                <span class="pref-saving-hint" id="desktopNotifHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
                <p class="pref-hint" id="desktopNotifState" style="margin-top:8px;color:var(--text-muted,#666);font-size:12px;"></p>
            </div>

            <!-- Task assignment by email. -->
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.task_email_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.task_email_desc')); ?></p>
                <label class="toggle-group">
                    <span class="toggle-switch">
                        <input type="checkbox" id="taskEmailToggle" <?php echo $prefs['task_assigned_email'] === 'on' ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </span>
                    <span class="toggle-label"><?php echo htmlspecialchars(t('system.preferences.task_email_label')); ?></span>
                </label>
                <span class="pref-saving-hint" id="taskEmailHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>

            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.position_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.position_desc')); ?></p>
                <div class="position-grid" id="toastPositionGrid"></div>
            </div>

            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.animation_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.animation_desc')); ?></p>
                <div class="anim-toggle" id="animToggle">
                    <button class="anim-option" data-anim="slide"><?php echo htmlspecialchars(t('system.preferences.anim_slide')); ?></button>
                    <button class="anim-option" data-anim="fade"><?php echo htmlspecialchars(t('system.preferences.anim_fade')); ?></button>
                </div>
            </div>
        </div><!-- /#notifications-tab -->

        <div class="tab-content" id="display-tab">
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.panels_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.panels_desc')); ?></p>
                <!-- One row per module that has a left panel. Rows are built
                     in JS from SIDEBAR_PANELS so adding a module is a one-line
                     change here + a default in $prefDefaults above. -->
                <div id="sidebarPanelsList" class="sidebar-panels-list"></div>
            </div>

            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.multiselect_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.multiselect_desc')); ?></p>
                <div class="anim-toggle" id="multiSelectPaneToggle">
                    <button class="anim-option" data-msmode="summary"><?php echo htmlspecialchars(t('system.preferences.multiselect_summary')); ?></button>
                    <button class="anim-option" data-msmode="keep"><?php echo htmlspecialchars(t('system.preferences.multiselect_keep')); ?></button>
                    <button class="anim-option" data-msmode="bar"><?php echo htmlspecialchars(t('system.preferences.multiselect_bar')); ?></button>
                </div>
                <p class="pref-hint" id="multiSelectPaneHint" style="margin-top:8px;color:var(--text-muted,#666);font-size:12px;"></p>
            </div>

            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.mc_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.mc_desc')); ?></p>
                <div class="anim-toggle" id="mcFillToggle">
                    <button class="anim-option" data-fill="plain"><?php echo htmlspecialchars(t('system.preferences.fill_plain')); ?></button>
                    <button class="anim-option" data-fill="gradient"><?php echo htmlspecialchars(t('system.preferences.fill_gradient')); ?></button>
                </div>
            </div>
        </div><!-- /#display-tab -->

        <div class="tab-content" id="details-tab">
            <!-- My details + email signatures (discussion #80, request 3).
                 Here rather than on an admin screen because a signature is one
                 person signing their own name — there is deliberately no shared
                 or install-wide signature to administer. -->
            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.details_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.details_desc')); ?></p>
                <div id="detailsManagedNote" class="pref-managed-note" style="display:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                    <span><?php echo htmlspecialchars(t('system.preferences.details_managed_note')); ?></span>
                </div>
                <div class="sig-details-grid">
                    <label><span><?php echo htmlspecialchars(t('system.preferences.details_job_title')); ?></span>
                        <input type="text" id="myJobTitle" autocomplete="off" maxlength="100"></label>
                    <label><span><?php echo htmlspecialchars(t('system.preferences.details_department')); ?></span>
                        <input type="text" id="myDepartment" autocomplete="off" maxlength="100"></label>
                    <label><span><?php echo htmlspecialchars(t('system.preferences.details_phone')); ?></span>
                        <input type="text" id="myPhone" autocomplete="off" maxlength="50"></label>
                    <label><span><?php echo htmlspecialchars(t('system.preferences.details_mobile')); ?></span>
                        <input type="text" id="myMobile" autocomplete="off" maxlength="50"></label>
                </div>
                <button type="button" class="sig-btn sig-btn-primary" id="saveDetailsBtn" onclick="saveMyDetails()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <span class="pref-saving-hint" id="detailsSavingHint"><?php echo htmlspecialchars(t('system.preferences.saving')); ?></span>
            </div>

            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.sig_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.sig_desc')); ?></p>

                <div id="sigList" class="sig-list"></div>

                <button type="button" class="sig-btn sig-btn-primary" onclick="openSignatureEditor()"><?php echo htmlspecialchars(t('common.add')); ?></button>

                <!-- Editor, shown in place rather than in a modal: it holds a rich
                     text box and a live preview, and both want room. -->
                <div id="sigEditor" class="sig-editor" style="display:none;">
                    <input type="hidden" id="sigId">
                    <label class="sig-field"><span><?php echo htmlspecialchars(t('system.preferences.sig_name')); ?></span>
                        <input type="text" id="sigName" autocomplete="off" maxlength="100" placeholder="<?php echo htmlspecialchars(t('system.preferences.sig_name_placeholder')); ?>"></label>

                    <label class="sig-field sig-default-row">
                        <input type="checkbox" id="sigIsDefault">
                        <span><?php echo htmlspecialchars(t('system.preferences.sig_is_default')); ?></span>
                    </label>

                    <textarea id="sigBody"></textarea>

                    <div class="sig-codes" id="sigCodes"></div>

                    <div class="sig-preview-wrap">
                        <div class="sig-preview-label"><?php echo htmlspecialchars(t('system.preferences.sig_preview')); ?></div>
                        <div class="sig-preview" id="sigPreview"></div>
                    </div>

                    <div class="sig-editor-actions">
                        <button type="button" class="sig-btn sig-btn-secondary" onclick="closeSignatureEditor()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                        <button type="button" class="sig-btn sig-btn-primary" onclick="saveSignature()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                    </div>
                </div>
            </div>
        </div><!-- /#details-tab -->
    </div>
    </div><!-- /.settings-shell -->

    <?php
        // The shared subscribe dialogue, same one the Calendar module opens.
        // Rendered only when the install permits subscribe links at all.
        if ($feedAllowed) {
            require_once __DIR__ . '/../../includes/subscribe_modal.php';
            renderSubscribeModal('workCal', [
                'title'         => t('system.preferences.workcal_heading'),
                'intro'         => t('system.preferences.workcal_desc'),
                'insecure'      => t('system.preferences.workcal_insecure'),
                'address_label' => t('calendar.subscribe.address_label'),
                'address_hint'  => t('calendar.subscribe.address_hint'),
                'url_label'     => t('system.preferences.workcal_url'),
                'copy'          => t('system.preferences.workcal_copy'),
                'secret_note'   => t('system.preferences.workcal_secret_note'),
                'ios_label'     => t('calendar.subscribe.ios_label'),
                'ios_hint'      => t('calendar.subscribe.ios_hint'),
                'android_label' => t('calendar.subscribe.android_label'),
                'android_hint'  => t('calendar.subscribe.android_hint'),
                'reset'         => t('system.preferences.workcal_reset'),
                'close'         => t('common.close'),
            ]);
        }
    ?>

    <?php /* The analyst's own name and email, for the signature live preview (#80). */ ?>
    <script>window.__MY_NAME = <?php echo json_encode($_SESSION['analyst_name'] ?? ''); ?>; window.__MY_EMAIL = <?php echo json_encode($_SESSION['analyst_email'] ?? ''); ?>;</script>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <script src="../../assets/js/qrcode.min.js"></script>
    <script src="../../assets/js/subscribe.js?v=1"></script>
    <script src="../../assets/js/tinymce/tinymce.min.js"></script>
    <script>
        // Initial preference values pre-fetched server-side. The page
        // hydrates UI controls from these instead of localStorage.
        const INITIAL_PREFS = <?php echo json_encode($prefs); ?>;

        // ===== Tabs =====
        // Same switcher the module Settings pages use. It also keeps the hash in
        // step, so a panel can be linked to directly: "Manage signatures" in the
        // reply box opens #details rather than dropping you on General to hunt.
        function switchTab(tab) {
            const panel = document.getElementById(tab + '-tab');
            if (!panel) return;                       // unknown hash — leave the default open
            document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            panel.classList.add('active');
            history.replaceState(null, '', '#' + tab);
        }
        if (location.hash.length > 1) switchTab(location.hash.slice(1));

        // Generic save helper — fire-and-forget POST to the per-analyst
        // preference store. Returns a Promise resolving to the API's
        // success flag so call sites can chain UI feedback off it.
        async function savePref(key, value) {
            try {
                const r = await fetch('../../api/system/set_user_preference.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ key: key, value: value })
                });
                const d = await r.json();
                if (d && d.success) {
                    // Reflect the change in window globals (used by toast.js)
                    // so subsequent toasts on THIS page use the new value
                    // without a reload.
                    if (key === 'toast_position')  window.TOAST_POSITION  = value;
                    if (key === 'toast_animation') window.TOAST_ANIMATION = value;
                    if (key === 'notification_sound') window.NOTIFICATION_SOUND = value;
                    return true;
                }
                showToast((d && d.error) || window.t('system.preferences.save_failed'), 'error');
            } catch (e) {
                showToast(window.t('system.preferences.save_failed'), 'error');
            }
            return false;
        }

        // ===== Notification position (toast_position) =====
        const positions = [
            { key: 'top-left',      label: window.t('system.preferences.pos_top_left') },
            { key: 'top-center',    label: window.t('system.preferences.pos_top_center') },
            { key: 'top-right',     label: window.t('system.preferences.pos_top_right') },
            { key: 'middle-left',   label: window.t('system.preferences.pos_middle_left') },
            { key: 'middle-center', label: window.t('system.preferences.pos_middle_center') },
            { key: 'middle-right',  label: window.t('system.preferences.pos_middle_right') },
            { key: 'bottom-left',   label: window.t('system.preferences.pos_bottom_left') },
            { key: 'bottom-center', label: window.t('system.preferences.pos_bottom_center') },
            { key: 'bottom-right',  label: window.t('system.preferences.pos_bottom_right') }
        ];
        const grid = document.getElementById('toastPositionGrid');
        const currentPosition = INITIAL_PREFS.toast_position;
        positions.forEach(pos => {
            const cell = document.createElement('div');
            cell.className = 'position-cell' + (pos.key === currentPosition ? ' active' : '');
            cell.title = pos.label;
            cell.dataset.pos = pos.key;
            const dot = document.createElement('div');
            dot.className = 'position-dot';
            cell.appendChild(dot);
            cell.addEventListener('click', async function() {
                grid.querySelectorAll('.position-cell').forEach(c => c.classList.remove('active'));
                cell.classList.add('active');
                const ok = await savePref('toast_position', pos.key);
                if (ok) showToast(window.t('system.preferences.pos_preview'), 'info');
            });
            grid.appendChild(cell);
        });

        // ===== Interface language (interface_language) =====
        // Persists to user_preferences and reloads so PHP re-renders
        // in the new language.
        const langSelect = document.getElementById('languageSelect');
        const langHint   = document.getElementById('langSavingHint');
        if (langSelect) {
            langSelect.addEventListener('change', async function() {
                langHint.classList.add('show');
                const ok = await savePref('interface_language', langSelect.value);
                if (ok) {
                    window.location.reload();
                } else {
                    langHint.classList.remove('show');
                }
            });
        }

        // ===== Notification types (notification_types) =====
        // Saved as ONE json preference rather than a row per type, because the
        // notification writer reads it on every event and one row keeps that to a
        // single lookup.
        const notifBoxes = document.querySelectorAll('.notif-type');
        const notifHint  = document.getElementById('notifSavingHint');
        if (notifBoxes.length) {
            notifBoxes.forEach(box => box.addEventListener('change', async function () {
                notifHint.classList.add('show');
                const map = {};
                notifBoxes.forEach(b => { map[b.dataset.type] = b.checked ? 1 : 0; });
                await savePref('notification_types', JSON.stringify(map));
                setTimeout(() => notifHint.classList.remove('show'), 1200);
            }));
        }

        // ===== Notification chime (notification_sound) =====
        // Choosing a sound plays it. You pick one of these by hearing it, and
        // making somebody select blind and then hunt for a second button to
        // find out what they chose is a step with no purpose. The Play button
        // is for hearing the same one again, and it plays what is SELECTED
        // rather than what is saved so it works before the save returns.
        const soundSelect  = document.getElementById('notifSoundSelect');
        const soundHint    = document.getElementById('notifSoundHint');
        const soundPreview = document.getElementById('notifSoundPreview');
        function playSelectedSound() {
            if (typeof window.playNotificationSound === 'function') {
                window.playNotificationSound(soundSelect.value);
            }
        }
        function paintSoundPreview() {
            // Nothing to preview when the answer is "no sound", and a Play
            // button that is silent by design reads as one that is broken.
            soundPreview.style.display = soundSelect.value === 'off' ? 'none' : '';
        }
        if (soundSelect && soundPreview) {
            paintSoundPreview();
            soundSelect.addEventListener('change', async function () {
                paintSoundPreview();
                playSelectedSound();
                soundHint.classList.add('show');
                await savePref('notification_sound', soundSelect.value);
                setTimeout(() => soundHint.classList.remove('show'), 1200);
            });
            soundPreview.addEventListener('click', playSelectedSound);
        }

        // ===== How a task opens (tasks_detail_view) =====
        const taskViewSelect = document.getElementById('taskViewSelect');
        const taskViewHint   = document.getElementById('taskViewSavingHint');
        if (taskViewSelect) {
            taskViewSelect.addEventListener('change', async function() {
                taskViewHint.classList.add('show');
                await savePref('tasks_detail_view', taskViewSelect.value);
                setTimeout(() => taskViewHint.classList.remove('show'), 1200);
            });
        }

        // ===== Desktop notifications (desktop_notifications) =====
        // Switching it on asks the BROWSER for permission - that has to happen
        // inside the click, which is why it lives here and not in the bell. If the
        // browser says no, the switch goes back off: a setting that is on but can
        // never show anything would be a promise the page cannot keep.
        const desktopToggle = document.getElementById('desktopNotifToggle');
        const desktopHint   = document.getElementById('desktopNotifHint');
        const desktopState  = document.getElementById('desktopNotifState');
        const desktopTest   = document.getElementById('desktopNotifTest');
        function paintDesktopState() {
            if (!desktopState) return;
            let key;
            if (!('Notification' in window)) key = 'desktop_unsupported';
            else if (!window.isSecureContext) key = 'desktop_insecure';
            else if (Notification.permission === 'denied') key = 'desktop_blocked';
            else if (Notification.permission === 'granted') key = 'desktop_allowed';
            else key = 'desktop_ask';
            desktopState.textContent = window.t('system.preferences.' + key);
            if (desktopTest) desktopTest.hidden = !(desktopToggle.checked && 'Notification' in window && Notification.permission === 'granted');
        }
        if (desktopToggle) {
            paintDesktopState();
            desktopToggle.addEventListener('change', async function () {
                if (desktopToggle.checked) {
                    if (!('Notification' in window) || !window.isSecureContext) {
                        desktopToggle.checked = false; paintDesktopState(); return;
                    }
                    let perm = Notification.permission;
                    if (perm === 'default') {
                        try { perm = await Notification.requestPermission(); } catch (e) { perm = 'denied'; }
                    }
                    if (perm !== 'granted') {
                        desktopToggle.checked = false; paintDesktopState(); return;
                    }
                }
                desktopHint.classList.add('show');
                await savePref('desktop_notifications', desktopToggle.checked ? 'on' : '');
                window.DESKTOP_NOTIFICATIONS = desktopToggle.checked;
                paintDesktopState();
                setTimeout(() => desktopHint.classList.remove('show'), 1200);
            });
        }
        if (desktopTest) {
            desktopTest.addEventListener('click', function () {
                try {
                    const n = new Notification(window.t('system.preferences.desktop_test_title'), {
                        body: window.t('system.preferences.desktop_test_body'),
                        icon: (document.querySelector('link[rel="icon"]') || {}).href,
                        tag: 'freeitsm-test'
                    });
                    n.onclick = () => { window.focus(); n.close(); };
                } catch (e) { paintDesktopState(); }
            });
        }

        // ===== Email me when a task is assigned to me (task_assigned_email) =====
        const taskEmailToggle = document.getElementById('taskEmailToggle');
        const taskEmailHint   = document.getElementById('taskEmailHint');
        if (taskEmailToggle) {
            taskEmailToggle.addEventListener('change', async function () {
                taskEmailHint.classList.add('show');
                await savePref('task_assigned_email', taskEmailToggle.checked ? 'on' : '');
                setTimeout(() => taskEmailHint.classList.remove('show'), 1200);
            });
        }

        // ===== Subtasks under their card on the board (tasks_hide_done_subtasks) =====
        const boardSubtasksToggle = document.getElementById('boardSubtasksToggle');
        const boardSubtasksHint   = document.getElementById('boardSubtasksSavingHint');
        if (boardSubtasksToggle) {
            boardSubtasksToggle.addEventListener('change', async function() {
                boardSubtasksHint.classList.add('show');
                await savePref('tasks_hide_done_subtasks', boardSubtasksToggle.checked ? 'on' : '');
                setTimeout(() => boardSubtasksHint.classList.remove('show'), 1200);
            });
        }

        // ===== Closing the search panel (search_panel_close_outside) =====
        // Stored as 'on' / '' so the value reads the same way as the other
        // opt-in keys, and so an un-migrated or empty row means "off".
        const searchDismissToggle = document.getElementById('searchDismissToggle');
        const searchDismissHint   = document.getElementById('searchDismissSavingHint');
        if (searchDismissToggle) {
            searchDismissToggle.addEventListener('change', async function() {
                searchDismissHint.classList.add('show');
                await savePref('search_panel_close_outside', searchDismissToggle.checked ? 'on' : '');
                // Take effect on this page's own globals too, so a module opened
                // in another tab is the only thing needing a reload.
                window.SEARCH_PANEL_CLOSE_OUTSIDE = searchDismissToggle.checked;
                setTimeout(() => searchDismissHint.classList.remove('show'), 1200);
            });
        }

        // ===== Subtasks on the task calendar (tasks_calendar_subtasks) =====
        const calSubtaskSelect = document.getElementById('calSubtaskSelect');
        const calSubtaskHint   = document.getElementById('calSubtaskSavingHint');
        if (calSubtaskSelect) {
            calSubtaskSelect.addEventListener('change', async function() {
                calSubtaskHint.classList.add('show');
                await savePref('tasks_calendar_subtasks', calSubtaskSelect.value);
                setTimeout(() => calSubtaskHint.classList.remove('show'), 1200);
            });
        }

        // ===== Start page (default_landing_page) =====
        // Saved like any other preference; set_user_preference.php mirrors this
        // one into a cookie so index.php can read it before anyone has logged in.
        const landingSelect = document.getElementById('landingSelect');
        const landingHint   = document.getElementById('landingSavingHint');
        if (landingSelect) {
            landingSelect.addEventListener('change', async function() {
                landingHint.classList.add('show');
                await savePref('default_landing_page', landingSelect.value);
                setTimeout(() => landingHint.classList.remove('show'), 1200);
            });
        }

        // ===== Display timezone (timezone) =====
        // Saves per-analyst; takes effect on other pages via Tz::init() +
        // window.USER_TIMEZONE. No reload needed here (this page shows no dates).
        const tzSelect = document.getElementById('timezoneSelect');
        const tzHint   = document.getElementById('tzSavingHint');
        if (tzSelect) {
            tzSelect.addEventListener('change', async function() {
                tzHint.classList.add('show');
                const ok = await savePref('timezone', tzSelect.value);
                tzHint.classList.remove('show');
                if (ok) showToast(window.t('system.preferences.timezone_saved'), 'success');
            });
        }

        // ===== Date and time format (date_format / time_format) =====
        // Per-analyst, overriding System > Date and time formats. '' is a real
        // value here meaning "follow the install default", so it must be saved
        // as-is rather than treated as "nothing chosen".
        [['dateFormatSelect', 'dateFormatSavingHint', 'date_format'],
         ['timeFormatSelect', 'timeFormatSavingHint', 'time_format']].forEach(function (row) {
            const sel  = document.getElementById(row[0]);
            const hint = document.getElementById(row[1]);
            if (!sel) return;
            sel.addEventListener('change', async function () {
                hint.classList.add('show');
                const ok = await savePref(row[2], sel.value);
                hint.classList.remove('show');
                if (ok) showToast(window.t('system.preferences.format_saved'), 'success');
            });
        });

        // ===== Generic two-button toggle wiring =====
        // Used for animation style, sidebar modes, MC fill — anything
        // that's a simple set of mutually-exclusive options. Pass the
        // toggle root element, the data-* attribute key its buttons
        // carry, the pref key, the initial value, and an optional
        // post-save callback for feedback toasts.
        function wireToggle(rootId, dataAttr, prefKey, initial, onSaved) {
            const root = document.getElementById(rootId);
            if (!root) return;
            const select = (val) => {
                root.querySelectorAll('.anim-option').forEach(b => {
                    b.classList.toggle('active', b.dataset[dataAttr] === val);
                });
            };
            select(initial);
            root.querySelectorAll('.anim-option').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const newValue = btn.dataset[dataAttr];
                    select(newValue);
                    const ok = await savePref(prefKey, newValue);
                    if (ok && onSaved) onSaved(newValue);
                });
            });
        }

        wireToggle('animToggle',      'anim', 'toast_animation',            INITIAL_PREFS.toast_animation,
                   v => showToast(window.t('system.preferences.anim_preview', { anim: v }), 'info'));
        wireToggle('mcFillToggle',    'fill', 'mc_chart_fill_style',        INITIAL_PREFS.mc_chart_fill_style);

        // Tickets inbox multi-select behaviour. The hint text changes with the
        // choice — the three modes are hard to tell apart from their names alone.
        const MULTISELECT_HINTS = {
            summary: window.t('system.preferences.multiselect_summary_hint'),
            keep:    window.t('system.preferences.multiselect_keep_hint'),
            bar:     window.t('system.preferences.multiselect_bar_hint')
        };
        function setMultiSelectHint(v) {
            const el = document.getElementById('multiSelectPaneHint');
            if (el) el.textContent = MULTISELECT_HINTS[v] || '';
        }
        setMultiSelectHint(INITIAL_PREFS.tickets_multiselect_pane);
        wireToggle('multiSelectPaneToggle', 'msmode', 'tickets_multiselect_pane',
                   INITIAL_PREFS.tickets_multiselect_pane, setMultiSelectHint);

        // ===== Left-panel visibility, one toggle per module =====
        // Rows are generated here so the markup stays a single container.
        // Each module's settings page (where one exists) edits the same
        // preference key, and the module header reads it on every page.
        const SIDEBAR_PANELS = [
            { key: 'knowledge_sidebar_mode',         label: window.t('system.preferences.panel_knowledge') },
            { key: 'process_mapper_sidebar_mode',    label: window.t('system.preferences.panel_process_mapper') },
            { key: 'contracts_sidebar_mode',         label: window.t('system.preferences.panel_contracts') },
            { key: 'calendar_sidebar_mode',          label: window.t('system.preferences.panel_calendar') },
            { key: 'tasks_sidebar_mode',             label: window.t('system.preferences.panel_tasks') },
            { key: 'cmdb_sidebar_mode',              label: window.t('system.preferences.panel_cmdb') },
            { key: 'change_management_sidebar_mode', label: window.t('system.preferences.panel_change_management') },
            { key: 'asset_management_sidebar_mode',  label: window.t('system.preferences.panel_asset_management') },
            { key: 'system_wiki_sidebar_mode',       label: window.t('system.preferences.panel_system_wiki') }
        ];
        const ALWAYS_LABEL = window.t('common.left_panel.always');
        const HOVER_LABEL  = window.t('common.left_panel.hover');
        const panelsList = document.getElementById('sidebarPanelsList');
        if (panelsList) {
            SIDEBAR_PANELS.forEach(panel => {
                const row = document.createElement('div');
                row.className = 'sidebar-panel-row';

                const label = document.createElement('span');
                label.className = 'sidebar-panel-label';
                label.textContent = panel.label;

                const toggle = document.createElement('div');
                toggle.className = 'anim-toggle';
                const toggleId = 'panelToggle_' + panel.key;
                toggle.id = toggleId;

                const alwaysBtn = document.createElement('button');
                alwaysBtn.className = 'anim-option';
                alwaysBtn.dataset.mode = 'always';
                alwaysBtn.textContent = ALWAYS_LABEL;

                const hoverBtn = document.createElement('button');
                hoverBtn.className = 'anim-option';
                hoverBtn.dataset.mode = 'hover';
                hoverBtn.textContent = HOVER_LABEL;

                toggle.appendChild(alwaysBtn);
                toggle.appendChild(hoverBtn);
                row.appendChild(label);
                row.appendChild(toggle);
                panelsList.appendChild(row);

                wireToggle(toggleId, 'mode', panel.key, INITIAL_PREFS[panel.key] || 'always');
            });
        }

        // One-shot migration — if the user had old localStorage values
        // for the two toast prefs but no DB row yet (e.g. they're
        // upgrading from before #432), promote them to the DB so the
        // change rides across browsers. We only migrate when the DB
        // value is still the default and localStorage has something,
        // to avoid overwriting a deliberate DB choice with stale
        // browser cache. Then we drop the localStorage entry.
        (function migrateToastPrefs() {
            const lsPos  = localStorage.getItem('toast_position');
            const lsAnim = localStorage.getItem('toast_animation');
            if (lsPos && INITIAL_PREFS.toast_position === 'bottom-right' && lsPos !== 'bottom-right') {
                savePref('toast_position', lsPos);
                localStorage.removeItem('toast_position');
            } else if (lsPos) {
                localStorage.removeItem('toast_position');
            }
            if (lsAnim && INITIAL_PREFS.toast_animation === 'slide' && lsAnim !== 'slide') {
                savePref('toast_animation', lsAnim);
                localStorage.removeItem('toast_animation');
            } else if (lsAnim) {
                localStorage.removeItem('toast_animation');
            }
        })();

        // ==================== My details + signatures (#80) ====================
        // Everything here is the signed-in analyst's own; no endpoint takes an
        // analyst id, so there is nothing to scope in the browser either.

        let sigEditor = null;      // the TinyMCE instance
        let sigState  = { loaded: false, signatures: [], codes: {} };

        async function loadSignatures() {
            try {
                const resp = await fetch('../../api/myaccount/get_signatures.php');
                const data = await resp.json();
                if (!data.success) throw new Error(data.error || 'load failed');

                sigState = { loaded: true, signatures: data.signatures || [], codes: data.merge_codes || {} };

                const p = data.profile || {};
                document.getElementById('myJobTitle').value  = p.job_title  || '';
                document.getElementById('myDepartment').value = p.department || '';
                document.getElementById('myPhone').value      = p.phone      || '';
                document.getElementById('myMobile').value     = p.mobile     || '';
                // An OIDC provider set to sync 'always' rewrites these on every
                // sign-in (#155), so they are read-only here. The server refuses
                // the save too - this only stops anyone typing into them.
                const isManaged = !!p.sso_synced;
                ['myJobTitle', 'myDepartment', 'myPhone', 'myMobile'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (el) {
                        el.disabled = isManaged;
                        if (isManaged) {
                            el.title = window.t ? window.t('system.preferences.details_managed_note') : 'Managed by identity provider';
                            if (el.parentElement) el.parentElement.classList.add('is-disabled');
                        } else {
                            el.removeAttribute('title');
                            if (el.parentElement) el.parentElement.classList.remove('is-disabled');
                        }
                    }
                });
                const saveBtn = document.getElementById('saveDetailsBtn');
                if (saveBtn) saveBtn.style.display = isManaged ? 'none' : '';
                const managedNote = document.getElementById('detailsManagedNote');
                if (managedNote) managedNote.style.display = isManaged ? 'flex' : 'none';

                renderSignatureList();
                renderSignatureCodes();
            } catch (e) {
                // ⚠️ Say so rather than rendering an empty list. "You have no
                // signatures" and "we could not find out" look identical, and the
                // second one would have somebody rewriting a signature they already
                // have — see the unloaded-checkbox problem this app has hit twice.
                sigState.loaded = false;
                document.getElementById('sigList').innerHTML =
                    '<div class="sig-empty sig-error">' + escapeHtmlSig(t('system.preferences.sig_load_failed')) + '</div>';
            }
        }

        function escapeHtmlSig(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        function renderSignatureList() {
            const box = document.getElementById('sigList');
            if (!sigState.signatures.length) {
                box.innerHTML = '<div class="sig-empty">' + escapeHtmlSig(t('system.preferences.sig_empty')) + '</div>';
                return;
            }
            box.innerHTML = sigState.signatures.map(function (s) {
                const badge = Number(s.is_default) === 1
                    ? '<span class="sig-default-badge">' + escapeHtmlSig(t('system.preferences.sig_default')) + '</span>' : '';
                // The rendered form, not the raw one: the point of the list is to see
                // what actually goes out, merge codes already filled in.
                return '<div class="sig-card">'
                     +   '<div class="sig-card-head">'
                     +     '<strong>' + escapeHtmlSig(s.name) + '</strong>' + badge
                     +     '<span class="sig-card-actions">'
                     +       '<button type="button" class="sig-btn sig-btn-secondary" onclick="openSignatureEditor(' + s.id + ')">'
                     +         escapeHtmlSig(t('common.edit')) + '</button>'
                     +       '<button type="button" class="sig-btn sig-btn-danger" onclick="deleteSignature(' + s.id + ')">'
                     +         escapeHtmlSig(t('common.delete')) + '</button>'
                     +     '</span>'
                     +   '</div>'
                     +   '<div class="sig-card-body">' + (s.rendered || '') + '</div>'
                     + '</div>';
            }).join('');
        }

        function renderSignatureCodes() {
            const box = document.getElementById('sigCodes');
            if (!box) return;
            const codes = Object.keys(sigState.codes);
            box.innerHTML = '<span class="sig-codes-label">' + escapeHtmlSig(t('system.preferences.sig_codes')) + '</span>'
                + codes.map(function (c) {
                    return '<button type="button" class="sig-code" title="' + escapeHtmlSig(sigState.codes[c])
                         + '" onclick="insertSignatureCode(\'' + c + '\')">[' + escapeHtmlSig(c) + ']</button>';
                  }).join('');
        }

        function insertSignatureCode(code) {
            if (sigEditor) sigEditor.execCommand('mceInsertContent', false, '[' + code + ']');
        }

        function initSignatureEditor() {
            if (sigEditor || typeof tinymce === 'undefined') return Promise.resolve();
            const isDark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';
            return tinymce.init({
                selector: '#sigBody',
                license_key: 'gpl',
                height: 220,
                menubar: false,
                skin: isDark ? 'oxide-dark' : 'oxide',
                content_css: isDark ? 'dark' : 'default',
                plugins: ['autolink', 'lists', 'link', 'code'],
                toolbar: 'undo redo | bold italic underline | bullist numlist | link | removeformat | code',
                content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; } @media (pointer: coarse) { body { font-size: 16px; } }',
                setup: function (ed) {
                    sigEditor = ed;
                    // Live preview, so the effect of a merge code is visible while
                    // typing rather than only after saving.
                    ed.on('input change keyup SetContent', function () { refreshSignaturePreview(); });
                }
            });
        }

        // The preview substitutes the analyst's OWN details, which the page already
        // holds — including anything typed into the details boxes but not yet saved,
        // so you can see a phone number take effect before committing to it.
        function refreshSignaturePreview() {
            const el = document.getElementById('sigPreview');
            if (!el) return;
            const values = {
                my_name:       (window.__MY_NAME || ''),
                my_email:      (window.__MY_EMAIL || ''),
                my_job_title:  document.getElementById('myJobTitle').value,
                my_department: document.getElementById('myDepartment').value,
                my_phone:      document.getElementById('myPhone').value,
                my_mobile:     document.getElementById('myMobile').value
            };
            let html = sigEditor ? sigEditor.getContent() : '';
            for (const k in values) {
                html = html.split('[' + k + ']').join(escapeHtmlSig(values[k]));
            }
            // Same rule as the server: a code with nothing behind it is removed, not
            // left showing at the bottom of every email you send.
            el.innerHTML = html.replace(/\[my_[a-z_]+\]/g, '');
        }

        async function openSignatureEditor(id) {
            await initSignatureEditor();
            const sig = id ? sigState.signatures.find(function (s) { return Number(s.id) === Number(id); }) : null;
            document.getElementById('sigId').value = sig ? sig.id : '';
            document.getElementById('sigName').value = sig ? sig.name : '';
            document.getElementById('sigIsDefault').checked = sig ? Number(sig.is_default) === 1 : false;
            if (sigEditor) sigEditor.setContent(sig ? sig.body : '');
            document.getElementById('sigEditor').style.display = '';
            refreshSignaturePreview();
            document.getElementById('sigName').focus();
        }

        function closeSignatureEditor() {
            document.getElementById('sigEditor').style.display = 'none';
        }

        async function saveSignature() {
            const body = sigEditor ? sigEditor.getContent() : '';
            const payload = {
                id: document.getElementById('sigId').value || null,
                name: document.getElementById('sigName').value.trim(),
                body: body,
                is_default: document.getElementById('sigIsDefault').checked
            };
            try {
                const resp = await fetch('../../api/myaccount/save_signature.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await resp.json();
                if (!data.success) { alert(data.error); return; }
                closeSignatureEditor();
                await loadSignatures();
            } catch (e) {
                alert(t('system.preferences.sig_save_failed'));
            }
        }

        async function deleteSignature(id) {
            if (!confirm(t('system.preferences.sig_delete_confirm'))) return;
            try {
                await fetch('../../api/myaccount/delete_signature.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                await loadSignatures();
            } catch (e) { /* the reload below will show the real state either way */ }
        }

        async function saveMyDetails() {
            const hint = document.getElementById('detailsSavingHint');
            hint.classList.add('visible');
            try {
                const resp = await fetch('../../api/myaccount/save_profile.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        job_title:  document.getElementById('myJobTitle').value,
                        department: document.getElementById('myDepartment').value,
                        phone:      document.getElementById('myPhone').value,
                        mobile:     document.getElementById('myMobile').value
                    })
                });
                const data = await resp.json();
                if (!data.success) { alert(data.error); return; }
                // Re-read: the saved details change every signature's rendered form,
                // and the list is showing the old one until it is reloaded.
                await loadSignatures();
            } catch (e) {
                alert(t('system.preferences.sig_save_failed'));
            } finally {
                setTimeout(function () { hint.classList.remove('visible'); }, 900);
            }
        }

        // ===== My work calendar (GH #75) =====
        //
        // The URL is minted only when the analyst actually turns the option on.
        // Creating a capability token for everyone who happens to open Preferences
        // would leave working secret links belonging to people who never asked.
        //
        // The dialogue itself is the SAME component the Calendar module opens
        // (assets/js/subscribe.js) — this page only says which endpoint mints its
        // URL and what happens either side of it.
        const WORKCAL_API = '../../api/tickets/get_schedule_feed_url.php';
        const ENROL_API   = '../../api/tickets/calendar_enrolment.php';

        function paintWorkCal(mode) {
            const root = document.getElementById('workCalToggle');
            if (!root) return;                       // switched off install-wide
            root.querySelectorAll('.anim-option').forEach(b => {
                b.classList.toggle('active', b.dataset.workcal === mode);
            });
            document.getElementById('workCalPanel').style.display     = mode === 'feed' ? '' : 'none';
            document.getElementById('workCalPushPanel').style.display = mode === 'push' ? '' : 'none';
        }

        async function loadWorkCalDetail() {
            const r = await fetch(WORKCAL_API, { credentials: 'same-origin' });
            const d = await r.json();
            if (!d.success) return;
            document.getElementById('workCalDetailLocked').style.display = d.detail_locked ? '' : 'none';
            document.querySelectorAll('#workCalDetailToggle .anim-option').forEach(b => {
                b.classList.toggle('active', b.dataset.workcaldetail === d.detail);
                // The organisation capped the detail: show it as already decided
                // rather than as a control that silently ignores you.
                b.disabled = !!d.detail_locked;
                b.style.opacity = d.detail_locked ? '0.5' : '';
                b.style.cursor  = d.detail_locked ? 'default' : '';
            });
        }

        /** Whether the direct option is on offer, and if not, WHY not. */
        async function loadWorkCalState() {
            const d = await (await fetch(ENROL_API, { credentials: 'same-origin' })).json();
            const pushBtn = document.getElementById('workCalPushBtn');
            const why     = document.getElementById('workCalPushWhy');

            if (!d.push_available) {
                // Disabled AND explained. A greyed control with no reason is the
                // thing people file support tickets about.
                pushBtn.disabled = true;
                pushBtn.style.opacity = '0.5';
                pushBtn.style.cursor  = 'default';
                why.textContent = window.t('system.preferences.workcal_push_none');
                why.style.display = '';
            }
            workCalState = d;

            // The chooser. Several connections and none chosen yet starts on
            // "Choose…" rather than quietly on the first: switching on writes
            // real events, and they should go where the analyst meant.
            const conns = d.connections || [];
            const sel   = document.getElementById('workCalConn');
            sel.innerHTML = '';
            if (!d.connection_id) {
                const o = document.createElement('option');
                o.value = ''; o.textContent = window.t('system.preferences.workcal_conn_choose');
                sel.appendChild(o);
            }
            conns.forEach(c => {
                const o = document.createElement('option');
                o.value = String(c.id); o.textContent = c.name;
                sel.appendChild(o);
            });
            sel.value = d.connection_id ? String(d.connection_id) : '';
            document.getElementById('workCalConnRow').style.display = conns.length > 1 ? '' : 'none';

            paintPushTarget();
            paintTaskCal(d.task_mode);
            paintWorkCal(d.mode || 'off');
            if (d.mode === 'feed') await loadWorkCalDetail();
            return d;
        }

        // ── Watchtower "Mine": cards with no owner (#58) ────────────────────
        (function initWtImpersonal() {
            const root = document.getElementById('wtImpersonalToggle');
            if (!root) return;

            const paint = v => root.querySelectorAll('.anim-option').forEach(b =>
                b.classList.toggle('active', b.dataset.wtimp === (v || 'show')));

            // Read the stored value rather than assuming the default, or somebody
            // who chose "hide" sees "keep showing" lit and reasonably concludes
            // their choice was lost.
            fetch('../../api/system/get_user_preference.php?key=watchtower_impersonal',
                  { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => paint(d.value || d.preference_value || 'show'))
                .catch(() => paint('show'));

            root.addEventListener('click', async function (e) {
                const btn = e.target.closest('.anim-option');
                if (!btn) return;
                const previous = root.querySelector('.anim-option.active');
                paint(btn.dataset.wtimp);
                try {
                    const r = await fetch('../../api/system/set_user_preference.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ key: 'watchtower_impersonal', value: btn.dataset.wtimp })
                    }).then(r => r.json());
                    if (!r || !r.success) throw new Error(r && r.error);
                } catch (err) {
                    // Back where it was: a control showing a state the server
                    // refused is a lie about what will happen.
                    paint(previous ? previous.dataset.wtimp : 'show');
                    showToast(window.t('system.preferences.save_failed'), 'error');
                }
            });
        })();
        // ── CalDAV: the analyst's own sign-in and calendar (#133) ───────────
        let workCalState = null;
        let calDavPrevMode = 'off';     // what the toggle said before "push" was pressed

        function calDavMsg(text, bad) {
            const el = document.getElementById('calDavMsg');
            if (!el) return;
            el.textContent = text || '';
            el.style.color = bad ? 'var(--danger-text,#721c24)' : 'var(--text-muted,#666)';
            el.style.display = text ? '' : 'none';
        }

        /** Show what is saved, or the form when nothing is. */
        function paintCalDav(d, forceForm) {
            const panel = document.getElementById('calDavPanel');
            if (!panel) return;
            const c = d.caldav || {};
            panel.style.display = '';
            document.getElementById('calDavIntro').textContent =
                window.t('system.preferences.caldav_intro', { server: c.server_url || '' });
            const saved = !!c.calendar_url;
            document.getElementById('calDavCurrent').style.display = (saved && !forceForm) ? '' : 'none';
            document.getElementById('calDavForm').style.display    = (!saved || forceForm) ? '' : 'none';
            document.getElementById('calDavCurrentText').textContent = saved
                ? window.t('system.preferences.caldav_current', { user: c.username || '', name: c.calendar_name || c.calendar_url })
                : '';
            const user = document.getElementById('calDavUser');
            if (!user.value) user.value = c.username || '';
            document.getElementById('calDavPass').placeholder = c.has_password
                ? window.t('system.preferences.caldav_password_saved') : '';
        }

        /** The connection picked on the screen: the chooser, else the only one. */
        function workCalTarget() {
            const conns = (workCalState && workCalState.connections) || [];
            if (conns.length === 1) return conns[0];
            const id = Number(document.getElementById('workCalConn').value || 0);
            return conns.find(c => c.id === id) || null;
        }

        /**
         * What the push panel says for the picked connection. The one already in
         * use shows where the work goes; a different one shows what setting it
         * up needs - a CalDAV sign-in form, fresh, since a sign-in belongs to
         * one server.
         */
        function paintPushTarget() {
            const d = workCalState;
            if (!d) return;
            const c = workCalTarget();
            const current = !!c && c.id === d.connection_id;
            const info = document.getElementById('workCalPushInfo');
            const panel = document.getElementById('calDavPanel');
            info.textContent = '';
            if (panel) panel.style.display = 'none';
            if (!c) {
                if ((d.connections || []).length > 1) info.textContent = window.t('system.preferences.workcal_conn_needed');
                return;
            }
            if (c.provider === 'caldav') {
                if (current) {
                    // The calendar is named rather than addressed: a web address
                    // means nothing to most people, the calendar's own name does.
                    info.textContent = (d.caldav && d.caldav.calendar_url)
                        ? window.t('system.preferences.workcal_push_caldav_where', { name: d.caldav.calendar_name || d.caldav.calendar_url })
                        : '';
                    paintCalDav(d);
                } else {
                    document.getElementById('calDavUser').value = '';
                    document.getElementById('calDavPass').value = '';
                    document.getElementById('calDavPick').style.display = 'none';
                    paintCalDav({ caldav: { server_url: c.server_url } }, true);
                }
            } else if (current && d.address) {
                info.textContent = window.t('system.preferences.workcal_push_where', { addr: d.address });
            }
        }

        async function calDavPost(body) {
            const target = workCalTarget();
            if (target) body.connection_id = target.id;
            const r = await fetch(ENROL_API, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(body)
            });
            return r.json().catch(() => ({ success: false }));
        }

        (function initCalDav() {
            if (!document.getElementById('calDavPanel')) return;

            document.getElementById('calDavFind').addEventListener('click', async function () {
                calDavMsg(window.t('system.preferences.caldav_finding'));
                this.disabled = true;
                const d = await calDavPost({
                    action: 'caldav_discover',
                    username: document.getElementById('calDavUser').value.trim(),
                    password: document.getElementById('calDavPass').value
                });
                this.disabled = false;
                const pick = document.getElementById('calDavPick');
                if (!d.success) { pick.style.display = 'none'; calDavMsg(d.error || window.t('system.preferences.save_failed'), true); return; }
                if (!(d.calendars || []).length) { pick.style.display = 'none'; calDavMsg(window.t('system.preferences.caldav_none'), true); return; }
                const sel = document.getElementById('calDavCal');
                const current = (workCalState && workCalState.caldav && workCalState.caldav.calendar_url) || '';
                sel.innerHTML = '';
                d.calendars.forEach(c => {
                    const o = document.createElement('option');
                    o.value = c.url; o.textContent = c.name;
                    if (c.url === current) o.selected = true;
                    sel.appendChild(o);
                });
                pick.style.display = '';
                calDavMsg('');
            });

            document.getElementById('calDavSave').addEventListener('click', async function () {
                const active = document.querySelector('#workCalToggle .anim-option.active');
                const turnOn = !!(active && active.dataset.workcal === 'push');
                this.disabled = true;
                const d = await calDavPost({
                    action: 'caldav_save',
                    username: document.getElementById('calDavUser').value.trim(),
                    password: document.getElementById('calDavPass').value,
                    calendar_url: document.getElementById('calDavCal').value,
                    turn_on: turnOn ? '1' : ''
                });
                this.disabled = false;
                if (!d.success) { calDavMsg(d.error || window.t('system.preferences.save_failed'), true); return; }
                document.getElementById('calDavPass').value = '';
                document.getElementById('calDavPick').style.display = 'none';
                // Switching from the subscribe link to this REVOKES the link, the
                // same as the toggle does: a secret URL that still works after you
                // moved away from it is not what anybody means.
                if (turnOn && calDavPrevMode === 'feed') {
                    await fetch(WORKCAL_API, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=revoke'
                    });
                    FreeITSMSubscribe.forget('workCal');
                }
                calDavPrevMode = d.mode || calDavPrevMode;
                await loadWorkCalState();
                // A first write that failed is said here, where it was caused,
                // rather than only on an administrator's screen.
                calDavMsg(d.last_error
                    ? window.t('system.preferences.caldav_saved_error', { error: d.last_error })
                    : window.t('system.preferences.caldav_saved', { name: d.calendar_name || '' }), !!d.last_error);
            });

            document.getElementById('calDavChange').addEventListener('click', function () {
                if (workCalState) paintCalDav(workCalState, true);
            });

            document.getElementById('calDavForget').addEventListener('click', async function () {
                const ok = await showConfirm({
                    title: window.t('system.preferences.caldav_forget_title'),
                    message: window.t('system.preferences.caldav_forget_confirm'),
                    okLabel: window.t('system.preferences.caldav_forget'), okClass: 'danger'
                });
                if (!ok) return;
                const d = await calDavPost({ action: 'caldav_forget' });
                if (!d.success) { calDavMsg(d.error || window.t('system.preferences.save_failed'), true); return; }
                document.getElementById('calDavUser').value = '';
                document.getElementById('calDavPick').style.display = 'none';
                calDavMsg('');
                await loadWorkCalState();
            });
        })();

        /** Paint the task choice (#75). */
        function paintTaskCal(taskMode) {
            const root = document.getElementById('taskCalToggle');
            if (!root) return;
            root.querySelectorAll('.anim-option').forEach(b => {
                b.classList.toggle('active', b.dataset.taskcal === (taskMode || 'off'));
            });
        }

        (function initTaskCal() {
            const root = document.getElementById('taskCalToggle');
            if (!root) return;

            root.addEventListener('click', async function (e) {
                const btn = e.target.closest('.anim-option');
                if (!btn || btn.disabled) return;
                const previous = root.querySelector('.anim-option.active');
                const taskMode = btn.dataset.taskcal;
                paintTaskCal(taskMode);

                // ⚠️ `mode` is sent UNCHANGED alongside. The endpoint takes both
                // on one POST, and omitting the mode would be read as a request
                // to change it — which would switch the whole thing off while
                // the analyst was only choosing what tasks do.
                const currentMode = (document.querySelector('#workCalToggle .anim-option.active') || {}).dataset;
                const r = await fetch(ENROL_API, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        mode: (currentMode && currentMode.workcal) || 'push',
                        task_mode: taskMode
                    })
                });
                const d = await r.json().catch(() => ({}));
                if (!d.success) {
                    // Back where it was: a control showing a state the server
                    // refused is a lie about what is happening.
                    paintTaskCal(previous ? previous.dataset.taskcal : 'off');
                    showToast(d.error || window.t('system.preferences.taskcal_failed'), 'error');
                }
            });
        })();

        (function initWorkCal() {
            const root = document.getElementById('workCalToggle');
            if (!root) return;
            FreeITSMSubscribe.mount('workCal', WORKCAL_API);
            loadWorkCalState();

            // Picking another connection. A CalDAV one needs a sign-in first, so
            // it only shows the form; a Microsoft one moves straight away when
            // "Add to my calendar" is on - it is the same request as pressing it.
            document.getElementById('workCalConn').addEventListener('change', function () {
                paintPushTarget();
                const c = workCalTarget();
                const active = root.querySelector('.anim-option.active');
                if (c && c.provider !== 'caldav' && active && active.dataset.workcal === 'push'
                    && c.id !== (workCalState && workCalState.connection_id)) {
                    document.getElementById('workCalPushBtn').click();
                }
            });

            root.addEventListener('click', async function (e) {
                const btn = e.target.closest('.anim-option');
                if (!btn || btn.disabled) return;
                const mode = btn.dataset.workcal;
                const previous = root.querySelector('.anim-option.active');
                paintWorkCal(mode);

                // The mode is stored server-side, and the server VERIFIES the
                // mailbox before accepting 'push' — so a refusal here is the
                // honest answer rather than a switch that appears on and never
                // does anything.
                const body = { mode: mode };
                const target = workCalTarget();
                if (mode === 'push' && target) body.connection_id = target.id;
                const r = await fetch(ENROL_API, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(body)
                });
                const d = await r.json();
                // Several connections and none picked: open the chooser rather
                // than refusing.
                if (!d.success && d.needs_connection) {
                    calDavPrevMode = previous ? previous.dataset.workcal : 'off';
                    paintPushTarget();
                    document.getElementById('workCalPushWhy').style.display = 'none';
                    return;
                }
                // CalDAV with nothing saved yet: not a refusal but the next step.
                // Keep "Add to my calendar" showing and open the sign-in form;
                // saving it is what switches this on.
                if (!d.success && d.needs_account && workCalState) {
                    calDavPrevMode = previous ? previous.dataset.workcal : 'off';
                    const c = workCalTarget();
                    if (c && c.id === workCalState.connection_id) paintCalDav(workCalState, true);
                    else paintPushTarget();
                    calDavMsg(d.error || window.t('system.preferences.caldav_needs'), false);
                    document.getElementById('workCalPushWhy').style.display = 'none';
                    return;
                }
                if (!d.success) {
                    // Put the control back where it was: leaving it on a state the
                    // server rejected would be a lie about what is happening.
                    paintWorkCal(previous ? previous.dataset.workcal : 'off');
                    // And the chooser back to the connection still in use: the
                    // move did not happen.
                    if (workCalState) {
                        document.getElementById('workCalConn').value = workCalState.connection_id ? String(workCalState.connection_id) : '';
                        paintPushTarget();
                    }
                    const why = document.getElementById('workCalPushWhy');
                    why.textContent = d.bad_address
                        ? window.t('system.preferences.workcal_push_bad', { addr: d.address })
                        : (d.no_address ? window.t('system.preferences.workcal_push_noaddr') : (d.error || ''));
                    why.style.display = '';
                    return;
                }
                document.getElementById('workCalPushWhy').style.display = 'none';
                // The connection and address may have moved with it.
                if (mode === 'push') await loadWorkCalState();

                if (mode === 'feed') {
                    await loadWorkCalDetail();
                } else {
                    // Leaving 'feed' REVOKES the link rather than merely hiding it.
                    // A secret URL that still works after you switched it off is the
                    // opposite of what "off" means.
                    await fetch(WORKCAL_API, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=revoke'
                    });
                    FreeITSMSubscribe.forget('workCal');
                }
            });

            const detail = document.getElementById('workCalDetailToggle');
            detail.addEventListener('click', async function (e) {
                const btn = e.target.closest('.anim-option');
                if (!btn || btn.disabled) return;
                detail.querySelectorAll('.anim-option').forEach(b => b.classList.toggle('active', b === btn));
                await savePref('tickets_schedule_feed_detail', btn.dataset.workcaldetail);
            });
        })();

        document.addEventListener('DOMContentLoaded', loadSignatures);
    </script>
    <script src="../../assets/js/mobile.js?v=78"></script>
</body>
</html>
