<?php
/**
 * API Endpoint (internal, session): create a REST API v1 key.
 *
 * The full key (fitsm_...) is generated here, returned ONCE in this response,
 * and only its SHA-256 hash is stored — it can never be shown again.
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/tenancy.php';
require_once '../../../api/v1/lib/permissions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid request data');
    }

    $name      = trim((string)($input['name'] ?? ''));
    $analystId = (int)($input['analyst_id'] ?? 0);
    if ($name === '') {
        throw new Exception('A key name is required');
    }
    if ($analystId <= 0) {
        throw new Exception('Choose the analyst this key acts as');
    }

    $permissions = apiV1NormalisePermissions($input['permissions'] ?? []);
    if (!$permissions) {
        throw new Exception('Grant the key at least one permission');
    }

    // Company scope: null/absent = all companies; otherwise a non-empty id list.
    $companyIds = null;
    if (isset($input['company_ids']) && is_array($input['company_ids'])) {
        $companyIds = array_values(array_unique(array_map('intval', $input['company_ids'])));
        if (!$companyIds) {
            $companyIds = null;
        }
    }

    $expiresAt = null;
    if (!empty($input['expires_at'])) {
        $ts = strtotime($input['expires_at'] . ' 23:59:59 UTC');
        if ($ts === false) {
            throw new Exception('Invalid expiry date');
        }
        $expiresAt = gmdate('Y-m-d H:i:s', $ts);
    }

    $rateLimit = null;
    if (isset($input['rate_limit_per_minute']) && $input['rate_limit_per_minute'] !== '' && $input['rate_limit_per_minute'] !== null) {
        $rateLimit = max(1, (int)$input['rate_limit_per_minute']);
    }

    $conn = connectToDatabase();

    $aStmt = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND is_active = 1");
    $aStmt->execute([$analystId]);
    if (!$aStmt->fetchColumn()) {
        throw new Exception('Unknown or inactive analyst');
    }

    // fitsm_ + 48 hex chars; only the hash is stored.
    $fullKey   = 'fitsm_' . bin2hex(random_bytes(24));
    $keyPrefix = substr($fullKey, 0, 14);
    $keyHash   = hash('sha256', $fullKey);

    $stmt = $conn->prepare(
        "INSERT INTO api_keys
            (name, key_prefix, key_hash, analyst_id, permissions, company_ids,
             rate_limit_per_minute, active, expires_at, created_by, created_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, UTC_TIMESTAMP())"
    );
    $stmt->execute([
        $name,
        $keyPrefix,
        $keyHash,
        $analystId,
        json_encode($permissions),
        $companyIds !== null ? json_encode($companyIds) : null,
        $rateLimit,
        $expiresAt,
        (int)$_SESSION['analyst_id'],
    ]);

    echo json_encode([
        'success' => true,
        'id'      => (int)$conn->lastInsertId(),
        'key'     => $fullKey, // shown once, never retrievable again
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
