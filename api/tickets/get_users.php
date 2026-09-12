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

    // ⚠️ ...and the LIST ITSELF has to be scoped, which it was not.
    //
    // Only the ticket-count subquery above was filtered. The comment beside it
    // said the count is scoped "so it doesn't reveal a requester's activity in
    // other companies" — which was true, and made the file read as careful while
    // `FROM users u` returned every requester on the install, with their email
    // address, to any analyst holding the tickets module. Scoping the number
    // attached to a row is worth nothing when the row is there to be counted.
    //
    // Same shape as the v1 /users list closed in the August security round; this
    // is its analyst-facing twin, found while building the requester picker on
    // top of it. A picker is a searchable, prominent front end to this endpoint,
    // so shipping one over an unscoped list would have made it materially worse.
    //
    // activeTenantFilter() rather than a hand-rolled clause: it already encodes
    // the rule that the Default company also owns NULL-tenant rows, so requesters
    // who have never been assigned a company stay visible from Default and do not
    // silently vanish from every queue.
    list($uSql, $uParams) = activeTenantFilter($conn, (int)$_SESSION['analyst_id'], 'u');

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
                -- The person, as opposed to the login. Written by the users screen
                -- and by directory sync; see includes/users.php for why the list of
                -- them lives in one place.
                u.job_title,
                u.department,
                u.office,
                u.phone,
                u.mobile,
                u.employee_id,
                u.manager_id,
                -- ⚠️ Load-bearing, not decoration. The edit form refuses to SEND the
                -- directory-owned fields on a managed record, because save_user.php
                -- refuses to accept them. Without this column the form would assume
                -- unmanaged, post them, and the save would fail with an error the
                -- analyst did nothing to deserve.
                u.is_managed,
                -- Deliberately NOT a join to users for the manager's name: manager_id
                -- is not tenant-scoped, so `LEFT JOIN users mgr` would hand an analyst
                -- scoped to one company the name of somebody in another. The name is
                -- resolved client-side from this same tenant-filtered list instead, so
                -- a manager you cannot see reads as blank rather than leaking.
                (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id{$ttSql}) as ticket_count
            FROM users u
            LEFT JOIN tenants ten ON ten.id = u.tenant_id";

    $params = $ttParams;

    // ⚠️ The company filter is a WHERE on its own, and the search terms are
    // bracketed inside it. Appending the tenancy clause after an unbracketed
    // `a LIKE ? OR b LIKE ? OR c LIKE ?` would bind it to the last OR branch
    // only — every row matching display_name or email would come back from any
    // company. Precedence is the whole guard here.
    $sql .= " WHERE 1=1" . $uSql;
    $params = array_merge($params, $uParams);

    if (!empty($search)) {
        // Username is searched too: a directory requester with no mailbox has
        // nothing else to type. Without this rung they are findable only by
        // display name — and an analyst who knows them as "w.noemail" would
        // conclude the account doesn't exist.
        $sql .= " AND (u.display_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
    }

    // The picker asks for a handful of matches per keystroke, not the whole
    // directory. Capped here rather than client-side so a large install does not
    // serialise thousands of rows on every pause in typing.
    $limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 0;

    $sql .= " ORDER BY u.display_name ASC";
    if ($limit > 0) {
        $sql .= " LIMIT " . $limit;   // integer, clamped above — never a bound param in LIMIT
    }

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
