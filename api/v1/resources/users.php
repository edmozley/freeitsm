<?php
/**
 * FreeITSM REST API v1 — requesters (the `users` table: end users who raise
 * tickets, distinct from analysts).
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
        'created_at'     => apiIsoDate($u['created_at']),
    ];
}

// GET /users?q=&email=
function apiUsersList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];
    if (isset($_GET['email']) && $_GET['email'] !== '') {
        $where[] = 'email = ?';
        $args[]  = strtolower(trim($_GET['email']));
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(email LIKE ? OR username LIKE ? OR display_name LIKE ? OR preferred_name LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);
    [$page, $perPage, $offset] = apiPagination();

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(
        // COALESCE so mailbox-less requesters sort among everyone else rather
         // than being bunched at the top, where MySQL puts NULLs.
        "SELECT id, email, username, display_name, preferred_name, tenant_id, created_at
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
    $stmt = $conn->prepare("SELECT id, email, username, display_name, preferred_name, tenant_id, created_at FROM users WHERE id = ?");
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
    }

    $stmt = $conn->prepare("INSERT INTO users (email, username, display_name, preferred_name, tenant_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    $stmt->execute([$emailOrNull, $usernameOrNull, $displayName, $preferredName, $tenantId]);
    $id = (int)$conn->lastInsertId();

    $get = $conn->prepare("SELECT id, email, username, display_name, preferred_name, tenant_id, created_at FROM users WHERE id = ?");
    $get->execute([$id]);
    apiRespond(apiSerializeUser($get->fetch(PDO::FETCH_ASSOC)), 201);
}

// PATCH /users/{id}
function apiUsersUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $stmt = $conn->prepare("SELECT id, email, username, display_name, preferred_name, tenant_id, created_at FROM users WHERE id = ?");
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
    if (!$updates) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    $args[] = $params[0];
    $conn->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

    $get = $conn->prepare("SELECT id, email, username, display_name, preferred_name, tenant_id, created_at FROM users WHERE id = ?");
    $get->execute([$params[0]]);
    apiRespond(apiSerializeUser($get->fetch(PDO::FETCH_ASSOC)));
}
