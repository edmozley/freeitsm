<?php
/**
 * OIDC (OpenID Connect) helper.
 *
 * Generic OpenID Connect client for SSO login. Everything is driven by the
 * provider's discovery document (/.well-known/openid-configuration), so one
 * code path serves Keycloak, Entra, Okta, Google, Authentik, etc.
 *
 * Uses the vendored firebase/php-jwt library for ID-token signature
 * validation against the provider's JWKS (the auth-bug hotspot — never
 * hand-rolled).
 *
 * Standard hardening: Authorization Code flow + PKCE (S256), `state` (CSRF)
 * and `nonce` (replay) validation, issuer + audience + expiry checks.
 */

require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/vendor/firebase-jwt/src/JWT.php';
require_once __DIR__ . '/vendor/firebase-jwt/src/JWK.php';
require_once __DIR__ . '/vendor/firebase-jwt/src/Key.php';
require_once __DIR__ . '/vendor/firebase-jwt/src/JWTExceptionWithPayloadInterface.php';
require_once __DIR__ . '/vendor/firebase-jwt/src/BeforeValidException.php';
require_once __DIR__ . '/vendor/firebase-jwt/src/ExpiredException.php';
require_once __DIR__ . '/vendor/firebase-jwt/src/SignatureInvalidException.php';

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

// Allow a little clock skew between this server and the IdP.
JWT::$leeway = 60;

/**
 * The redirect/callback URI registered in the IdP. Built from the
 * deployment's BASE_URL so it matches whatever path the app is served at.
 * MUST be identical in the login-redirect step and the token exchange.
 */
function oidcRedirectUri(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . BASE_URL . 'api/auth/oidc_callback.php';
}

/** URL-safe base64 (no padding) for PKCE / random tokens. */
function oidcBase64Url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Cryptographically-random URL-safe token. */
function oidcRandomToken(int $bytes = 32): string {
    return oidcBase64Url(random_bytes($bytes));
}

/**
 * Load a provider row by id, with the client secret decrypted.
 * Returns null if not found.
 */
function oidcGetProvider(PDO $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM auth_providers WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['client_secret'] = decryptValue($row['client_secret']);
    return $row;
}

/**
 * Fetch + cache (per request) a provider's discovery document.
 * Throws on failure.
 */
function oidcDiscover(string $issuer): array {
    static $cache = [];
    $issuer = rtrim($issuer, '/');
    if (isset($cache[$issuer])) return $cache[$issuer];

    $url  = $issuer . '/.well-known/openid-configuration';
    $body = oidcHttpGet($url);
    $doc  = json_decode($body, true);
    if (!is_array($doc) || empty($doc['authorization_endpoint']) || empty($doc['token_endpoint']) || empty($doc['jwks_uri'])) {
        throw new Exception('Invalid OIDC discovery document from ' . $url);
    }
    $cache[$issuer] = $doc;
    return $doc;
}

/**
 * Build the authorization URL to redirect the user's browser to.
 */
function oidcBuildAuthUrl(array $provider, array $disco, string $state, string $nonce, string $codeChallenge): string {
    $params = [
        'response_type'         => 'code',
        'client_id'             => $provider['client_id'],
        'redirect_uri'          => oidcRedirectUri(),
        'scope'                 => $provider['scopes'] ?: 'openid email profile',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => $codeChallenge,
        'code_challenge_method' => 'S256',
    ];
    return $disco['authorization_endpoint'] . '?' . http_build_query($params);
}

/**
 * Exchange the authorization code for tokens at the token endpoint
 * (server-to-server back-channel). Returns the decoded token response.
 * Throws on HTTP / provider error.
 */
function oidcExchangeCode(array $provider, array $disco, string $code, string $codeVerifier): array {
    $post = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => oidcRedirectUri(),
        'client_id'     => $provider['client_id'],
        'code_verifier' => $codeVerifier,
    ];
    if (!empty($provider['client_secret'])) {
        $post['client_secret'] = $provider['client_secret'];
    }

    $body = oidcHttpPost($disco['token_endpoint'], $post);
    $tok  = json_decode($body, true);
    if (!is_array($tok)) {
        throw new Exception('Token endpoint returned invalid JSON');
    }
    if (isset($tok['error'])) {
        throw new Exception('Token exchange failed: ' . $tok['error'] . ' — ' . ($tok['error_description'] ?? ''));
    }
    if (empty($tok['id_token'])) {
        throw new Exception('Token response did not include an id_token');
    }
    return $tok;
}

/**
 * Validate an ID token: signature (JWKS), issuer, audience, nonce, expiry.
 * Returns the validated claims as an associative array. Throws on any failure.
 */
function oidcValidateIdToken(string $idToken, array $disco, array $provider, string $expectedNonce): array {
    // Fetch the provider's signing keys and verify the signature + exp/nbf/iat.
    $jwksBody = oidcHttpGet($disco['jwks_uri']);
    $jwks     = json_decode($jwksBody, true);
    if (!is_array($jwks) || empty($jwks['keys'])) {
        throw new Exception('Could not load signing keys (JWKS)');
    }
    $keys   = JWK::parseKeySet($jwks, 'RS256');
    $claims = (array) JWT::decode($idToken, $keys);

    // issuer must match the discovery document's issuer exactly.
    if (($claims['iss'] ?? null) !== ($disco['issuer'] ?? null)) {
        throw new Exception('ID token issuer mismatch');
    }
    // audience must contain our client_id.
    $aud = $claims['aud'] ?? null;
    $audOk = is_array($aud) ? in_array($provider['client_id'], $aud, true) : ($aud === $provider['client_id']);
    if (!$audOk) {
        throw new Exception('ID token audience mismatch');
    }
    // nonce must match the one we sent (replay protection).
    if (($claims['nonce'] ?? null) !== $expectedNonce) {
        throw new Exception('ID token nonce mismatch');
    }
    return $claims;
}

// --------------------------------------------------------------------------
// Small curl wrappers (respect the SSL_VERIFY_PEER config constant).
// --------------------------------------------------------------------------

function oidcHttpGet(string $url): string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    sslApplyCurl($ch);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '') throw new Exception("GET $url failed: $err");
    if ($code !== 200) throw new Exception("GET $url returned HTTP $code");
    return $body;
}

function oidcHttpPost(string $url, array $fields): string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);
    sslApplyCurl($ch);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '') throw new Exception("POST $url failed: $err");
    return $body; // caller inspects JSON (may be a 400 with an error body)
}
