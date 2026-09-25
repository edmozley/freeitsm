<?php
/**
 * API: HTTPS certificate for the Docker image (System → Docker).
 *
 * POST {host, ip}         make the certificates (see includes/docker_tls.php)
 * GET  ?download=ca       the authority certificate, to install on the PCs
 *
 * Refuses outside a container: on WAMP or a native Linux install the web server
 * is configured by whoever runs it, and nothing here would reach it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only
require_once '../../includes/storage_persistence.php';
require_once '../../includes/docker_tls.php';

function dockerTlsJson(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

if (!storagePersistenceInContainer()) {
    dockerTlsJson(['success' => false, 'error' => 'This only works when FreeITSM runs in its Docker image.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['download'] ?? '') === 'ca') {
    $pem = @file_get_contents(DOCKER_TLS_DIR . '/ca.crt');
    if ($pem === false) {
        dockerTlsJson(['success' => false, 'error' => 'No certificate has been made yet.'], 404);
    }
    // .crt so Windows opens its certificate installer on double-click.
    $settings = json_decode((string) @file_get_contents(DOCKER_TLS_DIR . '/settings.json'), true);
    $name = 'freeitsm-ca-' . preg_replace('/[^a-z0-9.-]/', '', (string) ($settings['host'] ?? 'local')) . '.crt';
    header('Content-Type: application/x-x509-ca-cert');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($pem));
    echo $pem;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dockerTlsJson(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$host = dockerTlsNormaliseHost((string) ($input['host'] ?? ''));
if ($host === null) {
    dockerTlsJson(['success' => false, 'error' => 'invalid_host']);
}

$ipRaw = trim((string) ($input['ip'] ?? ''));
$ip    = dockerTlsNormaliseIp($ipRaw);
if ($ipRaw !== '' && $ip === null) {
    dockerTlsJson(['success' => false, 'error' => 'invalid_ip']);
}

// Not on a volume: the next `docker compose up -d` would delete the certificate,
// and with it the authority every PC was just told to trust. The screen blocks
// this too; this is the check that cannot be skipped.
if (storagePersistenceStatus(DOCKER_TLS_DIR) === 'at_risk') {
    dockerTlsJson(['success' => false, 'error' => 'not_persisted']);
}

try {
    $result = dockerTlsGenerate($host, $ip);
    dockerTlsJson(['success' => true, 'new_ca' => $result['new_ca']]);
} catch (Throwable $e) {
    dockerTlsJson(['success' => false, 'error' => $e->getMessage()]);
}
