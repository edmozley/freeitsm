<?php
/**
 * API Endpoint: Get asset types — multi-tenancy aware.
 *
 * Asset types are a "global default + per-company add/hide" list (mirrors ticket
 * types, design §7):
 *   - global defaults  → rows with tenant_id IS NULL (shared by every company)
 *   - a company's own  → rows with tenant_id = that company
 *   - a company can hide a global default from its own lists (tenant_config_hidden)
 *
 * Two response shapes:
 *   - default (consumer, e.g. the asset edit form): `asset_types` = the RESOLVED
 *     visible list for the active company. On a single-company install this is
 *     simply every type — exactly as before.
 *   - ?manage=1 (the settings screen): additionally returns `scoped` describing
 *     the two groups to manage when working in a *client* company's context.
 *
 * read_and_close releases the session lock immediately so parallel AJAX calls
 * from the same page don't queue behind PHP's exclusive session lock (see #388).
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

    // Consumer-safe RESOLVED list (global-not-hidden + this company's own). On a
    // single-company / part-migrated install this returns every row, as before.
    $rows = getTenantConfigRows(
        $conn, 'asset_types', 'asset_type', $activeId,
        'id, name, description, is_active, display_order, tenant_id, created_datetime',
        '', 'display_order, name'
    );
    foreach ($rows as &$r) {
        $r['is_active'] = (bool)$r['is_active'];
        $r['scope']     = ($r['tenant_id'] === null) ? 'global' : 'company';
    }
    unset($r);

    $resp = ['success' => true, 'asset_types' => $rows, 'multi_tenant' => $multi];

    // Settings management view, only meaningful inside a *client* company context.
    if ($manage && $multi && !$isDefaultCtx) {
        $company = getTenantById($conn, $activeId);

        $hiddenIds = [];
        $hs = $conn->prepare("SELECT entity_id FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'asset_type'");
        $hs->execute([$activeId]);
        foreach ($hs->fetchAll(PDO::FETCH_COLUMN) as $eid) { $hiddenIds[(int)$eid] = true; }

        $globals = [];
        foreach ($conn->query("SELECT id, name, description, is_active, display_order FROM asset_types WHERE tenant_id IS NULL ORDER BY display_order, name") as $g) {
            $globals[] = [
                'id'            => (int)$g['id'],
                'name'          => $g['name'],
                'description'   => $g['description'],
                'is_active'     => (bool)$g['is_active'],
                'display_order' => (int)$g['display_order'],
                'hidden'        => isset($hiddenIds[(int)$g['id']]),
            ];
        }

        $ownStmt = $conn->prepare("SELECT id, name, description, is_active, display_order FROM asset_types WHERE tenant_id = ? ORDER BY display_order, name");
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
