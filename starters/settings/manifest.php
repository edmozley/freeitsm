<?php
/**
 * Starters & leavers - settings manifest (4.6.0).
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities (includes/capabilities.php). No 'setting_keys': templates are
 * saved through api/starters/templates.php, which checks the same capability.
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'starters',
    'label'  => 'Starters & leavers',

    'umbrella' => [
        'cap'       => capKey(Cap::STARTERS_MANAGE),
        'grant'     => 'Manage everything in Starters & leavers settings, and cancel or reopen any case',
        'sensitive' => true,
    ],

    'tabs' => [
        [
            'id'        => 'templates',
            'cap'       => capKey(Cap::STARTERS_TEMPLATES),
            'label_key' => 'starters.settings.tab_templates',
            'grant'     => 'Decide what happens for a starter, mover or leaver - the steps, who does each, and when',
        ],
    ],
];
