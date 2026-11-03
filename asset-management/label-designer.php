<?php
/**
 * Assets -> label designer (GH #229, slice 2).
 *
 * A thin shell: the title bar, ribbon, toolbox, the label, the properties pane and
 * the status bar are built by assets/js/asset-labels/designer.js, which draws the
 * label with the same code that prints it (render.js). Everything the designer
 * needs is handed over here: the design (a template design opens with its items
 * worked out, and becomes a designed one when saved), the sizes, the fields, the
 * fonts, the logo and a few real assets to preview with. Saving goes through
 * api/assets/label_designs.php (action save_items), which cleans every item.
 *
 * ?id=<design>
 *
 * @wiki Asset-Label-Designer-Design
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/rbac.php';
require_once '../includes/tenancy.php';
require_once '../includes/tenant_settings.php';
require_once '../includes/capabilities.php';
require_once '../includes/asset_label_designs.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('assets');

$conn = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
if (!analystHasCapability($conn, $analystId, Cap::ASSETS_LABELS) || !assetLabelDesignsReady($conn) || !assetLabelsSchemaReady($conn)) {
    header('Location: settings/?tab=asset-labels');
    exit;
}
$tenantId = getActiveTenantId($conn, $analystId);
$design = null;
foreach (assetLabelDesignsFor($conn, $tenantId, $analystId) as $d) {
    if ($d['id'] === (int)($_GET['id'] ?? 0) && (int)($d['tenant_id'] ?? 0) === $tenantId) $design = $d;
}
if (!$design) { header('Location: settings/?tab=asset-labels'); exit; }

$settings = assetLabelSettings($conn, $tenantId);
$stocks = [];
foreach (assetLabelStocks() as $k => $st) $stocks[] = ['key' => (string)$k] + $st;

// A few real assets to preview with - their values only. The QR shows a sample
// address: opening the designer must never mint a code for an asset.
$samples = [];
[$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'a');
$st = $conn->prepare("SELECT a.id, a.asset_tag, a.hostname, a.service_tag, a.manufacturer, a.model, t.name AS company_name,
                             at.name AS asset_type_name, al.name AS location_name
                        FROM assets a LEFT JOIN tenants t ON t.id = a.tenant_id LEFT JOIN asset_types at ON at.id = a.asset_type_id
                        LEFT JOIN asset_locations al ON al.id = a.location_id
                       WHERE 1 = 1" . $tSql . " ORDER BY a.asset_tag IS NULL, a.asset_tag, a.hostname LIMIT 40");
$st->execute($tArgs);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $samples[] = ['label' => trim(($a['asset_tag'] ?: '') . ' ' . ($a['hostname'] ?: '')) ?: ('#' . $a['id']), 'values' => assetLabelValues($a, [])];
}
// The made-up one with long values, to see the worst case before printing.
array_unshift($samples, ['label' => t('asset-management.label_designer.sample_long'), 'values' => [
    'asset_tag' => 'AST-2026-000042', 'hostname' => 'LONDON-FINANCE-LAPTOP-0042', 'service_tag' => 'SN9876543210ABCDEF',
    'manufacturer' => 'Hewlett Packard Enterprise', 'model' => 'Hewlett Packard Enterprise EliteBook 860 G11 Notebook PC',
    'company' => 'ZHL Group International Holdings', 'location' => 'London Head Office, Third Floor', 'asset_type' => 'Laptop']]);

$boot = [
    'api'      => '../api/assets/label_designs.php',
    'base'     => BASE_URL,
    'design'   => ['id' => $design['id'], 'name' => $design['name'], 'version' => (int)$design['version'], 'stock' => $design['stock'],
                   'font' => (string)($design['design']['font'] ?? ASSET_LABEL_BUILTIN_FONT),
                   'mono' => assetLabelMono($design, $design['stock']),
                   'template' => (string)($design['design']['template'] ?? 'standard'),
                   'items' => assetLabelItemsFor($design, $design['stock'], $settings)],
    'stocks'   => $stocks,
    'fields'   => assetLabelAvailableFields($conn, $tenantId),
    'captions' => assetLabelPrintFields($conn, $tenantId),
    'fonts'    => array_map(fn($f) => ['id' => (int)$f['id'], 'name' => $f['name']], assetLabelFontsFor($conn, $tenantId)),
    'logo'     => $settings['logo_path'] !== '' ? BASE_URL . ltrim($settings['logo_path'], '/') : null,
    'samples'  => $samples,
    'noTag'    => t('asset-management.labels.no_tag'),
    'sampleUrl'=> BASE_URL . 'a/SAMPLE0000000000000',
];
$current_page = 'settings';
$path_prefix = '../';
$translationNamespaces = ['common', 'asset-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.label_designer.title') . ' - ' . $design['name'])); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../assets/css/label-designer.css?v=1">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
</head>
<body class="ld-body">
    <?php include 'includes/header.php'; ?>
    <div id="ldApp" class="ld-app"></div>
    <script>window.LD = <?php echo json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;</script>
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/qrcode.min.js"></script>
    <script src="../assets/js/asset-labels/render.js?v=3"></script>
    <script src="../assets/js/asset-labels/designer.js?v=2"></script>
</body>
</html>
