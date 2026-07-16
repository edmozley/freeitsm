<?php
/**
 * SSO login entry point.
 * GET ?provider=<id>
 *
 * Starts an OpenID Connect Authorization-Code + PKCE login: generates the
 * state / nonce / PKCE verifier, stashes them in the session, then redirects
 * the browser to the provider's authorization endpoint.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/oidc.php';

// Which portal is signing in: 'analyst' (default) or 'self-service'. The same
// callback + IdP redirect URI serve both; the portal is carried in the session
// so the callback knows which account space to resolve against and where to land.
$portal = ($_GET['portal'] ?? '') === 'self-service' ? 'self-service' : 'analyst';
$_SESSION['oidc_portal'] = $portal;

/** Bounce back to the right portal's login page with an error message. */
function ssoBail(string $msg): void {
    $loginPath = (($_SESSION['oidc_portal'] ?? 'analyst') === 'self-service')
        ? 'self-service/login.php' : 'login.php';
    $_SESSION['sso_error'] = $msg;
    header('Location: ' . BASE_URL . $loginPath);
    exit;
}

$providerId = isset($_GET['provider']) ? (int)$_GET['provider'] : 0;
if ($providerId <= 0) {
    ssoBail('No identity provider specified.');
}

try {
    $conn = connectToDatabase();

    // Master kill switch.
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'sso_enabled'");
    $stmt->execute();
    if (($stmt->fetchColumn() ?: '0') !== '1') {
        ssoBail('Single sign-on is currently disabled.');
    }

    $provider = oidcGetProvider($conn, $providerId);
    if (!$provider) {
        ssoBail('Unknown identity provider.');
    }
    if ((int)$provider['enabled'] !== 1) {
        ssoBail('That identity provider is disabled.');
    }
    // This flow is OIDC-only. An LDAP provider has no issuer to discover, so
    // reaching here with one would fail deep inside discovery with a confusing
    // "no host part in the URL". The login pages never offer an LDAP button, so
    // this is a backstop against a hand-typed or stale ?provider= id.
    if (($provider['protocol'] ?? 'oidc') !== 'oidc') {
        ssoBail('That sign-in method does not use single sign-on. Enter your username and password instead.');
    }

    $disco = oidcDiscover($provider['issuer_url']);

    // PKCE + CSRF/replay tokens.
    $state        = oidcRandomToken(32);
    $nonce        = oidcRandomToken(32);
    $codeVerifier = oidcRandomToken(48);
    $codeChallenge = oidcBase64Url(hash('sha256', $codeVerifier, true));

    // Stash for the callback to validate against.
    $_SESSION['oidc_state']         = $state;
    $_SESSION['oidc_nonce']         = $nonce;
    $_SESSION['oidc_code_verifier'] = $codeVerifier;
    $_SESSION['oidc_provider_id']   = $providerId;

    $authUrl = oidcBuildAuthUrl($provider, $disco, $state, $nonce, $codeChallenge);
    header('Location: ' . $authUrl);
    exit;

} catch (Exception $e) {
    ssoBail('Could not start sign-in: ' . $e->getMessage());
}
