<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The workflow action "Add a note to the ticket" (GitHub #268): "A note on the
 * ticket" writes a real note by System; "History only" - and every action saved
 * before the option existed - writes the history entry it always did.
 *
 *   FREEITSM_TEST_DATABASE=<the throwaway database config.php uses> php tests/workflow-ticket-note.php
 *
 * Runs the actions through WorkflowEngine::runActionList() against an existing
 * ticket; deletes the notes, history rows and runs it made in finally.
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

$tag = 'ZZ268-' . bin2hex(random_bytes(3));
$runs = []; $tid = 0;
try {
    $tid = (int)$conn->query("SELECT id FROM tickets ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$tid) throw new RuntimeException('This database has no ticket to add a note to.');
    $run = function (array $args) use ($tid, &$runs): array {
        $r = WorkflowEngine::runActionList('ZZ268 test', 'ticket.created',
            [['type' => 'add_ticket_note', 'args' => ['ticket_id' => '{{ticket.id}}'] + $args]],
            ['ticket' => ['id' => $tid]]);
        if (!empty($r['execution_id'])) $runs[] = (int)$r['execution_id'];
        return $r;
    };
    $note  = fn(string $text) => $conn->query("SELECT analyst_id, is_internal FROM ticket_notes WHERE ticket_id = $tid AND note_text = " . $conn->quote($text))->fetch(PDO::FETCH_ASSOC);
    $audit = fn(string $text) => (int)$conn->query("SELECT COUNT(*) FROM ticket_audit WHERE ticket_id = $tid AND field_name = 'Workflow Note' AND analyst_id IS NULL AND new_value = " . $conn->quote($text))->fetchColumn();

    echo "A workflow saved before the option existed\n";
    $r = $run(['note' => "$tag old"]);
    check('it writes the history entry, as it always did', ($r['status'] ?? '') === 'success' && $audit("$tag old") === 1, json_encode($r['steps'][0]['error'] ?? null));
    check('...and no note', $note("$tag old") === false);

    echo "\nHistory only\n";
    $run(['note' => "$tag hist", 'write_to' => '']);
    check('the history, not a note', $audit("$tag hist") === 1 && $note("$tag hist") === false);

    echo "\nA note on the ticket\n";
    $r = $run(['note' => "$tag note {{ticket.id}}", 'write_to' => 'note']);
    $n = $note("$tag note $tid");
    check('a real note, with merge codes filled in', ($r['status'] ?? '') === 'success' && $n !== false, json_encode($r['steps'][0]['error'] ?? $r['steps'][0]['output'] ?? null));
    check('...written by System (no analyst)', $n && $n['analyst_id'] === null);
    check('...internal by default', $n && (int)$n['is_internal'] === 1);
    check('...and not also in the history', $audit("$tag note $tid") === 0);
    $run(['note' => "$tag shared", 'write_to' => 'note', 'shared' => true]);
    $n = $note("$tag shared");
    check('shared with the requester when ticked', $n && (int)$n['is_internal'] === 0);

    echo "\nThe designer\n";
    $def = WorkflowEngine::availableActions()['add_ticket_note']['args']['write_to'] ?? [];
    check('a NEW action defaults to a note (new_default)', ($def['new_default'] ?? null) === 'note');
    check('...but has no `default`, which would flip a saved one when its panel is opened', !array_key_exists('default', $def));
    check('the empty value is "History only"', ($def['options'][0]['value'] ?? null) === '');
} finally {
    if ($tid) {
        $conn->prepare("DELETE FROM ticket_notes WHERE ticket_id = ? AND note_text LIKE ?")->execute([$tid, "$tag%"]);
        $conn->prepare("DELETE FROM ticket_audit WHERE ticket_id = ? AND field_name = 'Workflow Note' AND new_value LIKE ?")->execute([$tid, "$tag%"]);
    }
    foreach ($runs as $id) {
        try { $conn->prepare("DELETE FROM workflow_executions WHERE id = ?")->execute([$id]); } catch (Throwable $e) {}
    }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
