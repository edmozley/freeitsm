<?php
/**
 * API Endpoint: Delete an asset location — multi-tenancy aware.
 *
 * Refuses to delete a node that still has children (re-parent/remove them
 * first; the DB self-ref FK is RESTRICT as a backstop). Per company: you may
 * delete only what this context owns — a company's own locations in its
 * context, the shared ones from the MSP/Default context. Assets pointing at the
 * deleted location have their location cleared (FK ON DELETE SET NULL).
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
requireCapabilityJson(Cap::ASSETS_LOCATIONS);   // settings write — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = !empty($data['id']) ? (int)$data['id'] : null;
    if (!$id) {
        throw new Exception('Missing location id');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $cur = $conn->prepare("SELECT tenant_id FROM asset_locations WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Location not found');
    }
    $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];
    if ($isDefaultCtx) {
        if ($owner !== null) {
            throw new Exception("That's a company's own location — switch to that company to delete it.");
        }
    } else {
        if ($owner === null) {
            throw new Exception('Shared locations are managed from the MSP (default) company.');
        }
        if ($owner !== $activeId) {
            throw new Exception('That location belongs to another company.');
        }
    }

    $childStmt = $conn->prepare("SELECT COUNT(*) FROM asset_locations WHERE parent_id = ?");
    $childStmt->execute([$id]);
    if ((int)$childStmt->fetchColumn() > 0) {
        throw new Exception('This location has sub-locations. Delete or move them first.');
    }

    $name = $conn->query("SELECT name FROM asset_locations WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM asset_locations WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('asset_location', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
