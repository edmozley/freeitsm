<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A webhook's Full record on form events (GitHub #326, #327).
 *
 *   FREEITSM_TEST_DATABASE=<the throwaway database config.php uses> php tests/form-webhook-full-record.php
 *
 * Until 5.2.0 a form submission had no `.full`, so a send_webhook in Full record format
 * failed on "A form submission is received" - in a workflow with "the full-record format
 * needs a trigger that carries a record..." (#327), and in the form's own What happens
 * next with nothing reaching the webhook queue (#326). This submits a ZZ form through
 * FormsService and checks both routes queue the submission exactly as the REST API's
 * GET /forms/{id}/submissions/{id} shapes it, and that {{submission.full}} works in a
 * Custom body. Nothing is sent: the deliveries are read and deleted before the delivery
 * job could pick them up (and their address is example.invalid). Other active workflows
 * on form.submitted are paused for the run and put back; every ZZ row is removed in finally.
 *
 * @wiki Issue-326-Form-Webhook-Full-Record
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/forms.php';
require_once __DIR__ . '/../includes/webhook_delivery.php';
require_once __DIR__ . '/../workflow/includes/engine.php';
require_once __DIR__ . '/../api/v1/lib/response.php';
require_once __DIR__ . '/../api/v1/resources/forms.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
$db = $conn->query('SELECT DATABASE()')->fetchColumn();
if (!$expected || $expected !== $db) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the throwaway database config.php uses ({$db}).\n";
    exit(0);
}

