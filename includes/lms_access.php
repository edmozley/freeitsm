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

/**
 * WHO IS TAKING A COURSE.
 *
 * The LMS used to know one kind of learner — an analyst — and passed a bare
 * `int $analystId` everywhere. A course can now be given to a self-service
 * portal user, who lives in `users` and has no analyst record at all, so the
 * identity has to carry WHICH TABLE it means. That is this class, and it is why
 * every function below takes one instead of an int.
 *
 * 🔑 A CLASS RATHER THAN TWO NULLABLE INTS. The alternative was
 * `(?int $analystId, ?int $userId)` with an unwritten "exactly one of these"
 * rule — the shape the portal password-reset tables were deliberately split to
 * avoid, for the same reason: nothing enforces it, and the day somebody passes
 * both, or neither, the LMS shows one person another person's training record.
 * Here the object cannot be constructed in an invalid state.
 */
final class LmsLearner
{
    const ANALYST = 'analyst';
    const USER    = 'user';

    /** @var string self::ANALYST | self::USER — matches lms_progress.learner_type */
    private $type;
    /** @var int analysts.id or users.id, per $type */
    private $id;

    private function __construct(string $type, int $id)
    {
        $this->type = $type;
        $this->id   = $id;
    }

    public static function analyst(int $id): ?self
    {
        return $id > 0 ? new self(self::ANALYST, $id) : null;
    }

    public static function user(int $id): ?self
    {
        return $id > 0 ? new self(self::USER, $id) : null;
    }

    /**
     * Whoever is signed in, from either front door.
     *
     * ⚠️ THE ANALYST SESSION WINS when both keys are somehow present. One
     * browser really can hold both — an analyst who has also signed into the
     * portal to see what a requester sees — and the analyst is the more
     * privileged of the two, so resolving to the portal identity would show
     * them the wrong My Courses and, worse, write their progress onto a
     * requester's record. Deterministic, and documented, rather than "whichever
     * key PHP happens to find".
     */
    public static function fromSession(): ?self
    {
        if (!empty($_SESSION['analyst_id'])) return self::analyst((int)$_SESSION['analyst_id']);
        if (!empty($_SESSION['ss_user_id'])) return self::user((int)$_SESSION['ss_user_id']);
        return null;
    }

    public function type(): string { return $this->type; }
    public function id(): int      { return $this->id; }
    public function isAnalyst(): bool { return $this->type === self::ANALYST; }

    /** 'analyst:12' — for logs and array keys, never for storage. */
    public function key(): string { return $this->type . ':' . $this->id; }
}

/** May this analyst manage the LMS? (is_admin bypasses, via analystHasCapability.) */
function lmsCanManage(PDO $conn, int $analystId): bool {
    return analystHasCapability($conn, $analystId, Cap::LMS_MANAGE);
}

/**
 * Are the shared people groups available on this install?
 *
 * ⚠️ Guarded because the assignment query joins `knowledge_user_groups`, and an
 * install part-way through an upgrade may not have it yet. Without this the
 * whole My Courses page would be a 500 rather than a page listing the courses
 * that reach the learner by every OTHER route. Cached per request; the same
 * habit as tenancyColumnExists() and the try/catch in knowledge/visibility.php.
 */
function lmsUserGroupsAvailable(PDO $conn): bool {
    static $available = null;
    if ($available !== null) return $available;
    try {
        $conn->query("SELECT 1 FROM knowledge_user_groups LIMIT 1");
        return $available = true;
    } catch (PDOException $e) {
        return $available = false;
    }
}

