<?php
/**
 * API: a single problem with resolved names, linked incidents, linked changes, audit.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }

try {
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('Problem ID is required');
    if (!analystCanAccessProblem($conn, $analystId, $id)) throw new Exception('Problem not found');

    $stmt = $conn->prepare(
        "SELECT p.*, s.name AS status_name, s.is_closed, pr.name AS priority_name,
                a.full_name AS assignee_name, c.full_name AS created_by_name
         FROM problems p
         LEFT JOIN problem_statuses s ON s.id = p.status_id
         LEFT JOIN problem_priorities pr ON pr.id = p.priority_id
         LEFT JOIN analysts a ON a.id = p.assigned_analyst_id
         LEFT JOIN analysts c ON c.id = p.created_by_id
         WHERE p.id = ?"
    );
    $stmt->execute([$id]);
    $problem = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$problem) throw new Exception('Problem not found');

    // Linked incidents (tickets).
    $inc = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject, ts.name AS status
         FROM problem_tickets pt
         JOIN tickets t ON t.id = pt.ticket_id
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         WHERE pt.problem_id = ? AND t.deleted_datetime IS NULL
         ORDER BY t.created_datetime DESC"
    );
    $inc->execute([$id]);
    $incidents = $inc->fetchAll(PDO::FETCH_ASSOC);

    // Linked changes (the fix) — via the shared change_relations table.
    $changes = [];
    try {
        $ch = $conn->prepare(
            "SELECT c.id, c.title, cs.name AS status, cr.relation_type
             FROM change_relations cr
             JOIN changes c ON c.id = cr.change_id
             LEFT JOIN change_statuses cs ON cs.id = c.status_id
             WHERE cr.related_type = 'problem' AND cr.related_id = ?
             ORDER BY c.created_datetime DESC"
        );
        $ch->execute([$id]);
        $changes = $ch->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* change tables may be absent */ }

    // Audit trail.
    $au = $conn->prepare(
        "SELECT pa.action_type, pa.field_name, pa.old_value, pa.new_value, pa.created_datetime, a.full_name AS analyst_name
         FROM problem_audit pa LEFT JOIN analysts a ON a.id = pa.analyst_id
         WHERE pa.problem_id = ? ORDER BY pa.created_datetime DESC, pa.id DESC"
    );
    $au->execute([$id]);
    $audit = $au->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'problem' => $problem, 'incidents' => $incidents, 'changes' => $changes, 'audit' => $audit]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
