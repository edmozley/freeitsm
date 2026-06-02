<?php
/**
 * English (en) — Setup Verification (first-run installer) strings.
 *
 * Covers the single setup/index.php page: the page title, summary badges,
 * individual check names + details, the Database Verify section, the default
 * login block, the footer warning, and the JS strings used by runDbVerify().
 *
 * Dynamic bits (paths, driver names, extension names, raw error messages) are
 * passed in via {placeholder} params rather than translated.
 */
return [
    'title'   => 'FreeITSM Setup',
    'heading' => 'Setup Verification',

    'summary' => [
        'passed'   => '{n} passed',
        'warning'  => '{n} warning',
        'warnings' => '{n} warnings',
        'failed'   => '{n} failed',
    ],

    'checks' => [
        'config'         => 'config.php',
        'db_config'      => 'db_config.php',
        'db_connection'  => 'Database connection',
        'encryption_key' => 'Encryption key',
        'ssl_verify'     => 'SSL verify peer',
        'display_errors' => 'Display errors',
        'php_version'    => 'PHP version',
        'php_extension'  => 'PHP extension: {ext}',
    ],

    'detail' => [
        'found'                    => 'Found',
        'config_not_found'         => 'Not found — copy config.php to the application root',
        'db_config_not_found'      => 'Not found at: {path}',
        'db_config_path_unset'     => '$db_config_path variable not set in config.php',
        'db_connected'             => 'Connected (driver: {driver})',
        'db_constants_undefined'   => 'Database constants not defined — check db_config.php',
        'encryption_key_missing'   => 'Not found at: {path} — needed for encrypting sensitive settings',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH not defined in includes/encryption.php',
        'ssl_enabled'              => 'Enabled',
        'ssl_disabled'             => 'Disabled — enable for production (set SSL_VERIFY_PEER to true in config.php)',
        'ssl_undefined'            => 'SSL_VERIFY_PEER not defined in config.php',
        'display_errors_enabled'   => 'Enabled — disable for production (set display_errors to 0 in config.php)',
        'display_errors_disabled'  => 'Disabled',
        'php_version_ok'           => '{version}',
        'php_version_too_low'      => '{version} — PHP 7.4 or higher is required',
        'extension_loaded'         => 'Loaded',
        'extension_not_loaded'     => 'Not loaded — enable in php.ini',
        'pdo_mysql_not_loaded'     => 'Not loaded — enable pdo_mysql in php.ini',
    ],

    'db_verify' => [
        'heading' => 'Database Verify',
        'intro'   => 'Check and auto-create any missing tables or columns in the database.',
        'run'     => 'Run',
    ],

    'login' => [
        'heading'  => 'Default Login',
        'intro'    => 'A default admin account is created when you run Database Verify.',
        'username' => 'Username:',
        'password' => 'Password:',
    ],

    'footer' => [
        'warning'   => 'Once your system is in production, delete the {folder} folder for security.',
        'signature' => 'FreeITSM Setup Verification',
    ],

    'js' => [
        'running'        => 'Running...',
        'run'            => 'Run',
        'tables_checked' => '{n} tables checked:',
        'ok'             => '{n} OK',
        'created'        => '{n} created',
        'updated'        => '{n} updated',
        'errors'         => '{n} errors',
        'unknown_error'  => 'Unknown error',
        'verify_failed'  => 'Failed to run DB verify: {error}',
    ],
];
