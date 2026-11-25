<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Workflow run order and "Stop here when this one runs" (discussion #304).
 *
 *   1. The engine runs the workflows on one trigger in run_order; the unplaced
 *      ones (0) after the placed ones, oldest first - today's order.
 *   2. Stop here: when a ticked workflow RUNS (its conditions match), the ones after
 *      it are not run, and each gets a 'skipped' run naming the one that stopped it.
 *      A ticked workflow whose conditions do NOT match stops nothing.
 *   3. api/workflow/save_order.php: numbers 10, 20, 30; refuses a list that is not
 *      exactly the trigger's workflows; refuses someone who cannot edit every one of
 *      them (with a positive control).
 *   4. api/workflow/save.php keeps stop_after, and a workflow moved to another
 *      trigger goes to the end of that trigger's order (run_order back to 0).
 *   5. REST: run_order + stop_after on the serializer, stop_after on PATCH.
 *   6. Before Database Verification (no columns): the old order, nothing breaks.
 *
 * 🔴 Never touches the install's own database. It imports database/freeitsm.sql
 * into a throwaway database (zz_wforder_*), and drops it at the end, pass or fail.
 * Each endpoint call is its own process, because endpoints exit; the no-columns
 * phase is its own process because runOrderReady() is cached per process.
 *
 * Needs the mysql client WAMP ships (or MYSQL_BIN) and rights to create a database.
 *
 * Run: php tests/workflow-run-order.php
 */

$ROOT = dirname(__DIR__);

// Child mode 1: run ONE real app endpoint as an analyst.   --run <db> <analystId> <script>   body on STDIN
if (($argv[1] ?? '') === '--run') {
    [, , $db, $aid, $script] = $argv;
    define('DB_NAME', $db);
    ini_set('display_errors', 'stderr');
    $body = stream_get_contents(STDIN);
    class ZzInput {
        public static string $data = '';
        private int $pos = 0;
        public $context;
        public function stream_open($path, $m, $o, &$p): bool { return $path === 'php://input'; }
        public function stream_read(int $n): string { $c = substr(self::$data, $this->pos, $n); $this->pos += strlen($c); return $c; }
        public function stream_eof(): bool { return $this->pos >= strlen(self::$data); }
        public function stream_stat() { return ['size' => strlen(self::$data)]; }
    }
    ZzInput::$data = $body;
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'ZzInput');
    $_SERVER['REQUEST_METHOD'] = $body !== '' ? 'POST' : 'GET';
    $_SERVER['SCRIPT_FILENAME'] = "$ROOT/$script";
    $_SERVER['SCRIPT_NAME'] = '/freeitsm-app/' . $script;
    $_SERVER['HTTP_HOST'] = 'localhost';
    session_id('zzwforder' . $aid);
    @session_start();
    $_SESSION = ['analyst_id' => (int)$aid, 'analyst_name' => 'ZZ'];
    session_write_close();
    chdir(dirname("$ROOT/$script"));
    require "$ROOT/$script";
    exit;
}

// Child mode 2: one REST handler with a synthetic every-company key.   --rest <db> <analystId> <handler> <params json>   body on STDIN
if (($argv[1] ?? '') === '--rest') {
    [, , $db, $aid, $fn, $params] = $argv;
    define('DB_NAME', $db);
    ini_set('display_errors', 'stderr');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    require "$ROOT/api/v1/lib/bootstrap.php";
    require "$ROOT/api/v1/resources/workflows.php";
    $body = json_decode(stream_get_contents(STDIN), true) ?: [];
    $key = ['id' => 0, 'analyst_id' => (int)$aid, 'company_scope' => null];
    $fn(connectToDatabase(), $key, json_decode(base64_decode($params), true) ?: [], $body);
    exit;
}

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-74s\n", $label); }
    else       { $fail++; printf("  FAIL %-74s %s\n", $label, $detail); }
}
function runChild(string $cmd, string $stdin): array {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $start = strpos($out, '{');
    $j = $start === false ? null : json_decode(substr($out, $start), true);
    return is_array($j) ? $j : ['raw' => substr($out . ' | ' . $err, 0, 400)];
}
function call(string $db, int $aid, string $script, ?array $body = null): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run ' . escapeshellarg($db) . " $aid " . escapeshellarg($script);
    return runChild($cmd, $body === null ? '' : json_encode($body));
}
function rest(string $db, int $aid, string $fn, array $params = [], ?array $body = null): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --rest ' . escapeshellarg($db) . " $aid $fn " . base64_encode(json_encode($params));
    return runChild($cmd, $body === null ? '' : json_encode($body));
}

