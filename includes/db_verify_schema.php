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
        // Profile details an analyst can put in their signature (#80). Deliberately
        // on the analyst rather than borrowed from a users row: an analyst may have no
        // person record at all, and a signature that silently loses its phone number
        // because a link is missing is worse than one that never had it.
        'job_title'              => 'VARCHAR(100) NULL',
        'department'             => 'VARCHAR(100) NULL',
        'phone'                  => 'VARCHAR(50) NULL',
        'mobile'                 => 'VARCHAR(50) NULL',
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
        // Make this analyst change their password before they can do anything else.
        // Set on the seeded `admin` account, because admin/freeitsm was permanent:
        // there was no column like this and nothing anywhere forced, warned about or
        // even nagged on the change. Cleared the moment they set a new one.
        'must_change_password'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        // MFA code-step throttling (S6). Deliberately SEPARATE from
        // failed_login_count / locked_until above, and that separation is the fix:
        // a successful password step resets those two, so an attacker holding a
        // valid password never tripped them, and the MFA count had nowhere durable
        // to live. Only entering a correct code clears these.
        // See includes/mfa_throttle.php.
        'mfa_failed_count'       => 'INT NOT NULL DEFAULT 0',
        'mfa_locked_until'       => 'DATETIME NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        // --- directory sync (slice 2) ---
        // Sign-in asks about ONE person who is standing there; sync enumerates
        // everybody, so they exist before anyone signs in.
        'sync_enabled'           => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sync_base_dn'           => 'VARCHAR(255) NULL',
        // Ticked branches, one DN per line, and the carve-outs within them.
        // TEXT rather than a child table: this is a setting belonging to one
        // provider, never queried across providers and never joined to.
        // ⚠️ Both NULL is the ONLY state an upgraded install can be in, so it
        // must keep meaning exactly what sync_base_dn alone used to mean.
        'sync_ou_includes'       => 'TEXT NULL',
        'sync_ou_excludes'       => 'TEXT NULL',
        'sync_filter'            => 'VARCHAR(500) NULL',
        // adopt | flag — see the note in freeitsm.sql: adopting stops their
        // local portal password working, which is why it is a choice.
        'sync_on_conflict'       => 'VARCHAR(20) NOT NULL DEFAULT \'adopt\'',
        'sync_deactivate_after'  => 'INT NOT NULL DEFAULT 3',
        // The sanity brake. A run finding this many percent fewer people than
        // the last good one stops and changes nothing.
        'sync_brake_percent'     => 'INT NOT NULL DEFAULT 20',
        'ldap_attr_job_title'    => 'VARCHAR(64) NULL',
        'ldap_attr_department'   => 'VARCHAR(64) NULL',
        'ldap_attr_office'       => 'VARCHAR(64) NULL',
        'ldap_attr_phone'        => 'VARCHAR(64) NULL',
        'ldap_attr_mobile'       => 'VARCHAR(64) NULL',
        'ldap_attr_employee_id'  => 'VARCHAR(64) NULL',
        'ldap_attr_manager'      => 'VARCHAR(64) NULL',
        'sync_last_run_datetime' => 'DATETIME NULL',
        'sync_last_count'        => 'INT NULL',
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'analyst_teams' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'team_id'           => 'INT NOT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'department_teams' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'department_id'     => 'INT NOT NULL',
        'team_id'           => 'INT NOT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    // Per-team module grants (issue #30) — the team twin of analyst_modules,
    // mirroring team_tenant_access. A row = "this team grants this module".
    'team_modules' => [
        'id'          => 'INT NOT NULL AUTO_INCREMENT',
        'team_id'     => 'INT NOT NULL',
        'module_key'  => 'VARCHAR(50) NOT NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'rbac_role_capabilities' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'role_id'           => 'INT NOT NULL',
        'capability_key'    => 'VARCHAR(100) NOT NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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

    // Watchtower settings. analyst_id 0 = the installation's setting; real ids are
    // reserved for per-person overrides later. is_customised distinguishes "not
    // configured" from "configured to nothing".
    'watchtower_items' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'    => 'INT NOT NULL DEFAULT 0',
        'item_key'      => 'VARCHAR(60) NOT NULL',
        'is_visible'    => 'TINYINT(1) NOT NULL DEFAULT 1',
        'is_customised' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ],
    // Which statuses/priorities feed one Watchtower item. Rows rather than a flag
    // on the status, so an item can hold a set and each member can carry its own
    // severity later. entity_type is polymorphic, so no foreign key is possible.
    'watchtower_item_members' => [
        'id'          => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'  => 'INT NOT NULL DEFAULT 0',
        'item_key'    => 'VARCHAR(60) NOT NULL',
        'entity_type' => 'VARCHAR(30) NOT NULL',
        'entity_id'   => 'INT NOT NULL',
        'severity'    => 'VARCHAR(10) NULL',
    ],

    'ticket_prefixes' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'prefix'        => 'VARCHAR(3) NOT NULL',
        'description'   => 'VARCHAR(100) NULL',
        'department_id' => 'INT NULL',
        'is_default'    => 'TINYINT(1) NULL DEFAULT 0',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        // empty string collides. Enforced in api/tickets/save_user.php, which
        // converts a blank address to NULL before it ever reaches this column.
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
        // The portal twin of the analyst MFA throttle (S6). The portal login has the
        // same second factor, so it had the same session-scoped counter and the same
        // hole. See includes/mfa_throttle.php.
        'mfa_failed_count' => 'INT NOT NULL DEFAULT 0',
        'mfa_locked_until' => 'DATETIME NULL',
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

        // --- the person, as opposed to the login (directory sync slice 1) ---
        // Everything above describes how somebody authenticates; these describe
        // who they are, which is what an asset register and an approval chain
        // need. Filled in by hand today, by a directory sync later.
        //
        // DEFAULT 1 matters on an upgrade: every existing row becomes active
        // without a data migration, which is the only sane reading of a column
        // that did not exist yesterday.
        'is_active'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'deactivated_datetime' => 'DATETIME NULL',
        'job_title'       => 'VARCHAR(150) NULL',
        'department'      => 'VARCHAR(150) NULL',
        // The ticket asset-picker searches an asset's location and almost nobody
        // sets one by hand; a directory knows this and never forgets it.
        'office'          => 'VARCHAR(150) NULL',
        'phone'           => 'VARCHAR(50) NULL',
        'mobile'          => 'VARCHAR(50) NULL',
        'employee_id'     => 'VARCHAR(64) NULL',
        // Self-referencing manager. FK is ON DELETE SET NULL — deleting a
        // manager must never delete their reports.
        'manager_id'      => 'INT NULL',
        // What the DIRECTORY calls them, which is a different fact from what
        // they type into the portal: sAMAccountName is unique per directory,
        // `username` is unique per install. Keeping them apart is what stops a
        // sync ever having to mangle somebody's name into `smithj2`.
        'directory_username' => 'VARCHAR(255) NULL',
        // Explicit, not inferred from auth_provider_id: an account may be pinned
        // to a provider for SIGN-IN without being owned by a sync, and only the
        // latter should make its fields read-only.
        'is_managed'      => 'TINYINT(1) NOT NULL DEFAULT 0',
        'last_seen_in_source' => 'DATETIME NULL',
        // Consecutive runs that failed to find this person. Missing once is
        // noise; missing repeatedly is a fact. Any sighting resets it to 0.
        'sync_missed_count' => 'INT NOT NULL DEFAULT 0',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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

    // --- Ticket numbering (GH #71) ------------------------------------------
    // ⚠️ ticket_number_counters has NO auto-increment id: counter_key is the
    // primary key, which is what lets the read-and-increment be one statement.
    'ticket_number_counters' => [
        'counter_key'      => 'VARCHAR(64) NOT NULL',
        'next_value'       => 'BIGINT NOT NULL DEFAULT 1',
        'updated_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        // ⚠️ The PK is declared in db_verify.php's $primaryKeys map, NOT here —
        // this array is columns only, and a 'PRIMARY KEY' entry would be built
        // as a column literally called that.
    ],

    'ticket_number_history' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'        => 'INT NOT NULL',
        'ticket_number'    => 'VARCHAR(50) NOT NULL',
        'reason'           => "VARCHAR(30) NOT NULL DEFAULT 'renumber'",
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
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
        // NULL end = "scheduled before this column existed"; readers apply the
        // one-hour default. Nothing is backfilled, so an upgrade is a no-op.
        'work_end_datetime'     => 'DATETIME NULL',
        'work_all_day'          => 'TINYINT(1) NOT NULL DEFAULT 0',
        'deleted_datetime'      => 'DATETIME NULL',
        'deleted_by'            => 'INT NULL',
        // Messaging channels: when the customer last messaged in (drives the 24h
        // provider service window on the reply box). NULL for non-channel tickets.
        'last_inbound_at'       => 'DATETIME NULL',
        // Set when this ticket has been merged AWAY into another one. NULL = a live
        // ticket, which is every ticket until somebody merges it.
        //
        // The banner, the search redirect and the inbound-email redirect all key off
        // THIS COLUMN and never off a status name. Statuses are user-configurable
        // (an install may rename or add its own), so "merged" as a status string
        // would be a rule that quietly stops working the day somebody edits a list
        // in settings — the same trap the reopen-on-reply rule avoids by reading
        // ticket_statuses.is_closed rather than hardcoding "Closed".
        'merged_into_id'        => 'INT NULL',
        // Snooze (#933): the ticket is out of the working queue until this instant.
        // "Asleep" is `snoozed_until > UTC_TIMESTAMP()` and nothing else — no cron
        // clears it, so a ticket returns on time even on an install with the
        // schedulers switched off. A past value simply means it has woken.
        'snoozed_until'         => 'DATETIME NULL',
        'snoozed_at'            => 'DATETIME NULL',
        'snoozed_by'            => 'INT NULL',
        'snooze_reason'         => 'VARCHAR(255) NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    // Collision detection (#934): who is looking at which ticket right now.
    // Ephemeral by nature — one row per (ticket, analyst), refreshed by a
    // heartbeat and meaningless once `last_seen` goes stale. Deliberately NOT
    // an audit trail: rows are overwritten and deleted freely.
    'ticket_presence' => [
        'id'           => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'    => 'INT NOT NULL',
        'analyst_id'   => 'INT NOT NULL',
        'last_seen'    => 'DATETIME NULL',
        // 1 while the reply/forward/note composer is open — the state worth
        // warning about, as opposed to merely having the ticket on screen.
        'is_composing' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ],

    'ticket_audit' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'         => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'field_name'        => 'VARCHAR(100) NOT NULL',
        'old_value'         => 'VARCHAR(500) NULL',
        'new_value'         => 'VARCHAR(500) NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'ticket_notes' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'         => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'note_text'         => 'LONGTEXT NOT NULL',
        'is_internal'       => 'TINYINT(1) NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        // The short code that stands in for this company in a ticket number
        // ({COMPANY}). NULL means "derive one from the name" — see
        // TicketNumbering::companyCode().
        'ticket_code'      => 'VARCHAR(12) NULL',
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
        // What this channel points at, in the provider's own terms, when that is
        // not a phone number: for Slack the workspace (team) id, T0123ABCD. NULL
        // on phone channels, which use phone_number instead.
        'channel_ref'           => 'VARCHAR(190) NULL',
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

    // External issue trackers — see freeitsm.sql for the full commentary.
    //
    // ⚠️ CONNECTION-shaped tenancy (same as messaging_channels): tenant_id NULL =
    // SHARED across every company, set = PINNED to one. Never scope reads with
    // activeTenantFilter(), which treats NULL as Default-owned.
    'integration_connections' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'name'                  => 'VARCHAR(100) NOT NULL',
        'provider'              => "VARCHAR(20) NOT NULL DEFAULT 'jira'",
        'base_url'              => 'VARCHAR(500) NOT NULL',
        'auth_type'             => "VARCHAR(20) NOT NULL DEFAULT 'api_token'",
        'credentials'           => 'LONGTEXT NULL',
        'webhook_secret'        => 'VARCHAR(2000) NULL',
        'ingress_mode'          => "VARCHAR(10) NOT NULL DEFAULT 'poll'",
        'inbound_enabled'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'send_attachments'      => 'TINYINT(1) NOT NULL DEFAULT 1',
        'poll_interval_minutes' => 'INT NOT NULL DEFAULT 5',
        'account_identity'      => 'VARCHAR(255) NULL',
        'tenant_id'             => 'INT NULL',
        'is_active'             => 'TINYINT(1) NOT NULL DEFAULT 1',
        'last_poll_datetime'    => 'DATETIME NULL',
        'last_poll_watermark'   => 'VARCHAR(100) NULL',
        'created_by'            => 'INT NULL',
        'created_datetime'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // The ticket ↔ external issue spine. entity_type is polymorphic from day one
    // (V1 only writes 'ticket'). status_category (todo|in_progress|done|cancelled)
    // drives every decision; status_name is display only.
    'integration_links' => [
        'id'                   => 'INT NOT NULL AUTO_INCREMENT',
        'connection_id'        => 'INT NOT NULL',
        'entity_type'          => "VARCHAR(20) NOT NULL DEFAULT 'ticket'",
        'entity_id'            => 'INT NOT NULL',
        'external_id'          => 'VARCHAR(100) NOT NULL',
        'external_key'         => 'VARCHAR(100) NULL',
        'external_url'         => 'VARCHAR(1000) NULL',
        'status_name'          => 'VARCHAR(100) NULL',
        'status_category'      => 'VARCHAR(20) NULL',
        'assignee_name'        => 'VARCHAR(255) NULL',
        'last_synced_datetime' => 'DATETIME NULL',
        'last_error'           => 'VARCHAR(500) NULL',
        'created_by'           => 'INT NULL',
        'created_datetime'     => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // What our values mean in the tracker's vocabulary (V3 mapping). map_type is
    // project | issue_type | priority | custom.
    //
    // ⚠️ local_key is a STRING, not an FK — what it points at depends on map_type.
    // Project routing namespaces it ('tenant:5' / 'dept:3' / '*') so one map_type
    // covers both routing dimensions and the fallback.
    'integration_field_maps' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'connection_id'    => 'INT NOT NULL',
        'map_type'         => 'VARCHAR(20) NOT NULL',
        'local_key'        => 'VARCHAR(100) NOT NULL',
        'external_key'     => 'VARCHAR(255) NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Echo suppression + idempotency for comment sync. One row per comment that
    // has crossed in either direction. direction is 'in' (tracker -> note) or
    // 'out' (note -> tracker).
    //
    // ⚠️ UNIQUE (link_id, external_comment_id) — declared in freeitsm.sql, not
    // here — is the thing that actually guarantees a comment is imported once.
    // The "have I seen this?" read is an optimisation; the key is the guarantee,
    // and it is what makes two overlapping cron runs safe.
    'integration_comment_map' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'link_id'             => 'INT NOT NULL',
        'direction'           => "VARCHAR(3) NOT NULL DEFAULT 'in'",
        'external_comment_id' => 'VARCHAR(100) NOT NULL',
        'local_note_id'       => 'INT NULL',
        'author_identity'     => 'VARCHAR(255) NULL',
        'author_name'         => 'VARCHAR(255) NULL',
        'created_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
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
        'smtp_username'           => 'TEXT NULL',
        'smtp_password'           => 'TEXT NULL',
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
        // The ticket origin stamped on tickets this mailbox opens (#79). Stored as
        // an ID so renaming the origin can't break it; NULL = don't set one.
        'default_origin_id'       => 'INT NULL',
        // JSON array of mailbox-health warning keys the admin has acknowledged.
        'health_dismissed'        => 'TEXT NULL',
        // What the last check said when it did NOT work — the provider's own
        // words, so a mailbox that has stopped collecting can say why instead of
        // just going quiet. Cleared on the next clean check.
        'last_error'              => 'TEXT NULL',
        'last_error_datetime'     => 'DATETIME NULL',
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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

    // Text pulled out of an attachment (discussion #53). The DURABLE record —
    // search_documents holds a derived copy. See freeitsm.sql for why that
    // distinction matters. ⚠️ Its PK is attachment_id, not `id`, so it must also
    // be listed in $primaryKeys in api/system/db_verify.php.
    'attachment_text' => [
        'attachment_id'      => 'INT NOT NULL',
        'status'             => 'VARCHAR(16) NOT NULL',
        'extractor'          => 'VARCHAR(20) NULL',
        'extracted_text'     => 'LONGTEXT NULL',
        'chars'              => 'INT NOT NULL DEFAULT 0',
        'extracted_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Extracted text for an attached document (discussion #76).
    //
    // Its own table rather than a source_type column on attachment_text: that
    // table's primary key is the attachment id ALONE, and a document id is a
    // different small integer from a different table, so the two would collide.
    // What the pipelines genuinely share — attTextExtractFile() — is a function
    // taking a path, and both use it.
    //
    // ⚠️ PK is document_id, so it is registered in $primaryKeys in db_verify.php.
    'document_text' => [
        'document_id'        => 'INT NOT NULL',
        'status'             => 'VARCHAR(16) NOT NULL',
        'extractor'          => 'VARCHAR(20) NULL',
        'extracted_text'     => 'LONGTEXT NULL',
        'chars'              => 'INT NOT NULL DEFAULT 0',
        'extracted_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
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

    // One row per directory sync RUN — the attempt, not just the success. A sync
    // that did nothing and a sync that never ran are otherwise indistinguishable.
    'directory_sync_runs' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'provider_id'       => 'INT NOT NULL',
        'mode'              => 'VARCHAR(10) NOT NULL DEFAULT \'live\'',
        // running | ok | stopped | failed. 'stopped' is the sanity brake: a
        // refusal, not an error, and kept distinct so protecting somebody never
        // reads as breaking.
        'status'            => 'VARCHAR(12) NOT NULL DEFAULT \'running\'',
        'started_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'finished_datetime' => 'DATETIME NULL',
        'seen_count'        => 'INT NOT NULL DEFAULT 0',
        'created_count'     => 'INT NOT NULL DEFAULT 0',
        'updated_count'     => 'INT NOT NULL DEFAULT 0',
        'adopted_count'     => 'INT NOT NULL DEFAULT 0',
        'deactivated_count' => 'INT NOT NULL DEFAULT 0',
        'conflict_count'    => 'INT NOT NULL DEFAULT 0',
        'error_count'       => 'INT NOT NULL DEFAULT 0',
        'message'           => 'TEXT NULL',
        'triggered_by_analyst_id' => 'INT NULL',
    ],

    // What a run did to each PERSON. "47 updated" is a number; this is the
    // answer to "updated how, and who", which is the only actionable version.
    'directory_sync_entries' => [
        'id'                 => 'INT NOT NULL AUTO_INCREMENT',
        'run_id'             => 'INT NOT NULL',
        'action'             => 'VARCHAR(16) NOT NULL',
        'user_id'            => 'INT NULL',
        'directory_username' => 'VARCHAR(255) NULL',
        'display_name'       => 'VARCHAR(255) NULL',
        'detail'             => 'VARCHAR(1000) NULL',
        'created_datetime'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // A document is either a file we hold or a link to somewhere else (an external
    // DMS). It carries NO permissions of its own — see includes/documents.php:
    // visibility is inherited from whatever it is attached to.
    'documents' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'kind'              => "VARCHAR(8) NOT NULL DEFAULT 'file'",   // file | link
        'title'             => 'VARCHAR(255) NOT NULL',
        // For a LINK this is all search will ever have to go on — there is no
        // document to extract text from, only a URL. Hence not "optional".
        'description'       => 'TEXT NULL',
        // Opaque key, never a path: the files can move without a data migration.
        'storage_key'       => 'VARCHAR(255) NULL',
        'original_name'     => 'VARCHAR(255) NULL',
        'mime_type'         => 'VARCHAR(100) NULL',
        'size_bytes'        => 'BIGINT NULL',
        // Lets the same warranty PDF attached to eleven laptops be stored once.
        'content_hash'      => 'CHAR(64) NULL',
        'external_url'      => 'VARCHAR(2048) NULL',
        'tenant_id'         => 'INT NULL',
        'uploaded_by_id'    => 'INT NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL',
        'deleted_datetime'  => 'DATETIME NULL',
    ],

    // What each document is attached to. A ROW, deliberately, not a column on
    // `documents` — that is what lets one document belong to several things
    // later without a migration or a rewrite of every query that touches it.
    'document_links' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'document_id'       => 'INT NOT NULL',
        'parent_type'       => 'VARCHAR(32) NOT NULL',   // documentEntityTypes()
        'parent_id'         => 'INT NOT NULL',
        'linked_by_id'      => 'INT NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Who fetched which document, and when. One row per download, written at the
    // only place a document can actually be obtained — which is also the only
    // place that can honestly claim to have recorded every access.
    'document_access_log' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'document_id'       => 'INT NOT NULL',
        'analyst_id'        => 'INT NULL',
        'action'            => "VARCHAR(12) NOT NULL DEFAULT 'download'",
        'ip_address'        => 'VARCHAR(45) NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Per-company answers to settings that otherwise live install-wide in
    // system_settings (discussion #72). A company with no row follows the
    // install default — see includes/tenant_settings.php.
    'tenant_settings' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'tenant_id'         => 'INT NOT NULL',
        'setting_key'       => 'VARCHAR(100) NOT NULL',
        'setting_value'     => 'TEXT NULL',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'email_send_log' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'mailbox_id'        => 'INT NULL',
        'ticket_id'         => 'INT NULL',
        'route'             => 'VARCHAR(30) NOT NULL',
        'provider'          => 'VARCHAR(20) NULL',
        'auth_mode'         => 'VARCHAR(20) NULL',
        'to_address'        => 'VARCHAR(255) NOT NULL',
        'subject'           => 'VARCHAR(500) NULL',
        'status'            => 'VARCHAR(10) NOT NULL',
        'error_message'     => 'TEXT NULL',
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


    /**
     * An analyst's email signatures (discussion #80, request 3).
     *
     * PER ANALYST, ALWAYS. There is deliberately no shared or install-wide signature:
     * a signature is a person signing their own name, and one signature for a whole
     * team is either wrong for everybody or has to be filled in by merge codes anyway.
     *
     * SEVERAL ARE ALLOWED, and exactly one is the default. The default is what gets
     * inserted without being asked for — an analyst who only ever wants one signature
     * never sees a choice. Picking a different one is a deliberate act, which is what
     * keeps "several" from taxing every single reply with a decision.
     */
    'analyst_signatures' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'       => 'INT NOT NULL',
        'name'             => 'VARCHAR(100) NOT NULL',
        'body'             => 'LONGTEXT NOT NULL',
        'is_default'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'    => 'INT NOT NULL DEFAULT 0',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],
    /**
     * Which senders an email template applies to (discussion #80).
     *
     * A template with NO rows here applies to EVERYONE, and that is the state a new
     * template starts in — so an installation always has a catch-all unless somebody
     * deliberately removes it. "No rules" meaning "everyone" rather than "nobody" is
     * the whole safety property: the empty case is the permissive one.
     *
     * match_type is 'address' (someone@a.com) or 'domain' (a.com, stored without
     * the @). Selection is by SPECIFICITY, not by order: address beats domain beats
     * everyone, so display_order cannot change which template is chosen.
     */
    'ticket_email_template_rules' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'template_id'       => 'INT NOT NULL',
        'match_type'        => 'VARCHAR(10) NOT NULL',
        'match_value'       => 'VARCHAR(255) NOT NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    /**
     * One merge: which ticket was folded away, and into what.
     *
     * WHY THIS TABLE EXISTS AT ALL
     * ---------------------------
     * "Whatever happened to ticket ABC?" has to have an answer years later. The
     * merged-away ticket is never deleted, so the row it points at here is what turns
     * a dead end into a redirect — and not only for humans: inbound email still
     * arrives quoting `[SDREF:ABC-…]` from notifications sent before the merge, and
     * this is the lookup that lands those replies on the surviving ticket instead of
     * on a closed one nobody reads.
     *
     * `source_ticket_number` is a SNAPSHOT, deliberately duplicating what the source
     * ticket row already says. It costs nothing and makes the record self-describing:
     * a merge log you can read without a join is a merge log people will actually
     * consult, and it survives any future decision to hard-delete very old tickets.
     *
     * `reference_mode` / `originals_mode` record the settings AS THEY WERE at the time
     * (Tickets → Settings → Merge behaviour). An admin who changes the policy next
     * month must not silently rewrite the history of merges done under the old one.
     */
    'ticket_merges' => [
        'id'                   => 'INT NOT NULL AUTO_INCREMENT',
        'source_ticket_id'     => 'INT NOT NULL',
        'source_ticket_number' => 'VARCHAR(50) NULL',
        'target_ticket_id'     => 'INT NOT NULL',
        'reference_mode'       => "VARCHAR(20) NOT NULL DEFAULT 'survivor'",
        'originals_mode'       => "VARCHAR(20) NOT NULL DEFAULT 'thread'",
        // Which messages moved, as a JSON array of email ids — see the same column on
        // ticket_splits for why text and not a child table. Recorded now so unmerge
        // becomes possible later; merges done BEFORE this column existed cannot be
        // reconstructed, which is the whole reason for adding it early.
        'moved_email_ids'      => 'TEXT NULL',
        // Everything ELSE the merge moved, as JSON {table: [row ids]} — notes, time
        // entries, recordings, tasks, form submissions, CMDB/problem/change links.
        // Messages alone are not a merge: unmerging without these would leave a
        // ticket's notes and logged time stranded on somebody else's ticket.
        'moved_related'        => 'TEXT NULL',
        // The system message carrying the HTML snapshot, when the originals mode made
        // one. Created by the merge rather than moved, so it is not in
        // moved_email_ids, and an unmerge has to delete it (and its file).
        'snapshot_email_id'    => 'INT NULL',
        // What the source ticket looked like before it was closed by the merge. A
        // merge sets it to the install's first closed status; putting it back to
        // "Open" would be a guess, and a wrong one for anything that had been
        // resolved before it was merged.
        'source_prev_status_id'       => 'INT NULL',
        'source_prev_closed_datetime' => 'DATETIME NULL',
        'undone_datetime'      => 'DATETIME NULL',
        'undone_by_id'         => 'INT NULL',
        'merged_by_id'         => 'INT NULL',
        'merged_datetime'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    /**
     * One split: messages that were moved OUT of a ticket into a new one.
     *
     * The mirror of ticket_merges, and needed for the same reason: "why does this
     * conversation jump from Tuesday to Friday?" must be answerable. The original
     * also carries an inline marker message where the split happened, but that is a
     * display convenience — this is the record.
     *
     * NOTE the asymmetry with merging. A merge leaves a REDIRECT, because the
     * merged-away reference is one the customer already holds and may reply to. A
     * split creates a reference nobody has ever seen, so there is nothing to redirect
     * and no equivalent of merged_into_id: both tickets stay live and independent.
     *
     * message_count is denormalised on purpose — it is the one fact you want when
     * reading the log ("four messages left here") and recomputing it later is
     * impossible once those messages have moved on again.
     */
    'ticket_splits' => [
        'id'                   => 'INT NOT NULL AUTO_INCREMENT',
        'source_ticket_id'     => 'INT NOT NULL',
        'source_ticket_number' => 'VARCHAR(50) NULL',
        'new_ticket_id'        => 'INT NOT NULL',
        'new_ticket_number'    => 'VARCHAR(50) NULL',
        'message_count'        => 'INT NOT NULL DEFAULT 0',
        // EXACTLY which messages moved, as a JSON array of email ids. A count alone
        // cannot be undone: you would have to guess which rows to send back, and
        // guessing wrong scatters a conversation across two tickets permanently.
        //
        // Stored as text rather than a child table on purpose. This list is only ever
        // read whole ("undo this split") — never joined, filtered or aggregated — and
        // a child table with an FK to `emails` would CASCADE the record away the day
        // one of those messages is deleted, which is precisely when you most want to
        // know what happened.
        'moved_email_ids'      => 'TEXT NULL',
        // The system message left behind in the original thread, so an undo can
        // remove it rather than leaving a marker pointing at a split that no longer
        // exists.
        'marker_email_id'      => 'INT NULL',
        'undone_datetime'      => 'DATETIME NULL',
        'undone_by_id'         => 'INT NULL',
        'split_by_id'          => 'INT NULL',
        'split_datetime'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    /**
     * Canned responses an ANALYST inserts into a reply by hand.
     *
     * Not to be confused with `ticket_email_templates` directly above, which is the
     * automated mail the SYSTEM sends a requester on an event ("your ticket has been
     * logged"). Nobody clicks those. These are the opposite: a human picks one
     * mid-conversation because they have typed it two hundred times already.
     *
     * TWO nullable columns, and they mean DIFFERENT things — read carefully:
     *
     *   analyst_id  NULL = a SHARED team template, curated on the settings tab under
     *                      Cap::TICKETS_REPLY_TEMPLATES. Set = one analyst's private
     *                      template, saved straight from the reply box, visible to
     *                      nobody else — not even to an administrator's picker.
     *   tenant_id   NULL = a global default shared by every company. Set = a template
     *                      one company added for itself. This is the *config* meaning
     *                      of tenant_id (as ticket_types), NOT the scoped-data meaning
     *                      used by `tickets`. Resolved via getTenantConfigRows().
     *
     * A private template is therefore analyst_id = me, and a global shared one is both
     * columns NULL. The pair is never a wildcard: reads filter on both axes.
     */
    'ticket_reply_templates' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'body'              => 'LONGTEXT NOT NULL',
        'analyst_id'        => 'INT NULL',
        'tenant_id'         => 'INT NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
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
        // QR labels (#935). asset_tag is the printed human number, unique per
        // company in application code (a UNIQUE index can't hold it — NULL
        // tenant_id defeats it). qr_token is what the QR encodes.
        'asset_tag'         => 'VARCHAR(64) NULL',
        'qr_token'          => 'VARCHAR(64) NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'asset_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'icon_id'           => 'INT NULL',
        // Multi-tenancy config: NULL = global default type, set = a company's own.
        'tenant_id'         => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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

    // --- Custom asset fields (docs/design/flexible-asset-fields.md) ---------
    // The catalogue, the sets that bundle fields, the two ways a set attaches
    // (to a type, or to one asset), and the answers.

    'asset_fields' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'field_key'         => 'VARCHAR(100) NOT NULL',
        'label'             => 'VARCHAR(150) NOT NULL',
        'field_type'        => 'VARCHAR(20) NOT NULL',
        'config'            => 'LONGTEXT NULL',
        'help_text'         => 'VARCHAR(500) NULL',
        'is_unique'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_searchable'     => 'TINYINT(1) NOT NULL DEFAULT 0',
        'show_in_list'      => 'TINYINT(1) NOT NULL DEFAULT 0',
        'tenant_id'         => 'INT NULL',
        'is_deleted'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'asset_field_options' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'field_id'          => 'INT NOT NULL',
        'option_value'      => 'VARCHAR(255) NOT NULL',
        'colour'            => 'VARCHAR(7) NULL',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
    ],

    'asset_field_sets' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(150) NOT NULL',
        'description'       => 'VARCHAR(500) NULL',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'tenant_id'         => 'INT NULL',
        'is_deleted'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'asset_field_set_fields' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'set_id'            => 'INT NOT NULL',
        'field_id'          => 'INT NOT NULL',
        'sort_order'        => 'INT NOT NULL DEFAULT 0',
        'is_required'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'default_value'     => 'VARCHAR(255) NULL',
    ],

    'asset_type_field_sets' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'asset_type_id'     => 'INT NOT NULL',
        'set_id'            => 'INT NOT NULL',
        'sort_order'        => 'INT NOT NULL DEFAULT 0',
    ],

    'asset_field_set_assets' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'asset_id'              => 'INT NOT NULL',
        'set_id'                => 'INT NOT NULL',
        'created_by_analyst_id' => 'INT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'asset_field_values' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'asset_id'      => 'INT NOT NULL',
        'field_id'      => 'INT NOT NULL',
        'seq'           => 'INT NOT NULL DEFAULT 0',
        'value_text'    => 'TEXT NULL',
        'value_number'  => 'DECIMAL(20,4) NULL',
        'value_date'    => 'DATETIME NULL',
        'value_boolean' => 'TINYINT(1) NULL',
        'value_ref_id'  => 'INT NULL',
    ],

    // --- Asset import (docs/design/flexible-asset-fields.md §6) -------------

    'asset_import_profiles' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'name'                  => 'VARCHAR(150) NOT NULL',
        'target'                => "VARCHAR(20) NOT NULL DEFAULT 'asset'",
        'source_kind'           => "VARCHAR(10) NOT NULL DEFAULT 'csv'",
        'source_config'         => 'LONGTEXT NULL',
        'match_keys'            => 'LONGTEXT NULL',
        'on_missing'            => "VARCHAR(20) NOT NULL DEFAULT 'ignore'",
        'on_unknown_option'     => "VARCHAR(10) NOT NULL DEFAULT 'reject'",
        'write_mode'            => "VARCHAR(10) NOT NULL DEFAULT 'fill'",
        'default_asset_type_id' => 'INT NULL',
        'default_status_id'     => 'INT NULL',
        'apply_field_set_id'    => 'INT NULL',
        'is_active'             => 'TINYINT(1) NOT NULL DEFAULT 1',
        'tenant_id'             => 'INT NULL',
        'created_by_analyst_id' => 'INT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'asset_import_mappings' => [
        'id'          => 'INT NOT NULL AUTO_INCREMENT',
        'profile_id'  => 'INT NOT NULL',
        'source_key'  => 'VARCHAR(255) NOT NULL',
        'target_kind' => 'VARCHAR(10) NOT NULL',
        'target_key'  => 'VARCHAR(100) NOT NULL',
        'transform'   => 'LONGTEXT NULL',
    ],

    'asset_import_runs' => [
        'id'                      => 'INT NOT NULL AUTO_INCREMENT',
        'profile_id'              => 'INT NULL',
        'mode'                    => "VARCHAR(10) NOT NULL DEFAULT 'live'",
        'status'                  => "VARCHAR(12) NOT NULL DEFAULT 'running'",
        'source_name'             => 'VARCHAR(255) NULL',
        'stored_file'             => 'VARCHAR(255) NULL',
        'started_datetime'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'finished_datetime'       => 'DATETIME NULL',
        'seen_count'              => 'INT NOT NULL DEFAULT 0',
        'created_count'           => 'INT NOT NULL DEFAULT 0',
        'updated_count'           => 'INT NOT NULL DEFAULT 0',
        'unchanged_count'         => 'INT NOT NULL DEFAULT 0',
        'conflict_count'          => 'INT NOT NULL DEFAULT 0',
        'skipped_count'           => 'INT NOT NULL DEFAULT 0',
        'error_count'             => 'INT NOT NULL DEFAULT 0',
        'message'                 => 'TEXT NULL',
        'triggered_by_analyst_id' => 'INT NULL',
        'tenant_id'               => 'INT NULL',
    ],

    'asset_import_run_entries' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'run_id'            => 'INT NOT NULL',
        'row_number'        => 'INT NULL',
        'action'            => 'VARCHAR(16) NOT NULL',
        'asset_id'          => 'INT NULL',
        'source_ref'        => 'VARCHAR(255) NULL',
        'display_name'      => 'VARCHAR(255) NULL',
        'detail'            => 'VARCHAR(1000) NULL',
        'raw_row'           => 'LONGTEXT NULL',
        'resolved_datetime' => 'DATETIME NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'analyst_ticket_dashboard_widgets' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'widget_id'         => 'INT NOT NULL',
        'sort_order'        => 'INT NOT NULL DEFAULT 0',
        'status_filter'     => 'VARCHAR(50) NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'change_comments' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'change_id'         => 'INT NOT NULL',
        'analyst_id'        => 'INT NOT NULL',
        'comment_text'      => 'LONGTEXT NOT NULL',
        'is_internal'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    // ── Scheduled work -> the analyst's own calendar (GH #75) ────────────────
    // Admin-level connection, per-analyst enrolment, and the map of what we put
    // where. See database/freeitsm.sql for why the split is three tables.
    // ⚠️ $schema carries COLUMNS ONLY — the foreign keys and unique keys live in
    // freeitsm.sql; db_verify creates and adds columns, nothing else.
    'calendar_connections' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'name'                => 'VARCHAR(100) NOT NULL',
        'provider'            => "VARCHAR(20) NOT NULL DEFAULT 'microsoft'",
        'credentials'         => 'LONGTEXT NULL',
        'mailbox_id'          => 'INT NULL',
        'is_active'           => 'TINYINT(1) NOT NULL DEFAULT 1',
        'token_data'          => 'LONGTEXT NULL',
        'last_error'          => 'VARCHAR(500) NULL',
        'last_error_datetime' => 'DATETIME NULL',
        'created_by'          => 'INT NULL',
        'created_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'calendar_enrolments' => [
        'id'                 => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'         => 'INT NOT NULL',
        // off | push | feed — ONE choice, or you see every ticket twice.
        'mode'               => "VARCHAR(10) NOT NULL DEFAULT 'off'",
        'connection_id'      => 'INT NULL',
        'calendar_address'   => 'VARCHAR(255) NULL',
        'credentials'        => 'LONGTEXT NULL',
        'subscription_id'      => 'VARCHAR(255) NULL',
        'subscription_expires' => 'DATETIME NULL',
        'subscription_secret'  => 'VARCHAR(128) NULL',
        'delta_token'        => 'TEXT NULL',
        'delta_synced_datetime' => 'DATETIME NULL',
        'last_sync_datetime' => 'DATETIME NULL',
        'last_error'         => 'VARCHAR(500) NULL',
        'created_datetime'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'calendar_sync_events' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'        => 'INT NOT NULL',
        'analyst_id'       => 'INT NOT NULL',
        'connection_id'    => 'INT NULL',
        'remote_event_id'  => 'VARCHAR(500) NOT NULL',
        'remote_calendar'  => 'VARCHAR(255) NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Optional grouping + routing for the morning round (discussion #64).
    // ⚠️ Assignment is guidance, never a lock — nothing in the save path reads
    // it, so anyone can still complete anyone's check.
    // Handover document templates (discussion #56).
    'asset_handover_templates' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'name'             => 'VARCHAR(120) NOT NULL',
        'blocks'           => 'LONGTEXT NULL',
        'is_default'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_active'        => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // In-app notifications (discussion #55).
    'notifications' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'       => 'INT NOT NULL',
        'event_type'       => 'VARCHAR(64) NOT NULL',
        'entity_type'      => 'VARCHAR(32) NOT NULL',
        'entity_id'        => 'INT NOT NULL',
        'entity_ref'       => 'VARCHAR(64) NULL',
        'title'            => 'VARCHAR(255) NULL',
        'body'             => 'VARCHAR(500) NULL',
        'actor_name'       => 'VARCHAR(100) NULL',
        'event_count'      => 'INT NOT NULL DEFAULT 1',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'read_datetime'    => 'DATETIME NULL',
    ],

    'morningChecks_Groups' => [
        'GroupID'           => 'INT NOT NULL AUTO_INCREMENT',
        'GroupName'         => 'VARCHAR(255) NOT NULL',
        'GroupDescription'  => 'LONGTEXT NULL',
        'AssignedTeamID'    => 'INT NULL',
        'AssignedAnalystID' => 'INT NULL',
        'IsActive'          => 'TINYINT(1) NOT NULL DEFAULT 1',
        'SortOrder'         => 'INT NOT NULL DEFAULT 0',
        'CreatedDate'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'ModifiedDate'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'morningChecks_Checks' => [
        'CheckID'           => 'INT NOT NULL AUTO_INCREMENT',
        'CheckName'         => 'VARCHAR(255) NOT NULL',
        'CheckDescription'  => 'LONGTEXT NULL',
        'IsActive'          => 'TINYINT(1) NOT NULL DEFAULT 1',
        'SortOrder'         => 'INT NOT NULL DEFAULT 0',
        // NULL group = ungrouped, where every existing check starts.
        'GroupID'           => 'INT NULL',
        'AssignedAnalystID' => 'INT NULL',
        'CreatedDate'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'ModifiedDate'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    // Tickets/tasks raised from a check. A link table rather than columns on the
    // result, because one bad check can raise several things.
    'morningChecks_ResultLinks' => [
        'LinkID'        => 'INT NOT NULL AUTO_INCREMENT',
        'ResultID'      => 'INT NOT NULL',
        'EntityType'    => 'VARCHAR(20) NOT NULL',
        'EntityID'      => 'INT NOT NULL',
        'EntityRef'     => 'VARCHAR(100) NULL',
        'CreatedByID'   => 'INT NULL',
        'CreatedDate'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
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
        // Who last set the status (discussion #64) — see freeitsm.sql for why
        // this is not simply CreatedBy being reused.
        'ModifiedBy'    => 'VARCHAR(100) NULL',
        'CreatedDate'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'ModifiedDate'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'lms_answers' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'question_id'           => 'INT NOT NULL',
        'answer_text'           => 'VARCHAR(500) NOT NULL',
        'is_correct'            => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'         => 'INT NOT NULL DEFAULT 0',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'processes' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'title'             => 'VARCHAR(255) NOT NULL',
        'description'       => 'TEXT NULL',
        'created_by'        => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        // ── Folders and per-document permissions ────────────────────────────
        // Which folder the article lives in. NULL = the root, which is where
        // every existing article lands on upgrade: zero migration, and the
        // resulting state (root, inheriting, unrestricted) is indistinguishable
        // from how the module behaved before folders existed.
        'folder_id'             => 'INT NULL',
        // 0 = Open: readable unless a DENY names you.
        // 1 = Restricted: readable only if a GRANT names you.
        // The polarity lives on the OBJECT, never on the access rows, so an
        // allow and a deny cannot coexist and there is no precedence rule.
        'is_restricted'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        // 1 = take permissions from the parent folder and ignore my own rows.
        // Default 1 so an upgraded install inherits from a root that restricts
        // nothing — i.e. nothing changes.
        'inherit_permissions'   => 'TINYINT(1) NOT NULL DEFAULT 1',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    // ── Knowledge folders ───────────────────────────────────────────────────
    // A document lives in EXACTLY ONE folder. That is the load-bearing decision:
    // with several parents, "inherit from parent" has no answer — most-permissive
    // leaks, most-restrictive loses documents people filed themselves. Appearing
    // in two places is what knowledge_shortcuts is for.
    'knowledge_folders' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        // NULL = a top-level folder. The root itself is not a row.
        'parent_id'           => 'INT NULL',
        'name'                => 'VARCHAR(255) NOT NULL',
        // Same meaning as on an article — see above.
        'is_restricted'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'inherit_permissions' => 'TINYINT(1) NOT NULL DEFAULT 1',
        // Who to ask when a folder ends up unreachable. Not a permission by
        // itself: recovery is the knowledge.admin floor, which always passes.
        'owner_id'            => 'INT NULL',
        // ⚠️ NULL = shared with every company, exactly as on knowledge_articles,
        // and the OPPOSITE of tickets/assets. See includes/tenancy.php.
        'tenant_id'           => 'INT NULL',
        'created_by_id'       => 'INT NULL',
        'created_datetime'    => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'modified_datetime'   => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // ── The access list ─────────────────────────────────────────────────────
    // 🔑 THERE IS NO allow/deny COLUMN, AND ITS ABSENCE IS THE GUARANTEE. The
    // polarity comes from the object (is_restricted): rows on an Open object are
    // denies, rows on a Restricted object are grants. A contradictory pair
    // therefore cannot be stored, so there is no precedence rule to remember and
    // no effective-permissions dialog to explain. This is the same instinct as
    // the audience ladder — make the contradiction inexpressible rather than
    // adjudicating it.
    //
    // ⚠️ Flipping an object's polarity WIPES its rows. Keeping them dormant would
    // mean an invisible entry that springs back to life on the next flip, which
    // is the "an unloaded checkbox looks exactly like OFF" failure in a costume.
    'knowledge_acl' => [
        'id'             => 'INT NOT NULL AUTO_INCREMENT',
        // 'folder' | 'article'
        'object_type'    => 'VARCHAR(10) NOT NULL',
        'object_id'      => 'INT NOT NULL',
        // 'analyst' | 'team' | 'user' | 'user_group'
        'principal_type' => 'VARCHAR(12) NOT NULL',
        'principal_id'   => 'INT NOT NULL',
        'created_by_id'  => 'INT NULL',
        'created_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // ── Shortcuts ───────────────────────────────────────────────────────────
    // A pointer with NO permissions of its own — it resolves to the target and
    // the target's rules decide. That is what keeps the tree single-parent while
    // still letting a document appear in two places. Deliberately has no
    // polarity, no ACL, and no audience: a shortcut can never GRANT, and the
    // reader must filter shortcuts by target readability at list time or the
    // row leaks the target's title.
    'knowledge_shortcuts' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'folder_id'        => 'INT NULL',
        'article_id'       => 'INT NOT NULL',
        'created_by_id'    => 'INT NULL',
        'created_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // ── User groups ─────────────────────────────────────────────────────────
    // NOT lms_learning_groups. The driving case is ad hoc and short-lived —
    // three engineers on site for a week needing one folder — and routing that
    // through the LMS to grant a document permission would be daft. `users` has
    // no grouping of any kind today, so a table was needed regardless.
    'knowledge_user_groups' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(500) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_by_id'     => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'knowledge_user_group_members' => [
        'id'         => 'INT NOT NULL AUTO_INCREMENT',
        'group_id'   => 'INT NOT NULL',
        // A member is an analyst OR a portal user, never both: 'analyst' | 'user'.
        'member_type'=> 'VARCHAR(10) NOT NULL',
        'member_id'  => 'INT NOT NULL',
        // ⚠️ The whole reason this table exists. "For the week" is the requirement
        // as stated, and an access list that quietly stays open after the
        // engineers go home is the failure worth designing out on day one.
        // NULL = no expiry.
        'expires_at' => 'DATETIME NULL',
        'created_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // ── Audit ───────────────────────────────────────────────────────────────
    // Modelled on document_access_log, which already does this job for attached
    // documents. ⚠️ VIEWS ARE A DIFFERENT VOLUME CLASS FROM EDITS: view_count
    // already increments on every read, and a row per view on a busy KB is
    // millions a year. Creates, edits, permission changes, deletes and
    // administrator-floor passes are rare and are what somebody actually comes
    // looking for — do not let view spam bury them.
    'knowledge_audit' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        // 'folder' | 'article'
        'object_type'      => 'VARCHAR(10) NOT NULL',
        'object_id'        => 'INT NOT NULL',
        // create | edit | view | delete | restore | move | permissions | admin_override
        'action'           => 'VARCHAR(20) NOT NULL',
        'analyst_id'       => 'INT NULL',
        'user_id'          => 'INT NULL',
        // JSON. For a permission change: what the rows were and what they became.
        'detail'           => 'LONGTEXT NULL',
        'ip_address'       => 'VARCHAR(45) NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'knowledge_article_tags' => [
        'article_id'    => 'INT NOT NULL',
        'tag_id'        => 'INT NOT NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    // Knowledge gaps — the Knowledge assistant. One closed ticket says almost
    // nothing ("reset password, asked user to log in again" is not an article);
    // fourteen of them is a different statement. This is a CACHE — every row
    // can be dropped and rebuilt by re-running the analysis. It exists only
    // because embedding a ticket costs a paid API call.
    'knowledge_gap_tickets' => [
        'ticket_id'             => 'INT NOT NULL',
        'embedding'             => 'LONGTEXT NULL',
        'embedded_datetime'     => 'DATETIME NULL',
        // Similarity to the CLOSEST published article — low means nothing in
        // the KB answers this ticket, which is what makes it a gap candidate.
        'best_article_id'       => 'INT NULL',
        'best_similarity'       => 'FLOAT NULL',
        // 0-100: how much of an article could actually be written from this one
        // ticket. Picks which ticket in a cluster gets drafted from, and decides
        // whether the assistant has to interview the analyst instead.
        'richness'              => 'INT NOT NULL DEFAULT 0',
        'analysed_datetime'     => 'DATETIME NULL',
        'tenant_id'             => 'INT NULL',
    ],

    'knowledge_gap_clusters' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'label'                 => 'VARCHAR(255) NOT NULL',
        'seed_ticket_id'        => 'INT NULL',
        // The ticket the assistant drafts FROM — the richest in the cluster, not
        // the newest. A thin ticket counts towards the total but is never handed
        // to the model as the source.
        'best_ticket_id'        => 'INT NULL',
        'max_richness'          => 'INT NOT NULL DEFAULT 0',
        'ticket_count'          => 'INT NOT NULL DEFAULT 0',
        'first_ticket_datetime' => 'DATETIME NULL',
        'last_ticket_datetime'  => 'DATETIME NULL',
        // open | dismissed | written. 'dismissed' has to survive a re-analysis,
        // which is the whole reason clusters are stored rather than recomputed
        // on every view.
        'status'                => "VARCHAR(20) NOT NULL DEFAULT 'open'",
        'dismissed_by_id'       => 'INT NULL',
        'dismissed_datetime'    => 'DATETIME NULL',
        'article_id'            => 'INT NULL',
        'signature'             => 'VARCHAR(64) NULL',
        'tenant_id'             => 'INT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],

    'knowledge_gap_cluster_tickets' => [
        'cluster_id'    => 'INT NOT NULL',
        'ticket_id'     => 'INT NOT NULL',
        'similarity'    => 'FLOAT NULL',
    ],

    'software_inventory_apps' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'display_name'      => 'VARCHAR(512) NOT NULL',
        'publisher'         => 'VARCHAR(512) NULL',
        'first_detected'    => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        // SCOPED DATA, like tickets and assets: NULL = the Default company's,
        // NOT "shared". Existing tasks stay NULL and become Default-owned, which
        // is the same migration tickets and assets took.
        'tenant_id'           => 'INT NULL',
        'board_position'      => 'INT NOT NULL DEFAULT 0',
        'created_by_id'       => 'INT NOT NULL',
        'created_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'completed_datetime'  => 'DATETIME NULL',
        // WHEN THE WORK IS PLANNED FOR (GH #112). Deliberately the same three
        // columns, with the same names, that a ticket's scheduled work uses —
        // one convention for "this is booked in", not two.
        //
        // These are NAIVE wall-clock values, like every other scheduling field:
        // a 2pm slot means 2pm to whoever reads it. Never run them through
        // parseUTCDate/tzOpts. See the "Timezones and Time Handling" note.
        //
        // Separate from start_date/due_date, which are a plan and a deadline and
        // stay date-only. "Due by Friday" and "being done 09:00-11:00 on Tuesday"
        // are different statements and a task can carry both.
        'work_start_datetime' => 'DATETIME NULL',
        'work_end_datetime'   => 'DATETIME NULL',
        'work_all_day'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    // Time actually SPENT on a task, as many sessions as it took (GH #112).
    // Mirrors ticket_time_entries column for column, because it is the same idea
    // about a different record — a second, subtly different shape would be the
    // thing that later drifts.
    'task_time_entries' => [
        'id'                  => 'INT NOT NULL AUTO_INCREMENT',
        'task_id'             => 'INT NOT NULL',
        'analyst_id'          => 'INT NOT NULL',
        'notes'               => 'LONGTEXT NULL',
        'time_spent_minutes'  => 'INT NOT NULL',
        'entry_datetime'      => 'DATETIME NOT NULL',
        'is_active'           => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'    => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'    => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        // Catalogue-request approval (#928): gate a portal submission behind a
        // designated approver before a ticket is raised. FK on approver_id lives
        // in freeitsm.sql. requires_approval on + approver_id NULL = unconfigured.
        'requires_approval' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'approver_id'       => 'INT NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'form_fields' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'form_id'       => 'INT NOT NULL',
        // 'section' is a presentational item, not a question: it renders as a
        // heading, never produces submission data, and owns the fields below it
        // until the next 'section'. Kept in this table so one flat sort_order
        // still describes the whole form.
        'field_type'    => 'VARCHAR(50) NOT NULL',
        'label'         => 'VARCHAR(255) NOT NULL',
        'options'       => 'LONGTEXT NULL',
        'is_required'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sort_order'    => 'INT NOT NULL DEFAULT 0',
        // Per-field JSON. Today: the conditional-visibility rule, whose `field`
        // key is a form_fields.id. NULL = always visible = every pre-existing
        // row, so an upgraded form renders exactly as it did.
        'config'        => 'LONGTEXT NULL',
        // Soft delete — form_submission_data.field_id points at this row, so a
        // hard delete destroyed past respondents' answers. See freeitsm.sql.
        'is_deleted'    => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        // Catalogue-request approval (#928). approval_status: not_required (default,
        // and every pre-#928 row) / pending / approved / rejected. approver_id is
        // snapshotted from the form at submit time. FKs live in freeitsm.sql.
        'approval_status'            => "VARCHAR(20) NOT NULL DEFAULT 'not_required'",
        'approver_id'                => 'INT NULL',
        'approval_decided_by_id'     => 'INT NULL',
        'approval_decided_datetime'  => 'DATETIME NULL',
        'approval_comment'           => 'TEXT NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'form_submission_data' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'submission_id' => 'INT NOT NULL',
        'field_id'      => 'INT NOT NULL',
        'field_value'   => 'LONGTEXT NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'supplier_statuses' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'contract_statuses' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'payment_schedules' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'contract_term_tabs' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'contract_term_values' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'contract_id'       => 'INT NOT NULL',
        'term_tab_id'       => 'INT NOT NULL',
        'content'           => 'LONGTEXT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        // Uptime: does time at this level count as downtime? Defaults to 1 so an
        // upgrade is conservative — an existing custom level counts until somebody
        // says otherwise, rather than quietly vanishing from the figures. The
        // catch-up migration below then clears it for Maintenance / Operational /
        // No Disruption, which are the shipped levels where it is plainly wrong.
        'counts_as_downtime' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Incident update log (discussion #59, phase 2). Each row is a moment; the
    // per-service impacts at that moment live in the table below. See
    // includes/services/service_uptime.php for how the two are read back.
    'status_incident_updates' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'incident_id'       => 'INT NOT NULL',
        'status_id'         => 'INT NULL',
        'comment'           => 'LONGTEXT NULL',
        'created_by_id'     => 'INT NULL',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'status_incident_update_services' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'update_id'         => 'INT NOT NULL',
        'service_id'        => 'INT NOT NULL',
        'impact_level_id'   => 'INT NULL',
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'status_incident_services' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'incident_id'       => 'INT NOT NULL',
        'service_id'        => 'INT NOT NULL',
        'impact_level_id'   => 'INT NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'cmdb_class_properties' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'class_id'          => 'INT NOT NULL',
        'property_key'      => 'VARCHAR(100) NOT NULL',
        'label'             => 'VARCHAR(150) NOT NULL',
        'property_type'     => 'VARCHAR(20) NOT NULL',
        'target_class_id'   => 'INT NULL',
        'is_required'       => 'TINYINT(1) NULL DEFAULT 0',
        // object_ref only: 1 = a failure of the referenced object affects the
        // object holding this field. Drives the blast-radius walk.
        'spreads_impact'    => 'TINYINT(1) NOT NULL DEFAULT 0',
        'display_order'     => 'INT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'cmdb_class_property_options' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'property_id'       => 'INT NOT NULL',
        'option_value'      => 'VARCHAR(255) NOT NULL',
        'colour'            => 'VARCHAR(7) NULL',
        'display_order'     => 'INT NULL DEFAULT 0',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'cmdb_relationship_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'verb'              => 'VARCHAR(100) NOT NULL',
        'inverse_verb'      => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(500) NULL',
        // none | to_from | from_to — whether a failure travels along this
        // relationship and which way. Defaults to 'none' so an upgraded
        // install spreads nothing until someone says it should.
        'impact_direction'  => "VARCHAR(10) NOT NULL DEFAULT 'none'",
        'display_order'     => 'INT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'cmdb_object_relationships' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'from_object_id'        => 'INT NOT NULL',
        'to_object_id'          => 'INT NOT NULL',
        'relationship_type_id'  => 'INT NOT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'ticket_cmdb_objects' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'             => 'INT NOT NULL',
        'cmdb_object_id'        => 'INT NOT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'created_by_analyst_id' => 'INT NULL',
    ],

    // Tickets ↔ assets (discussion #57). Two nullable creator columns because a
    // link can be made by an analyst or by a portal user, and they are different
    // tables. See freeitsm.sql for the full reasoning.
    'ticket_assets' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_id'             => 'INT NOT NULL',
        'asset_id'              => 'INT NOT NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'created_by_analyst_id' => 'INT NULL',
        'created_by_user_id'    => 'INT NULL',
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
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'network_diagram_nodes' => [
        'id'             => 'INT NOT NULL AUTO_INCREMENT',
        'diagram_id'     => 'INT NOT NULL',
        'cmdb_object_id' => 'INT NOT NULL',
        'x'              => 'INT NOT NULL DEFAULT 0',
        'y'              => 'INT NOT NULL DEFAULT 0',
        'size'           => "VARCHAR(20) NOT NULL DEFAULT 'medium'",
        'icon_override'  => 'VARCHAR(100) NULL',
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    'network_diagram_connectors' => [
        'id'                   => 'INT NOT NULL AUTO_INCREMENT',
        'diagram_id'           => 'INT NOT NULL',
        'from_node_id'         => 'INT NOT NULL',
        'to_node_id'           => 'INT NOT NULL',
        'cmdb_relationship_id' => 'INT NULL',
        'label'                => 'VARCHAR(255) NULL',
        'line_style'           => "VARCHAR(20) NULL DEFAULT 'solid'",
        'is_demo'           => 'TINYINT(1) NOT NULL DEFAULT 0',   // set by the demo data importer (#1297)
    ],

    // Search corpus — one row per searchable unit (ticket subject, email body,
    // note, and later attachment text). Fully derived: every row is rebuildable
    // from its source, so losing this table costs a reindex and nothing else.
    // The full-text indexes are NOT here — indexes come from the generated
    // includes/db_verify_indexes.php, which understands 'fulltext' as of #991.
    // See database/freeitsm.sql for why tenant_scope exists rather than a
    // nullable tenant_id alone: NULL means "the default company" on a ticket and
    // "every company" on a knowledge article.
    'search_documents' => [
        'id'               => 'BIGINT NOT NULL AUTO_INCREMENT',
        'source_type'      => 'VARCHAR(32) NOT NULL',
        'source_id'        => 'INT NOT NULL',
        'ticket_id'        => 'INT NULL',
        'tenant_id'        => 'INT NULL',
        'tenant_scope'     => "VARCHAR(16) NOT NULL DEFAULT 'company'",
        'is_internal'      => 'TINYINT(1) NOT NULL DEFAULT 0',
        'title'            => 'VARCHAR(500) NULL',
        'body'             => 'MEDIUMTEXT NULL',
        'source_datetime'  => 'DATETIME NULL',
        'indexed_datetime' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // War room — the fallback chat analysts use when Teams/Slack is unavailable.
    //
    // Every conversation is a row here, of one of four KINDS, so that messages
    // need exactly one foreign key instead of a nullable column per kind:
    //   all    — the one all-hands room. Always exists, always first.
    //   team   — one per team. team_id is UNIQUE and CASCADEs, so a team channel
    //            cannot be orphaned, duplicated, or renamed into a lie: its name
    //            is READ FROM `teams` at display time and never stored here.
    //   custom — somebody made it. This kind, and only this kind, has a lifecycle.
    //   dm     — a pair of analysts. `dm_key` is "<lower id>:<higher id>" and is
    //            UNIQUE, which is what stops two people opening a DM with each
    //            other simultaneously and getting one conversation each.
    //
    // created_by is NULLABLE (FK SET NULL): a channel must outlive the person who
    // opened it, or deleting a leaver would take the incident room with them.
    'warroom_channels' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'kind'              => "VARCHAR(10) NOT NULL DEFAULT 'custom'",
        'team_id'           => 'INT NULL',
        'dm_key'            => 'VARCHAR(41) NULL',
        'name'              => 'VARCHAR(120) NULL',
        'topic'             => 'VARCHAR(255) NULL',
        'is_private'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_by'        => 'INT NULL',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'archived_datetime' => 'DATETIME NULL',
    ],

    // Membership, for private custom channels and for DMs. Public custom channels
    // also carry rows for whoever joined, so a member count means the same thing
    // whichever kind it is. Team membership is NOT duplicated here — that lives in
    // `analyst_teams` and is read from there, so the two can never disagree.
    'warroom_channel_members' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'channel_id'       => 'INT NOT NULL',
        'analyst_id'       => 'INT NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // analyst_id is NULLABLE on purpose (FK is ON DELETE SET NULL, see
    // db_verify.php): deleting an analyst must not erase the conversation that
    // happened during an incident. Those rows render as "Former analyst".
    // channel_id is nullable ONLY so the column can be added to an installation
    // created before channels existed; db_verify backfills it immediately.
    //
    // edited/deleted are RECORDED, not hidden. This table is the record of what was
    // said during an incident, so a message that changed after the fact says so, and
    // a deleted one leaves a tombstone rather than a silent gap. The body and any
    // attachments really are destroyed on delete — the point is that somebody can
    // see a message was removed, not that its contents linger.
    'warroom_messages' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'channel_id'       => 'INT NULL',
        'analyst_id'       => 'INT NULL',
        'body'             => 'TEXT NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'is_bot'           => 'TINYINT(1) NOT NULL DEFAULT 0',
        'reply_to_id'      => 'INT NULL',
        'edited_datetime'  => 'DATETIME NULL',
        'deleted_datetime' => 'DATETIME NULL',
        'deleted_by'       => 'INT NULL',
    ],

    // ⚠️ THERE IS NO content_type COLUMN, AND THAT IS THE POINT. The type an
    // attachment is served as is derived from its extension against our own map
    // at serve time (attachmentServeRules, security finding F5). Storing what the
    // uploader claimed would create something for a future endpoint to trust by
    // mistake, so the temptation is removed rather than merely documented.
    // stored_name is the random name uploads.php generated; original_name is for
    // display and for the download filename only, and never touches the path.
    'warroom_attachments' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'message_id'       => 'INT NOT NULL',
        'stored_name'      => 'VARCHAR(100) NOT NULL',
        'original_name'    => 'VARCHAR(255) NOT NULL',
        'size_bytes'       => 'INT NOT NULL DEFAULT 0',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Who was named in a message. One row PER RECIPIENT — `@everyone` is expanded
    // at send time rather than stored as a flag, which keeps every query that asks
    // "what has my name on it" a simple equality instead of a union. It also makes
    // the record point-in-time correct: you notified the people who were entitled
    // to that channel then, not whoever is entitled to it now.
    //
    // ⚠️ There is NO read column. A mention counts as unread when its message is
    // newer than your `warroom_reads` marker for that channel — reusing the state
    // that already exists rather than adding a second one that can disagree with it.
    'warroom_mentions' => [
        'id'               => 'INT NOT NULL AUTO_INCREMENT',
        'message_id'       => 'INT NOT NULL',
        'analyst_id'       => 'INT NOT NULL',
        'created_datetime' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // How far each analyst has read in each channel, so the list can show what is
    // new. One row per analyst per channel, upserted, and the id only ever moves
    // forward — an out-of-order poll must not un-read a channel.
    'warroom_reads' => [
        'id'                   => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'           => 'INT NOT NULL',
        'channel_id'           => 'INT NOT NULL',
        'last_read_message_id' => 'INT NOT NULL DEFAULT 0',
        'updated_datetime'     => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    // Who is currently in the war room. One row per analyst, upserted by the
    // same poll that fetches messages — so presence costs no extra request and
    // the table can never grow beyond the number of analysts. Shape deliberately
    // mirrors `ticket_presence` (surrogate id + UNIQUE on the natural key), which
    // is the pattern collision detection already established. channel_id records
    // WHERE they are, which is how the sidebar separates "here" from "around".
    'warroom_presence' => [
        'id'         => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id' => 'INT NOT NULL',
        'channel_id' => 'INT NULL',
        'last_seen'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],
];
