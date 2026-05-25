<?php
/**
 * API Endpoint: Get system logs
 * Returns logs filtered by type
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$logType = $_GET['type'] ?? null;
$limit = min((int)($_GET['limit'] ?? 100), 500);
$offset = (int)($_GET['offset'] ?? 0);

try {
    $conn = connectToDatabase();

    $params = [];
    $sql = "SELECT
                sl.id,
                sl.log_type,
                sl.created_datetime,
                sl.analyst_id,
                sl.details,
                a.full_name as analyst_name
            FROM system_logs sl
            LEFT JOIN analysts a ON sl.analyst_id = a.id";

    if ($logType) {
        $sql .= " WHERE sl.log_type = ?";
        $params[] = $logType;
    }

    $sql .= " ORDER BY sl.created_datetime DESC";
    $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse JSON details for each log
    foreach ($logs as &$log) {
        // Clean any control characters from stored JSON
        $rawDetails = $log['details'] ?? '';
        $cleanedDetails = preg_replace('/[\x00-\x1F\x7F]/', '', $rawDetails);
        $log['details'] = json_decode($cleanedDetails, true);
    }

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM system_logs";
    if ($logType) {
        $countSql .= " WHERE log_type = ?";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute([$logType]);
    } else {
        $countStmt = $conn->query($countSql);
    }
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
