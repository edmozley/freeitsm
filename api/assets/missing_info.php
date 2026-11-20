<?php
/**
 * Assets: missing information (GH #404).
 *
 *   GET                       the list: every asset the reader can see that lacks a field its
 *                             type expects. ?type_id= ?field= ?section= narrow it.
 *   GET  ?asset_id=N          one asset: what its type expects, what is missing, what is Not applicable
 *   POST {asset_id, field, not_applicable: bool}   mark / unmark one field Not applicable
 *
 * Reading needs access to Assets; marking needs write access to Assets and access to that
 * asset. Findings are worked out live (includes/services/asset_completeness.php), so there is
 * nothing to close: fill the field in and it is gone from the list.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/asset_completeness.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();   // labels for the built-in fields

header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!isset($_SESSION['analyst_id'])) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('assets');

$conn = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
if (!AssetCompleteness::ready($conn)) {
    echo json_encode(['success' => false, 'error' => t('asset-management.missing.needs_verify')]);
    exit;
}

/** Human labels for every key in play: built-in from the language file, custom from asset_fields. */
function missingLabels(PDO $conn, array $keys): array
{
    $out = []; $customIds = [];
    foreach (array_unique($keys) as $k) {
        if (isset(AssetCompleteness::BUILTIN[$k])) $out[$k] = t('asset-management.missing.field_' . $k);
        elseif (preg_match('/^custom:(\d+)$/', $k, $m)) $customIds[(int)$m[1]] = $k;
    }
    if ($customIds) {
        $in = implode(',', array_keys($customIds));
        foreach ($conn->query("SELECT id, label FROM asset_fields WHERE id IN ($in)")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $label) $out[$customIds[(int)$id]] = $label;
    }
    return $out;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfRequireWriteMethod();
        requireModuleWriteJson('assets');
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $assetId = (int)($in['asset_id'] ?? 0);
        $key = (string)($in['field'] ?? '');
        if ($assetId < 1 || AssetCompleteness::sectionOf($key) === null) throw new InvalidArgumentException('asset_id and field are required');
        if (!analystCanAccessAsset($conn, $analystId, $assetId)) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Asset not found']); exit; }
        $label = missingLabels($conn, [$key])[$key] ?? $key;
        AssetCompleteness::setNotApplicable($conn, $assetId, $key, !empty($in['not_applicable']), $analystId, $label);
        echo json_encode(['success' => true] + AssetCompleteness::forAsset($conn, $analystId, $assetId));
        exit;
    }

    if (isset($_GET['asset_id'])) {
        $assetId = (int)$_GET['asset_id'];
        if (!analystCanAccessAsset($conn, $analystId, $assetId)) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Asset not found']); exit; }
        $one = AssetCompleteness::forAsset($conn, $analystId, $assetId);
        echo json_encode(['success' => true, 'labels' => missingLabels($conn, $one['expected']),
            'can_write' => analystModuleLevel($conn, $analystId, 'assets') === 'full'] + $one);
        exit;
    }

    $filters = array_filter([
        'type_id' => (int)($_GET['type_id'] ?? 0),
        'field'   => (string)($_GET['field'] ?? ''),
        'section' => in_array($_GET['section'] ?? '', AssetCompleteness::SECTIONS, true) ? $_GET['section'] : '',
    ]);
    $rows = AssetCompleteness::findings($conn, $analystId, $filters);
    $types = $conn->query('SELECT id, name FROM asset_types')->fetchAll(PDO::FETCH_KEY_PAIR);
    $keys = [];
    foreach ($rows as &$r) { $r['type'] = $types[$r['type_id']] ?? ''; $keys = array_merge($keys, $r['missing']); }
    unset($r);
    // Every expected key, for the field filter - not just the ones missing right now.
    $allExpected = $conn->query('SELECT DISTINCT field_key FROM asset_type_expected_fields')->fetchAll(PDO::FETCH_COLUMN);
    $expectedTypes = $conn->query('SELECT DISTINCT e.asset_type_id, t.name FROM asset_type_expected_fields e JOIN asset_types t ON t.id = e.asset_type_id ORDER BY t.name')->fetchAll(PDO::FETCH_KEY_PAIR);
    echo json_encode([
        'success'  => true,
        'rows'     => $rows,
        'labels'   => missingLabels($conn, array_merge($keys, $allExpected)),
        'sections' => array_combine($allExpected, array_map([AssetCompleteness::class, 'sectionOf'], $allExpected)) ?: [],
        'types'    => $expectedTypes,
        'can_write'=> analystModuleLevel($conn, $analystId, 'assets') === 'full',   // read | full - only full may mark Not applicable
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('missing_info: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong']);
}
