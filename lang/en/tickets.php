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
