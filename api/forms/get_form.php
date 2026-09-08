<?php
/**
 * API: Get a single form with its fields
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$formId = (int)($_GET['id'] ?? 0);
if ($formId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing form ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT f.id, f.title, f.description, f.is_active,
                f.created_by,  ca.full_name AS created_by_name,
                DATE_FORMAT(f.created_date,  '%Y-%m-%d %H:%i:%s') AS created_date,
                f.modified_by, ma.full_name AS modified_by_name,
                DATE_FORMAT(f.modified_date, '%Y-%m-%d %H:%i:%s') AS modified_date,
                f.parent_form_id, f.version_number,
                f.requires_approval, f.approver_id, f.submission_actions,
                (SELECT COUNT(*) FROM forms ch WHERE ch.parent_form_id = f.id) AS child_count
         FROM forms f
         LEFT JOIN analysts ca ON f.created_by  = ca.id
         LEFT JOIN analysts ma ON f.modified_by = ma.id
         WHERE f.id = ?"
    );
    $stmt->execute([$formId]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($form) {
        // is_leaf = true → editable (current version). Older snapshots
        // get is_leaf=false and the editor renders them read-only.
        $form['parent_form_id'] = $form['parent_form_id'] !== null ? (int)$form['parent_form_id'] : null;
        $form['version_number'] = (int)$form['version_number'];
        $form['is_leaf'] = ((int)$form['child_count']) === 0;
        unset($form['child_count']);

        // The approval gate, so the "what happens next" tab can say which of its
        // sections can actually fire on this form.
        $form['requires_approval'] = (int)($form['requires_approval'] ?? 0);
        $form['approver_id'] = $form['approver_id'] !== null ? (int)$form['approver_id'] : null;

        // #95 action lists. Decoded here so the editor never has to parse JSON
        // out of a string field. NULL stays null — the editor distinguishes
        // "never configured" from "deliberately empty" exactly as the service
        // does, and rendering an unconfigured section as an explicit empty list
        // would silently convert one into the other on the next save.
        $raw = $form['submission_actions'] ?? null;
        $decoded = ($raw === null || trim((string)$raw) === '') ? null : json_decode((string)$raw, true);
        $form['submission_actions'] = is_array($decoded) ? $decoded : null;
    }

    if (!$form) {
        echo json_encode(['success' => false, 'error' => 'Form not found']);
        exit;
    }

    // is_deleted = 0: a retired field is kept only so old submissions stay readable
    // (see forms/submissions.php). It must not come back into the builder or the
    // fill page, or removing a question would look like it hadn't worked.
    $stmt = $conn->prepare("SELECT id, field_type, label, options, is_required, sort_order, config
                            FROM form_fields WHERE form_id = ? AND is_deleted = 0 ORDER BY sort_order, id");
    $stmt->execute([$formId]);
    $form['fields'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'form' => $form]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
