<?php
/**
 * API Endpoint: Get users list
 * Returns users with optional search filtering
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get search parameter
$search = $_GET['search'] ?? '';

try {
    $conn = connectToDatabase();

    // Build query with optional search
    $sql = "SELECT
                u.id,
                u.email,
                u.display_name,
                u.preferred_name,
                u.created_at,
                (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id) as ticket_count
            FROM users u";

    $params = [];

    if (!empty($search)) {
        $sql .= " WHERE u.display_name LIKE ? OR u.email LIKE ?";
        $searchParam = '%' . $search . '%';
        $params = [$searchParam, $searchParam];
    }

    $sql .= " ORDER BY u.display_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'users' => $users
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
