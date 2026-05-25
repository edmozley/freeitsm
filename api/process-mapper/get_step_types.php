<?php
/**
 * API Endpoint: Get process step types (the configurable block-type palette).
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
    $conn = connectToDatabase();
    $stmt = $conn->query("SELECT id, name, slug, shape, color, display_order, is_active, is_builtin
                          FROM process_step_types
                          ORDER BY display_order, name");
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($types as &$t) {
        $t['id']            = (int)$t['id'];
        $t['display_order'] = (int)$t['display_order'];
        $t['is_active']     = (int)$t['is_active'];
        $t['is_builtin']    = (int)$t['is_builtin'];
    }
    unset($t);
    echo json_encode(['success' => true, 'types' => $types]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
