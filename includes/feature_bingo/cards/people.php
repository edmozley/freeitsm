<?php
/**
 * Feature Bingo cards - People (#153 step 2). See includes/feature_bingo.php for the format.
 *
 * People is a view over Users, so there is nothing to switch on: these cards
 * light when the details its pages are built from have been filled in.
 */

return [
    [
        'id'       => 'people.companies',
        'module'   => 'people',
        'tier'     => 'recommended',
        'category' => 'organisation',
        'title'    => 'People grouped by company',
        'what'     => 'Each person in Users belongs to a company, so People -> Companies shows a card per company with its people, open tickets and equipment.',
        'why'      => 'One page per customer or department shows everything about them at once, instead of searching each module in turn.',
        'done'     => 'At least five people belong to a company.',
        'link'     => 'people/companies.php',
        'check'    => ['rows', 'users', 'tenant_id IS NOT NULL', 5],
    ],
    [
        'id'       => 'people.managers',
        'module'   => 'people',
        'tier'     => 'extra',
        'category' => 'organisation',
        'title'    => 'Who reports to whom',
        'what'     => 'A manager recorded against people - by hand in Users or filled in by directory sync - shown on each person\'s page and drawn as an organisation chart under People -> Organisation (4.8.0, PR #242), with the gaps: leavers who still manage people, and people with no manager.',
        'why'      => 'You can see who to chase about an approval or a leaver, everybody under a director, and where the directory\'s reporting lines have gone stale.',
        'done'     => 'At least one person has a manager.',
        'link'     => 'people/org.php',
        'check'    => ['rows', 'users', 'manager_id IS NOT NULL'],
    ],
    [
        'id'       => 'people.job_details',
        'module'   => 'people',
        'tier'     => 'extra',
        'category' => 'organisation',
        'title'    => 'Job titles and departments',
        'what'     => 'Job title and department filled in for your people, by hand or by directory sync (LDAP, Entra or SSO profile sync).',
        'why'      => 'A person\'s page and the People list tell you who someone is at a glance, not just their email address.',
        'done'     => 'At least five people have a job title or a department.',
        'link'     => 'people/',
        'check'    => ['rows', 'users', "(job_title IS NOT NULL AND job_title <> '') OR (department IS NOT NULL AND department <> '')", 5],
    ],
];
