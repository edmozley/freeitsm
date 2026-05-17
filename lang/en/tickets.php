<?php
/**
 * English (en) — Tickets module strings (phase 1a).
 *
 * Covers the inbox page chrome users see on page load:
 *  - Header nav links
 *  - Folder panel (heading + group toggle)
 *  - Email list (empty states + icon button tooltips)
 *  - Reading pane empty state
 *  - Five modals (Add Note, Reply/Forward, Create New Ticket, Search, Schedule)
 *  - AI chat panel
 *
 * Out of scope for this pass (deferred to phase 1b):
 *  - Dynamic strings rendered by inbox.js (toasts, ticket card content,
 *    column headers built at runtime, notification messages, error toasts)
 *  - Ticket statuses + priorities that come from DB lookup tables
 *  - Settings/dashboard/calendar/rota/users/help pages
 */
return [
    'title' => 'Tickets',

    'nav' => [
        'inbox'     => 'Inbox',
        'dashboard' => 'Dashboard',
        'users'     => 'Users',
        'calendar'  => 'Calendar',
        'rota'      => 'Rota',
        'settings'  => 'Settings',
        'help'      => 'Help',
    ],

    'folders' => [
        'title'            => 'Folders',
        'group_label'      => 'Group folders by',
        'group_department' => 'Department',
        'group_analyst'    => 'Analyst',
    ],

    'list' => [
        'all_tickets'     => 'All Tickets',
        'new_ticket_btn'  => 'New ticket',
        'search_btn'      => 'Search tickets',
        'refresh_btn'     => 'Refresh',
        'select_folder'   => 'Select a folder to view tickets',
    ],

    'reading_pane' => [
        'select_ticket' => 'Select a ticket to view details',
    ],

    'note_modal' => [
        'title'       => 'Add Note',
        'note_label'  => 'Note',
        'placeholder' => 'Enter your note here...',
        'save_btn'    => 'Save Note',
    ],

    'reply_modal' => [
        'to'             => 'To',
        'cc'             => 'Cc',
        'to_placeholder' => 'recipient@example.com',
        'cc_placeholder' => 'cc@example.com (separate multiple with semicolons)',
        'message'        => 'Message',
        'attachments'    => 'Attachments',
        'drop_files'     => 'Drag files here or',
        'browse'         => 'browse',
        'cleaned_up'     => 'Cleaned up',
        'undo'           => 'Undo',
        'cleanup'        => 'Cleanup',
        'send'           => 'Send',
    ],

    'new_ticket_modal' => [
        'title'                   => 'Create New Ticket',
        'requester_name'          => 'Requester Name',
        'requester_email'         => 'Requester Email',
        'subject'                 => 'Subject',
        'department'              => 'Department',
        'type'                    => 'Type',
        'priority'                => 'Priority',
        'description'             => 'Description',
        'select_placeholder'      => '-- Select --',
        'name_placeholder'        => 'e.g., John Smith',
        'email_placeholder'       => 'e.g., john.smith@company.com',
        'subject_placeholder'     => 'Brief description of the issue',
        'description_placeholder' => 'Detailed description of the issue...',
        'create_btn'              => 'Create Ticket',
        'priority_normal'         => 'Normal',
        'priority_low'            => 'Low',
        'priority_high'           => 'High',
    ],

    'search_modal' => [
        'title'             => 'Search Tickets',
        'ticket_number'     => 'Ticket Number',
        'email_address'     => 'Email Address',
        'subject'           => 'Subject',
        'ticket_number_ph'  => 'e.g., TDB-914-96769',
        'email_ph'          => 'e.g., user@example.com',
        'subject_ph'        => 'Search in subject...',
        'search_btn'        => 'Search',
        'clear_btn'         => 'Clear',
        'empty_state'       => 'Enter search criteria above',
    ],

    'schedule_modal' => [
        'title'                => 'Schedule Work',
        'date'                 => 'Date',
        'start_time'           => 'Start Time',
        'currently_scheduled'  => 'Currently scheduled:',
        'clear_schedule'       => 'Clear schedule',
    ],

    'ai_chat' => [
        'title'       => 'Ask AI',
        'welcome'     => 'Ask a question about this ticket and the AI will search the knowledge base for relevant articles.',
        'placeholder' => 'Ask a question...',
    ],

    // Action buttons in the reading-pane action toolbar (above the email body).
    'actions' => [
        'add_note'             => 'Add Note',
        'reply'                => 'Reply',
        'forward'              => 'Forward',
        'schedule'             => 'Schedule',
        'ask_ai'               => 'Ask AI',
        'audit'                => 'Audit',
        'delete'               => 'Delete',
        'loading_attachments'  => 'Loading attachments...',
    ],

    // CMDB-linked-objects section in the reading pane (below the email body).
    'cmdb' => [
        'section_title'      => 'Affected CMDB Objects',
        'link_btn'           => '+ Link object',
        'empty'              => 'No CMDB objects linked yet.',
        'search_placeholder' => 'Type to search any CMDB object…',
        'no_matches'         => 'No matches.',
        'unlink_title'       => 'Unlink',
        'unlink_confirm'     => 'Unlink this CMDB object from the ticket?',
        'unlinked_toast'     => 'Unlinked',
        'linked_toast'       => 'Linked {name}',
        'already_linked'     => '{name} is already linked',
    ],

    // Time-tracking section in the reading pane.
    'time_entries' => [
        'section_title'        => 'Time Entries',
        'total_prefix'         => 'Total {amount}',
        'minutes_placeholder'  => 'Minutes',
        'notes_placeholder'    => 'What did you do? (optional)',
        'add_btn'              => 'Add',
        'empty'                => 'No time logged yet.',
        'delete_title'         => 'Delete entry',
        'delete_confirm'       => 'Delete this time entry?',
        'minutes_required'     => 'Enter the number of minutes spent.',
        'save_failed'          => 'Failed to save time entry: {error}',
        'delete_failed'        => 'Failed to delete time entry: {error}',
    ],

    // tickets/settings/index.php — admin settings page (tabs + section headings)
    'settings' => [
        'page_title' => 'Service Desk - Settings',
        // Tab labels along the top of the page
        'tabs' => [
            'departments'     => 'Departments',
            'teams'           => 'Teams',
            'ticket_types'    => 'Ticket Types',
            'ticket_origins'  => 'Ticket Origins',
            'statuses'        => 'Statuses',
            'priorities'      => 'Priorities',
            'rota_locations'  => 'Rota Locations',
            'mailboxes'       => 'Mailboxes',
            'email_templates' => 'Templates',
            'rota'            => 'Rota',
            'analysts'        => 'Analysts',
            'general'         => 'General',
            'reply_cleanup'   => 'Reply Cleanup',
        ],
        // Section h2 headings inside each tab. Most mirror the tab labels but
        // some are more descriptive — kept separate so translators can pick
        // different phrasings where natural.
        'headings' => [
            'departments'      => 'Departments',
            'teams'            => 'Teams',
            'ticket_types'     => 'Ticket Types',
            'ticket_origins'   => 'Ticket Origins',
            'statuses'         => 'Statuses',
            'priorities'       => 'Priorities',
            'rota_locations'   => 'Rota Locations',
            'mailboxes'        => 'Mailboxes',
            'email_templates'  => 'Email Templates',
            'rota_shifts'      => 'Rota Shifts',
            'rota_settings'    => 'Rota Settings',
            'analysts'         => 'Analysts',
            'general_settings' => 'General Settings',
            'reply_cleanup_ai' => 'Reply Cleanup AI',
        ],
        // Shared table column headers across the settings tabs. Most tabs
        // share Name / Description / Order / Status / Actions; the rest are
        // tab-specific.
        'columns' => [
            'name'         => 'Name',
            'description'  => 'Description',
            'teams'        => 'Teams',
            'departments'  => 'Departments',
            'analysts'     => 'Analysts',
            'order'        => 'Order',
            'status'       => 'Status',
            'actions'      => 'Actions',
            'colour'       => 'Colour',
            'closed'       => 'Closed',
            'default'      => 'Default',
            'mailbox'      => 'Mailbox',
            'last_checked' => 'Last Checked',
            'event'        => 'Event',
            'subject'      => 'Subject',
            'start'        => 'Start',
            'end'          => 'End',
            'username'     => 'Username',
            'full_name'    => 'Full Name',
            'email'        => 'Email',
            'last_login'   => 'Last Login',
            'date_time'    => 'Date/Time',
            'from'         => 'From',
            'action'       => 'Action',
            'reason'       => 'Reason',
        ],
        // Tooltips on the icon buttons in the Actions column. Edit/Delete
        // reuse the existing common.edit / common.delete keys.
        'tooltips' => [
            'assign_teams'   => 'Assign Teams',
            'activity'       => 'Activity',
            'check_emails'   => 'Check Emails',
            'logout'         => 'Logout',
            'authenticate'   => 'Authenticate',
            'reset_password' => 'Reset Password',
        ],
        // Buttons specific to this settings page. Generic Save / Cancel /
        // Add / Close / Delete reuse the existing common.* keys. Pagination
        // Prev/Next reuse common.calendar.previous / common.calendar.next.
        'buttons' => [
            'logs'            => 'Logs',
            'check_all'       => 'Check All',
            'test_connection' => 'Test connection',
            'verify'          => 'Verify',
        ],
        // Add/Edit modal contents across the settings tabs. Each modal has its
        // own sub-namespace below. Convention for technical labels: product
        // names + protocols + standards (Azure, OAuth, IMAP, Microsoft, Google,
        // SMTP) stay in Latin script in every locale; generic surrounding
        // words (ID, Port, Scopes, Secret, URI, Server) translate to the
        // locale's natural equivalent.
        'modals' => [
            // Shared lookup modal — department / team / type / origin / status / priority / rota-location
            'lookup' => [
                'add' => [
                    'department'    => 'Add Department',
                    'team'          => 'Add Team',
                    'ticket_type'   => 'Add Ticket Type',
                    'ticket_origin' => 'Add Ticket Origin',
                    'status'        => 'Add Status',
                    'priority'      => 'Add Priority',
                    'rota_location' => 'Add Rota Location',
                    'fallback'      => 'Add Item',
                ],
                'edit' => [
                    'department'    => 'Edit Department',
                    'team'          => 'Edit Team',
                    'ticket_type'   => 'Edit Ticket Type',
                    'ticket_origin' => 'Edit Ticket Origin',
                    'status'        => 'Edit Status',
                    'priority'      => 'Edit Priority',
                    'rota_location' => 'Edit Rota Location',
                    'fallback'      => 'Edit Item',
                ],
                'colour_help'         => 'Used for badges in lists, dashboards and reports.',
                'closed_label'        => 'Counts as closed',
                'closed_help'         => 'Tickets in this status are treated as resolved/terminal — excluded from open-queue counts and trigger the closed-datetime stamp.',
                'default_label'       => 'Default for new tickets',
                'default_help'        => 'Only one row can be the default — setting this clears the flag on the others.',
                'display_order_label' => 'Display Order',
                'active_label'        => 'Active',
            ],

            // Mailbox modal — the big one with Microsoft/Google/OAuth/IMAP fields
            'mailbox' => [
                'add_title'                   => 'Add Mailbox',
                'edit_title'                  => 'Edit Mailbox',
                'empty_state'                 => 'No mailboxes configured. Click "Add Mailbox" to get started.',
                'provider'                    => 'Provider',
                'provider_microsoft'          => 'Microsoft 365 (Exchange / Graph API)',
                'provider_google'             => 'Google Workspace (Gmail API)',
                'display_name'                => 'Display Name',
                'display_name_placeholder'    => 'e.g., Service Desk',
                'target_mailbox'              => 'Target Mailbox',
                'target_mailbox_placeholder'  => 'e.g., servicedesk@company.com',
                'azure_tenant_id'             => 'Azure Tenant ID',
                'client_id'                   => 'Client ID',
                'client_secret'               => 'Client Secret',
                'client_secret_placeholder'   => 'Leave blank to keep existing (when editing)',
                'client_secret_help'          => 'Required for new mailboxes. Leave blank when editing to keep existing secret.',
                'oauth_redirect_uri'          => 'OAuth Redirect URI',
                'oauth_scopes'                => 'OAuth Scopes',
                'imap_server'                 => 'IMAP Server',
                'imap_port'                   => 'IMAP Port',
                'email_folder'                => 'Email Folder',
                'max_emails_per_check'        => 'Max Emails per Check',
                'rejected_emails'             => 'Rejected Emails',
                'rejected_delete'             => 'Delete permanently',
                'rejected_move_to_deleted'    => 'Move to Deleted Items',
                'rejected_mark_read'          => 'Mark as read',
                'imported_emails'             => 'Imported Emails',
                'imported_delete'             => 'Delete permanently',
                'imported_move_to_folder'     => 'Move to folder',
                'move_to_folder_label'        => 'Move to Folder',
                'move_to_folder_placeholder'  => 'e.g., Processed',
                'active'                      => 'Active',
                'whitelist_label'             => 'Email Whitelist',
                'whitelist_help'              => 'If empty, all senders are allowed. Add domains or email addresses to restrict which emails are imported.',
                'whitelist_domain'            => 'Domain',
                'whitelist_email'             => 'Email',
                'whitelist_value_placeholder' => 'e.g. company.com or user@example.com',
            ],

            // Activity log modal
            'activity' => [
                'title'              => 'Mailbox Activity',
                'search_placeholder' => 'Search by sender, name, or subject...',
                'processing_log'     => 'Processing Log',
            ],

            // Analyst modal
            'analyst' => [
                'add_title'             => 'Add Analyst',
                'edit_title'            => 'Edit Analyst',
                'username'              => 'Username',
                'username_placeholder'  => 'e.g., jsmith',
                'full_name'             => 'Full Name',
                'full_name_placeholder' => 'e.g., John Smith',
                'email'                 => 'Email',
                'email_placeholder'     => 'e.g., jsmith@company.com',
                'password'              => 'Password',
                'password_placeholder'  => 'Enter password',
                'password_help'         => 'Required for new analysts.',
                'active'                => 'Active',
            ],

            // Password reset modal
            'password_reset' => [
                'title'                        => 'Reset Password',
                'resetting_for'                => 'Resetting password for:',
                'new_password'                 => 'New Password',
                'new_password_placeholder'     => 'Enter new password',
                'confirm_password'             => 'Confirm Password',
                'confirm_password_placeholder' => 'Confirm new password',
            ],

            // Team assignment modal
            'team_assignment' => [
                'title'       => 'Assign Teams',
                'description' => 'Select the teams to assign:',
                'loading'     => 'Loading teams...',
            ],

            // Email template modal
            'template' => [
                'add_title'           => 'Add Email Template',
                'edit_title'          => 'Edit Email Template',
                'name'                => 'Name',
                'name_placeholder'    => 'e.g., New Ticket Auto-Reply',
                'event_trigger'       => 'Event Trigger',
                'event_select'        => 'Select event...',
                'event_new_ticket'    => 'New ticket from email',
                'event_assigned'      => 'Ticket assigned',
                'event_closed'        => 'Ticket closed',
                'subject'             => 'Subject',
                'subject_placeholder' => 'e.g., Your request has been received',
                'subject_help'        => '[SDREF:...] is added automatically for reply threading.',
                'body'                => 'Body',
                'body_placeholder'    => "Dear [requester_name],\n\nThank you for contacting us...",
                'body_help'           => 'Merge codes: [ticket_reference], [ticket_subject], [ticket_status], [ticket_priority], [requester_name], [requester_email], [analyst_name], [analyst_email], [department_name], [created_date], [closed_date]',
                'display_order'       => 'Display Order',
                'active'              => 'Active',
            ],

            // Rota shift modal
            'rota_shift' => [
                'add_title'        => 'Add Shift',
                'edit_title'       => 'Edit Shift',
                'name'             => 'Name',
                'name_placeholder' => 'e.g., Early, Standard, Late',
                'start_time'       => 'Start Time',
                'end_time'         => 'End Time',
                'display_order'    => 'Display Order',
                'active'           => 'Active',
            ],
        ],
    ],

    // tickets/rota.php — weekly staff rota grid
    'rota' => [
        'page_title'      => 'Service Desk - Rota',
        'analyst_col'     => 'Analyst',
        'no_analysts'     => 'No active analysts found.',
        'add_entry'       => 'Add entry',
        'on_call_badge'   => 'On Call',
        'modal' => [
            'add_title'         => 'Add Rota Entry',
            'edit_title'        => 'Edit Rota Entry',
            'shift_label'       => 'Shift *',
            'shift_placeholder' => 'Select shift...',
            'location_label'    => 'Location',
            'on_call_checkbox'  => 'On call',
        ],
        'toasts' => [
            'saved'         => 'Entry saved',
            'deleted'       => 'Entry deleted',
            'save_failed'   => 'Failed to save entry',
            'delete_failed' => 'Failed to delete entry',
            'error'         => 'Error: {error}',
        ],
        'delete_confirm'  => 'Delete this rota entry?',
    ],

    // tickets/users.php — end-user directory with per-user ticket list
    'users' => [
        'page_title'            => 'Service Desk - Users',
        'list_title'            => 'Users',
        'search_placeholder'    => 'Search users...',
        'count'                 => '{count} users',
        'ticket_count'          => '{count} tickets',
        'unknown_name'          => 'Unknown',
        'no_users'              => 'No users found',
        'select_user'           => 'Select a user to view their details and tickets',
        'no_tickets'            => 'No tickets found for this user',
        'error_loading_tickets' => 'Error loading tickets',
        'tickets_section'       => 'Tickets ({count})',
        'status_new_fallback'   => 'New',
        'info' => [
            'email'         => 'Email',
            'first_seen'    => 'First Seen',
            'total_tickets' => 'Total Tickets',
        ],
        'table' => [
            'ticket_number' => 'Ticket #',
            'subject'       => 'Subject',
            'status'        => 'Status',
            'priority'      => 'Priority',
            'created'       => 'Created',
        ],
    ],

    // tickets/calendar.php — scheduled-tickets calendar view
    'calendar' => [
        'page_title'    => 'Service Desk - Calendar',
        'modal_title'   => 'Ticket Details',
        'open_in_inbox' => 'Open in Inbox',
        'x_more'        => '{count} more...',
        'unassigned'    => 'Unassigned',
        'na'            => 'N/A',
        'date_at_time'  => '{date} at {time}',
        'modal' => [
            'scheduled'  => 'Scheduled:',
            'status'     => 'Status:',
            'priority'   => 'Priority:',
            'requester'  => 'Requester:',
            'department' => 'Department:',
            'owner'      => 'Owner:',
        ],
    ],
];
