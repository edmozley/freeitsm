<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Routing tickets by category in workflows (GitHub discussion #298).
 *
 *   FREEITSM_TEST_DATABASE=<the throwaway database config.php uses> php tests/workflow-category-routing.php
 *
 * Category is a condition on every ticket trigger; a condition naming a category also
 * matches its subcategories unless Tickets -> Settings -> Categories says "that category
 * only"; and "A ticket's category changes" fires when an analyst sets one at triage.
 * Real, active ZZ workflows fired by real tickets through TicketsService. Other active
 * workflows on the three triggers used are paused for the run and put back; every ZZ row
 * (workflows, runs, categories, tickets and what hangs off them) is removed in finally,
 * with the setting restored.
 *
 * @wiki Workflows
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/tickets.php';
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

$tag = 'ZZ298-' . bin2hex(random_bytes(3));
$cats = []; $wfs = []; $tickets = []; $paused = [];
$oldSetting = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'workflow_category_match'")->fetchColumn();
$setMatch = function (?string $v) use ($conn) {
    if ($v === null) $conn->exec("DELETE FROM system_settings WHERE setting_key = 'workflow_category_match'");
    else $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('workflow_category_match', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
};
try {
    $paused = $conn->query("SELECT id FROM workflows WHERE is_active = 1 AND trigger_event IN ('ticket.created', 'ticket.category_changed', 'ticket.note_added')")->fetchAll(PDO::FETCH_COLUMN);
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 0 WHERE id IN (" . implode(',', array_map('intval', $paused)) . ")");
    $tenant = getDefaultTenantId($conn);
    $user = (int)$conn->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = ActorContext::system('ZZ test');

    $mkCat = function (string $name, ?int $parent) use ($conn, &$cats): int {
        $conn->prepare("INSERT INTO ticket_categories (name, parent_id, is_active) VALUES (?, ?, 1)")->execute([$name, $parent]);
        return $cats[] = (int)$conn->lastInsertId();
    };
    $hw = $mkCat("$tag Hardware", null);
    $laptop = $mkCat("$tag Laptop", $hw);
    $other = $mkCat("$tag Software", null);

    $mkWf = function (string $trigger, array $catIds, string $marker) use ($conn, &$wfs, $tag): int {
        $conn->prepare("INSERT INTO workflows (name, trigger_event, conditions, actions, is_active) VALUES (?, ?, ?, ?, 1)")->execute([
            "$tag $marker", $trigger,
            json_encode([['field' => 'ticket.category_id', 'op' => 'in', 'value' => array_map('strval', $catIds)]]),
            json_encode([['type' => 'add_ticket_note', 'args' => ['ticket_id' => '{{ticket.id}}', 'note' => "$tag $marker", 'write_to' => 'note']]]),
        ]);
        return $wfs[] = (int)$conn->lastInsertId();
    };
    $noted = fn(int $ticket, string $marker) => (int)$conn->query("SELECT COUNT(*) FROM ticket_notes WHERE ticket_id = $ticket AND note_text = " . $conn->quote("$tag $marker"))->fetchColumn() > 0;
    $newTicket = function (?int $cat) use ($conn, $ctx, $tenant, $user, &$tickets, $tag): int {
        $in = ['subject' => "$tag ticket", 'user_id' => $user];
        if ($cat) $in['category_id'] = $cat;
        return $tickets[] = TicketsService::createTicket($conn, $ctx, $tenant, $in, null, 'ZZ test');
    };

    echo "The designer\n";
    foreach (['ticket.created', 'ticket.reply_received', 'ticket.note_added', 'ticket.status_changed'] as $t) {
        check("category is a condition on $t", in_array('ticket.category_id', WorkflowEngine::availableFields($t), true));
    }
    check('a new trigger: a ticket\'s category changes', isset(WorkflowEngine::availableTriggers()['ticket.category_changed']));
    check('...with the old and new category as fields', !array_diff(['old_category_id', 'new_category_id', 'ticket.category_id'], WorkflowEngine::availableFields('ticket.category_changed')));
    $lk = array_column(WorkflowEngine::availableValuesForField('ticket.category_id') ?? [], 'label', 'id');
    check('the picker lists the subcategory with its path', str_contains((string)($lk[(string)$laptop] ?? ''), "$tag Hardware") && str_contains((string)($lk[(string)$laptop] ?? ''), "$tag Laptop"), (string)($lk[(string)$laptop] ?? 'missing'));

    echo "\nA new ticket, routed by category\n";
    $setMatch(null);
    check('never saved = subcategories included', WorkflowEngine::categoryMatchIncludesSubcategories());
    $mkWf('ticket.created', [$hw], 'created-hw');
    $t1 = $newTicket($laptop);
    check('a workflow for "Hardware" runs for a ticket in "Hardware → Laptop"', $noted($t1, 'created-hw'));
    $t2 = $newTicket($other);
    check('...and not for one in another category', !$noted($t2, 'created-hw'));
    $t3 = $newTicket(null);
    check('...nor for one with no category', !$noted($t3, 'created-hw'));
    $setMatch('exact');
    $t4 = $newTicket($laptop);
    check('"that category only": a "Hardware" workflow no longer catches "Hardware → Laptop"', !$noted($t4, 'created-hw'));
    $t5 = $newTicket($hw);
    check('...but still catches "Hardware" itself', $noted($t5, 'created-hw'));
    $setMatch(null);
    $log = $conn->query("SELECT step_log FROM workflow_executions WHERE workflow_id = {$wfs[0]} ORDER BY id ASC LIMIT 1")->fetchColumn();
    check('the run history says the subcategories were included', str_contains((string)$log, '"includes_subcategories":true'));

    echo "\nCategorised later, at triage\n";
    $mkWf('ticket.category_changed', [$hw], 'changed-hw');
    $t6 = $newTicket(null);
    TicketsService::updateTicket($conn, $ctx, $t6, ['category_id' => $laptop], true);
    check('setting the category fires "A ticket\'s category changes", and the workflow routes it', $noted($t6, 'changed-hw'));
    $run = $conn->query("SELECT trigger_payload FROM workflow_executions WHERE workflow_id = {$wfs[1]} ORDER BY id DESC LIMIT 1")->fetchColumn();
    $p = json_decode((string)$run, true) ?: [];
    check('...with the old and new category in the event', array_key_exists('old_category_id', $p) && $p['old_category_id'] === null && (int)($p['new_category_id'] ?? 0) === $laptop, (string)$run);
    $before = (int)$conn->query("SELECT COUNT(*) FROM workflow_executions WHERE workflow_id = {$wfs[1]}")->fetchColumn();
    TicketsService::updateTicket($conn, $ctx, $t6, ['category_id' => $laptop, 'subject' => "$tag ticket renamed"], true);
    check('saving the same category again fires nothing', (int)$conn->query("SELECT COUNT(*) FROM workflow_executions WHERE workflow_id = {$wfs[1]}")->fetchColumn() === $before);

    echo "\nEvents that do not carry the category themselves\n";
    $mkWf('ticket.note_added', [$hw], 'note-hw');
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    TicketsService::createNote($conn, new ActorContext($admin, null, 'ui', 'en', 'ZZ tester'), $t1, ['text' => "$tag a note", 'is_internal' => true]);
    check('a note on a "Laptop" ticket: the engine looks the category up, and the workflow matches', $noted($t1, 'note-hw'));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($wfs) {
        $conn->exec("DELETE FROM workflow_executions WHERE workflow_id IN (" . implode(',', $wfs) . ")");
        $conn->exec("DELETE FROM workflows WHERE id IN (" . implode(',', $wfs) . ")");
    }
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 1 WHERE id IN (" . implode(',', array_map('intval', $paused)) . ")");
    $tickets = array_values(array_filter(array_map('intval', $tickets)));
    if ($tickets) {
        $in = implode(',', $tickets);
        $tables = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                  AND COLUMN_NAME = 'ticket_id' AND TABLE_NAME <> 'tickets'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tbl) { try { $conn->exec("DELETE FROM `$tbl` WHERE ticket_id IN ($in)"); } catch (Throwable) {} }
        $conn->exec("DELETE FROM tickets WHERE id IN ($in)");
    }
    foreach ([$cats[1] ?? 0, $cats[0] ?? 0, $cats[2] ?? 0] as $c) if ($c) $conn->exec("DELETE FROM ticket_categories WHERE id = " . (int)$c);   // the subcategory before its parent
    $setMatch($oldSetting === false ? null : $oldSetting);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
