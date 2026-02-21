<?php
/**
 * API Endpoint: Get CAB members for a change
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

    $sql = "SELECT m.id, m.change_id, m.analyst_id, m.is_required, m.vote,
                   m.vote_comment, m.vote_datetime, m.added_by_id, m.added_datetime,
                   a.full_name as analyst_name,
                   adder.full_name as added_by_name
            FROM change_cab_members m
            LEFT JOIN analysts a ON m.analyst_id = a.id
            LEFT JOIN analysts adder ON m.added_by_id = adder.id
            WHERE m.change_id = ?
            ORDER BY m.is_required DESC, a.full_name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$changeId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'members' => $members]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
