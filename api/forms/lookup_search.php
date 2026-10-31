<?php
/**
 * Search a lookup field's source, scoped to whoever is asking.
 *
 * Two callers with genuinely different rights, which is why the scope is worked
 * out HERE rather than inside the service:
 *
 *   an analyst     → the companies they can access
 *   a portal user  → their own company, and only if the FIELD says so
 *
 * ⚠️ The field id is required and the source is read FROM THE FIELD, never from
 * the request. Letting the caller name the source would make "which laptop?"
 * into "search the staff directory" with one edited parameter.
 *
 * GET ?field_id=12&q=lt-00
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/forms.php';

header('Content-Type: application/json');

$conn     = connectToDatabase();
$fieldId  = (int)($_GET['field_id'] ?? 0);
$term     = (string)($_GET['q'] ?? '');

$analystId = isset($_SESSION['analyst_id']) ? (int)$_SESSION['analyst_id'] : 0;
// ⚠️ The portal session key is `ss_user_id` — see api/self-service/*. Guessing
// it would have made every portal request look unauthenticated.
$portalUid = isset($_SESSION['ss_user_id']) ? (int)$_SESSION['ss_user_id'] : 0;

if (!$analystId && !$portalUid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
if ($fieldId <= 0) {
    echo json_encode(['success' => false, 'error' => 'No field.']);
    exit;
}

// The field decides the source. Also pull the form so we can check it is one a
// portal user is even allowed to see.
$fq = $conn->prepare(
    "SELECT f.id, f.field_type, f.config, fo.is_portal_visible
       FROM form_fields f
       JOIN forms fo ON fo.id = f.form_id
      WHERE f.id = ? AND f.is_deleted = 0"
);
$fq->execute([$fieldId]);
$field = $fq->fetch(PDO::FETCH_ASSOC);

if (!$field || $field['field_type'] !== 'lookup') {
    echo json_encode(['success' => false, 'error' => 'Not a lookup field.']);
    exit;
}

$source = FormsService::lookupSourceOf($field);
if ($source === null) {
    echo json_encode(['success' => false, 'error' => 'That field has no source configured.']);
    exit;
}

// ── Work out the scope ────────────────────────────────────────────────────
if ($analystId) {
    // An analyst restricted to some companies sees those; an unrestricted one
    // gets EVERY tenant id back (this helper always returns an array, never
    // null for "all"), which scopes identically and is safer than a magic value.
    $tenantIds = function_exists('getAccessibleTenantIds')
        ? getAccessibleTenantIds($conn, $analystId)
        : null;
} else {
    // ⚠️ A customer. BOTH gates must pass: the form must be a portal form, and
    // the field must be ticked for portal use (which also requires the source
    // to be portal-safe). Default is no.
    if (empty($field['is_portal_visible']) || !FormsService::lookupPortalAllowed($field)) {
        echo json_encode(['success' => false, 'error' => 'Not available here.']);
        exit;
    }
    // ⚠️ Their own company ONLY. The same call the submit makes.
    $tenantIds = FormsService::lookupPortalScope($conn, $portalUid);
}

echo json_encode([
    'success' => true,
    'results' => FormsService::lookupSearch($conn, $source, $term, $tenantIds, 20),
]);
