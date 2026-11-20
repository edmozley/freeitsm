<?php
/**
 * Back to the page you were on after signing in.
 *
 * A session that ends while a page is open (idle timeout, server restart, a sign-out
 * in another tab) sends the next request to auth/login.php - and after signing in the
 * analyst landed on their landing page, not the ticket, change or table they were on.
 *
 * HOW: around a hundred pages and gates send `Location: …auth/login.php` themselves
 * (module headers, requireModuleAccess(), the active-analyst gate, …). Rather than
 * touch each of them, functions.php installs one header callback: when a GET page
 * request is answered with a bare redirect to auth/login.php, the callback adds
 * `?return=<the requested path>`. login.php keeps that in the session, and whichever
 * way the sign-in finishes (password, trusted device, MFA, SSO, forced password
 * change) it goes there instead of index.php.
 *
 * 🔴 OPEN REDIRECT. The target comes from the query string, so anybody can craft a
 * login link with any `return`. loginReturnSanitize() only accepts a path on this
 * install (starting with BASE_URL), never a scheme, host, protocol-relative `//`,
 * backslash, control character or `..` segment, and never auth/, api/ or the portal.
 *
 * 🔴 SIGN-OUT LOOP. .htaccess serves sign-in and sign-out pages at root aliases too
 * (/analyst_logout.php, /logout, /login, …). A target of /analyst_logout.php would
 * sign the analyst out again straight after every sign-in. So the hook never runs
 * for a script under auth/ (judged by SCRIPT_NAME, the file actually running, not
 * the URL), and the aliases are refused as targets as well.
 *
 * Only defines functions, plus the hook install that functions.php calls.
 */

const LOGIN_RETURN_SESSION_KEY = 'login_return_to';

/** Folders whose pages are never a destination: sign-in itself, JSON endpoints, setup, the portal. */
const LOGIN_RETURN_REFUSED_FOLDERS = ['auth/', 'api/', 'setup/', 'self-service/'];

/** Root aliases .htaccess rewrites into auth/ (or to a public page): never a destination either. */
const LOGIN_RETURN_REFUSED_ALIASES = [
    'login', 'login.php', 'logout', 'logout.php', 'analyst_logout.php',
    'force_password_change.php', 'forgot-password', 'forgot-password.php',
    'reset-password', 'reset-password.php', 'csat', 'csat.php',
    'oauth_callback.php', 'google_oauth_callback.php',
];

/**
 * The target if it is a safe page on this install to send a signed-in analyst to,
 * otherwise null.
 *
 * @param mixed       $target a path with optional query, e.g. '/tickets/?ticket=12'
 * @param string|null $base   the install's BASE_URL; given only by tests
 */
function loginReturnSanitize($target, ?string $base = null): ?string
{
    if (!is_string($target) || $target === '' || strlen($target) > 2048) {
        return null;
    }
    // Control characters (header injection) and backslashes (browsers read "/\host" as "//host").
    if (preg_match('/[\x00-\x1F\x7F\\\\]/', $target)) {
        return null;
    }
    $base = $base ?? (defined('BASE_URL') ? BASE_URL : '/');
    if ($target[0] !== '/' || str_starts_with($target, '//') || !str_starts_with($target, $base)) {
        return null;
    }
    $parts = parse_url($target);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || !isset($parts['path'])) {
        return null;
    }
    $path = rawurldecode($parts['path']);
    if (preg_match('#(^|/)\.\.?(/|$)#', $path) || str_contains($path, '\\') || str_starts_with($path, '//')) {
        return null;
    }
    // Case-insensitive: on a case-insensitive filesystem /AUTH/login.php is the same file.
    $rel = strtolower(substr($path, strlen($base)));
    foreach (LOGIN_RETURN_REFUSED_FOLDERS as $folder) {
        if (str_starts_with($rel, $folder)) {
            return null;
        }
    }
    if (in_array(rtrim($rel, '/'), LOGIN_RETURN_REFUSED_ALIASES, true)) {
        return null;
    }
    return $target;
}

/** Remember where to go after signing in (or forget it, with null). Needs a writable session. */
function loginReturnRemember($target): void
{
    $target = loginReturnSanitize($target);
    if ($target === null) {
        unset($_SESSION[LOGIN_RETURN_SESSION_KEY]);
    } else {
        $_SESSION[LOGIN_RETURN_SESSION_KEY] = $target;
    }
}

/** The remembered page, without using it up, or null. */
function loginReturnPeek(): ?string
{
    return loginReturnSanitize($_SESSION[LOGIN_RETURN_SESSION_KEY] ?? null);
}

/**
 * Where a just-signed-in analyst goes: the remembered page, or index.php (their landing
 * page). The remembered page is used once - read and forgotten - so a later, ordinary
 * sign-in starts at the landing page again.
 *
 * A report download link opened while signed out (api/system/report_download.php)
 * leaves a cookie that index.php acts on. That one wins, so the download is not left
 * to fire on some later visit to index.php.
 */
function loginReturnTake(): string
{
    $target = loginReturnPeek();
    unset($_SESSION[LOGIN_RETURN_SESSION_KEY]);
    $landing = (defined('BASE_URL') ? BASE_URL : '/') . 'index.php';
    if (isset($_COOKIE['freeitsm_report_return'])) {
        return $landing;
    }
    return $target ?? $landing;
}

/**
 * Add ?return=<this page> to a bare redirect to auth/login.php, for page requests.
 *
 * TRAP: PHP keeps ONE header callback per request; a second header_register_callback()
 * replaces this one. Nothing else in the app registers one.
 */
function loginReturnInstallHook(): void
{
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        return;
    }
    // JSON callers get a 401 and are not navigations; a return address means nothing to them.
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
        return;
    }
    // The script actually running, whatever URL reached it (see SIGN-OUT LOOP above).
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $script = strtolower(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
    foreach (LOGIN_RETURN_REFUSED_FOLDERS as $folder) {
        if (str_starts_with($script, strtolower($base . $folder))) {
            return;
        }
    }
    $here = loginReturnSanitize($_SERVER['REQUEST_URI'] ?? '');
    // The landing page is where a sign-in goes anyway: keep its sign-in URL plain.
    if ($here === null || $here === $base || $here === $base . 'index.php') {
        return;
    }
    header_register_callback(function () use ($here) {
        foreach (headers_list() as $h) {
            if (stripos($h, 'Location:') !== 0) {
                continue;
            }
            $location = trim(substr($h, 9));
            // Only the bare sign-in page, absolute or relative ('../auth/login.php').
            // One that already carries a query (an error, a cancel) is left alone.
            if (preg_match('#(^|/)auth/login\.php$#', $location)) {
                header('Location: ' . $location . '?return=' . rawurlencode($here));
            }
            return;
        }
    });
}
