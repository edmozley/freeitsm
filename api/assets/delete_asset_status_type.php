<?php
/**
 * API Endpoint: Delete asset status type — multi-tenancy aware.
 *
 *   - Single-company / MSP-Default context → deletes a global default status
 *     (assets referencing it are nulled first).
 *   - Client company context → delete only THAT company's own statuses; shared
 *     defaults are hidden per company. Blocked if assets in the company still
 *     use the status (the in-use guard).
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
    $id = !empty($data['id']) ? (int)$data['id'] : null;
    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $cur = $conn->prepare("SELECT tenant_id FROM asset_status_types WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Asset status not found');
    }
    $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];

    if ($isDefaultCtx) {
        if ($owner !== null) {
            throw new Exception("That's a company's own status — switch to that company to delete it.");
        }
        $conn->prepare("UPDATE assets SET asset_status_id = NULL WHERE asset_status_id = ?")->execute([$id]);
    } else {
        if ($owner === null) {
            throw new Exception('Shared default statuses are managed from the MSP (default) company — here you can hide it from this company instead.');
        }
        if ($owner !== $activeId) {
            throw new Exception('That status belongs to another company.');
        }
        $g = $conn->prepare("SELECT COUNT(*) FROM assets WHERE tenant_id = ? AND asset_status_id = ?");
        $g->execute([$activeId, $id]);
        if ((int)$g->fetchColumn() > 0) {
            throw new Exception('Assets still use this status — change them first.');
        }
    }

    $name = $conn->query("SELECT name FROM asset_status_types WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM asset_status_types WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('asset_status', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
