<?php
/**
 * English (en) — Workflows module strings.
 *
 * Workflows is a cross-module automation engine: triggers fire when events
 * happen in other modules (a ticket is created, a form is submitted),
 * conditions filter which workflows respond, and actions do something
 * (update a record, send an email, call a Graph endpoint).
 */
return [
    'title' => 'Workflows',

    'nav' => [
        'workflows' => 'Workflows',
        'help'      => 'Help',
    ],

    'list' => [
        'page_title'      => 'Workflows',
        'intro'           => 'Automation rules that listen for events from other modules and act on them. Each rule is a trigger, optional conditions, and one or more actions.',
        'add_btn'         => 'New',
        'no_workflows'    => 'No workflows yet — click "New" to create your first automation.',
        'col_name'        => 'Name',
        'col_trigger'     => 'Trigger',
        'col_actions'     => 'Actions',
        'col_last_run'    => 'Last run',
        'col_status'      => 'Status',
        'col_row_actions' => 'Actions',
        'never_run'       => 'Never',
        'active'          => 'Active',
        'inactive'        => 'Inactive',
    ],

    'editor' => [
        'new_title'       => 'New workflow',
        'edit_title'      => 'Edit workflow',
        'name_label'      => 'Name',
        'name_placeholder'=> 'e.g. P1 ticket alert',
        'description_label' => 'Description',
        'description_placeholder' => 'Optional — explain what this workflow does and why',
        'trigger_label'   => 'Trigger',
        'trigger_hint'    => 'The event that fires this workflow.',
        'conditions_label'=> 'Conditions',
        'conditions_hint' => 'Optional. All conditions must match for the actions to run. Leave empty to run on every trigger event.',
        'actions_label'   => 'Actions',
        'actions_hint'    => 'Run these in order, top to bottom. At least one action is required.',
        'active_label'    => 'Active — run when the trigger fires',
        'add_condition'   => 'Add condition',
        'add_action'      => 'Add action',
        'remove'          => 'Remove',
        'test_fire'       => 'Test fire',
        'test_fire_hint'  => 'Run this workflow once with a synthetic payload so you can verify the actions work. Real triggers will fire when wired in subsequent commits.',
        'no_conditions'   => 'No conditions — runs on every event.',
        'no_actions'      => 'No actions yet — add at least one.',
        'condition_field' => 'Field',
        'condition_op'    => 'Operator',
        'condition_value' => 'Value',
        'action_type'     => 'Action',
        'action_args'     => 'Arguments (JSON)',
        'back'            => 'Back',
    ],

    'op' => [
        'equals'      => 'equals',
        'not_equals'  => 'does not equal',
        'contains'    => 'contains',
        'not_contains'=> 'does not contain',
        'gt'          => 'greater than',
        'lt'          => 'less than',
        'is_empty'    => 'is empty',
        'is_not_empty'=> 'is not empty',
    ],

    'status' => [
        'success' => 'Success',
        'failed'  => 'Failed',
        'skipped' => 'Skipped (conditions did not match)',
        'running' => 'Running',
    ],

    'toast' => [
        'saved'         => 'Workflow saved.',
        'deleted'       => 'Workflow deleted.',
        'delete_confirm'=> 'Delete this workflow?',
        'fire_started'  => 'Test fire started — see the Execution log.',
        'fire_done'     => 'Test fire complete: %s.',
        'fire_failed'   => 'Test fire failed: %s',
        'name_required' => 'Name is required.',
        'actions_required' => 'At least one action is required.',
        'ai_applied'    => 'AI proposal applied to the canvas. Tweak then save.',
        'ai_failed'     => 'AI co-author failed: %s',
    ],

    'ai' => [
        'btn'              => 'AI co-author',
        'modal_title'      => 'AI co-author',
        'intro'            => 'Describe the workflow you want and the AI will scaffold it on the canvas. You can keep going — say "now add a condition for…" and it iterates on what\'s there.',
        'prompt_label'     => 'What should this workflow do?',
        'prompt_placeholder' => 'e.g. When a P1 ticket from Finance is created, log a message saying "P1 finance ticket — alert team"',
        'generate'         => 'Generate',
        'thinking'         => 'Thinking…',
        'apply'            => 'Apply to canvas',
        'discard'          => 'Discard',
        'close'            => 'Close',
        'iterate_hint'     => 'Tip: when you have a workflow on the canvas, the AI iterates on it. Try "make it only match Finance, not all departments" or "add an action to log the ticket id too".',
        'explanation_label' => 'What I built',
        'preview_label'    => 'Proposed workflow',
        'warnings_label'   => 'Notes',
        'only_log_message' => 'Only the log_message action is implemented today; the AI will lean on it as a placeholder for unimplemented actions (e.g. "send email" → log_message documenting the intent).',
    ],

    'help' => [
        'page_title' => 'Workflows guide',
        'intro'      => 'A short overview of how the engine works while it\'s being built out. Visual canvas builder and AI co-author are planned for upcoming releases.',
    ],
];
