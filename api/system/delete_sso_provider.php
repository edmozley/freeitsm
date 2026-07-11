<?php
/**
 * API: Delete an SSO / OIDC identity provider.
 * POST JSON { id }
 *
 * Linked rows in analyst_sso_identities are removed by the ON DELETE CASCADE
 * foreign key; any analyst whose auth_provider_id pointed here is reset to
 * local (ON DELETE SET NULL).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing provider id']);
    exit;
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare("DELETE FROM auth_providers WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
