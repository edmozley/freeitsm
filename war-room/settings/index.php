<?php
/**
 * War room settings — the two decisions an administrator owns.
 *
 * Retention is applied on WRITE (see warRoomPrune), not by a scheduled job, so
 * there is nothing to configure beyond the dropdown and nothing that can be left
 * un-set-up on the day it matters.
 *
 * The situation report is the one part of the module that needs the internet, so
 * the page says that in as many words. Leaving it unconfigured is a perfectly
 * good answer and the chat is unaffected either way.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/ai_settings_panel.php';
require_once '../../includes/warroom.php';
require_once '../../includes/timezone.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../includes/settings_manifest.php';
requireModuleAccess('war-room');
I18n::initFromSession();
Tz::init();

// RBAC Layer 2: only the tabs this analyst may see are rendered.
$settingsManifest = settingsManifestFor('war-room');
$visibleTabs      = settingsVisibleTabs(connectToDatabase(), (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'war-room'];

$conn    = connectToDatabase();
$current = warRoomRetentionDays($conn);

// 0 is "keep forever" and is the default — a fallback tool should not quietly
// delete the record of an incident because nobody chose a number.
$choices = [0, 7, 30, 90, 180, 365];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('war-room.settings.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <style>
        body { --accent: var(--war-room-accent, #ea580c); --accent-hover: var(--war-room-accent-hover, #c2410c); }
        /* Full-width settings page, matching the canonical settings layout. */
        .container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
            margin: 0;
            padding: 16px 30px 24px;
        }
        .tab:hover  { color: var(--war-room-accent, #ea580c); }
        .tab.active { color: var(--war-room-accent, #ea580c); border-bottom-color: var(--war-room-accent, #ea580c); }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; min-height: 34px; }
        .section-header h2 { margin: 0; font-size: 18px; color: var(--text, #333); }
        .wr-set-form { max-width: 460px; }
        .wr-set-form label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: var(--text, #333); }
        .wr-set-form select { width: 100%; padding: 8px 12px; border: 1px solid var(--border, #ddd); border-radius: 4px; font-size: 14px; box-sizing: border-box; background: var(--surface, #fff); color: var(--text, #333); }
        .wr-set-hint { margin-top: 6px; font-size: 12px; color: var(--text-dim, #888); }
        .wr-set-actions { margin-top: 18px; }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=127">
    <script src="../../assets/js/i18n.js?v=2"></script>
</head>
<body data-mobile-page="settings">
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <?php renderSettingsTabBar($visibleTabs, $activeTabId); ?>

        <?php if (settingsTabVisible($visibleTabs, 'retention')): ?>
        <div class="tab-content<?php echo $activeTabId === 'retention' ? ' active' : ''; ?>" id="retention-tab" data-capability="<?php echo Cap::WAR_ROOM_MANAGE; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('war-room.settings.heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('war-room.settings.intro')); ?></p>

            <form class="wr-set-form" id="wrSettingsForm" autocomplete="off" onsubmit="event.preventDefault(); saveRetention();">
                <label for="wrRetention"><?php echo htmlspecialchars(t('war-room.settings.retention_label')); ?></label>
                <select id="wrRetention">
                    <?php foreach ($choices as $d): ?>
                        <option value="<?php echo $d; ?>"<?php echo $d === $current ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($d === 0
                                ? t('war-room.settings.retention_forever')
                                : t('war-room.settings.retention_days', ['count' => $d])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="wr-set-hint"><?php echo htmlspecialchars(t('war-room.settings.retention_hint')); ?></div>
                <div class="wr-set-actions">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('war-room.settings.save')); ?></button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'ai')): ?>
        <div class="tab-content<?php echo $activeTabId === 'ai' ? ' active' : ''; ?>" id="ai-tab" data-capability="<?php echo Cap::WAR_ROOM_MANAGE; ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('war-room.settings.ai_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 8px;"><?php echo htmlspecialchars(t('war-room.settings.ai_intro')); ?></p>
            <!-- Said plainly on the page rather than only in the code: the room
                 works without this, and during a real outage it may well not be
                 reachable. Better that an administrator reads that here than
                 discovers it mid-incident. -->
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('war-room.settings.ai_caveat')); ?></p>

            <?php renderAiSettingsPanel('warroom_ai'); ?>
        </div>
        <?php endif; ?>
    </div>

    <script src="../../assets/js/ai-settings.js?v=2"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/mobile.js?v=50"></script>
    <script>
        // ⚠️ The tab bar is rendered by the shared helper but SWITCHING is each
        // page's own job — renderSettingsTabBar() only emits the buttons. This
        // page had one tab until the AI settings arrived, so nothing was needed
        // and nothing was noticed; the second tab is what exposed it.
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            const panel = document.getElementById(tab + '-tab');
            if (panel) panel.classList.add('active');
        }

        // Saved through the generic settings writer. That endpoint refuses any
        // key nobody owns, and this key's owner is declared in the module's
        // settings manifest — so the tab that shows it and the capability that
        // guards it cannot drift apart.
        async function saveRetention() {
            const days = document.getElementById('wrRetention').value;
            try {
                const r = await fetch('<?php echo BASE_URL; ?>api/settings/save_system_settings.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    // The generic writer expects a `settings` wrapper, not the
                    // bare keys — it loops over $data['settings'].
                    body: JSON.stringify({ settings: { warroom_retention_days: days } })
                });
                const d = await r.json();
                if (!d || !d.success) throw new Error('save failed');
                if (typeof showToast === 'function') showToast(window.t('war-room.settings.saved'), 'success');
                else alert(window.t('war-room.settings.saved'));
            } catch (e) {
                if (typeof showToast === 'function') showToast(window.t('war-room.settings.save_failed'), 'error');
                else alert(window.t('war-room.settings.save_failed'));
            }
        }
    </script>
</body>
</html>
