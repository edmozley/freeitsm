<?php
/**
 * Docker Configuration for FreeITSM
 * Credentials are read from environment variables set in docker-compose.yml
 */

// Database credentials from environment
define('DB_SERVER',   getenv('DB_SERVER')   ?: 'db');
define('DB_NAME',     getenv('DB_NAME')     ?: 'freeitsm');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'freeitsm');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'freeitsm');

// Point to the placeholder db_config.php (keeps setup page compatible)
$db_config_path = '/var/www/html/db_config.php';
require_once($db_config_path);

// Encryption key file location: set via the ENCRYPTION_KEY_PATH environment
// variable in docker-compose.yml (defaults to /var/www/encryption_keys/freeitsm.key,
// stored on a persistent volume). Leave it to the env var here — do NOT define
// ENCRYPTION_KEY_PATH in this file, or it would override the compose setting.

// Timezone
date_default_timezone_set('UTC');

// SSL Certificate Verification
// Single global switch for outbound HTTPS certificate verification — see
// includes/ssl.php and workflow/help-ssl.php. Leave ON in production. In the
// Docker image (Debian php:8.4-apache) the OS ships a CA bundle that libcurl
// finds automatically, so verification works out of the box with no cacert.pem.
define('SSL_VERIFY_PEER', true);

// Load the shared SSL helper (sslApplyCurl) and resolve the CA bundle. REQUIRED:
// every outbound curl handle calls sslApplyCurl(), which is defined here. This
// config.php is copied to /var/www/html/config.php, so __DIR__ is the app root.
require_once(__DIR__ . '/includes/ssl.php');
if (!defined('SSL_CA_BUNDLE')) {
    define('SSL_CA_BUNDLE', sslResolveCaBundle());
}

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

/**
 * BASE_URL — absolute URL path prefix for the app's deployment root.
 * Auto-detected from the filesystem location of this config.php relative to
 * the web server's DOCUMENT_ROOT. In the standard Docker image the app is at
 * /var/www/html and Apache's doc root is /var/www/html, so this resolves to '/'.
 */
if (!defined('BASE_URL')) {
    $__appRoot = str_replace('\\', '/', realpath(__DIR__));
    $__docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $__rel = '';
    if ($__docRoot && strpos($__appRoot, $__docRoot) === 0) {
        $__rel = substr($__appRoot, strlen($__docRoot));
    }
    $__rel = '/' . trim($__rel, '/') . '/';
    if ($__rel === '//') $__rel = '/';
    define('BASE_URL', $__rel);
    unset($__appRoot, $__docRoot, $__rel);
}
?>
