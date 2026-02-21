<?php
/**
 * API Endpoint: List changes with optional filters, or return analysts list
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // If requesting analysts for dropdowns
    if (isset($_GET['analysts'])) {
        $sql = "SELECT id, full_name as name FROM analysts WHERE is_active = 1 ORDER BY full_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $analysts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'analysts' => $analysts]);
        exit;
    }

    // Build changes query
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';

    $sql = "SELECT
                c.id,
                c.title,
                c.change_type,
                c.status,
                c.priority,
                c.impact,
                c.category,
                c.work_start_datetime,
                c.work_end_datetime,
                c.risk_score,
                c.risk_level,
                c.created_datetime,
                c.modified_datetime,
                assigned.full_name as assigned_to_name,
                requester.full_name as requester_name
            FROM changes c
            LEFT JOIN analysts assigned ON c.assigned_to_id = assigned.id
            LEFT JOIN analysts requester ON c.requester_id = requester.id
            WHERE 1=1";

    $params = [];

    if ($status) {
        $sql .= " AND c.status = ?";
        $params[] = $status;
    }

    if ($search) {
        $sql .= " AND (c.title LIKE ? OR CAST(c.id AS CHAR) LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $sql .= " ORDER BY c.modified_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get counts per status
    $countsSql = "SELECT status, COUNT(*) as cnt FROM changes GROUP BY status";
    $countsStmt = $conn->prepare($countsSql);
    $countsStmt->execute();
    $countsRaw = $countsStmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = ['total' => 0];
    foreach ($countsRaw as $row) {
        $counts[$row['status']] = (int)$row['cnt'];
        $counts['total'] += (int)$row['cnt'];
    }

    echo json_encode([
        'success' => true,
        'changes' => $changes,
        'counts' => $counts
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
