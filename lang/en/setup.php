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

    // Issue #109. Shown above the check list, only inside a container and only when
    // something is genuinely exposed. Written for somebody who has run one command
    // and may never have heard of a Docker volume, so it states the consequence
    // first, then hands over the exact lines rather than describing them.
    'storage' => [
        'heading'          => 'Stop — files you upload will be deleted every time you update',
        'explain'          => 'FreeITSM is running in Docker, and some of the folders it stores uploaded files in are inside the container rather than on a Docker volume. Updating FreeITSM rebuilds the container, and everything inside it is thrown away when that happens. Your database is on a volume and will survive, so afterwards your attachments would still be listed while the files themselves were gone. Two minutes now prevents this permanently.',
        'encryption_key'   => 'This includes your encryption key. If it is lost, saved mailbox passwords and integration credentials are not damaged but can never be read again, and every one has to be entered by hand.',
        'existing_install' => 'This installation is already in use, so do not simply add these and rebuild. Copy the folders out of the running container first — adding a volume does not rescue files that are already inside it. System → Debug Tools → D013 gives you the commands in the right order.',
        'step1'            => '1. Create a new file called docker-compose.override.yml, in the same folder as docker-compose.yml, containing exactly this. Do not edit docker-compose.yml itself — that file is replaced when you update, and your changes would be lost or would block the update. FreeITSM reads the override file automatically.',
        'step2'            => '2. Apply it:',
        'foot'             => 'Then reload this page — this message will be gone. Nothing else needs to change, and doing it now costs you nothing, because there is nothing stored in these folders yet.',
        'foot_in_use'      => 'Then reload this page — this message will be gone. Do not skip the copying-out step above: there are already files in these folders, and rebuilding without copying them out first will delete them for good.',
    ],

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
        'storage_persistence'    => 'Storage persistence (Docker)',
    ],

    'detail' => [
        'found'                    => 'Found',
        // Issue #109. Shown only inside a container — see includes/storage_persistence.php.
        'storage_persisted'        => 'Every folder that holds uploaded files is on storage that survives a rebuild',
        'storage_at_risk'          => 'NOT on a Docker volume and emptied by every rebuild: {dirs} — add a volume for each of these now, before anything is stored in them',
        'storage_at_risk_masked'   => '{n} folders holding uploaded files would not survive an update',
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
        'php_version_too_low'      => '{version} — PHP 8.1 or higher is required',
        'php_version_eol'          => '{version} — still supported, but this release has had no security updates since it reached end of life. PHP 8.3 or 8.4 recommended.',
        'extension_loaded'         => 'Loaded',
        'extension_not_loaded'     => 'Not loaded — enable in php.ini',
        'pdo_mysql_not_loaded'     => 'Not loaded — enable pdo_mysql in php.ini',
        'ldap_not_loaded'          => 'Not loaded — only needed for LDAP / Active Directory sign-in and directory sync. Enable extension=ldap if you use them.',
        'zip_not_loaded'           => 'Not loaded — needed to upload LMS courses (SCORM), read Word files in the RFP Builder, and search inside .docx and .xlsx attachments.',
        'intl_not_loaded'          => 'Not loaded — only needed for international domain names (such as münchen.de) in Domains.',

        // Path-free twins, shown instead of the detail above when the page is being
        // viewed by neither a fresh install nor a signed-in administrator. Same
        // pass/warn/fail verdict, none of the filesystem layout or account names.
        'db_config_not_found_masked'     => 'Not found at the path set in config.php',
        'ssl_verified_masked'            => 'On and working — a live HTTPS request was certificate-verified',
        'ssl_broken_masked'              => 'On, but the server could not verify a certificate — outbound HTTPS (email, AI, webhooks, sign-in) will fail. Sign in as an administrator to see the error.',
        'ssl_untested_masked'            => 'On, but a live test request could not be completed, so verification could not be confirmed.',
        'db_error_masked'                => 'Could not connect — sign in as an administrator to see the full error',
        'encryption_key_missing_masked'  => 'Not found — needed for encrypting sensitive settings',
        'ca_ini_masked_ok'               => 'Configured',
        'ca_ini_masked_broken'           => 'Set, but pointing at a file that is not there — fix the path or comment the setting out in php.ini.',
        // The exact PHP build number is the one detail on this page that maps
        // straight to a published vulnerability list, so it is the version itself
        // that is withheld — never the verdict. A stranger still learns that the
        // version is fine, old, or too old; they do not learn which release to
        // look up.
        'php_version_ok_masked'          => 'Meets the requirement',
        'php_version_too_low_masked'     => 'Too old — PHP 8.1 or higher is required',
        'php_version_eol_masked'         => 'Supported, but this release has reached end of life and no longer receives security updates. Sign in as an administrator to see the version.',
    ],

    'locked' => [
        'notice' => 'Setup is complete on this install, so paths, connection errors and credentials are hidden. Sign in as an administrator to see full detail.',
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
