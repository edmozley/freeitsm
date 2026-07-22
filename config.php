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

// Timezone
// Fallback timezone for bare date() calls. Datetimes are stored in UTC and
// converted per-user (Settings > Preferences); SLA uses its own calendar zone.
// Matches the app's seeded defaults (SLA calendars default to Europe/London).
date_default_timezone_set('Europe/London');

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
