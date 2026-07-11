<?php
/**
 * API Endpoint: Save (create/update) a team
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$id = $input['id'] ?? null;
$name = trim($input['name'] ?? '');
$description = trim($input['description'] ?? '');
$displayOrder = intval($input['display_order'] ?? 0);
$isActive = isset($input['is_active']) ? ($input['is_active'] ? 1 : 0) : 1;

// Validate
if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Team name is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    if ($id) {
        // Update existing team
        $sql = "UPDATE teams SET name = ?, description = ?, display_order = ?, is_active = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $description, $displayOrder, $isActive, $id]);
        $message = 'Team updated successfully';
    } else {
        // Create new team
        $sql = "INSERT INTO teams (name, description, display_order, is_active) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $description, $displayOrder, $isActive]);

        $id = $conn->lastInsertId();
        $message = 'Team created successfully';
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'id' => $id
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
