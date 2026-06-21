<?php
/**
 * API Endpoint: Soft-delete a ticket time entry (sets is_active = 0)
 *
 * Only the entry's own analyst can delete it. Soft-delete keeps the row for
 * audit / restore — get_time_entries.php filters on is_active = 1 so deleted
 * rows just disappear from the UI.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $entry_id = isset($data['id']) ? (int)$data['id'] : null;

    if (!$entry_id) {
        throw new Exception('Entry ID is required');
    }

    $conn = connectToDatabase();

    $existing = $conn->prepare("SELECT analyst_id, ticket_id FROM ticket_time_entries WHERE id = ? AND is_active = 1");
    $existing->execute([$entry_id]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Time entry not found');
    }
    // Multi-tenancy: the entry's ticket must be one this analyst can access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], (int)$row['ticket_id'])) {
        throw new Exception('Time entry not found');
    }
    if ((int)$row['analyst_id'] !== (int)$_SESSION['analyst_id']) {
        throw new Exception('You can only delete your own time entries');
    }

    $stmt = $conn->prepare("UPDATE ticket_time_entries SET is_active = 0 WHERE id = ?");
    $stmt->execute([$entry_id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
