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
require_once __DIR__ . '/sso_identity.php';   // ssoClearDanglingLink() — shared with LDAP
require_once __DIR__ . '/session_security.php'; // requestScheme() — proxy-aware (GH #152)
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
    $scheme = requestScheme();
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . BASE_URL . 'api/auth/oidc_callback.php';
}

/**
 * URL-safe base64 (no padding) for PKCE / random tokens.
 *
 * @param string $bin  the raw bytes to encode
 */
function oidcBase64Url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * Cryptographically-random URL-safe token.
 *
 * @param int $bytes  how many random bytes to draw before encoding
 */
function oidcRandomToken(int $bytes = 32): string {
    return oidcBase64Url(random_bytes($bytes));
}

/**
 * Load a provider row by id, with the client secret decrypted.
 * Returns null if not found.
 *
 * @param int $id  the auth_providers id
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
 *
 * @param string $issuer  the provider's issuer URL; a trailing slash is ignored
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
    // Every endpoint must be an ABSOLUTE https:// (or http://) URL.
    //
    // The spec requires this, but a misconfigured provider can advertise a bare
    // path like "/oidc/authorize", and passing that straight through is silently
    // dangerous rather than merely broken: oidcBuildAuthUrl() would emit it as a
    // relative `Location:` header, and the BROWSER then resolves it against the
    // current origin — this application. The user lands on OUR host, gets a 404,
    // and every visible symptom points at us instead of at the IdP. Refusing it
    // here names the real culprit while we still know who it is. Same shape as
    // the relative-fetch bug in issue #74.
    foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
        if (!oidcIsAbsoluteHttpUrl((string)$doc[$key])) {
            throw new Exception(
                "The identity provider's discovery document at {$url} gives {$key} as \"{$doc[$key]}\", "
                . "which is not an absolute http(s) URL. The provider must publish the full address "
                . "(for example https://idp.example.com/oidc/authorize). This is a configuration problem "
                . "at the identity provider, not in FreeITSM — a relative address here would send people "
                . "to this server instead of to the provider."
            );
        }
    }
    $cache[$issuer] = $doc;
    return $doc;
}

/**
 * True only for an absolute http:// or https:// URL with a host part.
 *
 * Deliberately stricter than filter_var(..., FILTER_VALIDATE_URL), which
 * happily accepts schemes we must never redirect a browser to (javascript:,
 * data:, mailto:) and which some PHP builds accept without a host.
 *
 * @param string $url  the URL to check, e.g. an endpoint from a discovery document
 */
function oidcIsAbsoluteHttpUrl(string $url): bool {
    $parts = parse_url(trim($url));
    if (!is_array($parts)) return false;
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') return false;
    return !empty($parts['host']);
}

/**
 * Build the authorization URL to redirect the user's browser to.
 *
 * @param array $provider  the auth_providers row - client_id and scopes are used
 * @param array $disco  the provider's discovery document from oidcDiscover()
 * @param string $state  the random value the callback checks against the session (CSRF protection)
 * @param string $nonce  the random value the ID token must echo back
 * @param string $codeChallenge  the PKCE S256 challenge derived from the code verifier
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
 *
 * @param array $provider  the auth_providers row with client_secret decrypted (oidcGetProvider())
 * @param array $disco  the provider's discovery document from oidcDiscover()
 * @param string $code  the authorization code the provider sent to the callback
 * @param string $codeVerifier  the PKCE code verifier kept in the session at sign-in start
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
 * Link a requester to the identity that has just signed in, for next time.
 *
 * TRAP: uq_user_sso_provider_user allows one identity per person per provider,
 * and that refusal is a protection. Somebody whose email matches an account
 * already linked to a different identity must not take it over, so a real link
 * is never replaced. The one exception is the placeholder a directory import
 * holds (`entra:<oid>`, includes/entra_sync.php), and only for the account it
 * was made for when the provider says which account that is.
 *
 * @param int $providerId  auth_providers.id
 * @param int $userId  users.id
 * @param string $sub  the subject that signed in
 * @param string $email  their email claim, '' when there was none
 * @param string $oid  Entra's object id claim, lower-cased; '' when there was none
 * @return bool  false when this person is already linked to a different identity
 */
function oidcLinkRequester(PDO $conn, int $providerId, int $userId, string $sub, string $email, string $oid = ''): bool {
    $stmt = $conn->prepare("SELECT id, subject, source_ref FROM user_sso_identities WHERE provider_id = ? AND user_id = ?");
    $stmt->execute([$providerId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $conn->prepare(
            "INSERT INTO user_sso_identities (user_id, provider_id, subject, email, linked_datetime, last_login_datetime)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$userId, $providerId, $sub, $email ?: null]);
        return true;
    }

    $ref = strtolower((string)$row['source_ref']);
    if (!str_starts_with((string)$row['subject'], 'entra:') || ($oid !== '' && $ref !== '' && $ref !== $oid)) {
        return false;
    }
    $conn->prepare("UPDATE user_sso_identities SET subject = ?, email = ?, last_login_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$sub, $email ?: null, (int)$row['id']]);
    return true;
}

/**
 * Validate an ID token: signature (JWKS), issuer, audience, nonce, expiry.
 * Returns the validated claims as an associative array. Throws on any failure.
 *
 * @param string $idToken  the raw ID token (JWT) from the token response
 * @param array $disco  the provider's discovery document - jwks_uri and issuer are used
 * @param array $provider  the auth_providers row - its client_id must be in the audience
 * @param string $expectedNonce  the nonce stored in the session when the sign-in started
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

/**
 * GETs a JSON document (discovery, keys) and returns the body; throws on a network error or
 * any status but 200.
 *
 * @param string $url  the absolute URL to fetch
 */
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

/**
 * POSTs form fields to an OIDC endpoint and returns the body whatever the status, so the
 * caller can read an error reply.
 *
 * Throws only on a network error.
 *
 * @param string $url  the absolute endpoint URL, e.g. the token endpoint
 * @param array $fields  the form fields to send, URL-encoded as the body
 */
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


/**
 * Fetch claims from the provider userinfo_endpoint using the access_token.
 * Returns decoded JSON claims array or empty array on failure.
 *
 * @param string $userinfoEndpoint  the provider's userinfo_endpoint URL; empty gives []
 * @param string $accessToken  the access token from the token response, sent as a Bearer token; empty gives []
 */
function oidcFetchUserInfo(string $userinfoEndpoint, string $accessToken): array {
    if (empty($userinfoEndpoint) || empty($accessToken)) return [];
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $userinfoEndpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
        ]);
        sslApplyCurl($ch);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) {
            $data = json_decode($body, true);
            return is_array($data) ? $data : [];
        }
    } catch (Throwable) {
        // Fall back gracefully to ID token claims
    }
    return [];
}
