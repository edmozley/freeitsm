<?php
/**
 * FreeITSM REST API v1 — requesters (the `users` table: end users who raise
 * tickets, distinct from analysts).
 */

function apiSerializeUser(array $u): array {
    return [
        'id'             => (int)$u['id'],
        'email'          => $u['email'],
        'display_name'   => $u['display_name'],
        'preferred_name' => $u['preferred_name'],
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
        $where[] = '(email LIKE ? OR display_name LIKE ? OR preferred_name LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);
    [$page, $perPage, $offset] = apiPagination();

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(
        "SELECT id, email, display_name, preferred_name, created_at
         FROM users WHERE $whereSql ORDER BY email ASC LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($args);
    apiRespond(array_map('apiSerializeUser', $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page' => $page, 'per_page' => $perPage, 'total' => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// GET /users/{id}
function apiUsersGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $stmt = $conn->prepare("SELECT id, email, display_name, preferred_name, created_at FROM users WHERE id = ?");
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
    $email = strtolower(trim((string)($body['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError(422, 'missing_field', "'email' is required and must be a valid email address.");
    }
    $displayName   = trim((string)($body['display_name'] ?? '')) ?: ucfirst(explode('@', $email)[0]);
    $preferredName = trim((string)($body['preferred_name'] ?? '')) ?: null;

    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetchColumn()) {
        apiError(409, 'conflict', 'A requester with this email already exists.');
    }
    $stmt = $conn->prepare("INSERT INTO users (email, display_name, preferred_name, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())");
    $stmt->execute([$email, $displayName, $preferredName]);
    $id = (int)$conn->lastInsertId();

    $get = $conn->prepare("SELECT id, email, display_name, preferred_name, created_at FROM users WHERE id = ?");
    $get->execute([$id]);
    apiRespond(apiSerializeUser($get->fetch(PDO::FETCH_ASSOC)), 201);
}

// PATCH /users/{id}
function apiUsersUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $stmt = $conn->prepare("SELECT id, email, display_name, preferred_name, created_at FROM users WHERE id = ?");
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
    if (!$updates) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    $args[] = $params[0];
    $conn->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

    $get = $conn->prepare("SELECT id, email, display_name, preferred_name, created_at FROM users WHERE id = ?");
    $get->execute([$params[0]]);
    apiRespond(apiSerializeUser($get->fetch(PDO::FETCH_ASSOC)));
}
