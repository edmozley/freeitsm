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
require_once '../../includes/services/forms.php';   // portalCatalogueFilter()
require_once '../../includes/catalogue_card.php';   // the shop card (GH #348)

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    // GH #217: nothing from a module switched off for the install.
    requireModuleOnJson($conn, 'forms');

    /* A third gate, but only when the operator chose it: forms whose
       collection has closed. Empty string on every other install, so the
       query is unchanged for anyone not using collections - and unchanged
       for anyone who has not run DB Verification yet, which is the case
       where naming the column would 500 the whole portal catalogue.
       ⚠️ A FILTER, never a stored flag: closing must not write
       is_portal_visible = 0 onto the forms, or reopening would switch on
       one that was deliberately kept off. */
    $collectionGate = FormsService::portalCatalogueFilter($conn, 'f');

    /* 🔴 And the AUDIENCE gate (GH #145): a form restricted to a group of
       people is not in this customer's catalogue at all. Filtered in the QUERY
       rather than after the fetch, so a restricted form is indistinguishable
       from one that does not exist. No rows in form_audiences means everyone,
       which is every form that predates the feature. */
    $audienceGate = ' AND ' . FormsService::portalAudienceSql($conn, 'f');

    // The shop card (GH #348), once Database Verification has added its columns.
    $cardCols = catalogueCardAvailable($conn)
        ? ', f.catalogue_summary, f.catalogue_price, f.catalogue_currency, f.catalogue_price_period, f.catalogue_image_path'
        : '';
    $stmt = $conn->prepare(
        "SELECT f.id, f.title, f.description" . $cardCols . "
         FROM forms f
         WHERE f.is_portal_visible = 1
           AND f.is_active = 1
           AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)"
         . $collectionGate . $audienceGate .
        " ORDER BY f.title ASC"
    );
    $stmt->execute([':audUser' => (int)$_SESSION['ss_user_id']]);
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Descriptions are author-written plain text; strip any markup so the
    // catalogue card can render it as text. Only what the card shows goes out:
    // the stored picture path is ours, not the customer's.
    $out = [];
    foreach ($forms as $f) {
        $out[] = [
            'id'          => (int)$f['id'],
            'title'       => $f['title'],
            'description' => trim(preg_replace('/\s+/', ' ', strip_tags((string)$f['description']))),
        ] + catalogueCardForPortal($f);
    }

    echo json_encode(['success' => true, 'forms' => $out]);

} catch (Exception $e) {
    // Never the exception's own text: it can carry SQL and column names.
    error_log('get_catalogue: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The catalogue could not be loaded.']);
}
