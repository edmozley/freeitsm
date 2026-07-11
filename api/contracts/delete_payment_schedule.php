<?php
/**
 * API Endpoint: Delete payment schedule
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('contracts');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;

    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();

    // Nullify any contracts referencing this schedule before deleting
    $stmt = $conn->prepare("UPDATE contracts SET payment_schedule_id = NULL WHERE payment_schedule_id = ?");
    $stmt->execute([$id]);

    $name = $conn->query("SELECT name FROM payment_schedules WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM payment_schedules WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('payment_schedule', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
