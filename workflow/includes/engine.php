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
        $t = [
            // ---- Rich domain events (specific payloads) ----
            'ticket.created'           => 'A ticket is created',
            'ticket.status_changed'    => 'A ticket\'s status changes',
            'ticket.priority_changed'  => 'A ticket\'s priority changes',
            'ticket.assigned'          => 'A ticket is assigned to an analyst',
            'ticket.deleted'           => 'A ticket is moved to the trash',
            'ticket.restored'          => 'A ticket is restored from the trash',
            'form.submitted'           => 'A form submission is received',
            'task.created'             => 'A task is created',
            'task.completed'           => 'A task is marked complete',
            'task.deleted'             => 'A task is deleted',
            'change.created'           => 'A change request is created',
            'change.approved'          => 'A change request is approved',
            'change.deleted'           => 'A change request is deleted',
            'problem.created'          => 'A problem is created',
            'problem.status_changed'   => 'A problem\'s status changes',
            'problem.deleted'          => 'A problem is deleted',
            'asset.assigned'           => 'An asset is assigned to a user',
            'asset.unassigned'         => 'An asset is unassigned from a user',
            'cmdb.object.created'      => 'A CMDB object is created',
            'cmdb.object.updated'      => 'A CMDB object is updated',
            'cmdb.object.deleted'      => 'A CMDB object is deleted',
            'knowledge.published'      => 'A knowledge article is published',
            'knowledge.updated'        => 'A knowledge article is updated',
            'knowledge.archived'       => 'A knowledge article is archived (recycle bin)',
            'service_status.incident_created'  => 'A status-page incident is opened',
            'service_status.incident_updated'  => 'A status-page incident is updated',
            'service_status.incident_resolved' => 'A status-page incident is resolved',
            'service_status.incident_deleted'  => 'A status-page incident is deleted',
            'morning_check.recorded'   => 'A morning check result is recorded',
            'software.application_discovered' => 'A new software application is discovered',

            // ---- TIME-BASED events ----
            // These don't fire from a write path — nothing happened, TIME PASSED.
            // A scheduled job goes looking for them (cron/workflow_scheduled.php
            // and the SLA breach-check cron), and a fire-once ledger stops the
            // same still-true condition re-firing every few minutes. If those
            // crons aren't scheduled, these triggers never fire at all.
            'sla.warning'              => 'A ticket\'s SLA is approaching its deadline (time-based)',
            'sla.breached'             => 'A ticket\'s SLA has been breached (time-based)',
            'contract.expiring'        => 'A contract is approaching its end date (time-based)',
            'asset.warranty_expiring'  => 'An asset\'s warranty is approaching expiry (time-based)',
        ];
        // ---- Explicit created / updated / deleted for every CRUD + settings
        // entity. Generated from crudEntities() so the list stays maintainable,
        // but every entity.action still appears as its own trigger. ----
        foreach (self::crudEntities() as $key => $def) {
            $t["$key.created"] = $def[0] . ' is created';
            $t["$key.updated"] = $def[0] . ' is updated';
            $t["$key.deleted"] = $def[0] . ' is deleted';
        }
        return $t;
    }

    /**
     * CRUD + settings entities that each get explicit .created/.updated/.deleted
     * triggers. `[label, [condition field paths]]`. Domain entities carry a few
     * useful fields; settings/lookups carry just id + name (their own id isn't a
     * useful filter, but name is, and the .id/.name convention keeps the editor's
     * type inference happy).
     */
    private static function crudEntities(): array
    {
        // Every entity here is WIRED to fire from its module's write path (a
        // service method or a settings endpoint) — no dead triggers.
        return [
            // Domain entities
            'contract'          => ['A contract', ['contract.id', 'contract.title', 'contract.status_id', 'contract.supplier_id']],
            'supplier'          => ['A supplier', ['supplier.id', 'supplier.name', 'supplier.status_id', 'supplier.type_id']],
            'supplier_contact'  => ['A supplier contact', ['supplier_contact.id', 'supplier_contact.name', 'supplier_contact.supplier_id']],
            'calendar_event'    => ['A calendar event', ['calendar_event.id', 'calendar_event.title', 'calendar_event.category_id']],
            'status_service'    => ['A monitored service', ['status_service.id', 'status_service.name']],
            'morning_check'     => ['A morning check', ['morning_check.id', 'morning_check.name']],
            'software_licence'  => ['A software licence', ['software_licence.id', 'software_licence.name']],
            'network_diagram'   => ['A network diagram', ['network_diagram.id', 'network_diagram.name']],
            // Settings / lookups
            'ticket_status'     => ['A ticket status', ['ticket_status.id', 'ticket_status.name']],
            'ticket_priority'   => ['A ticket priority', ['ticket_priority.id', 'ticket_priority.name']],
            'ticket_type'       => ['A ticket type', ['ticket_type.id', 'ticket_type.name']],
            'ticket_origin'     => ['A ticket origin', ['ticket_origin.id', 'ticket_origin.name']],
            'asset_type'        => ['An asset type', ['asset_type.id', 'asset_type.name']],
            'asset_status'      => ['An asset status', ['asset_status.id', 'asset_status.name']],
            'asset_location'    => ['An asset location', ['asset_location.id', 'asset_location.name']],
            'change_status'     => ['A change status', ['change_status.id', 'change_status.name']],
            'change_type'       => ['A change type', ['change_type.id', 'change_type.name']],
            'change_priority'   => ['A change priority', ['change_priority.id', 'change_priority.name']],
            'change_impact'     => ['A change impact', ['change_impact.id', 'change_impact.name']],
            'problem_status'    => ['A problem status', ['problem_status.id', 'problem_status.name']],
            'problem_priority'  => ['A problem priority', ['problem_priority.id', 'problem_priority.name']],
            'task_status'       => ['A task status', ['task_status.id', 'task_status.name']],
            'task_priority'     => ['A task priority', ['task_priority.id', 'task_priority.name']],
            'task_tag'          => ['A task tag', ['task_tag.id', 'task_tag.name']],
            'cmdb_class'        => ['A CMDB class', ['cmdb_class.id', 'cmdb_class.name']],
            'cmdb_property'     => ['A CMDB class property', ['cmdb_property.id', 'cmdb_property.name']],
            'cmdb_relationship_type' => ['A CMDB relationship type', ['cmdb_relationship_type.id', 'cmdb_relationship_type.name']],
            'contract_status'   => ['A contract status', ['contract_status.id', 'contract_status.name']],
            'contract_term_tab' => ['A contract term tab', ['contract_term_tab.id', 'contract_term_tab.name']],
            'payment_schedule'  => ['A payment schedule', ['payment_schedule.id', 'payment_schedule.name']],
            'supplier_status'   => ['A supplier status', ['supplier_status.id', 'supplier_status.name']],
            'supplier_type'     => ['A supplier type', ['supplier_type.id', 'supplier_type.name']],
            'calendar_category' => ['A calendar category', ['calendar_category.id', 'calendar_category.name']],
            'incident_status'   => ['A status-page incident status', ['incident_status.id', 'incident_status.name']],
            'impact_level'      => ['A status-page impact level', ['impact_level.id', 'impact_level.name']],
            'morning_check_status' => ['A morning check status', ['morning_check_status.id', 'morning_check_status.name']],
        ];
    }

    /**
     * Fire a CRUD / settings event: <entityKey>.<action> with a
     * {entityKey: {id, name}} payload matching the entity's condition fields.
     * $action is 'created' | 'updated' | 'deleted'. dispatch() is self-safe, so
     * call sites need no try/catch — a workflow hiccup can't affect the write.
     */
    public static function emitCrud(string $entityKey, string $action, int $id, ?string $name = null): void
    {
        self::dispatch("{$entityKey}.{$action}", [$entityKey => ['id' => $id, 'name' => $name]]);
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
            'task.created' => [
                'task.id', 'task.title', 'task.status_id', 'task.priority_id', 'task.assignee_id',
            ],
            'task.completed' => [
                'task.id', 'task.title', 'task.priority_id', 'task.assignee_id',
            ],
            'change.created' => [
                'change.id', 'change.title', 'change.status_id', 'change.priority_id',
                'change.type_id', 'change.risk', 'change.assigned_to_id',
            ],
            'change.approved' => [
                'change.id', 'change.title', 'change.risk', 'approver.id',
            ],
            'problem.created' => [
                'problem.id', 'problem.problem_number', 'problem.title', 'problem.status_id',
                'problem.priority_id', 'problem.assigned_analyst_id', 'problem.is_known_error', 'problem.company_id',
            ],
            'problem.status_changed' => [
                'problem.id', 'problem.problem_number', 'problem.title', 'problem.status_id',
                'problem.priority_id', 'problem.assigned_analyst_id', 'problem.company_id',
            ],
            'asset.assigned' => [
                'asset.id', 'asset.hostname', 'user.id', 'user.name',
            ],
            'asset.unassigned' => [
                'asset.id', 'asset.hostname', 'user.id', 'user.name',
            ],
            'ticket.deleted'   => $fullTicket,
            'ticket.restored'  => $fullTicket,
            'task.deleted'     => ['task.id', 'task.title', 'task.priority_id', 'task.assignee_id'],
            'change.deleted'   => ['change.id', 'change.title'],
            'problem.deleted'  => ['problem.id', 'problem.problem_number', 'problem.title'],
            'cmdb.object.created' => ['object.id', 'object.name', 'object.class_id', 'object.is_planned'],
            'cmdb.object.updated' => ['object.id', 'object.name', 'object.class_id'],
            'cmdb.object.deleted' => ['object.id', 'object.name', 'object.class_id'],
            'knowledge.published' => ['article.id', 'article.title'],
            'knowledge.updated'   => ['article.id', 'article.title'],
            'knowledge.archived'  => ['article.id', 'article.title'],
            'service_status.incident_created'  => ['incident.id', 'incident.title', 'incident.status_id'],
            'service_status.incident_updated'  => ['incident.id', 'incident.title', 'incident.status_id'],
            'service_status.incident_resolved' => ['incident.id', 'incident.title', 'incident.status_id'],
            'service_status.incident_deleted'  => ['incident.id', 'incident.title'],
            'morning_check.recorded' => ['check.id', 'check.name', 'result.status_id', 'result.status_name', 'result.date'],
            'software.application_discovered' => ['application.id', 'application.name', 'application.publisher'],

            // ---- Time-based ----
            // SLA events carry the FULL ticket (so an escalation can reassign,
            // reprioritise or message about the ticket itself) plus the SLA state.
            // `sla.target` is 'response' or 'resolution' — condition on it if you
            // only care about one.
            'sla.warning' => array_merge($fullTicket, [
                'sla.target', 'sla.percent', 'sla.remaining_minutes', 'sla.target_minutes',
            ]),
            'sla.breached' => array_merge($fullTicket, [
                'sla.target', 'sla.overdue_minutes', 'sla.target_minutes',
            ]),
            // `window_days` is which reminder this is (90 / 30 / 7 / 1). One
            // emission per window, so "only tell me at 30 days" is a condition
            // rather than something the workflow has to schedule for itself.
            'contract.expiring' => [
                'contract.id', 'contract.number', 'contract.title', 'contract.end_date',
                'contract.days_remaining', 'contract.supplier_id', 'contract.supplier_name',
                'window_days',
            ],
            'asset.warranty_expiring' => [
                'asset.id', 'asset.hostname', 'asset.warranty_end', 'asset.days_remaining',
                'window_days',
            ],
        ];
        if (isset($byTrigger[$trigger])) {
            return $byTrigger[$trigger];
        }
        // CRUD / settings entity fallback: <entity>.created|updated|deleted.
        if (preg_match('/^(.+)\.(created|updated|deleted)$/', $trigger, $m)) {
            $crud = self::crudEntities();
            if (isset($crud[$m[1]])) {
                return $crud[$m[1]][1];
            }
        }
        return [];
    }

    /**
     * Payload objects that can be hydrated into a whole record under
     * `<key>.full` — see enrichWithFullObjects() / fullObjectLoaders().
     *
     * ⚠️ MUST stay in step with the keys returned by fullObjectLoaders().
     * It's a separate list only because the merge-code picker needs the key
     * names without opening a database connection to build the loaders.
     */
    public const FULL_OBJECT_KEYS = [
        'ticket', 'change', 'problem', 'task', 'asset', 'article',
        'contract', 'supplier', 'calendar_event', 'software_licence', 'incident',
    ];

    // -----------------------------------------------------------------
    //  Webhook message formats
    //
    //  A chat "preset" is nothing but a JSON body template with a
    //  {{message}} slot:
    //
    //      Slack   → {"text": "{{message}}"}
    //      Discord → {"content": "{{message}}"}
    //
    //  Which means the Custom (raw JSON) format already does everything a
    //  preset does — a preset is just a custom body somebody named. They were
    //  frozen into a PHP switch, so adding Google Chat or Mattermost meant a
    //  code change and a release. Now they're DATA: rows in
    //  webhook_message_formats, editable under Workflows → Settings.
    //
    //  These built-ins are the seed AND the fallback. If the table is missing,
    //  empty or unreadable, the engine uses these — a mangled setting must
    //  never be able to stop webhooks going out.
    //
    //  `custom` and `full` are deliberately NOT in here: they aren't
    //  message-wrapping formats, they're structurally different, and forcing
    //  them into this shape would be a lie.
    // -----------------------------------------------------------------
    public const BUILTIN_WEBHOOK_FORMATS = [
        'slack' => [
            'label'         => 'Slack',
            'body_template' => '{"text": "{{message}}"}',
            'url_pattern'   => 'hooks\.slack\.com',
            'markdown_hint' => 'Slack mrkdwn: *bold*, _italic_, `code`. Links are <https://example.com|like this>.',
        ],
        'teams' => [
            'label'         => 'Microsoft Teams',
            'body_template' => '{"@type": "MessageCard", "@context": "https://schema.org/extensions", "summary": "{{message}}", "text": "{{message}}"}',
            'url_pattern'   => 'webhook\.office\.com|office\.com/webhookb2|logic\.azure\.com',
            'markdown_hint' => 'Teams MessageCard: **bold**, *italic*, [link](https://example.com).',
        ],
        'discord' => [
            'label'         => 'Discord',
            'body_template' => '{"content": "{{message}}"}',
            'url_pattern'   => 'discord(app)?\.com/api/webhooks',
            'markdown_hint' => 'Discord markdown: **bold** (two asterisks — a single *asterisk* is italic). Emoji shortcodes like :rotating_light: work.',
        ],
    ];

    /** Formats that are structurally special and stay in code. */
    public const RESERVED_FORMAT_KEYS = ['custom', 'full'];

    /**
     * Every message-wrapping format available on this install: the built-ins
     * plus whatever the admin has added. Cached per request.
     *
     * Falls back to the built-ins on ANY database problem — the engine has to
     * keep sending webhooks even if the formats table is missing or broken.
     */
    public static function webhookFormats(): array
    {
        static $formats = null;
        if ($formats !== null) return $formats;

        $formats = self::BUILTIN_WEBHOOK_FORMATS;
        try {
            $conn = connectToDatabase();
            $rows = $conn->query(
                "SELECT format_key, label, body_template, url_pattern, markdown_hint
                   FROM webhook_message_formats
                  WHERE is_active = 1
                  ORDER BY display_order, label"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $key = strtolower(trim((string)$r['format_key']));
                if ($key === '' || in_array($key, self::RESERVED_FORMAT_KEYS, true)) continue;
                $formats[$key] = [
                    'label'         => (string)$r['label'],
                    'body_template' => (string)$r['body_template'],
                    'url_pattern'   => $r['url_pattern']   !== null ? (string)$r['url_pattern']   : null,
                    'markdown_hint' => $r['markdown_hint'] !== null ? (string)$r['markdown_hint'] : null,
                ];
            }
        } catch (Exception $e) {
            // Table not created yet (pre-Database-Verify), or unreadable.
            // The built-ins above already cover Slack / Teams / Discord.
        }
        return $formats;
    }

    /**
     * Render a format's JSON body template.
     *
     * ⚠️ THE ESCAPING POINT. We do NOT string-substitute into the raw template
     * text: a message containing a double quote, a backslash or a newline would
     * produce invalid JSON (or, worse, let a crafted message inject structure
     * into the payload). Instead we DECODE the template, walk it, substitute
     * inside the string VALUES, and re-encode — so json_encode does the
     * escaping, exactly as the old hardcoded array-then-encode approach did.
     */
    private static function renderFormatBody(string $template, string $message, array $payload): string
    {
        $decoded = json_decode($template, true);
        if ($decoded === null && strtolower(trim($template)) !== 'null') {
            throw new Exception('this message format\'s JSON template is not valid JSON: ' . json_last_error_msg());
        }
        $vars = $payload;
        $vars['message'] = $message;   // {{message}} is what the format wraps

        $walk = function ($node) use (&$walk, $vars) {
            if (is_string($node)) return self::renderTemplate($node, $vars);
            if (is_array($node)) {
                $out = [];
                foreach ($node as $k => $v) $out[$k] = $walk($v);
                return $out;
            }
            return $node;
        };

        return json_encode($walk($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Records whose human-facing REFERENCE differs from their internal id.
     *
     * `{{ticket.id}}` is the database row id (83). The thing a requester quotes
     * back at you — and the thing that belongs in an email subject or a chat
     * alert — is the ticket NUMBER ("ETC-623-64409"). They are not the same, and
     * only one of them means anything outside the database.
     *
     * Exposed as `{{<object>.number}}`. Deliberately NOT "ref": the app and the
     * schema both call this a number (`tickets.ticket_number`), and inventing a
     * third word for it would just add a synonym to learn.
     *
     * Changes and tasks have no reference column, so they get nothing here —
     * their id IS their identity today.
     */
    private const RECORD_REFERENCE_COLUMNS = [
        'ticket'  => ['table' => 'tickets',  'column' => 'ticket_number'],
        'problem' => ['table' => 'problems', 'column' => 'problem_number'],
    ];

    /**
     * Add `{{<object>.number}}` beside `{{<object>.id}}` wherever the record has
     * a human-facing reference. Cached; never clobbers a value the host module
     * already supplied (problem.* triggers ship `problem.problem_number`
     * themselves, and that stays authoritative).
     */
    public static function enrichWithReferences(PDO $conn, array $payload): array
    {
        static $cache = [];
        foreach (self::RECORD_REFERENCE_COLUMNS as $obj => $spec) {
            $id = self::dotGet($payload, $obj . '.id');
            if ($id === null || $id === '' || !is_numeric($id)) continue;
            if (self::dotGet($payload, $obj . '.number') !== null) continue;

            $key = $spec['table'] . ':' . (int)$id;
            if (!array_key_exists($key, $cache)) {
                try {
                    $stmt = $conn->prepare(sprintf(
                        'SELECT %s FROM `%s` WHERE id = ? LIMIT 1', $spec['column'], $spec['table']
                    ));
                    $stmt->execute([(int)$id]);
                    $v = $stmt->fetchColumn();
                    $cache[$key] = ($v === false || $v === null) ? null : (string)$v;
                } catch (Exception $e) {
                    $cache[$key] = null;
                }
            }
            if ($cache[$key] !== null) self::dotSet($payload, $obj . '.number', $cache[$key]);
        }
        return $payload;
    }

    /**
     * Everything a merge code might need that the raw event payload doesn't
     * carry: readable lookup names, and human-facing record references.
     *
     * ONE entry point, called by the engine at run time AND by the Send-test
     * preview — so the preview cannot drift from what production actually sends
     * (the failure that made the old hand-written sample payload a liability).
     */
    public static function enrichPayloadForTemplates(PDO $conn, array $payload): array
    {
        $payload = self::enrichWithLookupNames($conn, $payload);
        return self::enrichWithReferences($conn, $payload);
    }

    /**
     * The `_name` merge code that pairs with a lookup id field.
     *
     *   ticket.priority_id        → ticket.priority_name
     *   ticket.created_by         → ticket.created_by_name   (no _id to strip)
     *   approver.id               → approver.name
     *
     * Returns null if there's nothing sensible to derive.
     */
    private static function lookupNamePath(string $idPath): ?string
    {
        if (str_ends_with($idPath, '_id'))  return substr($idPath, 0, -3) . '_name';
        if (str_ends_with($idPath, '.id'))  return substr($idPath, 0, -3) . '.name';
        if ($idPath === '')                 return null;
        return $idPath . '_name';   // e.g. created_by → created_by_name
    }

    /**
     * Resolve one lookup id to its human label (cached per request — a ticket
     * payload touches ~6 of these and the same status/priority recurs across a
     * fan-out, so the cache earns its keep).
     */
    private static function lookupLabel(PDO $conn, array $spec, int $id): ?string
    {
        static $cache = [];
        $key = $spec['table'] . ':' . $id;
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $stmt = $conn->prepare(sprintf(
                'SELECT %s AS label FROM `%s` WHERE id = ? LIMIT 1',
                $spec['label_col'], $spec['table']
            ));
            $stmt->execute([$id]);
            $v = $stmt->fetchColumn();
            $cache[$key] = ($v === false || $v === null) ? null : (string)$v;
        } catch (Exception $e) {
            $cache[$key] = null;   // a missing lookup table must never break a run
        }
        return $cache[$key];
    }

    /**
     * Add a human-readable `_name` beside every lookup id the payload carries.
     *
     * WHY: `{{ticket.priority_id}}` renders as `4`. In a Slack message that is
     * useless — nobody reading the channel knows what priority 4 is. This makes
     * `{{ticket.priority_name}}` ("Critical") available everywhere a merge code
     * can be used, resolved from the same FIELD_LOOKUP_TABLES registry that
     * powers the condition dropdowns, so a new lookup field gets its name code
     * for free.
     *
     * NEVER CLOBBERS an existing value: `form.submitted` already ships a real
     * `form.name` in its payload, and the host module's value wins over anything
     * we'd derive from `form.id`.
     */
    public static function enrichWithLookupNames(PDO $conn, array $payload): array
    {
        foreach (self::FIELD_LOOKUP_TABLES as $idPath => $spec) {
            $id = self::dotGet($payload, $idPath);
            if ($id === null || $id === '' || !is_numeric($id)) continue;

            $namePath = self::lookupNamePath($idPath);
            if ($namePath === null) continue;
            if (self::dotGet($payload, $namePath) !== null) continue;   // host module's value wins

            $label = self::lookupLabel($conn, $spec, (int)$id);
            if ($label === null) continue;

            self::dotSet($payload, $namePath, $label);
        }
        return $payload;
    }

    /** Dotted-path setter — the write twin of dotGet(). */
    private static function dotSet(array &$haystack, string $path, $value): void
    {
        $parts = explode('.', $path);
        $ref = &$haystack;
        foreach ($parts as $i => $p) {
            if ($i === count($parts) - 1) { $ref[$p] = $value; return; }
            if (!isset($ref[$p]) || !is_array($ref[$p])) $ref[$p] = [];
            $ref = &$ref[$p];
        }
    }

    /**
     * The merge codes (`{{variables}}`) a workflow on `$trigger` can actually
     * resolve — the catalogue behind the editor's variable picker.
     *
     * This is deliberately a DIFFERENT list from availableFields(): that one
     * answers "what can I write a condition on" (scalars only), while this one
     * answers "what can I paste into a message". The two overlap but aren't the
     * same — `{{event}}` is a valid merge code and a useless condition field;
     * `{{ticket.full}}` is a whole JSON object, meaningless in a comparison.
     *
     * Why it matters: renderTemplate() resolves an unknown path to an EMPTY
     * STRING. So a knowledge.published workflow whose webhook body says
     * {{ticket.subject}} doesn't fail — it silently posts a blank. Offering
     * only the codes the trigger can really resolve is what stops that.
     *
     * Returns [['path' => ..., 'label' => ..., 'note' => ...], ...].
     */
    public static function availableVariables(string $trigger): array
    {
        $vars = [[
            'path'  => 'event',
            'label' => 'Event name',
            'note'  => 'The trigger that fired — "' . $trigger . '"',
        ]];

        $fields = self::availableFields($trigger);

        // {{<object>.number}} — the reference a human quotes, e.g. ETC-623-64409,
        // as opposed to {{ticket.id}} which is the database row id (83). Offered
        // wherever the trigger carries that object's id.
        foreach (self::RECORD_REFERENCE_COLUMNS as $obj => $spec) {
            if (!in_array($obj . '.id', $fields, true)) continue;
            if (in_array($obj . '.number', $fields, true)) continue;   // host already ships one
            $vars[] = [
                'path'  => $obj . '.number',
                'label' => self::humaniseSegment($obj) . ' · Number',
                'note'  => 'The reference people actually quote, e.g. ETC-623-64409 — not the internal id.',
            ];
        }

        foreach ($fields as $path) {
            $human    = self::humaniseFieldPath($path);
            $hasTwin  = isset(self::FIELD_LOOKUP_TABLES[$path]);
            $namePath = $hasTwin ? self::lookupNamePath($path) : null;
            // form.submitted already ships a real form.name — don't offer it twice.
            if ($namePath !== null && in_array($namePath, $fields, true)) $namePath = null;

            // Every lookup id gets a human-readable twin. {{ticket.priority_id}}
            // renders "4"; {{ticket.priority_name}} renders "Critical" — which is
            // the one you actually want in a message a person will read.
            //
            // So the NAME gets the plain label ("Ticket · Priority") and the id is
            // marked as such. Labelling them the other way round hands the natural-
            // sounding name to the field that renders a meaningless number.
            $vars[] = [
                'path'  => $path,
                'label' => $namePath !== null ? $human . ' (id)' : $human,
                'note'  => $namePath !== null ? 'The numeric id — usually you want the name instead.' : '',
            ];
            if ($namePath === null) continue;
            $vars[] = [
                'path'  => $namePath,
                'label' => $human,
                'note'  => 'The readable name, e.g. "Critical" rather than "4".',
            ];
        }

        // Whole-record codes, but only for objects this trigger actually carries
        // AND that we know how to hydrate.
        $seen = [];
        foreach ($fields as $path) {
            $obj = strpos($path, '.') === false ? null : explode('.', $path)[0];
            if ($obj === null || isset($seen[$obj])) continue;
            $seen[$obj] = true;
            if (!in_array($obj, self::FULL_OBJECT_KEYS, true)) continue;
            $vars[] = [
                'path'  => $obj . '.full',
                'label' => self::humaniseSegment($obj) . ' · whole record',
                'note'  => 'The entire record as JSON — the same shape the REST API returns.',
            ];
        }

        // A form's answers are keyed by the labels the form author chose, so
        // they can't be enumerated ahead of time. Advertise the shape instead.
        if ($trigger === 'form.submitted') {
            $vars[] = [
                'path'  => 'submission.fields.Your field label',
                'label' => 'Submission · any answer, by field label',
                'note'  => 'Replace "Your field label" with the exact label from your form, e.g. {{submission.fields.Start date}}.',
            ];
        }

        return $vars;
    }

    /**
     * Merge-code prefixes that are open-ended for this trigger — anything
     * beneath them is valid even though it can't be listed up front.
     *
     * The editor uses this so its unknown-variable warning doesn't cry wolf
     * over a legitimate {{submission.fields.Start date}}.
     */
    public static function variablePrefixes(string $trigger): array
    {
        return $trigger === 'form.submitted' ? ['submission.fields.'] : [];
    }

    /**
     * 'ticket.requester_email' → 'Ticket · Requester email'.
     * Derived rather than hand-mapped so it stays correct as the trigger
     * catalogue grows — a hand-written label map would rot immediately.
     */
    private static function humaniseFieldPath(string $path): string
    {
        $parts = explode('.', $path);
        return implode(' · ', array_map([self::class, 'humaniseSegment'], $parts));
    }

    private static function humaniseSegment(string $seg): string
    {
        $s = str_replace('_', ' ', $seg);
        if ($s === 'id') return 'ID';
        // A trailing "id" is noise in a label ("Status id" → "Status") — but
        // only when something precedes it, or "ticket.id" would blank out.
        $s = preg_replace('/\s+id$/', '', $s);
        return ucfirst(trim($s));
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
        // Registry gaps closed alongside the _name merge codes: owner and origin
        // were the only ticket lookup fields with no entry here, so they had no
        // condition dropdown (free-text id box) AND no readable name twin.
        'ticket.owner_id'            => ['table' => 'analysts',          'label_col' => 'full_name', 'where' => 'is_active = 1', 'order' => 'full_name'],
        'ticket.origin_id'           => ['table' => 'ticket_origins',    'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'display_order, name'],
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
        'change.status_id'           => ['table' => 'change_statuses',   'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'display_order, name'],
        'change.priority_id'         => ['table' => 'change_priorities', 'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'display_order, name'],
        'change.type_id'             => ['table' => 'change_types',      'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'display_order, name'],
        'change.assigned_to_id'      => ['table' => 'analysts',          'label_col' => 'full_name', 'where' => 'is_active = 1', 'order' => 'full_name'],
        'problem.status_id'          => ['table' => 'problem_statuses',  'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'display_order, name'],
        'problem.priority_id'        => ['table' => 'problem_priorities','label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'display_order, name'],
        'problem.assigned_analyst_id' => ['table' => 'analysts',         'label_col' => 'full_name', 'where' => 'is_active = 1', 'order' => 'full_name'],
        'object.class_id'            => ['table' => 'cmdb_classes',      'label_col' => 'name',      'where' => 'is_active = 1', 'order' => 'name'],
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
        'problem.id'             => 'numeric',
        'problem.problem_number' => 'text',
        'problem.title'          => 'text',
        'problem.is_known_error' => 'numeric',
        'problem.company_id'     => 'numeric',
        'asset.id'               => 'numeric',
        'asset.hostname'         => 'text',
        'user.id'                => 'numeric',
        'user.name'              => 'text',
        'object.id'              => 'numeric',
        'object.name'            => 'text',
        'object.is_planned'      => 'numeric',
        'article.id'             => 'numeric',
        'article.title'          => 'text',
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
        // The webhook Format dropdown is generated from the message-format
        // registry, so an admin-added format (Google Chat, Mattermost…) shows up
        // without touching this file. `custom` and `full` are the two structural
        // modes that stay in code — they don't wrap a message.
        $messageFormatKeys = array_keys(self::webhookFormats());
        $formatOptions = [
            ['value' => 'custom', 'label' => 'Custom (raw JSON body)'],
            ['value' => 'full',   'label' => 'Full record (whole object as JSON)'],
        ];
        foreach (self::webhookFormats() as $key => $fmt) {
            $formatOptions[] = ['value' => $key, 'label' => $fmt['label']];
        }

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
                        // Built from the registry, so a format an admin adds under
                        // Workflows → Settings appears here with no code change.
                        'options' => $formatOptions,
                        'default' => 'custom',
                    ],
                    'url'     => ['type' => 'text', 'label' => 'Webhook URL', 'required' => true, 'supports_vars' => true],
                    // Shown for every message-wrapping format — including new ones.
                    'message' => ['type' => 'textarea', 'label' => 'Message', 'supports_vars' => true, 'show_when' => ['preset' => $messageFormatKeys]],
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
    public static function manualFire(int $workflowId, array $samplePayload = [], bool $dryRun = false): array
    {
        $conn = connectToDatabase();
        $stmt = $conn->prepare("SELECT * FROM workflows WHERE id = ?");
        $stmt->execute([$workflowId]);
        $wf = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wf) {
            throw new Exception('Workflow not found');
        }
        return self::run($wf, $wf['trigger_event'], $samplePayload, /*isManual*/ true, $dryRun);
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
    private static function run(array $wf, string $event, array $payload, bool $isManual, bool $dryRun = false): array
    {
        $wfId = (int)$wf['id'];

        if (in_array($wfId, self::$activeWorkflowIds, true)) {
            return self::recordAbortedRun($wf, $event, $payload, $isManual, $dryRun,
                'Loop protection: this workflow is already running in the current event chain — re-entry refused to prevent an infinite loop.');
        }
        if (self::$chainDepth >= self::MAX_CHAIN_DEPTH) {
            return self::recordAbortedRun($wf, $event, $payload, $isManual, $dryRun,
                'Loop protection: workflow chain depth limit (' . self::MAX_CHAIN_DEPTH . ') reached — execution aborted.');
        }
        if (self::$runsThisRequest >= self::MAX_RUNS_PER_REQUEST) {
            return self::recordAbortedRun($wf, $event, $payload, $isManual, $dryRun,
                'Loop protection: per-request workflow run limit (' . self::MAX_RUNS_PER_REQUEST . ') reached — execution aborted.');
        }

        self::$activeWorkflowIds[] = $wfId;
        self::$chainDepth++;
        self::$runsThisRequest++;
        try {
            return self::runInner($wf, $event, $payload, $isManual, $dryRun);
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
    private static function recordAbortedRun(array $wf, string $event, array $payload, bool $isManual, bool $dryRun, string $message): array
    {
        $wfId = (int)$wf['id'];
        error_log('[WorkflowEngine] aborted run for workflow ' . $wfId . ': ' . $message);
        $stepLog = [['kind' => 'loop_protection', 'error' => $message]];
        try {
            $conn = connectToDatabase();
            $stmt = $conn->prepare(
                "INSERT INTO workflow_executions
                 (workflow_id, workflow_name, trigger_event, trigger_payload, status, is_dry_run, started_datetime, finished_datetime, step_log, error_message)
                 VALUES (?, ?, ?, ?, 'aborted', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)"
            );
            $stmt->execute([
                $wfId, $wf['name'] ?? null, $event, json_encode($payload), $dryRun ? 1 : 0, json_encode($stepLog), $message,
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

    private static function runInner(array $wf, string $event, array $payload, bool $isManual, bool $dryRun = false): array
    {
        $conn = connectToDatabase();
        $stepLog = [];
        $status  = 'success';
        $errorMessage = null;

        // Make the event name addressable as {{event}} — the send_webhook
        // action's own default body template references it, so without this it
        // renders empty on every real run. Set before the payload snapshot is
        // stored so the audit shows exactly what the actions saw. A host module
        // that already supplied an `event` key keeps its own value.
        if (!array_key_exists('event', $payload)) {
            $payload['event'] = $event;
        }

        // Add everything a merge code might want that the raw event doesn't carry:
        // readable lookup names ({{ticket.priority_name}} → "Critical", not "4")
        // and human-facing references ({{ticket.number}} → "ETC-623-64409", not
        // the row id). Done here, once, before conditions and actions — so the
        // step log, the dry-run preview and every action see the same payload.
        $payload = self::enrichPayloadForTemplates($conn, $payload);

        // Insert a "running" execution row so we have an id to update. The
        // workflow name is snapshotted so the run stays attributable after
        // its parent workflow is deleted (workflow_id goes NULL then).
        $insert = $conn->prepare(
            "INSERT INTO workflow_executions
             (workflow_id, workflow_name, trigger_event, trigger_payload, status, is_dry_run, started_datetime)
             VALUES (?, ?, ?, ?, 'running', ?, UTC_TIMESTAMP())"
        );
        $insert->execute([
            (int)$wf['id'],
            $wf['name'] ?? null,
            $event,
            json_encode($payload),
            $dryRun ? 1 : 0,
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

                    // Dry run: resolve the action exactly as far as we can
                    // WITHOUT touching anything — substitute the {{variables}}
                    // and record the args the handler would have received, then
                    // move on. Nothing is written, sent or queued.
                    if ($dryRun) {
                        $stepLog[] = [
                            'kind'        => 'action',
                            'index'       => $i,
                            'type'        => $type,
                            'status'      => 'dry_run',
                            'would_run'   => self::describeAction($type),
                            'would_args'  => self::previewArgs($args, $payload),
                        ];
                        continue;
                    }

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

        // Update parent workflow stats — only for real, non-manual runs, so
        // neither "Test fire" nor a dry run pollutes the production counters.
        if (!$isManual && !$dryRun) {
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
            'is_dry_run'     => $dryRun,
            'step_log'       => $stepLog,
            'error_message'  => $errorMessage,
        ];
    }

    /**
     * The catalogue label for an action type — what a dry run reports it
     * "would have" done. Falls back to the raw type for an action the
     * catalogue no longer knows about.
     */
    private static function describeAction(string $type): string
    {
        return self::availableActions()[$type]['label'] ?? $type;
    }

    /**
     * Render an action's args for a dry run: every {{variable}} substituted
     * against the real payload, so what you read in the log is exactly the
     * value the handler would have been handed.
     *
     * The signing secret is redacted — a dry run is a debugging surface and
     * must not become a way to read a secret back out of a saved workflow.
     */
    private static function previewArgs(array $args, array $payload): array
    {
        $out = [];
        foreach ($args as $k => $v) {
            if ($k === 'secret') {
                $out[$k] = ($v === '' || $v === null) ? '' : '(set — redacted)';
                continue;
            }
            $out[$k] = is_string($v) ? self::renderTemplate($v, $payload) : $v;
        }
        return $out;
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

        // The URL and signing secret are stored ENCRYPTED in workflows.actions.
        // Decrypt them here, at the point of use — otherwise we'd sign with the
        // ciphertext and POST to a URL that is a base64 blob. Values written
        // before encryption was introduced pass through untouched.
        foreach (['url', 'secret'] as $k) {
            if (isset($args[$k]) && $args[$k] !== '') {
                $args[$k] = webhookDecrypt((string)$args[$k]);
            }
        }

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
                throw new Exception('the full-record format needs a trigger that carries a record with a full object (ticket, change, problem, task, asset, knowledge, contract, supplier, calendar event, software licence, or service-status incident events)');
            }
            $bodyJson = json_encode($full, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (isset(self::webhookFormats()[$preset])) {
            // A message-wrapping format — Slack, Teams, Discord, or anything the
            // admin has added under Workflows → Settings → Message formats.
            $fmt     = self::webhookFormats()[$preset];
            $message = self::argString($args, 'message', $payload);
            if ($message === '') throw new Exception('message is required for the ' . $preset . ' format');
            $bodyJson = self::renderFormatBody($fmt['body_template'], $message, $payload);
        } elseif ($preset !== 'custom') {
            // A workflow referencing a format that has since been deleted or
            // deactivated. Fail loudly — silently falling back to some other
            // shape would post a malformed payload the receiver would reject
            // for no visible reason.
            throw new Exception(
                'unknown message format "' . $preset . '" — it may have been deleted under '
                . 'Workflows → Settings → Message formats. Pick a format that still exists.'
            );
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
        // key = the payload key an event ships (e.g. 'change' for change.*); the
        // loader reproduces exactly what GET /<resource>/{id} returns by reusing
        // the REST API's own SELECT + serialiser, so `.full` is byte-for-byte the
        // API shape. We run the RAW select (not the apiLoadX() helpers) on purpose:
        // those call apiError()/exit on a missing or out-of-scope row, which would
        // abort the host module's request. Best-effort throughout — any failure is
        // swallowed so the webhook still sends (just without `.full`).
        //
        // Deferred on purpose: `cmdb.object.*` and `network_diagram.*`. Their API
        // GET hydrates deep child collections (typed properties / nodes+connectors)
        // beyond a single select+serialise; those events still carry id+name inline.
        foreach (self::fullObjectLoaders($conn) as $key => $loader) {
            if (empty($payload[$key]['id']) || isset($payload[$key]['full'])) continue;
            try {
                $full = $loader((int)$payload[$key]['id']);
                if ($full) $payload[$key]['full'] = $full;
            } catch (Throwable $e) { /* full object is best-effort */ }
        }
        return $payload;
    }

    /**
     * The registry of full-object loaders, keyed by payload key. Each loader takes
     * an id and returns the API-shaped record (or null if gone). Built per-call (not
     * a static, so it can hold closures) — cheap, since it only runs when a webhook
     * actually needs a full object. One entry per resource whose GET /{id} is a
     * clean select+serialise; add a resource here to light up its `.full`.
     */
    private static function fullObjectLoaders(PDO $conn): array
    {
        $R = dirname(__DIR__, 2);
        $one = function (string $resourceFile, string $selectFn, string $where, callable $serialize) use ($conn, $R) {
            return function (int $id) use ($conn, $R, $resourceFile, $selectFn, $where, $serialize) {
                require_once $R . '/api/v1/lib/response.php';
                require_once $R . '/api/v1/resources/' . $resourceFile;
                $stmt = $conn->prepare($selectFn() . $where);
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row ? $serialize($conn, $row) : null;
            };
        };
        return [
            'ticket'           => $one('tickets.php',        'apiTicketSelect',         ' WHERE t.id = ? LIMIT 1',  fn($c, $r) => apiSerializeTicket($r)),
            'change'           => $one('changes.php',        'apiChangeSelect',         ' WHERE c.id = ? LIMIT 1',  fn($c, $r) => apiSerializeChange($r)),
            'problem'          => $one('problems.php',        'apiProblemSelect',        ' WHERE p.id = ? LIMIT 1',  fn($c, $r) => apiSerializeProblem($r)),
            'task'             => $one('tasks.php',          'apiTaskSelect',           ' WHERE t.id = ? LIMIT 1',  fn($c, $r) => apiSerializeTask($c, $r)),
            'asset'            => $one('assets.php',          'apiAssetSelect',          ' WHERE a.id = ? LIMIT 1',  fn($c, $r) => apiSerializeAsset($c, $r)),
            'article'          => $one('knowledge.php',       'apiKnowledgeSelect',      ' WHERE a.id = ? LIMIT 1',  fn($c, $r) => apiSerializeArticle($c, $r, true)),
            'contract'         => $one('contracts.php',       'apiContractSelect',       ' WHERE c.id = ? LIMIT 1',  fn($c, $r) => apiSerializeContract($r)),
            'supplier'         => $one('contracts.php',       'apiSupplierSelect',       ' WHERE s.id = ? LIMIT 1',  fn($c, $r) => apiSerializeSupplier($r)),
            'calendar_event'   => $one('calendar.php',        'apiCalendarEventSelect',  ' WHERE e.id = ? LIMIT 1',  fn($c, $r) => apiSerializeCalendarEvent($r)),
            'software_licence' => $one('software.php',        'apiSoftwareLicenceSelect', ' WHERE l.id = ? LIMIT 1', fn($c, $r) => apiSerializeLicence($r)),
            'incident'         => $one('service_status.php',  'apiServiceIncidentSelect', ' WHERE si.id = ? LIMIT 1', fn($c, $r) => apiSerializeIncident($c, $r)),
        ];
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
