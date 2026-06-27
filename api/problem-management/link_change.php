<?php
/**
 * API: link the Change that fixes a problem. Stored in the shared change_relations
 * table (related_type 'problem'), so the change side can surface it too later.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }

try {
    $d = json_decode(file_get_contents('php://input'), true);
    $problemId = (int) ($d['problem_id'] ?? 0);
    $changeId  = (int) ($d['change_id'] ?? 0);
    if ($problemId <= 0 || $changeId <= 0) throw new Exception('Problem and change are required');
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    if (!analystCanAccessProblem($conn, $analystId, $problemId)) throw new Exception('Problem not found');

    $chk = $conn->prepare("SELECT title FROM changes WHERE id = ?");
    $chk->execute([$changeId]);
    $title = $chk->fetchColumn();
    if ($title === false) throw new Exception('Change not found — check the Change ID.');

    $dup = $conn->prepare("SELECT COUNT(*) FROM change_relations WHERE change_id = ? AND related_type = 'problem' AND related_id = ?");
    $dup->execute([$changeId, $problemId]);
    if ((int) $dup->fetchColumn() === 0) {
        $conn->prepare("INSERT INTO change_relations (change_id, related_type, related_id, relation_type, created_by_id, created_datetime) VALUES (?, 'problem', ?, 'fixes', ?, UTC_TIMESTAMP())")
             ->execute([$changeId, $problemId, $analystId]);
        $conn->prepare("INSERT INTO problem_audit (problem_id, analyst_id, action_type, field_name, new_value, created_datetime) VALUES (?, ?, 'modified', 'linked_change', ?, UTC_TIMESTAMP())")
             ->execute([$problemId, $analystId, 'Change #' . $changeId]);
        $conn->prepare("UPDATE problems SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$problemId]);
    }
    echo json_encode(['success' => true, 'message' => 'Change linked']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
