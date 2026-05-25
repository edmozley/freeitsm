<?php
/**
 * API Endpoint: Delete Calendar Category
 * Only deletes if no events are using the category
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? (int)$input['id'] : null;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Category ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Check if any events are using this category
    $checkSql = "SELECT COUNT(*) as count FROM calendar_events WHERE category_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$id]);
    $count = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];

    if ($count > 0) {
        echo json_encode([
            'success' => false,
            'error' => "Cannot delete category: {$count} event(s) are using it. Please reassign or delete those events first."
        ]);
        exit;
    }

    // Delete the category
    $sql = "DELETE FROM calendar_categories WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Category deleted']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Category not found']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
