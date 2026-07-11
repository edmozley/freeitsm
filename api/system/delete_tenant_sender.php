<?php
/**
 * API: Remove a specific sender-address mapping from a company (tenant).
 * POST JSON { id }  (the tenant_sender_addresses row id)
 *
 * Future mail from that address falls back to domain routing (shared intake) or
 * triage. Nothing is deleted beyond the mapping; existing tickets are untouched.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing address id']);
    exit;
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare("DELETE FROM tenant_sender_addresses WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
