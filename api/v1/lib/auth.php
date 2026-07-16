<?php
/**
 * FreeITSM REST API v1 — key authentication, permissions and company scoping.
 *
 * Keys live in the api_keys table (separate from the legacy plaintext
 * `apikeys` table used by api/external/). Only a SHA-256 hash of the key is
 * stored; the full key (fitsm_...) is shown once at creation.
 *
 * Every key acts as an analyst (api_keys.analyst_id) so that audit rows,
 * notes and workflow events keep a real author, and carries:
 *   - a granular permission map (see lib/permissions.php)
 *   - an optional company scope (api_keys.company_ids JSON; NULL = all)
 *   - an optional expiry and per-minute rate-limit override
 */

/**
 * Authenticate the request and return the key row (with decoded permissions
 * and company scope). Emits 401/403/429 and stops on failure.
 */
function apiAuthenticate(PDO $conn): array {
    $rawKey = apiExtractKey();
    if ($rawKey === null || $rawKey === '') {
        apiError(401, 'unauthenticated', 'Missing API key. Send it as "Authorization: Bearer <key>".');
    }

    try {
        $stmt = $conn->prepare(
            "SELECT k.*, a.full_name AS analyst_name, a.is_active AS analyst_active
             FROM api_keys k
             LEFT JOIN analysts a ON a.id = k.analyst_id
             WHERE k.key_hash = ?"
        );
        $stmt->execute([hash('sha256', $rawKey)]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        apiError(500, 'server_error', 'API key store unavailable. Run System > Database Verification.');
    }

    if (!$key) {
        apiError(401, 'unauthenticated', 'Invalid API key.');
    }
    if (!(int)$key['active']) {
        apiError(403, 'key_disabled', 'This API key has been disabled.');
    }
    if ($key['expires_at'] !== null && $key['expires_at'] <= gmdate('Y-m-d H:i:s')) {
        apiError(403, 'key_expired', 'This API key expired on ' . apiIsoDate($key['expires_at']) . '.');
    }
    if ($key['analyst_id'] !== null && isset($key['analyst_active']) && $key['analyst_active'] !== null && !(int)$key['analyst_active']) {
        apiError(403, 'key_disabled', 'The analyst this API key acts as is inactive.');
    }

    apiRateLimit($conn, (int)$key['id'], $key['rate_limit_per_minute'] !== null ? (int)$key['rate_limit_per_minute'] : null);

    // Stamp usage (best-effort; never blocks the request).
    try {
        $upd = $conn->prepare("UPDATE api_keys SET last_used_at = UTC_TIMESTAMP(), last_used_ip = ? WHERE id = ?");
        $upd->execute([substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), (int)$key['id']]);
    } catch (Exception $e) { /* ignore */ }

    $key['permissions'] = apiV1NormalisePermissions(json_decode((string)$key['permissions'], true));
    $companyIds = json_decode((string)$key['company_ids'], true);
    $key['company_scope'] = is_array($companyIds) ? array_values(array_map('intval', $companyIds)) : null; // null = all companies

    return $key;
}

/** Pull the key from Authorization: Bearer <key>, a raw Authorization value, or X-Api-Key. */
function apiExtractKey(): ?string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = null;
    $xKey = null;
    foreach ($headers as $name => $value) {
        $lower = strtolower($name);
        if ($lower === 'authorization') $auth = trim($value);
        if ($lower === 'x-api-key')     $xKey = trim($value);
    }
    // FastCGI setups can strip Authorization from getallheaders(); the
    // .htaccess SetEnvIf hands it through in $_SERVER instead.
    if ($auth === null || $auth === '') {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $srvKey) {
            if (!empty($_SERVER[$srvKey])) {
                $auth = trim($_SERVER[$srvKey]);
                break;
            }
        }
    }
    if ($auth !== null && $auth !== '') {
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return $auth;
    }
    return $xKey;
}

