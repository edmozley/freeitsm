<?php
/**
 * API: add a free-text journal note to a problem.
 * Thin UI adapter over ProblemsService::addNote.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/problems.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }

try {
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $problemId = (int) ($d['problem_id'] ?? 0);
    if ($problemId <= 0) throw new Exception('Problem ID is required');
    $conn = connectToDatabase();
    ProblemsService::addNote($conn, ActorContext::fromSession($conn), $problemId, ['note' => $d['note'] ?? '']);
    echo json_encode(['success' => true, 'message' => 'Note added']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
