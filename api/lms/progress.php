<?php
/**
 * LMS API: everyone's progress, for the manager Progress tab.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS A UNION AND NOT A JOIN
 *
 * It used to be one chain — assignments -> learning group -> members ->
 * analysts — which worked while an assignment could only ever reach an analyst.
 * A course can now be given to a shared people group (holding analysts AND
 * portal users) or to every portal user at once, so "who is expected to do this
 * course" comes from three different places and lands in two different tables.
 *
 * 🔴 THE OLD SHAPE WOULD NOT HAVE FAILED LOUDLY. `JOIN analysts a ON
 * m.analyst_id = a.id` simply matches nothing for a portal learner, so the
 * screen would have reported a course pushed to 400 people as having nobody
 * assigned to it — a confident, wrong, empty table. That is the whole reason
 * this file was rewritten rather than extended.
 * ─────────────────────────────────────────────────────────────────────────────
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
// Everyone's progress across every assignment. Managers only; a learner sees
// only their own courses, on My Courses or the portal's Training page.
requireCapabilityJson(Cap::LMS_MANAGE);

$conn = connectToDatabase();

$courseId  = $_GET['course_id'] ?? '';
$groupId   = $_GET['group_id'] ?? '';
$status    = $_GET['status'] ?? '';
// One person's row across every course they have been assigned. Composes with
// the group filter rather than competing with it: the two narrow different
// things, so picking somebody who is not in the chosen group correctly returns
// nothing rather than quietly ignoring one of them.
$learnerKey = (string)($_GET['learner'] ?? '');
if ($learnerKey === '' && ($_GET['analyst_id'] ?? '') !== '') {
    // The filter used to be an analyst id and nothing else. Still honoured.
    $learnerKey = 'analyst:' . (int)$_GET['analyst_id'];
}

$hasUserGroups = lmsUserGroupsAvailable($conn);

/**
 * One SELECT per way a course reaches somebody. Each produces the same columns
 * so they can be UNIONed: who, which course, which assignment, and the deadline.
 *
 * 🔑 UNION, not UNION ALL — somebody reached by two routes (in a people group
 * AND caught by "everyone") is ONE person expected to do ONE course, and should
 * be one row. The deadline is then reduced in PHP below, where the earliest
 * wins, exactly as lmsMyCourses() does it for the learner's own view.
 */
$selects = [];
$params  = [];

// 1. An analyst learning group.
$selects[] = "SELECT 'analyst' AS learner_type, m.analyst_id AS learner_id,
                     ca.course_id, ca.group_id, ca.target_type, ca.deadline
                FROM lms_course_assignments ca
                JOIN lms_learning_groups g ON g.id = ca.group_id AND g.is_active = 1
                JOIN lms_learning_group_members m ON m.group_id = g.id
                JOIN analysts a ON a.id = m.analyst_id AND a.is_active = 1
               WHERE ca.target_type = 'learning_group'";

if ($hasUserGroups) {
    // 2. A shared people group — both kinds of member, expiry applied.
    //    ⚠️ The expiry is applied here for the same reason it is applied on the
    //    learner's own page: somebody whose access has lapsed is no longer
    //    expected to do the course, and leaving them on a manager's overdue list
    //    would generate chasing for training nobody can now open.
    $selects[] = "SELECT um.member_type AS learner_type, um.member_id AS learner_id,
                         ca.course_id, ca.group_id, ca.target_type, ca.deadline
                    FROM lms_course_assignments ca
                    JOIN knowledge_user_groups ug ON ug.id = ca.group_id AND ug.is_active = 1
                    JOIN knowledge_user_group_members um ON um.group_id = ug.id
                   WHERE ca.target_type = 'user_group'
                     AND (um.expires_at IS NULL OR um.expires_at > UTC_TIMESTAMP())";
}

// 3. Every active portal user.
$selects[] = "SELECT 'user' AS learner_type, u.id AS learner_id,
                     ca.course_id, ca.group_id, ca.target_type, ca.deadline
                FROM lms_course_assignments ca
                JOIN users u ON u.is_active = 1
               WHERE ca.target_type = 'all_users'";

$reach = "(" . implode("\n UNION \n", $selects) . ")";

$sql = "SELECT r.learner_type, r.learner_id, r.course_id, r.group_id, r.target_type, r.deadline,
               c.title AS course_title,
               COALESCE(p.status, 'not_started') AS status,
               p.score_raw, p.score_max, p.total_time, p.last_access, p.completion_datetime
          FROM $reach r
          JOIN lms_courses c ON c.id = r.course_id AND c.is_active = 1
          LEFT JOIN lms_progress p
                 ON p.learner_type = r.learner_type
                AND p.learner_id  = r.learner_id
                AND p.course_id   = r.course_id
         WHERE 1=1";

