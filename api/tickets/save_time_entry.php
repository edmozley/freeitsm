<?php
/**
 * API Endpoint: Create or update a ticket time entry
 *
 * POST JSON body:
 *   ticket_id           (required)
 *   id                  (omitted on create, present on update)
 *   notes               (optional)
 *   time_spent_minutes  (required, positive int)
 *   entry_datetime      (optional — defaults to now if omitted)
 *
 * On update, only the entry's own analyst can edit it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $entry_id  = isset($data['id']) ? (int)$data['id'] : null;
    $ticket_id = $data['ticket_id'] ?? null;
    $notes     = isset($data['notes']) ? trim((string)$data['notes']) : '';
    $minutes   = isset($data['time_spent_minutes']) ? (int)$data['time_spent_minutes'] : 0;
    $entryDt   = !empty($data['entry_datetime']) ? trim($data['entry_datetime']) : null;

    if (!$ticket_id) {
        throw new Exception('Ticket ID is required');
    }
    if ($minutes <= 0) {
        throw new Exception('Time spent must be greater than zero minutes');
    }

    // Normalise the entry datetime — accept anything strtotime can parse, store
    // in MySQL-friendly format. Default to now if not supplied.
    if ($entryDt) {
        $ts = strtotime($entryDt);
        if ($ts === false) {
            throw new Exception('Invalid entry datetime');
        }
        $entryDt = date('Y-m-d H:i:s', $ts);
    } else {
        $entryDt = date('Y-m-d H:i:s');
    }

    $conn = connectToDatabase();

    if ($entry_id) {
        // Update — gate on analyst owning the entry so people don't edit each other's
        $existing = $conn->prepare("SELECT analyst_id FROM ticket_time_entries WHERE id = ? AND is_active = 1");
        $existing->execute([$entry_id]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Time entry not found');
        }
        if ((int)$row['analyst_id'] !== (int)$_SESSION['analyst_id']) {
            throw new Exception('You can only edit your own time entries');
        }

        $stmt = $conn->prepare(
            "UPDATE ticket_time_entries
             SET notes = ?, time_spent_minutes = ?, entry_datetime = ?
             WHERE id = ?"
        );
        $stmt->execute([$notes, $minutes, $entryDt, $entry_id]);

        echo json_encode(['success' => true, 'id' => $entry_id]);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO ticket_time_entries
                (ticket_id, analyst_id, notes, time_spent_minutes, entry_datetime)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $ticket_id,
            $_SESSION['analyst_id'],
            $notes,
            $minutes,
            $entryDt
        ]);

        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
