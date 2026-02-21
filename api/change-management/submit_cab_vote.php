<?php
/**
 * API Endpoint: Submit a CAB vote
 * Auto-updates change status when approval/rejection threshold is met.
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

$changeId = (int)($input['change_id'] ?? 0);
$vote = $input['vote'] ?? '';
$voteComment = trim($input['vote_comment'] ?? '');

if (!$changeId || !in_array($vote, ['Approve', 'Reject', 'Abstain'])) {
    echo json_encode(['success' => false, 'error' => 'Change ID and valid vote (Approve/Reject/Abstain) required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Verify current user is a CAB member for this change and hasn't voted yet
    $memberStmt = $conn->prepare("SELECT id, vote FROM change_cab_members WHERE change_id = ? AND analyst_id = ?");
    $memberStmt->execute([$changeId, $analystId]);
    $membership = $memberStmt->fetch(PDO::FETCH_ASSOC);

    if (!$membership) {
        echo json_encode(['success' => false, 'error' => 'You are not a CAB member for this change']);
        exit;
    }

    if ($membership['vote'] !== null) {
        echo json_encode(['success' => false, 'error' => 'You have already voted on this change']);
        exit;
    }

    // Record the vote
    $voteSql = "UPDATE change_cab_members SET vote = ?, vote_comment = ?, vote_datetime = UTC_TIMESTAMP()
                WHERE change_id = ? AND analyst_id = ?";
    $voteStmt = $conn->prepare($voteSql);
    $voteStmt->execute([$vote, $voteComment ?: null, $changeId, $analystId]);

    // Get analyst name for audit
    $nameStmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
    $nameStmt->execute([$analystId]);
    $analystName = $nameStmt->fetchColumn() ?: 'Unknown';

    // Audit log the vote
    $auditDisplay = "$vote by $analystName";
    if ($voteComment) {
        $preview = mb_strlen($voteComment) > 80 ? mb_substr($voteComment, 0, 80) . '...' : $voteComment;
        $auditDisplay .= ": $preview";
    }
    $auditSql = "INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, new_value, created_datetime)
                 VALUES (?, ?, 'cab_vote', 'CAB Vote', ?, UTC_TIMESTAMP())";
    $auditStmt = $conn->prepare($auditSql);
    $auditStmt->execute([$changeId, $analystId, $auditDisplay]);

    // Auto-status update logic
    $statusChanged = false;
    $newStatus = null;

    $changeStmt = $conn->prepare("SELECT cab_approval_type, status FROM changes WHERE id = ?");
    $changeStmt->execute([$changeId]);
    $changeRow = $changeStmt->fetch(PDO::FETCH_ASSOC);

    if ($changeRow && $changeRow['status'] === 'Pending Approval') {
        $approvalType = $changeRow['cab_approval_type'] ?: 'all';

        // Get all required members' votes
        $reqStmt = $conn->prepare("SELECT vote FROM change_cab_members WHERE change_id = ? AND is_required = 1");
        $reqStmt->execute([$changeId]);
        $reqVotes = $reqStmt->fetchAll(PDO::FETCH_COLUMN);

        $totalRequired = count($reqVotes);
        $approved = count(array_filter($reqVotes, fn($v) => $v === 'Approve'));
        $rejected = count(array_filter($reqVotes, fn($v) => $v === 'Reject'));

        // Check for rejection first (applies to both approval types)
        if ($rejected > 0) {
            $updateSql = "UPDATE changes SET status = 'Draft', modified_datetime = UTC_TIMESTAMP() WHERE id = ?";
            $conn->prepare($updateSql)->execute([$changeId]);

            $statusAudit = "INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
                            VALUES (?, ?, 'status_change', 'Status', 'Pending Approval', 'Draft', UTC_TIMESTAMP())";
            $conn->prepare($statusAudit)->execute([$changeId, $analystId]);

            $statusChanged = true;
            $newStatus = 'Draft';
        }
        // Check for approval
        elseif ($totalRequired > 0) {
            $thresholdMet = false;

            if ($approvalType === 'all') {
                $thresholdMet = ($approved === $totalRequired);
            } elseif ($approvalType === 'majority') {
                $thresholdMet = ($approved > $totalRequired / 2);
            }

            if ($thresholdMet) {
                $updateSql = "UPDATE changes SET status = 'Approved', approval_datetime = UTC_TIMESTAMP(), modified_datetime = UTC_TIMESTAMP() WHERE id = ?";
                $conn->prepare($updateSql)->execute([$changeId]);

                $statusAudit = "INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
                                VALUES (?, ?, 'status_change', 'Status', 'Pending Approval', 'Approved', UTC_TIMESTAMP())";
                $conn->prepare($statusAudit)->execute([$changeId, $analystId]);

                $statusChanged = true;
                $newStatus = 'Approved';
            }
        }
    }

    echo json_encode([
        'success' => true,
        'vote' => $vote,
        'status_changed' => $statusChanged,
        'new_status' => $newStatus
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
