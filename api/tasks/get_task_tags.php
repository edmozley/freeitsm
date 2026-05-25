<?php
/**
 * API Endpoint: Get task tags
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
    $stmt = $conn->query("SELECT id, name, colour, display_order, created_datetime
                          FROM task_tags
                          ORDER BY display_order, name");
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tags as &$t) {
        $t['id'] = (int)$t['id'];
        $t['display_order'] = (int)$t['display_order'];
    }
    unset($t);
    echo json_encode(['success' => true, 'tags' => $tags]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
