<?php
/**
 * API Endpoint: List changes with optional filters, or return analysts list
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/changes/risk_policy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');

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

    // Company scope (no-op at N=1; Default also owns NULL-tenant changes). The
    // fragment is appended right after WHERE 1=1, so its params lead the bound list.
    //
    // GH #373: the list follows "All companies" like Tickets and Tasks - it used
    // activeTenantFilter() and quietly showed only the last company picked. Opening
    // a change checks that change's own company (analystCanAccessChange), and the
    // change's own pickers (linkable tickets) follow ITS company, so a wider list
    // is safe to act on.
    //
    // ?for_ticket=ID is the ticket's "Link to change" picker: a picker follows the
    // record's own company (wiki Assets-and-All-Companies-Design), and a change in
    // another company could not be linked anyway (ChangesService::linkTicket).
    $analystId = (int)$_SESSION['analyst_id'];
    $forTicket = (int)($_GET['for_ticket'] ?? 0);
    if ($forTicket > 0) {
        if (!analystCanAccessTicket($conn, $analystId, $forTicket)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Ticket not found']);
            exit;
        }
        $tenantSql = ''; $tenantParams = [];
        if (isMultiTenant($conn)) {
            $tt = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
            $tt->execute([$forTicket]);
            $ticketTenant = $tt->fetchColumn();
            $default = getDefaultTenantId($conn);
            $eff = ($ticketTenant === false || $ticketTenant === null) ? $default : (int)$ticketTenant;
            $tenantSql = $eff === $default ? " AND (c.tenant_id = ? OR c.tenant_id IS NULL)" : " AND c.tenant_id = ?";
            $tenantParams = [$eff];
        }
    } else {
        [$tenantSql, $tenantParams] = activeTenantReadFilter($conn, $analystId, 'c');
    }
    // Under All companies each card says which company the change is in.
    $showCompany = $forTicket === 0 && isActiveTenantAll($conn);

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
                " . (ChangeRiskPolicy::ready($conn) ? 'c.risk_policy_version_id,' : 'NULL AS risk_policy_version_id,') . "   -- #246: drawn on the scale it was rated on
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

    if ($showCompany) {
        $default = getDefaultTenantId($conn);
        $names = [];
        foreach ($changes as &$ch) {
            $tid = $ch['tenant_id'] === null ? $default : (int)$ch['tenant_id'];
            if (!array_key_exists($tid, $names)) $names[$tid] = getTenantById($conn, $tid)['name'] ?? null;
            $ch['company_name'] = $names[$tid];
        }
        unset($ch);
    }

    echo json_encode([
        'success' => true,
        'changes' => $changes,
        'counts' => $counts,
        'show_company' => $showCompany,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
