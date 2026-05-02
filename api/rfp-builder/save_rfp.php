<?php
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$VALID_STATUSES = ['draft', 'collecting', 'consolidating', 'generating', 'scoring', 'closed', 'abandoned'];

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id = $data['id'] ?? null;
    $name = trim($data['name'] ?? '');
    $status = $data['status'] ?? 'draft';
    $style_guide = trim($data['style_guide'] ?? '') ?: null;
    $contract_id = $data['contract_id'] ?? null;
    $chosen_supplier_id = $data['chosen_supplier_id'] ?? null;

    if ($name === '') {
        throw new Exception('Name is required');
    }
    if (!in_array($status, $VALID_STATUSES, true)) {
        throw new Exception('Invalid status');
    }

    $conn = connectToDatabase();

    if ($id) {
        $sql = "UPDATE rfps
                   SET name = ?, status = ?, style_guide = ?,
                       contract_id = ?, chosen_supplier_id = ?,
                       updated_datetime = CURRENT_TIMESTAMP
                 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $name, $status, $style_guide,
            $contract_id ?: null, $chosen_supplier_id ?: null,
            $id
        ]);
    } else {
        $sql = "INSERT INTO rfps (name, status, style_guide, contract_id, chosen_supplier_id, created_by_analyst_id)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $name, $status, $style_guide,
            $contract_id ?: null, $chosen_supplier_id ?: null,
            $_SESSION['analyst_id']
        ]);
        $id = $conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => (int)$id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
