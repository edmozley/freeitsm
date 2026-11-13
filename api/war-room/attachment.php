<?php
/**
 * API: War room — serve an attachment.
 *
 * GET ?id=<int>
 *
 * 🔒 TWO RULES, AND NEITHER IS OPTIONAL.
 *
 * 1. AUTHORISATION IS THE CHANNEL'S. warRoomAttachmentFor() resolves the file
 *    through its message to its channel and applies exactly the same access rule
 *    as reading the conversation. Guessing an attachment id from a DM you are not
 *    in returns 404, not the file. The check is in the service layer rather than
 *    here so there is one answer to "may I read this", shared with every other
 *    caller.
 *
 * 2. THE CONTENT TYPE IS OURS, NOT THE UPLOADER'S. attachmentSendHeaders() works
 *    it out from the file extension against ATTACHMENT_SERVE_TYPES and adds
 *    nosniff (security finding F5). This is why there is no content_type column
 *    to consult: an attachment that could declare itself image/svg+xml could run
 *    script in the reader's session, because SVG is XML and the browser opens it
 *    as a top-level document on our own origin.
 *
 * The bytes live outside any directory the web server will execute from — see
 * includes/uploads.php — but that is the net under the tightrope, not the
 * tightrope. Nothing is ever fetched from that folder directly.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/warroom.php';

requireModuleAccessJson('war-room');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

try {
    $conn = connectToDatabase();
    $row  = warRoomAttachmentFor($conn, (int) $_SESSION['analyst_id'], $id);
} catch (Throwable $e) {
    $row = null;
}

// One response for "no such file", "not yours" and "gone from storage". Telling the
// three apart would let somebody map which attachment ids exist in channels they
// cannot read. Storage that cannot be reached is a 503: the file may well be there.
$refuse = function (int $code = 404): never {
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $code === 503 ? 'Storage cannot be reached right now. Try again in a few minutes.' : 'Not found';
    exit;
};
if ($row === null) $refuse();
try {
    $size = storeSize($conn, 'warroom', (string) $row['stored_name']);
} catch (StorageUnavailable $e) {
    $refuse(503);
}
if ($size === null) $refuse();

$code = storeServe($conn, 'warroom', (string) $row['stored_name'], 0, max(0, $size - 1),
    fn() => attachmentSendHeaders((string) $row['original_name'], $size));
if ($code !== 0) $refuse($code === 503 ? 503 : 404);
