<?php
/**
 * Projects - settings manifest (3.2.0).
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. The tab bar, the tick-boxes on System -> Roles and their
 * descriptions are all derived from this file. See includes/capabilities.php.
 *
 * ⚠️ NO 'setting_keys' ON ANY TAB, deliberately. Every setting is saved through
 * api/projects/settings.php, which validates the value and checks the SAME
 * capability as the tab (the Domains pattern) - declaring the keys here would
 * let the generic settings writer store them unvalidated.
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'projects',
    'label'  => 'Projects',

    'umbrella' => [
        'cap'       => Cap::PROJECTS_MANAGE,
        'grant'     => 'Manage everything in Projects settings, and change or delete any project',
        'sensitive' => true,
    ],

    'tabs' => [
        [
            'id'        => 'general',
            'cap'       => Cap::PROJECTS_GENERAL,
            'label_key' => 'projects.settings.tab_general',
            'grant'     => 'Decide who may create and change projects, and the default way of running one',
            'sensitive' => true,   // these ARE the module's permission rules
        ],
        [
            'id'        => 'health',
            'cap'       => Cap::PROJECTS_HEALTH,
            'label_key' => 'projects.settings.tab_health',
            'grant'     => 'Tune when a project shows as at risk or off track',
        ],
        [
            'id'        => 'roles',
            'cap'       => Cap::PROJECTS_ROLES,
            'label_key' => 'projects.settings.tab_roles',
            'grant'     => 'Maintain the list of project roles',
        ],
        [
            'id'        => 'raid',
            'cap'       => Cap::PROJECTS_RAID,
            'label_key' => 'projects.settings.tab_raid',
            'grant'     => 'Maintain the risk scales used in the RAID log',
        ],
        [
            'id'        => 'templates',
            'cap'       => Cap::PROJECTS_TEMPLATES,
            'label_key' => 'projects.settings.tab_templates',
            'grant'     => 'Save projects as templates, and choose which templates are offered for new projects',
        ],
        [
            'id'        => 'budget',
            'cap'       => Cap::PROJECTS_BUDGET,
            'label_key' => 'projects.settings.tab_budget',
            'grant'     => 'Set the budget currency, how labour is costed, and the hourly rates - including each analyst\'s',
            'sensitive' => true,   // per-analyst rates are close to pay
        ],
    ],
];
