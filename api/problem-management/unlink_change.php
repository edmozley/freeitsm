<?php
/** API: unlink a change from a problem (removes the change_relations row). */
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
    if (!analystCanAccessProblem($conn, (int) $_SESSION['analyst_id'], $problemId)) throw new Exception('Problem not found');
    $conn->prepare("DELETE FROM change_relations WHERE change_id = ? AND related_type = 'problem' AND related_id = ?")->execute([$changeId, $problemId]);
    echo json_encode(['success' => true, 'message' => 'Change unlinked']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
