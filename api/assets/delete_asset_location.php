<?php
/**
 * API Endpoint: Delete an asset location — multi-tenancy aware.
 *
 * Refuses to delete a node that still has children (re-parent/remove them
 * first; the DB self-ref FK is RESTRICT as a backstop). Per company: you may
 * delete only what this context owns — a company's own locations in its
 * context, the shared ones from the MSP/Default context. Nothing that points at
 * it is ever cleared silently any more - see GH #311 below.
 *
 * GH #311: REFUSED while assets, people, tickets, contracts or projects point at it,
 * with the counts and a link to System -> Maintenance -> Locations in use,
 * which moves them all at once (Ed, 15 Nov 2026). Who may manage which
 * location is recordLocationManageError(), shared with that tool.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/asset_locations.php';
require_once '../../includes/record_locations.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// GH #311: an administrator (System -> Locations), or Assets plus the assets.locations
// permission (Assets -> Settings -> Locations) - see includes/asset_locations.php.
locationsRequireAccessJson(true);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = !empty($data['id']) ? (int)$data['id'] : null;
    if (!$id) {
        throw new Exception('Missing location id');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    // Who may delete which location - one rule, shared with System -> Maintenance ->
    // Locations in use (includes/record_locations.php).
    $reason = recordLocationManageError($conn, $analystId, $id);
    if ($reason !== null) {
        throw new Exception($reason);
    }

    $childStmt = $conn->prepare("SELECT COUNT(*) FROM asset_locations WHERE parent_id = ?");
    $childStmt->execute([$id]);
    if ((int)$childStmt->fetchColumn() > 0) {
        throw new Exception('This location has sub-locations. Delete or move them first.');
    }

    // GH #311: never while something points at it - the tool moves them all.
    $usage = array_filter(recordLocationUsage($conn, $id));
    if ($usage) {
        $parts = [];
        foreach ($usage as $kind => $n) $parts[] = $n . ' ' . $kind;
        echo json_encode([
            'success' => false,
            'error'   => 'This location is in use (' . implode(', ', $parts) . '). Move them to another location first.',
            'in_use'  => $usage,
            'tool'    => 'system/locations-in-use/?id=' . $id,
        ]);
        exit;
    }

    $name = $conn->query("SELECT name FROM asset_locations WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM asset_locations WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('asset_location', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