/** Enforce the per-key permission map. 403s (with the missing permission named) on failure. */
function apiRequirePermission(array $apiKey, string $resource, string $action): void {
    $perms = $apiKey['permissions'] ?? [];
    if (!isset($perms[$resource]) || !in_array($action, $perms[$resource], true)) {
        apiError(403, 'forbidden', "This API key does not have the '{$resource}.{$action}' permission.");
    }
}

/**
 * Rate limiting — fixed one-minute windows in api_key_rate_limits, same
 * mechanism as the proven api_rate_limits implementation. Default limit comes
 * from system_settings.api_rate_limit_per_minute (fallback 60); a key can
 * carry its own override. Fails open if the rate-limit table errors.
 */
function apiRateLimit(PDO $conn, int $keyId, ?int $override): void {
    $limit = 60;
    if ($override !== null && $override > 0) {
        $limit = $override;
    } else {
        try {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'api_rate_limit_per_minute'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && is_numeric($val)) {
                $limit = (int)$val;
            }
        } catch (Exception $e) { /* default */ }
    }

    $windowStart = gmdate('Y-m-d H:i:00');
    $count = 0;
    try {
        $stmt = $conn->prepare(
            "INSERT INTO api_key_rate_limits (api_key_id, request_count, window_start)
             VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1"
        );
        $stmt->execute([$keyId, $windowStart]);

        $stmt = $conn->prepare("SELECT request_count FROM api_key_rate_limits WHERE api_key_id = ? AND window_start = ?");
        $stmt->execute([$keyId, $windowStart]);
        $count = (int)$stmt->fetchColumn();

        $conn->exec("DELETE FROM api_key_rate_limits WHERE window_start < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
    } catch (Exception $e) {
        $count = 0; // fail open
    }

    header('X-RateLimit-Limit: ' . $limit);
    header('X-RateLimit-Remaining: ' . max(0, $limit - $count));
    header('X-RateLimit-Reset: ' . (strtotime($windowStart . ' UTC') + 60));

    if ($count > $limit) {
        apiError(429, 'rate_limited', "Rate limit exceeded ({$limit} requests per minute for this key).");
    }
}

// ---------------------------------------------------------------------------
// Company (tenant) scoping — the API-key mirror of includes/tenancy.php's
// analyst helpers. A key is scoped to all companies (company_ids NULL) or an
// explicit list. On a single-company install nothing is filtered, exactly
// like the UI at N=1.
// ---------------------------------------------------------------------------

/** May this key access this company? */
function apiKeyCanAccessTenant(PDO $conn, array $apiKey, int $tenantId): bool {
    if (!isMultiTenant($conn)) {
        return true;
    }
    if ($apiKey['company_scope'] === null) {
        return true;
    }
    return in_array($tenantId, $apiKey['company_scope'], true);
}

/**
 * May this key access this row of a tenant-scoped table (by its owning
 * company)? Unknown id => false. The generic by-id gate behind
 * apiKeyCanAccessTicket / apiKeyCanAccessProblem. $table is a developer
 * literal, never user input.
 */
