<?php
/**
 * API Endpoint: Check if demo core data has been imported.
 * Looks for demo analyst usernames to determine if core was already loaded.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE username = 'jsmith'");
    $stmt->execute();
    $count = (int)$stmt->fetchColumn();
    echo json_encode(['exists' => $count > 0]);
} catch (Exception $e) {
    echo json_encode(['exists' => false]);
}
?>
