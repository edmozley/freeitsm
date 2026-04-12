<?php
/**
 * API Endpoint: Get API Keys
 * Returns all API keys with owner information
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

    $sql = "SELECT k.id, k.apikey, k.label,
                   DATE_FORMAT(k.datestamp, '%Y-%m-%d %H:%i:%s') AS created_at,
                   k.active, k.analyst_id,
                   a.full_name AS analyst_name
            FROM apikeys k
            LEFT JOIN analysts a ON k.analyst_id = a.id
            ORDER BY k.datestamp DESC";

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