function apiKeyCanAccessTenantRow(PDO $conn, array $apiKey, string $table, int $rowId): bool {
    if ($rowId <= 0) {
        return false;
    }
    $stmt = $conn->prepare("SELECT tenant_id FROM {$table} WHERE id = ?");
    $stmt->execute([$rowId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if (!isMultiTenant($conn) || $apiKey['company_scope'] === null) {
        return true;
    }
    $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
    return in_array($tid, $apiKey['company_scope'], true);
}

/** May this key access this ticket (by its owning company)? Unknown id => false. */
function apiKeyCanAccessTicket(PDO $conn, array $apiKey, int $ticketId): bool {
    return apiKeyCanAccessTenantRow($conn, $apiKey, 'tickets', $ticketId);
}

/** May this key access this problem (by its owning company)? Unknown id => false. */
function apiKeyCanAccessProblem(PDO $conn, array $apiKey, int $problemId): bool {
    return apiKeyCanAccessTenantRow($conn, $apiKey, 'problems', $problemId);
}

/**
 * SQL predicate scoping any tenant_id-carrying list query to the key's
 * companies. Mirrors ticketTenantFilter(): NULL tenant_id rows belong to the
 * Default company. Works for tickets ('t'), problems ('p'), and any future
 * tenant-scoped module.
 * @return array [sqlFragment, params]
 */
function apiKeyTenantFilter(PDO $conn, array $apiKey, string $alias): array {
    if (!isMultiTenant($conn) || $apiKey['company_scope'] === null) {
        return ['', []];
    }
    $ids = $apiKey['company_scope'];
    if (!$ids) {
        return [' AND 1 = 0', []]; // scoped to no companies -> sees nothing
    }
    $col = $alias === '' ? 'tenant_id' : "$alias.tenant_id";
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    if (in_array(getDefaultTenantId($conn), $ids, true)) {
        return [" AND ($col IN ($placeholders) OR $col IS NULL)", $ids];
    }
    return [" AND $col IN ($placeholders)", $ids];
}

/** Back-compat name used by the tickets resource — delegates to the generic filter. */
function apiKeyTicketFilter(PDO $conn, array $apiKey, string $alias = 't'): array {
    return apiKeyTenantFilter($conn, $apiKey, $alias);
}

/**
 * The Knowledge counterpart of apiKeyTenantFilter().
 *
 * ⚠️ DO NOT REPLACE THIS WITH apiKeyTenantFilter(). ⚠️
 *
 * It exists because NULL means the opposite in Knowledge. For tickets/problems,
 * `tenant_id IS NULL` = "belongs to the Default company", so the generic filter
 * only lets NULL rows through when the key's scope happens to include Default.
 * In Knowledge, `tenant_id IS NULL` = "SHARED WITH EVERY COMPANY" — an MSP's
 * generic "how to reset your password" serves every client — so shared articles
 * must be visible to EVERY key, whatever its scope.
 *
 * Run a Knowledge query through the generic filter and every shared article
 * silently vanishes for any key not scoped to Default. The SQL looks nearly
 * identical; the meaning is not. See knowledgeTenantFilterForCompany() in
 * includes/tenancy.php for the session-side twin.
 *
 * @return array [sqlFragment, params]
 */
function apiKeyKnowledgeFilter(PDO $conn, array $apiKey, string $alias = 'a'): array {
    if (!isMultiTenant($conn) || $apiKey['company_scope'] === null) {
        return ['', []];
    }
    $ids = $apiKey['company_scope'];
    $col = $alias === '' ? 'tenant_id' : "$alias.tenant_id";
    if (!$ids) {
        return [" AND $col IS NULL", []];   // scoped to no companies -> shared only
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return [" AND ($col IN ($placeholders) OR $col IS NULL)", $ids];
}

/** May this key act on this article? Shared (NULL) articles belong to everyone. */
function apiKeyCanAccessArticle(PDO $conn, array $apiKey, int $articleId): bool {
    if (!isMultiTenant($conn) || $apiKey['company_scope'] === null) {
        return true;
    }
    $stmt = $conn->prepare("SELECT tenant_id FROM knowledge_articles WHERE id = ?");
    $stmt->execute([$articleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return true;                        // the caller's own 404 handles it
    if ($row['tenant_id'] === null) return true;   // shared with everyone
    return in_array((int)$row['tenant_id'], array_map('intval', $apiKey['company_scope']), true);
}

/**
 * The company a NEW ticket created by this key belongs to when the request
 * doesn't say: the Default company if the key may access it, otherwise the
 * key's first scoped company.
 */
function apiKeyDefaultTenantId(PDO $conn, array $apiKey): int {
    $default = getDefaultTenantId($conn);
    if (apiKeyCanAccessTenant($conn, $apiKey, $default)) {
        return $default;
    }
    return $apiKey['company_scope'][0] ?? $default;
}
