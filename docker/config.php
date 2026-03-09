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

// Timezone
date_default_timezone_set('UTC');

// SSL Certificate Verification
define('SSL_VERIFY_PEER', false);

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
?>
