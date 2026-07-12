<?php
/**
 * LMS settings — currently just the AI provider used by the course editor.
 *
 * Follows the tickets/settings shell (pinned header, scrolling full-width
 * container, shared inbox.css primitives) so it reads like every other module's
 * settings page rather than inventing its own.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ai_settings_panel.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/theme.php';
require_once __DIR__ . '/../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('lms');

$current_page = 'settings';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'lms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('lms.settings.heading')); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=22">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=37">
    <style>
        /* Pin the shared accent to the LMS blue so the tabs, buttons and focus
           rings read on-brand, as every other settings page does. */
        body { --accent: var(--lms-accent, #2563eb); --accent-hover: var(--lms-accent-hover, #1d4ed8); }

        /* Same shell as tickets/settings: header pinned, .container scrolls, full width.
           margin:0 is essential — inbox.css gives .container `margin: 30px auto`, and auto
           side margins on a flex item suppress the stretch, so it would shrink and centre. */
        .settings-shell { display: flex; flex-direction: column; height: 100vh; }
        .container { flex: 1 1 auto; min-height: 0; overflow-y: auto; max-width: none; width: 100%; margin: 0; padding: 24px 32px 40px; box-sizing: border-box; }
        .container > h1 { font-size: 1.5rem; margin: 0 0 18px; }
        .tab-content > p { margin-bottom: 14px; max-width: 720px; line-height: 1.6; }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=1"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/i18n.js?v=2"></script>
</head>
<body>
    <div class="settings-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="container">
        <h1><?php echo htmlspecialchars(t('lms.settings.heading')); ?></h1>

        <div class="tabs">
            <button class="tab active" data-tab="ai"><?php echo htmlspecialchars(t('lms.settings.tab_ai')); ?></button>
        </div>

        <div class="tab-content active" id="tab-ai">
            <h2 style="margin-top:0;"><?php echo htmlspecialchars(t('lms.settings.tab_ai')); ?></h2>
            <p style="color: var(--text-muted, #555);"><?php echo htmlspecialchars(t('lms.settings.ai_intro')); ?></p>
            <?php renderAiSettingsPanel('lms_ai'); ?>
        </div>
    </div>
    </div><!-- /.settings-shell -->

    <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/confirm.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/ai-settings.js"></script>
</body>
</html>
