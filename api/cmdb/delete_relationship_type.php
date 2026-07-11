<?php
/**
 * API: Delete a CMDB relationship type.
 * Refuses if any relationships currently use this type.
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

    $cnt = $conn->prepare("SELECT COUNT(*) FROM cmdb_object_relationships WHERE relationship_type_id = ?");
    $cnt->execute([$id]);
    $useCount = (int)$cnt->fetchColumn();
    if ($useCount > 0) {
        throw new Exception("Cannot delete: $useCount relationship(s) currently use this type.");
    }

    $name = $conn->query("SELECT verb FROM cmdb_relationship_types WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM cmdb_relationship_types WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('cmdb_relationship_type', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
