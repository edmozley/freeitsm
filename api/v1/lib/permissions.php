<?php
/**
 * FreeITSM REST API v1 — permission catalog.
 *
 * The single source of truth for every permission an API key can carry, used
 * by three consumers: the v1 auth layer (enforcement), the System > API admin
 * page (the checkbox matrix when creating/editing a key), and the docs page
 * (each endpoint states which permission it needs).
 *
 * A key's permissions are stored on api_keys.permissions as JSON:
 *   { "tickets": ["read","create"], "ticket_notes": ["read"] }
 * Anything not present is denied — keys start from zero.
 */

function apiV1PermissionCatalog(): array {
    return [
        'tickets' => [
            'label'   => 'Tickets',
            'actions' => [
                'read'    => 'List and view tickets (including filters and search)',
                'create'  => 'Create new tickets',
                'update'  => 'Update ticket fields (status, priority, assignment, company, …)',
                'delete'  => 'Move tickets to the trash (soft delete)',
                'restore' => 'Restore tickets from the trash',
            ],
        ],
        'ticket_notes' => [
            'label'   => 'Ticket notes',
            'actions' => [
                'read'   => 'Read the notes on a ticket',
                'create' => 'Add a note to a ticket (internal or public)',
            ],
        ],
        'ticket_thread' => [
            'label'   => 'Ticket conversation',
            'actions' => [
                'read' => 'Read the message thread on a ticket (emails / channel messages)',
            ],
        ],
        'ticket_audit' => [
            'label'   => 'Ticket audit log',
            'actions' => [
                'read' => 'Read the change history of a ticket',
            ],
        ],
        'ticket_sla' => [
            'label'   => 'Ticket SLA',
            'actions' => [
                'read' => 'Read the live SLA state of a ticket (response/resolution targets, breaches)',
            ],
        ],
        'ticket_time_entries' => [
            'label'   => 'Time entries',
            'actions' => [
                'read'   => 'Read the time logged against a ticket',
                'create' => 'Log time against a ticket',
                'delete' => 'Remove a time entry (soft delete)',
            ],
        ],
        'assets' => [
            'label'   => 'Assets',
            'actions' => [
                'read'   => 'List and view assets (including hardware, lifecycle and warranty filters)',
                'create' => 'Create new assets',
                'update' => 'Update asset fields (classification, lifecycle, hardware)',
            ],
        ],
        'asset_assignments' => [
            'label'   => 'Asset assignments',
            'actions' => [
                'read'   => 'See who an asset is assigned to',
                'create' => 'Assign an asset to a requester (check out)',
                'delete' => 'Unassign an asset from a requester (check in)',
            ],
        ],
        'asset_history' => [
            'label'   => 'Asset history',
            'actions' => [
                'read' => 'Read an asset\'s change history and custody (check-out/in) log',
            ],
        ],
        'asset_inventory' => [
            'label'   => 'Asset inventory',
            'actions' => [
                'read' => 'Read agent-collected inventory: disks, network adapters, devices, software',
            ],
        ],
        'problems' => [
            'label'   => 'Problems',
            'actions' => [
                'read'   => 'List and view problems (including linked incidents and changes)',
                'create' => 'Create new problems',
                'update' => 'Update problem fields (status, priority, RCA, known-error flag, …)',
                'delete' => 'Permanently delete a problem and its links/notes/history',
            ],
        ],
        'problem_notes' => [
            'label'   => 'Problem notes',
            'actions' => [
                'read'   => 'Read a problem\'s journal',
                'create' => 'Add a journal note (append-only)',
            ],
        ],
        'problem_audit' => [
            'label'   => 'Problem audit log',
            'actions' => [
                'read' => 'Read the change history of a problem',
            ],
        ],
        'problem_links' => [
            'label'   => 'Problem links',
            'actions' => [
                'create' => 'Link incidents (tickets) and changes to a problem',
                'delete' => 'Unlink incidents and changes from a problem',
            ],
        ],
        'changes' => [
            'label'   => 'Changes',
            'actions' => [
                'read'   => 'List and view changes (including risk, schedule, PIR, attachments list)',
                'create' => 'Create new changes',
                'update' => 'Update change fields (status, schedule, risk, plans, CAB settings, …)',
                'delete' => 'Permanently delete a change and its attachments/comments/history',
            ],
        ],
        'change_comments' => [
            'label'   => 'Change comments',
            'actions' => [
                'read'   => 'Read the comments on a change',
                'create' => 'Add a comment to a change',
                'delete' => 'Delete a comment from a change',
            ],
        ],
        'change_audit' => [
            'label'   => 'Change audit log',
            'actions' => [
                'read' => 'Read the change history of a change record',
            ],
        ],
        'change_cab' => [
            'label'   => 'Change CAB',
            'actions' => [
                'read'   => 'See the CAB roster, votes and approval progress',
                'manage' => 'Set the CAB member roster for a change',
                'vote'   => 'Cast a CAB vote as the analyst this key acts as',
            ],
        ],
        'knowledge' => [
            'label'   => 'Knowledge base',
            'actions' => [
                'read'    => 'List, search and read articles (including the recycle bin and review filters)',
                'create'  => 'Create articles (published immediately, like the UI)',
                'update'  => 'Update articles, tags and review dates (optionally saving a version snapshot)',
                'delete'  => 'Move articles to the recycle bin',
                'restore' => 'Restore articles from the recycle bin',
                'purge'   => 'Permanently delete an archived article',
            ],
        ],
        'knowledge_versions' => [
            'label'   => 'Article versions',
            'actions' => [
                'read' => 'Read an article\'s version history and snapshots',
            ],
        ],
        'users' => [
            'label'   => 'Requesters',
            'actions' => [
                'read'   => 'List and view requesters (end users)',
                'create' => 'Create requesters',
                'update' => 'Update a requester\'s details',
            ],
        ],
        'analysts' => [
            'label'   => 'Analysts',
            'actions' => [
                'read' => 'List analysts (for assignment lookups)',
            ],
        ],
        'companies' => [
            'label'   => 'Companies',
            'actions' => [
                'read' => 'List the companies this key can see',
            ],
        ],
        'reference' => [
            'label'   => 'Reference data',
            'actions' => [
                'read' => 'Read lookups: ticket statuses/priorities/types/origins, departments, asset types/statuses/locations, suppliers, problem statuses/priorities, change statuses/types/priorities/impacts/categories, knowledge tags',
            ],
        ],
    ];
}

/**
 * Validate + normalise a raw permissions structure (from the admin UI or a
 * stored JSON blob) against the catalog. Unknown resources/actions are
 * dropped; the result is always ['resource' => ['action', ...]].
 */
function apiV1NormalisePermissions($raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $catalog = apiV1PermissionCatalog();
    $clean = [];
    foreach ($raw as $resource => $actions) {
        if (!isset($catalog[$resource]) || !is_array($actions)) {
            continue;
        }
        $valid = array_values(array_intersect(
            array_map('strval', $actions),
            array_keys($catalog[$resource]['actions'])
        ));
        if ($valid) {
            $clean[$resource] = $valid;
        }
    }
    return $clean;
}
