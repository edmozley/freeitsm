<?php
/**
 * API Endpoint: Database Verification
 * Checks all tables and columns exist, creates any that are missing.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id']) && empty($_SESSION['setup_access'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

/**
 * Complete database schema definition (MySQL).
 * Each table maps to an array of columns.
 * Column format: 'column_name' => 'TYPE [NOT] NULL [DEFAULT ...]'
 * The first column with 'AUTO_INCREMENT' is the primary key.
 */
$schema = [

    'analysts' => [
        'id'                     => 'INT NOT NULL AUTO_INCREMENT',
        'username'               => 'VARCHAR(50) NOT NULL',
        'password_hash'          => 'VARCHAR(255) NOT NULL',
        'full_name'              => 'VARCHAR(100) NOT NULL',
        'email'                  => 'VARCHAR(100) NOT NULL',
        'is_active'              => 'TINYINT(1) NULL DEFAULT 1',
        'created_datetime'       => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'last_login_datetime'    => 'DATETIME NULL',
        'last_modified_datetime' => 'DATETIME NULL',
        'totp_secret'            => 'VARCHAR(500) NULL',
        'totp_enabled'           => 'TINYINT(1) NOT NULL DEFAULT 0',
        'trust_device_enabled'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        'password_changed_datetime' => 'DATETIME NULL',
        'failed_login_count'     => 'INT NOT NULL DEFAULT 0',
        'locked_until'           => 'DATETIME NULL',
        'auth_provider_id'       => 'INT NULL',
        'can_access_all_tenants' => 'TINYINT(1) NOT NULL DEFAULT 1',
        // Only administrators may enter the System module. New analysts default to
        // non-admin; existing analysts are grandfathered to admin on first upgrade
        // (see the one-time backfill below) so nobody is locked out.
        'is_admin'               => 'TINYINT(1) NOT NULL DEFAULT 0',
        // Module access (issue #30) — mirrors can_access_all_tenants. 1 = every
        // module; 0 = restricted to analyst_modules (+ any team grants). Defaults to
        // 1 so a new analyst is unrestricted; the upgrade back-fill sets it to 0 for
        // analysts who already had analyst_modules rows (i.e. were restricted).
        'can_access_all_modules' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ],

    'auth_providers' => [
        'id'                     => 'INT NOT NULL AUTO_INCREMENT',
        'display_name'           => 'VARCHAR(100) NOT NULL',
        'protocol'               => "VARCHAR(20) NOT NULL DEFAULT 'oidc'",
        'issuer_url'             => 'VARCHAR(500) NOT NULL',
        'client_id'              => 'VARCHAR(255) NOT NULL',
        'client_secret'          => 'VARCHAR(500) NULL',
        'scopes'                 => "VARCHAR(255) NOT NULL DEFAULT 'openid email profile'",
        'enabled'                => 'TINYINT(1) NOT NULL DEFAULT 1',
        'auto_create_users'      => 'TINYINT(1) NOT NULL DEFAULT 0',
        'require_verified_email' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'default_modules'        => 'VARCHAR(500) NULL',
        'sort_order'             => 'INT NOT NULL DEFAULT 0',
        'tenant_id'              => 'INT NULL',
        'created_datetime'       => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'last_modified_datetime' => 'DATETIME NULL',
    ],

    'analyst_sso_identities' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'          => 'INT NOT NULL',
        'provider_id'         => 'INT NOT NULL',
        'subject'             => 'VARCHAR(255) NOT NULL',
        'email'               => 'VARCHAR(100) NULL',
        'linked_datetime'     => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'last_login_datetime' => 'DATETIME NULL',
    ],

    'departments' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NULL DEFAULT 1',
        'display_order'     => 'INT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'teams' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(500) NULL',
        'display_order'     => 'INT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NULL DEFAULT 1',
        // Team company access. Defaults to 0 (grants nothing) — NOT 1 — so
        // existing teams don't silently widen their members' access on upgrade.
        'can_access_all_tenants' => 'TINYINT(1) NOT NULL DEFAULT 0',
        // Team module access (issue #30). Defaults to 0 (grants no modules) — same
        // reasoning: a team must be explicitly granted modules, and under the default
        // 'most' (union) mode a team defaulting to all would blow away every member's
        // individual restrictions. Grants are in team_modules.
        'can_access_all_modules' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'analyst_teams' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'team_id'           => 'INT NOT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'department_teams' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'department_id'     => 'INT NOT NULL',
        'team_id'           => 'INT NOT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Per-team module grants (issue #30) — the team twin of analyst_modules,
    // mirroring team_tenant_access. A row = "this team grants this module".
    'team_modules' => [
        'id'          => 'INT NOT NULL AUTO_INCREMENT',
        'team_id'     => 'INT NOT NULL',
        'module_key'  => 'VARCHAR(50) NOT NULL',
    ],

    'analyst_modules' => [
        'id'          => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'  => 'INT NOT NULL',
        'module_key'  => 'VARCHAR(50) NOT NULL',
    ],

    'user_preferences' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'preference_key'    => 'VARCHAR(100) NOT NULL',
        'preference_value'  => 'TEXT NULL',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NULL DEFAULT 1',
        'display_order'     => 'INT NULL DEFAULT 0',
        // Multi-tenancy: NULL = a global default type (shared by every company);
        // set = a type that company added for itself. Existing rows stay NULL, so
        // a single-company install is unaffected (all types are global). NB this
        // is the *config* meaning of tenant_id (NULL = global default) — different
        // from scoped data tables like `tickets` where NULL means "unrouted".
        'tenant_id'         => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_origins' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'display_order'     => 'INT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NULL DEFAULT 1',
        // Multi-tenancy: NULL = global default origin; set = a company's own.
        // (Config meaning of tenant_id — see ticket_types.) Existing rows stay
        // NULL, so a single-company install is unaffected.
        'tenant_id'         => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Multi-tenancy: the per-company "hide" layer for global config (the add+hide
    // override model — design §7). A row means "this company does NOT want global
    // <entity_type> #<entity_id> in its lists". Generic so one table serves every
    // overridable config type (ticket_type, ticket_origin, department, …). The
    // global row itself is never touched, so history/closed tickets still resolve
    // it — hiding only removes it from that company's pickers, and is reversible.
    'tenant_config_hidden' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'tenant_id'         => 'INT NOT NULL',
        'entity_type'       => 'VARCHAR(50) NOT NULL',
        'entity_id'         => 'INT NOT NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_prefixes' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'prefix'        => 'VARCHAR(3) NOT NULL',
        'description'   => 'VARCHAR(100) NULL',
        'department_id' => 'INT NULL',
        'is_default'    => 'TINYINT(1) NULL DEFAULT 0',
    ],

    'users' => [
        'id'              => 'INT NOT NULL AUTO_INCREMENT',
        'email'           => 'VARCHAR(255) NOT NULL',
        'display_name'    => 'VARCHAR(255) NULL',
        'preferred_name'  => 'VARCHAR(100) NULL',
        'password_hash'   => 'VARCHAR(255) NULL',
        'totp_secret'     => 'VARCHAR(500) NULL',
        'totp_enabled'    => 'TINYINT(1) NOT NULL DEFAULT 0',
        'auth_provider_id' => 'INT NULL',
        'created_at'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'user_sso_identities' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'user_id'             => 'INT NOT NULL',
        'provider_id'         => 'INT NOT NULL',
        'subject'             => 'VARCHAR(255) NOT NULL',
        'email'               => 'VARCHAR(255) NULL',
        'linked_datetime'     => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'last_login_datetime' => 'DATETIME NULL',
    ],

    'ticket_statuses' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'is_closed'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        'colour'            => 'VARCHAR(20) NULL',
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'pauses_sla'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_priorities' => [
        'id'                      => 'INT NOT NULL AUTO_INCREMENT',
        'name'                    => 'VARCHAR(50) NOT NULL',
        'colour'                  => 'VARCHAR(20) NULL',
        'is_default'              => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'           => 'INT NOT NULL DEFAULT 0',
        'is_active'               => 'TINYINT(1) NOT NULL DEFAULT 1',
        'sla_response_minutes'    => 'INT NULL',
        'sla_resolution_minutes'  => 'INT NULL',
        'sla_calendar_id'         => 'INT NULL',
        'created_datetime'        => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'sla_calendars' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'timezone'          => "VARCHAR(50) NOT NULL DEFAULT 'Europe/London'",
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],

    'sla_calendar_hours' => [
        'id'           => 'INT NOT NULL AUTO_INCREMENT',
        'calendar_id'  => 'INT NOT NULL',
        'weekday'      => 'TINYINT NOT NULL',
        'start_time'   => 'TIME NOT NULL',
        'end_time'     => 'TIME NOT NULL',
    ],

    'sla_calendar_holidays' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'calendar_id'   => 'INT NOT NULL',
        'holiday_date'  => 'DATE NOT NULL',
        'name'          => 'VARCHAR(100) NULL',
    ],

    'sla_notification_rules' => [
        'id'                       => 'INT NOT NULL AUTO_INCREMENT',
        'department_id'            => 'INT NULL',
        'trigger_type'             => "ENUM('warning','breach') NOT NULL",
        'target_type'              => "ENUM('response','resolution','both') NOT NULL DEFAULT 'both'",
        'notify_assignee'          => 'TINYINT(1) NOT NULL DEFAULT 0',
        'notify_department_teams'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'notify_analyst_id'        => 'INT NULL',
        'notify_emails'            => 'TEXT NULL',
        'is_active'                => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'         => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'         => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'sla_notifications_sent' => [
        'id'             => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'      => 'INT NOT NULL',
        'target_type'    => "ENUM('response','resolution') NOT NULL",
        'trigger_type'   => "ENUM('warning','breach') NOT NULL",
        'sent_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'recipients'     => 'TEXT NULL',
    ],

    'sla_cron_runs' => [
        'id'             => 'INT NOT NULL AUTO_INCREMENT',
        'started_at'     => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'ended_at'       => 'DATETIME NULL',
        'duration_ms'    => 'INT NULL',
        'invocation'     => "ENUM('cli','http') NOT NULL",
        'client_ip'      => 'VARCHAR(45) NULL',
        'outcome'        => "ENUM('ok','auth_failed','rate_limited','error','config_missing') NOT NULL",
        'sent_count'     => 'INT NULL DEFAULT 0',
        'skipped_count'  => 'INT NULL DEFAULT 0',
        'error_count'    => 'INT NULL DEFAULT 0',
        'notes'          => 'TEXT NULL',
    ],

    'tickets' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'tenant_id'             => 'INT NULL',
        'ticket_number'         => 'VARCHAR(50) NOT NULL',
        'subject'               => 'VARCHAR(500) NOT NULL',
        'status_id'             => 'INT NULL',
        'priority_id'           => 'INT NULL',
        'department_id'         => 'INT NULL',
        'ticket_type_id'        => 'INT NULL',
        'assigned_analyst_id'   => 'INT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'closed_datetime'       => 'DATETIME NULL',
        'origin_id'             => 'INT NULL',
        'first_time_fix'        => 'TINYINT(1) NULL',
        'it_training_provided'  => 'TINYINT(1) NULL',
        'user_id'               => 'INT NULL',
        'owner_id'              => 'INT NULL',
        'work_start_datetime'   => 'DATETIME NULL',
        'deleted_datetime'      => 'DATETIME NULL',
        'deleted_by'            => 'INT NULL',
        // Messaging channels: when the customer last messaged in (drives the 24h
        // provider service window on the reply box). NULL for non-channel tickets.
        'last_inbound_at'       => 'DATETIME NULL',
    ],

    'ticket_audit' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'         => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'field_name'        => 'VARCHAR(100) NOT NULL',
        'old_value'         => 'VARCHAR(500) NULL',
        'new_value'         => 'VARCHAR(500) NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_notes' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'         => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'note_text'         => 'LONGTEXT NOT NULL',
        'is_internal'       => 'TINYINT(1) NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_time_entries' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'           => 'INT NOT NULL',
        'analyst_id'          => 'INT NOT NULL',
        'notes'               => 'LONGTEXT NULL',
        'time_spent_minutes'  => 'INT NOT NULL',
        'entry_datetime'      => 'DATETIME NOT NULL',
        'is_active'           => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'    => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'    => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],

    // Multi-tenancy foundation — a single install can host multiple client
    // companies (tenants). Invisible until a second tenant exists.
    'tenants' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'name'             => 'VARCHAR(150) NOT NULL',
        'slug'             => 'VARCHAR(100) NULL',
        'is_default'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_active'        => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Domains owned by a tenant (used by shared-intake email routing).
    'tenant_domains' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'tenant_id'        => 'INT NOT NULL',
        'domain'           => 'VARCHAR(255) NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Admin-added public/free-email domains (gmail.com etc. are built into the
    // code; this table holds extra ones an MSP wants treated as public). These
    // are never mapped to a company — their mail is filed by hand from triage.
    'freemail_domains' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'domain'           => 'VARCHAR(255) NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Specific sender addresses mapped to a company (shared-intake routing). The
    // address-level twin of tenant_domains: matched before the domain, so a
    // personal/freemail address (jane@gmail.com) can route to a company even
    // though its domain is never mappable. UNIQUE so one address routes one way.
    'tenant_sender_addresses' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'tenant_id'        => 'INT NOT NULL',
        'email'            => 'VARCHAR(255) NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Messaging channel "inbox" — the channel twin of target_mailboxes. One row per
    // WhatsApp number wired to a provider (Twilio / Meta Cloud). Pinned (tenant_id set)
    // or shared intake (NULL, routed by sender phone). `credentials` = encrypted JSON
    // (per-provider shape). See includes/messaging/.
    'messaging_channels' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'name'                  => 'VARCHAR(100) NOT NULL',
        'channel_type'          => "VARCHAR(20) NOT NULL DEFAULT 'whatsapp'",
        'provider'              => "VARCHAR(20) NOT NULL DEFAULT 'twilio'",
        'phone_number'          => 'VARCHAR(40) NULL',
        'credentials'           => 'LONGTEXT NULL',
        'verify_token'          => 'VARCHAR(255) NULL',
        'ingress_mode'          => "VARCHAR(10) NOT NULL DEFAULT 'direct'",
        'relay_secret'          => 'VARCHAR(255) NULL',
        'tenant_id'             => 'INT NULL',
        'is_active'             => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'last_inbound_datetime' => 'DATETIME NULL',
    ],

    // Specific sender phone numbers mapped to a company (shared-intake channel
    // routing). Phone numbers have no domain, so for a shared channel an exact-number
    // map is the only routing key (else triage). UNIQUE so one number routes one way.
    'tenant_channel_senders' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'tenant_id'        => 'INT NOT NULL',
        'identifier'       => 'VARCHAR(64) NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Pre-approved provider message templates (replying after the WhatsApp 24h window).
    // FreeITSM stores the definition; the template is created/approved at the provider.
    // provider_ref = Twilio Content SID or Meta template name. language used by Meta.
    'messaging_templates' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'name'             => 'VARCHAR(100) NOT NULL',
        'provider'         => "VARCHAR(20) NOT NULL DEFAULT 'twilio'",
        'language'         => "VARCHAR(20) NOT NULL DEFAULT 'en'",
        'provider_ref'     => 'VARCHAR(255) NOT NULL',
        'body'             => 'LONGTEXT NOT NULL',
        'tenant_id'        => 'INT NULL',
        'is_active'        => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Which analysts may access which tenants (only consulted when an analyst
    // is NOT flagged can_access_all_tenants).
    'analyst_tenant_access' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'       => 'INT NOT NULL',
        'tenant_id'        => 'INT NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Which companies a TEAM grants its members (only consulted when the team
    // is NOT flagged can_access_all_tenants). Unioned with each member's own
    // analyst_tenant_access in getAccessibleTenantIds().
    'team_tenant_access' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'team_id'          => 'INT NOT NULL',
        'tenant_id'        => 'INT NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'target_mailboxes' => [
        'id'                      => 'INT NOT NULL AUTO_INCREMENT',
        'name'                    => 'VARCHAR(100) NOT NULL',
        'provider'                => "VARCHAR(20) NOT NULL DEFAULT 'microsoft'",
        'azure_tenant_id'         => 'TEXT NOT NULL',
        'azure_client_id'         => 'TEXT NOT NULL',
        'azure_client_secret'     => 'TEXT NOT NULL',
        'oauth_redirect_uri'      => 'TEXT NOT NULL',
        'oauth_scopes'            => 'VARCHAR(500) NOT NULL DEFAULT \'openid email offline_access User.Read Mail.Read Mail.ReadWrite Mail.Send\'',
        'imap_server'             => 'TEXT NOT NULL',
        'imap_port'               => 'INT NOT NULL DEFAULT 993',
        'imap_encryption'         => 'VARCHAR(10) NOT NULL DEFAULT \'ssl\'',
        // Basic IMAP / SMTP mailboxes: username + password auth (no OAuth). Encrypted
        // columns are TEXT NULL (empty on Microsoft/Google mailboxes).
        'imap_username'           => 'TEXT NULL',
        'imap_password'           => 'TEXT NULL',
        'smtp_server'             => 'TEXT NULL',
        'smtp_port'               => 'INT NULL DEFAULT 587',
        'smtp_encryption'         => 'VARCHAR(10) NULL DEFAULT \'tls\'',
        'target_mailbox'          => 'TEXT NOT NULL',
        // 'delegated' = OAuth sign-in (acts as the signed-in user, /me); 'app_only' =
        // client-credentials (the app reads the specific /users/<target_mailbox>).
        'auth_mode'               => "VARCHAR(20) NOT NULL DEFAULT 'delegated'",
        // The account actually authenticated in delegated mode (the primary address,
        // for display). Compared against target_mailbox to catch "reading the wrong
        // inbox". NULL = not yet authenticated / needs (re)authentication.
        'authenticated_as'        => 'VARCHAR(255) NULL',
        // JSON array of EVERY address the authenticated mailbox owns (primary SMTP, UPN
        // and aliases, from Graph proxyAddresses). The target matches if it's any of
        // these — so an alias (e.g. ed@ on the edmozley@ mailbox) is accepted, not flagged.
        'authenticated_addresses' => 'TEXT NULL',
        'token_data'              => 'LONGTEXT NULL',
        'email_folder'            => 'VARCHAR(100) NOT NULL DEFAULT \'INBOX\'',
        'max_emails_per_check'    => 'INT NOT NULL DEFAULT 10',
        'mark_as_read'            => 'TINYINT(1) NOT NULL DEFAULT 0',
        'rejected_action'         => 'VARCHAR(20) NOT NULL DEFAULT \'delete\'',
        'imported_action'         => 'VARCHAR(20) NOT NULL DEFAULT \'delete\'',
        'imported_folder'         => 'VARCHAR(100) NULL',
        'is_active'               => 'TINYINT(1) NOT NULL DEFAULT 1',
        'tenant_id'               => 'INT NULL',
        'created_datetime'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'last_checked_datetime'   => 'DATETIME NULL',
    ],

    'emails' => [
        'id'                      => 'INT NOT NULL AUTO_INCREMENT',
        'exchange_message_id'     => 'VARCHAR(255) NULL',
        'subject'                 => 'VARCHAR(500) NULL',
        'from_address'            => 'VARCHAR(255) NOT NULL',
        'from_name'               => 'VARCHAR(255) NULL',
        'to_recipients'           => 'LONGTEXT NULL',
        'cc_recipients'           => 'LONGTEXT NULL',
        'received_datetime'       => 'DATETIME NULL',
        'body_preview'            => 'LONGTEXT NULL',
        'body_content'            => 'LONGTEXT NULL',
        'body_type'               => 'VARCHAR(20) NULL',
        'has_attachments'         => 'TINYINT(1) NULL DEFAULT 0',
        'importance'              => 'VARCHAR(20) NULL',
        'is_read'                 => 'TINYINT(1) NULL DEFAULT 0',
        'processed_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'ticket_created'          => 'TINYINT(1) NULL DEFAULT 0',
        'ticket_id'               => 'INT NULL',
        'department_id'           => 'INT NULL',
        'ticket_type_id'          => 'INT NULL',
        'assigned_analyst_id'     => 'INT NULL',
        'status'                  => 'VARCHAR(50) NULL DEFAULT \'New\'',
        'assigned_datetime'       => 'DATETIME NULL',
        'is_initial'              => 'TINYINT(1) NULL DEFAULT 0',
        'direction'               => 'VARCHAR(20) NULL DEFAULT \'Inbound\'',
        'mailbox_id'              => 'INT NULL',
        // Which channel this message arrived/left on. 'email' (default) leaves every
        // existing row and the email pipeline untouched; 'whatsapp' reuses this table.
        'channel'                 => 'VARCHAR(20) NOT NULL DEFAULT \'email\'',
        // For channel messages: the messaging_channels row (which provider/number to
        // reply from). NULL for email.
        'channel_id'              => 'INT NULL',
    ],

    'email_attachments' => [
        'id'                        => 'INT NOT NULL AUTO_INCREMENT',
        'email_id'                  => 'INT NOT NULL',
        'exchange_attachment_id'    => 'VARCHAR(255) NULL',
        'filename'                  => 'VARCHAR(255) NOT NULL',
        'content_type'              => 'VARCHAR(100) NOT NULL',
        'content_id'                => 'VARCHAR(255) NULL',
        'file_path'                 => 'VARCHAR(500) NOT NULL',
        'file_size'                 => 'INT NOT NULL',
        'is_inline'                 => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_recordings' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'           => 'INT NULL',
        'recorded_by_user_id' => 'INT NULL',
        'filename'            => 'VARCHAR(255) NOT NULL',
        'original_filename'   => 'VARCHAR(255) NULL',
        'content_type'        => 'VARCHAR(100) NOT NULL',
        'file_path'           => 'VARCHAR(500) NOT NULL',
        'file_size'           => 'INT NOT NULL',
        'duration_seconds'    => 'INT NULL',
        'has_audio'           => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_at'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'mailbox_email_whitelist' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'mailbox_id'        => 'INT NOT NULL',
        'entry_type'        => 'VARCHAR(10) NOT NULL',
        'entry_value'       => 'VARCHAR(255) NOT NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'mailbox_activity_log' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'mailbox_id'        => 'INT NOT NULL',
        'action'            => 'VARCHAR(20) NOT NULL',
        'from_address'      => 'VARCHAR(255) NOT NULL',
        'from_name'         => 'VARCHAR(255) NULL',
        'subject'           => 'VARCHAR(500) NULL',
        'reason'            => 'VARCHAR(255) NULL',
        'ticket_id'         => 'INT NULL',
        'processing_log'    => 'TEXT NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_email_templates' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'event_trigger'     => 'VARCHAR(50) NOT NULL',
        'subject_template'  => 'VARCHAR(500) NOT NULL',
        'body_template'     => 'LONGTEXT NOT NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_csat_responses' => [
        'id'                 => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'          => 'INT NOT NULL',
        'token'              => 'VARCHAR(64) NOT NULL',
        'sent_datetime'      => 'DATETIME NULL',
        'responded_datetime' => 'DATETIME NULL',
        'rating'             => 'TINYINT NULL',
        'comment'            => 'TEXT NULL',
        'analyst_id'         => 'INT NULL',
        'created_at'         => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_rota_shifts' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'start_time'        => 'TIME NOT NULL',
        'end_time'          => 'TIME NOT NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rota_locations' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'colour'            => 'VARCHAR(20) NULL',
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_rota_entries' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'rota_date'         => 'DATE NOT NULL',
        'shift_id'          => 'INT NOT NULL',
        'location_id'       => 'INT NULL',
        'is_on_call'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'assets' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'hostname'          => 'VARCHAR(50) NULL',
        'manufacturer'      => 'VARCHAR(50) NULL',
        'model'             => 'VARCHAR(50) NULL',
        'memory'            => 'BIGINT NULL',
        'service_tag'       => 'VARCHAR(50) NULL',
        'operating_system'  => 'VARCHAR(50) NULL',
        'feature_release'   => 'VARCHAR(10) NULL',
        'build_number'      => 'VARCHAR(50) NULL',
        'cpu_name'          => 'VARCHAR(250) NULL',
        'speed'             => 'BIGINT NULL',
        'bios_version'      => 'VARCHAR(20) NULL',
        'first_seen'        => 'DATETIME NULL',
        'last_seen'         => 'DATETIME NULL',
        'asset_type_id'     => 'INT NULL',
        'asset_status_id'   => 'INT NULL',
        'location_id'       => 'INT NULL',
        'domain'            => 'VARCHAR(100) NULL',
        'logged_in_user'    => 'VARCHAR(100) NULL',
        'last_boot_utc'     => 'DATETIME NULL',
        'tpm_version'       => 'VARCHAR(50) NULL',
        'bitlocker_status'  => 'VARCHAR(20) NULL',
        'gpu_name'          => 'VARCHAR(250) NULL',
        'purchase_date'     => 'DATE NULL',
        'purchase_cost'     => 'DECIMAL(12,2) NULL',
        'supplier_id'       => 'INT NULL',
        'order_number'      => 'VARCHAR(100) NULL',
        'warranty_expiry'   => 'DATE NULL',
    ],

    'asset_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'asset_status_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Arbitrary-depth physical location tree (adjacency list). Self-ref FK +
    // parent index added in the post-schema section below.
    'asset_locations' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'parent_id'         => 'INT NULL',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'users_assets' => [
        'id'                        => 'INT NOT NULL AUTO_INCREMENT',
        'user_id'                   => 'INT NOT NULL',
        'asset_id'                  => 'INT NOT NULL',
        'assigned_datetime'         => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'assigned_by_analyst_id'    => 'INT NULL',
        'notes'                     => 'VARCHAR(500) NULL',
        'expected_return_date'      => 'DATE NULL',
    ],

    // Check-in / check-out custody trail. FK + index in post-schema section.
    'asset_checkout_log' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'asset_id'              => 'INT NOT NULL',
        'user_id'               => 'INT NULL',
        'user_name'             => 'VARCHAR(150) NULL',
        'action'                => 'VARCHAR(10) NOT NULL',
        'expected_return_date'  => 'DATE NULL',
        'analyst_id'            => 'INT NULL',
        'notes'                 => 'VARCHAR(500) NULL',
        'action_datetime'       => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'asset_history' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'asset_id'          => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'field_name'        => 'VARCHAR(100) NOT NULL',
        'old_value'         => 'VARCHAR(500) NULL',
        'new_value'         => 'VARCHAR(500) NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'asset_disks' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'asset_id'      => 'INT NOT NULL',
        'drive'         => 'VARCHAR(10) NULL',
        'label'         => 'VARCHAR(100) NULL',
        'file_system'   => 'VARCHAR(20) NULL',
        'size_bytes'    => 'BIGINT NULL',
        'free_bytes'    => 'BIGINT NULL',
        'used_percent'  => 'DECIMAL(5,1) NULL',
        'source'        => "VARCHAR(20) NOT NULL DEFAULT 'agent'",
    ],

    'asset_network_adapters' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'asset_id'      => 'INT NOT NULL',
        'name'          => 'VARCHAR(255) NULL',
        'mac_address'   => 'VARCHAR(17) NULL',
        'ip_address'    => 'VARCHAR(45) NULL',
        'subnet_mask'   => 'VARCHAR(45) NULL',
        'gateway'       => 'VARCHAR(45) NULL',
        'dhcp_enabled'  => 'TINYINT(1) NULL',
    ],

    'asset_devices' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'asset_id'          => 'INT NOT NULL',
        'device_class'      => 'VARCHAR(100) NULL',
        'device_name'       => 'VARCHAR(255) NOT NULL',
        'status'            => 'VARCHAR(20) NULL',
        'manufacturer'      => 'VARCHAR(255) NULL',
        'driver_version'    => 'VARCHAR(50) NULL',
        'driver_date'       => 'DATE NULL',
    ],

    'asset_dashboard_widgets' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'title'                 => 'VARCHAR(100) NOT NULL',
        'description'           => 'VARCHAR(255) NULL',
        'chart_type'            => "VARCHAR(20) NOT NULL DEFAULT 'bar'",
        'aggregate_property'    => 'VARCHAR(50) NOT NULL',
        'is_status_filterable'  => 'TINYINT(1) NOT NULL DEFAULT 1',
        'default_status_id'     => 'INT NULL',
        'display_order'         => 'INT NOT NULL DEFAULT 0',
        'is_active'             => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'analyst_dashboard_widgets' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'widget_id'         => 'INT NOT NULL',
        'sort_order'        => 'INT NOT NULL DEFAULT 0',
        'status_filter_id'  => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_dashboard_widgets' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'title'                 => 'VARCHAR(100) NOT NULL',
        'description'           => 'VARCHAR(255) NULL',
        'chart_type'            => "VARCHAR(20) NOT NULL DEFAULT 'bar'",
        'aggregate_property'    => 'VARCHAR(50) NOT NULL',
        'series_property'       => 'VARCHAR(20) NULL DEFAULT NULL',
        'is_status_filterable'  => 'TINYINT(1) NOT NULL DEFAULT 1',
        'default_status'        => 'VARCHAR(50) NULL',
        'date_range'            => 'VARCHAR(20) NULL DEFAULT NULL',
        'department_filter'     => 'JSON NULL',
        'time_grouping'         => 'VARCHAR(10) NULL DEFAULT NULL',
        'display_order'         => 'INT NOT NULL DEFAULT 0',
        'is_active'             => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'analyst_ticket_dashboard_widgets' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'widget_id'         => 'INT NOT NULL',
        'sort_order'        => 'INT NOT NULL DEFAULT 0',
        'status_filter'     => 'VARCHAR(50) NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'software_dashboard_widgets' => [
        'id'                        => 'INT NOT NULL AUTO_INCREMENT',
        'title'                     => 'VARCHAR(100) NOT NULL',
        'description'               => 'VARCHAR(255) NULL',
        'chart_type'                => "VARCHAR(20) NOT NULL DEFAULT 'bar'",
        'aggregate_property'        => "VARCHAR(50) NOT NULL DEFAULT 'version_distribution'",
        'app_id'                    => 'INT NULL',
        'exclude_system_components' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'             => 'INT NOT NULL DEFAULT 0',
        'is_active'                 => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'          => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'analyst_software_dashboard_widgets' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'widget_id'         => 'INT NOT NULL',
        'sort_order'        => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'servers' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'vm_id'                 => 'VARCHAR(100) NOT NULL',
        'name'                  => 'VARCHAR(255) NULL',
        'power_state'           => 'VARCHAR(20) NULL',
        'memory_gb'             => 'DECIMAL(10,2) NULL',
        'num_cpu'               => 'INT NULL',
        'ip_address'            => 'VARCHAR(50) NULL',
        'hard_disk_size_gb'     => 'DECIMAL(10,2) NULL',
        'host'                  => 'VARCHAR(255) NULL',
        'cluster'               => 'VARCHAR(255) NULL',
        'guest_os'              => 'VARCHAR(255) NULL',
        'last_synced'           => 'DATETIME NULL',
        'raw_data'              => 'LONGTEXT NULL',
    ],

    'intune_devices' => [
        'id'                            => 'INT NOT NULL AUTO_INCREMENT',
        'intune_id'                     => 'VARCHAR(64) NOT NULL',
        'asset_id'                      => 'INT NULL',
        'device_name'                   => 'VARCHAR(256) NULL',
        'user_principal_name'           => 'VARCHAR(256) NULL',
        'user_display_name'             => 'VARCHAR(256) NULL',
        'user_id'                       => 'VARCHAR(64) NULL',
        'operating_system'              => 'VARCHAR(64) NULL',
        'os_version'                    => 'VARCHAR(64) NULL',
        'compliance_state'              => 'VARCHAR(32) NULL',
        'management_state'              => 'VARCHAR(32) NULL',
        'managed_device_owner_type'     => 'VARCHAR(32) NULL',
        'device_enrollment_type'        => 'VARCHAR(64) NULL',
        'device_registration_state'     => 'VARCHAR(32) NULL',
        'enrolled_datetime'             => 'DATETIME NULL',
        'last_sync_datetime'            => 'DATETIME NULL',
        'model'                         => 'VARCHAR(128) NULL',
        'manufacturer'                  => 'VARCHAR(128) NULL',
        'serial_number'                 => 'VARCHAR(128) NULL',
        'imei'                          => 'VARCHAR(64) NULL',
        'meid'                          => 'VARCHAR(64) NULL',
        'wifi_mac_address'              => 'VARCHAR(64) NULL',
        'ethernet_mac_address'          => 'VARCHAR(64) NULL',
        'azure_ad_device_id'            => 'VARCHAR(64) NULL',
        'is_encrypted'                  => 'TINYINT(1) NULL',
        'is_supervised'                 => 'TINYINT(1) NULL',
        'jail_broken'                   => 'VARCHAR(16) NULL',
        'total_storage_bytes'           => 'BIGINT NULL',
        'free_storage_bytes'            => 'BIGINT NULL',
        'raw_json'                      => 'LONGTEXT NULL',
        'last_seen_local'               => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'intune_sync_jobs' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'started_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'finished_datetime'     => 'DATETIME NULL',
        'status'                => "VARCHAR(16) NOT NULL DEFAULT 'running'",
        'total'                 => 'INT NOT NULL DEFAULT 0',
        'processed'             => 'INT NOT NULL DEFAULT 0',
        'message'               => 'LONGTEXT NULL',
    ],

    'intune_app_sync_jobs' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'started_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'finished_datetime' => 'DATETIME NULL',
        'status'            => "VARCHAR(16) NOT NULL DEFAULT 'pending'",
        'total'             => 'INT NOT NULL DEFAULT 0',
        'processed'         => 'INT NOT NULL DEFAULT 0',
        'failed'            => 'INT NOT NULL DEFAULT 0',
        'message'           => 'LONGTEXT NULL',
    ],

    'intune_app_sync_job_assets' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'job_id'            => 'INT NOT NULL',
        'asset_id'          => 'INT NOT NULL',
        'status'            => "VARCHAR(16) NOT NULL DEFAULT 'pending'",
        'error_message'     => 'LONGTEXT NULL',
        'synced_datetime'   => 'DATETIME NULL',
        'app_count'         => 'INT NULL',
    ],

    'change_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'colour'            => 'VARCHAR(20) NULL',
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_statuses' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'is_closed'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        'colour'            => 'VARCHAR(20) NULL',
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_priorities' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'colour'            => 'VARCHAR(20) NULL',
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_impacts' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'colour'            => 'VARCHAR(20) NULL',
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Layout tables that drive the Change form's section structure.
    // Sections are user-editable (admin can add / rename / reorder / delete).
    // Each field_key in change_field_layout corresponds to a fixed slot in the
    // form (validated against a hardcoded catalogue in api/change-management/
    // get_field_layout.php) — what's configurable is which section the field
    // appears in, the order within that section, and whether it's visible.
    'change_field_sections' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_field_layout' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'field_key'         => 'VARCHAR(50) NOT NULL',
        'section_id'        => 'INT NOT NULL',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_visible'        => 'TINYINT(1) NOT NULL DEFAULT 1',
    ],

    'changes' => [
        'id'                            => 'INT NOT NULL AUTO_INCREMENT',
        'tenant_id'                     => 'INT NULL',
        'title'                         => 'VARCHAR(255) NOT NULL',
        'change_type_id'                => 'INT NULL',
        'status_id'                     => 'INT NULL',
        'priority_id'                   => 'INT NULL',
        'impact_id'                     => 'INT NULL',
        'category'                      => 'VARCHAR(100) NULL',
        'requester_id'                  => 'INT NULL',
        'assigned_to_id'                => 'INT NULL',
        'approver_id'                   => 'INT NULL',
        'approval_datetime'             => 'DATETIME NULL',
        'work_start_datetime'           => 'DATETIME NULL',
        'work_end_datetime'             => 'DATETIME NULL',
        'outage_start_datetime'         => 'DATETIME NULL',
        'outage_end_datetime'           => 'DATETIME NULL',
        'description'                   => 'LONGTEXT NULL',
        'reason_for_change'             => 'LONGTEXT NULL',
        'risk_evaluation'               => 'LONGTEXT NULL',
        'test_plan'                     => 'LONGTEXT NULL',
        'rollback_plan'                 => 'LONGTEXT NULL',
        'post_implementation_review'    => 'LONGTEXT NULL',
        'risk_likelihood'               => 'TINYINT NULL',
        'risk_impact_score'             => 'TINYINT NULL',
        'risk_score'                    => 'TINYINT NULL',
        'risk_level'                    => 'VARCHAR(20) NULL',
        'pir_was_successful'            => 'TINYINT(1) NULL',
        'pir_actual_start'              => 'DATETIME NULL',
        'pir_actual_end'                => 'DATETIME NULL',
        'pir_lessons_learned'           => 'LONGTEXT NULL',
        'pir_follow_up'                 => 'LONGTEXT NULL',
        'category_id'                   => 'INT NULL',
        'template_id'                   => 'INT NULL',
        'cab_required'                  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'cab_approval_type'             => 'VARCHAR(20) NOT NULL DEFAULT \'all\'',
        'created_by_id'                 => 'INT NULL',
        'created_datetime'              => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'modified_datetime'             => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_attachments' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'change_id'             => 'INT NOT NULL',
        'file_name'             => 'VARCHAR(255) NOT NULL',
        'file_path'             => 'VARCHAR(500) NOT NULL',
        'file_size'             => 'INT NULL',
        'file_type'             => 'VARCHAR(100) NULL',
        'uploaded_by_id'        => 'INT NULL',
        'uploaded_datetime'     => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_audit' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'change_id'         => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'action_type'       => 'VARCHAR(50) NOT NULL',
        'field_name'        => 'VARCHAR(100) NULL',
        'old_value'         => 'VARCHAR(1000) NULL',
        'new_value'         => 'VARCHAR(1000) NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_comments' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'change_id'         => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'comment_text'      => 'LONGTEXT NOT NULL',
        'is_internal'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_cab_members' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'change_id'         => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'is_required'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'vote'              => 'VARCHAR(20) NULL',
        'vote_comment'      => 'TEXT NULL',
        'vote_datetime'     => 'DATETIME NULL',
        'added_by_id'       => 'INT NULL',
        'added_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_checklist_items' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'change_id'             => 'INT NOT NULL',
        'description'           => 'VARCHAR(500) NOT NULL',
        'is_completed'          => 'TINYINT(1) NOT NULL DEFAULT 0',
        'completed_by_id'       => 'INT NULL',
        'completed_datetime'    => 'DATETIME NULL',
        'display_order'         => 'INT NOT NULL DEFAULT 0',
        'created_datetime'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_relations' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'change_id'         => 'INT NOT NULL',
        'related_type'      => 'VARCHAR(20) NOT NULL',
        'related_id'        => 'INT NOT NULL',
        'relation_type'     => 'VARCHAR(30) NOT NULL',
        'created_by_id'     => 'INT NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_categories' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_templates' => [
        'id'                        => 'INT NOT NULL AUTO_INCREMENT',
        'name'                      => 'VARCHAR(200) NOT NULL',
        'description'               => 'VARCHAR(500) NULL',
        'change_type_id'            => 'INT NULL',
        'priority_id'               => 'INT NULL',
        'impact_id'                 => 'INT NULL',
        'category_id'               => 'INT NULL',
        'risk_likelihood'           => 'TINYINT NULL',
        'risk_impact_score'         => 'TINYINT NULL',
        'description_template'      => 'LONGTEXT NULL',
        'reason_template'           => 'LONGTEXT NULL',
        'risk_template'             => 'LONGTEXT NULL',
        'test_plan_template'        => 'LONGTEXT NULL',
        'rollback_plan_template'    => 'LONGTEXT NULL',
        'is_active'                 => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'             => 'INT NOT NULL DEFAULT 0',
        'created_by_id'             => 'INT NULL',
        'created_datetime'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'change_notifications' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'change_id'         => 'INT NOT NULL',
        'notification_type' => 'VARCHAR(50) NOT NULL',
        'message'           => 'VARCHAR(500) NOT NULL',
        'is_read'           => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // ---- Problem Management ----
    // A Problem is the root cause behind one or more incidents (tickets). It carries
    // RCA, a workaround and a known-error flag, and links to the incidents it explains
    // (problem_tickets) and the change that fixes it (via change_relations). Company-
    // scoped via tenant_id like tickets (NULL = Default), invisible at N=1.
    'problems' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'tenant_id'           => 'INT NULL',
        'problem_number'      => 'VARCHAR(20) NULL',
        'title'               => 'VARCHAR(255) NOT NULL',
        'description'         => 'LONGTEXT NULL',
        'status_id'           => 'INT NULL',
        'priority_id'         => 'INT NULL',
        'assigned_analyst_id' => 'INT NULL',
        'root_cause'          => 'LONGTEXT NULL',
        'workaround'          => 'LONGTEXT NULL',
        'is_known_error'      => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_by_id'       => 'INT NULL',
        'created_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'closed_datetime'     => 'DATETIME NULL',
    ],

    'problem_statuses' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'name'             => 'VARCHAR(100) NOT NULL',
        'is_closed'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'colour'           => 'VARCHAR(20) NULL',
        'is_default'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'    => 'INT NOT NULL DEFAULT 0',
        'is_active'        => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'problem_priorities' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'name'             => 'VARCHAR(100) NOT NULL',
        'colour'           => 'VARCHAR(20) NULL',
        'is_default'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'    => 'INT NOT NULL DEFAULT 0',
        'is_active'        => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // The incident link: which tickets a problem explains. UNIQUE(problem_id,ticket_id).
    'problem_tickets' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'problem_id'       => 'INT NOT NULL',
        'ticket_id'        => 'INT NOT NULL',
        'created_by_id'    => 'INT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Ticket-to-ticket links (self-referential, typed): related / duplicate / parent.
    'ticket_links' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'source_ticket_id' => 'INT NOT NULL',
        'target_ticket_id' => 'INT NOT NULL',
        'relation_type'    => "VARCHAR(20) NOT NULL DEFAULT 'related'",
        'created_by_id'    => 'INT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Incidents (tickets) linked to a change — twin of problem_tickets.
    'change_tickets' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'change_id'        => 'INT NOT NULL',
        'ticket_id'        => 'INT NOT NULL',
        'created_by_id'    => 'INT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'problem_audit' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'problem_id'       => 'INT NOT NULL',
        'analyst_id'       => 'INT NOT NULL',
        'action_type'      => 'VARCHAR(20) NOT NULL',
        'field_name'       => 'VARCHAR(100) NULL',
        'old_value'        => 'VARCHAR(1000) NULL',
        'new_value'        => 'VARCHAR(1000) NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Free-text journal notes on a problem (who / when / the note). Distinct from
    // problem_audit, which logs structured field changes.
    'problem_notes' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'problem_id'       => 'INT NOT NULL',
        'analyst_id'       => 'INT NULL',
        'note'             => 'LONGTEXT NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'calendar_categories' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'name'          => 'VARCHAR(100) NOT NULL',
        'color'         => 'VARCHAR(7) NOT NULL DEFAULT \'#ef6c00\'',
        'description'   => 'VARCHAR(500) NULL',
        'is_active'     => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_at'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'calendar_events' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'title'             => 'VARCHAR(255) NOT NULL',
        'description'       => 'LONGTEXT NULL',
        'category_id'       => 'INT NULL',
        'start_datetime'    => 'DATETIME NOT NULL',
        'end_datetime'      => 'DATETIME NULL',
        'all_day'           => 'TINYINT(1) NOT NULL DEFAULT 0',
        'location'          => 'VARCHAR(255) NULL',
        'contract_id'       => 'INT NULL',
        'created_by'        => 'INT NOT NULL',
        'source'            => 'VARCHAR(30) NULL',
        'created_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'morningChecks_Checks' => [
        'CheckID'           => 'INT NOT NULL AUTO_INCREMENT',
        'CheckName'         => 'VARCHAR(255) NOT NULL',
        'CheckDescription'  => 'LONGTEXT NULL',
        'IsActive'          => 'TINYINT(1) NOT NULL DEFAULT 1',
        'SortOrder'         => 'INT NOT NULL DEFAULT 0',
        'CreatedDate'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'ModifiedDate'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'morningChecks_Results' => [
        'ResultID'      => 'INT NOT NULL AUTO_INCREMENT',
        'CheckID'       => 'INT NOT NULL',
        'CheckDate'     => 'DATETIME NOT NULL',
        // Normalised reference to morningChecks_Statuses.StatusID. NULL
        // is allowed for two cases: (a) pre-#424 rows whose Status label
        // didn't match any seeded status (orphans the admin needs to
        // normalise via Settings); (b) rows whose status was later
        // deleted (FK is ON DELETE SET NULL, with delete_status.php
        // snapshotting the label into Status first so the orphan keeps
        // its label for the normalisation tool).
        'StatusID'      => 'INT NULL',
        // Label snapshot — nullable now that StatusID is the source of
        // truth. Holds the original label string for orphan rows so the
        // normalisation tool in Settings can show "you have N results
        // with label X, map them to ...".
        'Status'        => 'VARCHAR(50) NULL',
        'Notes'         => 'LONGTEXT NULL',
        'CreatedBy'     => 'VARCHAR(100) NULL',
        'CreatedDate'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'ModifiedDate'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Configurable status options for morning checks. Drives the status
    // buttons on the dashboard (label + colour) and whether picking a
    // status pops the notes modal (RequiresNotes).
    'morningChecks_Statuses' => [
        'StatusID'        => 'INT NOT NULL AUTO_INCREMENT',
        'Label'           => 'VARCHAR(50) NOT NULL',
        'Colour'          => 'VARCHAR(20) NOT NULL',
        'RequiresNotes'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        'SortOrder'       => 'INT NOT NULL DEFAULT 0',
        'IsActive'        => 'TINYINT(1) NOT NULL DEFAULT 1',
        'CreatedDate'     => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'ModifiedDate'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'system_logs' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'log_type'          => 'VARCHAR(50) NOT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'analyst_id'        => 'INT NULL',
        'details'           => 'LONGTEXT NOT NULL',
    ],

    'system_settings' => [
        'setting_key'       => 'VARCHAR(100) NOT NULL',
        'setting_value'     => 'LONGTEXT NULL',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'trusted_devices' => [
        'id'                 => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'         => 'INT NOT NULL',
        'device_token_hash'  => 'VARCHAR(255) NOT NULL',
        'user_agent'         => 'VARCHAR(500) NULL',
        'ip_address'         => 'VARCHAR(45) NULL',
        'created_datetime'   => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'expires_datetime'   => 'DATETIME NOT NULL',
    ],

    'password_reset_tokens' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'token_hash'        => 'VARCHAR(255) NOT NULL',
        'expires_datetime'  => 'DATETIME NOT NULL',
        'used'              => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ip_login_bans' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'ip_address'        => 'VARCHAR(45) NOT NULL',
        'attempt_count'     => 'INT NOT NULL DEFAULT 0',
        'ban_count'         => 'INT NOT NULL DEFAULT 0',
        'banned_until'      => 'DATETIME NULL',
        'last_attempt'      => 'DATETIME NULL',
    ],

    'lms_courses' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'title'                 => 'VARCHAR(255) NOT NULL',
        'description'           => 'LONGTEXT NULL',
        'scorm_version'         => 'VARCHAR(20) NULL',
        'manifest_identifier'   => 'VARCHAR(255) NULL',
        'launch_url'            => 'VARCHAR(500) NULL',
        'original_filename'     => 'VARCHAR(255) NULL',
        'is_active'             => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_by_id'         => 'INT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'lms_learning_groups' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'name'                  => 'VARCHAR(100) NOT NULL',
        'description'           => 'VARCHAR(500) NULL',
        'is_active'             => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_by_id'         => 'INT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'lms_learning_group_members' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'group_id'              => 'INT NOT NULL',
        'analyst_id'            => 'INT NOT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'lms_course_assignments' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'course_id'             => 'INT NOT NULL',
        'group_id'              => 'INT NOT NULL',
        'deadline'              => 'DATETIME NULL',
        'assigned_by_id'        => 'INT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'lms_progress' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'            => 'INT NOT NULL',
        'course_id'             => 'INT NOT NULL',
        'status'                => "VARCHAR(20) NOT NULL DEFAULT 'not_started'",
        'score_raw'             => 'DECIMAL(10,2) NULL',
        'score_min'             => 'DECIMAL(10,2) NULL',
        'score_max'             => 'DECIMAL(10,2) NULL',
        'total_time'            => 'VARCHAR(50) NULL',
        'bookmark'              => 'VARCHAR(500) NULL',
        'suspend_data'          => 'LONGTEXT NULL',
        'completion_datetime'   => 'DATETIME NULL',
        'first_access'          => 'DATETIME NULL',
        'last_access'           => 'DATETIME NULL',
        'attempt_count'         => 'INT NOT NULL DEFAULT 0',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'lms_cmi_data' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'progress_id'           => 'INT NOT NULL',
        'element'               => 'VARCHAR(255) NOT NULL',
        'value'                 => 'LONGTEXT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'processes' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'title'             => 'VARCHAR(255) NOT NULL',
        'description'       => 'TEXT NULL',
        'created_by'        => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'process_steps' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'process_id'        => 'INT NOT NULL',
        'type'              => "VARCHAR(50) NOT NULL DEFAULT 'process'",
        'label'             => "VARCHAR(255) NOT NULL DEFAULT ''",
        'description'       => 'TEXT NULL',
        'url'               => 'VARCHAR(500) NULL',
        'x'                 => 'INT NOT NULL DEFAULT 0',
        'y'                 => 'INT NOT NULL DEFAULT 0',
        'width'             => 'INT NOT NULL DEFAULT 160',
        'height'            => 'INT NOT NULL DEFAULT 80',
        'color'             => "VARCHAR(20) NULL DEFAULT '#0078d4'",
        'color2'            => 'VARCHAR(20) NULL',
        'lane_id'           => 'INT NULL',
        'group_id'          => 'INT NULL',
    ],

    'process_annotations' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'process_id'        => 'INT NOT NULL',
        'text'              => 'TEXT NULL',
        'x'                 => 'INT NOT NULL DEFAULT 0',
        'y'                 => 'INT NOT NULL DEFAULT 0',
        'width'             => 'INT NOT NULL DEFAULT 180',
        'height'            => 'INT NOT NULL DEFAULT 100',
        'color'             => "VARCHAR(20) NULL DEFAULT '#fff59d'",
        'color2'            => 'VARCHAR(20) NULL',
    ],

    'process_connectors' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'process_id'        => 'INT NOT NULL',
        'from_step_id'      => 'INT NOT NULL',
        'to_step_id'        => 'INT NOT NULL',
        'label'             => "VARCHAR(255) NULL DEFAULT ''",
    ],

    'process_groups' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'process_id'        => 'INT NOT NULL',
        'label'             => "VARCHAR(100) NULL DEFAULT ''",
        'color'             => "VARCHAR(20) NULL DEFAULT '#e3f2fd'",
        'color2'            => 'VARCHAR(20) NULL',
        'x'                 => 'INT NOT NULL DEFAULT 0',
        'y'                 => 'INT NOT NULL DEFAULT 0',
        'width'             => 'INT NOT NULL DEFAULT 240',
        'height'            => 'INT NOT NULL DEFAULT 160',
    ],

    'process_lanes' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'process_id'        => 'INT NOT NULL',
        'label'             => "VARCHAR(100) NULL DEFAULT ''",
        'color'             => "VARCHAR(20) NULL DEFAULT '#f5f7fa'",
        'color2'            => 'VARCHAR(20) NULL',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'height'            => 'INT NOT NULL DEFAULT 180',
    ],

    'process_step_types' => [
        'id'             => 'INT NOT NULL AUTO_INCREMENT',
        'name'           => 'VARCHAR(100) NOT NULL',
        'slug'           => 'VARCHAR(50) NOT NULL',
        'shape'          => "VARCHAR(30) NOT NULL DEFAULT 'rounded'",
        'color'          => "VARCHAR(20) NOT NULL DEFAULT '#0078d4'",
        'display_order'  => 'INT NOT NULL DEFAULT 0',
        'is_active'      => 'TINYINT(1) NOT NULL DEFAULT 1',
        'is_builtin'     => 'TINYINT(1) NOT NULL DEFAULT 0',
    ],

    // Workflows module — automation engine with cross-module triggers.
    // conditions and actions are JSON-in-TEXT so the rule shape can evolve
    // without a schema migration each time the engine grows new operators
    // or action kinds.
    'workflows' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(255) NOT NULL',
        'description'       => 'TEXT NULL',
        'trigger_event'     => 'VARCHAR(100) NOT NULL',
        'conditions'        => 'TEXT NULL',
        'actions'           => 'TEXT NOT NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_by'        => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'last_run_datetime' => 'DATETIME NULL',
        'last_run_status'   => 'VARCHAR(20) NULL',
        'run_count'         => 'INT NOT NULL DEFAULT 0',
    ],

    'webhook_deliveries' => [
        'id'                 => 'INT NOT NULL AUTO_INCREMENT',
        'workflow_id'        => 'INT NULL',
        'execution_id'       => 'INT NULL',
        'preset'             => 'VARCHAR(20) NULL',
        'url'                => 'VARCHAR(2000) NOT NULL',
        'method'             => "VARCHAR(10) NOT NULL DEFAULT 'POST'",
        'request_headers'    => 'TEXT NULL',
        'request_body'       => 'MEDIUMTEXT NULL',
        'payload_purged'     => 'TINYINT(1) NOT NULL DEFAULT 0',
        'status'             => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'attempts'           => 'INT NOT NULL DEFAULT 0',
        'max_attempts'       => 'INT NOT NULL DEFAULT 6',
        'next_attempt_at'    => 'DATETIME NULL',
        'last_status_code'   => 'INT NULL',
        'last_error'         => 'VARCHAR(500) NULL',
        'response_snippet'   => 'MEDIUMTEXT NULL',
        'created_datetime'   => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'   => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'delivered_datetime' => 'DATETIME NULL',
    ],

    'workflow_executions' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'workflow_id'       => 'INT NULL',
        'workflow_name'     => 'VARCHAR(255) NULL',
        'trigger_event'     => 'VARCHAR(100) NOT NULL',
        'trigger_payload'   => 'TEXT NULL',
        'status'            => 'VARCHAR(20) NOT NULL',
        'is_dry_run'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'started_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'finished_datetime' => 'DATETIME NULL',
        'step_log'          => 'TEXT NULL',
        'error_message'     => 'TEXT NULL',
    ],

    'knowledge_articles' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'title'                 => 'VARCHAR(255) NOT NULL',
        'body'                  => 'LONGTEXT NULL',
        'author_id'             => 'INT NOT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'modified_datetime'     => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_published'          => 'TINYINT(1) NULL DEFAULT 1',
        'view_count'            => 'INT NULL DEFAULT 0',
        'next_review_date'      => 'DATE NULL',
        'owner_id'              => 'INT NULL',
        'embedding'             => 'LONGTEXT NULL',
        'embedding_updated'     => 'DATETIME NULL',
        'is_archived'           => 'TINYINT(1) NULL DEFAULT 0',
        'archived_datetime'     => 'DATETIME NULL',
        'archived_by_id'        => 'INT NULL',
        'version'               => 'INT NOT NULL DEFAULT 1',
    ],

    'knowledge_article_versions' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'article_id'        => 'INT NOT NULL',
        'version'           => 'INT NOT NULL',
        'title'             => 'VARCHAR(255) NOT NULL',
        'body'              => 'LONGTEXT NULL',
        'saved_by_id'       => 'INT NOT NULL',
        'saved_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'knowledge_tags' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'knowledge_article_tags' => [
        'article_id'    => 'INT NOT NULL',
        'tag_id'        => 'INT NOT NULL',
    ],

    'software_inventory_apps' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'display_name'      => 'VARCHAR(512) NOT NULL',
        'publisher'         => 'VARCHAR(512) NULL',
        'first_detected'    => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'software_inventory_detail' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'host_id'           => 'INT NOT NULL',
        'app_id'            => 'INT NOT NULL',
        'display_version'   => 'VARCHAR(100) NULL',
        'install_date'      => 'VARCHAR(50) NULL',
        'uninstall_string'  => 'LONGTEXT NULL',
        'install_location'  => 'LONGTEXT NULL',
        'estimated_size'    => 'VARCHAR(100) NULL',
        'system_component'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'last_seen'         => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'source'            => "VARCHAR(20) NOT NULL DEFAULT 'agent'",
    ],

    'software_licences' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'app_id'            => 'INT NOT NULL',
        'licence_type'      => 'VARCHAR(50) NOT NULL',
        'licence_key'       => 'VARCHAR(500) NULL',
        'quantity'          => 'INT NULL',
        'renewal_date'      => 'DATE NULL',
        'notice_period_days'=> 'INT NULL',
        'portal_url'        => 'VARCHAR(500) NULL',
        'cost'              => 'DECIMAL(10,2) NULL',
        'currency'          => 'VARCHAR(10) NULL DEFAULT \'GBP\'',
        'purchase_date'     => 'DATE NULL',
        'vendor_contact'    => 'VARCHAR(500) NULL',
        'notes'             => 'LONGTEXT NULL',
        'status'            => 'VARCHAR(20) NOT NULL DEFAULT \'Active\'',
        'created_by'        => 'INT NULL',
        'created_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Ingest log for software-inventory agent submissions — the submit
    // endpoint always wrote here but the table was never defined (silent
    // try/catch), so logging never worked. Defined 2026-07-03.
    'software_inventory_log' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'host_id'          => 'INT NULL',
        'api_response'     => 'LONGTEXT NULL',
        'created_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'apikeys' => [
        'id'         => 'INT NOT NULL AUTO_INCREMENT',
        'apikey'     => 'VARCHAR(50) NULL',
        'analyst_id' => 'INT NULL',
        'label'      => 'VARCHAR(100) NULL',
        'datestamp'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'active'     => 'TINYINT(1) NULL DEFAULT 1',
    ],

    'api_rate_limits' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'apikey_id'     => 'INT NOT NULL',
        'request_count' => 'INT NOT NULL DEFAULT 0',
        'window_start'  => 'DATETIME NOT NULL',
    ],

    // REST API v1 keys (System > API) — distinct from the legacy `apikeys`
    // table above (api/external ingest): stored hashed, granular permission
    // map (JSON), optional company scope, acts as an analyst.
    'api_keys' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'name'                  => 'VARCHAR(100) NOT NULL',
        'key_prefix'            => 'VARCHAR(16) NOT NULL',
        'key_hash'              => 'CHAR(64) NOT NULL',
        'analyst_id'            => 'INT NOT NULL',
        'permissions'           => 'LONGTEXT NULL',
        'company_ids'           => 'TEXT NULL',
        'rate_limit_per_minute' => 'INT NULL',
        'active'                => 'TINYINT(1) NOT NULL DEFAULT 1',
        'expires_at'            => 'DATETIME NULL',
        'last_used_at'          => 'DATETIME NULL',
        'last_used_ip'          => 'VARCHAR(45) NULL',
        'created_by'            => 'INT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'api_key_rate_limits' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'api_key_id'    => 'INT NOT NULL',
        'request_count' => 'INT NOT NULL DEFAULT 0',
        'window_start'  => 'DATETIME NOT NULL',
    ],

    'task_statuses' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'is_closed'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        'colour'            => 'VARCHAR(20) NULL',
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'task_priorities' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'colour'            => 'VARCHAR(20) NULL',
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'tasks' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'title'               => 'VARCHAR(255) NOT NULL',
        'description'         => 'LONGTEXT NULL',
        'status_id'           => 'INT NULL',
        'priority_id'         => 'INT NULL',
        'start_date'          => 'DATE NULL',
        'due_date'            => 'DATE NULL',
        'assigned_analyst_id' => 'INT NULL',
        'assigned_team_id'    => 'INT NULL',
        'parent_task_id'      => 'INT NULL',
        'ticket_id'           => 'INT NULL',
        'change_id'           => 'INT NULL',
        'contract_id'         => 'INT NULL',
        'board_position'      => 'INT NOT NULL DEFAULT 0',
        'created_by_id'       => 'INT NOT NULL',
        'created_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'completed_datetime'  => 'DATETIME NULL',
    ],

    'task_tags' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'colour'            => 'VARCHAR(20) NULL',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'task_tag_map' => [
        'task_id' => 'INT NOT NULL',
        'tag_id'  => 'INT NOT NULL',
    ],

    'task_comments' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'task_id'           => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'comment'           => 'LONGTEXT NOT NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'forms' => [
        'id'             => 'INT NOT NULL AUTO_INCREMENT',
        'title'          => 'VARCHAR(255) NOT NULL',
        'description'    => 'LONGTEXT NULL',
        'is_active'      => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_by'     => 'INT NULL',
        'created_date'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'modified_by'    => 'INT NULL',
        'modified_date'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        // Versioning (#442): a form's history is a chain of rows, each
        // pointing back at its predecessor via parent_form_id. The leaf
        // (no children) is the current editable version; older rows are
        // frozen snapshots. version_number is the position in the
        // chain — set on create / clone, NEVER incremented by an
        // in-place save (regular Save just updates modified_by/date).
        'parent_form_id' => 'INT NULL',
        'version_number' => 'INT NOT NULL DEFAULT 1',
    ],

    'form_fields' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'form_id'       => 'INT NOT NULL',
        'field_type'    => 'VARCHAR(50) NOT NULL',
        'label'         => 'VARCHAR(255) NOT NULL',
        'options'       => 'LONGTEXT NULL',
        'is_required'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sort_order'    => 'INT NOT NULL DEFAULT 0',
    ],

    'form_submissions' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'form_id'           => 'INT NOT NULL',
        'submitted_by'      => 'INT NULL',
        'submitted_date'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'form_submission_data' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'submission_id' => 'INT NOT NULL',
        'field_id'      => 'INT NOT NULL',
        'field_value'   => 'LONGTEXT NULL',
    ],

    'wiki_scan_runs' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'started_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'completed_at'      => 'DATETIME NULL',
        'status'            => 'VARCHAR(20) NOT NULL DEFAULT \'running\'',
        'files_scanned'     => 'INT NOT NULL DEFAULT 0',
        'functions_found'   => 'INT NOT NULL DEFAULT 0',
        'classes_found'     => 'INT NOT NULL DEFAULT 0',
        'error_message'     => 'LONGTEXT NULL',
        'scanned_by'        => 'VARCHAR(100) NULL',
    ],

    'wiki_files' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'scan_id'           => 'INT NOT NULL',
        'file_path'         => 'VARCHAR(500) NOT NULL',
        'file_name'         => 'VARCHAR(255) NOT NULL',
        'folder_path'       => 'VARCHAR(500) NOT NULL',
        'file_type'         => 'VARCHAR(10) NOT NULL',
        'file_size_bytes'   => 'BIGINT NOT NULL DEFAULT 0',
        'line_count'        => 'INT NOT NULL DEFAULT 0',
        'last_modified'     => 'DATETIME NULL',
        'description'       => 'LONGTEXT NULL',
        'created_date'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'wiki_functions' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'file_id'           => 'INT NOT NULL',
        'function_name'     => 'VARCHAR(255) NOT NULL',
        'line_number'       => 'INT NOT NULL',
        'end_line_number'   => 'INT NULL',
        'parameters'        => 'LONGTEXT NULL',
        'class_name'        => 'VARCHAR(255) NULL',
        'visibility'        => 'VARCHAR(20) NULL',
        'is_static'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        'description'       => 'LONGTEXT NULL',
    ],

    'wiki_classes' => [
        'id'                        => 'INT NOT NULL AUTO_INCREMENT',
        'file_id'                   => 'INT NOT NULL',
        'class_name'                => 'VARCHAR(255) NOT NULL',
        'line_number'               => 'INT NOT NULL',
        'extends_class'             => 'VARCHAR(255) NULL',
        'implements_interfaces'     => 'LONGTEXT NULL',
        'description'               => 'LONGTEXT NULL',
    ],

    'wiki_dependencies' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'file_id'           => 'INT NOT NULL',
        'dependency_type'   => 'VARCHAR(50) NOT NULL',
        'target_path'       => 'VARCHAR(500) NOT NULL',
        'resolved_file_id'  => 'INT NULL',
        'line_number'       => 'INT NULL',
    ],

    'wiki_function_calls' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'file_id'       => 'INT NOT NULL',
        'function_name' => 'VARCHAR(255) NOT NULL',
        'line_number'   => 'INT NULL',
    ],

    'wiki_db_references' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'file_id'           => 'INT NOT NULL',
        'table_name'        => 'VARCHAR(255) NOT NULL',
        'reference_type'    => 'VARCHAR(50) NOT NULL',
        'line_number'       => 'INT NULL',
    ],

    'wiki_session_vars' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'file_id'       => 'INT NOT NULL',
        'variable_name' => 'VARCHAR(255) NOT NULL',
        'access_type'   => 'VARCHAR(10) NOT NULL',
        'line_number'   => 'INT NULL',
    ],

    // Contracts module
    'supplier_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'supplier_statuses' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'suppliers' => [
        'id'                            => 'INT NOT NULL AUTO_INCREMENT',
        'legal_name'                    => 'VARCHAR(255) NOT NULL',
        'trading_name'                  => 'VARCHAR(255) NULL',
        'reg_number'                    => 'VARCHAR(50) NULL',
        'vat_number'                    => 'VARCHAR(50) NULL',
        'supplier_type_id'              => 'INT NULL',
        'supplier_status_id'            => 'INT NULL',
        'address_line_1'                => 'VARCHAR(255) NULL',
        'address_line_2'                => 'VARCHAR(255) NULL',
        'city'                          => 'VARCHAR(100) NULL',
        'county'                        => 'VARCHAR(100) NULL',
        'postcode'                      => 'VARCHAR(20) NULL',
        'country'                       => 'VARCHAR(100) NULL',
        'questionnaire_date_issued'     => 'DATE NULL',
        'questionnaire_date_received'   => 'DATE NULL',
        'comments'                      => 'LONGTEXT NULL',
        'is_active'                     => 'TINYINT(1) NOT NULL DEFAULT 1',
        'supplies_assets'               => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'              => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'contacts' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'supplier_id'       => 'INT NULL',
        'first_name'        => 'VARCHAR(100) NOT NULL',
        'surname'           => 'VARCHAR(100) NOT NULL',
        'email'             => 'VARCHAR(255) NULL',
        'mobile'            => 'VARCHAR(50) NULL',
        'job_title'         => 'VARCHAR(100) NULL',
        'direct_dial'       => 'VARCHAR(50) NULL',
        'switchboard'       => 'VARCHAR(50) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'contract_statuses' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'payment_schedules' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'contract_term_tabs' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'contract_term_values' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'contract_id'       => 'INT NOT NULL',
        'term_tab_id'       => 'INT NOT NULL',
        'content'           => 'LONGTEXT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'contracts' => [
        'id'                        => 'INT NOT NULL AUTO_INCREMENT',
        'contract_number'           => 'VARCHAR(50) NOT NULL',
        'title'                     => 'VARCHAR(255) NOT NULL',
        'description'               => 'LONGTEXT NULL',
        'supplier_id'               => 'INT NULL',
        'contract_owner_id'         => 'INT NULL',
        'contract_status_id'        => 'INT NULL',
        'contract_start'            => 'DATE NULL',
        'contract_end'              => 'DATE NULL',
        'notice_period_days'        => 'INT NULL',
        'notice_date'               => 'DATE NULL',
        'contract_value'            => 'DECIMAL(18,2) NULL',
        'currency'                  => 'VARCHAR(3) NULL',
        'payment_schedule_id'       => 'INT NULL',
        'cost_centre'               => 'VARCHAR(100) NULL',
        'dms_link'                  => 'VARCHAR(500) NULL',
        'terms_status'              => 'VARCHAR(20) NULL',
        'personal_data_transferred' => 'TINYINT(1) NULL',
        'dpia_required'             => 'TINYINT(1) NULL',
        'dpia_completed_date'       => 'DATE NULL',
        'dpia_dms_link'             => 'VARCHAR(500) NULL',
        'is_active'                 => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'          => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // RFP Builder (feature of the Contracts module)
    'rfps' => [
        'id'                       => 'INT NOT NULL AUTO_INCREMENT',
        'name'                     => 'VARCHAR(200) NOT NULL',
        'status'                   => "VARCHAR(50) NOT NULL DEFAULT 'draft'",
        'contract_id'              => 'INT NULL',
        'chosen_supplier_id'       => 'INT NULL',
        'style_guide'              => 'LONGTEXT NULL',
        'framing_context_text'     => 'LONGTEXT NULL',
        'created_by_analyst_id'    => 'INT NULL',
        'created_datetime'         => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'         => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rfp_departments' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'colour'            => "VARCHAR(7) NOT NULL DEFAULT '#6c757d'",
        'sort_order'        => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rfp_categories' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'rfp_id'            => 'INT NOT NULL',
        'name'              => 'VARCHAR(200) NOT NULL',
        'description'       => 'LONGTEXT NULL',
        'sort_order'        => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rfp_documents' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'rfp_id'            => 'INT NOT NULL',
        'department_id'     => 'INT NULL',
        'filename'          => 'VARCHAR(255) NOT NULL',
        'original_filename' => 'VARCHAR(255) NOT NULL',
        'file_path'         => 'VARCHAR(500) NOT NULL',
        'raw_text'          => 'LONGTEXT NULL',
        'status'            => "VARCHAR(50) NOT NULL DEFAULT 'uploaded'",
        'uploaded_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rfp_extracted_requirements' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'rfp_id'            => 'INT NOT NULL',
        'document_id'       => 'INT NOT NULL',
        'requirement_text'  => 'LONGTEXT NOT NULL',
        'requirement_type'  => "VARCHAR(50) NOT NULL DEFAULT 'requirement'",
        'source_quote'      => 'LONGTEXT NULL',
        'ai_confidence'     => 'DECIMAL(3,2) NULL',
        'is_consolidated'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rfp_consolidated_requirements' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'rfp_id'            => 'INT NOT NULL',
        'category_id'       => 'INT NULL',
        'requirement_text'  => 'LONGTEXT NOT NULL',
        'requirement_type'  => "VARCHAR(50) NOT NULL DEFAULT 'requirement'",
        'priority'          => "VARCHAR(20) NOT NULL DEFAULT 'medium'",
        'ai_rationale'      => 'LONGTEXT NULL',
        'is_locked'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rfp_consolidated_sources' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'consolidated_id'   => 'INT NOT NULL',
        'extracted_id'      => 'INT NOT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rfp_conflicts' => [
        'id'                       => 'INT NOT NULL AUTO_INCREMENT',
        'rfp_id'                   => 'INT NOT NULL',
        'consolidated_id_a'        => 'INT NOT NULL',
        'consolidated_id_b'        => 'INT NOT NULL',
        'ai_explanation'           => 'LONGTEXT NULL',
        'resolution'               => "VARCHAR(50) NOT NULL DEFAULT 'open'",
        'resolution_notes'         => 'LONGTEXT NULL',
        'resolved_by_analyst_id'   => 'INT NULL',
        'resolved_datetime'        => 'DATETIME NULL',
        'created_datetime'         => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rfp_output_sections' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'rfp_id'              => 'INT NOT NULL',
        'category_id'         => 'INT NOT NULL',
        'section_title'       => 'VARCHAR(300) NOT NULL',
        'section_content'     => 'LONGTEXT NULL',
        'version'             => 'INT NOT NULL DEFAULT 1',
        'is_manually_edited'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'requirements_hash'   => 'VARCHAR(64) NULL',
        'generated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'edited_datetime'     => 'DATETIME NULL',
    ],

    'rfp_section_history' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'section_id'          => 'INT NOT NULL',
        'version'             => 'INT NOT NULL',
        'section_content'     => 'LONGTEXT NULL',
        'is_manually_edited'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'    => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rfp_document_sections' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'rfp_id'              => 'INT NOT NULL',
        'section_key'         => 'VARCHAR(50) NOT NULL',
        'section_title'       => 'VARCHAR(200) NOT NULL',
        'section_content'     => 'LONGTEXT NULL',
        'sort_order'          => 'INT NOT NULL DEFAULT 0',
        'is_manually_edited'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'inputs_hash'         => 'VARCHAR(64) NULL',
        'generated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'edited_datetime'     => 'DATETIME NULL',
    ],

    'rfp_invited_suppliers' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'rfp_id'            => 'INT NOT NULL',
        'supplier_id'       => 'INT NOT NULL',
        'invited_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'demo_date'         => 'DATE NULL',
        'notes'             => 'LONGTEXT NULL',
    ],

    'rfp_scores' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'rfp_id'            => 'INT NOT NULL',
        'supplier_id'       => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'consolidated_id'   => 'INT NOT NULL',
        'score'             => 'INT NULL',
        'notes'             => 'LONGTEXT NULL',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rfp_processing_log' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'rfp_id'            => 'INT NOT NULL',
        'document_id'       => 'INT NULL',
        'section_id'        => 'INT NULL',
        'action'            => 'VARCHAR(100) NOT NULL',
        'status'            => 'VARCHAR(50) NOT NULL',
        'details'           => 'LONGTEXT NULL',
        'tokens_in'         => 'INT NULL',
        'tokens_out'        => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Service Status module
    'status_services' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(500) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'service_incident_statuses' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'is_resolved'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'colour'            => 'VARCHAR(20) NULL',
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'service_impact_levels' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(50) NOT NULL',
        'colour'            => 'VARCHAR(20) NULL',
        'is_default'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'severity_order'    => 'INT NOT NULL DEFAULT 99',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'status_incidents' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'title'                 => 'VARCHAR(255) NOT NULL',
        'status_id'             => 'INT NULL',
        'comment'               => 'LONGTEXT NULL',
        'created_by_id'         => 'INT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'resolved_datetime'     => 'DATETIME NULL',
    ],

    'status_incident_services' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'incident_id'       => 'INT NOT NULL',
        'service_id'        => 'INT NOT NULL',
        'impact_level_id'   => 'INT NULL',
    ],

    // CMDB ----------------------------------------------------------
    'cmdb_icons' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'icon_key'          => 'VARCHAR(50) NOT NULL',
        'label'             => 'VARCHAR(100) NOT NULL',
        'display_order'     => 'INT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NULL DEFAULT 1',
    ],

    'cmdb_classes' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'class_key'         => 'VARCHAR(100) NOT NULL',
        'name'              => 'VARCHAR(150) NOT NULL',
        'description'       => 'VARCHAR(500) NULL',
        'icon_id'           => 'INT NULL',
        'display_order'     => 'INT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'cmdb_class_properties' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'class_id'          => 'INT NOT NULL',
        'property_key'      => 'VARCHAR(100) NOT NULL',
        'label'             => 'VARCHAR(150) NOT NULL',
        'property_type'     => 'VARCHAR(20) NOT NULL',
        'target_class_id'   => 'INT NULL',
        'is_required'       => 'TINYINT(1) NULL DEFAULT 0',
        'display_order'     => 'INT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'cmdb_class_property_options' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'property_id'       => 'INT NOT NULL',
        'option_value'      => 'VARCHAR(255) NOT NULL',
        'colour'            => 'VARCHAR(7) NULL',
        'display_order'     => 'INT NULL DEFAULT 0',
    ],

    'cmdb_objects' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'class_id'          => 'INT NOT NULL',
        'name'              => 'VARCHAR(255) NOT NULL',
        'parent_id'         => 'INT NULL',
        'is_planned'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'ai_summary'        => 'LONGTEXT NULL',
        'ai_summary_generated_at' => 'DATETIME NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'cmdb_object_properties' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'object_id'         => 'INT NOT NULL',
        'property_id'       => 'INT NOT NULL',
        'value_text'        => 'TEXT NULL',
        'value_number'      => 'DECIMAL(20,4) NULL',
        'value_date'        => 'DATETIME NULL',
        'value_boolean'     => 'TINYINT(1) NULL',
        'value_object_id'   => 'INT NULL',
    ],

    'cmdb_relationship_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'verb'              => 'VARCHAR(100) NOT NULL',
        'inverse_verb'      => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(500) NULL',
        'display_order'     => 'INT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'cmdb_object_relationships' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'from_object_id'        => 'INT NOT NULL',
        'to_object_id'          => 'INT NOT NULL',
        'relationship_type_id'  => 'INT NOT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_cmdb_objects' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'             => 'INT NOT NULL',
        'cmdb_object_id'        => 'INT NOT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'created_by_analyst_id' => 'INT NULL',
    ],

    // Network Mapper — visual diagrams over the CMDB graph (see freeitsm.sql header).
    'network_diagrams' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'parent_diagram_id'     => 'INT NULL',
        'title'                 => 'VARCHAR(255) NOT NULL',
        'description'           => 'TEXT NULL',
        'version_label'         => 'VARCHAR(50) NULL',
        'created_by_analyst_id' => 'INT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        // Optional paper-size overlay (NULL = no overlay shown). Surfaces on
        // the editor canvas as a dashed outline so analysts can see what
        // will fit before exporting to PNG/PDF. Persisted per-diagram.
        'paper_size'            => 'VARCHAR(20) NULL',
        'paper_orientation'     => 'VARCHAR(20) NULL',
        // Per-diagram header/footer override slots. NULL = inherit the
        // org-wide default from system_settings (`branding_header_left` etc.);
        // non-NULL = explicit override. Renders only when paper_size is set.
        'header_left'           => 'VARCHAR(200) NULL',
        'header_center'         => 'VARCHAR(200) NULL',
        'header_right'          => 'VARCHAR(200) NULL',
        'footer_left'           => 'VARCHAR(200) NULL',
        'footer_center'         => 'VARCHAR(200) NULL',
        'footer_right'          => 'VARCHAR(200) NULL',
    ],

    'network_diagram_nodes' => [
        'id'             => 'INT NOT NULL AUTO_INCREMENT',
        'diagram_id'     => 'INT NOT NULL',
        'cmdb_object_id' => 'INT NOT NULL',
        'x'              => 'INT NOT NULL DEFAULT 0',
        'y'              => 'INT NOT NULL DEFAULT 0',
        'size'           => "VARCHAR(20) NOT NULL DEFAULT 'medium'",
        'icon_override'  => 'VARCHAR(100) NULL',
    ],

    'network_diagram_connectors' => [
        'id'                   => 'INT NOT NULL AUTO_INCREMENT',
        'diagram_id'           => 'INT NOT NULL',
        'from_node_id'         => 'INT NOT NULL',
        'to_node_id'           => 'INT NOT NULL',
        'cmdb_relationship_id' => 'INT NULL',
        'label'                => 'VARCHAR(255) NULL',
        'line_style'           => "VARCHAR(20) NULL DEFAULT 'solid'",
    ],
];

