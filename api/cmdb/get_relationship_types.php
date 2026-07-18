<?php
/**
 * API: List CMDB relationship types.
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

// CMDB reference data — was reachable by any signed-in analyst, even one
// without the CMDB module. Classes stay install-wide (no company scope).
requireModuleAccessJson('cmdb');

try {
    $conn = connectToDatabase();
    $stmt = $conn->query(
        "SELECT id, verb, inverse_verb, description, display_order, is_active
           FROM cmdb_relationship_types
       ORDER BY display_order, verb"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['display_order'] = (int)$r['display_order'];
        $r['is_active'] = (int)$r['is_active'] === 1;
    }
    echo json_encode(['success' => true, 'relationship_types' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
