<?php
/**
 * LMS API: a native course as the LEARNER sees it.
 *
 * This is the one place course content is handed to a player, and it exists
 * separately from lessons.php for a single reason: **it never sends the answer
 * key**. The SELECT below does not read is_correct at all, so there is no
 * filtering step to forget and nothing to strip — a learner cannot read the
 * answers out of the network tab because the server never puts them on the wire.
 * Grading happens in native_progress.php, against the database.
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

$conn = connectToDatabase();

try {
    $courseId = (int)($_GET['course_id'] ?? 0);
    if (!$courseId) throw new Exception('Missing course_id');
    // A learner may only load content for a course assigned to them; managers
    // may load any (Preview). Same rule as the player.
    requireLmsCourseAccessJson($conn, $courseId);

    $stmt = $conn->prepare("SELECT id, title, description, content_type, pass_mark FROM lms_courses WHERE id = ? AND is_active = 1");
    $stmt->execute([$courseId]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course)                            throw new Exception('Course not found');
    if ($course['content_type'] !== 'native') throw new Exception('Not an authored course');
    $course['pass_mark'] = $course['pass_mark'] === null ? null : (int)$course['pass_mark'];

    $ls = $conn->prepare("SELECT id, title, body FROM lms_lessons WHERE course_id = ? ORDER BY display_order, id");
    $ls->execute([$courseId]);
    $lessons = $ls->fetchAll(PDO::FETCH_ASSOC);

    if ($lessons) {
        $ids = array_column($lessons, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));

        $qs = $conn->prepare("SELECT id, lesson_id, question_text, question_type
                              FROM lms_questions WHERE lesson_id IN ($in) ORDER BY display_order, id");
        $qs->execute($ids);
        $questions = $qs->fetchAll(PDO::FETCH_ASSOC);

        $answersByQ = [];
        if ($questions) {
            $qids = array_column($questions, 'id');
            $qin  = implode(',', array_fill(0, count($qids), '?'));
            // NOTE: is_correct is deliberately absent from this SELECT.
            $as = $conn->prepare("SELECT id, question_id, answer_text
                                  FROM lms_answers WHERE question_id IN ($qin) ORDER BY display_order, id");
            $as->execute($qids);
            foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $answersByQ[$a['question_id']][] = $a;
            }
        }

        $byLesson = [];
        foreach ($questions as $q) {
            $q['answers'] = $answersByQ[$q['id']] ?? [];
            $byLesson[$q['lesson_id']][] = $q;
        }
        foreach ($lessons as &$l) {
            $l['questions'] = $byLesson[$l['id']] ?? [];
        }
        unset($l);
    }

    // Where the learner got to last time, so the player can offer to resume.
    $pr = $conn->prepare("SELECT status, bookmark, score_raw, attempt_count FROM lms_progress WHERE analyst_id = ? AND course_id = ?");
    $pr->execute([$_SESSION['analyst_id'], $courseId]);
    $progress = $pr->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode(['success' => true, 'course' => $course, 'lessons' => $lessons, 'progress' => $progress]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
