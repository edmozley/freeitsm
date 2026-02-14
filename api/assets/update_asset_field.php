<?php
/**
 * API Endpoint: Update a single field on an asset (type or status)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $asset_id = $data['asset_id'] ?? null;
    $field = $data['field'] ?? '';
    $value = $data['value'] ?? null;

    if (!$asset_id) {
        throw new Exception('Asset ID is required');
    }

    // Whitelist allowed fields to prevent SQL injection
    $allowedFields = ['asset_type_id', 'asset_status_id'];
    if (!in_array($field, $allowedFields)) {
        throw new Exception('Invalid field');
    }

    // Convert empty string to null
    if ($value === '' || $value === null) {
        $value = null;
    }

    $conn = connectToDatabase();

    // Get the current value before updating
    $oldStmt = $conn->prepare("SELECT $field FROM assets WHERE id = ?");
    $oldStmt->execute([$asset_id]);
    $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
    $oldValue = $oldRow ? $oldRow[$field] : null;

    $sql = "UPDATE assets SET $field = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$value, $asset_id]);

    // Resolve display names for type/status IDs
    $oldDisplay = $oldValue;
    $newDisplay = $value;
    if ($field === 'asset_type_id') {
        $nameQuery = "SELECT name FROM asset_types WHERE id = ?";
        if ($oldValue) { $n = $conn->prepare($nameQuery); $n->execute([$oldValue]); $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $oldDisplay = $r['name']; }
        if ($value)    { $n = $conn->prepare($nameQuery); $n->execute([$value]);    $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $newDisplay = $r['name']; }
    } elseif ($field === 'asset_status_id') {
        $nameQuery = "SELECT name FROM asset_status_types WHERE id = ?";
        if ($oldValue) { $n = $conn->prepare($nameQuery); $n->execute([$oldValue]); $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $oldDisplay = $r['name']; }
        if ($value)    { $n = $conn->prepare($nameQuery); $n->execute([$value]);    $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $newDisplay = $r['name']; }
    }

    // Log the change to asset_history
    $fieldLabel = $field === 'asset_type_id' ? 'Type' : ($field === 'asset_status_id' ? 'Status' : $field);
    $auditSql = "INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                 VALUES (?, ?, ?, ?, ?, GETUTCDATE())";
    $auditStmt = $conn->prepare($auditSql);
    $auditStmt->execute([$asset_id, $_SESSION['analyst_id'], $fieldLabel, $oldDisplay, $newDisplay]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
