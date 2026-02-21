<?php
/**
 * API Endpoint: Database Verification
 * Checks all tables and columns exist, creates any that are missing.
 */
session_start();
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

    'analyst_modules' => [
        'id'          => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'  => 'INT NOT NULL',
        'module_key'  => 'VARCHAR(50) NOT NULL',
    ],

    'ticket_types' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'is_active'         => 'TINYINT(1) NULL DEFAULT 1',
        'display_order'     => 'INT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_origins' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(255) NULL',
        'display_order'     => 'INT NULL DEFAULT 0',
        'is_active'         => 'TINYINT(1) NULL DEFAULT 1',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_prefixes' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'prefix'        => 'VARCHAR(3) NOT NULL',
        'description'   => 'VARCHAR(100) NULL',
        'department_id' => 'INT NULL',
        'is_default'    => 'TINYINT(1) NULL DEFAULT 0',
    ],

    'users' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'email'         => 'VARCHAR(255) NOT NULL',
        'display_name'  => 'VARCHAR(255) NULL',
        'created_at'    => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'tickets' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'ticket_number'         => 'VARCHAR(50) NOT NULL',
        'subject'               => 'VARCHAR(500) NOT NULL',
        'status'                => 'VARCHAR(50) NULL DEFAULT \'Open\'',
        'priority'              => 'VARCHAR(50) NULL DEFAULT \'Normal\'',
        'department_id'         => 'INT NULL',
        'ticket_type_id'        => 'INT NULL',
        'assigned_analyst_id'   => 'INT NULL',
        'requester_email'       => 'VARCHAR(255) NULL',
        'requester_name'        => 'VARCHAR(255) NULL',
        'created_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_datetime'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'closed_datetime'       => 'DATETIME NULL',
        'origin_id'             => 'INT NULL',
        'first_time_fix'        => 'TINYINT(1) NULL',
        'it_training_provided'  => 'TINYINT(1) NULL',
        'user_id'               => 'INT NULL',
        'owner_id'              => 'INT NULL',
        'work_start_datetime'   => 'DATETIME NULL',
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

    'target_mailboxes' => [
        'id'                      => 'INT NOT NULL AUTO_INCREMENT',
        'name'                    => 'VARCHAR(100) NOT NULL',
        'azure_tenant_id'         => 'VARCHAR(100) NOT NULL',
        'azure_client_id'         => 'VARCHAR(100) NOT NULL',
        'azure_client_secret'     => 'VARCHAR(255) NOT NULL',
        'oauth_redirect_uri'      => 'VARCHAR(500) NOT NULL',
        'oauth_scopes'            => 'VARCHAR(500) NOT NULL DEFAULT \'openid email offline_access Mail.Read Mail.ReadWrite Mail.Send\'',
        'imap_server'             => 'VARCHAR(255) NOT NULL DEFAULT \'outlook.office365.com\'',
        'imap_port'               => 'INT NOT NULL DEFAULT 993',
        'imap_encryption'         => 'VARCHAR(10) NOT NULL DEFAULT \'ssl\'',
        'target_mailbox'          => 'VARCHAR(255) NOT NULL',
        'token_data'              => 'LONGTEXT NULL',
        'email_folder'            => 'VARCHAR(100) NOT NULL DEFAULT \'INBOX\'',
        'max_emails_per_check'    => 'INT NOT NULL DEFAULT 10',
        'mark_as_read'            => 'TINYINT(1) NOT NULL DEFAULT 0',
        'rejected_action'         => 'VARCHAR(20) NOT NULL DEFAULT \'delete\'',
        'imported_action'         => 'VARCHAR(20) NOT NULL DEFAULT \'delete\'',
        'imported_folder'         => 'VARCHAR(100) NULL',
        'is_active'               => 'TINYINT(1) NOT NULL DEFAULT 1',
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

    'ticket_rota_shifts' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'start_time'        => 'TIME NOT NULL',
        'end_time'          => 'TIME NOT NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'ticket_rota_entries' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'analyst_id'        => 'INT NOT NULL',
        'rota_date'         => 'DATE NOT NULL',
        'shift_id'          => 'INT NOT NULL',
        'location'          => "VARCHAR(20) NOT NULL DEFAULT 'office'",
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
        'domain'            => 'VARCHAR(100) NULL',
        'logged_in_user'    => 'VARCHAR(100) NULL',
        'last_boot_utc'     => 'DATETIME NULL',
        'tpm_version'       => 'VARCHAR(50) NULL',
        'bitlocker_status'  => 'VARCHAR(20) NULL',
        'gpu_name'          => 'VARCHAR(250) NULL',
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

    'users_assets' => [
        'id'                        => 'INT NOT NULL AUTO_INCREMENT',
        'user_id'                   => 'INT NOT NULL',
        'asset_id'                  => 'INT NOT NULL',
        'assigned_datetime'         => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'assigned_by_analyst_id'    => 'INT NULL',
        'notes'                     => 'VARCHAR(500) NULL',
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

    'changes' => [
        'id'                            => 'INT NOT NULL AUTO_INCREMENT',
        'title'                         => 'VARCHAR(255) NOT NULL',
        'change_type'                   => 'VARCHAR(20) NOT NULL DEFAULT \'Normal\'',
        'status'                        => 'VARCHAR(30) NOT NULL DEFAULT \'Draft\'',
        'priority'                      => 'VARCHAR(20) NOT NULL DEFAULT \'Medium\'',
        'impact'                        => 'VARCHAR(20) NOT NULL DEFAULT \'Medium\'',
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
        'change_type'               => 'VARCHAR(20) NULL DEFAULT \'Normal\'',
        'priority'                  => 'VARCHAR(20) NULL DEFAULT \'Medium\'',
        'impact'                    => 'VARCHAR(20) NULL DEFAULT \'Medium\'',
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
        'created_by'        => 'INT NOT NULL',
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
        'Status'        => 'VARCHAR(10) NOT NULL',
        'Notes'         => 'LONGTEXT NULL',
        'CreatedBy'     => 'VARCHAR(100) NULL',
        'CreatedDate'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'ModifiedDate'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
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

    'apikeys' => [
        'id'        => 'INT NOT NULL AUTO_INCREMENT',
        'apikey'    => 'VARCHAR(50) NULL',
        'datestamp' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
        'active'    => 'TINYINT(1) NULL DEFAULT 1',
    ],

    'forms' => [
        'id'            => 'INT NOT NULL AUTO_INCREMENT',
        'title'         => 'VARCHAR(255) NOT NULL',
        'description'   => 'LONGTEXT NULL',
        'is_active'     => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_by'    => 'INT NULL',
        'created_date'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'modified_date' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
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

    // Service Status module
    'status_services' => [
        'id'                => 'INT NOT NULL AUTO_INCREMENT',
        'name'              => 'VARCHAR(100) NOT NULL',
        'description'       => 'VARCHAR(500) NULL',
        'is_active'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'display_order'     => 'INT NOT NULL DEFAULT 0',
        'created_datetime'  => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ],

    'status_incidents' => [
        'id'                    => 'INT NOT NULL AUTO_INCREMENT',
        'title'                 => 'VARCHAR(255) NOT NULL',
        'status'                => 'VARCHAR(30) NOT NULL DEFAULT \'Investigating\'',
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
        'impact_level'      => 'VARCHAR(30) NOT NULL DEFAULT \'Operational\'',
    ],
];

// Primary key definitions: table => pk_column (defaults to 'id')
$primaryKeys = [
    'system_settings'           => 'setting_key',
    'morningChecks_Checks'      => 'CheckID',
    'morningChecks_Results'     => 'ResultID',
    'knowledge_article_tags'    => null, // composite PK: article_id, tag_id
];

try {
    $conn = connectToDatabase();
    $results = [];
    $dbName = DB_NAME;

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

    // Seed default admin account if no analysts exist
    $countStmt = $conn->query("SELECT COUNT(*) FROM analysts");
    $analystCount = (int) $countStmt->fetchColumn();
    if ($analystCount === 0) {
        $defaultHash = password_hash('freeitsm', PASSWORD_DEFAULT);
        $seedStmt = $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, created_datetime) VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP())");
        $seedStmt->execute(['admin', $defaultHash, 'Administrator', 'admin@localhost']);
        $results[] = [
            'table' => 'analysts',
            'status' => 'seeded',
            'details' => ['Created default admin account (username: admin, password: freeitsm)']
        ];
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

    echo json_encode([
        'success' => true,
        'results' => $results,
        'total_tables' => count($schema)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
