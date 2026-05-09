<?php
/**
 * API Endpoint: Get task priorities
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, name, colour, is_default, display_order, is_active, created_datetime
            FROM task_priorities
            ORDER BY display_order, name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $priorities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($priorities as &$p) {
        $p['is_default'] = (bool)$p['is_default'];
        $p['is_active']  = (bool)$p['is_active'];
    }

    echo json_encode([
        'success' => true,
        'priorities' => $priorities
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
