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

        // Card search box. Keywords below carry search synonyms so e.g. typing
        // "oidc" finds Single Sign-On; they are never shown, only matched.
        'search_placeholder' => 'Search system areas…',
        'no_results'         => 'No system areas match your search.',

        'help_title' => 'Help & guides',
        'help_desc'  => 'Step-by-step guides for every system area, including single sign-on setup.',

        'topology_title'    => 'Topology',
        'topology_desc'     => 'See how companies, mailboxes, domains, sign-in and analysts fit together',
        'topology_keywords' => 'topology map overview tree relationships companies mailboxes domains analysts structure diagram graph',

        'orphaned_title'    => 'Orphaned tickets',
        'orphaned_desc'     => 'Find tickets stuck in a deleted department and reassign them',
        'orphaned_keywords' => 'orphaned tickets department missing deleted hidden reassign fix stuck lost broken',

        'encryption_title'  => 'Encryption',
        'encryption_desc'   => 'Generate and manage the encryption key used to protect sensitive data such as API keys and credentials.',
        'encryption_keywords' => 'encryption key master key crypto secrets credentials api keys cipher',
        'modules_title'     => 'Module Access',
        'modules_desc'      => 'Control which modules each analyst can access. Restrict visibility on the home screen and navigation menu.',
        'modules_keywords'  => 'module access permissions analyst rights visibility roles enable disable',
        'db_verify_title'   => 'Database Verify',
        'db_verify_desc'    => 'Check all tables and columns exist in the database. Automatically creates any that are missing.',
        'db_verify_keywords' => 'database verify schema tables columns migration repair sql db',
        'colours_title'     => 'Colours',
        'colours_desc'      => 'Customise the colour theme for each module. Changes apply to headers, icons, and the home screen.',
        'colours_keywords'  => 'colours colors theme palette appearance customise branding',
        'branding_title'    => 'Branding',
        'branding_desc'     => 'Upload the organisation logo and set default header/footer text for diagrams and exported documents.',
        'branding_keywords' => 'branding logo header footer organisation company export documents',
        'security_title'    => 'Security',
        'security_desc'     => 'Configure trusted device policies, password expiry, and account lockout settings.',
        'security_keywords' => 'security password expiry lockout trusted device mfa 2fa login policy brute force',
        'sso_title'         => 'Single Sign-On',
        'sso_desc'          => 'Connect external identity providers (OpenID Connect) such as Keycloak, Entra, Okta or Google so users can sign in with SSO.',
        'sso_keywords'      => 'sso single sign-on single sign on oidc openid connect saml identity provider idp keycloak entra azure ad okta google oauth federation login',
        'api_title'         => 'API',
        'api_desc'          => 'Create API keys with granular permissions, and explore the REST API with interactive documentation.',
        'api_keywords'      => 'api rest keys tokens integration webhook endpoints documentation swagger developer external',
        'preferences_title' => 'Preferences',
        'preferences_desc'  => 'Personal settings like notification position. These are saved per-browser and apply only to you.',
        'preferences_keywords' => 'preferences personal settings notifications toast position per-browser',
        'demo_data_title'   => 'Demo Data',
        'demo_data_desc'    => 'Import realistic sample data across all modules. Ideal for evaluation and testing on a fresh install.',
        'demo_data_keywords' => 'demo data sample seed test evaluation import fixtures example',
        'debug_tools_title' => 'Debug Tools',
        'debug_tools_desc'  => 'Library of diagnostics for troubleshooting failed flows. Run on request and send the output back to support.',
        'debug_tools_keywords' => 'debug tools diagnostics troubleshoot logs errors support fix',
        'companies_title'   => 'Companies',
        'companies_desc'    => 'Manage the client companies this install serves.',
        'companies_keywords' => 'companies clients tenants multi-tenancy multi tenant organisations msp',
        'routing_test_title' => 'Email routing test',
        'routing_test_desc'  => 'Dry-run an inbound email to see which company it would be filed to, and why.',
        'routing_test_keywords' => 'email routing test dry run mailbox sender domain triage tenant inbound diagnostic',
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

        'fix'         => 'Fix',
        'fixing'      => 'Fixing…',
        'fix_confirm' => 'Permanently delete {count} orphaned row(s) from {table}? Their parent record no longer exists, so this data is unreachable.',
        'fix_failed'  => 'Fix failed: {message}',

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
        'input_required' => 'Please enter a value before running this tool.',
        'search_placeholder' => 'Search debug tools…',
        'no_results'         => 'No debug tools match your search.',
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

        'panels_heading' => 'Left panels',
        'panels_desc'    => 'Choose, per module, whether the left panel stays pinned open or collapses to a thin strip that expands on hover. Modules with a settings page also offer this on their own Left panel tab.',
        'panel_knowledge'         => 'Knowledge',
        'panel_process_mapper'    => 'Process Mapper',
        'panel_contracts'         => 'Contracts',
        'panel_calendar'          => 'Calendar',
        'panel_tasks'             => 'Tasks',
        'panel_cmdb'              => 'CMDB',
        'panel_change_management' => 'Change Management',
        'panel_asset_management'  => 'Asset Management',
        'panel_system_wiki'       => 'System Wiki',

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
        'col_company'     => 'Company',
        'col_issuer'      => 'Issuer',
        'col_status'      => 'Status',
        'col_auto_create' => 'Auto-create',
        'col_actions'     => 'Actions',
        'global_badge'    => 'Global',

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
        'field_company'        => 'Company',
        'field_company_hint'   => 'Which client company owns this identity provider — its requesters are routed here on the self-service portal. Leave as Global for an MSP-internal provider (e.g. analyst sign-in).',
        'field_company_global' => 'Global (internal / all)',
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

    // Companies page (system/companies/index.php). "Company" is the
    // user-facing word for a tenant; the underlying table/code stays `tenants`.
    'companies' => [
        'title'    => 'Companies',
        'subtitle' => 'The client companies this install serves. Each new company is a separate space; the default company always stays active.',

        'add' => 'Add',

        'col_name'    => 'Name',
        'col_domains' => 'Email domains',
        'col_status'  => 'Status',
        'col_actions' => 'Actions',
        'domains_dash'  => '—',

        'loading'      => 'Loading…',
        'no_companies' => 'No companies yet. Click {add} to create one.',
        'add_strong'   => 'Add',
        'default'      => 'Default',
        'active'       => 'Active',
        'inactive'     => 'Inactive',
        'edit'         => 'Edit',

        'modal_add_title'  => 'Add company',
        'modal_edit_title' => 'Edit company',
        'field_name'       => 'Name',
        'field_name_hint'  => 'The company name shown across the app.',
        'field_name_placeholder' => 'Acme Ltd',
        'cb_active'        => 'Active',
        'cb_active_desc'   => 'Inactive companies are hidden from day-to-day use. The default company is always active.',
        'cancel'          => 'Cancel',
        'save'            => 'Save',

        'required_name' => 'Name is required',
        'company_saved' => 'Company saved',
        'error'         => 'Error: {error}',
        'save_failed'   => 'Failed to save',

        // Email domains (shared-intake routing)
        'domains_label'       => 'Email domains',
        'domains_hint'        => 'Mail from a shared-intake mailbox is routed to this company when the sender\'s domain matches one of these. Public providers (gmail.com, etc.) can\'t be added — that mail is filed by hand from triage.',
        'domains_save_first'  => 'Save the company first, then add its email domains.',
        'domains_none'        => 'No domains yet.',
        'domain_placeholder'  => 'acme.com',
        'domain_add'          => 'Add',
        'domain_remove'       => 'Remove',
        'domain_added'        => 'Domain added',
        'domain_removed'      => 'Domain removed',
        'domain_add_failed'   => 'Failed to add domain',
        'domain_remove_failed'=> 'Failed to remove domain',

        // Specific senders (shared-intake routing, address-level)
        'senders_label'       => 'Specific senders',
        'senders_hint'        => 'Individual addresses that route to this company, checked before the domain. Use this for people on public providers (jane@gmail.com) whose domain can\'t be mapped — their mail still reaches the right company instead of landing in triage.',
        'senders_none'        => 'No specific senders yet.',
        'sender_placeholder'  => 'jane@gmail.com',
        'sender_add'          => 'Add',
        'sender_remove'       => 'Remove',
        'sender_added'        => 'Sender added',
        'sender_removed'      => 'Sender removed',
        'sender_add_failed'   => 'Failed to add sender',
        'sender_remove_failed'=> 'Failed to remove sender',

        // "How email reaches this company" — derived, read-only routing summary.
        'routing_label'        => 'How email reaches this company',
        'routing_hint'         => 'A read-only summary, worked out from the mailboxes and domains above. Replies always go out from the same mailbox a message arrived on.',
        'routing_loading'      => 'Working out routing…',
        'routing_pinned'       => 'Dedicated mailbox',
        'routing_pinned_desc'  => 'Mail to {address} always belongs to this company. Replies go out from this address.',
        'routing_shared'       => 'Shared intake',
        'routing_shared_desc'  => 'Mail to {address} is routed here when the sender\'s domain is {domains}. Replies go out from this address.',
        'routing_shared_desc_senders' => 'Mail to {address} is routed here when the sender is {senders}. Replies go out from this address.',
        'routing_shared_desc_both'    => 'Mail to {address} is routed here when the sender\'s domain is {domains}, or the sender is {senders}. Replies go out from this address.',
        'routing_reply_from'   => 'Replies from {address}',
        'routing_inactive'     => 'inactive',
        'routing_unauth'       => 'not authenticated',
        'routing_default_note' => 'As the default company, it also receives any mail that matched no other company (the triage queue).',
        'routing_warn_no_route'   => 'No automatic email route. Mail for this company has to be filed by hand from the triage queue. Pin a mailbox to it, or register an email domain so shared intake can match it.',
        'routing_warn_domains_no_shared' => 'Domains are registered, but there\'s no active shared-intake mailbox to match them against. Add one, or pin a mailbox to this company.',
        'routing_warn_unauth'     => 'A mailbox on a route above is not authenticated, so mail won\'t flow until it\'s reconnected in Settings.',
        'routing_failed'       => 'Couldn\'t load the routing summary.',

        // Public email domains (global, add-only)
        'freemail_title'          => 'Public email domains',
        'freemail_hint'           => 'Mail from public providers like Gmail and Outlook is never auto-routed to a company — two clients can share the same provider, so it lands in triage to be filed by hand. The common providers are always included; add any others your clients use so they\'re handled the same way.',
        'freemail_placeholder'    => 'example-isp.com',
        'freemail_add'            => 'Add',
        'freemail_remove'         => 'Remove',
        'freemail_none'           => 'No extra domains added — only the built-in providers below.',
        'freemail_added'          => 'Domain added',
        'freemail_removed'        => 'Domain removed',
        'freemail_add_failed'     => 'Failed to add domain',
        'freemail_remove_failed'  => 'Failed to remove domain',
        'freemail_builtin_toggle' => 'Show the {count} built-in providers',
    ],

    // Email routing test — dry-run diagnostic (system/email-routing-test/).
    'routing_test' => [
        'title'    => 'Email routing test',
        'subtitle' => 'Pretend an email arrived, and see where a new ticket would be filed — which company, or the triage queue — and which rule decided it. Nothing is created; this only reads your mailbox and domain settings.',
        'single_company_note' => 'This install has just one company, so every email is filed to it. Add a second company for routing to have anything to decide.',

        'from_label'       => 'Sender address',
        'from_hint'        => 'The address the email is from. Shared-intake routing matches on the exact address first, then its domain.',
        'from_placeholder' => 'jane@acme.com',
        'mailbox_label'    => 'Arriving at mailbox',
        'mailbox_hint'     => 'The mailbox that received the email. A pinned mailbox decides the company outright; a shared-intake one routes by the sender\'s domain.',
        'mailbox_loading'  => 'Loading…',
        'mailbox_choose'   => 'Choose a mailbox…',
        'no_mailboxes'     => 'No mailboxes configured',
        'opt_pinned'       => 'Pinned to {company}',
        'opt_shared'       => 'Shared intake',
        'run'              => 'Test',
        'pick_mailbox'     => 'Choose a mailbox first',
        'failed'           => 'Routing test failed',

        'placeholder'          => 'Run a test to see where an email would land.',
        'result_company_label' => 'Filed to company',
        'result_triage_label'  => 'Sent to',
        'result_triage_value'  => 'Triage queue (unassigned)',
        'steps_title'          => 'How it was decided',

        'step_reply'          => 'Reply to an existing ticket?',
        'step_reply_detail'   => 'Checked first in reality (a reply inherits its ticket\'s company), but it depends on the subject carrying a ticket reference — so it can\'t be tested from a sender and mailbox alone.',
        'step_single'         => 'Single-company install',
        'step_single_detail'  => 'Only one company exists, so all mail is filed to {company}.',
        'step_pinned'         => 'Pinned mailbox?',
        'step_pinned_fired'   => '{mailbox} is pinned to {company}, so the email is filed there. The sender is ignored.',
        'step_pinned_skipped' => '{mailbox} is a shared-intake mailbox, so routing falls through to the sender.',
        'step_sender'         => 'Sender address mapped to a company?',
        'step_sender_fired'   => 'The address {address} is on {company}\'s specific-senders list, so the email is filed there. Checked before the domain.',
        'step_sender_nomatch' => 'No company has {address} on its specific-senders list, so routing falls through to the sender\'s domain.',
        'step_sender_noaddress'=> 'No sender address was given, so there\'s nothing to match.',
        'step_domain'         => 'Sender domain matches a company?',
        'step_domain_fired'   => 'Domain {domain} is registered to {company}.',
        'step_domain_freemail'=> '{domain} is a public email provider, which is never registered to a company — so it can\'t match here and goes to triage.',
        'step_domain_nomatch' => 'No company has registered {domain}.',
        'step_domain_nodomain'=> 'No sender domain was given, so there\'s nothing to match.',
        'step_triage'         => 'Triage queue',
        'step_triage_detail'  => 'Nothing matched, so the ticket is left un-companied and waits in the triage queue to be filed by hand. Nothing is lost.',
    ],
];
