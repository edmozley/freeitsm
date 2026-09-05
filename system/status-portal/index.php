<?php
/**
 * System — Service status on the portal (discussion #99).
 *
 * What end users see about outages: whether incidents appear on the self-service
 * portal at all, and how far back.
 *
 * 🔴 The switch is OFF until somebody turns it on here, and that is the point of
 * having a screen. Turning it on publishes incident TITLES and every update
 * marked external. Titles were written when nobody expected a customer to read
 * them, so this has to be a decision somebody makes with their eyes open rather
 * than something an upgrade does for them.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
Tz::init();

$current_page = 'status-portal';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

// Auth before any output, so a redirect never hits "headers already sent".
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}
requireModuleAccess('system');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.status_portal.heading')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <style>
        /* System accent, pinned with --on-accent alongside: System's accent is a
           LIGHT colour, so without it buttons render white-on-light. */
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        /* Full width, edge to edge — the house style for settings screens. */
        .sp-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            width: 100%;
            max-width: none;
            margin: 0;
            box-sizing: border-box;
            padding: 24px 32px 40px;
        }
        .sp-header { margin-bottom: 22px; }
        .sp-header h2 { margin: 0; font-size: 22px; color: var(--text, #333); }
        .sp-header p  { margin: 5px 0 0 0; font-size: 13px; color: var(--text-dim, #888); max-width: 820px; line-height: 1.55; }

        /* inbox.css's .btn-primary sets only background and colour, so on its own
           it renders a coloured rectangle with no padding or radius. */
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all .15s; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover:not(:disabled) { background: #455a64; }
        .btn:disabled { opacity: .55; cursor: progress; }

        .sp-panel {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 18px;
            max-width: 820px;
        }
        .sp-panel h3 {
            margin: 0 0 4px 0; font-size: 13px; text-transform: uppercase;
            letter-spacing: .5px; color: var(--text-dim, #888);
        }
        .sp-panel > p { margin: 0 0 16px; font-size: 13px; color: var(--text-muted, #666); line-height: 1.55; }

        .sp-toggle { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 4px; }
        .sp-toggle input { margin-top: 3px; }
        .sp-toggle-label { font-size: 14px; font-weight: 600; color: var(--text, #333); }
        .sp-toggle-hint  { font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.5; margin-top: 3px; }

        .sp-choices { margin-top: 18px; }
        .sp-choice { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-top: 1px solid var(--border-soft, #f1f1f1); }
        .sp-choice input { margin-top: 3px; }
        .sp-choice-name { font-size: 13.5px; font-weight: 600; color: var(--text, #333); }
        .sp-choice-desc { font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.5; }
        .sp-days { margin-left: 6px; width: 64px; padding: 4px 6px; font: inherit; font-size: 13px;
                   border: 1px solid var(--border, #ddd); border-radius: 4px;
                   background: var(--surface, #fff); color: var(--text, #333); }
        /* Everything below the master switch is meaningless while it is off, so
           it dims rather than disappearing — somebody deciding whether to turn it
           on should be able to see what they would be turning on. */
        .sp-disabled { opacity: .45; pointer-events: none; }

        .sp-warn {
            background: var(--warning-bg, #fef3c7);
            border: 1px solid var(--warning-border, #f0d9a8);
            color: var(--warning-text, #92400e);
            border-radius: 8px; padding: 14px 16px; margin-bottom: 18px;
            font-size: 13px; line-height: 1.55; max-width: 820px;
        }
        .sp-saved { font-size: 13px; color: var(--success-text, #166534); margin-left: 12px; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="system" data-mobile-page="status-portal">
    <?php include '../includes/header.php'; ?>

    <div class="sp-container">
        <div class="sp-header">
            <h2><?php echo htmlspecialchars(t('system.status_portal.heading')); ?></h2>
            <p><?php echo t('system.status_portal.intro'); ?></p>
        </div>

        <div class="sp-warn"><?php echo t('system.status_portal.warning'); ?></div>

        <div class="sp-panel">
            <h3><?php echo htmlspecialchars(t('system.status_portal.panel_heading')); ?></h3>

            <label class="sp-toggle">
                <input type="checkbox" id="spEnabled" onchange="syncEnabled()">
                <span>
                    <span class="sp-toggle-label"><?php echo htmlspecialchars(t('system.status_portal.enable_label')); ?></span>
                    <span class="sp-toggle-hint"><?php echo t('system.status_portal.enable_hint'); ?></span>
                </span>
            </label>

            <div class="sp-choices" id="spChoices">
                <label class="sp-choice">
                    <input type="radio" name="spMode" value="open">
                    <span>
                        <span class="sp-choice-name"><?php echo htmlspecialchars(t('system.status_portal.mode_open')); ?></span><br>
                        <span class="sp-choice-desc"><?php echo t('system.status_portal.mode_open_desc'); ?></span>
                    </span>
                </label>
                <label class="sp-choice">
                    <input type="radio" name="spMode" value="recent">
                    <span>
                        <span class="sp-choice-name">
                            <?php echo htmlspecialchars(t('system.status_portal.mode_recent')); ?>
                            <input type="number" id="spDays" class="sp-days" min="1" max="365" value="7">
                            <?php echo htmlspecialchars(t('system.status_portal.mode_recent_days')); ?>
                        </span><br>
                        <span class="sp-choice-desc"><?php echo t('system.status_portal.mode_recent_desc'); ?></span>
                    </span>
                </label>
                <label class="sp-choice">
                    <input type="radio" name="spMode" value="all">
                    <span>
                        <span class="sp-choice-name"><?php echo htmlspecialchars(t('system.status_portal.mode_all')); ?></span><br>
                        <span class="sp-choice-desc"><?php echo t('system.status_portal.mode_all_desc'); ?></span>
                    </span>
                </label>
            </div>

            <div style="margin-top:18px;">
                <button class="btn btn-primary" id="spSave" onclick="saveSettings()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <span class="sp-saved" id="spSaved" hidden><?php echo htmlspecialchars(t('system.status_portal.saved')); ?></span>
            </div>
        </div>
    </div>

    <script>
        const API = '../../api/system/service_status_portal.php';

        function syncEnabled() {
            // Dimmed rather than hidden: somebody deciding whether to switch it
            // on can still read what they would be switching on.
            document.getElementById('spChoices').classList.toggle(
                'sp-disabled', !document.getElementById('spEnabled').checked);
        }

        async function load() {
            try {
                const d = await fetch(API).then(r => r.json());
                if (!d.success) return;
                document.getElementById('spEnabled').checked = !!d.updates;
                document.getElementById('spDays').value = d.days || 7;
                const radio = document.querySelector(`input[name="spMode"][value="${d.mode}"]`)
                           || document.querySelector('input[name="spMode"][value="recent"]');
                radio.checked = true;
                syncEnabled();
            } catch (e) { /* the form keeps its safe defaults */ }
        }

        async function saveSettings() {
            const btn = document.getElementById('spSave');
            btn.disabled = true;
            try {
                const d = await fetch(API, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        updates: document.getElementById('spEnabled').checked,
                        mode:    (document.querySelector('input[name="spMode"]:checked') || {}).value || 'recent',
                        days:    parseInt(document.getElementById('spDays').value, 10) || 7,
                    }),
                }).then(r => r.json());

                if (!d.success) {
                    if (typeof showToast === 'function') showToast(d.error || 'Could not save', 'error');
                    return;
                }
                // Show what was STORED, not what was sent — the day count is
                // clamped server-side and the form should admit it.
                document.getElementById('spDays').value = d.days;
                const saved = document.getElementById('spSaved');
                saved.hidden = false;
                setTimeout(() => { saved.hidden = true; }, 2500);
            } catch (e) {
                if (typeof showToast === 'function') showToast('Could not save', 'error');
            } finally {
                btn.disabled = false;
            }
        }

        load();
    </script>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
