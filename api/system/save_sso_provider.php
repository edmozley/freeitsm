<?php
/**
 * API: Create or update an SSO / OIDC identity provider.
 * POST JSON { id?, display_name, issuer_url, client_id, client_secret,
 *             scopes, enabled, auto_create_users, require_verified_email,
 *             default_modules, sort_order }
 *
 * The client_secret is encrypted at rest (AES-256-GCM via encryptValue()).
 * On update, a blank or masked ("****") secret means "leave the stored one
 * untouched" so the admin needn't re-enter it every save.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

// --- Validate required fields ---
$displayName = trim($data['display_name'] ?? '');
$issuerUrl   = trim($data['issuer_url'] ?? '');
$clientId    = trim($data['client_id'] ?? '');

if ($displayName === '' || $issuerUrl === '' || $clientId === '') {
    echo json_encode(['success' => false, 'error' => 'Display name, issuer URL and client ID are required']);
    exit;
}
if (!preg_match('#^https?://#i', $issuerUrl)) {
    echo json_encode(['success' => false, 'error' => 'Issuer URL must start with http:// or https://']);
    exit;
}

// --- Normalise optional fields ---
$scopes          = trim($data['scopes'] ?? '');
if ($scopes === '') $scopes = 'openid email profile';
$enabled         = !empty($data['enabled']) ? 1 : 0;
$autoCreate      = !empty($data['auto_create_users']) ? 1 : 0;
$requireVerified = !empty($data['require_verified_email']) ? 1 : 0;
$defaultModules  = isset($data['default_modules']) && trim($data['default_modules']) !== ''
                   ? trim($data['default_modules']) : null;
$sortOrder       = (int)($data['sort_order'] ?? 0);
// Which client company owns this IdP. Empty/0 = a global (MSP-internal) provider.
$tenantId        = !empty($data['tenant_id']) ? (int)$data['tenant_id'] : null;
$issuerUrl       = rtrim($issuerUrl, '/'); // store without trailing slash for consistent discovery
$secretInput     = $data['client_secret'] ?? '';
$id              = isset($data['id']) ? (int)$data['id'] : 0;

try {
    $conn = connectToDatabase();

    if ($id > 0) {
        // --- Update existing provider ---
        if (isMaskedNoChangeValue($secretInput)) {
            // Leave the stored secret untouched.
            $stmt = $conn->prepare(
                "UPDATE auth_providers
                    SET display_name = ?, issuer_url = ?, client_id = ?, scopes = ?,
                        enabled = ?, auto_create_users = ?, require_verified_email = ?,
                        default_modules = ?, sort_order = ?, tenant_id = ?,
                        last_modified_datetime = UTC_TIMESTAMP()
                  WHERE id = ?"
            );
            $stmt->execute([$displayName, $issuerUrl, $clientId, $scopes,
                            $enabled, $autoCreate, $requireVerified, $defaultModules, $sortOrder, $tenantId, $id]);
        } else {
            $encSecret = encryptValue($secretInput);
            $stmt = $conn->prepare(
                "UPDATE auth_providers
                    SET display_name = ?, issuer_url = ?, client_id = ?, client_secret = ?, scopes = ?,
                        enabled = ?, auto_create_users = ?, require_verified_email = ?,
                        default_modules = ?, sort_order = ?, tenant_id = ?,
                        last_modified_datetime = UTC_TIMESTAMP()
                  WHERE id = ?"
            );
            $stmt->execute([$displayName, $issuerUrl, $clientId, $encSecret, $scopes,
                            $enabled, $autoCreate, $requireVerified, $defaultModules, $sortOrder, $tenantId, $id]);
        }
        echo json_encode(['success' => true, 'id' => $id]);

    } else {
        // --- Create new provider ---
        $encSecret = ($secretInput !== '' && !isMaskedNoChangeValue($secretInput))
                     ? encryptValue($secretInput) : null;
        $stmt = $conn->prepare(
            "INSERT INTO auth_providers
                (display_name, issuer_url, client_id, client_secret, scopes,
                 enabled, auto_create_users, require_verified_email, default_modules, sort_order, tenant_id, protocol)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'oidc')"
        );
        $stmt->execute([$displayName, $issuerUrl, $clientId, $encSecret, $scopes,
                        $enabled, $autoCreate, $requireVerified, $defaultModules, $sortOrder, $tenantId]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
