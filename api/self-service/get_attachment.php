<?php
/**
 * Serve an email attachment to the requester who owns the ticket.
 *
 * The portal could show a thread but never hand back a FILE, so when an analyst
 * emailed someone a form, a driver or a screenshot, the one place that person
 * was told to look couldn't give it to them.
 *
 * This is the portal twin of api/tickets/get_attachment.php. The difference is
 * the authorisation model: the analyst version asks "can this analyst reach the
 * ticket's company", this one asks "did this requester raise this ticket". Both
 * resolve the ticket THROUGH the attachment's email rather than trusting anything
 * in the URL, so ids can't be enumerated into someone else's files.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/portal_visibility.php';

if (!isset($_SESSION['ss_user_id'])) {
    http_response_code(401);
    exit('Not authenticated');
}

$userId       = (int)$_SESSION['ss_user_id'];
$attachmentId = (int)($_GET['id'] ?? 0);

if (!$attachmentId) {
    http_response_code(400);
    exit('Attachment ID required');
}

try {
    $conn = connectToDatabase();

    // One query, joined all the way to the ticket: an attachment is only served
    // if its email belongs to a ticket THIS user raised and which hasn't been
    // deleted. A miss is a 404 either way, so a wrong id and someone else's id
    // are indistinguishable from outside.
    $stmt = $conn->prepare(
        "SELECT ea.filename, ea.content_type, ea.file_path, ea.file_size,
                e.channel, e.direction, e.from_address, e.to_recipients, e.cc_recipients,
                u.email AS requester_email
         FROM email_attachments ea
         JOIN emails e  ON e.id = ea.email_id
         JOIN tickets t ON t.id = e.ticket_id
         JOIN users u   ON u.id = t.user_id
         WHERE ea.id = ? AND t.user_id = ? AND t.deleted_datetime IS NULL"
    );
    $stmt->execute([$attachmentId, $userId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        http_response_code(404);
        exit('Attachment not found');
    }

    // Owning the ticket is not the whole question: a ticket can carry mail that
    // was never TO or FROM the requester (an analyst forwarding to a supplier,
    // and that supplier's reply). Tickets → Settings → Privacy decides. Enforced
    // HERE as well as in the listing, because hiding a link while the URL still
    // works is decoration — the id is trivially guessable and the file is the
    // sensitive part. See includes/portal_visibility.php.
    // ⚠️ NULL is preserved, NOT cast to '': the two mean opposite things to the
    // policy (see includes/portal_visibility.php). A requester with no mailbox
    // must have the policy APPLIED, not bypassed — and this endpoint is the one
    // that actually serves the file.
    $decision = portalEmailVisibility(
        $attachment,
        $attachment['requester_email'] !== null ? (string)$attachment['requester_email'] : null,
        portalThirdPartyPolicy($conn)
    );
    if (!$decision['attachments']) {
        http_response_code(404);
        exit('Attachment not found');
    }

    // file_path is a relative path we generated ourselves ({subdir}/{email_id}/
    // {sanitised name}), but it is still concatenated into a filesystem path, so
    // confirm the resolved file really sits under the attachments directory
    // before reading it.
    $baseDir  = realpath(dirname(dirname(__DIR__)) . '/tickets/attachments');
    $filePath = realpath($baseDir . '/' . $attachment['file_path']);

    if ($baseDir === false || $filePath === false || strpos($filePath, $baseDir) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        exit('Attachment file not found');
    }

    header('Content-Type: ' . $attachment['content_type']);
    header('Content-Length: ' . $attachment['file_size']);
    header('Cache-Control: private, max-age=86400');

    // Never let the browser MIME-sniff a user-supplied file into something
    // executable (e.g. a "photo" that is actually HTML/JS). Matters more here
    // than on the analyst side: anyone can email the service desk, and this
    // serves the result back into the requester's own session.
    header('X-Content-Type-Options: nosniff');

    // Media that is safe to render is shown inline so it previews in the thread;
    // everything else downloads.
    $ct = strtolower($attachment['content_type']);
    $inlineSafe = strpos($ct, 'image/') === 0
        || strpos($ct, 'audio/') === 0
        || strpos($ct, 'video/') === 0
        || $ct === 'application/pdf';

    $disposition = $inlineSafe ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . $attachment['filename'] . '"');

    readfile($filePath);

} catch (Exception $e) {
    http_response_code(500);
    exit('Error serving attachment');
}
