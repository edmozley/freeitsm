<?php
/**
 * API: list open incidents (tickets) that can be linked to a problem — scoped to the
 * problem's company, excluding closed/trashed ones and any already linked. Optional ?q
 * search on number/subject. Used by the "Link incident" picker.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('problems');

try {
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    $problemId = (int) ($_GET['problem_id'] ?? 0);
    if ($problemId <= 0) throw new Exception('Problem ID is required');
    if (!analystCanAccessProblem($conn, $analystId, $problemId)) throw new Exception('Problem not found');

    // Scope to the problem's own company (matches the same-company rule in link_ticket).
    $where = " WHERE t.deleted_datetime IS NULL AND t.closed_datetime IS NULL";
    $params = [];
    // Only tickets of the analyst's departments (includes/ticket_department_scope.php).
    require_once __DIR__ . '/../../includes/ticket_department_scope.php';
    [$dSql, $dParams] = ticketDepartmentScopeSql($conn, (int)$_SESSION['analyst_id'], 't');
    $where .= $dSql; $params = array_merge($params, $dParams);
    if (isMultiTenant($conn)) {
        $pt = $conn->prepare("SELECT tenant_id FROM problems WHERE id = ?");
        $pt->execute([$problemId]);
        $pten = $pt->fetchColumn();
        $eff = ($pten === null || $pten === false) ? getDefaultTenantId($conn) : (int) $pten;
        if ($eff === getDefaultTenantId($conn)) {
            $where .= " AND (t.tenant_id = ? OR t.tenant_id IS NULL)";
            $params[] = $eff;
        } else {
            $where .= " AND t.tenant_id = ?";
            $params[] = $eff;
        }
    }

    // Exclude already-linked.
    $where .= " AND t.id NOT IN (SELECT ticket_id FROM problem_tickets WHERE problem_id = ?)";
    $params[] = $problemId;

    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where .= " AND (t.ticket_number LIKE ? OR t.subject LIKE ?)";
        $q = '%' . trim($_GET['q']) . '%'; $params[] = $q; $params[] = $q;
    }

    $sql = "SELECT t.id, t.ticket_number, t.subject, t.created_datetime,
                   ts.name AS status, COALESCE(NULLIF(TRIM(u.display_name),''), u.email) AS requester
            FROM tickets t
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            LEFT JOIN users u ON u.id = t.user_id
            $where
            ORDER BY t.created_datetime DESC
            LIMIT 200";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'tickets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
