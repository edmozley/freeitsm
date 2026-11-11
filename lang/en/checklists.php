<?php
/**
 * Checklists & SOPs — English strings.
 *
 * The module was contributed with its screens hardcoded in English (2.0.0,
 * Santhosh Srinivasan) and this namespace originally covered the help page
 * only. The four screens — the template list, the settings tabs, the template
 * editor and the panel on a ticket — were wired to t() afterwards.
 *
 * 🔴 HOW THE GAP WAS FOUND, because no tool here could find it. Ed opened the
 * Checklists page with Spanish set and saw "Ayuda" in the nav and every other
 * word in English. `i18n_audit.php` reported Spanish at 100% and was right:
 * the strings were not missing from lang/es, they were missing from lang/en
 * as well, because they had never left the PHP. A locale comparison can only
 * see keys that exist.
 *
 * `scripts/i18n_unwired.php` is the check that CAN see it — it counts strings
 * a file renders against the number of t() calls it makes. Run it after adding
 * a screen.
 */

return [
    // The template editor (checklists/edit/).
    'editor' => [
        'page_title'        => 'Edit checklist',
        'back'              => 'Back',
        'save'              => 'Save',
        'heading_template'  => 'Template',
        'heading_template_sub' => 'What this checklist is, and how analysts will find it.',
        'title'             => 'Title',
        'category'          => 'Category',
        'applies_to'        => 'Applies to',
        'applies_both'      => 'Tickets and tasks',
        'applies_ticket'    => 'Tickets only',
        'applies_task'      => 'Tasks only',
        // Shown under "Applies to" while Tasks → Settings → Checklists is off (discussion #138).
        'tasks_off_hint'    => 'Checklists on tasks are switched off. Until an administrator turns them on in Tasks → Settings → Checklists, this only reaches tickets.',
        'closure_mode'      => 'Closure gate',
        'closure_mode_desc' => 'How outstanding mandatory steps behave when a ticket moves to a closed status.',
        'closure_warn'      => 'Standard (Warn & record override)',
        'closure_block'     => 'Critical (Block closure until complete)',
        'description'       => 'Description',
        'description_ph'    => 'When and why to use this checklist.',
        'keywords'          => 'Keywords',
        'keywords_ph'       => 'vpn, remote access, token',
        'keywords_help'     => 'Comma separated. Used to suggest this checklist against a ticket\'s subject.',
        'heading_steps'     => 'Steps',
        'heading_steps_sub' => 'Drag a step by its handle to reorder. The order here is the order an analyst works through.',
        'add_step'          => 'Add step',
        'steps_empty'       => 'No steps yet - add the first one.',
        'step_title_ph'     => 'What the analyst does',
        'mandatory'         => 'Mandatory',
        'ask_for_value'     => 'Ask for a value',
        'ask_for_result'    => 'Ask for a result',
        'ask_for_result_hint' => 'This step records Pass, Fail or Not applicable instead of a tick, on tasks and on tickets, and Fail asks what went wrong.',
        'value_prompt_ph'   => 'What to ask for, e.g. Asset tag',
        'step_desc_label' => 'Step description',
        'step_desc_ph' => 'Description (optional) - shown under the step, e.g. where to look or what good looks like',
        'remove_step'       => 'Remove step',
        'remove_step_named' => 'Remove "{name}" from this checklist?',
        'remove_step_empty' => 'Remove this empty step?',
        'remove'            => 'Remove',
        'role_optional'     => 'Role (optional)',
        'new_template'      => 'New template',
        'unsaved'           => 'Unsaved changes',
        'title_ph'          => 'For example, New employee workstation setup',
        'category_ph'       => 'For example, HR & IT',
    ],

    // The templates list (checklists/).
    'list' => [
        'page_title'      => 'Checklists',
        'heading'         => 'Checklist templates',
        'heading_sub'     => 'Standard checklist templates with step-level role assignments',
        'new_template'    => 'New template',
        'search_ph'       => 'Search templates...',
        'scope'           => 'Scope',
        'scope_all'       => 'All scopes',
        'scope_ticket'    => 'Ticket',
        'scope_task'      => 'Task',
        'categories'      => 'Categories',
        'categories_all'  => 'All categories',
        'roles'           => 'Suggested roles',
        'roles_all'       => 'All roles',
        'manage'          => 'Manage',
        'edit'            => 'Edit',
        'delete'          => 'Delete',
        'more_steps'      => '+ {count} more steps...',
        'no_steps'        => 'No checklist steps defined',
        'delete_title'    => 'Delete template',
        'delete_confirm'  => 'Delete "{name}"? Checklists already attached to a ticket keep their steps — only the template goes.',
        'badge_both'      => 'BOTH',
        'badge_ticket'    => 'TICKET',
        'badge_task'      => 'TASK',
    ],

    // Checklists -> Settings (checklists/settings/).
    'settings' => [
        'page_title'        => 'Checklist settings',
        'heading'           => 'Checklist settings',
        'heading_sub'       => 'Manage the categories and suggested roles your checklist templates can use.',
        'tab_categories'    => 'Categories',
        'tab_roles'         => 'Suggested roles',
        'tab_layout'        => 'Left panel',

        'categories'        => 'Categories',
        'categories_sub'    => 'How checklist templates are grouped. A category is created automatically the first time a template uses it.',
        'col_category'      => 'Category',
        'col_templates'     => 'Templates',
        'categories_empty'  => 'No categories yet - one appears here as soon as a template uses it.',

        'roles'             => 'Suggested roles',
        'roles_sub'         => 'Who normally carries out a step. Offered when building a template; it suggests, it does not assign.',
        'col_role'          => 'Role',
        'col_steps_using'   => 'Steps using it',
        'roles_empty'       => 'No roles yet.',

        'col_actions'       => 'Actions',
        'add'               => 'Add',
        'edit'              => 'Edit',
        'delete'            => 'Delete',
        'rename'            => 'Rename',
        'name'              => 'Name',
        'cancel'            => 'Cancel',
        'save'              => 'Save',

        'layout_heading'    => 'Left panel visibility',
        'layout_sub'        => 'Choose how the checklist templates sidebar behaves on your account.',
        'layout_pinned'     => 'Always visible',
        'layout_pinned_desc' => 'Keep the 260px filter panel permanently pinned to the left of the page.',
        'layout_hover'      => 'Show on hover',
        'layout_hover_desc' => 'Collapse the sidebar into a thin 16px strip that slides open when your cursor approaches it.',
    ],

    // The checklist panel on a ticket (checklists/ticket_view.js).
    'panel' => [
        'title'            => 'Checklist',
        'none_attached'    => 'None attached to this ticket.',
        'attach'           => 'Attach',
        'modal_title'      => 'Checklists for ticket #{id}',
        'next_steps'       => 'Checklist next steps',
        'pop_out'          => 'Pop out full checklist',
        'expand'           => 'Expand',
        'collapse'         => 'Collapse',
        'expand_title'     => 'Show every step',
        'collapse_title'   => 'Show only the next step',
        'no_match'         => 'No matching checklists found.',
        'suggested_one'    => 'Suggested checklist for this ticket (Best match):',
        'suggested_many'   => 'Suggested checklists for this ticket (Top {count} matches):',
        'remove_title'     => 'Remove checklist',
        'remove_confirm'   => 'Remove "{name}" from this ticket?',
        'remove_confirm_done' => 'Remove "{name}" from this ticket? {count} completed step(s) and their attribution go with it.',
        'attached_elsewhere' => 'Checklist attached',
        // GH #271: results on ticket steps
        'next' => 'Next:',
        'result_label' => 'Result',
        'result_pass' => 'Pass',
        'result_fail' => 'Fail',
        'result_na' => 'Not applicable',
        'result_clear' => 'Clear',
        'result_by' => 'Recorded by {name}',
        'result_exception' => 'What went wrong: {note}',
        'fail_title' => 'What went wrong?',
        'fail_placeholder' => 'Say what failed, so the record shows it',
        'fail_needed' => 'A failed step needs a note on what went wrong.',
        'cancel' => 'Cancel',
        'save' => 'Save',
        'save_failed' => 'Could not update the step',
    ],
    'nav' => [
        'templates' => 'Templates',
        'settings'  => 'Settings',
        'help'      => 'Help',
    ],

    'help' => [
        'page_title' => 'Checklists - Help',
        'guide'      => 'Checklists guide',
        'hero_title' => 'Checklists',
        'hero_sub'   => 'Write a checklist once, attach it to any ticket, and see at a glance which steps are done. Ideal for tracking Standard Operating Procedures (SOPs) and routine jobs your team does the same way every time — onboarding, offboarding, a firewall change, or a VPN fault.',

        'nav_overview'  => 'Overview',
        'nav_building'  => 'Writing a checklist',
        'nav_steps'     => 'Steps',
        'nav_using'     => 'Using one on a ticket',
        'nav_closing'   => 'Mandatory steps and closing',
        'nav_settings'  => 'Settings',
        'nav_tips'      => 'Tips',

        // 1 ---------------------------------------------------------------
        'overview_heading' => 'What this module is for',
        'overview_intro'   => 'Some jobs are done the same way every time, and the cost of getting one step wrong is high. A new starter with no mailbox. A leaver whose VPN token is still live. This module holds those Standard Operating Procedures and routine jobs as reusable checklist templates, tracking them per ticket so nothing is finished from memory.',

        'card_templates_title' => 'Templates',
        'card_templates_desc'  => 'A checklist written once - a title, a description, and an ordered list of steps. Edit it and every future use picks up the change.',
        'card_attach_title'    => 'Attached per ticket',
        'card_attach_desc'     => 'Attaching a template to a ticket takes a copy of its steps. Ticking them off records who did what and when, on that ticket only.',
        'card_mandatory_title' => 'Mandatory steps',
        'card_mandatory_desc'  => 'Mark the steps that genuinely must happen. FreeITSM can then warn - or refuse - when somebody closes a ticket with one outstanding.',
        'card_suggest_title'   => 'Suggested automatically',
        'card_suggest_desc'    => 'Keywords on a template let FreeITSM offer the right checklist on a ticket before anybody goes looking for it.',

        // 2 ---------------------------------------------------------------
        'building_heading' => 'Writing a checklist',
        'building_intro'   => 'Templates live on the main Checklists screen. <strong>New</strong>, at the top right, opens the editor on its own screen - it is a writing job, not a dialogue, and it needs the room.',
        'building_step1'   => '<strong>Title</strong> - what the checklist is, as somebody searching would say it. <em>New starter onboarding</em>, not <em>Process 4b</em>.',
        'building_step2'   => '<strong>Category</strong> - groups templates on the list and colours their pill. Categories are yours to define, in <strong>Settings &rarr; Categories</strong>.',
        'building_step3'   => '<strong>Applies to</strong> - whether the checklist is offered on tickets, on tasks, or on both.',
        // A separate key so the translated step above keeps its meaning (discussion #138).
        'building_step3_tasks' => 'Tasks only offer checklists once an administrator switches them on in <strong>Tasks &rarr; Settings &rarr; Checklists</strong>; there, Standard and Critical work exactly as they do on tickets.',
        'building_step3_workflows' => 'A workflow\'s <strong>Attach SOP Checklist(s)</strong> action offers only checklists for tickets (or both). Making one tasks only after a workflow uses it does not break the workflow: that checklist is skipped, and the run leaves a note in the workflow\'s execution log.',
        'building_step4'   => '<strong>Description</strong> - when and why to use this one. This is what somebody reads when deciding between two similar checklists, so it earns its place.',
        'building_step5'   => '<strong>Keywords</strong> - comma separated, and the reason a checklist finds its own ticket. A VPN checklist tagged <code>vpn, remote access, token</code> is offered on a ticket about any of them.',
        'building_step6'   => '<strong>Closure gate</strong> - choose how mandatory steps behave when a ticket is closed: <em>Standard</em> (warns the analyst and records an audit note if overridden) or <em>Critical</em> (strictly blocks ticket closure until completed).',
        'building_note'    => 'Editing a template does not change checklists already attached to tickets. An attached checklist is a copy taken at the moment it was attached, so a ticket half way through a job keeps the steps the analyst started with.',

        // 3 ---------------------------------------------------------------
        'steps_heading' => 'Steps',
        'steps_intro'   => 'A step is one thing the analyst does. Keep them to one action each - a step reading <em>set the account up</em> cannot be ticked off honestly.',
        'steps_order_title' => 'Order',
        'steps_order_desc'  => 'Drag a step by the handle on its left to move it. The numbers renumber themselves. Order is the point of a checklist, so it is worth getting right rather than living with "do step 6 before step 4".',
        'steps_role_title'  => 'Suggested role',
        'steps_role_desc'   => 'Who normally does this step - <em>IT</em>, <em>HR</em>, <em>Facilities</em>. It is guidance printed beside the step, not a permission: anybody can tick any step. Roles are defined in <strong>Settings &rarr; Roles</strong>.',
        'steps_mand_title'  => 'Mandatory',
        'steps_mand_desc'   => 'This step must be done before the ticket is closed. See <em>Mandatory steps and closing</em> below for what FreeITSM does about it.',
        'steps_input_title' => 'Ask for a value',
        'steps_input_desc'  => 'The step asks the analyst to type something when they tick it - an asset tag, a serial number, a reference. Set the prompt so it is obvious what is wanted. The answer is stored against that ticket\'s step and appears beside it.',
        'steps_desc_title' => 'Step description',
        'steps_desc_desc' => 'Each step can carry a short description - where to look, what good looks like - shown under the step on the ticket or task. Optional; leave it empty for a step that speaks for itself.',
        'steps_result_title' => 'Ask for a result',
        'steps_result_desc' => 'Tick <strong>Ask for a result</strong> and the step is answered with <strong>Pass</strong>, <strong>Fail</strong> or <strong>Not applicable</strong> instead of a tick - on tickets and on tasks alike. Fail asks what went wrong, and the answer is kept with the step and noted on the ticket. A failed step still counts as done, so the ticket can close: the failure is on the record rather than hidden. A step with no result yet is outstanding, like an unticked one.',
        'steps_delete'      => 'Removing a step asks first and tells you which one. Deleting a whole template removes its steps with it.',

        // 4 ---------------------------------------------------------------
        'using_heading' => 'Using one on a ticket',
        'using_intro'   => 'Open a ticket and find the <strong>Checklist</strong> panel. Everything below happens on that ticket alone.',
        'using_step1'   => '<strong>Attach a checklist</strong> - search by name or keyword and press <strong>Attach</strong>. More than one can be attached to the same ticket. Or, without opening it, <strong>right-click the ticket</strong> in the list, point to <strong>Attach checklist</strong> and pick one - best matches first, with <strong>Search all checklists…</strong> for the rest. Right-click a selection to attach it to every selected ticket at once.',
        'using_step2'   => '<strong>Best match</strong> - if a template\'s keywords match the ticket, FreeITSM offers it at the top without being asked. This is what the keywords are for.',
        'using_step3'   => '<strong>Tick steps off</strong> as you do them. Each tick records who and when; a step set to ask for a value prompts for it as you tick it.',
        'using_step4'   => '<strong>A note is added to the ticket</strong> each time a step is completed or reopened, so the ticket history tells the story on its own - useful months later, and to anybody auditing the work.',
        'using_step5'   => '<strong>Remove</strong> takes a checklist off the ticket. It asks first, because the ticks go with it.',

        // 5 ---------------------------------------------------------------
        'closing_heading' => 'Mandatory steps and closing a ticket',
        'closing_intro'   => 'A mandatory step is only worth marking if something happens when it is skipped. Each checklist template decides its own gate, while administrators can enforce company-wide rules in <strong>Tickets &rarr; Settings &rarr; Checklists</strong>.',
        'closing_warn_title'  => 'Standard (Warn & audit)',
        'closing_warn_desc'   => 'The analyst is prompted with a warning listing the skipped mandatory steps. If they confirm closure, an internal timeline note records which steps were skipped, who closed it, and whether it was closed from the web inbox, bulk actions, workflow, or API.',
        'closing_block_title' => 'Critical (Block closure)',
        'closing_block_desc'  => 'The ticket cannot be closed until all mandatory checklist steps are completed. Choose this for regulated procedures, leaver offboarding where access could remain live, or critical infrastructure changes.',
        'closing_empty_title' => 'Closing with no checklist at all',
        'closing_empty_desc'  => 'Separately, <strong>Tickets &rarr; Settings &rarr; Checklists</strong> decides what happens when a ticket has no checklist attached: allow it (the default), warn and record an audit note, or refuse the close until one is attached. Most desks should leave this alone - a password reset needs no checklist, and insisting on one adds friction to the tickets that least deserve it.',
        'closing_enforced'    => '<strong>Whichever gate is active is enforced everywhere a ticket can be closed</strong>, not just on the button in front of you: bulk actions, the REST API and workflow automation all obey it. If a ticket carries both a standard and a critical checklist, the strictest gate wins. A rule that only lives in one screen is not a rule, and a gate that can be walked around reports control that is not there.',

        // 6 ---------------------------------------------------------------
        'settings_heading' => 'Settings',
        'settings_intro'   => 'Two screens matter, and they do different jobs.',
        'settings_mod_title'  => 'Checklists &rarr; Settings',
        'settings_mod_desc'   => '<strong>Categories</strong> group templates and colour their pills. <strong>Roles</strong> fill the "suggested role" list on a step. Both show how many steps or templates use an entry before you delete it. <strong>Left panel</strong> is a per-account preference: keep the template sidebar always visible, or let it appear on hover.',
        'settings_tick_title' => 'Tickets &rarr; Settings &rarr; Checklists',
        'settings_tick_desc'  => 'Configure company-wide checklist close gates: follow each template\'s gate (Standard vs Critical) or block all tickets with outstanding steps. Also controls enforcement when closing a ticket with no attached checklist (Allow, Warn, or Block).',
        'settings_demo'       => '<strong>System &rarr; Demo data</strong> will seed five realistic checklists - onboarding, offboarding, server decommissioning, VPN troubleshooting and a firewall change - so you can see the module working before writing anything. Removing the demo data removes exactly those and nothing you wrote.',

        // 7 ---------------------------------------------------------------
        'tips_heading' => 'Tips',
        'tip_one_action_title' => 'One action per step',
        'tip_one_action_body'  => 'If a step cannot be answered yes or no, it is two steps. This is the single thing that decides whether a checklist gets used or ignored.',
        'tip_keywords_title'   => 'Spend a minute on keywords',
        'tip_keywords_body'    => 'A checklist nobody can find is a checklist nobody follows. Add the words a requester would use, not the words you would - "cannot get in", not "authentication failure".',
        'tip_mandatory_title'  => 'Be sparing with mandatory',
        'tip_mandatory_body'   => 'Mark everything mandatory and people learn to close through the warning without reading it. Mark the three that matter and the warning still means something.',
        'tip_edit_title'       => 'Improve the template, not the ticket',
        'tip_edit_body'        => 'When somebody finds a missing step mid-job, add it to the template as well. Attached checklists are copies, so the ticket in front of you keeps its steps and every future ticket gets the better procedure.',
    ],
];
