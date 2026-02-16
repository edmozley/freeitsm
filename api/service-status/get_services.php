<?php
/**
 * API: Get all services for the Service Status module
 * GET - Returns list of services ordered by display_order then name
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

    $sql = "SELECT id, name, description, is_active, display_order, created_datetime
            FROM status_services
            ORDER BY display_order, name";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($services as &$s) {
        $s['is_active'] = (bool)$s['is_active'];
    }

    echo json_encode(['success' => true, 'services' => $services]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
