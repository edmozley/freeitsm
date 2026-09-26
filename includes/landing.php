<?php
/**
 * Where an unauthenticated visitor lands (discussion #63).
 *
 * FreeITSM has two front doors: the analyst login and the self-service portal.
 * Historically the root URL always sent people to the analyst login, which is
 * the wrong first impression on an install where most humans are end users.
 *
 * Two levels, most specific first:
 *   1. a per-analyst preference, cached in a cookie so it is readable BEFORE
 *      anyone has logged in
 *   2. an install-wide default set by an administrator
 *
 * ⚠️ THE STORED VALUE IS A KEY, NEVER A PATH. `landingTargets()` is the only
 * place a URL exists. This matters: the setting drives a redirect on the single
 * most-visited URL in the product, so accepting a path from the database — or,
 * worse, from a cookie — would be an open redirect on the front door. Every
 * value coming from outside is validated against the key list and anything
 * unrecognised falls back to the default.
 */

require_once __DIR__ . '/session_security.php';   // requestIsHttps() — proxy-aware (GH #152)

/** Cookie holding the analyst's own choice. Read pre-auth; see landingResolve(). */
const LANDING_COOKIE = 'freeitsm_landing';

/** The preference key in `user_preferences` — the source of truth for a person. */
const LANDING_PREF_KEY = 'default_landing_page';

/** The system_settings key holding the install-wide default. */
const LANDING_SETTING_KEY = 'default_landing_page';

/**
 * The only place a landing key maps to a URL.
 *
 * Paths are relative to the application root, because that is where index.php
 * performs the redirect from.
 */
function landingTargets(): array
{
    return [
        // Real file paths, resolvable with no URL rewriting of any kind. `login.php`
        // used to sit in the root; it moved to auth/ in the root-folder tidy, and this
        // still named the old location. It only appeared to work because the root
        // .htaccess rewrites /login.php onto auth/login.php — so on any server that
        // does not read .htaccess (nginx, or Apache with AllowOverride None) a fresh
        // install redirected every logged-out visitor to a 404. See issue #68.
        'analyst' => 'auth/login.php',
        'portal'  => 'self-service/login.php',
    ];
}

/** Is this a landing key we recognise? Everything from outside goes through here. */
function landingIsValid($key): bool
{
    return is_string($key) && array_key_exists($key, landingTargets());
}

/** The install-wide default, or 'analyst' when unset — the historical behaviour. */
function landingInstallDefault(PDO $conn): string
{
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([LANDING_SETTING_KEY]);
        $value = $stmt->fetchColumn();
    } catch (Exception $e) {
        // A missing settings table must never stop somebody logging in.
        return 'analyst';
    }
    return landingIsValid($value) ? $value : 'analyst';
}

/**
 * Where should this visitor go? Returns a relative path from the app root.
 *
 * The cookie wins when present and valid. It is only ever written from an
 * analyst's saved preference, so trusting it costs nothing an analyst could not
 * already do by typing either login URL directly — and it is validated anyway.
 */
function landingResolve(PDO $conn): string
{
    $targets = landingTargets();

    $cookie = $_COOKIE[LANDING_COOKIE] ?? null;
    if (landingIsValid($cookie)) {
        return $targets[$cookie];
    }

    return $targets[landingInstallDefault($conn)];
}

/**
 * Write (or clear) the cookie that caches an analyst's choice.
 *
 * ⚠️ Must be called before any output. Pass null to clear, which puts the
 * analyst back on whatever the administrator chose.
 *
 * HttpOnly because nothing in the browser needs to read it — the decision is
 * made in PHP before a page exists. A year, because the whole point is that it
 * survives long enough to be useful on a machine somebody uses every day.
 */
function landingSetCookie(?string $key): void
{
    if (headers_sent()) {
        return;
    }

    $params = [
        'expires'  => $key === null ? time() - 3600 : time() + 31536000,
        'path'     => '/',
        'secure'   => requestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    setcookie(LANDING_COOKIE, $key === null ? '' : $key, $params);

    // Keep the current request consistent with what we just sent, so anything
    // reading $_COOKIE later in this same request sees the new value.
    if ($key === null) {
        unset($_COOKIE[LANDING_COOKIE]);
    } else {
        $_COOKIE[LANDING_COOKIE] = $key;
    }
}

/**
 * Re-issue the cookie from the analyst's stored preference, on login.
 *
 * This is what makes the preference follow the person rather than the browser:
 * set it on your desktop, and the first time you log in on your laptop the
 * cookie is written there too. It also self-heals if somebody clears cookies.
 *
 * Silent on failure — a preference lookup must never be the reason a login
 * fails. The cost of it not running is that somebody lands on the install
 * default once more.
 */
function landingRefreshCookieFromPreference(PDO $conn, int $analystId): void
{
    if ($analystId <= 0) {
        return;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT preference_value FROM user_preferences
             WHERE analyst_id = ? AND preference_key = ?"
        );
        $stmt->execute([$analystId, LANDING_PREF_KEY]);
        $value = $stmt->fetchColumn();

        if (landingIsValid($value)) {
            landingSetCookie($value);
        } elseif ($value !== false) {
            // Explicitly saved as "use the install default" — clear any stale cookie.
            landingSetCookie(null);
        }
    } catch (Exception $e) {
        // Ignored on purpose. See the docblock.
    }
}

/**
 * Hook for api/system/set_user_preference.php.
 *
 * The preference row is the source of truth; the cookie is a cache of it that
 * exists purely so PHP can read the value before anyone has authenticated.
 * Saving the two together keeps them from drifting apart.
 */
function landingOnPreferenceSaved(string $key, $value): void
{
    if ($key !== LANDING_PREF_KEY) {
        return;
    }
    landingSetCookie(landingIsValid($value) ? $value : null);
}
