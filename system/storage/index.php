<?php
/**
 * System - Storage (#284): the install's one S3-compatible connection, and where each store
 * keeps its files - its folder on this server, or that bucket. Switching a store moves its
 * files in the background (includes/storage/store_migration.php). Files keeps its own switch
 * on Files -> Settings -> Storage and uses this connection.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
Tz::init();

$current_page = 'storage';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
$h = fn(string $k, array $p = []) => htmlspecialchars(t($k, $p));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.storage.title'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <style>
        body { --accent: var(--sys-accent, #546e7a); --accent-hover: var(--sys-accent-hover, #37474f); --on-accent: var(--sys-on-accent, #fff); }
        .main-container { flex: 1; background: var(--app-bg, #f5f7fa); overflow-y: auto; }
        .st-container { max-width: 980px; margin: 0 auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 24px 0; }
        .st-card { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .st-card h3 { margin: 0 0 4px 0; font-size: 16px; color: var(--text, #333); }
        .st-hint { margin: 0 0 14px 0; font-size: 13px; color: var(--text-dim, #777); }
        .st-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
        .st-field { display: flex; flex-direction: column; gap: 4px; min-width: 200px; flex: 1; }
        .st-field label { font-size: 12px; font-weight: 600; color: var(--text-dim, #666); }
        .st-field input, select.st-select { padding: 8px 10px; border: 1px solid var(--border, #ccc); border-radius: 5px; font-size: 14px;
                           background: var(--input-bg, #fff); color: var(--text, #333); }
        .st-check { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text, #333); }
        .st-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn { padding: 8px 16px; border-radius: 5px; border: 1px solid var(--border, #ccc); background: var(--surface, #fff); color: var(--text, #333); cursor: pointer; font-size: 14px; }
        .btn:disabled { opacity: .55; cursor: default; }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
        .btn-primary:hover:not(:disabled) { background: var(--accent-hover); }
        .btn-small { padding: 4px 10px; font-size: 13px; }
        .st-msg { font-size: 13px; }
        .st-msg.ok { color: var(--success, #2e7d32); }
        .st-msg.err { color: var(--danger, #c62828); white-space: pre-line; }
        .st-status { font-size: 13px; color: var(--text, #333); margin-bottom: 12px; }
        .st-warning { background: var(--warn-bg, #fff8e1); border: 1px solid var(--warn-border, #ffe082); color: var(--text, #5d4037);
                      border-radius: 6px; padding: 10px 12px; font-size: 13px; margin-bottom: 14px; }
        [data-theme-mode="dark"] .st-warning { background: #3a3220; border-color: #6d5a2a; color: var(--text); }
        .st-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .st-table th, .st-table td { text-align: left; padding: 10px 6px; border-bottom: 1px solid var(--border, #eee); vertical-align: top; }
        .st-table th { color: var(--text-dim, #666); font-weight: 600; }
        .st-name { font-weight: 600; color: var(--text, #333); }
        .st-desc { color: var(--text-dim, #777); font-size: 12px; margin-top: 2px; }
        .st-move { margin-top: 8px; font-size: 12px; color: var(--text, #333); }
        .st-move .st-actions { margin-top: 6px; }
        .st-where { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .st-hidden { display: none !important; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="storage">
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="st-container">
            <h1 class="page-title"><?php echo $h('system.storage.title'); ?></h1>
            <p class="page-subtitle"><?php echo $h('system.storage.subtitle'); ?></p>

            <div id="stLoading"><?php echo $h('system.storage.loading'); ?></div>
            <div id="stContent" class="st-hidden">
                <div id="stScheduler" class="st-warning st-hidden"><?php echo $h('system.storage.scheduler_off'); ?></div>

                <!-- 1. The connection -->
                <div class="st-card">
                    <h3><?php echo $h('system.storage.conn_heading'); ?></h3>
                    <p class="st-hint"><?php echo $h('system.storage.conn_hint'); ?></p>
                    <div id="stSource" class="st-status"></div>
                    <div id="stLocked" class="st-warning st-hidden"><?php echo $h('system.storage.place_locked'); ?></div>
                    <div class="st-row">
                        <div class="st-field"><label for="stEndpoint"><?php echo $h('system.storage.endpoint'); ?></label><input id="stEndpoint" type="text" placeholder="https://s3.us-east-1.amazonaws.com" autocomplete="off" spellcheck="false"></div>
                        <div class="st-field"><label for="stRegion"><?php echo $h('system.storage.region'); ?></label><input id="stRegion" type="text" placeholder="us-east-1" autocomplete="off" spellcheck="false"></div>
                    </div>
                    <div class="st-row">
                        <div class="st-field"><label for="stBucket"><?php echo $h('system.storage.bucket'); ?></label><input id="stBucket" type="text" autocomplete="off" spellcheck="false"></div>
                        <div class="st-field"><label for="stPrefix"><?php echo $h('system.storage.prefix'); ?></label><input id="stPrefix" type="text" placeholder="freeitsm/" autocomplete="off" spellcheck="false"></div>
                    </div>
                    <div class="st-row">
                        <div class="st-field"><label for="stKey"><?php echo $h('system.storage.access_key'); ?></label><input id="stKey" type="text" autocomplete="off" spellcheck="false"></div>
                        <div class="st-field"><label for="stSecret"><?php echo $h('system.storage.secret'); ?></label><input id="stSecret" type="password" autocomplete="new-password"></div>
                    </div>
                    <div class="st-row"><label class="st-check"><input id="stPathStyle" type="checkbox"> <?php echo $h('system.storage.path_style'); ?></label></div>
                    <div class="st-actions">
                        <button class="btn" id="stTest" type="button"><?php echo $h('system.storage.test'); ?></button>
                        <button class="btn btn-primary" id="stSave" type="button"><?php echo $h('common.save'); ?></button>
                        <span class="st-msg" id="stConnMsg"></span>
                    </div>
                </div>

                <!-- 2. Where each store keeps its files -->
                <div class="st-card">
                    <h3><?php echo $h('system.storage.stores_heading'); ?></h3>
                    <p class="st-hint"><?php echo $h('system.storage.stores_hint'); ?></p>
                    <table class="st-table">
                        <thead><tr>
                            <th><?php echo $h('system.storage.col_store'); ?></th>
                            <th><?php echo $h('system.storage.col_holds'); ?></th>
                            <th><?php echo $h('system.storage.col_where'); ?></th>
                        </tr></thead>
                        <tbody id="stStores"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script>window.ST_API = '<?php echo $path_prefix; ?>api/system/storage.php'; window.ST_FILES_SETTINGS = '<?php echo $path_prefix; ?>files/settings/?tab=storage';</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <script src="../../assets/js/system-storage.js?v=2"></script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
