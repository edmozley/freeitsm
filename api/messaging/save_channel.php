<?php
/**
 * API Endpoint: create/update a messaging channel (WhatsApp etc.).
 *
 * Credentials are provider-specific and stored as an encrypted JSON blob:
 *   twilio: { account_sid, auth_token }
 *   meta:   { phone_number_id, access_token, app_secret }
 * On update, a blank or masked (***) secret field means "keep the existing value".
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/messaging/messaging.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

/** Treat blank or all-asterisk values as "unchanged" so masked secrets aren't wiped. */
function provided($v): bool
{
    $v = trim((string) $v);
    return $v !== '' && !preg_match('/^\*+$/', $v);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        throw new Exception('Invalid request data');
    }

    $id          = $data['id'] ?? null;
    $name        = trim((string) ($data['name'] ?? ''));
    $provider    = $data['provider'] ?? 'twilio';
    $channelType = $data['channel_type'] ?? 'whatsapp';
    $phone       = trim((string) ($data['phone_number'] ?? ''));
    $ingress     = ($data['ingress_mode'] ?? 'direct') === 'relay' ? 'relay' : 'direct';
    $verifyToken = trim((string) ($data['verify_token'] ?? ''));
    $relaySecret = trim((string) ($data['relay_secret'] ?? ''));
    $isActive    = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new Exception('Name is required');
    }
    if (!in_array($provider, ['twilio', 'meta'], true)) {
        throw new Exception('Unknown provider');
    }

    $conn = connectToDatabase();

    // Pinned company (NULL = shared intake). Validate it's a real company.
    $tenantId = $data['tenant_id'] ?? null;
    if ($tenantId === '' || $tenantId === 0 || $tenantId === '0') {
        $tenantId = null;
    }
    if ($tenantId !== null) {
        $tenantId = (int) $tenantId;
        $chk = $conn->prepare("SELECT COUNT(*) FROM tenants WHERE id = ?");
        $chk->execute([$tenantId]);
        if ((int) $chk->fetchColumn() === 0) {
            $tenantId = null;
        }
    }

    // Load existing (on edit) so blank/masked secret fields are preserved, not wiped.
    $cur = $id ? loadMessagingChannel($conn, (int) $id) : null;

    // Merge credentials: start from existing, overwrite only provided fields.
    $creds = $cur['credentials'] ?? [];
    if ($provider === 'twilio') {
        if (provided($data['account_sid'] ?? '')) $creds['account_sid'] = trim($data['account_sid']);
        if (provided($data['auth_token'] ?? ''))  $creds['auth_token']  = trim($data['auth_token']);
    } else { // meta
        if (provided($data['phone_number_id'] ?? '')) $creds['phone_number_id'] = trim($data['phone_number_id']);
        if (provided($data['access_token'] ?? ''))     $creds['access_token']     = trim($data['access_token']);
        if (provided($data['app_secret'] ?? ''))       $creds['app_secret']       = trim($data['app_secret']);
    }
    // Optional Graph API version override (Meta only; not a secret). Blank = use the
    // built-in default, so an admin can bump it when Meta retires a version.
    $gv = trim((string) ($data['graph_version'] ?? ''));
    if ($gv !== '') {
        $creds['graph_version'] = $gv;
    } else {
        unset($creds['graph_version']);
    }
    $credsEncrypted = empty($creds) ? null : encryptValue(json_encode($creds));

    // verify_token + relay_secret are secrets too — encrypt at rest, and keep the
    // existing value if the field came in blank/masked on edit (write-only fields).
    $verifyPlain = provided($verifyToken) ? $verifyToken : (string) ($cur['verify_token'] ?? '');
    $relayPlain  = provided($relaySecret) ? $relaySecret : (string) ($cur['relay_secret'] ?? '');
    $verifyToken = $verifyPlain === '' ? null : encryptValue($verifyPlain);
    $relaySecret = $relayPlain === ''  ? null : encryptValue($relayPlain);

    if ($id) {
        $sql = "UPDATE messaging_channels SET
                    name = ?, channel_type = ?, provider = ?, phone_number = ?,
                    credentials = ?, verify_token = ?, ingress_mode = ?,
                    relay_secret = ?, tenant_id = ?, is_active = ?
                WHERE id = ?";
        $conn->prepare($sql)->execute([
            $name, $channelType, $provider, $phone, $credsEncrypted, $verifyToken,
            $ingress, $relaySecret, $tenantId, $isActive, (int) $id,
        ]);
        echo json_encode(['success' => true, 'id' => (int) $id, 'message' => 'Channel saved']);
    } else {
        $sql = "INSERT INTO messaging_channels
                    (name, channel_type, provider, phone_number, credentials,
                     verify_token, ingress_mode, relay_secret, tenant_id, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $conn->prepare($sql)->execute([
            $name, $channelType, $provider, $phone, $credsEncrypted, $verifyToken,
            $ingress, $relaySecret, $tenantId, $isActive,
        ]);
        echo json_encode(['success' => true, 'id' => (int) $conn->lastInsertId(), 'message' => 'Channel created']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
