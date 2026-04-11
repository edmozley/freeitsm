<?php
/**
 * LMS API: Get all CMI data for a specific analyst + course
 * Returns structured data grouped by category for display.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int)($_GET['analyst_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);

if (!$analystId || !$courseId) {
    echo json_encode(['success' => false, 'error' => 'Missing analyst_id or course_id']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get progress record
    $stmt = $conn->prepare("SELECT p.*, a.full_name as analyst_name, c.title as course_title
                            FROM lms_progress p
                            JOIN analysts a ON p.analyst_id = a.id
                            JOIN lms_courses c ON p.course_id = c.id
                            WHERE p.analyst_id = ? AND p.course_id = ?");
    $stmt->execute([$analystId, $courseId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$progress) {
        echo json_encode(['success' => false, 'error' => 'No progress record found']);
        exit;
    }

    // Get all CMI data
    $cmiStmt = $conn->prepare("SELECT element, value, updated_datetime FROM lms_cmi_data WHERE progress_id = ? ORDER BY element");
    $cmiStmt->execute([$progress['id']]);
    $rawData = $cmiStmt->fetchAll(PDO::FETCH_ASSOC);

    // Categorise the data
    $interactions = [];
    $objectives = [];
    $scores = [];
    $general = [];
    $suspendData = null;

    foreach ($rawData as $row) {
        $el = $row['element'];
        $val = $row['value'];

        // Parse interactions: cmi.interactions.N.xxx or cmi.core.interactions.N.xxx
        if (preg_match('/^cmi\.(?:core\.)?interactions\.(\d+)\.(.+)$/', $el, $m)) {
            $idx = (int)$m[1];
            $field = $m[2];
            if (!isset($interactions[$idx])) $interactions[$idx] = [];
            $interactions[$idx][$field] = $val;
            continue;
        }

        // Parse objectives: cmi.objectives.N.xxx
        if (preg_match('/^cmi\.objectives\.(\d+)\.(.+)$/', $el, $m)) {
            $idx = (int)$m[1];
            $field = $m[2];
            if (!isset($objectives[$idx])) $objectives[$idx] = [];
            $objectives[$idx][$field] = $val;
            continue;
        }

        // Score-related
        if (preg_match('/score/i', $el)) {
            $scores[] = ['element' => $el, 'value' => $val];
            continue;
        }

        // Suspend data
        if ($el === 'cmi.suspend_data') {
            $suspendData = $val;
            continue;
        }

        // Everything else
        $general[] = ['element' => $el, 'value' => $val, 'updated' => $row['updated_datetime']];
    }

    // Try to decode suspend_data if it's JSON
    $suspendDecoded = null;
    if ($suspendData) {
        $decoded = json_decode($suspendData, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $suspendDecoded = $decoded;
        }
    }

    echo json_encode([
        'success' => true,
        'progress' => [
            'analyst_name' => $progress['analyst_name'],
            'course_title' => $progress['course_title'],
            'status' => $progress['status'],
            'score_raw' => $progress['score_raw'],
            'score_min' => $progress['score_min'],
            'score_max' => $progress['score_max'],
            'total_time' => $progress['total_time'],
            'attempt_count' => $progress['attempt_count'],
            'first_access' => $progress['first_access'],
            'last_access' => $progress['last_access'],
            'completion_datetime' => $progress['completion_datetime'],
            'bookmark' => $progress['bookmark'],
        ],
        'interactions' => array_values($interactions),
        'objectives' => array_values($objectives),
        'scores' => $scores,
        'suspend_data_raw' => $suspendData,
        'suspend_data_decoded' => $suspendDecoded,
        'general' => $general,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
