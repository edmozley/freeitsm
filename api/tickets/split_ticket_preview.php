<?php
/**
 * API Endpoint: what a split WOULD move.
 *
 * GET ?ticket_id=&from_email_id=&include_newer=0|1
 *   -> { messages: [...], total_on_ticket, would_empty: bool }
 *
 * Exists so the dialog lists the exact messages the split will take, computed by the
 * same function the split itself uses (splitMessagesFrom). A dialog that counted them
 * separately in JavaScript would eventually disagree with the server — and the moment
 * it did, an analyst would confirm a move they hadn't been shown.
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
    $fromEmailId  = (int)($_GET['from_email_id'] ?? 0);
    $includeNewer = !empty($_GET['include_newer']) && $_GET['include_newer'] !== '0';

    if ($ticketId <= 0 || $fromEmailId <= 0) throw new Exception('ticket_id and from_email_id are required');

    $conn = connectToDatabase();
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $messages = splitMessagesFrom($conn, $ticketId, $fromEmailId, $includeNewer);
    $total    = (int)$conn->query("SELECT COUNT(*) FROM emails WHERE ticket_id = " . $ticketId)->fetchColumn();

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
