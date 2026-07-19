<?php
/**
 * API: one catalogue form and its fields, for the portal.
 * GET ?id=
 *
 * A deliberately NARROWER shape than the analyst's api/forms/get_form.php,
 * which also returns created_by_name, modified_by_name, version_number and
 * is_leaf. None of that is a customer's business: who wrote the form and how
 * many times it has been revised is internal detail.
 *
 * The visibility gate is in the QUERY, so a form that isn't in the catalogue is
 * indistinguishable from one that doesn't exist — knowing its id gets you
 * nothing.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$formId = (int)($_GET['id'] ?? 0);
if (!$formId) {
    echo json_encode(['success' => false, 'error' => 'Form not found']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT f.id, f.title, f.description
         FROM forms f
         WHERE f.id = ?
           AND f.is_portal_visible = 1
           AND f.is_active = 1
           AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)"
    );
    $stmt->execute([$formId]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        echo json_encode(['success' => false, 'error' => 'Form not found']);
        exit;
    }

    $fStmt = $conn->prepare(
        "SELECT id, field_type, label, options, is_required, sort_order
         FROM form_fields WHERE form_id = ? ORDER BY sort_order, id"
    );
    $fStmt->execute([$formId]);
    $form['fields'] = $fStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'form' => $form]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
