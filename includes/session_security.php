<?php
/**
 * Session hardening.
 *
 * ⚠️ WHY THIS EXISTS. A security review in August 2026 found three gaps that
 * compound:
 *
 *   1. `session_regenerate_id` appeared ZERO times in the whole tree. Every point
 *      that establishes identity — analyst login, MFA, OIDC, portal login, email
 *      verification — carried on using the session ID the browser arrived with.
 *      Anyone who can set a victim's session ID before they log in then shares the
 *      logged-in session. Changing a password did not regenerate either, so a stolen
 *      session survived the user's own remediation.
 *   2. No `session_set_cookie_params` call anywhere and no php.ini shipped, so PHP's
 *      compiled defaults applied: cookie_httponly=0, cookie_samesite="" and
 *      use_strict_mode=0 — the last meaning PHP would happily ADOPT an
 *      attacker-chosen session ID rather than minting a fresh one.
 *   3. No CSRF tokens. SameSite is what stands in for them; unset, Chrome applies
 *      its own Lax default but Firefox and Safari do not.
 *
 * Missing HttpOnly was the worst of the three: it means any XSS anywhere in a
 * 21-module application yields the session cookie itself rather than being confined
 * to the page it fired on.
 *
 * ── Why the ini settings are NOT set here ────────────────────────────────────
 * Cookie parameters only take effect BEFORE session_start(), and 809 of the 818
 * files that call session_start() do so before they include config.php — so there
 * is no PHP file early enough in the request to be a hook. The ini settings are
 * therefore shipped as configuration that the SAPI reads for us:
 *
 *   .user.ini      PHP-FPM / CGI / FastCGI — covers nginx and IIS, which is the
 *                  case the review specifically called out as unprotected
 *   .htaccess      Apache with mod_php, which does not read .user.ini
 *   docker/php.ini baked into the image
 *
 * `session.cookie_secure` is deliberately absent from all three: it cannot be made
 * conditional in a static file, and turning it on for an install served over plain
 * HTTP (which plenty of internal service desks are) would stop the cookie being
 * sent at all — an instant lockout. It is applied at runtime below, where the
 * scheme is actually known.
 *
 * ── And why this file exists as well ─────────────────────────────────────────
 * A server that reads none of those three files would silently have no protection,
 * which is exactly the failure mode the review found. So the moment a session
 * becomes an AUTHENTICATED one, the cookie is re-issued explicitly with the right
 * attributes, regardless of what the server was configured to do. That is the
 * cookie that matters.
 */

/**
 * Is this request actually over HTTPS?
 *
 * X-Forwarded-Proto is only believed when the install says it is behind a trusted
 * proxy, because otherwise it is a header any client can set — and a client that
 * sets it would flag its own cookie Secure over a plain-HTTP connection and lock
 * itself out. Define TRUST_PROXY_HTTPS in config.php when terminating TLS upstream.
 */
function requestIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    if (defined('TRUST_PROXY_HTTPS') && TRUST_PROXY_HTTPS
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return false;
}

/**
 * The attributes an authenticated session cookie must carry.
 *
 * SameSite=Lax is doing the CSRF work here: it stops the cookie riding along with a
 * cross-site form post while leaving ordinary top-level navigation (a link in an
 * email to a ticket) working. Chrome already defaults to this; stating it explicitly
 * is what extends the same protection to Firefox and Safari.
 */
function sessionCookieOptions(): array
{
    $current = session_get_cookie_params();
    return [
        'expires'  => 0,                                   // session cookie
        'path'     => $current['path'] !== '' ? $current['path'] : '/',
        'domain'   => $current['domain'] ?? '',
        'secure'   => requestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Call at every point that turns an anonymous session into an identified one, and
 * whenever a password changes.
 *
 * Rotates the session ID (destroying the old one, so a fixated ID is worthless) and
 * re-issues the cookie with the hardened attributes.
 *
 * Safe to call unconditionally: it does nothing if there is no active session, and
 * it will not fatal if output has already started — it logs instead, because
 * refusing to log somebody in over a cookie-attribute problem would be a worse
 * outcome than the one being prevented.
 */
function sessionPromoteToAuthenticated(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent($file, $line)) {
        error_log("session_security: cannot rotate the session id, output already started at $file:$line");
        return;
    }
    session_regenerate_id(true);   // true = delete the old session file too

    // regenerate_id has already re-sent the cookie using whatever the server's ini
    // says. Only override it when the server did NOT give us what we need — on a
    // correctly configured install that means one Set-Cookie header rather than two
    // identical ones, and on a misconfigured one it means the authenticated cookie
    // is correct anyway. This is the whole reason the runtime half exists.
    if (!sessionCookieParamsAreHardened()) {
        setcookie(session_name(), session_id(), sessionCookieOptions());
    }
}

/**
 * Did the server's own configuration already give the session cookie the attributes
 * we require? (i.e. did one of .user.ini / .htaccess / docker php.ini apply?)
 */
function sessionCookieParamsAreHardened(): bool
{
    $p = session_get_cookie_params();
    return !empty($p['httponly'])
        && strcasecmp((string)($p['samesite'] ?? ''), 'Lax') === 0
        && (bool)($p['secure'] ?? false) === requestIsHttps();
}
