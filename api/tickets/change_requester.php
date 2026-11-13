<?php
/**
 * API Endpoint: change a ticket's requester (GitHub #308, part 2).
 *
 *   GET  ?ticket_id=&user_id=   what the change would offer: the current requester, and whether the
 *                               old person can be merged into the new one (userMergePreview()).
 *   POST {ticket_id, user_id, merge_old?}
 *        - the ticket's requester becomes user_id (TicketsService::updateTicket(), in its history);
 *        - merge_old: the old requester is merged into the new one - their other tickets move, their
 *          address becomes one of the new person's (so their next email lands there too), and they
 *          are removed, or switched off when something else still points at them.
 *
 * There is no "link the old address" without merging: it is the old person's MAIN address, and an
 * address has one owner. When a merge is refused (they sign in, a directory keeps them, another
 * company) the dialog says why.
 *
 * @wiki Linked-Email-Addresses-Developer-Guide
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/service_context.php';
require_once '../../includes/services/tickets.php';
require_once '../../includes/user_emails.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($isPost) csrfRequireWriteMethod();
    $in = $isPost ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $ticketId = (int)($in['ticket_id'] ?? 0);
    $newId = (int)($in['user_id'] ?? 0);
    if ($ticketId <= 0 || !analystCanAccessTicket($conn, $analystId, $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }
    $st = $conn->prepare("SELECT t.user_id, u.email, u.display_name FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $st->execute([$ticketId]);
    $cur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $oldId = (int)($cur['user_id'] ?? 0);
    $oldEmail = userEmailNorm($cur['email'] ?? '');

    // What linking / merging the old requester would do - for the dialog, and checked again on POST.
    $offer = function () use ($conn, $analystId, $oldId, $newId, $oldEmail): array {
        if (!$oldId || !$newId || $oldId === $newId || !userEmailsReady($conn)) return ['merge' => null];
        return ['merge' => userMergePreview($conn, $analystId, $oldId, $newId)];
    };

    if (!$isPost) {
        echo json_encode(['success' => true, 'current' => ['id' => $oldId ?: null, 'name' => $cur['display_name'] ?? null, 'email' => $oldEmail ?: null]] + $offer());
        exit;
    }

    $ctx = ActorContext::fromSession($conn);
    TicketsService::updateTicket($conn, $ctx, $ticketId, ['user_id' => $newId], true);

    $result = ['success' => true, 'merged' => null];
    $o = $offer();
    if (!empty($in['merge_old']) && $o['merge'] && $o['merge']['can']) {
        // The old person's other tickets move, and their address becomes the new person's.
        $m = userMergeInto($conn, $analystId, $oldId, $newId);
        $result['merged'] = ['moved' => $m['moved'], 'outcome' => $m['outcome']];
    }
    echo json_encode($result);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('change_requester: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The requester could not be changed.']);
}
