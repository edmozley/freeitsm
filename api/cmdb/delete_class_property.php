<?php
/**
 * API: Delete a property definition.
 * Refuses if any objects have a value set for this property — analyst must clear values first.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    $cnt = $conn->prepare("SELECT COUNT(*) FROM cmdb_object_properties WHERE property_id = ?");
    $cnt->execute([$id]);
    $valueCount = (int)$cnt->fetchColumn();
    if ($valueCount > 0) {
        throw new Exception("Cannot delete: $valueCount object(s) have a value set for this property. Clear those values first.");
    }

    $name = $conn->query("SELECT label FROM cmdb_class_properties WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM cmdb_class_properties WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('cmdb_property', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
