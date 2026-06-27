<?php
/**
 * Public inbound webhook for messaging channels (WhatsApp via Twilio / Meta Cloud).
 *
 *   POST /api/messaging/webhook.php?channel=<id>
 *
 * This is the channel equivalent of the email importer, but inverted: instead of
 * us polling a mailbox, the provider PUSHES messages here. There is no session —
 * the request comes from Twilio/Meta, so authenticity is established by the
 * provider's own signature (direct mode) or a shared relay secret (relay mode).
 *
 * Two ingress modes (messaging_channels.ingress_mode), per the configurable design:
 *   - 'direct' : the provider hits this URL directly (install exposes HTTPS, e.g.
 *                via ngrok in dev). Verified by the provider signature.
 *   - 'relay'  : a hosted relay forwards the verbatim request. Verified by the
 *                X-FreeITSM-Relay-Secret header matching the channel's relay_secret.
 *                (The relay itself is Phase 2; this endpoint already accepts it.)
 *
 * Always responds 200 quickly on success so the provider doesn't retry; auth
 * failures return 403 and unknown channels 404.
 */

require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/messaging/messaging.php';
require_once '../../includes/messaging/ingest.php';

/** Request headers with lower-cased keys (getallheaders fallback for non-Apache). */
function webhookHeaders(): array
{
    $out = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            $out[strtolower($k)] = $v;
        }
        return $out;
    }
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            $out[$name] = $v;
        }
    }
    return $out;
}

/** Reconstruct the exact public URL the provider called (honours ngrok proxy headers). */
function webhookFullUrl(): string
{
    $proto = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        $proto = 'https';
    }
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $uri  = $_SERVER['REQUEST_URI'] ?? '';
    return $proto . '://' . $host . $uri;
}

function webhookFail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$channelId = isset($_GET['channel']) ? (int) $_GET['channel'] : 0;
if ($channelId <= 0) {
    webhookFail(400, 'Missing channel');
}

try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    webhookFail(500, 'Database unavailable');
}

$channel = loadMessagingChannel($conn, $channelId);
if (!$channel) {
    webhookFail(404, 'Unknown channel');
}
if (empty($channel['is_active'])) {
    webhookFail(403, 'Channel inactive');
}

try {
    $provider = messagingProvider($channel);
} catch (Exception $e) {
    webhookFail(500, $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Meta Cloud GET subscription handshake (echo hub.challenge).
if ($method === 'GET') {
    $challenge = $provider->verifyChallenge($_GET);
    if ($challenge !== null) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    webhookFail(403, 'Verification failed');
}

if ($method !== 'POST') {
    webhookFail(405, 'Method not allowed');
}

$rawBody = file_get_contents('php://input');
$headers = webhookHeaders();
$params  = $_POST;

// Authenticate the request per ingress mode.
$ingress = $channel['ingress_mode'] ?? 'direct';
if ($ingress === 'relay') {
    $presented = $headers['x-freeitsm-relay-secret'] ?? '';
    $expected  = (string) ($channel['relay_secret'] ?? '');
    if ($expected === '' || !hash_equals($expected, $presented)) {
        webhookFail(403, 'Relay authentication failed');
    }
} else {
    if (!$provider->verifyWebhook($rawBody, $headers, $params, webhookFullUrl())) {
        webhookFail(403, 'Signature verification failed');
    }
}

// Parse → ingest. Never let one bad message 500 the whole webhook (the provider
// would retry the batch); log and carry on.
$results = ['created' => 0, 'appended' => 0, 'duplicate' => 0, 'errors' => 0];
try {
    $messages = $provider->parseInbound($rawBody, $params);
} catch (Exception $e) {
    error_log('messaging webhook parse error (channel ' . $channelId . '): ' . $e->getMessage());
    $messages = [];
}

foreach ($messages as $msg) {
    try {
        $r = ingestInboundMessage($conn, $channel, $msg);
        $key = $r['status'] ?? 'errors';
        if (isset($results[$key])) {
            $results[$key]++;
        }
    } catch (Exception $e) {
        $results['errors']++;
        error_log('messaging ingest error (channel ' . $channelId . '): ' . $e->getMessage());
    }
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'results' => $results]);
