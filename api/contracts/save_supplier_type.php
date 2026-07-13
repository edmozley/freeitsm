<?php
/**
 * API Endpoint: Save supplier type (create or update)
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
requireModuleAccessJson('contracts');
requireCapabilityJson(Cap::CONTRACTS_SUPPLIER_TYPES);   // Contracts settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id = $data['id'] ?? null;
    $name = $data['name'] ?? '';
    $description = $data['description'] ?? '';
    $display_order = $data['display_order'] ?? 0;
    $is_active = $data['is_active'] ?? 1;

    if (empty($name)) {
        throw new Exception('Name is required');
    }

    $conn = connectToDatabase();

    if ($id) {
        $sql = "UPDATE supplier_types SET name = ?, description = ?, display_order = ?, is_active = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $description, $display_order, $is_active, $id]);
    } else {
        $sql = "INSERT INTO supplier_types (name, description, display_order, is_active) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $description, $display_order, $is_active]);
    }

    wf_emit('supplier_type', $id ? 'updated' : 'created', $id ? (int)$id : (int)$conn->lastInsertId(), $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