$tag = 'ZZ326-' . bin2hex(random_bytes(3));
$forms = []; $subs = []; $wfs = []; $paused = [];
$before = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM workflow_executions")->fetchColumn();
try {
    $paused = $conn->query("SELECT id FROM workflows WHERE is_active = 1 AND trigger_event = 'form.submitted'")->fetchAll(PDO::FETCH_COLUMN);
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 0 WHERE id IN (" . implode(',', array_map('intval', $paused)) . ")");
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext(actorId: $admin, source: 'ui');
    $_SESSION = ['analyst_id' => $admin];

    $hook = fn(string $preset, string $body = '') => ['type' => 'send_webhook', 'args' => [
        'preset' => $preset, 'url' => "https://example.invalid/$tag", 'body' => $body, 'message' => '']];
    $mkForm = function (array $actions) use ($conn, $ctx, $tag, &$forms): int {
        $id = (int)FormsService::saveForm($conn, $ctx, ['title' => "$tag form", 'is_portal_visible' => 1,
            'fields' => [['field_type' => 'text', 'label' => "$tag what"], ['field_type' => 'checkboxes', 'label' => "$tag which", 'options' => ['A', 'B']]],
            'submission_actions' => ['submitted' => $actions]])['id'];
        return $forms[] = $id;
    };
    $submit = function (int $formId) use ($conn, $ctx, $tag, &$subs): int {
        $f = $conn->query("SELECT id, field_type FROM form_fields WHERE form_id = $formId ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($f as $x) $data[(int)$x['id']] = $x['field_type'] === 'checkboxes' ? json_encode(['A', 'B']) : "$tag answer";
        return $subs[] = FormsService::submitForm($conn, $ctx, $formId, $data);
    };
    $runs = function () use ($conn, &$before): array {
        $r = $conn->query("SELECT * FROM workflow_executions WHERE id > $before ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if ($r) $before = (int)end($r)['id'];
        return $r;
    };
    $deliveries = fn(int $execId) => $conn->query("SELECT * FROM webhook_deliveries WHERE execution_id = $execId")->fetchAll(PDO::FETCH_ASSOC);
    $apiShape = function (int $subId) use ($conn): array {
        $row = $conn->query(apiFormSubmissionsSelect() . " WHERE s.id = $subId")->fetch(PDO::FETCH_ASSOC);
        return apiSerializeFormSubmission($conn, $row);
    };

    // ---- 1. #326: the form's own What happens next, Full record ----
    $f1 = $mkForm([$hook('full')]);
    $s1 = $submit($f1);
    $r = $runs();
    $run = $r[0] ?? [];
    check("#326: the form's own list ran and succeeded", ($run['status'] ?? '') === 'success', json_encode([$run['status'] ?? null, $run['error_message'] ?? null]));
    $d = $run ? $deliveries((int)$run['id']) : [];
    check('#326: one delivery reached the webhook queue', count($d) === 1, (string)count($d));
    $body = json_decode((string)($d[0]['request_body'] ?? ''), true);
    check('#326: its body is the submission exactly as GET /forms/{id}/submissions/{id} returns it', $body === $apiShape($s1), substr((string)($d[0]['request_body'] ?? ''), 0, 200));
    check('#326: every answer is there, checkboxes as a list', is_array($body) && count($body['answers'] ?? []) === 2 && ($body['answers'][1]['value'] ?? null) === ['A', 'B']);

    // ---- 2. #327: a workflow on "A form submission is received", Full record ----
    $f2 = $mkForm([]);
    $conn->prepare("INSERT INTO workflows (name, trigger_event, conditions, actions, is_active) VALUES (?, 'form.submitted', ?, ?, 1)")->execute([
        "$tag workflow", json_encode([['field' => 'form.id', 'op' => 'equals', 'value' => (string)$f2]]),
        json_encode(webhookEncryptActions([$hook('full')])),
    ]);
    $wfs[] = $wf = (int)$conn->lastInsertId();
    $s2 = $submit($f2);
    $r = array_values(array_filter($runs(), fn($x) => (int)$x['workflow_id'] === $wf));
    $run = $r[0] ?? [];
    check('#327: the workflow ran without the full-record error', ($run['status'] ?? '') === 'success', json_encode([$run['status'] ?? null, $run['error_message'] ?? null]));
    $d = $run ? $deliveries((int)$run['id']) : [];
    $body = json_decode((string)($d[0]['request_body'] ?? ''), true);
    check('#327: it queued the submission, API-shaped', count($d) === 1 && $body === $apiShape($s2), substr((string)($d[0]['request_body'] ?? ''), 0, 200));

    // ---- 3. {{submission.full}} in a Custom body ----
    $f3 = $mkForm([$hook('custom', '{"event":"{{event}}","record":{{submission.full}}}')]);
    $s3 = $submit($f3);
    // The form's own run (workflow_id NULL) - the step-2 workflow also fires, and skips on its condition.
    $run = array_values(array_filter($runs(), fn($x) => $x['workflow_id'] === null))[0] ?? [];
    $d = $run ? $deliveries((int)$run['id']) : [];
    $body = json_decode((string)($d[0]['request_body'] ?? ''), true);
    check('{{submission.full}} embeds the submission in a Custom body', ($body['record'] ?? null) === $apiShape($s3) && ($body['event'] ?? '') === 'form.submitted', substr((string)($d[0]['request_body'] ?? ''), 0, 200));

    // ---- 4. the merge-code list knows it ----
    check('FULL_OBJECT_KEYS lists submission (the merge-code picker reads it)', in_array('submission', WorkflowEngine::FULL_OBJECT_KEYS, true));
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $execs = $conn->query("SELECT id FROM workflow_executions WHERE workflow_name LIKE " . $conn->quote("%$tag%") . ($wfs ? " OR workflow_id IN (" . implode(',', $wfs) . ")" : ''))->fetchAll(PDO::FETCH_COLUMN);
    if ($execs) {
        $in = implode(',', array_map('intval', $execs));
        $conn->exec("DELETE FROM webhook_deliveries WHERE execution_id IN ($in)");
        $conn->exec("DELETE FROM workflow_executions WHERE id IN ($in)");
    }
    if ($wfs) $conn->exec("DELETE FROM workflows WHERE id IN (" . implode(',', $wfs) . ")");
    foreach ($subs as $s) { $conn->exec("DELETE FROM form_submission_data WHERE submission_id = " . (int)$s); $conn->exec("DELETE FROM form_submissions WHERE id = " . (int)$s); }
    foreach ($forms as $f) { $conn->exec("DELETE FROM form_fields WHERE form_id = " . (int)$f); $conn->exec("DELETE FROM forms WHERE id = " . (int)$f); }
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 1 WHERE id IN (" . implode(',', array_map('intval', $paused)) . ")");
    $left = (int)$conn->query("SELECT COUNT(*) FROM forms WHERE title LIKE " . $conn->quote("$tag%"))->fetchColumn();
    check('cleaned up', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
