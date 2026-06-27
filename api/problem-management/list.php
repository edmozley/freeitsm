<?php
/**
 * API: list problems for the active company, with per-status counts.
 * Filters: ?status_id=, ?q= (title / problem number). Company-scoped.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }

try {
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    [$tf, $tp] = ticketTenantFilter($conn, $analystId, 'p');

    $where = ' WHERE 1=1' . $tf;
    $params = $tp;
    if (!empty($_GET['status_id'])) { $where .= ' AND p.status_id = ?'; $params[] = (int) $_GET['status_id']; }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where .= ' AND (p.title LIKE ? OR p.problem_number LIKE ?)';
        $q = '%' . trim($_GET['q']) . '%'; $params[] = $q; $params[] = $q;
    }

    $sql = "SELECT p.id, p.problem_number, p.title, p.status_id, p.priority_id,
                   p.assigned_analyst_id, p.is_known_error, p.tenant_id,
                   p.created_datetime, p.updated_datetime,
                   s.name AS status_name, s.colour AS status_colour, s.is_closed,
                   pr.name AS priority_name, pr.colour AS priority_colour,
                   a.full_name AS assignee_name,
                   (SELECT COUNT(*) FROM problem_tickets pt WHERE pt.problem_id = p.id) AS incident_count
            FROM problems p
            LEFT JOIN problem_statuses s ON s.id = p.status_id
            LEFT JOIN problem_priorities pr ON pr.id = p.priority_id
            LEFT JOIN analysts a ON a.id = p.assigned_analyst_id
            $where
            ORDER BY p.updated_datetime DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $problems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Per-status counts (respecting the company filter, ignoring the status/q filters).
    $statusCounts = $conn->query("SELECT id, name, colour, is_closed FROM problem_statuses WHERE is_active = 1 ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
    $cStmt = $conn->prepare("SELECT status_id, COUNT(*) AS cnt FROM problems p WHERE 1=1" . $tf . " GROUP BY status_id");
    $cStmt->execute($tp);
    $byStatus = [];
    foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $byStatus[(int) $r['status_id']] = (int) $r['cnt']; }
    foreach ($statusCounts as &$s) { $s['cnt'] = $byStatus[(int) $s['id']] ?? 0; }
    unset($s);

    echo json_encode(['success' => true, 'problems' => $problems, 'status_counts' => $statusCounts, 'total' => count($problems)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
