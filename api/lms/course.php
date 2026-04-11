<?php
/**
 * LMS API: Single course operations (GET detail, PUT update, DELETE deactivate)
 */
session_start();
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

$input = json_decode(file_get_contents('php://input'), true);
$id = (int)($input['id'] ?? 0);

if ($method === 'PUT' || ($method === 'POST' && ($input['_method'] ?? '') === 'PUT')) {
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    if (empty($title)) {
        echo json_encode(['success' => false, 'error' => 'Title is required']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE lms_courses SET title = ?, description = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
    $stmt->execute([$title, $description, $id]);
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
