<?php
/**
 * Workflow Engine
 * ----------------------------------------------------------------------
 *
 * Cross-module automation. Other modules CALL into this engine when their
 * events happen (e.g. tickets/save.php calls WorkflowEngine::dispatch(
 * 'ticket.created', ['ticket' => $row]) after a successful insert). The
 * engine finds active workflows matching the event, evaluates conditions
 * against the payload, executes actions in order, and writes a row to
 * workflow_executions for every run so the user can see what happened.
 *
 * The contract is deliberately small in v1:
 *
 *   - Triggers are flat event-name strings. The catalogue lives in
 *     availableTriggers(); new ones are added here and wired in their
 *     module's save flow.
 *
 *   - Conditions are an array of {field, op, value} objects. The engine
 *     reads `field` from the payload via dot-notation (`ticket.priority`).
 *     All conditions must match (AND). If empty, the workflow always fires.
 *
 *   - Actions are an array of {type, args} objects executed in order.
 *     The catalogue lives in availableActions(); the handlers live as
 *     private methods on this class so each action's contract is in one
 *     place.
 *
 *   - Execution is synchronous in v1. Each event triggers a same-request
 *     pass through the engine. An async queue + retries are planned but
 *     not built — keep actions fast and idempotent.
 *
 * Failures don't propagate. The engine catches per-step exceptions, logs
 * them in the execution row, and continues. The point is that a buggy
 * workflow can't take down the host module's request.
 */
class WorkflowEngine
{
    // -----------------------------------------------------------------
    //  Infinite-loop protection (request-scoped)
    // -----------------------------------------------------------------
    //
    // Workflow actions mutate host data, and host modules dispatch events
    // from their save flows — so a workflow can trigger itself, either
    // directly (A fires an event that re-runs A) or through a cycle
    // (A -> B -> A). Execution is synchronous within one PHP request, so we
    // guard with three request-scoped counters reset naturally per request:
    //
    //   1. $activeWorkflowIds — the stack of workflow ids currently running.
    //      A workflow already on the stack is refused re-entry. This alone
    //      catches every cycle: a cycle must revisit a node still on the
    //      stack.
    //   2. MAX_CHAIN_DEPTH — a hard ceiling on nesting depth. Backstop for an
    //      absurdly deep but acyclic cascade (A -> B -> C -> ... all distinct).
    //   3. MAX_RUNS_PER_REQUEST — a ceiling on total runs in one request.
    //      Backstop for fan-out blow-ups where each run spawns new, distinct
    //      workflows without ever cycling.
    //
    // A blocked run is still recorded as an 'aborted' execution row so the
    // user can see the engine stepped in (see recordAbortedRun()).

    private const MAX_CHAIN_DEPTH       = 10;
    private const MAX_RUNS_PER_REQUEST  = 100;

    private static $chainDepth       = 0;
    private static $runsThisRequest  = 0;
    private static $activeWorkflowIds = [];

    // The workflow + execution currently running, so async actions (webhooks)
    // can stamp their queue rows with the source. Set per run in runInner().
    private static $ctxWorkflowId    = null;
    private static $ctxExecutionId   = null;

    // -----------------------------------------------------------------
    //  Catalogue — triggers, actions, operators, fields per trigger
    // -----------------------------------------------------------------

    /**
     * Events the engine knows about. Display labels are i18n-ready strings
     * but kept plain English here for simplicity in v1 — the editor UI
     * shows them as-is. Adding a trigger = one entry here + a single
     * dispatch() call from the corresponding module's save flow.
     *
     * All seven events are now wired from their host modules:
     *   ticket.*          — api/tickets/create_ticket.php + assign_ticket.php
     *   form.submitted    — api/forms/submit_form.php (after commit)
     *   task.completed    — api/tasks/save.php (on transition into a closed status)
     *   change.approved   — api/change-management/submit_cab_vote.php (CAB threshold)
     *                       + save.php (manual status edit to Approved)
     */
    public static function availableTriggers(): array
    {
        return [
            'ticket.created'           => 'A ticket is created',
            'ticket.status_changed'    => 'A ticket\'s status changes',
            'ticket.priority_changed'  => 'A ticket\'s priority changes',
            'ticket.assigned'          => 'A ticket is assigned to an analyst',
            'form.submitted'           => 'A form submission is received',
            'task.completed'           => 'A task is marked complete',
            'change.approved'          => 'A change request is approved',
        ];
    }

    /**
     * Per-trigger field hints — the dotted paths into the payload that
     * are valid for `condition.field`. Used to populate the editor's
     * field dropdown so users aren't guessing.
     */
    public static function availableFields(string $trigger): array
    {
        // The full canonical ticket payload that every ticket.* event ships
        // (see api/tickets/assign_ticket.php + create_ticket.php). Listed
        // once and reused so a condition on, say, ticket.priority_id works
        // for *any* ticket trigger — not just the priority_changed one.
        $fullTicket = [
            'ticket.id', 'ticket.subject', 'ticket.priority_id', 'ticket.status_id',
            'ticket.department_id', 'ticket.type_id', 'ticket.assigned_analyst_id',
            'ticket.owner_id', 'ticket.origin_id', 'ticket.created_by',
            'ticket.requester_email',
        ];
        $byTrigger = [
            'ticket.created'          => $fullTicket,
            'ticket.status_changed'   => array_merge($fullTicket, ['old_status_id', 'new_status_id']),
            'ticket.priority_changed' => array_merge($fullTicket, ['old_priority_id', 'new_priority_id']),
            'ticket.assigned'         => array_merge($fullTicket, ['analyst_id', 'team_id']),
            'form.submitted' => [
                'form.id', 'form.name', 'submission.id', 'submission.email',
            ],
            'task.completed' => [
                'task.id', 'task.title', 'task.priority_id', 'task.assignee_id',
            ],
            'change.approved' => [
                'change.id', 'change.title', 'change.risk', 'approver.id',
            ],
        ];
        return $byTrigger[$trigger] ?? [];
    }

    public static function availableOperators(): array
    {
        return [
            'equals'       => 'equals',
            'not_equals'   => 'does not equal',
            'in'           => 'is one of',          // OR across a list of values
            'not_in'       => 'is not one of',
            'contains'     => 'contains',
            'not_contains' => 'does not contain',
            'gt'           => 'greater than',
            'lt'           => 'less than',
            'is_empty'     => 'is empty',
            'is_not_empty' => 'is not empty',
        ];
    }

