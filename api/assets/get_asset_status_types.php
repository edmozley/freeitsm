<?php
/**
 * API Endpoint: Get asset status types — multi-tenancy aware.
 *
 * A "global default + per-company add/hide" list (mirrors asset/ticket types).
 *   - default (consumer): `asset_status_types` = the RESOLVED visible list for
 *     the active company (every row on a single-company install).
 *   - ?manage=1 (settings): additionally returns `scoped` (globals + own) for a
 *     client company's context.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $manage    = !empty($_GET['manage']);

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $rows = getTenantConfigRows(
        $conn, 'asset_status_types', 'asset_status_type', $activeId,
        'id, name, description, is_active, display_order, tenant_id, created_datetime',
        '', 'display_order, name'
    );
    foreach ($rows as &$r) {
        $r['is_active'] = (bool)$r['is_active'];
        $r['scope']     = ($r['tenant_id'] === null) ? 'global' : 'company';
    }
    unset($r);

    $resp = ['success' => true, 'asset_status_types' => $rows, 'multi_tenant' => $multi];

    if ($manage && $multi && !$isDefaultCtx) {
        $company = getTenantById($conn, $activeId);

        $hiddenIds = [];
        $hs = $conn->prepare("SELECT entity_id FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'asset_status_type'");
        $hs->execute([$activeId]);
        foreach ($hs->fetchAll(PDO::FETCH_COLUMN) as $eid) { $hiddenIds[(int)$eid] = true; }

        $globals = [];
        foreach ($conn->query("SELECT id, name, description, is_active, display_order FROM asset_status_types WHERE tenant_id IS NULL ORDER BY display_order, name") as $g) {
            $globals[] = [
                'id'            => (int)$g['id'],
                'name'          => $g['name'],
                'description'   => $g['description'],
                'is_active'     => (bool)$g['is_active'],
                'display_order' => (int)$g['display_order'],
                'hidden'        => isset($hiddenIds[(int)$g['id']]),
            ];
        }

        $ownStmt = $conn->prepare("SELECT id, name, description, is_active, display_order FROM asset_status_types WHERE tenant_id = ? ORDER BY display_order, name");
        $ownStmt->execute([$activeId]);
        $own = [];
        foreach ($ownStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $own[] = [
                'id'            => (int)$o['id'],
                'name'          => $o['name'],
                'description'   => $o['description'],
                'is_active'     => (bool)$o['is_active'],
                'display_order' => (int)$o['display_order'],
            ];
        }

        $resp['scoped'] = [
            'is_default' => false,
            'company'    => ['id' => $activeId, 'name' => $company['name'] ?? ''],
            'globals'    => $globals,
            'own'        => $own,
        ];
    }

    echo json_encode($resp);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
