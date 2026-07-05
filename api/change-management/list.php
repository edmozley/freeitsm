<?php
/**
 * API Endpoint: List changes with optional filters, or return analysts list
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

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

    // Scope to the analyst's active company (no-op at N=1; Default also owns
    // NULL-tenant changes). The fragment is appended right after WHERE 1=1, so
    // its params lead the bound list.
    [$tenantSql, $tenantParams] = activeTenantFilter($conn, (int)$_SESSION['analyst_id'], 'c');

    $sql = "SELECT
                c.id,
                c.tenant_id,
                c.title,
                ct.name AS change_type,
                cs.name AS status,
                cp.name AS priority,
                ci.name AS impact,
                c.category,
                c.work_start_datetime,
                c.work_end_datetime,
                c.risk_score,
                c.risk_level,
                c.created_datetime,
                c.modified_datetime,
                c.assigned_to_id,
                assigned.full_name as assigned_to_name,
                requester.full_name as requester_name
            FROM changes c
            LEFT JOIN change_types      ct ON ct.id = c.change_type_id
            LEFT JOIN change_statuses   cs ON cs.id = c.status_id
            LEFT JOIN change_priorities cp ON cp.id = c.priority_id
            LEFT JOIN change_impacts    ci ON ci.id = c.impact_id
            LEFT JOIN analysts assigned ON c.assigned_to_id = assigned.id
            LEFT JOIN analysts requester ON c.requester_id = requester.id
            WHERE 1=1" . $tenantSql;

    $params = $tenantParams;

    if ($status) {
        $sql .= " AND cs.name = ?";
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

    // Get counts per status (same company scope as the list)
    $countsSql = "SELECT cs.name AS status, COUNT(*) as cnt
                  FROM changes c
                  LEFT JOIN change_statuses cs ON cs.id = c.status_id
                  WHERE 1=1" . $tenantSql . "
                  GROUP BY cs.name";
    $countsStmt = $conn->prepare($countsSql);
    $countsStmt->execute($tenantParams);
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
