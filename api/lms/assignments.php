<?php
/**
 * LMS API: List assignments (GET) or Create assignment (POST)
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT ca.*, c.title as course_title, g.name as group_name, a.full_name as assigned_by_name
            FROM lms_course_assignments ca
            JOIN lms_courses c ON ca.course_id = c.id
            JOIN lms_learning_groups g ON ca.group_id = g.id
            LEFT JOIN analysts a ON ca.assigned_by_id = a.id
            WHERE c.is_active = 1 AND g.is_active = 1
            ORDER BY ca.created_datetime DESC";
    $stmt = $conn->query($sql);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// POST: create assignment
$input = json_decode(file_get_contents('php://input'), true);
$courseId = (int)($input['course_id'] ?? 0);
$groupId = (int)($input['group_id'] ?? 0);
$deadline = !empty($input['deadline']) ? $input['deadline'] : null;

if (!$courseId || !$groupId) {
    echo json_encode(['success' => false, 'error' => 'Course and group are required']);
    exit;
}

try {
    $stmt = $conn->prepare("INSERT INTO lms_course_assignments (course_id, group_id, deadline, assigned_by_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$courseId, $groupId, $deadline, $_SESSION['analyst_id']]);
    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo json_encode(['success' => false, 'error' => 'This course is already assigned to that group']);
    } else {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
