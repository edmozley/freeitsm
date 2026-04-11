<?php
/**
 * LMS API: Update or delete a single assignment
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
$input = json_decode(file_get_contents('php://input'), true);
$id = (int)($input['id'] ?? 0);
$method = $input['_method'] ?? $_SERVER['REQUEST_METHOD'];

if (strtoupper($method) === 'PUT') {
    $deadline = !empty($input['deadline']) ? $input['deadline'] : null;
    $conn->prepare("UPDATE lms_course_assignments SET deadline = ? WHERE id = ?")->execute([$deadline, $id]);
    echo json_encode(['success' => true]);
    exit;
}

if (strtoupper($method) === 'DELETE') {
    $conn->prepare("DELETE FROM lms_course_assignments WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid method']);
