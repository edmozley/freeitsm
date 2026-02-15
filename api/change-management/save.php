<?php
/**
 * API Endpoint: Create or update a change record
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int)$_SESSION['analyst_id'];

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$changeId = !empty($input['id']) ? (int)$input['id'] : null;
$title = trim($input['title'] ?? '');

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

// Helper to coerce empty/null values for ODBC compatibility
function nullInt($val) {
    return (isset($val) && $val !== '' && $val !== null) ? (int)$val : null;
}
function nullStr($val) {
    return (isset($val) && $val !== '' && $val !== null) ? trim($val) : null;
}
function nullDatetime($val) {
    if (!isset($val) || $val === '' || $val === null) return null;
    $val = str_replace('T', ' ', $val);
    // Ensure seconds are present for ODBC driver compatibility
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $val)) {
        $val .= ':00';
    }
    return $val;
}
function nullText($val) {
    if (!isset($val) || $val === null) return null;
    // Strip empty TinyMCE content
    $stripped = trim(strip_tags($val));
    return ($stripped === '' || $stripped === '&nbsp;') ? null : $val;
}

$changeType = $input['change_type'] ?? 'Normal';
$status = $input['status'] ?? 'Draft';
$priority = $input['priority'] ?? 'Medium';
$impact = $input['impact'] ?? 'Medium';
$category = nullStr($input['category'] ?? null);
$requesterId = nullInt($input['requester_id'] ?? null);
$assignedToId = nullInt($input['assigned_to_id'] ?? null);
$approverId = nullInt($input['approver_id'] ?? null);
$workStart = nullDatetime($input['work_start_datetime'] ?? null);
$workEnd = nullDatetime($input['work_end_datetime'] ?? null);
$outageStart = nullDatetime($input['outage_start_datetime'] ?? null);
$outageEnd = nullDatetime($input['outage_end_datetime'] ?? null);
$description = nullText($input['description'] ?? null);
$reasonForChange = nullText($input['reason_for_change'] ?? null);
$riskEvaluation = nullText($input['risk_evaluation'] ?? null);
$testPlan = nullText($input['test_plan'] ?? null);
$rollbackPlan = nullText($input['rollback_plan'] ?? null);
$postImplementationReview = nullText($input['post_implementation_review'] ?? null);

try {
    $conn = connectToDatabase();

    if ($changeId) {
        // Update existing change
        $sql = "UPDATE changes SET
                    title = ?,
                    change_type = ?,
                    status = ?,
                    priority = ?,
                    impact = ?,
                    category = ?,
                    requester_id = ?,
                    assigned_to_id = ?,
                    approver_id = ?,
                    work_start_datetime = ?,
                    work_end_datetime = ?,
                    outage_start_datetime = ?,
                    outage_end_datetime = ?,
                    description = ?,
                    reason_for_change = ?,
                    risk_evaluation = ?,
                    test_plan = ?,
                    rollback_plan = ?,
                    post_implementation_review = ?,
                    modified_datetime = GETUTCDATE()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $title, $changeType, $status, $priority, $impact, $category,
            $requesterId, $assignedToId, $approverId,
            $workStart, $workEnd, $outageStart, $outageEnd,
            $description, $reasonForChange, $riskEvaluation,
            $testPlan, $rollbackPlan, $postImplementationReview,
            $changeId
        ]);
    } else {
        // Create new change
        $sql = "INSERT INTO changes (
                    title, change_type, status, priority, impact, category,
                    requester_id, assigned_to_id, approver_id,
                    work_start_datetime, work_end_datetime,
                    outage_start_datetime, outage_end_datetime,
                    description, reason_for_change, risk_evaluation,
                    test_plan, rollback_plan, post_implementation_review,
                    created_by_id, created_datetime, modified_datetime
                )
                OUTPUT INSERTED.id
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE(), GETUTCDATE())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $title, $changeType, $status, $priority, $impact, $category,
            $requesterId, $assignedToId, $approverId,
            $workStart, $workEnd, $outageStart, $outageEnd,
            $description, $reasonForChange, $riskEvaluation,
            $testPlan, $rollbackPlan, $postImplementationReview,
            $analystId
        ]);
        $changeId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
    }

    echo json_encode([
        'success' => true,
        'change_id' => $changeId,
        'message' => 'Change saved successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
