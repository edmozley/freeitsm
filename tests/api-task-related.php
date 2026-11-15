<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A task's `related` block (GitHub #328, the task half) - the twin of
 * tests/api-ticket-related.php.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/api-task-related.php
 *
 * A ZZ ticket and a family of ZZ tasks around one task T: its parent P, its subtask S,
 * a task D it waits for and a task B that waits for it, plus a comment on T. Called over
 * real HTTP with two temporary API keys - one with the sub-endpoint and ticket
 * permissions, one with tasks read only - to prove the counts and links are right AND
 * never say more than the key may read. Then the list (opt-in) and the Full record
 * webhook. Everything ZZ is removed in finally.
 *
 * @wiki REST-API-Ticket-Related-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/tickets.php';
require_once __DIR__ . '/../workflow/includes/engine.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}
$rest = function (string $path, string $key) use ($base): array {
    [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
    $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($p) . ($q !== '' ? '&' . $q : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
};

$tag = 'ZZ328T-' . bin2hex(random_bytes(3));
$tickets = []; $keys = []; $tasks = [];
$counters = $conn->query("SELECT * FROM ticket_number_counters")->fetchAll(PDO::FETCH_ASSOC);
try {
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $tenant = getDefaultTenantId($conn);
    $user = (int)$conn->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
    $t1 = $tickets[] = TicketsService::createTicket($conn, $ctx, $tenant, ['subject' => "$tag SECRET ticket subject", 'user_id' => $user], null, 'ZZ test');
    $t1num = (string)$conn->query("SELECT ticket_number FROM tickets WHERE id = $t1")->fetchColumn();

    $mk = function (string $title, ?int $parent = null, ?int $ticket = null) use ($conn, $admin, $tenant, &$tasks, $tag): int {
        $conn->prepare("INSERT INTO tasks (title, parent_task_id, ticket_id, created_by_id, tenant_id) VALUES (?, ?, ?, ?, ?)")
             ->execute(["$tag $title", $parent, $ticket, $admin, $tenant]);
        return $tasks[] = (int)$conn->lastInsertId();
    };
    $P = $mk('parent');
    $T = $mk('the task', $P, $t1);
    $S = $mk('subtask', $T);
    $D = $mk('waited for');
    $B = $mk('waits for it');
    $dep = $conn->prepare("INSERT INTO task_dependencies (task_id, depends_on_id) VALUES (?, ?)");
    $dep->execute([$T, $D]);
    $dep->execute([$B, $T]);
    $conn->prepare("INSERT INTO task_comments (task_id, analyst_id, comment) VALUES (?, ?, ?)")->execute([$T, $admin, "$tag a comment"]);

    $mkKey = function (array $perms) use ($conn, $admin, &$keys, $tag): string {
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
             ->execute(["$tag key", substr($key, 0, 12), hash('sha256', $key), $admin, json_encode($perms)]);
        $keys[] = (int)$conn->lastInsertId();
        return $key;
    };
    $full = $mkKey(['tasks' => ['read'], 'task_comments' => ['read'], 'task_forms' => ['read'], 'task_checklists' => ['read'], 'tickets' => ['read']]);
    $narrow = $mkKey(['tasks' => ['read']]);

    echo "GET /tasks/{id} with a key that may read everything\n";
    [$code, $r] = $rest("/tasks/$T", $full);
    $rel = $r['data']['related'] ?? null;
    check('200 with a related block', $code === 200 && is_array($rel), "$code " . json_encode($r));
    check('counts: one comment, no forms, no checklists',
        ($rel['counts']['comments'] ?? null) === 1 && ($rel['counts']['forms'] ?? null) === 0 && ($rel['counts']['checklists'] ?? null) === 0, json_encode($rel['counts'] ?? null));
    $by = function (array $links, string $type, ?string $relation = null) {
        return array_values(array_filter($links, fn($l) => $l['type'] === $type && ($relation === null || ($l['relation'] ?? null) === $relation)));
    };
    $links = $rel['links'] ?? [];
    $tk = $by($links, 'ticket');
    check('the ticket, by number', count($tk) === 1 && $tk[0]['id'] === $t1 && $tk[0]['reference'] === $t1num, json_encode($tk));
    check("the ticket's subject is never shown", !str_contains(json_encode($rel), 'SECRET'));
    check('its parent is child_of', array_column($by($links, 'task', 'child_of'), 'id') === [$P]);
    check('its subtask is parent_of', array_column($by($links, 'task', 'parent_of'), 'id') === [$S]);
    check('what it waits for is waits_on', array_column($by($links, 'task', 'waits_on'), 'id') === [$D]);
    check('what waits for it is blocks', array_column($by($links, 'task', 'blocks'), 'id') === [$B]);

    echo "GET /tasks/{id} with tasks read only\n";
    [$code, $r] = $rest("/tasks/$T", $narrow);
    $rel = $r['data']['related'] ?? null;
    check('no counts - the key may read none of the sub-endpoints', $code === 200 && is_array($rel) && $rel['counts'] === [], json_encode($rel['counts'] ?? null));
    check('no ticket link - the key may not read tickets', !$by($rel['links'] ?? [], 'ticket'));
    check('the task links are there - it may read tasks', count($by($rel['links'] ?? [], 'task')) === 4);

    echo "GET /tasks - related only when asked for\n";
    [$code, $r] = $rest('/tasks?include=related&search=' . rawurlencode($tag) . '&per_page=50', $full);
    $mine = array_values(array_filter($r['data'] ?? [], fn($x) => str_starts_with((string)$x['title'], $tag)));
    check('with include=related every task carries it', $mine && !array_filter($mine, fn($x) => !isset($x['related'])), $code . ' ' . count($mine));
    [$code, $r] = $rest('/tasks?search=' . rawurlencode($tag) . '&per_page=50', $full);
    $mine = array_values(array_filter($r['data'] ?? [], fn($x) => str_starts_with((string)$x['title'], $tag)));
    check('without it, the list is as before', $mine && !array_filter($mine, fn($x) => isset($x['related'])));

    echo "The Full record webhook\n";
    $p = WorkflowEngine::enrichWithFullObjects($conn, ['task' => ['id' => $T]]);
    $wr = $p['task']['full']['related'] ?? null;
    check('task.full carries related - every count and every link', is_array($wr) && count((array)$wr['counts']) === 3 && count($wr['links']) === 5, json_encode($wr));
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($keys) $conn->exec("DELETE FROM api_keys WHERE id IN (" . implode(',', $keys) . ")");
    if ($tasks) {
        $in = implode(',', $tasks);
        $conn->exec("DELETE FROM task_dependencies WHERE task_id IN ($in) OR depends_on_id IN ($in)");
        $conn->exec("DELETE FROM task_comments WHERE task_id IN ($in)");
        try { $conn->exec("DELETE FROM task_audit WHERE task_id IN ($in)"); } catch (Throwable) {}
        $conn->exec("UPDATE tasks SET parent_task_id = NULL WHERE id IN ($in)");
        $conn->exec("DELETE FROM tasks WHERE id IN ($in)");
    }
    $tickets = array_values(array_filter(array_map('intval', $tickets)));
    if ($tickets) {
        $in = implode(',', $tickets);
        $tables = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                  AND COLUMN_NAME = 'ticket_id' AND TABLE_NAME <> 'tickets'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tbl) { try { $conn->exec("DELETE FROM `$tbl` WHERE ticket_id IN ($in)"); } catch (Throwable) {} }
        $conn->exec("DELETE FROM tickets WHERE id IN ($in)");
    }
    $conn->exec("DELETE FROM ticket_number_counters");
    $ins = null;
    foreach ($counters as $c) {
        $cols = array_keys($c);
        $ins ??= $conn->prepare("INSERT INTO ticket_number_counters (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
        $ins->execute(array_values($c));
    }
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM tickets WHERE subject LIKE " . $conn->quote("$tag%") . ")
                                    + (SELECT COUNT(*) FROM tasks WHERE title LIKE " . $conn->quote("$tag%") . ")")->fetchColumn();
    check('cleaned up', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
