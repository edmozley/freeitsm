<?php
/**
 * API: reassign one or more tickets to a department (or to "no department").
 * Used by the System -> Orphaned tickets screen to fix tickets pointing at a
 * department that no longer exists.
 *
 * POST JSON { ticket_ids: [int,...], department_id: int|null }
 *   department_id null/empty => clears it (sets to NULL = "no department").
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$analystId = (int)$_SESSION['analyst_id'];

$input = json_decode(file_get_contents('php://input'), true);
$ticketIds = $input['ticket_ids'] ?? [];
$departmentId = (isset($input['department_id']) && $input['department_id'] !== '' && $input['department_id'] !== null)
    ? (int)$input['department_id'] : null;

if (!is_array($ticketIds) || !count($ticketIds)) {
    echo json_encode(['success' => false, 'error' => 'No tickets specified']);
    exit;
}
$ticketIds = array_values(array_unique(array_map('intval', $ticketIds)));

try {
    $conn = connectToDatabase();

    // Validate the target department exists (null = clear, always allowed).
    $deptName = '(none)';
    if ($departmentId !== null) {
        $ds = $conn->prepare("SELECT name FROM departments WHERE id = ?");
        $ds->execute([$departmentId]);
        $deptName = $ds->fetchColumn();
        if ($deptName === false) {
            echo json_encode(['success' => false, 'error' => 'That department does not exist']);
            exit;
        }
    }

    $conn->beginTransaction();

    $place = implode(',', array_fill(0, count($ticketIds), '?'));
    // Read current department ids for the audit trail.
    $cur = $conn->prepare("SELECT id, department_id FROM tickets WHERE id IN ($place)");
    $cur->execute($ticketIds);
    $oldById = [];
    foreach ($cur->fetchAll(PDO::FETCH_ASSOC) as $r) { $oldById[(int)$r['id']] = $r['department_id']; }

    $upd = $conn->prepare("UPDATE tickets SET department_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id IN ($place)");
    $upd->execute(array_merge([$departmentId], $ticketIds));
    $updated = $upd->rowCount();

    // Audit each ticket that actually existed.
    $audit = $conn->prepare(
        "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?, ?, 'Department', ?, ?, UTC_TIMESTAMP())"
    );
    foreach ($ticketIds as $tid) {
        if (!array_key_exists($tid, $oldById)) continue;
        $old = $oldById[$tid] !== null ? ('department #' . $oldById[$tid] . ' (orphaned)') : '(none)';
        $audit->execute([$tid, $analystId, $old, $deptName]);
    }

    $conn->commit();
    echo json_encode(['success' => true, 'updated' => $updated, 'department' => $deptName]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
