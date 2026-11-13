<?php
/**
 * API: Stream a screen recording with HTTP Range support.
 *
 * Dual auth: either the end user who owns the ticket OR any logged-in analyst.
 * Range requests are honoured so the <video> element can seek without
 * downloading the entire file first.
 *
 * Query: id — ticket_recordings.id
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';   // analystCanAccessTicket() — see F9

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Bad request');
}

$ssUserId = $_SESSION['ss_user_id'] ?? null;
$analystId = $_SESSION['analyst_id'] ?? null;
if (!$ssUserId && !$analystId) {
    http_response_code(401);
    exit('Not authenticated');
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT id, ticket_id, recorded_by_user_id, file_path, content_type, original_filename
                            FROM ticket_recordings WHERE id = ?");
    $stmt->execute([$id]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rec) {
        http_response_code(404);
        exit('Not found');
    }

    // Authorisation.
    //
    // ⚠️ The analyst branch below used to be empty — a bare `// analyst can view any`
    // with no check of any kind, while `id` is a raw ticket_recordings.id off the
    // query string. Any analyst at all, including one scoped to a single company and
    // with no Tickets access whatsoever, could count upwards and stream every
    // client's customer screen recordings. The self-service half of this same file
    // was correctly scoped throughout, which is what made it easy to miss.
    if ($analystId) {
        if (!analystCanAccessModule($conn, (int)$analystId, 'tickets')) {
            http_response_code(403);
            exit('Forbidden');
        }
        // A recording only has a company through its ticket. One that is still
        // pending (uploaded before the ticket existed) belongs to the portal user
        // who recorded it and to nobody else — there is nothing yet to authorise
        // an analyst against, so there is no answer to give.
        if (empty($rec['ticket_id']) || !analystCanAccessTicket($conn, (int)$analystId, (int)$rec['ticket_id'])) {
            http_response_code(404);
            exit('Not found');
        }
    } elseif ($ssUserId) {
        $owns = false;
        if ($rec['ticket_id']) {
            // The SAME rule as the ticket page (includes/managers.php): its
            // requester, or one of their managers - never through a confidential
            // stub, and never on a ticket in the trash (#62).
            require_once __DIR__ . '/../../includes/managers.php';
            $access = portalTicketAccess($conn, (int)$ssUserId, (int)$rec['ticket_id']);
            $owns = $access && !$access['stub'];
        } elseif ((int)$rec['recorded_by_user_id'] === (int)$ssUserId) {
            $owns = true; // pending recording uploaded by this user
        }
        if (!$owns) {
            // Not found, never forbidden: a refusal must not confirm it exists.
            http_response_code(404);
            exit('Not found');
        }
    }

    // The bytes are in the recordings store (a server folder or S3), under the row's
    // path without its "recordings/" folder name.
    require_once __DIR__ . '/../../includes/ticket_recordings.php';
    $key = recordingStoreKey((string)$rec['file_path']);
    try {
        $size = $key === null ? null : storeSize($conn, 'recordings', $key);
    } catch (StorageUnavailable $e) {
        http_response_code(503);
        exit('Storage cannot be reached right now');
    }
    if ($size === null) {
        http_response_code(404);
        exit('File missing');
    }

    $contentType = $rec['content_type'] ?: 'application/octet-stream';

    // Range-aware streaming so <video> can seek
    $start = 0;
    $end = $size - 1;
    $isPartial = false;
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d+)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
        if ($start > $end || $start >= $size) {
            header("Content-Range: bytes */$size");
            http_response_code(416);
            exit;
        }
        $isPartial = true;
    }

    $length = $end - $start + 1;

    // Headers only once the store has answered, so a store that cannot be reached
    // still gets a proper status rather than half a 206.
    $code = storeServe($conn, 'recordings', $key, $start, $end, function () use ($isPartial, $start, $end, $size, $contentType, $length) {
        if ($isPartial) {
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header("Content-Type: $contentType");
        header("X-Content-Type-Options: nosniff");   // a video is played, never sniffed into something else
        header("Accept-Ranges: bytes");
        header("Content-Length: $length");
        header('Cache-Control: private, max-age=600');
    });
    if ($code !== 0) {
        http_response_code($code === 503 ? 503 : 404);
        exit($code === 503 ? 'Storage cannot be reached right now' : 'File missing');
    }
} catch (Exception $e) {
    error_log('get_recording.php: ' . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}
