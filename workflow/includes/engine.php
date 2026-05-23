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
    //  Catalogue — triggers, actions, operators, fields per trigger
    // -----------------------------------------------------------------

    /**
     * Events the engine knows about. Display labels are i18n-ready strings
     * but kept plain English here for simplicity in v1 — the editor UI
     * shows them as-is. Adding a trigger = one entry here + a single
     * dispatch() call from the corresponding module's save flow.
     *
     * NOTE: trigger wiring from host modules is being added in subsequent
     * commits — the catalogue is here so the editor UI is complete, but
     * not every entry is firing yet.
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
        $byTrigger = [
            'ticket.created' => [
                'ticket.id', 'ticket.subject', 'ticket.priority_id', 'ticket.status_id',
                'ticket.department_id', 'ticket.type_id', 'ticket.assigned_analyst_id',
                'ticket.created_by', 'ticket.requester_email',
            ],
            'ticket.status_changed' => [
                'ticket.id', 'old_status_id', 'new_status_id',
                'ticket.priority_id', 'ticket.assigned_analyst_id',
            ],
            'ticket.priority_changed' => [
                'ticket.id', 'old_priority_id', 'new_priority_id',
                'ticket.status_id', 'ticket.assigned_analyst_id',
            ],
            'ticket.assigned' => [
                'ticket.id', 'analyst_id', 'team_id',
            ],
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
            'contains'     => 'contains',
            'not_contains' => 'does not contain',
            'gt'           => 'greater than',
            'lt'           => 'less than',
            'is_empty'     => 'is empty',
            'is_not_empty' => 'is not empty',
        ];
    }

    /**
     * Action handlers available to the engine. v1 ships just `log_message`
     * so the engine is exercisable end-to-end (build a workflow, fire it,
     * see the log entry) without needing every host module to be wired up.
     * Subsequent commits will add set_ticket_status, send_email, create_task,
     * graph_add_to_group, etc.
     */
    public static function availableActions(): array
    {
        return [
            'log_message' => [
                'label'       => 'Log a message',
                'description' => 'Write a message into this workflow\'s execution log. Useful as a placeholder while you scaffold a rule and as a test for the engine itself.',
                'args'        => ['message' => 'string'],
            ],
        ];
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

    private static function run(array $wf, string $event, array $payload, bool $isManual): array
    {
        $conn = connectToDatabase();
        $stepLog = [];
        $status  = 'success';
        $errorMessage = null;

        // Insert a "running" execution row so we have an id to update.
        $insert = $conn->prepare(
            "INSERT INTO workflow_executions
             (workflow_id, trigger_event, trigger_payload, status, started_datetime)
             VALUES (?, ?, ?, 'running', UTC_TIMESTAMP())"
        );
        $insert->execute([
            (int)$wf['id'],
            $event,
            json_encode($payload),
        ]);
        $execId = (int)$conn->lastInsertId();

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
            case 'contains':     return is_string($actual) && is_string($value) && $value !== '' && strpos($actual, $value) !== false;
            case 'not_contains': return !(is_string($actual) && is_string($value) && $value !== '' && strpos($actual, $value) !== false);
            case 'gt':           return is_numeric($actual) && is_numeric($value) && $actual > $value;
            case 'lt':           return is_numeric($actual) && is_numeric($value) && $actual < $value;
            case 'is_empty':     return $actual === null || $actual === '' || $actual === [];
            case 'is_not_empty': return !($actual === null || $actual === '' || $actual === []);
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
            case 'log_message':
                return self::action_log_message($args, $payload);
            default:
                throw new Exception("Unknown action type: {$type}");
        }
    }

    private static function action_log_message(array $args, array $payload): array
    {
        $message = (string)($args['message'] ?? '');
        // Echo the message verbatim into the step log result — the engine
        // already writes the step log row, so there's nothing else to do.
        // Variables in the form {{ticket.id}} aren't resolved in v1.
        return ['message' => $message];
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
