<?php
/**
 * API Endpoint: Get change types
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

    $sql = "SELECT id, name, colour, is_default, display_order, is_active, created_datetime
            FROM change_types
            ORDER BY display_order, name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($types as &$t) {
        $t['is_default'] = (bool)$t['is_default'];
        $t['is_active']  = (bool)$t['is_active'];
    }

    echo json_encode([
        'success' => true,
        'types' => $types
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
