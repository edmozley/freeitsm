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

    // Authorisation: an analyst can view any recording attached to a ticket.
    // A self-service user can view a recording only if they own the ticket OR
    // they uploaded it (pending state, before the ticket exists yet).
    if ($analystId) {
        // analyst can view any
    } elseif ($ssUserId) {
        $owns = false;
        if ($rec['ticket_id']) {
            $check = $conn->prepare("SELECT user_id FROM tickets WHERE id = ?");
            $check->execute([(int)$rec['ticket_id']]);
            $owns = ((int)$check->fetchColumn() === (int)$ssUserId);
        } elseif ((int)$rec['recorded_by_user_id'] === (int)$ssUserId) {
            $owns = true; // pending recording uploaded by this user
        }
        if (!$owns) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    $appRoot = realpath(__DIR__ . '/../../');
    $absPath = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rec['file_path']);
    if (!is_file($absPath)) {
        http_response_code(404);
        exit('File missing');
    }

    $size = filesize($absPath);
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

    if ($isPartial) {
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header("Content-Type: $contentType");
    header("Accept-Ranges: bytes");
    header("Content-Length: $length");
    header('Cache-Control: private, max-age=600');

    $fp = fopen($absPath, 'rb');
    if (!$fp) { http_response_code(500); exit; }
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false) break;
        echo $chunk;
        $remaining -= strlen($chunk);
        if (connection_aborted()) break;
        @ob_flush();
        @flush();
    }
    fclose($fp);
} catch (Exception $e) {
    error_log('get_recording.php: ' . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}
