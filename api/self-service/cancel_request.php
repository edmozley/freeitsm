<?php
/**
 * API: the requester cancels their own catalogue request (GH #348).
 * POST { submission_id, reason }
 *
 * While it waits for an approval, or was sent with no approval and has no ticket yet -
 * catalogueCancelRequest() is the rule. The reason is required; the approver sees it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/catalogue_approvals.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
csrfRequireWriteMethod();

$input = json_decode(file_get_contents('php://input'), true);
$submissionId = (int)($input['submission_id'] ?? 0);
$reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';

try {
    $conn = connectToDatabase();
    requireModuleOnJson($conn, 'forms');   // GH #217
    if ($submissionId <= 0) throw new ServiceError('not_found', 'not_found', 'That request was not found.');
    catalogueCancelRequest($conn, (int)$_SESSION['ss_user_id'], $submissionId, $reason);
    echo json_encode(['success' => true]);
} catch (ServiceError $e) {
    // Written for the person cancelling, so shown as they are.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    // Never the exception's own text: it can carry SQL and column names.
    error_log('cancel_request: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The request could not be cancelled. Please try again.']);
}
