<?php
/**
 * LMS API: save/delete a question and its answers (author-side).
 *
 * A question and its options are saved as one unit — the answers are deleted and
 * re-inserted inside a transaction rather than diffed. That means answer ids are
 * not stable across an edit, which is fine: nothing references them once a
 * learner's response has been recorded (responses are stored as TEXT in
 * lms_cmi_data, deliberately, so an edit can never rewrite history).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('lms');

const LMS_QUESTION_TYPES = ['single', 'multiple', 'truefalse'];

$conn  = connectToDatabase();
$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    if (($input['_method'] ?? '') === 'DELETE') {
        $stmt = $conn->prepare("DELETE FROM lms_questions WHERE id = ?");   // answers cascade
        $stmt->execute([(int)($input['id'] ?? 0)]);
        echo json_encode(['success' => true]);
        exit;
    }

    $id       = (int)($input['id'] ?? 0);
    $lessonId = (int)($input['lesson_id'] ?? 0);
    $text     = trim($input['question_text'] ?? '');
    $type     = $input['question_type'] ?? 'single';
    $expl     = trim($input['explanation'] ?? '');
    $answers  = $input['answers'] ?? [];

    if (!$lessonId)                             throw new Exception('Missing lesson_id');
    if ($text === '')                           throw new Exception('The question is required');
    if (!in_array($type, LMS_QUESTION_TYPES))   throw new Exception('Unknown question type');
    if (!is_array($answers) || count($answers) < 2) throw new Exception('A question needs at least two answers');

    // Validate the key BEFORE writing anything. A question with no correct answer
    // is unanswerable and would silently mark every learner wrong; a 'single'
    // question with two correct answers is a contradiction. Both are author
    // mistakes worth refusing at the door rather than discovering in the results.
    $correct = 0;
    foreach ($answers as $a) {
        if (trim($a['answer_text'] ?? '') === '') throw new Exception('Every answer needs some text');
        if (!empty($a['is_correct'])) $correct++;
    }
    if ($correct === 0) throw new Exception('Mark at least one answer as correct');
    if ($type !== 'multiple' && $correct > 1) {
        throw new Exception('This question type allows only one correct answer — use "Choose several" for more.');
    }

    $conn->beginTransaction();

    if ($id) {
        $stmt = $conn->prepare("UPDATE lms_questions SET question_text = ?, question_type = ?, explanation = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ? AND lesson_id = ?");
        $stmt->execute([$text, $type, $expl, $id, $lessonId]);
        $conn->prepare("DELETE FROM lms_answers WHERE question_id = ?")->execute([$id]);
    } else {
        $next = $conn->prepare("SELECT COALESCE(MAX(display_order), -1) + 1 FROM lms_questions WHERE lesson_id = ?");
        $next->execute([$lessonId]);
        $order = (int)$next->fetchColumn();

        $stmt = $conn->prepare("INSERT INTO lms_questions (lesson_id, question_text, question_type, explanation, display_order, created_datetime, updated_datetime)
                                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $stmt->execute([$lessonId, $text, $type, $expl, $order]);
        $id = (int)$conn->lastInsertId();
    }

    $ins = $conn->prepare("INSERT INTO lms_answers (question_id, answer_text, is_correct, display_order, created_datetime)
                           VALUES (?, ?, ?, ?, UTC_TIMESTAMP())");
    foreach (array_values($answers) as $i => $a) {
        $ins->execute([$id, trim($a['answer_text']), !empty($a['is_correct']) ? 1 : 0, $i]);
    }

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
