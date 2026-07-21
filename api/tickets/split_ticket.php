<?php
/**
 * API Endpoint: split messages off a ticket into a new one.
 *
 * POST { ticket_id, from_email_id, include_newer: bool, subject? }
 *   -> { success, new_ticket_id, new_ticket_number, moved }
 *
 * Module access only, like merging: splitting is everyday service-desk work, not
 * administration. There is no settings tab for it either — unlike a merge, a split
 * cannot orphan a reference the customer already holds, so there is no install-wide
 * policy to decide. Both tickets come out live and independent.
 *
 * The engine is includes/ticket_split.php, where the reasoning lives.
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
    $data         = json_decode(file_get_contents('php://input'), true) ?: [];
    $ticketId     = (int)($data['ticket_id'] ?? 0);
    $fromEmailId  = (int)($data['from_email_id'] ?? 0);
    $includeNewer = !empty($data['include_newer']);
    $subject      = isset($data['subject']) ? (string)$data['subject'] : null;

    if ($ticketId <= 0 || $fromEmailId <= 0) throw new Exception('ticket_id and from_email_id are required');

    $conn   = connectToDatabase();
    $result = splitTicket($conn, (int)$_SESSION['analyst_id'], $ticketId, $fromEmailId, $includeNewer, $subject);

    echo json_encode(array_merge(['success' => true], $result));

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
