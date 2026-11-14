<?php
/**
 * API: Create or update a company (tenant).
 * POST JSON { id?, name, is_active }
 *
 * "Company" is the user-facing word for a tenant; the underlying table/code
 * stays `tenants`. is_default is out of scope here and never edited.
 *
 * GUARD: the default company (is_default = 1) can never be set inactive.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_numbering.php';

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

// --- Validate fields ---
// GH #279: every rule (name, ticket code, outside reference, tax ID, the Default
// company staying active) is in CompaniesService, shared with the REST API.
require_once '../../includes/services/companies.php';
$id = isset($data['id']) ? (int)$data['id'] : 0;
try {
    $conn = connectToDatabase();
    $in = [];
    foreach (['name', 'is_active', 'ticket_code', 'external_ref', 'tax_id'] as $k) {
        if (array_key_exists($k, $data)) $in[$k] = $data[$k];
    }
    // The form always sends these two; an older page without them still saves.
    if (!CompaniesService::refsReady($conn)) unset($in['external_ref'], $in['tax_id']);
    $r = CompaniesService::save($conn, $in, $id > 0 ? $id : null);
    echo json_encode(['success' => true, 'id' => $r['id']]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('save_tenant: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The company could not be saved.']);
}
