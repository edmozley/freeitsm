<?php
/**
 * API Endpoint: Save task tag (create or update)
 * Returns the tag id so the detail-panel picker can attach a newly created tag.
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
    $data = json_decode(file_get_contents('php://input'), true);

    $id            = $data['id'] ?? null;
    $name          = trim($data['name'] ?? '');
    $colour        = trim($data['colour'] ?? '');
    $display_order = (int)($data['display_order'] ?? 0);

    if ($name === '') throw new Exception('Name is required');
    if ($colour !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
        throw new Exception('Colour must be a #rrggbb hex code');
    }

    $conn = connectToDatabase();

    if ($id) {
        $stmt = $conn->prepare("UPDATE task_tags SET name = ?, colour = ?, display_order = ? WHERE id = ?");
        $stmt->execute([$name, $colour ?: null, $display_order, (int)$id]);
        echo json_encode(['success' => true, 'id' => (int)$id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO task_tags (name, colour, display_order) VALUES (?, ?, ?)");
        $stmt->execute([$name, $colour ?: null, $display_order]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    }

} catch (Exception $e) {
    // Friendlier message for the unique-name constraint
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        echo json_encode(['success' => false, 'error' => 'A tag with that name already exists']);
    } else {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>
