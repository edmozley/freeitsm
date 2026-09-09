<?php
/**
 * LMS API: SCORM runtime data endpoint
 *
 * GET  ?course_id=N  — Load all CMI data for the current analyst + course (called on Initialize)
 * POST               — Save CMI data elements (called on Commit/Finish)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/lms_access.php';
header('Content-Type: application/json');

// Either front door — a SCORM package plays the same for a portal learner as for
// an analyst. The module gate is an analyst-app concept and applies only to them;
// requireLmsCourseAccessJson() below is what entitles everybody.
$learner = LmsLearner::fromSession();
if (!$learner) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
if ($learner->isAnalyst()) {
    requireModuleAccessJson('lms');
}

$conn = connectToDatabase();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $courseId = (int)($_GET['course_id'] ?? 0);
    if (!$courseId) {
        echo json_encode(['success' => false, 'error' => 'Missing course_id']);
        exit;
    }
    // A learner may only run a course assigned to them; managers may run any.
    requireLmsCourseAccessJson($conn, $courseId);

    // Get or create progress record. The find-or-create is shared with the
    // native player (includes/lms_access.php) rather than written twice.
    $existed  = true;
    $progress = $conn->prepare("SELECT id, status, bookmark, suspend_data, total_time, attempt_count
                                  FROM lms_progress WHERE learner_type = ? AND learner_id = ? AND course_id = ?");
    $progress->execute([$learner->type(), $learner->id(), $courseId]);
    $progress = $progress->fetch(PDO::FETCH_ASSOC);

    if (!$progress) {
        $existed  = false;
        $created  = lmsProgressRowFor($conn, $learner, $courseId);
        $progress = ['id' => (int)($created['id'] ?? 0), 'status' => $created['status'] ?? 'incomplete'];
    }

    if (!$existed) {
        // First access: no CMI data to replay, but the attempt still counts —
        // the shared helper inserts with attempt_count 0, and the increment
        // below is skipped by this early return, so do it here.
        $conn->prepare("UPDATE lms_progress SET attempt_count = attempt_count + 1, last_access = UTC_TIMESTAMP(), updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$progress['id']]);
        echo json_encode(['success' => true, 'data' => [], 'progress_id' => (int)$progress['id']]);
        exit;
    }

    // Increment attempt count and update last access
    $conn->prepare("UPDATE lms_progress SET attempt_count = attempt_count + 1, last_access = UTC_TIMESTAMP(), updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
        ->execute([$progress['id']]);

    // Load all CMI data
    $cmiStmt = $conn->prepare("SELECT element, value FROM lms_cmi_data WHERE progress_id = ?");
    $cmiStmt->execute([$progress['id']]);
    $cmiData = [];
    while ($row = $cmiStmt->fetch(PDO::FETCH_ASSOC)) {
        $cmiData[$row['element']] = $row['value'];
    }

    echo json_encode([
        'success' => true,
        'data' => $cmiData,
        'progress_id' => (int)$progress['id'],
        'status' => $progress['status']
    ]);
    exit;
}

// POST: save CMI data
$input = json_decode(file_get_contents('php://input'), true);
$courseId = (int)($input['course_id'] ?? 0);
$elements = $input['elements'] ?? [];

if (!$courseId) {
    echo json_encode(['success' => false, 'error' => 'Missing course_id']);
    exit;
}
requireLmsCourseAccessJson($conn, $courseId);

try {
    // Get progress record
    $stmt = $conn->prepare("SELECT id FROM lms_progress WHERE learner_type = ? AND learner_id = ? AND course_id = ?");
    $stmt->execute([$learner->type(), $learner->id(), $courseId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$progress) {
        echo json_encode(['success' => false, 'error' => 'No progress record found']);
        exit;
    }

    $progressId = (int)$progress['id'];

    $conn->beginTransaction();

    // Upsert each CMI element (check-then-insert/update to handle missing unique key)
    $checkStmt = $conn->prepare("SELECT id FROM lms_cmi_data WHERE progress_id = ? AND element = ? LIMIT 1");
    $updateStmt = $conn->prepare("UPDATE lms_cmi_data SET value = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
    $insertStmt = $conn->prepare("INSERT INTO lms_cmi_data (progress_id, element, value, updated_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())");

    foreach ($elements as $el) {
        $checkStmt->execute([$progressId, $el['element']]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $updateStmt->execute([$el['value'], $existing['id']]);
        } else {
            $insertStmt->execute([$progressId, $el['element'], $el['value']]);
        }
    }

    // Denormalize key fields into lms_progress
    $statusFields = [];
    foreach ($elements as $el) {
        $key = $el['element'];
        $val = $el['value'];

        // SCORM 1.2 mappings
        if ($key === 'cmi.core.lesson_status') {
            $statusFields['status'] = mapScormStatus($val);
        }
        if ($key === 'cmi.core.score.raw') $statusFields['score_raw'] = $val;
        if ($key === 'cmi.core.score.min') $statusFields['score_min'] = $val;
        if ($key === 'cmi.core.score.max') $statusFields['score_max'] = $val;
        if ($key === 'cmi.core.lesson_location') $statusFields['bookmark'] = $val;
        if ($key === 'cmi.suspend_data') $statusFields['suspend_data'] = $val;
        if ($key === 'cmi.core.total_time') $statusFields['total_time'] = $val;

        // SCORM 2004 mappings
        if ($key === 'cmi.completion_status') {
            $statusFields['status'] = mapScormStatus($val);
        }
        if ($key === 'cmi.success_status') {
            $mapped = mapScormStatus($val);
            if ($mapped === 'passed' || $mapped === 'failed') {
                $statusFields['status'] = $mapped;
            }
        }
        if ($key === 'cmi.score.raw') $statusFields['score_raw'] = $val;
        if ($key === 'cmi.score.min') $statusFields['score_min'] = $val;
        if ($key === 'cmi.score.max') $statusFields['score_max'] = $val;
        if ($key === 'cmi.location') $statusFields['bookmark'] = $val;
        if ($key === 'cmi.total_time') $statusFields['total_time'] = $val;
    }

    if (!empty($statusFields)) {
        $sets = [];
        $params = [];
        foreach ($statusFields as $col => $val) {
            $sets[] = "$col = ?";
            $params[] = $val;
        }
        $sets[] = "last_access = UTC_TIMESTAMP()";
        $sets[] = "updated_datetime = UTC_TIMESTAMP()";

        // Set completion_datetime when status becomes completed or passed
        if (isset($statusFields['status']) && in_array($statusFields['status'], ['completed', 'passed'])) {
            $sets[] = "completion_datetime = COALESCE(completion_datetime, UTC_TIMESTAMP())";
        }

        $params[] = $progressId;
        $conn->prepare("UPDATE lms_progress SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    } else {
        $conn->prepare("UPDATE lms_progress SET last_access = UTC_TIMESTAMP(), updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$progressId]);
    }

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Map SCORM status values to our normalised status
 */
function mapScormStatus(string $val): string {
    $val = strtolower(trim($val));
    $map = [
        'passed' => 'passed',
        'failed' => 'failed',
        'completed' => 'completed',
        'incomplete' => 'incomplete',
        'not attempted' => 'not_started',
        'unknown' => 'not_started',
        'browsed' => 'incomplete',
    ];
    return $map[$val] ?? 'incomplete';
}
