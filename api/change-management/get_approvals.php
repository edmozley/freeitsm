<?php
/**
 * API Endpoint: Get changes pending approval
 * Filters: all, requested (by me), assigned (to me as approver), cab (my CAB reviews)
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
$filter = $_GET['filter'] ?? 'all';

try {
    $conn = connectToDatabase();

    // Base query for Pending Approval changes
    if ($filter === 'cab') {
        // CAB filter: changes where current user is a CAB member with pending vote
        $sql = "SELECT
                    c.id,
                    c.title,
                    c.change_type,
                    c.status,
                    c.priority,
                    c.impact,
                    c.cab_required,
                    c.work_start_datetime,
                    c.created_datetime,
                    assigned.full_name as assigned_to_name,
                    requester.full_name as requester_name,
                    approver.full_name as approver_name
                FROM changes c
                INNER JOIN change_cab_members m ON m.change_id = c.id AND m.analyst_id = ? AND m.vote IS NULL
                LEFT JOIN analysts assigned ON c.assigned_to_id = assigned.id
                LEFT JOIN analysts requester ON c.requester_id = requester.id
                LEFT JOIN analysts approver ON c.approver_id = approver.id
                WHERE c.status = 'Pending Approval'
                ORDER BY c.created_datetime DESC";
        $params = [$analystId];
    } else {
        $sql = "SELECT
                    c.id,
                    c.title,
                    c.change_type,
                    c.status,
                    c.priority,
                    c.impact,
                    c.cab_required,
                    c.work_start_datetime,
                    c.created_datetime,
                    assigned.full_name as assigned_to_name,
                    requester.full_name as requester_name,
                    approver.full_name as approver_name
                FROM changes c
                LEFT JOIN analysts assigned ON c.assigned_to_id = assigned.id
                LEFT JOIN analysts requester ON c.requester_id = requester.id
                LEFT JOIN analysts approver ON c.approver_id = approver.id
                WHERE c.status = 'Pending Approval'";

        $params = [];

        if ($filter === 'requested') {
            $sql .= " AND c.requester_id = ?";
            $params[] = $analystId;
        } elseif ($filter === 'assigned') {
            $sql .= " AND c.approver_id = ?";
            $params[] = $analystId;
        }

        $sql .= " ORDER BY c.created_datetime DESC";
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich changes that have CAB with progress info
    foreach ($changes as &$ch) {
        if ($ch['cab_required']) {
            $progSql = "SELECT
                            COUNT(CASE WHEN is_required = 1 THEN 1 END) as required_total,
                            COUNT(CASE WHEN is_required = 1 AND vote = 'Approve' THEN 1 END) as required_approved
                        FROM change_cab_members WHERE change_id = ?";
            $progStmt = $conn->prepare($progSql);
            $progStmt->execute([$ch['id']]);
            $ch['cab_progress'] = $progStmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    unset($ch);

    // Get counts for all filters
    $countSql = "SELECT
                    COUNT(*) as cnt_all,
                    SUM(CASE WHEN requester_id = ? THEN 1 ELSE 0 END) as cnt_requested,
                    SUM(CASE WHEN approver_id = ? THEN 1 ELSE 0 END) as cnt_assigned
                 FROM changes WHERE status = 'Pending Approval'";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute([$analystId, $analystId]);
    $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);

    // CAB count: changes where current user is a CAB member with pending vote
    $cabCountSql = "SELECT COUNT(*) FROM change_cab_members m
                    INNER JOIN changes c ON c.id = m.change_id
                    WHERE m.analyst_id = ? AND m.vote IS NULL AND c.status = 'Pending Approval'";
    $cabCountStmt = $conn->prepare($cabCountSql);
    $cabCountStmt->execute([$analystId]);
    $cabCount = (int)$cabCountStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'changes' => $changes,
        'counts' => [
            'all'       => (int)($countRow['cnt_all'] ?? 0),
            'requested' => (int)($countRow['cnt_requested'] ?? 0),
            'assigned'  => (int)($countRow['cnt_assigned'] ?? 0),
            'cab'       => $cabCount
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
