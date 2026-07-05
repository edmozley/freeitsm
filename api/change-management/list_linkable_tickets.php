<?php
/**
 * API: list open incidents (tickets) that can be linked to a change — scoped to the
 * change's company, excluding closed/trashed ones and any already linked. Optional ?q
 * search on number/subject. Used by the change detail's "Link incident" picker.
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
    $changeId = (int) ($_GET['change_id'] ?? 0);
    if ($changeId <= 0) throw new Exception('Change ID is required');
    if (!analystCanAccessChange($conn, $analystId, $changeId)) throw new Exception('Change not found');

    // Scope to the change's own company (matches the same-company rule in link_ticket).
    $where = " WHERE t.deleted_datetime IS NULL AND t.closed_datetime IS NULL";
    $params = [];
    if (isMultiTenant($conn)) {
        $ct = $conn->prepare("SELECT tenant_id FROM changes WHERE id = ?");
        $ct->execute([$changeId]);
        $cten = $ct->fetchColumn();
        $eff = ($cten === null || $cten === false) ? getDefaultTenantId($conn) : (int) $cten;
        if ($eff === getDefaultTenantId($conn)) {
            $where .= " AND (t.tenant_id = ? OR t.tenant_id IS NULL)";
            $params[] = $eff;
        } else {
            $where .= " AND t.tenant_id = ?";
            $params[] = $eff;
        }
    }

    // Exclude already-linked.
    $where .= " AND t.id NOT IN (SELECT ticket_id FROM change_tickets WHERE change_id = ?)";
    $params[] = $changeId;

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
