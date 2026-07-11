<?php
/**
 * API Endpoint: set the "access all modules" flag for one analyst or team.
 *
 * Lets an admin grant/revoke all-module access straight from System -> Modules
 * without going back to the Analysts/Teams screens (issue #30). Admin-only.
 * POST { kind: 'analyst'|'team', id: int, all_modules: bool }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$kind = $data['kind'] ?? '';
$id   = (int) ($data['id'] ?? 0);
$all  = !empty($data['all_modules']) ? 1 : 0;

if (!in_array($kind, ['analyst', 'team'], true) || $id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    $conn  = connectToDatabase();
    $table = $kind === 'analyst' ? 'analysts' : 'teams';
    $stmt  = $conn->prepare("UPDATE $table SET can_access_all_modules = ? WHERE id = ?");
    $stmt->execute([$all, $id]);
    echo json_encode(['success' => true, 'all_modules' => $all]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
