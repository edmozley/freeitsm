<?php
/**
 * REST API v1 - checklists on tickets and tasks (GitHub #273).
 *
 *   GET   /tickets/{id}/checklists                    ticket_checklists.read
 *   PATCH /tickets/{id}/checklists/steps/{step_id}     ticket_checklists.update
 *   GET   /tasks/{id}/checklists                      task_checklists.read
 *   PATCH /tasks/{id}/checklists/steps/{step_id}       task_checklists.update
 *
 * One shape for both: since #271 tickets and tasks share ChecklistsService, so a
 * step is the same thing on either, and an integration reads and answers them
 * the same way.
 *
 * Writes go through the SAME service methods as the panels, so the server's
 * rules apply unchanged: a value the step asks for, a result rather than a bare
 * tick, a note on Fail, a completed task's lock (Tasks -> Settings -> Evidence).
 * They are recorded like any other change - the ticket's step note and the
 * task's step history - and marked "via the API".
 *
 * The company scope is the parent's: apiLoadTicket / apiLoadTask answer 404 for
 * a ticket or task the key may not see, and a step is then refused unless it
 * belongs to that ticket or task - never reached by its id alone.
 */

require_once __DIR__ . '/../../../includes/services/checklists.php';

/**
 * A checklist as the API returns it - the same for a ticket and a task.
 *
 * @param array $c  one row from ChecklistsService::ticketChecklists() / taskChecklists()
 */
function apiChecklistShape(array $c): array {
    return [
        'id'               => (int)$c['id'],
        'title'            => (string)$c['title'],
        'template_id'      => $c['template_id'] !== null ? (int)$c['template_id'] : null,
        'template_version' => isset($c['template_version']) && $c['template_version'] !== null ? (int)$c['template_version'] : null,
        // What closing / completing does with outstanding mandatory steps, after the
        // company setting: 'warn' (allowed and recorded) or 'block' (refused).
        'closure'          => ($c['effective_closure_mode'] ?? 'warn') === 'block' ? 'block' : 'warn',
        'steps_done'       => (int)($c['completed_items'] ?? 0),
        'steps_total'      => (int)($c['total_items'] ?? 0),
        'steps'            => array_map('apiChecklistStepShape', $c['items'] ?? []),
    ];
}

/**
 * One step. `asks_for` says how it is answered: 'tick', 'value' (a tick with a
 * text answer) or 'result' (pass / fail / na, with a note on fail).
 *
 * @param array $i  one step row from the service
 */
function apiChecklistStepShape(array $i): array {
    $asks = ($i['result_mode'] ?? 'none') === 'pass_fail' ? 'result' : ((int)($i['requires_input'] ?? 0) === 1 ? 'value' : 'tick');
    return [
        'id'             => (int)$i['id'],
        'title'          => (string)$i['title'],
        'description'    => ($i['description'] ?? '') !== '' ? (string)$i['description'] : null,
        'mandatory'      => (int)($i['is_mandatory'] ?? 0) === 1,
        'suggested_role' => ($i['suggested_role'] ?? '') !== '' ? (string)$i['suggested_role'] : null,
        'asks_for'       => $asks,
        'value_prompt'   => $asks === 'value' && ($i['input_placeholder'] ?? '') !== '' ? (string)$i['input_placeholder'] : null,
        'completed'      => (int)($i['is_completed'] ?? 0) === 1,
        'value'          => ($i['response_value'] ?? '') !== '' ? (string)$i['response_value'] : null,
        'result'         => in_array($i['result'] ?? null, ['pass', 'fail', 'na'], true) ? $i['result'] : null,
        'note'           => ($i['exception_note'] ?? '') !== '' ? (string)$i['exception_note'] : null,
        'completed_by'   => ($i['completed_by_name'] ?? '') !== '' ? (string)$i['completed_by_name'] : null,
        'completed_at'   => !empty($i['completed_datetime']) ? apiIsoDate($i['completed_datetime']) : null,
    ];
}

