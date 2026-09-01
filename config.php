<?php
/**
 * Configuration file for Service Desk Ticketing System
 *
 * Mailbox settings (Azure AD credentials, OAuth tokens, etc.) are now stored
 * in the target_mailboxes database table and managed via Settings > Mailboxes.
 */

// Load database credentials from secure location (outside web root)
// Update this path to match your db_config.php location
$db_config_path = 'C:\wamp64\db_config.php';
require_once($db_config_path);

// Encryption key file location (optional override).
// The AES key that protects sensitive values lives in a file outside the web
// root. By default it is c:\wamp64\encryption_keys\sdtickets.key on Windows or
// /var/www/encryption_keys/freeitsm.key on Linux. To store it elsewhere (e.g.
// your web root isn't /var/www), uncomment the line below and set an absolute
// path. This can also be set via the ENCRYPTION_KEY_PATH environment variable,
// which Docker uses; the setting here takes precedence when both are present.
// define('ENCRYPTION_KEY_PATH', '/your/path/encryption_keys/freeitsm.key');

// Behind a reverse proxy that terminates HTTPS?
// Uncomment this if TLS ends at nginx, Traefik, Caddy, a load balancer or Cloudflare
// and plain HTTP is used between there and PHP. Without it FreeITSM sees an HTTP
// request, so the session cookie does NOT get its `Secure` flag and can be sent over
// a plain connection.
//
// It is off by default, and must stay that way: X-Forwarded-Proto is a header any
// client can invent, so believing it unconditionally would let a visitor flag their
// own cookie Secure over plain HTTP and lock themselves out. Turn it on only when a
// proxy you control is the only way in.
// define('TRUST_PROXY_HTTPS', true);

// Timezone
// Fallback timezone for bare date() calls. Datetimes are stored in UTC and
// converted per-user (Settings > Preferences); SLA uses its own calendar zone.
// Matches the app's seeded defaults (SLA calendars default to Europe/London).
date_default_timezone_set('Europe/London');

/**
 * 🔴 THE DATABASE CONNECTION'S OWN CLOCK — read this before opening a PDO by hand.
 *
 * Every connection in the product MUST be opened with these options.
 *
 * MySQL evaluates `NOW()`, `CURRENT_TIMESTAMP` and `CURDATE()` in the CONNECTION'S
 * SESSION TIME ZONE, which defaults to `SYSTEM` — the server's own clock. That is
 * not UTC unless somebody happened to set the server to UTC, and FreeITSM stores
 * every instant in UTC.
 *
 * The two met in GH #126. A note's INSERT did not name `created_datetime`, so the
 * column's `DEFAULT CURRENT_TIMESTAMP` filled it in with a LOCAL WALL CLOCK, which
 * the screen then converted as if it were a UTC instant — putting every note the
 * server's own offset into the future. Two hours in Vienna, one in the UK in
 * summer, and nothing at all on a server running UTC, which is why it survived.
 *
 * 🔑 That was not one bug. A sweep of the schema against every INSERT found 302
 * statements letting one of 272 auto-stamped columns fire, across 220 tables. This
 * line fixes all of them at once, and every one written from here on: with the
 * session pinned to UTC, `CURRENT_TIMESTAMP` and `UTC_TIMESTAMP()` are the same
 * instant, so a forgotten column can no longer be wrong.
 *
 * ⚠️ INIT_COMMAND rather than an `exec()` after connecting, because it re-runs on
 * an automatic reconnect. A SET issued once by hand is lost the moment the client
 * silently reopens the socket, and nothing announces that it has.
 *
 * ⚠️ WHAT THIS DOES NOT DO: existing rows are untouched. Nothing records which of
 * the 302 routes wrote a given row, and the tables hold rows from both, so a
 * migration would have to guess — and every wrong guess moves a row that was
 * already right. Same call as GH #116.
 *
 * ⚠️ AND THE ONE THING TO WATCH: a few columns are stored as NAIVE WALL CLOCKS on
 * purpose — change windows, scheduled work, PIR actuals — so that "2pm" reads 2pm
 * for everybody. Those must be compared against a wall clock, never against this
 * connection's now. See includes/timezone.php and the Timezones-and-Time-Handling
 * page; the queries that do it are marked.
 */
function dbConnectionOptions(): array
{
    return [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
    ];
}

// SSL Certificate Verification
// Single global switch for outbound HTTPS certificate verification. Leave this
// ON in production — turning it off means the app stops checking who it is
// talking to (Graph, mailboxes, AI providers, webhooks) and can be silently
// man-in-the-middled. It is only ever a stopgap for a service you fully control
// on your own network. There are no per-module SSL tick-boxes; this line governs
// everything. See workflow/help-ssl.php for the full explanation.
define('SSL_VERIFY_PEER', true);

// CA bundle used for verification (CURLOPT_CAINFO). Prefers a bundle configured
// in php.ini; on Windows falls back to the cacert.pem shipped in includes/ so a
// fresh WAMP install verifies out of the box. See includes/ssl.php.
require_once(__DIR__ . '/includes/ssl.php');
if (!defined('SSL_CA_BUNDLE')) {
    define('SSL_CA_BUNDLE', sslResolveCaBundle());
}

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * BASE_URL — absolute URL path prefix for the app's deployment root.
 *
 * Examples:
 *   App served at http://localhost/freeitsm-app/ → BASE_URL = '/freeitsm-app/'
 *   App served at https://itsm.company.com/      → BASE_URL = '/'
 *
 * Used everywhere we build internal links so we don't have to fiddle with
 * $path_prefix or '../' on every page. Auto-detected from the filesystem
 * location of this config.php relative to the web server's DOCUMENT_ROOT.
 */
if (!defined('BASE_URL')) {
    $__appRoot = str_replace('\\', '/', realpath(__DIR__));
    $__docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $__rel = '';
    if ($__docRoot && strpos($__appRoot, $__docRoot) === 0) {
        $__rel = substr($__appRoot, strlen($__docRoot));
    }
    $__rel = '/' . trim($__rel, '/') . '/';
    if ($__rel === '//') $__rel = '/'; // app deployed at document root
    define('BASE_URL', $__rel);
    unset($__appRoot, $__docRoot, $__rel);
}
?>
