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
    return analystHasCapability($conn, $analystId, 'lms.manage');
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
                   p.score_raw, p.score_max, p.last_access, p.completion_datetime
            FROM lms_course_assignments ca
            JOIN lms_learning_groups g ON ca.group_id = g.id AND g.is_active = 1
            JOIN lms_learning_group_members m ON m.group_id = g.id AND m.analyst_id = ?
            JOIN lms_courses c ON ca.course_id = c.id AND c.is_active = 1
            LEFT JOIN lms_progress p ON p.analyst_id = ? AND p.course_id = c.id
            GROUP BY c.id, c.title, c.description, c.content_type, c.scorm_version,
                     p.status, p.score_raw, p.score_max, p.last_access, p.completion_datetime
            ORDER BY (MIN(ca.deadline) IS NULL), MIN(ca.deadline), c.title";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analystId, $analystId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
