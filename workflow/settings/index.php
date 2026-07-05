<?php
/**
 * Workflows — Settings page.
 *
 * Single tab for now (AI integration); the tabs scaffold is in place so
 * further per-module settings (defaults, retention policy, etc.) can be
 * dropped in without re-architecting the page.
 *
 * Visual chrome mirrors tickets/settings exactly (shared inbox.css
 * primitives + tab strip + form-group layout) so the page feels like the
 * rest of the product. AI key is encrypted at rest via the existing
 * `workflow_ai_api_key` entry in ENCRYPTED_SETTING_KEYS and returned to
 * the client as a "****<last4>" mask only.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/ai_settings_panel.php';   // defines renderAiSettingsPanel()
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'settings';
$path_prefix  = '../../';

$translationNamespaces = ['common', 'workflow'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('workflow.title') . ' — ' . t('workflow.nav.settings')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/workflow.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js"></script>
    <style>
        /* Module accent — drives the tab hover/active colour and any future
           modal form-field focus rings / toggle on-state (--accent fallback
           in inbox.css). Workflow's accent is amber #f59e0b. */
        body { --accent: #f59e0b; }
        .tab:hover { color: #f59e0b; }
        .tab.active { color: #f59e0b; border-bottom-color: #f59e0b; }

        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; }
        /* Amber-tinted SSL warning callout, scoped to the workflow settings
           page so it doesn't leak into other modules' SSL-verify rows. */
        .wfs-ssl-warning {
            margin-top: 8px;
            padding: 10px 14px;
            background: #fef2f2;
            border-left: 3px solid #dc2626;
            color: #7f1d1d;
            border-radius: 4px;
            font-size: 12.5px;
            line-height: 1.55;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <!-- Tabs strip (one tab for now; ready to grow) -->
        <div class="tabs">
            <button class="tab active" data-tab="ai"><?php echo htmlspecialchars(t('workflow.settings_tabs.ai')); ?></button>
        </div>

        <!-- AI tab -->
        <div class="tab-content active" id="ai-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('workflow.ai_settings.title')); ?></h2>
            </div>
            <p style="color:#666; font-size:13px; margin: 0 0 20px 0; max-width: 720px;">
                <?php echo htmlspecialchars(t('workflow.ai_settings.intro')); ?>
            </p>

            <div style="max-width: 640px;">
                <?php renderAiSettingsPanel('workflow_ai'); ?>
            </div>
        </div>
    </div>

    <script src="../../assets/js/ai-settings.js"></script>
</body>
</html>
