<?php
/**
 * API Endpoint: Save Calendar Category
 * Creates or updates a category
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
$name = trim($input['name'] ?? '');
$color = trim($input['color'] ?? '#ef6c00');
$description = trim($input['description'] ?? '');
$isActive = isset($input['is_active']) ? (bool)$input['is_active'] : true;

if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Category name is required']);
    exit;
}

// Validate color format
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
    echo json_encode(['success' => false, 'error' => 'Invalid color format']);
    exit;
}

try {
    $conn = connectToDatabase();

    if ($id) {
        // Update existing category
        $sql = "UPDATE calendar_categories
                SET name = ?, color = ?, description = ?, is_active = ?, updated_at = UTC_TIMESTAMP()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $color, $description, $isActive ? 1 : 0, $id]);

        echo json_encode([
            'success' => true,
            'message' => 'Category updated',
            'id' => $id
        ]);
    } else {
        // Create new category
        $sql = "INSERT INTO calendar_categories (name, color, description, is_active)
                VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $color, $description, $isActive ? 1 : 0]);
        $newId = $conn->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Category created',
            'id' => $newId
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
