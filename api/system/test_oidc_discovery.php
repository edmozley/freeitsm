<?php
/**
 * API: Test an OIDC issuer's discovery document.
 * POST JSON { issuer_url }
 *
 * Fetches <issuer>/.well-known/openid-configuration and confirms it exposes
 * the endpoints we need (authorization, token, jwks). This lets an admin
 * validate a provider's issuer URL before saving — the same discovery the
 * login flow will rely on, so "one config form works for every IdP".
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$issuer = trim($data['issuer_url'] ?? '');
if ($issuer === '' || !preg_match('#^https?://#i', $issuer)) {
    echo json_encode(['success' => false, 'error' => 'Enter a valid issuer URL (http:// or https://)']);
    exit;
}

$discoveryUrl = rtrim($issuer, '/') . '/.well-known/openid-configuration';

try {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $discoveryUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => SSL_VERIFY_PEER,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlErr !== '') {
        echo json_encode(['success' => false, 'error' => 'Could not reach the discovery URL: ' . ($curlErr ?: 'unknown error')]);
        exit;
    }
    if ($httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => "Discovery URL returned HTTP $httpCode (expected 200). Check the issuer URL."]);
        exit;
    }

    $doc = json_decode($body, true);
    if (!is_array($doc)) {
        echo json_encode(['success' => false, 'error' => 'Discovery document was not valid JSON']);
        exit;
    }

    $required = ['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'];
    $missing  = array_filter($required, fn($k) => empty($doc[$k]));
    if ($missing) {
        echo json_encode(['success' => false, 'error' => 'Discovery document is missing: ' . implode(', ', $missing)]);
        exit;
    }

    echo json_encode([
        'success'  => true,
        'issuer'   => $doc['issuer'],
        'endpoints' => [
            'authorization_endpoint' => $doc['authorization_endpoint'],
            'token_endpoint'         => $doc['token_endpoint'],
            'jwks_uri'               => $doc['jwks_uri'],
            'userinfo_endpoint'      => $doc['userinfo_endpoint'] ?? null,
            'end_session_endpoint'   => $doc['end_session_endpoint'] ?? null,
        ],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
