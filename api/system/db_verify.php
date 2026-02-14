<?php
/**
 * API Endpoint: Database Verification
 * Checks all tables and columns exist, creates any that are missing.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

/**
 * Complete database schema definition.
 * Each table maps to an array of columns.
 * Column format: 'column_name' => 'TYPE [NOT] NULL [DEFAULT ...]'
 * The first column with 'IDENTITY' is the primary key.
 */
$schema = [

    'analysts' => [
        'id'                     => 'int IDENTITY(1,1) NOT NULL',
        'username'               => 'nvarchar(50) NOT NULL',
        'password_hash'          => 'nvarchar(255) NOT NULL',
        'full_name'              => 'nvarchar(100) NOT NULL',
        'email'                  => 'nvarchar(100) NOT NULL',
        'is_active'              => 'bit NULL DEFAULT 1',
        'created_datetime'       => 'datetime NULL DEFAULT GETDATE()',
        'last_login_datetime'    => 'datetime NULL',
        'last_modified_datetime' => 'datetime NULL',
        'totp_secret'            => 'nvarchar(500) NULL',
        'totp_enabled'           => 'bit NOT NULL DEFAULT 0',
    ],

    'departments' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'name'              => 'nvarchar(100) NOT NULL',
        'description'       => 'nvarchar(255) NULL',
        'is_active'         => 'bit NULL DEFAULT 1',
        'display_order'     => 'int NULL DEFAULT 0',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'teams' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'name'              => 'nvarchar(100) NOT NULL',
        'description'       => 'nvarchar(500) NULL',
        'display_order'     => 'int NULL DEFAULT 0',
        'is_active'         => 'bit NULL DEFAULT 1',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
        'updated_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'analyst_teams' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'analyst_id'        => 'int NOT NULL',
        'team_id'           => 'int NOT NULL',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'department_teams' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'department_id'     => 'int NOT NULL',
        'team_id'           => 'int NOT NULL',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'analyst_modules' => [
        'id'          => 'int IDENTITY(1,1) NOT NULL',
        'analyst_id'  => 'int NOT NULL',
        'module_key'  => 'nvarchar(50) NOT NULL',
    ],

    'ticket_types' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'name'              => 'nvarchar(100) NOT NULL',
        'description'       => 'nvarchar(255) NULL',
        'is_active'         => 'bit NULL DEFAULT 1',
        'display_order'     => 'int NULL DEFAULT 0',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'ticket_origins' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'name'              => 'nvarchar(100) NOT NULL',
        'description'       => 'nvarchar(255) NULL',
        'display_order'     => 'int NULL DEFAULT 0',
        'is_active'         => 'bit NULL DEFAULT 1',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'ticket_prefixes' => [
        'id'            => 'int IDENTITY(1,1) NOT NULL',
        'prefix'        => 'nvarchar(3) NOT NULL',
        'description'   => 'nvarchar(100) NULL',
        'department_id' => 'int NULL',
        'is_default'    => 'bit NULL DEFAULT 0',
    ],

    'users' => [
        'id'            => 'int IDENTITY(1,1) NOT NULL',
        'email'         => 'nvarchar(255) NOT NULL',
        'display_name'  => 'nvarchar(255) NULL',
        'created_at'    => 'datetime NULL DEFAULT GETDATE()',
    ],

    'tickets' => [
        'id'                    => 'int IDENTITY(1,1) NOT NULL',
        'ticket_number'         => 'nvarchar(50) NOT NULL',
        'subject'               => 'nvarchar(500) NOT NULL',
        'status'                => 'nvarchar(50) NULL DEFAULT \'Open\'',
        'priority'              => 'nvarchar(50) NULL DEFAULT \'Normal\'',
        'department_id'         => 'int NULL',
        'ticket_type_id'        => 'int NULL',
        'assigned_analyst_id'   => 'int NULL',
        'requester_email'       => 'nvarchar(255) NULL',
        'requester_name'        => 'nvarchar(255) NULL',
        'created_datetime'      => 'datetime NULL DEFAULT GETDATE()',
        'updated_datetime'      => 'datetime NULL DEFAULT GETDATE()',
        'closed_datetime'       => 'datetime NULL',
        'origin_id'             => 'int NULL',
        'first_time_fix'        => 'bit NULL',
        'it_training_provided'  => 'bit NULL',
        'user_id'               => 'int NULL',
        'owner_id'              => 'int NULL',
        'work_start_datetime'   => 'datetime NULL',
    ],

    'ticket_audit' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'ticket_id'         => 'int NOT NULL',
        'analyst_id'        => 'int NOT NULL',
        'field_name'        => 'nvarchar(100) NOT NULL',
        'old_value'         => 'nvarchar(500) NULL',
        'new_value'         => 'nvarchar(500) NULL',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'ticket_notes' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'ticket_id'         => 'int NOT NULL',
        'analyst_id'        => 'int NOT NULL',
        'note_text'         => 'nvarchar(max) NOT NULL',
        'is_internal'       => 'bit NULL DEFAULT 1',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'target_mailboxes' => [
        'id'                      => 'int IDENTITY(1,1) NOT NULL',
        'name'                    => 'nvarchar(100) NOT NULL',
        'azure_tenant_id'         => 'nvarchar(100) NOT NULL',
        'azure_client_id'         => 'nvarchar(100) NOT NULL',
        'azure_client_secret'     => 'nvarchar(255) NOT NULL',
        'oauth_redirect_uri'      => 'nvarchar(500) NOT NULL',
        'oauth_scopes'            => 'nvarchar(500) NOT NULL DEFAULT \'openid email offline_access Mail.Read Mail.ReadWrite Mail.Send\'',
        'imap_server'             => 'nvarchar(255) NOT NULL DEFAULT \'outlook.office365.com\'',
        'imap_port'               => 'int NOT NULL DEFAULT 993',
        'imap_encryption'         => 'nvarchar(10) NOT NULL DEFAULT \'ssl\'',
        'target_mailbox'          => 'nvarchar(255) NOT NULL',
        'token_data'              => 'nvarchar(max) NULL',
        'email_folder'            => 'nvarchar(100) NOT NULL DEFAULT \'INBOX\'',
        'max_emails_per_check'    => 'int NOT NULL DEFAULT 10',
        'mark_as_read'            => 'bit NOT NULL DEFAULT 0',
        'is_active'               => 'bit NOT NULL DEFAULT 1',
        'created_datetime'        => 'datetime NOT NULL DEFAULT GETDATE()',
        'last_checked_datetime'   => 'datetime NULL',
    ],

    'emails' => [
        'id'                      => 'int IDENTITY(1,1) NOT NULL',
        'exchange_message_id'     => 'nvarchar(255) NULL',
        'subject'                 => 'nvarchar(500) NULL',
        'from_address'            => 'nvarchar(255) NOT NULL',
        'from_name'               => 'nvarchar(255) NULL',
        'to_recipients'           => 'nvarchar(max) NULL',
        'cc_recipients'           => 'nvarchar(max) NULL',
        'received_datetime'       => 'datetime NULL',
        'body_preview'            => 'nvarchar(max) NULL',
        'body_content'            => 'nvarchar(max) NULL',
        'body_type'               => 'nvarchar(20) NULL',
        'has_attachments'         => 'bit NULL DEFAULT 0',
        'importance'              => 'nvarchar(20) NULL',
        'is_read'                 => 'bit NULL DEFAULT 0',
        'processed_datetime'      => 'datetime NULL DEFAULT GETDATE()',
        'ticket_created'          => 'bit NULL DEFAULT 0',
        'ticket_id'               => 'int NULL',
        'department_id'           => 'int NULL',
        'ticket_type_id'          => 'int NULL',
        'assigned_analyst_id'     => 'int NULL',
        'status'                  => 'nvarchar(50) NULL DEFAULT \'New\'',
        'assigned_datetime'       => 'datetime NULL',
        'is_initial'              => 'bit NULL DEFAULT 0',
        'direction'               => 'nvarchar(20) NULL DEFAULT \'Inbound\'',
        'mailbox_id'              => 'int NULL',
    ],

    'email_attachments' => [
        'id'                        => 'int IDENTITY(1,1) NOT NULL',
        'email_id'                  => 'int NOT NULL',
        'exchange_attachment_id'    => 'nvarchar(255) NULL',
        'filename'                  => 'nvarchar(255) NOT NULL',
        'content_type'              => 'nvarchar(100) NOT NULL',
        'content_id'                => 'nvarchar(255) NULL',
        'file_path'                 => 'nvarchar(500) NOT NULL',
        'file_size'                 => 'int NOT NULL',
        'is_inline'                 => 'bit NOT NULL DEFAULT 0',
        'created_datetime'          => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'assets' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'hostname'          => 'nvarchar(20) NULL',
        'manufacturer'      => 'nvarchar(50) NULL',
        'model'             => 'nvarchar(50) NULL',
        'memory'            => 'numeric(18,0) NULL',
        'service_tag'       => 'nvarchar(20) NULL',
        'operating_system'  => 'nvarchar(50) NULL',
        'feature_release'   => 'nvarchar(10) NULL',
        'build_number'      => 'nvarchar(50) NULL',
        'cpu_name'          => 'nvarchar(250) NULL',
        'speed'             => 'numeric(18,0) NULL',
        'bios_version'      => 'nvarchar(20) NULL',
        'first_seen'        => 'datetime NULL',
        'last_seen'         => 'datetime NULL',
        'asset_type_id'     => 'int NULL',
        'asset_status_id'   => 'int NULL',
    ],

    'asset_types' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'name'              => 'nvarchar(100) NOT NULL',
        'description'       => 'nvarchar(255) NULL',
        'is_active'         => 'bit NOT NULL DEFAULT 1',
        'display_order'     => 'int NOT NULL DEFAULT 0',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'asset_status_types' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'name'              => 'nvarchar(100) NOT NULL',
        'description'       => 'nvarchar(255) NULL',
        'is_active'         => 'bit NOT NULL DEFAULT 1',
        'display_order'     => 'int NOT NULL DEFAULT 0',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'users_assets' => [
        'id'                        => 'int IDENTITY(1,1) NOT NULL',
        'user_id'                   => 'int NOT NULL',
        'asset_id'                  => 'int NOT NULL',
        'assigned_datetime'         => 'datetime NULL DEFAULT GETDATE()',
        'assigned_by_analyst_id'    => 'int NULL',
        'notes'                     => 'nvarchar(500) NULL',
    ],

    'servers' => [
        'id'                    => 'int IDENTITY(1,1) NOT NULL',
        'vm_id'                 => 'nvarchar(100) NOT NULL',
        'name'                  => 'nvarchar(255) NULL',
        'power_state'           => 'nvarchar(20) NULL',
        'memory_gb'             => 'decimal(10,2) NULL',
        'num_cpu'               => 'int NULL',
        'ip_address'            => 'nvarchar(50) NULL',
        'hard_disk_size_gb'     => 'decimal(10,2) NULL',
        'host'                  => 'nvarchar(255) NULL',
        'cluster'               => 'nvarchar(255) NULL',
        'guest_os'              => 'nvarchar(255) NULL',
        'last_synced'           => 'datetime NULL',
        'raw_data'              => 'varchar(max) NULL',
    ],

    'changes' => [
        'id'                            => 'int IDENTITY(1,1) NOT NULL',
        'title'                         => 'nvarchar(255) NOT NULL',
        'change_type'                   => 'nvarchar(20) NOT NULL DEFAULT \'Normal\'',
        'status'                        => 'nvarchar(30) NOT NULL DEFAULT \'Draft\'',
        'priority'                      => 'nvarchar(20) NOT NULL DEFAULT \'Medium\'',
        'impact'                        => 'nvarchar(20) NOT NULL DEFAULT \'Medium\'',
        'category'                      => 'nvarchar(100) NULL',
        'requester_id'                  => 'int NULL',
        'assigned_to_id'                => 'int NULL',
        'approver_id'                   => 'int NULL',
        'approval_datetime'             => 'datetime NULL',
        'work_start_datetime'           => 'datetime NULL',
        'work_end_datetime'             => 'datetime NULL',
        'outage_start_datetime'         => 'datetime NULL',
        'outage_end_datetime'           => 'datetime NULL',
        'description'                   => 'nvarchar(max) NULL',
        'reason_for_change'             => 'nvarchar(max) NULL',
        'risk_evaluation'               => 'nvarchar(max) NULL',
        'test_plan'                     => 'nvarchar(max) NULL',
        'rollback_plan'                 => 'nvarchar(max) NULL',
        'post_implementation_review'    => 'nvarchar(max) NULL',
        'created_by_id'                 => 'int NULL',
        'created_datetime'              => 'datetime NOT NULL DEFAULT GETDATE()',
        'modified_datetime'             => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'change_attachments' => [
        'id'                    => 'int IDENTITY(1,1) NOT NULL',
        'change_id'             => 'int NOT NULL',
        'file_name'             => 'nvarchar(255) NOT NULL',
        'file_path'             => 'nvarchar(500) NOT NULL',
        'file_size'             => 'int NULL',
        'file_type'             => 'nvarchar(100) NULL',
        'uploaded_by_id'        => 'int NULL',
        'uploaded_datetime'     => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'calendar_categories' => [
        'id'            => 'int IDENTITY(1,1) NOT NULL',
        'name'          => 'nvarchar(100) NOT NULL',
        'color'         => 'nvarchar(7) NOT NULL DEFAULT \'#ef6c00\'',
        'description'   => 'nvarchar(500) NULL',
        'is_active'     => 'bit NOT NULL DEFAULT 1',
        'created_at'    => 'datetime NOT NULL DEFAULT GETDATE()',
        'updated_at'    => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'calendar_events' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'title'             => 'nvarchar(255) NOT NULL',
        'description'       => 'nvarchar(max) NULL',
        'category_id'       => 'int NULL',
        'start_datetime'    => 'datetime NOT NULL',
        'end_datetime'      => 'datetime NULL',
        'all_day'           => 'bit NOT NULL DEFAULT 0',
        'location'          => 'nvarchar(255) NULL',
        'created_by'        => 'int NOT NULL',
        'created_at'        => 'datetime NOT NULL DEFAULT GETDATE()',
        'updated_at'        => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'morningChecks_Checks' => [
        'CheckID'           => 'int IDENTITY(1,1) NOT NULL',
        'CheckName'         => 'nvarchar(255) NOT NULL',
        'CheckDescription'  => 'nvarchar(max) NULL',
        'IsActive'          => 'bit NOT NULL DEFAULT 1',
        'SortOrder'         => 'int NOT NULL DEFAULT 0',
        'CreatedDate'       => 'datetime NOT NULL DEFAULT GETDATE()',
        'ModifiedDate'      => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'morningChecks_Results' => [
        'ResultID'      => 'int IDENTITY(1,1) NOT NULL',
        'CheckID'       => 'int NOT NULL',
        'CheckDate'     => 'datetime NOT NULL',
        'Status'        => 'nvarchar(10) NOT NULL',
        'Notes'         => 'nvarchar(max) NULL',
        'CreatedBy'     => 'nvarchar(100) NULL',
        'CreatedDate'   => 'datetime NOT NULL DEFAULT GETDATE()',
        'ModifiedDate'  => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'system_logs' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'log_type'          => 'nvarchar(50) NOT NULL',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
        'analyst_id'        => 'int NULL',
        'details'           => 'nvarchar(max) NOT NULL',
    ],

    'system_settings' => [
        'setting_key'       => 'nvarchar(100) NOT NULL',
        'setting_value'     => 'nvarchar(max) NULL',
        'updated_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'knowledge_articles' => [
        'id'                    => 'int IDENTITY(1,1) NOT NULL',
        'title'                 => 'nvarchar(255) NOT NULL',
        'body'                  => 'nvarchar(max) NULL',
        'author_id'             => 'int NOT NULL',
        'created_datetime'      => 'datetime NULL DEFAULT GETDATE()',
        'modified_datetime'     => 'datetime NULL DEFAULT GETDATE()',
        'is_published'          => 'bit NULL DEFAULT 1',
        'view_count'            => 'int NULL DEFAULT 0',
        'next_review_date'      => 'date NULL',
        'owner_id'              => 'int NULL',
        'embedding'             => 'nvarchar(max) NULL',
        'embedding_updated'     => 'datetime NULL',
    ],

    'knowledge_tags' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'name'              => 'nvarchar(50) NOT NULL',
        'created_datetime'  => 'datetime NULL DEFAULT GETDATE()',
    ],

    'knowledge_article_tags' => [
        'article_id'    => 'int NOT NULL',
        'tag_id'        => 'int NOT NULL',
    ],

    'software_inventory_apps' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'display_name'      => 'nvarchar(512) NOT NULL',
        'publisher'         => 'nvarchar(512) NULL',
        'first_detected'    => 'datetime NULL DEFAULT GETDATE()',
    ],

    'software_inventory_detail' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'host_id'           => 'int NOT NULL',
        'app_id'            => 'int NOT NULL',
        'display_version'   => 'nvarchar(100) NULL',
        'install_date'      => 'nvarchar(50) NULL',
        'uninstall_string'  => 'nvarchar(max) NULL',
        'install_location'  => 'nvarchar(max) NULL',
        'estimated_size'    => 'nvarchar(100) NULL',
        'created_at'        => 'datetime NOT NULL DEFAULT GETDATE()',
        'last_seen'         => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'software_licences' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'app_id'            => 'int NOT NULL',
        'licence_type'      => 'nvarchar(50) NOT NULL',
        'licence_key'       => 'nvarchar(500) NULL',
        'quantity'          => 'int NULL',
        'renewal_date'      => 'date NULL',
        'notice_period_days'=> 'int NULL',
        'portal_url'        => 'nvarchar(500) NULL',
        'cost'              => 'decimal(10,2) NULL',
        'currency'          => 'nvarchar(10) NULL DEFAULT \'GBP\'',
        'purchase_date'     => 'date NULL',
        'vendor_contact'    => 'nvarchar(500) NULL',
        'notes'             => 'nvarchar(max) NULL',
        'status'            => 'nvarchar(20) NOT NULL DEFAULT \'Active\'',
        'created_by'        => 'int NULL',
        'created_at'        => 'datetime NOT NULL DEFAULT GETDATE()',
        'updated_at'        => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'apikeys' => [
        'id'        => 'int IDENTITY(1,1) NOT NULL',
        'apikey'    => 'nvarchar(50) NULL',
        'datestamp' => 'datetime NULL DEFAULT GETDATE()',
        'active'    => 'bit NULL DEFAULT 1',
    ],

    'forms' => [
        'id'            => 'int IDENTITY(1,1) NOT NULL',
        'title'         => 'nvarchar(255) NOT NULL',
        'description'   => 'nvarchar(max) NULL',
        'is_active'     => 'bit NOT NULL DEFAULT 1',
        'created_by'    => 'int NULL',
        'created_date'  => 'datetime NOT NULL DEFAULT GETDATE()',
        'modified_date' => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'form_fields' => [
        'id'            => 'int IDENTITY(1,1) NOT NULL',
        'form_id'       => 'int NOT NULL',
        'field_type'    => 'nvarchar(50) NOT NULL',
        'label'         => 'nvarchar(255) NOT NULL',
        'options'       => 'nvarchar(max) NULL',
        'is_required'   => 'bit NOT NULL DEFAULT 0',
        'sort_order'    => 'int NOT NULL DEFAULT 0',
    ],

    'form_submissions' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'form_id'           => 'int NOT NULL',
        'submitted_by'      => 'int NULL',
        'submitted_date'    => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'form_submission_data' => [
        'id'            => 'int IDENTITY(1,1) NOT NULL',
        'submission_id' => 'int NOT NULL',
        'field_id'      => 'int NOT NULL',
        'field_value'   => 'nvarchar(max) NULL',
    ],

    'wiki_scan_runs' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'started_at'        => 'datetime NOT NULL DEFAULT GETDATE()',
        'completed_at'      => 'datetime NULL',
        'status'            => 'nvarchar(20) NOT NULL DEFAULT \'running\'',
        'files_scanned'     => 'int NOT NULL DEFAULT 0',
        'functions_found'   => 'int NOT NULL DEFAULT 0',
        'classes_found'     => 'int NOT NULL DEFAULT 0',
        'error_message'     => 'nvarchar(max) NULL',
        'scanned_by'        => 'nvarchar(100) NULL',
    ],

    'wiki_files' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'scan_id'           => 'int NOT NULL',
        'file_path'         => 'nvarchar(500) NOT NULL',
        'file_name'         => 'nvarchar(255) NOT NULL',
        'folder_path'       => 'nvarchar(500) NOT NULL',
        'file_type'         => 'nvarchar(10) NOT NULL',
        'file_size_bytes'   => 'bigint NOT NULL DEFAULT 0',
        'line_count'        => 'int NOT NULL DEFAULT 0',
        'last_modified'     => 'datetime NULL',
        'description'       => 'nvarchar(max) NULL',
        'created_date'      => 'datetime NOT NULL DEFAULT GETDATE()',
    ],

    'wiki_functions' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'file_id'           => 'int NOT NULL',
        'function_name'     => 'nvarchar(255) NOT NULL',
        'line_number'       => 'int NOT NULL',
        'end_line_number'   => 'int NULL',
        'parameters'        => 'nvarchar(max) NULL',
        'class_name'        => 'nvarchar(255) NULL',
        'visibility'        => 'nvarchar(20) NULL',
        'is_static'         => 'bit NOT NULL DEFAULT 0',
        'description'       => 'nvarchar(max) NULL',
    ],

    'wiki_classes' => [
        'id'                        => 'int IDENTITY(1,1) NOT NULL',
        'file_id'                   => 'int NOT NULL',
        'class_name'                => 'nvarchar(255) NOT NULL',
        'line_number'               => 'int NOT NULL',
        'extends_class'             => 'nvarchar(255) NULL',
        'implements_interfaces'     => 'nvarchar(max) NULL',
        'description'               => 'nvarchar(max) NULL',
    ],

    'wiki_dependencies' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'file_id'           => 'int NOT NULL',
        'dependency_type'   => 'nvarchar(50) NOT NULL',
        'target_path'       => 'nvarchar(500) NOT NULL',
        'resolved_file_id'  => 'int NULL',
        'line_number'       => 'int NULL',
    ],

    'wiki_function_calls' => [
        'id'            => 'int IDENTITY(1,1) NOT NULL',
        'file_id'       => 'int NOT NULL',
        'function_name' => 'nvarchar(255) NOT NULL',
        'line_number'   => 'int NULL',
    ],

    'wiki_db_references' => [
        'id'                => 'int IDENTITY(1,1) NOT NULL',
        'file_id'           => 'int NOT NULL',
        'table_name'        => 'nvarchar(255) NOT NULL',
        'reference_type'    => 'nvarchar(50) NOT NULL',
        'line_number'       => 'int NULL',
    ],

    'wiki_session_vars' => [
        'id'            => 'int IDENTITY(1,1) NOT NULL',
        'file_id'       => 'int NOT NULL',
        'variable_name' => 'nvarchar(255) NOT NULL',
        'access_type'   => 'nvarchar(10) NOT NULL',
        'line_number'   => 'int NULL',
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

    foreach ($schema as $tableName => $columns) {
        $tableResult = ['table' => $tableName, 'status' => 'ok', 'details' => []];

        // Check if table exists
        $check = $conn->query("SELECT OBJECT_ID('$tableName', 'U') as table_exists");
        $exists = $check->fetch(PDO::FETCH_ASSOC)['table_exists'] !== null;

        if (!$exists) {
            // Build CREATE TABLE statement
            $colDefs = [];
            foreach ($columns as $colName => $colDef) {
                $colDefs[] = "[$colName] $colDef";
            }

            // Determine primary key
            if ($tableName === 'knowledge_article_tags') {
                $colDefs[] = "PRIMARY KEY CLUSTERED ([article_id], [tag_id])";
            } elseif (isset($primaryKeys[$tableName])) {
                $pkCol = $primaryKeys[$tableName];
                $colDefs[] = "PRIMARY KEY CLUSTERED ([$pkCol])";
            } else {
                $colDefs[] = "PRIMARY KEY CLUSTERED ([id])";
            }

            $sql = "CREATE TABLE [$tableName] (\n    " . implode(",\n    ", $colDefs) . "\n)";

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
                $colCheck = $conn->query("SELECT COL_LENGTH('$tableName', '$colName') as col_exists");
                $colExists = $colCheck->fetch(PDO::FETCH_ASSOC)['col_exists'] !== null;

                if (!$colExists) {
                    // Strip IDENTITY from ALTER TABLE ADD (can't add identity column to existing table)
                    $alterDef = preg_replace('/IDENTITY\(\d+,\d+\)\s*/', '', $colDef);

                    // For NOT NULL columns without defaults, add a sensible default to avoid errors on existing rows
                    if (stripos($alterDef, 'NOT NULL') !== false && stripos($alterDef, 'DEFAULT') === false) {
                        if (stripos($alterDef, 'int') === 0 || stripos($alterDef, 'numeric') === 0 || stripos($alterDef, 'decimal') === 0 || stripos($alterDef, 'bigint') === 0) {
                            $alterDef .= ' DEFAULT 0';
                        } elseif (stripos($alterDef, 'bit') === 0) {
                            $alterDef .= ' DEFAULT 0';
                        } elseif (stripos($alterDef, 'datetime') === 0 || stripos($alterDef, 'date') === 0) {
                            $alterDef .= ' DEFAULT GETDATE()';
                        } else {
                            $alterDef .= " DEFAULT ''";
                        }
                    }

                    try {
                        $conn->exec("ALTER TABLE [$tableName] ADD [$colName] $alterDef");
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

    echo json_encode([
        'success' => true,
        'results' => $results,
        'total_tables' => count($schema)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
