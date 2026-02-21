<?php
/**
 * API Endpoint: Get activity log for a mailbox
 * GET: ?mailbox_id=N&search=text&page=1
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$mailboxId = $_GET['mailbox_id'] ?? null;
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

if (!$mailboxId) {
    echo json_encode(['success' => false, 'error' => 'Mailbox ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $where = "WHERE mailbox_id = ?";
    $params = [$mailboxId];

    if ($search !== '') {
        $where .= " AND (from_address LIKE ? OR from_name LIKE ? OR subject LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    // Get total count
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM mailbox_activity_log $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Get entries
    $sql = "SELECT id, action, from_address, from_name, subject, reason, ticket_id, processing_log, created_datetime
            FROM mailbox_activity_log $where
            ORDER BY created_datetime DESC
            LIMIT $perPage OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'entries' => $entries,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
