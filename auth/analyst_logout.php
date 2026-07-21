<?php
/**
 * Logout handler for analysts
 * Destroys the session and redirects to login page
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Capture any SSO context before we wipe the session.
$ssoProviderId = $_SESSION['sso_provider_id'] ?? null;
$ssoIdToken    = $_SESSION['sso_id_token'] ?? null;

// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}

// Destroy the session
session_destroy();

// If this was an SSO session, also end the session at the identity provider
// (single logout), then let it redirect back to the app's front door.
if ($ssoProviderId) {
    try {
        require_once __DIR__ . '/../includes/oidc.php';
        $conn = connectToDatabase();
        $provider = oidcGetProvider($conn, (int)$ssoProviderId);
        if ($provider) {
            $disco = oidcDiscover($provider['issuer_url']);
            if (!empty($disco['end_session_endpoint'])) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $postLogout = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;
                $params = ['post_logout_redirect_uri' => $postLogout];
                // id_token_hint is preferred; fall back to client_id (both satisfy Keycloak).
                if ($ssoIdToken) { $params['id_token_hint'] = $ssoIdToken; }
                else { $params['client_id'] = $provider['client_id']; }
                header('Location: ' . $disco['end_session_endpoint'] . '?' . http_build_query($params));
                exit;
            }
        }
    } catch (Exception $e) {
        // Any problem -> just fall through to the local login page.
    }
}

// Redirect to login page
header('Location: login.php');
exit;
?>