/** GET /tickets/{id}/checklists - every checklist on the ticket, with its steps. */
function apiTicketChecklistsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, (int)$params[0]);   // 404 outside the key's companies
    apiRespond(array_map('apiChecklistShape', ChecklistsService::ticketChecklists($conn, (int)$params[0])));
}

/** GET /tasks/{id}/checklists - empty while checklists on tasks are switched off (Tasks -> Settings -> Checklists). */
function apiTaskChecklistsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTask($conn, (int)$params[0], $apiKey);
    if (!ChecklistsService::tasksEnabled($conn)) apiRespond([]);
    apiRespond(array_map('apiChecklistShape', ChecklistsService::taskChecklists($conn, (int)$params[0])));
}

/**
 * PATCH /tickets/{id}/checklists/steps/{step_id}
 *
 * Body, one of:
 *   { "completed": true, "value": "AT-1234" }   tick (value only for a step that asks for one)
 *   { "completed": false }                      untick - clears its value and any result
 *   { "result": "fail", "note": "..." }         pass / fail / na on a step that asks for a result
 */
function apiTicketChecklistStepUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, (int)$params[0]);
    apiChecklistStepApply($conn, $apiKey, 'ticket', (int)$params[0], (int)$params[1], $body);
}

/** PATCH /tasks/{id}/checklists/steps/{step_id} - the same body as on a ticket. */
function apiTaskChecklistStepUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTask($conn, (int)$params[0], $apiKey);
    apiChecklistStepApply($conn, $apiKey, 'task', (int)$params[0], (int)$params[1], $body);
}

/**
 * Tick, untick or record a result on one step, then answer with the step as it now stands.
 *
 * @param string $kind  'ticket' or 'task'
 * @param int $ownerId  the ticket or task id from the path, already gated
 * @param int $stepId  the step id from the path; must belong to $ownerId
 */
function apiChecklistStepApply(PDO $conn, array $apiKey, string $kind, int $ownerId, int $stepId, array $body): void {
    try {
        $owner = $kind === 'ticket' ? ChecklistsService::ticketIdForItem($conn, $stepId) : ChecklistsService::taskIdForItem($conn, $stepId);
        if ($owner !== $ownerId) {
            throw new ServiceError('not_found', 'not_found', 'That step is not on this ' . $kind . '.');
        }
        if ($kind === 'task' && !ChecklistsService::tasksEnabled($conn)) {
            throw new ServiceError('validation', 'disabled', 'Checklists on tasks are switched off (Tasks -> Settings -> Checklists).');
        }
        $hasResult = array_key_exists('result', $body);
        $hasTick   = array_key_exists('completed', $body);
        if ($hasResult === $hasTick) {
            throw new ServiceError('validation', 'invalid_field', 'Send either "completed" (to tick or untick) or "result" (pass, fail or na) - one of them.');
        }
        $ctx = ActorContext::fromApiKey($apiKey);
        if ($hasResult) {
            $note = isset($body['note']) ? (string)$body['note'] : null;
            $kind === 'ticket'
                ? ChecklistsService::setTicketItemResult($conn, $ctx, $stepId, (string)$body['result'], $note)
                : ChecklistsService::setTaskItemResult($conn, $ctx, $stepId, (string)$body['result'], $note);
        } else {
            if (!is_bool($body['completed'])) {
                throw new ServiceError('validation', 'invalid_field', '"completed" must be true or false.');
            }
            $value = isset($body['value']) ? (string)$body['value'] : null;
            $kind === 'ticket'
                ? ChecklistsService::toggleTicketItem($conn, $ctx, $stepId, $body['completed'], $value)
                : ChecklistsService::toggleTaskItem($conn, $ctx, $stepId, $body['completed'], $value);
        }
        $lists = $kind === 'ticket' ? ChecklistsService::ticketChecklists($conn, $ownerId) : ChecklistsService::taskChecklists($conn, $ownerId);
        foreach ($lists as $c) {
            foreach ($c['items'] as $i) {
                if ((int)$i['id'] === $stepId) apiRespond(apiChecklistStepShape($i));
            }
        }
        throw new ServiceError('not_found', 'not_found', 'Step not found.');
    } catch (ServiceError $e) { apiFailFromService($e); }
}
