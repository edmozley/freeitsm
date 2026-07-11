<?php
/**
 * API: list "orphaned" tickets — tickets whose department_id points at a
 * department that no longer exists (so they're invisible to every team-filtered
 * queue, since they're neither "no department" nor in anyone's departments).
 * Also returns the valid active departments for the reassignment dropdowns.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $multi = isMultiTenant($conn);

    $LIMIT = 1000;
    // Orphaned = department_id set, but no matching departments row.
    $sql = "SELECT t.id, t.ticket_number, t.subject, t.department_id, t.tenant_id,
                   t.created_datetime, s.name AS status, u.email AS requester,
                   ten.name AS company
              FROM tickets t
              LEFT JOIN departments d   ON d.id   = t.department_id
              LEFT JOIN ticket_statuses s ON s.id = t.status_id
              LEFT JOIN users u         ON u.id   = t.user_id
              LEFT JOIN tenants ten     ON ten.id = t.tenant_id
             WHERE t.department_id IS NOT NULL AND d.id IS NULL
               AND t.deleted_datetime IS NULL
             ORDER BY t.tenant_id, t.created_datetime DESC
             LIMIT " . ($LIMIT + 1);
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $truncated = count($rows) > $LIMIT;
    if ($truncated) $rows = array_slice($rows, 0, $LIMIT);

    $tickets = array_map(function ($r) {
        return [
            'id'            => (int)$r['id'],
            'ticket_number' => $r['ticket_number'],
            'subject'       => $r['subject'],
            'department_id' => (int)$r['department_id'],
            'tenant_id'     => $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null,
            'company'       => $r['company'],
            'status'        => $r['status'],
            'requester'     => $r['requester'],
            'created'       => $r['created_datetime'],
        ];
    }, $rows);

    // Valid departments for the reassignment dropdown.
    $departments = array_map(function ($d) {
        return ['id' => (int)$d['id'], 'name' => $d['name']];
    }, $conn->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success'      => true,
        'multi_tenant' => $multi,
        'tickets'      => $tickets,
        'departments'  => $departments,
        'truncated'    => $truncated,
        'limit'        => $LIMIT,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
