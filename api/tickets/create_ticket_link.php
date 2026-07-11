<?php
/**
 * API Endpoint: Link one ticket to another (issue #38).
 * POST { source_ticket_id, target_ticket_id, relation } where relation is one of
 * related | duplicate_of | parent_of | child_of, from the source ticket's view.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_links.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sourceId = (int)($input['source_ticket_id'] ?? 0);
$targetId = (int)($input['target_ticket_id'] ?? 0);
$relation = (string)($input['relation'] ?? '');

try {
    $conn = connectToDatabase();
    echo json_encode(ticketLinkCreate($conn, (int)$_SESSION['analyst_id'], $sourceId, $targetId, $relation));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
