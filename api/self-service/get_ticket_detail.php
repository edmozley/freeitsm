<?php
/**
 * API: Get Ticket Detail for Self-Service User
 * GET ?ticket_id=X - Returns ticket info, email thread, and non-internal notes
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/portal_visibility.php';
require_once '../../includes/managers.php';   // portalTicketAccess() - who may see this ticket (#62)
require_once '../../includes/services/ticket_fields.php';   // the requester's answers (#193)

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

    // Discussion #62 - shown to the requester, and whether to offer "Mark confidential".
    require_once '../../includes/ticket_sensitivity.php';
    $sensCol = ticketSensitivityReady($conn) ? 't.sensitivity' : "'normal' AS sensitivity";

    // May this portal user see this ticket at all, and as whom? The ONE rule every
    // portal endpoint asks (includes/managers.php): the person who raised it, or -
    // since discussion #62 - one of their managers. A miss reads exactly like a
    // ticket that does not exist.
    $access = portalTicketAccess($conn, $userId, $ticketId);
    if (!$access) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }
    $isRequester = $access['role'] === 'requester';
    $requesterId = (int)$access['ticket']['user_id'];

    // Fetch the ticket (access is settled above)
    $ticketStmt = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject,
                ts.name AS status, ts.colour AS status_colour,
                -- Whether the ticket is already finished. The portal needs this
                -- to decide whether to offer the self-close button; the status
                -- NAME cannot answer it, because installs rename statuses and
                -- add their own, and more than one can be a closed one.
                ts.is_closed,
                tp.name AS priority,
                t.created_datetime, t.updated_datetime, $sensCol,
                d.name as department_name
         FROM tickets t
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
         LEFT JOIN departments d ON t.department_id = d.id
         WHERE t.id = ? AND t.deleted_datetime IS NULL"
    );
    $ticketStmt->execute([$ticketId]);
    $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // What this viewer may do, said by the server so the page never guesses.
    $viewer = [
        'role'      => $access['role'],
        'can_reply' => $access['can_reply'],
        'can_close' => $access['can_close'],
        'stub'      => $access['stub'],
    ];
    if (!$isRequester) {
        // A manager is told whose ticket this is - the page says so up front.
        $rn = $conn->prepare("SELECT COALESCE(NULLIF(display_name, ''), email) FROM users WHERE id = ?");
        $rn->execute([$requesterId]);
        $viewer['requester_name'] = (string)$rn->fetchColumn();

        // Recorded AFTER every check above, so a refusal never counts as a look,
        // and shown to the requester under "Who has seen this ticket" (step 2).
        // NOT for a confidential stub: the manager saw that a ticket exists and
        // nothing it says, and "your manager has seen this ticket" on a grievance
        // about that manager would alarm the person it exists to protect (Ed:
        // "if the manager has not been able to view the ticket contents then
        // don't record anything").
        if (!$access['stub']) {
            require_once '../../includes/ticket_views.php';
            ticketViewRecord($conn, $ticketId, 'user', $userId);
        }
    }

    // A confidential ticket shown to a manager as a STUB: that it exists, and
    // nothing of what it says - not the subject, not a word of the thread.
    if ($access['stub']) {
        echo json_encode([
            'success' => true,
            'ticket'  => [
                'id'               => (int)$ticket['id'],
                'ticket_number'    => $ticket['ticket_number'],
                'subject'          => '',
                'status'           => $ticket['status'],
                'status_colour'    => $ticket['status_colour'],
                'is_closed'        => $ticket['is_closed'],
                'created_datetime' => $ticket['created_datetime'],
                'sensitivity'      => 'confidential',
            ],
            'thread' => [], 'notes' => [], 'recordings' => [],
            'viewer' => $viewer,
            'seen_by' => null,
        ]);
        exit;
    }

    // The requester's own address — the yardstick for "was this correspondence
    // with them, or about them" (see includes/portal_visibility.php).
    // ⚠️ NULL is passed through DELIBERATELY and means "this person has no
    // mailbox", which portal_visibility.php treats as the opposite of ''
    // ("we don't know their address"). Casting it to '' here would fail the
    // policy open and show them every forward on their ticket.
    // ⚠️ The TICKET'S requester, not the viewer: a manager sees exactly what the
    // requester would see of their own ticket, and never more (#62).
    $reqStmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $reqStmt->execute([$requesterId]);
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
        "SELECT n.id, n.note_text, n.created_datetime, a.full_name as analyst_name
         FROM ticket_notes n
         LEFT JOIN analysts a ON n.analyst_id = a.id
         WHERE n.ticket_id = ? AND n.is_internal = 0
         ORDER BY n.created_datetime ASC"
    );
    $notesStmt->execute([$ticketId]);
    $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Files attached to those shared notes (discussion #103). Until now a shared
    // note could not carry one at all, because the portal had no way to hand a
    // document back — api/self-service/get_document.php is that way.
    //
    // Listed only for the notes ALREADY selected above, so a note that failed the
    // is_internal test cannot contribute a file here. The download endpoint
    // re-checks the same rule anyway: hiding a link while the URL still works is
    // decoration, and the file is the sensitive part.
    if ($notes) {
        $noteIds = array_column($notes, 'id');
        $in      = implode(',', array_fill(0, count($noteIds), '?'));
        $docStmt = $conn->prepare(
            "SELECT dl.parent_id AS note_id, d.id, d.kind, d.title,
                    d.original_name, d.size_bytes, d.external_url
               FROM document_links dl
               JOIN documents d ON d.id = dl.document_id AND d.deleted_datetime IS NULL
                                AND d.kind <> 'files'   -- GH #303: a Files link is for analysts only
              WHERE dl.parent_type = 'ticket_note' AND dl.parent_id IN ($in)
              ORDER BY d.id ASC"
        );
        $docStmt->execute($noteIds);
        $byNote = [];
        foreach ($docStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $byNote[(int) $d['note_id']][] = [
                'id'            => (int) $d['id'],
                'kind'          => $d['kind'],
                'title'         => $d['title'],
                'original_name' => $d['original_name'],
                'size_bytes'    => $d['size_bytes'] !== null ? (int) $d['size_bytes'] : null,
                'external_url'  => $d['external_url'],
            ];
        }
        foreach ($notes as &$n) {
            $n['documents'] = $byNote[(int) $n['id']] ?? [];
        }
        unset($n);
    }

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

    // Who else has looked at this ticket in the portal - its requester's managers,
    // once managers exist (discussion #62). Shown to the requester by default.
    // Service-desk views are deliberately NOT included; see includes/ticket_views.php.
    // null (not []) before Database Verification, so the page shows no panel
    // rather than a reassurance it cannot yet back up.
    require_once '../../includes/ticket_views.php';
    // 🔴 To the REQUESTER only. A manager must not see who else has looked,
    // nor find their own name listed as if they were the requester (#62).
    $seenBy = ($isRequester && ticketViewsReady($conn)) ? ticketViewsForRequester($conn, (int)$ticketId, $requesterId) : null;

    // The answers the requester gave (discussion #193). Only for a ticket the
    // caller sees in full - the confidential stub above returned long before this.
    $answersPlace = TicketFieldsService::portalAnswersPlace($conn);
    $fieldAnswers = ['place' => $answersPlace, 'items' => $answersPlace === 'off' ? [] : TicketFieldsService::portalAnswers($conn, $ticketId)];

    echo json_encode([
        'success' => true,
        'ticket' => $ticket,
        'thread' => $thread,
        'notes' => $notes,
        'recordings' => $recordings,
        'seen_by' => $seenBy,
        'viewer' => $viewer,
        'field_answers' => $fieldAnswers
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
