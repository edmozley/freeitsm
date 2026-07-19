<?php
/**
 * API: submit a request-catalogue form from the self-service portal.
 * POST { form_id, data: { field_id: value, ... } }
 *
 * Goes through FormsService::submitForm() so the portal gets the SAME
 * validation the analyst path has — required fields, email/number formats, and
 * (newly) choice values checked against the field's own options. Duplicating
 * that here would be the classic drift: the customer-facing copy is the one
 * that would fall behind.
 *
 * The submitter is passed as $portalUserId, NOT as ActorContext->actorId:
 * `users` and `analysts` are different id spaces, `form_submissions.submitted_by`
 * has no foreign key, and every reader LEFT JOINs it to `analysts` — so a
 * requester's id written there would silently appear as whichever analyst
 * shared the number. The service writes them to different columns.
 *
 * The context therefore carries actorId 0: there is no analyst here, and saying
 * so plainly is better than borrowing someone's id.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/forms.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];
$input  = json_decode(file_get_contents('php://input'), true);
$formId = (int)($input['form_id'] ?? 0);
$data   = isset($input['data']) && is_array($input['data']) ? $input['data'] : [];

if (!$formId) {
    echo json_encode(['success' => false, 'error' => 'Form not found']);
    exit;
}

try {
    $conn = connectToDatabase();

    // No analyst is acting. The service re-checks is_portal_visible itself, so
    // a hidden form is refused here even though this adapter never looked.
    $ctx = new ActorContext(
        actorId:   0,
        source:    'ui',
        locale:    class_exists('I18n') ? I18n::getLocale() : 'en',
        actorName: ''
    );

    $submissionId = FormsService::submitForm($conn, $ctx, $formId, $data, $userId);

    echo json_encode([
        'success'       => true,
        'submission_id' => $submissionId,
    ]);

} catch (ServiceError $e) {
    // Validation messages are written for the person filling the form in, so
    // they are shown as-is; anything else stays generic.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Your request could not be submitted. Please try again.']);
}