if ($courseId !== '') { $sql .= " AND r.course_id = ?"; $params[] = (int)$courseId; }
// The group filter names a group id; which KIND of group is implied by the
// assignment row it came from, so both target types are matched on the id.
if ($groupId !== '')  { $sql .= " AND r.group_id = ? AND r.target_type <> 'all_users'"; $params[] = (int)$groupId; }

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Names, in two lookups rather than two hundred -------------------------
// 🔑 Collected and fetched in bulk. The obvious shape here is a per-row lookup
// inside the loop, which on a course pushed to every portal user is one query
// per person on the install.
$analystIds = [];
$userIds    = [];
foreach ($rows as $r) {
    if ($r['learner_type'] === 'analyst') $analystIds[(int)$r['learner_id']] = true;
    else                                  $userIds[(int)$r['learner_id']]    = true;
}

$names = [];
if ($analystIds) {
    $in = implode(',', array_fill(0, count($analystIds), '?'));
    $st = $conn->prepare("SELECT id, full_name FROM analysts WHERE id IN ($in)");
    $st->execute(array_keys($analystIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $names['analyst:' . $a['id']] = $a['full_name'];
}
if ($userIds) {
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $st = $conn->prepare("SELECT id, COALESCE(NULLIF(display_name, ''), email, username) AS name
                            FROM users WHERE id IN ($in)");
    $st->execute(array_keys($userIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $names['user:' . $u['id']] = $u['name'];
}

// Group names, for the row label. Two tables again, same reason.
$groupNames = [];
foreach ($conn->query("SELECT id, name FROM lms_learning_groups")->fetchAll(PDO::FETCH_ASSOC) as $g) {
    $groupNames['learning_group:' . $g['id']] = $g['name'];
}
if ($hasUserGroups) {
    foreach ($conn->query("SELECT id, name FROM knowledge_user_groups")->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $groupNames['user_group:' . $g['id']] = $g['name'];
    }
}

// ---- Collapse to one row per (learner, course) -----------------------------
// A UNION already removed exact duplicates, but somebody can be reached by two
// DIFFERENT routes with different deadlines. Earliest wins, matching what the
// learner is told on their own page — the two screens must not disagree about
// when something is due.
$byLearnerCourse = [];
foreach ($rows as $r) {
    $key = $r['learner_type'] . ':' . $r['learner_id'] . ':' . $r['course_id'];
    if (!isset($byLearnerCourse[$key])) {
        $byLearnerCourse[$key] = $r;
        continue;
    }
    $seen = $byLearnerCourse[$key];
    $keepEarlier = $r['deadline'] !== null
        && ($seen['deadline'] === null || $r['deadline'] < $seen['deadline']);
    if ($keepEarlier) $byLearnerCourse[$key]['deadline'] = $r['deadline'];
}

$now      = new DateTime('now', new DateTimeZone('UTC'));
$filtered = [];
$learners = [];

foreach ($byLearnerCourse as $row) {
    $lk = $row['learner_type'] . ':' . $row['learner_id'];

    $row['learner_key']  = $lk;
    $row['analyst_id']   = $row['learner_type'] === 'analyst' ? (int)$row['learner_id'] : null;
    $row['learner_name'] = $names[$lk] ?? 'Deleted account';
    // The old key the Progress tab reads. Kept so the column keeps rendering.
    $row['analyst_name'] = $row['learner_name'];
    $row['group_name']   = $row['target_type'] === 'all_users'
        ? 'Everyone on the portal'
        : ($groupNames[$row['target_type'] . ':' . $row['group_id']] ?? '—');

    $row['is_overdue'] = false;
    if (!empty($row['deadline'])) {
        $deadline = new DateTime($row['deadline'], new DateTimeZone('UTC'));
        if ($now > $deadline && !in_array($row['status'], ['completed', 'passed'], true)) {
            $row['is_overdue'] = true;
        }
    }

    // Who the current course/group selection covers, for the learner dropdown —
    // built BEFORE the learner filter is applied, or choosing somebody would
    // remove everyone else from the list you chose them from.
    $learners[$lk] = ['key' => $lk, 'type' => $row['learner_type'],
                      'id' => (int)$row['learner_id'], 'name' => $row['learner_name']];

    if ($learnerKey !== '' && $lk !== $learnerKey) continue;

    if ($status === 'overdue') {
        if (!$row['is_overdue']) continue;
    } elseif ($status !== '' && $row['status'] !== $status) {
        continue;
    }

    $filtered[] = $row;
}

usort($filtered, function ($a, $b) {
    return [$a['learner_name'], $a['course_title']] <=> [$b['learner_name'], $b['course_title']];
});

$learners = array_values($learners);
usort($learners, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

echo json_encode([
    'success'  => true,
    'data'     => $filtered,
    'learners' => $learners,
    // The old key, in its old {id, full_name} shape, so an un-updated Progress
    // tab still populates its dropdown rather than rendering an empty select.
    'analysts' => array_values(array_map(
        function ($l) { return ['id' => $l['id'], 'full_name' => $l['name']]; },
        array_filter($learners, function ($l) { return $l['type'] === 'analyst'; })
    )),
]);
