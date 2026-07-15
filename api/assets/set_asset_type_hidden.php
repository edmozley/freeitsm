<?php
/**
 * API Endpoint: Hide / show a global asset type for the active company.
 *
 * The "hide" half of the add+hide override model (mirrors ticket types, design
 * §7). A company can remove a shared default type from its own pickers without
 * affecting any other company — the global row itself is never touched, so
 * existing assets still resolve it, and the action is fully reversible.
 *
 * POST JSON { asset_type_id, hidden: true|false }
 *
 * Only meaningful inside a client company's context (not at N=1, not in the
 * MSP/Default context). Hiding is blocked while assets in the company still use
 * the type — the in-use guard.
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
requireCapabilityJson(Cap::ASSETS_TYPES);   // settings tab — see docs/design/rbac.md

try {
    $data   = json_decode(file_get_contents('php://input'), true);
    $typeId = !empty($data['asset_type_id']) ? (int)$data['asset_type_id'] : 0;
    $hidden = !empty($data['hidden']);
    if ($typeId <= 0) {
        throw new Exception('Missing asset type');
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

    // Only a global default (tenant_id NULL) can be hidden; a company's own type
    // is removed by deleting it, not hiding.
    $cur = $conn->prepare("SELECT tenant_id FROM asset_types WHERE id = ?");
    $cur->execute([$typeId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Asset type not found');
    }
    if ($row['tenant_id'] !== null) {
        throw new Exception('Only shared default types are hidden per company.');
    }

    if ($hidden) {
        // In-use guard: don't hide a type this company's assets depend on.
        $g = $conn->prepare("SELECT COUNT(*) FROM assets WHERE tenant_id = ? AND asset_type_id = ?");
        $g->execute([$activeId, $typeId]);
        if ((int)$g->fetchColumn() > 0) {
            throw new Exception('Assets in this company use this type — change them first.');
        }
        $ins = $conn->prepare("INSERT IGNORE INTO tenant_config_hidden (tenant_id, entity_type, entity_id) VALUES (?, 'asset_type', ?)");
        $ins->execute([$activeId, $typeId]);
    } else {
        $del = $conn->prepare("DELETE FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'asset_type' AND entity_id = ?");
        $del->execute([$activeId, $typeId]);
    }

    echo json_encode(['success' => true, 'hidden' => $hidden]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
