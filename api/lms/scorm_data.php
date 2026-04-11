<?php
/**
 * LMS API: SCORM runtime data endpoint
 *
 * GET  ?course_id=N  — Load all CMI data for the current analyst + course (called on Initialize)
 * POST               — Save CMI data elements (called on Commit/Finish)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = $_SESSION['analyst_id'];
$conn = connectToDatabase();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $courseId = (int)($_GET['course_id'] ?? 0);
    if (!$courseId) {
        echo json_encode(['success' => false, 'error' => 'Missing course_id']);
        exit;
    }

    // Get or create progress record
    $stmt = $conn->prepare("SELECT id, status, bookmark, suspend_data, total_time, attempt_count FROM lms_progress WHERE analyst_id = ? AND course_id = ?");
    $stmt->execute([$analystId, $courseId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$progress) {
        // First access — create progress record
        $conn->prepare("INSERT INTO lms_progress (analyst_id, course_id, status, first_access, last_access, attempt_count) VALUES (?, ?, 'incomplete', UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1)")
            ->execute([$analystId, $courseId]);
        $progressId = (int)$conn->lastInsertId();
        echo json_encode(['success' => true, 'data' => [], 'progress_id' => $progressId]);
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

try {
    // Get progress record
    $stmt = $conn->prepare("SELECT id FROM lms_progress WHERE analyst_id = ? AND course_id = ?");
    $stmt->execute([$analystId, $courseId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$progress) {
        echo json_encode(['success' => false, 'error' => 'No progress record found']);
        exit;
    }

    $progressId = (int)$progress['id'];

    $conn->beginTransaction();

    // Upsert each CMI element
    $upsert = $conn->prepare("INSERT INTO lms_cmi_data (progress_id, element, value, updated_datetime)
                               VALUES (?, ?, ?, UTC_TIMESTAMP())
                               ON DUPLICATE KEY UPDATE value = VALUES(value), updated_datetime = UTC_TIMESTAMP()");

    foreach ($elements as $el) {
        $upsert->execute([$progressId, $el['element'], $el['value']]);
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
