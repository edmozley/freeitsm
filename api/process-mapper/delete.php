<?php
/**
 * Delete a process and its steps/connectors
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = (int)($input['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing process ID']);
    exit;
}

try {
    $conn = connectToDatabase();
    $conn->beginTransaction();

    $conn->prepare("DELETE FROM process_connectors WHERE process_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM process_steps WHERE process_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM processes WHERE id = ?")->execute([$id]);

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
