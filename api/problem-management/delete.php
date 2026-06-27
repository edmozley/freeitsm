<?php
/**
 * API: delete a problem (cascades problem_tickets + problem_audit via FKs).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($data['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('Problem ID is required');
    $conn = connectToDatabase();
    if (!analystCanAccessProblem($conn, (int) $_SESSION['analyst_id'], $id)) throw new Exception('Problem not found');
    // Tidy any change_relations pointing at this problem (not FK-bound).
    try { $conn->prepare("DELETE FROM change_relations WHERE related_type = 'problem' AND related_id = ?")->execute([$id]); } catch (Exception $e) {}
    $conn->prepare("DELETE FROM problems WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'Problem deleted']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
