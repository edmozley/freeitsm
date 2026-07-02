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
                'read' => 'Read statuses, priorities, ticket types, origins and departments',
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
