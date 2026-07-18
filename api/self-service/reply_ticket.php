<?php
/**
 * API: Reply to your own ticket from the Self-Service Portal.
 * POST { ticket_id, body, attachments[] }
 *
 * Until this existed the portal was entirely READ-ONLY: a requester could watch
 * their ticket but not answer it, so "can you send us a screenshot?" forced them
 * back to email — the exact round trip the portal is meant to remove.
 *
 * The reply is recorded as an `emails` row with direction 'Portal', the same
 * shape create_ticket.php already writes for the opening message. Nothing is
 * SENT: the requester is already here and the analyst sees it in the inbox, so
 * emailing them their own words back would be noise. (That also keeps the portal
 * working on an install with no mailbox configured at all.) Analysts' outbound
 * replies quote the whole thread, so a portal reply is carried into their next
 * email automatically.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_reply.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId   = (int)$_SESSION['ss_user_id'];
$input    = json_decode(file_get_contents('php://input'), true);
$ticketId = (int)($input['ticket_id'] ?? 0);
$body     = trim($input['body'] ?? '');
$inputAttachments = $input['attachments'] ?? [];

if (!$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID required']);
    exit;
}

// A reply with neither words nor a file is nothing at all.
if ($body === '' && empty($inputAttachments)) {
    echo json_encode(['success' => false, 'error' => 'Please write a message or attach a file']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Ownership is the whole guard here: you may only reply to a ticket you
    // raised. Same rule (and same "not found" wording) as get_ticket_detail.php,
    // so this endpoint can never confirm the existence of someone else's ticket.
    // Deleted tickets are excluded — a requester shouldn't be able to add to a
    // thread the desk has binned.
    $ticketStmt = $conn->prepare(
        "SELECT id, subject FROM tickets WHERE id = ? AND user_id = ? AND deleted_datetime IS NULL"
    );
    $ticketStmt->execute([$ticketId, $userId]);
    $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $userStmt = $conn->prepare("SELECT email, display_name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('User not found');
    }

    $fromEmail = $user['email'];
    $fromName  = $user['display_name'] ?: $user['email'];

    // Keep the reply on the same mailbox as the rest of the thread, so an
    // analyst's next outbound reply goes out from the address the requester has
    // been talking to. Mirrors getMailboxForTicket() in api/tickets/send_email.php.
    $mbStmt = $conn->prepare(
        "SELECT mailbox_id FROM emails
         WHERE ticket_id = ? AND mailbox_id IS NOT NULL
         ORDER BY is_initial DESC, received_datetime ASC
         LIMIT 1"
    );
    $mbStmt->execute([$ticketId]);
    $mailboxId = $mbStmt->fetchColumn();
    $mailboxId = ($mailboxId === false || $mailboxId === null) ? null : (int)$mailboxId;

    $conn->beginTransaction();

    // The requester types plain text, so it is escaped on the way IN and stored
    // as the HTML it will be rendered as. Never store their raw markup: this body
    // is echoed into the analyst's reading pane and quoted into outbound email.
    $bodyHtml    = $body === '' ? '' : nl2br(htmlspecialchars($body));
    $bodyPreview = substr(strip_tags($body), 0, 200);
    $hasAttachments = !empty($inputAttachments) ? 1 : 0;

    $emailSql = "INSERT INTO emails (
        subject, from_address, from_name, to_recipients, received_datetime,
        body_preview, body_content, body_type, has_attachments, importance,
        is_read, ticket_id, is_initial, direction, mailbox_id
    ) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'html', ?, 'normal', 0, ?, 0, 'Portal', ?)";

    $emailStmt = $conn->prepare($emailSql);
    $emailStmt->execute([
        $ticket['subject'],
        $fromEmail,
        $fromName,
        $fromEmail,
        $bodyPreview,
        $bodyHtml,
        $hasAttachments,
        $ticketId,
        $mailboxId,
    ]);

    $emailId = $conn->lastInsertId();

    // Attachments land on disk under tickets/attachments/{floor(id/1000)}/{id}/,
    // the same convention as inbound mail (check_mailbox_email.php::saveAttachment)
    // so the analyst's existing get_attachment.php serves them with no changes.
    if (!empty($inputAttachments)) {
        $attachDir = realpath(__DIR__ . '/../../tickets/attachments');
        $subdir    = floor($emailId / 1000);
        $emailDir  = $attachDir . '/' . $subdir . '/' . $emailId;

        if (!is_dir($emailDir)) {
            mkdir($emailDir, 0755, true);
        }

        $attachStmt = $conn->prepare(
            "INSERT INTO email_attachments (email_id, filename, content_type, file_path, file_size, is_inline, created_datetime)
             VALUES (?, ?, ?, ?, ?, 0, UTC_TIMESTAMP())"
        );

        foreach ($inputAttachments as $att) {
            $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $att['name'] ?? 'file');
            $fileData = base64_decode($att['content'] ?? '');
            $fileSize = strlen($fileData);

            $savePath = $emailDir . '/' . $filename;
            $counter  = 1;
            $info     = pathinfo($filename);
            while (file_exists($savePath)) {
                $filename = $info['filename'] . '_' . $counter . '.' . ($info['extension'] ?? '');
                $savePath = $emailDir . '/' . $filename;
                $counter++;
            }

            file_put_contents($savePath, $fileData);

            $relPath     = $subdir . '/' . $emailId . '/' . $filename;
            $contentType = $att['type'] ?? 'application/octet-stream';

            $attachStmt->execute([$emailId, $filename, $contentType, $relPath, $fileSize]);
        }
    }

    // Bring the ticket back to the top of the queue.
    $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticketId]);

    $conn->commit();

    // Reopen AFTER the commit: the reply is the thing that must not be lost, and
    // the helper is deliberately non-fatal for the same reason.
    $reopened = reopenTicketForCustomerReply($conn, $ticketId);

    echo json_encode([
        'success'  => true,
        'email_id' => (int)$emailId,
        'reopened' => $reopened,
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
