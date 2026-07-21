<?php
/**
 * System - Preferences (per-browser + per-analyst settings)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

$current_page = 'preferences';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
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
    'toast_position'             => 'bottom-right',
    'toast_animation'            => 'slide',
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
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.preferences.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
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

        .prefs-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            padding: 30px;
        }

        .prefs-card {
            background: var(--surface, #fff);
            border-radius: 10px;
            box-shadow: 0 2px 8px var(--shadow, rgba(0,0,0,0.06));
            padding: 30px;
        }

        .prefs-card h2 {
            margin: 0 0 6px 0;
            font-size: 20px;
            color: var(--text, #333);
        }

        .prefs-card .subtitle {
            margin: 0 0 30px 0;
            font-size: 13px;
            color: var(--text-dim, #888);
        }

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
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="prefs-container">
        <div class="prefs-card">
            <h2><?php echo htmlspecialchars(t('system.preferences.title')); ?></h2>
            <p class="subtitle"><?php echo htmlspecialchars(t('system.preferences.subtitle')); ?></p>

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
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script>
        // Initial preference values pre-fetched server-side. The page
        // hydrates UI controls from these instead of localStorage.
        const INITIAL_PREFS = <?php echo json_encode($prefs); ?>;

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
    </script>
</body>
</html>
