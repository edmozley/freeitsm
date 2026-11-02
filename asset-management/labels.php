<?php
/**
 * QR asset labels — printable PDF/screen sheet generator.
 *
 * Designed to print accurately onto standard commercial A4 label stock.
 *
 * Physical Label Layout (Constrained 5-Zone Model):
 *  1. Optional Header Text (e.g. Company Name / Department)
 *  2. QR Code (with optional centre logo badge and H error-correction capacity)
 *  3. Asset Tag (Always prominent primary ITAM identifier)
 *  4. Configurable Ordered Fields (Hostname, Serial, Model, Custom Fields, etc.)
 *  5. Optional Footer Text (e.g. Helpdesk Extension)
 */

session_start(['read_and_close' => true]);

require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
I18n::initFromSession();
require_once '../includes/rbac.php';
require_once '../includes/tenancy.php';
require_once '../includes/tenant_settings.php';
require_once '../includes/capabilities.php';
require_once '../includes/public_url.php';
require_once '../includes/asset_labels.php';

requireModuleAccess('assets');

$conn = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
$ready = assetLabelsSchemaReady($conn);
$activeTenantId = getActiveTenantId($conn, $analystId);

// Load physical label and QR branding settings for the current context
$labelSettings = assetLabelSettings($conn, $activeTenantId);

// Selected IDs
$ids = [];
if (!empty($_GET['ids'])) {
    foreach (explode(',', (string)$_GET['ids']) as $raw) {
        $id = (int)trim($raw);
        if ($id > 0) $ids[] = $id;
    }
}
$ids = array_slice(array_values(array_unique($ids)), 0, 200);

// Canonical label stock definitions
$sheets = assetLabelSheetSpecs();

// Resolve default sheet key and URL overrides
$sheetsKeys = array_keys($sheets);
$defaultSheetKey = reset($sheetsKeys);
$getSheet = isset($_GET["sheet"]) ? trim((string)$_GET["sheet"]) : "";
$sheetKey = isset($sheets[$getSheet])
    ? (is_numeric($getSheet) ? (int)$getSheet : $getSheet)
    : $defaultSheetKey;
$sheet = $sheets[$sheetKey];
$isPortrait = $sheet['h'] > $sheet['w'];

// CSV for a professional print house (pure asset data contract)
if (!empty($_GET['csv']) && $ready && $ids) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="asset-labels-' . date('Ymd-His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, ['asset_tag', 'hostname', 'service_tag', 'model', 'company_name', 'qr_url'], ',', '"', '');

    $place = implode(',', array_fill(0, count($ids), '?'));
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'a');
    $stmt = $conn->prepare(
        "SELECT a.id, a.asset_tag, a.hostname, a.service_tag, a.manufacturer, a.model,
                t.name AS company_name
           FROM assets a
      LEFT JOIN tenants t ON t.id = a.tenant_id
          WHERE a.id IN ($place)" . $tSql . "
          ORDER BY a.asset_tag IS NULL, a.asset_tag, a.hostname"
    );
    $stmt->execute(array_merge($ids, $tArgs));
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $token = assetEnsureToken($conn, (int)$r['id']);
        $modelStr = trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? ''));
        fputcsv($out, [
            $r['asset_tag'] ?? '',
            $r['hostname'] ?? '',
            $r['service_tag'] ?? '',
            $modelStr,
            $r['company_name'] ?? '',
            $token ? assetLabelUrl($token, $conn) : '',
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

$assets = [];
$customValues = [];

if ($ready && $ids) {
    $place = implode(',', array_fill(0, count($ids), '?'));
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'a');
    $stmt = $conn->prepare(
        "SELECT a.id, a.asset_tag, a.hostname, a.service_tag, a.manufacturer, a.model,
                t.name AS company_name,
                at.name AS asset_type_name,
                al.name AS location_name
           FROM assets a
      LEFT JOIN tenants t ON t.id = a.tenant_id
      LEFT JOIN asset_types at ON at.id = a.asset_type_id
      LEFT JOIN asset_locations al ON al.id = a.location_id
          WHERE a.id IN ($place)" . $tSql . "
          ORDER BY a.asset_tag IS NULL, a.asset_tag, a.hostname"
    );
    $stmt->execute(array_merge($ids, $tArgs));
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assets as &$a) {
        $a['token'] = assetEnsureToken($conn, (int)$a['id']);
        $a['url']   = $a['token'] ? assetLabelUrl($a['token'], $conn) : '';
    }
    unset($a);

    // Fetch custom field values for all displayed assets in one query
    if (!empty($ids)) {
        $placeCF = implode(',', array_fill(0, count($ids), '?'));
        try {
            $cfStmt = $conn->prepare(
                "SELECT v.asset_id, f.field_key,
                        COALESCE(v.value_text, CAST(v.value_number AS CHAR), DATE_FORMAT(v.value_date, '%Y-%m-%d'), CASE WHEN v.value_boolean = 1 THEN 'Yes' WHEN v.value_boolean = 0 THEN 'No' ELSE '' END) AS field_val
                   FROM asset_field_values v
                   JOIN asset_fields f ON f.id = v.field_id
                  WHERE v.asset_id IN ($placeCF) AND f.is_deleted = 0"
            );
            $cfStmt->execute($ids);
            while ($cfr = $cfStmt->fetch(PDO::FETCH_ASSOC)) {
                $customValues[(int)$cfr['asset_id']]['cf_' . $cfr['field_key']] = (string)$cfr['field_val'];
            }
        } catch (Exception $e) { /* table absent fallback */ }
    }
}

