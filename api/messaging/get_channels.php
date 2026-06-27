<?php
/**
 * API Endpoint: list messaging channels (WhatsApp etc.) for the settings page.
 * Never returns raw credentials — only whether they're set, plus the public
 * webhook URL to paste into the provider console.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/messaging/messaging.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Base URL for the webhook hint (honours proxy headers behind ngrok).
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = $proto . '://' . $host;
    // Strip /api/messaging/get_channels.php to get the app root.
    $root = preg_replace('#/api/messaging/.*$#', '', $_SERVER['SCRIPT_NAME'] ?? '');

    $rows = $conn->query("SELECT * FROM messaging_channels ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $channels = array_map(function ($r) use ($base, $root) {
        $creds = messagingDecodeCredentials($r['credentials'] ?? null);
        return [
            'id'                    => (int) $r['id'],
            'name'                  => $r['name'],
            'channel_type'          => $r['channel_type'],
            'provider'              => $r['provider'],
            'phone_number'          => $r['phone_number'],
            'ingress_mode'          => $r['ingress_mode'],
            'tenant_id'             => $r['tenant_id'] !== null ? (int) $r['tenant_id'] : null,
            'is_active'             => (bool) $r['is_active'],
            'has_credentials'       => !empty($creds),
            'last_inbound_datetime' => $r['last_inbound_datetime'],
            'webhook_url'           => $base . $root . '/api/messaging/webhook.php?channel=' . (int) $r['id'],
        ];
    }, $rows);

    echo json_encode(['success' => true, 'channels' => $channels]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
