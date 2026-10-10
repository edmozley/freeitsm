<?php
/**
 * Files → Settings.
 *
 * Two tabs, two capabilities (files/settings/manifest.php). Only the tabs this
 * analyst may use are rendered at all; api/files/settings.php checks the same
 * capability again for every read and write.
 *
 *   Folders  every folder that exists - including ones the viewer cannot open -
 *            with Take ownership, the way back into an orphaned folder.
 *   Storage  where the bytes live on the server, and the largest upload.
 *
 * Behaviour is in assets/js/files-settings.js.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

require_once '../../includes/settings_manifest.php';
requireModuleAccess('files');

$settingsManifest = settingsManifestFor('files');
$visibleTabs      = settingsVisibleTabs(connectToDatabase(), (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);
if (!empty($_GET['tab']) && settingsTabVisible($visibleTabs, (string) $_GET['tab'])) {
    $activeTabId = (string) $_GET['tab'];
}

$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'files'];
$tt = fn(string $k) => htmlspecialchars(t('files.settings.' . $k));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('files.title') . ' - ' . t('files.nav.settings')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=78">
    <style>
        /* Full-width settings page. ⚠️ max-width alone is not enough: inbox.css's
           `.container { margin: 30px auto }` would keep the gutters. */
        .container { height: calc(100vh - 62px); overflow-y: auto; max-width: none; width: 100%; margin: 0; padding: 16px 30px 30px; box-sizing: border-box; }
        .tab:hover { color: var(--fil-accent); }
        .tab.active { color: var(--fil-accent); border-bottom-color: var(--fil-accent); }
        .set-row { display: grid; grid-template-columns: 300px 1fr; gap: 20px; padding: 16px 0; border-bottom: 1px solid var(--border-soft); }
        .set-row:last-of-type { border-bottom: none; }
        .set-row .lbl { font-weight: 600; font-size: 14px; color: var(--text); }
        .set-row .desc { font-size: 12.5px; color: var(--text-dim); margin-top: 4px; line-height: 1.5; }
        .set-row input[type="text"], .set-row input[type="number"] {
            padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; width: 100%; max-width: 520px;
            background: var(--surface); color: var(--text); box-sizing: border-box; font-family: inherit;
        }
        .set-row .dflt { font-size: 11.5px; color: var(--text-dim); margin-top: 5px; }
        .set-actions { display: flex; gap: 10px; align-items: center; margin-top: 18px; }
        .set-box { background: var(--surface-2); border: 1px solid var(--border-soft); border-radius: 8px; padding: 14px 16px; font-size: 13px; margin: 6px 0 16px; line-height: 1.55; }
        .set-box.warn { background: var(--warning-bg); border-color: var(--warning-border); color: var(--warning-text); }
        .set-box code { word-break: break-all; }
        .fil-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface); color: var(--text); cursor: pointer; font: inherit; font-size: 13px; }
        .fil-btn.primary { background: var(--fil-accent); border-color: var(--fil-accent); color: var(--fil-on-accent); }
        .fil-btn:disabled { opacity: .5; cursor: default; }
        .fil-folders { width: 100%; border-collapse: collapse; font-size: 13px; }
        .fil-folders th { text-align: left; font-weight: 600; color: var(--text-muted); padding: 8px 10px; border-bottom: 1px solid var(--border); }
        .fil-folders td { padding: 8px 10px; border-bottom: 1px solid var(--border-soft); }
        .fil-folders tr.orphan td:first-child { color: var(--danger-text); }
        .fil-pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11.5px; background: var(--surface-2); color: var(--text-muted); }
        .fil-pill.full { background: var(--fil-accent-soft); color: var(--fil-accent); }
        .fil-filter { padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px; width: 320px; max-width: 100%; background: var(--surface); color: var(--text); font: inherit; margin-bottom: 10px; }
        @media (max-width: 900px) { .set-row { grid-template-columns: 1fr; gap: 8px; } }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=192">
</head>
<body data-mobile-module="files" data-mobile-page="settings">
    <?php include '../includes/header.php'; ?>
    <div class="container">
        <?php if (!$visibleTabs): ?>
            <div class="set-box"><strong><?php echo $tt('no_tabs_title'); ?></strong><br><?php echo $tt('no_tabs_body'); ?></div>
        <?php else: renderSettingsTabBar($visibleTabs, $activeTabId); endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'folders')): ?>
        <div class="tab-content<?php echo $activeTabId === 'folders' ? ' active' : ''; ?>" id="folders-tab" data-capability="<?php echo Cap::FILES_FOLDERS; ?>">
            <h2 style="margin:0 0 4px;font-size:18px"><?php echo $tt('folders_title'); ?></h2>
            <div class="set-box"><?php echo $tt('folders_intro'); ?></div>
            <input type="search" class="fil-filter" id="folFilter" placeholder="<?php echo $tt('folders_filter'); ?>">
            <table class="fil-folders">
                <thead><tr><th><?php echo $tt('col_folder'); ?></th><th><?php echo $tt('col_entries'); ?></th><th><?php echo $tt('col_files'); ?></th><th><?php echo $tt('col_inherits'); ?></th><th><?php echo $tt('col_your_access'); ?></th><th></th></tr></thead>
                <tbody id="folBody"></tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'storage')): ?>
        <div class="tab-content<?php echo $activeTabId === 'storage' ? ' active' : ''; ?>" id="storage-tab" data-capability="<?php echo Cap::FILES_STORAGE; ?>">
            <h2 style="margin:0 0 4px;font-size:18px"><?php echo $tt('storage_title'); ?></h2>
            <div class="set-box" id="stoState"></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('storage_root'); ?></div><div class="desc"><?php echo $tt('storage_root_desc'); ?></div></div>
                <div><input type="text" id="stoRoot" spellcheck="false"><div class="dflt" id="stoRootDefault"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('max_upload'); ?></div><div class="desc"><?php echo $tt('max_upload_desc'); ?></div></div>
                <div><input type="number" id="stoMax" min="1" style="width:160px"> MB<div class="dflt"><?php echo $tt('max_upload_default'); ?></div></div></div>
            <div class="set-box warn"><?php echo $tt('storage_move_warning'); ?></div>
            <div class="set-actions"><button type="button" class="fil-btn primary" id="stoSave"><?php echo htmlspecialchars(t('files.btn.save')); ?></button><span id="stoMsg" style="font-size:13px"></span></div>
        </div>
        <?php endif; ?>
    </div>
    <script>window.FILES_API = <?php echo json_encode(BASE_URL . 'api/files/'); ?>;</script>
    <script src="../../assets/js/files-settings.js?v=1"></script>
    <script src="../../assets/js/mobile.js?v=78"></script>
</body>
</html>
