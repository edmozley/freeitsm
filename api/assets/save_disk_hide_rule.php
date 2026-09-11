<?php
/**
 * API: hide or show a physical drive (#97).
 *
 * POST { asset_id, model, size_bytes, hidden, everywhere } -> { success, hidden }
 *
 *   hidden:     true to hide, false to show again
 *   everywhere: true  -> one estate-wide rule (asset_id NULL)
 *               false -> a rule for this asset only
 *
 * SHOWING IS DELIBERATELY BROADER THAN HIDING. Hiding writes one rule of the
 * scope you asked for; showing deletes EVERY rule that matches the drive,
 * estate-wide ones included. The alternative — an "unhide" exception layered
 * over a hide rule — is a second, invisible set of rules to reason about, and
 * "Show" that leaves the drive hidden is a button that does not work. The
 * screen says what it is about to do, using the count, before it does it.
 *
 * 🔴 The rule describes the DRIVE (model + exact size), never the disk row:
 * asset_physical_disks is cleared and rewritten on every agent report and the
 * ids are reissued. See includes/asset_disk_visibility.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/asset_disk_visibility.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('assets');

try {
    $data      = json_decode(file_get_contents('php://input'), true) ?: [];
    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $assetId   = (int)($data['asset_id'] ?? 0);

    if ($assetId <= 0 || !analystCanAccessAsset($conn, $analystId, $assetId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }
    if (!assetDiskHideSchemaReady($conn)) {
        throw new Exception('Hiding drives needs a database update — run System → Database Verification.');
    }

    // Absent or empty means the drive genuinely has no model / no size. Both are
    // stored as NULL and matched NULL-safely, so a nameless drive can be hidden
    // like any other.
    $model      = array_key_exists('model', $data) && $data['model'] !== '' && $data['model'] !== null ? (string)$data['model'] : null;
    $size       = array_key_exists('size_bytes', $data) && $data['size_bytes'] !== '' && $data['size_bytes'] !== null ? (string)$data['size_bytes'] : null;
    $hidden     = !empty($data['hidden']);
    $everywhere = !empty($data['everywhere']);

    if ($model !== null) $model = mb_substr($model, 0, 255);

    if ($hidden) {
        // Don't stack duplicates. No unique index can hold this: the key would
        // be (tenant_id, asset_id, model, size_bytes) and MySQL treats NULLs in
        // a unique index as distinct, so three of the four columns being
        // nullable makes the index look like a guard without being one — the
        // same trap assets.asset_tag documents.
        $existing = assetDiskHideRulesFor($conn, $analystId, $assetId);
        foreach ($existing as $r) {
            $sameScope = $everywhere ? ($r['asset_id'] === null) : ((int)$r['asset_id'] === $assetId);
            $ruleSize  = $r['size_bytes'] === null ? null : (string)$r['size_bytes'];
            if ($sameScope && $r['model'] === $model && $ruleSize === $size) {
                echo json_encode(['success' => true, 'hidden' => true, 'already' => true]);
                exit;
            }
        }

        // The rule belongs to the company whose screen it was created from, so
        // one company hiding its virtual disks does not blank another's.
        $tenantId = null;
        try {
            if (isMultiTenant($conn)) {
                $active  = getActiveTenantId($conn, $analystId);
                $default = getDefaultTenantId($conn);
                $tenantId = ($active === $default) ? null : $active;
            }
        } catch (Exception $e) { $tenantId = null; }

        $stmt = $conn->prepare(
            "INSERT INTO asset_disk_hide_rules (tenant_id, asset_id, model, size_bytes, created_by_analyst_id, created_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([$tenantId, $everywhere ? null : $assetId, $model, $size, $analystId]);

        echo json_encode(['success' => true, 'hidden' => true]);
        exit;
    }

    // Showing: clear every rule that could be hiding this drive from this
    // analyst — its own and the estate-wide ones. Scoped by the same filter the
    // read path uses, so one company cannot delete another's rule.
    $ids = [];
    foreach (assetDiskHideRulesFor($conn, $analystId, $assetId) as $r) {
        $ruleSize = $r['size_bytes'] === null ? null : (string)$r['size_bytes'];
        if ($r['model'] === $model && $ruleSize === $size) {
            $ids[] = (int)$r['id'];
        }
    }
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $conn->prepare("DELETE FROM asset_disk_hide_rules WHERE id IN ($in)")->execute($ids);
    }

    echo json_encode(['success' => true, 'hidden' => false, 'rules_removed' => count($ids)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
