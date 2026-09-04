<?php
/**
 * Asset Management — Full-screen table view
 *
 * Thin page over the shared data-table engine (assets/js/data-table.js +
 * assets/css/data-table.css). Read-only: clicking a row deep-links to the
 * split-pane view for that asset. Adds PDF export on top of the shared CSV.
 * The asset-specific columns + loading live in assets/js/asset-table.js.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

requireModuleAccess('assets');

$current_page = 'table';
$path_prefix = '../';
$dtShowPdf = true;
$translationNamespaces = ['common', 'asset-management'];

/**
 * Custom field columns, resolved SERVER-SIDE and handed to asset-table.js as a
 * global.
 *
 * ⚠️ Not fetched by the JS, deliberately. createDataTable() hangs its boot off
 * DOMContentLoaded, so awaiting anything before calling it means the event has
 * already fired and the table never builds at all. Rendering the descriptors
 * with the page removes the race entirely and saves a round trip.
 *
 * Only fields ticked "offer as a column" appear — the catalogue could be large,
 * and a table with forty columns nobody asked for is worse than one with none.
 */
require_once '../includes/services/asset_fields.php';
$assetCustomColumns = [];
try {
    $cfConn = connectToDatabase();
    if (AssetFieldsService::schemaReady($cfConn)) {
        foreach (AssetFieldsService::catalogue($cfConn, (int)$_SESSION['analyst_id']) as $f) {
            if (empty($f['show_in_list'])) {
                continue;
            }
            $assetCustomColumns[] = [
                // 🔑 Prefixed, so a custom field called "model" can never collide
                // with the built-in column of the same name.
                'key'   => 'cf_' . $f['field_key'],
                'label' => $f['label'],
                // The shared engine sorts and filters by these three only.
                'type'  => in_array($f['field_type'], ['number'], true) ? 'number'
                           : ($f['field_type'] === 'date' ? 'date' : 'string'),
            ];
        }
    }
} catch (Exception $e) {
    // A table with no custom columns beats no table at all.
    $assetCustomColumns = [];
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('asset-management.table.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/data-table.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <!-- jsPDF + autotable (same versions as morning-checks) for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <?php /* Mobile-friendly opt-in (#937). Last stylesheet so its @media rules
             win on ties. Every rule inside is gated at 768px. */ ?>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=131">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="dt-page">
        <?php include '../includes/data-table-skeleton.php'; ?>
    </div>

    <script>window.assetCustomColumns = <?php echo json_encode($assetCustomColumns, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/data-table.js?v=6"></script>
    <script src="../assets/js/asset-table.js?v=7"></script>
    <?php /* Loaded last so it can wrap this page's globals; inert on desktop. */ ?>
    <script src="../assets/js/mobile.js?v=54"></script>
</body>
</html>
