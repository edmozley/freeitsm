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
