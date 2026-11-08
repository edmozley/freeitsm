<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Workflows: the "Set a ticket field" action (GH #278) - one custom ticket field,
 * its value from {{merge codes}}, checked by the field's type, recorded in history.
 *
 *   FREEITSM_TEST_DATABASE=<the throwaway database config.php uses> php tests/workflow-set-ticket-field.php
 *
 * Makes two ZZ custom fields (text and number, shared, on every ticket type), runs real
 * action lists against an existing ticket through WorkflowEngine::runActionList(), and
 * deletes the fields, their values, their history rows and the runs in finally.
 *
 * @wiki Workflows
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../workflow/includes/engine.php';

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

$tag = 'zz278_' . bin2hex(random_bytes(3));
$fields = []; $runs = []; $tid = 0;
try {
    $tid = (int)$conn->query("SELECT id FROM tickets ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$tid) throw new RuntimeException('This database has no ticket to set a field on.');
    $mk = function (string $key, string $label, string $type) use ($conn, &$fields): int {
        $conn->prepare("INSERT INTO ticket_fields (field_key, label, field_type, all_types, tenant_id) VALUES (?, ?, ?, 1, NULL)")
             ->execute([$key, $label, $type]);
        return $fields[$key] = (int)$conn->lastInsertId();
    };
    $textId = $mk("{$tag}_ref", "ZZ278 Reference $tag", 'text');
    $numId  = $mk("{$tag}_qty", "ZZ278 Quantity $tag", 'number');

    $run = function (string $key, string $value) use ($tid, &$runs): array {
        $r = WorkflowEngine::runActionList('ZZ278 test', 'ticket.created',
            [['type' => 'set_ticket_field', 'args' => ['ticket_id' => '{{ticket.id}}', 'field_key' => $key, 'value' => $value]]],
            ['ticket' => ['id' => $tid]]);
        if (!empty($r['execution_id'])) $runs[] = (int)$r['execution_id'];
        return $r;
    };
    $stored = function (int $fieldId, string $col) use ($conn, $tid) {
        $s = $conn->prepare("SELECT `$col` FROM ticket_field_values WHERE ticket_id = ? AND field_id = ?");
        $s->execute([$tid, $fieldId]);
        return $s->fetchColumn();
    };

    $lookup = WorkflowEngine::availableActionLookup('ticket_custom_field');
    check('the editor offers the custom fields', in_array("{$tag}_ref", array_column((array)$lookup, 'id'), true));

    $r = $run("{$tag}_ref", 'T-{{ticket.id}}');
    check('a text field is set from a merge code', ($r['status'] ?? '') === 'success' && $stored($textId, 'value_text') === "T-$tid",
        json_encode([$r['status'] ?? null, $stored($textId, 'value_text'), $r['steps'][0]['error'] ?? null]));

    $hist = $conn->prepare("SELECT COUNT(*) FROM ticket_audit WHERE ticket_id = ? AND field_name = ? AND analyst_id IS NULL");
    $hist->execute([$tid, "ZZ278 Reference $tag"]);
    check('the change is in the ticket history, with no analyst against it', (int)$hist->fetchColumn() === 1);

    $r = $run("{$tag}_qty", '42');
    check('a number field is set', ($r['status'] ?? '') === 'success' && (float)$stored($numId, 'value_number') === 42.0,
        json_encode([$r['status'] ?? null, $stored($numId, 'value_number'), $r['steps'][0]['error'] ?? null]));

    $r = $run("{$tag}_qty", 'not a number');
    check('a value the field type refuses fails the step and says why', ($r['status'] ?? '') === 'failed'
        && (float)$stored($numId, 'value_number') === 42.0, json_encode([$r['status'] ?? null, $r['steps'][0]['error'] ?? null]));

    $r = $run("{$tag}_ref", '');
    $left = $stored($textId, 'value_text');
    check('an empty value clears the field', ($r['status'] ?? '') === 'success' && ($left === false || $left === null || $left === ''),
        json_encode([$r['status'] ?? null, $left]));

    $r = $run('zz278_no_such_field', 'x');
    check('a field the ticket does not have fails the step', ($r['status'] ?? '') === 'failed', json_encode($r['steps'][0]['error'] ?? null));
} catch (Throwable $e) {
    check('the test ran: ' . $e->getMessage(), false);
} finally {
    foreach ($fields as $key => $id) {
        $conn->prepare("DELETE FROM ticket_field_values WHERE field_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM ticket_audit WHERE ticket_id = ? AND field_name LIKE ?")->execute([$tid, 'ZZ278 %' . $tag]);
        try { $conn->prepare("DELETE FROM ticket_field_types WHERE field_id = ?")->execute([$id]); } catch (Throwable $e) {}
        $conn->prepare("DELETE FROM ticket_fields WHERE id = ?")->execute([$id]);
    }
    foreach ($runs as $id) {
        try { $conn->prepare("DELETE FROM workflow_executions WHERE id = ?")->execute([$id]); } catch (Throwable $e) {}
    }
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