    /**
     * Lookup-table map for normalised id fields. When a condition is built
     * against one of these field paths, the editor offers a dropdown / multi-
     * select of real values from the joined table instead of asking the
     * user to type opaque ids. Each entry: which table to read, which
     * column holds the human-readable label, and optional `where` / `order`
     * for sensible defaults (active-only, sorted).
     *
     * Tables are interpolated into a SELECT statement — the map is a code-
     * level const so there's no untrusted input here, but if you ever expose
     * a mechanism to add entries dynamically, switch to a whitelist instead
     * of string concatenation.
     */
    private const FIELD_LOOKUP_TABLES = [
        'ticket.priority_id'         => ['table' => 'ticket_priorities', 'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'display_order, name'],
        'ticket.status_id'           => ['table' => 'ticket_statuses',   'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'display_order, name'],
        'ticket.department_id'       => ['table' => 'departments',       'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'name'],
        'ticket.type_id'             => ['table' => 'ticket_types',      'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'name'],
        'ticket.assigned_analyst_id' => ['table' => 'analysts',          'label_col' => 'full_name', 'where' => 'is_active = 1', 'order' => 'full_name'],
        'ticket.created_by'          => ['table' => 'analysts',          'label_col' => 'full_name', 'where' => 'is_active = 1', 'order' => 'full_name'],
        'old_status_id'              => ['table' => 'ticket_statuses',   'label_col' => 'name',      'order' => 'display_order, name'],
        'new_status_id'              => ['table' => 'ticket_statuses',   'label_col' => 'name',      'order' => 'display_order, name'],
        'old_priority_id'            => ['table' => 'ticket_priorities', 'label_col' => 'name',      'order' => 'display_order, name'],
        'new_priority_id'            => ['table' => 'ticket_priorities', 'label_col' => 'name',      'order' => 'display_order, name'],
        'analyst_id'                 => ['table' => 'analysts',          'label_col' => 'full_name', 'where' => 'is_active = 1', 'order' => 'full_name'],
        'team_id'                    => ['table' => 'teams',             'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'name'],
        'task.priority_id'           => ['table' => 'task_priorities',   'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'display_order, name'],
        'task.status_id'             => ['table' => 'task_statuses',     'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'display_order, name'],
        'task.assignee_id'           => ['table' => 'analysts',          'label_col' => 'full_name', 'where' => 'is_active = 1', 'order' => 'full_name'],
        'form.id'                    => ['table' => 'forms',             'label_col' => 'name',      'order' => 'name'],
        'approver.id'                => ['table' => 'analysts',          'label_col' => 'full_name', 'where' => 'is_active = 1', 'order' => 'full_name'],
    ];

    /**
     * Field-type catalogue for the non-lookup fields. Drives which operators
     * the editor offers per field. Lookups are detected via FIELD_LOOKUP_TABLES
     * and always considered type 'lookup' — they don't need an entry here.
     *
     * 'numeric' fields offer equals/not_equals/in/not_in/gt/lt/is_empty/is_not_empty
     *     (no contains — substring search on a number is meaningless).
     * 'text' fields offer equals/not_equals/in/not_in/contains/not_contains/
     *     is_empty/is_not_empty (no gt/lt — lexicographic string comparison
     *     is a footgun for users who'd expect numeric ordering).
     */
    private const FIELD_TYPES = [
        'ticket.id'              => 'numeric',
        'ticket.subject'         => 'text',
        'ticket.requester_email' => 'text',
        'form.name'              => 'text',
        'submission.id'          => 'numeric',
        'submission.email'       => 'text',
        'task.id'                => 'numeric',
        'task.title'             => 'text',
        'change.id'              => 'numeric',
        'change.title'           => 'text',
        'change.risk'            => 'text',
    ];

    /**
     * Resolve the type of a field path. Lookup fields always win; explicit
     * entries in FIELD_TYPES take the next precedence; the default is a
     * mild convention — anything ending in `.id` is treated as numeric,
     * everything else as text. New fields added to availableFields() get
     * a sensible default without needing an explicit FIELD_TYPES entry,
     * but it's the explicit entry that's authoritative.
     */
    public static function fieldType(string $fieldPath): string
    {
        if (isset(self::FIELD_LOOKUP_TABLES[$fieldPath])) return 'lookup';
        if (isset(self::FIELD_TYPES[$fieldPath]))         return self::FIELD_TYPES[$fieldPath];
        // Convention fallback.
        if (preg_match('/(^|\.)id$/', $fieldPath))         return 'numeric';
        return 'text';
    }

    /**
     * Operator slugs valid for a given field type. The full catalogue lives
     * in availableOperators(); this is the per-type subset. Drives which
     * operators the editor shows once the user picks a field. Lookup fields
     * are handled by the editor with their own friendly relabelling (is /
     * is not / is empty / is not empty mapping to in / not_in / is_empty /
     * is_not_empty), so we just return the underlying slugs here.
     */
    public static function operatorsForFieldType(string $type): array
    {
        switch ($type) {
            case 'lookup':
                return ['in', 'not_in', 'is_empty', 'is_not_empty'];
            case 'numeric':
                return ['equals', 'not_equals', 'in', 'not_in', 'gt', 'lt', 'is_empty', 'is_not_empty'];
            case 'text':
            default:
                return ['equals', 'not_equals', 'in', 'not_in', 'contains', 'not_contains', 'is_empty', 'is_not_empty'];
        }
    }

    /**
     * For a normalised id field, return the list of selectable {id, label}
     * pairs from the joined table. Returns null for free-text fields (the
     * editor falls back to a plain text input in that case). Returns null
     * defensively if the lookup table doesn't exist (older installs that
     * haven't migrated yet) so the editor still works.
     */
    public static function availableValuesForField(string $fieldPath): ?array
    {
        if (!isset(self::FIELD_LOOKUP_TABLES[$fieldPath])) return null;
        $spec = self::FIELD_LOOKUP_TABLES[$fieldPath];
        try {
            $conn = connectToDatabase();
            $where = isset($spec['where']) ? ' WHERE ' . $spec['where'] : '';
            $order = isset($spec['order']) ? ' ORDER BY ' . $spec['order'] : '';
            $sql = sprintf(
                'SELECT id AS id, %s AS label FROM `%s`%s%s',
                $spec['label_col'],
                $spec['table'],
                $where,
                $order
            );
            $stmt = $conn->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Stringify ids so the editor's form values (always strings)
            // compare cleanly without type juggling.
            return array_map(
                fn($r) => ['id' => (string)$r['id'], 'label' => (string)$r['label']],
                $rows
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Action handlers available to the engine. Each entry's `args` is the
     * editor UI spec, NOT just a name → type map — it drives the per-action
     * form the user sees when they pick an action from the dropdown, so the
     * editor never has to know about specific action types.
     *
     * Per-arg fields:
     *   - type:          'text' / 'textarea' / 'numeric' / 'bool' / 'lookup'
     *   - label:         human-readable label shown above the input
     *   - required:      whether the engine throws if missing (default false)
     *   - default:       pre-fill value when the action is first added
     *   - supports_vars: arg can reference payload data via {{path.to.field}}
     *                    (drives an inline hint in the UI; the engine
     *                    interpolates these via renderTemplate())
     *   - lookup:        for type='lookup', names a lookup source
     *                    (`ticket_status`, `ticket_priority`, `analyst`,
     *                    `department`, `ticket_type`, `task_status`,
     *                    `task_priority`) — values listed in
     *                    ACTION_ARG_LOOKUPS below
     */
    public static function availableActions(): array
    {
        $ticketIdArg = [
            'type'          => 'text',
            'label'         => 'Ticket ID',
            'default'       => '{{ticket.id}}',
            'supports_vars' => true,
            'required'      => true,
        ];
        return [
            'log_message' => [
                'label'       => 'Log a message',
                'description' => 'Write a message into this workflow\'s execution log. Useful as a placeholder while you scaffold a rule and as a test for the engine itself.',
                'args'        => [
                    'message' => ['type' => 'textarea', 'label' => 'Message', 'required' => true, 'supports_vars' => true],
                ],
            ],
            'set_ticket_status' => [
                'label'       => 'Set ticket status',
                'description' => 'Change a ticket\'s status. Mirrors picking from the Status dropdown — automatically sets / clears the closed timestamp.',
                'args'        => [
                    'ticket_id' => $ticketIdArg,
                    'status_id' => ['type' => 'lookup', 'label' => 'New status', 'lookup' => 'ticket_status', 'required' => true],
                ],
            ],
            'set_ticket_priority' => [
                'label'       => 'Set ticket priority',
                'description' => 'Change a ticket\'s priority.',
                'args'        => [
                    'ticket_id'   => $ticketIdArg,
                    'priority_id' => ['type' => 'lookup', 'label' => 'New priority', 'lookup' => 'ticket_priority', 'required' => true],
                ],
            ],
            'assign_ticket' => [
                'label'       => 'Assign ticket to analyst',
                'description' => 'Set a ticket\'s owner / assignee. Use the round-robin or load-balancing logic elsewhere — this just assigns to the chosen analyst.',
                'args'        => [
                    'ticket_id'  => $ticketIdArg,
                    'analyst_id' => ['type' => 'lookup', 'label' => 'Assign to', 'lookup' => 'analyst', 'required' => true],
                ],
            ],
            'add_ticket_note' => [
                'label'       => 'Add a note to the ticket',
                'description' => 'Append a free-text note to the ticket\'s audit trail (visible to analysts; never sent to the requester).',
                'args'        => [
                    'ticket_id' => $ticketIdArg,
                    'note'      => ['type' => 'textarea', 'label' => 'Note', 'required' => true, 'supports_vars' => true],
                ],
            ],
            'send_email' => [
                'label'       => 'Send an email',
                'description' => 'Send an email to the ticket\'s requester using the ticket\'s mailbox. The body is plain-text-with-newlines or HTML; both work.',
                'args'        => [
                    'ticket_id' => $ticketIdArg,
                    'to'        => ['type' => 'text', 'label' => 'To (blank = ticket requester)', 'supports_vars' => true],
                    'subject'   => ['type' => 'text', 'label' => 'Subject', 'required' => true, 'supports_vars' => true],
                    'body'      => ['type' => 'textarea', 'label' => 'Body', 'required' => true, 'supports_vars' => true],
                ],
            ],
            'create_task' => [
                'label'       => 'Create a task',
                'description' => 'Spawn a task. If a ticket is in scope it\'s linked automatically.',
                'args'        => [
                    'title'       => ['type' => 'text', 'label' => 'Title', 'required' => true, 'supports_vars' => true],
                    'description' => ['type' => 'textarea', 'label' => 'Description', 'supports_vars' => true],
                    'status_id'   => ['type' => 'lookup', 'label' => 'Status (blank = default)', 'lookup' => 'task_status'],
                    'priority_id' => ['type' => 'lookup', 'label' => 'Priority (blank = default)', 'lookup' => 'task_priority'],
                    'assignee_id' => ['type' => 'lookup', 'label' => 'Assign to', 'lookup' => 'analyst'],
                    'ticket_id'   => ['type' => 'text', 'label' => 'Linked ticket ID', 'default' => '{{ticket.id}}', 'supports_vars' => true],
                ],
            ],
            'create_ticket' => [
                'label'       => 'Create a ticket',
                'description' => 'Create a new ticket. Useful for fan-out workflows like "new starter form → IT + HR + Facilities tickets".',
                'args'        => [
                    'subject'             => ['type' => 'text', 'label' => 'Subject', 'required' => true, 'supports_vars' => true],
                    'body'                => ['type' => 'textarea', 'label' => 'Body', 'supports_vars' => true],
                    'priority_id'         => ['type' => 'lookup', 'label' => 'Priority', 'lookup' => 'ticket_priority'],
                    'department_id'       => ['type' => 'lookup', 'label' => 'Department', 'lookup' => 'department'],
                    'type_id'             => ['type' => 'lookup', 'label' => 'Ticket type', 'lookup' => 'ticket_type'],
                    'assigned_analyst_id' => ['type' => 'lookup', 'label' => 'Assign to', 'lookup' => 'analyst'],
                    'from_email'          => ['type' => 'text', 'label' => 'Requester email', 'default' => '{{ticket.requester_email}}', 'supports_vars' => true],
                    'from_name'           => ['type' => 'text', 'label' => 'Requester name', 'supports_vars' => true],
                ],
            ],
            'send_webhook' => [
                'label'       => 'Send a webhook',
                'description' => 'POST a message to an external URL when this rule fires — the universal way to push events into Slack, Teams, Discord, PagerDuty, Zapier/Make, or any system that accepts an incoming webhook. Pick a preset for the common chat tools, choose "Full record" to send the entire object (the same JSON as the REST API), or choose "Custom (raw JSON)" and write the exact payload the target expects. Delivery is queued and sent by a background worker with automatic retries, so a slow or dead endpoint never delays anything — track every send under System > Webhooks queue.',
                'args'        => [
                    'preset' => [
                        'type'    => 'select',
                        'label'   => 'Format',
                        'options' => [
                            ['value' => 'custom',  'label' => 'Custom (raw JSON body)'],
                            ['value' => 'full',    'label' => 'Full record (whole object as JSON)'],
                            ['value' => 'slack',   'label' => 'Slack'],
                            ['value' => 'teams',   'label' => 'Microsoft Teams'],
                            ['value' => 'discord', 'label' => 'Discord'],
                        ],
                        'default' => 'custom',
                    ],
                    'url'     => ['type' => 'text', 'label' => 'Webhook URL', 'required' => true, 'supports_vars' => true],
                    'message' => ['type' => 'textarea', 'label' => 'Message', 'supports_vars' => true, 'show_when' => ['preset' => ['slack', 'teams', 'discord']]],
                    'body'    => ['type' => 'textarea', 'label' => 'Raw JSON body', 'supports_vars' => true, 'show_when' => ['preset' => ['custom']], 'default' => "{\n  \"event\": \"{{event}}\",\n  \"ticket_id\": \"{{ticket.id}}\",\n  \"subject\": \"{{ticket.subject}}\"\n}"],
                    'secret'  => ['type' => 'text', 'label' => 'Signing secret (optional)', 'supports_vars' => false],
                ],
            ],
        ];
    }

    /**
     * Action-arg lookup sources. Keys are the `lookup` value used in action
     * arg specs; values name an underlying FIELD_LOOKUP_TABLES entry so
     * action args reuse the same {id, label} feed that conditions use,
     * meaning a new lookup field added for conditions is automatically
     * available to actions too.
     */
    private const ACTION_ARG_LOOKUPS = [
        'ticket_status'   => 'ticket.status_id',
        'ticket_priority' => 'ticket.priority_id',
        'analyst'         => 'ticket.assigned_analyst_id',
        'department'      => 'ticket.department_id',
        'ticket_type'     => 'ticket.type_id',
        'task_status'     => 'task.status_id',
        'task_priority'   => 'task.priority_id',
    ];

    /**
     * Resolve a named action-arg lookup to its {id, label} pairs.
     * Returns null for unknown keys — the editor falls back to a text input
     * in that case so the workflow editor never breaks on a stale spec.
     */
    public static function availableActionLookup(string $key): ?array
    {
        if (!isset(self::ACTION_ARG_LOOKUPS[$key])) return null;
        return self::availableValuesForField(self::ACTION_ARG_LOOKUPS[$key]);
    }

    // -----------------------------------------------------------------
    //  Public entry points
    // -----------------------------------------------------------------

    /**
     * Fire all active workflows matching `$event` with the supplied
     * payload. Called from host modules' save flows. Safe to call even
     * if no workflows exist — does nothing in that case.
     *
     * Errors are swallowed (logged into the workflow_executions row but
     * not re-thrown) so a buggy workflow can never break the host module's
     * request.
     */
    public static function dispatch(string $event, array $payload): void
    {
        try {
            $conn = connectToDatabase();
            $stmt = $conn->prepare(
                "SELECT * FROM workflows WHERE trigger_event = ? AND is_active = 1"
            );
            $stmt->execute([$event]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $wf) {
                self::run($wf, $event, $payload, /*isManual*/ false);
            }
        } catch (Exception $e) {
            // Engine failures must not propagate to host modules.
            error_log('[WorkflowEngine::dispatch] ' . $e->getMessage());
        }
    }

    /**
     * Fire a single workflow with a synthetic payload — used by the
     * editor's "Test fire" button so the user can verify a rule without
     * waiting for a real event.
     *
     * Returns the workflow_executions row as an array (for the API to
     * surface back to the caller).
     */
    public static function manualFire(int $workflowId, array $samplePayload = []): array
    {
        $conn = connectToDatabase();
        $stmt = $conn->prepare("SELECT * FROM workflows WHERE id = ?");
        $stmt->execute([$workflowId]);
        $wf = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wf) {
            throw new Exception('Workflow not found');
        }
        return self::run($wf, $wf['trigger_event'], $samplePayload, /*isManual*/ true);
    }

    // -----------------------------------------------------------------
    //  Core execution loop
    // -----------------------------------------------------------------

    /**
     * Loop-protection wrapper around runInner(). Refuses re-entrant / runaway
     * runs (see the class-level notes), recording a blocked attempt as an
     * 'aborted' execution row, and otherwise manages the request-scoped
     * counters around the real run.
     */
    private static function run(array $wf, string $event, array $payload, bool $isManual): array
    {
        $wfId = (int)$wf['id'];

        if (in_array($wfId, self::$activeWorkflowIds, true)) {
            return self::recordAbortedRun($wf, $event, $payload, $isManual,
                'Loop protection: this workflow is already running in the current event chain — re-entry refused to prevent an infinite loop.');
        }
        if (self::$chainDepth >= self::MAX_CHAIN_DEPTH) {
            return self::recordAbortedRun($wf, $event, $payload, $isManual,
                'Loop protection: workflow chain depth limit (' . self::MAX_CHAIN_DEPTH . ') reached — execution aborted.');
        }
        if (self::$runsThisRequest >= self::MAX_RUNS_PER_REQUEST) {
            return self::recordAbortedRun($wf, $event, $payload, $isManual,
                'Loop protection: per-request workflow run limit (' . self::MAX_RUNS_PER_REQUEST . ') reached — execution aborted.');
        }

        self::$activeWorkflowIds[] = $wfId;
        self::$chainDepth++;
        self::$runsThisRequest++;
        try {
            return self::runInner($wf, $event, $payload, $isManual);
        } finally {
            // Pop this workflow off the active stack and unwind the depth even
            // if runInner threw, so one bad run can't wedge the counters for
            // the rest of the request.
            array_pop(self::$activeWorkflowIds);
            self::$chainDepth--;
        }
    }

    /**
     * Record a run that loop-protection refused to execute. Writes a single
     * 'aborted' execution row (status / step_log / error_message) so the
     * block is visible in the execution audit, and stamps the parent
     * workflow's last-run state for non-manual runs. Never throws.
     */
    private static function recordAbortedRun(array $wf, string $event, array $payload, bool $isManual, string $message): array
    {
        $wfId = (int)$wf['id'];
        error_log('[WorkflowEngine] aborted run for workflow ' . $wfId . ': ' . $message);
        $stepLog = [['kind' => 'loop_protection', 'error' => $message]];
        try {
            $conn = connectToDatabase();
            $stmt = $conn->prepare(
                "INSERT INTO workflow_executions
                 (workflow_id, workflow_name, trigger_event, trigger_payload, status, started_datetime, finished_datetime, step_log, error_message)
                 VALUES (?, ?, ?, ?, 'aborted', UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)"
            );
            $stmt->execute([
                $wfId, $wf['name'] ?? null, $event, json_encode($payload), json_encode($stepLog), $message,
            ]);
            $execId = (int)$conn->lastInsertId();
            // Reflect the block in the parent workflow's last-run state, but
            // don't bump run_count — nothing actually executed.
            if (!$isManual) {
                $conn->prepare(
                    "UPDATE workflows SET last_run_datetime = UTC_TIMESTAMP(), last_run_status = 'aborted' WHERE id = ?"
                )->execute([$wfId]);
            }
        } catch (Exception $e) {
            error_log('[WorkflowEngine::recordAbortedRun] ' . $e->getMessage());
            $execId = null;
        }
        return [
            'execution_id'  => $execId,
            'workflow_id'   => $wfId,
            'status'        => 'aborted',
            'step_log'      => $stepLog,
            'error_message' => $message,
        ];
    }

    private static function runInner(array $wf, string $event, array $payload, bool $isManual): array
    {
        $conn = connectToDatabase();
        $stepLog = [];
        $status  = 'success';
        $errorMessage = null;

        // Insert a "running" execution row so we have an id to update. The
        // workflow name is snapshotted so the run stays attributable after
        // its parent workflow is deleted (workflow_id goes NULL then).
        $insert = $conn->prepare(
            "INSERT INTO workflow_executions
             (workflow_id, workflow_name, trigger_event, trigger_payload, status, started_datetime)
             VALUES (?, ?, ?, ?, 'running', UTC_TIMESTAMP())"
        );
        $insert->execute([
            (int)$wf['id'],
            $wf['name'] ?? null,
            $event,
            json_encode($payload),
        ]);
        $execId = (int)$conn->lastInsertId();
        self::$ctxWorkflowId  = (int)$wf['id'];
        self::$ctxExecutionId = $execId;

        try {
            $conditions = self::decodeJsonField($wf['conditions']);
            $actions    = self::decodeJsonField($wf['actions']);

            // Conditions — AND semantics. If any fails the run is "skipped".
            $conditionsPassed = self::evaluateConditions($conditions, $payload, $stepLog);
            if (!$conditionsPassed) {
                $status = 'skipped';
            } else {
                foreach ($actions as $i => $action) {
                    $type = $action['type'] ?? '';
                    $args = $action['args'] ?? [];
                    try {
                        $result = self::executeAction($type, $args, $payload);
                        $stepLog[] = [
                            'kind'   => 'action',
                            'index'  => $i,
                            'type'   => $type,
                            'status' => 'success',
                            'result' => $result,
                        ];
                    } catch (Exception $e) {
                        $stepLog[] = [
                            'kind'   => 'action',
                            'index'  => $i,
                            'type'   => $type,
                            'status' => 'failed',
                            'error'  => $e->getMessage(),
                        ];
                        $status = 'failed';
                        $errorMessage = "Action {$i} ({$type}): " . $e->getMessage();
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
            $stepLog[] = ['kind' => 'engine_error', 'error' => $e->getMessage()];
        }

        // Update execution row with the final state.
        $update = $conn->prepare(
            "UPDATE workflow_executions
             SET status = ?, finished_datetime = UTC_TIMESTAMP(), step_log = ?, error_message = ?
             WHERE id = ?"
        );
        $update->execute([$status, json_encode($stepLog), $errorMessage, $execId]);

        // Update parent workflow stats — only for non-manual runs, so
        // "Test fire" doesn't pollute the production last-run counters.
        if (!$isManual) {
            $updateWf = $conn->prepare(
                "UPDATE workflows
                 SET last_run_datetime = UTC_TIMESTAMP(),
                     last_run_status = ?,
                     run_count = run_count + 1
                 WHERE id = ?"
            );
            $updateWf->execute([$status, (int)$wf['id']]);
        }

        return [
            'execution_id'   => $execId,
            'workflow_id'    => (int)$wf['id'],
            'status'         => $status,
            'step_log'       => $stepLog,
            'error_message'  => $errorMessage,
        ];
    }

    // -----------------------------------------------------------------
    //  Conditions
    // -----------------------------------------------------------------

    private static function evaluateConditions(array $conditions, array $payload, array &$stepLog): bool
    {
        if (empty($conditions)) {
            return true;
        }
        foreach ($conditions as $i => $cond) {
            $field = $cond['field'] ?? '';
            $op    = $cond['op']    ?? 'equals';
            $value = $cond['value'] ?? null;
            $actual = self::dotGet($payload, $field);
            $passed = self::evaluateOperator($op, $actual, $value);
            $stepLog[] = [
                'kind'   => 'condition',
                'index'  => $i,
                'field'  => $field,
                'op'     => $op,
                'value'  => $value,
                'actual' => $actual,
                'passed' => $passed,
            ];
            if (!$passed) return false;
        }
        return true;
    }

    private static function evaluateOperator(string $op, $actual, $value): bool
    {
        switch ($op) {
            case 'equals':       return self::loose_equal($actual, $value);
            case 'not_equals':   return !self::loose_equal($actual, $value);
            // `in` / `not_in` accept an array of values for OR semantics.
            // Tolerant on the input shape: a single scalar is treated as a
            // 1-element list, and a comma-separated string is split — so
            // older workflows that stored "1,2" as a string still work.
            case 'in':           return self::value_in_list($actual, $value);
            case 'not_in':       return !self::value_in_list($actual, $value);
            case 'contains':     return is_string($actual) && is_string($value) && $value !== '' && strpos($actual, $value) !== false;
            case 'not_contains': return !(is_string($actual) && is_string($value) && $value !== '' && strpos($actual, $value) !== false);
            case 'gt':           return is_numeric($actual) && is_numeric($value) && $actual > $value;
            case 'lt':           return is_numeric($actual) && is_numeric($value) && $actual < $value;
            case 'is_empty':     return $actual === null || $actual === '' || $actual === [];
            case 'is_not_empty': return !($actual === null || $actual === '' || $actual === []);
        }
        return false;
    }

    /**
     * True if `$actual` matches any value in `$list`. `$list` can be an
     * array, a comma-separated string, or a single scalar. Comparison uses
     * loose-equal (string cast both sides) so ids stored as numbers in the
     * payload match the strings the form serialises.
     */
    private static function value_in_list($actual, $list): bool
    {
        if (is_string($list)) {
            // Split comma-separated values; trim whitespace around each.
            $list = array_map('trim', explode(',', $list));
        }
        if (!is_array($list)) {
            $list = [$list];
        }
        foreach ($list as $candidate) {
            if (self::loose_equal($actual, $candidate)) return true;
        }
        return false;
    }

    private static function loose_equal($a, $b): bool
    {
        // Cast both to string for comparison so the editor's typed-in values
        // (always strings from the form) match numeric ids loaded from the DB.
        if ($a === null && ($b === null || $b === '')) return true;
        return (string)$a === (string)$b;
    }

    // -----------------------------------------------------------------
    //  Action handlers
    // -----------------------------------------------------------------

    private static function executeAction(string $type, array $args, array $payload)
    {
        switch ($type) {
            case 'log_message':         return self::action_log_message($args, $payload);
            case 'set_ticket_status':   return self::action_set_ticket_status($args, $payload);
            case 'set_ticket_priority': return self::action_set_ticket_priority($args, $payload);
            case 'assign_ticket':       return self::action_assign_ticket($args, $payload);
            case 'add_ticket_note':     return self::action_add_ticket_note($args, $payload);
            case 'send_email':          return self::action_send_email($args, $payload);
            case 'create_task':         return self::action_create_task($args, $payload);
            case 'create_ticket':       return self::action_create_ticket($args, $payload);
            case 'send_webhook':        return self::action_send_webhook($args, $payload);
            default:
                throw new Exception("Unknown action type: {$type}");
        }
    }

    /**
     * Substitute `{{path.to.field}}` placeholders in a string with values
     * read from the payload via dot-notation. Missing fields render as
     * empty. Arrays are JSON-encoded so a list-valued field renders as
     * something rather than the surprising-PHP-default of "Array".
     *
     * This is what makes `subject: "Ticket {{ticket.id}} closed"` work —
     * the engine resolves it against the dispatch payload before passing
     * the string to the action handler.
     */
    private static function renderTemplate(string $tmpl, array $payload): string
    {
        return preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', function ($m) use ($payload) {
            $val = self::dotGet($payload, $m[1]);
            if (is_array($val)) return json_encode($val);
            return $val === null ? '' : (string)$val;
        }, $tmpl);
    }

    /**
     * Resolve a single arg to a (rendered) string and trim it. Returns
     * '' if the arg is missing — handlers decide whether that's fatal.
     */
    private static function argString(array $args, string $key, array $payload): string
    {
        $raw = $args[$key] ?? '';
        if ($raw === null) return '';
        $rendered = self::renderTemplate((string)$raw, $payload);
        return trim($rendered);
    }

    /**
     * Resolve a single arg to an int, or null if blank / non-numeric.
     * (Handler enforces required-ness if the arg matters.)
     */
    private static function argInt(array $args, string $key, array $payload): ?int
    {
        $s = self::argString($args, $key, $payload);
        if ($s === '' || !is_numeric($s)) return null;
        return (int)$s;
    }

    private static function action_log_message(array $args, array $payload): array
    {
        $message = self::argString($args, 'message', $payload);
        // Variables in the form {{ticket.id}} are resolved by argString().
        return ['message' => $message];
    }

    private static function action_set_ticket_status(array $args, array $payload): array
    {
        $ticketId = self::argInt($args, 'ticket_id', $payload);
        $statusId = self::argInt($args, 'status_id', $payload);
        if (!$ticketId) throw new Exception('ticket_id is required');
        if (!$statusId) throw new Exception('status_id is required');
        $conn = connectToDatabase();
        $sLookup = $conn->prepare("SELECT name, is_closed FROM ticket_statuses WHERE id = ? LIMIT 1");
        $sLookup->execute([$statusId]);
        $sRow = $sLookup->fetch(PDO::FETCH_ASSOC);
        if (!$sRow) throw new Exception("Unknown status_id: {$statusId}");
        // Mirror assign_ticket.php's closure handling — when the new status
        // is_closed, stamp closed_datetime if it's not already set; when
        // reopening, clear it. Idempotent if the status hasn't actually
        // changed.
        $sets = ['status_id = ?', 'updated_datetime = UTC_TIMESTAMP()'];
        $params = [$statusId];
        if ((int)$sRow['is_closed']) {
            $sets[] = 'closed_datetime = COALESCE(closed_datetime, UTC_TIMESTAMP())';
        } else {
            $sets[] = 'closed_datetime = NULL';
        }
        $params[] = $ticketId;
        $conn->prepare('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        return ['ticket_id' => $ticketId, 'status_id' => $statusId, 'status_name' => $sRow['name']];
    }

    private static function action_set_ticket_priority(array $args, array $payload): array
    {
        $ticketId   = self::argInt($args, 'ticket_id', $payload);
        $priorityId = self::argInt($args, 'priority_id', $payload);
        if (!$ticketId)   throw new Exception('ticket_id is required');
        if (!$priorityId) throw new Exception('priority_id is required');
        $conn = connectToDatabase();
        $pLookup = $conn->prepare('SELECT name FROM ticket_priorities WHERE id = ? LIMIT 1');
        $pLookup->execute([$priorityId]);
        $pRow = $pLookup->fetch(PDO::FETCH_ASSOC);
        if (!$pRow) throw new Exception("Unknown priority_id: {$priorityId}");
        $conn->prepare('UPDATE tickets SET priority_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?')
             ->execute([$priorityId, $ticketId]);
        return ['ticket_id' => $ticketId, 'priority_id' => $priorityId, 'priority_name' => $pRow['name']];
    }

    private static function action_assign_ticket(array $args, array $payload): array
    {
        $ticketId  = self::argInt($args, 'ticket_id', $payload);
        $analystId = self::argInt($args, 'analyst_id', $payload);
        if (!$ticketId)  throw new Exception('ticket_id is required');
        if (!$analystId) throw new Exception('analyst_id is required');
        $conn = connectToDatabase();
        $aLookup = $conn->prepare('SELECT full_name FROM analysts WHERE id = ? LIMIT 1');
        $aLookup->execute([$analystId]);
        $aRow = $aLookup->fetch(PDO::FETCH_ASSOC);
        if (!$aRow) throw new Exception("Unknown analyst_id: {$analystId}");
        // Mirror assign_ticket.php's explicit-analyst branch — set BOTH
        // assigned_analyst_id and owner_id so the detail-pane Owner field
        // stays in sync.
        $conn->prepare('UPDATE tickets SET assigned_analyst_id = ?, owner_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?')
             ->execute([$analystId, $analystId, $ticketId]);
        return ['ticket_id' => $ticketId, 'analyst_id' => $analystId, 'analyst_name' => $aRow['full_name']];
    }

    private static function action_add_ticket_note(array $args, array $payload): array
    {
        $ticketId = self::argInt($args, 'ticket_id', $payload);
        $note     = self::argString($args, 'note', $payload);
        if (!$ticketId) throw new Exception('ticket_id is required');
        if ($note === '') throw new Exception('note is required');
        $conn = connectToDatabase();
        // Use the same `ticket_audit` table the rest of the app writes to
        // for analyst-visible activity. analyst_id is null to flag this as
        // a workflow-engine-driven note (so the UI can render it differently
        // if it wants to).
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, NULL, 'Workflow Note', NULL, ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, $note]);
        return ['ticket_id' => $ticketId, 'note_length' => strlen($note)];
    }

    private static function action_send_email(array $args, array $payload): array
    {
        $ticketId = self::argInt($args, 'ticket_id', $payload);
        $subject  = self::argString($args, 'subject', $payload);
        $body     = self::argString($args, 'body',    $payload);
        $to       = self::argString($args, 'to',      $payload);
        if (!$ticketId)        throw new Exception('ticket_id is required');
        if ($subject === '')   throw new Exception('subject is required');
        if ($body === '')      throw new Exception('body is required');

        // Reuse template_email.php's helpers — they handle mailbox lookup,
        // token refresh, Graph / Gmail dispatch, and saving the sent email
        // to the emails table for the threading SDREF marker. We're sending
        // ad-hoc content (not an ITSM template), but the plumbing is the same.
        require_once dirname(dirname(__DIR__)) . '/includes/template_email.php';

        $conn = connectToDatabase();
        $merge = buildTicketMergeData($conn, $ticketId);
        if (!$merge) throw new Exception("Ticket not found: {$ticketId}");

        $recipient = $to !== '' ? $to : ($merge['requester_email'] ?? '');
        if ($recipient === '') throw new Exception('No recipient (and ticket has no requester email)');

        $mailbox = templateGetMailboxForTicket($conn, $ticketId);
        if (!$mailbox) throw new Exception('Ticket has no associated mailbox — cannot send');

        $tokenJson = preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data'] ?? '');
        $tokenData = $tokenJson ? json_decode($tokenJson, true) : null;
        if (!$tokenData || !isset($tokenData['access_token'])) {
            throw new Exception('Mailbox token is missing or invalid');
        }

        $provider = $mailbox['provider'] ?? 'microsoft';
        if ($provider === 'google') {
            require_once dirname(dirname(__DIR__)) . '/includes/gmail.php';
            $accessToken = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
        } else {
            $accessToken = templateGetValidAccessToken($conn, $mailbox, $tokenData);
        }
        if (!$accessToken) throw new Exception('Failed to obtain a valid access token');

        $ticketRef = $merge['ticket_reference'] ?? '';
        $fullSubject = $ticketRef !== '' ? "[SDREF:{$ticketRef}] {$subject}" : $subject;
        $fullBody    = buildTemplateEmailBody($body, $ticketRef);

        if ($provider === 'google') {
            $fromAddress = $mailbox['target_mailbox'] ?? '';
            gmailSendEmail($accessToken, $recipient, $fullSubject, $fullBody, $fromAddress);
        } else {
            $message = [
                'message' => [
                    'subject'      => $fullSubject,
                    'body'         => ['contentType' => 'HTML', 'content' => $fullBody],
                    'toRecipients' => [['emailAddress' => ['address' => $recipient]]],
                ],
                'saveToSentItems' => true,
            ];
            templateSendViaGraph($accessToken, $message);
        }
        templateSaveSentEmail($conn, $ticketId, $mailbox, $recipient, $fullSubject, $body);
        return ['ticket_id' => $ticketId, 'to' => $recipient, 'subject' => $subject];
    }

    private static function action_create_task(array $args, array $payload): array
    {
        $title       = self::argString($args, 'title',       $payload);
        $description = self::argString($args, 'description', $payload);
        $statusId    = self::argInt($args, 'status_id',   $payload);
        $priorityId  = self::argInt($args, 'priority_id', $payload);
        $assigneeId  = self::argInt($args, 'assignee_id', $payload);
        $ticketId    = self::argInt($args, 'ticket_id',   $payload);
        if ($title === '') throw new Exception('title is required');

        $conn = connectToDatabase();
        // Fill in default status / priority when the user didn't pick one,
        // so the resulting task lands somewhere sensible on the board.
        if (!$statusId) {
            $s = $conn->query("SELECT id FROM task_statuses WHERE is_default = 1 LIMIT 1")->fetchColumn();
            if (!$s) $s = $conn->query("SELECT id FROM task_statuses ORDER BY display_order, id LIMIT 1")->fetchColumn();
            $statusId = $s ? (int)$s : null;
        }
        if (!$priorityId) {
            $p = $conn->query("SELECT id FROM task_priorities WHERE is_default = 1 LIMIT 1")->fetchColumn();
            $priorityId = $p ? (int)$p : null;
        }
        // Append to the end of the destination column's board (same
        // calculation api/tasks/save.php does on create).
        $boardPos = 0;
        if ($statusId) {
            $posStmt = $conn->prepare("SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks WHERE status_id = ? AND parent_task_id IS NULL");
            $posStmt->execute([$statusId]);
            $boardPos = (int)$posStmt->fetchColumn();
        }
        $conn->prepare(
            "INSERT INTO tasks (title, description, status_id, priority_id,
                                assigned_analyst_id, ticket_id, board_position, created_by_id,
                                created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $title,
            $description !== '' ? $description : null,
            $statusId,
            $priorityId,
            $assigneeId,
            $ticketId,
            $boardPos,
        ]);
        $newId = (int)$conn->lastInsertId();
        return [
            'task_id'     => $newId,
            'title'       => $title,
            'status_id'   => $statusId,
            'priority_id' => $priorityId,
            'assignee_id' => $assigneeId,
            'ticket_id'   => $ticketId,
        ];
    }

    private static function action_create_ticket(array $args, array $payload): array
    {
        $subject            = self::argString($args, 'subject', $payload);
        $body               = self::argString($args, 'body',    $payload);
        $priorityId         = self::argInt($args, 'priority_id',         $payload);
        $departmentId       = self::argInt($args, 'department_id',       $payload);
        $typeId             = self::argInt($args, 'type_id',             $payload);
        $assignedAnalystId  = self::argInt($args, 'assigned_analyst_id', $payload);
        $fromEmail          = self::argString($args, 'from_email', $payload);
        $fromName           = self::argString($args, 'from_name',  $payload);
        if ($subject === '') throw new Exception('subject is required');

        $conn = connectToDatabase();
        $conn->beginTransaction();
        try {
            // Resolve / upsert the user row by email so the ticket has a
            // requester. If from_email is blank we leave user_id null —
            // the rest of the app handles unattributed tickets fine.
            $userId = null;
            if ($fromEmail !== '') {
                $uLookup = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $uLookup->execute([$fromEmail]);
                $userId = $uLookup->fetchColumn();
                if (!$userId) {
                    $conn->prepare('INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())')
                         ->execute([$fromEmail, $fromName !== '' ? $fromName : $fromEmail]);
                    $userId = (int)$conn->lastInsertId();
                } else {
                    $userId = (int)$userId;
                }
            }

            // Default status to 'Open' (matches api/tickets/create_ticket.php).
            $statusId = $conn->query("SELECT id FROM ticket_statuses WHERE name = 'Open' LIMIT 1")->fetchColumn();
            $statusId = $statusId ? (int)$statusId : null;

            // Mirror create_ticket.php's number generator pattern.
            $ticketNumber = null;
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $candidate = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90)) . '-'
                           . rand(0, 9) . rand(0, 9) . rand(0, 9) . '-'
                           . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9);
                $check = $conn->prepare("SELECT 1 FROM tickets WHERE ticket_number = ?");
                $check->execute([$candidate]);
                if (!$check->fetchColumn()) { $ticketNumber = $candidate; break; }
            }
            if (!$ticketNumber) throw new Exception('Failed to generate unique ticket number');

            $conn->prepare(
                "INSERT INTO tickets (ticket_number, subject, status_id, priority_id,
                                     department_id, ticket_type_id, assigned_analyst_id,
                                     user_id, created_datetime, updated_datetime)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([
                $ticketNumber, $subject, $statusId, $priorityId,
                $departmentId, $typeId, $assignedAnalystId, $userId,
            ]);
            $newTicketId = (int)$conn->lastInsertId();

            // Initial "Manual" email entry so the new ticket appears in the
            // inbox like all others (same trick api/tickets/create_ticket.php
            // uses). If body is blank we still want a row.
            $safeBody = $body !== '' ? $body : '(created by workflow)';
            $bodyHtml = nl2br(htmlspecialchars($safeBody));
            $bodyPreview = substr(strip_tags($safeBody), 0, 200);
            $conn->prepare(
                "INSERT INTO emails (subject, from_address, from_name, to_recipients,
                                    received_datetime, body_preview, body_content, body_type,
                                    has_attachments, importance, is_read, ticket_id,
                                    is_initial, direction)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'html', 0, 'normal', 1, ?, 1, 'Manual')"
            )->execute([
                $subject,
                $fromEmail !== '' ? $fromEmail : '',
                $fromName  !== '' ? $fromName  : ($fromEmail ?: 'Workflow Engine'),
                $fromEmail !== '' ? $fromEmail : '',
                $bodyPreview, $bodyHtml, $newTicketId,
            ]);

            $conn->prepare(
                "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                 VALUES (?, NULL, 'Ticket Created', NULL, 'Created by workflow', UTC_TIMESTAMP())"
            )->execute([$newTicketId]);

            $conn->commit();
            return [
                'ticket_id'     => $newTicketId,
                'ticket_number' => $ticketNumber,
                'subject'       => $subject,
            ];
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * POST a payload to an external URL — the outbound-webhook action.
     *
     * Two modes, chosen by the `preset` arg:
     *   - a chat preset (slack / teams / discord) wraps the rendered `message`
     *     in that platform's expected JSON shape, so the common case is
     *     one field to fill;
     *   - `custom` sends the rendered `body` verbatim (must be valid JSON), so
     *     any target's exact payload can be produced with {{template}} vars.
     *
     * If a signing `secret` is set, the raw body is HMAC-SHA256 signed and the
     * hex digest sent as X-FreeITSM-Signature so the receiver can verify the
     * call really came from this install. Delivery is synchronous with a short
     * timeout (a dead URL fails fast rather than hanging the host request); a
     * non-2xx response or transport error marks the step failed and is visible
     * in the execution log. An async delivery queue with retries is planned.
     */
    private static function action_send_webhook(array $args, array $payload): array
    {
        require_once dirname(__DIR__, 2) . '/includes/webhook_delivery.php';
        $conn = connectToDatabase();

        // If the body embeds a full object ({{ticket.full}}) or the Full record
        // format is chosen, load + serialise the record (reusing the REST API
        // serialisers) so the same rich, typed shape the API returns is sent.
        // Only when needed — a plain Slack ping shouldn't trigger an extra query.
        $preset = strtolower(trim((string)($args['preset'] ?? '')));
        if ($preset === 'full' || strpos((string)($args['body'] ?? ''), '.full') !== false) {
            $payload = self::enrichWithFullObjects($conn, $payload);
        }

        $req = self::buildWebhookRequest($args, $payload);

        $deliveryId = webhookEnqueue($conn, [
            'workflow_id'  => self::$ctxWorkflowId,
            'execution_id' => self::$ctxExecutionId,
            'preset'       => $req['preset'],
            'url'          => $req['url'],
            'method'       => 'POST',
            'headers'      => $req['headers'],
            'body'         => $req['body'],
        ]);
        // Never log the secret or the full body; enough to audit the queueing.
        return [
            'queued'      => true,
            'delivery_id' => $deliveryId,
            'preset'      => $req['preset'],
            'url'         => preg_replace('#(https?://[^/]+).*#i', '$1/…', $req['url']),
            'signed'      => $req['signed'],
            'bytes'       => strlen($req['body']),
        ];
    }

    /**
     * Build the outbound-webhook HTTP request (url, headers incl. any HMAC
     * signature, and the rendered/validated JSON body) from the action's args
     * and the trigger payload. Shared by the live action (action_send_webhook,
     * which then enqueues the result) and the editor's "Send test" preview
     * endpoint (which sends it synchronously) — so the test can never drift
     * from a real send. Throws on any validation failure (bad url, empty
     * message, invalid JSON).
     *
     * The optional HMAC-SHA256 signature is computed here so the secret itself
     * is never stored — only the resulting X-FreeITSM-Signature header travels
     * on (retries reuse it: same body → same signature).
     *
     * @return array{preset:string,url:string,body:string,headers:array<int,string>,signed:bool}
     */
    public static function buildWebhookRequest(array $args, array $payload): array
    {
        $preset = strtolower(trim((string)($args['preset'] ?? 'custom'))) ?: 'custom';
        $url    = self::argString($args, 'url', $payload);
        if ($url === '') throw new Exception('url is required');
        if (!preg_match('#^https?://#i', $url)) throw new Exception('url must be an http(s) URL');

        // Build the request body for the chosen format.
        if ($preset === 'full') {
            // Send the entire record — exactly the shape the REST API returns for
            // GET /<resource>/{id}. The full object is attached to the payload by
            // enrichWithFullObjects() (under <key>.full) before we get here.
            $full = null;
            foreach ($payload as $v) {
                if (is_array($v) && isset($v['full']) && is_array($v['full'])) { $full = $v['full']; break; }
            }
            if ($full === null) {
                throw new Exception('the full-record format needs a trigger that carries a record with a full object (currently: ticket triggers)');
            }
            $bodyJson = json_encode($full, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($preset === 'slack' || $preset === 'discord' || $preset === 'teams') {
            $message = self::argString($args, 'message', $payload);
            if ($message === '') throw new Exception('message is required for the ' . $preset . ' preset');
            switch ($preset) {
                case 'slack':   $bodyArr = ['text' => $message]; break;
                case 'discord': $bodyArr = ['content' => $message]; break;
                case 'teams':
                default:
                    $bodyArr = [
                        '@type'    => 'MessageCard',
                        '@context' => 'https://schema.org/extensions',
                        'summary'  => mb_substr($message, 0, 80),
                        'text'     => $message,
                    ];
                    break;
            }
            $bodyJson = json_encode($bodyArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            // Custom: render the raw JSON body and validate it parses.
            $bodyJson = self::argString($args, 'body', $payload);
            if ($bodyJson === '') throw new Exception('a JSON body is required for the custom format');
            if (json_decode($bodyJson) === null && strtolower(trim($bodyJson)) !== 'null') {
                throw new Exception('the rendered body is not valid JSON: ' . json_last_error_msg());
            }
        }

        $headers = ['Content-Type: application/json', 'User-Agent: FreeITSM-Webhook/1'];
        $secret = trim((string)($args['secret'] ?? ''));
        if ($secret !== '') {
            $headers[] = 'X-FreeITSM-Signature: sha256=' . hash_hmac('sha256', $bodyJson, $secret);
        }

        return ['preset' => $preset, 'url' => $url, 'body' => $bodyJson, 'headers' => $headers, 'signed' => $secret !== ''];
    }

    /**
     * Enrich a trigger payload with the FULL, API-shaped version of its primary
     * object, reusing the REST API serialisers so a webhook can emit exactly the
     * JSON that GET /<resource>/{id} returns — rich, consistent and already typed
     * in the OpenAPI spec. The full object is attached under <key>.full, so a
     * Custom body can embed the whole record with {{ticket.full}} (the template
     * engine json_encodes arrays), and the "Full record" preset sends it wholesale.
     *
     * Extend $registry to cover more trigger object types — one entry per resource
     * is the single, typed source of truth for its shape. Best-effort: a load
     * failure is swallowed so the webhook still sends (just without .full).
     */
    public static function enrichWithFullObjects(PDO $conn, array $payload): array
    {
        static $registry = [
            'ticket' => [
                'deps'      => ['/api/v1/lib/response.php', '/api/v1/resources/tickets.php'],
                'select'    => 'apiTicketSelect',
                'where'     => ' WHERE t.id = ? LIMIT 1',
                'serialize' => 'apiSerializeTicket',
            ],
        ];
        foreach ($registry as $key => $cfg) {
            if (empty($payload[$key]['id']) || isset($payload[$key]['full'])) continue;
            try {
                foreach ($cfg['deps'] as $dep) require_once dirname(__DIR__, 2) . $dep;
                $stmt = $conn->prepare(call_user_func($cfg['select']) . $cfg['where']);
                $stmt->execute([(int)$payload[$key]['id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) $payload[$key]['full'] = call_user_func($cfg['serialize'], $row);
            } catch (Throwable $e) { /* full object is best-effort */ }
        }
        return $payload;
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Dotted-path getter — `dotGet(['ticket' => ['id' => 5]], 'ticket.id')` → 5.
     */
    private static function dotGet(array $haystack, string $path)
    {
        if ($path === '') return null;
        $parts = explode('.', $path);
        $cur = $haystack;
        foreach ($parts as $p) {
            if (is_array($cur) && array_key_exists($p, $cur)) {
                $cur = $cur[$p];
            } else {
                return null;
            }
        }
        return $cur;
    }

    private static function decodeJsonField($raw): array
    {
        if ($raw === null || $raw === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
}
