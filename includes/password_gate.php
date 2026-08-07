<?php
/**
 * Make "you must change your password" actually mean it.
 *
 * ⚠️ WHY THIS EXISTS. Both the password-expiry policy and the new
 * must_change_password flag worked by redirecting to force_password_change.php at
 * the end of login. That is a signpost, not a gate: the session is fully
 * authenticated by then, so typing /tickets/ into the address bar walked straight
 * past it. Verified — an account flagged to change its password reached the inbox
 * with a 200.
 *
 * For the expiry policy that was merely weak. For the seeded admin/freeitsm account
 * it would have made the whole fix cosmetic, which is the one outcome worth avoiding
 * when the finding is "the default credentials are permanent".
 *
 * So the flag is enforced on every request instead of only at the moment of sign-in.
 * Included from functions.php and runs on include, for the same reason
 * request_guard.php does: there is no other choke point every page passes through.
 */

/**
 * Stop an analyst who owes a password change from doing anything else.
 *
 * Deliberately narrow:
 *   - only when $_SESSION['password_expired'] is set, which only login sets;
 *   - only for ANALYST sessions (portal users have no such flow);
 *   - never for the handful of scripts they need in order to comply or leave.
 *
 * API callers get a 403 with a machine-readable marker rather than a redirect,
 * because a fetch() following a 302 to an HTML page produces a baffling error.
 */
function enforcePasswordChangeGate(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (empty($_SESSION['password_expired']) || empty($_SESSION['analyst_id'])) {
        return;
    }

    // The scripts that must stay reachable: the interstitial itself, the endpoint it
    // posts to, and the ways out. Anything missing from this list would trap the user
    // in a loop, so it is kept explicit rather than pattern-matched.
    $allowed = [
        'force_password_change.php',   // the interstitial
        'change_password.php',         // what it posts to
        'analyst_logout.php',
        'logout.php',
        'login.php',
    ];
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, $allowed, true)) {
        return;
    }

    // Does the caller want JSON? An API path or an explicit Accept/X-Requested-With.
    $isApi = strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false
          || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
          || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    if ($isApi) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success'                 => false,
            'error'                   => 'You must change your password before continuing.',
            'password_change_required' => true,
        ]);
        exit;
    }

    // Build a relative path back to the interstitial. The app is commonly served from
    // a subfolder (http://host/freeitsm-app/), so an absolute "/auth/..." would be
    // wrong; work out how deep the current script sits below the app root instead.
    $appRoot   = str_replace('\\', '/', dirname(__DIR__));
    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? '')));
    $relative  = trim(str_replace($appRoot, '', $scriptDir), '/');
    $depth     = $relative === '' ? 0 : substr_count($relative, '/') + 1;

    // ⚠️ The app-root URL, NOT auth/force_password_change.php. Pages in auth/ are
    // reachable only through the root .htaccess rewrites — a direct hit on
    // /auth/… deliberately 404s (see auth/.htaccess), so redirecting there would
    // strand the user on a 404 with no way to comply and no way back.
    header('Location: ' . str_repeat('../', $depth) . 'force_password_change.php');
    exit;
}

enforcePasswordChangeGate();
