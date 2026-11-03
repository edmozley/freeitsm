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
        'cap'   => capKey(Cap::TASKS_MANAGE),
        'grant' => 'Manage everything in Tasks settings',
    ],

    'tabs' => [
        [
            // Also governs reordering the Kanban board's columns — see the note above.
            'id'        => 'statuses',
            'cap'       => capKey(Cap::TASKS_STATUSES),
            'label_key' => 'tasks.settings.tab_statuses',
            'grant'     => 'Manage task statuses, and the order of the board\'s columns',
        ],
        [
            'id'        => 'priorities',
            'cap'       => capKey(Cap::TASKS_PRIORITIES),
            'label_key' => 'tasks.settings.tab_priorities',
            'grant'     => 'Manage task priorities',
        ],
        [
            'id'           => 'calendar',
            'cap'          => capKey(Cap::TASKS_CALENDAR),
            'label_key'    => 'tasks.settings.tab_calendar',
            'grant'        => 'Configure how tasks appear on the calendar',
            'setting_keys' => ['tasks_calendar_span_mode'],
        ],
        [
            'id'           => 'card',
            'cap'          => capKey(Cap::TASKS_CARD),
            'label_key'    => 'tasks.settings.tab_card',
            'grant'        => 'Configure what appears on a task card',
            'setting_keys' => ['tasks_card_fields'],
        ],
        [
            // Where time is recorded (GH #112). A top-level task and a subtask are the
            // same record told apart by parent_task_id, so this setting is the only
            // thing that distinguishes them — which is why it is a setting rather than
            // two separate features.
            'id'           => 'time',
            'cap'          => capKey(Cap::TASKS_TIME),
            'label_key'    => 'tasks.settings.tab_time',
            'grant'        => 'Configure where time is recorded on tasks',
            'setting_keys' => ['tasks_time_scope'],
        ],
        [
            // Who else is on a task (GH #89). Adding people is everyday work and
            // stays on plain module access — the same rule that keeps creating a
            // tag off the tags capability. What sits behind THIS is the one
            // administrative choice: whether each person gets a tick of their own.
            'id'           => 'involved',
            'cap'          => capKey(Cap::TASKS_INVOLVED),
            'label_key'    => 'tasks.settings.tab_involved',
            'grant'        => 'Configure whether people on a task tick off their own part',
            'setting_keys' => ['tasks_collaborator_completion', 'tasks_contractor_fields'],
        ],
        [
            // Checklists on tasks (discussion #138, round three). Off by default:
            // the author of the module thought subtasks and checklists side by side
            // would confuse some teams, so it is offered rather than imposed - the
            // same shape as time recording.
            'id'           => 'checklists',
            'cap'          => capKey(Cap::TASKS_CHECKLISTS),
            'label_key'    => 'tasks.settings.tab_checklists',
            'grant'        => 'Switch checklists, forms and procedures on tasks on or off',
            'setting_keys' => ['tasks_checklists_enabled', 'tasks_forms_enabled', 'tasks_procedures_enabled'],
        ],
        [
            // Repeating task evidence (#181): what deleting an occurrence and changing a
            // finished task's checklist do, which status a skipped occurrence moves to,
            // and whether running the evidence report needs its own permission. Sensitive:
            // these decide what an audit trail allows.
            'id'           => 'evidence',
            'cap'          => capKey(Cap::TASKS_EVIDENCE),
            'label_key'    => 'tasks.settings.tab_evidence',
            'grant'        => 'Configure repeating task evidence: deleting occurrences, changing finished checklists, who runs the report',
            'sensitive'    => true,
            'setting_keys' => ['tasks_evidence_completed_checklist', 'tasks_evidence_delete_occurrence',
                               'tasks_evidence_skip_status_id', 'tasks_evidence_report_access', 'tasks_evidence_open_followups'],
        ],
        [
            // Deleting tags and configuring them. Creating one inline from the board is
            // everyday work and deliberately stays on plain module access.
            'id'           => 'tags',
            'cap'          => capKey(Cap::TASKS_TAGS),
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

    // Everyday work (#181) - not a tab. Bites only once Settings -> Evidence ->
    // "Who can run the evidence report" is set to the permission.
    'actions' => [
        [
            'cap'      => capKey(Cap::TASKS_EVIDENCE_REPORT),
            'grant'    => 'Run the evidence report for a repeating task, and export it (print and CSV)',
            'restrict' => ['setting' => 'tasks_evidence_report_access', 'value' => 'permission'],
        ],
        [
            // GH #190 decision 4: task access alone never reveals form answers. This grants
            // exactly those on the occurrences you can see - nothing else in Forms.
            'cap'      => capKey(Cap::TASKS_FORM_ANSWERS),
            'grant'    => 'Read the form answers on tasks you can see, without access to the Forms module',
            'restrict' => null,
        ],
    ],
];
