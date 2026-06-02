<?php
/**
 * System - Preferences (per-browser + per-analyst settings)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
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
    'toast_position'             => 'bottom-right',
    'toast_animation'            => 'slide',
    'knowledge_sidebar_mode'     => 'always',
    'process_mapper_sidebar_mode'=> 'always',
    'mc_chart_fill_style'        => 'plain',
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
<html lang="<?php echo htmlspecialchars($currentLocale); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.preferences.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .prefs-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            padding: 30px;
        }

        .prefs-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 30px;
        }

        .prefs-card h2 {
            margin: 0 0 6px 0;
            font-size: 20px;
            color: #333;
        }

        .prefs-card .subtitle {
            margin: 0 0 30px 0;
            font-size: 13px;
            color: #888;
        }

        .pref-section {
            margin-bottom: 32px;
        }

        .pref-section:last-child { margin-bottom: 0; }

        .pref-section h3 {
            margin: 0 0 6px 0;
            font-size: 15px;
            color: #333;
        }

        .pref-section p {
            margin: 0 0 16px 0;
            font-size: 13px;
            color: #666;
        }

        .position-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 4px;
            width: 192px;
            height: 128px;
            background: #f0f0f0;
            border: 2px solid #ddd;
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
        .position-cell.active { background: #546e7a; }
        .position-cell.active .position-dot { background: #fff; }

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
            border: 2px solid #ddd;
            border-radius: 6px;
            overflow: hidden;
            width: fit-content;
        }

        .anim-option {
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 500;
            color: #666;
            background: #f5f5f5;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }

        .anim-option:not(:last-child) { border-right: 1px solid #ddd; }
        .anim-option:hover { background: #e8e8e8; }
        .anim-option.active { background: #546e7a; color: #fff; }

        .pref-language-select {
            font-size: 14px;
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            background: #fff;
            min-width: 240px;
            cursor: pointer;
        }
        .pref-language-select:focus { outline: none; border-color: #546e7a; }

        .pref-saving-hint {
            margin-left: 10px;
            font-size: 12px;
            color: #888;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .pref-saving-hint.show { opacity: 1; }
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
                <h3><?php echo htmlspecialchars(t('system.preferences.kb_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.kb_desc')); ?></p>
                <div class="anim-toggle" id="kbSidebarToggle">
                    <button class="anim-option" data-mode="always"><?php echo htmlspecialchars(t('system.preferences.always_visible')); ?></button>
                    <button class="anim-option" data-mode="hover"><?php echo htmlspecialchars(t('system.preferences.show_on_hover')); ?></button>
                </div>
            </div>

            <div class="pref-section">
                <h3><?php echo htmlspecialchars(t('system.preferences.pm_heading')); ?></h3>
                <p><?php echo htmlspecialchars(t('system.preferences.pm_desc')); ?></p>
                <div class="anim-toggle" id="pmSidebarToggle">
                    <button class="anim-option" data-mode="always"><?php echo htmlspecialchars(t('system.preferences.always_visible')); ?></button>
                    <button class="anim-option" data-mode="hover"><?php echo htmlspecialchars(t('system.preferences.show_on_hover')); ?></button>
                </div>
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
    <script src="../../assets/js/i18n.js"></script>
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
        wireToggle('kbSidebarToggle', 'mode', 'knowledge_sidebar_mode',     INITIAL_PREFS.knowledge_sidebar_mode);
        wireToggle('pmSidebarToggle', 'mode', 'process_mapper_sidebar_mode',INITIAL_PREFS.process_mapper_sidebar_mode);
        wireToggle('mcFillToggle',    'fill', 'mc_chart_fill_style',        INITIAL_PREFS.mc_chart_fill_style);

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
