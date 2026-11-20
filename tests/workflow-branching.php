<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Workflows: branching (GH #364) - If / Else if / Otherwise, and Choose by value with an
 * ALWAYS-present Default.
 *
 *   php tests/workflow-branching.php      (from a TEST install, never a real one)
 *
 * Fires ZZ workflows by hand (WorkflowEngine::manualFire), each made of "Log a message"
 * actions whose text says which path ran, so nothing is written anywhere but the run
 * history - and removes the workflows and their runs in a finally block. Covers:
 *   - the path taken: first matching branch, else-if, Otherwise; all / any; a case by
 *     equals and by contains (an email domain); Default on no match and on a missing field
 *   - Default always present: normaliseActionTree() adds an empty one, and a run that
 *     lands on it says so
 *   - results: an action after a branch reads {{steps.N}} / {{last}} from the path that ran,
 *     and an action inside a branch never overwrites a top-level step's result
 *   - nesting: two deep runs; four deep is refused by validateActionTree()
 *   - a dry run decides the path but runs nothing; a failing action in a branch fails the run
 *   - validation messages, and webhook credentials encrypted inside a branch (round trip)
 *   - a workflow with no branches runs exactly as before (positive control)
 *
 * Control: make executeBranch() always take Otherwise / Default and the path checks fail;
 * drop the "$depth === 0" guard round mergeStepResult() and "a nested action never
 * overwrites a top-level step" fails.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../workflow/includes/engine.php';
require_once __DIR__ . '/../includes/webhook_delivery.php';

$conn = connectToDatabase();
$pass = 0; $fail = 0;
/** Records and prints one PASS or FAIL line. */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
/** A Log a message action. */
function logA(string $msg): array { return ['type' => 'log_message', 'args' => ['message' => $msg]]; }
/** The messages the run logged, in order, at any depth. */
function messages(array $steps): array
{
    $out = [];
    foreach ($steps as $s) {
        if (($s['kind'] ?? '') === 'action' && isset($s['result']['message'])) $out[] = $s['result']['message'];
        if (($s['kind'] ?? '') === 'action' && ($s['status'] ?? '') === 'dry_run') $out[] = 'DRY:' . ($s['would_args']['message'] ?? '');
        if (($s['kind'] ?? '') === 'branch') $out = array_merge($out, messages($s['steps'] ?? []));
    }
    return $out;
}
/** The first branch entry in a step log. */
function branchStep(array $steps): ?array { foreach ($steps as $s) if (($s['kind'] ?? '') === 'branch') return $s; return null; }

$made = [];
/** Store a ZZ workflow through the same validation and normalising save.php uses; returns its id. */
function makeWorkflow(array $actions, array $conditions = []): int
{
    global $conn, $made;
    $err = WorkflowEngine::validateActionTree($actions);
    if ($err !== null) throw new RuntimeException("fixture invalid: $err");
    $actions = webhookEncryptActions(WorkflowEngine::normaliseActionTree($actions));
    $conn->prepare("INSERT INTO workflows (name, description, trigger_event, conditions, actions, is_active, created_datetime, updated_datetime) VALUES ('ZZ branching test', '', 'ticket.created', ?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
         ->execute([json_encode($conditions), json_encode($actions)]);
    return $made[] = (int)$conn->lastInsertId();
}
/** Fire it with a ticket payload. */
function fire(int $id, array $ticket, bool $dry = false): array
{
    return WorkflowEngine::manualFire($id, ['ticket' => $ticket + ['id' => 0]], $dry);
}

try {
    // -------------------------------------------------------------------------------
    echo "1. If / Else if / Otherwise\n";
    $if = ['type' => 'branch_if', 'args' => [
        'branches' => [
            ['label' => 'ABC', 'match' => 'all', 'conditions' => [['field' => 'ticket.requester_email', 'op' => 'contains', 'value' => 'abc.com']], 'actions' => [logA('team A')]],
            ['label' => 'XYZ or urgent', 'match' => 'any', 'conditions' => [
                ['field' => 'ticket.requester_email', 'op' => 'contains', 'value' => 'xyz.com'],
                ['field' => 'ticket.subject', 'op' => 'contains', 'value' => 'urgent']], 'actions' => [logA('team B')]],
        ],
        'otherwise' => ['actions' => [logA('default team')]],
    ]];
    $wf = makeWorkflow([logA('before'), $if, logA('after')]);
    $r = fire($wf, ['requester_email' => 'Ann@ABC.com', 'subject' => 'hello']);
    check('first matching branch runs (contains ignores capitals)', messages($r['step_log']) === ['before', 'team A', 'after'], json_encode(messages($r['step_log'])));
    $b = branchStep($r['step_log']);
    check('  the run says which path: branch:0 "ABC"', ($b['taken'] ?? '') === 'branch:0' && ($b['taken_label'] ?? '') === 'ABC');
    $r = fire($wf, ['requester_email' => 'bob@other.org', 'subject' => 'URGENT: down']);
    check('else-if with "any" matches on its second condition', messages($r['step_log']) === ['before', 'team B', 'after'], json_encode(messages($r['step_log'])));
    $r = fire($wf, ['requester_email' => 'carol@other.org', 'subject' => 'hello']);
    check('nothing matching takes Otherwise', messages($r['step_log']) === ['before', 'default team', 'after']);
    check('  and says so', (branchStep($r['step_log'])['taken'] ?? '') === 'otherwise');
    check('  every branch checked is in the log', count(branchStep($r['step_log'])['checked'] ?? []) === 2);

    // -------------------------------------------------------------------------------
    echo "\n2. Choose by value - and its Default\n";
    $sw = ['type' => 'branch_switch', 'args' => [
        'field' => 'ticket.requester_email', 'compare' => 'contains',
        'cases' => [
            ['label' => 'ABC', 'values' => ['abc.com'], 'actions' => [logA('team A')]],
            ['label' => 'XYZ', 'values' => ['xyz.com', 'xyz.co.uk'], 'actions' => [logA('team B')]],
        ],
        'default' => ['actions' => [logA('default team')]],
    ]];
    $wf = makeWorkflow([$sw]);
    check('contains: abc.com matches ann@abc.com', messages(fire($wf, ['requester_email' => 'ann@abc.com'])['step_log']) === ['team A']);
    check('a case with several values matches any of them', messages(fire($wf, ['requester_email' => 'dan@xyz.co.uk'])['step_log']) === ['team B']);
    $r = fire($wf, ['requester_email' => 'eve@nowhere.net']);
    check('no case matching takes Default', messages($r['step_log']) === ['default team']);
    check('  reason: no_match', (branchStep($r['step_log'])['reason'] ?? '') === 'no_match');
    $r = fire($wf, ['subject' => 'no email at all']);
    check('a MISSING field takes Default, it does not break', $r['status'] === 'success' && messages($r['step_log']) === ['default team'], $r['status']);
    check('  reason: missing', (branchStep($r['step_log'])['reason'] ?? '') === 'missing');

    $eq = ['type' => 'branch_switch', 'args' => ['field' => 'ticket.priority_id', 'compare' => 'equals',
        'cases' => [['label' => 'P1', 'values' => ['4'], 'actions' => [logA('page on-call')]]], 'default' => ['actions' => []]]];
    $wf = makeWorkflow([$eq]);
    check('equals matches a number against the saved text "4"', messages(fire($wf, ['priority_id' => 4])['step_log']) === ['page on-call']);
    $r = fire($wf, ['priority_id' => 2]);
    check('an empty Default runs nothing - and the run still says it took Default', messages($r['step_log']) === [] && (branchStep($r['step_log'])['taken'] ?? '') === 'default' && !empty(branchStep($r['step_log'])['nothing_to_do']));

    // -------------------------------------------------------------------------------
    echo "\n3. Default is always there\n";
    $n = WorkflowEngine::normaliseActionTree([['type' => 'branch_switch', 'args' => ['field' => 'ticket.subject', 'cases' => [['values' => 'a, b', 'actions' => []]]]]]);
    check('a Choose saved without a Default gets an empty one', isset($n[0]['args']['default']['actions']) && $n[0]['args']['default']['actions'] === []);
    check('  and comma-separated values become a list', $n[0]['args']['cases'][0]['values'] === ['a', 'b']);
    $n = WorkflowEngine::normaliseActionTree([['type' => 'branch_if', 'args' => ['branches' => [['conditions' => [['field' => 'ticket.subject', 'op' => 'contains', 'value' => 'x']]]]]]]);
    check('an If saved without an Otherwise gets an empty one', ($n[0]['args']['otherwise']['actions'] ?? null) === []);
    $plain = [logA('one'), ['type' => 'send_email', 'args' => ['to' => 'x@example.invalid'], 'x' => 10, 'y' => 20]];
    check('a list with no branches is stored exactly as it was', WorkflowEngine::normaliseActionTree($plain) === $plain);

    // -------------------------------------------------------------------------------
    echo "\n4. Results carry on after a branch\n";
    $wf = makeWorkflow([logA('first'), ['type' => 'branch_if', 'args' => [
        'branches' => [['match' => 'all', 'conditions' => [['field' => 'ticket.subject', 'op' => 'is_not_empty', 'value' => null]], 'actions' => [logA('inside one'), logA('inside two')]]],
        'otherwise' => ['actions' => []]]],
        logA('after: {{steps.2.message}} / {{last.message}} / first was {{steps.1.message}}')]);
    $msgs = messages(fire($wf, ['subject' => 'x'])['step_log']);
    check('after the branch, {{steps.N}} and {{last}} are what the path produced last', ($msgs[3] ?? '') === 'after: inside two / inside two / first was first', $msgs[3] ?? '');
    check('a nested action never overwrites a top-level step ({{steps.1}} is still "first")', str_ends_with($msgs[3] ?? '', 'first was first'));

    // -------------------------------------------------------------------------------
    echo "\n5. Nesting\n";
    $inner = ['type' => 'branch_switch', 'args' => ['field' => 'ticket.priority_id', 'compare' => 'equals',
        'cases' => [['label' => 'P1', 'values' => ['4'], 'actions' => [logA('ABC and P1')]]], 'default' => ['actions' => [logA('ABC, not P1')]]]];
    $wf = makeWorkflow([['type' => 'branch_if', 'args' => ['branches' => [['match' => 'all',
        'conditions' => [['field' => 'ticket.requester_email', 'op' => 'contains', 'value' => 'abc.com']], 'actions' => [$inner]]],
        'otherwise' => ['actions' => [logA('not ABC')]]]]]);
    check('a branch inside a branch: outer path, then inner path', messages(fire($wf, ['requester_email' => 'a@abc.com', 'priority_id' => 4])['step_log']) === ['ABC and P1']);
    check('  inner Default', messages(fire($wf, ['requester_email' => 'a@abc.com', 'priority_id' => 1])['step_log']) === ['ABC, not P1']);
    $deep = logA('too deep');
    for ($d = 0; $d < 4; $d++) $deep = ['type' => 'branch_switch', 'args' => ['field' => 'ticket.subject', 'cases' => [['values' => ['x'], 'actions' => [$deep]]], 'default' => ['actions' => []]]];
    $err = WorkflowEngine::validateActionTree([$deep]);
    check('four deep is refused, saying why', $err !== null && str_contains($err, 'deep'), (string)$err);
    $three = logA('ok');
    for ($d = 0; $d < 3; $d++) $three = ['type' => 'branch_switch', 'args' => ['field' => 'ticket.subject', 'cases' => [['values' => ['x'], 'actions' => [$three]]], 'default' => ['actions' => []]]];
    check('three deep is allowed', WorkflowEngine::validateActionTree([$three]) === null);

    // -------------------------------------------------------------------------------
    echo "\n6. Dry run and failure\n";
    $wf = makeWorkflow([$sw]);
    $r = fire($wf, ['requester_email' => 'ann@abc.com'], true);
    check('a dry run decides the path for real', (branchStep($r['step_log'])['taken'] ?? '') === 'case:0');
    check('  and only previews the path\'s actions', messages($r['step_log']) === ['DRY:team A'], json_encode(messages($r['step_log'])));
    $wf = makeWorkflow([['type' => 'branch_if', 'args' => ['branches' => [['match' => 'all', 'conditions' => [['field' => 'ticket.subject', 'op' => 'is_not_empty', 'value' => null]],
        'actions' => [['type' => 'set_ticket_priority', 'args' => ['ticket_id' => '', 'priority_id' => '1']]]]], 'otherwise' => ['actions' => []]]], logA('must not run')]);
    $r = fire($wf, ['subject' => 'x']);
    check('a failing action inside a branch fails the run', $r['status'] === 'failed', $r['status']);
    check('  and stops it: nothing after the branch runs', !in_array('must not run', messages($r['step_log']), true));

    // -------------------------------------------------------------------------------
    echo "\n7. Validation\n";
    check('an If with no branches is refused', WorkflowEngine::validateActionTree([['type' => 'branch_if', 'args' => ['branches' => []]]]) !== null);
    check('a branch with no conditions is refused', WorkflowEngine::validateActionTree([['type' => 'branch_if', 'args' => ['branches' => [['conditions' => [], 'actions' => []]]]]]) !== null);
    check('a Choose with no field is refused', WorkflowEngine::validateActionTree([['type' => 'branch_switch', 'args' => ['field' => '', 'cases' => [['values' => ['x']]]]]]) !== null);
    check('an unknown action inside a branch is refused', str_contains((string)WorkflowEngine::validateActionTree([['type' => 'branch_switch', 'args' => ['field' => 'ticket.subject', 'cases' => [['values' => ['x'], 'actions' => [['type' => 'launch_rockets', 'args' => []]]]]]]]), 'launch_rockets'));
    check('plain actions still validate as before', WorkflowEngine::validateActionTree([logA('x')]) === null);

    // -------------------------------------------------------------------------------
    echo "\n8. Webhook credentials inside a branch\n";
    $hook = ['type' => 'send_webhook', 'args' => ['url' => 'https://hooks.example.invalid/abc', 'secret' => 's3cret']];
    $tree = [['type' => 'branch_switch', 'args' => ['field' => 'ticket.subject', 'cases' => [['values' => ['x'], 'actions' => [$hook]]], 'default' => ['actions' => [$hook]]]]];
    $enc = webhookEncryptActions($tree);
    $u1 = $enc[0]['args']['cases'][0]['actions'][0]['args']['url'];
    $u2 = $enc[0]['args']['default']['actions'][0]['args']['url'];
    check('a webhook URL inside a case is encrypted at rest', $u1 !== $hook['args']['url'] && $u1 !== '');
    check('  and inside Default', $u2 !== $hook['args']['url'] && $u2 !== '');
    $dec = webhookDecryptActions($enc);
    check('  and decrypts back for the editor - no double encryption on the next save', $dec[0]['args']['cases'][0]['actions'][0]['args'] === $hook['args']);

    // -------------------------------------------------------------------------------
    echo "\n9. A workflow with no branches (positive control)\n";
    $wf = makeWorkflow([logA('a {{ticket.subject}}'), logA('b {{last.message}}'), logA('c {{steps.1.message}}')], [['field' => 'ticket.subject', 'op' => 'contains', 'value' => 'hi']]);
    $r = fire($wf, ['subject' => 'hi there']);
    check('runs as before, results chained', messages($r['step_log']) === ['a hi there', 'b a hi there', 'c a hi there'], json_encode(messages($r['step_log'])));
    check('  conditions not met: skipped, as before', fire($wf, ['subject' => 'bye'])['status'] === 'skipped');
} finally {
    foreach ($made as $id) {
        $conn->prepare('DELETE FROM workflow_executions WHERE workflow_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM workflows WHERE id = ?')->execute([$id]);
    }
}

echo "\n" . str_repeat('-', 70) . "\n  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
