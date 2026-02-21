<?php
/**
 * API Endpoint: Get audit trail for a change
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$changeId = (int)($_GET['change_id'] ?? 0);

if (!$changeId) {
    echo json_encode(['success' => false, 'error' => 'Change ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT a.id, a.change_id, a.analyst_id, a.action_type, a.field_name,
                   a.old_value, a.new_value, a.created_datetime,
                   an.full_name as analyst_name
            FROM change_audit a
            LEFT JOIN analysts an ON a.analyst_id = an.id
            WHERE a.change_id = ?
            ORDER BY a.created_datetime DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$changeId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'entries' => $entries]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
