<?php
/**
 * Get a single process with its steps and connectors
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing process ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT * FROM processes WHERE id = ?");
    $stmt->execute([$id]);
    $process = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$process) {
        echo json_encode(['success' => false, 'error' => 'Process not found']);
        exit;
    }

    $stepsStmt = $conn->prepare("SELECT * FROM process_steps WHERE process_id = ?");
    $stepsStmt->execute([$id]);
    $steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

    $connStmt = $conn->prepare("SELECT * FROM process_connectors WHERE process_id = ?");
    $connStmt->execute([$id]);
    $connectors = $connStmt->fetchAll(PDO::FETCH_ASSOC);

    $process['steps'] = $steps;
    $process['connectors'] = $connectors;

    echo json_encode(['success' => true, 'data' => $process]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
