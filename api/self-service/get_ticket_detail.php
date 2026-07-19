<?php
/**
 * API: Get Ticket Detail for Self-Service User
 * GET ?ticket_id=X - Returns ticket info, email thread, and non-internal notes
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/portal_visibility.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];
$ticketId = (int)($_GET['ticket_id'] ?? 0);

if (!$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Fetch ticket - validate ownership
    $ticketStmt = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject,
                ts.name AS status, ts.colour AS status_colour,
                tp.name AS priority,
                t.created_datetime, t.updated_datetime,
                d.name as department_name
         FROM tickets t
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
         LEFT JOIN departments d ON t.department_id = d.id
         WHERE t.id = ? AND t.user_id = ? AND t.deleted_datetime IS NULL"
    );
    $ticketStmt->execute([$ticketId, $userId]);
    $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // The requester's own address — the yardstick for "was this correspondence
    // with them, or about them" (see includes/portal_visibility.php).
    // ⚠️ NULL is passed through DELIBERATELY and means "this person has no
    // mailbox", which portal_visibility.php treats as the opposite of ''
    // ("we don't know their address"). Casting it to '' here would fail the
    // policy open and show them every forward on their ticket.
    $reqStmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $reqStmt->execute([$userId]);
    $reqEmailRaw    = $reqStmt->fetchColumn();
    $requesterEmail = ($reqEmailRaw === false || $reqEmailRaw === null) ? null : (string)$reqEmailRaw;
    $policy = portalThirdPartyPolicy($conn);

    // Fetch email thread
    $threadStmt = $conn->prepare(
        // body_type: chat messages are stored verbatim as 'text' and must be
        // escaped by the renderer, not parsed as markup.
        // channel / from / to / cc drive the third-party visibility rule; they are
        // used for the decision and then stripped, never returned to the browser —
        // the portal has no business listing who else was on an email.
        "SELECT id, from_name, received_datetime, body_content, body_type, direction,
                channel, from_address, to_recipients, cc_recipients
         FROM emails
         WHERE ticket_id = ?
         ORDER BY received_datetime ASC"
    );
    $threadStmt->execute([$ticketId]);
    $thread = $threadStmt->fetchAll(PDO::FETCH_ASSOC);

    // Apply the privacy policy, then drop the routing columns from the payload.
    $visibleThread   = [];
    $noAttachmentIds = [];   // shown, but their files are withheld
    foreach ($thread as $msg) {
        $decision = portalEmailVisibility($msg, $requesterEmail, $policy);
        if (!$decision['visible']) continue;
        if (!$decision['attachments']) $noAttachmentIds[(int)$msg['id']] = true;

        unset($msg['channel'], $msg['from_address'], $msg['to_recipients'], $msg['cc_recipients']);
        $visibleThread[] = $msg;
    }
    $thread = $visibleThread;

    // Attachments for the whole thread in one query, then bucketed onto their
    // message — so a requester can finally retrieve the file an analyst sent them.
    // INLINE ones are excluded: they're the images already embedded in the body
    // (signature logos, pasted screenshots), and listing them again would show a
    // "spacer.gif" download under every message.
    if (!empty($thread)) {
        $emailIds = array_column($thread, 'id');
        $ph = implode(',', array_fill(0, count($emailIds), '?'));
        $attStmt = $conn->prepare(
            "SELECT id, email_id, filename, content_type, file_size
             FROM email_attachments
             WHERE email_id IN ($ph) AND (is_inline = 0 OR is_inline IS NULL)
             ORDER BY id ASC"
        );
        $attStmt->execute($emailIds);

        $byEmail = [];
        foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $att) {
            $byEmail[(int)$att['email_id']][] = [
                'id'           => (int)$att['id'],
                'filename'     => $att['filename'],
                'content_type' => $att['content_type'],
                'file_size'    => (int)$att['file_size'],
            ];
        }
        foreach ($thread as &$msg) {
            // A message kept visible under "show the message, withhold the file"
            // lists no attachments. get_attachment.php enforces the same rule
            // independently — an empty list here is presentation, not security.
            $msg['attachments'] = isset($noAttachmentIds[(int)$msg['id']])
                ? []
                : ($byEmail[(int)$msg['id']] ?? []);
        }
        unset($msg);
    }

    // Fetch non-internal notes only
    $notesStmt = $conn->prepare(
        "SELECT n.note_text, n.created_datetime, a.full_name as analyst_name
         FROM ticket_notes n
         LEFT JOIN analysts a ON n.analyst_id = a.id
         WHERE n.ticket_id = ? AND n.is_internal = 0
         ORDER BY n.created_datetime ASC"
    );
    $notesStmt->execute([$ticketId]);
    $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Screen recordings attached to the ticket
    $recordings = [];
    try {
        // email_id says WHICH message the recording came with; NULL means the
        // opening one. The thread renders each against its own message so a
        // video attached to reply #7 isn't shown as though it arrived with the
        // original report.
        $recStmt = $conn->prepare(
            "SELECT id, email_id, original_filename, content_type, file_size, duration_seconds, has_audio, created_at
             FROM ticket_recordings WHERE ticket_id = ? ORDER BY created_at ASC"
        );
        $recStmt->execute([$ticketId]);
        $recordings = $recStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table may not exist on installs that haven't run db_verify yet
    }

    echo json_encode([
        'success' => true,
        'ticket' => $ticket,
        'thread' => $thread,
        'notes' => $notes,
        'recordings' => $recordings
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