/**
 * THE ONE PLACE THAT SAYS WHICH ASSIGNMENTS REACH A LEARNER.
 *
 * Returns [sql, params] for a WHERE fragment against `lms_course_assignments ca`,
 * covering all three kinds of target:
 *
 *   learning_group  an analyst learning group. ANALYSTS ONLY — a portal user is
 *                   not in `analysts` and can never match one, which is why the
 *                   branch is omitted entirely rather than left to return no rows.
 *   user_group      a shared people group, which holds both kinds of member. The
 *                   EXPIRY IS APPLIED HERE, at read time, exactly as Knowledge
 *                   does it: somebody given a fortnight's training access loses
 *                   it by the clock rather than by anyone remembering.
 *   all_users       every portal user. Matches any 'user' learner and no analyst
 *                   — "push it to the portal" means the portal.
 *
 * 🔑 Every caller composes this rather than writing its own joins. The old code
 * spelled the same three-table join out in five places; they agreed then, and
 * the first person to add a target type would have found out that they don't.
 *
 * @return array{0:string,1:array}
 */
function lmsAssignmentReachSql(PDO $conn, LmsLearner $learner): array
{
    $branches = [];
    $params   = [];

    if ($learner->isAnalyst()) {
        $branches[] = "(ca.target_type = 'learning_group' AND EXISTS (
                            SELECT 1 FROM lms_learning_groups g
                              JOIN lms_learning_group_members m ON m.group_id = g.id
                             WHERE g.id = ca.group_id AND g.is_active = 1 AND m.analyst_id = ?))";
        $params[] = $learner->id();
    } else {
        $branches[] = "ca.target_type = 'all_users'";
    }

    if (lmsUserGroupsAvailable($conn)) {
        $branches[] = "(ca.target_type = 'user_group' AND EXISTS (
                            SELECT 1 FROM knowledge_user_groups ug
                              JOIN knowledge_user_group_members um ON um.group_id = ug.id
                             WHERE ug.id = ca.group_id AND ug.is_active = 1
                               AND um.member_type = ? AND um.member_id = ?
                               AND (um.expires_at IS NULL OR um.expires_at > UTC_TIMESTAMP())))";
        $params[] = $learner->type();
        $params[] = $learner->id();
    }

    return ['(' . implode(' OR ', $branches) . ')', $params];
}

/**
 * Is this course assigned to this learner? This is what they are *entitled* to
 * take, by any of the routes above.
 */
function lmsCourseAssignedTo(PDO $conn, LmsLearner $learner, int $courseId): bool {
    if ($courseId <= 0) return false;
    list($reachSql, $reachParams) = lmsAssignmentReachSql($conn, $learner);

    $stmt = $conn->prepare("SELECT 1 FROM lms_course_assignments ca
                             WHERE ca.course_id = ? AND $reachSql
                             LIMIT 1");
    $stmt->execute(array_merge([$courseId], $reachParams));
    return (bool) $stmt->fetchColumn();
}

/**
 * May this learner open/play this course? Managers (and admins) may open any
 * course — that's how Preview works. Everyone else may open only what's assigned
 * to them. This is the gate the player and the learner content APIs enforce.
 *
 * ⚠️ THE MANAGER BYPASS IS ANALYST-ONLY, and deliberately so. lmsCanManage()
 * asks an RBAC question about an `analysts` row, and a portal user's id is a
 * `users` row — passing it in would ask whether analyst #12 is an LMS manager
 * while holding portal user #12, and answer about the wrong person entirely.
 * A portal learner has no Preview and no override: assigned, or nothing.
 */
function lmsCanAccessCourse(PDO $conn, LmsLearner $learner, int $courseId): bool {
    if ($learner->isAnalyst() && lmsCanManage($conn, $learner->id())) return true;
    return lmsCourseAssignedTo($conn, $learner, $courseId);
}

/**
 * Hard gate for a learner course API: 403 unless the caller may access
 * $courseId. Resolves the learner from whichever session is present, so one
 * guard covers both the analyst app and the portal.
 * Assumes the session check and module gate have already run.
 */
function requireLmsCourseAccessJson(PDO $conn, int $courseId): void {
    $learner = LmsLearner::fromSession();
    if (!$learner || !lmsCanAccessCourse($conn, $learner, $courseId)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'This course has not been assigned to you.']);
        exit;
    }
}

