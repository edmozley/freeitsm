<?php
/**
 * API Endpoint: suggestions for the To and Cc boxes of a ticket's email (GitHub #307).
 * GET ?ticket_id=&q=  ->  {success, suggestions: [{email, name, source, detail}]}
 *
 * The analyst must be able to open the ticket; what is suggested is scoped to its
 * company - see includes/services/address_suggest.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_department_scope.php';
require_once '../../includes/services/address_suggest.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$analystId = (int)$_SESSION['analyst_id'];
$ticketId = (int)($_GET['ticket_id'] ?? 0);
$q = (string)($_GET['q'] ?? '');

try {
    $conn = connectToDatabase();
    if ($ticketId <= 0 || !analystCanAccessTicket($conn, $analystId, $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }
    echo json_encode(['success' => true, 'suggestions' => AddressSuggest::search($conn, $analystId, $ticketId, $q)]);
} catch (Exception $e) {
    error_log('address_suggest: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load suggestions']);
}
