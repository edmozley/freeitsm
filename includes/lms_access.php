<?php
/**
 * LMS access rules — the learner/manager split (RBAC pilot).
 *
 * Two distinct things a person can do with the LMS:
 *   - TAKE assigned courses. Needs the 'lms' module only. A learner.
 *   - MANAGE the LMS (author, upload, run groups, assign, see everyone's
 *     progress). Needs the 'lms.manage' capability (or is_admin). A manager.
 *
 * These helpers are the single source of truth for both, used by the pages, the
 * APIs and the playback gate so the rule can't drift between them.
 */

require_once __DIR__ . '/rbac.php';

/** May this analyst manage the LMS? (is_admin bypasses, via analystHasCapability.) */
function lmsCanManage(PDO $conn, int $analystId): bool {
    return analystHasCapability($conn, $analystId, Cap::LMS_MANAGE);
}

/**
 * Is this course assigned to the analyst — i.e. assigned to a learning group
 * they belong to? This is what an analyst is *entitled* to take.
 */
function lmsCourseAssignedTo(PDO $conn, int $analystId, int $courseId): bool {
    if ($analystId <= 0 || $courseId <= 0) return false;
    $sql = "SELECT 1
            FROM lms_course_assignments ca
            JOIN lms_learning_groups g ON ca.group_id = g.id AND g.is_active = 1
            JOIN lms_learning_group_members m ON m.group_id = g.id
            WHERE ca.course_id = ? AND m.analyst_id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$courseId, $analystId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * May this analyst open/play this course? Managers (and admins) may open any
 * course — that's how Preview works. Everyone else may open only what's assigned
 * to them. This is the gate the player and the learner content APIs enforce.
 */
function lmsCanAccessCourse(PDO $conn, int $analystId, int $courseId): bool {
    if (lmsCanManage($conn, $analystId)) return true;
    return lmsCourseAssignedTo($conn, $analystId, $courseId);
}

/**
 * Hard gate for a learner course API: 403 unless the analyst may access $courseId.
 * Assumes the session check and module gate have already run.
 */
function requireLmsCourseAccessJson(PDO $conn, int $courseId): void {
    $id = (int) ($_SESSION['analyst_id'] ?? 0);
    if (!$id || !lmsCanAccessCourse($conn, $id, $courseId)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'This course has not been assigned to you.']);
        exit;
    }
}

/**
 * The courses assigned to an analyst (via any group they're in), each with their
 * own progress — the data behind the My Courses page. One row per course even if
 * it reaches them through several groups (earliest deadline wins). is_overdue is
 * computed here so the page and any caller agree.
 *
 * @return array<int,array<string,mixed>>
 */
function lmsMyCourses(PDO $conn, int $analystId): array {
    if ($analystId <= 0) return [];

    $sql = "SELECT c.id, c.title, c.description, c.content_type, c.scorm_version,
                   MIN(ca.deadline) AS deadline,
                   COALESCE(p.status, 'not_started') AS status,
                   p.score_raw, p.score_max, p.last_access, p.completion_datetime,
                   p.bookmark
            FROM lms_course_assignments ca
            JOIN lms_learning_groups g ON ca.group_id = g.id AND g.is_active = 1
            JOIN lms_learning_group_members m ON m.group_id = g.id AND m.analyst_id = ?
            JOIN lms_courses c ON ca.course_id = c.id AND c.is_active = 1
            LEFT JOIN lms_progress p ON p.analyst_id = ? AND p.course_id = c.id
            GROUP BY c.id, c.title, c.description, c.content_type, c.scorm_version,
                     p.status, p.score_raw, p.score_max, p.last_access, p.completion_datetime,
                     p.bookmark
            ORDER BY (MIN(ca.deadline) IS NULL), MIN(ca.deadline), c.title";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analystId, $analystId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    lmsAttachLessonProgress($conn, $rows);

    $now = new DateTime('now', new DateTimeZone('UTC'));
    foreach ($rows as &$row) {
        $row['is_overdue'] = false;
        if (!empty($row['deadline'])) {
            $deadline = new DateTime($row['deadline'], new DateTimeZone('UTC'));
            if ($now > $deadline && !in_array($row['status'], ['completed', 'passed'], true)) {
                $row['is_overdue'] = true;
            }
        }
    }
    unset($row);

    return $rows;
}

/**
 * How far through a course somebody is, as "lesson N of M".
 *
 * 🔑 THERE IS NO STORED PERCENTAGE, AND THIS DOES NOT INVENT ONE. The only
 * position the product records for a native course is `lms_progress.bookmark`,
 * which the native player writes as the ID of the lesson being read. So the
 * honest reading is a POSITION IN A LIST - lesson 2 of 3 - and that is what the
 * page shows beside the bar. A bar on its own would imply a measured percentage
 * of a course that nothing measures.
 *
 * ⚠️ SCORM courses get nothing. They have no `lms_lessons` rows at all (their
 * content is a package on disk), and their bookmark holds `cmi.core.lesson_location`,
 * which is whatever the package chose to put there - a page id, a slide name, a
 * blob. It is not a lesson id and must never be compared to one, which is also
 * why this ranks in PHP rather than joining `lb.id = p.bookmark` in SQL: that
 * join would coerce arbitrary text to an integer and quietly match lesson 0.
 *
 * A finished course is full whatever the bookmark says, because somebody who
 * passed and then reopened lesson 1 has not gone backwards.
 *
 * Adds `lesson_count` and `lesson_position` to each row, both 0 when unknown.
 * One extra query for the whole page, not one per course.
 */
function lmsAttachLessonProgress(PDO $conn, array &$rows): void
{
    foreach ($rows as &$r) {
        $r['lesson_count'] = 0;
        $r['lesson_position'] = 0;
    }
    unset($r);
    if (!$rows) return;

    // Only native courses have lessons to count.
    $nativeIds = [];
    foreach ($rows as $r) {
        if (($r['content_type'] ?? '') === 'native') $nativeIds[] = (int)$r['id'];
    }
    if (!$nativeIds) return;

    $in = implode(',', array_fill(0, count($nativeIds), '?'));
    // Same order the player walks them in, so "lesson 2" means the same thing
    // on this page as it does inside the course.
    $st = $conn->prepare(
        "SELECT course_id, id FROM lms_lessons
          WHERE course_id IN ($in)
       ORDER BY course_id, display_order, id"
    );
    $st->execute($nativeIds);

    $byCourse = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $byCourse[(int)$l['course_id']][] = (int)$l['id'];
    }

    foreach ($rows as &$r) {
        $lessons = $byCourse[(int)$r['id']] ?? [];
        if (!$lessons) continue;
        $r['lesson_count'] = count($lessons);

        if (in_array($r['status'], ['completed', 'passed'], true)) {
            $r['lesson_position'] = $r['lesson_count'];
            continue;
        }
        $bookmark = trim((string)($r['bookmark'] ?? ''));
        if ($bookmark === '' || !ctype_digit($bookmark)) continue;
        $idx = array_search((int)$bookmark, $lessons, true);
        if ($idx !== false) $r['lesson_position'] = $idx + 1;
    }
    unset($r);
}
