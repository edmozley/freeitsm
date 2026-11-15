<?php
/**
 * API: what points at a location (GH #311) - for System -> Maintenance ->
 * Locations in use, and the refusal when deleting one.
 * GET ?id=N
 *
 * {counts: {people, tickets, contracts, projects}, records: {kind: [{id, label, url}]},
 *  path, can_manage, reason}. Only someone who may manage locations, and this
 * one in particular (recordLocationManageError()), sees the records.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/record_locations.php';

header('Content-Type: application/json');

locationsRequireAccessJson(true);

try {
    $conn = connectToDatabase();
    $id = (int)($_GET['id'] ?? 0);
    $path = $id > 0 ? recordLocationPath($conn, $id) : null;
    if ($path === null) {
        echo json_encode(['success' => false, 'error' => 'Location not found']);
        exit;
    }
    $reason = recordLocationManageError($conn, (int)$_SESSION['analyst_id'], $id);
    if ($reason !== null) {
        echo json_encode(['success' => true, 'path' => $path, 'can_manage' => false, 'reason' => $reason]);
        exit;
    }
    echo json_encode([
        'success'    => true,
        'path'       => $path,
        'can_manage' => true,
        'counts'     => recordLocationUsage($conn, $id),
        'records'    => recordLocationRecords($conn, $id),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
