<?php
/**
 * API Endpoint: Generate new API Key
 * Creates a new random API key for the software inventory external API
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

    // Generate a secure random 40-character hex key
    $apikey = bin2hex(random_bytes(20));

    $sql = "INSERT INTO apikeys (apikey) OUTPUT INSERTED.id VALUES (?)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$apikey]);
    $newId = $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => 'API key generated',
        'id' => $newId,
        'apikey' => $apikey
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
