<?php
/**
 * API Endpoint: has anything new arrived? - one number, for the inbox to poll.
 *   GET → { success, marker }
 *
 * `marker` is the id of the newest message (email, chat message, reply or note
 * row in `emails`) on any ticket this analyst can see. The inbox polls it every
 * minute alongside its own mailbox check, and when it goes up runs the same
 * refresh as the ↻ button.
 *
 * Why it exists: the inbox only refreshed when the mailbox check IT ran found
 * mail. A WhatsApp, Telegram, Slack, Teams or Mattermost message arrives at the
 * server through a webhook, and mail collected by another analyst's open inbox
 * is not reported to this one - neither ever told an open inbox, so a new chat ticket
 * sat unseen until somebody pressed refresh.
 *
 * Deliberately says nothing but a number: no subject, no sender, no count, so it
 * reveals nothing a refresh would not show anyway. Scoped to the analyst's
 * companies, so another company's traffic never makes this inbox refresh.
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
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    [$ttSql, $ttParams] = ticketScopeFilter($conn, (int)$_SESSION['analyst_id'], 't');
    // ORDER BY e.id DESC LIMIT 1 rather than MAX(): it walks the primary key from
    // the newest row and stops at the first visible one, which on a large
    // install is the first row it looks at.
    $stmt = $conn->prepare(
        "SELECT e.id FROM emails e
         JOIN tickets t ON t.id = e.ticket_id
         WHERE t.deleted_datetime IS NULL" . $ttSql . "
         ORDER BY e.id DESC LIMIT 1"
    );
    $stmt->execute($ttParams);
    echo json_encode(['success' => true, 'marker' => (int)$stmt->fetchColumn()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
