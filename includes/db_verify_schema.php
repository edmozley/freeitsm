<?php
/**
 * The expected COLUMNS of every table, table => [column => definition].
 *
 * Database Verification creates any missing table from this and ALTERs in any
 * missing column. It is one of the schema's two hand-maintained sources of
 * truth — `database/freeitsm.sql` (used by a FRESH install) is the other, and
 * the two must agree.
 *
 * ⚠️ They can silently disagree, and that has shipped a real bug: a column here
 * but not in freeitsm.sql leaves a NEW install missing it until someone runs
 * Verification, while a column in freeitsm.sql but not here means an EXISTING
 * install never gains it. dbVerifyColumnSelfCheck() (includes/db_verify_column_parse.php)
 * compares the two on every Verification run and raises a red card on drift.
 *
 * Lives in its own file — rather than inline in db_verify.php — precisely so the
 * guard, and any future tooling, can `require` it. Same reasoning as
 * includes/db_verify_indexes.php.
 *
 * NOTE: this array carries COLUMNS (+ PRIMARY KEY) only. UNIQUE keys, other
 * indexes and FOREIGN KEYs are NOT built from it — indexes come from the
 * generated includes/db_verify_indexes.php, and FKs from the explicit FK groups
 * in db_verify.php.
 */

return [

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
        // --- LDAP / Active Directory (protocol = 'ldap') ---
        // Mutually exclusive with the OIDC columns above. ldap_bind_password is
        // the service account's password, encrypted at rest via encryptValue().
        // ldap_attr_guid names the immutable id attribute (objectGUID on AD,
        // entryUUID on OpenLDAP) used as the stable `subject` link — a DN is not
        // safe for that, it changes when a user is renamed or moved between OUs.
        'ldap_host'              => 'VARCHAR(255) NULL',
        'ldap_port'              => 'INT NULL',
        'ldap_encryption'        => 'VARCHAR(10) NULL',
        'ldap_bind_dn'           => 'VARCHAR(255) NULL',
        'ldap_bind_password'     => 'VARCHAR(500) NULL',
        'ldap_base_dn'           => 'VARCHAR(255) NULL',
        'ldap_user_filter'       => 'VARCHAR(500) NULL',
        'ldap_attr_username'     => 'VARCHAR(64) NULL',
        'ldap_attr_email'        => 'VARCHAR(64) NULL',
        'ldap_attr_name'         => 'VARCHAR(64) NULL',
        'ldap_attr_guid'         => 'VARCHAR(64) NULL',
        // Group gating (issue #47). ldap_analyst_group / ldap_user_group name the
        // directory groups that grant access; both blank = gate off (anyone the
        // directory authenticates becomes an analyst). ldap_group_filter finds the
        // groups a user is in — %s is their DN.
        'ldap_group_base_dn'     => 'VARCHAR(255) NULL',
        'ldap_group_filter'      => 'VARCHAR(500) NULL',
        'ldap_analyst_group'     => 'VARCHAR(255) NULL',
        'ldap_user_group'        => 'VARCHAR(255) NULL',
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

    // RBAC Layer 2 — per-module settings permissions (see docs/design/rbac.md).
    // Deny by default; is_admin bypasses. Capability keys validated against the
    // code registry in includes/rbac.php. Unique keys + FKs added below.
    'rbac_roles' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(500) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_by_id'     => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rbac_role_capabilities' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'role_id'           => 'INT NOT NULL',
        'capability_key'    => 'VARCHAR(100) NOT NULL',
    ],

    'rbac_analyst_roles' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'role_id'           => 'INT NOT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'rbac_team_roles' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'team_id'           => 'INT NOT NULL',
        'role_id'           => 'INT NOT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
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
        // NULL because a directory (LDAP) user may genuinely have no mailbox —
        // warehouse and shop-floor staff are never given one. The UNIQUE index
        // stays: MySQL permits many NULLs in a unique index, so any number of
        // mailbox-less people coexist while real addresses stay unique.
        //
        // ⚠️ ABSENT MUST BE NULL, NEVER ''. The analyst side gets away with ''
        // (#872) only because analysts.email is NOT unique; here the second
        // empty string collides. See usersIdentityMatch() in includes/users.php.
        'email'           => 'VARCHAR(255) NULL',
        // What a directory user types to sign in when they have no email.
        // NULL for every local/registered account, which is why it is nullable
        // and why UNIQUE tolerates it repeatedly.
        'username'        => 'VARCHAR(50) NULL',
        'display_name'    => 'VARCHAR(255) NULL',
        'preferred_name'  => 'VARCHAR(100) NULL',
        'password_hash'   => 'VARCHAR(255) NULL',
        'totp_secret'     => 'VARCHAR(500) NULL',
        'totp_enabled'    => 'TINYINT(1) NOT NULL DEFAULT 0',
        'auth_provider_id' => 'INT NULL',
        // Portal user's colour palette ('default' | 'dark'); NULL = install
        // default. Analysts use user_preferences, which is keyed by analyst_id
        // and so unavailable to portal users.
        'theme_preference' => 'VARCHAR(32) NULL',
        // The company this requester belongs to. NULL = unknown → their tickets
        // land in triage, the same meaning NULL carries on `tickets`. Scoped-data
        // shape, not config: NULL is "not yet known", never "shared".
        'tenant_id'       => 'INT NULL',
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

    'user_verification_tokens' => [
        'id'            => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
        'email'         => 'VARCHAR(255) NOT NULL',
        'password_hash' => 'VARCHAR(255) NOT NULL',
        'display_name'  => 'VARCHAR(255) NULL',
        'token_hash'    => 'CHAR(64) NOT NULL',
        'expires_at'    => 'DATETIME NOT NULL',
        'created_at'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
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

    // Embed config for one website chat widget. Drives a messaging_channels row
    // (channel_type='webchat', provider='freeitsm'); company routing + active flag
    // live there. widget_key is public (ships in the site's <script>) — abuse is
    // contained by allowed_origins + rate limiting, not by hiding this.
    'webchat_widgets' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'channel_id'       => 'INT NOT NULL',
        'widget_key'       => 'VARCHAR(64) NOT NULL',
        'allowed_origins'  => 'LONGTEXT NULL',
        'greeting'         => 'VARCHAR(500) NULL',
        'accent_colour'    => 'VARCHAR(20) NULL',
        'launcher_text'    => 'VARCHAR(60) NULL',
        'offline_message'  => 'VARCHAR(500) NULL',
        'require_email'    => 'TINYINT(1) NOT NULL DEFAULT 1',
        // Availability (business-hours calendar), offline email delivery, and AI answers.
        'business_calendar_id' => 'INT NULL',
        'email_when_away'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'ai_enabled'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'ai_mode'          => "VARCHAR(10) NOT NULL DEFAULT 'assist'",
        'ai_offer_agent'   => 'TINYINT(1) NOT NULL DEFAULT 1',
        'ai_offer_email'   => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Pre-ticket chat transcript (AI 'deflect' mode) — see freeitsm.sql. sender is
    // 'visitor'|'ai'|'agent'|'system'. Source for the ticket opening message + .txt log.
    'webchat_messages' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'conversation_id'  => 'INT NOT NULL',
        'sender'           => "VARCHAR(10) NOT NULL DEFAULT 'visitor'",
        'body'             => 'LONGTEXT NULL',
        'source_email_id'  => 'INT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // One website chat conversation. token is the visitor's browser-held capability for
    // this chat (needed on every send/poll); ticket_id is set lazily on the first
    // message so a conversation maps to exactly one ticket. visitor_ip is for rate limits.
    'webchat_conversations' => [
        'id'                     => 'INT NOT NULL AUTO_INCREMENT',
        'channel_id'             => 'INT NOT NULL',
        'token'                  => 'VARCHAR(64) NOT NULL',
        'ticket_id'              => 'INT NULL',
        'visitor_name'           => 'VARCHAR(150) NULL',
        'visitor_email'          => 'VARCHAR(255) NULL',
        'visitor_ip'             => 'VARCHAR(45) NULL',
        'created_datetime'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'last_activity_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
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
        // NULL = the sender has no email address at all — a portal requester who
        // signs in through a directory and was never given a mailbox. Only ever
        // NULL for portal-raised messages; anything that arrived BY email has a
        // sender by definition. `from_name` identifies these people instead.
        'from_address'            => 'VARCHAR(255) NULL',
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
        // Which message the recording came with. NULL = the ticket's opening
        // message, which is what every recording was before replies could carry
        // one — so existing rows are already correct and need no backfill.
        'email_id'            => 'INT NULL',
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
        // Multi-tenancy: the company this asset belongs to (NULL = Default).
        'tenant_id'         => 'INT NULL',
    ],

    'asset_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        // Multi-tenancy config: NULL = global default type, set = a company's own.
        'tenant_id'         => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'asset_status_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        // Multi-tenancy config: NULL = global default status, set = a company's own.
        'tenant_id'         => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Arbitrary-depth physical location tree (adjacency list). Self-ref FK +
    // parent index added in the post-schema section below.
    'asset_locations' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'parent_id'         => 'INT NULL',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        // Multi-tenancy SCOPED DATA (not a config list, unlike the two lists
        // above): a company's sites are entirely its own, so NULL = the Default
        // company's, set = that company's. Read via activeTenantFilter().
        'tenant_id'         => 'INT NULL',
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
        // 'scorm' (uploaded package) or 'native' (authored here). Defaulting to
        // 'scorm' is what silently classifies every pre-existing course correctly.
        'content_type'          => "VARCHAR(10) NOT NULL DEFAULT 'scorm'",
        'pass_mark'             => 'INT NULL',
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

    // Native course content. Lesson bodies are TinyMCE HTML (as knowledge
    // articles are); answers carry the key, which never leaves the server.
    'lms_lessons' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'course_id'             => 'INT NOT NULL',
        'title'                 => 'VARCHAR(255) NOT NULL',
        'body'                  => 'LONGTEXT NULL',
        'display_order'         => 'INT NOT NULL DEFAULT 0',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'lms_questions' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'lesson_id'             => 'INT NOT NULL',
        'question_text'         => 'TEXT NOT NULL',
        'question_type'         => "VARCHAR(20) NOT NULL DEFAULT 'single'",
        'explanation'           => 'TEXT NULL',
        'display_order'         => 'INT NOT NULL DEFAULT 0',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'lms_answers' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'question_id'           => 'INT NOT NULL',
        'answer_text'           => 'VARCHAR(500) NOT NULL',
        'is_correct'            => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'         => 'INT NOT NULL DEFAULT 0',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
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

    'workflow_scheduled_emissions' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'trigger_event'    => 'VARCHAR(100) NOT NULL',
        'entity_key'       => 'VARCHAR(120) NOT NULL',
        'fingerprint'      => 'VARCHAR(64) NOT NULL',
        'emitted_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'webhook_message_formats' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'format_key'       => 'VARCHAR(40) NOT NULL',
        'label'            => 'VARCHAR(100) NOT NULL',
        'body_template'    => 'TEXT NOT NULL',
        'url_pattern'      => 'VARCHAR(255) NULL',
        'markdown_hint'    => 'VARCHAR(255) NULL',
        'is_builtin'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_active'        => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'    => 'INT NOT NULL DEFAULT 0',
        'created_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
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
        // Which company OWNS the article. ⚠️ NULL = SHARED WITH EVERY COMPANY here,
        // NOT "belongs to Default" as it does for tickets/assets — Knowledge has its
        // own filter helper for exactly this reason (see includes/tenancy.php).
        // NULL is also the zero-migration default: existing articles stay shared,
        // which is precisely today's behaviour.
        'tenant_id'             => 'INT NULL',
        // WHO may read it: 'internal' | 'customer' | 'public'. Defaults to 'internal'
        // so running Database Verify can NEVER start disclosing existing articles to
        // anonymous web chat visitors — authors opt in per article.
        'audience'              => "VARCHAR(20) NOT NULL DEFAULT 'internal'",
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
        // Multi-tenancy: the company an ingest key belongs to (NULL = Default).
        'tenant_id'  => 'INT NULL',
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
        // Offer this form in the self-service portal's request catalogue.
        // Separate from is_active (the analyst-side on/off) and defaulting to 0,
        // so upgrading never exposes an existing internal form to customers.
        'is_portal_visible' => 'TINYINT(1) NOT NULL DEFAULT 0',
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
        // The ANALYST submitter. Readers LEFT JOIN this to `analysts`, so a
        // requester's id must never land here — separate id spaces.
        'submitted_by'      => 'INT NULL',
        // The REQUESTER submitter (portal request catalogue). Exactly one of
        // the two is set.
        'submitted_by_user_id' => 'INT NULL',
        // The ticket an analyst raised from this submission; NULL = not yet
        // actioned, which is what the queue filters on.
        'ticket_id'         => 'INT NULL',
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
        // Multi-tenancy SCOPED DATA: the company this CI belongs to, NULL =
        // Default's. Only cmdb_objects carries it — classes/properties/relationship
        // types are install-wide config, and the child tables inherit from here.
        'tenant_id'         => 'INT NULL',
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
