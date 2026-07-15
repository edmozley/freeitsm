<?php
/**
 * API Endpoint: Hide / show a global asset status for the active company.
 *
 * The "hide" half of the add+hide override model. Reversible; the global row is
 * never touched. POST JSON { asset_status_type_id, hidden: true|false }. Only
 * meaningful in a client company's context; blocked while assets in the company
 * still use the status (in-use guard).
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
requireCapabilityJson(Cap::ASSETS_STATUSES);   // settings tab — see docs/design/rbac.md

try {
    $data   = json_decode(file_get_contents('php://input'), true);
    $typeId = !empty($data['asset_status_type_id']) ? (int)$data['asset_status_type_id'] : 0;
    $hidden = !empty($data['hidden']);
    if ($typeId <= 0) {
        throw new Exception('Missing asset status');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!isMultiTenant($conn)) {
        throw new Exception('Hiding applies only when more than one company exists.');
    }
    $activeId  = getActiveTenantId($conn, $analystId);
    $defaultId = getDefaultTenantId($conn);
    if ($activeId === $defaultId) {
        throw new Exception('Switch to a client company to hide a shared default from it.');
    }

    $cur = $conn->prepare("SELECT tenant_id FROM asset_status_types WHERE id = ?");
    $cur->execute([$typeId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Asset status not found');
    }
    if ($row['tenant_id'] !== null) {
        throw new Exception('Only shared default statuses are hidden per company.');
    }

    if ($hidden) {
        $g = $conn->prepare("SELECT COUNT(*) FROM assets WHERE tenant_id = ? AND asset_status_id = ?");
        $g->execute([$activeId, $typeId]);
        if ((int)$g->fetchColumn() > 0) {
            throw new Exception('Assets in this company use this status — change them first.');
        }
        $ins = $conn->prepare("INSERT IGNORE INTO tenant_config_hidden (tenant_id, entity_type, entity_id) VALUES (?, 'asset_status_type', ?)");
        $ins->execute([$activeId, $typeId]);
    } else {
        $del = $conn->prepare("DELETE FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'asset_status_type' AND entity_id = ?");
        $del->execute([$activeId, $typeId]);
    }

    echo json_encode(['success' => true, 'hidden' => $hidden]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
