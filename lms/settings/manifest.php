<?php
/**
 * LMS — settings manifest.
 *
 * The LMS is the one module whose capability is not really about a settings TAB. Its
 * whole management surface — authoring courses, running learning groups, assigning
 * training, seeing everyone's progress — sits behind a single capability, and plain
 * module access means "you may take the training assigned to you" (the My Courses page).
 * So the umbrella is the capability, and the settings page has one tab behind it.
 *
 * Declared here anyway, rather than special-cased, so that every module's capabilities
 * come from the same place and the Roles picker has a single source. See
 * includes/capabilities.php.
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'lms',
    'label'  => 'LMS',

    'umbrella' => [
        'cap'       => Cap::LMS_MANAGE,
        'grant'     => 'Manage courses, learning groups and assignments, and view everyone\'s progress',
        'sensitive' => false,
    ],

    'tabs' => [
        [
            // The AI settings for the course-authoring helpers. Behind the same
            // capability as the rest of LMS management — a learner has no business here,
            // and until now could reach this page simply by typing its URL.
            'id'        => 'ai',
            'cap'       => Cap::LMS_MANAGE,
            'label_key' => 'lms.settings.tab_ai',
            'grant'     => 'Manage courses, learning groups and assignments, and view everyone\'s progress',
        ],
        [
            // Training reminder emails.
            //
            // ⚠️ NO 'setting_keys' HERE, deliberately. That list exists for tabs
            // saved through api/settings/save_system_settings.php, the generic
            // key/value writer — and declaring a key there without teaching
            // includes/settings_keys.php who owns it makes the tab's save fail.
            // These settings have their own guarded endpoint
            // (api/lms/reminder_settings.php, behind this same capability), which
            // is how the AI panel on this page works too.
            'id'        => 'reminders',
            'cap'       => Cap::LMS_MANAGE,
            'label_key' => 'lms.settings.tab_reminders',
            'grant'     => 'Manage courses, learning groups and assignments, and view everyone\'s progress',
        ],
    ],
];
