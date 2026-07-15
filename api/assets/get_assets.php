<?php
/**
 * API Endpoint: Get assets list
 * Returns assets with optional search filtering and user counts
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get search parameter
$search = $_GET['search'] ?? '';

try {
    $conn = connectToDatabase();

    // Check if users_assets table exists
    $tableCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'users_assets'");
    $tableCheck->execute([DB_NAME]);
    $tableExists = (int)$tableCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

    // Check if asset lookup tables exist
    $typeTableCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'asset_types'");
    $typeTableCheck->execute([DB_NAME]);
    $typeTableExists = (int)$typeTableCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

    $statusTableCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'asset_status_types'");
    $statusTableCheck->execute([DB_NAME]);
    $statusTableExists = (int)$statusTableCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

    // Build query with optional search
    if ($tableExists) {
        $sql = "SELECT
                    a.id,
                    a.hostname,
                    a.manufacturer,
                    a.model,
                    a.memory,
                    a.service_tag,
                    a.operating_system,
                    a.feature_release,
                    a.build_number,
                    a.cpu_name,
                    a.speed,
                    a.bios_version,
                    a.location_id,
                    a.purchase_date,
                    a.purchase_cost,
                    a.supplier_id,
                    a.order_number,
                    a.warranty_expiry,";

        if ($typeTableExists) {
            $sql .= "
                    a.asset_type_id,
                    aty.name AS asset_type_name,";
        } else {
            $sql .= "
                    NULL AS asset_type_id,
                    NULL AS asset_type_name,";
        }

        if ($statusTableExists) {
            $sql .= "
                    a.asset_status_id,
                    ast.name AS asset_status_name,";
        } else {
            $sql .= "
                    NULL AS asset_status_id,
                    NULL AS asset_status_name,";
        }

        $sql .= "
                    COUNT(ua.user_id) as user_count
                FROM assets a
                LEFT JOIN users_assets ua ON ua.asset_id = a.id";

        if ($typeTableExists) {
            $sql .= " LEFT JOIN asset_types aty ON aty.id = a.asset_type_id";
        }
        if ($statusTableExists) {
            $sql .= " LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id";
        }
    } else {
        // Table doesn't exist yet, just return assets without user counts
        $sql = "SELECT
                    a.id,
                    a.hostname,
                    a.manufacturer,
                    a.model,
                    a.memory,
                    a.service_tag,
                    a.operating_system,
                    a.feature_release,
                    a.build_number,
                    a.cpu_name,
                    a.speed,
                    a.bios_version,
                    a.location_id,
                    a.purchase_date,
                    a.purchase_cost,
                    a.supplier_id,
                    a.order_number,
                    a.warranty_expiry,
                    NULL AS asset_type_id,
                    NULL AS asset_type_name,
                    NULL AS asset_status_id,
                    NULL AS asset_status_name,
                    0 as user_count
                FROM assets a";
    }

    $params = [];

    // Scope to the active company (multi-tenancy). No-op on a single-company
    // install — activeTenantFilter returns ['', []]. The Default company also
    // sees NULL-tenant (not-yet-assigned) assets.
    [$tenantSql, $tenantParams] = activeTenantFilter($conn, (int)$_SESSION['analyst_id'], 'a');

    $sql .= " WHERE 1=1";
    if (!empty($search)) {
        $sql .= " AND a.hostname LIKE ?";
        $params[] = '%' . $search . '%';
    }
    $sql .= $tenantSql;
    $params = array_merge($params, $tenantParams);

    if ($tableExists) {
        $groupBy = " GROUP BY a.id, a.hostname, a.manufacturer, a.model, a.memory, a.service_tag, a.operating_system, a.feature_release, a.build_number, a.cpu_name, a.speed, a.bios_version, a.location_id, a.purchase_date, a.purchase_cost, a.supplier_id, a.order_number, a.warranty_expiry";
        if ($typeTableExists) {
            $groupBy .= ", a.asset_type_id, aty.name";
        }
        if ($statusTableExists) {
            $groupBy .= ", a.asset_status_id, ast.name";
        }
        $sql .= $groupBy;
    }

    $sql .= " ORDER BY a.hostname ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach the full location path (e.g. "UK › London › Office 1") for each
    // asset, built in PHP from the location tree so the client doesn't need
    // the tree loaded. Only if the location table exists.
    $locTableCheck = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'asset_locations'");
    $locTableCheck->execute([DB_NAME]);
    if ((int)$locTableCheck->fetchColumn() > 0) {
        $locRows = $conn->query("SELECT id, name, parent_id FROM asset_locations")->fetchAll(PDO::FETCH_ASSOC);
        $locById = [];
        foreach ($locRows as $lr) { $locById[(int)$lr['id']] = $lr; }
        $pathOf = function ($id) use ($locById) {
            $parts = [];
            $guard = 0;
            while ($id !== null && isset($locById[$id]) && $guard++ < 1000) {
                array_unshift($parts, $locById[$id]['name']);
                $id = $locById[$id]['parent_id'] !== null ? (int)$locById[$id]['parent_id'] : null;
            }
            return implode(' › ', $parts);
        };
        foreach ($assets as &$a) {
            $lid = isset($a['location_id']) && $a['location_id'] !== null ? (int)$a['location_id'] : null;
            $a['location_name'] = $lid !== null && isset($locById[$lid]) ? $locById[$lid]['name'] : null;
            $a['location_path'] = $lid !== null ? $pathOf($lid) : null;
        }
        unset($a);
    }

    // Attach the supplier display name (trading name, falling back to legal name)
    // from the shared suppliers registry.
    $supTableCheck = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'suppliers'");
    $supTableCheck->execute([DB_NAME]);
    if ((int)$supTableCheck->fetchColumn() > 0) {
        $supRows = $conn->query("SELECT id, COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) AS name FROM suppliers")->fetchAll(PDO::FETCH_ASSOC);
        $supById = [];
        foreach ($supRows as $sr) { $supById[(int)$sr['id']] = $sr['name']; }
        foreach ($assets as &$a) {
            $sid = isset($a['supplier_id']) && $a['supplier_id'] !== null ? (int)$a['supplier_id'] : null;
            $a['supplier_name'] = $sid !== null && isset($supById[$sid]) ? $supById[$sid] : null;
        }
        unset($a);
    }

    echo json_encode([
        'success' => true,
        'assets' => $assets,
        'users_assets_table_exists' => $tableExists
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
