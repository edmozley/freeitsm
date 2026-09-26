<?php
/**
 * Self-Service Portal Logout
 * Clears self-service session vars only (preserves any analyst session). If the
 * requester signed in via SSO, also ends the session at the identity provider
 * (single logout), then returns to the portal login page.
 */
session_start();

// Capture any SSO context before we clear it.
$ssoProviderId = $_SESSION['ss_sso_provider_id'] ?? null;
$ssoIdToken    = $_SESSION['ss_sso_id_token'] ?? null;

unset(
    $_SESSION['ss_user_id'],
    $_SESSION['ss_user_email'],
    $_SESSION['ss_user_name'],
    $_SESSION['ss_sso_provider_id'],
    $_SESSION['ss_sso_id_token']
);

// If this was an SSO session, also end it at the identity provider, then let it
// redirect back to the portal login page.
if ($ssoProviderId) {
    try {
        require_once '../config.php';
        require_once '../includes/functions.php';
        require_once '../includes/oidc.php';
        $conn = connectToDatabase();
        $provider = oidcGetProvider($conn, (int)$ssoProviderId);
        if ($provider) {
            $disco = oidcDiscover($provider['issuer_url']);
            if (!empty($disco['end_session_endpoint'])) {
                $scheme = requestScheme();
                $postLogout = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'self-service/login.php';
                $params = ['post_logout_redirect_uri' => $postLogout];
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

header('Location: login.php');
exit;
