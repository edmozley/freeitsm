<?php
/**
 * API Endpoint: Get the company (tenant) access a team grants its members.
 * Returns the all-access flag + the specific granted tenant ids. Used by the
 * "Manage companies" picker on System → Teams.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
if (!$teamId) {
    echo json_encode(['success' => false, 'error' => 'Team ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT can_access_all_tenants FROM teams WHERE id = ?");
    $stmt->execute([$teamId]);
    $allAccess = (int) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT tenant_id FROM team_tenant_access WHERE team_id = ?");
    $stmt->execute([$teamId]);
    $tenantIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    echo json_encode([
        'success' => true,
        'can_access_all_tenants' => $allAccess,
        'tenant_ids' => $tenantIds,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
