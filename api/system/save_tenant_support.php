<?php
/**
 * API: Set how one company supports another (GH #228).
 * POST JSON { tenant_id, supported_id, quiet_warning, portal_raise }
 *
 * tenant_id is the company giving support (B), supported_id the one it supports
 * (A). Both ticks off removes the link. See includes/tenant_support.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/tenant_support.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$supporterId = (int)($data['tenant_id'] ?? 0);
$supportedId = (int)($data['supported_id'] ?? 0);

try {
    $conn = connectToDatabase();
    if (!tenantSupportReady($conn)) {
        throw new Exception('Run System → Database Verification first.');
    }
    if ($supporterId <= 0 || $supportedId <= 0 || !getTenantById($conn, $supporterId) || !getTenantById($conn, $supportedId)) {
        throw new Exception('That company does not exist.');
    }
    tenantSupportSet($conn, $supporterId, $supportedId, !empty($data['quiet_warning']), !empty($data['portal_raise']));
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
