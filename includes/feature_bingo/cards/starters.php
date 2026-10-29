<?php
/**
 * Feature Bingo cards - Starters & leavers (4.6.0). See includes/feature_bingo.php for the format.
 *
 * The module is built from Tasks, Assets, Users and Workflows, so most of what
 * it can do lights other modules' cards too; these are the ones only it has.
 */

return [
    [
        'id'       => 'starters.templates',
        'module'   => 'starters',
        'tier'     => 'recommended',
        'category' => 'getting-started',
        'title'    => 'A template for starters or leavers',
        'what'     => 'Starters & leavers -> Settings -> Templates: the steps for a kind of change, each given to a team or analyst and due a number of days before or after the date.',
        'why'      => 'Every case opened from it gets the same steps as tasks, on the right days, so nothing is forgotten when someone joins or leaves.',
        'done'     => 'At least one template has a step.',
        'link'     => 'starters/settings/',
        'check'    => ['rows', 'jml_template_steps', '1 = 1'],
    ],
    [
        'id'       => 'starters.cases',
        'module'   => 'starters',
        'tier'     => 'recommended',
        'category' => 'organisation',
        'title'    => 'Joiners and leavers tracked as cases',
        'what'     => 'A case for each person joining, moving or leaving, with its steps in Tasks and their kit issued and recovered through Assets.',
        'why'      => 'One page shows what is done and what is not for each person, and their People page keeps the history.',
        'done'     => 'At least one case has been opened.',
        'link'     => 'starters/',
        'check'    => ['rows', 'jml_cases', '1 = 1'],
    ],
    [
        'id'       => 'starters.checklists',
        'module'   => 'starters',
        'tier'     => 'extra',
        'category' => 'productivity',
        'title'    => 'Checklists on starter and leaver steps',
        'what'     => 'A template step that carries a checklist from Tasks - "Build the laptop" with every item to tick.',
        'why'      => 'A step with several parts is done the same way every time, by whoever picks it up.',
        'done'     => 'At least one template step has a checklist.',
        'link'     => 'starters/settings/',
        'check'    => ['rows', 'jml_template_steps', 'checklist_template_id IS NOT NULL'],
    ],
    [
        'id'       => 'starters.workflow',
        'module'   => 'starters',
        'tier'     => 'extra',
        'category' => 'automation',
        'title'    => 'Automation when a case opens or closes',
        'what'     => 'A workflow listening for a starter, mover or leaver case being opened or marked done - to email HR, post to chat or raise a ticket.',
        'why'      => 'The people outside IT hear about a joiner or leaver without anybody remembering to tell them.',
        'done'     => 'An active workflow is triggered by a Starters & leavers event.',
        'link'     => 'workflow/',
        'check'    => ['rows', 'workflows', "is_active = 1 AND is_demo = 0 AND trigger_event LIKE 'jml.%'"],
    ],
];
