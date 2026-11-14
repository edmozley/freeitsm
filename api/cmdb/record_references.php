<?php
/**
 * API: which CIs point at a record (GH #312) - for the summary in a delete
 * dialog ("3 configuration items point at this person ... they will show
 * '(removed)'"). Deleting is still allowed (Ed, 2026-11-14); this only lets the
 * person deleting see what it touches first.
 *
 * Query params: type (person, team, location, supplier, contract, analyst,
 * company) and id. Open to any signed-in analyst, because it is asked from the
 * People, System, Assets and Contracts delete dialogs, not from the CMDB - but
 * CI names are given only for the companies the analyst can reach, and only to
 * someone with the CMDB module; anyone else gets the count alone.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/cmdb_record_refs.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $type = (string)($_GET['type'] ?? '');
    $id = (int)($_GET['id'] ?? 0);
    if (!cmdbIsRecordType($type) || $id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Unknown record']);
        exit;
    }
    // No CMDB module (switched off, or not granted): nothing to say.
    if (!analystCanAccessModule($conn, $analystId, 'cmdb')) {
        echo json_encode(['success' => true, 'count' => 0, 'hidden' => 0, 'items' => []]);
        exit;
    }
    $reach = analystHasAllTenantAccess($conn, $analystId) ? null : getAccessibleTenantIds($conn, $analystId);
    $refs = cmdbRecordReferences($conn, $type, $id, $reach, 5);
    echo json_encode(['success' => true] + $refs);
} catch (Exception $e) {
    error_log('cmdb record_references: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Lookup failed']);
}
