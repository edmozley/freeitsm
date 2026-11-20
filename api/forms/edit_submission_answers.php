<?php
/**
 * API: change a waiting catalogue request's answers (discussion #298).
 *
 * POST { submission_id, answers: { <field id>: <value>, ... }, reason? }
 *   -> { success, changes: [ { field_id, label, old, new } ] }
 *
 * Only while the request waits for approval, and only by someone who could decide it
 * (catalogueCanEditAnswers()). Every changed answer is recorded as an 'answer_changed'
 * event - see catalogueEditAnswers() in includes/catalogue_approvals.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/i18n.php';
require_once '../../includes/catalogue_approvals.php';
I18n::initFromSession();
header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
// Changes data: never on a GET.
csrfRequireWriteMethod();
try {
    // The same door as deciding: Forms access, or named as an approver (#180 Part 3).
    // Who may edit THIS request is catalogueEditAnswers()'s check.
    if (!catalogueApproverCanEnter(connectToDatabase(), (int)$_SESSION['analyst_id'])) {
        requireModuleAccessJson('forms');
    }
    $data         = json_decode(file_get_contents('php://input'), true) ?: [];
    $submissionId = (int)($data['submission_id'] ?? 0);
    $answers      = is_array($data['answers'] ?? null) ? $data['answers'] : [];
    if ($submissionId <= 0) throw new Exception('submission_id is required');
    $changes = catalogueEditAnswers(connectToDatabase(), (int)$_SESSION['analyst_id'], $submissionId, $answers, (string)($data['reason'] ?? ''));
    echo json_encode(['success' => true, 'changes' => $changes]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}