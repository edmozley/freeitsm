<?php
/**
 * FreeITSM REST API v1 — requesters (the `users` table: end users who raise
 * tickets, distinct from analysts).
 */
require_once dirname(__DIR__, 3) . '/includes/cost_centre_assign.php';   // GH #160

/**
 * Has Database Verification added users.employee_id (the payroll / HR number) yet?
 * GH #279: an ERP or HR sync matches people on it.
 */
function apiUserEmployeeIdReady(PDO $conn): bool {
    static $ok = null;
    if ($ok === null) {
        try { $conn->query("SELECT employee_id FROM users LIMIT 0"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/**
 * `employee_id` from a request body: trimmed, null when empty, 422 when longer
 * than 64 or not text. Not unique - two companies may both have an employee 1001.
 * Returns [sent?, value]; never sent before Database Verification has added it.
 */
function apiUserEmployeeId(PDO $conn, array $body): array {
    if (!array_key_exists('employee_id', $body)) return [false, null];
    if (!apiUserEmployeeIdReady($conn)) {
        apiError(422, 'invalid_field', "'employee_id' needs a database update: run System -> Database Verification.");
    }
    $v = $body['employee_id'];
    if ($v !== null && !is_string($v) && !is_int($v)) {
        apiError(422, 'invalid_field', "'employee_id' must be text.");
    }
    $v = trim((string)($v ?? ''));
    if (mb_strlen($v) > 64) {
        apiError(422, 'invalid_field', "'employee_id' is too long (64 characters at most).");
    }
    return [true, $v === '' ? null : $v];
}

/**
 * The columns every read of a requester selects - including the cost centre
 * they are charged to (GH #160), once Database Verification has added it.
 */
function apiUserColumns(PDO $conn): string {
    require_once dirname(__DIR__, 3) . '/includes/cost_centre_assign.php';
    $cols = "id, email, username, display_name, preferred_name, tenant_id, COALESCE(is_active, 1) AS is_active, created_at"
          . (apiUserEmployeeIdReady($conn) ? ', employee_id' : '');
    if (costCentreReady($conn, 'users')) {
        $cols .= ", cost_centre_id,
                  (SELECT ccu.code FROM cost_centres ccu WHERE ccu.id = users.cost_centre_id) AS cost_centre_code,
                  (SELECT ccu.name FROM cost_centres ccu WHERE ccu.id = users.cost_centre_id) AS cost_centre_name";
    }
    return $cols;
}

/**
 * `cost_centre_id` from a request body for a requester in company $tenantId
 * (NULL = Default): an ACTIVE one of that company, or the one they hold. 422
 * otherwise. Returns [sent?, value].
 *
 * @param ?int $currentId  the cost centre the requester holds now (may be inactive and still kept); null on a create
 */
function apiUserCostCentre(PDO $conn, array $body, ?int $tenantId, ?int $currentId): array {
    if (!array_key_exists('cost_centre_id', $body)) return [false, null];
    require_once dirname(__DIR__, 3) . '/includes/cost_centre_assign.php';
    if (!costCentreReady($conn, 'users')) return [false, null];
    try {
        return [true, costCentreCheck($conn, costCentreRecordTenant($conn, $tenantId), $body['cost_centre_id'], $currentId)];
    } catch (ServiceError $e) {
        apiError(422, 'invalid_field', $e->getMessage());
    }
}

/**
 * Shapes a requester (users row) for the API - email (null for a directory user with no
 * mailbox), names, company, active flag, cost centre and created date.
 *
 * @param array $u  a users row selected with apiUserColumns(), including the cost centre code and name when present
 */
function apiSerializeUser(array $u): array {
    return [
        'id'             => (int)$u['id'],
        // NULL for a requester who signs in through a directory and was never
        // given a mailbox. Consumers must expect null here.
        'email'          => $u['email'],
        'username'       => $u['username'] ?? null,
        'display_name'   => $u['display_name'],
        'preferred_name' => $u['preferred_name'],
        // The company this requester belongs to; null = not known, and tickets
        // they raise land in triage.
        'tenant_id'      => isset($u['tenant_id']) && $u['tenant_id'] !== null ? (int)$u['tenant_id'] : null,
        // false = marked as left (3.5.0): kept on record, cannot sign in to the portal.
        'is_active'      => (int)($u['is_active'] ?? 1) === 1,
        // GH #279: the payroll / HR number an ERP or HR system knows them by, or null.
        'employee_id'    => $u['employee_id'] ?? null,
        // GH #160: the cost centre they are charged to, or null.
        'cost_centre'    => !empty($u['cost_centre_id'])
            ? ['id' => (int)$u['cost_centre_id'], 'code' => $u['cost_centre_code'] ?? null, 'name' => $u['cost_centre_name'] ?? null]
            : null,
        'created_at'     => apiIsoDate($u['created_at']),
    ];
}

// GET /users?q=&email=&employee_id=
function apiUsersList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];
    if (isset($_GET['email']) && $_GET['email'] !== '') {
        $where[] = 'email = ?';
        $args[]  = strtolower(trim($_GET['email']));
    }
    // GH #279: exact (ignoring case) - the join key an HR or ERP sync has.
    if (isset($_GET['employee_id']) && trim((string)$_GET['employee_id']) !== '') {
        if (!apiUserEmployeeIdReady($conn)) {
            $where[] = '1 = 0';
        } else {
            $where[] = 'LOWER(employee_id) = LOWER(?)';
            $args[]  = trim((string)$_GET['employee_id']);
        }
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(email LIKE ? OR username LIKE ? OR display_name LIKE ? OR preferred_name LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    // ⚠️ Scope the list to the key's companies.
    //
    // This read every row in `users` regardless of the key's company scope, so a key
    // issued to one customer company could page through every requester on the
    // install — address, username and display name — or search them with ?q=. The
    // resource is company-aware everywhere else, which is what made this easy to
    // miss: apiUsersCreate checks the destination company, so the file looks scoped.
    //
    // Erlend Volden reported the PATCH twin (below); the list and single GET had the
    // same gap and are the higher-volume leak, so all three are closed together.
    [$scopeSql, $scopeArgs] = apiKeyTenantFilter($conn, $apiKey, '');
    $whereSql = implode(' AND ', $where) . $scopeSql;
    $args     = array_merge($args, $scopeArgs);
    [$page, $perPage, $offset] = apiPagination();

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(
        // COALESCE so mailbox-less requesters sort among everyone else rather
         // than being bunched at the top, where MySQL puts NULLs.
        "SELECT " . apiUserColumns($conn) . "
         FROM users WHERE $whereSql ORDER BY COALESCE(email, username, display_name) ASC LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($args);
    apiRespond(array_map('apiSerializeUser', $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page' => $page, 'per_page' => $perPage, 'total' => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// GET /users/{id}
function apiUsersGet(PDO $conn, array $apiKey, array $params, array $body): void {
    // Same gap as the list, one row at a time. The ticket counts below were already
    // scoped by apiKeyTicketFilter(), which made the endpoint look careful while the
    // record itself — address, username, display name — was fetched by raw id.
    // Unknown id and out-of-scope id give the identical 404 on purpose.
    if (!apiKeyCanAccessTenantRow($conn, $apiKey, 'users', (int)$params[0])) {
        apiError(404, 'not_found', 'Requester not found.');
    }
    $stmt = $conn->prepare("SELECT " . apiUserColumns($conn) . " FROM users WHERE id = ?");
    $stmt->execute([$params[0]]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        apiError(404, 'not_found', 'Requester not found.');
    }
    $data = apiSerializeUser($user);

    // Ticket counts, scoped to the key's companies.
    [$scopeSql, $scopeArgs] = apiKeyTicketFilter($conn, $apiKey);
    $cStmt = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN ts.is_closed = 1 THEN 0 ELSE 1 END) AS open_count
         FROM tickets t LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         WHERE t.user_id = ? AND t.deleted_datetime IS NULL" . $scopeSql
    );
    $cStmt->execute(array_merge([$params[0]], $scopeArgs));
    $counts = $cStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'open_count' => 0];
    $data['tickets'] = [
        'total' => (int)$counts['total'],
        'open'  => (int)$counts['open_count'],
    ];
    apiRespond($data);
}

// POST /users
function apiUsersCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    // EITHER an email or a username identifies a requester — staff who sign in
    // through a directory may have no mailbox at all (GitHub #47), and the admin
    // UI accepts them, so the API must too or the two disagree about who can
    // exist. A malformed address is still an error; an absent one is not.
    $email    = strtolower(trim((string)($body['email'] ?? '')));
    $username = trim((string)($body['username'] ?? ''));

    if ($email === '' && $username === '') {
        apiError(422, 'missing_field', "Provide 'email' or 'username' — a requester needs at least one.");
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError(422, 'invalid_field', "'email' must be a valid email address.");
    }

    // Absent means NULL, never '' — '' occupies the unique index, so the second
    // requester created without one would be rejected as a duplicate.
    $emailOrNull    = $email !== '' ? $email : null;
    $usernameOrNull = $username !== '' ? $username : null;

    $displayName   = trim((string)($body['display_name'] ?? ''))
        ?: ($email !== '' ? ucfirst(explode('@', $email)[0]) : $username);
    $preferredName = trim((string)($body['preferred_name'] ?? '')) ?: null;

    if ($emailOrNull !== null) {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetchColumn()) {
            apiError(409, 'conflict', 'A requester with this email already exists.');
        }
        require_once dirname(__DIR__, 3) . '/includes/user_emails.php';   // GH #308: one owner per address
        if (userEmailOwner($conn, $email) !== null) {
            apiError(409, 'conflict', 'This email is linked to another requester.');
        }
    }
    if ($usernameOrNull !== null) {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetchColumn()) {
            apiError(409, 'conflict', 'A requester with this username already exists.');
        }
    }
    // Told a company → use it, but only one this key may reach. Not told → infer
    // it from the address, the same pre-fill the admin UI does.
    if (array_key_exists('tenant_id', $body) && $body['tenant_id'] !== null && $body['tenant_id'] !== '') {
        $tenantId = (int)$body['tenant_id'];
        if (!apiKeyCanAccessTenant($conn, $apiKey, $tenantId)) {
            apiError(403, 'forbidden', 'This API key cannot assign requesters to that company.');
        }
    } else {
        // No address to infer a domain from → no company, which means triage.
        $tenantId = (array_key_exists('tenant_id', $body) || $emailOrNull === null)
            ? null : resolveTenantForNewUser($conn, $email);
        // 🔴 The inferred company gets the same check as a named one. Without it a
        // key limited to company A created a requester in company B just by leaving
        // tenant_id out and using an address on B's domain. Reported privately,
        // October 2026.
        if ($tenantId !== null && !apiKeyCanAccessTenant($conn, $apiKey, (int)$tenantId)) {
            apiError(403, 'forbidden', "This address belongs to a company this API key cannot reach. Pass a 'tenant_id' it can, or null for triage.");
        }
    }

    [$ccSent, $ccId] = apiUserCostCentre($conn, $body, $tenantId, null);
    [$empSent, $empId] = apiUserEmployeeId($conn, $body);

    $stmt = $conn->prepare("INSERT INTO users (email, username, display_name, preferred_name, tenant_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    $stmt->execute([$emailOrNull, $usernameOrNull, $displayName, $preferredName, $tenantId]);
    $id = (int)$conn->lastInsertId();
    if ($ccSent) $conn->prepare("UPDATE users SET cost_centre_id = ? WHERE id = ?")->execute([$ccId, $id]);
    if ($empSent) $conn->prepare("UPDATE users SET employee_id = ? WHERE id = ?")->execute([$empId, $id]);

    $get = $conn->prepare("SELECT " . apiUserColumns($conn) . " FROM users WHERE id = ?");
    $get->execute([$id]);
    apiRespond(apiSerializeUser($get->fetch(PDO::FETCH_ASSOC)), 201);
}

// PATCH /users/{id}
function apiUsersUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    // ⚠️ The REST twin of the save_user.php hole (S1), reported by Erlend Volden.
    //
    // The check further down tests the DESTINATION company when the body sets
    // tenant_id — but nothing tested the SUBJECT, so a key scoped to company A could
    // PATCH a requester owned by company B: rewrite their email address, then use the
    // portal's password-reset flow against the address it now controls. Guarding where
    // a record is going while leaving open which record you may touch guards nothing;
    // that is the identical lesson as S1, one layer up.
    //
    // apiKeyCanAccessTenantRow() already existed and handles the single-company and
    // unscoped-key cases internally. It was simply never called here.
    if (!apiKeyCanAccessTenantRow($conn, $apiKey, 'users', (int)$params[0])) {
        apiError(404, 'not_found', 'Requester not found.');
    }
    $stmt = $conn->prepare("SELECT " . apiUserColumns($conn) . " FROM users WHERE id = ?");
    $stmt->execute([$params[0]]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        apiError(404, 'not_found', 'Requester not found.');
    }

    $updates = [];
    $args    = [];
    if (array_key_exists('email', $body)) {
        $email = strtolower(trim((string)$body['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            apiError(422, 'invalid_field', "'email' must be a valid email address.");
        }
        $dup = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $dup->execute([$email, $params[0]]);
        if ($dup->fetchColumn()) {
            apiError(409, 'conflict', 'Another requester already uses this email.');
        }
        require_once dirname(__DIR__, 3) . '/includes/user_emails.php';   // GH #308: one owner per address
        $own = userEmailOwner($conn, $email);
        if ($own && $own['kind'] === 'linked' && $own['user_id'] !== (int)$params[0]) {
            apiError(409, 'conflict', 'This email is linked to another requester.');
        }
        $updates[] = 'email = ?';
        $args[]    = $email;
    }
    if (array_key_exists('display_name', $body)) {
        $updates[] = 'display_name = ?';
        $args[]    = trim((string)$body['display_name']) ?: null;
    }
    if (array_key_exists('preferred_name', $body)) {
        $updates[] = 'preferred_name = ?';
        $args[]    = trim((string)$body['preferred_name']) ?: null;
    }
    // "Mark as left" from an identity source (3.5.0): false keeps them on record but
    // stops portal sign-in by every route and ends an open portal session.
    if (array_key_exists('is_active', $body)) {
        if (!is_bool($body['is_active']) && !in_array($body['is_active'], [0, 1, '0', '1'], true)) {
            apiError(422, 'invalid_field', "'is_active' must be true or false.");
        }
        $updates[] = 'is_active = ?';
        $args[]    = (int)(bool)(int)$body['is_active'];
    }
    if (array_key_exists('tenant_id', $body)) {
        if ($body['tenant_id'] === null || $body['tenant_id'] === '') {
            $updates[] = 'tenant_id = ?';
            $args[]    = null;
        } else {
            $tenantId = (int)$body['tenant_id'];
            if (!apiKeyCanAccessTenant($conn, $apiKey, $tenantId)) {
                apiError(403, 'forbidden', 'This API key cannot assign requesters to that company.');
            }
            $updates[] = 'tenant_id = ?';
            $args[]    = $tenantId;
        }
    }
    // The cost centre (GH #160), checked against the company they will be in.
    $ccCur = $conn->prepare("SELECT tenant_id" . (costCentreReady($conn, 'users') ? ", cost_centre_id" : ", NULL AS cost_centre_id") . " FROM users WHERE id = ?");
    $ccCur->execute([$params[0]]);
    $ccRow = $ccCur->fetch(PDO::FETCH_ASSOC) ?: ['tenant_id' => null, 'cost_centre_id' => null];
    $targetTenant = array_key_exists('tenant_id', $body)
        ? (($body['tenant_id'] === null || $body['tenant_id'] === '') ? null : (int)$body['tenant_id'])
        : ($ccRow['tenant_id'] !== null ? (int)$ccRow['tenant_id'] : null);
    [$ccSent, $ccId] = apiUserCostCentre($conn, $body, $targetTenant, $ccRow['cost_centre_id'] !== null ? (int)$ccRow['cost_centre_id'] : null);
    if ($ccSent) {
        $updates[] = 'cost_centre_id = ?';
        $args[]    = $ccId;
    } elseif ($ccRow['cost_centre_id'] !== null && array_key_exists('tenant_id', $body)) {
        // Moving company: their old company's cost centre cannot come too.
        $ct = $conn->prepare("SELECT tenant_id FROM cost_centres WHERE id = ?");
        $ct->execute([(int)$ccRow['cost_centre_id']]);
        if ((int)$ct->fetchColumn() !== costCentreRecordTenant($conn, $targetTenant)) {
            apiError(422, 'invalid_field', "This requester's cost centre belongs to their current company. Send cost_centre_id (one of the new company's, or null) with tenant_id.");
        }
    }
    [$empSent, $empId] = apiUserEmployeeId($conn, $body);
    if ($empSent) {
        $updates[] = 'employee_id = ?';
        $args[]    = $empId;
    }
    if (!$updates) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    $args[] = $params[0];
    $conn->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

    $get = $conn->prepare("SELECT " . apiUserColumns($conn) . " FROM users WHERE id = ?");
    $get->execute([$params[0]]);
    apiRespond(apiSerializeUser($get->fetch(PDO::FETCH_ASSOC)));
}
