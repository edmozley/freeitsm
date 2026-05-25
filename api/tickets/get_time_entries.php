<?php
/**
 * API Endpoint: List time entries for a ticket
 *
 * Returns only active (is_active = 1) entries, newest entry_datetime first.
 * Each row carries the logging analyst's name for inline display.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$ticket_id = $_GET['ticket_id'] ?? null;

if (!$ticket_id) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT
                te.id,
                te.ticket_id,
                te.analyst_id,
                te.notes,
                te.time_spent_minutes,
                te.entry_datetime,
                te.created_datetime,
                te.updated_datetime,
                a.full_name AS analyst_name
            FROM ticket_time_entries te
            JOIN analysts a ON te.analyst_id = a.id
            WHERE te.ticket_id = ? AND te.is_active = 1
            ORDER BY te.entry_datetime DESC, te.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticket_id]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalMinutes = 0;
    foreach ($entries as &$entry) {
        $entry['time_spent_minutes'] = (int)$entry['time_spent_minutes'];
        $totalMinutes += $entry['time_spent_minutes'];
        foreach (['entry_datetime', 'created_datetime', 'updated_datetime'] as $col) {
            if (!empty($entry[$col])) {
                $entry[$col] = date('Y-m-d\TH:i:s', strtotime($entry[$col]));
            }
        }
    }

    echo json_encode([
        'success' => true,
        'entries' => $entries,
        'total_minutes' => $totalMinutes
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
