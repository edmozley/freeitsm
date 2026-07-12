<?php
/**
 * LMS API: Single course operations (GET detail, PUT update, DELETE deactivate)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$conn = connectToDatabase();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM lms_courses WHERE id = ? AND is_active = 1");
    $stmt->execute([$id]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($course ? ['success' => true, 'data' => $course] : ['success' => false, 'error' => 'Not found']);
    exit;
}

requireModuleAccessJson('lms');

$input = json_decode(file_get_contents('php://input'), true);
$id = (int)($input['id'] ?? 0);

/**
 * Read a pass mark off the request. Null means "no pass mark" — the course is
 * marked complete on reaching the end rather than passed/failed. Anything
 * outside 0-100 is a bad request rather than something to silently clamp: a
 * course whose pass mark isn't what the author typed is worse than an error.
 */
function lmsReadPassMark(array $input, &$error) {
    if (!array_key_exists('pass_mark', $input)) return null;
    $raw = $input['pass_mark'];
    if ($raw === null || $raw === '') return null;
    if (!is_numeric($raw) || (int)$raw != $raw || (int)$raw < 0 || (int)$raw > 100) {
        $error = 'Pass mark must be a whole number between 0 and 100.';
        return null;
    }
    return (int)$raw;
}

// Create a NATIVE course (an authored one). SCORM courses are created by the
// zip upload in courses.php instead — that path needs multipart, this one is
// plain JSON, so they stay separate rather than one endpoint sniffing its input.
if ($method === 'POST' && ($input['_method'] ?? '') === 'CREATE_NATIVE') {
    $title = trim($input['title'] ?? '');
    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Title is required']);
        exit;
    }
    $err = null;
    $passMark = lmsReadPassMark($input, $err);
    if ($err) { echo json_encode(['success' => false, 'error' => $err]); exit; }

    $stmt = $conn->prepare("INSERT INTO lms_courses (title, description, content_type, pass_mark, created_by_id, created_datetime, updated_datetime)
                            VALUES (?, ?, 'native', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$title, trim($input['description'] ?? ''), $passMark, $_SESSION['analyst_id']]);
    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    exit;
}

if ($method === 'PUT' || ($method === 'POST' && ($input['_method'] ?? '') === 'PUT')) {
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    if (empty($title)) {
        echo json_encode(['success' => false, 'error' => 'Title is required']);
        exit;
    }
    $err = null;
    $passMark = lmsReadPassMark($input, $err);
    if ($err) { echo json_encode(['success' => false, 'error' => $err]); exit; }

    // Only touch pass_mark when the caller actually sent it, so the existing SCORM
    // edit form (title + description only) can't null it out on an authored course.
    if (array_key_exists('pass_mark', $input)) {
        $stmt = $conn->prepare("UPDATE lms_courses SET title = ?, description = ?, pass_mark = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$title, $description, $passMark, $id]);
    } else {
        $stmt = $conn->prepare("UPDATE lms_courses SET title = ?, description = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$title, $description, $id]);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE' || ($method === 'POST' && ($input['_method'] ?? '') === 'DELETE')) {
    $stmt = $conn->prepare("UPDATE lms_courses SET is_active = 0, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid method']);
