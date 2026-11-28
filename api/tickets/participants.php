<?php
/**
 * API: a ticket's participants - end users copied on its emails (GitHub #432).
 *
 *   GET  ?ticket_id=N&preview=1&user_id=|email=        who an Add would put on the ticket (named, new,
 *                                                      another company?) - changes nothing
 *   GET  ?ticket_id=N                                  the participants, whether I may change them,
 *                                                      whether the ticket is confidential (it then has none)
 *   POST {action: 'add',    ticket_id, user_id? | email?}
 *   POST {action: 'remove', ticket_id, user_id}
 *
 * Thin adapter over TicketParticipants (includes/services/ticket_participants.php),
 * which holds the rules. A refusal answers with a code the page turns into words.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/tenancy.php';
require_once __DIR__ . '/../../includes/services/ticket_participants.php';
require_once __DIR__ . '/../../includes/service_context.php';   // ServiceError, thrown here directly

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$conn = connectToDatabase();
$me = (int)$_SESSION['analyst_id'];
$canWrite = analystCanWriteModule($conn, $me, 'tickets');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $ticketId = (int)($_GET['ticket_id'] ?? 0);
        $ctx = ActorContext::fromSession($conn);
        if (!TicketParticipants::ready($conn)) {
            // Before Database Verify: the feature is off - the page shows nothing, rather than "nobody".
            echo json_encode(['success' => true, 'ready' => false, 'participants' => [], 'can_edit' => false, 'confidential' => false]);
            exit;
        }
        if (!empty($_GET['preview'])) {
            $pUser = isset($_GET['user_id']) && (int)$_GET['user_id'] > 0 ? (int)$_GET['user_id'] : null;
            $pMail = isset($_GET['email']) ? (string)$_GET['email'] : null;
            echo json_encode(['success' => true] + TicketParticipants::preview($conn, $ctx, $ticketId, $pUser, $pMail));
            exit;
        }
        $list = TicketParticipants::list($conn, $ctx, $ticketId);   // 404 for a ticket I cannot see
        echo json_encode([
            'success'      => true,
            'ready'        => true,
            'participants' => $list,
            'can_edit'     => $canWrite,
            'confidential' => ticketIsConfidential($conn, $ticketId),
            // Every address of the requester, so Reply knows they are in To under any of them.
            'requester_addresses' => TicketParticipants::requesterAddresses($conn, $ticketId),
        ]);
        exit;
    }

    csrfRequireWriteMethod();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $ctx = ActorContext::fromSession($conn);
    $action = (string)($in['action'] ?? '');
    $ticketId = (int)($in['ticket_id'] ?? 0);
    $userId = isset($in['user_id']) && (int)$in['user_id'] > 0 ? (int)$in['user_id'] : null;

    if ($action === 'add') {
        $email = isset($in['email']) ? (string)$in['email'] : null;
        $r = TicketParticipants::add($conn, $ctx, $ticketId, $userId, $email, $canWrite);
    } elseif ($action === 'remove') {
        if ($userId === null) throw new ServiceError('validation', 'invalid_field', "Give 'user_id'.");
        $r = TicketParticipants::remove($conn, $ctx, $ticketId, $userId, $canWrite);
    } else {
        throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
    }
    echo json_encode(['success' => true] + $r);
} catch (ServiceError $e) {
    http_response_code($e->kind === 'not_found' ? 404 : ($e->kind === 'forbidden' ? 403 : 400));
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('api/tickets/participants.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not update the participants.']);
}
