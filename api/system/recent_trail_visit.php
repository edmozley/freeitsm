<?php
/**
 * API: "I have just opened this record" (discussion #124).
 * POST { type, id } -> { success }
 *
 * 🔴 WHY A CLIENT-SIDE PING EXISTS AT ALL. Most of this product opens records
 * WITHOUT a page load: the ticket inbox, the tasks board, assets, problems,
 * changes and knowledge are each one screen whose list swaps the record in the
 * reading pane, and most of them never touch the URL when they do it. So the
 * server hooks alone would have recorded the moments you ARRIVED at a module
 * from somewhere else and missed every record you opened once you were there —
 * which is exactly the work the trail exists to lead you back to.
 *
 * ⚠️ NOT GATED ON ONE MODULE, for the same reason record_preview.php is not:
 * eight kinds of record reach this and each needs a different gate.
 *
 * 🔑 AND IT DOES NOT NEED THE RECORD GATE HERE. Being able to write "I looked at
 * ticket 91" into your OWN trail discloses nothing — the write is unverified,
 * and the read (entityRecentTrail()) re-checks the module and the record every
 * single time the drawer is opened. A record you were never entitled to see
 * therefore cannot be made to appear in it by claiming to have visited it, and
 * that is the property that matters. Checking here as well would cost a query
 * per record opened, on every screen, to defend against somebody putting a row
 * into their own history that they will never be shown.
 *
 * Fire-and-forget: it rides on a keepalive fetch and nothing on the page waits
 * for the answer. A dropped ping means one row missing from a list of recent
 * things, which is why nothing here is allowed to be noisy about failing.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/recent_trail.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    // is_string / is_scalar FIRST — the same lesson as the preview endpoint:
    // casting an array to a string emits a warning carrying the server's
    // absolute path, printed before the JSON.
    $rawType = $data['type'] ?? '';
    $rawId   = $data['id']   ?? 0;

    recentTrailWrite(
        is_string($rawType) ? $rawType : '',
        is_scalar($rawId) ? (int)$rawId : 0
    );

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    echo json_encode(['success' => false]);
}
