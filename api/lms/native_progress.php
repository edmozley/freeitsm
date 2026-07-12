<?php
/**
 * LMS API: progress + grading for a native course.
 *
 * GET  ?course_id=N   — register an attempt, return the resume point.
 * POST {course_id, bookmark}                       — remember where they got to.
 * POST {course_id, finished:true, responses:[…]}   — grade the attempt.
 *
 * GRADING IS SERVER-SIDE AND AUTHORITATIVE. The client posts only which answer
 * *ids* were chosen; the correct set is re-read from lms_answers here. The
 * learner never receives is_correct (see course_content.php), so there is
 * nothing on the client to tamper with — a forged POST can change which answers
 * it claims were picked, but not whether they were right.
 *
 * Results are written as SCORM cmi.interactions.* rows into lms_cmi_data, which
 * is what makes an authored course show up in the existing admin Progress tab
 * and learner drill-down with no new admin UI at all.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/lms_access.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('lms');

$conn      = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];

/** Find the learner's progress row for a course, creating it on first sight. */
function lmsProgressRow(PDO $conn, int $analystId, int $courseId): array {
    $stmt = $conn->prepare("SELECT * FROM lms_progress WHERE analyst_id = ? AND course_id = ?");
    $stmt->execute([$analystId, $courseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $ins = $conn->prepare("INSERT INTO lms_progress (analyst_id, course_id, status, first_access, last_access, attempt_count, created_datetime, updated_datetime)
                           VALUES (?, ?, 'incomplete', UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $ins->execute([$analystId, $courseId]);

    $stmt->execute([$analystId, $courseId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/** HH:MM:SS — the format lms.js formatTime() understands. */
function lmsFormatTime(int $seconds): string {
    $seconds = max(0, $seconds);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

/** Parse HH:MM:SS back to seconds, so session time can be accumulated. */
function lmsParseTime(?string $t): int {
    if (!$t || !preg_match('/^(\d+):(\d{2}):(\d{2})$/', $t, $m)) return 0;
    return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)$m[3];
}

/** Upsert one CMI element. The unique key on (progress_id, element) does the work. */
function lmsWriteCmi(PDO $conn, int $progressId, string $element, string $value): void {
    $stmt = $conn->prepare("INSERT INTO lms_cmi_data (progress_id, element, value, created_datetime, updated_datetime)
                            VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_datetime = UTC_TIMESTAMP()");
    $stmt->execute([$progressId, $element, $value]);
}

try {
    $courseId = (int)($_GET['course_id'] ?? 0);

    // ---- GET: starting (or resuming) an attempt ----
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$courseId) throw new Exception('Missing course_id');
        requireLmsCourseAccessJson($conn, $courseId);   // assigned-to-me, or a manager
        $row = lmsProgressRow($conn, $analystId, $courseId);

        $conn->prepare("UPDATE lms_progress SET attempt_count = attempt_count + 1, last_access = UTC_TIMESTAMP(), updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$row['id']]);

        echo json_encode(['success' => true, 'status' => $row['status'], 'bookmark' => $row['bookmark'], 'score_raw' => $row['score_raw']]);
        exit;
    }

    $input    = json_decode(file_get_contents('php://input'), true) ?: [];
    $courseId = (int)($input['course_id'] ?? 0);
    if (!$courseId) throw new Exception('Missing course_id');
    requireLmsCourseAccessJson($conn, $courseId);   // can't post progress for an unassigned course

    $cs = $conn->prepare("SELECT id, pass_mark, content_type FROM lms_courses WHERE id = ? AND is_active = 1");
    $cs->execute([$courseId]);
    $course = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$course)                             throw new Exception('Course not found');
    if ($course['content_type'] !== 'native') throw new Exception('Not an authored course');

    $row        = lmsProgressRow($conn, $analystId, $courseId);
    $progressId = (int)$row['id'];

    // Accumulate time across sessions rather than overwriting it.
    $elapsed   = max(0, (int)($input['elapsed_seconds'] ?? 0));
    $totalTime = lmsFormatTime(lmsParseTime($row['total_time']) + $elapsed);

    // ---- Just a bookmark: they moved to another lesson ----
    if (empty($input['finished'])) {
        $bookmark = substr((string)($input['bookmark'] ?? ''), 0, 500);
        $status   = in_array($row['status'], ['completed', 'passed', 'failed'], true) ? $row['status'] : 'incomplete';

        $conn->prepare("UPDATE lms_progress SET bookmark = ?, status = ?, total_time = ?, last_access = UTC_TIMESTAMP(), updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$bookmark, $status, $totalTime, $progressId]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ---- Finishing: grade it ----
    // Every question in the course, in order, with its key — read fresh from the DB.
    $qs = $conn->prepare("SELECT q.id, q.question_text, q.question_type, q.explanation
                          FROM lms_questions q
                          JOIN lms_lessons l ON q.lesson_id = l.id
                          WHERE l.course_id = ?
                          ORDER BY l.display_order, l.id, q.display_order, q.id");
    $qs->execute([$courseId]);
    $questions = $qs->fetchAll(PDO::FETCH_ASSOC);

    $answersByQ = [];
    if ($questions) {
        $qin = implode(',', array_fill(0, count($questions), '?'));
        $as  = $conn->prepare("SELECT id, question_id, answer_text, is_correct FROM lms_answers WHERE question_id IN ($qin) ORDER BY display_order, id");
        $as->execute(array_column($questions, 'id'));
        foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $a) $answersByQ[$a['question_id']][] = $a;
    }

    // What the learner picked, keyed by question.
    $picked = [];
    foreach (($input['responses'] ?? []) as $r) {
        $qid = (int)($r['question_id'] ?? 0);
        $ids = array_map('intval', (array)($r['answer_ids'] ?? []));
        if ($qid) $picked[$qid] = $ids;
    }

    $conn->beginTransaction();

    $correctCount = 0;
    $review       = [];   // what they got right/wrong — only ever sent back AFTER submitting
    foreach ($questions as $i => $q) {
        $options     = $answersByQ[$q['id']] ?? [];
        $correctIds  = array_column(array_filter($options, fn($a) => (int)$a['is_correct'] === 1), 'id');
        $chosenIds   = $picked[$q['id']] ?? [];

        // Right = exactly the correct set, no more and no less. Sorting first means
        // the comparison doesn't care what order the options were ticked in.
        sort($correctIds);
        $chosenIds = array_values(array_unique($chosenIds));
        sort($chosenIds);
        $isCorrect = ($correctIds == $chosenIds) && $correctIds !== [];
        if ($isCorrect) $correctCount++;

        $textOf = function (array $ids) use ($options) {
            $texts = [];
            foreach ($options as $o) if (in_array((int)$o['id'], $ids, true)) $texts[] = $o['answer_text'];
            return implode(', ', $texts);
        };

        // SCORM element names, so the existing learner drill-down renders these for free.
        $p = "cmi.interactions.$i.";
        lmsWriteCmi($conn, $progressId, $p . 'id',          'q' . $q['id']);
        lmsWriteCmi($conn, $progressId, $p . 'type',        $q['question_type'] === 'truefalse' ? 'true-false' : 'choice');
        lmsWriteCmi($conn, $progressId, $p . 'description', $q['question_text']);
        lmsWriteCmi($conn, $progressId, $p . 'learner_response', $textOf($chosenIds));
        lmsWriteCmi($conn, $progressId, $p . 'result',      $isCorrect ? 'correct' : 'incorrect');
        lmsWriteCmi($conn, $progressId, $p . 'correct_responses.0.pattern', $textOf($correctIds));

        $review[] = [
            'question_text'  => $q['question_text'],
            'is_correct'     => $isCorrect,
            'your_answer'    => $textOf($chosenIds),
            'correct_answer' => $textOf($correctIds),
            'explanation'    => $q['explanation'] ?? '',
        ];
    }

    $total = count($questions);
    $score = $total > 0 ? round(($correctCount / $total) * 100, 2) : null;

    // No questions means there is nothing to pass or fail — reaching the end IS
    // completion. A pass mark on a course with no questions is meaningless, so it
    // is ignored rather than failing everyone at 0%.
    $passMark = $course['pass_mark'] === null ? null : (int)$course['pass_mark'];
    if ($total === 0 || $passMark === null) {
        $status = 'completed';
    } else {
        $status = $score >= $passMark ? 'passed' : 'failed';
    }

    if ($score !== null) {
        lmsWriteCmi($conn, $progressId, 'cmi.score.raw', (string)$score);
        lmsWriteCmi($conn, $progressId, 'cmi.score.min', '0');
        lmsWriteCmi($conn, $progressId, 'cmi.score.max', '100');
    }

    $upd = $conn->prepare("UPDATE lms_progress
                           SET status = ?, score_raw = ?, score_min = ?, score_max = ?, total_time = ?,
                               completion_datetime = COALESCE(completion_datetime, UTC_TIMESTAMP()),
                               last_access = UTC_TIMESTAMP(), updated_datetime = UTC_TIMESTAMP()
                           WHERE id = ?");
    $upd->execute([$status, $score, $score === null ? null : 0, $score === null ? null : 100, $totalTime, $progressId]);

    $conn->commit();

    echo json_encode([
        'success'  => true,
        'status'   => $status,
        'score'    => $score,
        'correct'  => $correctCount,
        'total'    => $total,
        'pass_mark'=> $passMark,
        'review'   => $review,
    ]);
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
