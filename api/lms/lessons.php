<?php
/**
 * LMS API: lessons of a native course — the AUTHOR's view.
 *
 * GET returns lessons with their questions and answers INCLUDING the answer key,
 * because an author needs to see and edit it. The learner's view is a different
 * endpoint (course_content.php) which strips it. Keeping the two apart is what
 * stops the key leaking: there is no flag to get wrong, just two endpoints with
 * different jobs, and only this one is behind the module write guard.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson('lms.manage');

$conn   = connectToDatabase();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) throw new Exception('Missing course_id');

        $stmt = $conn->prepare("SELECT id, course_id, title, body, display_order FROM lms_lessons WHERE course_id = ? ORDER BY display_order, id");
        $stmt->execute([$courseId]);
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($lessons) {
            $ids = array_column($lessons, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));

            $qs = $conn->prepare("SELECT id, lesson_id, question_text, question_type, explanation, display_order
                                  FROM lms_questions WHERE lesson_id IN ($in) ORDER BY display_order, id");
            $qs->execute($ids);
            $questions = $qs->fetchAll(PDO::FETCH_ASSOC);

            $answersByQ = [];
            if ($questions) {
                $qids = array_column($questions, 'id');
                $qin  = implode(',', array_fill(0, count($qids), '?'));
                $as   = $conn->prepare("SELECT id, question_id, answer_text, is_correct, display_order
                                        FROM lms_answers WHERE question_id IN ($qin) ORDER BY display_order, id");
                $as->execute($qids);
                foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $a['is_correct'] = (int)$a['is_correct'];
                    $answersByQ[$a['question_id']][] = $a;
                }
            }

            $questionsByLesson = [];
            foreach ($questions as $q) {
                $q['answers'] = $answersByQ[$q['id']] ?? [];
                $questionsByLesson[$q['lesson_id']][] = $q;
            }
            foreach ($lessons as &$l) {
                $l['questions'] = $questionsByLesson[$l['id']] ?? [];
            }
            unset($l);
        }

        echo json_encode(['success' => true, 'data' => $lessons]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $verb  = $input['_method'] ?? 'SAVE';

    if ($verb === 'DELETE') {
        // Questions and answers go with it, by FK cascade.
        $stmt = $conn->prepare("DELETE FROM lms_lessons WHERE id = ?");
        $stmt->execute([(int)($input['id'] ?? 0)]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($verb === 'REORDER') {
        // The client sends the ids in their new visual order; position IS the index.
        $ids = $input['ids'] ?? [];
        if (!is_array($ids)) throw new Exception('ids must be an array');
        $conn->beginTransaction();
        $stmt = $conn->prepare("UPDATE lms_lessons SET display_order = ? WHERE id = ? AND course_id = ?");
        foreach (array_values($ids) as $i => $lessonId) {
            $stmt->execute([$i, (int)$lessonId, (int)($input['course_id'] ?? 0)]);
        }
        $conn->commit();
        echo json_encode(['success' => true]);
        exit;
    }

    // SAVE — create or update one lesson (body only; questions have their own endpoint).
    $id       = (int)($input['id'] ?? 0);
    $courseId = (int)($input['course_id'] ?? 0);
    $title    = trim($input['title'] ?? '');
    $body     = $input['body'] ?? '';
    if ($title === '')  throw new Exception('Lesson title is required');
    if (!$courseId)     throw new Exception('Missing course_id');

    if ($id) {
        $stmt = $conn->prepare("UPDATE lms_lessons SET title = ?, body = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ? AND course_id = ?");
        $stmt->execute([$title, $body, $id, $courseId]);
    } else {
        // New lessons land at the end.
        $next = $conn->prepare("SELECT COALESCE(MAX(display_order), -1) + 1 FROM lms_lessons WHERE course_id = ?");
        $next->execute([$courseId]);
        $order = (int)$next->fetchColumn();

        $stmt = $conn->prepare("INSERT INTO lms_lessons (course_id, title, body, display_order, created_datetime, updated_datetime)
                                VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $stmt->execute([$courseId, $title, $body, $order]);
        $id = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
