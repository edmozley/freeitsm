<?php
/**
 * API Endpoint: Get API Keys
 * Returns all API keys for the software inventory external API
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

    $sql = "SELECT id, apikey,
                   CONVERT(VARCHAR(19), datestamp, 120) AS created_at,
                   active
            FROM apikeys
            ORDER BY datestamp DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'keys' => $keys
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
