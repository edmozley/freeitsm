<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * One form submission, several "Create a ticket" steps in one run: each step raises
 * its OWN ticket. The shipped "New starter onboarding fan-out" template (IT, HR,
 * Facilities) raised only the IT ticket - the submission path hands back the
 * submission's ticket once it has one, so HR and Facilities got IT back.
 *
 *   FREEITSM_TEST_DATABASE=<the throwaway database config.php uses> php tests/workflow-form-fanout.php
 *
 * Runs the template's own actions through WorkflowEngine::runActionList() against a
 * ZZ submission of an existing form. CONTROL: a LATER run (a rule on .approved after
 * the approval raised the ticket, say) still gets the submission's ticket back rather
 * than a duplicate. Everything ZZ is removed in finally.
 *
 * @wiki Workflows
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../workflow/includes/engine.php';
require_once __DIR__ . '/../workflow/includes/templates.php';

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

$tag = 'ZZfan-' . bin2hex(random_bytes(3));
$runs = []; $sid = 0; $tickets = [];
$counters = $conn->query("SELECT * FROM ticket_number_counters")->fetchAll(PDO::FETCH_ASSOC);
try {
    $form = $conn->query("SELECT id, title FROM forms ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$form) throw new RuntimeException('This database has no form.');
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $conn->prepare("INSERT INTO form_submissions (form_id, submitted_by, approval_status) VALUES (?, ?, 'not_required')")->execute([(int)$form['id'], $admin]);
    $sid = (int)$conn->lastInsertId();

    $tpl = null;
    foreach (WorkflowTemplates::all() as $key => $t) if ($key === 'new_starter_onboarding') $tpl = $t;
    if (!$tpl) throw new RuntimeException('The new_starter_onboarding template is missing.');
    $actions = array_values(array_filter($tpl['actions'], fn($a) => $a['type'] === 'create_ticket'));
    foreach ($actions as &$a) $a['args']['subject'] = "$tag " . $a['args']['subject'];
    unset($a);

    $payload = ['form' => ['id' => (int)$form['id'], 'name' => 'New starter'],
                'submission' => ['id' => $sid, 'email' => 'zz-fanout@example.invalid', 'fields' => []]];
    $run = function (array $acts) use ($payload, &$runs): array {
        $r = WorkflowEngine::runActionList('ZZ fan-out test', 'form.submitted', $acts, $payload);
        if (!empty($r['execution_id'])) $runs[] = (int)$r['execution_id'];
        return $r;
    };

    echo "The template's three Create a ticket steps, in one run\n";
    $r = $run($actions);
    $ids = [];
    foreach ($r['steps'] ?? [] as $s) if (!empty($s['result']['ticket_id'])) $ids[] = (int)$s['result']['ticket_id'];
    $tickets = array_merge($tickets, $ids);
    check('the run succeeds', ($r['status'] ?? '') === 'success', json_encode(array_map(fn($s) => $s['error'] ?? null, $r['steps'] ?? [])));
    check('three steps, three DIFFERENT tickets', count($ids) === 3 && count(array_unique($ids)) === 3, json_encode($ids));
    $subs = $conn->query("SELECT subject FROM tickets WHERE id IN (" . implode(',', $ids ?: [0]) . ") ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    check('each with its own subject - IT, HR, Facilities', count($subs) === 3 && str_contains($subs[0], 'IT setup') && str_contains($subs[1], 'HR paperwork') && str_contains($subs[2], 'desk, pass'), json_encode($subs));
    $linked = (int)$conn->query("SELECT ticket_id FROM form_submissions WHERE id = $sid")->fetchColumn();
    check('the submission points at the first one', $ids && $linked === $ids[0]);

    echo "CONTROL: a later run for the same submission\n";
    $r2 = $run([$actions[0]]);
    $again = (int)($r2['steps'][0]['result']['ticket_id'] ?? 0);
    check('gets the submission\'s ticket back, never a duplicate', $again === ($ids[0] ?? -1), "$again");
    $n = (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE subject LIKE " . $conn->quote("$tag%"))->fetchColumn();
    check('so there are still three', $n === 3, "$n");
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $all = array_map('intval', $conn->query("SELECT id FROM tickets WHERE subject LIKE " . $conn->quote("$tag%"))->fetchAll(PDO::FETCH_COLUMN));
    if ($all) {
        $in = implode(',', $all);
        $tables = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                  AND COLUMN_NAME = 'ticket_id' AND TABLE_NAME NOT IN ('tickets', 'form_submissions')")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tbl) { try { $conn->exec("DELETE FROM `$tbl` WHERE ticket_id IN ($in)"); } catch (Throwable) {} }
        $conn->exec("DELETE FROM tickets WHERE id IN ($in)");
    }
    if ($sid) $conn->exec("DELETE FROM form_submissions WHERE id = $sid");
    if ($runs) $conn->exec("DELETE FROM workflow_executions WHERE id IN (" . implode(',', $runs) . ")");
    $conn->exec("DELETE FROM ticket_number_counters");
    $ins = null;
    foreach ($counters as $c) {
        $cols = array_keys($c);
        $ins ??= $conn->prepare("INSERT INTO ticket_number_counters (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
        $ins->execute(array_values($c));
    }
    $left = (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE subject LIKE " . $conn->quote("$tag%"))->fetchColumn();
    check('cleaned up', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
