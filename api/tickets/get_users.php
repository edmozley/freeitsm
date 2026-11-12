<?php
/**
 * API Endpoint: Get users list
 * Returns users with optional search filtering
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/users.php';   // userDirectoryOwnedFields()
require_once '../../includes/phone_dial.php';   // the Call links

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireAnyModuleAccessJson(['tickets', 'assets']);

// Get search parameter
$search = $_GET['search'] ?? '';

try {
    $conn = connectToDatabase();

    // Multi-tenancy: the per-user ticket count is scoped to the active company
    // (no-op at N=1), so it doesn't reveal a requester's activity in other
    // companies (§9). The placeholder sits in the SELECT subquery, so its param
    // must lead the bound list.
    list($ttSql, $ttParams) = ticketScopeFilter($conn, (int)$_SESSION['analyst_id'], 't');
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
    //
    // The READ variant, so "All companies" lists every company's requesters
    // rather than the last one selected (2.6.0 backlog). The ticket count above
    // already widened with it, so rows and counts now agree.
    list($uSql, $uParams) = activeTenantReadFilter($conn, (int)$_SESSION['analyst_id'], 'u');

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
                u.is_active,     -- the shared editor's manager picker offers active people
                -- ⚠️ Load-bearing, not decoration. The edit form refuses to SEND the
                -- directory-owned fields on a managed record, because save_user.php
                -- refuses to accept them. Without this column the form would assume
                -- unmanaged, post them, and the save would fail with an error the
                -- analyst did nothing to deserve.
                u.is_managed,
                -- WHICH directory, not just whether. An address book owns a job
                -- title and a phone number but has nowhere to keep a payroll
                -- number or a reporting line, so those two stay editable on a
                -- CardDAV contact. The client is told the resulting field list
                -- rather than deriving it — see the note by `managed_fields`
                -- below and userDirectoryOwnedFields() in includes/users.php.
                ap.protocol AS managed_protocol,
                ap.carddav_write_back AS managed_write_back,
                -- The source BY NAME, for the Details-from line and the Source
                -- filter. Null when the person is linked to nothing.
                ap.id AS source_id, ap.display_name AS source_name,
                -- Deliberately NOT a join to users for the manager's name: manager_id
                -- is not tenant-scoped, so `LEFT JOIN users mgr` would hand an analyst
                -- scoped to one company the name of somebody in another. The name is
                -- resolved client-side from this same tenant-filtered list instead, so
                -- a manager you cannot see reads as blank rather than leaking.
                (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id{$ttSql}) as ticket_count
            FROM users u
            LEFT JOIN tenants ten ON ten.id = u.tenant_id
            LEFT JOIN auth_providers ap ON ap.id = u.auth_provider_id";

    $params = $ttParams;

    // ⚠️ The company filter is a WHERE on its own, and the search terms are
    // bracketed inside it. Appending the tenancy clause after an unbracketed
    // `a LIKE ? OR b LIKE ? OR c LIKE ?` would bind it to the last OR branch
    // only — every row matching display_name or email would come back from any
    // company. Precedence is the whole guard here.
    $sql .= " WHERE 1=1" . $uSql;
    $params = array_merge($params, $uParams);

    // Source: everyone, only people added here, or one directory/address book.
    list($srcSql, $srcParams) = userSourceFilter($_GET['source'] ?? '');
    $sql .= $srcSql;
    $params = array_merge($params, $srcParams);

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

    // 🔑 The read-only field list is RESOLVED HERE and sent, never derived by the
    // client. It was previously retyped as a JavaScript literal in two screens,
    // and includes/users.php warned in writing that a list duplicated across
    // writers "will disagree with itself within a month" — which is exactly what
    // then happened when CardDAV arrived and owned five of the seven rather than
    // all of them. One function, one answer, every consumer.
    //
    // Empty array on an unmanaged person, so a client can use it directly without
    // first testing is_managed.
    // The Call links, built here for the same reason: one place reads a phone
    // field into a dialable number (phoneDialHref). '' when click-to-call is off.
    $dial = phoneDialTemplate($conn);
    foreach ($users as &$u) {
        $u['phone_dial']  = phoneDialHref($dial, $u['phone'] ?? null);
        $u['mobile_dial'] = phoneDialHref($dial, $u['mobile'] ?? null);
        $u['managed_fields'] = ((int)($u['is_managed'] ?? 0) === 1)
            ? array_values(userDirectoryOwnedFields(
                  $u['managed_protocol'] ?? null,
                  (int)($u['managed_write_back'] ?? 0) === 1
              ))
            : [];
    }
    unset($u);

    $out = ['success' => true, 'users' => $users];
    // What the Source filter offers - only when asked, so the requester picker,
    // which calls this on every keystroke, does not pay for it.
    if (!empty($_GET['include_sources'])) {
        $out['sources'] = userSourcesInScope($conn, $uSql, $uParams);
    }
    echo json_encode($out);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
