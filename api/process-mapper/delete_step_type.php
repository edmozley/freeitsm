<?php
/**
 * API Endpoint: Delete a process step type.
 *
 * Built-in types cannot be deleted. Steps that still reference a deleted
 * type by its slug fall back to a plain rectangle in the editor — the
 * coupling is intentionally loose (process_steps.type is a free-text string,
 * not a foreign key), so a delete can never corrupt a saved diagram.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('process-mapper');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Type ID is required');

    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT is_builtin FROM process_step_types WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Type not found');
    if ((int)$row['is_builtin'] === 1) throw new Exception('Built-in types cannot be deleted');

    $conn->prepare("DELETE FROM process_step_types WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
