<?php
/**
 * API: the request catalogue — forms offered to customers in the portal.
 *
 * Two gates, both required:
 *   is_portal_visible = 1  — someone deliberately offered this form to
 *                            customers. Defaults to 0, so an upgrade never
 *                            exposes an existing internal form (a new-starter
 *                            request a manager fills in is `is_active`, but has
 *                            no business being on a customer's menu).
 *   is_active = 1          — the analyst-side on/off, unchanged.
 *
 * Forms are VERSIONED: each row is one snapshot, chained by parent_form_id, and
 * the LEAF (no children) is the current one. The catalogue lists leaves only,
 * exactly as the analyst list does — otherwise every past revision of a form
 * would appear as a separate thing to request.
 *
 * ⚠️ Forms are NOT multi-company yet (no tenant_id on any forms table), so this
 * catalogue is the same for every company. That is a known limitation, not an
 * oversight — see the module's multi-tenancy work.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->query(
        "SELECT f.id, f.title, f.description
         FROM forms f
         WHERE f.is_portal_visible = 1
           AND f.is_active = 1
           AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)
         ORDER BY f.title ASC"
    );
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Descriptions are author-written plain text; strip any markup so the
    // catalogue card can render it as text.
    foreach ($forms as &$f) {
        $f['description'] = trim(preg_replace('/\s+/', ' ', strip_tags((string)$f['description'])));
    }
    unset($f);

    echo json_encode(['success' => true, 'forms' => $forms]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
