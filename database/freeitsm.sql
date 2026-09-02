-- ============================================================
-- FreeITSM Database Schema (MySQL 8.0+)
-- ============================================================
-- Run this script against a fresh MySQL database to create
-- all tables, constraints, defaults, and the seed admin user.
--
-- Requires: MySQL 8.0+ with InnoDB engine
-- Charset:  utf8mb4 / utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
-- Core: Analysts & Organisation
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `analysts` (
    `id`                        INT NOT NULL AUTO_INCREMENT,
    `username`                  VARCHAR(50) NOT NULL,
    `password_hash`             VARCHAR(255) NOT NULL,
    `full_name`                 VARCHAR(100) NOT NULL,
    `email`                     VARCHAR(100) NOT NULL,
    `is_active`                 TINYINT(1) NULL DEFAULT 1,
    `job_title`                 VARCHAR(100) NULL,
    `department`                VARCHAR(100) NULL,
    `phone`                     VARCHAR(50) NULL,
    `mobile`                    VARCHAR(50) NULL,
    `created_datetime`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_datetime`       DATETIME NULL,
    `last_modified_datetime`    DATETIME NULL,
    `totp_secret`               VARCHAR(500) NULL,
    `totp_enabled`              TINYINT(1) NOT NULL DEFAULT 0,
    `trust_device_enabled`      TINYINT(1) NOT NULL DEFAULT 0,
    `password_changed_datetime` DATETIME NULL,
    `failed_login_count`        INT NOT NULL DEFAULT 0,
    `locked_until`              DATETIME NULL,
    `auth_provider_id`          INT NULL,
    `can_access_all_tenants`    TINYINT(1) NOT NULL DEFAULT 1,
    -- Only administrators may enter the System module (analyst/team/company mgmt,
    -- SSO, security, DB verify, etc.). New analysts default to non-admin.
    `is_admin`                  TINYINT(1) NOT NULL DEFAULT 0,
    -- Module access (issue #30). 1 = all modules; 0 = restricted to analyst_modules
    -- (+ team grants). New analysts default unrestricted.
    `can_access_all_modules`    TINYINT(1) NOT NULL DEFAULT 1,
    -- Force a password change before this analyst can do anything else. Set on the
    -- seeded `admin` account, whose admin/freeitsm credentials were otherwise
    -- permanent — nothing forced, warned about or nagged on the change.
    `must_change_password`      TINYINT(1) NOT NULL DEFAULT 0,
    -- MFA code-step throttling. Kept SEPARATE from failed_login_count/locked_until
    -- above on purpose: a successful password step clears those two, so an attacker
    -- who already holds a valid password never trips them. Only a correct MFA code
    -- clears these. See includes/mfa_throttle.php.
    `mfa_failed_count`          INT NOT NULL DEFAULT 0,
    `mfa_locked_until`          DATETIME NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_analysts_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Authentication providers (one row per configured sign-in method)
-- `protocol` selects the flavour:
--   'oidc' — OpenID Connect SSO (browser redirect to an IdP). Uses the
--            issuer_url / client_id / client_secret / scopes columns.
--   'ldap' — LDAP / Active Directory bind (user types their directory
--            password into our own login form; NOT single sign-on).
--            Uses the ldap_* columns below.
-- The two column groups are mutually exclusive; the unused group is left
-- empty. issuer_url/client_id stay NOT NULL by convention — LDAP rows store
-- '' in them rather than NULL, which reads unambiguously as "not applicable
-- to this protocol". (Not a limitation: db_verify can relax a column with a
-- probe-then-MODIFY block, as it does for users.email and emails.from_address.)
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `auth_providers` (
    `id`                     INT NOT NULL AUTO_INCREMENT,
    `display_name`           VARCHAR(100) NOT NULL,
    `protocol`               VARCHAR(20) NOT NULL DEFAULT 'oidc',
    `issuer_url`             VARCHAR(500) NOT NULL,
    `client_id`              VARCHAR(255) NOT NULL,
    `client_secret`          VARCHAR(500) NULL,
    `scopes`                 VARCHAR(255) NOT NULL DEFAULT 'openid email profile',
    -- --- LDAP / Active Directory (protocol = 'ldap') ---
    `ldap_host`              VARCHAR(255) NULL,
    `ldap_port`              INT NULL,
    `ldap_encryption`        VARCHAR(10) NULL,
    `ldap_bind_dn`           VARCHAR(255) NULL,
    `ldap_bind_password`     VARCHAR(500) NULL,
    `ldap_base_dn`           VARCHAR(255) NULL,
    `ldap_user_filter`       VARCHAR(500) NULL,
    `ldap_attr_username`     VARCHAR(64) NULL,
    `ldap_attr_email`        VARCHAR(64) NULL,
    `ldap_attr_name`         VARCHAR(64) NULL,
    `ldap_attr_guid`         VARCHAR(64) NULL,
    `ldap_group_base_dn`     VARCHAR(255) NULL,
    `ldap_group_filter`      VARCHAR(500) NULL,
    `ldap_analyst_group`     VARCHAR(255) NULL,
    `ldap_user_group`        VARCHAR(255) NULL,

    -- ---------------------------------------------------------------------
    -- Directory SYNC (slice 2). Distinct from sign-in above: sign-in asks the
    -- directory about ONE person who is standing there; sync enumerates
    -- everybody so they exist before anyone signs in — which is the entire
    -- point, since the people who hold equipment are largely the people who
    -- never log in.
    -- ---------------------------------------------------------------------
    `sync_enabled`           TINYINT(1) NOT NULL DEFAULT 0,
    -- Where to enumerate from. NULL falls back to ldap_base_dn: the sign-in
    -- subtree is usually the right one, but not always — you may authenticate
    -- against the whole directory and only want to IMPORT one OU.
    `sync_base_dn`           VARCHAR(255) NULL,
    -- The OU browser writes these: the branches ticked, and the branches
    -- carved back out of them. One DN per line. A ticked branch means the
    -- whole branch INCLUDING anything created under it later, which is the
    -- behaviour that makes a new department import on its own.
    -- ⚠️ Both NULL means "use sync_base_dn", which is the only state an
    -- upgraded install can be in — the fallback is not a nicety, it is what
    -- stops an upgrade silently importing nobody.
    `sync_ou_includes`       TEXT NULL,
    `sync_ou_excludes`       TEXT NULL,
    `sync_filter`            VARCHAR(500) NULL,
    -- What to do when somebody already exists here. 'adopt' attaches the
    -- directory identity to the existing record; 'flag' leaves them alone and
    -- records a conflict. ⚠️ Adopting sets auth_provider_id, which means their
    -- local portal password STOPS WORKING — that is why this is a choice.
    `sync_on_conflict`       VARCHAR(20) NOT NULL DEFAULT 'adopt',
    -- Consecutive misses before somebody is marked as left. 0 disables
    -- deactivation entirely, for installs that would rather do it by hand.
    `sync_deactivate_after`  INT NOT NULL DEFAULT 3,
    -- THE SANITY BRAKE. If a run finds this many percent fewer people than the
    -- last good run, it stops and changes nothing. A typo in a base DN, a
    -- service account quietly losing read rights, or a directory being slow
    -- would otherwise deactivate an entire company in one pass. 0 disables it,
    -- which is a decision somebody should have to make deliberately.
    `sync_brake_percent`     INT NOT NULL DEFAULT 20,
    -- Attribute names for the person fields. Defaults are Active Directory's;
    -- OpenLDAP and others differ, which is why these are configurable at all.
    `ldap_attr_job_title`    VARCHAR(64) NULL,
    `ldap_attr_department`   VARCHAR(64) NULL,
    `ldap_attr_office`       VARCHAR(64) NULL,
    `ldap_attr_phone`        VARCHAR(64) NULL,
    `ldap_attr_mobile`       VARCHAR(64) NULL,
    `ldap_attr_employee_id`  VARCHAR(64) NULL,
    -- The manager attribute holds a DN, not a name, so it is resolved to a
    -- person in a second pass once everybody exists.
    `ldap_attr_manager`      VARCHAR(64) NULL,
    `sync_last_run_datetime` DATETIME NULL,
    -- People found by the last SUCCESSFUL run. The number the brake compares
    -- against; NULL means "no baseline yet", so a first run is never braked.
    `sync_last_count`        INT NULL,

    `enabled`                TINYINT(1) NOT NULL DEFAULT 1,
    `auto_create_users`      TINYINT(1) NOT NULL DEFAULT 0,
    `require_verified_email` TINYINT(1) NOT NULL DEFAULT 0,
    `default_modules`        VARCHAR(500) NULL,
    `sort_order`             INT NOT NULL DEFAULT 0,
    `tenant_id`              INT NULL,
    `created_datetime`       DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `last_modified_datetime` DATETIME NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- auth_providers.tenant_id => which client company owns this IdP (NULL = a
-- global/MSP-internal provider, e.g. analyst SSO or a single-company install).
-- FK added after `tenants` is defined (further down).

-- Links an analyst to their identity at a given provider (the IdP `sub` claim).
CREATE TABLE IF NOT EXISTS `analyst_sso_identities` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `analyst_id`          INT NOT NULL,
    `provider_id`         INT NOT NULL,
    `subject`             VARCHAR(255) NOT NULL,
    `email`               VARCHAR(100) NULL,
    `linked_datetime`     DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_datetime` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sso_provider_subject` (`provider_id`, `subject`),
    UNIQUE KEY `uq_sso_provider_analyst` (`provider_id`, `analyst_id`),
    CONSTRAINT `fk_sso_identity_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sso_identity_provider` FOREIGN KEY (`provider_id`) REFERENCES `auth_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- analysts.auth_provider_id => the IdP a user is assigned to (NULL = local password).
-- Added here (not inline above) because `auth_providers` is defined after `analysts`.
ALTER TABLE `analysts`
    ADD CONSTRAINT `fk_analysts_auth_provider` FOREIGN KEY (`auth_provider_id`) REFERENCES `auth_providers` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `departments` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NULL DEFAULT 1,
    `display_order`     INT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_departments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_preferences` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `preference_key`    VARCHAR(100) NOT NULL,
    `preference_value`  TEXT NULL,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_pref` (`analyst_id`, `preference_key`),
    CONSTRAINT `fk_user_pref_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Handover document templates (discussion #56).
--
-- `blocks` is a JSON array describing which sections appear, in what order, and
-- what their editable text says. It is deliberately a document rather than a set
-- of columns: the shape is a LIST OF BLOCKS, and modelling that relationally
-- would mean a second table and a join to render one page.
--
-- ⚠️ The JSON is validated against a fixed block catalogue on save — see
-- HandoverTemplates::sanitiseBlocks(). Nothing arbitrary is ever stored, so
-- rendering never has to trust it.
CREATE TABLE IF NOT EXISTS `asset_handover_templates` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(120) NOT NULL,
    `blocks`           LONGTEXT NULL,          -- JSON, see above
    `is_default`       TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- The render path looks up the default template on every document produced.
    KEY `ix_aht_default` (`is_default`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app notifications (discussion #55). One row per thing an analyst is told
-- about. Rows COALESCE while unread: three changes to the same ticket bump
-- event_count on one row rather than making three, which is what stops the bell
-- becoming unusable for anyone carrying a real ticket load.
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `event_type`        VARCHAR(64) NOT NULL,     -- 'ticket.assigned', 'ticket.reply_received', …
    `entity_type`       VARCHAR(32) NOT NULL,     -- 'ticket'
    `entity_id`         INT NOT NULL,
    `entity_ref`        VARCHAR(64) NULL,         -- ticket number, for display and the deep link
    `title`             VARCHAR(255) NULL,        -- the ticket subject at the time
    `body`              VARCHAR(500) NULL,        -- 'Sam Cover replied'
    `actor_name`        VARCHAR(100) NULL,        -- who caused it, NULL for system events
    `event_count`       INT NOT NULL DEFAULT 1,   -- >1 once coalesced
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `read_datetime`     DATETIME NULL,            -- NULL = unread; the badge counts these
    PRIMARY KEY (`id`),
    -- The unread index serves the badge, which is polled by every open tab; the
    -- coalesce index is hit on every event written.
    KEY `ix_notif_unread` (`analyst_id`, `read_datetime`, `updated_datetime`),
    KEY `ix_notif_coalesce` (`analyst_id`, `entity_type`, `entity_id`, `read_datetime`),
    CONSTRAINT `fk_notif_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teams` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(500) NULL,
    `display_order`     INT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NULL DEFAULT 1,
    -- Team company access (multi-tenant). Team grants are ADDITIVE to an
    -- analyst's own access: an analyst can reach a company if their own grants
    -- OR any team they're in grants it. Unlike analysts (which default to
    -- all-access so N=1 installs stay invisible), a team defaults to granting
    -- NOTHING (0) — else every existing team would silently hand all-company
    -- access to its members on upgrade. When 0, team_tenant_access lists the
    -- specific companies the team grants; when 1, the team grants every company.
    `can_access_all_tenants` TINYINT(1) NOT NULL DEFAULT 0,
    -- Team module access (issue #30). Defaults to 0 (grants no modules) for the same
    -- reason — a team must be explicitly granted modules; team_modules lists them.
    `can_access_all_modules` TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analyst_teams` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `team_id`           INT NOT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_analyst_team` (`analyst_id`, `team_id`),
    CONSTRAINT `fk_analyst_teams_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_analyst_teams_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `department_teams` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `department_id`     INT NOT NULL,
    `team_id`           INT NOT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_department_team` (`department_id`, `team_id`),
    CONSTRAINT `fk_department_teams_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_department_teams_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analyst_modules` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `analyst_id`    INT NOT NULL,
    `module_key`    VARCHAR(50) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_analyst_module` (`analyst_id`, `module_key`),
    CONSTRAINT `fk_analyst_modules_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-team module grants (issue #30) — the team twin of analyst_modules.
CREATE TABLE IF NOT EXISTS `team_modules` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `team_id`       INT NOT NULL,
    `module_key`    VARCHAR(50) NOT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_team_module` (`team_id`, `module_key`),
    CONSTRAINT `fk_team_modules_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- RBAC (Layer 2): per-module SETTINGS permissions.
-- Module access (above) decides which modules you can ENTER. These tables decide
-- whether you can also ADMINISTER a module's settings once in. Deny by default;
-- System administrators (analysts.is_admin) bypass the whole layer. Capability
-- keys are '<module>.<action>' and validated against the code registry in
-- includes/rbac.php — the DB never holds a capability the code doesn't know.
-- See docs/design/rbac.md.
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `rbac_roles` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(500) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_id`     INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The capabilities a role grants. capability_key is '<module>.<action>'.
CREATE TABLE IF NOT EXISTS `rbac_role_capabilities` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `role_id`           INT NOT NULL,
    `capability_key`    VARCHAR(100) NOT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rrc_role_capability` (`role_id`, `capability_key`),
    CONSTRAINT `fk_rrc_role` FOREIGN KEY (`role_id`) REFERENCES `rbac_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles assigned to an analyst.
CREATE TABLE IF NOT EXISTS `rbac_analyst_roles` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `role_id`           INT NOT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rar_analyst_role` (`analyst_id`, `role_id`),
    CONSTRAINT `fk_rar_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rar_role` FOREIGN KEY (`role_id`) REFERENCES `rbac_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles assigned to a team — every member inherits (mirrors team_modules).
CREATE TABLE IF NOT EXISTS `rbac_team_roles` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `team_id`           INT NOT NULL,
    `role_id`           INT NOT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rtr_team_role` (`team_id`, `role_id`),
    CONSTRAINT `fk_rtr_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rtr_role` FOREIGN KEY (`role_id`) REFERENCES `rbac_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Tickets
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ticket_types` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NULL DEFAULT 1,
    `display_order`     INT NULL DEFAULT 0,
    -- Multi-tenancy: NULL = global default type (shared by every company); set =
    -- a type a company added for itself. Existing rows stay NULL, so a
    -- single-company install is unaffected. (Config meaning of tenant_id: NULL =
    -- global default — unlike scoped data tables where NULL means "unrouted".)
    `tenant_id`         INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    -- Per-scope name uniqueness (a company may hold a type whose name matches a
    -- global default). Global-name dedup is enforced in the API, since NULL
    -- tenant_id rows aren't de-duped by a unique key.
    UNIQUE KEY `uq_ticket_types_tenant_name` (`tenant_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_origins` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `display_order`     INT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NULL DEFAULT 1,
    -- Multi-tenancy: NULL = global default origin; set = a company's own.
    `tenant_id`         INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Watchtower settings. Two tables rather than flags on the status rows, because
-- "show this on my dashboard" is a fact about the DASHBOARD, not about the
-- status — the status's own facts (is_closed, is_default) are used by the SLA
-- engine and everything else, and must not be mixed up with one view's taste.
--
-- analyst_id 0 = the installation's setting. Real analyst ids are reserved for
-- per-person overrides later; storing it now means adding those needs no
-- migration. (0 rather than NULL so the unique key actually de-duplicates.)
CREATE TABLE IF NOT EXISTS `watchtower_items` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `analyst_id`    INT NOT NULL DEFAULT 0,
    -- 'card.tickets', 'tickets.by_status', 'tickets.high_priority', …
    `item_key`      VARCHAR(60) NOT NULL,
    `is_visible`    TINYINT(1) NOT NULL DEFAULT 1,
    -- 0 = follow the built-in default. Distinguishes "not configured" from
    -- "configured to nothing", which a bare list of members cannot express —
    -- and getting that wrong would make an empty selection silently mean "all".
    `is_customised` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_watchtower_items` (`analyst_id`, `item_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which statuses / priorities feed one Watchtower item. Rows, not a flag on the
-- status, so an item can hold a SET (the "high priority tickets" line is one
-- number over several priorities) and so each member can carry its own severity
-- later without a schema change.
CREATE TABLE IF NOT EXISTS `watchtower_item_members` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `analyst_id`  INT NOT NULL DEFAULT 0,
    `item_key`    VARCHAR(60) NOT NULL,
    -- 'ticket_status' | 'ticket_priority' | 'task_status' | 'mc_status'.
    -- Deliberately no foreign key: the target table varies by row, so no FK
    -- could cover it. Reads join the real table, which drops members whose
    -- status has since been deleted rather than counting a ghost.
    `entity_type` VARCHAR(30) NOT NULL,
    `entity_id`   INT NOT NULL,
    -- Reserved: 'red' / 'amber' per member, for when one line becomes several.
    `severity`    VARCHAR(10) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_watchtower_members` (`analyst_id`, `item_key`, `entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_prefixes` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `prefix`        VARCHAR(3) NOT NULL,
    `description`   VARCHAR(100) NULL,
    `department_id` INT NULL,
    `is_default`    TINYINT(1) NULL DEFAULT 0,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_prefixes_prefix` (`prefix`),
    CONSTRAINT `fk_prefixes_departments` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id`              INT NOT NULL AUTO_INCREMENT,
    -- NULL because a directory (LDAP) user may genuinely have no mailbox —
    -- warehouse and shop-floor staff are never given one (GitHub #47). The
    -- UNIQUE index below still applies: MySQL permits many NULLs in a unique
    -- index, so any number of mailbox-less people coexist while real addresses
    -- stay unique.
    --
    -- ⚠️ An absent address MUST be stored as NULL, never ''. The analyst table
    -- gets away with '' because analysts.email is not unique; here the second
    -- empty string would collide.
    `email`           VARCHAR(255) NULL,
    -- What a directory user types to sign in when they have no email address.
    -- NULL for every local/registered account.
    `username`        VARCHAR(50) NULL,
    `display_name`    VARCHAR(255) NULL,
    `preferred_name`  VARCHAR(100) NULL,
    `password_hash`   VARCHAR(255) NULL,
    `totp_secret`     VARCHAR(500) NULL,
    `totp_enabled`    TINYINT(1) NOT NULL DEFAULT 0,
    -- The portal twin of the analyst MFA throttle: the portal offers the same
    -- second factor, so it had the same session-scoped counter and the same hole.
    -- See includes/mfa_throttle.php.
    `mfa_failed_count` INT NOT NULL DEFAULT 0,
    `mfa_locked_until` DATETIME NULL,
    `auth_provider_id` INT NULL,
    -- The portal user's chosen colour palette ('default' | 'dark'), matching the
    -- ids in Theme::THEMES. NULL = follow the install default. Analysts keep
    -- theirs in `user_preferences` (keyed by analyst_id), which portal users
    -- can't use — hence a column here rather than a row there.
    `theme_preference` VARCHAR(32) NULL,
    -- The company this requester belongs to. NULL = unknown, and a ticket they
    -- raise lands in triage for an analyst to route — the same meaning NULL has
    -- on `tickets`. Set explicitly (admin, or pre-filled from the email domain
    -- at registration); deliberately NOT re-derived at ticket time, so editing
    -- a company's domains never silently re-files an existing person.
    `tenant_id`       INT NULL,
    `created_at`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,

    -- ---------------------------------------------------------------------
    -- The person, as opposed to the login (directory sync slice 1).
    --
    -- Everything above this line describes how somebody authenticates. These
    -- describe who they are, which is what an asset register, an approval chain
    -- and a service desk actually need. They are filled in by hand today and by
    -- a directory sync later, so none of them is required.
    -- ---------------------------------------------------------------------

    -- Has this person left? NEVER delete somebody who has: assets, tickets and
    -- handover documents all hang off users.id, and deleting the row takes the
    -- history of who had what with it. Defaults to 1 so every existing row —
    -- and every future self-registration — is active without a migration step.
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    -- When they were deactivated, so "who left this month, and what are they
    -- still holding" is answerable. NULL while active.
    `deactivated_datetime` DATETIME NULL,

    `job_title`       VARCHAR(150) NULL,
    `department`      VARCHAR(150) NULL,
    -- Where they sit. Worth more than it looks: the ticket asset-picker searches
    -- on an asset's location, and almost nobody fills that in by hand. A
    -- directory usually knows (physicalDeliveryOfficeName) and never forgets.
    `office`          VARCHAR(150) NULL,
    `phone`           VARCHAR(50) NULL,
    `mobile`          VARCHAR(50) NULL,
    -- Payroll/HR number. The join key when somebody reconciles against a system
    -- that has never heard of an email address.
    `employee_id`     VARCHAR(64) NULL,
    -- Self-referencing: this person's manager, as another row in this table.
    -- Wanted before sync existed — catalogue-request approvals need a chain to
    -- route along, and a directory hands you the org chart for free.
    `manager_id`      INT NULL,

    -- What the DIRECTORY calls them, which is not the same fact as what they
    -- type into the portal. sAMAccountName is unique only within one directory,
    -- while `username` above is unique across the whole install — so two
    -- companies can each legitimately have a `smithj`. Keeping them apart means
    -- sync stores the real directory name faithfully and never has to mangle it;
    -- `username` is only populated for people who actually sign in, which most
    -- mailbox-less asset holders never do.
    `directory_username` VARCHAR(255) NULL,
    -- Is this record maintained by a directory? Deliberately explicit rather
    -- than inferred from auth_provider_id: an account can be pinned to a
    -- provider for SIGN-IN without being owned by a sync, and the difference
    -- decides whether a field is editable in the UI.
    `is_managed`      TINYINT(1) NOT NULL DEFAULT 0,
    -- Last time a sync actually saw this person in the source. The basis for
    -- "missing for N runs" — a single absence is noise, three is a fact.
    `last_seen_in_source` DATETIME NULL,
    -- How many CONSECUTIVE sync runs have failed to find this person. Missing
    -- once is noise -- a slow directory, a filter being edited, a replica that
    -- had not caught up. Missing repeatedly is a fact. Nobody is deactivated
    -- until this passes the provider's threshold, and any sighting resets it.
    `sync_missed_count` INT NOT NULL DEFAULT 0,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    -- Same rationale as the email index: UNIQUE so two directory users can't
    -- share a sign-in name, nullable so the many local accounts that have no
    -- username don't fight over a single NULL.
    UNIQUE KEY `uq_users_username` (`username`),
    -- Per PROVIDER, not global — that is the whole point of the column. Two
    -- directories may each contain a `smithj`; the same directory may not.
    UNIQUE KEY `uq_users_dir_username` (`auth_provider_id`, `directory_username`),
    KEY `idx_users_tenant` (`tenant_id`),
    KEY `idx_users_manager` (`manager_id`),
    KEY `idx_users_active` (`is_active`),
    KEY `idx_users_department` (`department`),
    -- SET NULL, not CASCADE: deleting a manager must never delete their reports.
    CONSTRAINT `fk_users_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Links a self-service requester to their identity at a given provider (the IdP
-- `sub` claim). Mirrors analyst_sso_identities, one layer down for the portal.
CREATE TABLE IF NOT EXISTS `user_sso_identities` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `user_id`             INT NOT NULL,
    `provider_id`         INT NOT NULL,
    `subject`             VARCHAR(255) NOT NULL,
    `email`               VARCHAR(255) NULL,
    `linked_datetime`     DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_datetime` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_sso_provider_subject` (`provider_id`, `subject`),
    UNIQUE KEY `uq_user_sso_provider_user` (`provider_id`, `user_id`),
    CONSTRAINT `fk_user_sso_identity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_sso_identity_provider` FOREIGN KEY (`provider_id`) REFERENCES `auth_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pending self-service registrations awaiting email confirmation. A row holds
-- the *pending* password hash; the users row's password is only set once the
-- emailed token is confirmed, so registering an email you don't control can
-- never take over an existing passwordless account. Token stored as a SHA-256
-- hash (the raw token only ever lives in the email link).
CREATE TABLE IF NOT EXISTS `user_verification_tokens` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`         VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name`  VARCHAR(255) NULL,
    `token_hash`    CHAR(64) NOT NULL,
    `expires_at`    DATETIME NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uvt_token` (`token_hash`),
    KEY `ix_uvt_email` (`email`),
    KEY `ix_uvt_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users.auth_provider_id => the IdP a requester is assigned to (NULL = local
-- password). Added after auth_providers is defined.
ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_auth_provider` FOREIGN KEY (`auth_provider_id`) REFERENCES `auth_providers` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `ticket_statuses` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `is_closed`         TINYINT(1) NOT NULL DEFAULT 0,
    `colour`            VARCHAR(20) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `pauses_sla`        TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_statuses_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed defaults: On Hold and Awaiting Response pause the SLA clock by default.
INSERT IGNORE INTO `ticket_statuses` (`name`, `is_closed`, `colour`, `is_default`, `display_order`, `pauses_sla`) VALUES
    ('Open',              0, '#2563eb', 1, 10, 0),
    ('In Progress',       0, '#9333ea', 0, 20, 0),
    ('On Hold',           0, '#f59e0b', 0, 30, 1),
    ('Awaiting Response', 0, '#0891b2', 0, 40, 1),
    ('Closed',            1, '#6b7280', 0, 50, 0);

CREATE TABLE IF NOT EXISTS `ticket_priorities` (
    `id`                      INT NOT NULL AUTO_INCREMENT,
    `name`                    VARCHAR(50) NOT NULL,
    `colour`                  VARCHAR(20) NULL,
    `is_default`              TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`           INT NOT NULL DEFAULT 0,
    `is_active`               TINYINT(1) NOT NULL DEFAULT 1,
    `sla_response_minutes`    INT NULL,
    `sla_resolution_minutes`  INT NULL,
    `sla_calendar_id`         INT NULL,
    `created_datetime`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_priorities_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ticket_priorities` (`name`, `colour`, `is_default`, `display_order`) VALUES
    ('Low',      '#16a34a', 0, 10),
    ('Normal',   '#2563eb', 1, 20),
    ('High',     '#f59e0b', 0, 30),
    ('Critical', '#dc2626', 0, 40),
    ('Urgent',   '#b91c1c', 0, 50);

-- ----------------------------------------------------------
-- SLA (Service Level Agreements) — see docs/sla.md for design
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `sla_calendars` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `timezone`          VARCHAR(50) NOT NULL DEFAULT 'Europe/London',
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sla_calendars_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly working-hours pattern for a calendar. One row per (calendar, weekday).
-- weekday: 1=Mon, 2=Tue, ..., 7=Sun (ISO 8601). Absence of a row = closed.
CREATE TABLE IF NOT EXISTS `sla_calendar_hours` (
    `id`           INT NOT NULL AUTO_INCREMENT,
    `calendar_id`  INT NOT NULL,
    `weekday`      TINYINT NOT NULL,
    `start_time`   TIME NOT NULL,
    `end_time`     TIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sla_calendar_hours` (`calendar_id`, `weekday`),
    CONSTRAINT `fk_sla_hours_calendar` FOREIGN KEY (`calendar_id`) REFERENCES `sla_calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-calendar holiday list. Dates that override the weekly working pattern.
CREATE TABLE IF NOT EXISTS `sla_calendar_holidays` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `calendar_id`   INT NOT NULL,
    `holiday_date`  DATE NOT NULL,
    `name`          VARCHAR(100) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sla_holidays` (`calendar_id`, `holiday_date`),
    CONSTRAINT `fk_sla_holidays_calendar` FOREIGN KEY (`calendar_id`) REFERENCES `sla_calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a default Mon-Fri 09:00-17:00 calendar in Europe/London. db_verify.php
-- handles seeding for existing installs.
INSERT IGNORE INTO `sla_calendars` (`id`, `name`, `timezone`, `is_default`) VALUES
    (1, 'Default Business Hours', 'Europe/London', 1);

INSERT IGNORE INTO `sla_calendar_hours` (`calendar_id`, `weekday`, `start_time`, `end_time`) VALUES
    (1, 1, '09:00:00', '17:00:00'),
    (1, 2, '09:00:00', '17:00:00'),
    (1, 3, '09:00:00', '17:00:00'),
    (1, 4, '09:00:00', '17:00:00'),
    (1, 5, '09:00:00', '17:00:00');

-- SLA breach notification rules. department_id NULL = default rule applied when
-- no per-department rule matches for the same (trigger_type, target_type).
-- trigger_type 'warning' = approaching breach (>= sla_warning_threshold_percent),
-- 'breach' = target exceeded. target_type 'both' applies to response and resolution.
CREATE TABLE IF NOT EXISTS `sla_notification_rules` (
    `id`                       INT NOT NULL AUTO_INCREMENT,
    `department_id`            INT NULL,
    `trigger_type`             ENUM('warning','breach') NOT NULL,
    `target_type`              ENUM('response','resolution','both') NOT NULL DEFAULT 'both',
    `notify_assignee`          TINYINT(1) NOT NULL DEFAULT 0,
    `notify_department_teams`  TINYINT(1) NOT NULL DEFAULT 0,
    `notify_analyst_id`        INT NULL,
    `notify_emails`            TEXT NULL,
    `is_active`                TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_sla_notif_rule_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sla_notif_rule_analyst` FOREIGN KEY (`notify_analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dedup log so the cron worker doesn't re-send the same notification on every tick.
-- One row per (ticket, target, trigger) — once a warning fires for a ticket's
-- response SLA, the next warning for that ticket+target won't fire.
CREATE TABLE IF NOT EXISTS `sla_notifications_sent` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `ticket_id`      INT NOT NULL,
    `target_type`    ENUM('response','resolution') NOT NULL,
    `trigger_type`   ENUM('warning','breach') NOT NULL,
    `sent_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `recipients`     TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sla_notif_sent` (`ticket_id`, `target_type`, `trigger_type`),
    CONSTRAINT `fk_sla_notif_sent_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SLA breach-check cron audit log. One row per invocation (CLI or HTTP),
-- including rejected ones (rate-limited / auth-failed) so the rate-limit
-- check and the security audit both have the same source of truth.
-- Pruned by the cron worker itself based on sla_cron_log_retention_days.
CREATE TABLE IF NOT EXISTS `sla_cron_runs` (
    `id`              INT NOT NULL AUTO_INCREMENT,
    `started_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at`        DATETIME NULL,
    `duration_ms`     INT NULL,
    `invocation`      ENUM('cli','http') NOT NULL,
    `client_ip`       VARCHAR(45) NULL,
    `outcome`         ENUM('ok','auth_failed','rate_limited','error','config_missing') NOT NULL,
    `sent_count`      INT NULL DEFAULT 0,
    `skipped_count`   INT NULL DEFAULT 0,
    `error_count`     INT NULL DEFAULT 0,
    `notes`           TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sla_cron_started` (`started_at`),
    KEY `idx_sla_cron_ip_started` (`client_ip`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Ticket numbering (GH discussion #71).
--
-- The format an install issues is configurable, so two things have to be true
-- forever: a number must never be issued twice, and a number that has EVER been
-- issued must keep resolving to its ticket.
-- ============================================================================

-- One row per sequence. Which sequences exist depends on the settings: one
-- globally, or one per ticket type, or per company, and optionally a fresh one
-- each year or month.
--
-- 🔑 The reset period is part of the KEY rather than a stored date. A yearly
-- reset is simply a different counter each year, so nothing has to notice
-- midnight on 31 December and no job has to zero anything.
--
-- ⚠️ No AUTO_INCREMENT id, deliberately: `counter_key` is the primary key so
-- the INSERT … ON DUPLICATE KEY UPDATE … LAST_INSERT_ID(next_value + 1) trick
-- reads and increments in ONE statement. A SELECT followed by an UPDATE would
-- race, and the collision would only show up under load.
CREATE TABLE IF NOT EXISTS `ticket_number_counters` (
    `counter_key`       VARCHAR(64) NOT NULL,
    `next_value`        BIGINT NOT NULL DEFAULT 1,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`counter_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every number a ticket has ever had, other than its current one.
--
-- 🔴 THIS IS WHAT MAKES RENUMBERING SAFE, and it is not optional. Every
-- notification FreeITSM has ever sent carries [SDREF:<number>] in its subject,
-- and those emails sit in customers' inboxes forever. Renumber a ticket without
-- this and a reply to any older email matches nothing: it silently becomes a
-- new ticket. No error, no warning, across the whole estate at once.
--
-- Exactly the same principle the ticket MERGE code already relies on — an old
-- identifier keeps working because the emails quoting it do.
--
-- ⚠️ A retired number is also never handed to a DIFFERENT ticket: the uniqueness
-- check reads this table as well as `tickets`. Reusing one would route somebody's
-- reply onto a stranger's ticket, which is worse than not matching at all.
CREATE TABLE IF NOT EXISTS `ticket_number_history` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `ticket_id`         INT NOT NULL,
    `ticket_number`     VARCHAR(50) NOT NULL,
    -- Why it changed: 'renumber' today; a future migration tool can say its own.
    `reason`            VARCHAR(30) NOT NULL DEFAULT 'renumber',
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- The lookup that runs on every inbound email, and the guarantee that one
    -- retired number cannot be recorded twice.
    UNIQUE KEY `uq_tnh_number` (`ticket_number`),
    KEY `ix_tnh_ticket` (`ticket_id`)
    -- fk_tnh_ticket (ticket_id -> tickets.id, CASCADE) is added in db_verify.php:
    -- the `tickets` table is defined LATER in this file, so the FK cannot be
    -- inline here. Same reason as fk_assets_supplier.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tickets` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `tenant_id`             INT NULL,
    `ticket_number`         VARCHAR(50) NOT NULL,
    `subject`               VARCHAR(500) NOT NULL,
    `status_id`             INT NULL,
    `priority_id`           INT NULL,
    `department_id`         INT NULL,
    `ticket_type_id`        INT NULL,
    `assigned_analyst_id`   INT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `closed_datetime`       DATETIME NULL,
    `origin_id`             INT NULL,
    `first_time_fix`        TINYINT(1) NULL,
    `it_training_provided`  TINYINT(1) NULL,
    `user_id`               INT NULL,
    `owner_id`              INT NULL,
    `work_start_datetime`   DATETIME NULL,
    -- When the scheduled work is expected to finish. NULL on rows scheduled before
    -- this existed, and on those a reader applies the one-hour default rather than
    -- treating the row as broken — nothing is backfilled.
    -- ⚠️ The UI asks for a DURATION, never an end time: an end that precedes its
    -- start is then not expressible, so there is no such error to validate or
    -- report. This column is the computed result, stored so consumers (the
    -- calendar, and Outlook via Graph) read two datetimes and do not care how it
    -- was entered. Mirrors `changes`, which has carried both since it shipped.
    `work_end_datetime`     DATETIME NULL,
    -- "Sometime on Tuesday" rather than a slot. Same convention as
    -- calendar_events.all_day: the DATE part is what matters, and a consumer that
    -- ignores this flag still sees a sane 00:00–23:59 block.
    `work_all_day`          TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_datetime`      DATETIME NULL,
    `deleted_by`            INT NULL,
    -- Messaging channels (WhatsApp etc.): when the customer last messaged in. Drives
    -- the provider 24h service window — outside it, only template replies are allowed.
    `last_inbound_at`       DATETIME NULL,
    -- Set when this ticket has been merged AWAY into another. NULL = live.
    -- The banner, search redirect and inbound-email redirect key off this column,
    -- never off a status name (statuses are user-configurable).
    `merged_into_id`        INT NULL,
    -- Snooze (#933). "Asleep" is `snoozed_until > UTC_TIMESTAMP()` and nothing else,
    -- so a ticket returns to the queue on time with no cron involved; a past value
    -- just means it has already woken.
    `snoozed_until`         DATETIME NULL,
    `snoozed_at`            DATETIME NULL,
    `snoozed_by`            INT NULL,
    `snooze_reason`         VARCHAR(255) NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tickets_number` (`ticket_number`),
    KEY `ix_tickets_merged_into_id` (`merged_into_id`),
    KEY `ix_tickets_snoozed_until` (`snoozed_until`),
    KEY `ix_tickets_status_id` (`status_id`),
    KEY `ix_tickets_priority_id` (`priority_id`),
    KEY `ix_tickets_assigned_analyst_id` (`assigned_analyst_id`),
    KEY `ix_tickets_department_id` (`department_id`),
    KEY `ix_tickets_created_datetime` (`created_datetime`),
    KEY `ix_tickets_tenant_id` (`tenant_id`),
    KEY `ix_tickets_deleted_datetime` (`deleted_datetime`),
    CONSTRAINT `fk_tickets_analysts` FOREIGN KEY (`assigned_analyst_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_tickets_departments` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
    CONSTRAINT `fk_tickets_origin` FOREIGN KEY (`origin_id`) REFERENCES `ticket_origins` (`id`),
    CONSTRAINT `fk_tickets_ticket_types` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types` (`id`),
    CONSTRAINT `fk_tickets_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_tickets_status` FOREIGN KEY (`status_id`) REFERENCES `ticket_statuses` (`id`),
    CONSTRAINT `fk_tickets_priority` FOREIGN KEY (`priority_id`) REFERENCES `ticket_priorities` (`id`),
    CONSTRAINT `fk_tickets_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
    -- Self-reference. ON DELETE SET NULL, not CASCADE: hard-deleting the surviving
    -- ticket must never take the merged-away ones with it — they would be the only
    -- remaining record of the conversation.
    CONSTRAINT `fk_tickets_merged_into` FOREIGN KEY (`merged_into_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Collision detection (#934). Who has which ticket open right now, so two
-- analysts don't answer the same customer twice. Ephemeral: one row per
-- (ticket, analyst), refreshed by a heartbeat, ignored once `last_seen` goes
-- stale, and purged opportunistically. Nothing here is an audit trail — rows
-- are overwritten and deleted freely, so CASCADE on both parents is right.
-- Must follow `tickets` and `analysts`: it points at both.
CREATE TABLE IF NOT EXISTS `ticket_presence` (
    `id`           INT NOT NULL AUTO_INCREMENT,
    `ticket_id`    INT NOT NULL,
    `analyst_id`   INT NOT NULL,
    `last_seen`    DATETIME NULL,
    `is_composing` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    -- One row per person per ticket: the heartbeat is an upsert onto this key,
    -- so a long-open ticket can never accumulate rows.
    UNIQUE KEY `uq_ticket_presence` (`ticket_id`, `analyst_id`),
    KEY `ix_ticket_presence_last_seen` (`last_seen`),
    CONSTRAINT `fk_ticket_presence_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_presence_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One split: messages moved OUT of a ticket into a new one. The mirror of
-- ticket_merges. Note the asymmetry: a split creates a reference nobody has ever
-- seen, so unlike a merge there is nothing to redirect and both tickets stay live.
-- message_count is denormalised because it cannot be recomputed once those messages
-- move again.
CREATE TABLE IF NOT EXISTS `ticket_splits` (
    `id`                   INT NOT NULL AUTO_INCREMENT,
    `source_ticket_id`     INT NOT NULL,
    `source_ticket_number` VARCHAR(50) NULL,
    `new_ticket_id`        INT NOT NULL,
    `new_ticket_number`    VARCHAR(50) NULL,
    `message_count`        INT NOT NULL DEFAULT 0,
    -- EXACTLY which messages moved (JSON array of email ids). A count alone cannot be
    -- undone — you would have to guess which rows to send back. Text rather than a
    -- child table: read whole, never joined, and an FK would CASCADE the record away
    -- exactly when you most want to know what happened.
    `moved_email_ids`      TEXT NULL,
    -- The marker left in the original thread, so an undo can remove it.
    `marker_email_id`      INT NULL,
    `undone_datetime`      DATETIME NULL,
    `undone_by_id`         INT NULL,
    `split_by_id`          INT NULL,
    `split_datetime`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_ticket_splits_source` (`source_ticket_id`),
    KEY `ix_ticket_splits_new` (`new_ticket_id`),
    CONSTRAINT `fk_ticket_splits_source` FOREIGN KEY (`source_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_splits_new` FOREIGN KEY (`new_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_splits_analyst` FOREIGN KEY (`split_by_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One merge: which ticket was folded away, and into what. Never deleted — this is
-- what answers "whatever happened to ABC?" and what redirects inbound email still
-- quoting the old [SDREF:ABC-...] from notifications sent before the merge.
-- source_ticket_number is a deliberate snapshot so the log reads without a join.
-- reference_mode/originals_mode record the policy AS IT WAS at the time.
CREATE TABLE IF NOT EXISTS `ticket_merges` (
    `id`                   INT NOT NULL AUTO_INCREMENT,
    `source_ticket_id`     INT NOT NULL,
    `source_ticket_number` VARCHAR(50) NULL,
    `target_ticket_id`     INT NOT NULL,
    `reference_mode`       VARCHAR(20) NOT NULL DEFAULT 'survivor',
    `originals_mode`       VARCHAR(20) NOT NULL DEFAULT 'thread',
    -- Which messages moved (JSON array of email ids), so unmerge becomes possible.
    -- Merges done before this column existed cannot be reconstructed — which is
    -- exactly why it is being added before there are many of them.
    `moved_email_ids`      TEXT NULL,
    -- Everything else the merge moved, {table: [row ids]}. Messages alone are not a
    -- merge: without this an unmerge strands notes and logged time on the survivor.
    `moved_related`        TEXT NULL,
    -- The system message carrying the HTML snapshot (created, not moved), so an
    -- unmerge can delete it and its file.
    `snapshot_email_id`    INT NULL,
    -- What the source looked like before the merge closed it. Restoring to "Open"
    -- would be a guess, and wrong for anything already resolved when it was merged.
    `source_prev_status_id`       INT NULL,
    `source_prev_closed_datetime` DATETIME NULL,
    `undone_datetime`      DATETIME NULL,
    `undone_by_id`         INT NULL,
    `merged_by_id`         INT NULL,
    `merged_datetime`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_ticket_merges_source` (`source_ticket_id`),
    KEY `ix_ticket_merges_target` (`target_ticket_id`),
    CONSTRAINT `fk_ticket_merges_source` FOREIGN KEY (`source_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_merges_target` FOREIGN KEY (`target_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_merges_analyst` FOREIGN KEY (`merged_by_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ticket_ai_summaries: the AI-written summary of a ticket, kept as a HISTORY
-- rather than one row that gets overwritten (discussion #104, idea 7).
--
-- Versioned deliberately. A summary is a machine's reading of somebody's
-- conversation, and a later reading can be worse than an earlier one — a model
-- changes, a prompt changes, a long thread pushes something out of scope. If
-- the newest version were the only one, that loss would be silent and
-- unrecoverable. So nothing is ever overwritten: a refresh writes version n+1
-- and every earlier version stays readable.
--
-- last_email_id is the newest message the summary actually read. It is what
-- makes "this summary is out of date" a FACT rather than a guess about
-- timestamps, and it is how the optional auto-refresh knows how far behind it
-- has fallen.
--
-- generated_by NULL means FreeITSM refreshed it by itself; an analyst id means
-- somebody pressed the button.
CREATE TABLE IF NOT EXISTS `ticket_ai_summaries` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `ticket_id`     INT NOT NULL,
    -- 'summary' = the standing panel at the top of the ticket; 'read' = a
    -- "read it for me" briefing. One table because they want the same three
    -- things — a version history, a record of how much was read, and a way to
    -- know the conversation has moved on since. Two tables would be two sets
    -- of those rules, and they would drift.
    `kind`          VARCHAR(16) NOT NULL DEFAULT 'summary',
    -- Numbered per (ticket, kind), so a briefing and a summary count separately.
    `version`       INT NOT NULL DEFAULT 1,
    `summary`       MEDIUMTEXT NOT NULL,
    `provider`      VARCHAR(32) NULL,
    `model`         VARCHAR(120) NULL,
    -- What it was written from, so the panel can say "read 14 messages" and mean it.
    `message_count` INT NOT NULL DEFAULT 0,
    `note_count`    INT NOT NULL DEFAULT 0,
    `last_email_id` INT NULL,
    `generated_by`  INT NULL,
    -- Whether the model ran out of room before it finished. A summary cut off
    -- mid-sentence is the worst failure this feature has: it reads almost like
    -- a complete one. Recorded so the panel can say so out loud rather than
    -- presenting half an answer as the whole of one.
    `truncated`     TINYINT(1) NOT NULL DEFAULT 0,
    `tokens_in`     INT NOT NULL DEFAULT 0,
    `tokens_out`    INT NOT NULL DEFAULT 0,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_ticket_ai_summaries_ticket` (`ticket_id`, `kind`, `version`),
    CONSTRAINT `fk_ticket_ai_summaries_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_ai_summaries_analyst` FOREIGN KEY (`generated_by`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `ticket_audit` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `ticket_id`         INT NOT NULL,
    -- NULL = nobody did this; the workflow engine did (GH #120). The
    -- `add_ticket_note` action has always written NULL here on purpose, to mark
    -- an entry as automation rather than a person, but the column was NOT NULL
    -- — so the action could never once have succeeded on any installation.
    -- Both readers already LEFT JOIN analysts, and a NULL never violates the
    -- foreign key below, so relaxing this is safe in the way tightening is not.
    `analyst_id`        INT NULL,
    `field_name`        VARCHAR(100) NOT NULL,
    `old_value`         VARCHAR(500) NULL,
    `new_value`         VARCHAR(500) NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_ticket_audit_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`),
    CONSTRAINT `fk_ticket_audit_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_notes` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `ticket_id`         INT NOT NULL,
    `analyst_id`        INT NOT NULL,
    `note_text`         LONGTEXT NOT NULL,
    `is_internal`       TINYINT(1) NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_notes_tickets` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`),
    CONSTRAINT `fk_notes_analysts` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_time_entries` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `ticket_id`           INT NOT NULL,
    `analyst_id`          INT NOT NULL,
    `notes`               LONGTEXT NULL,
    `time_spent_minutes`  INT NOT NULL,
    `entry_datetime`      DATETIME NOT NULL,
    `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_time_entries_ticket_id` (`ticket_id`),
    KEY `ix_time_entries_analyst_date` (`analyst_id`, `entry_datetime`),
    CONSTRAINT `fk_time_entries_tickets` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`),
    CONSTRAINT `fk_time_entries_analysts` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Multi-tenancy (foundation)
-- A single FreeITSM install can host multiple client companies (tenants).
-- Single-company installs run entirely inside one silent "Default" tenant,
-- so multi-tenancy stays invisible until a second tenant is created.
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tenants` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(150) NOT NULL,
    `slug`              VARCHAR(100) NULL,
    -- Short code standing in for this company in a ticket number ({COMPANY}).
    -- NULL means one is derived from the name.
    `ticket_code`       VARCHAR(12) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The silent default tenant that owns all data on a single-company install.
INSERT INTO `tenants` (`name`, `is_default`, `is_active`)
SELECT 'Default', 1, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `tenants`);

-- auth_providers.tenant_id => the client company that owns this IdP (NULL =
-- global). Defined here because `tenants` is created after `auth_providers`.
ALTER TABLE `auth_providers`
    ADD CONSTRAINT `fk_auth_providers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

-- Domains owned by a tenant (used by shared-intake email routing).
CREATE TABLE IF NOT EXISTS `tenant_domains` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT NOT NULL,
    `domain`            VARCHAR(255) NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_domains_domain` (`domain`),
    CONSTRAINT `fk_tenant_domains_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-added public/free-email domains. gmail.com etc. are built into the code
-- (freemailBuiltinDomains); this table holds extra domains an MSP wants treated
-- as public. Public domains are never mapped to a company — their mail is filed
-- by hand from the triage queue.
CREATE TABLE IF NOT EXISTS `freemail_domains` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `domain`            VARCHAR(255) NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_freemail_domains_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Specific sender addresses mapped to a company (shared-intake routing). The
-- address-level twin of tenant_domains: matched before the domain, so a
-- personal/freemail address (jane@gmail.com) can route to a company even though
-- its domain can never be mapped. UNIQUE so one address routes exactly one way.
CREATE TABLE IF NOT EXISTS `tenant_sender_addresses` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT NOT NULL,
    `email`             VARCHAR(255) NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_sender_email` (`email`),
    CONSTRAINT `fk_tenant_sender_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-company "hide" layer for global config (the add+hide override model, design
-- §7). A row = "this company doesn't want global <entity_type> #<entity_id> in its
-- lists". Generic so one table serves every overridable config type (ticket_type,
-- ticket_origin, department, …). The global row is never touched, so closed/historic
-- tickets still resolve it — hiding only removes it from that company's pickers.
CREATE TABLE IF NOT EXISTS `tenant_config_hidden` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT NOT NULL,
    `entity_type`       VARCHAR(50) NOT NULL,
    `entity_id`         INT NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_config_hidden` (`tenant_id`, `entity_type`, `entity_id`),
    CONSTRAINT `fk_tenant_config_hidden_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which analysts may access which tenants (only consulted when an analyst is
-- NOT flagged can_access_all_tenants).
CREATE TABLE IF NOT EXISTS `analyst_tenant_access` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `tenant_id`         INT NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_analyst_tenant` (`analyst_id`, `tenant_id`),
    CONSTRAINT `fk_ata_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ata_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which companies a TEAM grants its members (only consulted when the team is
-- NOT flagged can_access_all_tenants). Team grants are unioned with each
-- member's own analyst_tenant_access — see getAccessibleTenantIds().
CREATE TABLE IF NOT EXISTS `team_tenant_access` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `team_id`           INT NOT NULL,
    `tenant_id`         INT NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_team_tenant` (`team_id`, `tenant_id`),
    CONSTRAINT `fk_tta_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tta_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Email / Mailbox
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `target_mailboxes` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `name`                  VARCHAR(100) NOT NULL,
    `provider`              VARCHAR(20) NOT NULL DEFAULT 'microsoft',
    `azure_tenant_id`       TEXT NOT NULL,
    `azure_client_id`       TEXT NOT NULL,
    `azure_client_secret`   TEXT NOT NULL,
    `oauth_redirect_uri`    TEXT NOT NULL,
    `oauth_scopes`          VARCHAR(500) NOT NULL DEFAULT 'openid email offline_access Mail.Read Mail.ReadWrite Mail.Send',
    `imap_server`           TEXT NOT NULL,
    `imap_port`             INT NOT NULL DEFAULT 993,
    `imap_encryption`       VARCHAR(10) NOT NULL DEFAULT 'ssl',
    -- Basic IMAP / SMTP mailboxes: username + password auth (no OAuth). Encrypted
    -- columns are NULL on Microsoft/Google mailboxes.
    `imap_username`         TEXT NULL,
    `imap_password`         TEXT NULL,
    `smtp_server`           TEXT NULL,
    `smtp_port`             INT NULL DEFAULT 587,
    `smtp_encryption`       VARCHAR(10) NULL DEFAULT 'tls',
    -- Sending credentials, when they differ from the reading ones. Plenty of
    -- providers issue a separate SMTP login, and a submission relay in front of
    -- an internal mail server often wants its own. BOTH BLANK = use the IMAP
    -- credentials, which is what every mailbox saved before these columns
    -- existed does, so nothing had to be migrated.
    `smtp_username`         TEXT NULL,
    `smtp_password`         TEXT NULL,
    `target_mailbox`        TEXT NOT NULL,
    -- 'delegated' = OAuth sign-in (acts as the signed-in user, Graph /me);
    -- 'app_only'  = client-credentials (the app reads the specific /users/<target_mailbox>).
    `auth_mode`             VARCHAR(20) NOT NULL DEFAULT 'delegated',
    -- Account actually authenticated in delegated mode (primary address, for display);
    -- compared to target_mailbox to catch "reading the wrong inbox".
    `authenticated_as`      VARCHAR(255) NULL,
    -- JSON array of every address the authenticated mailbox owns (primary + aliases);
    -- the target matches if it's any of these, so aliases aren't falsely flagged.
    `authenticated_addresses` TEXT NULL,
    `token_data`            LONGTEXT NULL,
    `email_folder`          VARCHAR(100) NOT NULL DEFAULT 'INBOX',
    `max_emails_per_check`  INT NOT NULL DEFAULT 10,
    `mark_as_read`          TINYINT(1) NOT NULL DEFAULT 0,
    `rejected_action`       VARCHAR(20) NOT NULL DEFAULT 'delete',
    `imported_action`       VARCHAR(20) NOT NULL DEFAULT 'delete',
    `imported_folder`       VARCHAR(100) NULL,
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `tenant_id`             INT NULL,
    -- The origin stamped on tickets this mailbox opens (#79). An ID, not a name,
    -- so renaming the origin can't break it. NULL = don't set one. Per mailbox
    -- rather than a single global "Email", because a helpdesk address and a
    -- monitoring alert address are genuinely different sources.
    `default_origin_id`     INT NULL,
    -- JSON array of mailbox-health warning keys the admin has acknowledged, so a
    -- deliberate choice (no origin wanted, receive-only IMAP) stops nagging. Only
    -- warnings can appear here; errors are never dismissible.
    `health_dismissed`      TEXT NULL,
    -- What the last check said when it did NOT work: the provider's own words,
    -- so a mailbox that has stopped collecting can say why rather than just
    -- going quiet. Cleared on the next clean check.
    `last_error`            TEXT NULL,
    `last_error_datetime`   DATETIME NULL,
    `created_datetime`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_checked_datetime` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `ix_target_mailboxes_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_target_mailboxes_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_target_mailboxes_origin` FOREIGN KEY (`default_origin_id`) REFERENCES `ticket_origins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emails` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `exchange_message_id`   VARCHAR(255) NULL,
    `subject`               VARCHAR(500) NULL,
    -- NULL = the sender genuinely has no email address. That happens when a
    -- self-service requester signs in through a directory and was never given a
    -- mailbox (GitHub #47 — warehouse and shop-floor staff). Every message that
    -- ARRIVED by email necessarily has one; this is only ever NULL for messages
    -- raised inside the portal.
    --
    -- Deliberately not a synthesised placeholder like `someone@company.local`:
    -- a fake address is indistinguishable from a real one, so an analyst would
    -- reply to it and the reply would bounce into nowhere. NULL is the truth and
    -- the UI can say so. `from_name` is what identifies these people.
    `from_address`          VARCHAR(255) NULL,
    `from_name`             VARCHAR(255) NULL,
    `to_recipients`         LONGTEXT NULL,
    `cc_recipients`         LONGTEXT NULL,
    `received_datetime`     DATETIME NULL,
    `body_preview`          LONGTEXT NULL,
    `body_content`          LONGTEXT NULL,
    `body_type`             VARCHAR(20) NULL,
    `has_attachments`       TINYINT(1) NULL DEFAULT 0,
    `importance`            VARCHAR(20) NULL,
    `is_read`               TINYINT(1) NULL DEFAULT 0,
    `processed_datetime`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `ticket_created`        TINYINT(1) NULL DEFAULT 0,
    `ticket_id`             INT NULL,
    `department_id`         INT NULL,
    `ticket_type_id`        INT NULL,
    `assigned_analyst_id`   INT NULL,
    `status`                VARCHAR(50) NULL DEFAULT 'New',
    `assigned_datetime`     DATETIME NULL,
    `is_initial`            TINYINT(1) NULL DEFAULT 0,
    `direction`             VARCHAR(20) NULL DEFAULT 'Inbound',
    `mailbox_id`            INT NULL,
    -- Which channel this message arrived/left on. 'email' (default) keeps every
    -- existing row and the email pipeline unchanged; 'whatsapp' reuses this same
    -- table so the reading-pane thread, threading and attachments work for free.
    `channel`               VARCHAR(20) NOT NULL DEFAULT 'email',
    -- For channel messages: the messaging_channels row it belongs to (so an
    -- outbound reply knows which provider/number to send from). NULL for email.
    `channel_id`            INT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_emails_analysts` FOREIGN KEY (`assigned_analyst_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_emails_departments` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
    CONSTRAINT `fk_emails_ticket_types` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types` (`id`),
    CONSTRAINT `fk_emails_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `target_mailboxes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_attachments` (
    `id`                        INT NOT NULL AUTO_INCREMENT,
    `email_id`                  INT NOT NULL,
    `exchange_attachment_id`    VARCHAR(255) NULL,
    `filename`                  VARCHAR(255) NOT NULL,
    `content_type`              VARCHAR(100) NOT NULL,
    `content_id`                VARCHAR(255) NULL,
    `file_path`                 VARCHAR(500) NOT NULL,
    `file_size`                 INT NOT NULL,
    `is_inline`                 TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_email_attachments_email` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Text pulled out of an attachment, so it can be searched (discussion #53).
--
-- ⚠️ THIS TABLE IS THE DURABLE RECORD, NOT THE INDEX. `search_documents` holds a
-- derived copy that can be rebuilt from here at any time; this holds the result
-- of actually opening the file. The distinction is what stops a future change of
-- search engine — or a corpus rebuild — from turning into a re-extraction of
-- every document ever received, which for PDFs and OCR would be hours of work
-- and, with an external extractor, a bill.
--
-- One row per attachment, keyed on the attachment itself, so re-indexing a busy
-- ticket does not re-open its files. `status` is carried as a FACT and shown in
-- the UI: a search that silently finds nothing because a file was never readable
-- is worse than one that admits the file could not be read.
--
--   extracted    text was read in full
--   truncated    read, but longer than the cap
--   too_large    the file itself is over the size limit; never opened
--   unsupported  no extractor handles this format (a PDF, until tier 2 exists)
--   failed       an extractor tried and could not
--   pending      queued for an extractor that runs off the request thread
CREATE TABLE IF NOT EXISTS `attachment_text` (
    `attachment_id`      INT NOT NULL,
    `status`             VARCHAR(16) NOT NULL,
    -- Which extractor produced it: 'builtin' for the dependency-free formats,
    -- later the name of an external service. Kept so a tier-2 install can find
    -- and redo everything the built-in tier could only mark unsupported.
    `extractor`          VARCHAR(20) NULL,
    `extracted_text`     LONGTEXT NULL,
    `chars`              INT NOT NULL DEFAULT 0,
    `extracted_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`attachment_id`),
    KEY `ix_attachment_text_status` (`status`),
    CONSTRAINT `fk_attachment_text_attachment` FOREIGN KEY (`attachment_id`)
        REFERENCES `email_attachments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Messaging channels (WhatsApp etc.)
-- ----------------------------------------------------------

-- A messaging "inbox" — the channel equivalent of a target_mailbox. Each row is one
-- WhatsApp number wired to a provider (Twilio or Meta Cloud). Like a mailbox it is
-- either pinned to a company (tenant_id set → that company owns every conversation,
-- sender ignored) or a shared intake (tenant_id NULL → routed by sender phone number,
-- else triage). `credentials` holds an encrypted JSON blob whose shape is per-provider
-- (Twilio: account_sid/auth_token; Meta: phone_number_id/access_token/app_secret).
CREATE TABLE IF NOT EXISTS `messaging_channels` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `name`                  VARCHAR(100) NOT NULL,
    `channel_type`          VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
    `provider`              VARCHAR(20) NOT NULL DEFAULT 'twilio',
    `phone_number`          VARCHAR(40) NULL,
    -- What this channel points at in the provider's own terms when that isn't a
    -- phone number: for Slack the workspace (team) id. NULL on phone channels.
    `channel_ref`           VARCHAR(190) NULL,
    `credentials`           LONGTEXT NULL,
    `verify_token`          VARCHAR(255) NULL,
    `ingress_mode`          VARCHAR(10) NOT NULL DEFAULT 'direct',
    `relay_secret`          VARCHAR(255) NULL,
    `tenant_id`             INT NULL,
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_inbound_datetime` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `ix_messaging_channels_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_messaging_channels_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Specific sender phone numbers mapped to a company (shared-intake channel routing).
-- The channel twin of tenant_sender_addresses: phone numbers have no domain, so for
-- shared channels an exact-number map is the only routing key (else triage). Stored
-- normalised (digits + leading +). UNIQUE so one number routes exactly one way.
CREATE TABLE IF NOT EXISTS `tenant_channel_senders` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT NOT NULL,
    `identifier`        VARCHAR(64) NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_channel_sender_identifier` (`identifier`),
    CONSTRAINT `fk_tenant_channel_sender_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pre-approved provider message templates (the only way to message a customer after
-- the WhatsApp 24-hour window closes). FreeITSM stores the definition so an analyst
-- can pick one and fill its {{1}},{{2}} placeholders; the template itself must be
-- created and approved at the provider. `provider_ref` is the provider's identifier:
-- a Twilio Content SID (HX…) or a Meta template name. `language` is used by Meta.
CREATE TABLE IF NOT EXISTS `messaging_templates` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `provider`          VARCHAR(20) NOT NULL DEFAULT 'twilio',
    `language`          VARCHAR(20) NOT NULL DEFAULT 'en',
    `provider_ref`      VARCHAR(255) NOT NULL,
    `body`              LONGTEXT NOT NULL,
    `tenant_id`         INT NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_messaging_templates_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_messaging_templates_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Web chat widgets (embeddable website chat → tickets)
-- ----------------------------------------------------------

-- The public/embed config for one website chat widget. A widget is the self-hosted
-- twin of a WhatsApp number: it drives exactly one `messaging_channels` row
-- (channel_type='webchat', provider='freeitsm'), so once a visitor's message is
-- ingested it flows through the same ticket membrane, inbox and reply pipeline as
-- every other channel. Company routing and the active flag live on that channel row;
-- this table holds only what the browser widget needs.
--
--   widget_key       the public id embedded in the customer's <script> snippet. NOT a
--                    secret (it ships in page source) — abuse is contained by the
--                    origin allowlist + rate limiting, not by keeping this hidden.
--   allowed_origins  newline-separated list of site origins permitted to embed this
--                    widget (e.g. https://acme.com). Empty = allow any (dev only).
--   require_email    pre-chat gate: when 1, the visitor must give a name + email before
--                    the conversation opens, so every ticket has a real requester.
CREATE TABLE IF NOT EXISTS `webchat_widgets` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `channel_id`       INT NOT NULL,
    `widget_key`       VARCHAR(64) NOT NULL,
    `allowed_origins`  LONGTEXT NULL,
    `greeting`         VARCHAR(500) NULL,
    `accent_colour`    VARCHAR(20) NULL,
    `launcher_text`    VARCHAR(60) NULL,
    `offline_message`  VARCHAR(500) NULL,
    `require_email`    TINYINT(1) NOT NULL DEFAULT 1,
    -- Availability: an SLA business-hours calendar defines "open" vs "closed". NULL =
    -- always open. When closed the widget shows offline_message and still takes a ticket.
    `business_calendar_id` INT NULL,
    -- If a reply arrives while the visitor isn't watching the chat, email it to them.
    `email_when_away`  TINYINT(1) NOT NULL DEFAULT 0,
    -- AI answers from the Knowledge base. ai_mode: 'assist' always raises a ticket;
    -- 'deflect' only raises one if the visitor escalates. The two ai_offer_* flags
    -- control which escalation routes the AI presents.
    `ai_enabled`       TINYINT(1) NOT NULL DEFAULT 0,
    `ai_mode`          VARCHAR(10) NOT NULL DEFAULT 'assist',
    `ai_offer_agent`   TINYINT(1) NOT NULL DEFAULT 1,
    `ai_offer_email`   TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_webchat_widget_key` (`widget_key`),
    UNIQUE KEY `uq_webchat_widget_channel` (`channel_id`),
    CONSTRAINT `fk_webchat_widget_channel` FOREIGN KEY (`channel_id`) REFERENCES `messaging_channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- External issue trackers (Jira first; GitHub/GitLab/DevOps behind the same contract)
-- ----------------------------------------------------------

-- One configured tracker instance: a Jira site, a GitHub org, an Azure DevOps
-- organisation.
--
-- ⚠️ TENANCY: this is a CONNECTION-shaped table, the same shape as
-- messaging_channels and mailboxes — NOT scoped data and NOT a config list.
--   tenant_id NULL = SHARED: serves every company (an MSP's own Jira).
--   tenant_id set  = PINNED to that one company (a client with their own Jira).
-- So the admin list is deliberately UNFILTERED; what is gated is WRITING (you may
-- only pin to a company you can reach). Do NOT scope reads with
-- activeTenantFilter() — it treats NULL as Default-owned and would hide every
-- shared connection from every client company. See the wiki,
-- Multi-Tenancy-Developer-Guide §1, for all three meanings of NULL.
--
-- ⚠️ A read that returns credentials is the exception to "capabilities guard
-- writes, not reads": the list endpoint must return a has_credentials boolean and
-- never the token itself, exactly as api/messaging/get_channels.php does.
CREATE TABLE IF NOT EXISTS `integration_connections` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `name`                  VARCHAR(100) NOT NULL,
    `provider`              VARCHAR(20) NOT NULL DEFAULT 'jira',   -- jira | github | gitlab | devops
    `base_url`              VARCHAR(500) NOT NULL,
    `auth_type`             VARCHAR(20) NOT NULL DEFAULT 'api_token', -- api_token | pat | oauth
    -- Encrypted at rest (AES-256-GCM, ENC: prefix) like messaging_channels.credentials.
    `credentials`           LONGTEXT NULL,
    -- Inbound signature secret, also encrypted. NULL while ingress_mode='poll'.
    -- Note this is the OPPOSITE of webhook_deliveries, which deliberately does not
    -- persist its signing secret: outbound signs at enqueue and forgets, inbound
    -- must keep the secret in order to verify.
    `webhook_secret`        VARCHAR(2000) NULL,
    -- 'webhook' needs the provider to reach this install; 'poll' is the
    -- firewalled fallback. Both produce the same canonical events downstream.
    `ingress_mode`          VARCHAR(10) NOT NULL DEFAULT 'poll',
    `inbound_enabled`       TINYINT(1) NOT NULL DEFAULT 0,   -- master "accept updates" switch
    -- Push the ticket's attachments up with the issue. ON by default: on a bug
    -- report the screenshot usually IS the report. Inline images (signatures,
    -- tracking pixels) are never sent — see integrationsTicketAttachments().
    `send_attachments`      TINYINT(1) NOT NULL DEFAULT 1,
    `poll_interval_minutes` INT NOT NULL DEFAULT 5,
    -- The account our token authenticates as, captured at connection test.
    -- Half of echo suppression: an inbound event authored by this identity is our
    -- own write coming back. Populated from V1 even though nothing reads it until
    -- comment sync, because back-filling it later is miserable.
    `account_identity`      VARCHAR(255) NULL,
    `tenant_id`             INT NULL,
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `last_poll_datetime`    DATETIME NULL,
    `last_poll_watermark`   VARCHAR(100) NULL,   -- provider-native "changed since" cursor
    `created_by`            INT NULL,
    `created_datetime`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_integration_connections_tenant` (`tenant_id`),
    KEY `ix_integration_connections_active` (`is_active`),
    CONSTRAINT `fk_integration_connections_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The spine: one row per "our work item ↔ their issue".
--
-- `entity_type` is polymorphic from day one although V1 only ever writes 'ticket'
-- — Problems and Changes cost nothing now and a migration later.
--
-- `external_id` is the provider's STABLE id, not the key: PROJ-412 becomes
-- OPS-412 when a Jira project is renamed, so the key is display only.
--
-- `status_category` is one of todo|in_progress|done|cancelled and is what every
-- decision keys off; `status_name` is raw and for display only. Jira statuses are
-- per-project and freely renamed — branching on the name is the mistake
-- tickets.merged_into_id exists to avoid.
CREATE TABLE IF NOT EXISTS `integration_links` (
    `id`                   INT NOT NULL AUTO_INCREMENT,
    `connection_id`        INT NOT NULL,
    `entity_type`          VARCHAR(20) NOT NULL DEFAULT 'ticket',  -- ticket | problem | change
    `entity_id`            INT NOT NULL,
    `external_id`          VARCHAR(100) NOT NULL,
    `external_key`         VARCHAR(100) NULL,
    `external_url`         VARCHAR(1000) NULL,
    `status_name`          VARCHAR(100) NULL,
    `status_category`      VARCHAR(20) NULL,
    `assignee_name`        VARCHAR(255) NULL,
    `last_synced_datetime` DATETIME NULL,
    `last_error`           VARCHAR(500) NULL,
    `created_by`           INT NULL,
    `created_datetime`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- One issue may legitimately link to two tickets; never twice to the same one.
    UNIQUE KEY `uq_integration_link` (`connection_id`, `external_id`, `entity_type`, `entity_id`),
    KEY `ix_integration_links_entity` (`entity_type`, `entity_id`),
    KEY `ix_integration_links_connection` (`connection_id`),
    CONSTRAINT `fk_integration_links_connection` FOREIGN KEY (`connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What our values mean in the tracker's vocabulary. One row per mapping.
--
-- map_type is 'project' | 'issue_type' | 'priority' (and 'custom' later).
--
-- ⚠️ local_key is DELIBERATELY a string, not a foreign key, because what it points
-- at differs per map_type: a tenant or department for routing, a ticket type id,
-- a priority id. It is namespaced for project routing ('tenant:5', 'dept:3', '*')
-- so one map_type covers both routing dimensions plus the fallback, rather than
-- inventing a second column that is NULL most of the time.
--
-- ⚠️ Deliberately NOT keyed to a project for priorities. Jira priorities are
-- defined per project, but a per-project map would be unmaintainable across
-- dozens of projects, so the map is global and a rejected value falls back to
-- creating the issue WITHOUT that field. Losing a priority is cosmetic; losing
-- the escalation because somebody renamed a priority on one project is not.
CREATE TABLE IF NOT EXISTS `integration_field_maps` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `connection_id`    INT NOT NULL,
    `map_type`         VARCHAR(20) NOT NULL,               -- project | issue_type | priority | custom
    `local_key`        VARCHAR(100) NOT NULL,              -- 'tenant:5' | 'dept:3' | '*' | a local id
    `external_key`     VARCHAR(255) NOT NULL,              -- the tracker's value (project key, issue type name, priority name)
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_integration_field_map` (`connection_id`, `map_type`, `local_key`),
    CONSTRAINT `fk_integration_field_map_connection` FOREIGN KEY (`connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every comment that has crossed between a ticket and its linked issue, in either
-- direction. This is echo suppression: without it, a comment we push to Jira comes
-- back on the next poll, becomes a note, and pushes again — forever.
--
-- ⚠️ uq_integration_comment is not an optimisation, it is THE guarantee that a
-- comment is imported exactly once. The service reads the map first, but that read
-- is check-then-act and two overlapping cron runs would both pass it; the unique
-- key is what makes the second writer lose. Never drop it "because we check first".
--
-- local_note_id is nullable: an outbound comment posted by a workflow has no note
-- behind it, only text.
CREATE TABLE IF NOT EXISTS `integration_comment_map` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `link_id`             INT NOT NULL,
    `direction`           VARCHAR(3) NOT NULL DEFAULT 'in',       -- in | out
    `external_comment_id` VARCHAR(100) NOT NULL,
    `local_note_id`       INT NULL,
    `author_identity`     VARCHAR(255) NULL,                      -- as the tracker names them; compared with connection.account_identity
    `author_name`         VARCHAR(255) NULL,                      -- display only
    `created_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_integration_comment` (`link_id`, `external_comment_id`),
    KEY `ix_integration_comment_note` (`local_note_id`),
    CONSTRAINT `fk_integration_comment_link` FOREIGN KEY (`link_id`) REFERENCES `integration_links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One website chat conversation. Created when a visitor opens the widget and (if the
-- widget asks for it) gives their name + email. `token` is the browser's capability
-- for THIS conversation — it is stored in the visitor's browser and presented on every
-- send/poll, so knowing it is the only thing that lets you read or post to the chat
-- (the widget key alone can't: it can only START a conversation). `ticket_id` is set
-- lazily on the first message, so one conversation maps to exactly one ticket. visitor_ip
-- is kept only for rate limiting. Rows are disposable once their ticket is closed.
CREATE TABLE IF NOT EXISTS `webchat_conversations` (
    `id`                     INT NOT NULL AUTO_INCREMENT,
    `channel_id`             INT NOT NULL,
    `token`                  VARCHAR(64) NOT NULL,
    `ticket_id`              INT NULL,
    `visitor_name`           VARCHAR(150) NULL,
    `visitor_email`          VARCHAR(255) NULL,
    `visitor_ip`             VARCHAR(45) NULL,
    `created_datetime`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_webchat_conversation_token` (`token`),
    KEY `ix_webchat_conversation_channel` (`channel_id`),
    KEY `ix_webchat_conversation_ticket` (`ticket_id`),
    CONSTRAINT `fk_webchat_conversation_channel` FOREIGN KEY (`channel_id`) REFERENCES `messaging_channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The chat transcript held BEFORE (and if) a conversation becomes a ticket — used in AI
-- 'deflect' mode, where the AI can answer without ever raising a ticket. `sender` is
-- 'visitor', 'ai', 'agent' or 'system'. When the visitor escalates, these rows are the
-- source for the ticket's opening message + the full-chat-log .txt attachment. Once a
-- ticket exists the ticket's own `emails` thread takes over (this table is not written
-- to for plain, AI-off widgets — those go straight to the ticket).
CREATE TABLE IF NOT EXISTS `webchat_messages` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `conversation_id`  INT NOT NULL,
    `sender`           VARCHAR(10) NOT NULL DEFAULT 'visitor',
    `body`             LONGTEXT NULL,
    -- When an agent reply (stored in `emails`) is mirrored into this transcript so the
    -- visitor's widget can show it, this holds the source emails.id (dedup key). NULL for
    -- native visitor/ai/system rows.
    `source_email_id`  INT NULL,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_webchat_messages_conversation` (`conversation_id`),
    CONSTRAINT `fk_webchat_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `webchat_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_recordings` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `ticket_id`           INT NULL,
    -- Which message the recording came with. NULL = the ticket's opening
    -- message (how every recording behaved before replies could carry one).
    `email_id`            INT NULL,
    `recorded_by_user_id` INT NULL,
    `filename`            VARCHAR(255) NOT NULL,
    `original_filename`   VARCHAR(255) NULL,
    `content_type`        VARCHAR(100) NOT NULL,
    `file_path`           VARCHAR(500) NOT NULL,
    `file_size`           INT NOT NULL,
    `duration_seconds`    INT NULL,
    `has_audio`           TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_ticket_recordings_ticket_id` (`ticket_id`),
    KEY `ix_ticket_recordings_pending` (`ticket_id`, `created_at`),
    CONSTRAINT `fk_ticket_recordings_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    -- SET NULL, not CASCADE: if a message is ever removed the video should fall
    -- back to the ticket, not be destroyed with it.
    CONSTRAINT `fk_ticket_recordings_email` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ticket_recordings_user` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mailbox_email_whitelist` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `mailbox_id`        INT NOT NULL,
    `entry_type`        VARCHAR(10) NOT NULL,
    `entry_value`       VARCHAR(255) NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_mew_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `target_mailboxes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mailbox_activity_log` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `mailbox_id`        INT NOT NULL,
    `action`            VARCHAR(20) NOT NULL,
    `from_address`      VARCHAR(255) NOT NULL,
    `from_name`         VARCHAR(255) NULL,
    `subject`           VARCHAR(500) NULL,
    `reason`            VARCHAR(255) NULL,
    `ticket_id`         INT NULL,
    `processing_log`    TEXT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_mal_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `target_mailboxes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per directory sync RUN. Follows email_send_log's principle: record the
-- attempt, not just the success, because a sync that did nothing and a sync that
-- never ran look identical afterwards otherwise.
--
-- `mode` distinguishes a preview from a real run. Previews are logged too — what
-- somebody was shown before they pressed the button is worth being able to check
-- when the result surprises them.
CREATE TABLE IF NOT EXISTS `directory_sync_runs` (
    `id`                 INT NOT NULL AUTO_INCREMENT,
    `provider_id`        INT NOT NULL,
    `mode`               VARCHAR(10) NOT NULL DEFAULT 'live',      -- live | preview
    -- running | ok | stopped | failed.  'stopped' is the sanity brake: not an
    -- error, a refusal. Kept distinct so "we protected you" never reads as
    -- "something broke".
    `status`             VARCHAR(12) NOT NULL DEFAULT 'running',
    `started_datetime`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_datetime`  DATETIME NULL,
    `seen_count`         INT NOT NULL DEFAULT 0,
    `created_count`      INT NOT NULL DEFAULT 0,
    `updated_count`      INT NOT NULL DEFAULT 0,
    `adopted_count`      INT NOT NULL DEFAULT 0,
    `deactivated_count`  INT NOT NULL DEFAULT 0,
    `conflict_count`     INT NOT NULL DEFAULT 0,
    `error_count`        INT NOT NULL DEFAULT 0,
    `message`            TEXT NULL,
    `triggered_by_analyst_id` INT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_dsr_provider` (`provider_id`, `started_datetime`),
    KEY `idx_dsr_status` (`status`),
    CONSTRAINT `fk_dsr_provider` FOREIGN KEY (`provider_id`) REFERENCES `auth_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a run did to each PERSON. "47 updated" is a number; this is the answer to
-- "updated how, and who?", which is the only version anybody can act on.
CREATE TABLE IF NOT EXISTS `directory_sync_entries` (
    `id`                 INT NOT NULL AUTO_INCREMENT,
    `run_id`             INT NOT NULL,
    -- create | update | adopt | deactivate | conflict | skip | error | unchanged
    `action`             VARCHAR(16) NOT NULL,
    -- NULL on a preview (nobody was created yet) and on a skip.
    `user_id`            INT NULL,
    `directory_username` VARCHAR(255) NULL,
    `display_name`       VARCHAR(255) NULL,
    -- Human-readable: which fields changed, or why nothing happened.
    `detail`             VARCHAR(1000) NULL,
    `created_datetime`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dse_run` (`run_id`, `action`),
    KEY `idx_dse_user` (`user_id`),
    -- The run owns its entries; deleting the provider takes both with it.
    CONSTRAINT `fk_dse_run` FOREIGN KEY (`run_id`) REFERENCES `directory_sync_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents that can be attached to anything (GH discussion #76).
--
-- A document is EITHER a file FreeITSM holds OR a link to an external DMS, so the
-- two live in one table with a `kind` — a user sees one list either way, and
-- splitting a table later is mechanical where merging two is not.
--
-- ⚠️ There are deliberately NO permission columns. A document is visible if you
-- can see at least one thing it is attached to, resolved at query time from the
-- parent. Storing visibility here would go stale the moment a permission changed.
-- See includes/documents.php.
CREATE TABLE IF NOT EXISTS `documents` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `kind`              VARCHAR(8) NOT NULL DEFAULT 'file',
    `title`             VARCHAR(255) NOT NULL,
    `description`       TEXT NULL,
    `storage_key`       VARCHAR(255) NULL,
    `original_name`     VARCHAR(255) NULL,
    `mime_type`         VARCHAR(100) NULL,
    `size_bytes`        BIGINT NULL,
    `content_hash`      CHAR(64) NULL,
    `external_url`      VARCHAR(2048) NULL,
    `tenant_id`         INT NULL,
    `uploaded_by_id`    INT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL,
    `deleted_datetime`  DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_documents_hash` (`content_hash`),
    KEY `idx_documents_tenant` (`tenant_id`),
    FULLTEXT KEY `ft_documents` (`title`,`description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What each document is attached to. A ROW rather than a column on `documents`,
-- so one document can belong to several things without a migration — and so that
-- deleting a parent removes the LINK, never the file somebody else is still using.
CREATE TABLE IF NOT EXISTS `document_links` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `document_id`       INT NOT NULL,
    `parent_type`       VARCHAR(32) NOT NULL,
    `parent_id`         INT NOT NULL,
    `linked_by_id`      INT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_document_links` (`document_id`,`parent_type`,`parent_id`),
    KEY `idx_document_links_parent` (`parent_type`,`parent_id`),
    KEY `idx_document_links_doc` (`document_id`),
    CONSTRAINT `fk_document_links_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-company answers to settings that otherwise live install-wide in
-- system_settings (discussion #72). A company with NO ROW here is not "off" — it
-- follows the install default, which is what keeps this invisible on a
-- single-company install. See includes/tenant_settings.php.
CREATE TABLE IF NOT EXISTS `tenant_settings` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT NOT NULL,
    `setting_key`       VARCHAR(100) NOT NULL,
    `setting_value`     TEXT NULL,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_setting` (`tenant_id`,`setting_key`),
    CONSTRAINT `fk_tenant_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extracted text for an attached document, so its CONTENTS are searchable and
-- not merely its title. Separate from attachment_text because that table's key
-- is an email-attachment id; the shared part is the extractor, not the queue.
CREATE TABLE IF NOT EXISTS `document_text` (
    `document_id`        INT NOT NULL,
    `status`             VARCHAR(16) NOT NULL,
    `extractor`          VARCHAR(20) NULL,
    `extracted_text`     LONGTEXT NULL,
    `chars`              INT NOT NULL DEFAULT 0,
    `extracted_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`document_id`),
    KEY `ix_document_text_status` (`status`),
    CONSTRAINT `fk_document_text_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who fetched which document, and when. Written at the download endpoint, which
-- is the only way to obtain a document and therefore the only place that can
-- honestly claim to have seen every access.
CREATE TABLE IF NOT EXISTS `document_access_log` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `document_id`       INT NOT NULL,
    `analyst_id`        INT NULL,
    `action`            VARCHAR(12) NOT NULL DEFAULT 'download',
    `ip_address`        VARCHAR(45) NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_document_access_doc` (`document_id`),
    CONSTRAINT `fk_document_access_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound counterpart to mailbox_activity_log: one row per send ATTEMPT, so a
-- failure is visible in the UI instead of only in the PHP error log. `route` says
-- which part of FreeITSM asked for the send; provider/auth_mode are recorded on the
-- row because they are what most sending faults turn out to hinge on.
CREATE TABLE IF NOT EXISTS `email_send_log` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `mailbox_id`        INT NULL,
    `ticket_id`         INT NULL,
    `route`             VARCHAR(30) NOT NULL,
    `provider`          VARCHAR(20) NULL,
    `auth_mode`         VARCHAR(20) NULL,
    `to_address`        VARCHAR(255) NOT NULL,
    `subject`           VARCHAR(500) NULL,
    `status`            VARCHAR(10) NOT NULL,
    `error_message`     TEXT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_esl_mailbox` (`mailbox_id`, `created_datetime`),
    KEY `idx_esl_status` (`status`, `created_datetime`),
    KEY `idx_esl_ticket` (`ticket_id`),
    CONSTRAINT `fk_esl_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `target_mailboxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_email_templates` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `event_trigger`     VARCHAR(50) NOT NULL,
    `subject_template`  VARCHAR(500) NOT NULL,
    `body_template`     LONGTEXT NOT NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An analyst's email signatures (discussion #80). Per analyst always — there is no
-- shared signature, because a signature is a person signing their own name. Several
-- are allowed and exactly one is the default: the default is inserted without being
-- asked for, so an analyst who wants only one never has to choose.
CREATE TABLE IF NOT EXISTS `analyst_signatures` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `analyst_id`       INT NOT NULL,
    `name`             VARCHAR(100) NOT NULL,
    `body`             LONGTEXT NOT NULL,
    `is_default`       TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`    INT NOT NULL DEFAULT 0,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sig_analyst` (`analyst_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which senders an email template applies to (discussion #80). A template with no
-- rows here applies to everyone, which is the state a new template starts in — so
-- there is always a catch-all unless one is deliberately removed. match_type is
-- 'address' or 'domain'; selection is by specificity, never by display_order.
CREATE TABLE IF NOT EXISTS `ticket_email_template_rules` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `template_id`       INT NOT NULL,
    `match_type`        VARCHAR(10) NOT NULL,
    `match_value`       VARCHAR(255) NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tmpl` (`template_id`),
    KEY `idx_match` (`match_type`, `match_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canned responses an analyst inserts into a reply by hand. Distinct from
-- ticket_email_templates above (automated mail the system sends on an event).
-- analyst_id NULL = a shared team template; set = that analyst's private one.
-- tenant_id  NULL = a global default (the config meaning, as ticket_types).
CREATE TABLE IF NOT EXISTS `ticket_reply_templates` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `body`              LONGTEXT NOT NULL,
    `analyst_id`        INT NULL,
    `tenant_id`         INT NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_reply_tpl_analyst` (`analyst_id`),
    KEY `idx_reply_tpl_tenant` (`tenant_id`),
    CONSTRAINT `fk_reply_tpl_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reply_tpl_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_csat_responses` (
    `id`                 INT NOT NULL AUTO_INCREMENT,
    `ticket_id`          INT NOT NULL,
    `token`              VARCHAR(64) NOT NULL,
    `sent_datetime`      DATETIME NULL,
    `responded_datetime` DATETIME NULL,
    `rating`             TINYINT NULL,
    `comment`            TEXT NULL,
    `analyst_id`         INT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_csat_token` (`token`),
    KEY `ix_ticket_csat_ticket_id` (`ticket_id`),
    KEY `ix_ticket_csat_responded` (`responded_datetime`),
    CONSTRAINT `fk_ticket_csat_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_csat_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_rota_shifts` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `start_time`        TIME NOT NULL,
    `end_time`          TIME NOT NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rota_locations` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `colour`            VARCHAR(20) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rota_locations_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `rota_locations` (`name`, `colour`, `is_default`, `display_order`) VALUES
    ('Office', '#1a73e8', 1, 10),
    ('WFH',    '#1e8e3e', 0, 20);

CREATE TABLE IF NOT EXISTS `ticket_rota_entries` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `rota_date`         DATE NOT NULL,
    `shift_id`          INT NOT NULL,
    `location_id`       INT NULL,
    `is_on_call`        TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_analyst_date` (`analyst_id`, `rota_date`),
    KEY `ix_rota_entries_location_id` (`location_id`),
    CONSTRAINT `fk_rota_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_rota_shift` FOREIGN KEY (`shift_id`) REFERENCES `ticket_rota_shifts` (`id`),
    CONSTRAINT `fk_rota_location` FOREIGN KEY (`location_id`) REFERENCES `rota_locations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Assets
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `asset_types` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    -- The glyph shown beside every asset of this type (#1146). Points at the
    -- SAME library the CMDB's classes use (`cmdb_icons`) rather than a second
    -- one: a printer must not look different depending on which module you are
    -- in, and the 66 glyphs already there were paid for and half-unused.
    -- NULL = no icon, which is every type until somebody picks one.
    `icon_id`           INT NULL,
    -- Multi-tenancy: NULL = global default type (shared by every company); set =
    -- a type a company added for itself. Existing rows stay NULL, so a
    -- single-company install is unaffected. (Config meaning of tenant_id: NULL =
    -- global default — unlike scoped data tables where NULL means "unrouted".)
    `tenant_id`         INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    -- Per-scope name uniqueness (a company may hold a type whose name matches a
    -- global default). Global-name dedup is enforced in the API, since NULL
    -- tenant_id rows aren't de-duped by a unique key.
    UNIQUE KEY `uq_asset_types_tenant_name` (`tenant_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_status_types` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    -- Multi-tenancy: NULL = global default status; set = a company's own. See
    -- asset_types above for the tenant_id config convention.
    `tenant_id`         INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_asset_status_types_tenant_name` (`tenant_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Arbitrary-depth physical location tree (adjacency list). A NULL parent_id is
-- a root; any node can have children, so each branch nests as deep as needed
-- (e.g. UK > London > Office 1). The self-referencing FK is RESTRICT, so a
-- parent can't be deleted while it still has children.
CREATE TABLE IF NOT EXISTS `asset_locations` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `parent_id`         INT NULL,
    `display_order`     INT NOT NULL DEFAULT 0,
    -- Multi-tenancy: SCOPED DATA, not a config list. A company's physical sites
    -- are entirely its own — there is no such thing as a shared office — so this
    -- scopes like `assets` (activeTenantFilter): the Default company owns the
    -- pre-existing NULL rows, and a client company sees only its own. Deleting a
    -- company CASCADEs its locations away rather than promoting them to NULL,
    -- which would hand them to Default. Assets pointing at a deleted location
    -- fall back to none via fk_assets_location (ON DELETE SET NULL).
    `tenant_id`         INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_asset_locations_parent` (`parent_id`),
    CONSTRAINT `fk_asset_locations_parent` FOREIGN KEY (`parent_id`) REFERENCES `asset_locations` (`id`),
    CONSTRAINT `fk_asset_locations_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assets` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `hostname`          VARCHAR(50) NULL,
    `manufacturer`      VARCHAR(50) NULL,
    `model`             VARCHAR(50) NULL,
    `memory`            BIGINT NULL,
    `service_tag`       VARCHAR(50) NULL,
    `operating_system`  VARCHAR(50) NULL,
    `feature_release`   VARCHAR(10) NULL,
    `build_number`      VARCHAR(50) NULL,
    `cpu_name`          VARCHAR(250) NULL,
    `speed`             BIGINT NULL,
    `bios_version`      VARCHAR(20) NULL,
    `first_seen`        DATETIME NULL,
    `last_seen`         DATETIME NULL,
    `asset_type_id`     INT NULL,
    `asset_status_id`   INT NULL,
    `location_id`       INT NULL,
    `domain`            VARCHAR(100) NULL,
    `logged_in_user`    VARCHAR(100) NULL,
    `last_boot_utc`     DATETIME NULL,
    `tpm_version`       VARCHAR(50) NULL,
    `bitlocker_status`  VARCHAR(20) NULL,
    `gpu_name`          VARCHAR(250) NULL,
    -- Procurement & warranty (Snipe-IT-style lifecycle fields)
    `purchase_date`     DATE NULL,
    `purchase_cost`     DECIMAL(12,2) NULL,
    `supplier_id`       INT NULL,
    `order_number`      VARCHAR(100) NULL,
    `warranty_expiry`   DATE NULL,
    -- Multi-tenancy (SCOPED DATA, not config): the company this asset belongs to.
    -- NULL = the Default company (existing installs stay NULL, so a single-company
    -- install is unaffected). Agent ingest derives it from the API key's tenant_id;
    -- hostname uniqueness is enforced PER COMPANY in application code (two clients
    -- may each legitimately have a "LAPTOP-01").
    `tenant_id`         INT NULL,
    -- QR labels (#935). `asset_tag` is the human-readable number printed on the
    -- label ("LT0001") and is unique PER COMPANY — enforced in application code,
    -- exactly as hostname is, and for the same reason plus one more: a UNIQUE
    -- (tenant_id, asset_tag) index would NOT hold for the Default company,
    -- because MySQL treats NULLs as distinct in a unique index, so two default
    -- assets could both be LT0001 while the index looked like it was guarding
    -- them. A unique index that silently doesn't apply is worse than none.
    `asset_tag`         VARCHAR(64) NULL,
    -- What the QR actually encodes: an opaque token, install-wide unique, minted
    -- on first label print. Deliberately NOT the id (…/a/4711 invites 4712) and
    -- deliberately NOT the asset tag (two companies may both use LT0001).
    `qr_token`          VARCHAR(64) NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `idx_assets_location` (`location_id`),
    KEY `idx_assets_supplier` (`supplier_id`),
    KEY `idx_assets_tenant` (`tenant_id`),
    -- Lookup only. See the asset_tag comment for why this one is NOT unique.
    KEY `idx_assets_tag` (`tenant_id`, `asset_tag`),
    -- This one IS safe to make unique: the token is install-wide and never NULL
    -- once minted, so there is no NULL-distinctness trap.
    UNIQUE KEY `uq_assets_qr_token` (`qr_token`),
    CONSTRAINT `fk_assets_location` FOREIGN KEY (`location_id`) REFERENCES `asset_locations` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_assets_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
    -- fk_assets_supplier (supplier_id -> suppliers.id) is added in db_verify.php:
    -- the suppliers table is defined later in this file, so the FK can't be inline here.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_assets` (
    `id`                        INT NOT NULL AUTO_INCREMENT,
    `user_id`                   INT NOT NULL,
    `asset_id`                  INT NOT NULL,
    `assigned_datetime`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `assigned_by_analyst_id`    INT NULL,
    `notes`                     VARCHAR(500) NULL,
    `expected_return_date`      DATE NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_asset` (`user_id`, `asset_id`),
    CONSTRAINT `fk_users_assets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_users_assets_analyst` FOREIGN KEY (`assigned_by_analyst_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Check-in / check-out custody trail. One row per checkout (assign) and checkin
-- (unassign) event, with the user name snapshotted so history survives a user
-- being deleted. expected_return_date carries the due-back date at checkout.
CREATE TABLE IF NOT EXISTS `asset_checkout_log` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `asset_id`              INT NOT NULL,
    `user_id`               INT NULL,
    `user_name`             VARCHAR(150) NULL,
    `action`                VARCHAR(10) NOT NULL,
    `expected_return_date`  DATE NULL,
    `analyst_id`            INT NULL,
    `notes`                 VARCHAR(500) NULL,
    `action_datetime`       DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_acl_asset` (`asset_id`),
    CONSTRAINT `fk_acl_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_history` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `asset_id`          INT NOT NULL,
    `analyst_id`        INT NOT NULL,
    `field_name`        VARCHAR(100) NOT NULL,
    `old_value`         VARCHAR(500) NULL,
    `new_value`         VARCHAR(500) NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_asset_history_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`),
    CONSTRAINT `fk_asset_history_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_disks` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `asset_id`      INT NOT NULL,
    `drive`         VARCHAR(10) NULL,
    `label`         VARCHAR(100) NULL,
    `file_system`   VARCHAR(20) NULL,
    `size_bytes`    BIGINT NULL,
    `free_bytes`    BIGINT NULL,
    `used_percent`  DECIMAL(5,1) NULL,
    `source`        VARCHAR(20) NOT NULL DEFAULT 'agent',
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_asset_disks_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_network_adapters` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `asset_id`      INT NOT NULL,
    `name`          VARCHAR(255) NULL,
    `mac_address`   VARCHAR(17) NULL,
    `ip_address`    VARCHAR(45) NULL,
    `subnet_mask`   VARCHAR(45) NULL,
    `gateway`       VARCHAR(45) NULL,
    `dhcp_enabled`  TINYINT(1) NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_asset_network_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_devices` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `asset_id`          INT NOT NULL,
    `device_class`      VARCHAR(100) NULL,
    `device_name`       VARCHAR(255) NOT NULL,
    `status`            VARCHAR(20) NULL,
    `manufacturer`      VARCHAR(255) NULL,
    `driver_version`    VARCHAR(50) NULL,
    `driver_date`       DATE NULL,
    PRIMARY KEY (`id`),
    KEY `idx_asset_devices_asset` (`asset_id`),
    CONSTRAINT `fk_asset_devices_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Custom asset fields — recording things that are not Windows computers.
--
-- The `assets` columns above describe a PC, because that is what the agent
-- reports. A printer, a headset or a television needs different columns, and
-- which ones differs per customer, so they are user-defined rather than
-- hard-coded: a field is DECLARED here and its answers are stored one row per
-- asset per field, typed.
--
-- Typing, validation and storage rules are shared with the CMDB's property
-- system via includes/typed_fields.php. Only the definition and value tables
-- are separate — see docs/design/flexible-asset-fields.md §2.3 for why.
-- ============================================================================

-- The catalogue. 🔑 A field is defined ONCE, install-wide, and then attached to
-- as many asset types as want it — so a television's "IP address" is the SAME
-- field as a network device's and one report can span both. (The CMDB scopes
-- properties per class, which is right for 8 classes and wrong for "absolutely
-- anything".)
CREATE TABLE IF NOT EXISTS `asset_fields` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    -- Stable machine key. NEVER changes: import mappings and saved reports point
    -- at it, so renaming the LABEL from "Size" to "Screen size" must not break a
    -- nightly import.
    `field_key`         VARCHAR(100) NOT NULL,
    `label`             VARCHAR(150) NOT NULL,
    -- One of TypedFields::TYPES. ⚠️ Cannot be changed once values exist — a
    -- presentational variant is a MODE inside `config` (text/textarea is `text`
    -- + config.multiline; date/time/datetime is `date` + config.date_mode), so
    -- that flipping one keeps the field's identity and every answer given to it.
    `field_type`        VARCHAR(20) NOT NULL,
    -- Per-type JSON settings: multiline, decimals, unit, date_mode, ref_kind…
    `config`            LONGTEXT NULL,
    `help_text`         VARCHAR(500) NULL,
    `is_unique`         TINYINT(1) NOT NULL DEFAULT 0,
    `is_searchable`     TINYINT(1) NOT NULL DEFAULT 0,
    `show_in_list`      TINYINT(1) NOT NULL DEFAULT 0,
    -- Multi-tenancy: NULL = a global default field shared by every company; set
    -- = one a company added for itself. Same convention as asset_types.
    `tenant_id`         INT NULL,
    -- ⚠️ SOFT delete, never hard. asset_field_values.field_id points here, so
    -- dropping the row silently destroys every answer ever recorded against it.
    -- form_fields learned this the hard way; see its own comment.
    `is_deleted`        TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- ⚠️ Does NOT dedupe GLOBAL fields: MySQL treats NULLs as distinct, so two
    -- tenant_id-NULL rows may share a key as far as this index is concerned.
    -- Global-key dedup is enforced in application code, exactly as asset_types
    -- documents for the same reason.
    UNIQUE KEY `uq_asset_fields_tenant_key` (`tenant_id`, `field_key`),
    CONSTRAINT `fk_asset_fields_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allowed values for a `dropdown` field. Mirrors cmdb_class_property_options,
-- colour included, so a coloured pill renders the same in both modules.
CREATE TABLE IF NOT EXISTS `asset_field_options` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `field_id`          INT NOT NULL,
    `option_value`      VARCHAR(255) NOT NULL,
    `colour`            VARCHAR(7) NULL,
    `display_order`     INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `ix_asset_field_options_field` (`field_id`),
    CONSTRAINT `fk_asset_field_options_field` FOREIGN KEY (`field_id`) REFERENCES `asset_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 🔑 A field SET is a bundle you ATTACH, not a copy you make. "Peripheral
-- basics" = Serial + Warranty end, attached to Headset, Webcam and Keyboard;
-- add a field to the set and all three gain it at once. Without sets the same
-- two fields get re-declared fourteen times and adding a fifteenth means
-- editing fourteen types.
CREATE TABLE IF NOT EXISTS `asset_field_sets` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(150) NOT NULL,
    `description`       VARCHAR(500) NULL,
    `display_order`     INT NOT NULL DEFAULT 0,
    `tenant_id`         INT NULL,
    `is_deleted`        TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_asset_field_sets_tenant` (`tenant_id`),
    CONSTRAINT `fk_asset_field_sets_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What is in a set. 🔑 `is_required` lives HERE, not on the field: a serial
-- number may be required for laptops and optional for keyboards — same field,
-- different obligation.
CREATE TABLE IF NOT EXISTS `asset_field_set_fields` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `set_id`            INT NOT NULL,
    `field_id`          INT NOT NULL,
    `sort_order`        INT NOT NULL DEFAULT 0,
    `is_required`       TINYINT(1) NOT NULL DEFAULT 0,
    `default_value`     VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_asset_field_set_field` (`set_id`, `field_id`),
    KEY `ix_afsf_field` (`field_id`),
    CONSTRAINT `fk_afsf_set` FOREIGN KEY (`set_id`) REFERENCES `asset_field_sets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_afsf_field` FOREIGN KEY (`field_id`) REFERENCES `asset_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A set attached to an asset TYPE — every Television gets these fields.
CREATE TABLE IF NOT EXISTS `asset_type_field_sets` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `asset_type_id`     INT NOT NULL,
    `set_id`            INT NOT NULL,
    `sort_order`        INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_asset_type_field_set` (`asset_type_id`, `set_id`),
    KEY `ix_atfs_set` (`set_id`),
    CONSTRAINT `fk_atfs_type` FOREIGN KEY (`asset_type_id`) REFERENCES `asset_types` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_atfs_set` FOREIGN KEY (`set_id`) REFERENCES `asset_field_sets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A set attached to ONE asset — the pilot case. Ten televisions, three of them
-- trialled as smart TVs: tick "Smart TV" on those three and only those three
-- show IP address, MAC address and Netflix enabled. The other seven do not
-- carry empty fields, they carry no fields.
--
-- 🔑 It attaches a SET, never a loose field. If any asset could invent its own
-- field, no two assets would be comparable and "list every smart TV's IP
-- address" becomes unanswerable. The constraint is the feature.
CREATE TABLE IF NOT EXISTS `asset_field_set_assets` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `asset_id`              INT NOT NULL,
    `set_id`                INT NOT NULL,
    `created_by_analyst_id` INT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_asset_field_set_asset` (`asset_id`, `set_id`),
    KEY `ix_afsa_set` (`set_id`),
    CONSTRAINT `fk_afsa_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_afsa_set` FOREIGN KEY (`set_id`) REFERENCES `asset_field_sets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_afsa_analyst` FOREIGN KEY (`created_by_analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The answers. One row per asset per field. 🔑 NO ROW MEANS NOT SET — which is
-- exactly what a wide table cannot express: a `smart_tv_ip` column would sit
-- empty on every laptop in the estate forever.
--
-- No tenant_id: inherited from the asset, as cmdb_object_properties and
-- ticket_assets inherit theirs.
CREATE TABLE IF NOT EXISTS `asset_field_values` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `asset_id`      INT NOT NULL,
    `field_id`      INT NOT NULL,
    -- Always 0 today. Reserved so that multi-value fields can be added later
    -- without surgery on the unique key: Database Verification restores columns
    -- and primary keys, not changes to an existing UNIQUE index.
    `seq`           INT NOT NULL DEFAULT 0,
    `value_text`    TEXT NULL,
    `value_number`  DECIMAL(20,4) NULL,
    `value_date`    DATETIME NULL,
    `value_boolean` TINYINT(1) NULL,
    -- ⚠️ Polymorphic — which table this points at is decided by the field's
    -- `config.ref_kind` and the registry in includes/typed_fields.php. No FK is
    -- possible, so a dangling reference is caught by a sweep, never by a hook.
    -- Same rule as the `documents` block.
    `value_ref_id`  INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_asset_field_value` (`asset_id`, `field_id`, `seq`),
    -- The filter indexes cmdb_object_properties never got. Without these,
    -- "every asset whose Warranty Provider is X" scans every value in the table.
    KEY `ix_afv_field_text` (`field_id`, `value_text`(64)),
    KEY `ix_afv_field_number` (`field_id`, `value_number`),
    KEY `ix_afv_field_date` (`field_id`, `value_date`),
    CONSTRAINT `fk_afv_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_afv_field` FOREIGN KEY (`field_id`) REFERENCES `asset_fields` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Asset import — CSV now, an API source later.
--
-- 🔑 CSV and API are the SAME feature. A source produces rows of key => value;
-- everything after that — map, reconcile, validate, apply, log — is shared, and
-- only the front half differs (parse a file vs fetch and walk a JSON path).
-- The schema is therefore written for both, and `source_kind` is the only
-- column that knows the difference.
--
-- See docs/design/flexible-asset-fields.md §6.
-- ============================================================================

-- A saved, re-runnable import. The mapping is worth keeping precisely because
-- the same spreadsheet arrives again next month.
CREATE TABLE IF NOT EXISTS `asset_import_profiles` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(150) NOT NULL,
    -- What is being imported INTO. Only 'asset' is built; the column exists from
    -- day one so a CMDB import is a later commit rather than a new project —
    -- which is also the parked "how does an estate get into the CMDB?" question.
    `target`            VARCHAR(20) NOT NULL DEFAULT 'asset',
    `source_kind`       VARCHAR(10) NOT NULL DEFAULT 'csv',   -- csv | api
    -- JSON. csv: delimiter, encoding, has_header. api: url, auth, root path…
    `source_config`     LONGTEXT NULL,
    -- 🔑 ORDERED JSON list of the columns that identify a row — the single most
    -- important setting here. See asset_import_run_entries for what happens on
    -- 0 / 1 / many matches.
    `match_keys`        LONGTEXT NULL,
    -- What to do with a row that was in the source last time and is gone now.
    -- Never a default guess: ignore | flag | deactivate.
    `on_missing`        VARCHAR(20) NOT NULL DEFAULT 'ignore',
    -- A dropdown value the field's option list does not have: reject | add.
    `on_unknown_option` VARCHAR(10) NOT NULL DEFAULT 'reject',
    -- fill = only populate blanks; overwrite = the source wins. The blunt
    -- version of source precedence; per-field precedence is a later job.
    `write_mode`        VARCHAR(10) NOT NULL DEFAULT 'fill',
    -- Applied to rows that do not name their own.
    `default_asset_type_id`   INT NULL,
    `default_status_id`       INT NULL,
    -- A field set attached to every asset this profile touches — how a CSV of
    -- pilot smart TVs carries its own extra fields.
    `apply_field_set_id`      INT NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `tenant_id`         INT NULL,
    `created_by_analyst_id`   INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_aip_tenant` (`tenant_id`),
    CONSTRAINT `fk_aip_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_aip_type` FOREIGN KEY (`default_asset_type_id`) REFERENCES `asset_types` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_aip_status` FOREIGN KEY (`default_status_id`) REFERENCES `asset_status_types` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_aip_set` FOREIGN KEY (`apply_field_set_id`) REFERENCES `asset_field_sets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Source column -> where it lands. `target_kind` says which side of the fence:
-- a built-in `assets` column, or a custom field by its stable field_key.
CREATE TABLE IF NOT EXISTS `asset_import_mappings` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `profile_id`        INT NOT NULL,
    -- A CSV header, or (later) a JSON path.
    `source_key`        VARCHAR(255) NOT NULL,
    `target_kind`       VARCHAR(10) NOT NULL,      -- core | field
    -- 'hostname' for core; a field_key for field. ⚠️ The KEY, never the label —
    -- renaming a field's label must not break a nightly import.
    `target_key`        VARCHAR(100) NOT NULL,
    -- JSON: trim, case, date format, value substitutions.
    `transform`         LONGTEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_aim_profile_source` (`profile_id`, `source_key`),
    CONSTRAINT `fk_aim_profile` FOREIGN KEY (`profile_id`) REFERENCES `asset_import_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One run. Mirrors directory_sync_runs, including `mode`, because that design
-- already answers "what did it do, and can I see it before it does it?".
CREATE TABLE IF NOT EXISTS `asset_import_runs` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `profile_id`        INT NULL,
    -- 🔑 preview is not a lesser run — it is the SAME run that stops before
    -- writing. Anything a preview cannot tell you, a live run would surprise
    -- you with.
    `mode`              VARCHAR(10) NOT NULL DEFAULT 'live',   -- live | preview
    `status`            VARCHAR(12) NOT NULL DEFAULT 'running',-- running | ok | stopped | failed
    `source_name`       VARCHAR(255) NULL,     -- the uploaded file's own name, for the log
    `stored_file`       VARCHAR(255) NULL,     -- our generated name; a preview keeps it so
                                               -- committing does not need a re-upload
    `started_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_datetime` DATETIME NULL,
    `seen_count`        INT NOT NULL DEFAULT 0,
    `created_count`     INT NOT NULL DEFAULT 0,
    `updated_count`     INT NOT NULL DEFAULT 0,
    `unchanged_count`   INT NOT NULL DEFAULT 0,
    `conflict_count`    INT NOT NULL DEFAULT 0,
    `skipped_count`     INT NOT NULL DEFAULT 0,
    `error_count`       INT NOT NULL DEFAULT 0,
    `message`           TEXT NULL,
    `triggered_by_analyst_id` INT NULL,
    `tenant_id`         INT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_air_profile` (`profile_id`, `started_datetime`),
    KEY `ix_air_status` (`status`),
    CONSTRAINT `fk_air_profile` FOREIGN KEY (`profile_id`) REFERENCES `asset_import_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the run did to each ROW. "47 updated" is a number; this is the answer to
-- "updated how, and which?", which is the only version anybody can act on.
--
-- 🔑 THIS IS ALSO THE HOLDING AREA. A row that could not be imported is kept
-- here with `action = 'error'` and its source line verbatim in `raw_row`, to be
-- reviewed, corrected and retried — rather than silently dropped (the data and
-- the reason are both lost) or silently auto-created (which invents an asset
-- type called "Televsion" the first time somebody typos a spreadsheet).
CREATE TABLE IF NOT EXISTS `asset_import_run_entries` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `run_id`            INT NOT NULL,
    `row_number`        INT NULL,              -- line in the source, for "row 14 is wrong"
    -- create | update | unchanged | conflict | skip | error | deactivate
    `action`            VARCHAR(16) NOT NULL,
    `asset_id`          INT NULL,              -- NULL on a preview, a skip or an error
    `source_ref`        VARCHAR(255) NULL,     -- the value the match key was tried on
    `display_name`      VARCHAR(255) NULL,
    -- Human-readable: which fields changed, or exactly why nothing happened.
    `detail`            VARCHAR(1000) NULL,
    -- ⚠️ The ONE place JSON belongs in this design: the source row verbatim, so
    -- a run is diffable and a parked row can be retried without re-fetching.
    `raw_row`           LONGTEXT NULL,
    -- Set when a parked row has been dealt with, so the holding area empties.
    `resolved_datetime` DATETIME NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_aire_run` (`run_id`, `action`),
    KEY `ix_aire_asset` (`asset_id`),
    -- The holding area's own query: everything still needing attention.
    KEY `ix_aire_unresolved` (`action`, `resolved_datetime`),
    CONSTRAINT `fk_aire_run` FOREIGN KEY (`run_id`) REFERENCES `asset_import_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_dashboard_widgets` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `title`                 VARCHAR(100) NOT NULL,
    `description`           VARCHAR(255) NULL,
    `chart_type`            VARCHAR(20) NOT NULL DEFAULT 'bar',
    `aggregate_property`    VARCHAR(50) NOT NULL,
    `is_status_filterable`  TINYINT(1) NOT NULL DEFAULT 1,
    `default_status_id`     INT NULL,
    `display_order`         INT NOT NULL DEFAULT 0,
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analyst_dashboard_widgets` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `widget_id`         INT NOT NULL,
    `sort_order`        INT NOT NULL DEFAULT 0,
    `status_filter_id`  INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_analyst_widget` (`analyst_id`, `widget_id`),
    CONSTRAINT `fk_adw_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_adw_widget` FOREIGN KEY (`widget_id`) REFERENCES `asset_dashboard_widgets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: Asset Dashboard Widgets
INSERT IGNORE INTO `asset_dashboard_widgets` (`id`, `title`, `description`, `chart_type`, `aggregate_property`, `is_status_filterable`, `default_status_id`, `display_order`) VALUES
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
(13, 'BIOS Version',      'BIOS versions across the estate',                       'bar',      'bios_version',      1, NULL, 13);

-- =====================================================
-- Ticket Dashboard Widgets
-- =====================================================

CREATE TABLE IF NOT EXISTS `ticket_dashboard_widgets` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `title`                 VARCHAR(100) NOT NULL,
    `description`           VARCHAR(255) NULL,
    `chart_type`            VARCHAR(20) NOT NULL DEFAULT 'bar',
    `aggregate_property`    VARCHAR(50) NOT NULL,
    `series_property`       VARCHAR(20) NULL DEFAULT NULL,
    `is_status_filterable`  TINYINT(1) NOT NULL DEFAULT 1,
    `default_status`        VARCHAR(50) NULL,
    `date_range`            VARCHAR(20) NULL DEFAULT NULL,
    `department_filter`     JSON NULL DEFAULT NULL,
    `time_grouping`         VARCHAR(10) NULL DEFAULT NULL,
    `display_order`         INT NOT NULL DEFAULT 0,
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analyst_ticket_dashboard_widgets` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `widget_id`         INT NOT NULL,
    `sort_order`        INT NOT NULL DEFAULT 0,
    `status_filter`     VARCHAR(50) NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_analyst_ticket_widget` (`analyst_id`, `widget_id`),
    CONSTRAINT `fk_atdw_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_atdw_widget` FOREIGN KEY (`widget_id`) REFERENCES `ticket_dashboard_widgets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: Ticket Dashboard Widgets
INSERT IGNORE INTO `ticket_dashboard_widgets` (`id`, `title`, `description`, `chart_type`, `aggregate_property`, `series_property`, `is_status_filterable`, `default_status`, `date_range`, `department_filter`, `time_grouping`, `display_order`) VALUES
(1,  'Tickets by status',             'Distribution of tickets by current status',                 'doughnut', 'status',            NULL,       0, NULL, NULL,         NULL, NULL,    1),
(2,  'Tickets by priority',           'Breakdown of tickets by priority level',                    'doughnut', 'priority',          NULL,       1, NULL, NULL,         NULL, NULL,    2),
(3,  'Tickets by department',         'Ticket volume per department',                              'bar',      'department',        NULL,       1, NULL, NULL,         NULL, NULL,    3),
(4,  'Tickets by type',               'Incidents, service requests, problems and tasks',           'doughnut', 'ticket_type',       NULL,       1, NULL, NULL,         NULL, NULL,    4),
(5,  'Tickets by analyst',            'Ticket count per assigned analyst',                         'bar',      'analyst',           NULL,       1, NULL, NULL,         NULL, NULL,    5),
(6,  'Tickets by origin',             'How tickets are being raised',                              'doughnut', 'origin',            NULL,       1, NULL, NULL,         NULL, NULL,    6),
(7,  'First time fix rate',           'Proportion of tickets resolved on first contact',           'doughnut', 'first_time_fix',    NULL,       1, NULL, NULL,         NULL, NULL,    7),
(8,  'Created per day',               'Tickets created each day this month',                       'bar',      'created',           NULL,       0, NULL, 'this_month', NULL, 'day',   8),
(9,  'Closed per day',                'Tickets closed each day this month',                        'bar',      'closed',            NULL,       0, NULL, 'this_month', NULL, 'day',   9),
(10, 'Created per month',             'Monthly ticket creation over the last 12 months',           'bar',      'created',           NULL,       0, NULL, '12m',        NULL, 'month', 10),
(11, 'Closed per month',              'Monthly ticket closures over the last 12 months',           'bar',      'closed',            NULL,       0, NULL, '12m',        NULL, 'month', 11),
(12, 'Created vs closed (monthly)',   'Compare ticket creation and closure rates by month',        'line',     'created_vs_closed', NULL,       0, NULL, '12m',        NULL, 'month', 12),
(13, 'Monthly created by status',     'Monthly ticket creation broken down by current status',     'bar',      'created',           'status',   0, NULL, '12m',        NULL, 'month', 13),
(14, 'Monthly created by priority',   'Monthly ticket creation broken down by priority',           'bar',      'created',           'priority', 0, NULL, '12m',        NULL, 'month', 14),
(15, 'Dept breakdown by priority',    'Tickets per department broken down by priority level',      'bar',      'department',        'priority', 1, NULL, NULL,         NULL, NULL,    15);

CREATE TABLE IF NOT EXISTS `software_dashboard_widgets` (
    `id`                        INT NOT NULL AUTO_INCREMENT,
    `title`                     VARCHAR(100) NOT NULL,
    `description`               VARCHAR(255) NULL,
    `chart_type`                VARCHAR(20) NOT NULL DEFAULT 'bar',
    `aggregate_property`        VARCHAR(50) NOT NULL DEFAULT 'version_distribution',
    `app_id`                    INT NULL,
    `exclude_system_components` TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`             INT NOT NULL DEFAULT 0,
    `is_active`                 TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_sdw_app` FOREIGN KEY (`app_id`) REFERENCES `software_inventory_apps` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analyst_software_dashboard_widgets` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `widget_id`         INT NOT NULL,
    `sort_order`        INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_analyst_software_widget` (`analyst_id`, `widget_id`),
    CONSTRAINT `fk_asdw_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_asdw_widget` FOREIGN KEY (`widget_id`) REFERENCES `software_dashboard_widgets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: Software Dashboard Widgets
INSERT IGNORE INTO `software_dashboard_widgets` (`id`, `title`, `description`, `chart_type`, `aggregate_property`, `app_id`, `exclude_system_components`, `display_order`) VALUES
(1, 'Top Installed Applications', 'Most installed applications across all machines', 'bar', 'top_installed', NULL, 1, 1),
(2, 'Publisher Distribution', 'Software distribution by publisher', 'doughnut', 'publisher_distribution', NULL, 1, 2);

CREATE TABLE IF NOT EXISTS `servers` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `vm_id`             VARCHAR(100) NOT NULL,
    `name`              VARCHAR(255) NULL,
    `power_state`       VARCHAR(20) NULL,
    `memory_gb`         DECIMAL(10,2) NULL,
    `num_cpu`           INT NULL,
    `ip_address`        VARCHAR(50) NULL,
    `hard_disk_size_gb` DECIMAL(10,2) NULL,
    `host`              VARCHAR(255) NULL,
    `cluster`           VARCHAR(255) NULL,
    `guest_os`          VARCHAR(255) NULL,
    `last_synced`       DATETIME NULL,
    `raw_data`          LONGTEXT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Microsoft InTune Integration
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `intune_devices` (
    `id`                            INT NOT NULL AUTO_INCREMENT,
    `intune_id`                     VARCHAR(64) NOT NULL,
    `asset_id`                      INT NULL,
    `device_name`                   VARCHAR(256) NULL,
    `user_principal_name`           VARCHAR(256) NULL,
    `user_display_name`             VARCHAR(256) NULL,
    `user_id`                       VARCHAR(64) NULL,
    `operating_system`              VARCHAR(64) NULL,
    `os_version`                    VARCHAR(64) NULL,
    `compliance_state`              VARCHAR(32) NULL,
    `management_state`              VARCHAR(32) NULL,
    `managed_device_owner_type`     VARCHAR(32) NULL,
    `device_enrollment_type`        VARCHAR(64) NULL,
    `device_registration_state`     VARCHAR(32) NULL,
    `enrolled_datetime`             DATETIME NULL,
    `last_sync_datetime`            DATETIME NULL,
    `model`                         VARCHAR(128) NULL,
    `manufacturer`                  VARCHAR(128) NULL,
    `serial_number`                 VARCHAR(128) NULL,
    `imei`                          VARCHAR(64) NULL,
    `meid`                          VARCHAR(64) NULL,
    `wifi_mac_address`              VARCHAR(64) NULL,
    `ethernet_mac_address`          VARCHAR(64) NULL,
    `azure_ad_device_id`            VARCHAR(64) NULL,
    `is_encrypted`                  TINYINT(1) NULL,
    `is_supervised`                 TINYINT(1) NULL,
    `jail_broken`                   VARCHAR(16) NULL,
    `total_storage_bytes`           BIGINT NULL,
    `free_storage_bytes`            BIGINT NULL,
    `raw_json`                      LONGTEXT NULL,
    `last_seen_local`               DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_intune_devices_intune_id` (`intune_id`),
    KEY `ix_intune_devices_asset_id` (`asset_id`),
    KEY `ix_intune_devices_device_name` (`device_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `intune_sync_jobs` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `started_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_datetime`     DATETIME NULL,
    `status`                VARCHAR(16) NOT NULL DEFAULT 'running',
    `total`                 INT NOT NULL DEFAULT 0,
    `processed`             INT NOT NULL DEFAULT 0,
    `message`               LONGTEXT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `intune_app_sync_jobs` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `started_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_datetime` DATETIME NULL,
    `status`            VARCHAR(16) NOT NULL DEFAULT 'pending',
    `total`             INT NOT NULL DEFAULT 0,
    `processed`         INT NOT NULL DEFAULT 0,
    `failed`            INT NOT NULL DEFAULT 0,
    `message`           LONGTEXT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_intune_app_sync_jobs_status` (`status`),
    KEY `ix_intune_app_sync_jobs_started` (`started_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `intune_app_sync_job_assets` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `job_id`            INT NOT NULL,
    `asset_id`          INT NOT NULL,
    `status`            VARCHAR(16) NOT NULL DEFAULT 'pending',
    `error_message`     LONGTEXT NULL,
    `synced_datetime`   DATETIME NULL,
    `app_count`         INT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_intune_app_sync_job_assets_job` (`job_id`),
    KEY `ix_intune_app_sync_job_assets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Change Management
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `change_types` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `colour`            VARCHAR(20) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_change_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `change_types` (`name`, `colour`, `is_default`, `display_order`) VALUES
    ('Standard',  '#16a34a', 0, 10),
    ('Normal',    '#2563eb', 1, 20),
    ('Emergency', '#dc2626', 0, 30);

CREATE TABLE IF NOT EXISTS `change_statuses` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `is_closed`         TINYINT(1) NOT NULL DEFAULT 0,
    `colour`            VARCHAR(20) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_change_statuses_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `change_statuses` (`name`, `is_closed`, `colour`, `is_default`, `display_order`) VALUES
    ('Draft',            0, '#9e9e9e', 1, 10),
    ('Submitted',        0, '#2563eb', 0, 20),
    ('Pending Approval', 0, '#e65100', 0, 30),
    ('Approved',         0, '#2e7d32', 0, 40),
    ('Rejected',         1, '#c62828', 0, 50),
    ('Scheduled',        0, '#9333ea', 0, 60),
    ('In Progress',      0, '#1565c0', 0, 70),
    ('Completed',        1, '#1b5e20', 0, 80),
    ('Failed',           1, '#c62828', 0, 90),
    ('Cancelled',        1, '#bdbdbd', 0, 100);

CREATE TABLE IF NOT EXISTS `change_priorities` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `colour`            VARCHAR(20) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_change_priorities_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `change_priorities` (`name`, `colour`, `is_default`, `display_order`) VALUES
    ('Low',      '#16a34a', 0, 10),
    ('Medium',   '#2563eb', 1, 20),
    ('High',     '#f59e0b', 0, 30),
    ('Critical', '#dc2626', 0, 40);

CREATE TABLE IF NOT EXISTS `change_impacts` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `colour`            VARCHAR(20) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_change_impacts_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `change_impacts` (`name`, `colour`, `is_default`, `display_order`) VALUES
    ('Low',    '#16a34a', 0, 10),
    ('Medium', '#2563eb', 1, 20),
    ('High',   '#f59e0b', 0, 30);

-- Change form layout tables — admin-editable sections + per-field placement.
CREATE TABLE IF NOT EXISTS `change_field_sections` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `change_field_sections` (`id`, `name`, `display_order`) VALUES
    (1, 'General information', 10),
    (2, 'People',              20),
    (3, 'Schedule',            30),
    (4, 'Details',             40),
    (5, 'Attachments',         50);

CREATE TABLE IF NOT EXISTS `change_field_layout` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `field_key`      VARCHAR(50) NOT NULL,
    `section_id`     INT NOT NULL,
    `display_order`  INT NOT NULL DEFAULT 0,
    `is_visible`     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cfl_field_key` (`field_key`),
    CONSTRAINT `fk_cfl_section` FOREIGN KEY (`section_id`) REFERENCES `change_field_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `change_field_layout` (`field_key`, `section_id`, `display_order`, `is_visible`) VALUES
    ('title',        1, 10, 1),
    ('change_type',  1, 20, 1),
    ('status',       1, 30, 1),
    ('priority',     1, 40, 1),
    ('impact',       1, 50, 1),
    ('category',     1, 60, 1),
    ('requester',    2, 10, 1),
    ('assigned_to',  2, 20, 1),
    ('approver',     2, 30, 1),
    ('cab',          2, 40, 1),
    ('work_start',   3, 10, 1),
    ('work_end',     3, 20, 1),
    ('outage_start', 3, 30, 1),
    ('outage_end',   3, 40, 1),
    ('description',  4, 10, 1),
    ('reason',       4, 20, 1),
    ('risk',         4, 30, 1),
    ('testplan',     4, 40, 1),
    ('rollback',     4, 50, 1),
    ('pir',          4, 60, 1),
    ('attachments',  5, 10, 1);

CREATE TABLE IF NOT EXISTS `changes` (
    `id`                            INT NOT NULL AUTO_INCREMENT,
    `tenant_id`                     INT NULL,
    `title`                         VARCHAR(255) NOT NULL,
    `change_type_id`                INT NULL,
    `status_id`                     INT NULL,
    `priority_id`                   INT NULL,
    `impact_id`                     INT NULL,
    `category`                      VARCHAR(100) NULL,
    `requester_id`                  INT NULL,
    `assigned_to_id`                INT NULL,
    `approver_id`                   INT NULL,
    `approval_datetime`             DATETIME NULL,
    `work_start_datetime`           DATETIME NULL,
    `work_end_datetime`             DATETIME NULL,
    `outage_start_datetime`         DATETIME NULL,
    `outage_end_datetime`           DATETIME NULL,
    `description`                   LONGTEXT NULL,
    `reason_for_change`             LONGTEXT NULL,
    `risk_evaluation`               LONGTEXT NULL,
    `test_plan`                     LONGTEXT NULL,
    `rollback_plan`                 LONGTEXT NULL,
    `post_implementation_review`    LONGTEXT NULL,
    `risk_likelihood`               TINYINT NULL,
    `risk_impact_score`             TINYINT NULL,
    `risk_score`                    TINYINT NULL,
    `risk_level`                    VARCHAR(20) NULL,
    `pir_was_successful`            TINYINT(1) NULL,
    `pir_actual_start`              DATETIME NULL,
    `pir_actual_end`                DATETIME NULL,
    `pir_lessons_learned`           LONGTEXT NULL,
    `pir_follow_up`                 LONGTEXT NULL,
    `category_id`                   INT NULL,
    `template_id`                   INT NULL,
    `cab_required`                  TINYINT(1) NOT NULL DEFAULT 0,
    `cab_approval_type`             VARCHAR(20) NOT NULL DEFAULT 'all',
    `created_by_id`                 INT NULL,
    `created_datetime`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `modified_datetime`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `ix_changes_status_id` (`status_id`),
    KEY `ix_changes_priority_id` (`priority_id`),
    KEY `ix_changes_change_type_id` (`change_type_id`),
    KEY `ix_changes_impact_id` (`impact_id`),
    KEY `ix_changes_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_changes_requester` FOREIGN KEY (`requester_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_changes_assigned_to` FOREIGN KEY (`assigned_to_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_changes_approver` FOREIGN KEY (`approver_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_changes_created_by` FOREIGN KEY (`created_by_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_changes_status` FOREIGN KEY (`status_id`) REFERENCES `change_statuses` (`id`),
    CONSTRAINT `fk_changes_priority` FOREIGN KEY (`priority_id`) REFERENCES `change_priorities` (`id`),
    CONSTRAINT `fk_changes_change_type` FOREIGN KEY (`change_type_id`) REFERENCES `change_types` (`id`),
    CONSTRAINT `fk_changes_impact` FOREIGN KEY (`impact_id`) REFERENCES `change_impacts` (`id`),
    CONSTRAINT `fk_changes_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `change_attachments` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `change_id`         INT NOT NULL,
    `file_name`         VARCHAR(255) NOT NULL,
    `file_path`         VARCHAR(500) NOT NULL,
    `file_size`         INT NULL,
    `file_type`         VARCHAR(100) NULL,
    `uploaded_by_id`    INT NULL,
    `uploaded_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_change_attachments_change` FOREIGN KEY (`change_id`) REFERENCES `changes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_change_attachments_uploaded_by` FOREIGN KEY (`uploaded_by_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `change_audit` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `change_id`         INT NOT NULL,
    `analyst_id`        INT NOT NULL,
    `action_type`       VARCHAR(50) NOT NULL,
    `field_name`        VARCHAR(100) NULL,
    `old_value`         VARCHAR(1000) NULL,
    `new_value`         VARCHAR(1000) NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    INDEX `idx_change_audit_change` (`change_id`),
    CONSTRAINT `fk_change_audit_change` FOREIGN KEY (`change_id`) REFERENCES `changes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_change_audit_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `change_comments` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `change_id`         INT NOT NULL,
    `analyst_id`        INT NOT NULL,
    `comment_text`      LONGTEXT NOT NULL,
    `is_internal`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    INDEX `idx_change_comments_change` (`change_id`),
    CONSTRAINT `fk_change_comments_change` FOREIGN KEY (`change_id`) REFERENCES `changes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_change_comments_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `change_cab_members` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `change_id`         INT NOT NULL,
    `analyst_id`        INT NOT NULL,
    `is_required`       TINYINT(1) NOT NULL DEFAULT 1,
    `vote`              VARCHAR(20) NULL,
    `vote_comment`      TEXT NULL,
    `vote_datetime`     DATETIME NULL,
    `added_by_id`       INT NULL,
    `added_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cab_change_analyst` (`change_id`, `analyst_id`),
    CONSTRAINT `fk_cab_change` FOREIGN KEY (`change_id`) REFERENCES `changes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cab_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_cab_added_by` FOREIGN KEY (`added_by_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `change_checklist_items` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `change_id`             INT NOT NULL,
    `description`           VARCHAR(500) NOT NULL,
    `is_completed`          TINYINT(1) NOT NULL DEFAULT 0,
    `completed_by_id`       INT NULL,
    `completed_datetime`    DATETIME NULL,
    `display_order`         INT NOT NULL DEFAULT 0,
    `created_datetime`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_checklist_change` (`change_id`),
    CONSTRAINT `fk_checklist_change` FOREIGN KEY (`change_id`) REFERENCES `changes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_checklist_completed_by` FOREIGN KEY (`completed_by_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `change_relations` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `change_id`         INT NOT NULL,
    `related_type`      VARCHAR(20) NOT NULL,
    `related_id`        INT NOT NULL,
    `relation_type`     VARCHAR(30) NOT NULL,
    `created_by_id`     INT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_relations_change` (`change_id`),
    INDEX `idx_relations_related` (`related_type`, `related_id`),
    CONSTRAINT `fk_relations_change` FOREIGN KEY (`change_id`) REFERENCES `changes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_relations_created_by` FOREIGN KEY (`created_by_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Incidents (tickets) linked to a change — the Change Management twin of
-- problem_tickets. Right-click a ticket → "Link to change".
CREATE TABLE IF NOT EXISTS `change_tickets` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `change_id`        INT NOT NULL,
    `ticket_id`        INT NOT NULL,
    `created_by_id`    INT NULL,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_change_ticket` (`change_id`, `ticket_id`),
    KEY `ix_ctickets_ticket` (`ticket_id`),
    CONSTRAINT `fk_ctickets_change` FOREIGN KEY (`change_id`) REFERENCES `changes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ctickets_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `change_categories` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_change_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `change_templates` (
    `id`                        INT NOT NULL AUTO_INCREMENT,
    `name`                      VARCHAR(200) NOT NULL,
    `description`               VARCHAR(500) NULL,
    `change_type_id`            INT NULL,
    `priority_id`               INT NULL,
    `impact_id`                 INT NULL,
    `category_id`               INT NULL,
    `risk_likelihood`           TINYINT NULL,
    `risk_impact_score`         TINYINT NULL,
    `description_template`      LONGTEXT NULL,
    `reason_template`           LONGTEXT NULL,
    `risk_template`             LONGTEXT NULL,
    `test_plan_template`        LONGTEXT NULL,
    `rollback_plan_template`    LONGTEXT NULL,
    `is_active`                 TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`             INT NOT NULL DEFAULT 0,
    `created_by_id`             INT NULL,
    `created_datetime`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_template_category` FOREIGN KEY (`category_id`) REFERENCES `change_categories` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_template_created_by` FOREIGN KEY (`created_by_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_template_change_type` FOREIGN KEY (`change_type_id`) REFERENCES `change_types` (`id`),
    CONSTRAINT `fk_template_priority` FOREIGN KEY (`priority_id`) REFERENCES `change_priorities` (`id`),
    CONSTRAINT `fk_template_impact` FOREIGN KEY (`impact_id`) REFERENCES `change_impacts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `change_notifications` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `change_id`         INT NOT NULL,
    `notification_type` VARCHAR(50) NOT NULL,
    `message`           VARCHAR(500) NOT NULL,
    `is_read`           TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_notifications_analyst` (`analyst_id`, `is_read`),
    CONSTRAINT `fk_notification_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_notification_change` FOREIGN KEY (`change_id`) REFERENCES `changes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Problem Management
-- ----------------------------------------------------------
-- A Problem is the root cause behind one or more incidents (tickets). Company-scoped
-- via tenant_id like tickets (NULL = Default/triage); invisible at N=1.
CREATE TABLE IF NOT EXISTS `problems` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `tenant_id`           INT NULL,
    `problem_number`      VARCHAR(20) NULL,
    `title`               VARCHAR(255) NOT NULL,
    `description`         LONGTEXT NULL,
    `status_id`           INT NULL,
    `priority_id`         INT NULL,
    `assigned_analyst_id` INT NULL,
    `root_cause`          LONGTEXT NULL,
    `workaround`          LONGTEXT NULL,
    `is_known_error`      TINYINT(1) NOT NULL DEFAULT 0,
    `created_by_id`       INT NULL,
    `created_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closed_datetime`     DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `ix_problems_status_id` (`status_id`),
    KEY `ix_problems_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_problems_status` FOREIGN KEY (`status_id`) REFERENCES `problem_statuses` (`id`),
    CONSTRAINT `fk_problems_priority` FOREIGN KEY (`priority_id`) REFERENCES `problem_priorities` (`id`),
    CONSTRAINT `fk_problems_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `problem_statuses` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(100) NOT NULL,
    `is_closed`        TINYINT(1) NOT NULL DEFAULT 0,
    `colour`           VARCHAR(20) NULL,
    `is_default`       TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`    INT NOT NULL DEFAULT 0,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `problem_priorities` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(100) NOT NULL,
    `colour`           VARCHAR(20) NULL,
    `is_default`       TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`    INT NOT NULL DEFAULT 0,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The incident link: which tickets a problem explains.
CREATE TABLE IF NOT EXISTS `problem_tickets` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `problem_id`       INT NOT NULL,
    `ticket_id`        INT NOT NULL,
    `created_by_id`    INT NULL,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_problem_ticket` (`problem_id`, `ticket_id`),
    KEY `ix_ptickets_ticket` (`ticket_id`),
    CONSTRAINT `fk_ptickets_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ptickets_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ticket-to-ticket links (self-referential, typed). relation_type:
--   'related'   = symmetric (order doesn't matter; reciprocal duplicates blocked);
--   'duplicate' = source is a DUPLICATE OF target (target is the master);
--   'parent'    = source is the PARENT OF target (target is the child).
-- The service enforces: no self-link, at most one parent per child, at most one
-- duplicate-master per ticket, and same-company only on multi-tenant installs.
CREATE TABLE IF NOT EXISTS `ticket_links` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `source_ticket_id`  INT NOT NULL,
    `target_ticket_id`  INT NOT NULL,
    `relation_type`     VARCHAR(20) NOT NULL DEFAULT 'related',
    `created_by_id`     INT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_link` (`source_ticket_id`, `target_ticket_id`, `relation_type`),
    KEY `ix_ticket_links_target` (`target_ticket_id`),
    CONSTRAINT `fk_ticket_links_source` FOREIGN KEY (`source_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_links_target` FOREIGN KEY (`target_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `problem_audit` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `problem_id`       INT NOT NULL,
    `analyst_id`       INT NOT NULL,
    `action_type`      VARCHAR(20) NOT NULL,
    `field_name`       VARCHAR(100) NULL,
    `old_value`        VARCHAR(1000) NULL,
    `new_value`        VARCHAR(1000) NULL,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_paudit_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Free-text journal notes on a problem (who / when / the note).
CREATE TABLE IF NOT EXISTS `problem_notes` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `problem_id`       INT NOT NULL,
    `analyst_id`       INT NULL,
    `note`             LONGTEXT NOT NULL,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_pnotes_problem` (`problem_id`),
    CONSTRAINT `fk_pnotes_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Calendar
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `calendar_categories` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100) NOT NULL,
    `color`         VARCHAR(7) NOT NULL DEFAULT '#ef6c00',
    `description`   VARCHAR(500) NULL,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendar_events` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `title`             VARCHAR(255) NOT NULL,
    `description`       LONGTEXT NULL,
    `category_id`       INT NULL,
    `start_datetime`    DATETIME NOT NULL,
    `end_datetime`      DATETIME NULL,
    `all_day`           TINYINT(1) NOT NULL DEFAULT 0,
    `location`          VARCHAR(255) NULL,
    `contract_id`       INT NULL,
    `created_by`        INT NOT NULL,
    -- Marks auto-generated events (e.g. 'asset_warranty'); NULL = a normal,
    -- user-created event. Lets a generator resync its own events without
    -- touching manual ones.
    `source`            VARCHAR(30) NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_calendar_events_category` FOREIGN KEY (`category_id`) REFERENCES `calendar_categories` (`id`),
    CONSTRAINT `fk_calendar_events_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------
-- Scheduled work -> the analyst's own calendar (GH discussion #75)
-- ----------------------------------------------------------
--
-- Three tables, and the split is the point:
--
--   calendar_connections  ONE per install (usually), configured by an admin.
--                         Microsoft app-only and Google Workspace domain-wide
--                         delegation BOTH authenticate as the app rather than as
--                         each person, so credentials belong here, not per user.
--   calendar_enrolments   ONE per analyst. Whether they want it at all, which
--                         connection, and WHICH ADDRESS is theirs.
--   calendar_sync_events  What we put where. Without this a reassignment leaves
--                         an orphan in somebody's calendar forever.
--
-- ⚠️ Provider-agnostic ON PURPOSE. FreeITSM already runs this pattern three
-- times (messaging: Twilio/Meta; issue trackers: Jira/Azure DevOps; mailboxes:
-- Microsoft/Google/IMAP) — see includes/integrations/IssueTrackerProvider.php,
-- which itself says it copied MessagingProvider rather than reinventing. Only
-- create/update/delete-an-event differs between providers; everything difficult
-- here (whose work, what happens on reassign, not orphaning) is shared.

CREATE TABLE IF NOT EXISTS `calendar_connections` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `name`                VARCHAR(100) NOT NULL,
    -- 'microsoft' today. 'google' is the next one and needs no schema change.
    `provider`            VARCHAR(20) NOT NULL DEFAULT 'microsoft',
    -- Encrypted JSON, same convention as integration_connections.credentials.
    -- NULL when borrowing a mailbox's credentials instead (below).
    `credentials`         LONGTEXT NULL,
    -- Borrow the Azure app registration already configured for a mailbox rather
    -- than registering a second app. Most installs have done the Azure dance for
    -- mail already; adding Calendars.ReadWrite to that app is far less work than
    -- starting again. NULL = this connection carries its own credentials.
    -- ⚠️ ON DELETE SET NULL, not CASCADE: deleting a mailbox must not silently
    -- delete the calendar connection and everyone's enrolment with it. It breaks
    -- loudly instead, which is recoverable.
    `mailbox_id`          INT NULL,
    `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
    -- Cached app-only access token, encrypted, exactly as target_mailboxes does
    -- it. App-only tokens carry no refresh token and last about an hour, so this
    -- is a cache and not a credential store — but it is still a bearer token that
    -- grants calendar access on its own, so it is encrypted like one.
    `token_data`          LONGTEXT NULL,
    `last_error`          VARCHAR(500) NULL,
    `last_error_datetime` DATETIME NULL,
    `created_by`          INT NULL,
    `created_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_calendar_connections_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `target_mailboxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendar_enrolments` (
    `id`                 INT NOT NULL AUTO_INCREMENT,
    `analyst_id`         INT NOT NULL,
    -- 'off' | 'push' | 'feed'. ONE choice, not independent switches: with both a
    -- push and a subscribed feed live you see every scheduled ticket TWICE, once
    -- as a real event and once from the subscription.
    `mode`               VARCHAR(10) NOT NULL DEFAULT 'off',
    -- What of a TASK reaches the calendar: 'off' | 'work' | 'due' | 'both' (#75).
    -- Separate from `mode`, which decides HOW things get there. Folding the two
    -- together would need eight values to say two independent things, and
    -- turning tickets off would silently take tasks with them. Defaults to
    -- 'off': nobody asked for their task list to appear in Outlook overnight.
    `task_mode`          VARCHAR(10) NOT NULL DEFAULT 'off',
    `connection_id`      INT NULL,
    -- The mailbox to write into. Normally analysts.email, but an analyst's
    -- FreeITSM address is not always their mailbox UPN — a local account with a
    -- personal address, or an LDAP import keyed differently. Without this the
    -- only fix would be changing the address they log in with.
    `calendar_address`   VARCHAR(255) NULL,
    -- Reserved: encrypted per-analyst tokens, for a provider that genuinely needs
    -- them (a personal, non-Workspace Google account). Neither provider planned
    -- today uses it — the row exists so adding one is not a migration.
    `credentials`        LONGTEXT NULL,
    -- Microsoft Graph delta token for reading CHANGES back out of this mailbox
    -- (GH #75, bi-directional). Opaque and long. NULL = never polled, so the
    -- next run takes a baseline and applies nothing.
    -- Graph change-notification subscription for this mailbox (GH #75).
    -- Notifications make a change land in seconds instead of on the next poll;
    -- the poll REMAINS as a backstop, because a notification that never arrives
    -- looks exactly like nothing having changed.
    `subscription_id`      VARCHAR(255) NULL,
    -- Graph caps calendar subscriptions at ~3 days, so the cron renews them.
    `subscription_expires` DATETIME NULL,
    -- Random per subscription, echoed by Graph in every notification. It is the
    -- ONLY thing distinguishing a real callback from anyone on the internet
    -- POSTing to a deliberately public endpoint.
    `subscription_secret`  VARCHAR(128) NULL,
    `delta_token`        TEXT NULL,
    `delta_synced_datetime` DATETIME NULL,
    `last_sync_datetime` DATETIME NULL,
    `last_error`         VARCHAR(500) NULL,
    `created_datetime`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_calendar_enrolment_analyst` (`analyst_id`),
    CONSTRAINT `fk_calendar_enrolments_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_calendar_enrolments_connection` FOREIGN KEY (`connection_id`) REFERENCES `calendar_connections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendar_sync_events` (
    `id`                 INT NOT NULL AUTO_INCREMENT,
    -- A row belongs to a ticket OR a task, never both, so both are nullable
    -- (#75). An install that predates tasks is relaxed by the probed MODIFY in
    -- api/system/db_verify.php.
    `ticket_id`          INT NULL,
    `task_id`            INT NULL,
    -- 'work' | 'due'. ONE TASK CAN PRODUCE TWO EVENTS: its scheduled work
    -- window and its due date. Without this the second write finds the first
    -- row and overwrites it, so an analyst who asked for both gets whichever
    -- was reconciled last. Tickets only ever have a work window, hence the
    -- default and no migration for existing rows.
    `kind`               VARCHAR(16) NOT NULL DEFAULT 'work',
    -- WHOSE calendar it went into. Not derivable from the ticket at delete time:
    -- reassignment changes tickets.owner_id, and by then this row is the only
    -- record of who the event was actually created for.
    `analyst_id`         INT NOT NULL,
    `connection_id`      INT NULL,
    -- The provider's id for the event. Graph's are long and opaque.
    `remote_event_id`    VARCHAR(500) NOT NULL,
    -- The address it was written to, stored rather than looked up: an analyst can
    -- change calendar_address, and the event still lives in the OLD mailbox.
    `remote_calendar`    VARCHAR(255) NOT NULL,
    `created_datetime`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- One event per ticket per person. A ticket reassigned A -> B -> A must not
    -- accumulate rows, and this is what makes "delete the old one" a lookup.
    -- One event per ticket per person. A ticket reassigned A -> B -> A must not
    -- accumulate rows, and this is what makes "delete the old one" a lookup.
    --
    -- Still correct now ticket_id is nullable: MySQL allows any number of NULLs
    -- in a UNIQUE index, so task rows pass straight through it rather than
    -- colliding on (NULL, analyst).
    UNIQUE KEY `uniq_calendar_sync_ticket_analyst` (`ticket_id`, `analyst_id`),
    KEY `idx_calendar_sync_ticket` (`ticket_id`),
    -- The task equivalent MUST include `kind`, or a task's work event and its
    -- due event are the same row.
    UNIQUE KEY `uniq_calendar_sync_task_analyst_kind` (`task_id`, `analyst_id`, `kind`),
    KEY `idx_calendar_sync_task` (`task_id`),
    CONSTRAINT `fk_calendar_sync_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_calendar_sync_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_calendar_sync_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks had NO history at all until calendar sync could change one from outside
-- FreeITSM (#75) - a due date dragged in Outlook is a change nobody in FreeITSM
-- made. Mirrors ticket_audit rather than inventing a second shape for the same
-- idea, plus a `source` so an Outlook-driven change is tellable from a person's.
CREATE TABLE IF NOT EXISTS `task_audit` (
    `id`                 INT NOT NULL AUTO_INCREMENT,
    `task_id`            INT NOT NULL,
    -- NULL when it was not a person: a calendar sync, a cron.
    `analyst_id`         INT NULL,
    `field_name`         VARCHAR(100) NOT NULL,
    `old_value`          VARCHAR(500) NULL,
    `new_value`          VARCHAR(500) NULL,
    `source`             VARCHAR(20) NOT NULL DEFAULT 'app',
    `created_datetime`   DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`            TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_task_audit_task` (`task_id`),
    CONSTRAINT `fk_task_audit_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_audit_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ----------------------------------------------------------
-- Morning Checks
-- ----------------------------------------------------------

-- Optional grouping for the morning round (discussion #64). A check may sit in
-- one group or none; ungrouped checks behave exactly as they always have, so an
-- install that never opens the Groups tab sees no change.
--
-- ⚠️ ASSIGNMENT HERE IS GUIDANCE, NOT A LOCK. Nothing in the save path consults
-- it. Whoever is in first thing can complete any check, which is the whole point
-- on the morning somebody is off sick — the request was for routing and
-- ownership, not for a permission.
CREATE TABLE IF NOT EXISTS `morningChecks_Groups` (
    `GroupID`           INT NOT NULL AUTO_INCREMENT,
    `GroupName`         VARCHAR(255) NOT NULL,
    `GroupDescription`  LONGTEXT NULL,
    -- A group may be routed to a team OR to one analyst. Both NULL = nobody in
    -- particular, which is the default and stays the common case.
    `AssignedTeamID`    INT NULL,
    `AssignedAnalystID` INT NULL,
    `IsActive`          TINYINT(1) NOT NULL DEFAULT 1,
    `SortOrder`         INT NOT NULL DEFAULT 0,
    `CreatedDate`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ModifiedDate`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`GroupID`),
    KEY `ix_mcg_team` (`AssignedTeamID`),
    KEY `ix_mcg_analyst` (`AssignedAnalystID`),
    CONSTRAINT `fk_mcg_team`    FOREIGN KEY (`AssignedTeamID`)    REFERENCES `teams` (`id`)     ON DELETE SET NULL,
    CONSTRAINT `fk_mcg_analyst` FOREIGN KEY (`AssignedAnalystID`) REFERENCES `analysts` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `morningChecks_Checks` (
    `CheckID`           INT NOT NULL AUTO_INCREMENT,
    `CheckName`         VARCHAR(255) NOT NULL,
    `CheckDescription`  LONGTEXT NULL,
    `IsActive`          TINYINT(1) NOT NULL DEFAULT 1,
    `SortOrder`         INT NOT NULL DEFAULT 0,
    -- Discussion #64. NULL group = ungrouped, which is where every existing
    -- check starts. A check's own analyst overrides whatever its group says.
    `GroupID`           INT NULL,
    `AssignedAnalystID` INT NULL,
    `CreatedDate`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ModifiedDate`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`CheckID`),
    KEY `ix_mcc_group` (`GroupID`),
    KEY `ix_mcc_analyst` (`AssignedAnalystID`),
    CONSTRAINT `fk_mcc_group`   FOREIGN KEY (`GroupID`)           REFERENCES `morningChecks_Groups` (`GroupID`) ON DELETE SET NULL,
    CONSTRAINT `fk_mcc_analyst` FOREIGN KEY (`AssignedAnalystID`) REFERENCES `analysts` (`id`)                  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurable status options for morning checks (drives the dashboard
-- status buttons and whether picking a status pops the notes modal).
-- Defined ABOVE morningChecks_Results so the FK in Results can reference it.
CREATE TABLE IF NOT EXISTS `morningChecks_Statuses` (
    `StatusID`        INT NOT NULL AUTO_INCREMENT,
    `Label`           VARCHAR(50) NOT NULL,
    `Colour`          VARCHAR(20) NOT NULL,
    `RequiresNotes`   TINYINT(1) NOT NULL DEFAULT 0,
    `SortOrder`       INT NOT NULL DEFAULT 0,
    `IsActive`        TINYINT(1) NOT NULL DEFAULT 1,
    `CreatedDate`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ModifiedDate`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`StatusID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `morningChecks_Statuses` (`StatusID`, `Label`, `Colour`, `RequiresNotes`, `SortOrder`, `IsActive`) VALUES
    (1, 'Green', '#28a745', 0, 10, 1),
    (2, 'Amber', '#ffc107', 1, 20, 1),
    (3, 'Red',   '#dc3545', 1, 30, 1);

CREATE TABLE IF NOT EXISTS `morningChecks_Results` (
    `ResultID`      INT NOT NULL AUTO_INCREMENT,
    `CheckID`       INT NOT NULL,
    `CheckDate`     DATETIME NOT NULL,
    -- Normalised FK to morningChecks_Statuses.StatusID. NULL allowed
    -- for orphan rows (pre-normalisation imports or rows whose status
    -- was later deleted — FK is ON DELETE SET NULL).
    `StatusID`      INT NULL,
    -- Label snapshot — nullable now that StatusID is the source of
    -- truth. Holds the original label for orphan rows so the
    -- normalisation tool in Settings can show what needs remapping.
    `Status`        VARCHAR(50) NULL,
    `Notes`         LONGTEXT NULL,
    `CreatedBy`     VARCHAR(100) NULL,
    -- Who most recently SET the status (discussion #64). Deliberately separate
    -- from CreatedBy, which keeps meaning "who first recorded a result today":
    -- the v1 API exposes that as created_by, and redefining it would silently
    -- change a published contract. The dashboard credits ModifiedBy and falls
    -- back to CreatedBy, so somebody covering for an absent colleague is
    -- attributed the check they actually did rather than the person who happened
    -- to touch it first.
    `ModifiedBy`    VARCHAR(100) NULL,
    `CreatedDate`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ModifiedDate`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`ResultID`),
    UNIQUE KEY `uq_check_date` (`CheckID`, `CheckDate`),
    CONSTRAINT `fk_results_checks` FOREIGN KEY (`CheckID`) REFERENCES `morningChecks_Checks` (`CheckID`),
    CONSTRAINT `fk_results_status` FOREIGN KEY (`StatusID`) REFERENCES `morningChecks_Statuses` (`StatusID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tickets and tasks raised FROM a morning check (discussion #64).
--
-- A link table rather than a column pair on the result, because one bad check
-- can legitimately raise several things — a ticket for the supplier and a task
-- to chase it — and because the alternative is two nullable columns that then
-- need a third the first time somebody wants a second ticket.
--
-- No FK to tickets/tasks on purpose: the row is a breadcrumb, and a ticket being
-- deleted or merged should not take the record of "this check raised something"
-- with it. EntityType keeps it honest about which table the id belongs to.
CREATE TABLE IF NOT EXISTS `morningChecks_ResultLinks` (
    `LinkID`        INT NOT NULL AUTO_INCREMENT,
    `ResultID`      INT NOT NULL,
    `EntityType`    VARCHAR(20) NOT NULL,   -- 'ticket' | 'task'
    `EntityID`      INT NOT NULL,
    `EntityRef`     VARCHAR(100) NULL,      -- ticket number / task title snapshot, for display without a join
    `CreatedByID`   INT NULL,
    `CreatedDate`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`LinkID`),
    KEY `ix_mcrl_result` (`ResultID`),
    CONSTRAINT `fk_mcrl_result` FOREIGN KEY (`ResultID`) REFERENCES `morningChecks_Results` (`ResultID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Knowledge Base
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `knowledge_articles` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `title`                 VARCHAR(255) NOT NULL,
    `body`                  LONGTEXT NULL,
    `author_id`             INT NOT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `modified_datetime`     DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_published`          TINYINT(1) NULL DEFAULT 1,
    `view_count`            INT NULL DEFAULT 0,
    `next_review_date`      DATE NULL,
    `owner_id`              INT NULL,
    `embedding`             LONGTEXT NULL,
    `embedding_updated`     DATETIME NULL,
    `is_archived`           TINYINT(1) NULL DEFAULT 0,
    `archived_datetime`     DATETIME NULL,
    `archived_by_id`        INT NULL,
    `version`               INT NOT NULL DEFAULT 1,
    `tenant_id`             INT NULL,
    `audience`              VARCHAR(20) NOT NULL DEFAULT 'internal',
    `folder_id`             INT NULL,
    `is_restricted`         TINYINT(1) NOT NULL DEFAULT 0,
    `inherit_permissions`   TINYINT(1) NOT NULL DEFAULT 1,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `idx_knowledge_articles_tenant` (`tenant_id`),
    KEY `idx_knowledge_articles_folder` (`folder_id`),
    CONSTRAINT `fk_knowledge_articles_author` FOREIGN KEY (`author_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_knowledge_articles_owner` FOREIGN KEY (`owner_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_knowledge_articles_archived_by` FOREIGN KEY (`archived_by_id`) REFERENCES `analysts` (`id`),
    -- NO "ON DELETE SET NULL" here, unlike fk_assets_tenant. For assets NULL means
    -- "unassigned"; for knowledge NULL means "shared with EVERY company" — so
    -- SET NULL would silently promote a deleted company's private articles into
    -- everyone's knowledge base. Restrict instead: deleting a company with its
    -- own articles must be a deliberate act, not a side effect.
    CONSTRAINT `fk_knowledge_articles_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- knowledge_articles.tenant_id => which client company OWNS this article.
--   ⚠️ NULL means SHARED WITH EVERY COMPANY here — NOT "belongs to Default", which
--   is what NULL means for tickets/assets/changes. Knowledge is the one module
--   where a NULL row is deliberately visible to all tenants (an MSP's generic
--   "how to reset your password" serves every client). Hence Knowledge has its
--   own filter helper and must NOT use activeTenantFilter(). See tenancy.php.
--   NULL is also the zero-migration default: existing articles stay shared,
--   which is exactly the pre-multi-tenancy behaviour.
-- knowledge_articles.audience => WHO may read it, independent of who owns it:
--   'internal' (analysts only) | 'customer' (+ signed-in self-service users)
--   | 'public' (+ anonymous web chat visitors). Defaults to 'internal' so an
--   upgrade can never start disclosing existing articles to the public — an
--   author opts in. See includes/knowledge/audience.php.
-- knowledge_articles.folder_id => which folder it lives in. NULL = the root,
--   which is where every existing article lands on upgrade: zero migration, and
--   the resulting state (root, inheriting, unrestricted) behaves exactly as the
--   module did before folders existed.
-- knowledge_articles.is_restricted       0 = Open (access rows are DENIES)
--                                        1 = Restricted (access rows are GRANTS)
-- knowledge_articles.inherit_permissions 1 = use the folder's rules, not my own.
--   Defaults to 1 so an upgraded article inherits from a root that restricts
--   nothing. See knowledge_folders below and includes/knowledge/visibility.php.


-- ----------------------------------------------------------
-- Knowledge folders and per-document permissions
--
-- Design: https://github.com/edmozley/freeitsm/wiki/Knowledge-Folders-and-Permissions
--
-- THE RULE: every axis NARROWS, nothing widens. An article is readable only if
-- the company owns it (or it is shared), the reader's trust level reaches its
-- audience, AND the access list does not exclude them. The axes are ANDed, never
-- weighed against each other -- so an access-list grant can never reach past the
-- "Who can see this" setting, and there is no precedence puzzle to solve.
-- ----------------------------------------------------------

-- A document lives in EXACTLY ONE folder. That is the load-bearing decision:
-- with several parents "inherit from parent" has no answer -- most-permissive
-- leaks, most-restrictive loses documents people filed themselves, and every
-- system that tried it ended up with an effective-permissions dialog nobody
-- could read. Appearing in two places is what knowledge_shortcuts is for.
CREATE TABLE IF NOT EXISTS `knowledge_folders` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `parent_id`           INT NULL,
    `name`                VARCHAR(255) NOT NULL,
    `is_restricted`       TINYINT(1) NOT NULL DEFAULT 0,
    `inherit_permissions` TINYINT(1) NOT NULL DEFAULT 1,
    `owner_id`            INT NULL,
    `tenant_id`           INT NULL,
    `created_by_id`       INT NULL,
    `created_datetime`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `modified_datetime`   DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_knowledge_folders_parent` (`parent_id`),
    KEY `idx_knowledge_folders_tenant` (`tenant_id`),
    -- A folder whose parent is deleted would be unreachable but still hold
    -- documents, so deleting a parent must be a deliberate act that moves or
    -- removes its children first.
    CONSTRAINT `fk_knowledge_folders_parent` FOREIGN KEY (`parent_id`) REFERENCES `knowledge_folders` (`id`),
    -- Same reasoning as fk_knowledge_articles_tenant: NO "ON DELETE SET NULL",
    -- because NULL here means SHARED WITH EVERY COMPANY, so SET NULL would
    -- silently promote a deleted company's private folder into everyone's tree.
    CONSTRAINT `fk_knowledge_folders_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- knowledge_folders.is_restricted       0 = Open (rows below are DENIES)
--                                       1 = Restricted (rows below are GRANTS)
-- knowledge_folders.inherit_permissions 1 = take the parent's rules, ignore my own
-- knowledge_folders.tenant_id           NULL = shared with EVERY company

-- THERE IS NO allow/deny COLUMN HERE, AND ITS ABSENCE IS THE GUARANTEE.
-- The polarity comes from the OBJECT: rows against an Open object are denies,
-- rows against a Restricted object are grants. A contradictory pair therefore
-- cannot be stored -- no precedence rule to remember, no effective-permissions
-- dialog to explain. Same instinct as the audience ladder: make the
-- contradiction inexpressible rather than adjudicating it.
--
-- Flipping an object's polarity WIPES its rows. A dormant wrong-polarity row
-- that springs back on the next flip is the "unloaded checkbox looks exactly
-- like OFF" failure wearing a different hat.
CREATE TABLE IF NOT EXISTS `knowledge_acl` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `object_type`      VARCHAR(10) NOT NULL,
    `object_id`        INT NOT NULL,
    `principal_type`   VARCHAR(12) NOT NULL,
    `principal_id`     INT NOT NULL,
    `created_by_id`    INT NULL,
    `created_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- The lookup every permission check makes.
    KEY `idx_knowledge_acl_object` (`object_type`, `object_id`),
    KEY `idx_knowledge_acl_principal` (`principal_type`, `principal_id`),
    -- One principal cannot be listed twice on the same object.
    UNIQUE KEY `uq_knowledge_acl` (`object_type`, `object_id`, `principal_type`, `principal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- knowledge_acl.object_type    'folder' | 'article'
-- knowledge_acl.principal_type 'analyst' | 'team' | 'user' | 'user_group'
-- No FOREIGN KEYs on object_id/principal_id: both are polymorphic, so there is
-- no single table to point at. Rows are cleaned up where the object is deleted.

-- A pointer with NO permissions of its own: it resolves to the target and the
-- TARGET's rules decide. This is what preserves the single-parent tree while
-- letting a document appear in two places.
--   * a shortcut can never GRANT -- if you cannot read the target it is not a way in
--   * shortcuts must be filtered by TARGET readability at list time, or the row
--     leaks the target's title, which is the search-snippet leak in a new hat
CREATE TABLE IF NOT EXISTS `knowledge_shortcuts` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `folder_id`        INT NULL,
    `article_id`       INT NOT NULL,
    `created_by_id`    INT NULL,
    `created_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_knowledge_shortcut` (`folder_id`, `article_id`),
    CONSTRAINT `fk_knowledge_shortcuts_folder` FOREIGN KEY (`folder_id`) REFERENCES `knowledge_folders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_knowledge_shortcuts_article` FOREIGN KEY (`article_id`) REFERENCES `knowledge_articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOT lms_learning_groups. The driving case is ad hoc and short-lived -- three
-- engineers on site for a week needing one folder -- and routing that through the
-- LMS to grant a document permission would be daft. `users` has no grouping of
-- any kind today, so a table was needed regardless.
CREATE TABLE IF NOT EXISTS `knowledge_user_groups` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(100) NOT NULL,
    `description`      VARCHAR(500) NULL,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_id`    INT NULL,
    `created_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_knowledge_user_groups_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `knowledge_user_group_members` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `group_id`         INT NOT NULL,
    `member_type`      VARCHAR(10) NOT NULL,
    `member_id`        INT NOT NULL,
    `expires_at`       DATETIME NULL,
    `created_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_knowledge_group_member` (`group_id`, `member_type`, `member_id`),
    KEY `idx_knowledge_group_member` (`member_type`, `member_id`),
    CONSTRAINT `fk_knowledge_group_members_group` FOREIGN KEY (`group_id`) REFERENCES `knowledge_user_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- knowledge_user_group_members.member_type 'analyst' | 'user'
-- knowledge_user_group_members.expires_at  THE REASON THIS TABLE EXISTS.
--   "For the week" is the requirement as stated, and an access list that quietly
--   stays open after the engineers go home is the failure worth designing out on
--   day one. NULL = no expiry.

-- Modelled on document_access_log, which already does this for attached files.
--
-- VIEWS ARE A DIFFERENT VOLUME CLASS FROM EDITS. knowledge_articles.view_count
-- already increments on every read, and a row per view on a busy KB is millions a
-- year. Creates, edits, permission changes, deletes and administrator-floor
-- passes are RARE, and are what somebody actually comes looking for -- so views
-- get sampled, deduped per person per day, or held to a retention window rather
-- than being allowed to bury them.
CREATE TABLE IF NOT EXISTS `knowledge_audit` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `object_type`      VARCHAR(10) NOT NULL,
    `object_id`        INT NOT NULL,
    `action`           VARCHAR(20) NOT NULL,
    `analyst_id`       INT NULL,
    `user_id`          INT NULL,
    `detail`           LONGTEXT NULL,
    `ip_address`       VARCHAR(45) NULL,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_knowledge_audit_object` (`object_type`, `object_id`),
    KEY `idx_knowledge_audit_action` (`action`, `created_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- knowledge_audit.action 'create' | 'edit' | 'view' | 'delete' | 'restore'
--                        | 'move' | 'permissions' | 'admin_override'
--   'admin_override' is written EVERY time the knowledge.admin floor lets
--   somebody past an access list they were not on. That floor exists so a
--   Restricted folder whose only grantee has left is recoverable rather than
--   lost -- but a permission that always passes must leave a trace, or it is
--   indistinguishable from not having a permission system.
CREATE TABLE IF NOT EXISTS `knowledge_article_versions` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `article_id`        INT NOT NULL,
    `version`           INT NOT NULL,
    `title`             VARCHAR(255) NOT NULL,
    `body`              LONGTEXT NULL,
    `saved_by_id`       INT NOT NULL,
    `saved_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_kav_article` FOREIGN KEY (`article_id`) REFERENCES `knowledge_articles` (`id`),
    CONSTRAINT `fk_kav_saved_by` FOREIGN KEY (`saved_by_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `knowledge_tags` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_knowledge_tags_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `knowledge_article_tags` (
    `article_id`    INT NOT NULL,
    `tag_id`        INT NOT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`article_id`, `tag_id`),
    CONSTRAINT `fk_article_tags_article` FOREIGN KEY (`article_id`) REFERENCES `knowledge_articles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_article_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `knowledge_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Knowledge gaps — the Knowledge assistant
--
-- One closed ticket at a time tells you almost nothing: "reset password, asked
-- user to log in again" is not an article. Fourteen of them is a different
-- statement entirely. These three tables hold the answer to "what has this
-- service desk answered over and over that the knowledge base never learned?"
--
-- knowledge_gap_tickets is a CACHE, not a source of truth: every row can be
-- deleted and rebuilt by re-running the analysis. It exists because embedding
-- a ticket costs a paid API call, so we do it once.
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `knowledge_gap_tickets` (
    `ticket_id`             INT NOT NULL,
    `embedding`             LONGTEXT NULL,
    `embedded_datetime`     DATETIME NULL,
    -- Similarity to the CLOSEST published article. Low = nothing in the KB
    -- answers this ticket, which is what makes it a gap candidate.
    `best_article_id`       INT NULL,
    `best_similarity`       FLOAT NULL,
    -- 0-100: how much an article could actually be written from this one
    -- ticket. Drives which ticket in a cluster gets drafted from, and whether
    -- the assistant has to interview the analyst instead.
    `richness`              INT NOT NULL DEFAULT 0,
    `analysed_datetime`     DATETIME NULL,
    `tenant_id`             INT NULL,
    PRIMARY KEY (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `knowledge_gap_clusters` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `label`                 VARCHAR(255) NOT NULL,
    `seed_ticket_id`        INT NULL,
    -- The ticket the assistant would draft FROM — the richest in the cluster,
    -- not the newest. A thin ticket counts towards the total but never gets
    -- handed to the model as the source.
    `best_ticket_id`        INT NULL,
    `max_richness`          INT NOT NULL DEFAULT 0,
    `ticket_count`          INT NOT NULL DEFAULT 0,
    `first_ticket_datetime` DATETIME NULL,
    `last_ticket_datetime`  DATETIME NULL,
    -- open | dismissed | written. 'dismissed' must survive a re-analysis, which
    -- is why clusters are stored at all rather than recomputed on each view.
    `status`                VARCHAR(20) NOT NULL DEFAULT 'open',
    `dismissed_by_id`       INT NULL,
    `dismissed_datetime`    DATETIME NULL,
    `article_id`            INT NULL,
    `signature`             VARCHAR(64) NULL,
    `tenant_id`             INT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_kgc_status` (`status`),
    KEY `ix_kgc_signature` (`signature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `knowledge_gap_cluster_tickets` (
    `cluster_id`    INT NOT NULL,
    `ticket_id`     INT NOT NULL,
    `similarity`    FLOAT NULL,
    PRIMARY KEY (`cluster_id`, `ticket_id`),
    CONSTRAINT `fk_kgct_cluster` FOREIGN KEY (`cluster_id`) REFERENCES `knowledge_gap_clusters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Software Inventory & Licences
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `software_inventory_apps` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `display_name`      VARCHAR(512) NOT NULL,
    `publisher`         VARCHAR(512) NULL,
    `first_detected`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_app_display_publisher` (`display_name`(400), `publisher`(360))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `software_inventory_detail` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `host_id`           INT NOT NULL,
    `app_id`            INT NOT NULL,
    `display_version`   VARCHAR(100) NULL,
    `install_date`      VARCHAR(50) NULL,
    `uninstall_string`  LONGTEXT NULL,
    `install_location`  LONGTEXT NULL,
    `estimated_size`    VARCHAR(100) NULL,
    `system_component`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `source`            VARCHAR(20) NOT NULL DEFAULT 'agent',
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_software_detail_host_app` (`host_id`, `app_id`),
    CONSTRAINT `fk_software_detail_app` FOREIGN KEY (`app_id`) REFERENCES `software_inventory_apps` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `software_licences` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `app_id`            INT NOT NULL,
    `licence_type`      VARCHAR(50) NOT NULL,
    `licence_key`       VARCHAR(500) NULL,
    `quantity`          INT NULL,
    `renewal_date`      DATE NULL,
    `notice_period_days` INT NULL,
    `portal_url`        VARCHAR(500) NULL,
    `cost`              DECIMAL(10,2) NULL,
    `currency`          VARCHAR(10) NULL DEFAULT 'GBP',
    `purchase_date`     DATE NULL,
    `vendor_contact`    VARCHAR(500) NULL,
    `notes`             LONGTEXT NULL,
    `status`            VARCHAR(20) NOT NULL DEFAULT 'Active',
    `created_by`        INT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_software_licences_app` FOREIGN KEY (`app_id`) REFERENCES `software_inventory_apps` (`id`),
    CONSTRAINT `fk_software_licences_analyst` FOREIGN KEY (`created_by`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingest log for the software-inventory agent submissions. The submit
-- endpoint has always written here (best-effort, failures swallowed) but the
-- table was never defined anywhere — added 2026-07-03 so the logging works.
CREATE TABLE IF NOT EXISTS `software_inventory_log` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `host_id`           INT NULL,
    `api_response`      LONGTEXT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_sil_host_id` (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `apikeys` (
    `id`         INT NOT NULL AUTO_INCREMENT,
    `apikey`     VARCHAR(50) NULL,
    `analyst_id` INT NULL,
    `label`      VARCHAR(100) NULL,
    `datestamp`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `active`     TINYINT(1) NULL DEFAULT 1,
    -- Multi-tenancy: the company this ingest key belongs to. A monitoring agent
    -- authenticates with its key, so assets it reports are stamped with this
    -- company (the "pinned mailbox" equivalent for asset ingest). NULL = the
    -- Default company, so existing keys keep working unchanged at N=1.
    `tenant_id`  INT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_apikeys_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_rate_limits` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `apikey_id`     INT NOT NULL,
    `request_count` INT NOT NULL DEFAULT 0,
    `window_start`  DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_apikey_window` (`apikey_id`, `window_start`),
    CONSTRAINT `fk_rate_limits_apikey` FOREIGN KEY (`apikey_id`) REFERENCES `apikeys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- REST API v1 keys (System > API) — distinct from the legacy `apikeys` table
-- above (used by the api/external ingest endpoints). v1 keys are stored as a
-- SHA-256 hash (shown once at creation), carry a granular permission map
-- (JSON: {"tickets":["read","create"],...}), an optional company scope
-- (JSON array of tenant ids; NULL = all companies), an optional expiry and
-- per-minute rate-limit override, and act as an analyst so audit rows, notes
-- and workflow events keep a real author.
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `api_keys` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `name`                  VARCHAR(100) NOT NULL,
    `key_prefix`            VARCHAR(16) NOT NULL,
    `key_hash`              CHAR(64) NOT NULL,
    `analyst_id`            INT NOT NULL,
    `permissions`           LONGTEXT NULL,
    `company_ids`           TEXT NULL,
    `rate_limit_per_minute` INT NULL,
    `active`                TINYINT(1) NOT NULL DEFAULT 1,
    `expires_at`            DATETIME NULL,
    `last_used_at`          DATETIME NULL,
    `last_used_ip`          VARCHAR(45) NULL,
    `created_by`            INT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_keys_hash` (`key_hash`),
    CONSTRAINT `fk_api_keys_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_api_keys_created_by` FOREIGN KEY (`created_by`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_key_rate_limits` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `api_key_id`    INT NOT NULL,
    `request_count` INT NOT NULL DEFAULT 0,
    `window_start`  DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_key_window` (`api_key_id`, `window_start`),
    CONSTRAINT `fk_api_key_rate_limits_key` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Tasks
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `task_statuses` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `is_closed`         TINYINT(1) NOT NULL DEFAULT 0,
    `colour`            VARCHAR(20) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_task_statuses_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `task_statuses` (`name`, `is_closed`, `colour`, `is_default`, `display_order`) VALUES
    ('To Do',       0, '#6b7280', 1, 10),
    ('In Progress', 0, '#9333ea', 0, 20),
    ('Blocked',     0, '#f59e0b', 0, 30),
    ('Done',        1, '#16a34a', 0, 40),
    ('Cancelled',   1, '#bdbdbd', 0, 50);

CREATE TABLE IF NOT EXISTS `task_priorities` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `colour`            VARCHAR(20) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_task_priorities_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `task_priorities` (`name`, `colour`, `is_default`, `display_order`) VALUES
    ('Low',    '#16a34a', 0, 10),
    ('Medium', '#2563eb', 1, 20),
    ('High',   '#f59e0b', 0, 30),
    ('Urgent', '#dc2626', 0, 40);

CREATE TABLE IF NOT EXISTS `tasks` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `title`               VARCHAR(255) NOT NULL,
    `description`         LONGTEXT NULL,
    `status_id`           INT NULL,
    `priority_id`         INT NULL,
    `start_date`          DATE NULL,
    `due_date`            DATE NULL,
    `assigned_analyst_id` INT NULL,
    `assigned_team_id`    INT NULL,
    `parent_task_id`      INT NULL,
    `ticket_id`           INT NULL,
    `change_id`           INT NULL,
    `contract_id`         INT NULL,
    `tenant_id`           INT NULL,                 -- NULL = the Default company
    `board_position`      INT NOT NULL DEFAULT 0,
    `created_by_id`       INT NOT NULL,
    `created_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_datetime`  DATETIME NULL,
    `work_start_datetime` DATETIME NULL,             -- naive wall clock, like a ticket's scheduled work
    `work_end_datetime`   DATETIME NULL,
    `work_all_day`        TINYINT(1) NOT NULL DEFAULT 0,
    -- Recurring tasks (#94). recurrence_id names the rule that made this
    -- occurrence; recurrence_master_id points at the first task of the series.
    -- The constraints are added after task_recurrences is created, below.
    `recurrence_id`        INT NULL,
    `recurrence_master_id` INT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `ix_tasks_status_id` (`status_id`),
    KEY `ix_tasks_priority_id` (`priority_id`),
    KEY `idx_tasks_tenant` (`tenant_id`),
    -- Recurring tasks (#94). The first finds every occurrence of a series, the
    -- second answers "what else came from the same original", which is what the
    -- detail panel's link to the master needs.
    --
    -- ⚠️ These were added to includes/db_verify_indexes.php by hand and never to
    -- this file, so a FRESH install never had them - only an install that ran
    -- Database Verification got them backfilled. Found when the generated index
    -- list was rebuilt and tried to delete them.
    KEY `ix_tasks_recurrence_id` (`recurrence_id`),
    KEY `ix_tasks_recurrence_master` (`recurrence_master_id`),
    CONSTRAINT `fk_tasks_analyst` FOREIGN KEY (`assigned_analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_team` FOREIGN KEY (`assigned_team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_parent` FOREIGN KEY (`parent_task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tasks_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_change` FOREIGN KEY (`change_id`) REFERENCES `changes` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_created_by` FOREIGN KEY (`created_by_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_tasks_status` FOREIGN KEY (`status_id`) REFERENCES `task_statuses` (`id`),
    CONSTRAINT `fk_tasks_priority` FOREIGN KEY (`priority_id`) REFERENCES `task_priorities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recurring tasks (#94). One row per SERIES, not per occurrence and not a
-- template: the task a series was created from IS its first occurrence, so
-- nothing appears in a list, count or report that is not real work.
CREATE TABLE IF NOT EXISTS `task_recurrences` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    -- completion = count from the day it was finished (what Planner does, and
    -- what people mean by "monthly"). schedule = fixed dates whether or not the
    -- last one was done, which is what a compliance review needs.
    `mode`                VARCHAR(12) NOT NULL DEFAULT 'completion',
    `freq`                VARCHAR(10) NOT NULL DEFAULT 'weekly',
    `interval_n`          INT NOT NULL DEFAULT 1,
    `weekdays`            VARCHAR(20) NULL,
    `month_mode`          VARCHAR(4) NULL,
    `day_of_month`        INT NULL,
    `nth`                 INT NULL,
    `nth_weekday`         INT NULL,
    `month_of_year`       INT NULL,
    `ends_mode`           VARCHAR(12) NOT NULL DEFAULT 'never',
    `ends_on`             DATE NULL,
    `max_occurrences`     INT NULL,
    `occurrences_created` INT NOT NULL DEFAULT 1,
    `copy_description`    TINYINT(1) NOT NULL DEFAULT 1,
    `copy_subtasks`       TINYINT(1) NOT NULL DEFAULT 1,
    `copy_assignee`       TINYINT(1) NOT NULL DEFAULT 1,
    `copy_tags`           TINYINT(1) NOT NULL DEFAULT 1,
    `copy_links`          TINYINT(1) NOT NULL DEFAULT 0,
    `copy_attachments`    TINYINT(1) NOT NULL DEFAULT 0,
    `next_due_date`       DATE NULL,
    `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_id`       INT NULL,
    `created_datetime`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`    DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `ix_task_recurrences_due` (`is_active`, `next_due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Added after task_recurrences exists. Both SET NULL, deliberately: deleting the
-- rule stops the series repeating, and deleting the first task must not take the
-- work recorded on later occurrences with it.
ALTER TABLE `tasks`
    ADD CONSTRAINT `fk_tasks_recurrence` FOREIGN KEY (`recurrence_id`) REFERENCES `task_recurrences` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_tasks_recurrence_master` FOREIGN KEY (`recurrence_master_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL;

-- Time actually spent on a task, as many sessions as it took (GH #112).
-- Mirrors ticket_time_entries column for column: it is the same idea about a
-- different record, and a second, subtly different shape is what later drifts.
CREATE TABLE IF NOT EXISTS `task_time_entries` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `task_id`             INT NOT NULL,
    `analyst_id`          INT NOT NULL,
    `notes`               LONGTEXT NULL,
    `time_spent_minutes`  INT NOT NULL,
    `entry_datetime`      DATETIME NOT NULL,
    `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_task_time_entries_task_id` (`task_id`),
    KEY `ix_task_time_entries_analyst_date` (`analyst_id`, `entry_datetime`),
    -- CASCADE, unlike the ticket equivalent: deleting a task deletes it outright
    -- (there is no trash for tasks), so leaving its time behind would orphan rows
    -- that nothing can ever reach or tidy.
    CONSTRAINT `fk_task_time_entries_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_time_entries_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_comments` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `task_id`           INT NOT NULL,
    `analyst_id`        INT NOT NULL,
    `comment`           LONGTEXT NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_task_comments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_comments_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The other people on a task (GH #89, dschipfel). Shown as "Involved".
--
-- 🔴 THE OWNER IS NOT IN HERE, and that is the load-bearing decision. Who is
-- accountable stays in `tasks.assigned_analyst_id` and nowhere else: two homes
-- for one fact are two things that can disagree. Because that column keeps its
-- exact meaning, the REST contract (`assignee_id` is a scalar), every stored
-- workflow, and board grouping are unchanged BY DEFINITION rather than because
-- somebody checked.
--
-- Shape copied from `change_cab_members` — Changes already solved "several named
-- people on one record, each with their own state", and reusing it makes the
-- optional per-person tick almost free. `is_required` is deliberately NOT copied.
--
-- ⚠️ ON DELETE CASCADE for `analyst_id`, which is where this DIVERGES from
-- change_cab_members. A CAB row records a VOTE — a decision somebody made, worth
-- keeping after they leave. A collaborator row is a MEMBERSHIP, and a membership
-- held by an account that no longer exists means nothing. It also keeps
-- api/tickets/delete_analyst.php working, which a restricting constraint would
-- have blocked the first time anybody deleted an analyst who was helping on a task.
--
-- Must follow `tasks` and `analysts`: it points at both.
CREATE TABLE IF NOT EXISTS `task_collaborators` (
    `id`                 INT NOT NULL AUTO_INCREMENT,
    `task_id`            INT NOT NULL,
    `analyst_id`         INT NOT NULL,
    -- Recorded whether or not the per-person setting is on. Switching the setting
    -- off hides ticks; it must never destroy them, the same rule that governs
    -- narrowing `tasks_time_scope` when somebody has already logged hours.
    `is_completed`       TINYINT(1) NOT NULL DEFAULT 0,
    `completed_datetime` DATETIME NULL,
    `added_by_id`        INT NULL,
    `added_datetime`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`            TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    -- One row per person per task: adding somebody twice is not a second fact.
    UNIQUE KEY `uq_task_collaborator` (`task_id`, `analyst_id`),
    -- ⚠️ NOT redundant with the unique key above. "My tasks" now asks
    -- "which tasks is THIS ANALYST on", which reads analyst-first; the composite
    -- key is task-first and cannot serve it. Without this the new filter is a
    -- full scan of the table on every board load.
    KEY `ix_task_collaborators_analyst` (`analyst_id`),
    CONSTRAINT `fk_task_collab_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_collab_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_collab_added_by` FOREIGN KEY (`added_by_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_tags` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `colour`            VARCHAR(20) NULL,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_task_tags_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `task_tags` (`name`, `colour`, `display_order`) VALUES
    ('Security',    '#dc2626', 10),
    ('ISO',         '#2563eb', 20),
    ('Environment', '#16a34a', 30);

CREATE TABLE IF NOT EXISTS `task_tag_map` (
    `task_id`  INT NOT NULL,
    `tag_id`   INT NOT NULL,
    PRIMARY KEY (`task_id`, `tag_id`),
    CONSTRAINT `fk_task_tag_map_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_tag_map_tag`  FOREIGN KEY (`tag_id`)  REFERENCES `task_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Forms
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `forms` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `title`          VARCHAR(255) NOT NULL,
    `description`    LONGTEXT NULL,
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`     INT NULL,
    `created_date`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `modified_by`    INT NULL,
    `modified_date`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Versioning (#442): each form row is one snapshot in a chain.
    -- parent_form_id chains back to the previous version (NULL for the
    -- root). The leaf (no children) is editable; older rows are frozen.
    -- version_number is set on create / clone, never incremented by
    -- in-place saves.
    `parent_form_id` INT NULL,
    `version_number` INT NOT NULL DEFAULT 1,
    -- Show this form in the self-service portal's request catalogue.
    -- SEPARATE from is_active, which is the analyst-side on/off: an internal
    -- form (a new-starter request a manager fills in) is active but has no
    -- business being offered to every customer. Defaults to 0 so no existing
    -- form is exposed by an upgrade — the same fail-closed rule as article
    -- visibility.
    `is_portal_visible` TINYINT(1) NOT NULL DEFAULT 0,
    -- Catalogue-request approval (#928). When requires_approval is on, a portal
    -- submission of this form waits for the designated approver before a ticket is
    -- raised. approver_id is that single sign-off analyst. requires_approval on with
    -- approver_id NULL means "needs approval but nobody assigned" — treated as
    -- unconfigured so it can never hold requests hostage (submitForm skips the gate).
    `requires_approval` TINYINT(1) NOT NULL DEFAULT 0,
    `approver_id`       INT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    -- RESTRICT (no delete rule): a frozen version can't be deleted while
    -- newer versions chain off it — delete leaf-first (or the whole chain).
    CONSTRAINT `fk_forms_parent` FOREIGN KEY (`parent_form_id`) REFERENCES `forms` (`id`),
    -- SET NULL: losing the approver shouldn't delete the catalogue item, it just
    -- becomes unconfigured (and stops gating) until a new approver is chosen.
    CONSTRAINT `fk_forms_approver` FOREIGN KEY (`approver_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_fields` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `form_id`       INT NOT NULL,
    -- One of FormsService::FIELD_TYPES. 'section' is a PRESENTATIONAL item, not a
    -- question: it renders as a heading, never produces a form_submission_data row,
    -- and owns every field below it until the next 'section'. Modelled as a row here
    -- rather than its own table so one flat sort_order still describes the whole form
    -- and the builder's existing drag-and-drop keeps working untouched.
    `field_type`    VARCHAR(50) NOT NULL,
    `label`         VARCHAR(255) NOT NULL,
    `options`       LONGTEXT NULL,
    `is_required`   TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`    INT NOT NULL DEFAULT 0,
    -- Per-field JSON settings. Today it holds only the conditional-visibility rule:
    --   {"visible_if":{"match":"all|any","rules":[{"field":<id>,"op":...,"value":...}]}}
    -- `field` is a form_fields.id, which is exactly why field identity had to be fixed
    -- first — a rule pointing at "the third question" would not survive a reorder.
    -- NULL = always visible, which is every pre-existing row, so an upgraded form
    -- renders identically.
    `config`        LONGTEXT NULL,
    -- Soft delete. Removing a field from the builder sets this instead of dropping the
    -- row, because form_submission_data.field_id points at it: a hard delete silently
    -- destroyed the answers every past respondent gave to that question. Hidden
    -- everywhere a form is filled in; still shown in the submissions view so history
    -- stays readable.
    `is_deleted`    TINYINT(1) NOT NULL DEFAULT 0,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_form_fields_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_submissions` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `form_id`           INT NOT NULL,
    -- The ANALYST who submitted it. Every reader LEFT JOINs this to `analysts`,
    -- so a requester's id must NOT be written here: `users` and `analysts` are
    -- separate id spaces and a collision would silently attribute a customer's
    -- request to whichever analyst happened to share the number.
    `submitted_by`      INT NULL,
    -- The REQUESTER who submitted it, via the portal's request catalogue.
    -- Exactly one of these two is set; both NULL means an old row whose
    -- submitter is unknown.
    `submitted_by_user_id` INT NULL,
    -- The ticket an analyst raised FROM this submission, once they have. NULL
    -- means "not yet actioned", which is what the analyst queue filters on.
    `ticket_id`         INT NULL,
    `submitted_date`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Catalogue-request approval (#928). approval_status is one of
    -- 'not_required' (no gate, the default and every pre-#928 row), 'pending',
    -- 'approved' or 'rejected'. approver_id is snapshotted from the form AT SUBMIT
    -- TIME so later changes to the catalogue item never re-route history.
    -- decided_by is who actually clicked (normally the approver). A fixed internal
    -- vocabulary, not a user-configurable status, so string literals are safe.
    `approval_status`            VARCHAR(20) NOT NULL DEFAULT 'not_required',
    `approver_id`                INT NULL,
    `approval_decided_by_id`     INT NULL,
    `approval_decided_datetime`  DATETIME NULL,
    `approval_comment`           TEXT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `idx_form_submissions_user` (`submitted_by_user_id`),
    KEY `idx_form_submissions_ticket` (`ticket_id`),
    KEY `idx_form_submissions_approval` (`approval_status`, `approver_id`),
    CONSTRAINT `fk_form_submissions_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`),
    CONSTRAINT `fk_form_submissions_user` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_form_submissions_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_form_submissions_approver` FOREIGN KEY (`approver_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_form_submissions_decided_by` FOREIGN KEY (`approval_decided_by_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_submission_data` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `submission_id` INT NOT NULL,
    `field_id`      INT NOT NULL,
    `field_value`   LONGTEXT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_submission_data_submission` FOREIGN KEY (`submission_id`) REFERENCES `form_submissions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_submission_data_field` FOREIGN KEY (`field_id`) REFERENCES `form_fields` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Wiki / Code Scanner
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `wiki_scan_runs` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `started_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`      DATETIME NULL,
    `status`            VARCHAR(20) NOT NULL DEFAULT 'running',
    `files_scanned`     INT NOT NULL DEFAULT 0,
    `functions_found`   INT NOT NULL DEFAULT 0,
    `classes_found`     INT NOT NULL DEFAULT 0,
    `error_message`     LONGTEXT NULL,
    `scanned_by`        VARCHAR(100) NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wiki_files` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `scan_id`           INT NOT NULL,
    `file_path`         VARCHAR(500) NOT NULL,
    `file_name`         VARCHAR(255) NOT NULL,
    `folder_path`       VARCHAR(500) NOT NULL,
    `file_type`         VARCHAR(10) NOT NULL,
    `file_size_bytes`   BIGINT NOT NULL DEFAULT 0,
    `line_count`        INT NOT NULL DEFAULT 0,
    `last_modified`     DATETIME NULL,
    `description`       LONGTEXT NULL,
    `created_date`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_wiki_files_scan` FOREIGN KEY (`scan_id`) REFERENCES `wiki_scan_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wiki_functions` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `file_id`           INT NOT NULL,
    `function_name`     VARCHAR(255) NOT NULL,
    `line_number`       INT NOT NULL,
    `end_line_number`   INT NULL,
    `parameters`        LONGTEXT NULL,
    `class_name`        VARCHAR(255) NULL,
    `visibility`        VARCHAR(20) NULL,
    `is_static`         TINYINT(1) NOT NULL DEFAULT 0,
    `description`       LONGTEXT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_wiki_functions_file` FOREIGN KEY (`file_id`) REFERENCES `wiki_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wiki_classes` (
    `id`                        INT NOT NULL AUTO_INCREMENT,
    `file_id`                   INT NOT NULL,
    `class_name`                VARCHAR(255) NOT NULL,
    `line_number`               INT NOT NULL,
    `extends_class`             VARCHAR(255) NULL,
    `implements_interfaces`     LONGTEXT NULL,
    `description`               LONGTEXT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_wiki_classes_file` FOREIGN KEY (`file_id`) REFERENCES `wiki_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wiki_dependencies` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `file_id`           INT NOT NULL,
    `dependency_type`   VARCHAR(50) NOT NULL,
    `target_path`       VARCHAR(500) NOT NULL,
    `resolved_file_id`  INT NULL,
    `line_number`       INT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_wiki_deps_file` FOREIGN KEY (`file_id`) REFERENCES `wiki_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wiki_function_calls` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `file_id`       INT NOT NULL,
    `function_name` VARCHAR(255) NOT NULL,
    `line_number`   INT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_wiki_funccalls_file` FOREIGN KEY (`file_id`) REFERENCES `wiki_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wiki_db_references` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `file_id`           INT NOT NULL,
    `table_name`        VARCHAR(255) NOT NULL,
    `reference_type`    VARCHAR(50) NOT NULL,
    `line_number`       INT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_wiki_dbrefs_file` FOREIGN KEY (`file_id`) REFERENCES `wiki_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wiki_session_vars` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `file_id`       INT NOT NULL,
    `variable_name` VARCHAR(255) NOT NULL,
    `access_type`   VARCHAR(10) NOT NULL,
    `line_number`   INT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_wiki_sessvars_file` FOREIGN KEY (`file_id`) REFERENCES `wiki_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Contracts Module
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `supplier_types` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_statuses` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `suppliers` (
    `id`                            INT NOT NULL AUTO_INCREMENT,
    `legal_name`                    VARCHAR(255) NOT NULL,
    `trading_name`                  VARCHAR(255) NULL,
    `reg_number`                    VARCHAR(50) NULL,
    `vat_number`                    VARCHAR(50) NULL,
    `supplier_type_id`              INT NULL,
    `supplier_status_id`            INT NULL,
    `address_line_1`                VARCHAR(255) NULL,
    `address_line_2`                VARCHAR(255) NULL,
    `city`                          VARCHAR(100) NULL,
    `county`                        VARCHAR(100) NULL,
    `postcode`                      VARCHAR(20) NULL,
    `country`                       VARCHAR(100) NULL,
    `questionnaire_date_issued`     DATE NULL,
    `questionnaire_date_received`   DATE NULL,
    `comments`                      LONGTEXT NULL,
    `is_active`                     TINYINT(1) NOT NULL DEFAULT 1,
    `supplies_assets`               TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`              DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_suppliers_type` FOREIGN KEY (`supplier_type_id`) REFERENCES `supplier_types` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_suppliers_status` FOREIGN KEY (`supplier_status_id`) REFERENCES `supplier_statuses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contacts` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `supplier_id`       INT NULL,
    `first_name`        VARCHAR(100) NOT NULL,
    `surname`           VARCHAR(100) NOT NULL,
    `email`             VARCHAR(255) NULL,
    `mobile`            VARCHAR(50) NULL,
    `job_title`         VARCHAR(100) NULL,
    `direct_dial`       VARCHAR(50) NULL,
    `switchboard`       VARCHAR(50) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_contacts_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contract_statuses` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_schedules` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contract_term_tabs` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contracts` (
    `id`                        INT NOT NULL AUTO_INCREMENT,
    `contract_number`           VARCHAR(50) NOT NULL,
    `title`                     VARCHAR(255) NOT NULL,
    `description`               LONGTEXT NULL,
    `supplier_id`               INT NULL,
    `contract_owner_id`         INT NULL,
    `contract_status_id`        INT NULL,
    `contract_start`            DATE NULL,
    `contract_end`              DATE NULL,
    `notice_period_days`        INT NULL,
    `notice_date`               DATE NULL,
    `contract_value`            DECIMAL(18,2) NULL,
    `currency`                  VARCHAR(3) NULL,
    `payment_schedule_id`       INT NULL,
    `cost_centre`               VARCHAR(100) NULL,
    `dms_link`                  VARCHAR(500) NULL,
    `terms_status`              VARCHAR(20) NULL,
    `personal_data_transferred` TINYINT(1) NULL,
    `dpia_required`             TINYINT(1) NULL,
    `dpia_completed_date`       DATE NULL,
    `dpia_dms_link`             VARCHAR(500) NULL,
    `is_active`                 TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `ix_contracts_supplier_id` (`supplier_id`),
    KEY `ix_contracts_contract_end` (`contract_end`),
    CONSTRAINT `fk_contracts_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_contracts_owner` FOREIGN KEY (`contract_owner_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_contracts_status` FOREIGN KEY (`contract_status_id`) REFERENCES `contract_statuses` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_contracts_payment_schedule` FOREIGN KEY (`payment_schedule_id`) REFERENCES `payment_schedules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contract_term_values` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `contract_id`       INT NOT NULL,
    `term_tab_id`       INT NOT NULL,
    `content`           LONGTEXT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ctv_contract_tab` (`contract_id`, `term_tab_id`),
    CONSTRAINT `fk_ctv_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ctv_term_tab` FOREIGN KEY (`term_tab_id`) REFERENCES `contract_term_tabs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- RFP Builder (feature of the Contracts module)
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `rfps` (
    `id`                       INT NOT NULL AUTO_INCREMENT,
    `name`                     VARCHAR(200) NOT NULL,
    `status`                   VARCHAR(50) NOT NULL DEFAULT 'draft',
    `contract_id`              INT NULL,
    `chosen_supplier_id`       INT NULL,
    `style_guide`              LONGTEXT NULL,
    `framing_context_text`     LONGTEXT NULL,
    `created_by_analyst_id`    INT NULL,
    `created_datetime`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rfps_status` (`status`),
    KEY `idx_rfps_contract_id` (`contract_id`),
    KEY `idx_rfps_supplier_id` (`chosen_supplier_id`),
    CONSTRAINT `fk_rfps_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rfps_supplier` FOREIGN KEY (`chosen_supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rfps_creator` FOREIGN KEY (`created_by_analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_departments` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `colour`            VARCHAR(7) NOT NULL DEFAULT '#6c757d',
    `sort_order`        INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rfp_departments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_categories` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `rfp_id`            INT NOT NULL,
    `name`              VARCHAR(200) NOT NULL,
    `description`       LONGTEXT NULL,
    `sort_order`        INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rfp_categories_rfp_id` (`rfp_id`),
    CONSTRAINT `fk_rfp_categories_rfp` FOREIGN KEY (`rfp_id`) REFERENCES `rfps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_documents` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `rfp_id`            INT NOT NULL,
    `department_id`     INT NULL,
    `filename`          VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `file_path`         VARCHAR(500) NOT NULL,
    `raw_text`          LONGTEXT NULL,
    `status`            VARCHAR(50) NOT NULL DEFAULT 'uploaded',
    `uploaded_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rfp_documents_rfp_id` (`rfp_id`),
    KEY `idx_rfp_documents_department_id` (`department_id`),
    CONSTRAINT `fk_rfp_documents_rfp` FOREIGN KEY (`rfp_id`) REFERENCES `rfps` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_documents_department` FOREIGN KEY (`department_id`) REFERENCES `rfp_departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_extracted_requirements` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `rfp_id`            INT NOT NULL,
    `document_id`       INT NOT NULL,
    `requirement_text`  LONGTEXT NOT NULL,
    `requirement_type`  VARCHAR(50) NOT NULL DEFAULT 'requirement',
    `source_quote`      LONGTEXT NULL,
    `ai_confidence`     DECIMAL(3,2) NULL,
    `is_consolidated`   TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rfp_extracted_rfp_id` (`rfp_id`),
    KEY `idx_rfp_extracted_doc_id` (`document_id`),
    CONSTRAINT `fk_rfp_extracted_rfp` FOREIGN KEY (`rfp_id`) REFERENCES `rfps` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_extracted_document` FOREIGN KEY (`document_id`) REFERENCES `rfp_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_consolidated_requirements` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `rfp_id`            INT NOT NULL,
    `category_id`       INT NULL,
    `requirement_text`  LONGTEXT NOT NULL,
    `requirement_type`  VARCHAR(50) NOT NULL DEFAULT 'requirement',
    `priority`          VARCHAR(20) NOT NULL DEFAULT 'medium',
    `ai_rationale`      LONGTEXT NULL,
    `is_locked`         TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rfp_consolidated_rfp_id` (`rfp_id`),
    KEY `idx_rfp_consolidated_category_id` (`category_id`),
    CONSTRAINT `fk_rfp_consolidated_rfp` FOREIGN KEY (`rfp_id`) REFERENCES `rfps` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_consolidated_category` FOREIGN KEY (`category_id`) REFERENCES `rfp_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_consolidated_sources` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `consolidated_id`   INT NOT NULL,
    `extracted_id`      INT NOT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rfp_consolidated_sources` (`consolidated_id`, `extracted_id`),
    CONSTRAINT `fk_rfp_csrcs_consolidated` FOREIGN KEY (`consolidated_id`) REFERENCES `rfp_consolidated_requirements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_csrcs_extracted` FOREIGN KEY (`extracted_id`) REFERENCES `rfp_extracted_requirements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_conflicts` (
    `id`                       INT NOT NULL AUTO_INCREMENT,
    `rfp_id`                   INT NOT NULL,
    `consolidated_id_a`        INT NOT NULL,
    `consolidated_id_b`        INT NOT NULL,
    `ai_explanation`           LONGTEXT NULL,
    `resolution`               VARCHAR(50) NOT NULL DEFAULT 'open',
    `resolution_notes`         LONGTEXT NULL,
    `resolved_by_analyst_id`   INT NULL,
    `resolved_datetime`        DATETIME NULL,
    `created_datetime`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rfp_conflicts_rfp_id` (`rfp_id`),
    KEY `idx_rfp_conflicts_a` (`consolidated_id_a`),
    KEY `idx_rfp_conflicts_b` (`consolidated_id_b`),
    CONSTRAINT `fk_rfp_conflicts_rfp` FOREIGN KEY (`rfp_id`) REFERENCES `rfps` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_conflicts_a` FOREIGN KEY (`consolidated_id_a`) REFERENCES `rfp_consolidated_requirements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_conflicts_b` FOREIGN KEY (`consolidated_id_b`) REFERENCES `rfp_consolidated_requirements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_conflicts_resolver` FOREIGN KEY (`resolved_by_analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_output_sections` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `rfp_id`              INT NOT NULL,
    `category_id`         INT NOT NULL,
    `section_title`       VARCHAR(300) NOT NULL,
    `section_content`     LONGTEXT NULL,
    `version`             INT NOT NULL DEFAULT 1,
    `is_manually_edited`  TINYINT(1) NOT NULL DEFAULT 0,
    `requirements_hash`   VARCHAR(64) NULL,
    `generated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `edited_datetime`     DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_rfp_sections_rfp_id` (`rfp_id`),
    KEY `idx_rfp_sections_category_id` (`category_id`),
    CONSTRAINT `fk_rfp_sections_rfp` FOREIGN KEY (`rfp_id`) REFERENCES `rfps` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_sections_category` FOREIGN KEY (`category_id`) REFERENCES `rfp_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_document_sections` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `rfp_id`              INT NOT NULL,
    `section_key`         VARCHAR(50) NOT NULL,
    `section_title`       VARCHAR(200) NOT NULL,
    `section_content`     LONGTEXT NULL,
    `sort_order`          INT NOT NULL DEFAULT 0,
    `is_manually_edited`  TINYINT(1) NOT NULL DEFAULT 0,
    `inputs_hash`         VARCHAR(64) NULL,
    `generated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `edited_datetime`     DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rfp_doc_section` (`rfp_id`, `section_key`),
    KEY `idx_rfp_doc_section_rfp_id` (`rfp_id`),
    CONSTRAINT `fk_rfp_doc_section_rfp` FOREIGN KEY (`rfp_id`) REFERENCES `rfps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_section_history` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `section_id`          INT NOT NULL,
    `version`             INT NOT NULL,
    `section_content`     LONGTEXT NULL,
    `is_manually_edited`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rfp_section_history_section_id` (`section_id`),
    CONSTRAINT `fk_rfp_section_history_section` FOREIGN KEY (`section_id`) REFERENCES `rfp_output_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_invited_suppliers` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `rfp_id`            INT NOT NULL,
    `supplier_id`       INT NOT NULL,
    `invited_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `demo_date`         DATE NULL,
    `notes`             LONGTEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rfp_invited_suppliers` (`rfp_id`, `supplier_id`),
    CONSTRAINT `fk_rfp_invited_rfp` FOREIGN KEY (`rfp_id`) REFERENCES `rfps` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_invited_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_scores` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `rfp_id`            INT NOT NULL,
    `supplier_id`       INT NOT NULL,
    `analyst_id`        INT NOT NULL,
    `consolidated_id`   INT NOT NULL,
    `score`             INT NULL,
    `notes`             LONGTEXT NULL,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rfp_scores` (`rfp_id`, `supplier_id`, `analyst_id`, `consolidated_id`),
    KEY `idx_rfp_scores_rfp_id` (`rfp_id`),
    CONSTRAINT `fk_rfp_scores_rfp` FOREIGN KEY (`rfp_id`) REFERENCES `rfps` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_scores_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_scores_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_scores_consolidated` FOREIGN KEY (`consolidated_id`) REFERENCES `rfp_consolidated_requirements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rfp_processing_log` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `rfp_id`            INT NOT NULL,
    `document_id`       INT NULL,
    `section_id`        INT NULL,
    `action`            VARCHAR(100) NOT NULL,
    `status`            VARCHAR(50) NOT NULL,
    `details`           LONGTEXT NULL,
    `tokens_in`         INT NULL,
    `tokens_out`        INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rfp_log_rfp_id` (`rfp_id`),
    KEY `idx_rfp_log_action` (`action`),
    CONSTRAINT `fk_rfp_log_rfp` FOREIGN KEY (`rfp_id`) REFERENCES `rfps` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rfp_log_document` FOREIGN KEY (`document_id`) REFERENCES `rfp_documents` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rfp_log_section` FOREIGN KEY (`section_id`) REFERENCES `rfp_output_sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- LMS (Learning Management System)
-- ----------------------------------------------------------

-- A course is either an uploaded SCORM package ('scorm' — rendered by the
-- package itself inside an iframe) or authored here ('native' — lessons and
-- questions in the tables below, rendered by our own player). content_type is
-- the discriminator both player.php and the Courses tab branch on. Existing
-- rows default to 'scorm', which is what they are.
CREATE TABLE IF NOT EXISTS `lms_courses` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `title`                 VARCHAR(255) NOT NULL,
    `description`           LONGTEXT NULL,
    `content_type`          VARCHAR(10) NOT NULL DEFAULT 'scorm',
    `pass_mark`             INT NULL,
    `scorm_version`         VARCHAR(20) NULL,
    `manifest_identifier`   VARCHAR(255) NULL,
    `launch_url`            VARCHAR(500) NULL,
    `original_filename`     VARCHAR(255) NULL,
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_id`         INT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Native course content (content_type = 'native') ----

-- An ordered lesson within a course. `body` is TinyMCE HTML, exactly as
-- knowledge_articles.body is — including inline base64 images, so a lesson is
-- entirely self-contained with nothing on disk to lose or leak.
CREATE TABLE IF NOT EXISTS `lms_lessons` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `course_id`             INT NOT NULL,
    `title`                 VARCHAR(255) NOT NULL,
    `body`                  LONGTEXT NULL,
    `display_order`         INT NOT NULL DEFAULT 0,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `idx_lms_lessons_course` (`course_id`),
    CONSTRAINT `fk_lms_lessons_course` FOREIGN KEY (`course_id`) REFERENCES `lms_courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A question asked at the end of a lesson. question_type is one of
-- 'single' (one right answer), 'multiple' (several) or 'truefalse'.
CREATE TABLE IF NOT EXISTS `lms_questions` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `lesson_id`             INT NOT NULL,
    `question_text`         TEXT NOT NULL,
    `question_type`         VARCHAR(20) NOT NULL DEFAULT 'single',
    `explanation`           TEXT NULL,
    `display_order`         INT NOT NULL DEFAULT 0,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `idx_lms_questions_lesson` (`lesson_id`),
    CONSTRAINT `fk_lms_questions_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lms_lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One option of a question. `is_correct` is the answer key and is NEVER sent to
-- a learner — api/lms/course_content.php strips it (see the comment there).
CREATE TABLE IF NOT EXISTS `lms_answers` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `question_id`           INT NOT NULL,
    `answer_text`           VARCHAR(500) NOT NULL,
    `is_correct`            TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`         INT NOT NULL DEFAULT 0,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `idx_lms_answers_question` (`question_id`),
    CONSTRAINT `fk_lms_answers_question` FOREIGN KEY (`question_id`) REFERENCES `lms_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lms_learning_groups` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `name`                  VARCHAR(100) NOT NULL,
    `description`           VARCHAR(500) NULL,
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_id`         INT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lms_learning_group_members` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `group_id`              INT NOT NULL,
    `analyst_id`            INT NOT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lgm_group_analyst` (`group_id`, `analyst_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lms_course_assignments` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `course_id`             INT NOT NULL,
    `group_id`              INT NOT NULL,
    `deadline`              DATETIME NULL,
    `assigned_by_id`        INT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lca_course_group` (`course_id`, `group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lms_progress` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `analyst_id`            INT NOT NULL,
    `course_id`             INT NOT NULL,
    `status`                VARCHAR(20) NOT NULL DEFAULT 'not_started',
    `score_raw`             DECIMAL(10,2) NULL,
    `score_min`             DECIMAL(10,2) NULL,
    `score_max`             DECIMAL(10,2) NULL,
    `total_time`            VARCHAR(50) NULL,
    `bookmark`              VARCHAR(500) NULL,
    `suspend_data`          LONGTEXT NULL,
    `completion_datetime`   DATETIME NULL,
    `first_access`          DATETIME NULL,
    `last_access`           DATETIME NULL,
    `attempt_count`         INT NOT NULL DEFAULT 0,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lp_analyst_course` (`analyst_id`, `course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lms_cmi_data` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `progress_id`           INT NOT NULL,
    `element`               VARCHAR(255) NOT NULL,
    `value`                 LONGTEXT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lcd_progress_element` (`progress_id`, `element`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Process Mapper
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `processes` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `title`             VARCHAR(255) NOT NULL,
    `description`       TEXT NULL,
    `created_by`        INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_steps` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `process_id`        INT NOT NULL,
    `type`              VARCHAR(50) NOT NULL DEFAULT 'process',
    `label`             VARCHAR(255) NOT NULL DEFAULT '',
    `description`       TEXT NULL,
    `url`               VARCHAR(500) NULL,
    `x`                 INT NOT NULL DEFAULT 0,
    `y`                 INT NOT NULL DEFAULT 0,
    `width`             INT NOT NULL DEFAULT 160,
    `height`            INT NOT NULL DEFAULT 80,
    `color`             VARCHAR(20) NULL DEFAULT '#0078d4',
    `color2`            VARCHAR(20) NULL,
    `lane_id`           INT NULL,
    `group_id`          INT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `idx_ps_process` (`process_id`),
    KEY `idx_ps_lane` (`lane_id`),
    KEY `idx_ps_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_connectors` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `process_id`        INT NOT NULL,
    `from_step_id`      INT NOT NULL,
    `to_step_id`        INT NOT NULL,
    `label`             VARCHAR(255) NULL DEFAULT '',
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `idx_pc_process` (`process_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_groups` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `process_id`        INT NOT NULL,
    `label`             VARCHAR(100) NULL DEFAULT '',
    `color`             VARCHAR(20) NULL DEFAULT '#e3f2fd',
    `color2`            VARCHAR(20) NULL,
    `x`                 INT NOT NULL DEFAULT 0,
    `y`                 INT NOT NULL DEFAULT 0,
    `width`             INT NOT NULL DEFAULT 240,
    `height`            INT NOT NULL DEFAULT 160,
    PRIMARY KEY (`id`),
    KEY `idx_pg_process` (`process_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_lanes` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `process_id`        INT NOT NULL,
    `label`             VARCHAR(100) NULL DEFAULT '',
    `color`             VARCHAR(20) NULL DEFAULT '#f5f7fa',
    `color2`            VARCHAR(20) NULL,
    `display_order`     INT NOT NULL DEFAULT 0,
    `height`            INT NOT NULL DEFAULT 180,
    PRIMARY KEY (`id`),
    KEY `idx_pl_process` (`process_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_annotations` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `process_id`        INT NOT NULL,
    `text`              TEXT NULL,
    `x`                 INT NOT NULL DEFAULT 0,
    `y`                 INT NOT NULL DEFAULT 0,
    `width`             INT NOT NULL DEFAULT 180,
    `height`            INT NOT NULL DEFAULT 100,
    `color`             VARCHAR(20) NULL DEFAULT '#fff59d',
    `color2`            VARCHAR(20) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pa_process` (`process_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_step_types` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(100) NOT NULL,
    `slug`           VARCHAR(50) NOT NULL,
    `shape`          VARCHAR(30) NOT NULL DEFAULT 'rounded',
    `color`          VARCHAR(20) NOT NULL DEFAULT '#0078d4',
    `display_order`  INT NOT NULL DEFAULT 0,
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `is_builtin`     TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_process_step_types_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `process_step_types` (`name`, `slug`, `shape`, `color`, `display_order`, `is_active`, `is_builtin`) VALUES
    ('Process',  'process',  'rounded',  '#0078d4', 10, 1, 1),
    ('Decision', 'decision', 'diamond',  '#f59e0b', 20, 1, 1),
    ('Terminal', 'start',    'pill',     '#10b981', 30, 1, 1),
    ('Document', 'document', 'document', '#8764b8', 40, 1, 1);

-- ----------------------------------------------------------
-- Workflows
-- ----------------------------------------------------------

-- Trigger / condition / action engine, cross-module. Conditions and actions
-- are stored as JSON in TEXT columns rather than normalised tables so the
-- engine can evolve the shape of a rule (extra operators, new action kinds)
-- without a schema migration each time.
CREATE TABLE IF NOT EXISTS `workflows` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(255) NOT NULL,
    `description`       TEXT NULL,
    `trigger_event`     VARCHAR(100) NOT NULL,
    `conditions`        TEXT NULL,                    -- JSON array of {field, op, value}
    `actions`           TEXT NOT NULL,                -- JSON array of {type, args}
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`        INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_run_datetime` DATETIME NULL,
    `last_run_status`   VARCHAR(20) NULL,             -- 'success' | 'failed' | 'skipped' | 'aborted'
    `run_count`         INT NOT NULL DEFAULT 0,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `idx_workflows_trigger` (`trigger_event`),
    KEY `idx_workflows_active` (`is_active`),
    CONSTRAINT `fk_workflows_created_by` FOREIGN KEY (`created_by`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Execution rows deliberately SURVIVE workflow deletion (they're the audit
-- trail): workflow_id is nullable with ON DELETE SET NULL, and workflow_name
-- snapshots the name at run time so orphaned runs stay attributable.
CREATE TABLE IF NOT EXISTS `workflow_executions` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `workflow_id`       INT NULL,
    `workflow_name`     VARCHAR(255) NULL,            -- snapshot at run time; survives workflow deletion
    `trigger_event`     VARCHAR(100) NOT NULL,
    `trigger_payload`   TEXT NULL,                    -- JSON snapshot of the event payload
    `status`            VARCHAR(20) NOT NULL,         -- 'running' | 'success' | 'failed' | 'skipped' | 'aborted'
    `is_dry_run`        TINYINT(1) NOT NULL DEFAULT 0, -- 1 = actions were described, not executed
    `started_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_datetime` DATETIME NULL,
    `step_log`          TEXT NULL,                    -- JSON array of per-step results
    `error_message`     TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_we_workflow` (`workflow_id`),
    KEY `idx_we_started` (`started_datetime`),
    CONSTRAINT `fk_we_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound webhook delivery queue. The `send_webhook` workflow action enqueues
-- a row (status 'pending'); the cron worker (cron/webhook_deliveries.php)
-- delivers it asynchronously with retries + exponential backoff, so a slow or
-- dead endpoint never blocks the host request. The signing secret is NOT
-- stored — the signature header is computed at enqueue time and kept in
-- request_headers, so retries reuse it without persisting the secret.
-- Time-based workflow triggers: the fire-once ledger.
--
-- Every other trigger hangs off a write path (someone saved a ticket), so there
-- is a moment to dispatch from. "The SLA is about to breach" is not an event —
-- nothing happened, TIME PASSED — so a cron has to go looking. And the condition
-- it finds STAYS TRUE: a breached SLA is still breached on the next run. Without
-- this ledger the escalation would re-fire every few minutes, forever.
--
-- `fingerprint` is the state the emission was recorded against (an SLA target,
-- a contract end date). If that changes — priority changed, contract renewed —
-- the fingerprint changes, and the new deadline is allowed to fire again. Without
-- it, "fire once" would silently mean "fire once ever, even if the thing you were
-- watching changed underneath you".
--
-- The UNIQUE key is what makes it atomic: INSERT IGNORE, and only the insert that
-- actually created a row dispatches. Two overlapping cron runs cannot double-fire.
CREATE TABLE IF NOT EXISTS `workflow_scheduled_emissions` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `trigger_event`     VARCHAR(100) NOT NULL,   -- e.g. 'sla.breached'
    `entity_key`        VARCHAR(120) NOT NULL,   -- WHAT: 'ticket:183:response'
    `fingerprint`       VARCHAR(64)  NOT NULL,   -- the STATE it fired against
    `emitted_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wse_once` (`trigger_event`, `entity_key`, `fingerprint`),
    KEY `idx_wse_emitted` (`emitted_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook message formats. A chat "preset" is just a JSON body template with a
-- {{message}} slot — Slack is {"text": "{{message}}"}, Discord is
-- {"content": "{{message}}"} — so they live as DATA rather than a PHP switch,
-- and an admin can add Google Chat / Mattermost / Rocket.Chat with no code change.
-- Built-ins are seeded and LOCKED (is_builtin = 1); users add their own.
-- NB `custom` and `full` are NOT rows here: they aren't message-wrapping formats,
-- they're structurally different, and they stay in the engine.
CREATE TABLE IF NOT EXISTS `webhook_message_formats` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `format_key`     VARCHAR(40) NOT NULL,           -- stored in the workflow's action args
    `label`          VARCHAR(100) NOT NULL,          -- shown in the Format dropdown
    `body_template`  TEXT NOT NULL,                  -- JSON, with {{message}} (and any payload vars)
    `url_pattern`    VARCHAR(255) NULL,              -- regex fragment; warns on a mismatched webhook URL
    `markdown_hint`  VARCHAR(255) NULL,              -- e.g. Discord's **bold** vs Slack's *bold*
    `is_builtin`     TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = shipped, not editable/deletable
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`  INT NOT NULL DEFAULT 0,
    `created_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wmf_key` (`format_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `webhook_message_formats` (`format_key`, `label`, `body_template`, `url_pattern`, `markdown_hint`, `is_builtin`, `display_order`) VALUES
    ('slack',   'Slack',            '{"text": "{{message}}"}',    'hooks\\.slack\\.com',                                        'Slack mrkdwn: *bold*, _italic_, `code`. Links are <https://example.com|like this>.', 1, 10),
    ('teams',   'Microsoft Teams',  '{"@type": "MessageCard", "@context": "https://schema.org/extensions", "summary": "{{message}}", "text": "{{message}}"}', 'webhook\\.office\\.com|office\\.com/webhookb2|logic\\.azure\\.com', 'Teams MessageCard: **bold**, *italic*, [link](https://example.com).', 1, 20),
    ('discord', 'Discord',          '{"content": "{{message}}"}', 'discord(app)?\\.com/api/webhooks',                           'Discord markdown: **bold** (two asterisks — a single *asterisk* is italic). Emoji shortcodes like :rotating_light: work.', 1, 30);

CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
    `id`                 INT NOT NULL AUTO_INCREMENT,
    `workflow_id`        INT NULL,                       -- source workflow (SET NULL if deleted)
    `execution_id`       INT NULL,                       -- the workflow_executions row that enqueued it
    `preset`             VARCHAR(20) NULL,               -- 'custom' | 'slack' | 'teams' | 'discord'
    -- 2000, not 1000: the URL is ENCRYPTED at rest (AES-256-GCM, ENC: prefix),
    -- which inflates it by ~1/3 + 28 bytes. A max-length 1000-char URL becomes
    -- ~1377 chars — at VARCHAR(1000) MySQL would silently truncate it, and a
    -- truncated ciphertext can never be decrypted again.
    `url`                VARCHAR(2000) NOT NULL,
    `method`             VARCHAR(10) NOT NULL DEFAULT 'POST',
    `request_headers`    TEXT NULL,                      -- JSON array of header lines (Content-Type + optional signature; NO secret)
    `request_body`       MEDIUMTEXT NULL,                -- the rendered JSON payload; purged per the payload-retention setting
    `payload_purged`     TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = body scrubbed by retention (so "empty" != "never had one"); blocks Replay
    `status`             VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | delivering | delivered | failed | dead
    `attempts`           INT NOT NULL DEFAULT 0,
    `max_attempts`       INT NOT NULL DEFAULT 6,
    `next_attempt_at`    DATETIME NULL,                  -- earliest time to (re)try; NULL = asap
    `last_status_code`   INT NULL,
    `last_error`         VARCHAR(500) NULL,
    `response_snippet`   MEDIUMTEXT NULL,                 -- full response body from the endpoint, for the delivery log
    `created_datetime`   DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`   DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `delivered_datetime` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_wd_due` (`status`, `next_attempt_at`),
    KEY `idx_wd_workflow` (`workflow_id`),
    KEY `idx_wd_created` (`created_datetime`),
    CONSTRAINT `fk_wd_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- System
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `system_logs` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `log_type`          VARCHAR(50) NOT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `analyst_id`        INT NULL,
    `details`           LONGTEXT NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key`       VARCHAR(100) NOT NULL,
    `setting_value`     LONGTEXT NULL,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
    ('tasks_calendar_span_mode', 'deadline');

-- SSO global switches: master kill switch (off until a provider is configured)
-- and the local-login break-glass toggle (on by default).
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
    ('sso_enabled', '0'),
    ('local_login_enabled', '1');

-- Install-wide date and time format (GH #105). Set in System > Date and time
-- formats; an analyst can override it for themselves in Preferences. These are
-- the values the app rendered BEFORE the setting existed, so seeding them means
-- an upgrade changes nothing on screen. Values are KEYS from DateFmt, never
-- pattern strings - see includes/timezone.php.
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
    ('date_format', 'd_mon_y'),
    ('time_format', '24h');

CREATE TABLE IF NOT EXISTS `trusted_devices` (
    `id`                 INT NOT NULL AUTO_INCREMENT,
    `analyst_id`         INT NOT NULL,
    `device_token_hash`  VARCHAR(255) NOT NULL,
    `user_agent`         VARCHAR(500) NULL,
    `ip_address`         VARCHAR(45) NULL,
    `created_datetime`   DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_datetime`   DATETIME NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `token_hash`        VARCHAR(255) NOT NULL,
    `expires_datetime`  DATETIME NOT NULL,
    `used`              TINYINT(1) NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_prt_token` (`token_hash`),
    KEY `idx_prt_analyst` (`analyst_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ip_login_bans` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `ip_address`        VARCHAR(45) NOT NULL,
    `attempt_count`     INT NOT NULL DEFAULT 0,
    `ban_count`         INT NOT NULL DEFAULT 0,
    `banned_until`      DATETIME NULL,
    `last_attempt`      DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ip_bans_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Service Status
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `status_services` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(500) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_incident_statuses` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `is_resolved`       TINYINT(1) NOT NULL DEFAULT 0,
    `colour`            VARCHAR(20) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_service_incident_statuses_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `service_incident_statuses` (`name`, `is_resolved`, `colour`, `is_default`, `display_order`) VALUES
    ('Investigating', 0, '#dc2626', 1, 10),
    ('Identified',    0, '#f59e0b', 0, 20),
    ('Monitoring',    0, '#0891b2', 0, 30),
    ('3rd Party',     0, '#9333ea', 0, 40),
    ('Resolved',      1, '#16a34a', 0, 50);

-- Service impact levels: severity_order drives "worst current impact" ordering
-- (replaces the hardcoded CASE statement that used to live in get_dashboard.php).
-- 1 = worst, 5 = best — matches the existing CASE convention.
CREATE TABLE IF NOT EXISTS `service_impact_levels` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(50) NOT NULL,
    `colour`            VARCHAR(20) NULL,
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `severity_order`    INT NOT NULL DEFAULT 99,
    `display_order`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    -- Does time at this level count against uptime? A property OF the level
    -- rather than a separate "downtime rules" screen, so a custom level added
    -- later is asked the question when it is created instead of silently
    -- defaulting to whatever a second list happened to say. Planned maintenance
    -- is excluded by convention — counting it makes a well-run service look
    -- worse than a neglected one.
    `counts_as_downtime` TINYINT(1) NOT NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_service_impact_levels_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `service_impact_levels` (`name`, `colour`, `is_default`, `severity_order`, `display_order`, `counts_as_downtime`) VALUES
    ('Major Outage',   '#dc2626', 0, 1, 10, 1),
    ('Partial Outage', '#f59e0b', 0, 2, 20, 1),
    ('Degraded',       '#eab308', 0, 3, 30, 1),
    ('Maintenance',    '#0891b2', 0, 4, 40, 0),
    ('Operational',    '#16a34a', 1, 5, 50, 0),
    ('No Disruption',  '#9ca3af', 0, 6, 60, 0);

CREATE TABLE IF NOT EXISTS `status_incidents` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `title`                 VARCHAR(255) NOT NULL,
    `status_id`             INT NULL,
    `comment`               LONGTEXT NULL,
    `created_by_id`         INT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_datetime`     DATETIME NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `ix_status_incidents_status_id` (`status_id`),
    CONSTRAINT `fk_status_incidents_status` FOREIGN KEY (`status_id`) REFERENCES `service_incident_statuses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `status_incident_services` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `incident_id`       INT NOT NULL,
    `service_id`        INT NOT NULL,
    `impact_level_id`   INT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `ix_sis_impact_level_id` (`impact_level_id`),
    CONSTRAINT `fk_sis_impact_level` FOREIGN KEY (`impact_level_id`) REFERENCES `service_impact_levels` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Incident update log (discussion #59, phase 2)
--
-- status_incident_services holds only the CURRENT impact per service: saving an
-- incident deletes and re-inserts those rows, so downgrading a service from
-- Major Outage to Degraded overwrote the earlier value and the history reported
-- the whole incident at whatever it ended on. That is what this fixes.
--
-- ⚠️ EACH UPDATE IS A FULL SNAPSHOT, not a diff. Every save writes one row here
-- plus one _update_services row per affected service, describing the state at
-- that moment. Carrying values forward from a diff would mean the reader has to
-- reconstruct state, and a single missing row would silently shift a service's
-- whole timeline. A snapshot costs a few more rows and cannot drift.
--
-- A service being restored is recorded either by moving it to a level that does
-- not count as downtime, or by dropping it from the snapshot entirely. Both end
-- its interval at that update, which is why the reader does not need a special
-- "resolved" marker per service.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `status_incident_updates` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `incident_id`       INT NOT NULL,
    `status_id`         INT NULL,
    `comment`           LONGTEXT NULL,
    -- Internal by default (#99). An update written before this column
    -- existed becomes internal, so an upgrade can never retroactively
    -- publish troubleshooting notes to the self-service portal.
    `is_internal` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_id`     INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Every read walks an incident's updates in time order, so both are indexed.
    KEY `ix_siu_incident_id` (`incident_id`),
    KEY `ix_siu_created` (`created_datetime`),
    CONSTRAINT `fk_siu_incident` FOREIGN KEY (`incident_id`) REFERENCES `status_incidents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `status_incident_update_services` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `update_id`         INT NOT NULL,
    `service_id`        INT NOT NULL,
    `impact_level_id`   INT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_sius_update_id` (`update_id`),
    KEY `ix_sius_service_id` (`service_id`),
    CONSTRAINT `fk_sius_update` FOREIGN KEY (`update_id`) REFERENCES `status_incident_updates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- CMDB (Configuration Management Database)
-- See docs/cmdb.md for the full design rationale.
-- ----------------------------------------------------------

-- Curated icon library. The icon_key references SVG path data held in PHP
-- (cmdb/includes/icons.php once the picker UX lands); the DB only stores
-- which icon a class has chosen, not the SVG itself. Keeping it as a lookup
-- (rather than a free-text VARCHAR on cmdb_classes) means adding/renaming
-- icons later doesn't require touching every class row.
CREATE TABLE IF NOT EXISTS `cmdb_icons` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `icon_key`          VARCHAR(50) NOT NULL,
    `label`             VARCHAR(100) NOT NULL,
    `display_order`     INT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cmdb_icons_key` (`icon_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cmdb_classes` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `class_key`         VARCHAR(100) NOT NULL,
    `name`              VARCHAR(150) NOT NULL,
    `description`       VARCHAR(500) NULL,
    `icon_id`           INT NULL,
    `display_order`     INT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cmdb_classes_key` (`class_key`),
    KEY `ix_cmdb_classes_icon_id` (`icon_id`),
    CONSTRAINT `fk_cmdb_classes_icon` FOREIGN KEY (`icon_id`) REFERENCES `cmdb_icons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cmdb_class_properties` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `class_id`          INT NOT NULL,
    `property_key`      VARCHAR(100) NOT NULL,
    `label`             VARCHAR(150) NOT NULL,
    `property_type`     VARCHAR(20) NOT NULL,
    -- text | number | date | boolean | dropdown | object_ref
    `target_class_id`   INT NULL,
    -- only used when property_type = 'object_ref'
    `is_required`       TINYINT(1) NULL DEFAULT 0,
    -- object_ref only: a dependency recorded as a field rather than a
    -- relationship (e.g. a Database whose "Host Server" points at a Server).
    -- 1 = if the referenced object fails, the object holding this field is
    -- affected. Ignored for every other property type.
    `spreads_impact`    TINYINT(1) NOT NULL DEFAULT 0,
    `display_order`     INT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cmdb_class_property_key` (`class_id`, `property_key`),
    CONSTRAINT `fk_cmdb_cp_class` FOREIGN KEY (`class_id`) REFERENCES `cmdb_classes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cmdb_cp_target_class` FOREIGN KEY (`target_class_id`) REFERENCES `cmdb_classes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cmdb_class_property_options` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `property_id`       INT NOT NULL,
    `option_value`      VARCHAR(255) NOT NULL,
    `colour`            VARCHAR(7) NULL,
    -- hex colour like "#22c55e", optional. Drives the coloured pill on the
    -- object detail page when set; plain text fallback otherwise.
    `display_order`     INT NULL DEFAULT 0,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `ix_cmdb_cpo_property_id` (`property_id`),
    CONSTRAINT `fk_cmdb_cpo_property` FOREIGN KEY (`property_id`) REFERENCES `cmdb_class_properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cmdb_objects` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `class_id`          INT NOT NULL,
    `name`              VARCHAR(255) NOT NULL,
    `parent_id`         INT NULL,
    `is_planned`        TINYINT(1) NOT NULL DEFAULT 0,
    `ai_summary`        LONGTEXT NULL,
    `ai_summary_generated_at` DATETIME NULL,
    -- Multi-tenancy: SCOPED DATA. The company this configuration item belongs
    -- to; NULL = the Default company (which is every CI on a single-company
    -- install, and every pre-existing CI on upgrade). A CI belongs to exactly
    -- one company — there are no shared CIs — so parent/child, relationships and
    -- object_ref properties must all stay within one company; that invariant is
    -- enforced in CmdbService, not by the schema.
    -- Only cmdb_objects carries this: classes, properties, relationship types
    -- and icons are install-wide admin config, and the child tables
    -- (cmdb_object_properties, cmdb_object_relationships, ticket_cmdb_objects)
    -- inherit their company from the object they hang off.
    `tenant_id`         INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `ix_cmdb_objects_class_id` (`class_id`),
    KEY `ix_cmdb_objects_parent_id` (`parent_id`),
    KEY `ix_cmdb_objects_name` (`name`),
    KEY `ix_cmdb_objects_is_planned` (`is_planned`),
    KEY `ix_cmdb_objects_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_cmdb_objects_class` FOREIGN KEY (`class_id`) REFERENCES `cmdb_classes` (`id`),
    CONSTRAINT `fk_cmdb_objects_parent` FOREIGN KEY (`parent_id`) REFERENCES `cmdb_objects` (`id`) ON DELETE CASCADE,
    -- SET NULL, never CASCADE: deleting a company must not destroy its CI
    -- records — they revert to Default so the estate history survives. Mirrors
    -- fk_assets_tenant.
    CONSTRAINT `fk_cmdb_objects_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cmdb_object_properties` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `object_id`         INT NOT NULL,
    `property_id`       INT NOT NULL,
    `value_text`        TEXT NULL,
    `value_number`      DECIMAL(20,4) NULL,
    `value_date`        DATETIME NULL,
    `value_boolean`     TINYINT(1) NULL,
    `value_object_id`   INT NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cmdb_op_obj_prop` (`object_id`, `property_id`),
    KEY `ix_cmdb_op_value_object_id` (`value_object_id`),
    CONSTRAINT `fk_cmdb_op_object` FOREIGN KEY (`object_id`) REFERENCES `cmdb_objects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cmdb_op_property` FOREIGN KEY (`property_id`) REFERENCES `cmdb_class_properties` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cmdb_op_value_object` FOREIGN KEY (`value_object_id`) REFERENCES `cmdb_objects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cmdb_relationship_types` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `verb`              VARCHAR(100) NOT NULL,
    `inverse_verb`      VARCHAR(100) NOT NULL,
    `description`       VARCHAR(500) NULL,
    -- Whether a failure travels along this relationship, and which way.
    --   'none'    = it does not spread impact (e.g. "is located in")
    --   'to_from' = if the TO object fails, the FROM object is affected
    --               ("A depends on B": B breaks, so A is affected)
    --   'from_to' = if the FROM object fails, the TO object is affected
    --               ("A hosts B": A breaks, so B is affected)
    -- Defaults to 'none' so an upgraded install reports nothing until someone
    -- deliberately says a relationship carries impact.
    `impact_direction`  VARCHAR(10) NOT NULL DEFAULT 'none',
    `display_order`     INT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cmdb_rel_type_verb` (`verb`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cmdb_object_relationships` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `from_object_id`        INT NOT NULL,
    `to_object_id`          INT NOT NULL,
    `relationship_type_id`  INT NOT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cmdb_or_triple` (`from_object_id`, `to_object_id`, `relationship_type_id`),
    KEY `ix_cmdb_or_to_object_id` (`to_object_id`),
    CONSTRAINT `fk_cmdb_or_from` FOREIGN KEY (`from_object_id`) REFERENCES `cmdb_objects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cmdb_or_to`   FOREIGN KEY (`to_object_id`)   REFERENCES `cmdb_objects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cmdb_or_type` FOREIGN KEY (`relationship_type_id`) REFERENCES `cmdb_relationship_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Join table linking tickets to CMDB objects (M:N).
-- Drives the "Affected CMDB Objects" section on the ticket reading pane and
-- the "Activity" panel on the CMDB object detail page.
CREATE TABLE IF NOT EXISTS `ticket_cmdb_objects` (
    `id`                  INT NOT NULL AUTO_INCREMENT,
    `ticket_id`           INT NOT NULL,
    `cmdb_object_id`      INT NOT NULL,
    `created_datetime`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by_analyst_id` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_cmdb_obj` (`ticket_id`, `cmdb_object_id`),
    KEY `ix_tco_cmdb_object_id` (`cmdb_object_id`),
    CONSTRAINT `fk_tco_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tco_cmdb_object` FOREIGN KEY (`cmdb_object_id`) REFERENCES `cmdb_objects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tco_analyst` FOREIGN KEY (`created_by_analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Join table linking tickets to assets (M:N). Asked for in discussion #57.
-- Drives the "Affected equipment" section on the ticket reading pane and the
-- "Tickets" tab on the asset detail page.
--
-- Two nullable "created by" columns, not one: an analyst can attach any asset
-- from the ticket screen, and a portal user can attach one of their own when
-- raising a ticket. Analysts and end users live in different tables, so there is
-- no single id that covers both. Exactly one is set on any given row; both are
-- NULL only where the row was created by an automated path.
--
-- No tenant_id: the company is inherited from the ticket and the asset, both of
-- which carry their own, and a link may only ever be made between two rows in
-- the same company. That is enforced in application code, as it is for
-- ticket_cmdb_objects.
-- Assets covered by a contract (discussion #106).
--
-- No tenant_id, for the same reason ticket_assets has none: the company comes
-- from the asset, which carries its own. Contracts are NOT company-scoped at
-- all today, so the asset side is the only side that can answer "whose is
-- this?" - which is exactly why every list of linked assets must be filtered to
-- the companies the reader can reach, and why the write re-checks rather than
-- trusting the list it was picked from.
--
-- `reference` is the link's own free text: the phone number on a SIM, a line
-- ID, a seat number. It belongs on the LINK rather than the asset because it
-- describes the asset's place in this contract, and the same handset moved to a
-- different agreement keeps the handset and loses the line.
CREATE TABLE IF NOT EXISTS `contract_assets` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `contract_id`      INT NOT NULL,
    `asset_id`         INT NOT NULL,
    `reference`        VARCHAR(190) NULL,
    `linked_by_id`     INT NULL,
    `created_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_contract_asset` (`contract_id`, `asset_id`),
    KEY `ix_ca_asset_id` (`asset_id`),
    CONSTRAINT `fk_ca_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ca_asset`    FOREIGN KEY (`asset_id`)    REFERENCES `assets` (`id`)    ON DELETE CASCADE,
    CONSTRAINT `fk_ca_analyst`  FOREIGN KEY (`linked_by_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Saved table views (discussion #96).
--
-- One row per saved view, for ANY table that runs the shared data-table engine
-- (assets/js/data-table.js). `table_key` names which — 'assets', 'tasks',
-- 'calendar', 'changes' — so a view saved on the asset table can never appear on
-- the tasks table.
--
-- `config` is the engine's own state as JSON: which columns are visible and in
-- what order, the sort, and the per-column filters. Deliberately opaque to the
-- database: the engine owns that shape and a column per setting would need a
-- migration every time it gained one.
--
-- No tenant_id. A view is a way of LOOKING at rows, not the rows themselves; its
-- filters are applied to whatever the reader was already allowed to see, so a
-- shared view cannot expose another company's assets.
--
-- owner_id is SET NULL rather than CASCADE, so a team or public view survives
-- the person who wrote it leaving. A private view with no owner then matches
-- nobody, which is harmless - it is unreachable rather than exposed.
CREATE TABLE IF NOT EXISTS `table_views` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `table_key`        VARCHAR(32) NOT NULL,
    `name`             VARCHAR(120) NOT NULL,
    `description`      VARCHAR(500) NULL,
    `owner_id`         INT NULL,
    `visibility`       VARCHAR(10) NOT NULL DEFAULT 'private',
    `team_id`          INT NULL,
    `config`           MEDIUMTEXT NOT NULL,
    `created_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime` DATETIME NULL,
    `last_used_datetime` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `ix_tv_table_key` (`table_key`),
    KEY `ix_tv_owner` (`owner_id`),
    KEY `ix_tv_team` (`team_id`),
    CONSTRAINT `fk_tv_owner` FOREIGN KEY (`owner_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tv_team`  FOREIGN KEY (`team_id`)  REFERENCES `teams` (`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `ticket_assets` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `ticket_id`             INT NOT NULL,
    `asset_id`              INT NOT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by_analyst_id` INT NULL,
    `created_by_user_id`    INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_asset` (`ticket_id`, `asset_id`),
    KEY `ix_ta_asset_id` (`asset_id`),
    CONSTRAINT `fk_ta_ticket`  FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ta_asset`   FOREIGN KEY (`asset_id`)  REFERENCES `assets` (`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_ta_analyst` FOREIGN KEY (`created_by_analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ta_user`    FOREIGN KEY (`created_by_user_id`)    REFERENCES `users` (`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Network Mapper — visual diagrams over the CMDB graph.
-- A diagram is a curated view of a subset of CMDB objects plus the connections
-- between them. Diagrams support versioning: parent_diagram_id chains forward
-- through versions, with the "current" (editable) version being whichever row
-- in the chain has no children. Old versions are read-only historical records.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `network_diagrams` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `parent_diagram_id`     INT NULL,
    `title`                 VARCHAR(255) NOT NULL,
    `description`           TEXT NULL,
    `version_label`         VARCHAR(50) NULL,
    `created_by_analyst_id` INT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    -- Optional paper-size guide overlay (off when NULL). Sets up the
    -- WYSIWYG bounds for PNG/PDF export and shows a dashed outline on the
    -- canvas so analysts know what'll fit. Persisted per-diagram.
    `paper_size`            VARCHAR(20) NULL,
    `paper_orientation`     VARCHAR(20) NULL,
    -- Per-diagram header/footer override slots. NULL = inherit the org-wide
    -- default from system_settings (`branding_header_left` etc.); non-NULL
    -- (including '') = explicit override. Renders only when paper_size is set.
    `header_left`           VARCHAR(200) NULL,
    `header_center`         VARCHAR(200) NULL,
    `header_right`          VARCHAR(200) NULL,
    `footer_left`           VARCHAR(200) NULL,
    `footer_center`         VARCHAR(200) NULL,
    `footer_right`          VARCHAR(200) NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `ix_net_diag_parent` (`parent_diagram_id`),
    KEY `ix_net_diag_author` (`created_by_analyst_id`),
    CONSTRAINT `fk_net_diag_parent` FOREIGN KEY (`parent_diagram_id`) REFERENCES `network_diagrams` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_net_diag_author` FOREIGN KEY (`created_by_analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `network_diagram_nodes` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `diagram_id`     INT NOT NULL,
    `cmdb_object_id` INT NOT NULL,
    `x`              INT NOT NULL DEFAULT 0,
    `y`              INT NOT NULL DEFAULT 0,
    `size`           VARCHAR(20) NOT NULL DEFAULT 'medium',
    `icon_override`  VARCHAR(100) NULL,
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `ix_net_node_diag` (`diagram_id`),
    KEY `ix_net_node_cmdb` (`cmdb_object_id`),
    CONSTRAINT `fk_net_node_diag` FOREIGN KEY (`diagram_id`) REFERENCES `network_diagrams` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_net_node_cmdb` FOREIGN KEY (`cmdb_object_id`) REFERENCES `cmdb_objects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `network_diagram_connectors` (
    `id`                       INT NOT NULL AUTO_INCREMENT,
    `diagram_id`               INT NOT NULL,
    `from_node_id`             INT NOT NULL,
    `to_node_id`               INT NOT NULL,
    `cmdb_relationship_id`     INT NULL,
    `label`                    VARCHAR(255) NULL,
    `line_style`               VARCHAR(20) NULL DEFAULT 'solid',
    `is_demo`           TINYINT(1) NOT NULL DEFAULT 0,   -- set by the demo data importer (#1297)
    PRIMARY KEY (`id`),
    KEY `ix_net_conn_diag` (`diagram_id`),
    KEY `ix_net_conn_from` (`from_node_id`),
    KEY `ix_net_conn_to` (`to_node_id`),
    CONSTRAINT `fk_net_conn_diag` FOREIGN KEY (`diagram_id`) REFERENCES `network_diagrams` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_net_conn_from` FOREIGN KEY (`from_node_id`) REFERENCES `network_diagram_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_net_conn_to`   FOREIGN KEY (`to_node_id`)   REFERENCES `network_diagram_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_net_conn_rel`  FOREIGN KEY (`cmdb_relationship_id`) REFERENCES `cmdb_object_relationships` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Search corpus. One row per SEARCHABLE UNIT, whatever it came from: a ticket
-- subject, an email body, an internal note, and later the extracted text of an
-- attachment or a knowledge article. Keeping them in one table (rather than a
-- FULLTEXT index per source table) is what lets a single query rank a note hit
-- against an attachment hit -- relevance scores from different full-text indexes
-- are computed against their own corpus statistics and are not comparable.
--
-- DERIVED, NOT AUTHORITATIVE. Every row is rebuildable from its source; nothing
-- is stored here that does not exist elsewhere. `body` holds STRIPPED PLAINTEXT,
-- never the HTML in emails.body_content -- indexing markup makes every ticket
-- "contain" div, span and style.
--
-- ⚠️ tenant_scope exists because NULL means DIFFERENT THINGS in the source
-- tables: a ticket with tenant_id IS NULL belongs to the DEFAULT company, while
-- a knowledge article with tenant_id IS NULL is SHARED WITH EVERY company -- the
-- exact opposite. Overloading NULL here would make the scope of a row depend on
-- which source_type it came from, so the meaning is written down instead:
--   'company' -> visible to tenant_id only
--   'default' -> the source's NULL meant "the default company"
--   'shared'  -> the source's NULL meant "every company"
--
-- ⚠️ PORTAL EXPOSURE IS DELIBERATELY NOT MODELLED YET. is_internal is a fact we
-- can always establish (a note is internal or it is not). Whether a requester
-- may see a message sent to a third party is an install SETTING applied at read
-- time, and whether portal users get content search at all is still an open
-- product question -- so phase 1 is analyst-only and nothing here should be read
-- as sufficient for exposing search to customers.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `search_documents` (
    `id`               BIGINT NOT NULL AUTO_INCREMENT,
    `source_type`      VARCHAR(32) NOT NULL,
    `source_id`        INT NOT NULL,
    `ticket_id`        INT NULL,
    `tenant_id`        INT NULL,
    `tenant_scope`     VARCHAR(16) NOT NULL DEFAULT 'company',
    `is_internal`      TINYINT(1) NOT NULL DEFAULT 0,
    `title`            VARCHAR(500) NULL,
    `body`             MEDIUMTEXT NULL,
    `source_datetime`  DATETIME NULL,
    `indexed_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_search_docs_source` (`source_type`,`source_id`),
    KEY `idx_search_docs_ticket` (`ticket_id`),
    KEY `idx_search_docs_tenant` (`tenant_id`),
    FULLTEXT KEY `ft_search_docs` (`title`,`body`),
    FULLTEXT KEY `ft_search_docs_title` (`title`),
    CONSTRAINT `fk_search_docs_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the curated icon library on first run. Adding more icons later means
-- inserting a row here AND adding the SVG path to cmdb/includes/icons.php.
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`)
SELECT * FROM (SELECT 'server'        AS icon_key, 'Server'         AS label,  10 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'server');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'database'      AS icon_key, 'Database'       AS label,  20 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'database');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'application'   AS icon_key, 'Application'    AS label,  30 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'application');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'service'       AS icon_key, 'Service'        AS label,  40 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'service');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'website'       AS icon_key, 'Website'        AS label,  50 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'website');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'api'           AS icon_key, 'API'            AS label,  60 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'api');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'vm'            AS icon_key, 'Virtual Machine' AS label, 70 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'vm');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'container'     AS icon_key, 'Container'      AS label,  80 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'container');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'cloud'         AS icon_key, 'Cloud Resource' AS label,  90 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'cloud');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'network'       AS icon_key, 'Network Device' AS label, 100 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'network');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'firewall'      AS icon_key, 'Firewall'       AS label, 110 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'firewall');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'router'        AS icon_key, 'Router'         AS label, 120 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'router');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'switch'        AS icon_key, 'Switch'         AS label, 130 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'switch');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'storage'       AS icon_key, 'Storage'        AS label, 140 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'storage');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'workstation'   AS icon_key, 'Workstation'    AS label, 150 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'workstation');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'printer'       AS icon_key, 'Printer'        AS label, 160 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'printer');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'person'        AS icon_key, 'Person'         AS label, 170 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'person');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'team'          AS icon_key, 'Team'           AS label, 180 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'team');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'document'      AS icon_key, 'Document'       AS label, 190 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'document');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'box'           AS icon_key, 'Generic'        AS label, 200 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'box');

-- Extended icon library (added with the Network Mapper per-node override
-- feature). Display orders interleaved so related variants group together
-- in the CMDB Classes picker. Same NOT EXISTS guard pattern so re-running
-- the SQL is idempotent.
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'server-rack'    AS icon_key, 'Server (rack)'      AS label,  11 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'server-rack');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'server-blade'   AS icon_key, 'Server (blade)'     AS label,  12 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'server-blade');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'server-tower'   AS icon_key, 'Server (tower)'     AS label,  13 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'server-tower');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'mainframe'      AS icon_key, 'Mainframe'          AS label,  14 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'mainframe');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'function'       AS icon_key, 'Function'           AS label,  71 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'function');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'database-cluster' AS icon_key, 'Database cluster' AS label,  21 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'database-cluster');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'database-cache' AS icon_key, 'Database (cache)'   AS label,  22 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'database-cache');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'storage-san'    AS icon_key, 'SAN'                AS label, 141 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'storage-san');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'storage-tape'   AS icon_key, 'Tape backup'        AS label, 142 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'storage-tape');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'backup'         AS icon_key, 'Backup'             AS label, 143 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'backup');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'load-balancer'  AS icon_key, 'Load balancer'      AS label, 111 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'load-balancer');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'proxy'          AS icon_key, 'Proxy'              AS label, 112 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'proxy');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'vpn'            AS icon_key, 'VPN'                AS label, 113 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'vpn');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'gateway'        AS icon_key, 'Gateway'            AS label, 114 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'gateway');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'wireless-ap'    AS icon_key, 'Wireless AP'        AS label, 131 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'wireless-ap');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'modem'          AS icon_key, 'Modem'              AS label, 132 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'modem');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'cdn'            AS icon_key, 'CDN'                AS label, 115 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'cdn');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'dns'            AS icon_key, 'DNS'                AS label, 116 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'dns');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'shield'         AS icon_key, 'Shield'             AS label, 117 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'shield');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'lock'           AS icon_key, 'Lock'               AS label, 118 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'lock');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'key'            AS icon_key, 'Key'                AS label, 119 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'key');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'ids'            AS icon_key, 'IDS / IPS'          AS label, 121 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'ids');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'siem'           AS icon_key, 'SIEM'               AS label, 122 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'siem');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'cloud-private'  AS icon_key, 'Private cloud'      AS label,  91 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'cloud-private');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'cloud-public'   AS icon_key, 'Public cloud'       AS label,  92 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'cloud-public');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'cloud-hybrid'   AS icon_key, 'Hybrid cloud'       AS label,  93 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'cloud-hybrid');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'region'         AS icon_key, 'Region'             AS label,  94 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'region');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'container-pod'  AS icon_key, 'Pod'                AS label,  81 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'container-pod');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'kubernetes'     AS icon_key, 'Kubernetes'         AS label,  82 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'kubernetes');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'registry'       AS icon_key, 'Registry'           AS label,  83 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'registry');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'microservice'   AS icon_key, 'Microservice'       AS label,  31 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'microservice');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'queue'          AS icon_key, 'Message queue'      AS label,  32 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'queue');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'cache'          AS icon_key, 'Cache'              AS label,  33 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'cache');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'dashboard'      AS icon_key, 'Dashboard'          AS label,  34 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'dashboard');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'laptop'         AS icon_key, 'Laptop'             AS label, 151 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'laptop');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'mobile'         AS icon_key, 'Mobile'             AS label, 152 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'mobile');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'tablet'         AS icon_key, 'Tablet'             AS label, 153 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'tablet');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'iot'            AS icon_key, 'IoT device'         AS label, 154 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'iot');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'monitor'        AS icon_key, 'Monitor / gauge'    AS label, 161 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'monitor');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'alert'          AS icon_key, 'Alert'              AS label, 162 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'alert');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'log'            AS icon_key, 'Log'                AS label, 163 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'log');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'org'            AS icon_key, 'Org'                AS label, 181 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'org');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'folder'         AS icon_key, 'Folder'             AS label, 191 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'folder');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'globe'          AS icon_key, 'Globe'              AS label, 192 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'globe');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'mail'           AS icon_key, 'Mail'               AS label, 193 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'mail');
INSERT INTO `cmdb_icons` (`icon_key`, `label`, `display_order`) SELECT * FROM (SELECT 'calendar'       AS icon_key, 'Calendar'           AS label, 194 AS display_order) AS t WHERE NOT EXISTS (SELECT 1 FROM `cmdb_icons` WHERE icon_key = 'calendar');

-- Seed a small starter set of relationship verbs so analysts have something
-- to work with on first run. Easily editable from CMDB → Settings.
-- Only 'depends on' carries impact out of the box: "A depends on B" means B
-- failing affects A, so impact travels to_from. A network link and a management
-- relationship do NOT propagate failure, so they stay 'none' — the blast radius
-- should under-claim by default rather than invent consequences.
INSERT INTO `cmdb_relationship_types` (`verb`, `inverse_verb`, `description`, `impact_direction`, `display_order`)
SELECT * FROM (SELECT 'depends on'  AS verb, 'is depended on by' AS inverse_verb, 'A needs B in order to function'  AS description, 'to_from' AS impact_direction, 10 AS display_order) AS t
WHERE NOT EXISTS (SELECT 1 FROM `cmdb_relationship_types` WHERE verb = 'depends on');
INSERT INTO `cmdb_relationship_types` (`verb`, `inverse_verb`, `description`, `impact_direction`, `display_order`)
SELECT * FROM (SELECT 'connects to' AS verb, 'is connected from' AS inverse_verb, 'A has a network or data link to B' AS description, 'none' AS impact_direction, 20 AS display_order) AS t
WHERE NOT EXISTS (SELECT 1 FROM `cmdb_relationship_types` WHERE verb = 'connects to');
INSERT INTO `cmdb_relationship_types` (`verb`, `inverse_verb`, `description`, `impact_direction`, `display_order`)
SELECT * FROM (SELECT 'managed by'  AS verb, 'manages'           AS inverse_verb, 'A is administered by B'           AS description, 'none' AS impact_direction, 30 AS display_order) AS t
WHERE NOT EXISTS (SELECT 1 FROM `cmdb_relationship_types` WHERE verb = 'managed by');

-- ----------------------------------------------------------
-- War room — fallback chat for when Teams/Slack is unavailable
-- ----------------------------------------------------------
-- Every conversation is a row in `warroom_channels`, of one of four KINDS, so
-- that a message needs exactly one foreign key rather than a nullable column per
-- kind. The kinds differ in where their identity comes from:
--
--   all    the one all-hands room. Always exists, always listed first: in a real
--          outage you want one obvious place for everybody, not six team rooms
--          and an argument about which to use.
--   team   one per team. `team_id` is UNIQUE and CASCADEs, and the channel's NAME
--          IS NOT STORED — it is read from `teams` at display time. So a team
--          channel cannot be duplicated, cannot be orphaned, and cannot be
--          renamed into something the team is no longer called.
--   custom somebody made it. This kind, and only this kind, has a lifecycle
--          (create, rename, archive) — the cost of the feature, paid knowingly.
--   dm     a pair of analysts. `dm_key` is "<lower id>:<higher id>" and UNIQUE.
--          Without that constraint two people opening a DM with each other at the
--          same moment get one conversation each and neither sees the other's —
--          exactly the failure you would hit mid-incident, when both are trying.
--
-- Retention is a setting (`warroom_retention_days`, 0 = keep forever) applied
-- opportunistically on send, so it needs no cron — a tool for the day the
-- internet is down should not depend on scheduled jobs having been set up.
CREATE TABLE IF NOT EXISTS `warroom_channels` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `kind`              VARCHAR(10) NOT NULL DEFAULT 'custom',
    `team_id`           INT NULL,
    `dm_key`            VARCHAR(41) NULL,
    `name`              VARCHAR(120) NULL,
    `topic`             VARCHAR(255) NULL,
    `is_private`        TINYINT(1) NOT NULL DEFAULT 0,
    `created_by`        INT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `archived_datetime` DATETIME NULL,
    PRIMARY KEY (`id`),
    -- One channel per team, and one DM per pair. Both are UNIQUE over a NULLABLE
    -- column, which MySQL allows to repeat NULLs — so the other kinds are unaffected.
    UNIQUE KEY `uq_warroom_channels_team` (`team_id`),
    UNIQUE KEY `uq_warroom_channels_dm` (`dm_key`),
    KEY `ix_warroom_channels_kind` (`kind`, `archived_datetime`),
    CONSTRAINT `fk_warroom_channels_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
    -- SET NULL: a channel must outlive whoever opened it, or deleting a leaver
    -- would take the incident room with them.
    CONSTRAINT `fk_warroom_channels_creator` FOREIGN KEY (`created_by`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Membership, for private custom channels and for DMs. Team membership is NOT
-- duplicated here — it lives in `analyst_teams` and is read from there, so the
-- two can never disagree about who is in a team.
CREATE TABLE IF NOT EXISTS `warroom_channel_members` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `channel_id`       INT NOT NULL,
    `analyst_id`       INT NOT NULL,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_warroom_member` (`channel_id`, `analyst_id`),
    KEY `ix_warroom_member_analyst` (`analyst_id`),
    CONSTRAINT `fk_warroom_member_channel` FOREIGN KEY (`channel_id`) REFERENCES `warroom_channels` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_warroom_member_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The two delete rules on a message differ on purpose:
--   channel_id CASCADE   delete a channel and its conversation goes with it.
--   analyst_id SET NULL  delete an analyst and the conversation SURVIVES. This
--                        is the record of what was said during an incident;
--                        losing half of it because somebody left the company
--                        would be the wrong trade. Those rows show as
--                        "Former analyst".
--
-- `edited_datetime` / `deleted_datetime` are RECORDED, not hidden. This table is
-- the record of what was said during an incident, so a message that changed after
-- the fact says so, and a deleted one leaves a tombstone rather than a silent gap.
-- The body and any attachments really are destroyed on delete — the point is that
-- somebody can see a message was removed, not that its contents survive.
CREATE TABLE IF NOT EXISTS `warroom_messages` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `channel_id`       INT NULL,
    `analyst_id`       INT NULL,
    `body`             TEXT NOT NULL,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_bot`           TINYINT(1) NOT NULL DEFAULT 0,
    `reply_to_id`      INT NULL,
    `edited_datetime`  DATETIME NULL,
    `deleted_datetime` DATETIME NULL,
    `deleted_by`       INT NULL,
    PRIMARY KEY (`id`),
    -- (channel_id, id): every read is "this channel, newer than id N".
    KEY `ix_warroom_messages_channel` (`channel_id`, `id`),
    -- Retention deletes by age across every channel at once.
    KEY `ix_warroom_messages_created` (`created_datetime`),
    -- Warbot's reply points at the message it answers, which is also how a
    -- duplicate answer is prevented when the trigger is retried.
    KEY `ix_warroom_messages_reply` (`reply_to_id`),
    CONSTRAINT `fk_warroom_messages_channel` FOREIGN KEY (`channel_id`) REFERENCES `warroom_channels` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_warroom_messages_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_warroom_messages_deleter` FOREIGN KEY (`deleted_by`) REFERENCES `analysts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ⚠️ THERE IS NO `content_type` COLUMN, AND THAT IS THE POINT. What an attachment
-- is served as is worked out from its extension against our own map at serve time
-- (`attachmentServeRules`, security finding F5 — an attachment that declares its
-- own type can declare `image/svg+xml` and run script in the reader's session).
-- Storing the uploader's claim would only leave something for a future endpoint to
-- trust by mistake, so the temptation is removed rather than merely documented.
--
-- `stored_name` is the random name includes/uploads.php generated; `original_name`
-- is for display and the download filename only, and never touches the path.
CREATE TABLE IF NOT EXISTS `warroom_attachments` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `message_id`       INT NOT NULL,
    `stored_name`      VARCHAR(100) NOT NULL,
    `original_name`    VARCHAR(255) NOT NULL,
    `size_bytes`       INT NOT NULL DEFAULT 0,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_warroom_attachments_message` (`message_id`),
    KEY `ix_warroom_attachments_stored` (`stored_name`),
    CONSTRAINT `fk_warroom_attachments_message` FOREIGN KEY (`message_id`) REFERENCES `warroom_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who was named in a message. One row PER RECIPIENT — `@everyone` is expanded at
-- send time rather than stored as a flag, which keeps "what has my name on it" a
-- simple equality, and makes the record point-in-time correct: you notified the
-- people entitled to that channel then, not whoever is entitled to it now.
--
-- ⚠️ There is no read column. A mention is unread when its message is newer than
-- your `warroom_reads` marker for that channel — reusing state that already exists
-- rather than adding a second copy that can disagree with it.
CREATE TABLE IF NOT EXISTS `warroom_mentions` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `message_id`       INT NOT NULL,
    `analyst_id`       INT NOT NULL,
    `created_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- The same person cannot be named twice by one message, however many times
    -- their name appears in it.
    UNIQUE KEY `uq_warroom_mention` (`message_id`, `analyst_id`),
    -- (analyst_id, id): the notifications panel asks "mine, newest first".
    KEY `ix_warroom_mentions_analyst` (`analyst_id`, `id`),
    CONSTRAINT `fk_warroom_mentions_message` FOREIGN KEY (`message_id`) REFERENCES `warroom_messages` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_warroom_mentions_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How far each analyst has read in each channel, so the list can show what is new.
-- Upserted, and the id only ever moves forward (GREATEST) — an out-of-order poll
-- must not un-read a channel.
CREATE TABLE IF NOT EXISTS `warroom_reads` (
    `id`                   INT NOT NULL AUTO_INCREMENT,
    `analyst_id`           INT NOT NULL,
    `channel_id`           INT NOT NULL,
    `last_read_message_id` INT NOT NULL DEFAULT 0,
    `updated_datetime`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_warroom_read` (`analyst_id`, `channel_id`),
    CONSTRAINT `fk_warroom_reads_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_warroom_reads_channel` FOREIGN KEY (`channel_id`) REFERENCES `warroom_channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who is currently in the war room. Knowing whether anyone is actually reading
-- is most of the value when your usual chat is down, so this is not a frill.
--
-- One row per analyst, upserted by the same poll that fetches messages: presence
-- costs no extra request, and the table cannot grow beyond the analyst count.
-- Shape mirrors `ticket_presence` (surrogate id + UNIQUE on the natural key) —
-- the pattern collision detection already established. Unlike messages, presence
-- is ephemeral, so an analyst delete CASCADEs it away.
CREATE TABLE IF NOT EXISTS `warroom_presence` (
    `id`         INT NOT NULL AUTO_INCREMENT,
    `analyst_id` INT NOT NULL,
    `channel_id` INT NULL,
    `last_seen`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_warroom_presence` (`analyst_id`),
    KEY `ix_warroom_presence_last_seen` (`last_seen`),
    CONSTRAINT `fk_warroom_presence_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_warroom_presence_channel` FOREIGN KEY (`channel_id`) REFERENCES `warroom_channels` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The recent trail (#124). Every record an analyst opens, in the order they
-- opened it, so the waffle drawer can draw an outline of how they got where they
-- are: a heading per run of records in one module, the records indented under it.
-- includes/recent_trail.php carries the reasoning; the two properties that are
-- easy to "correct" by mistake are these:
--
-- 🔴 NO UNIQUE KEY on (analyst_id, entity_type, entity_id), and that is the whole
--    design rather than an omission. This is a LOG. Opening a ticket at 09:00 and
--    again at 15:00 has to be two rows under two headings — deduplicating them
--    into one would delete the fact the outline exists to show. recentTrailPrune()
--    caps the table instead, by count and by age.
--
-- ⚠️ entity_type holds the ENTITY names from entityLinkTypes() — 'knowledge_article',
--    not the module key 'knowledge'. The module a row is filed under is derived at
--    render time, so moving a record type between modules is one edit in one PHP
--    file and never a data migration.
--
-- Must follow `analysts`: it points at it, and CASCADE is right because a trail is
-- one analyst's own history and means nothing once the account is gone.
CREATE TABLE IF NOT EXISTS `analyst_recent_trail` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `analyst_id`       INT NOT NULL,
    `entity_type`      VARCHAR(40) NOT NULL,
    `entity_id`        INT NOT NULL,
    `visited_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Every read and every prune is "this analyst, newest first". One index does
    -- both, and it is the only one the table needs.
    KEY `ix_analyst_recent_trail_analyst` (`analyst_id`, `visited_datetime`),
    CONSTRAINT `fk_analyst_recent_trail_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The all-hands room. Seeded rather than created on demand so a fresh install has
-- somewhere to talk before anybody has a team; warRoomEnsureChannels() creates it
-- too, for installations that predate this line.
INSERT INTO `warroom_channels` (`kind`, `created_datetime`)
SELECT 'all', UTC_TIMESTAMP() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `warroom_channels` WHERE `kind` = 'all');

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------
-- Seed: Default admin account
-- ----------------------------------------------------------
-- Username: admin  |  Password: freeitsm
-- The account is created with must_change_password = 1, so this password cannot be
-- kept: the first sign-in can go nowhere but the change-password screen.
--
-- ⚠️ must_change_password MUST be listed here explicitly. It defaults to 0, and
-- docker-compose.yml mounts this file as a /docker-entrypoint-initdb.d script — so on
-- the Docker quickstart THIS seed runs, not the one in api/system/db_verify.php, whose
-- `COUNT(*) === 0` test then finds the row already present and never fires. Leaving the
-- column out here left admin/freeitsm permanently valid on the flagship install path,
-- while README.md promised the opposite. Reported by Erlend Volden.
INSERT INTO `analysts` (`username`, `password_hash`, `full_name`, `email`, `is_active`, `is_admin`, `must_change_password`, `created_datetime`)
SELECT 'admin', '$2y$12$z9jzs9Sqol4i.ThVE/wwL.EzvbYtZrU0GHpzUJX7UC6ODp5h.q2U2', 'Administrator', 'admin@localhost', 1, 1, 1, UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `analysts` LIMIT 1);
