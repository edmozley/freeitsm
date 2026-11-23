<?php
/**
 * API: the requester's Remind on a waiting request (GH #160 Part C2 - @bigtrodat's "poke").
 * POST { submission_id } -> { success, told }
 *
 * Tells the people the request is waiting on again - the bell, and an email when Forms ->
 * Settings names a mailbox. At most once a day per step, and switched off in Forms ->
 * Settings -> Approvals if an install does not want it. catalogueRemind() is the rule.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/catalogue_approvals.php';
require_once '../../includes/service_context.php';   // ServiceError

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
csrfRequireWriteMethod();

$input = json_decode(file_get_contents('php://input'), true);
$submissionId = (int)($input['submission_id'] ?? 0);

try {
    $conn = connectToDatabase();
    requireModuleOnJson($conn, 'forms');
    if ($submissionId <= 0) throw new ServiceError('not_found', 'not_found', 'That request was not found.');
    $told = catalogueRemind($conn, (int)$_SESSION['ss_user_id'], $submissionId);
    echo json_encode(['success' => true, 'told' => $told]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('remind_request: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The reminder could not be sent. Please try again.']);
}
