<?php
/**
 * Save Ticket Origin API - Create or Update
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

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid request data');
    }

    $id = $input['id'] ?? null;
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $displayOrder = intval($input['display_order'] ?? 0);
    $isActive = isset($input['is_active']) ? ($input['is_active'] ? 1 : 0) : 1;

    if (empty($name)) {
        throw new Exception('Name is required');
    }

    $conn = connectToDatabase();

    if ($id) {
        // Update existing
        $sql = "UPDATE ticket_origins SET name = ?, description = ?, display_order = ?, is_active = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $description, $displayOrder, $isActive, $id]);

        echo json_encode([
            'success' => true,
            'message' => 'Ticket origin updated successfully',
            'id' => $id
        ]);
    } else {
        // Insert new
        $sql = "INSERT INTO ticket_origins (name, description, display_order, is_active) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $description, $displayOrder, $isActive]);

        // Get the new ID
        $newId = $conn->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Ticket origin created successfully',
            'id' => $newId
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