// Child mode 3: the engine assertions, in their own process.   --engine <db> <ready|nocol> <fixture json>
if (($argv[1] ?? '') === '--engine') {
    [, , $db, $phase, $fx] = $argv;
    $fx = json_decode(base64_decode($fx), true);
    define('DB_NAME', $db);
    ini_set('display_errors', 'stderr');
    $er = error_reporting(E_ALL & ~E_WARNING);
    require "$ROOT/config.php";
    error_reporting($er);
    require_once "$ROOT/includes/functions.php";
    require_once "$ROOT/workflow/includes/engine.php";
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $w = $fx['w'];
    $order = function () use ($conn): array {   // [workflow_id => status], in the order they ran
        $out = [];
        foreach ($conn->query("SELECT workflow_id, status, error_message FROM workflow_executions ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $r;
        return $out;
    };
    $ids = fn(array $rows) => array_map(fn($r) => (int)$r['workflow_id'], $rows);
    $set = function (array $runOrder, array $stop = []) use ($conn, $w) {
        $conn->exec("DELETE FROM workflow_executions");
        foreach ($w as $k => $id) {
            $conn->prepare("UPDATE workflows SET run_order = ?, stop_after = ? WHERE id = ?")
                 ->execute([$runOrder[$k] ?? 0, in_array($k, $stop, true) ? 1 : 0, $id]);
        }
    };
    $fire = fn() => WorkflowEngine::dispatch('ticket.created', ['ticket' => ['id' => $fx['ticket']]]);

    if ($phase === 'ready') {
        echo "\n1. Run order\n";
        ok('runOrderReady() sees the columns', WorkflowEngine::runOrderReady($conn));
        $set([]);
        $fire();
        ok('nothing placed: oldest first (today\'s order)', $ids($order()) === [$w['a'], $w['b'], $w['c'], $w['nomatch']], json_encode($ids($order())));
        $set(['c' => 10, 'a' => 20, 'b' => 30, 'nomatch' => 40]);
        $fire();
        ok('placed: in run_order', $ids($order()) === [$w['c'], $w['a'], $w['b'], $w['nomatch']], json_encode($ids($order())));
        $set(['b' => 10]);
        $fire();
        ok('one placed: it first, then the rest oldest first', $ids($order()) === [$w['b'], $w['a'], $w['c'], $w['nomatch']], json_encode($ids($order())));

        echo "\n2. Stop here when this one runs\n";
        $set(['a' => 10, 'b' => 20, 'c' => 30, 'nomatch' => 40], ['b']);
        $fire();
        $rows = $order();
        $st = array_column($rows, 'status', 'workflow_id');
        ok('the ones before it and the stopper ran', ($st[$w['a']] ?? '') === 'success' && ($st[$w['b']] ?? '') === 'success', json_encode($st));
        ok('the ones after it are skipped', ($st[$w['c']] ?? '') === 'skipped' && ($st[$w['nomatch']] ?? '') === 'skipped', json_encode($st));
        $msg = (string)(array_column($rows, 'error_message', 'workflow_id')[$w['c']] ?? '');
        ok('the skipped run names the one that stopped it', str_contains($msg, 'ZZ order b'), $msg);
        $lastC = (string)$conn->query("SELECT last_run_status FROM workflows WHERE id = " . (int)$w['c'])->fetchColumn();
        ok('the skipped workflow\'s last run says skipped', $lastC === 'skipped', $lastC);

        $set(['nomatch' => 10, 'a' => 20, 'b' => 30, 'c' => 40], ['nomatch']);
        $fire();
        $st = array_column($order(), 'status', 'workflow_id');
        ok('a ticked one whose conditions do not match stops nothing', ($st[$w['nomatch']] ?? '') === 'skipped'
            && ($st[$w['a']] ?? '') === 'success' && ($st[$w['b']] ?? '') === 'success' && ($st[$w['c']] ?? '') === 'success', json_encode($st));

        $set(['a' => 10, 'b' => 20], ['c']);   // the stopper last: nothing after it to stop
        $fire();
        $st = array_column($order(), 'status', 'workflow_id');
        ok('a stopper with nothing after it changes nothing', count(array_filter($st, fn($s) => $s === 'success')) === 3, json_encode($st));

        $conn->prepare("UPDATE workflows SET is_active = 0 WHERE id = ?")->execute([$w['b']]);
        $set(['a' => 10, 'b' => 20, 'c' => 30, 'nomatch' => 40], ['b']);
        $fire();
        $st = array_column($order(), 'status', 'workflow_id');
        ok('an inactive stopper stops nothing', !isset($st[$w['b']]) && ($st[$w['c']] ?? '') === 'success', json_encode($st));
        $conn->prepare("UPDATE workflows SET is_active = 1 WHERE id = ?")->execute([$w['b']]);
    }

    if ($phase === 'nocol') {
        echo "\n6. Before Database Verification (no run_order / stop_after)\n";
        ok('runOrderReady() says not yet', !WorkflowEngine::runOrderReady($conn));
        $conn->exec("DELETE FROM workflow_executions");
        $fire();
        $got = $ids($order());
        ok('every workflow runs, oldest first', $got === [$w['a'], $w['b'], $w['c'], $w['nomatch']], json_encode($got));
        $l = call($db, $fx['all'], 'api/workflow/list.php');
        ok('list works, run_order 0', ($l['success'] ?? false) === true && (int)($l['workflows'][0]['run_order'] ?? -1) === 0, json_encode($l));
        $s = call($db, $fx['all'], 'api/workflow/save.php', ['id' => $w['a'], 'name' => 'ZZ order a', 'trigger_event' => 'ticket.created',
                  'conditions' => [], 'actions' => [], 'is_active' => 1, 'stop_after' => 1]);
        ok('save works (stop_after ignored)', ($s['success'] ?? false) === true, json_encode($s));
        $o = call($db, $fx['all'], 'api/workflow/save_order.php', ['trigger_event' => 'ticket.created', 'ids' => [$w['a'], $w['b'], $w['c'], $w['nomatch']]]);
        ok('save_order refuses with "run Database Verification"', ($o['success'] ?? true) === false && str_contains((string)($o['error'] ?? ''), 'Database Verification'), json_encode($o));
        $r = rest($db, $fx['all'], 'apiWorkflowsGet', [$w['b']]); $r = $r['data'] ?? $r;
        ok('REST get works, run_order 0 / stop_after false', ($r['run_order'] ?? null) === 0 && ($r['stop_after'] ?? null) === false, json_encode($r));
    }
    printf("\n__RESULT__ %d %d\n", $pass, $fail);
    exit(0);
}

// ---------------------------------------------------------------------------
// Parent: scratch database, seed, run, drop.
// ---------------------------------------------------------------------------
require "$ROOT/config.php";
$mysql = getenv('MYSQL_BIN') ?: (glob('C:/wamp64/bin/mysql/*/bin/mysql.exe')[0] ?? 'mysql');
$db = 'zz_wforder_' . getmypid();
echo "\nWorkflow run order + Stop here (discussion #304)\n" . str_repeat('=', 80) . "\nScratch database: $db\n";

$admin = new PDO('mysql:host=' . DB_SERVER . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$total = [0, 0];
$tally = function (string $out) use (&$total) {
    echo preg_replace('/^__RESULT__.*$/m', '', $out);
    if (preg_match('/__RESULT__ (\d+) (\d+)/', $out, $m)) { $total[0] += (int)$m[1]; $total[1] += (int)$m[2]; }
    else { $total[1]++; echo "  FAIL a phase did not finish\n"; }
};
try {
    putenv('MYSQL_PWD=' . DB_PASSWORD);
    $cmd = sprintf('"%s" -u %s -h %s %s < "%s/database/freeitsm.sql" 2>&1', $mysql, escapeshellarg(DB_USERNAME), escapeshellarg(DB_SERVER), escapeshellarg($db), $ROOT);
    $out = shell_exec($cmd);
    if (trim((string)$out) !== '') echo "import said: " . substr(trim($out), 0, 300) . "\n";
    $c = new PDO('mysql:host=' . DB_SERVER . ";dbname=$db;charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $id = fn() => (int)$c->lastInsertId();
    $status = (int)$c->query("SELECT id FROM ticket_statuses WHERE is_closed = 0 ORDER BY id LIMIT 1")->fetchColumn();
    $prio = (int)$c->query("SELECT id FROM ticket_priorities ORDER BY id LIMIT 1")->fetchColumn();
    $analyst = function (string $u, int $all) use ($c, $id): int {
        $c->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules)
                     VALUES (?, '!', ?, ?, 1, 0, ?, 1)")->execute([$u, $u, "$u@zz.invalid", $all]);
        return $id();
    };
    $all = $analyst('zz-all', 1);
    $c->prepare("INSERT INTO tickets (ticket_number, subject, status_id, priority_id, created_datetime) VALUES ('ZZ-1', 'ZZ order', ?, ?, UTC_TIMESTAMP())")->execute([$status, $prio]);
    $ticket = $id();
    $wf = function (string $name, string $trigger, array $conditions = [], ?int $tenant = null) use ($c, $id): int {
        $c->prepare("INSERT INTO workflows (name, trigger_event, conditions, actions, is_active, tenant_id) VALUES (?, ?, ?, ?, 1, ?)")
          ->execute([$name, $trigger, json_encode($conditions), json_encode([['type' => 'log_message', 'args' => ['message' => 'zz']]]), $tenant]);
        return $id();
    };
    $w = [
        'a'       => $wf('ZZ order a', 'ticket.created'),
        'b'       => $wf('ZZ order b', 'ticket.created'),
        'c'       => $wf('ZZ order c', 'ticket.created'),
        'nomatch' => $wf('ZZ order never', 'ticket.created', [['field' => 'ticket.id', 'op' => 'equals', 'value' => '-1']]),
    ];
    $fx = ['w' => $w, 'ticket' => $ticket, 'all' => $all];
    $engine = fn(string $phase) => (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " --engine $db $phase " . base64_encode(json_encode($fx)) . ' 2>&1');
    $tally($engine('ready'));

    // ---- 3. save_order ----
    echo "\n3. Saving the order\n";
    $c->exec("UPDATE workflows SET run_order = 0, stop_after = 0");
    $o = call($db, $all, 'api/workflow/save_order.php', ['trigger_event' => 'ticket.created', 'ids' => [$w['c'], $w['nomatch'], $w['a'], $w['b']]]);
    ok('saves', ($o['success'] ?? false) === true, json_encode($o));
    $ro = $c->query("SELECT id, run_order FROM workflows WHERE trigger_event = 'ticket.created'")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok('numbered 10, 20, 30, 40 in the order sent', (int)$ro[$w['c']] === 10 && (int)$ro[$w['nomatch']] === 20 && (int)$ro[$w['a']] === 30 && (int)$ro[$w['b']] === 40, json_encode($ro));
    $o = call($db, $all, 'api/workflow/save_order.php', ['trigger_event' => 'ticket.created', 'ids' => [$w['a'], $w['b'], $w['c']]]);
    ok('refuses a list missing one', ($o['success'] ?? true) === false, json_encode($o));
    $o = call($db, $all, 'api/workflow/save_order.php', ['trigger_event' => 'ticket.created', 'ids' => [$w['a'], $w['b'], $w['c'], $w['nomatch'], 999999]]);
    ok('refuses a list with a stranger in it', ($o['success'] ?? true) === false, json_encode($o));
    $o = call($db, $all, 'api/workflow/save_order.php', ['trigger_event' => 'ticket.created', 'ids' => [$w['a'], $w['a'], $w['b'], $w['c']]]);
    ok('refuses a list with one twice', ($o['success'] ?? true) === false, json_encode($o));
    $o = call($db, $all, 'api/workflow/save_order.php', ['trigger_event' => 'zz.nothing', 'ids' => []]);
    ok('refuses a trigger with no workflows', ($o['success'] ?? true) === false, json_encode($o));
    $ro2 = $c->query("SELECT id, run_order FROM workflows WHERE trigger_event = 'ticket.created'")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok('a refusal changes nothing', $ro2 == $ro, json_encode($ro2));

    // Companies: someone who cannot edit every workflow on the trigger cannot reorder it.
    $c->prepare("INSERT INTO tenants (name, is_default, is_active) VALUES ('ZZ Acme', 0, 1)")->execute(); $A = $id();
    $c->prepare("INSERT INTO tenants (name, is_default, is_active) VALUES ('ZZ Globex', 0, 1)")->execute();
    $za = $analyst('zz-a', 0);
    $c->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$za, $A]);
    $supShared = $wf('ZZ sup shared', 'supplier.created');
    $supA1 = $wf('ZZ sup A1', 'supplier.created', [], $A);
    $o = call($db, $za, 'api/workflow/save_order.php', ['trigger_event' => 'supplier.created', 'ids' => [$supA1, $supShared]]);
    ok('companies: someone who cannot edit the shared one is refused', ($o['success'] ?? true) === false, json_encode($o));
    $o = call($db, $all, 'api/workflow/save_order.php', ['trigger_event' => 'supplier.created', 'ids' => [$supA1, $supShared]]);
    ok('companies: someone who can edit every one may (positive control)', ($o['success'] ?? false) === true, json_encode($o));
    $c->prepare("DELETE FROM workflows WHERE id = ?")->execute([$supShared]);
    $supA2 = $wf('ZZ sup A2', 'supplier.created', [], $A);
    $o = call($db, $za, 'api/workflow/save_order.php', ['trigger_event' => 'supplier.created', 'ids' => [$supA2, $supA1]]);
    ok('companies: all her own company\'s - allowed', ($o['success'] ?? false) === true, json_encode($o));

    // ---- 4. save.php ----
    echo "\n4. Saving a workflow\n";
    $base = ['name' => 'ZZ order a', 'trigger_event' => 'ticket.created', 'conditions' => [], 'actions' => [], 'is_active' => 1];
    $s = call($db, $all, 'api/workflow/save.php', ['id' => $w['a'], 'stop_after' => 1] + $base);
    ok('stop_after saved', ($s['success'] ?? false) === true && (int)$c->query("SELECT stop_after FROM workflows WHERE id = " . $w['a'])->fetchColumn() === 1, json_encode($s));
    ok('same trigger: keeps its place', (int)$c->query("SELECT run_order FROM workflows WHERE id = " . $w['a'])->fetchColumn() === 30);
    $l = call($db, $all, 'api/workflow/list.php');
    $row = array_values(array_filter($l['workflows'] ?? [], fn($x) => (int)$x['id'] === $w['a']))[0] ?? [];
    ok('list carries run_order and stop_after', (int)($row['run_order'] ?? 0) === 30 && (int)($row['stop_after'] ?? 0) === 1, json_encode($row));
    $s = call($db, $all, 'api/workflow/save.php', ['id' => $w['a'], 'stop_after' => 0] + $base);
    ok('unticked', (int)$c->query("SELECT stop_after FROM workflows WHERE id = " . $w['a'])->fetchColumn() === 0);
    $s = call($db, $all, 'api/workflow/save.php', ['id' => $w['a'], 'stop_after' => 0, 'trigger_event' => 'ticket.assigned'] + $base);
    ok('moved to another trigger: to the end of its order (0)', (int)$c->query("SELECT run_order FROM workflows WHERE id = " . $w['a'])->fetchColumn() === 0, json_encode($s));
    $s = call($db, $all, 'api/workflow/save.php', ['stop_after' => 1, 'name' => 'ZZ order new'] + $base);
    $newId = (int)($s['id'] ?? 0);
    ok('a new one saved ticked', $newId > 0 && (int)$c->query("SELECT stop_after FROM workflows WHERE id = $newId")->fetchColumn() === 1, json_encode($s));

    // ---- 5. REST ----
    echo "\n5. REST API\n";
    $r = rest($db, $all, 'apiWorkflowsGet', [$w['c']]); $r = $r['data'] ?? $r;
    ok('get: run_order and stop_after', ($r['run_order'] ?? null) === 10 && ($r['stop_after'] ?? null) === false, json_encode($r));
    $r = rest($db, $all, 'apiWorkflowsUpdate', [$w['c']], ['stop_after' => true]); $r = $r['data'] ?? $r;
    ok('PATCH stop_after true', ($r['stop_after'] ?? null) === true && ($r['run_order'] ?? null) === 10, json_encode($r));
    $r = rest($db, $all, 'apiWorkflowsUpdate', [$w['c']], ['is_active' => true]); $r = $r['data'] ?? $r;
    ok('PATCH without it leaves it', ($r['stop_after'] ?? null) === true, json_encode($r));
    $r = rest($db, $all, 'apiWorkflowsUpdate', [$w['c']], ['trigger_event' => 'ticket.assigned']); $r = $r['data'] ?? $r;
    ok('PATCH to another trigger: run_order 0', ($r['run_order'] ?? null) === 0, json_encode($r));
    $r = rest($db, $all, 'apiWorkflowsCreate', [], ['name' => 'ZZ rest new', 'trigger_event' => 'ticket.created', 'stop_after' => true]); $r = $r['data'] ?? $r;
    ok('create with stop_after', ($r['stop_after'] ?? null) === true, json_encode($r));

    // ---- 6. no columns ----
    $c->exec("DELETE FROM workflows WHERE name IN ('ZZ order new', 'ZZ rest new') OR trigger_event = 'supplier.created'");
    $c->exec("UPDATE workflows SET trigger_event = 'ticket.created' WHERE id IN ({$w['a']}, {$w['c']})");
    $c->exec("ALTER TABLE workflows DROP COLUMN run_order, DROP COLUMN stop_after");
    $tally($engine('nocol'));
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `$db`");
    foreach (glob(rtrim(preg_replace('/^\d+;/', '', session_save_path() ?: sys_get_temp_dir()), '/\\') . '/sess_zzwforder*') ?: [] as $f) @unlink($f);
}
$total[0] += $pass; $total[1] += $fail;
printf("\n%d passed, %d failed\n", $total[0], $total[1]);
exit($total[1] ? 1 : 0);