$logoUrl = '';
if ($labelSettings['logo_enabled'] && $labelSettings['logo_path'] !== '') {
    $p = ltrim($labelSettings['logo_path'], '/');
    if (file_exists(__DIR__ . '/../' . $p)) {
        $logoUrl = '../' . $p;
    }
}

$selectedFields = $labelSettings['fields'];
$availableCatalogue = assetLabelAvailableFields($conn, $activeTenantId);
$printCatalogue = assetLabelPrintFields($conn, $activeTenantId);
$showFieldLabels = !empty($labelSettings['show_field_labels']);
$labelHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostIsLocal = (bool)preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $labelHost);

// GH #229: label designs, printed as an exact-size PDF. Until Database Verification
// has made their tables, the sheet below prints exactly as it always has.
require_once '../includes/asset_label_designs.php';
if ($ready && assetLabelDesignsReady($conn)) {
    require __DIR__ . '/includes/labels_designs_view.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.labels.browser_title'))); ?></title>
    <script src="../assets/js/qrcode.min.js"></script>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f6fa; color: #222; }
        .bar { background: #546e7a; color: #fff; padding: 12px 18px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .bar h1 { font-size: 16px; margin: 0; font-weight: 600; }
        .bar label { font-size: 13px; }
        .bar select, .bar button, .bar a.btn {
            font-size: 13px; padding: 7px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.4);
            background: rgba(255,255,255,0.18); color: #fff; cursor: pointer; text-decoration: none;
        }
        .bar select option {
            background-color: #ffffff;
            color: #222222;
        }
        .bar button.primary { background: #fff; color: #37474f; font-weight: 600; border-color: #fff; }
        .hint { padding: 12px 18px; font-size: 13px; color: #555; background: #fff8e1; border-bottom: 1px solid #ffe0a3; }
        .sheet { padding: 14px; }
        .labels {
            display: grid;
            grid-template-columns: repeat(<?php echo (int)$sheet['cols']; ?>, <?php echo $sheet['w']; ?>mm);
            gap: 0;
        }
        .label {
            width: <?php echo $sheet['w']; ?>mm;
            height: <?php echo $sheet['h']; ?>mm;
            padding: 1.2mm 1.5mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            background: #fff;
            border: 1px dashed #cfd6dd;
            box-sizing: border-box;
            line-height: 1.15;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .label-header {
            font-size: <?php echo max(4.8, min(7.5, $sheet['h'] / 4.2)); ?>pt;
            font-weight: 600;
            color: #444;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 0.5mm;
            margin-left: calc(<?php echo $sheet['qr']; ?>mm + 3mm);
            width: calc(100% - <?php echo $sheet['qr']; ?>mm - 4.5mm);
        }
        .label-body {
            display: flex;
            align-items: center;
            gap: 1.5mm;
            flex: 1;
            min-height: 0;
        }
        .label .qr {
            position: relative;
            flex: 0 0 auto;
            width: <?php echo $sheet['qr']; ?>mm;
            height: <?php echo $sheet['qr']; ?>mm;
            background: #fff;
        }
        .label .qr img.qr-img {
            width: 100%;
            height: 100%;
            image-rendering: pixelated;
            display: block;
        }
        .qr-logo-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 22%;
            height: 22%;
            background: #ffffff !important;
            padding: 1.5%;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 2px;
            pointer-events: none;
        }
        .qr-logo-overlay img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }
        .label .txt {
            min-width: 0;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow: hidden;
        }
        .label .tag {
            font-weight: 700;
            font-size: <?php echo max(6.2, min(13, $sheet['qr'] / 2.5)); ?>pt;
            letter-spacing: 0.3px;
            color: #000;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .label .sub {
            font-size: <?php echo max(4.8, min(8.5, $sheet['qr'] / (3.2 + count($selectedFields) * 0.35))); ?>pt;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.15;
            margin-top: 0.3mm;
        }
        .label.is-portrait .label-body {
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 1.2mm;
        }
        .label.is-portrait .qr {
            margin: 0 auto;
        }
        .label.is-portrait .txt {
            width: 100%;
            text-align: center;
            align-items: center;
            justify-content: flex-start;
        }
        .label.is-portrait .tag {
            width: 100%;
            text-align: center;
        }
        .label.is-portrait .label-header, .label.is-portrait .label-footer { margin-left: 0; width: 100%; text-align: center; }
        .label.is-portrait .sub {
            width: 100%;
            text-align: center;
        }
        .label .empty-tag { color: #888; font-weight: 400; }
        .label-footer {
            font-size: <?php echo max(4.5, min(7, $sheet['h'] / 4.8)); ?>pt;
            color: #555;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 0.5mm;
            margin-left: calc(<?php echo $sheet['qr']; ?>mm + 3mm);
            width: calc(100% - <?php echo $sheet['qr']; ?>mm - 4.5mm);
        }

        @media print {
            body { background: #fff; }
            .bar, .hint { display: none !important; }
            .sheet { padding: 0; }
            .label { border: none !important; page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="bar">
    <h1><?php echo htmlspecialchars(t('asset-management.labels.heading')); ?></h1>
    <label>
        <?php echo htmlspecialchars(t('asset-management.labels.sheet_label')); ?>
        <select
            id="sheetSelector"
            name="sheet"
            autocomplete="off"
            onchange="window.location.href='labels.php?ids=<?php echo htmlspecialchars(implode(',', $ids)); ?>&sheet=' + encodeURIComponent(this.value);"
        >
            <?php foreach ($sheets as $k => $info): ?>
                <option value="<?php echo $k; ?>" <?php echo $k === $sheetKey ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(t('asset-management.labels.sheet_option', ['n' => $k, 'dims' => $info['dims']])); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="primary" onclick="window.print()"><?php echo htmlspecialchars(t('asset-management.labels.print')); ?></button>
    <?php if ($ids): ?>
        <a class="btn" href="?ids=<?php echo urlencode(implode(',', $ids)); ?>&sheet=<?php echo $sheetKey; ?>&csv=1">
            <?php echo htmlspecialchars(t('asset-management.labels.csv')); ?>
        </a>
    <?php endif; ?>
    <span style="font-size:12px; opacity:0.8; margin-left:auto;">
        <?php echo htmlspecialchars(t(count($assets) === 1 ? 'asset-management.labels.count_one' : 'asset-management.labels.count_many', ['n' => count($assets)])); ?>
    </span>
</div>

<?php if ($ready && $hostIsLocal): ?>
    <div class="hint" style="background:#fdeceb;border-bottom-color:#f5c6cb;color:#8a1f1a;">
        <strong><?php echo t('asset-management.labels.localhost_warn_title', ['host' => '<code>' . htmlspecialchars($labelHost) . '</code>']); ?></strong>
        <?php echo t('asset-management.labels.localhost_warn_body'); ?>
    </div>
<?php endif; ?>

<?php if (!$ready): ?>
    <div class="hint"><?php echo t('asset-management.labels.not_ready'); ?></div>
<?php elseif (!$ids): ?>
    <div class="hint"><?php echo t('asset-management.labels.no_ids'); ?></div>
<?php elseif (!$assets): ?>
    <div class="hint"><?php echo htmlspecialchars(t('asset-management.labels.none_visible')); ?></div>
<?php else: ?>
    <div class="hint">
        <?php echo htmlspecialchars(t('asset-management.labels.alignment_hint')); ?>
    </div>
<?php endif; ?>

<div class="sheet">
    <?php if (!$ready || !$ids || !$assets): ?>
        <div style="text-align: center; margin: 10vh auto; max-width: 500px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <div style="background: #fff; border: 1px solid #cfd6dd; border-radius: 8px; padding: 40px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                <div style="color: #f5b041; margin-bottom: 16px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h3 style="font-size: 18px; font-weight: 600; margin: 0 0 8px; color: #2c3e50;">
                    <?php if (!$ready) echo htmlspecialchars(t('asset-management.labels.not_ready_heading'));
                          elseif (!$ids) echo htmlspecialchars(t('asset-management.labels.no_ids_heading'));
                          else echo htmlspecialchars(t('asset-management.labels.none_visible_heading')); ?>
                </h3>
                <p style="font-size: 13px; color: #7f8c8d; line-height: 1.5; margin: 0 0 20px;">
                    <?php if (!$ready) echo htmlspecialchars(t('asset-management.labels.not_ready_body'));
                          elseif (!$ids) echo htmlspecialchars(t('asset-management.labels.no_ids_body'));
                          else echo htmlspecialchars(t('asset-management.labels.none_visible_body')); ?>
                </p>
                <a href="../" style="display: inline-block; background: #37474f; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer;">
                    <?php echo htmlspecialchars(t('asset-management.labels.back_to_inbox')); ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="labels" id="labels">
        <?php foreach ($assets as $a): ?>
            <div class="label<?php echo $isPortrait ? ' is-portrait' : ''; ?>">
                <?php if ($labelSettings['title'] !== ''): ?>
                    <div class="label-header"><?php echo htmlspecialchars($labelSettings['title']); ?></div>
                <?php endif; ?>
                <div class="label-body">
                    <div class="qr"
                         data-url="<?php echo htmlspecialchars($a['url']); ?>"
                         data-has-logo="<?php echo $logoUrl !== '' ? '1' : '0'; ?>"
                         data-logo-src="<?php echo htmlspecialchars($logoUrl); ?>"
                         data-ec-level="<?php echo assetLabelQrEcLevel($logoUrl !== ''); ?>"></div>
                    <div class="txt">
                        <?php foreach ($selectedFields as $fk):
                            if ($fk === 'asset_tag') {
                                $tagVal = $a['asset_tag'] ?? '';
                                ?>
                                <div class="tag<?php echo empty($tagVal) ? ' empty-tag' : ''; ?>">
                                    <?php echo htmlspecialchars($tagVal ?: t('asset-management.labels.no_tag')); ?>
                                </div>
                                <?php
                                continue;
                            }
                            $subVal = '';
                            switch ($fk) {
                                case 'hostname':
                                    $subVal = $a['hostname'] ?? '';
                                    break;
                                case 'service_tag':
                                    $subVal = $a['service_tag'] ?? '';
                                    break;
                                case 'manufacturer':
                                    $subVal = $a['manufacturer'] ?? '';
                                    break;
                                case 'model':
                                    $subVal = trim(($a['manufacturer'] ?? '') . ' ' . ($a['model'] ?? ''));
                                    break;
                                case 'company':
                                    $subVal = $a['company_name'] ?? '';
                                    break;
                                case 'location':
                                    $subVal = $a['location_name'] ?? '';
                                    break;
                                case 'asset_type':
                                    $subVal = $a['asset_type_name'] ?? '';
                                    break;
                                default:
                                    if (str_starts_with($fk, 'cf_')) {
                                        $subVal = $customValues[(int)$a['id']][$fk] ?? '';
                                    }
                                    break;
                            }
                            if ($subVal !== ''):
                                $fieldLabel = trim((string)($printCatalogue[$fk] ?? ''));
                                $displayLine = ($showFieldLabels && $fieldLabel !== '') ? $fieldLabel . ': ' . $subVal : $subVal;
                        ?>
                            <div class="sub"><?php echo htmlspecialchars($displayLine); ?></div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
                <?php if ($labelSettings['footer'] !== ''): ?>
                    <div class="label-footer"><?php echo htmlspecialchars($labelSettings['footer']); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<script>
/**
 * Render QR codes client-side:
 *  - No logo: uses standard error-correction 'M'
 *  - Centre logo enabled: automatically uses error-correction 'H' (higher error-correction capacity)
 *    and overlays a white-backed logo badge constrained to 22% dimensions.
 */
document.querySelectorAll('.qr').forEach(function (box) {
    var url = box.getAttribute('data-url');
    var hasLogo = box.getAttribute('data-has-logo') === '1';
    var logoSrc = box.getAttribute('data-logo-src') || '';
    if (!url) {
        box.textContent = '—';
        return;
    }
    try {
        var ecLevel = box.getAttribute('data-ec-level') || (hasLogo ? 'H' : 'M');
        var qr = qrcode(0, ecLevel);
        qr.addData(url);
        qr.make();

        var imgHtml = qr.createImgTag(4, 0);
        box.innerHTML = imgHtml;
        var imgEl = box.querySelector('img');
        if (imgEl) {
            imgEl.className = 'qr-img';
        }

        if (hasLogo && logoSrc) {
            var overlay = document.createElement('div');
            overlay.className = 'qr-logo-overlay';
            var logoImg = document.createElement('img');
            logoImg.src = logoSrc;
            logoImg.alt = 'Logo';
            overlay.appendChild(logoImg);
            box.appendChild(overlay);
        }
    } catch (e) {
        box.textContent = '!';
    }
});
</script>
</body>
</html>
