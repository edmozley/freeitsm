<?php
/**
 * Tasks — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. See includes/capabilities.php.
 *
 * Two things this module forced us to be honest about:
 *
 * 1. **Creating a tag is operational, deleting one is not.** The Kanban board lets you
 *    type a new tag straight onto a task (assets/js/tasks.js), so api/tasks/save_task_tag.php
 *    is everyday work and stays on plain module access — gate it and the board breaks for
 *    everyone. Deleting a tag, and configuring how tags behave, are administration and sit
 *    behind tasks.tags. The capability is honest about what it actually controls.
 *
 * 2. **Reordering the board's columns is a configuration change.** Dragging a column on
 *    the Kanban board reorders the statuses *for everybody*, so it now needs tasks.statuses
 *    — the same permission as editing the statuses in settings. It is the same act,
 *    reached by a different route.
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'tasks',
    'label'  => 'Tasks',

    'umbrella' => [
        'cap'   => Cap::TASKS_MANAGE,
        'grant' => 'Manage everything in Tasks settings',
    ],

    'tabs' => [
        [
            // Also governs reordering the Kanban board's columns — see the note above.
            'id'        => 'statuses',
            'cap'       => Cap::TASKS_STATUSES,
            'label_key' => 'tasks.settings.tab_statuses',
            'grant'     => 'Manage task statuses, and the order of the board\'s columns',
        ],
        [
            'id'        => 'priorities',
            'cap'       => Cap::TASKS_PRIORITIES,
            'label_key' => 'tasks.settings.tab_priorities',
            'grant'     => 'Manage task priorities',
        ],
        [
            'id'           => 'calendar',
            'cap'          => Cap::TASKS_CALENDAR,
            'label_key'    => 'tasks.settings.tab_calendar',
            'grant'        => 'Configure how tasks appear on the calendar',
            'setting_keys' => ['tasks_calendar_span_mode'],
        ],
        [
            'id'           => 'card',
            'cap'          => Cap::TASKS_CARD,
            'label_key'    => 'tasks.settings.tab_card',
            'grant'        => 'Configure what appears on a task card',
            'setting_keys' => ['tasks_card_fields'],
        ],
        [
            // Deleting tags and configuring them. Creating one inline from the board is
            // everyday work and deliberately stays on plain module access.
            'id'           => 'tags',
            'cap'          => Cap::TASKS_TAGS,
            'label_key'    => 'tasks.settings.tab_tags',
            'grant'        => 'Delete tags and configure how tagging behaves',
            'setting_keys' => ['tasks_tag_settings'],
        ],
        [
            'id'        => 'left-panel',
            'cap'       => null,
            'label_key' => 'common.left_panel.tab',
        ],
    ],
];
