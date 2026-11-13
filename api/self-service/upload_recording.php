<?php
/**
 * API: Self-Service Portal — Upload Screen Recording
 *
 * Multipart POST: file (binary), duration_seconds (optional int), has_audio (0/1)
 * Returns: { success, recording_id }
 *
 * Pending model: recordings are uploaded BEFORE the ticket exists. The row is
 * inserted with ticket_id = NULL and recorded_by_user_id = current session user;
 * the ticket creation endpoint claims them by setting ticket_id once known.
 *
 * Files land in recordings/pending/{uuid}.{ext}; create_ticket.php moves them to
 * recordings/{ticket_id}/{uuid}.{ext} on claim.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['file']['error'] ?? 'no file';
    echo json_encode(['success' => false, 'error' => 'Upload failed (' . $err . ')']);
    exit;
}

$duration   = isset($_POST['duration_seconds']) ? (int)$_POST['duration_seconds'] : null;
$hasAudio   = !empty($_POST['has_audio']) ? 1 : 0;

// 🔴 Through includes/uploads.php, like every other upload. This endpoint used to
// trust the browser's declared type outright - video/mp4 meant no check at all -
// or accept any file with "ftyp" somewhere in its first 16 bytes, with its own
// size rule and no refusal of an empty file. uploadStoreFile() checks the
// extension, then the CONTENT against it (fails closed), and stores under a random
// name in a folder it protects. The recorder names its file
// screen-recording-<stamp>.mp4|webm, so genuine recordings pass unchanged.
// Reported privately, October 2026.
require_once '../../includes/uploads.php';
require_once '../../includes/storage/stores.php';   // the recordings store: a server folder or S3
try {
    $conn = connectToDatabase();
    $stored = storeSaveUpload($conn, 'recordings', 'pending', $_FILES['file'],
        ['mp4' => UPLOAD_TYPES_VIDEO['mp4'], 'webm' => UPLOAD_TYPES_VIDEO['webm']],
        50 * 1024 * 1024);   // 50MB: keeps the on-disk footprint sane
} catch (StorageUnavailable $e) {
    error_log('upload_recording.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Storage cannot be reached right now. Try again in a few minutes.']);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
$storedName = $stored['stored_name'];
$origName   = uploadCleanName($stored['original_name']);
$size       = (int)$stored['size'];
// What the <video> element needs to hear, whichever of each type's names finfo used.
$mime       = substr($storedName, -4) === '.mp4' ? 'video/mp4' : 'video/webm';

// Store the relative path so it stays portable across deployments
$relativePath = 'recordings/pending/' . $storedName;

try {
    $stmt = $conn->prepare("INSERT INTO ticket_recordings
        (ticket_id, recorded_by_user_id, filename, original_filename, content_type, file_path, file_size, duration_seconds, has_audio, created_at)
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    $stmt->execute([
        (int)$_SESSION['ss_user_id'],
        $storedName,
        $origName,
        $mime,
        $relativePath,
        $size,
        $duration,
        $hasAudio,
    ]);
    $recordingId = (int)$conn->lastInsertId();
    echo json_encode(['success' => true, 'recording_id' => $recordingId]);
} catch (Exception $e) {
    storeDelete($conn, 'recordings', $stored['key']);   // no row will ever point at it
    echo json_encode(['success' => false, 'error' => 'Database error']);
    error_log('upload_recording.php: ' . $e->getMessage());
}
