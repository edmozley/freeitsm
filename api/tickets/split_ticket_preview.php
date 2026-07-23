<?php
/**
 * API Endpoint: what a split WOULD move, or the full list to pick from.
 *
 * GET ?ticket_id=&list_all=1
 *   -> { messages: [...], total_on_ticket }   // every movable message, to tick from
 * GET ?ticket_id=&from_email_id=&include_newer=0|1
 *   -> { messages: [...], total_on_ticket, would_empty: bool }   // the anchor path
 *
 * The dialog lets the analyst tick individual messages, so it needs the whole movable
 * set (list_all) — markers excluded, because you cannot split a marker off on its own.
 * The anchor path stays for the "select newer" helper's count. Either way the messages
 * are read from the server, not counted in JavaScript: a dialog that tallied them
 * itself would eventually disagree with the server, and an analyst would confirm a move
 * they had not been shown.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_split.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

try {
    $ticketId     = (int)($_GET['ticket_id'] ?? 0);
    $listAll      = !empty($_GET['list_all']) && $_GET['list_all'] !== '0';
    $fromEmailId  = (int)($_GET['from_email_id'] ?? 0);
    $includeNewer = !empty($_GET['include_newer']) && $_GET['include_newer'] !== '0';

    if ($ticketId <= 0) throw new Exception('ticket_id is required');
    if (!$listAll && $fromEmailId <= 0) throw new Exception('from_email_id is required');

    $conn = connectToDatabase();
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // The dialog's checklist: every movable message on the ticket, oldest-first, with
    // split markers left out — they are placeholders, not content to move.
    if ($listAll) {
        $markers   = splitMarkerEmailIds($conn, $ticketId);
        $markerNot = $markers ? ' AND id NOT IN (' . implode(',', $markers) . ')' : '';
        $rows = $conn->query(
            "SELECT id, subject, from_name, from_address, received_datetime, direction
               FROM emails WHERE ticket_id = " . $ticketId . $markerNot . "
           ORDER BY received_datetime, id"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'messages' => $rows, 'total_on_ticket' => count($rows)]);
        exit;
    }

    $messages = splitMessagesFrom($conn, $ticketId, $fromEmailId, $includeNewer);
    $total    = splitMovableCount($conn, $ticketId);

    echo json_encode([
        'success'         => true,
        'messages'        => $messages,
        'total_on_ticket' => $total,
        // The dialog disables Split rather than letting the analyst discover this
        // as an error after committing.
        'would_empty'     => count($messages) >= $total,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
