<?php
/**
 * API Endpoint: Save asset status type (create or update) — multi-tenancy aware.
 *
 * Context decides scope (mirrors save_asset_type.php, design §7): a save in the
 * MSP/Default context is a GLOBAL default (tenant_id NULL); a save in a client
 * company's context is that company's own. You may edit/delete only a company's
 * own statuses; shared defaults are hidden (not edited) per company.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');
requireCapabilityJson(Cap::ASSETS_STATUSES);   // settings write — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id            = !empty($data['id']) ? (int)$data['id'] : null;
    $name          = trim($data['name'] ?? '');
    $description   = $data['description'] ?? '';
    $display_order = (int)($data['display_order'] ?? 0);
    $is_active     = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new Exception('Name is required');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $scopeTenant = $isDefaultCtx ? null : $activeId;

    // Name must be unique within what this company sees (own + non-hidden globals).
    $clashSql = $isDefaultCtx
        ? "SELECT id FROM asset_status_types WHERE tenant_id IS NULL AND LOWER(name) = LOWER(?)"
        : "SELECT id FROM asset_status_types
             WHERE LOWER(name) = LOWER(?)
               AND ( tenant_id = " . (int)$activeId . "
                     OR ( tenant_id IS NULL
                          AND id NOT IN (SELECT entity_id FROM tenant_config_hidden
                                         WHERE tenant_id = " . (int)$activeId . " AND entity_type = 'asset_status_type') ) )";
    $clashParams = [$name];
    if ($id) { $clashSql .= " AND id <> ?"; $clashParams[] = $id; }
    $cs = $conn->prepare($clashSql);
    $cs->execute($clashParams);
    if ($cs->fetch()) {
        throw new Exception('An asset status with that name already exists here');
    }

    if ($id) {
        $cur = $conn->prepare("SELECT tenant_id FROM asset_status_types WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Asset status not found');
        }
        $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];

        if ($isDefaultCtx) {
            if ($owner !== null) {
                throw new Exception("That's a company's own status — switch to that company to edit it.");
            }
        } else {
            if ($owner === null) {
                throw new Exception('Shared default statuses are managed from the MSP (default) company — here you can hide them instead.');
            }
            if ($owner !== $activeId) {
                throw new Exception('That status belongs to another company.');
            }
        }

        $stmt = $conn->prepare("UPDATE asset_status_types SET name = ?, description = ?, display_order = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $description, $display_order, $is_active, $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO asset_status_types (name, description, display_order, is_active, tenant_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $display_order, $is_active, $scopeTenant]);
    }

    wf_emit('asset_status', $id ? 'updated' : 'created', $id ? (int)$id : (int)$conn->lastInsertId(), $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
