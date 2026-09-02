<?php
/**
 * English (en) — Common shared UI strings.
 *
 * Used everywhere. Keep it small — module-specific strings belong in lang/en/<module>.php.
 * Other locales mirror this file structure under lang/<locale>/common.php.
 */
return [
    // Shared "add this calendar to your phone" dialogue
    // (includes/subscribe_modal.php + assets/js/subscribe.js). Only the strings
    // that are genuinely identical for every feed live here; each caller passes
    // its own title, intro and field labels.
    'subscribe' => [
        'insecure'      => 'This system is not using HTTPS, so this link — and everything it shows — travels across the network unprotected every time your calendar refreshes. Ask your administrator to enable HTTPS before using it outside a trusted network.',
        'copied'        => 'Link copied',
        'reset'         => 'Reset',
        'reset_confirm' => 'Every device already subscribed will stop updating until you give it the new link. Continue?',
        'reset_done'    => 'Link reset — the old one no longer works',
    ],
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

        // Azure OpenAI deployment-based endpoints (discussion #86). Named
        // "Azure OpenAI" rather than "Azure" because Azure hosts a great many
        // things and the distinction matters to the person choosing it.
        'provider_azure'        => 'Azure OpenAI (your own deployment)',
        'azure_note'            => 'Requests go to a deployment in your own Azure subscription rather than to a shared service, so prompts never leave your tenant. Copy all three values from the Azure portal, under your resource\'s Deployments.',
        'azure_endpoint'        => 'Azure endpoint',
        'azure_endpoint_help'   => 'The resource address from the Azure portal. A trailing slash, or a "/openai" on the end, makes no difference.',
        'azure_deployment'      => 'Deployment name',
        'azure_deployment_help' => 'The name YOU gave the deployment, which need not match the model it runs. This replaces the model box — on a deployment endpoint the deployment decides the model.',
        'azure_api_version'     => 'API version',
        'azure_api_version_help'=> 'Shown beside the deployment in the Azure portal. Leave it blank to use 2024-02-01.',
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
    'dismiss'      => 'Dismiss',
    'copy'         => 'Copy',
    'copied'       => 'Copied',
    'retry'        => 'Retry',
    'export'       => 'Export',
    'back'         => 'Back',
    'open'         =>  'Open',
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
    'filter'       => 'Filter',

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
        // The recent trail (#124). "Recent" rather than "History": history is a
        // record of what happened, and this is a way back to it.
        'tab_modules'  => 'Modules',
        'tab_recent'   => 'Recent',
        'trail_search' => 'Search your trail',
        'trail_loading' => 'Loading…',
        // Shown before anybody has opened a record. It says what the pane WILL
        // hold rather than that it is empty, because an empty list with no
        // explanation reads as something that is broken.
        'trail_empty'  => 'Records you open will appear here, grouped by the module you were in.',
        'trail_no_matches' => 'Nothing in your trail matches that.',
        'trail_unavailable' => 'Run Database Verification in System to switch this on.',
    ],

    // Per-module display name + one-line description.
    // Used by the home cards (name + description tooltip) and the waffle panel (name only).
    'modules' => [
        'watchtower'     => ['name' => 'Watchtower',  'description' => 'Unified attention dashboard across all modules'],
        'tickets'        => ['name' => 'Tickets',     'description' => 'Manage support requests, emails, and user issues'],
        'assets'         => ['name' => 'Assets',      'description' => 'Track IT assets and user assignments'],
        'knowledge'      => ['name' => 'Knowledge',   'description' => 'Create and browse knowledge base articles'],
        'changes'        => ['name' => 'Changes',     'description' => 'Plan, track and manage IT changes'],
        'problems'       => ['name' => 'Problem Management', 'name_short' => 'Problems', 'description' => 'Track the root cause behind recurring incidents'],
        'calendar'       => ['name' => 'Calendar',    'description' => 'Track events, deadlines and schedules'],
        'morning-checks' => ['name' => 'Checks',      'description' => 'Record daily infrastructure checks'],
        'reporting'      => ['name' => 'Reporting',   'description' => 'View system logs and analytics'],
        'software'       => ['name' => 'Software',    'description' => 'Browse software inventory and licensing'],
        'forms'          => ['name' => 'Forms',       'description' => 'Design custom forms and view submissions'],
        'contracts'      => ['name' => 'Contracts',   'description' => 'Manage suppliers, contacts and contracts'],
        'service-status' => ['name' => 'Status',      'description' => 'Monitor service health and track incidents'],
        'war-room'       => ['name' => 'War room',    'description' => 'Fallback chat for when Teams or Slack is unavailable'],
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
    // The global notification bell (discussion #55). Event descriptions are
    // resolved when the bell renders, not when the row is written, so a
    // notification reads in the LANGUAGE OF WHOEVER IS READING IT rather than
    // whoever happened to trigger it.
    // The documents panel, shared by every module that attaches files or links
    // to a record. Lives in `common` because it belongs to no single module.
    'documents' => [
        'heading'        => 'Documents',
        'count_one'      => '1 document',
        'count_many'     => '{n} documents',
        'none'           => 'No documents attached yet.',
        'drop'           => 'Drop a file here, or click to choose one',
        'drop_or'        => 'or paste a link to it in your document system below',
        'link_url'       => 'https://link-to-your-document',
        'link_title'     => 'What is it? (optional)',
        'add_link'       => 'Add link',
        'open'           => 'Open',
        'download'       => 'Download',
        'remove'         => 'Remove',
        'remove_confirm' => 'Remove "{name}" from this record?',
        // Said only when that was the LAST place it was attached. Everywhere else
        // "Remove" means detach, and the file stays where others can still see it.
        'removed_last'   => 'That was the last place it was attached, so the document has been deleted.',
        'also_on'        => 'Also on {label}',
        'uploading'      => 'Uploading…',
        'show_more'      => 'Show more',
        'failed'         => 'Something went wrong.',
        'by'             => 'by {name}',
        'loading'        => 'Loading…',
        'close'          => 'Close',
        // The ⓘ dialogue: where a document is attached, and whether it is searchable.
        'info_title'     => 'Document details',
        'attached_to'    => 'Attached to',
        'attached_none'  => 'Not attached to anything you can see.',
        // A COUNT, never a name — it says the document is more widely attached
        // than you can see, which matters before you attach it somewhere new,
        // and identifies nothing.
        'attached_hidden' => 'And {n} other record(s) you do not have access to.',
        'kind_link'      => 'A link to an external document',
        // "Not indexed yet" and "nothing readable in it" look identical without this.
        'idx_ok'          => 'Searchable — {n} characters of text indexed.',
        'idx_pending'     => 'Not searchable yet — the text is still being read.',
        'idx_unsupported' => 'Its contents cannot be read, so only its name and description are searchable.',
        'idx_failed'      => 'Its contents could not be read.',
        // Attaching an existing document: the other half of the join table.
        'find_existing'   => 'Or attach a document already in FreeITSM — start typing its name',
        'find_none'       => 'No documents match, that you can see and that are not already here.',
        // Shown against each candidate, because attaching WIDENS who can read it.
        'currently_on'    => 'currently on {where}',
    ],

    'notifications' => [
        'title'       => 'Notifications',
        'aria'        => 'Notifications',
        'mark_all'    => 'Mark all read',
        // Clearing is separate from reading: "read" silences the badge and keeps
        // the row, "clear" deletes it (discussion #111).
        'clear_all'         => 'Clear all',
        'clear_one'         => 'Clear this notification',
        'clear_one_title'   => 'Clear this notification?',
        'clear_one_msg'     => 'It will be removed from your bell for good. This cannot be undone.',
        'clear_title'       => 'Clear notifications?',
        'clear_msg'         => 'This removes them from your bell for good. It cannot be undone.',
        'clear_msg_read'    => 'This removes read notifications from your bell for good. It cannot be undone.',
        'clear_unread'      => 'Also clear the {n} you have not read yet',
        'clear_unread_one'  => 'Also clear the 1 you have not read yet',
        'clear_ok'          => 'Clear',
        'clear_failed'      => 'Could not clear notifications.',
        'clear_nothing'     => 'Nothing to clear — all of these are unread.',
        'empty'       => 'Nothing new.',
        'loading'     => 'Loading…',
        'load_failed' => 'Could not load notifications.',
        'someone'     => 'Someone',
        'just_now'    => 'just now',
        'minutes'     => '{n}m ago',
        'hours'       => '{n}h ago',
        'days'        => '{n}d ago',
        // ⚠️ NESTED BY ENTITY, not keyed by the literal event name. I18n::t()
        // splits the key on EVERY dot, so a flat 'ticket.assigned' key here is
        // unreachable — the lookup silently misses and the bell falls back to
        // showing a bare name with no explanation of what happened.
        // Event types are 'ticket.assigned' etc, so 'event.' . $type resolves
        // through this nesting exactly as intended.
        'event' => [
            'ticket' => [
                'assigned'         => 'Assigned to you by {actor}',
                'reply_received'   => 'The requester replied',
                'note_added'       => '{actor} added a note',
                'status_changed'   => '{actor} changed the status',
                'priority_changed' => '{actor} changed the priority',
                'created'          => 'Raised by {actor}',
            ],
            'sla' => [
                'warning'  => 'Approaching its SLA target',
                'breached' => 'SLA target breached',
            ],
            'task' => [
                'assigned'  => 'Assigned to you by {actor}',
                'created'   => '{actor} created a task for you',
                'completed' => '{actor} completed a task',
            ],
        ],
        // Labels for the per-type switches on the Preferences page. Same nesting
        // rule as above, and for the same reason.
        'pref' => [
            'ticket' => [
                'assigned'         => 'A ticket is assigned to me',
                'reply_received'   => 'A requester replies to my ticket',
                'note_added'       => 'Someone adds a note to my ticket',
                'status_changed'   => 'Someone changes the status of my ticket',
                'priority_changed' => 'Someone changes the priority of my ticket',
                'created'          => 'A ticket is raised',
            ],
            'sla' => [
                'warning'  => 'My ticket is approaching its SLA target',
                'breached' => 'My ticket breaches its SLA target',
            ],
            // ⚠️ "on a task I am on" rather than "my task": since GH #89 these
            // reach the owner AND everybody listed as Involved, so wording them
            // as ownership would be wrong for most of the people reading them.
            'task' => [
                'assigned'            => 'A task is assigned to me',
                'created'             => 'A task is created for me',
                'completed'           => 'A task I am on is completed',
                'collaborator_added'  => 'I am added to a task',
                'collaborator_removed'=> 'I am taken off a task',
                'comment_added'       => 'Someone comments on a task I am on',
                'status_changed'      => 'Someone changes the status of a task I am on',
                'due_date_changed'    => 'Someone changes the due date of a task I am on',
            ],
        ],
    ],

    'account' => [
        'mail_check'      => 'Check for new emails',
        'preferences'     => 'Preferences',
        'appearance'      => 'Appearance',
        'change_password' => 'Change Password',
        'mfa'             => 'Multi-Factor Auth',
        'trusted_device'  => 'Trusted Device',
        // Only rendered when the analyst also has a portal account they could sign
        // in to. See includes/waffle-menu.php (#81).
        'portal'          => 'Self-Service Portal',
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

        // Abbreviated forms, used by the date formatter (DateFmt / fmtDate) for
        // the "25 Aug 2026" family of formats. Kept beside the full names so a
        // translator sees both together. Where a language does not abbreviate a
        // month, repeat the full word - do NOT invent an abbreviation.
        'months_short' => [
            'january'   => 'Jan',
            'february'  => 'Feb',
            'march'     => 'Mar',
            'april'     => 'Apr',
            'may'       => 'May',
            'june'      => 'Jun',
            'july'      => 'Jul',
            'august'    => 'Aug',
            'september' => 'Sep',
            'october'   => 'Oct',
            'november'  => 'Nov',
            'december'  => 'Dec',
        ],

        'weekdays_short' => [
            'monday'    => 'Mon',
            'tuesday'   => 'Tue',
            'wednesday' => 'Wed',
            'thursday'  => 'Thu',
            'friday'    => 'Fri',
            'saturday'  => 'Sat',
            'sunday'    => 'Sun',
        ],
    ],
    // Saved table views, shared by every module running the data-table engine (#96)
    'table_views' => [
        'button'               => 'Views',
        'heading'              => 'Saved views',
        'search_placeholder'   => 'Search views by name, description or who made them',
        'layout_list'          => 'List',
        'layout_cards'         => 'Cards',
        'save_current'         => 'Save current view',
        'close'                => 'Close',
        'none_yet'             => 'No saved views yet. Set the table up how you like it, then use Save view in the toolbar.',
        'none_matching'        => 'No views match what you typed.',
        'vis_private'          => 'Only me',
        'vis_team'             => 'Team',
        'vis_public'           => 'Everyone',
        'vis_team_label'       => 'A team',
        'vis_public_label'     => 'Everyone',
        'default'              => 'Default',
        'set_default'          => 'Open this table with this view',
        'unset_default'        => 'Stop opening this table with this view',
        'edit'                 => 'Edit',
        'delete'               => 'Delete',
        'by'                   => 'by {name}',
        'by_nobody'            => 'owner removed',
        'created'              => 'Created {d}',
        'modified'             => 'Modified {d}',
        'last_used'            => 'Last used {d}',
        'never_used'           => 'Never used',
        'save_heading'         => 'Save this view',
        'edit_heading'         => 'Edit view',
        'field_name'           => 'Name',
        'field_description'    => 'Description',
        'field_visibility'     => 'Who can see it',
        'name_placeholder'     => 'My end user devices',
        'desc_placeholder'     => 'What this view is for, so somebody else knows whether to use it',
        'no_teams'             => 'You are not in a team, so there is nobody to share a team view with.',
        'edit_hint'            => 'This changes the name and who can see it. What the view SHOWS is left as it was saved - use "Save current view" to capture the table as it looks now.',
        'save'                 => 'Save',
        'cancel'               => 'Cancel',
        'failed'               => 'That did not work.',
    ],

    // At-a-glance previews of a linked record (#91). Shared, because seven
    // modules render them.
    'preview' => [
        'unavailable'        => 'That record cannot be shown here.',
        'status'             => 'Status',
        'priority'           => 'Priority',
        'with'               => 'With',
        'requester'          => 'Requester',
        'assignee'           => 'Assignee',
        'due'                => 'Due',
        'subtasks'           => 'Subtasks',
        'subtask_progress'   => '{done} of {total} done',
        'risk'               => 'Risk',
        'window'             => 'Planned',
        'tickets'            => 'Tickets attached',
        'tag'                => 'Asset tag',
        'type'               => 'Type',
        'model'              => 'Make and model',
        'serial'             => 'Serial',
        'held_by'            => 'Held by',
        'held_by_many'       => '{name} and {n} other(s)',
        'warranty'           => 'Warranty',
        'supplier'           => 'Supplier',
        'renewal'            => 'Renews',
        'notice'             => 'Notice by',
        'open'               => 'Open',
        'loading'            => 'Loading…',
        'aria'               => 'Preview this record',
    ],

];