/**
 * The courses assigned to a learner by any route, each with their own progress —
 * the data behind My Courses and the portal's Training page. One row per course
 * even if it reaches them several ways (earliest deadline wins). is_overdue is
 * computed here so the page and any caller agree.
 *
 * 🔑 The three-table join this used to open with is gone. Which assignments
 * reach somebody is now one question, answered in lmsAssignmentReachSql(), and a
 * plain EXISTS keeps this query returning ONE ROW PER COURSE — joining through
 * group membership instead would multiply the row by every group that reaches
 * it, which the old GROUP BY was quietly there to mop up.
 *
 * @return array<int,array<string,mixed>>
 */
function lmsMyCourses(PDO $conn, LmsLearner $learner): array {
    list($reachSql, $reachParams) = lmsAssignmentReachSql($conn, $learner);

    $sql = "SELECT c.id, c.title, c.description, c.content_type, c.scorm_version,
                   MIN(ca.deadline) AS deadline,
                   COALESCE(p.status, 'not_started') AS status,
                   p.score_raw, p.score_max, p.last_access, p.completion_datetime,
                   p.bookmark
            FROM lms_course_assignments ca
            JOIN lms_courses c ON ca.course_id = c.id AND c.is_active = 1
            LEFT JOIN lms_progress p ON p.learner_type = ? AND p.learner_id = ? AND p.course_id = c.id
            WHERE $reachSql
            GROUP BY c.id, c.title, c.description, c.content_type, c.scorm_version,
                     p.status, p.score_raw, p.score_max, p.last_access, p.completion_datetime,
                     p.bookmark
            ORDER BY (MIN(ca.deadline) IS NULL), MIN(ca.deadline), c.title";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$learner->type(), $learner->id()], $reachParams));
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

/**
 * This learner's progress row for a course, created on first sight.
 *
 * 🔑 ONE COPY, because there were two and they are the rows that record whether
 * somebody has done their mandatory training. api/lms/native_progress.php and
 * api/lms/scorm_data.php each had their own find-or-create, written against
 * `analyst_id`; two independent INSERTs into a table whose unique key was about
 * to change is precisely the pair you do not want to leave lying around.
 *
 * ⚠️ `analyst_id` IS WRITTEN TOO, for an analyst, and left NULL for a portal
 * learner. It is legacy (see freeitsm.sql) and nothing reads it — but while the
 * column still exists it must not go stale, or the next person to write a report
 * against the obvious-looking column gets a partial answer with no hint that it
 * is partial. It goes at the next MAJOR, and this line goes with it.
 */
function lmsProgressRowFor(PDO $conn, LmsLearner $learner, int $courseId): array
{
    $find = $conn->prepare("SELECT * FROM lms_progress
                             WHERE learner_type = ? AND learner_id = ? AND course_id = ?");
    $find->execute([$learner->type(), $learner->id(), $courseId]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    // INSERT IGNORE, not a bare INSERT: two tabs open on the same course is an
    // ordinary thing for a learner to do, and the loser of that race would
    // otherwise throw a duplicate-key error into the middle of a lesson. The
    // unique key makes the second one a no-op and the re-read below finds the
    // first one's row.
    $ins = $conn->prepare(
        "INSERT IGNORE INTO lms_progress
            (analyst_id, learner_type, learner_id, course_id, status,
             first_access, last_access, attempt_count, created_datetime, updated_datetime)
         VALUES (?, ?, ?, ?, 'incomplete',
             UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $ins->execute([
        $learner->isAnalyst() ? $learner->id() : null,
        $learner->type(),
        $learner->id(),
        $courseId,
    ]);

    $find->execute([$learner->type(), $learner->id(), $courseId]);
    return $find->fetch(PDO::FETCH_ASSOC) ?: [];
}
