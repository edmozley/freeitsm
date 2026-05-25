<?php
/**
 * API: Get a single workflow with its conditions + actions decoded, plus
 * the latest execution rows so the editor can show a recent-runs sidebar.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT * FROM workflows WHERE id = ?");
    $stmt->execute([$id]);
    $wf = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$wf) {
        echo json_encode(['success' => false, 'error' => 'Workflow not found']);
        exit;
    }

    // Decode JSON columns for the client.
    $wf['conditions'] = json_decode($wf['conditions'] ?: '[]', true) ?: [];
    $wf['actions']    = json_decode($wf['actions']    ?: '[]', true) ?: [];

    // Last 20 executions, newest first.
    $execStmt = $conn->prepare(
        "SELECT id, trigger_event, status, started_datetime, finished_datetime, error_message
         FROM workflow_executions
         WHERE workflow_id = ?
         ORDER BY started_datetime DESC, id DESC
         LIMIT 20"
    );
    $execStmt->execute([$id]);
    $executions = $execStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'workflow' => $wf, 'executions' => $executions]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
