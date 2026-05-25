<?php
/**
 * API: Fork the current form into a new version (#442).
 *
 * Clones the form's title + description + fields into a new `forms` row
 * whose parent_form_id points back at the source. The new row becomes
 * the leaf (current editable version); the source freezes as a
 * historical snapshot. version_number is set to source.version_number
 * + 1 so the chain reads naturally as v1 → v2 → v3.
 *
 * POST { parent_form_id }
 * Returns { id, version_number }.
 *
 * Refuses if the supplied parent already has children — we only fork
 * forward from the leaf, no branching in v1 (mirrors network-mapper's
 * chain-only model).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $parentId = isset($data['parent_form_id']) ? (int)$data['parent_form_id'] : 0;
    if ($parentId <= 0) throw new Exception('parent_form_id is required');

    $conn = connectToDatabase();

    // Load source form + verify it's a leaf
    $srcStmt = $conn->prepare(
        "SELECT id, title, description, is_active, version_number,
                (SELECT COUNT(*) FROM forms WHERE parent_form_id = forms.id) AS child_count
           FROM forms WHERE id = ?"
    );
    $srcStmt->execute([$parentId]);
    $src = $srcStmt->fetch(PDO::FETCH_ASSOC);
    if (!$src) throw new Exception('Source form not found');
    if ((int)$src['child_count'] > 0) {
        throw new Exception('Can only create a new version from the current (leaf) version of the chain');
    }

    $conn->beginTransaction();

    // Insert the new version row
    $ins = $conn->prepare(
        "INSERT INTO forms
            (title, description, is_active, created_by, modified_by,
             parent_form_id, version_number)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $ins->execute([
        $src['title'],
        $src['description'],
        (int)$src['is_active'],
        $_SESSION['analyst_id'],
        $_SESSION['analyst_id'],
        $parentId,
        (int)$src['version_number'] + 1,
    ]);
    $newId = (int)$conn->lastInsertId();

    // Clone every field. The field rows themselves are independent of
    // submissions (which key off the source form's field ids) so this
    // is a straight copy — no id-map gymnastics needed.
    $copyFields = $conn->prepare(
        "INSERT INTO form_fields (form_id, field_type, label, options, is_required, sort_order)
         SELECT ?, field_type, label, options, is_required, sort_order
           FROM form_fields WHERE form_id = ?
       ORDER BY sort_order, id"
    );
    $copyFields->execute([$newId, $parentId]);

    $conn->commit();
    echo json_encode([
        'success'        => true,
        'id'             => $newId,
        'version_number' => (int)$src['version_number'] + 1,
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
