<?php
/**
 * System — Locations (GH #311).
 *
 * The organisation's sites, buildings, floors and rooms: one tree, per company,
 * with locations that can be shared by every company (2.6.0). It is the same
 * list Assets has always used (`asset_locations`), now kept here because it is
 * no longer only about kit - a form can ask "which site?", and people, tickets
 * and CMDB objects are next.
 *
 * Assets -> Settings -> Locations keeps the same editor (assets/js/locations-tree.js)
 * for the asset managers who look after it there, some of whom are not System
 * administrators, with a note pointing here. Same endpoints, same rules - see
 * locationsRequireAccessJson() in includes/asset_locations.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
$current_page = 'locations';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
// asset-management: the editor's own strings (form, buttons, messages) were
// already translated there, so they are reused rather than copied.
$translationNamespaces = ['common', 'system', 'asset-management'];
requireModuleAccess('system');
$lT = fn(string $k) => t('system.locations.' . $k);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', $lT('title'))); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../../assets/css/locations-tree.css?v=2">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
            display: flex; flex-direction: column;
        }
        /* Full width, like the other System settings pages. flex:1 inside a flex
           column, so the page scrolls rather than being cut off. */
        /* Layout: assets/css/system-page.css (.sys-page, .sys-page-head). Only the scroll box is local. */
        .lc-container { flex: 1; min-height: 0; overflow-y: auto; }
        .lc-uses { margin: 0 0 18px; font-size: 13px; color: var(--text-muted, #555); line-height: 1.55; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        .btn-secondary { background: var(--surface-2, #eceff1); color: var(--text, #333); }
        .btn-secondary:hover { background: var(--border, #cfd8dc); }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="locations">
    <?php include '../includes/header.php'; ?>
    <div class="lc-container sys-page">
        <div class="sys-page-head">
            <div>
                <h1 class="sys-title"><?php echo htmlspecialchars($lT('title')); ?></h1>
                <p class="sys-subtitle"><?php echo t('asset-management.settings.locations_intro'); ?></p>
            </div>
            <button type="button" class="btn btn-primary" id="lcAddBtn"><?php echo htmlspecialchars(t('common.add')); ?></button>
        </div>
        <p class="lc-uses"><?php echo $lT('uses'); ?></p>
        <div id="locations-tree"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></div>
    </div>

    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js"></script>
    <script src="../../assets/js/locations-tree.js?v=1"></script>
    <script>
    (function () {
        var tree = LocationsTree.mount(document.getElementById('locations-tree'), { apiBase: '../../api/assets/' });
        document.getElementById('lcAddBtn').addEventListener('click', function () { tree.add(); });
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
