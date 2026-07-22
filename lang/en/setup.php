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
        'ssl_verify'     => 'HTTPS certificate verification',
        'ca_bundle_ini'  => 'CA bundle in php.ini',
        'display_errors' => 'Display errors',
        'php_version'    => 'PHP version',
        'php_extension'  => 'PHP extension: {ext}',
        'php_extension_optional' => 'PHP extension: {ext} (optional)',
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
        'ssl_verified'             => 'On and working — a live HTTPS request was certificate-verified (CA bundle: {bundle})',
        'ssl_broken'               => 'On, but the server could not verify a certificate — outbound HTTPS (email, AI, webhooks, sign-in) will fail. Simplest fix: put a cacert.pem file in the app\'s includes/ folder (download from https://curl.se/ca/cacert.pem) — no php.ini changes needed. Error: {error}',
        'ssl_untested'             => 'On, but a live test request could not be completed (no outbound network?), so verification could not be confirmed. Error: {error}',
        'ssl_bundle_system'        => 'system store',
        'help_link'                => 'How to fix this — HTTPS certificates guide →',
        'ca_ini_status'            => 'curl.cainfo: {curl} · openssl.cafile: {ossl}',
        'ca_ini_none'              => 'not set',
        'ca_ini_missing'           => '{path} (file missing!)',
        'ca_ini_note_fix'          => ' — fix the path or comment the setting out in php.ini.',
        'ca_ini_note_fallback'     => ' — optional: FreeITSM falls back to its bundled CA list (Windows) or the OS trust store (Linux). Note: this reflects the web server\'s PHP; the background worker uses a separate CLI php.ini.',
        'ssl_disabled'             => 'Disabled — enable for production (set SSL_VERIFY_PEER to true in config.php)',
        'ssl_undefined'            => 'SSL_VERIFY_PEER not defined in config.php',
        'display_errors_enabled'   => 'Enabled — disable for production (set display_errors to 0 in config.php)',
        'display_errors_disabled'  => 'Disabled',
        'php_version_ok'           => '{version}',
        'php_version_too_low'      => '{version} — PHP 7.4 or higher is required',
        'php_version_eol'          => '{version} — still supported, but this release has had no security updates since it reached end of life. PHP 8.3 or 8.4 recommended.',
        'extension_loaded'         => 'Loaded',
        'extension_not_loaded'     => 'Not loaded — enable in php.ini',
        'pdo_mysql_not_loaded'     => 'Not loaded — enable pdo_mysql in php.ini',
        'imap_not_loaded'          => 'Not loaded — only needed for basic IMAP/SMTP mailboxes. PHP 8.4 no longer bundles this extension; install it via PECL if you use one.',
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
