<?php
/**
 * API Endpoint: Get Calendar Categories
 * Returns all categories (or only active ones if specified)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$activeOnly = isset($_GET['active_only']) && $_GET['active_only'] === '1';

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, name, color, description, is_active, created_at, updated_at
            FROM calendar_categories";
    if ($activeOnly) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert is_active to boolean for JS
    foreach ($categories as &$cat) {
        $cat['is_active'] = (bool)$cat['is_active'];
    }

    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
