<?php
/**
 * API Endpoint: Get machines with a specific software application
 * Returns hostname, version, install date, and last seen for each machine
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$app_id = $_GET['app_id'] ?? '';

if (empty($app_id)) {
    echo json_encode(['success' => false, 'error' => 'app_id is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT
                h.hostname,
                d.display_version,
                d.install_date,
                DATE_FORMAT(d.last_seen, '%Y-%m-%d') as last_seen
            FROM software_inventory_detail d
            INNER JOIN assets h ON h.id = d.host_id
            WHERE d.app_id = ?
            ORDER BY h.hostname ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$app_id]);
    $machines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'machines' => $machines
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
