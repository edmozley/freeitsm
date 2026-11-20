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
require_once '../../includes/services/asset_type_layout.php';
require_once '../../includes/services/asset_completeness.php';   // GH #404

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

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
    // ?for_tenant= (2.6.0): the New asset form adding to another company needs
    // THAT company's types. Ignored for the settings view and for a company the
    // analyst cannot reach (requestedTenantId falls back to the active one).
    $rows = getTenantConfigRows(
        $conn, 'asset_types', 'asset_type', $manage ? $activeId : requestedTenantId($conn, $analystId),
        'id, name, description, is_active, display_order, icon_id, tenant_id, created_datetime',
        '', 'display_order, name'
    );
    // The icon's KEY, not just its id — every consumer wants to draw it, and
    // nobody should have to fetch the library to turn 42 into "printer".
    $iconKeys = [];
    try {
        $iconKeys = $conn->query("SELECT id, icon_key FROM cmdb_icons")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        // No CMDB icon table on this install — types simply have no icons.
    }

    // Which built-in sections and tabs each type shows (GH #200). Every consumer gets it: the
    // asset page draws from it, and the settings modal edits it.
    $layouts = AssetTypeLayout::forTypes($conn, array_column($rows, 'id'));
    // GH #404: expected fields, and the custom fields each type could expect.
    $expected = AssetCompleteness::expectedForTypes($conn, array_column($rows, 'id'));

    // GH #201: which CMDB class each type becomes through "Create linked CI". Read on its
    // own, so the list still loads before Database Verification adds the column.
    $classOf = [];
    if ($rows) {
        try {
            $in = implode(',', array_map('intval', array_column($rows, 'id')));
            $classOf = $conn->query("SELECT id, cmdb_class_id FROM asset_types WHERE id IN ($in) AND cmdb_class_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            $classOf = [];
        }
    }

    foreach ($rows as &$r) {
        $r['cmdb_class_id'] = isset($classOf[$r['id']]) ? (int)$classOf[$r['id']] : null;
        $r['layout']    = $layouts[(int)$r['id']] ?? AssetTypeLayout::defaults();
        $r['expected']  = $expected[(int)$r['id']] ?? [];
        $r['expected_custom'] = AssetCompleteness::ready($conn) ? AssetCompleteness::customCandidates($conn, (int)$r['id']) : [];
        $r['is_active'] = (bool)$r['is_active'];
        $r['scope']     = ($r['tenant_id'] === null) ? 'global' : 'company';
        $r['icon_id']   = isset($r['icon_id']) && $r['icon_id'] !== null ? (int)$r['icon_id'] : null;
        $r['icon_key']  = $r['icon_id'] !== null ? ($iconKeys[$r['icon_id']] ?? null) : null;
    }
    unset($r);

    $resp = ['success' => true, 'asset_types' => $rows, 'multi_tenant' => $multi];
    // The settings dialog's CMDB class dropdown (GH #201): the active classes. Install-wide
    // configuration, so naming them to an Assets administrator reveals no CI.
    if ($manage) {
        try {
            $resp['cmdb_classes'] = $conn->query('SELECT id, name FROM cmdb_classes WHERE is_active = 1 ORDER BY display_order, name')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $resp['cmdb_classes'] = [];
        }
    }

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
        // The settings dialog edits these rows and saves every field it shows, so each one
        // carries the icon, layout and CMDB class too. Without them, saving a company's own
        // type in its context sent icon_id null and the default layout - wiping both.
        $full = [];
        foreach ($rows as $r) { $full[(int)$r['id']] = $r; }
        $own = [];
        foreach ($ownStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $f = $full[(int)$o['id']] ?? [];
            $own[] = [
                'id'            => (int)$o['id'],
                'name'          => $o['name'],
                'description'   => $o['description'],
                'is_active'     => (bool)$o['is_active'],
                'display_order' => (int)$o['display_order'],
                'icon_id'       => $f['icon_id'] ?? null,
                'icon_key'      => $f['icon_key'] ?? null,
                'layout'        => $f['layout'] ?? AssetTypeLayout::forTypes($conn, [(int)$o['id']])[(int)$o['id']],
                'cmdb_class_id' => $f['cmdb_class_id'] ?? null,
                // GH #404: carried here too, or Save on a company's own type would wipe them.
                'expected'        => $f['expected'] ?? (AssetCompleteness::expectedForTypes($conn, [(int)$o['id']])[(int)$o['id']] ?? []),
                'expected_custom' => $f['expected_custom'] ?? (AssetCompleteness::ready($conn) ? AssetCompleteness::customCandidates($conn, (int)$o['id']) : []),
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
