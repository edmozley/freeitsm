<?php
/**
 * API: List workflows for the landing page.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->query(
        "SELECT id, name, description, trigger_event, is_active,
                last_run_datetime, last_run_status, run_count,
                created_datetime, updated_datetime
         FROM workflows
         ORDER BY updated_datetime DESC, id DESC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'workflows' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
