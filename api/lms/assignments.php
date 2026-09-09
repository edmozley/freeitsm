<?php
/**
 * LMS API: List assignments (GET) or Create one (POST).
 *
 * An assignment says WHO a course is for. Three kinds of target, described on
 * lms_course_assignments in freeitsm.sql:
 *   learning_group  an analyst learning group (the original; still the default)
 *   user_group      a shared people group — analysts and portal users together
 *   all_users       every active portal user
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

$conn = connectToDatabase();

/** The three target kinds, and which table (if any) each one's id points at. */
const LMS_TARGET_TYPES = ['learning_group', 'user_group', 'all_users'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ⚠️ LEFT JOINs and a COALESCE, not the single inner JOIN this used to have.
    // The old query was `JOIN lms_learning_groups g ON ca.group_id = g.id`, which
    // for a people-group assignment matches nothing — so every course pushed to
    // the portal would have silently vanished from the manager's own list of
    // what he had assigned. An inner join to one of three possible tables is a
    // filter, however much it looks like a lookup.
    $lg = "LEFT JOIN lms_learning_groups lg ON ca.target_type = 'learning_group' AND lg.id = ca.group_id AND lg.is_active = 1";
    $ug = lmsUserGroupsAvailable($conn)
        ? "LEFT JOIN knowledge_user_groups ug ON ca.target_type = 'user_group' AND ug.id = ca.group_id AND ug.is_active = 1"
        : "";
    $ugName = lmsUserGroupsAvailable($conn) ? 'ug.name' : 'NULL';

    $sql = "SELECT ca.*,
                   c.title AS course_title,
                   a.full_name AS assigned_by_name,
                   COALESCE(lg.name, $ugName) AS group_name
              FROM lms_course_assignments ca
              JOIN lms_courses c ON ca.course_id = c.id
              $lg
              $ug
              LEFT JOIN analysts a ON ca.assigned_by_id = a.id
             WHERE c.is_active = 1
               -- A group that has been deleted leaves its assignment behind.
               -- Hidden rather than shown as a blank row, which is what the old
               -- inner join did by accident and is the right answer here.
               AND (ca.target_type = 'all_users'
                    OR lg.id IS NOT NULL
                    " . (lmsUserGroupsAvailable($conn) ? "OR ug.id IS NOT NULL" : "") . ")
          ORDER BY ca.created_datetime DESC";

    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        if ($r['target_type'] === 'all_users') $r['group_name'] = null;   // the page names it
    }
    unset($r);

    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

requireCapabilityJson(Cap::LMS_MANAGE);

// POST: create assignment
$input      = json_decode(file_get_contents('php://input'), true);
$courseId   = (int)($input['course_id'] ?? 0);
$targetType = (string)($input['target_type'] ?? 'learning_group');
$groupId    = (int)($input['group_id'] ?? 0);
$deadline   = !empty($input['deadline']) ? $input['deadline'] : null;

if (!in_array($targetType, LMS_TARGET_TYPES, true)) {
    echo json_encode(['success' => false, 'error' => 'Unknown target']);
    exit;
}
// "Everyone on the portal" names no group, so its id is a constant rather than
// something the caller supplies — otherwise the unique key would let the same
// course be pushed to everyone twice under two different ids.
if ($targetType === 'all_users') $groupId = 0;

if (!$courseId || ($targetType !== 'all_users' && !$groupId)) {
    echo json_encode(['success' => false, 'error' => 'Choose a course and who it is for']);
    exit;
}

// The target has to exist. Without this a typo'd id is accepted happily and the
// assignment reaches nobody at all, which looks exactly like the feature not
// working — the worst kind of silence.
if ($targetType === 'learning_group') {
    $st = $conn->prepare("SELECT 1 FROM lms_learning_groups WHERE id = ? AND is_active = 1");
    $st->execute([$groupId]);
    if (!$st->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'That learning group was not found']); exit; }
} elseif ($targetType === 'user_group') {
    if (!lmsUserGroupsAvailable($conn)) {
        echo json_encode(['success' => false, 'error' => 'People groups are not available on this install yet']);
        exit;
    }
    $st = $conn->prepare("SELECT 1 FROM knowledge_user_groups WHERE id = ? AND is_active = 1");
    $st->execute([$groupId]);
    if (!$st->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'That group was not found']); exit; }
}

try {
    $stmt = $conn->prepare("INSERT INTO lms_course_assignments (course_id, target_type, group_id, deadline, assigned_by_id)
                            VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$courseId, $targetType, $groupId, $deadline, $_SESSION['analyst_id']]);
    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo json_encode(['success' => false, 'error' => $targetType === 'all_users'
            ? 'This course has already been pushed to everyone on the portal'
            : 'This course is already assigned to that group']);
    } else {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
