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

// LMS_TARGET_TYPES and lmsAssignmentTargetProblem() live in
// includes/lms_access.php — shared with assignment.php, which needs exactly the
// same list and exactly the same validation.

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    /* The opportunistic reminder run, hooked here because this endpoint is what
       the LMS console loads when a manager opens the Assignments tab — the most
       plausible moment somebody who cares about training is using FreeITSM.
       Throttled to once an hour inside, off unless reminders are switched on,
       and wrapped so it can never be the reason this list fails to load.
       See includes/lms_reminders.php for why an opportunistic path exists. */
    require_once '../../includes/lms_reminders.php';
    lmsRemindersRunOpportunistic($conn);

    // ⚠️ LEFT JOINs and a COALESCE, not the single inner JOIN this used to have.
    // The old query was `JOIN lms_learning_groups g ON ca.group_id = g.id`, which
    // for a people-group assignment matches nothing — so every course pushed to
    // the portal would have silently vanished from the manager's own list of
    // what he had assigned. An inner join to one of three possible tables is a
    // filter, however much it looks like a lookup.
    $hasUg  = lmsUserGroupsAvailable($conn);
    $lg = "LEFT JOIN lms_learning_groups lg ON ca.target_type = 'learning_group' AND lg.id = ca.group_id AND lg.is_active = 1";
    $ug = $hasUg
        ? "LEFT JOIN knowledge_user_groups ug ON ca.target_type = 'user_group' AND ug.id = ca.group_id AND ug.is_active = 1"
        : "";
    $ugName = $hasUg ? 'ug.name' : 'NULL';
    // The two individual targets, resolved to a name the same way.
    $ia = "LEFT JOIN analysts ia ON ca.target_type = 'analyst' AND ia.id = ca.group_id";
    $iu = "LEFT JOIN users iu ON ca.target_type = 'user' AND iu.id = ca.group_id";

    $sql = "SELECT ca.*,
                   c.title AS course_title,
                   a.full_name AS assigned_by_name,
                   COALESCE(lg.name, $ugName, ia.full_name,
                            NULLIF(iu.display_name, ''), iu.email, iu.username) AS group_name
              FROM lms_course_assignments ca
              JOIN lms_courses c ON ca.course_id = c.id
              $lg
              $ug
              $ia
              $iu
              LEFT JOIN analysts a ON ca.assigned_by_id = a.id
             WHERE c.is_active = 1
               -- A group or a person that has been deleted leaves its assignment
               -- behind. Hidden rather than shown as a blank row, which is what
               -- the old inner join did by accident and is the right answer here.
               AND (ca.target_type = 'all_users'
                    OR lg.id IS NOT NULL
                    OR ia.id IS NOT NULL
                    OR iu.id IS NOT NULL
                    " . ($hasUg ? "OR ug.id IS NOT NULL" : "") . ")
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

$problem = lmsAssignmentTargetProblem($conn, $targetType, $groupId);
if ($problem !== null) { echo json_encode(['success' => false, 'error' => $problem]); exit; }

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
