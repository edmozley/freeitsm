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
        'tasks' => [
            'label'   => 'Tasks',
            'actions' => [
                'read'   => 'List and view tasks (board order, subtasks, links, comments inline)',
                'create' => 'Create tasks and subtasks',
                'update' => 'Update task fields, move cards on the board, replace tags',
                'delete' => 'Permanently delete a task (subtasks and comments go with it)',
            ],
        ],
        'task_comments' => [
            'label'   => 'Task comments',
            'actions' => [
                'read'   => 'Read the comments on a task',
                'create' => 'Add a comment to a task',
            ],
        ],
        'cmdb_classes' => [
            'label'   => 'CMDB classes',
            'actions' => [
                'read' => 'Read class definitions, their typed properties and dropdown options',
            ],
        ],
        'cmdb_objects' => [
            'label'   => 'CMDB objects',
            'actions' => [
                'read'   => 'List, search and view configuration items (including impact analysis)',
                'create' => 'Create configuration items (typed properties validated)',
                'update' => 'Update configuration items and their property values',
                'delete' => 'Permanently delete a configuration item and its descendant tree',
            ],
        ],
        'cmdb_relationships' => [
            'label'   => 'CMDB relationships',
            'actions' => [
                'create' => 'Link two configuration items (depends on, connects to, …)',
                'delete' => 'Remove a relationship between configuration items',
            ],
        ],
        'cmdb_ticket_links' => [
            'label'   => 'CMDB ticket links',
            'actions' => [
                'read'   => 'See the tickets linked to a configuration item (company-scoped)',
                'create' => 'Link a ticket to a configuration item',
                'delete' => 'Unlink a ticket from a configuration item',
            ],
        ],
        'contracts' => [
            'label'   => 'Contracts',
            'actions' => [
                'read'   => 'List and view contracts (including renewal/expiry and notice-period filters)',
                'create' => 'Create contracts',
                'update' => 'Update contract fields (dates, value, supplier, governance, active flag)',
                'delete' => 'Permanently delete a contract and its term values',
            ],
        ],
        'contract_terms' => [
            'label'   => 'Contract terms',
            'actions' => [
                'read'   => 'Read a contract\'s term-tab contents',
                'update' => 'Write a contract\'s term-tab contents (per-tab upsert)',
            ],
        ],
        'suppliers' => [
            'label'   => 'Suppliers',
            'actions' => [
                'read'   => 'View full supplier records (address, registration, questionnaire, contacts)',
                'create' => 'Create suppliers',
                'update' => 'Update supplier records (including the supplies-assets flag)',
                'delete' => 'Delete a supplier (contracts/contacts/assets keep their rows, unlinked)',
            ],
        ],
        'supplier_contacts' => [
            'label'   => 'Supplier contacts',
            'actions' => [
                'read'   => 'List a supplier\'s contacts',
                'create' => 'Add contacts to a supplier',
                'update' => 'Update a supplier\'s contacts',
                'delete' => 'Remove a supplier\'s contacts',
            ],
        ],
        'calendar_events' => [
            'label'   => 'Calendar events',
            'actions' => [
                'read'   => 'List and view team-calendar events (including generated warranty events)',
                'create' => 'Create calendar events',
                'update' => 'Update manual calendar events (generated events are read-only)',
                'delete' => 'Delete manual calendar events (generated events are read-only)',
            ],
        ],
        'software_inventory' => [
            'label'   => 'Software inventory',
            'actions' => [
                'read' => 'List applications with install counts, and the machines each is installed on (agent-owned, read-only)',
            ],
        ],
        'software_licences' => [
            'label'   => 'Software licences',
            'actions' => [
                'read'   => 'List and view licences (including compliance install counts and renewal status)',
                'create' => 'Create licences',
                'update' => 'Update licences',
                'delete' => 'Delete licences',
            ],
        ],
        'services' => [
            'label'   => 'Status services',
            'actions' => [
                'read'   => 'The service health board (derived live status per service) and service records',
                'create' => 'Add services to the status board',
                'update' => 'Update service records',
                'delete' => 'Delete a service (its incident links go with it)',
            ],
        ],
        'service_incidents' => [
            'label'   => 'Status incidents',
            'actions' => [
                'read'   => 'List and view status incidents with affected services and impacts',
                'create' => 'Open incidents (with per-service impact levels)',
                'update' => 'Update incidents — status, comment, affected services; resolving stamps resolved_at',
                'delete' => 'Delete an incident and its service links',
            ],
        ],
        'morning_checks' => [
            'label'   => 'Morning checks',
            'actions' => [
                'read'   => 'List and view check definitions',
                'create' => 'Add checks',
                'update' => 'Update checks (name, description, order, active)',
                'delete' => 'Delete a check and all its historical results',
            ],
        ],
        'morning_check_results' => [
            'label'   => 'Morning check results',
            'actions' => [
                'read'   => 'The day board (every active check with its result for a date) and result history',
                'record' => 'Record a result — one per check per day, overwriting any earlier result for that day',
            ],
        ],
        'forms' => [
            'label'   => 'Forms',
            'actions' => [
                'read'   => 'List and view forms with their fields and version chains',
                'create' => 'Create forms and fork new versions',
                'update' => 'Update the current version in place (title, description, fields, active flag)',
                'delete' => 'Delete a form version (or a whole chain with its submissions)',
            ],
        ],
        'form_submissions' => [
            'label'   => 'Form submissions',
            'actions' => [
                'read'   => 'List and view submissions with their answers',
                'create' => 'Submit a form (validation + the form.submitted workflow event, like the UI)',
                'delete' => 'Delete a submission and its answers',
            ],
        ],
        'workflows' => [
            'label'   => 'Workflows',
            'actions' => [
                'read'   => 'List and view workflow definitions (trigger, conditions, actions, run stats)',
                'create' => 'Create workflows — powerful: actions run with engine privileges (tickets, email)',
                'update' => 'Update workflows (rule bodies validated against the engine catalogues)',
                'delete' => 'Delete a workflow (its execution history survives, detached)',
                'fire'   => 'Manually fire a workflow with a synthetic payload (like the editor\'s Test fire)',
            ],
        ],
        'workflow_executions' => [
            'label'   => 'Workflow executions',
            'actions' => [
                'read' => 'Read the engine\'s run history: status, payload snapshot, per-step log',
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
                'read' => 'Read lookups: ticket statuses/priorities/types/origins, departments, asset types/statuses/locations, suppliers (lite list), problem statuses/priorities, change statuses/types/priorities/impacts/categories, knowledge tags, task statuses/priorities/tags, CMDB relationship types, contract statuses/term tabs, payment schedules, supplier types/statuses, calendar categories, service incident statuses / impact levels, morning-check statuses, workflow triggers/actions catalogues',
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
