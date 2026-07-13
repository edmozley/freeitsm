<?php
/**
 * API Endpoint: Delete a rota shift
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
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_ROTA);   // settings tab — see docs/design/rbac.md

$input = json_decode(file_get_contents('php://input'), true);
$id = !empty($input['id']) ? (int)$input['id'] : null;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Shift ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Check if shift is in use
    $checkSql = "SELECT COUNT(*) as cnt FROM ticket_rota_entries WHERE shift_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$id]);
    $count = $checkStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    if ($count > 0) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete: shift is assigned to ' . $count . ' rota entries']);
        exit;
    }

    $sql = "DELETE FROM ticket_rota_shifts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
