<?php
/**
 * FreeITSM REST API v1 — response + input helpers.
 *
 * Every success body is  { "data": ..., "meta": {...}? }
 * Every error body is    { "error": { "code": "...", "message": "..." } }
 * with a real HTTP status code (unlike the internal session AJAX endpoints,
 * which always return 200 — the v1 surface is for machines, so status codes
 * carry the outcome).
 */

/** Emit a success response and stop. $meta (pagination etc.) is optional. */
function apiRespond($data, int $status = 200, ?array $meta = null): void {
    http_response_code($status);
    $body = ['data' => $data];
    if ($meta !== null) {
        $body['meta'] = $meta;
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Emit an error response and stop. */
function apiError(int $status, string $code, string $message, ?array $details = null): void {
    http_response_code($status);
    $error = ['code' => $code, 'message' => $message];
    if ($details !== null) {
        $error['details'] = $details;
    }
    echo json_encode(['error' => $error], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Parse the JSON request body. Returns [] for an empty body; 400s on a body
 * that is present but not valid JSON (silent garbage-in is worse than an error).
 */
function apiJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        apiError(400, 'invalid_json', 'Request body must be a valid JSON object.');
    }
    return $decoded;
}

/** DB "YYYY-MM-DD HH:MM:SS" (stored UTC) -> ISO 8601 "YYYY-MM-DDTHH:MM:SSZ", or null. */
function apiIsoDate(?string $dbDatetime): ?string {
    if ($dbDatetime === null || $dbDatetime === '') {
        return null;
    }
    return str_replace(' ', 'T', $dbDatetime) . 'Z';
}

/**
 * Parse an incoming date/time filter or field (ISO 8601, with or without Z,
 * or "YYYY-MM-DD HH:MM:SS") into a UTC DB string. 400s on garbage.
 */
function apiParseDate(string $value, string $field): string {
    $v = trim($value);
    try {
        $dt = new DateTimeImmutable($v, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        apiError(400, 'invalid_parameter', "'{$field}' is not a valid date/time. Use ISO 8601, e.g. 2026-07-02T09:00:00Z.");
    }
}

/** Pagination from ?page= and ?per_page= (defaults 1 / 25, per_page capped at 100). */
function apiPagination(): array {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 25);
    if ($perPage < 1)   $perPage = 25;
    if ($perPage > 100) $perPage = 100;
    return [$page, $perPage, ($page - 1) * $perPage];
}
