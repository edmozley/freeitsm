<?php
/**
 * API Endpoint (portal): the categories a REQUESTER may choose from (#1540).
 *
 * Deliberately narrower than the analyst endpoint in three ways:
 *
 *   1. `is_portal_visible` only. Analysts see the whole tree; a customer should
 *      see a short list in plain English, not sixty internal leaves.
 *   2. Active only, and a category whose ANCESTOR is retired or hidden goes with
 *      it — otherwise a child surfaces at the top level wearing a path that no
 *      longer resolves.
 *   3. Untied categories only. The portal form has no ticket type field at all,
 *      so a category tied to one has no context here and offering it would let a
 *      requester pick "Incident / Printing" on a ticket that is not an Incident.
 *
 * Also returns whether the field is switched on for this user's company, so the
 * form can decide whether to render it without a second request.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/tenant_settings.php';
require_once '../../includes/ticket_categories.php';

header('Content-Type: application/json');

// ⚠️ The portal and the analyst app SHARE ONE SESSION, and the portal's own key
// is `ss_user_id` — NOT `analyst_id`. A signed-in analyst
// hitting this must be answered as the PORTAL USER they are browsing as, never
// as themselves — see the LMS work, where exactly this leaked an admin's own
// courses into the portal.
if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn   = connectToDatabase();
    $userId = (int) $_SESSION['ss_user_id'];

    $st = $conn->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $tenantId = ($row && $row['tenant_id'] !== null) ? (int) $row['tenant_id'] : null;

    $enabled = ticketCategoryOn($conn, $tenantId);

    $categories = [];
    if ($enabled) {
        foreach (ticketCategoriesResolved($conn, $tenantId, ['activeOnly' => true, 'portalOnly' => true]) as $c) {
            // Reason 3 above: no ticket type context on this form.
            if ($c['effective_type_id'] !== null) continue;
            $categories[] = [
                'id'         => $c['id'],
                'name'       => $c['name'],
                'depth'      => $c['depth'],
                'path_label' => $c['path_label'],
            ];
        }
    }

    echo json_encode(['success' => true, 'enabled' => $enabled, 'categories' => $categories]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
