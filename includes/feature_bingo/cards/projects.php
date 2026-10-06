<?php
/**
 * Feature Bingo cards - Projects (3.2.0). See includes/feature_bingo.php for the format.
 *
 * Projects needs no switching on, so these cards light as the module is used:
 * a first project, a plan split into phases, work filed under it, and the
 * project joined to the records it touches.
 */

return [
    [
        'id'       => 'projects.first',
        'module'   => 'projects',
        'tier'     => 'recommended',
        'category' => 'organisation',
        'title'    => 'Run a project',
        'what'     => 'A project gathers the tasks behind a bigger piece of work - an office move, a laptop refresh, a migration - with a goal, dates and a project manager.',
        'why'      => 'The portfolio shows every project\'s progress and health at a glance, worked out from its tasks rather than from somebody\'s update.',
        'done'     => 'At least one project exists.',
        'link'     => 'projects/',
        'check'    => ['rows', 'projects'],
    ],
    [
        'id'       => 'projects.plan',
        'module'   => 'projects',
        'tier'     => 'extra',
        'category' => 'productivity',
        'title'    => 'A plan in phases',
        'what'     => 'A project split into phases, stages or sprints, each with its own tasks and dates. Run it Simple, Staged (PRINCE2-style) or Agile, and change your mind later.',
        'why'      => 'Everyone can see which part of the work is happening now, and the ring shows how far along each phase is.',
        'done'     => 'At least one project has a phase, stage or sprint with a task in it.',
        'link'     => 'projects/',
        'check'    => ['sql', 'SELECT COUNT(*) FROM tasks t JOIN project_stages s ON s.id = t.project_stage_id'],
    ],
    [
        'id'       => 'projects.connections',
        'module'   => 'projects',
        'tier'     => 'extra',
        'category' => 'organisation',
        'title'    => 'Projects joined to everything else',
        'what'     => 'A project\'s Connections tab links it to the equipment, changes, tickets, contracts, configuration items and knowledge articles it touches.',
        'why'      => 'The changes a project raises, the tickets it causes and the kit it works on sit on one page instead of being hunted for across modules.',
        'done'     => 'At least one project is linked to another record.',
        'link'     => 'projects/',
        'check'    => ['any', [
            ['rows', 'project_assets'], ['rows', 'project_changes'], ['rows', 'project_tickets'],
            ['rows', 'project_contracts'], ['rows', 'project_cmdb_objects'], ['rows', 'project_knowledge_articles'],
        ]],
    ],
];
