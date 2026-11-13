<?php
/**
 * API Endpoint: a person's email addresses and history (GitHub #308).
 *
 *   GET  ?user_id=      main address, linked addresses, history, and whether you may change them
 *   POST {action: 'link',   user_id, email}            link an address (you vouch for it - no confirmation)
 *        {action: 'unlink', user_id, email}            remove a linked address
 *        {action: 'merge',  user_id, from_user_id}     merge a duplicate person into this one
 *
 * Seeing needs the person (analystCanAccessUser); changing needs Tickets as well, as editing a
 * person always has (Tickets -> Users). Linking an address that is another person's MAIN address
 * answers needs_merge with what a merge would do - the analyst then confirms it.
 *
 * @wiki Linked-Email-Addresses-Developer-Guide
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/user_emails.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireAnyModuleAccessJson(['people', 'tickets']);
$analystId = (int)$_SESSION['analyst_id'];

/** The same answer for "not there" and "not yours". */
function peFail(string $msg = 'Person not found'): void
{
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    $conn = connectToDatabase();
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($isPost) csrfRequireWriteMethod();
    $in = $isPost ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $userId = (int)($in['user_id'] ?? 0);
    if ($userId <= 0 || !analystCanAccessUser($conn, $analystId, $userId)) peFail();
    $canEdit = analystCanAccessModule($conn, $analystId, 'tickets') && userEmailsReady($conn);

    if (!$isPost) {
        $st = $conn->prepare("SELECT email FROM users WHERE id = ?");
        $st->execute([$userId]);
        echo json_encode(['success' => true, 'ready' => userEmailsReady($conn), 'can_edit' => $canEdit,
            'main' => $st->fetchColumn() ?: null, 'linked' => userLinkedEmails($conn, $userId), 'history' => userHistory($conn, $userId, 50)]);
        exit;
    }
    if (!$canEdit) peFail('You cannot change this person\'s addresses.');

    $action = (string)($in['action'] ?? '');
    if ($action === 'link') {
        $r = userLinkEmail($conn, $analystId, $userId, (string)($in['email'] ?? ''), 'analyst');
        if ($r['ok']) { echo json_encode(['success' => true]); exit; }
        if ($r['reason'] === 'main_elsewhere') {
            // Another person's MAIN address: a duplicate, usually made when they first wrote from it.
            $p = userMergePreview($conn, $analystId, (int)$r['other_user_id'], $userId);
            if ($p['reason'] === 'not_found') peFail('This address belongs to someone else.');
            echo json_encode(['success' => false, 'needs_merge' => $p]);
            exit;
        }
        $msg = [
            'invalid'          => 'That is not an email address.',
            'own_main'         => 'That is already this person\'s main address.',
            'already_yours'    => 'That address is already linked to this person.',
            'linked_elsewhere' => 'That address is linked to someone else. Remove it there first.',
            'not_ready'        => 'Run Database Verification first.',
        ][$r['reason']] ?? 'The address could not be linked.';
        peFail($msg);
    }
    if ($action === 'unlink') {
        if (!userUnlinkEmail($conn, $analystId, $userId, (string)($in['email'] ?? ''))) peFail('That address is not linked to this person.');
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'merge') {
        $fromId = (int)($in['from_user_id'] ?? 0);
        try {
            $m = userMergeInto($conn, $analystId, $fromId, $userId);
        } catch (RuntimeException $e) {
            peFail(['not_found' => 'Person not found', 'same' => 'That is the same person.', 'other_company' => 'They belong to different companies.',
                    'has_login' => 'They sign in to the portal with that address, so they are a real account - merge them by hand.',
                    'directory' => 'A directory keeps them up to date, so removing them would only bring them back.'][$e->getMessage()] ?? 'They could not be merged.');
        }
        echo json_encode(['success' => true, 'moved' => $m['moved'], 'outcome' => $m['outcome']]);
        exit;
    }
    peFail('Unknown action.');
} catch (Throwable $e) {
    error_log('people/emails: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
}