// Primary key definitions: table => pk_column (defaults to 'id')
$primaryKeys = [
    'system_settings'           => 'setting_key',
    'morningChecks_Checks'      => 'CheckID',
    'morningChecks_Results'     => 'ResultID',
    'morningChecks_Statuses'    => 'StatusID',
    'knowledge_article_tags'    => null, // composite PK: article_id, tag_id
    'task_tag_map'              => null, // composite PK: task_id, tag_id
];

try {
    $conn = connectToDatabase();
    $results = [];
    $dbName = DB_NAME;

    // Multi-tenancy: was target_mailboxes.tenant_id absent *before* this run added
    // it? If so the post-schema section backfills existing mailboxes to the Default
    // company (pinning them) — but ONLY this once. Once multi-tenancy is live a NULL
    // tenant_id legitimately means "shared intake" (route by sender), so we must
    // never re-backfill on later verifies or we'd clobber that deliberate choice.
    $mailboxTenantColWasMissing = false;
    try {
        $mbProbe = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'target_mailboxes' AND column_name = 'tenant_id'");
        $mbProbe->execute([$dbName]);
        $mailboxTenantColWasMissing = ((int)$mbProbe->fetchColumn() === 0);
    } catch (Exception $e) {}

    // Same one-time logic for tickets.tenant_id: backfill existing tickets to the
    // Default company only when the column is first added. After that a NULL
    // tenant_id is meaningful — it marks an inbound email that matched no company
    // and is waiting in the TRIAGE queue — so a repeated sweep would wrongly file
    // every triaged ticket under Default and empty the queue.
    $ticketsTenantColWasMissing = false;
    try {
        $tkProbe = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'tickets' AND column_name = 'tenant_id'");
        $tkProbe->execute([$dbName]);
        $ticketsTenantColWasMissing = ((int)$tkProbe->fetchColumn() === 0);
    } catch (Exception $e) {}

    // Was analysts.is_admin absent *before* this run added it? If so, every existing
    // analyst predates the admin/non-admin split and must be grandfathered to admin
    // (below) so an upgrade never locks anyone out of System. Once the column exists
    // the flag is managed deliberately, so this backfill must run only this once.
    $analystIsAdminColWasMissing = false;
    try {
        $iaProbe = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'analysts' AND column_name = 'is_admin'");
        $iaProbe->execute([$dbName]);
        $analystIsAdminColWasMissing = ((int)$iaProbe->fetchColumn() === 0);
    } catch (Exception $e) {}

    // Module access (issue #30): was analysts.can_access_all_modules absent before
    // this run? The column defaults to 1 (all modules), which is right for analysts
    // who were unrestricted — but analysts who already had analyst_modules rows were
    // RESTRICTED, so once the column is added we must flip them to 0 (see back-fill
    // below), or the upgrade would silently give restricted analysts every module.
    $analystAllModulesColWasMissing = false;
    try {
        $amProbe = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'analysts' AND column_name = 'can_access_all_modules'");
        $amProbe->execute([$dbName]);
        $analystAllModulesColWasMissing = ((int)$amProbe->fetchColumn() === 0);
    } catch (Exception $e) {}

    foreach ($schema as $tableName => $columns) {
        $tableResult = ['table' => $tableName, 'status' => 'ok', 'details' => []];

        // Check if table exists
        $check = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
        $check->execute([$dbName, $tableName]);
        $exists = (int)$check->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

        if (!$exists) {
            // Build CREATE TABLE statement
            $colDefs = [];
            foreach ($columns as $colName => $colDef) {
                $colDefs[] = "`$colName` $colDef";
            }

            // Determine primary key
            if ($tableName === 'knowledge_article_tags') {
                $colDefs[] = "PRIMARY KEY (`article_id`, `tag_id`)";
            } elseif ($tableName === 'task_tag_map') {
                $colDefs[] = "PRIMARY KEY (`task_id`, `tag_id`)";
            } elseif (isset($primaryKeys[$tableName])) {
                $pkCol = $primaryKeys[$tableName];
                $colDefs[] = "PRIMARY KEY (`$pkCol`)";
            } else {
                $colDefs[] = "PRIMARY KEY (`id`)";
            }

            $sql = "CREATE TABLE `$tableName` (\n    " . implode(",\n    ", $colDefs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            try {
                $conn->exec($sql);
                $tableResult['status'] = 'created';
                $tableResult['details'][] = 'Table created with ' . count($columns) . ' columns';
            } catch (Exception $e) {
                $tableResult['status'] = 'error';
                $tableResult['details'][] = 'Failed to create table: ' . $e->getMessage();
            }
        } else {
            // Table exists - check each column
            $addedColumns = [];
            foreach ($columns as $colName => $colDef) {
                $colCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?");
                $colCheck->execute([$dbName, $tableName, $colName]);
                $colExists = (int)$colCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

                if (!$colExists) {
                    // Strip AUTO_INCREMENT from ALTER TABLE ADD (can't add auto_increment column to existing table)
                    $alterDef = str_ireplace('AUTO_INCREMENT', '', $colDef);
                    $alterDef = trim(preg_replace('/\s+/', ' ', $alterDef));

                    // For NOT NULL columns without defaults, add a sensible default to avoid errors on existing rows
                    if (stripos($alterDef, 'NOT NULL') !== false && stripos($alterDef, 'DEFAULT') === false) {
                        if (stripos($alterDef, 'INT') === 0 || stripos($alterDef, 'DECIMAL') === 0 || stripos($alterDef, 'BIGINT') === 0) {
                            $alterDef .= ' DEFAULT 0';
                        } elseif (stripos($alterDef, 'TINYINT') === 0) {
                            $alterDef .= ' DEFAULT 0';
                        } elseif (stripos($alterDef, 'DATETIME') === 0 || stripos($alterDef, 'DATE') === 0) {
                            $alterDef .= ' DEFAULT CURRENT_TIMESTAMP';
                        } else {
                            $alterDef .= " DEFAULT ''";
                        }
                    }

                    try {
                        $conn->exec("ALTER TABLE `$tableName` ADD `$colName` $alterDef");
                        $addedColumns[] = $colName;
                    } catch (Exception $e) {
                        $tableResult['status'] = 'error';
                        $tableResult['details'][] = "Failed to add column $colName: " . $e->getMessage();
                    }
                }
            }

            if (count($addedColumns) > 0) {
                $tableResult['status'] = 'updated';
                $tableResult['details'][] = 'Added columns: ' . implode(', ', $addedColumns);
            }
        }
        $results[] = $tableResult;
    }

    // Existing installs may have encrypted mailbox values in VARCHAR columns
    // that are too small. AES-GCM ciphertext can far exceed the original
    // plaintext length (e.g. a ~72-char Google OAuth client ID encrypts to
    // ~140 chars and was silently truncated by the old VARCHAR(100), corrupting
    // the stored value so it could no longer be decrypted). The schema loop only
    // ADDs missing columns; it never widens existing ones — so widen them here.
    $mailboxEncryptedColumns = [
        'azure_tenant_id',
        'azure_client_id',
        'azure_client_secret',
        'oauth_redirect_uri',
        'imap_server',
        'target_mailbox',
    ];
    $modifiedMailboxColumns = [];
    foreach ($mailboxEncryptedColumns as $columnName) {
        $typeStmt = $conn->prepare(
            "SELECT DATA_TYPE FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'target_mailboxes' AND column_name = ?"
        );
        $typeStmt->execute([$dbName, $columnName]);
        $dataType = strtolower((string)$typeStmt->fetchColumn());
        if ($dataType !== '' && $dataType !== 'text') {
            try {
                $conn->exec("ALTER TABLE `target_mailboxes` MODIFY `$columnName` TEXT NOT NULL");
                $modifiedMailboxColumns[] = $columnName;
            } catch (Exception $e) {
                $results[] = [
                    'table' => 'target_mailboxes',
                    'status' => 'error',
                    'details' => ["Failed to widen $columnName: " . $e->getMessage()]
                ];
            }
        }
    }
    if (count($modifiedMailboxColumns) > 0) {
        $results[] = [
            'table' => 'target_mailboxes',
            'status' => 'updated',
            'details' => ['Widened encrypted mailbox columns: ' . implode(', ', $modifiedMailboxColumns)]
        ];
    }

    // One-time grandfather: if is_admin was just added, promote every existing
    // analyst to admin so the upgrade preserves today's behaviour (all analysts
    // could reach System) rather than locking everyone out. Admins then demote
    // people deliberately. Runs only on the run that first adds the column.
    if ($analystIsAdminColWasMissing) {
        $graduated = $conn->exec("UPDATE analysts SET is_admin = 1");
        $results[] = [
            'table' => 'analysts',
            'status' => 'updated',
            'details' => ['Granted admin to ' . (int)$graduated . ' existing analyst(s) (one-time upgrade — demote non-admins in System → Analysts)']
        ];
    }

    // One-time module-access grandfather (issue #30): analysts who already had
    // analyst_modules rows were restricted, so flip their new all-modules flag to 0
    // (the default 1 correctly leaves previously-unrestricted analysts untouched).
    // Runs only on the run that first adds the column, so a later deliberate
    // "all modules" choice is never clobbered.
    if ($analystAllModulesColWasMissing) {
        $restricted = $conn->exec("UPDATE analysts SET can_access_all_modules = 0
            WHERE id IN (SELECT analyst_id FROM (SELECT DISTINCT analyst_id FROM analyst_modules) t)");
        $results[] = [
            'table' => 'analysts',
            'status' => 'updated',
            'details' => ['Preserved module restrictions for ' . (int)$restricted . ' analyst(s) on upgrade (issue #30)']
        ];
    }

    // Seed default admin account if no analysts exist
    $countStmt = $conn->query("SELECT COUNT(*) FROM analysts");
    $analystCount = (int) $countStmt->fetchColumn();
    if ($analystCount === 0) {
        $defaultHash = password_hash('freeitsm', PASSWORD_DEFAULT);
        $seedStmt = $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, created_datetime) VALUES (?, ?, ?, ?, 1, 1, UTC_TIMESTAMP())");
        $seedStmt->execute(['admin', $defaultHash, 'Administrator', 'admin@localhost']);
        $results[] = [
            'table' => 'analysts',
            'status' => 'seeded',
            'details' => ['Created default admin account (username: admin, password: freeitsm)']
        ];
    }

    // Seed the silent Default tenant (multi-tenancy foundation) if none exists.
    // Single-company installs run entirely inside this one tenant; it stays
    // invisible until a second tenant is created.
    $tenantTableCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'tenants'");
    $tenantTableCheck->execute([DB_NAME]);
    if ((int)$tenantTableCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
        $tenantCount = (int) $conn->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
        if ($tenantCount === 0) {
            $conn->exec("INSERT INTO tenants (name, is_default, is_active, created_datetime) VALUES ('Default', 1, 1, UTC_TIMESTAMP())");
            $results[] = [
                'table' => 'tenants',
                'status' => 'seeded',
                'details' => ['Created the default tenant (multi-tenancy foundation; invisible until a second tenant is added)']
            ];
        }
    }

    // Seed default dashboard widgets if table is empty
    $widgetCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'asset_dashboard_widgets'");
    $widgetCheck->execute([DB_NAME]);
    if ((int)$widgetCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
        $widgetCount = (int) $conn->query("SELECT COUNT(*) FROM asset_dashboard_widgets")->fetchColumn();
        if ($widgetCount === 0) {
            $conn->exec("INSERT INTO asset_dashboard_widgets (id, title, description, chart_type, aggregate_property, is_status_filterable, default_status_id, display_order) VALUES
                (1,  'OS Distribution',   'Distribution of operating systems across assets',       'doughnut', 'operating_system',  1, NULL, 1),
                (2,  'Manufacturer',      'Asset count by manufacturer',                           'bar',      'manufacturer',      1, NULL, 2),
                (3,  'Model',             'Asset count by model',                                  'bar',      'model',             1, NULL, 3),
                (4,  'Asset Type',        'Breakdown by asset type',                               'doughnut', 'asset_type_id',     1, NULL, 4),
                (5,  'Asset Status',      'Current status of all assets',                          'doughnut', 'asset_status_id',   0, NULL, 5),
                (6,  'Feature Release',   'Windows feature release versions',                      'bar',      'feature_release',   1, NULL, 6),
                (7,  'Domain',            'Assets grouped by domain',                              'doughnut', 'domain',            1, NULL, 7),
                (8,  'CPU',               'Processor models across the estate',                    'bar',      'cpu_name',          1, NULL, 8),
                (9,  'Memory',            'RAM distribution across assets',                        'bar',      'memory',            1, NULL, 9),
                (10, 'GPU',               'Graphics adapters across the estate',                   'bar',      'gpu_name',          1, NULL, 10),
                (11, 'TPM Version',       'TPM module versions',                                   'doughnut', 'tpm_version',       1, NULL, 11),
                (12, 'BitLocker Status',  'BitLocker encryption status',                           'doughnut', 'bitlocker_status',  1, NULL, 12),
                (13, 'BIOS Version',      'BIOS versions across the estate',                       'bar',      'bios_version',      1, NULL, 13)
            ");
            $results[] = [
                'table' => 'asset_dashboard_widgets',
                'status' => 'seeded',
                'details' => ['Inserted 13 default dashboard widgets']
            ];
        }
    }

    // Migration: convert old aggregate_property values to new format with time_grouping
    $tktWidgetCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'ticket_dashboard_widgets'");
    $tktWidgetCheck->execute([DB_NAME]);
    if ((int)$tktWidgetCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
        $migCheck = $conn->query("SELECT COUNT(*) FROM ticket_dashboard_widgets WHERE aggregate_property IN ('created_daily','created_monthly','closed_daily','closed_monthly','created_vs_closed_daily','created_vs_closed_monthly')");
        if ((int)$migCheck->fetchColumn() > 0) {
            $migrations = [
                ['created_daily',             'created',          'day',   'this_month'],
                ['created_monthly',           'created',          'month', '12m'],
                ['closed_daily',              'closed',           'day',   'this_month'],
                ['closed_monthly',            'closed',           'month', '12m'],
                ['created_vs_closed_daily',   'created_vs_closed','day',   'this_month'],
                ['created_vs_closed_monthly', 'created_vs_closed','month', '12m'],
            ];
            foreach ($migrations as [$old, $new, $grouping, $dateRange]) {
                $conn->prepare("UPDATE ticket_dashboard_widgets SET aggregate_property = ?, time_grouping = ?, date_range = COALESCE(date_range, ?) WHERE aggregate_property = ?")
                     ->execute([$new, $grouping, $dateRange, $old]);
            }
            $results[] = ['table' => 'ticket_dashboard_widgets', 'status' => 'migrated', 'details' => ['Converted legacy aggregate properties to new format with time_grouping']];
        }
    }

    // Seed default software dashboard widgets if table is empty
    $swWidgetCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'software_dashboard_widgets'");
    $swWidgetCheck->execute([DB_NAME]);
    if ((int)$swWidgetCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
        $swWidgetCount = (int) $conn->query("SELECT COUNT(*) FROM software_dashboard_widgets")->fetchColumn();
        if ($swWidgetCount === 0) {
            $conn->exec("INSERT INTO software_dashboard_widgets (id, title, description, chart_type, aggregate_property, app_id, exclude_system_components, display_order) VALUES
                (1, 'Top Installed Applications', 'Most installed applications across all machines', 'bar', 'top_installed', NULL, 1, 1),
                (2, 'Publisher Distribution', 'Software distribution by publisher', 'doughnut', 'publisher_distribution', NULL, 1, 2)
            ");
            $results[] = [
                'table' => 'software_dashboard_widgets',
                'status' => 'seeded',
                'details' => ['Inserted 2 default software dashboard widgets']
            ];
        }
    }

    // ----------------------------------------------------------------------
    // Tickets normalisation: lookup tables, backfill, drop legacy columns
    // ----------------------------------------------------------------------
    $colExists = function($table, $col) use ($conn, $dbName) {
        $s = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?");
        $s->execute([$dbName, $table, $col]);
        return (int)$s->fetchColumn() > 0;
    };
    $tableExists = function($table) use ($conn, $dbName) {
        $s = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
        $s->execute([$dbName, $table]);
        return (int)$s->fetchColumn() > 0;
    };
    $fkExists = function($table, $fk) use ($conn, $dbName) {
        $s = $conn->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'");
        $s->execute([$dbName, $table, $fk]);
        return (int)$s->fetchColumn() > 0;
    };
    $idxExists = function($table, $idx) use ($conn, $dbName) {
        $s = $conn->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?");
        $s->execute([$dbName, $table, $idx]);
        return (int)$s->fetchColumn() > 0;
    };

    // Seed default ticket statuses if table is empty
    if ($tableExists('ticket_statuses')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM ticket_statuses")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO ticket_statuses (name, is_closed, colour, is_default, display_order) VALUES
                ('Open',              0, '#2563eb', 1, 10),
                ('In Progress',       0, '#9333ea', 0, 20),
                ('On Hold',           0, '#f59e0b', 0, 30),
                ('Awaiting Response', 0, '#0891b2', 0, 40),
                ('Closed',            1, '#6b7280', 0, 50)");
            $results[] = ['table' => 'ticket_statuses', 'status' => 'seeded', 'details' => ['Inserted 5 default ticket statuses']];
        }
    }

    // Seed default ticket priorities if table is empty
    if ($tableExists('ticket_priorities')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM ticket_priorities")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO ticket_priorities (name, colour, is_default, display_order) VALUES
                ('Low',      '#16a34a', 0, 10),
                ('Normal',   '#2563eb', 1, 20),
                ('High',     '#f59e0b', 0, 30),
                ('Critical', '#dc2626', 0, 40),
                ('Urgent',   '#b91c1c', 0, 50)");
            $results[] = ['table' => 'ticket_priorities', 'status' => 'seeded', 'details' => ['Inserted 5 default ticket priorities']];
        }
    }

    // Seed default ticket types if table is empty. Parity with statuses/priorities
    // above (GitHub #42): a fresh install landed with an empty Type dropdown because
    // types were only ever created by the demo data. Global defaults (tenant_id NULL);
    // only ever seeded into an empty table, so a deliberately-cleared list is respected.
    if ($tableExists('ticket_types')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM ticket_types")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO ticket_types (name, description, is_active, display_order, tenant_id) VALUES
                ('Incident',        'Something is broken or not working as expected',   1, 10, NULL),
                ('Service Request', 'A request for something new or a standard change',  1, 20, NULL),
                ('Question',        'A general query or how-to',                         1, 30, NULL)");
            $results[] = ['table' => 'ticket_types', 'status' => 'seeded', 'details' => ['Inserted 3 default ticket types']];
        }
    }

    // Seed default ticket origins if table is empty (GitHub #42). WhatsApp is
    // deliberately excluded here — the WhatsApp origin is owned by its own seeder
    // further down (which adds it whether or not this base set exists).
    if ($tableExists('ticket_origins')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM ticket_origins")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO ticket_origins (name, description, display_order, is_active, tenant_id) VALUES
                ('Email',   'Received by email',                     10, 1, NULL),
                ('Phone',   'Logged from a phone call',              20, 1, NULL),
                ('Portal',  'Raised via the self-service portal',    30, 1, NULL),
                ('Walk-up', 'Reported in person',                    40, 1, NULL)");
            $results[] = ['table' => 'ticket_origins', 'status' => 'seeded', 'details' => ['Inserted 4 default ticket origins']];
        }
    }

    // ----------------------------------------------------------
    // Multi-tenancy foundation — unique keys + FKs for the tenant tables
    // (the $schema loop builds columns + PK only). Added idempotently.
    // ----------------------------------------------------------
    if ($tableExists('tenant_domains') && $tableExists('tenants')) {
        if (!$idxExists('tenant_domains', 'uq_tenant_domains_domain')) {
            try { $conn->exec("ALTER TABLE tenant_domains ADD UNIQUE KEY uq_tenant_domains_domain (domain)"); } catch (Exception $e) {}
        }
        if (!$fkExists('tenant_domains', 'fk_tenant_domains_tenant')) {
            try { $conn->exec("ALTER TABLE tenant_domains ADD CONSTRAINT fk_tenant_domains_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('tenant_sender_addresses') && $tableExists('tenants')) {
        if (!$idxExists('tenant_sender_addresses', 'uq_tenant_sender_email')) {
            try { $conn->exec("ALTER TABLE tenant_sender_addresses ADD UNIQUE KEY uq_tenant_sender_email (email)"); } catch (Exception $e) {}
        }
        if (!$fkExists('tenant_sender_addresses', 'fk_tenant_sender_tenant')) {
            try { $conn->exec("ALTER TABLE tenant_sender_addresses ADD CONSTRAINT fk_tenant_sender_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // Messaging channels (WhatsApp etc.): tenant index + FK (pinned company), and the
    // sender-phone → company map FK. Mirrors target_mailboxes / tenant_sender_addresses.
    if ($tableExists('messaging_channels') && $tableExists('tenants') && $colExists('messaging_channels', 'tenant_id')) {
        if (!$idxExists('messaging_channels', 'ix_messaging_channels_tenant_id')) {
            try { $conn->exec("ALTER TABLE messaging_channels ADD KEY ix_messaging_channels_tenant_id (tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('messaging_channels', 'fk_messaging_channels_tenant')) {
            try { $conn->exec("ALTER TABLE messaging_channels ADD CONSTRAINT fk_messaging_channels_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('tenant_channel_senders') && $tableExists('tenants')) {
        if (!$idxExists('tenant_channel_senders', 'uq_tenant_channel_sender_identifier')) {
            try { $conn->exec("ALTER TABLE tenant_channel_senders ADD UNIQUE KEY uq_tenant_channel_sender_identifier (identifier)"); } catch (Exception $e) {}
        }
        if (!$fkExists('tenant_channel_senders', 'fk_tenant_channel_sender_tenant')) {
            try { $conn->exec("ALTER TABLE tenant_channel_senders ADD CONSTRAINT fk_tenant_channel_sender_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('messaging_templates') && $tableExists('tenants') && $colExists('messaging_templates', 'tenant_id')) {
        if (!$idxExists('messaging_templates', 'ix_messaging_templates_tenant_id')) {
            try { $conn->exec("ALTER TABLE messaging_templates ADD KEY ix_messaging_templates_tenant_id (tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('messaging_templates', 'fk_messaging_templates_tenant')) {
            try { $conn->exec("ALTER TABLE messaging_templates ADD CONSTRAINT fk_messaging_templates_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    // Seed the WhatsApp ticket origin (global default) if absent, so channel tickets
    // can be tagged by origin out of the box. Single-company installs see it like any
    // other origin; it can be hidden per-company via the add+hide model.
    if ($tableExists('ticket_origins')) {
        try {
            $chk = $conn->prepare("SELECT COUNT(*) FROM ticket_origins WHERE name = 'WhatsApp' AND tenant_id IS NULL");
            $chk->execute();
            if ((int) $chk->fetchColumn() === 0) {
                $conn->exec("INSERT INTO ticket_origins (name, description, display_order, is_active, tenant_id) VALUES ('WhatsApp', 'Messages received via WhatsApp', 50, 1, NULL)");
                $results[] = ['table' => 'ticket_origins', 'status' => 'updated', 'details' => ['Seeded the WhatsApp ticket origin']];
            }
        } catch (Exception $e) {}
    }
    // Per-company config: the generic "hide" layer + per-entity tenant_id columns.
    if ($tableExists('tenant_config_hidden') && $tableExists('tenants')) {
        if (!$idxExists('tenant_config_hidden', 'uq_tenant_config_hidden')) {
            try { $conn->exec("ALTER TABLE tenant_config_hidden ADD UNIQUE KEY uq_tenant_config_hidden (tenant_id, entity_type, entity_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('tenant_config_hidden', 'fk_tenant_config_hidden_tenant')) {
            try { $conn->exec("ALTER TABLE tenant_config_hidden ADD CONSTRAINT fk_tenant_config_hidden_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('ticket_types') && $tableExists('tenants') && $colExists('ticket_types', 'tenant_id')) {
        if (!$fkExists('ticket_types', 'fk_ticket_types_tenant')) {
            try { $conn->exec("ALTER TABLE ticket_types ADD CONSTRAINT fk_ticket_types_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        // Widen name-uniqueness from global to per-scope so a company can hold a
        // type whose name matches a global default. (Global-name dedup is enforced
        // in the API — NULL tenant_id rows aren't de-duped by a unique key.)
        if ($idxExists('ticket_types', 'uq_ticket_types_name')) {
            try { $conn->exec("ALTER TABLE ticket_types DROP INDEX uq_ticket_types_name"); } catch (Exception $e) {}
        }
        if (!$idxExists('ticket_types', 'uq_ticket_types_tenant_name')) {
            try { $conn->exec("ALTER TABLE ticket_types ADD UNIQUE KEY uq_ticket_types_tenant_name (tenant_id, name)"); } catch (Exception $e) {}
        }
    }
    // ticket_origins had no name unique key historically; we don't add one (would
    // fail on pre-existing duplicate names) — dedup is enforced in the API.
    if ($tableExists('ticket_origins') && $tableExists('tenants') && $colExists('ticket_origins', 'tenant_id')) {
        if (!$fkExists('ticket_origins', 'fk_ticket_origins_tenant')) {
            try { $conn->exec("ALTER TABLE ticket_origins ADD CONSTRAINT fk_ticket_origins_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('analyst_tenant_access') && $tableExists('analysts') && $tableExists('tenants')) {
        if (!$idxExists('analyst_tenant_access', 'uq_analyst_tenant')) {
            try { $conn->exec("ALTER TABLE analyst_tenant_access ADD UNIQUE KEY uq_analyst_tenant (analyst_id, tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('analyst_tenant_access', 'fk_ata_analyst')) {
            try { $conn->exec("ALTER TABLE analyst_tenant_access ADD CONSTRAINT fk_ata_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if (!$fkExists('analyst_tenant_access', 'fk_ata_tenant')) {
            try { $conn->exec("ALTER TABLE analyst_tenant_access ADD CONSTRAINT fk_ata_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('team_tenant_access') && $tableExists('teams') && $tableExists('tenants')) {
        if (!$idxExists('team_tenant_access', 'uq_team_tenant')) {
            try { $conn->exec("ALTER TABLE team_tenant_access ADD UNIQUE KEY uq_team_tenant (team_id, tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('team_tenant_access', 'fk_tta_team')) {
            try { $conn->exec("ALTER TABLE team_tenant_access ADD CONSTRAINT fk_tta_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if (!$fkExists('team_tenant_access', 'fk_tta_tenant')) {
            try { $conn->exec("ALTER TABLE team_tenant_access ADD CONSTRAINT fk_tta_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // tickets.tenant_id — index + FK + a ONE-TIME backfill of existing tickets to
    // the Default company. Like target_mailboxes (and unlike a naive sweep), we
    // backfill ONLY when the column was just added ($ticketsTenantColWasMissing):
    // afterwards a NULL tenant_id marks an un-routed inbound email sitting in the
    // TRIAGE queue, so re-sweeping it to Default would empty that queue.
    if ($tableExists('tickets') && $tableExists('tenants') && $colExists('tickets', 'tenant_id')) {
        if (!$idxExists('tickets', 'ix_tickets_tenant_id')) {
            try { $conn->exec("ALTER TABLE tickets ADD KEY ix_tickets_tenant_id (tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('tickets', 'fk_tickets_tenant')) {
            try { $conn->exec("ALTER TABLE tickets ADD CONSTRAINT fk_tickets_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)"); } catch (Exception $e) {}
        }
        if ($ticketsTenantColWasMissing) {
            $defaultTenantId = (int) ($conn->query("SELECT id FROM tenants WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
            if ($defaultTenantId > 0) {
                $backfilled = $conn->exec("UPDATE tickets SET tenant_id = $defaultTenantId WHERE tenant_id IS NULL");
                if ($backfilled > 0) {
                    $results[] = ['table' => 'tickets', 'status' => 'updated', 'details' => ["Backfilled tenant_id on $backfilled ticket(s) to the Default company (multi-tenancy migration)"]];
                }
            }
        }
    }
    // target_mailboxes.tenant_id — index + FK + a ONE-TIME backfill pinning every
    // existing mailbox to the Default company. This keeps existing inbound mail
    // flowing to Default exactly as before once a second company is added (a
    // pinned mailbox decides the tenant; the sender is ignored). NULL means
    // "shared intake" (route by sender domain) going forward, so — unlike tickets
    // — we backfill ONLY when the column was just added ($mailboxTenantColWasMissing),
    // never on later verifies, so an admin's deliberate shared-intake choice sticks.
    if ($tableExists('target_mailboxes') && $tableExists('tenants') && $colExists('target_mailboxes', 'tenant_id')) {
        if (!$idxExists('target_mailboxes', 'ix_target_mailboxes_tenant_id')) {
            try { $conn->exec("ALTER TABLE target_mailboxes ADD KEY ix_target_mailboxes_tenant_id (tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('target_mailboxes', 'fk_target_mailboxes_tenant')) {
            try { $conn->exec("ALTER TABLE target_mailboxes ADD CONSTRAINT fk_target_mailboxes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
        if ($mailboxTenantColWasMissing) {
            $defaultTenantId = (int) ($conn->query("SELECT id FROM tenants WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
            if ($defaultTenantId > 0) {
                $pinned = $conn->exec("UPDATE target_mailboxes SET tenant_id = $defaultTenantId WHERE tenant_id IS NULL");
                if ($pinned > 0) {
                    $results[] = ['table' => 'target_mailboxes', 'status' => 'updated', 'details' => ["Pinned $pinned existing mailbox(es) to the Default company (multi-tenancy migration)"]];
                }
            }
        }
    }

    // ----------------------------------------------------------
    // SLA setup — see docs/sla.md
    // ----------------------------------------------------------

    // Unique key + FK constraints for the SLA hours/holidays tables (columns
    // alone aren't enough). Add idempotently.
    if ($tableExists('sla_calendar_hours') && $tableExists('sla_calendars')) {
        if (!$idxExists('sla_calendar_hours', 'uq_sla_calendar_hours')) {
            try { $conn->exec("ALTER TABLE sla_calendar_hours ADD UNIQUE KEY uq_sla_calendar_hours (calendar_id, weekday)"); } catch (Exception $e) {}
        }
        if (!$fkExists('sla_calendar_hours', 'fk_sla_hours_calendar')) {
            try { $conn->exec("ALTER TABLE sla_calendar_hours ADD CONSTRAINT fk_sla_hours_calendar FOREIGN KEY (calendar_id) REFERENCES sla_calendars (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('sla_calendar_holidays') && $tableExists('sla_calendars')) {
        if (!$idxExists('sla_calendar_holidays', 'uq_sla_holidays')) {
            try { $conn->exec("ALTER TABLE sla_calendar_holidays ADD UNIQUE KEY uq_sla_holidays (calendar_id, holiday_date)"); } catch (Exception $e) {}
        }
        if (!$fkExists('sla_calendar_holidays', 'fk_sla_holidays_calendar')) {
            try { $conn->exec("ALTER TABLE sla_calendar_holidays ADD CONSTRAINT fk_sla_holidays_calendar FOREIGN KEY (calendar_id) REFERENCES sla_calendars (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('ticket_priorities') && $tableExists('sla_calendars') && $colExists('ticket_priorities', 'sla_calendar_id')) {
        if (!$fkExists('ticket_priorities', 'fk_ticket_priorities_sla_calendar')) {
            try { $conn->exec("ALTER TABLE ticket_priorities ADD CONSTRAINT fk_ticket_priorities_sla_calendar FOREIGN KEY (sla_calendar_id) REFERENCES sla_calendars (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    // FK + UNIQUE constraints for SLA breach notification rules + dedup log
    if ($tableExists('sla_notification_rules')) {
        if ($tableExists('departments') && !$fkExists('sla_notification_rules', 'fk_sla_notif_rule_dept')) {
            try { $conn->exec("ALTER TABLE sla_notification_rules ADD CONSTRAINT fk_sla_notif_rule_dept FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('sla_notification_rules', 'fk_sla_notif_rule_analyst')) {
            try { $conn->exec("ALTER TABLE sla_notification_rules ADD CONSTRAINT fk_sla_notif_rule_analyst FOREIGN KEY (notify_analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('sla_notifications_sent')) {
        if (!$idxExists('sla_notifications_sent', 'uq_sla_notif_sent')) {
            try { $conn->exec("ALTER TABLE sla_notifications_sent ADD UNIQUE KEY uq_sla_notif_sent (ticket_id, target_type, trigger_type)"); } catch (Exception $e) {}
        }
        if ($tableExists('tickets') && !$fkExists('sla_notifications_sent', 'fk_sla_notif_sent_ticket')) {
            try { $conn->exec("ALTER TABLE sla_notifications_sent ADD CONSTRAINT fk_sla_notif_sent_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('sla_cron_runs')) {
        if (!$idxExists('sla_cron_runs', 'idx_sla_cron_started')) {
            try { $conn->exec("ALTER TABLE sla_cron_runs ADD INDEX idx_sla_cron_started (started_at)"); } catch (Exception $e) {}
        }
        if (!$idxExists('sla_cron_runs', 'idx_sla_cron_ip_started')) {
            try { $conn->exec("ALTER TABLE sla_cron_runs ADD INDEX idx_sla_cron_ip_started (client_ip, started_at)"); } catch (Exception $e) {}
        }
    }

    // ticket_recordings: ticket_id is nullable (pending uploads before ticket creation), CASCADE on ticket delete
    if ($tableExists('ticket_recordings')) {
        if (!$idxExists('ticket_recordings', 'ix_ticket_recordings_ticket_id')) {
            try { $conn->exec("ALTER TABLE ticket_recordings ADD INDEX ix_ticket_recordings_ticket_id (ticket_id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('ticket_recordings', 'ix_ticket_recordings_pending')) {
            try { $conn->exec("ALTER TABLE ticket_recordings ADD INDEX ix_ticket_recordings_pending (ticket_id, created_at)"); } catch (Exception $e) {}
        }
        if ($tableExists('tickets') && !$fkExists('ticket_recordings', 'fk_ticket_recordings_ticket')) {
            try { $conn->exec("ALTER TABLE ticket_recordings ADD CONSTRAINT fk_ticket_recordings_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('users') && !$fkExists('ticket_recordings', 'fk_ticket_recordings_user')) {
            try { $conn->exec("ALTER TABLE ticket_recordings ADD CONSTRAINT fk_ticket_recordings_user FOREIGN KEY (recorded_by_user_id) REFERENCES users (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }

    // ticket_csat_responses: token must be unique (it's the survey URL key), CASCADE on ticket delete
    if ($tableExists('ticket_csat_responses')) {
        if (!$idxExists('ticket_csat_responses', 'uq_ticket_csat_token')) {
            try { $conn->exec("ALTER TABLE ticket_csat_responses ADD UNIQUE KEY uq_ticket_csat_token (token)"); } catch (Exception $e) {}
        }
        if (!$idxExists('ticket_csat_responses', 'ix_ticket_csat_ticket_id')) {
            try { $conn->exec("ALTER TABLE ticket_csat_responses ADD INDEX ix_ticket_csat_ticket_id (ticket_id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('ticket_csat_responses', 'ix_ticket_csat_responded')) {
            try { $conn->exec("ALTER TABLE ticket_csat_responses ADD INDEX ix_ticket_csat_responded (responded_datetime)"); } catch (Exception $e) {}
        }
        if ($tableExists('tickets') && !$fkExists('ticket_csat_responses', 'fk_ticket_csat_ticket')) {
            try { $conn->exec("ALTER TABLE ticket_csat_responses ADD CONSTRAINT fk_ticket_csat_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('ticket_csat_responses', 'fk_ticket_csat_analyst')) {
            try { $conn->exec("ALTER TABLE ticket_csat_responses ADD CONSTRAINT fk_ticket_csat_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }

    // Seed a default Mon-Fri 09:00-17:00 calendar in Europe/London if no
    // calendars exist yet. Detected installs that pre-date the SLA module
    // will pick this up on first verify; the freeitsm.sql seed handles fresh.
    if ($tableExists('sla_calendars')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM sla_calendars")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO sla_calendars (name, timezone, is_default) VALUES ('Default Business Hours', 'Europe/London', 1)");
            $newCalId = (int)$conn->lastInsertId();
            if ($newCalId && $tableExists('sla_calendar_hours')) {
                $stmt = $conn->prepare("INSERT INTO sla_calendar_hours (calendar_id, weekday, start_time, end_time) VALUES (?, ?, '09:00:00', '17:00:00')");
                foreach ([1, 2, 3, 4, 5] as $wd) { $stmt->execute([$newCalId, $wd]); }
            }
            $results[] = ['table' => 'sla_calendars', 'status' => 'seeded', 'details' => ['Inserted default Mon-Fri 09:00-17:00 calendar (Europe/London)']];
        }
    }

    // Seed default system_settings rows for the SLA toggles. INSERT IGNORE
    // so they only land on first run; existing values aren't overwritten.
    if ($tableExists('system_settings')) {
        $defaults = [
            'sla_enforce_from'                => null, // NULL = SLA enforcement disabled
            'sla_priority_change_behaviour'   => 'forward',
            'sla_reopen_behaviour'            => 'reset',
            'sla_warning_threshold_percent'   => '80',
            'sla_notify_assignee_at_warning'  => '1',
            'sla_notify_lead_at_breach'       => '1',
            'sla_first_response_definition'   => 'either',
            // Shared secret for HTTP-triggered cron worker; random per install
            'sla_cron_token'                  => bin2hex(random_bytes(16)),
            // Min seconds between successful cron runs — protects against
            // accidental double-scheduling, runaway loops, or token-leak abuse
            'sla_cron_min_interval_seconds'   => '30',
            // How many days to keep rows in sla_cron_runs before pruning
            'sla_cron_log_retention_days'     => '30',
            // Outbound-webhook delivery worker (cron/webhook_deliveries.php):
            // shared secret for HTTP invocation + min seconds between runs +
            // how long to keep delivered/dead rows before pruning.
            'webhook_cron_token'              => bin2hex(random_bytes(16)),
            'webhook_cron_min_interval_seconds' => '20',
            'webhook_delivery_retention_days' => '30',
            // Watchtower: flag tickets stuck in a paused-SLA status longer than this
            // (wall-clock hours since last status change). Guardrail against analysts
            // parking tickets in On Hold to escape the SLA clock.
            'watchtower_paused_too_long_hours' => '24',
            // Tasks calendar: how multi-day tasks render — deadline | span | repeat
            'tasks_calendar_span_mode'        => 'deadline',
            // CSAT (customer satisfaction surveys on ticket closure)
            // mode: off | auto | manual
            //   off    — feature disabled, no survey ever sent
            //   auto   — survey email queued immediately on close (or after csat_delay_minutes)
            //   manual — analyst clicks 'Request feedback' from the ticket toolbar
            'csat_mode'                       => 'off',
            // Wait this many minutes after close before sending the survey email,
            // so the user has a chance to verify the fix actually held. 0 = send immediately.
            'csat_delay_minutes'              => '0',
            // Render the survey UI as 5 stars (⭐⭐⭐⭐⭐) or 5 emojis (😡 🙁 😐 🙂 😀)
            // Same 1-5 data model either way, dashboards/averages work identically.
            'csat_scale'                      => 'stars',
            // If 1, a reopened-then-closed ticket only gets a new survey when the analyst
            // manually triggers it (stops survey-spamming a flaky ticket). If 0, every close fires.
            'csat_one_per_ticket'             => '1',
            // Shared HMAC secret for tokenising survey URLs. Random per install — leaking it
            // would let anyone post ratings on behalf of users, so it stays in system_settings
            // rather than going to a public file or being printed in error pages.
            'csat_token_secret'                => bin2hex(random_bytes(32)),
        ];
        $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($defaults as $k => $v) { $stmt->execute([$k, $v]); }
    }

    // Backfill tickets.status_id from legacy tickets.status
    if ($tableExists('tickets') && $colExists('tickets', 'status') && $colExists('tickets', 'status_id')) {
        // Insert any unknown status names into ticket_statuses so the FK can be set
        $conn->exec("INSERT IGNORE INTO ticket_statuses (name, display_order)
                     SELECT DISTINCT t.status, 999
                     FROM tickets t
                     LEFT JOIN ticket_statuses s ON LOWER(s.name) = LOWER(t.status)
                     WHERE t.status IS NOT NULL AND t.status <> '' AND s.id IS NULL");

        $upd = $conn->exec("UPDATE tickets t
                            JOIN ticket_statuses s ON LOWER(s.name) = LOWER(t.status)
                            SET t.status_id = s.id
                            WHERE t.status_id IS NULL AND t.status IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'migrated', 'details' => ["Backfilled status_id for $upd ticket(s)"]];
        }

        // Set status_id to default for any ticket still missing one
        $conn->exec("UPDATE tickets SET status_id = (SELECT id FROM ticket_statuses WHERE is_default = 1 LIMIT 1) WHERE status_id IS NULL");
    }

    // Backfill tickets.priority_id from legacy tickets.priority
    if ($tableExists('tickets') && $colExists('tickets', 'priority') && $colExists('tickets', 'priority_id')) {
        $conn->exec("INSERT IGNORE INTO ticket_priorities (name, display_order)
                     SELECT DISTINCT t.priority, 999
                     FROM tickets t
                     LEFT JOIN ticket_priorities p ON LOWER(p.name) = LOWER(t.priority)
                     WHERE t.priority IS NOT NULL AND t.priority <> '' AND p.id IS NULL");

        $upd = $conn->exec("UPDATE tickets t
                            JOIN ticket_priorities p ON LOWER(p.name) = LOWER(t.priority)
                            SET t.priority_id = p.id
                            WHERE t.priority_id IS NULL AND t.priority IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'migrated', 'details' => ["Backfilled priority_id for $upd ticket(s)"]];
        }

        $conn->exec("UPDATE tickets SET priority_id = (SELECT id FROM ticket_priorities WHERE is_default = 1 LIMIT 1) WHERE priority_id IS NULL");
    }

    // asset_history.field_name normalisation: legacy rows stored an English label
    // (e.g. "Purchase date"). New writes store a stable key so the history view can
    // localise it via t('asset-management.field.<key>'). Exact-match + idempotent.
    if ($tableExists('asset_history') && $colExists('asset_history', 'field_name')) {
        $assetHistoryLabelMap = [
            'Type' => 'type', 'Status' => 'status', 'Location' => 'location',
            'Purchase date' => 'purchase_date', 'Purchase cost' => 'purchase_cost',
            'Supplier' => 'supplier', 'Order number' => 'order_number',
            'Warranty expiry' => 'warranty_expiry', 'Assigned User' => 'assigned_user',
        ];
        $assetHistoryNormalised = 0;
        foreach ($assetHistoryLabelMap as $old => $new) {
            $u = $conn->prepare("UPDATE asset_history SET field_name = ? WHERE field_name = ?");
            $u->execute([$new, $old]);
            $assetHistoryNormalised += $u->rowCount();
        }
        if ($assetHistoryNormalised > 0) {
            $results[] = ['table' => 'asset_history', 'status' => 'migrated', 'details' => ["Normalised field_name to localisable keys on $assetHistoryNormalised row(s)"]];
        }
    }

    // Backfill tickets.user_id from legacy requester_email / requester_name
    if ($tableExists('tickets') && $tableExists('users') && $colExists('tickets', 'requester_email')) {
        // Match existing users by email
        $upd1 = $conn->exec("UPDATE tickets t
                             JOIN users u ON LOWER(u.email) = LOWER(t.requester_email)
                             SET t.user_id = u.id
                             WHERE t.user_id IS NULL AND t.requester_email IS NOT NULL AND t.requester_email <> ''");

        // Create users for any orphan emails
        $conn->exec("INSERT IGNORE INTO users (email, display_name, created_at)
                     SELECT t.requester_email, COALESCE(NULLIF(t.requester_name, ''), t.requester_email), UTC_TIMESTAMP()
                     FROM tickets t
                     LEFT JOIN users u ON LOWER(u.email) = LOWER(t.requester_email)
                     WHERE t.user_id IS NULL
                       AND t.requester_email IS NOT NULL AND t.requester_email <> ''
                       AND u.id IS NULL
                     GROUP BY t.requester_email");

        // Re-link any tickets that just had users created
        $upd2 = $conn->exec("UPDATE tickets t
                             JOIN users u ON LOWER(u.email) = LOWER(t.requester_email)
                             SET t.user_id = u.id
                             WHERE t.user_id IS NULL AND t.requester_email IS NOT NULL AND t.requester_email <> ''");

        $totalLinked = (int)$upd1 + (int)$upd2;
        if ($totalLinked > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'migrated', 'details' => ["Backfilled user_id for $totalLinked ticket(s) from requester_email/requester_name"]];
        }
    }

    // Add foreign keys + indexes for new ticket columns (only if missing)
    if ($tableExists('tickets')) {
        $alters = [
            ['ix_tickets_status_id',          "ALTER TABLE tickets ADD KEY ix_tickets_status_id (status_id)",                                       'index'],
            ['ix_tickets_priority_id',        "ALTER TABLE tickets ADD KEY ix_tickets_priority_id (priority_id)",                                   'index'],
            ['ix_tickets_assigned_analyst_id',"ALTER TABLE tickets ADD KEY ix_tickets_assigned_analyst_id (assigned_analyst_id)",                   'index'],
            ['ix_tickets_department_id',      "ALTER TABLE tickets ADD KEY ix_tickets_department_id (department_id)",                               'index'],
            ['ix_tickets_created_datetime',   "ALTER TABLE tickets ADD KEY ix_tickets_created_datetime (created_datetime)",                         'index'],
            ['fk_tickets_status',             "ALTER TABLE tickets ADD CONSTRAINT fk_tickets_status FOREIGN KEY (status_id) REFERENCES ticket_statuses (id)",     'fk'],
            ['fk_tickets_priority',           "ALTER TABLE tickets ADD CONSTRAINT fk_tickets_priority FOREIGN KEY (priority_id) REFERENCES ticket_priorities (id)", 'fk'],
        ];
        foreach ($alters as [$name, $sql, $kind]) {
            $present = $kind === 'fk' ? $fkExists('tickets', $name) : $idxExists('tickets', $name);
            if (!$present) {
                try { $conn->exec($sql); } catch (Exception $e) { /* may already exist under another name */ }
            }
        }
    }

    // Drop legacy ticket columns once backfill is complete (no NULLs remain)
    if ($tableExists('tickets')) {
        $orphanStatus    = $colExists('tickets', 'status')           ? (int) $conn->query("SELECT COUNT(*) FROM tickets WHERE status_id IS NULL")->fetchColumn() : 0;
        $orphanPriority  = $colExists('tickets', 'priority')         ? (int) $conn->query("SELECT COUNT(*) FROM tickets WHERE priority_id IS NULL")->fetchColumn() : 0;
        $orphanRequester = $colExists('tickets', 'requester_email')  ? (int) $conn->query("SELECT COUNT(*) FROM tickets WHERE user_id IS NULL AND requester_email IS NOT NULL AND requester_email <> ''")->fetchColumn() : 0;

        $dropped = [];
        if ($colExists('tickets', 'status') && $orphanStatus === 0) {
            try { $conn->exec("ALTER TABLE tickets DROP COLUMN `status`"); $dropped[] = 'status'; } catch (Exception $e) { $tableResult['details'][] = 'Drop status failed: '.$e->getMessage(); }
        }
        if ($colExists('tickets', 'priority') && $orphanPriority === 0) {
            try { $conn->exec("ALTER TABLE tickets DROP COLUMN `priority`"); $dropped[] = 'priority'; } catch (Exception $e) {}
        }
        if ($colExists('tickets', 'requester_email') && $orphanRequester === 0) {
            try { $conn->exec("ALTER TABLE tickets DROP COLUMN `requester_email`"); $dropped[] = 'requester_email'; } catch (Exception $e) {}
        }
        if ($colExists('tickets', 'requester_name')) {
            // requester_name is safe to drop whenever requester_email was (or never existed)
            $stillNeed = $colExists('tickets', 'requester_email') ? true : false;
            if (!$stillNeed) {
                try { $conn->exec("ALTER TABLE tickets DROP COLUMN `requester_name`"); $dropped[] = 'requester_name'; } catch (Exception $e) {}
            }
        }
        if (count($dropped) > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'updated', 'details' => ['Dropped legacy columns: '.implode(', ', $dropped)]];
        }
        $stillOrphan = [];
        if ($orphanStatus > 0)    $stillOrphan[] = "status ($orphanStatus rows)";
        if ($orphanPriority > 0)  $stillOrphan[] = "priority ($orphanPriority rows)";
        if ($orphanRequester > 0) $stillOrphan[] = "requester ($orphanRequester rows)";
        if (count($stillOrphan) > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'pending', 'details' => ['Cannot drop legacy columns yet — orphans remain: '.implode(', ', $stillOrphan)]];
        }
    }

    // ----------------------------------------------------------------------
    // Rota locations: lookup table, backfill, drop legacy column
    // ----------------------------------------------------------------------

    // Seed default rota locations if table is empty
    if ($tableExists('rota_locations')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM rota_locations")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO rota_locations (name, colour, is_default, display_order) VALUES
                ('Office', '#1a73e8', 1, 10),
                ('WFH',    '#1e8e3e', 0, 20)");
            $results[] = ['table' => 'rota_locations', 'status' => 'seeded', 'details' => ['Inserted 2 default rota locations']];
        }
    }

    // Backfill ticket_rota_entries.location_id from legacy location string
    if ($tableExists('ticket_rota_entries') && $colExists('ticket_rota_entries', 'location') && $colExists('ticket_rota_entries', 'location_id')) {
        // Map legacy slugs to canonical names. Anything else gets inserted as a new location row.
        $conn->exec("INSERT IGNORE INTO rota_locations (name, display_order)
                     SELECT DISTINCT
                         CASE
                             WHEN LOWER(e.location) = 'office' THEN 'Office'
                             WHEN LOWER(e.location) = 'wfh'    THEN 'WFH'
                             ELSE e.location
                         END AS name,
                         999
                     FROM ticket_rota_entries e
                     LEFT JOIN rota_locations l ON LOWER(l.name) = LOWER(
                         CASE
                             WHEN LOWER(e.location) = 'office' THEN 'Office'
                             WHEN LOWER(e.location) = 'wfh'    THEN 'WFH'
                             ELSE e.location
                         END)
                     WHERE e.location IS NOT NULL AND e.location <> '' AND l.id IS NULL");

        $upd = $conn->exec("UPDATE ticket_rota_entries e
                            JOIN rota_locations l ON LOWER(l.name) = LOWER(
                                CASE
                                    WHEN LOWER(e.location) = 'office' THEN 'Office'
                                    WHEN LOWER(e.location) = 'wfh'    THEN 'WFH'
                                    ELSE e.location
                                END)
                            SET e.location_id = l.id
                            WHERE e.location_id IS NULL AND e.location IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'ticket_rota_entries', 'status' => 'migrated', 'details' => ["Backfilled location_id for $upd rota entry/entries"]];
        }

        // Default any still-null rows to the default location
        $conn->exec("UPDATE ticket_rota_entries SET location_id = (SELECT id FROM rota_locations WHERE is_default = 1 LIMIT 1) WHERE location_id IS NULL");
    }

    // Add FK + index for location_id if missing
    if ($tableExists('ticket_rota_entries') && $colExists('ticket_rota_entries', 'location_id')) {
        if (!$idxExists('ticket_rota_entries', 'ix_rota_entries_location_id')) {
            try { $conn->exec("ALTER TABLE ticket_rota_entries ADD KEY ix_rota_entries_location_id (location_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('ticket_rota_entries', 'fk_rota_location') && $tableExists('rota_locations')) {
            try { $conn->exec("ALTER TABLE ticket_rota_entries ADD CONSTRAINT fk_rota_location FOREIGN KEY (location_id) REFERENCES rota_locations (id)"); } catch (Exception $e) {}
        }
    }

    // Drop legacy location column once everything has a location_id
    if ($tableExists('ticket_rota_entries') && $colExists('ticket_rota_entries', 'location')) {
        $orphan = (int) $conn->query("SELECT COUNT(*) FROM ticket_rota_entries WHERE location_id IS NULL")->fetchColumn();
        if ($orphan === 0) {
            try {
                $conn->exec("ALTER TABLE ticket_rota_entries DROP COLUMN `location`");
                $results[] = ['table' => 'ticket_rota_entries', 'status' => 'updated', 'details' => ['Dropped legacy location column']];
            } catch (Exception $e) {}
        } else {
            $results[] = ['table' => 'ticket_rota_entries', 'status' => 'pending', 'details' => ["Cannot drop legacy location column yet — $orphan row(s) still missing location_id"]];
        }
    }

    // ----------------------------------------------------------------------
    // Change Management: lookups for type / status / priority / impact
    // ----------------------------------------------------------------------

    // Seed default change types
    if ($tableExists('change_types')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM change_types")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO change_types (name, colour, is_default, display_order) VALUES
                ('Standard',  '#16a34a', 0, 10),
                ('Normal',    '#2563eb', 1, 20),
                ('Emergency', '#dc2626', 0, 30)");
            $results[] = ['table' => 'change_types', 'status' => 'seeded', 'details' => ['Inserted 3 default change types']];
        }
    }

    // Seed default change statuses
    if ($tableExists('change_statuses')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM change_statuses")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO change_statuses (name, is_closed, colour, is_default, display_order) VALUES
                ('Draft',            0, '#9e9e9e', 1, 10),
                ('Submitted',        0, '#2563eb', 0, 20),
                ('Pending Approval', 0, '#e65100', 0, 30),
                ('Approved',         0, '#2e7d32', 0, 40),
                ('Rejected',         1, '#c62828', 0, 50),
                ('Scheduled',        0, '#9333ea', 0, 60),
                ('In Progress',      0, '#1565c0', 0, 70),
                ('Completed',        1, '#1b5e20', 0, 80),
                ('Failed',           1, '#c62828', 0, 90),
                ('Cancelled',        1, '#bdbdbd', 0, 100)");
            $results[] = ['table' => 'change_statuses', 'status' => 'seeded', 'details' => ['Inserted 10 default change statuses']];
        }
    }

    // Seed default change priorities
    if ($tableExists('change_priorities')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM change_priorities")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO change_priorities (name, colour, is_default, display_order) VALUES
                ('Low',      '#16a34a', 0, 10),
                ('Medium',   '#2563eb', 1, 20),
                ('High',     '#f59e0b', 0, 30),
                ('Critical', '#dc2626', 0, 40)");
            $results[] = ['table' => 'change_priorities', 'status' => 'seeded', 'details' => ['Inserted 4 default change priorities']];
        }
    }

    // Seed default problem statuses (the Problem Management lifecycle).
    if ($tableExists('problem_statuses')) {
        if ((int) $conn->query("SELECT COUNT(*) FROM problem_statuses")->fetchColumn() === 0) {
            $conn->exec("INSERT INTO problem_statuses (name, is_closed, colour, is_default, display_order) VALUES
                ('New',                   0, '#2563eb', 1, 10),
                ('Investigating',         0, '#1565c0', 0, 20),
                ('Root Cause Identified', 0, '#9333ea', 0, 30),
                ('Known Error',           0, '#e65100', 0, 40),
                ('Resolved',              1, '#1b5e20', 0, 50),
                ('Closed',                1, '#607d8b', 0, 60)");
            $results[] = ['table' => 'problem_statuses', 'status' => 'seeded', 'details' => ['Inserted 6 default problem statuses']];
        }
    }

    // Seed default problem priorities.
    if ($tableExists('problem_priorities')) {
        if ((int) $conn->query("SELECT COUNT(*) FROM problem_priorities")->fetchColumn() === 0) {
            $conn->exec("INSERT INTO problem_priorities (name, colour, is_default, display_order) VALUES
                ('Low',      '#16a34a', 0, 10),
                ('Medium',   '#2563eb', 1, 20),
                ('High',     '#f59e0b', 0, 30),
                ('Critical', '#dc2626', 0, 40)");
            $results[] = ['table' => 'problem_priorities', 'status' => 'seeded', 'details' => ['Inserted 4 default problem priorities']];
        }
    }

    // Seed default morning check statuses. Matches the three hardcoded
    // statuses (Green / Amber / Red) that the dashboard used before the
    // statuses became configurable, so existing historical results
    // continue to render with the right colour and the dashboard keeps
    // working out of the box.
    if ($tableExists('morningChecks_Statuses')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM morningChecks_Statuses")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO morningChecks_Statuses (Label, Colour, RequiresNotes, SortOrder, IsActive) VALUES
                ('Green', '#28a745', 0, 10, 1),
                ('Amber', '#ffc107', 1, 20, 1),
                ('Red',   '#dc3545', 1, 30, 1)");
            $results[] = ['table' => 'morningChecks_Statuses', 'status' => 'seeded', 'details' => ['Inserted 3 default morning-check statuses (Green / Amber / Red)']];
        }
    }

    // Normalise morningChecks_Results — switch from label-string-only to a
    // proper StatusID FK while keeping the Status column as a label
    // snapshot for orphans. Idempotent: each step is guarded so re-runs
    // are no-ops.
    if ($tableExists('morningChecks_Results') && $tableExists('morningChecks_Statuses')) {
        // Step 1: relax morningChecks_Results.Status to NULL (was
        // VARCHAR(50) NOT NULL). New normalised writes set Status = NULL.
        try {
            $col = $conn->prepare(
                "SELECT IS_NULLABLE FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = 'morningChecks_Results' AND column_name = 'Status'"
            );
            $col->execute([$dbName]);
            $row = $col->fetch(PDO::FETCH_ASSOC);
            if ($row && strtoupper($row['IS_NULLABLE']) === 'NO') {
                $conn->exec("ALTER TABLE `morningChecks_Results` MODIFY `Status` VARCHAR(50) NULL");
                $results[] = ['table' => 'morningChecks_Results', 'status' => 'updated', 'details' => ['Status: NOT NULL → NULL (StatusID is now the source of truth)']];
            }
        } catch (Exception $e) { /* shrug */ }

        // Step 2: backfill StatusID from the Status label where it
        // matches a row in morningChecks_Statuses. Targets rows where
        // StatusID is NULL — newly migrated rows OR newly added column
        // (the schema loop above just created it).
        try {
            $stmt = $conn->exec(
                "UPDATE morningChecks_Results r
                 JOIN morningChecks_Statuses s ON s.Label = r.Status
                 SET r.StatusID = s.StatusID
                 WHERE r.StatusID IS NULL"
            );
            if ($stmt > 0) {
                $results[] = ['table' => 'morningChecks_Results', 'status' => 'migrated', 'details' => ["Backfilled StatusID for $stmt result row(s) from existing label strings"]];
            }
        } catch (Exception $e) { /* shrug */ }

        // Step 3: add the FK. ON DELETE SET NULL preserves the result
        // row (and its label snapshot in Status) when a status is
        // deleted — the dashboard banner + normalisation tool then
        // surfaces the orphan to the admin.
        $hasFk = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = ? AND table_name = 'morningChecks_Results'
               AND constraint_name = 'fk_results_status' AND constraint_type = 'FOREIGN KEY'"
        );
        $hasFk->execute([$dbName]);
        if ((int)$hasFk->fetchColumn() === 0) {
            try {
                $conn->exec(
                    "ALTER TABLE morningChecks_Results
                     ADD CONSTRAINT fk_results_status
                     FOREIGN KEY (StatusID) REFERENCES morningChecks_Statuses (StatusID)
                     ON DELETE SET NULL"
                );
                $results[] = ['table' => 'morningChecks_Results', 'status' => 'updated', 'details' => ['Added fk_results_status (StatusID → morningChecks_Statuses.StatusID, ON DELETE SET NULL)']];
            } catch (Exception $e) { /* shrug — possibly mismatched engine */ }
        }

        // Step 4: the check FK (fk_results_checks in freeitsm.sql) was never
        // backfilled here, so installs grown via Database Verification could
        // hold results pointing at deleted checks. Remove any such orphans
        // (a result without its check is meaningless — the UI deletes a
        // check's results with the check), then add the constraint.
        $hasCheckFk = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = ? AND table_name = 'morningChecks_Results'
               AND constraint_name = 'fk_results_checks' AND constraint_type = 'FOREIGN KEY'"
        );
        $hasCheckFk->execute([$dbName]);
        if ((int)$hasCheckFk->fetchColumn() === 0 && $tableExists('morningChecks_Checks')) {
            try {
                $orphans = $conn->exec(
                    "DELETE r FROM morningChecks_Results r
                     LEFT JOIN morningChecks_Checks c ON c.CheckID = r.CheckID
                     WHERE c.CheckID IS NULL"
                );
                $conn->exec(
                    "ALTER TABLE morningChecks_Results
                     ADD CONSTRAINT fk_results_checks
                     FOREIGN KEY (CheckID) REFERENCES morningChecks_Checks (CheckID)"
                );
                $details = ['Added fk_results_checks (CheckID → morningChecks_Checks.CheckID)'];
                if ($orphans > 0) {
                    $details[] = "Removed $orphans orphaned result row(s) whose check no longer exists";
                }
                $results[] = ['table' => 'morningChecks_Results', 'status' => 'updated', 'details' => $details];
            } catch (Exception $e) { /* shrug — possibly mismatched engine */ }
        }
    }

    // Seed default change impacts
    if ($tableExists('change_impacts')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM change_impacts")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO change_impacts (name, colour, is_default, display_order) VALUES
                ('Low',    '#16a34a', 0, 10),
                ('Medium', '#2563eb', 1, 20),
                ('High',   '#f59e0b', 0, 30)");
            $results[] = ['table' => 'change_impacts', 'status' => 'seeded', 'details' => ['Inserted 3 default change impacts']];
        }
    }

    // Seed default change form sections + per-field layout.
    // The sections + initial field-to-section assignment mirror what was
    // previously hardcoded in change-management/settings/index.php's
    // FIELD_SECTIONS const. Field visibility is migrated from the old
    // system_settings.field_visibility JSON blob if present (so admins
    // who had toggled fields off don't lose their setting).
    if ($tableExists('change_field_sections') && $tableExists('change_field_layout')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM change_field_sections")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO change_field_sections (id, name, display_order) VALUES
                (1, 'General information', 10),
                (2, 'People',              20),
                (3, 'Schedule',            30),
                (4, 'Details',             40),
                (5, 'Attachments',         50)");
            // Field catalogue mirrors api/change-management/get_field_layout.php
            // — single source of truth lives there. We seed in this order.
            $defaultLayout = [
                // section_id, field_key, display_order
                [1, 'title',        10],
                [1, 'change_type',  20],
                [1, 'status',       30],
                [1, 'priority',     40],
                [1, 'impact',       50],
                [1, 'category',     60],
                [2, 'requester',    10],
                [2, 'assigned_to',  20],
                [2, 'approver',     30],
                [2, 'cab',          40],
                [3, 'work_start',   10],
                [3, 'work_end',     20],
                [3, 'outage_start', 30],
                [3, 'outage_end',   40],
                [4, 'description',  10],
                [4, 'reason',       20],
                [4, 'risk',         30],
                [4, 'testplan',     40],
                [4, 'rollback',     50],
                [4, 'pir',          60],
                [5, 'attachments',  10],
            ];
            // Pull any pre-existing visibility from system_settings so we keep
            // the admin's earlier toggles. Best-effort — silent fallback if
            // the row / column doesn't exist on this install.
            $visMap = [];
            try {
                $stmt = $conn->query("SELECT settings_json FROM system_settings WHERE id = 1");
                $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($row && !empty($row['settings_json'])) {
                    $decoded = json_decode($row['settings_json'], true);
                    if (is_array($decoded) && isset($decoded['field_visibility']) && is_array($decoded['field_visibility'])) {
                        $visMap = $decoded['field_visibility'];
                    }
                }
            } catch (Exception $e) {
                // Fine — no previous layout to migrate
            }
            $insertStmt = $conn->prepare(
                "INSERT INTO change_field_layout (field_key, section_id, display_order, is_visible) VALUES (?, ?, ?, ?)"
            );
            foreach ($defaultLayout as [$sectionId, $fieldKey, $order]) {
                $isVisible = array_key_exists($fieldKey, $visMap) ? ($visMap[$fieldKey] ? 1 : 0) : 1;
                $insertStmt->execute([$fieldKey, $sectionId, $order, $isVisible]);
            }
            $results[] = [
                'table' => 'change_field_sections',
                'status' => 'seeded',
                'details' => ['Inserted 5 default sections + ' . count($defaultLayout) . ' field placements (visibility migrated from system_settings.field_visibility where present)']
            ];
        }
    }

    // Backfill changes.{change_type_id, status_id, priority_id, impact_id} and change_templates equivalents
    $changeBackfills = [
        ['changes',           'change_type', 'change_type_id', 'change_types'],
        ['changes',           'status',      'status_id',      'change_statuses'],
        ['changes',           'priority',    'priority_id',    'change_priorities'],
        ['changes',           'impact',      'impact_id',      'change_impacts'],
        ['change_templates',  'change_type', 'change_type_id', 'change_types'],
        ['change_templates',  'priority',    'priority_id',    'change_priorities'],
        ['change_templates',  'impact',      'impact_id',      'change_impacts'],
    ];
    foreach ($changeBackfills as [$tbl, $oldCol, $newCol, $lkTbl]) {
        if (!$tableExists($tbl) || !$colExists($tbl, $oldCol) || !$colExists($tbl, $newCol) || !$tableExists($lkTbl)) continue;

        // Insert any unrecognised values into the lookup so the FK can be satisfied
        $conn->exec("INSERT IGNORE INTO `$lkTbl` (name, display_order)
                     SELECT DISTINCT t.`$oldCol`, 999
                     FROM `$tbl` t
                     LEFT JOIN `$lkTbl` l ON LOWER(l.name) = LOWER(t.`$oldCol`)
                     WHERE t.`$oldCol` IS NOT NULL AND t.`$oldCol` <> '' AND l.id IS NULL");

        $upd = $conn->exec("UPDATE `$tbl` t
                            JOIN `$lkTbl` l ON LOWER(l.name) = LOWER(t.`$oldCol`)
                            SET t.`$newCol` = l.id
                            WHERE t.`$newCol` IS NULL AND t.`$oldCol` IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => $tbl, 'status' => 'migrated', 'details' => ["Backfilled $newCol for $upd row(s) from legacy $oldCol"]];
        }

        // Default any still-null rows to the configured default lookup row
        $conn->exec("UPDATE `$tbl` SET `$newCol` = (SELECT id FROM `$lkTbl` WHERE is_default = 1 LIMIT 1) WHERE `$newCol` IS NULL");
    }

    // Add FKs + indexes for new change columns if missing
    $changeFks = [
        ['changes',          'fk_changes_status',      "ALTER TABLE changes ADD CONSTRAINT fk_changes_status FOREIGN KEY (status_id) REFERENCES change_statuses (id)"],
        ['changes',          'fk_changes_priority',    "ALTER TABLE changes ADD CONSTRAINT fk_changes_priority FOREIGN KEY (priority_id) REFERENCES change_priorities (id)"],
        ['changes',          'fk_changes_change_type', "ALTER TABLE changes ADD CONSTRAINT fk_changes_change_type FOREIGN KEY (change_type_id) REFERENCES change_types (id)"],
        ['changes',          'fk_changes_impact',      "ALTER TABLE changes ADD CONSTRAINT fk_changes_impact FOREIGN KEY (impact_id) REFERENCES change_impacts (id)"],
        ['changes',          'fk_changes_tenant',      "ALTER TABLE changes ADD CONSTRAINT fk_changes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"],
        ['change_templates', 'fk_template_change_type',"ALTER TABLE change_templates ADD CONSTRAINT fk_template_change_type FOREIGN KEY (change_type_id) REFERENCES change_types (id)"],
        ['change_templates', 'fk_template_priority',   "ALTER TABLE change_templates ADD CONSTRAINT fk_template_priority FOREIGN KEY (priority_id) REFERENCES change_priorities (id)"],
        ['change_templates', 'fk_template_impact',     "ALTER TABLE change_templates ADD CONSTRAINT fk_template_impact FOREIGN KEY (impact_id) REFERENCES change_impacts (id)"],
        ['change_field_layout', 'fk_cfl_section',      "ALTER TABLE change_field_layout ADD CONSTRAINT fk_cfl_section FOREIGN KEY (section_id) REFERENCES change_field_sections (id) ON DELETE CASCADE"],
    ];
    foreach ($changeFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }
    $changeIndexes = [
        ['changes', 'ix_changes_status_id',      'status_id'],
        ['changes', 'ix_changes_priority_id',    'priority_id'],
        ['changes', 'ix_changes_change_type_id', 'change_type_id'],
        ['changes', 'ix_changes_impact_id',      'impact_id'],
        ['changes', 'ix_changes_tenant_id',      'tenant_id'],
    ];
    foreach ($changeIndexes as [$tbl, $name, $col]) {
        if (!$tableExists($tbl) || $idxExists($tbl, $name)) continue;
        try { $conn->exec("ALTER TABLE `$tbl` ADD KEY `$name` (`$col`)"); } catch (Exception $e) {}
    }

    // Problem Management foreign keys + indexes.
    $problemFks = [
        ['problems',        'fk_problems_status',   "ALTER TABLE problems ADD CONSTRAINT fk_problems_status FOREIGN KEY (status_id) REFERENCES problem_statuses (id)"],
        ['problems',        'fk_problems_priority', "ALTER TABLE problems ADD CONSTRAINT fk_problems_priority FOREIGN KEY (priority_id) REFERENCES problem_priorities (id)"],
        ['problems',        'fk_problems_tenant',   "ALTER TABLE problems ADD CONSTRAINT fk_problems_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"],
        ['problem_tickets', 'fk_ptickets_problem',  "ALTER TABLE problem_tickets ADD CONSTRAINT fk_ptickets_problem FOREIGN KEY (problem_id) REFERENCES problems (id) ON DELETE CASCADE"],
        ['problem_tickets', 'fk_ptickets_ticket',   "ALTER TABLE problem_tickets ADD CONSTRAINT fk_ptickets_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['change_tickets',  'fk_ctickets_change',   "ALTER TABLE change_tickets ADD CONSTRAINT fk_ctickets_change FOREIGN KEY (change_id) REFERENCES changes (id) ON DELETE CASCADE"],
        ['change_tickets',  'fk_ctickets_ticket',   "ALTER TABLE change_tickets ADD CONSTRAINT fk_ctickets_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['ticket_links',    'fk_ticket_links_source', "ALTER TABLE ticket_links ADD CONSTRAINT fk_ticket_links_source FOREIGN KEY (source_ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['ticket_links',    'fk_ticket_links_target', "ALTER TABLE ticket_links ADD CONSTRAINT fk_ticket_links_target FOREIGN KEY (target_ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['problem_audit',   'fk_paudit_problem',    "ALTER TABLE problem_audit ADD CONSTRAINT fk_paudit_problem FOREIGN KEY (problem_id) REFERENCES problems (id) ON DELETE CASCADE"],
        ['problem_notes',   'fk_pnotes_problem',    "ALTER TABLE problem_notes ADD CONSTRAINT fk_pnotes_problem FOREIGN KEY (problem_id) REFERENCES problems (id) ON DELETE CASCADE"],
    ];
    foreach ($problemFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }
    $problemIndexes = [
        ['problems', 'ix_problems_status_id',  'status_id'],
        ['problems', 'ix_problems_tenant_id',  'tenant_id'],
        ['problem_tickets', 'ix_ptickets_ticket', 'ticket_id'],
        ['change_tickets',  'ix_ctickets_ticket', 'ticket_id'],
        ['ticket_links',    'ix_ticket_links_target', 'target_ticket_id'],
        ['problem_notes', 'ix_pnotes_problem', 'problem_id'],
    ];
    foreach ($problemIndexes as [$tbl, $name, $col]) {
        if (!$tableExists($tbl) || $idxExists($tbl, $name)) continue;
        try { $conn->exec("ALTER TABLE `$tbl` ADD KEY `$name` (`$col`)"); } catch (Exception $e) {}
    }

    // SSO / OIDC foreign keys (db_verify $schema only builds columns + PK; FKs added here)
    $ssoFks = [
        ['analyst_sso_identities', 'fk_sso_identity_analyst',  "ALTER TABLE analyst_sso_identities ADD CONSTRAINT fk_sso_identity_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE CASCADE"],
        ['analyst_sso_identities', 'fk_sso_identity_provider', "ALTER TABLE analyst_sso_identities ADD CONSTRAINT fk_sso_identity_provider FOREIGN KEY (provider_id) REFERENCES auth_providers (id) ON DELETE CASCADE"],
        ['analysts',               'fk_analysts_auth_provider', "ALTER TABLE analysts ADD CONSTRAINT fk_analysts_auth_provider FOREIGN KEY (auth_provider_id) REFERENCES auth_providers (id) ON DELETE SET NULL"],
        // Self-service requester SSO (mirror of the analyst tables, one layer down).
        ['user_sso_identities',    'fk_user_sso_identity_user',     "ALTER TABLE user_sso_identities ADD CONSTRAINT fk_user_sso_identity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE"],
        ['user_sso_identities',    'fk_user_sso_identity_provider', "ALTER TABLE user_sso_identities ADD CONSTRAINT fk_user_sso_identity_provider FOREIGN KEY (provider_id) REFERENCES auth_providers (id) ON DELETE CASCADE"],
        ['users',                  'fk_users_auth_provider',        "ALTER TABLE users ADD CONSTRAINT fk_users_auth_provider FOREIGN KEY (auth_provider_id) REFERENCES auth_providers (id) ON DELETE SET NULL"],
        // Multi-tenant portal SSO: a provider can be owned by a client company.
        ['auth_providers',         'fk_auth_providers_tenant',      "ALTER TABLE auth_providers ADD CONSTRAINT fk_auth_providers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"],
    ];
    foreach ($ssoFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Knowledge foreign keys (db_verify $schema only builds columns + PK; a
    // grown install was missing all of these, which orphaned tag links on
    // hard delete). Names + delete rules match freeitsm.sql. The article-tags
    // FKs won't add while orphaned junction rows exist (MySQL refuses); the
    // endpoints now clean children explicitly, so orphans stop accumulating.
    $knowledgeFks = [
        ['knowledge_articles',         'fk_knowledge_articles_author',      "ALTER TABLE knowledge_articles ADD CONSTRAINT fk_knowledge_articles_author FOREIGN KEY (author_id) REFERENCES analysts (id)"],
        ['knowledge_articles',         'fk_knowledge_articles_owner',       "ALTER TABLE knowledge_articles ADD CONSTRAINT fk_knowledge_articles_owner FOREIGN KEY (owner_id) REFERENCES analysts (id)"],
        ['knowledge_articles',         'fk_knowledge_articles_archived_by', "ALTER TABLE knowledge_articles ADD CONSTRAINT fk_knowledge_articles_archived_by FOREIGN KEY (archived_by_id) REFERENCES analysts (id)"],
        ['knowledge_article_versions', 'fk_kav_article',                    "ALTER TABLE knowledge_article_versions ADD CONSTRAINT fk_kav_article FOREIGN KEY (article_id) REFERENCES knowledge_articles (id)"],
        ['knowledge_article_versions', 'fk_kav_saved_by',                   "ALTER TABLE knowledge_article_versions ADD CONSTRAINT fk_kav_saved_by FOREIGN KEY (saved_by_id) REFERENCES analysts (id)"],
        ['knowledge_article_tags',     'fk_article_tags_article',           "ALTER TABLE knowledge_article_tags ADD CONSTRAINT fk_article_tags_article FOREIGN KEY (article_id) REFERENCES knowledge_articles (id) ON DELETE CASCADE"],
        ['knowledge_article_tags',     'fk_article_tags_tag',               "ALTER TABLE knowledge_article_tags ADD CONSTRAINT fk_article_tags_tag FOREIGN KEY (tag_id) REFERENCES knowledge_tags (id) ON DELETE CASCADE"],
    ];
    foreach ($knowledgeFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // CMDB foreign keys (db_verify $schema only builds columns + PK; grown
    // installs had NONE of these, so the module's cascade-delete design
    // silently didn't apply there). Names + delete rules match freeitsm.sql.
    $cmdbFks = [
        ['cmdb_classes',                'fk_cmdb_classes_icon',    "ALTER TABLE cmdb_classes ADD CONSTRAINT fk_cmdb_classes_icon FOREIGN KEY (icon_id) REFERENCES cmdb_icons (id) ON DELETE SET NULL"],
        ['cmdb_class_properties',       'fk_cmdb_cp_class',        "ALTER TABLE cmdb_class_properties ADD CONSTRAINT fk_cmdb_cp_class FOREIGN KEY (class_id) REFERENCES cmdb_classes (id) ON DELETE CASCADE"],
        ['cmdb_class_properties',       'fk_cmdb_cp_target_class', "ALTER TABLE cmdb_class_properties ADD CONSTRAINT fk_cmdb_cp_target_class FOREIGN KEY (target_class_id) REFERENCES cmdb_classes (id)"],
        ['cmdb_class_property_options', 'fk_cmdb_cpo_property',    "ALTER TABLE cmdb_class_property_options ADD CONSTRAINT fk_cmdb_cpo_property FOREIGN KEY (property_id) REFERENCES cmdb_class_properties (id) ON DELETE CASCADE"],
        ['cmdb_objects',                'fk_cmdb_objects_class',   "ALTER TABLE cmdb_objects ADD CONSTRAINT fk_cmdb_objects_class FOREIGN KEY (class_id) REFERENCES cmdb_classes (id)"],
        ['cmdb_objects',                'fk_cmdb_objects_parent',  "ALTER TABLE cmdb_objects ADD CONSTRAINT fk_cmdb_objects_parent FOREIGN KEY (parent_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['cmdb_object_properties',      'fk_cmdb_op_object',       "ALTER TABLE cmdb_object_properties ADD CONSTRAINT fk_cmdb_op_object FOREIGN KEY (object_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['cmdb_object_properties',      'fk_cmdb_op_property',     "ALTER TABLE cmdb_object_properties ADD CONSTRAINT fk_cmdb_op_property FOREIGN KEY (property_id) REFERENCES cmdb_class_properties (id) ON DELETE CASCADE"],
        ['cmdb_object_properties',      'fk_cmdb_op_value_object', "ALTER TABLE cmdb_object_properties ADD CONSTRAINT fk_cmdb_op_value_object FOREIGN KEY (value_object_id) REFERENCES cmdb_objects (id) ON DELETE SET NULL"],
        ['cmdb_object_relationships',   'fk_cmdb_or_from',         "ALTER TABLE cmdb_object_relationships ADD CONSTRAINT fk_cmdb_or_from FOREIGN KEY (from_object_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['cmdb_object_relationships',   'fk_cmdb_or_to',           "ALTER TABLE cmdb_object_relationships ADD CONSTRAINT fk_cmdb_or_to FOREIGN KEY (to_object_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['cmdb_object_relationships',   'fk_cmdb_or_type',         "ALTER TABLE cmdb_object_relationships ADD CONSTRAINT fk_cmdb_or_type FOREIGN KEY (relationship_type_id) REFERENCES cmdb_relationship_types (id)"],
        ['ticket_cmdb_objects',         'fk_tco_ticket',           "ALTER TABLE ticket_cmdb_objects ADD CONSTRAINT fk_tco_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['ticket_cmdb_objects',         'fk_tco_cmdb_object',      "ALTER TABLE ticket_cmdb_objects ADD CONSTRAINT fk_tco_cmdb_object FOREIGN KEY (cmdb_object_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['ticket_cmdb_objects',         'fk_tco_analyst',          "ALTER TABLE ticket_cmdb_objects ADD CONSTRAINT fk_tco_analyst FOREIGN KEY (created_by_analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"],
    ];
    foreach ($cmdbFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Software-module foreign keys (db_verify $schema only builds columns +
    // PK). Names + rules match freeitsm.sql; note the RESTRICT (no rule) FKs
    // deliberately block deleting an app while installs/licences reference it.
    $softwareFks = [
        ['software_inventory_detail',        'fk_software_detail_app',      "ALTER TABLE software_inventory_detail ADD CONSTRAINT fk_software_detail_app FOREIGN KEY (app_id) REFERENCES software_inventory_apps (id)"],
        ['software_licences',                'fk_software_licences_app',    "ALTER TABLE software_licences ADD CONSTRAINT fk_software_licences_app FOREIGN KEY (app_id) REFERENCES software_inventory_apps (id)"],
        ['software_licences',                'fk_software_licences_analyst', "ALTER TABLE software_licences ADD CONSTRAINT fk_software_licences_analyst FOREIGN KEY (created_by) REFERENCES analysts (id)"],
        ['software_dashboard_widgets',       'fk_sdw_app',                  "ALTER TABLE software_dashboard_widgets ADD CONSTRAINT fk_sdw_app FOREIGN KEY (app_id) REFERENCES software_inventory_apps (id) ON DELETE SET NULL"],
        ['analyst_software_dashboard_widgets', 'fk_asdw_analyst',           "ALTER TABLE analyst_software_dashboard_widgets ADD CONSTRAINT fk_asdw_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id)"],
        ['analyst_software_dashboard_widgets', 'fk_asdw_widget',            "ALTER TABLE analyst_software_dashboard_widgets ADD CONSTRAINT fk_asdw_widget FOREIGN KEY (widget_id) REFERENCES software_dashboard_widgets (id)"],
    ];
    foreach ($softwareFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Calendar foreign keys (db_verify $schema only builds columns + PK) —
    // names + rules match freeitsm.sql. The category FK has no delete rule
    // (RESTRICT), backstopping delete_category.php's in-use guard.
    $calendarFks = [
        ['calendar_events', 'fk_calendar_events_category', "ALTER TABLE calendar_events ADD CONSTRAINT fk_calendar_events_category FOREIGN KEY (category_id) REFERENCES calendar_categories (id)"],
        ['calendar_events', 'fk_calendar_events_contract', "ALTER TABLE calendar_events ADD CONSTRAINT fk_calendar_events_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE SET NULL"],
    ];
    foreach ($calendarFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Contracts-domain foreign keys (db_verify $schema only builds columns +
    // PK; this domain historically had NO FKs anywhere — not even in
    // freeitsm.sql — so contract deletes orphaned term values). Names + rules
    // match the constraints now in freeitsm.sql. Lookups use SET NULL to
    // preserve the existing delete-freely settings behaviour.
    $contractFks = [
        ['suppliers',            'fk_suppliers_type',             "ALTER TABLE suppliers ADD CONSTRAINT fk_suppliers_type FOREIGN KEY (supplier_type_id) REFERENCES supplier_types (id) ON DELETE SET NULL"],
        ['suppliers',            'fk_suppliers_status',           "ALTER TABLE suppliers ADD CONSTRAINT fk_suppliers_status FOREIGN KEY (supplier_status_id) REFERENCES supplier_statuses (id) ON DELETE SET NULL"],
        ['contacts',             'fk_contacts_supplier',          "ALTER TABLE contacts ADD CONSTRAINT fk_contacts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL"],
        ['contracts',            'fk_contracts_supplier',         "ALTER TABLE contracts ADD CONSTRAINT fk_contracts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL"],
        ['contracts',            'fk_contracts_owner',            "ALTER TABLE contracts ADD CONSTRAINT fk_contracts_owner FOREIGN KEY (contract_owner_id) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['contracts',            'fk_contracts_status',           "ALTER TABLE contracts ADD CONSTRAINT fk_contracts_status FOREIGN KEY (contract_status_id) REFERENCES contract_statuses (id) ON DELETE SET NULL"],
        ['contracts',            'fk_contracts_payment_schedule', "ALTER TABLE contracts ADD CONSTRAINT fk_contracts_payment_schedule FOREIGN KEY (payment_schedule_id) REFERENCES payment_schedules (id) ON DELETE SET NULL"],
        ['contract_term_values', 'fk_ctv_contract',               "ALTER TABLE contract_term_values ADD CONSTRAINT fk_ctv_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE"],
        ['contract_term_values', 'fk_ctv_term_tab',               "ALTER TABLE contract_term_values ADD CONSTRAINT fk_ctv_term_tab FOREIGN KEY (term_tab_id) REFERENCES contract_term_tabs (id) ON DELETE CASCADE"],
    ];
    foreach ($contractFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Forms-module foreign keys (db_verify $schema only builds columns + PK;
    // grown installs had NONE of the four freeitsm.sql constraints, and
    // parent_form_id — the #442 version chain — never had one anywhere).
    // Orphans are cleaned first so the constraints can attach: fields /
    // submissions / data of deleted parents go, dangling version-chain
    // pointers become chain roots (SET NULL).
    if ($tableExists('forms')) {
        try {
            if ($tableExists('form_fields')) {
                $conn->exec("DELETE ff FROM form_fields ff LEFT JOIN forms f ON f.id = ff.form_id WHERE f.id IS NULL");
            }
            if ($tableExists('form_submission_data')) {
                $conn->exec("DELETE sd FROM form_submission_data sd LEFT JOIN form_submissions s ON s.id = sd.submission_id WHERE s.id IS NULL");
                $conn->exec("DELETE sd FROM form_submission_data sd LEFT JOIN form_fields ff ON ff.id = sd.field_id WHERE ff.id IS NULL");
            }
            if ($tableExists('form_submissions')) {
                $conn->exec("DELETE s FROM form_submissions s LEFT JOIN forms f ON f.id = s.form_id WHERE f.id IS NULL");
            }
            $conn->exec("UPDATE forms c LEFT JOIN forms p ON p.id = c.parent_form_id SET c.parent_form_id = NULL WHERE c.parent_form_id IS NOT NULL AND p.id IS NULL");
        } catch (Exception $e) { /* shrug */ }
    }
    $formsFks = [
        ['forms',                'fk_forms_parent',              "ALTER TABLE forms ADD CONSTRAINT fk_forms_parent FOREIGN KEY (parent_form_id) REFERENCES forms (id)"],
        ['form_fields',          'fk_form_fields_form',          "ALTER TABLE form_fields ADD CONSTRAINT fk_form_fields_form FOREIGN KEY (form_id) REFERENCES forms (id) ON DELETE CASCADE"],
        ['form_submissions',     'fk_form_submissions_form',     "ALTER TABLE form_submissions ADD CONSTRAINT fk_form_submissions_form FOREIGN KEY (form_id) REFERENCES forms (id)"],
        ['form_submission_data', 'fk_submission_data_submission', "ALTER TABLE form_submission_data ADD CONSTRAINT fk_submission_data_submission FOREIGN KEY (submission_id) REFERENCES form_submissions (id) ON DELETE CASCADE"],
        ['form_submission_data', 'fk_submission_data_field',     "ALTER TABLE form_submission_data ADD CONSTRAINT fk_submission_data_field FOREIGN KEY (field_id) REFERENCES form_fields (id)"],
    ];
    foreach ($formsFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Workflow-module foreign keys (db_verify $schema only builds columns +
    // PK; these tables historically had NO FKs anywhere — deliberately, so
    // execution rows survive workflow deletion as an audit trail). The intent
    // is kept but made referentially sound: workflow_id becomes nullable with
    // ON DELETE SET NULL, and the workflow_name snapshot (backfilled from
    // still-live parents, stamped by the engine on every new run) keeps
    // orphaned runs attributable. Existing dangling workflow_ids are detached
    // (no rows deleted).
    if ($tableExists('workflow_executions')) {
        try {
            if ($tableExists('workflows')) {
                $conn->exec("UPDATE workflow_executions we JOIN workflows w ON w.id = we.workflow_id
                             SET we.workflow_name = w.name WHERE we.workflow_name IS NULL");
                $conn->exec("UPDATE workflow_executions we LEFT JOIN workflows w ON w.id = we.workflow_id
                             SET we.workflow_id = NULL WHERE we.workflow_id IS NOT NULL AND w.id IS NULL");
            }
            // Grown installs created workflow_id as NOT NULL — the SET NULL
            // FK can't attach until it's nullable ($schema only adds columns).
            $conn->exec("ALTER TABLE workflow_executions MODIFY workflow_id INT NULL");
        } catch (Exception $e) { /* shrug */ }
    }
    if ($tableExists('workflows') && $tableExists('analysts')) {
        try {
            $conn->exec("UPDATE workflows w LEFT JOIN analysts a ON a.id = w.created_by
                         SET w.created_by = NULL WHERE w.created_by IS NOT NULL AND a.id IS NULL");
        } catch (Exception $e) { /* shrug */ }
    }
    // webhook_deliveries.workflow_id can dangle if a workflow is deleted while a
    // delivery is queued; detach (SET NULL) before attaching the FK.
    if ($tableExists('webhook_deliveries') && $tableExists('workflows')) {
        try {
            $conn->exec("UPDATE webhook_deliveries wd LEFT JOIN workflows w ON w.id = wd.workflow_id
                         SET wd.workflow_id = NULL WHERE wd.workflow_id IS NOT NULL AND w.id IS NULL");
        } catch (Exception $e) { /* shrug */ }
    }
    // response_snippet was originally TEXT (64KB). We now store the full endpoint
    // response for the Webhooks queue log, so widen it to MEDIUMTEXT (matching
    // request_body). MODIFY is a no-op if it's already MEDIUMTEXT.
    if ($tableExists('webhook_deliveries')) {
        try {
            $col = $conn->query("SHOW COLUMNS FROM webhook_deliveries LIKE 'response_snippet'")->fetch(PDO::FETCH_ASSOC);
            if ($col && stripos($col['Type'], 'mediumtext') === false) {
                $conn->exec("ALTER TABLE webhook_deliveries MODIFY `response_snippet` MEDIUMTEXT NULL");
            }
        } catch (Exception $e) { /* shrug */ }
    }
    // url was VARCHAR(1000). It is now ENCRYPTED at rest, and AES-256-GCM +
    // base64 inflates a string by ~1/3 + 28 bytes — so a max-length 1000-char
    // URL becomes ~1377 chars. At VARCHAR(1000) MySQL would silently TRUNCATE
    // the ciphertext, and a truncated ciphertext can never be decrypted again.
    // This widen MUST happen before anything is encrypted. No-op once widened.
    if ($tableExists('webhook_deliveries')) {
        try {
            $col = $conn->query("SHOW COLUMNS FROM webhook_deliveries LIKE 'url'")->fetch(PDO::FETCH_ASSOC);
            if ($col && !preg_match('/varchar\((\d+)\)/i', $col['Type'], $m0)) {
                // not a varchar at all — leave it alone
            } elseif ($col && isset($m0[1]) && (int)$m0[1] < 2000) {
                $conn->exec("ALTER TABLE webhook_deliveries MODIFY `url` VARCHAR(2000) NOT NULL");
                $results[] = ['table' => 'webhook_deliveries', 'status' => 'altered',
                              'details' => ['Widened url to VARCHAR(2000) — required headroom for encryption at rest']];
            }
        } catch (Exception $e) { /* shrug */ }
    }
    $workflowFks = [
        ['workflows',           'fk_workflows_created_by', "ALTER TABLE workflows ADD CONSTRAINT fk_workflows_created_by FOREIGN KEY (created_by) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['workflow_executions', 'fk_we_workflow',          "ALTER TABLE workflow_executions ADD CONSTRAINT fk_we_workflow FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE SET NULL"],
        ['webhook_deliveries',  'fk_wd_workflow',          "ALTER TABLE webhook_deliveries ADD CONSTRAINT fk_wd_workflow FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE SET NULL"],
    ];
    foreach ($workflowFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Network Mapper foreign keys (db_verify $schema only builds columns + PK;
    // freeitsm.sql has had 7 of these 8 since the module shipped but grown
    // installs got NONE — so delete_diagram.php's reliance on CASCADE orphaned
    // nodes/connectors there, and deleting a CMDB object left its diagram
    // nodes dangling). Orphans are cleaned first so the constraints attach:
    // nodes of dead diagrams/objects go (CASCADE semantics, matching fresh
    // installs), connectors of dead diagrams/nodes go, dangling provenance /
    // parent / author pointers become NULL (SET NULL semantics).
    if ($tableExists('network_diagrams')) {
        try {
            if ($tableExists('network_diagram_nodes')) {
                $conn->exec("DELETE n FROM network_diagram_nodes n LEFT JOIN network_diagrams d ON d.id = n.diagram_id WHERE d.id IS NULL");
                if ($tableExists('cmdb_objects')) {
                    $conn->exec("DELETE n FROM network_diagram_nodes n LEFT JOIN cmdb_objects o ON o.id = n.cmdb_object_id WHERE o.id IS NULL");
                }
            }
            if ($tableExists('network_diagram_connectors')) {
                $conn->exec("DELETE c FROM network_diagram_connectors c LEFT JOIN network_diagrams d ON d.id = c.diagram_id WHERE d.id IS NULL");
                $conn->exec("DELETE c FROM network_diagram_connectors c LEFT JOIN network_diagram_nodes n ON n.id = c.from_node_id WHERE n.id IS NULL");
                $conn->exec("DELETE c FROM network_diagram_connectors c LEFT JOIN network_diagram_nodes n ON n.id = c.to_node_id WHERE n.id IS NULL");
                if ($tableExists('cmdb_object_relationships')) {
                    $conn->exec("UPDATE network_diagram_connectors c LEFT JOIN cmdb_object_relationships r ON r.id = c.cmdb_relationship_id
                                 SET c.cmdb_relationship_id = NULL WHERE c.cmdb_relationship_id IS NOT NULL AND r.id IS NULL");
                }
            }
            $conn->exec("UPDATE network_diagrams d LEFT JOIN network_diagrams p ON p.id = d.parent_diagram_id
                         SET d.parent_diagram_id = NULL WHERE d.parent_diagram_id IS NOT NULL AND p.id IS NULL");
            $conn->exec("UPDATE network_diagrams d LEFT JOIN analysts a ON a.id = d.created_by_analyst_id
                         SET d.created_by_analyst_id = NULL WHERE d.created_by_analyst_id IS NOT NULL AND a.id IS NULL");
        } catch (Exception $e) { /* shrug */ }
    }
    $networkFks = [
        ['network_diagrams',           'fk_net_diag_parent', "ALTER TABLE network_diagrams ADD CONSTRAINT fk_net_diag_parent FOREIGN KEY (parent_diagram_id) REFERENCES network_diagrams (id) ON DELETE SET NULL"],
        ['network_diagrams',           'fk_net_diag_author', "ALTER TABLE network_diagrams ADD CONSTRAINT fk_net_diag_author FOREIGN KEY (created_by_analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['network_diagram_nodes',      'fk_net_node_diag',   "ALTER TABLE network_diagram_nodes ADD CONSTRAINT fk_net_node_diag FOREIGN KEY (diagram_id) REFERENCES network_diagrams (id) ON DELETE CASCADE"],
        ['network_diagram_nodes',      'fk_net_node_cmdb',   "ALTER TABLE network_diagram_nodes ADD CONSTRAINT fk_net_node_cmdb FOREIGN KEY (cmdb_object_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['network_diagram_connectors', 'fk_net_conn_diag',   "ALTER TABLE network_diagram_connectors ADD CONSTRAINT fk_net_conn_diag FOREIGN KEY (diagram_id) REFERENCES network_diagrams (id) ON DELETE CASCADE"],
        ['network_diagram_connectors', 'fk_net_conn_from',   "ALTER TABLE network_diagram_connectors ADD CONSTRAINT fk_net_conn_from FOREIGN KEY (from_node_id) REFERENCES network_diagram_nodes (id) ON DELETE CASCADE"],
        ['network_diagram_connectors', 'fk_net_conn_to',     "ALTER TABLE network_diagram_connectors ADD CONSTRAINT fk_net_conn_to FOREIGN KEY (to_node_id) REFERENCES network_diagram_nodes (id) ON DELETE CASCADE"],
        ['network_diagram_connectors', 'fk_net_conn_rel',    "ALTER TABLE network_diagram_connectors ADD CONSTRAINT fk_net_conn_rel FOREIGN KEY (cmdb_relationship_id) REFERENCES cmdb_object_relationships (id) ON DELETE SET NULL"],
    ];
    foreach ($networkFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // REST API v1 key foreign keys (db_verify $schema only builds columns + PK)
    $apiKeyFks = [
        ['api_keys',            'fk_api_keys_analyst',        "ALTER TABLE api_keys ADD CONSTRAINT fk_api_keys_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id)"],
        ['api_keys',            'fk_api_keys_created_by',     "ALTER TABLE api_keys ADD CONSTRAINT fk_api_keys_created_by FOREIGN KEY (created_by) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['api_key_rate_limits', 'fk_api_key_rate_limits_key', "ALTER TABLE api_key_rate_limits ADD CONSTRAINT fk_api_key_rate_limits_key FOREIGN KEY (api_key_id) REFERENCES api_keys (id) ON DELETE CASCADE"],
    ];
    foreach ($apiKeyFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Ticket child foreign keys (db_verify $schema only builds columns + PK; FKs
    // added here so installs grown via db_verify match a fresh freeitsm.sql).
    // These have NO cascade, so delete_ticket.php removes the children explicitly.
    // NON-DESTRUCTIVE: db_verify never deletes rows. MySQL refuses to add a FK
    // while orphaned child rows exist (e.g. attachments left behind when a
    // pre-fix delete removed an email but not its email_attachments); when that
    // happens we leave the data untouched and report it as 'pending' so the
    // admin can clear the orphans deliberately, then re-run.
    // [child table, constraint name, child FK column, parent table, ADD sql]
    $ticketChildFks = [
        ['email_attachments',   'fk_email_attachments_email', 'email_id',  'emails',  "ALTER TABLE email_attachments ADD CONSTRAINT fk_email_attachments_email FOREIGN KEY (email_id) REFERENCES emails (id)"],
        ['ticket_notes',        'fk_notes_tickets',           'ticket_id', 'tickets', "ALTER TABLE ticket_notes ADD CONSTRAINT fk_notes_tickets FOREIGN KEY (ticket_id) REFERENCES tickets (id)"],
        ['ticket_audit',        'fk_ticket_audit_ticket',     'ticket_id', 'tickets', "ALTER TABLE ticket_audit ADD CONSTRAINT fk_ticket_audit_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)"],
        ['ticket_time_entries', 'fk_time_entries_tickets',    'ticket_id', 'tickets', "ALTER TABLE ticket_time_entries ADD CONSTRAINT fk_time_entries_tickets FOREIGN KEY (ticket_id) REFERENCES tickets (id)"],
    ];
    // Plain-English description of what an orphan in each table actually is.
    $orphanLabel = [
        'email_attachments'   => 'email attachment(s) whose email has been deleted but the attachment rows were left behind',
        'ticket_notes'        => 'note(s) whose ticket no longer exists',
        'ticket_audit'        => 'audit record(s) whose ticket no longer exists',
        'ticket_time_entries' => 'time entry/entries whose ticket no longer exists',
    ];
    foreach ($ticketChildFks as [$tbl, $name, $col, $parent, $sql]) {
        if (!$tableExists($tbl) || !$tableExists($parent) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
        // Still missing? Orphaned rows are blocking it — report (never delete) and
        // attach 'fix' metadata so the UI can offer a one-click cleanup button.
        if (!$fkExists($tbl, $name)) {
            try {
                $n = (int)$conn->query("SELECT COUNT(*) FROM `$tbl` c LEFT JOIN `$parent` p ON p.id = c.`$col` WHERE p.id IS NULL")->fetchColumn();
                if ($n > 0) {
                    $what = $orphanLabel[$tbl] ?? "orphaned row(s) referencing a missing $parent";
                    $results[] = [
                        'table'   => $tbl,
                        'status'  => 'pending',
                        'details' => ["Found $n $what. The foreign key can't be added until these are removed. Click Fix to delete them and re-check."],
                        'fix'     => ['type' => 'delete_orphans', 'table' => $tbl, 'count' => $n],
                    ];
                }
            } catch (Exception $e) {}
        }
    }

    // Drop legacy change columns once each tablet's rows are fully backfilled
    foreach ([['changes', 'change_type', 'change_type_id'],
              ['changes', 'status',      'status_id'],
              ['changes', 'priority',    'priority_id'],
              ['changes', 'impact',      'impact_id'],
              ['change_templates', 'change_type', 'change_type_id'],
              ['change_templates', 'priority',    'priority_id'],
              ['change_templates', 'impact',      'impact_id']] as [$tbl, $oldCol, $newCol]) {
        if (!$tableExists($tbl) || !$colExists($tbl, $oldCol)) continue;
        $orphan = (int) $conn->query("SELECT COUNT(*) FROM `$tbl` WHERE `$newCol` IS NULL")->fetchColumn();
        if ($orphan === 0) {
            try {
                $conn->exec("ALTER TABLE `$tbl` DROP COLUMN `$oldCol`");
                $results[] = ['table' => $tbl, 'status' => 'updated', 'details' => ["Dropped legacy $oldCol column"]];
            } catch (Exception $e) {}
        } else {
            $results[] = ['table' => $tbl, 'status' => 'pending', 'details' => ["Cannot drop $oldCol yet — $orphan row(s) still missing $newCol"]];
        }
    }

    // ----------------------------------------------------------------------
    // Tasks: lookups for status / priority
    // ----------------------------------------------------------------------

    if ($tableExists('task_statuses')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM task_statuses")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO task_statuses (name, is_closed, colour, is_default, display_order) VALUES
                ('To Do',       0, '#6b7280', 1, 10),
                ('In Progress', 0, '#9333ea', 0, 20),
                ('Blocked',     0, '#f59e0b', 0, 30),
                ('Done',        1, '#16a34a', 0, 40),
                ('Cancelled',   1, '#bdbdbd', 0, 50)");
            $results[] = ['table' => 'task_statuses', 'status' => 'seeded', 'details' => ['Inserted 5 default task statuses']];
        }
    }

    if ($tableExists('task_priorities')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM task_priorities")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO task_priorities (name, colour, is_default, display_order) VALUES
                ('Low',    '#16a34a', 0, 10),
                ('Medium', '#2563eb', 1, 20),
                ('High',   '#f59e0b', 0, 30),
                ('Urgent', '#dc2626', 0, 40)");
            $results[] = ['table' => 'task_priorities', 'status' => 'seeded', 'details' => ['Inserted 4 default task priorities']];
        }
    }

    if ($tableExists('task_tags')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM task_tags")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO task_tags (name, colour, display_order) VALUES
                ('Security',    '#dc2626', 10),
                ('ISO',         '#2563eb', 20),
                ('Environment', '#16a34a', 30)");
            $results[] = ['table' => 'task_tags', 'status' => 'seeded', 'details' => ['Inserted 3 default task tags']];
        }
    }

    if ($tableExists('process_step_types')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM process_step_types")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO process_step_types (name, slug, shape, color, display_order, is_active, is_builtin) VALUES
                ('Process',  'process',  'rounded',  '#0078d4', 10, 1, 1),
                ('Decision', 'decision', 'diamond',  '#f59e0b', 20, 1, 1),
                ('Terminal', 'start',    'pill',     '#10b981', 30, 1, 1),
                ('Document', 'document', 'document', '#8764b8', 40, 1, 1)");
            $results[] = ['table' => 'process_step_types', 'status' => 'seeded', 'details' => ['Inserted 4 default step types']];
        }
    }

    foreach ([['tasks', 'status',   'status_id',   'task_statuses'],
              ['tasks', 'priority', 'priority_id', 'task_priorities']] as [$tbl, $oldCol, $newCol, $lkTbl]) {
        if (!$tableExists($tbl) || !$colExists($tbl, $oldCol) || !$colExists($tbl, $newCol) || !$tableExists($lkTbl)) continue;

        $conn->exec("INSERT IGNORE INTO `$lkTbl` (name, display_order)
                     SELECT DISTINCT t.`$oldCol`, 999
                     FROM `$tbl` t
                     LEFT JOIN `$lkTbl` l ON LOWER(l.name) = LOWER(t.`$oldCol`)
                     WHERE t.`$oldCol` IS NOT NULL AND t.`$oldCol` <> '' AND l.id IS NULL");

        $upd = $conn->exec("UPDATE `$tbl` t
                            JOIN `$lkTbl` l ON LOWER(l.name) = LOWER(t.`$oldCol`)
                            SET t.`$newCol` = l.id
                            WHERE t.`$newCol` IS NULL AND t.`$oldCol` IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => $tbl, 'status' => 'migrated', 'details' => ["Backfilled $newCol for $upd row(s)"]];
        }

        $conn->exec("UPDATE `$tbl` SET `$newCol` = (SELECT id FROM `$lkTbl` WHERE is_default = 1 LIMIT 1) WHERE `$newCol` IS NULL");
    }

    // FK + index for the asset location tree (self-referencing parent) and the
    // assets -> location link.
    foreach ([
        ['asset_locations', 'fk_asset_locations_parent', "ALTER TABLE asset_locations ADD CONSTRAINT fk_asset_locations_parent FOREIGN KEY (parent_id) REFERENCES asset_locations (id)"],
        ['assets', 'fk_assets_location', "ALTER TABLE assets ADD CONSTRAINT fk_assets_location FOREIGN KEY (location_id) REFERENCES asset_locations (id) ON DELETE SET NULL"],
        ['assets', 'fk_assets_supplier', "ALTER TABLE assets ADD CONSTRAINT fk_assets_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL"],
        ['asset_checkout_log', 'fk_acl_asset', "ALTER TABLE asset_checkout_log ADD CONSTRAINT fk_acl_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE"],
    ] as [$tbl, $name, $sql]) {
        if ($tableExists($tbl) && !$fkExists($tbl, $name)) {
            try { $conn->exec($sql); } catch (Exception $e) {}
        }
    }
    foreach ([
        ['asset_locations', 'idx_asset_locations_parent', 'parent_id'],
        ['assets', 'idx_assets_location', 'location_id'],
        ['assets', 'idx_assets_supplier', 'supplier_id'],
        ['asset_checkout_log', 'idx_acl_asset', 'asset_id'],
    ] as [$tbl, $name, $col]) {
        if ($tableExists($tbl) && !$idxExists($tbl, $name)) {
            try { $conn->exec("ALTER TABLE `$tbl` ADD KEY `$name` (`$col`)"); } catch (Exception $e) {}
        }
    }

    // Migrate legacy free-text assets.supplier -> normalised supplier_id (FK to
    // the shared suppliers registry), then drop the old column. Each distinct
    // free-text value becomes (or matches) a suppliers row flagged supplies_assets.
    if ($tableExists('assets') && $colExists('assets', 'supplier') && $colExists('assets', 'supplier_id') && $tableExists('suppliers')) {
        try {
            $names = $conn->query("SELECT DISTINCT supplier FROM assets WHERE supplier IS NOT NULL AND supplier <> ''")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($names as $nm) {
                $sel = $conn->prepare("SELECT id FROM suppliers WHERE legal_name = ? LIMIT 1");
                $sel->execute([$nm]);
                $sid = $sel->fetchColumn();
                if (!$sid) {
                    $ins = $conn->prepare("INSERT INTO suppliers (legal_name, supplies_assets, is_active) VALUES (?, 1, 1)");
                    $ins->execute([$nm]);
                    $sid = (int)$conn->lastInsertId();
                } else {
                    $conn->prepare("UPDATE suppliers SET supplies_assets = 1 WHERE id = ?")->execute([$sid]);
                }
                $conn->prepare("UPDATE assets SET supplier_id = ? WHERE supplier = ? AND supplier_id IS NULL")->execute([$sid, $nm]);
            }
            $conn->exec("ALTER TABLE assets DROP COLUMN supplier");
        } catch (Exception $e) { /* leave the legacy column in place if migration fails */ }
    }

    // FKs and indexes for tasks (full set matching freeitsm.sql — grown
    // installs were missing the parent/comments cascades, which orphaned
    // subtasks and comments on delete)
    foreach ([
        ['tasks', 'fk_tasks_status',   "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_status FOREIGN KEY (status_id) REFERENCES task_statuses (id)"],
        ['tasks', 'fk_tasks_priority', "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_priority FOREIGN KEY (priority_id) REFERENCES task_priorities (id)"],
        ['tasks', 'fk_tasks_analyst',  "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_analyst FOREIGN KEY (assigned_analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['tasks', 'fk_tasks_team',     "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_team FOREIGN KEY (assigned_team_id) REFERENCES teams (id) ON DELETE SET NULL"],
        ['tasks', 'fk_tasks_parent',   "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_parent FOREIGN KEY (parent_task_id) REFERENCES tasks (id) ON DELETE CASCADE"],
        ['tasks', 'fk_tasks_ticket',   "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE SET NULL"],
        ['tasks', 'fk_tasks_change',   "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_change FOREIGN KEY (change_id) REFERENCES changes (id) ON DELETE SET NULL"],
        ['tasks', 'fk_tasks_created_by', "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by_id) REFERENCES analysts (id)"],
        ['task_comments', 'fk_task_comments_task',    "ALTER TABLE task_comments ADD CONSTRAINT fk_task_comments_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE"],
        ['task_comments', 'fk_task_comments_analyst', "ALTER TABLE task_comments ADD CONSTRAINT fk_task_comments_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id)"],
        ['task_tag_map', 'fk_task_tag_map_task', "ALTER TABLE task_tag_map ADD CONSTRAINT fk_task_tag_map_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE"],
        ['task_tag_map', 'fk_task_tag_map_tag',  "ALTER TABLE task_tag_map ADD CONSTRAINT fk_task_tag_map_tag FOREIGN KEY (tag_id) REFERENCES task_tags (id) ON DELETE CASCADE"],
    ] as [$tbl, $name, $sql]) {
        if ($tableExists($tbl) && !$fkExists($tbl, $name)) {
            try { $conn->exec($sql); } catch (Exception $e) {}
        }
    }
    foreach ([
        ['tasks', 'ix_tasks_status_id',   'status_id'],
        ['tasks', 'ix_tasks_priority_id', 'priority_id'],
    ] as [$tbl, $name, $col]) {
        if ($tableExists($tbl) && !$idxExists($tbl, $name)) {
            try { $conn->exec("ALTER TABLE `$tbl` ADD KEY `$name` (`$col`)"); } catch (Exception $e) {}
        }
    }

    // Drop legacy task columns
    foreach ([['tasks', 'status',   'status_id'],
              ['tasks', 'priority', 'priority_id']] as [$tbl, $oldCol, $newCol]) {
        if (!$tableExists($tbl) || !$colExists($tbl, $oldCol)) continue;
        $orphan = (int) $conn->query("SELECT COUNT(*) FROM `$tbl` WHERE `$newCol` IS NULL")->fetchColumn();
        if ($orphan === 0) {
            try {
                $conn->exec("ALTER TABLE `$tbl` DROP COLUMN `$oldCol`");
                $results[] = ['table' => $tbl, 'status' => 'updated', 'details' => ["Dropped legacy $oldCol column"]];
            } catch (Exception $e) {}
        } else {
            $results[] = ['table' => $tbl, 'status' => 'pending', 'details' => ["Cannot drop $oldCol yet — $orphan row(s) still missing $newCol"]];
        }
    }

    // ----------------------------------------------------------------------
    // Service Status: incident-status and impact-level lookups
    // ----------------------------------------------------------------------

    if ($tableExists('service_incident_statuses')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM service_incident_statuses")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO service_incident_statuses (name, is_resolved, colour, is_default, display_order) VALUES
                ('Investigating', 0, '#dc2626', 1, 10),
                ('Identified',    0, '#f59e0b', 0, 20),
                ('Monitoring',    0, '#0891b2', 0, 30),
                ('3rd Party',     0, '#9333ea', 0, 40),
                ('Resolved',      1, '#16a34a', 0, 50)");
            $results[] = ['table' => 'service_incident_statuses', 'status' => 'seeded', 'details' => ['Inserted 5 default incident statuses']];
        }
    }

    if ($tableExists('service_impact_levels')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM service_impact_levels")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO service_impact_levels (name, colour, is_default, severity_order, display_order) VALUES
                ('Major Outage',   '#dc2626', 0, 1, 10),
                ('Partial Outage', '#f59e0b', 0, 2, 20),
                ('Degraded',       '#eab308', 0, 3, 30),
                ('Maintenance',    '#0891b2', 0, 4, 40),
                ('Operational',    '#16a34a', 1, 5, 50),
                ('No Disruption',  '#9ca3af', 0, 6, 60)");
            $results[] = ['table' => 'service_impact_levels', 'status' => 'seeded', 'details' => ['Inserted 6 default impact levels']];
        }
    }

    // Backfill status_incidents.status_id from legacy status string
    if ($tableExists('status_incidents') && $colExists('status_incidents', 'status') && $colExists('status_incidents', 'status_id') && $tableExists('service_incident_statuses')) {
        $conn->exec("INSERT IGNORE INTO service_incident_statuses (name, display_order)
                     SELECT DISTINCT i.status, 999
                     FROM status_incidents i
                     LEFT JOIN service_incident_statuses s ON LOWER(s.name) = LOWER(i.status)
                     WHERE i.status IS NOT NULL AND i.status <> '' AND s.id IS NULL");

        $upd = $conn->exec("UPDATE status_incidents i
                            JOIN service_incident_statuses s ON LOWER(s.name) = LOWER(i.status)
                            SET i.status_id = s.id
                            WHERE i.status_id IS NULL AND i.status IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'status_incidents', 'status' => 'migrated', 'details' => ["Backfilled status_id for $upd incident(s)"]];
        }
        $conn->exec("UPDATE status_incidents SET status_id = (SELECT id FROM service_incident_statuses WHERE is_default = 1 LIMIT 1) WHERE status_id IS NULL");
    }

    // Backfill status_incident_services.impact_level_id from legacy impact_level string
    if ($tableExists('status_incident_services') && $colExists('status_incident_services', 'impact_level') && $colExists('status_incident_services', 'impact_level_id') && $tableExists('service_impact_levels')) {
        $conn->exec("INSERT IGNORE INTO service_impact_levels (name, severity_order, display_order)
                     SELECT DISTINCT sis.impact_level, 99, 999
                     FROM status_incident_services sis
                     LEFT JOIN service_impact_levels l ON LOWER(l.name) = LOWER(sis.impact_level)
                     WHERE sis.impact_level IS NOT NULL AND sis.impact_level <> '' AND l.id IS NULL");

        $upd = $conn->exec("UPDATE status_incident_services sis
                            JOIN service_impact_levels l ON LOWER(l.name) = LOWER(sis.impact_level)
                            SET sis.impact_level_id = l.id
                            WHERE sis.impact_level_id IS NULL AND sis.impact_level IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'status_incident_services', 'status' => 'migrated', 'details' => ["Backfilled impact_level_id for $upd row(s)"]];
        }
        $conn->exec("UPDATE status_incident_services SET impact_level_id = (SELECT id FROM service_impact_levels WHERE is_default = 1 LIMIT 1) WHERE impact_level_id IS NULL");
    }

    // FKs and indexes
    foreach ([
        ['status_incidents',          'fk_status_incidents_status', "ALTER TABLE status_incidents ADD CONSTRAINT fk_status_incidents_status FOREIGN KEY (status_id) REFERENCES service_incident_statuses (id)"],
        ['status_incident_services',  'fk_sis_impact_level',        "ALTER TABLE status_incident_services ADD CONSTRAINT fk_sis_impact_level FOREIGN KEY (impact_level_id) REFERENCES service_impact_levels (id)"],
    ] as [$tbl, $name, $sql]) {
        if ($tableExists($tbl) && !$fkExists($tbl, $name)) {
            try { $conn->exec($sql); } catch (Exception $e) {}
        }
    }
    foreach ([
        ['status_incidents',          'ix_status_incidents_status_id', 'status_id'],
        ['status_incident_services',  'ix_sis_impact_level_id',         'impact_level_id'],
    ] as [$tbl, $name, $col]) {
        if ($tableExists($tbl) && !$idxExists($tbl, $name)) {
            try { $conn->exec("ALTER TABLE `$tbl` ADD KEY `$name` (`$col`)"); } catch (Exception $e) {}
        }
    }

    // Drop legacy columns once everything's backfilled
    foreach ([
        ['status_incidents',         'status',       'status_id'],
        ['status_incident_services', 'impact_level', 'impact_level_id'],
    ] as [$tbl, $oldCol, $newCol]) {
        if (!$tableExists($tbl) || !$colExists($tbl, $oldCol)) continue;
        $orphan = (int) $conn->query("SELECT COUNT(*) FROM `$tbl` WHERE `$newCol` IS NULL")->fetchColumn();
        if ($orphan === 0) {
            try {
                $conn->exec("ALTER TABLE `$tbl` DROP COLUMN `$oldCol`");
                $results[] = ['table' => $tbl, 'status' => 'updated', 'details' => ["Dropped legacy $oldCol column"]];
            } catch (Exception $e) {}
        } else {
            $results[] = ['table' => $tbl, 'status' => 'pending', 'details' => ["Cannot drop $oldCol yet — $orphan row(s) still missing $newCol"]];
        }
    }

    // Seed / top-up the curated CMDB icon library so the class form has a
    // picker source. Used to fire only when the table was empty; now uses
    // INSERT IGNORE per row so existing installs pick up library expansions
    // (e.g. the Network Mapper per-node icon override set) on next verify.
    // The uq_cmdb_icons_key unique index (asserted further down) makes the
    // IGNORE safe — duplicates are skipped, existing rows untouched.
    if ($tableExists('cmdb_icons')) {
        $icons = [
            // Original 20 (chunk A foundation)
            ['server',         'Server',            10],
            ['database',       'Database',          20],
            ['application',    'Application',       30],
            ['service',        'Service',           40],
            ['website',        'Website',           50],
            ['api',            'API',               60],
            ['vm',             'Virtual Machine',   70],
            ['container',      'Container',         80],
            ['cloud',          'Cloud Resource',    90],
            ['network',        'Network Device',   100],
            ['firewall',       'Firewall',         110],
            ['router',         'Router',           120],
            ['switch',         'Switch',           130],
            ['storage',        'Storage',          140],
            ['workstation',    'Workstation',      150],
            ['printer',        'Printer',          160],
            ['person',         'Person',           170],
            ['team',           'Team',             180],
            ['document',       'Document',         190],
            ['box',            'Generic',          200],
            // Extended set (Network Mapper per-node icon override). Display
            // orders interleaved so related variants group together.
            ['server-rack',    'Server (rack)',     11],
            ['server-blade',   'Server (blade)',    12],
            ['server-tower',   'Server (tower)',    13],
            ['mainframe',      'Mainframe',         14],
            ['function',       'Function',          71],
            ['database-cluster', 'Database cluster', 21],
            ['database-cache', 'Database (cache)',  22],
            ['storage-san',    'SAN',              141],
            ['storage-tape',   'Tape backup',      142],
            ['backup',         'Backup',           143],
            ['load-balancer',  'Load balancer',    111],
            ['proxy',          'Proxy',            112],
            ['vpn',            'VPN',              113],
            ['gateway',        'Gateway',          114],
            ['wireless-ap',    'Wireless AP',      131],
            ['modem',          'Modem',            132],
            ['cdn',            'CDN',              115],
            ['dns',            'DNS',              116],
            ['shield',         'Shield',           117],
            ['lock',           'Lock',             118],
            ['key',            'Key',              119],
            ['ids',            'IDS / IPS',        121],
            ['siem',           'SIEM',             122],
            ['cloud-private',  'Private cloud',     91],
            ['cloud-public',   'Public cloud',      92],
            ['cloud-hybrid',   'Hybrid cloud',      93],
            ['region',         'Region',            94],
            ['container-pod',  'Pod',               81],
            ['kubernetes',     'Kubernetes',        82],
            ['registry',       'Registry',          83],
            ['microservice',   'Microservice',      31],
            ['queue',          'Message queue',     32],
            ['cache',          'Cache',             33],
            ['dashboard',      'Dashboard',         34],
            ['laptop',         'Laptop',           151],
            ['mobile',         'Mobile',           152],
            ['tablet',         'Tablet',           153],
            ['iot',            'IoT device',       154],
            ['monitor',        'Monitor / gauge',  161],
            ['alert',          'Alert',            162],
            ['log',            'Log',              163],
            ['org',            'Org',              181],
            ['folder',         'Folder',           191],
            ['globe',          'Globe',            192],
            ['mail',           'Mail',             193],
            ['calendar',       'Calendar',         194],
        ];
        $before = (int) $conn->query("SELECT COUNT(*) FROM cmdb_icons")->fetchColumn();
        $ins = $conn->prepare("INSERT IGNORE INTO cmdb_icons (icon_key, label, display_order) VALUES (?, ?, ?)");
        foreach ($icons as $row) { $ins->execute($row); }
        $after = (int) $conn->query("SELECT COUNT(*) FROM cmdb_icons")->fetchColumn();
        $added = $after - $before;
        if ($before === 0) {
            $results[] = ['table' => 'cmdb_icons', 'status' => 'seeded', 'details' => ["Inserted $after default CMDB icons"]];
        } elseif ($added > 0) {
            $results[] = ['table' => 'cmdb_icons', 'status' => 'updated', 'details' => ["Topped up icon library — added $added new icons"]];
        }
    }

    // Seed default CMDB relationship types so the module has something usable on first run
    if ($tableExists('cmdb_relationship_types')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM cmdb_relationship_types")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO cmdb_relationship_types (verb, inverse_verb, description, display_order) VALUES
                ('depends on',  'is depended on by', 'A needs B in order to function',           10),
                ('connects to', 'is connected from', 'A has a network or data link to B',       20),
                ('managed by',  'manages',           'A is administered by B',                  30)");
            $results[] = ['table' => 'cmdb_relationship_types', 'status' => 'seeded', 'details' => ['Inserted 3 default CMDB relationship types']];
        }
    }

    // Ensure unique indexes exist on LMS tables (db_verify only creates columns, not indexes)
    $uniqueIndexes = [
        ['lms_cmi_data', 'uq_lcd_progress_element', '(`progress_id`, `element`)'],
        ['lms_progress', 'uq_lp_analyst_course', '(`analyst_id`, `course_id`)'],
        ['lms_learning_group_members', 'uq_lgm_group_analyst', '(`group_id`, `analyst_id`)'],
        ['lms_course_assignments', 'uq_lca_course_group', '(`course_id`, `group_id`)'],
        ['intune_devices', 'uq_intune_devices_intune_id', '(`intune_id`)'],
        ['rfp_departments', 'uq_rfp_departments_name', '(`name`)'],
        ['rfp_consolidated_sources', 'uq_rfp_consolidated_sources', '(`consolidated_id`, `extracted_id`)'],
        ['rfp_invited_suppliers', 'uq_rfp_invited_suppliers', '(`rfp_id`, `supplier_id`)'],
        ['rfp_scores', 'uq_rfp_scores', '(`rfp_id`, `supplier_id`, `analyst_id`, `consolidated_id`)'],
        ['user_preferences', 'uq_user_pref', '(`analyst_id`, `preference_key`)'],
        ['cmdb_icons', 'uq_cmdb_icons_key', '(`icon_key`)'],
        ['cmdb_classes', 'uq_cmdb_classes_key', '(`class_key`)'],
        ['cmdb_class_properties', 'uq_cmdb_class_property_key', '(`class_id`, `property_key`)'],
        ['cmdb_object_properties', 'uq_cmdb_op_obj_prop', '(`object_id`, `property_id`)'],
        ['cmdb_relationship_types', 'uq_cmdb_rel_type_verb', '(`verb`)'],
        ['cmdb_object_relationships', 'uq_cmdb_or_triple', '(`from_object_id`, `to_object_id`, `relationship_type_id`)'],
        ['ticket_cmdb_objects', 'uq_ticket_cmdb_obj', '(`ticket_id`, `cmdb_object_id`)'],
        ['process_step_types', 'uq_process_step_types_slug', '(`slug`)'],
        ['change_field_layout', 'uq_cfl_field_key', '(`field_key`)'],
        ['analyst_sso_identities', 'uq_sso_provider_subject', '(`provider_id`, `subject`)'],
        ['analyst_sso_identities', 'uq_sso_provider_analyst', '(`provider_id`, `analyst_id`)'],
        ['user_sso_identities', 'uq_user_sso_provider_subject', '(`provider_id`, `subject`)'],
        ['user_sso_identities', 'uq_user_sso_provider_user', '(`provider_id`, `user_id`)'],
        ['freemail_domains', 'uq_freemail_domains_domain', '(`domain`)'],
        ['tenant_channel_senders', 'uq_tenant_channel_sender_identifier', '(`identifier`)'],
        ['problem_tickets', 'uq_problem_ticket', '(`problem_id`, `ticket_id`)'],
        ['change_tickets',  'uq_change_ticket',  '(`change_id`, `ticket_id`)'],
        ['ticket_links',    'uq_ticket_link',    '(`source_ticket_id`, `target_ticket_id`, `relation_type`)'],
        ['api_keys', 'uq_api_keys_hash', '(`key_hash`)'],
        ['api_key_rate_limits', 'uq_api_key_window', '(`api_key_id`, `window_start`)'],
        ['contract_term_values', 'uq_ctv_contract_tab', '(`contract_id`, `term_tab_id`)'],
        ['morningChecks_Results', 'uq_check_date', '(`CheckID`, `CheckDate`)'],
    ];

    foreach ($uniqueIndexes as [$tbl, $idxName, $cols]) {
        try {
            // Check if table exists
            $tblCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
            $tblCheck->execute([DB_NAME, $tbl]);
            if ((int)$tblCheck->fetch(PDO::FETCH_ASSOC)['cnt'] === 0) continue;

            // Check if index exists
            $idxCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?");
            $idxCheck->execute([DB_NAME, $tbl, $idxName]);
            if ((int)$idxCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) continue;

            // For lms_cmi_data: clean up duplicates before adding unique key
            if ($tbl === 'lms_cmi_data') {
                $conn->exec("DELETE d1 FROM lms_cmi_data d1
                             INNER JOIN lms_cmi_data d2
                             ON d1.progress_id = d2.progress_id AND d1.element = d2.element AND d1.id < d2.id");
            }
            // For process_step_types: drop duplicate slugs (keep lowest id) before the unique key
            if ($tbl === 'process_step_types') {
                $conn->exec("DELETE t1 FROM process_step_types t1
                             INNER JOIN process_step_types t2
                             ON t1.slug = t2.slug AND t1.id > t2.id");
            }
            // For contract_term_values: the old select-then-insert upsert could
            // double-insert under concurrency — keep the newest row per
            // (contract, tab) before adding the unique key
            if ($tbl === 'contract_term_values') {
                $conn->exec("DELETE t1 FROM contract_term_values t1
                             INNER JOIN contract_term_values t2
                             ON t1.contract_id = t2.contract_id AND t1.term_tab_id = t2.term_tab_id AND t1.id < t2.id");
            }
            // For morningChecks_Results: same select-then-insert upsert shape —
            // keep the newest row per (check, date) before adding the unique key
            if ($tbl === 'morningChecks_Results') {
                $conn->exec("DELETE t1 FROM morningChecks_Results t1
                             INNER JOIN morningChecks_Results t2
                             ON t1.CheckID = t2.CheckID AND t1.CheckDate = t2.CheckDate AND t1.ResultID < t2.ResultID");
            }

            $conn->exec("ALTER TABLE `$tbl` ADD UNIQUE KEY `$idxName` $cols");
            $results[] = ['table' => $tbl, 'status' => 'updated', 'details' => ["Added unique index $idxName"]];
        } catch (Exception $e) {
            // Index may already exist under a different name — ignore
        }
    }

    // One-off: bump user_preferences.preference_value from VARCHAR(500) to TEXT.
    // Larger config blobs (e.g. the asset-management table view's column /
    // sort prefs in #383) can otherwise overflow the original 500-char cap.
    // Idempotent — only fires if the column is still varchar.
    try {
        $col = $conn->prepare(
            "SELECT DATA_TYPE FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'user_preferences' AND column_name = 'preference_value'"
        );
        $col->execute([DB_NAME]);
        $row = $col->fetch(PDO::FETCH_ASSOC);
        if ($row && strtolower($row['DATA_TYPE']) === 'varchar') {
            $conn->exec("ALTER TABLE `user_preferences` MODIFY `preference_value` TEXT NULL");
            $results[] = [
                'table' => 'user_preferences',
                'status' => 'updated',
                'details' => ['preference_value: VARCHAR(500) → TEXT (allow larger config blobs)']
            ];
        }
    } catch (Exception $e) {
        // Non-fatal — fall through with verification result
    }

    echo json_encode([
        'success' => true,
        'results' => $results,
        'total_tables' => count($schema)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
