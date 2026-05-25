<?php
/**
 * API: Create a relationship between two CMDB objects.
 * Refuses self-links and duplicates of an existing (from, to, type) triple.
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
    $from = isset($data['from_object_id']) ? (int)$data['from_object_id'] : 0;
    $to   = isset($data['to_object_id']) ? (int)$data['to_object_id'] : 0;
    $type = isset($data['relationship_type_id']) ? (int)$data['relationship_type_id'] : 0;

    if ($from <= 0 || $to <= 0 || $type <= 0) throw new Exception('from_object_id, to_object_id and relationship_type_id are required');
    if ($from === $to) throw new Exception('An object can\'t have a relationship with itself');

    $conn = connectToDatabase();

    // Verify both objects + the type exist
    $vs = $conn->prepare("SELECT id FROM cmdb_objects WHERE id IN (?, ?)");
    $vs->execute([$from, $to]);
    if (count($vs->fetchAll(PDO::FETCH_COLUMN)) !== 2) throw new Exception('One or both objects not found');

    $ts = $conn->prepare("SELECT id FROM cmdb_relationship_types WHERE id = ? AND is_active = 1");
    $ts->execute([$type]);
    if (!$ts->fetch()) throw new Exception('Relationship type not found or inactive');

    // Insert (or surface a friendly duplicate error)
    try {
        $ins = $conn->prepare(
            "INSERT INTO cmdb_object_relationships (from_object_id, to_object_id, relationship_type_id, created_datetime)
             VALUES (?, ?, ?, UTC_TIMESTAMP())"
        );
        $ins->execute([$from, $to, $type]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    } catch (PDOException $pe) {
        if ($pe->errorInfo[1] == 1062) { // duplicate key
            throw new Exception('That relationship already exists.');
        }
        throw $pe;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
