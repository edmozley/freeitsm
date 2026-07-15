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
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_analysts_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- SSO / OIDC identity providers (one row per configured IdP)
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `auth_providers` (
    `id`                     INT NOT NULL AUTO_INCREMENT,
    `display_name`           VARCHAR(100) NOT NULL,
    `protocol`               VARCHAR(20) NOT NULL DEFAULT 'oidc',
    `issuer_url`             VARCHAR(500) NOT NULL,
    `client_id`              VARCHAR(255) NOT NULL,
    `client_secret`          VARCHAR(500) NULL,
    `scopes`                 VARCHAR(255) NOT NULL DEFAULT 'openid email profile',
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
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analyst_teams` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `team_id`           INT NOT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
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
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The capabilities a role grants. capability_key is '<module>.<action>'.
CREATE TABLE IF NOT EXISTS `rbac_role_capabilities` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `role_id`           INT NOT NULL,
    `capability_key`    VARCHAR(100) NOT NULL,
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
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_prefixes` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `prefix`        VARCHAR(3) NOT NULL,
    `description`   VARCHAR(100) NULL,
    `department_id` INT NULL,
    `is_default`    TINYINT(1) NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_prefixes_prefix` (`prefix`),
    CONSTRAINT `fk_prefixes_departments` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id`              INT NOT NULL AUTO_INCREMENT,
    `email`           VARCHAR(255) NOT NULL,
    `display_name`    VARCHAR(255) NULL,
    `preferred_name`  VARCHAR(100) NULL,
    `password_hash`   VARCHAR(255) NULL,
    `totp_secret`     VARCHAR(500) NULL,
    `totp_enabled`    TINYINT(1) NOT NULL DEFAULT 0,
    `auth_provider_id` INT NULL,
    `created_at`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
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
    `deleted_datetime`      DATETIME NULL,
    `deleted_by`            INT NULL,
    -- Messaging channels (WhatsApp etc.): when the customer last messaged in. Drives
    -- the provider 24h service window — outside it, only template replies are allowed.
    `last_inbound_at`       DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tickets_number` (`ticket_number`),
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
    CONSTRAINT `fk_tickets_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_audit` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `ticket_id`         INT NOT NULL,
    `analyst_id`        INT NOT NULL,
    `field_name`        VARCHAR(100) NOT NULL,
    `old_value`         VARCHAR(500) NULL,
    `new_value`         VARCHAR(500) NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
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
    `created_datetime`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_checked_datetime` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `ix_target_mailboxes_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_target_mailboxes_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emails` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `exchange_message_id`   VARCHAR(255) NULL,
    `subject`               VARCHAR(500) NULL,
    `from_address`          VARCHAR(255) NOT NULL,
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
    -- Multi-tenancy: NULL = global default type (shared by every company); set =
    -- a type a company added for itself. Existing rows stay NULL, so a
    -- single-company install is unaffected. (Config meaning of tenant_id: NULL =
    -- global default — unlike scoped data tables where NULL means "unrouted".)
    `tenant_id`         INT NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
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
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_asset_locations_parent` (`parent_id`),
    CONSTRAINT `fk_asset_locations_parent` FOREIGN KEY (`parent_id`) REFERENCES `asset_locations` (`id`)
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
    PRIMARY KEY (`id`),
    KEY `idx_assets_location` (`location_id`),
    KEY `idx_assets_supplier` (`supplier_id`),
    KEY `idx_assets_tenant` (`tenant_id`),
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
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analyst_ticket_dashboard_widgets` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `analyst_id`        INT NOT NULL,
    `widget_id`         INT NOT NULL,
    `sort_order`        INT NOT NULL DEFAULT 0,
    `status_filter`     VARCHAR(50) NULL,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
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
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_calendar_events_category` FOREIGN KEY (`category_id`) REFERENCES `calendar_categories` (`id`),
    CONSTRAINT `fk_calendar_events_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Morning Checks
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `morningChecks_Checks` (
    `CheckID`           INT NOT NULL AUTO_INCREMENT,
    `CheckName`         VARCHAR(255) NOT NULL,
    `CheckDescription`  LONGTEXT NULL,
    `IsActive`          TINYINT(1) NOT NULL DEFAULT 1,
    `SortOrder`         INT NOT NULL DEFAULT 0,
    `CreatedDate`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ModifiedDate`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`CheckID`)
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
    `CreatedDate`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ModifiedDate`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ResultID`),
    UNIQUE KEY `uq_check_date` (`CheckID`, `CheckDate`),
    CONSTRAINT `fk_results_checks` FOREIGN KEY (`CheckID`) REFERENCES `morningChecks_Checks` (`CheckID`),
    CONSTRAINT `fk_results_status` FOREIGN KEY (`StatusID`) REFERENCES `morningChecks_Statuses` (`StatusID`) ON DELETE SET NULL
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
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_knowledge_articles_author` FOREIGN KEY (`author_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_knowledge_articles_owner` FOREIGN KEY (`owner_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_knowledge_articles_archived_by` FOREIGN KEY (`archived_by_id`) REFERENCES `analysts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_knowledge_tags_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `knowledge_article_tags` (
    `article_id`    INT NOT NULL,
    `tag_id`        INT NOT NULL,
    PRIMARY KEY (`article_id`, `tag_id`),
    CONSTRAINT `fk_article_tags_article` FOREIGN KEY (`article_id`) REFERENCES `knowledge_articles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_article_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `knowledge_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- Software Inventory & Licences
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `software_inventory_apps` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `display_name`      VARCHAR(512) NOT NULL,
    `publisher`         VARCHAR(512) NULL,
    `first_detected`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
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
    `board_position`      INT NOT NULL DEFAULT 0,
    `created_by_id`       INT NOT NULL,
    `created_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_datetime`  DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `ix_tasks_status_id` (`status_id`),
    KEY `ix_tasks_priority_id` (`priority_id`),
    CONSTRAINT `fk_tasks_analyst` FOREIGN KEY (`assigned_analyst_id`) REFERENCES `analysts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_team` FOREIGN KEY (`assigned_team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_parent` FOREIGN KEY (`parent_task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tasks_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_change` FOREIGN KEY (`change_id`) REFERENCES `changes` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_created_by` FOREIGN KEY (`created_by_id`) REFERENCES `analysts` (`id`),
    CONSTRAINT `fk_tasks_status` FOREIGN KEY (`status_id`) REFERENCES `task_statuses` (`id`),
    CONSTRAINT `fk_tasks_priority` FOREIGN KEY (`priority_id`) REFERENCES `task_priorities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_comments` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `task_id`           INT NOT NULL,
    `analyst_id`        INT NOT NULL,
    `comment`           LONGTEXT NOT NULL,
    `created_datetime`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_task_comments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_task_comments_analyst` FOREIGN KEY (`analyst_id`) REFERENCES `analysts` (`id`)
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
    PRIMARY KEY (`id`),
    -- RESTRICT (no delete rule): a frozen version can't be deleted while
    -- newer versions chain off it — delete leaf-first (or the whole chain).
    CONSTRAINT `fk_forms_parent` FOREIGN KEY (`parent_form_id`) REFERENCES `forms` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_fields` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `form_id`       INT NOT NULL,
    `field_type`    VARCHAR(50) NOT NULL,
    `label`         VARCHAR(255) NOT NULL,
    `options`       LONGTEXT NULL,
    `is_required`   TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_form_fields_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_submissions` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `form_id`           INT NOT NULL,
    `submitted_by`      INT NULL,
    `submitted_date`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_form_submissions_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_submission_data` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `submission_id` INT NOT NULL,
    `field_id`      INT NOT NULL,
    `field_value`   LONGTEXT NULL,
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
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_statuses` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
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
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_schedules` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contract_term_tabs` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `display_order`     INT NOT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
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
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lms_learning_group_members` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `group_id`              INT NOT NULL,
    `analyst_id`            INT NOT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
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
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_service_impact_levels_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `service_impact_levels` (`name`, `colour`, `is_default`, `severity_order`, `display_order`) VALUES
    ('Major Outage',   '#dc2626', 0, 1, 10),
    ('Partial Outage', '#f59e0b', 0, 2, 20),
    ('Degraded',       '#eab308', 0, 3, 30),
    ('Maintenance',    '#0891b2', 0, 4, 40),
    ('Operational',    '#16a34a', 1, 5, 50),
    ('No Disruption',  '#9ca3af', 0, 6, 60);

CREATE TABLE IF NOT EXISTS `status_incidents` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `title`                 VARCHAR(255) NOT NULL,
    `status_id`             INT NULL,
    `comment`               LONGTEXT NULL,
    `created_by_id`         INT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_datetime`     DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `ix_status_incidents_status_id` (`status_id`),
    CONSTRAINT `fk_status_incidents_status` FOREIGN KEY (`status_id`) REFERENCES `service_incident_statuses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `status_incident_services` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `incident_id`       INT NOT NULL,
    `service_id`        INT NOT NULL,
    `impact_level_id`   INT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_sis_impact_level_id` (`impact_level_id`),
    CONSTRAINT `fk_sis_impact_level` FOREIGN KEY (`impact_level_id`) REFERENCES `service_impact_levels` (`id`)
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
    `display_order`     INT NULL DEFAULT 0,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
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
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_cmdb_objects_class_id` (`class_id`),
    KEY `ix_cmdb_objects_parent_id` (`parent_id`),
    KEY `ix_cmdb_objects_name` (`name`),
    KEY `ix_cmdb_objects_is_planned` (`is_planned`),
    CONSTRAINT `fk_cmdb_objects_class` FOREIGN KEY (`class_id`) REFERENCES `cmdb_classes` (`id`),
    CONSTRAINT `fk_cmdb_objects_parent` FOREIGN KEY (`parent_id`) REFERENCES `cmdb_objects` (`id`) ON DELETE CASCADE
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
    `display_order`     INT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NULL DEFAULT 1,
    `created_datetime`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cmdb_rel_type_verb` (`verb`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cmdb_object_relationships` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `from_object_id`        INT NOT NULL,
    `to_object_id`          INT NOT NULL,
    `relationship_type_id`  INT NOT NULL,
    `created_datetime`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
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
    PRIMARY KEY (`id`),
    KEY `ix_net_conn_diag` (`diagram_id`),
    KEY `ix_net_conn_from` (`from_node_id`),
    KEY `ix_net_conn_to` (`to_node_id`),
    CONSTRAINT `fk_net_conn_diag` FOREIGN KEY (`diagram_id`) REFERENCES `network_diagrams` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_net_conn_from` FOREIGN KEY (`from_node_id`) REFERENCES `network_diagram_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_net_conn_to`   FOREIGN KEY (`to_node_id`)   REFERENCES `network_diagram_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_net_conn_rel`  FOREIGN KEY (`cmdb_relationship_id`) REFERENCES `cmdb_object_relationships` (`id`) ON DELETE SET NULL
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
INSERT INTO `cmdb_relationship_types` (`verb`, `inverse_verb`, `description`, `display_order`)
SELECT * FROM (SELECT 'depends on'  AS verb, 'is depended on by' AS inverse_verb, 'A needs B in order to function'  AS description, 10 AS display_order) AS t
WHERE NOT EXISTS (SELECT 1 FROM `cmdb_relationship_types` WHERE verb = 'depends on');
INSERT INTO `cmdb_relationship_types` (`verb`, `inverse_verb`, `description`, `display_order`)
SELECT * FROM (SELECT 'connects to' AS verb, 'is connected from' AS inverse_verb, 'A has a network or data link to B' AS description, 20 AS display_order) AS t
WHERE NOT EXISTS (SELECT 1 FROM `cmdb_relationship_types` WHERE verb = 'connects to');
INSERT INTO `cmdb_relationship_types` (`verb`, `inverse_verb`, `description`, `display_order`)
SELECT * FROM (SELECT 'managed by'  AS verb, 'manages'           AS inverse_verb, 'A is administered by B'           AS description, 30 AS display_order) AS t
WHERE NOT EXISTS (SELECT 1 FROM `cmdb_relationship_types` WHERE verb = 'managed by');

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------
-- Seed: Default admin account
-- ----------------------------------------------------------
-- Username: admin  |  Password: freeitsm
-- IMPORTANT: Change this password after first login!
INSERT INTO `analysts` (`username`, `password_hash`, `full_name`, `email`, `is_active`, `is_admin`, `created_datetime`)
SELECT 'admin', '$2y$12$z9jzs9Sqol4i.ThVE/wwL.EzvbYtZrU0GHpzUJX7UC6ODp5h.q2U2', 'Administrator', 'admin@localhost', 1, 1, UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `analysts` LIMIT 1);
