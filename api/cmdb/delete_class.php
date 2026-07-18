<?php
/**
 * API: Delete a CMDB class.
 * Refuses if any objects exist for the class — analyst must reassign or delete them first.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');
requireCapabilityJson(Cap::CMDB_CLASSES);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    // In-use guard. DELIBERATELY NOT company-scoped, unlike the sidebar count in
    // get_classes.php: a class is install-wide config, so deleting one must be
    // blocked while ANY company still has a CI using it — scoping this would let
    // one company delete a class another is actively using.
    $cnt = $conn->prepare("SELECT COUNT(*) FROM cmdb_objects WHERE class_id = ?");
    $cnt->execute([$id]);
    $objectCount = (int)$cnt->fetchColumn();
    if ($objectCount > 0) {
        throw new Exception("Cannot delete: $objectCount object(s) currently use this class. Delete or reassign them first.");
    }

    $name = $conn->query("SELECT name FROM cmdb_classes WHERE id = " . (int)$id)->fetchColumn() ?: null;
    // Cascade-delete the property definitions and dropdown options via FK.
    $stmt = $conn->prepare("DELETE FROM cmdb_classes WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('cmdb_class', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
