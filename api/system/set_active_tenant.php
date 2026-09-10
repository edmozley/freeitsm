<?php
/**
 * API Endpoint: set the analyst's active company (tenant) context.
 *
 * Stores the chosen tenant id in the session so the rest of the app can scope
 * to it. Validates that the analyst is actually allowed to access that company.
 *
 * Requires a WRITABLE session — note the plain session_start() (NOT
 * read_and_close), because we write $_SESSION['active_tenant_id'].
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// The consolidated view (#1554). Sent as its own field rather than a reserved
// tenant_id value: `tenant_id: 0` or `-1` would be indistinguishable from a bug
// upstream, and would mean something dangerous if it ever reached the filters.
$wantAll = !empty($data['all_companies']);

$tenantId = isset($data['tenant_id']) ? (int) $data['tenant_id'] : 0;

if (!$wantAll && $tenantId < 1) {
    echo json_encode(['success' => false, 'error' => 'tenant_id is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    if ($wantAll) {
        // No permission of its own: "all" means "every company this analyst may
        // already see", so it grants nothing they did not have one switch away.
        // An analyst with a single company gets a view of that single company.
        //
        // ⚠️ The active company is deliberately LEFT ALONE. It is what a WRITE
        // still resolves against — ticket numbering, mailboxes, SLA — so it has
        // to keep naming a real company while the view is widened.
        setActiveTenantAll(true);
        echo json_encode(['success' => true, 'all_companies' => true]);
        exit;
    }

    if (!analystCanAccessTenant($conn, $analystId, $tenantId)) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to that company']);
        exit;
    }

    setActiveTenantId($tenantId);   // also clears the consolidated view

    echo json_encode(['success' => true, 'tenant_id' => $tenantId, 'all_companies' => false]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
