<?php
/**
 * API Endpoint: Reorder process step types.
 * POST { order: [typeId, typeId, ...] } — rewrites display_order to match.
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
    $input = json_decode(file_get_contents('php://input'), true);
    $order = $input['order'] ?? [];
    if (!is_array($order) || !$order) throw new Exception('No order provided');

    $conn = connectToDatabase();
    $stmt = $conn->prepare("UPDATE process_step_types SET display_order = ? WHERE id = ?");
    $pos = 10;
    foreach ($order as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $stmt->execute([$pos, $id]);
            $pos += 10;
        }
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
