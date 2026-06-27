<?php
/** API: unlink an incident from a problem. */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }

try {
    $d = json_decode(file_get_contents('php://input'), true);
    $problemId = (int) ($d['problem_id'] ?? 0);
    $ticketId  = (int) ($d['ticket_id'] ?? 0);
    if ($problemId <= 0 || $ticketId <= 0) throw new Exception('Problem and ticket are required');
    $conn = connectToDatabase();
    if (!analystCanAccessProblem($conn, (int) $_SESSION['analyst_id'], $problemId)) throw new Exception('Problem not found');
    $conn->prepare("DELETE FROM problem_tickets WHERE problem_id = ? AND ticket_id = ?")->execute([$problemId, $ticketId]);
    echo json_encode(['success' => true, 'message' => 'Incident unlinked']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
