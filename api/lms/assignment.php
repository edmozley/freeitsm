<?php
/**
 * LMS API: change or remove a single assignment.
 *
 * PUT    course, who it is for, and the deadline
 * DELETE remove it
 *
 * ⚠️ EDITING THE TARGET IS ALLOWED, and is the point of having an edit at all:
 * "I meant Finance, not All staff" was previously delete-and-start-again, which
 * loses the deadline you had already typed. Changing the course is allowed for
 * the same reason — the row means "this course, for these people", and either
 * half can be the one you got wrong.
 *
 * 🔑 NOBODY'S PROGRESS IS TOUCHED BY ANY OF THIS. lms_progress is keyed on
 * (learner, course) and knows nothing about assignments, so moving an assignment
 * from one group to another does not erase what anyone has already done — if
 * they are still reached by it, their existing record is simply still theirs.
 * Somebody who is no longer reached keeps their record too; it just stops being
 * expected of them. That is the right behaviour and it is worth stating,
 * because "will this wipe the training records?" is the first thing anybody
 * sensible asks before pressing Save.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/lms_access.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson(Cap::LMS_MANAGE);

$conn = connectToDatabase();
$input = json_decode(file_get_contents('php://input'), true);
$id = (int)($input['id'] ?? 0);
$method = $input['_method'] ?? $_SERVER['REQUEST_METHOD'];

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'No assignment given']);
    exit;
}

if (strtoupper($method) === 'PUT') {
    $deadline = !empty($input['deadline']) ? $input['deadline'] : null;

    // The target and course are optional on a PUT: a caller that sends only a
    // deadline still means "just change the deadline", which is what this
    // endpoint did before it could do anything else.
    $existing = $conn->prepare("SELECT course_id, target_type, group_id FROM lms_course_assignments WHERE id = ?");
    $existing->execute([$id]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'error' => 'That assignment no longer exists']); exit; }

    $courseId   = array_key_exists('course_id', $input)   ? (int)$input['course_id']         : (int)$row['course_id'];
    $targetType = array_key_exists('target_type', $input) ? (string)$input['target_type']    : (string)$row['target_type'];
    $groupId    = array_key_exists('group_id', $input)    ? (int)$input['group_id']          : (int)$row['group_id'];

    if (!in_array($targetType, LMS_TARGET_TYPES, true)) {
        echo json_encode(['success' => false, 'error' => 'Unknown target']); exit;
    }
    if ($targetType === 'all_users') $groupId = 0;
    if (!$courseId || ($targetType !== 'all_users' && !$groupId)) {
        echo json_encode(['success' => false, 'error' => 'Choose a course and who it is for']); exit;
    }

    $problem = lmsAssignmentTargetProblem($conn, $targetType, $groupId);
    if ($problem !== null) { echo json_encode(['success' => false, 'error' => $problem]); exit; }

    try {
        $conn->prepare("UPDATE lms_course_assignments
                           SET course_id = ?, target_type = ?, group_id = ?, deadline = ?
                         WHERE id = ?")
             ->execute([$courseId, $targetType, $groupId, $deadline, $id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo json_encode(['success' => false, 'error' => $targetType === 'all_users'
                ? 'This course has already been pushed to everyone on the portal'
                : 'This course is already assigned to that group']);
        } else {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    exit;
}

if (strtoupper($method) === 'DELETE') {
    $conn->prepare("DELETE FROM lms_course_assignments WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid method']);
