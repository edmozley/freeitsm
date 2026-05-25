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

$tmp        = $_FILES['file']['tmp_name'];
$origName   = $_FILES['file']['name'] ?? 'recording';
$size       = (int)$_FILES['file']['size'];
$mime       = $_FILES['file']['type'] ?? '';
$duration   = isset($_POST['duration_seconds']) ? (int)$_POST['duration_seconds'] : null;
$hasAudio   = !empty($_POST['has_audio']) ? 1 : 0;

// Whitelist MIME types — only the two MediaRecorder can produce
$allowedMimes = ['video/mp4', 'video/webm'];
if (!in_array($mime, $allowedMimes, true)) {
    // Some browsers omit the codec param; sniff the first few bytes as a fallback
    $first = file_get_contents($tmp, false, null, 0, 16);
    if (strpos($first, 'ftyp') !== false) {
        $mime = 'video/mp4';
    } elseif (strpos($first, "\x1A\x45\xDF\xA3") === 0) {
        $mime = 'video/webm';
    } else {
        echo json_encode(['success' => false, 'error' => 'Unsupported file type. Only mp4 and webm recordings are allowed.']);
        exit;
    }
}

// 50MB cap — keeps the on-disk footprint sane and protects against rogue uploads
$maxBytes = 50 * 1024 * 1024;
if ($size > $maxBytes) {
    echo json_encode(['success' => false, 'error' => 'Recording too large (max 50MB)']);
    exit;
}

$ext = $mime === 'video/mp4' ? 'mp4' : 'webm';
$uuid = bin2hex(random_bytes(16));
$storedName = $uuid . '.' . $ext;

$appRoot = realpath(__DIR__ . '/../../');
$pendingDir = $appRoot . DIRECTORY_SEPARATOR . 'recordings' . DIRECTORY_SEPARATOR . 'pending';
if (!is_dir($pendingDir)) {
    if (!mkdir($pendingDir, 0755, true) && !is_dir($pendingDir)) {
        echo json_encode(['success' => false, 'error' => 'Server storage unavailable']);
        exit;
    }
}

$destPath = $pendingDir . DIRECTORY_SEPARATOR . $storedName;
if (!move_uploaded_file($tmp, $destPath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to store recording']);
    exit;
}

// Store the relative path so it stays portable across deployments
$relativePath = 'recordings/pending/' . $storedName;

try {
    $conn = connectToDatabase();
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
    @unlink($destPath);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    error_log('upload_recording.php: ' . $e->getMessage());
}
