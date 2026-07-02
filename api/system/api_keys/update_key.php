<?php
/**
 * API Endpoint (internal, session): update a REST API v1 key — rename,
 * enable/disable, re-scope companies, change permissions, expiry or rate
 * limit. The key material itself is immutable (revoke + create a new key).
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/functions.php';
require_once '../../../api/v1/lib/permissions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || empty($input['id'])) {
        throw new Exception('Key ID is required');
    }
    $id = (int)$input['id'];

    $conn = connectToDatabase();
    $check = $conn->prepare("SELECT id FROM api_keys WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetchColumn()) {
        throw new Exception('Key not found');
    }

    $updates = [];
    $args    = [];

    if (array_key_exists('name', $input)) {
        $name = trim((string)$input['name']);
        if ($name === '') {
            throw new Exception('A key name is required');
        }
        $updates[] = 'name = ?';
        $args[]    = $name;
    }
    if (array_key_exists('active', $input)) {
        $updates[] = 'active = ?';
        $args[]    = $input['active'] ? 1 : 0;
    }
    if (array_key_exists('analyst_id', $input)) {
        $analystId = (int)$input['analyst_id'];
        $aStmt = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND is_active = 1");
        $aStmt->execute([$analystId]);
        if (!$aStmt->fetchColumn()) {
            throw new Exception('Unknown or inactive analyst');
        }
        $updates[] = 'analyst_id = ?';
        $args[]    = $analystId;
    }
    if (array_key_exists('permissions', $input)) {
        $permissions = apiV1NormalisePermissions($input['permissions']);
        if (!$permissions) {
            throw new Exception('Grant the key at least one permission');
        }
        $updates[] = 'permissions = ?';
        $args[]    = json_encode($permissions);
    }
    if (array_key_exists('company_ids', $input)) {
        $companyIds = null;
        if (is_array($input['company_ids'])) {
            $companyIds = array_values(array_unique(array_map('intval', $input['company_ids'])));
            if (!$companyIds) {
                $companyIds = null;
            }
        }
        $updates[] = 'company_ids = ?';
        $args[]    = $companyIds !== null ? json_encode($companyIds) : null;
    }
    if (array_key_exists('expires_at', $input)) {
        $expiresAt = null;
        if (!empty($input['expires_at'])) {
            $ts = strtotime($input['expires_at'] . ' 23:59:59 UTC');
            if ($ts === false) {
                throw new Exception('Invalid expiry date');
            }
            $expiresAt = gmdate('Y-m-d H:i:s', $ts);
        }
        $updates[] = 'expires_at = ?';
        $args[]    = $expiresAt;
    }
    if (array_key_exists('rate_limit_per_minute', $input)) {
        $rateLimit = null;
        if ($input['rate_limit_per_minute'] !== '' && $input['rate_limit_per_minute'] !== null) {
            $rateLimit = max(1, (int)$input['rate_limit_per_minute']);
        }
        $updates[] = 'rate_limit_per_minute = ?';
        $args[]    = $rateLimit;
    }

    if (!$updates) {
        throw new Exception('No updates specified');
    }

    $args[] = $id;
    $conn->prepare('UPDATE api_keys SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
