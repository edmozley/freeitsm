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

    // Public base URL for the webhook hint. An admin can set one (Settings →
    // Messaging) so self-hosters get a copy-paste-ready URL instead of "localhost";
    // otherwise we derive it from the request (honouring proxy headers behind ngrok).
    $configuredBase = '';
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'messaging_public_base_url'");
        $st->execute();
        $configuredBase = trim((string) ($st->fetchColumn() ?: ''));
    } catch (Exception $e) { /* table missing → derive from request */ }

    if ($configuredBase !== '') {
        $base = rtrim($configuredBase, '/');
    } else {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = $proto . '://' . $host;
    }
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
            'graph_version'         => $creds['graph_version'] ?? '',
            'last_inbound_datetime' => $r['last_inbound_datetime'],
            'webhook_url'           => $base . $root . '/api/messaging/webhook.php?channel=' . (int) $r['id'],
        ];
    }, $rows);

    echo json_encode([
        'success'          => true,
        'channels'         => $channels,
        'public_base_url'  => $configuredBase,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
