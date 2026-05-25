<?php
/**
 * API Endpoint: Get change impacts
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
            FROM change_impacts
            ORDER BY display_order, name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $impacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($impacts as &$i) {
        $i['is_default'] = (bool)$i['is_default'];
        $i['is_active']  = (bool)$i['is_active'];
    }

    echo json_encode([
        'success' => true,
        'impacts' => $impacts
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
