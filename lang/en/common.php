<?php
/**
 * English (en) — Common shared UI strings.
 *
 * Used everywhere. Keep it small — module-specific strings belong in lang/en/<module>.php.
 * Other locales mirror this file structure under lang/<locale>/common.php.
 */
return [
    // Left-panel visibility preference — shared labels reused by every module
    // that has a left panel (settings pages + System → Preferences). Only the
    // identical strings live here; per-module intro/description copy stays in
    // each module's own file.
    'left_panel' => [
        'tab'        => 'Left panel',
        'visibility' => 'Visibility',
        'always'     => 'Always visible',
        'hover'      => 'Show on hover',
    ],

    // Shared AI provider/model/key panel (includes/ai_settings_panel.php),
    // reused by every module's AI settings tab.
    'ai' => [
        'provider'            => 'Provider',
        'provider_anthropic'  => 'Anthropic (Claude)',
        'provider_openai'     => 'OpenAI (GPT)',
        'provider_openrouter' => 'OpenRouter (one key, many models)',
        'openrouter_note'     => 'With OpenRouter, a single key reaches hundreds of models. Note that prompts are routed through OpenRouter\'s service.',
        'model'               => 'Model',
        'model_placeholder'   => 'Type or pick a model…',
        'model_set'           => 'Model',
        'loading_models'      => 'Loading model list…',
        'no_models'           => 'No matching models — you can type any model id',
        'openrouter_pricing'  => 'Prices shown per 1M tokens (in / out).',
        'models_stale'        => 'cached',
        'api_key'             => 'API key',
        'api_key_help'        => 'Stored encrypted. Leave blank to keep the saved key.',
        'api_key_set'         => 'A key is saved. Leave blank to keep it.',
        'verify_ssl'          => 'Verify SSL certificate',
        'verify_ssl_help'     => 'Leave on in production. Turn off only if your server can\'t validate the provider\'s certificate.',
        'save'                => 'Save',
        'test'                => 'Test',
        'testing'             => 'Testing…',
        'test_ok'             => 'Connection OK',
        'test_failed'         => 'Test failed',
        'saved'               => 'Saved',
        'save_failed'         => 'Failed to save',
    ],

    // Buttons
    'save'         => 'Save',
    'cancel'       => 'Cancel',
    'delete'       => 'Delete',
    'add'          => 'Add',
    'edit'         => 'Edit',
    'close'        => 'Close',
    'copy'         => 'Copy',
    'copied'       => 'Copied',
    'retry'        => 'Retry',
    'export'       => 'Export',
    'open'         => 'Open',
    'apply'        => 'Apply',

    // Confirm / state
    'yes'          => 'Yes',
    'no'           => 'No',
    'ok'           => 'OK',
    'loading'      => 'Loading...',
    'saving'       => 'Saving...',
    'saved'        => 'Saved',
    'unsaved'      => 'Unsaved',
    'unsaved_changes' => 'Unsaved changes',
    'failed'       => 'Failed',

    // Time / units (often inlined)
    'just_now'     => 'just now',
    'today'        => 'Today',
    'yesterday'    => 'Yesterday',

    // Form helpers
    'required'     => 'Required',
    'optional'     => 'Optional',
    'select_one'   => 'Select…',
    'search'       => 'Search',

    // Errors
    'error_generic'        => 'Something went wrong.',
    'error_network'        => 'Network error',
    'error_not_logged_in'  => 'You need to be logged in.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Service Desk',
        'browser_title'    => 'Service Desk - ITSM',
        'welcome_heading'  => 'What would you like to do?',
        'welcome_subtitle' => 'Select a module to get started',
        'footer'           => 'Service Desk ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM Modules',
    ],

    // Per-module display name + one-line description.
    // Used by the home cards (name + description tooltip) and the waffle panel (name only).
    'modules' => [
        'watchtower'     => ['name' => 'Watchtower',  'description' => 'Unified attention dashboard across all modules'],
        'tickets'        => ['name' => 'Tickets',     'description' => 'Manage support requests, emails, and user issues'],
        'assets'         => ['name' => 'Assets',      'description' => 'Track IT assets and user assignments'],
        'knowledge'      => ['name' => 'Knowledge',   'description' => 'Create and browse knowledge base articles'],
        'changes'        => ['name' => 'Changes',     'description' => 'Plan, track and manage IT changes'],
        'problems'       => ['name' => 'Problems',    'description' => 'Track the root cause behind recurring incidents'],
        'calendar'       => ['name' => 'Calendar',    'description' => 'Track events, deadlines and schedules'],
        'morning-checks' => ['name' => 'Checks',      'description' => 'Record daily infrastructure checks'],
        'reporting'      => ['name' => 'Reporting',   'description' => 'View system logs and analytics'],
        'software'       => ['name' => 'Software',    'description' => 'Browse software inventory and licensing'],
        'forms'          => ['name' => 'Forms',       'description' => 'Design custom forms and view submissions'],
        'contracts'      => ['name' => 'Contracts',   'description' => 'Manage suppliers, contacts and contracts'],
        'service-status' => ['name' => 'Status',      'description' => 'Monitor service health and track incidents'],
        'wiki'           => ['name' => 'Wiki',        'description' => 'Browse auto-generated codebase documentation'],
        'lms'            => ['name' => 'LMS',         'description' => 'Learning Management System with SCORM course player'],
        'process-mapper' => ['name' => 'Processes',   'description' => 'Visual flowchart and process mapping tool'],
        'tasks'          => ['name' => 'Tasks',       'description' => 'Kanban board and list view for tracking tasks'],
        'cmdb'           => ['name' => 'CMDB',        'description' => 'Configuration Management Database'],
        'network-mapper' => ['name' => 'Network',     'description' => 'Design and document network diagrams'],
        'workflow'       => ['name' => 'Workflows',   'description' => 'Cross-module automation — triggers, conditions, actions'],
        'system'         => ['name' => 'System',      'description' => 'System administration and configuration'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Check for new emails',
        'preferences'     => 'Preferences',
        'change_password' => 'Change Password',
        'mfa'             => 'Multi-Factor Auth',
        'trusted_device'  => 'Trusted Device',
        'logout'          => 'Logout',
        'logout_confirm'  => 'Are you sure you want to logout?',
        'badge_off'       => 'Off',
        'badge_on'        => 'On',
    ],

    // Change-password modal (static labels — dynamic JS toasts stay English for now)
    'password_modal' => [
        'title'            => 'Change Password',
        'current_password' => 'Current Password',
        'new_password'     => 'New Password',
        'confirm_password' => 'Confirm New Password',
        'submit'           => 'Change Password',
    ],

    // MFA modal (just the static title — the dynamic content is JS-rendered)
    'mfa_modal' => [
        'title' => 'Multi-Factor Authentication',
    ],

    // Calendar primitives — months, weekdays, navigation. Shared across any module
    // that renders a calendar (tickets/calendar.php today; top-level calendar/ next).
    'calendar' => [
        'previous'   => 'Previous',
        'next'       => 'Next',
        'today'      => 'Today',
        'view_month' => 'Month',
        'view_week'  => 'Week',
        'view_day'   => 'Day',

        'months' => [
            'january'   => 'January',
            'february'  => 'February',
            'march'     => 'March',
            'april'     => 'April',
            'may'       => 'May',
            'june'      => 'June',
            'july'      => 'July',
            'august'    => 'August',
            'september' => 'September',
            'october'   => 'October',
            'november'  => 'November',
            'december'  => 'December',
        ],

        'weekdays' => [
            'monday'    => 'Monday',
            'tuesday'   => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday'  => 'Thursday',
            'friday'    => 'Friday',
            'saturday'  => 'Saturday',
            'sunday'    => 'Sunday',
        ],
    ],
];
