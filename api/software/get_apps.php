<?php
/**
 * API Endpoint: Get software applications list
 * Returns all applications with publisher and install counts
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Optional filter: 'apps' = user-visible only, 'components' = system components only, '' = all
    $filter = $_GET['filter'] ?? '';

    $where = '';
    $params = [];
    if ($filter === 'apps') {
        $where = 'HAVING MAX(d.system_component) = 0 OR MAX(d.system_component) IS NULL';
    } elseif ($filter === 'components') {
        $where = 'HAVING MAX(d.system_component) = 1';
    }

    $sql = "SELECT
                a.id,
                a.display_name,
                a.publisher,
                COUNT(DISTINCT d.host_id) as install_count,
                MAX(d.system_component) as system_component
            FROM software_inventory_apps a
            LEFT JOIN software_inventory_detail d ON d.app_id = a.id
            GROUP BY a.id, a.display_name, a.publisher
            $where
            ORDER BY a.display_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'apps' => $apps
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
