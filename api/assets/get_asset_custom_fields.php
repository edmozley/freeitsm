<?php
/**
 * API: the custom fields that apply to ONE asset, with its values.
 *
 * Returns the fields grouped by the set they arrive through, plus `via` so the
 * page can show a set attached to this one asset as a removable chip — the
 * answer to "why does this TV have a field the type does not?" before anybody
 * has to ask.
 *
 * 🔑 A field with no value comes back as value === null, NOT omitted and NOT
 * defaulted. Absent means NOT SET, and the caller must be able to tell that
 * apart from "no" or zero.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/asset_fields.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

/**
 * Dropdown options for a field, memoised for the life of the request.
 *
 * @param int $fieldId  the asset_custom_fields id whose options are wanted
 */
function assetFieldOptions(PDO $conn, int $fieldId): array
{
    static $cache = [];
    if (!isset($cache[$fieldId])) {
        $stmt = $conn->prepare(
            "SELECT option_value, colour FROM asset_field_options WHERE field_id = ? ORDER BY display_order, id"
        );
        $stmt->execute([$fieldId]);
        $cache[$fieldId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $cache[$fieldId];
}

try {
    $conn    = connectToDatabase();
    $assetId = (int)($_GET['asset_id'] ?? 0);
    if ($assetId <= 0) {
        throw new Exception('asset_id is required');
    }
    // Multi-tenancy: only an asset in this analyst's companies, as every sibling get_asset_* endpoint.
    if (!analystCanAccessAsset($conn, (int)$_SESSION['analyst_id'], $assetId)) {
        throw new Exception('Asset not found');
    }

    // ⚠️ Told, not implied: an install that has pulled the update but not run
    // Database Verification must not have this render as "no custom fields".
    if (!AssetFieldsService::schemaReady($conn)) {
        echo json_encode([
            'success' => true, 'schema_ready' => false,
            'sets' => [], 'available_sets' => [], 'filled' => 0, 'total' => 0,
        ]);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, asset_type_id FROM assets WHERE id = ?");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) {
        throw new Exception('Asset not found');
    }
    $typeId = $asset['asset_type_id'] !== null ? (int)$asset['asset_type_id'] : null;

    $sets   = AssetFieldsService::setsForAsset($conn, $assetId, $typeId);
    $defs   = AssetFieldsService::fieldsForAsset($conn, $assetId, $typeId);
    $values = AssetFieldsService::valuesForAsset($conn, $assetId, $defs);

    // Reference values need names. Resolved HERE, not in the engine: deciding
    // who may see the referenced row is module business, every time.
    $labels = [];
    $byKind = [];
    foreach ($defs as $key => $d) {
        if ($d['type'] === 'ref' && isset($values[$key]) && $d['ref_kind']) {
            $byKind[$d['ref_kind']][] = (int)$values[$key];
        }
    }
    foreach ($byKind as $kind => $ids) {
        $labels[$kind] = TypedFields::refLabels($conn, $kind, $ids);
    }

    // Group the fields under the set they arrive through, keeping set order.
    $grouped = [];
    foreach ($sets as $s) {
        $grouped[(int)$s['id']] = [
            'id'          => (int)$s['id'],
            'name'        => $s['name'],
            'description' => $s['description'],
            'via'         => $s['via'],          // 'type' | 'asset'
            'fields'      => [],
        ];
    }

    $filled = 0;
    $total  = 0;
    foreach ($defs as $key => $d) {
        $setId = $d['set_id'];
        if ($setId === null || !isset($grouped[$setId])) {
            continue;
        }
        $has = array_key_exists($key, $values);
        $total++;
        if ($has) {
            $filled++;
        }
        $refKind = $d['ref_kind'];
        $grouped[$setId]['fields'][] = [
            'key'         => $key,
            'label'       => $d['label'],
            'type'        => $d['type'],
            'config'      => $d['config'],
            'required'    => $d['required'],
            'help_text'   => $d['help_text'],
            'ref_kind'    => $refKind,
            'value'       => $has ? $values[$key] : null,
            'value_label' => ($d['type'] === 'ref' && $has && $refKind)
                             ? ($labels[$refKind][(int)$values[$key]] ?? null) : null,
            'options'     => $d['type'] === 'dropdown' ? assetFieldOptions($conn, (int)$d['id']) : [],
        ];
    }

    // Sets that could be attached to THIS asset — the pilot list. Excludes any
    // already applying, however they arrive.
    $applied   = array_map(static fn($s) => (int)$s['id'], $sets);
    $available = array_values(array_filter(
        AssetFieldsService::sets($conn, (int)$_SESSION['analyst_id']),
        static fn($s) => !in_array((int)$s['id'], $applied, true)
    ));

    echo json_encode([
        'success'        => true,
        'schema_ready'   => true,
        'sets'           => array_values($grouped),
        'available_sets' => $available,
        // "6 of 8 filled in". A blank field and a field nobody ever looked at
        // must never render identically.
        'filled'         => $filled,
        'total'          => $total,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
