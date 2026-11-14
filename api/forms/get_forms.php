<?php
/**
 * API: Get all forms with field count and submission count
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/forms.php';   // collectionsAvailable()

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('forms');

try {
    $conn = connectToDatabase();

    // List view returns ONE row per form chain — the leaf (current
    // editable version). Older snapshots in each chain are surfaced via
    // the Versions dropdown inside the editor, not here. Filter is
    // "no children" which works for both single-version forms (NULL
    // parent_form_id, no children) and forks (parent set, no children).
    /* Collections, only if the schema has them. Empty strings otherwise, so
       the query below is byte for byte the one that shipped before - this is
       the forms dashboard, and an unknown-column error here takes the whole
       module out for anyone who has not run DB Verification yet.
       `closed_datetime` comes along so the list can mark a form whose
       collection has closed, rather than it only becoming apparent to the
       person who tries to fill it in. */
    $hasCollections = FormsService::collectionsAvailable($conn);
    $collectionSelect = $hasCollections
        ? "                   f.collection_id, col.name AS collection_name,
                   col.closed_datetime AS collection_closed_datetime,"
        : '';
    /* Whether this form is restricted to a group of people (GH #145).
       A COUNT, not the groups themselves: the list only needs to show THAT a
       restriction exists, and fetching every audience for every row to render
       one pill would be a query per form. Guarded, because the table does not
       exist until Database Verification has run. */
    $audienceSelect = FormsService::audiencesAvailable($conn)
        ? ",
                   (SELECT COUNT(*) FROM form_audiences fa WHERE fa.form_id = f.id) AS audience_count"
        : ",
                   0 AS audience_count";

    // Group approval (GH #220): the team, how many must approve / reject, and the
    // named-person "not your own request" switch. Guarded until Database Verification.
    require_once '../../includes/catalogue_approvals.php';
    $teamApproval = catalogueTeamApprovalAvailable($conn);
    $teamSelect = $teamApproval
        ? " f.approval_team_id, apt.name AS approval_team_name, f.approvals_required, f.rejections_required, f.approval_exclude_requester,"
        : " NULL AS approval_team_id, NULL AS approval_team_name, 1 AS approvals_required, 1 AS rejections_required, 0 AS approval_exclude_requester,";
    $teamJoin = $teamApproval ? "
            LEFT JOIN teams apt ON apt.id = f.approval_team_id" : '';

    $collectionJoin = $hasCollections
        ? "
            LEFT JOIN form_collections col ON col.id = f.collection_id"
        : '';

    // The shop card (GH #348), once Database Verification has added it.
    require_once '../../includes/catalogue_card.php';
    $cardSelect = catalogueCardAvailable($conn)
        ? "
                   f.catalogue_summary, f.catalogue_price, f.catalogue_currency, f.catalogue_price_period, f.catalogue_image_path,"
        : '';

    $sql = "SELECT f.id, f.title, f.description, f.is_active, f.is_portal_visible,
                   f.requires_approval, f.approver_id, apr.full_name AS approver_name," . $teamSelect . "
                   f.created_by,  ca.full_name AS created_by_name,
                   DATE_FORMAT(f.created_date,  '%Y-%m-%d %H:%i:%s') AS created_date,
                   f.modified_by, ma.full_name AS modified_by_name,
                   DATE_FORMAT(f.modified_date, '%Y-%m-%d %H:%i:%s') AS modified_date,
                   f.version_number," . $collectionSelect . $cardSelect . "
                   (SELECT COUNT(*) FROM form_fields      WHERE form_id = f.id) AS field_count,
                   (SELECT COUNT(*) FROM form_submissions WHERE form_id = f.id) AS submission_count" . $audienceSelect . "
            FROM forms f
            LEFT JOIN analysts ca  ON f.created_by  = ca.id
            LEFT JOIN analysts ma  ON f.modified_by = ma.id
            LEFT JOIN analysts apr ON f.approver_id = apr.id" . $teamJoin . $collectionJoin . "
            WHERE NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)
            ORDER BY f.modified_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'forms' => $forms]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
