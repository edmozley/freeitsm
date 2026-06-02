<?php
/**
 * English (en) — System module strings.
 *
 * Source-of-truth locale. Every other lang/<code>/system.php may omit keys;
 * missing keys fall back to the value here (see includes/i18n.php).
 *
 * Covers the System landing page, the shared header nav, and each system
 * sub-page: branding, colours, db-verify, debug-tools, demo-data,
 * encryption, modules (access), preferences, security and SSO.
 *
 * Excluded from translation (left as literal content): stored config
 * values, demo-data per-module card content, encrypted-key/setting
 * identifiers, module/permission keys, enum codes and server log strings.
 */
return [
    'title' => 'System',

    // Shared header navigation (system/includes/header.php)
    'nav' => [
        'encryption'  => 'Encryption',
        'modules'     => 'Modules',
        'db_verify'   => 'DB Verify',
        'colours'     => 'Colours',
        'branding'    => 'Branding',
        'security'    => 'Security',
        'preferences' => 'Preferences',
        'demo_data'   => 'Demo Data',
        'debug_tools' => 'Debug Tools',
    ],

    // System landing page (system/index.php)
    'landing' => [
        'heading'  => 'System Administration',
        'subtitle' => 'Configure system-level settings and access controls',

        'encryption_title'  => 'Encryption',
        'encryption_desc'   => 'Generate and manage the encryption key used to protect sensitive data such as API keys and credentials.',
        'modules_title'     => 'Module Access',
        'modules_desc'      => 'Control which modules each analyst can access. Restrict visibility on the home screen and navigation menu.',
        'db_verify_title'   => 'Database Verify',
        'db_verify_desc'    => 'Check all tables and columns exist in the database. Automatically creates any that are missing.',
        'colours_title'     => 'Colours',
        'colours_desc'      => 'Customise the colour theme for each module. Changes apply to headers, icons, and the home screen.',
        'branding_title'    => 'Branding',
        'branding_desc'     => 'Upload the organisation logo and set default header/footer text for diagrams and exported documents.',
        'security_title'    => 'Security',
        'security_desc'     => 'Configure trusted device policies, password expiry, and account lockout settings.',
        'sso_title'         => 'Single Sign-On',
        'sso_desc'          => 'Connect external identity providers (OpenID Connect) such as Keycloak, Entra, Okta or Google so users can sign in with SSO.',
        'preferences_title' => 'Preferences',
        'preferences_desc'  => 'Personal settings like notification position. These are saved per-browser and apply only to you.',
        'demo_data_title'   => 'Demo Data',
        'demo_data_desc'    => 'Import realistic sample data across all modules. Ideal for evaluation and testing on a fresh install.',
        'debug_tools_title' => 'Debug Tools',
        'debug_tools_desc'  => 'Library of diagnostics for troubleshooting failed flows. Run on request and send the output back to support.',
    ],

    // Branding page (system/branding/index.php)
    'branding' => [
        'title'    => 'Branding',
        'subtitle' => 'Set the organisation logo and default header/footer text used on diagrams and exported documents',

        'logo_heading'  => 'Company Logo',
        'logo_desc'     => 'Used as the {code} token in any header/footer slot. PNG, JPG, or SVG, max 2&nbsp;MB. SVG is recommended for crisp print and export.',
        'no_logo'       => 'No logo',
        'remove'        => 'Remove',
        'logo_hint'     => 'Pick a file to replace the current logo. The new image is saved when you press Save.',

        'header_heading' => 'Header',
        'header_desc'    => 'Three slots rendered along the top of the page. Leave a slot blank to omit it.',
        'footer_heading' => 'Footer',
        'footer_desc'    => 'Three slots rendered along the bottom of the page.',
        'col_left'       => 'Left',
        'col_centre'     => 'Centre',
        'col_right'      => 'Right',
        'row_header'     => 'Header',
        'row_footer'     => 'Footer',

        'tokens_heading' => 'Available tokens',
        'tokens_intro'   => 'these are replaced when the header/footer renders on a diagram or export:',
        'token_logo'     => 'the company logo image',
        'token_title'    => 'the diagram or document title',
        'token_author'   => "the author's name",
        'token_version'  => 'the version label',
        'token_modified' => 'the last-modified date',
        'tokens_example_prefix' => 'Mix tokens with plain text — e.g.',
        'tokens_example_suffix' => 'renders as',
        'tokens_example_render' => 'Author: Ed Mozley',

        'save'             => 'Save',
        'reset_defaults'   => 'Reset to defaults',

        'load_failed'         => 'Failed to load branding: {error}',
        'load_failed_generic' => 'Failed to load branding settings',
        'logo_too_large'      => 'Logo too large (max 2 MB)',
        'reset_hint'          => 'Slots reset to defaults — press Save to apply',
        'saved'               => 'Branding saved',
        'error'               => 'Error: {error}',
        'save_failed'         => 'Failed to save branding',
    ],

    // Module colours page (system/colours/index.php)
    'colours' => [
        'title'     => 'Module Colours',
        'subtitle'  => 'Customise the colour theme for each module across headers, icons, and the home screen',
        'save'      => 'Save',
        'primary'   => 'Primary',
        'secondary' => 'Secondary',
        'reset'     => 'Reset',
        'saved'     => 'Module colours saved',
        'error'     => 'Error: {error}',
        'save_failed' => 'Failed to save colours',
    ],

    // Database verification page (system/db-verify/index.php)
    'db_verify' => [
        'heading'     => 'Database Verification',
        'intro'       => 'Check all tables and columns exist. Automatically creates any that are missing.',
        'run'         => 'Run Verification',
        'verifying'   => 'Verifying...',
        'checking'    => 'Checking tables...',
        'placeholder' => 'Click "Run Verification" to check your database schema',

        'count_ok'      => 'OK',
        'count_created' => 'Created',
        'count_updated' => 'Updated',
        'count_errors'  => 'Errors',

        'col_table'   => 'Table',
        'col_status'  => 'Status',
        'col_details' => 'Details',

        'status_ok' => 'OK',

        'error'        => 'Error: {message}',
        'connect_fail' => 'Failed to connect: {message}',
    ],

    // Debug tools page (system/debug-tools/index.php)
    'debug' => [
        'heading' => 'Debug Tools',
        'intro'   => "Library of self-contained diagnostics. When something doesn't work, run the relevant tool and send the output back to support — each diagnostic captures enough environment and runtime detail to identify the cause without a back-and-forth.",
        'how_label' => 'How to use:',
        'how_text'  => 'Support will tell you which diagnostic to run (e.g. "run D001"). Click {run}, wait for the output to appear, then click {copy} and paste the entire report into your reply. Diagnostics are read-mostly — any that write to the database say so on the card.',
        'checks_label'   => 'What it checks',
        'runtime_label'  => 'Runtime:',
        'side_effects_label' => 'Side effects:',
        'run'     => 'Run',
        'running' => 'Running…',
        'copy'    => 'Copy',
        'copied'  => 'Copied',
        'output_running' => 'Running diagnostic…',
        'fetch_failed'   => 'Failed to fetch diagnostic: {message}',
    ],

    // Demo data page (system/demo-data/index.php)
    'demo' => [
        'heading'  => 'Demo Data',
        'subtitle' => 'Import realistic sample data module by module. Import Core first, then choose which modules to populate.',

        'warning_strong' => 'Designed for fresh installations only.',
        'warning_text'   => 'Importing demo data into a system that already contains real data may cause conflicts. Each module can only be imported once.',
        'tip_text_prefix' => 'Import both',
        'tip_text_and'    => 'and',
        'tip_text_suffix' => 'to unlock a bonus option that links installed software to computers.',
        'tip_assets'      => 'Assets',
        'tip_software'    => 'Software',

        'step1' => 'Step 1 — Required',
        'step2' => 'Step 2 — Choose modules',
        'step3_cross' => 'Step 3 — Cross-module data',
        'step3_dashboards' => 'Step 3 — Dashboards',

        'import'           => 'Import',
        'importing'        => 'Importing...',
        'imported_count'   => '{total} imported',
        'already_imported' => 'Already imported',

        'delete_title'   => 'Delete',
        'delete_confirm' => 'This will delete existing {module} demo data and re-import fresh. Continue?',
        'delete_ok'      => 'Delete',
        'connection_failed' => 'Connection failed: {message}',
    ],

    // Encryption page (system/encryption/index.php)
    'encryption' => [
        'title'    => 'Encryption',
        'subtitle' => 'Manage the encryption key used to protect sensitive data at rest',
        'checking' => 'Checking encryption status...',

        'how_heading'   => 'How Encryption Works',
        'how_point1'    => 'FreeITSM uses {strong} authenticated encryption to protect sensitive data stored in the database, such as API keys, vCenter credentials, and mailbox connection details.',
        'how_point1_strong' => 'AES-256-GCM',
        'how_point2'    => 'The encryption key is a 64-character hex string (256 bits) stored in a file {strong} so it cannot be accessed via a browser.',
        'how_point2_strong' => 'outside the web root',
        'how_point3'    => 'Key file location:',
        'how_point4'    => 'Encrypted values in the database are prefixed with {enc} followed by the base64-encoded ciphertext. Unencrypted values are left as-is, allowing gradual migration.',

        'backup_strong' => 'Back up your encryption key.',
        'backup_text'   => 'If the key is lost, any data encrypted with it cannot be recovered. Store a copy somewhere safe outside this server.',

        'whats_heading'    => "What's Encrypted",
        'group_settings'   => 'System Settings',
        'group_mailbox'    => 'Mailbox Connections',

        'status_ok_title'      => 'Encryption is configured',
        'status_ok_detail'     => 'The encryption key is present and valid at {path}. Sensitive data is being encrypted at rest using AES-256-GCM.',
        'status_invalid_title' => 'Invalid encryption key',
        'status_invalid_detail'=> 'A key file was found at {path} but it is not a valid 64-character hex string. The key must be exactly 64 hexadecimal characters (256 bits).',
        'generate_valid'       => 'Generate Valid Key',
        'status_missing_title' => 'No encryption key found',
        'status_missing_detail'=> 'No encryption key file exists at {path}. Sensitive data cannot be encrypted until a key is generated. Click the button below to generate one automatically.',
        'generate'             => 'Generate Encryption Key',
        'generating'           => 'Generating...',

        'check_failed' => 'Failed to check encryption status',
        'error'        => 'Error: {error}',
        'generate_failed' => 'Failed to generate key',
        'error_prefix' => 'Error: {message}',
    ],

    // Module access page (system/modules/index.php)
    'modules' => [
        'title'    => 'Module Access',
        'subtitle' => 'Control which modules each analyst can see on the home screen and in navigation',

        'info_text' => 'By default all analysts have access to every module. Toggle {all_access} off to restrict an analyst to specific modules. The System module cannot be disabled.',
        'all_access_strong' => 'All Access',

        'loading' => 'Loading analysts...',

        'empty_heading' => 'No analysts found',
        'empty_text'    => 'Add analysts in the Tickets module settings first.',

        'col_analyst'    => 'Analyst',
        'col_all_access' => 'All Access',

        'load_failed' => 'Failed to load data',
        'save_failed' => 'Failed to save',
    ],

    // Preferences page (system/preferences/index.php)
    'preferences' => [
        'title'    => 'Preferences',
        'subtitle' => 'Personal settings saved to your account — they follow you across browsers.',

        'language_heading' => 'Interface language',
        'language_desc'    => 'The language used across the FreeITSM UI. Translations fall back to English for any strings not yet covered in your chosen language. Reloads the page on change.',
        'saving'           => 'Saving…',

        'position_heading' => 'Notification position',
        'position_desc'    => 'Where toast notifications appear on the screen.',

        'animation_heading' => 'Notification animation',
        'animation_desc'    => 'How notifications enter and exit the screen.',
        'anim_slide'        => 'Slide',
        'anim_fade'         => 'Fade',

        'kb_heading'  => 'Knowledge sidebar',
        'kb_desc'     => 'How the article sidebar behaves on the Knowledge module pages. Also available on the Knowledge settings page.',
        'pm_heading'  => 'Process Mapper sidebar',
        'pm_desc'     => 'How the process list sidebar behaves on the Process Mapper module pages. Also available on the Process Mapper settings page.',
        'always_visible' => 'Always visible',
        'show_on_hover'  => 'Show on hover',

        'mc_heading' => 'Morning checks bar fill',
        'mc_desc'    => 'Solid or gradient fill for the Morning Checks 30-day trend chart. Also available on the Morning Checks settings page.',
        'fill_plain'    => 'Plain',
        'fill_gradient' => 'Gradient',

        'pos_top_left'      => 'Top left',
        'pos_top_center'    => 'Top centre',
        'pos_top_right'     => 'Top right',
        'pos_middle_left'   => 'Middle left',
        'pos_middle_center' => 'Middle centre',
        'pos_middle_right'  => 'Middle right',
        'pos_bottom_left'   => 'Bottom left',
        'pos_bottom_center' => 'Bottom centre',
        'pos_bottom_right'  => 'Bottom right',

        'pos_preview'   => 'Notifications will appear here',
        'anim_preview'  => 'Preview: {anim} animation',
        'save_failed'   => 'Failed to save',
    ],

    // Security page (system/security/index.php)
    'security' => [
        'title'    => 'Security',
        'subtitle' => 'Configure authentication policies and account protection',

        'trusted_heading' => 'Trusted Device',
        'trusted_desc'    => 'Allow users to skip OTP verification on trusted browsers. Users opt in individually via their avatar menu. Set to 0 to disable this feature entirely.',
        'trust_duration'  => 'Trust duration',
        'trust_duration_hint' => 'How long a device stays trusted after OTP verification',

        'password_heading' => 'Password Policy',
        'password_desc'    => 'Require users to change their password periodically. When a password expires, the user is redirected to a mandatory password change screen on next login. Set to 0 to disable.',
        'password_expiry'  => 'Password expiry',
        'password_expiry_hint' => 'Maximum age of a password before it must be changed',

        'lockout_heading' => 'Account Lockout',
        'lockout_desc'    => 'Lock accounts after repeated failed login attempts to prevent brute-force attacks. Set max attempts to 0 to disable lockout.',
        'max_attempts'    => 'Max failed attempts',
        'max_attempts_hint' => 'Number of wrong passwords before the account is locked',
        'lockout_duration' => 'Lockout duration',
        'lockout_duration_hint' => 'How long the account stays locked (counter resets after unlock)',

        'ipban_heading' => 'IP Ban',
        'ipban_desc'    => 'Automatically ban IP addresses that repeatedly attempt logins against non-existent or locked accounts. Each ban lasts 24 hours. After each ban the threshold drops by 1 (down to the minimum), making repeat offenders harder to abuse. Set max attempts to 0 to disable.',
        'first_ban'     => 'First ban threshold',
        'first_ban_hint' => 'Failed attempts before the IP is banned the first time',
        'min_threshold' => 'Minimum threshold',
        'min_threshold_hint' => 'The threshold stops reducing once it reaches this floor',
        'ipban_example_strong' => 'Example:',
        'ipban_example_text'   => 'With max 5 and min 2, the first ban triggers after 5 failed attempts, the second after 4, then 3, then 2. It stays at 2 for every subsequent ban. Only attempts against non-existent usernames or already-locked accounts count.',

        'unit_days'     => 'days',
        'unit_attempts' => 'attempts',
        'unit_minutes'  => 'minutes',

        'save'        => 'Save',
        'saved'       => 'Security settings saved',
        'error'       => 'Error: {error}',
        'save_failed' => 'Failed to save settings',
    ],

    // Single sign-on page (system/sso/index.php)
    'sso' => [
        'title'    => 'Single sign-on',
        'subtitle' => 'Let users sign in through an external identity provider (OpenID Connect) such as Keycloak, Microsoft Entra, Okta or Google — alongside local accounts.',

        'global_heading' => 'Global settings',
        'global_desc'    => 'Master controls for sign-on across the whole system.',
        'enable_sso'     => 'Enable single sign-on',
        'enable_sso_desc'=> 'Show the configured provider buttons on the login page. Turn off to instantly fall back to local logins everywhere (break-glass).',
        'allow_local'    => 'Allow local login',
        'allow_local_desc' => 'Keep the username + password form available. Leave on so a misconfigured or down provider can never lock everyone out.',
        'save'           => 'Save',

        'redirect_heading' => 'Redirect URI',
        'redirect_desc'    => "Register this exact URL in each identity provider as an allowed redirect / callback URL. It's where the provider sends users back after they sign in.",
        'copy'             => 'Copy',

        'providers_heading' => 'Identity providers',
        'providers_desc'    => 'Each provider is a separate IdP. Assign different users to different providers to run pilots in parallel.',
        'add'               => '+ Add',

        'col_name'        => 'Name',
        'col_issuer'      => 'Issuer',
        'col_status'      => 'Status',
        'col_auto_create' => 'Auto-create',
        'col_actions'     => 'Actions',

        'loading'        => 'Loading…',
        'no_providers'   => 'No providers yet. Click {add} to configure one.',
        'add_strong'     => 'Add',
        'enabled'        => 'Enabled',
        'disabled'       => 'Disabled',
        'jit_on'         => 'JIT on',
        'jit_off'        => 'Off',
        'edit'           => 'Edit',
        'delete'         => 'Delete',

        'modal_add_title'  => 'Add provider',
        'modal_edit_title' => 'Edit provider',
        'field_display_name' => 'Display name',
        'field_display_name_hint' => 'Shown on the login button, e.g. "Sign in with Keycloak"',
        'field_display_name_placeholder' => 'Sign in with Keycloak',
        'field_issuer'     => 'Issuer URL',
        'field_issuer_hint'=> "The provider's base URL. e.g. http://localhost:8080/realms/freeitsm",
        'field_issuer_placeholder' => 'https://your-idp/realms/your-realm',
        'test'             => 'Test',
        'field_client_id'  => 'Client ID',
        'field_client_id_hint' => 'The client/app identifier created in the provider, e.g. freeitsm-app',
        'field_client_secret' => 'Client secret',
        'field_client_secret_hint' => "The client's secret from the provider. Stored encrypted.",
        'field_scopes'     => 'Scopes',
        'field_scopes_hint'=> 'Space-separated OIDC scopes. Leave as default unless your provider needs more.',
        'cb_enabled'       => 'Enabled',
        'cb_enabled_desc'  => "Show this provider's button on the login page",
        'cb_autocreate'    => 'Auto-create users on first login (JIT)',
        'cb_autocreate_desc' => 'Create an analyst automatically the first time someone signs in via this provider. Leave off for tightly controlled pilots where only pre-created users may enter.',
        'cb_verified'      => 'Require a verified-email claim',
        'cb_verified_desc' => "Refuse sign-in unless the provider sends {claim}. Leave off for providers that omit the claim entirely (e.g. Okta's org server). An explicit {claim_false} is always refused regardless of this setting. Turn on only for IdPs where users can self-register with unverified addresses.",
        'field_default_modules' => 'Default module access for auto-created users',
        'field_default_modules_hint' => 'Comma-separated module keys granted to JIT-created analysts (e.g. {example}). {strong} — set this for pilots so auto-created users aren\'t admins.',
        'field_default_modules_strong' => 'Leave blank and they get full access to every module',
        'field_default_modules_placeholder' => 'tickets, knowledge',
        'cancel'           => 'Cancel',

        'global_saved'   => 'Global settings saved',
        'error'          => 'Error: {error}',
        'save_failed'    => 'Failed to save',
        'redirect_copied'=> 'Redirect URI copied',
        'enter_issuer'   => 'Enter an issuer URL first.',
        'discovery_ok'   => '✓ Discovery OK — issuer: {issuer}',
        'discovery_err'  => '✗ {error}',
        'request_failed' => '✗ Request failed',
        'secret_stored_placeholder' => '•••••••• (leave blank to keep current)',
        'secret_stored_hint' => 'A secret is already stored. Leave blank to keep it, or type a new one to replace it.',
        'required_fields' => 'Display name, issuer URL and client ID are required',
        'provider_saved'  => 'Provider saved',
        'delete_confirm'  => 'Delete "{name}"? Users assigned to it will revert to local login.',
        'delete_this'     => 'this provider',
        'provider_deleted'=> 'Provider deleted',
        'delete_failed'   => 'Failed to delete',
    ],
];
