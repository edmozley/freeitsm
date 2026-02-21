<?php
/**
 * API Endpoint: Create or update a change record
 * Includes server-side audit logging for all field changes.
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

// Helper to coerce empty/null values
function nullInt($val) {
    return (isset($val) && $val !== '' && $val !== null) ? (int)$val : null;
}
function nullTinyInt($val) {
    return (isset($val) && $val !== '' && $val !== null) ? (int)$val : null;
}
function nullStr($val) {
    return (isset($val) && $val !== '' && $val !== null) ? trim($val) : null;
}
function nullDatetime($val) {
    if (!isset($val) || $val === '' || $val === null) return null;
    $val = str_replace('T', ' ', $val);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $val)) {
        $val .= ':00';
    }
    return $val;
}
function nullText($val) {
    if (!isset($val) || $val === null) return null;
    $stripped = trim(strip_tags($val));
    return ($stripped === '' || $stripped === '&nbsp;') ? null : $val;
}

// Calculate risk level from score
function calculateRiskLevel($score) {
    if ($score === null) return null;
    if ($score <= 4) return 'Low';
    if ($score <= 9) return 'Medium';
    if ($score <= 15) return 'High';
    if ($score <= 20) return 'Very High';
    return 'Critical';
}

$changeType = $input['change_type'] ?? 'Normal';
$status = $input['status'] ?? 'Draft';
$priority = $input['priority'] ?? 'Medium';
$impact = $input['impact'] ?? 'Medium';
$category = nullStr($input['category'] ?? null);
$categoryId = nullInt($input['category_id'] ?? null);
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

// Risk scoring
$riskLikelihood = nullTinyInt($input['risk_likelihood'] ?? null);
$riskImpactScore = nullTinyInt($input['risk_impact_score'] ?? null);
$riskScore = ($riskLikelihood !== null && $riskImpactScore !== null) ? $riskLikelihood * $riskImpactScore : null;
$riskLevel = calculateRiskLevel($riskScore);

// PIR fields
$pirWasSuccessful = isset($input['pir_was_successful']) && $input['pir_was_successful'] !== '' ? (int)$input['pir_was_successful'] : null;
$pirActualStart = nullDatetime($input['pir_actual_start'] ?? null);
$pirActualEnd = nullDatetime($input['pir_actual_end'] ?? null);
$pirLessonsLearned = nullText($input['pir_lessons_learned'] ?? null);
$pirFollowUp = nullText($input['pir_follow_up'] ?? null);

try {
    $conn = connectToDatabase();

    // Audit: fields to track (field_name => [label, is_longtext])
    $auditFields = [
        'title'                     => ['Title', false],
        'change_type'               => ['Type', false],
        'status'                    => ['Status', false],
        'priority'                  => ['Priority', false],
        'impact'                    => ['Impact', false],
        'category'                  => ['Category', false],
        'requester_id'              => ['Requester', false],
        'assigned_to_id'            => ['Assigned To', false],
        'approver_id'               => ['Approver', false],
        'work_start_datetime'       => ['Work Start', false],
        'work_end_datetime'         => ['Work End', false],
        'outage_start_datetime'     => ['Outage Start', false],
        'outage_end_datetime'       => ['Outage End', false],
        'risk_likelihood'           => ['Risk Likelihood', false],
        'risk_impact_score'         => ['Risk Impact Score', false],
        'risk_level'                => ['Risk Level', false],
        'pir_was_successful'        => ['PIR Successful', false],
        'pir_actual_start'          => ['PIR Actual Start', false],
        'pir_actual_end'            => ['PIR Actual End', false],
    ];

    // New values map
    $newValues = [
        'title' => $title, 'change_type' => $changeType, 'status' => $status,
        'priority' => $priority, 'impact' => $impact, 'category' => $category,
        'category_id' => $categoryId,
        'requester_id' => $requesterId, 'assigned_to_id' => $assignedToId,
        'approver_id' => $approverId,
        'work_start_datetime' => $workStart, 'work_end_datetime' => $workEnd,
        'outage_start_datetime' => $outageStart, 'outage_end_datetime' => $outageEnd,
        'description' => $description, 'reason_for_change' => $reasonForChange,
        'risk_evaluation' => $riskEvaluation, 'test_plan' => $testPlan,
        'rollback_plan' => $rollbackPlan, 'post_implementation_review' => $postImplementationReview,
        'risk_likelihood' => $riskLikelihood, 'risk_impact_score' => $riskImpactScore,
        'risk_score' => $riskScore, 'risk_level' => $riskLevel,
        'pir_was_successful' => $pirWasSuccessful, 'pir_actual_start' => $pirActualStart,
        'pir_actual_end' => $pirActualEnd, 'pir_lessons_learned' => $pirLessonsLearned,
        'pir_follow_up' => $pirFollowUp,
    ];

    if ($changeId) {
        // Fetch existing record for audit comparison
        $oldStmt = $conn->prepare("SELECT * FROM changes WHERE id = ?");
        $oldStmt->execute([$changeId]);
        $oldRecord = $oldStmt->fetch(PDO::FETCH_ASSOC);

        // Update existing change
        $sql = "UPDATE changes SET
                    title = ?, change_type = ?, status = ?, priority = ?, impact = ?,
                    category = ?, category_id = ?,
                    requester_id = ?, assigned_to_id = ?, approver_id = ?,
                    work_start_datetime = ?, work_end_datetime = ?,
                    outage_start_datetime = ?, outage_end_datetime = ?,
                    description = ?, reason_for_change = ?, risk_evaluation = ?,
                    test_plan = ?, rollback_plan = ?, post_implementation_review = ?,
                    risk_likelihood = ?, risk_impact_score = ?, risk_score = ?, risk_level = ?,
                    pir_was_successful = ?, pir_actual_start = ?, pir_actual_end = ?,
                    pir_lessons_learned = ?, pir_follow_up = ?,
                    modified_datetime = UTC_TIMESTAMP()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $title, $changeType, $status, $priority, $impact,
            $category, $categoryId,
            $requesterId, $assignedToId, $approverId,
            $workStart, $workEnd, $outageStart, $outageEnd,
            $description, $reasonForChange, $riskEvaluation,
            $testPlan, $rollbackPlan, $postImplementationReview,
            $riskLikelihood, $riskImpactScore, $riskScore, $riskLevel,
            $pirWasSuccessful, $pirActualStart, $pirActualEnd,
            $pirLessonsLearned, $pirFollowUp,
            $changeId
        ]);

        // Server-side audit logging
        if ($oldRecord) {
            $auditSql = "INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
                         VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())";
            $auditStmt = $conn->prepare($auditSql);

            foreach ($auditFields as $field => $info) {
                $oldVal = $oldRecord[$field] ?? null;
                $newVal = $newValues[$field] ?? null;

                // Normalize for comparison
                $oldNorm = ($oldVal === null || $oldVal === '') ? null : (string)$oldVal;
                $newNorm = ($newVal === null || $newVal === '') ? null : (string)$newVal;

                if ($oldNorm !== $newNorm) {
                    $actionType = ($field === 'status') ? 'status_change' : 'field_change';
                    $oldDisplay = $oldNorm ?? '(empty)';
                    $newDisplay = $newNorm ?? '(empty)';

                    // Truncate long values for audit display
                    if (strlen($oldDisplay) > 200) $oldDisplay = substr($oldDisplay, 0, 200) . '...';
                    if (strlen($newDisplay) > 200) $newDisplay = substr($newDisplay, 0, 200) . '...';

                    $auditStmt->execute([
                        $changeId, $analystId, $actionType,
                        $info[0], $oldDisplay, $newDisplay
                    ]);
                }
            }
        }
    } else {
        // Create new change
        $sql = "INSERT INTO changes (
                    title, change_type, status, priority, impact,
                    category, category_id,
                    requester_id, assigned_to_id, approver_id,
                    work_start_datetime, work_end_datetime,
                    outage_start_datetime, outage_end_datetime,
                    description, reason_for_change, risk_evaluation,
                    test_plan, rollback_plan, post_implementation_review,
                    risk_likelihood, risk_impact_score, risk_score, risk_level,
                    pir_was_successful, pir_actual_start, pir_actual_end,
                    pir_lessons_learned, pir_follow_up,
                    created_by_id, created_datetime, modified_datetime
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $title, $changeType, $status, $priority, $impact,
            $category, $categoryId,
            $requesterId, $assignedToId, $approverId,
            $workStart, $workEnd, $outageStart, $outageEnd,
            $description, $reasonForChange, $riskEvaluation,
            $testPlan, $rollbackPlan, $postImplementationReview,
            $riskLikelihood, $riskImpactScore, $riskScore, $riskLevel,
            $pirWasSuccessful, $pirActualStart, $pirActualEnd,
            $pirLessonsLearned, $pirFollowUp,
            $analystId
        ]);
        $changeId = $conn->lastInsertId();

        // Log creation in audit
        $auditSql = "INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, new_value, created_datetime)
                     VALUES (?, ?, 'status_change', 'Status', 'Created as Draft', UTC_TIMESTAMP())";
        $auditStmt = $conn->prepare($auditSql);
        $auditStmt->execute([$changeId, $analystId]);
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
