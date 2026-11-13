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
require_once '../../includes/uploads.php';   // attachmentSendHeaders()

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
        "SELECT ea.filename, ea.content_type, ea.file_path, ea.file_size, t.id AS ticket_id,
                e.channel, e.direction, e.from_address, e.to_recipients, e.cc_recipients,
                u.email AS requester_email
         FROM email_attachments ea
         JOIN emails e  ON e.id = ea.email_id
         JOIN tickets t ON t.id = e.ticket_id
         JOIN users u   ON u.id = t.user_id
         WHERE ea.id = ? AND t.deleted_datetime IS NULL"
    );
    $stmt->execute([$attachmentId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    // Who may have it: the SAME rule as the ticket page (includes/managers.php) -
    // its requester, or one of their managers - and never from a confidential
    // stub, which shows that a ticket exists and nothing it contains (#62).
    // The privacy check below still measures against the TICKET'S requester
    // (requester_email above), so a manager gets no file the requester would not.
    require_once __DIR__ . '/../../includes/managers.php';
    $access = $attachment ? portalTicketAccess($conn, $userId, (int)$attachment['ticket_id']) : null;
    if (!$access || $access['stub']) {
        $attachment = false;
    }

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

    // The bytes are in the tickets store (a server folder or S3, System -> Storage).
    // The store refuses a key that could leave it and, on a server folder, a file
    // that resolves outside it - the containment this endpoint used to check here.
    require_once __DIR__ . '/../../includes/storage/stores.php';
    $key = (string)$attachment['file_path'];
    try {
        $actual = storeSize($conn, 'tickets', $key);
    } catch (StorageUnavailable $e) {
        http_response_code(503);
        exit('Storage cannot be reached right now. Try again in a few minutes.');
    }
    if ($actual === null) {
        http_response_code(404);
        exit('Attachment file not found');
    }

    // ⚠️ Same fix as api/tickets/get_attachment.php, and it matters more here:
    // anyone can email the service desk, and this serves the result back into the
    // requester's own session. The served type comes from the file extension via
    // our own map, never from the sender-supplied content_type — which let
    // `image/svg+xml` through the old inline test and made a planted SVG run script
    // on our origin. See includes/uploads.php.
    $code = storeServe($conn, 'tickets', $key, 0, max(0, $actual - 1), function () use ($attachment) {
        header('Cache-Control: private, max-age=86400');
        attachmentSendHeaders((string)$attachment['filename'], (int)$attachment['file_size']);
    });
    if ($code !== 0) {
        http_response_code($code === 503 ? 503 : 404);
        exit($code === 503 ? 'Storage cannot be reached right now.' : 'Attachment file not found');
    }

} catch (Exception $e) {
    http_response_code(500);
    exit('Error serving attachment');
}
