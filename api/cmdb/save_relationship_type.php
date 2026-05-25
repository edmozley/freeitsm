<?php
/**
 * API: Create or update a CMDB relationship type.
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
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
    $verb = trim((string)($data['verb'] ?? ''));
    $inverse = trim((string)($data['inverse_verb'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $displayOrder = isset($data['display_order']) ? (int)$data['display_order'] : 0;
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($verb === '') throw new Exception('Verb is required');
    if ($inverse === '') throw new Exception('Inverse verb is required');
    if (mb_strlen($verb) > 100) throw new Exception('Verb too long (max 100 chars)');
    if (mb_strlen($inverse) > 100) throw new Exception('Inverse verb too long (max 100 chars)');

    $conn = connectToDatabase();

    // Refuse duplicate verbs
    $check = $conn->prepare("SELECT id FROM cmdb_relationship_types WHERE verb = ? AND ($id IS NULL OR id <> ?)");
    $check->execute([$verb, $id ?: 0]);
    if ($check->fetch()) {
        throw new Exception('Another relationship type already uses that verb');
    }

    if ($id === null) {
        $stmt = $conn->prepare(
            "INSERT INTO cmdb_relationship_types (verb, inverse_verb, description, display_order, is_active, created_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([$verb, $inverse, $description ?: null, $displayOrder, $isActive]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    } else {
        $stmt = $conn->prepare(
            "UPDATE cmdb_relationship_types
                SET verb = ?, inverse_verb = ?, description = ?, display_order = ?, is_active = ?
              WHERE id = ?"
        );
        $stmt->execute([$verb, $inverse, $description ?: null, $displayOrder, $isActive, $id]);
        echo json_encode(['success' => true, 'id' => $id]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
