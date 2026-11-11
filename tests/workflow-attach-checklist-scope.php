<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The workflow action "Attach checklist" and a checklist that is for tasks only.
 *
 *   FREEITSM_TEST_DATABASE=<the throwaway database config.php uses> php tests/workflow-attach-checklist-scope.php
 *
 * The picker offers checklists for tickets (and both) only. A workflow saved before its checklist
 * was made tasks-only does not fail any more: that checklist is skipped, the others are attached,
 * the later actions still run - and the run leaves a note that the run history's "Needs a look"
 * filter and the Watchtower Workflows card both pick up. (It used to fail the whole run with
 * "That checklist is for tasks only.", stopping every action after it.)
 *
 * Makes two ZZ checklist templates and attaches one to the oldest ticket; the attached checklist,
 * the templates, the runs and the ZZ note are removed in finally.
 *
 * @wiki Workflows
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../workflow/includes/engine.php';
require_once __DIR__ . '/../includes/watchtower_queries.php';

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
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/db_verify_cli.php') . ' --apply 2>&1', $o, $code);
if ($code !== 0) { check('Database Verify ran', false); exit(1); }

$tag = 'ZZchk-' . bin2hex(random_bytes(3));
$tpls = []; $runs = []; $ticketId = 0;
try {
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $ticketId = (int)$conn->query("SELECT id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $mk = function (string $title, string $scope) use ($conn, &$tpls): int {
        $conn->prepare("INSERT INTO checklist_templates (title, category, scope, is_active) VALUES (?, 'General', ?, 1)")->execute([$title, $scope]);
        $id = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO checklist_template_items (template_id, title, sort_order) VALUES (?, 'Step one', 1)")->execute([$id]);
        return $tpls[] = $id;
    };
    $forTicket = $mk("$tag for tickets", 'ticket');
    $forTask   = $mk("$tag for tasks", 'task');

    echo "The picker\n";
    $offered = array_map('intval', array_column(WorkflowEngine::availableActionLookup('checklist_template') ?? [], 'id'));
    check('offers a checklist for tickets', in_array($forTicket, $offered, true));
    check('does not offer a checklist for tasks only', !in_array($forTask, $offered, true));

    $run = function (array $ids) use (&$runs, $ticketId, $tag): array {
        $r = WorkflowEngine::runActionList('ZZ attach test', 'ticket.created', [
            ['type' => 'attach_checklist', 'args' => ['ticket_id' => '{{ticket.id}}', 'template_ids' => array_map('strval', $ids)]],
            ['type' => 'add_ticket_note', 'args' => ['ticket_id' => '{{ticket.id}}', 'note' => "$tag after", 'write_to' => 'note']],
        ], ['ticket' => ['id' => $ticketId]]);
        if (!empty($r['execution_id'])) $runs[] = (int)$r['execution_id'];
        return $r;
    };
    $onTicket = fn(int $tpl) => (int)$conn->query("SELECT COUNT(*) FROM ticket_checklists WHERE ticket_id = $ticketId AND template_id = $tpl")->fetchColumn();

    echo "\nAn old workflow naming one of each\n";
    $r = $run([$forTask, $forTicket]);
    $s0 = $r['steps'][0] ?? [];
    check('the run succeeds (it used to fail)', ($r['status'] ?? '') === 'success', json_encode($r['steps'] ?? []));
    check('the checklist for tickets is attached', $onTicket($forTicket) === 1);
    check('the checklist for tasks only is not', $onTicket($forTask) === 0);
    check('the step leaves a note naming it', str_contains((string)($s0['attention'] ?? ''), "$tag for tasks"), (string)($s0['attention'] ?? ''));
    check('...and the step still counts as done (it attached one)', ($s0['status'] ?? '') === 'success');
    $notes = (int)$conn->query("SELECT COUNT(*) FROM ticket_notes WHERE ticket_id = $ticketId AND note_text = " . $conn->quote("$tag after"))->fetchColumn();
    check('the action after it still runs', $notes === 1);

    echo "\nOnly the tasks-only one\n";
    $r = $run([$forTask]);
    $s0 = $r['steps'][0] ?? [];
    check('the step shows as skipped, with the note', ($s0['status'] ?? '') === 'skipped' && !empty($s0['attention']), json_encode($s0));
    check('...and the run still succeeds', ($r['status'] ?? '') === 'success');

    echo "\nSomebody finds out\n";
    $hits = (int)$conn->query("SELECT COUNT(*) FROM workflow_executions WHERE id IN (" . implode(',', $runs) . ") AND step_log LIKE '%\"attention\":%'")->fetchColumn();
    check('both runs match the "Needs a look" filter', $hits === 2, (string)$hits);
    $wt = getWatchtowerData($conn, $admin);
    $wf = $wt['workflows'] ?? [];
    check('the Watchtower Workflows card counts them', ($wf['attention_24h'] ?? 0) >= 2 && empty($wf['all_clear']), json_encode($wf));
    check('...naming the latest run', ($wf['attention_last'] ?? '') === 'ZZ attach test', (string)($wf['attention_last'] ?? ''));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($tpls as $t) {
        $ids = $conn->query("SELECT id FROM ticket_checklists WHERE template_id = $t")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $c) {
            $conn->exec("DELETE FROM ticket_checklist_items WHERE ticket_checklist_id = " . (int)$c);
            $conn->exec("DELETE FROM ticket_checklists WHERE id = " . (int)$c);
        }
        $conn->exec("DELETE FROM checklist_template_items WHERE template_id = $t");
        try { $conn->exec("DELETE FROM checklist_template_versions WHERE template_id = $t"); } catch (Throwable) {}
        $conn->exec("DELETE FROM checklist_templates WHERE id = $t");
    }
    if ($ticketId) $conn->prepare("DELETE FROM ticket_notes WHERE ticket_id = ? AND note_text = ?")->execute([$ticketId, "$tag after"]);
    if ($runs) $conn->exec("DELETE FROM workflow_executions WHERE id IN (" . implode(',', $runs) . ")");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
