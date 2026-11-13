<?php
/**
 * Public, unauthenticated media server for ONE outbound attachment — the
 * companion to webhook.php, but outbound: Twilio's WhatsApp API sends media
 * by URL rather than accepting an upload, so it has to fetch the bytes from
 * somewhere reachable on the internet. This is that somewhere.
 *
 *   GET /api/messaging/media.php?id=<email_attachments.id>&exp=<unix ts>&token=<hmac>
 *
 * Deliberately narrow, not a general file host:
 *   - the token is an HMAC over (id, exp) using the app's own encryption key
 *     (messagingOutboundMediaToken()) — nobody can mint one without server-side
 *     code, and it is bound to exactly one attachment
 *   - exp is checked server-side; an expired link 403s even with a
 *     correctly-computed token for the (id, exp) pair it was issued for
 *   - only serves a row this app itself created as an OUTBOUND channel
 *     attachment (email_attachments joined to an Outbound, non-email emails
 *     row) — an inbound attachment, or an ordinary email attachment, is not
 *     reachable through this endpoint even with a forged token, because the
 *     query itself excludes them
 */
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/messaging/messaging.php';
require_once '../../includes/uploads.php';   // attachmentSendHeaders()

/**
 * Sends the given HTTP status with a plain "Not found", then stops - every refusal looks
 * the same, so nothing is revealed about why.
 *
 * @param int $code  the HTTP status to send, e.g. 400, 404 or 410 for an expired link
 */
function mediaFail(int $code): void
{
    http_response_code($code);
    header('Content-Type: text/plain');
    echo 'Not found';
    exit;
}

$id    = (int) ($_GET['id'] ?? 0);
$exp   = (int) ($_GET['exp'] ?? 0);
$token = (string) ($_GET['token'] ?? '');

if ($id <= 0 || $exp <= 0 || $token === '') {
    mediaFail(400);
}
if (time() > $exp) {
    mediaFail(410); // Gone — expired, not merely missing
}

// A missing encryption key makes the token function throw. On an endpoint the
// whole internet can reach, that must be a plain refusal, never a PHP fatal that
// prints the key file's path.
try {
    $expected = messagingOutboundMediaToken($id, $exp);
} catch (Exception $e) {
    mediaFail(404);
}
if (!hash_equals($expected, $token)) {
    mediaFail(403);
}

try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    mediaFail(500);
}

// ⚠️ The Outbound + channel<>'email' filter is load-bearing, not cosmetic —
// see the file header. It is what stops a guessed-but-otherwise-valid-shaped
// request (or a token somehow computed offline) from reaching an inbound
// attachment or an ordinary email attachment.
$stmt = $conn->prepare(
    "SELECT ea.file_path, ea.content_type, ea.filename
     FROM email_attachments ea
     JOIN emails e ON e.id = ea.email_id
     WHERE ea.id = ? AND e.direction = 'Outbound' AND e.channel <> 'email'"
);
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    mediaFail(404);
}

// The bytes are in the tickets store (a server folder or S3). The store refuses a
// key that could leave it and, on a server folder, a file that resolves outside it:
// the containment this file checked by hand. file_path is built by this app, never
// taken from the request, so this is defence in depth rather than the primary guard.
require_once dirname(__DIR__, 2) . '/includes/storage/stores.php';
$key = ltrim((string) $row['file_path'], '/\\');
try {
    $size = storeSize($conn, 'tickets', $key);
} catch (StorageUnavailable $e) {
    mediaFail(503);
}
if ($size === null) {
    mediaFail(404);
}

// The app's one rule for serving a stored file (includes/uploads.php): the type
// and inline-or-download come from the EXTENSION via a fixed table, never from
// the stored content_type, plus nosniff and a header-safe name. PR #159 set these
// by hand from content_type; the shared helper is what every other attachment
// download uses, so a fix to it reaches this one too.
$code = storeServe($conn, 'tickets', $key, 0, max(0, $size - 1), function () use ($row, $size) {
    attachmentSendHeaders((string) $row['filename'], $size);
    // This URL is single-purpose and expires on its own (checked above) — caching
    // it anywhere beyond the fetch that's about to happen serves no one.
    header('Cache-Control: no-store');
});
if ($code !== 0) {
    mediaFail($code === 503 ? 503 : 404);
}
