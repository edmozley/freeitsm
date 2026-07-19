<?php
/**
 * API Endpoint: Get users list
 * Returns users with optional search filtering
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get search parameter
$search = $_GET['search'] ?? '';

try {
    $conn = connectToDatabase();

    // Multi-tenancy: the per-user ticket count is scoped to the active company
    // (no-op at N=1), so it doesn't reveal a requester's activity in other
    // companies (§9). The placeholder sits in the SELECT subquery, so its param
    // must lead the bound list.
    list($ttSql, $ttParams) = ticketTenantFilter($conn, (int)$_SESSION['analyst_id'], 't');
    $ttSql .= " AND t.deleted_datetime IS NULL"; // exclude trashed tickets from the per-user count

    // Build query with optional search
    $sql = "SELECT
                u.id,
                u.email,
                u.username,
                u.display_name,
                u.preferred_name,
                u.created_at,
                u.tenant_id,
                ten.name AS tenant_name,
                (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id{$ttSql}) as ticket_count
            FROM users u
            LEFT JOIN tenants ten ON ten.id = u.tenant_id";

    $params = $ttParams;

    if (!empty($search)) {
        // Username is searched too: a directory requester with no mailbox has
        // nothing else to type. Without this rung they are findable only by
        // display name — and an analyst who knows them as "w.noemail" would
        // conclude the account doesn't exist.
        $sql .= " WHERE u.display_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?";
        $searchParam = '%' . $search . '%';
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
    }

    $sql .= " ORDER BY u.display_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'users' => $users
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
