<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Starters & leavers cases on the self-service portal (5.11.0): who may see them,
 * and what they see.
 *
 *   1. Off by default: a member of the People group is refused until it is switched on.
 *   2. On for a group: its member sees their OWN company's cases - open ones, and
 *      ones finished in the last 60 days - with progress and owner, and nothing of
 *      another company's, nothing cancelled, nothing finished long ago, no notes.
 *   3. Refused (positive control beside each): someone not in the group; a group
 *      switched off in the directory; the Starters & leavers module switched off.
 *   4. The Default company's member sees the cases with no company.
 *   5. The page: drawn for a member, sent away for anyone else.
 *   6. The setting: System -> Self-service portal takes off / groups, refuses
 *      "all" (there is no everyone), and drops a group id that does not exist.
 *
 * 🔴 Never touches the install's own database: imports database/freeitsm.sql into a
 * throwaway zz_portaljml_* database and drops it at the end, pass or fail. Every
 * endpoint call is its own process (endpoints exit; the settings are cached per process).
 *
 * Run: php tests/portal-starters.php
 */

$ROOT = dirname(__DIR__);

// Child: one endpoint or page.   --run <db> <portal|analyst> <id> <script> <query b64>   body on STDIN
if (($argv[1] ?? '') === '--run') {
    [, , $db, $who, $id, $script, $query] = $argv;
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
    parse_str(base64_decode($query), $_GET);
    $_SERVER['REQUEST_METHOD'] = $body !== '' ? 'POST' : 'GET';
    $_SERVER['SCRIPT_FILENAME'] = "$ROOT/$script";
    $_SERVER['SCRIPT_NAME'] = '/freeitsm-app/' . $script;
    $_SERVER['HTTP_HOST'] = 'localhost';
    session_id('zzportaljml' . $who . $id);
    @session_start();
    $_SESSION = $who === 'portal' ? ['ss_user_id' => (int)$id, 'ss_user_name' => 'ZZ'] : ['analyst_id' => (int)$id, 'analyst_name' => 'ZZ'];
    if ($who === 'analyst') { $_SESSION['csrf_token'] = 'zz'; $_SERVER['HTTP_X_CSRF_TOKEN'] = 'zz'; }
    session_write_close();
    chdir(dirname("$ROOT/$script"));
    require "$ROOT/$script";
    exit;
}

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-72s\n", $label); }
    else       { $fail++; printf("  FAIL %-72s %s\n", $label, $detail); }
}
/** Runs a script as a portal user or an analyst; returns [decoded JSON or null, raw output]. */
function run(string $db, string $who, int $id, string $script, array $get = [], ?array $body = null): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run ' . escapeshellarg($db) . " $who $id "
         . escapeshellarg($script) . ' ' . base64_encode(http_build_query($get));
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $body === null ? '' : json_encode($body)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $start = strpos($out, '{');
    $j = $start === false ? null : json_decode(substr($out, $start), true);
    return [is_array($j) ? $j : null, $out . ($err !== '' ? ' | ' . $err : '')];
}

require "$ROOT/config.php";
$mysql = getenv('MYSQL_BIN') ?: (glob('C:/wamp64/bin/mysql/*/bin/mysql.exe')[0] ?? 'mysql');
$db = 'zz_portaljml_' . getmypid();
echo "\nStarters & leavers on the portal\n" . str_repeat('=', 80) . "\nScratch database: $db\n";
$admin = new PDO('mysql:host=' . DB_SERVER . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    putenv('MYSQL_PWD=' . DB_PASSWORD);
    $out = shell_exec(sprintf('"%s" -u %s -h %s %s < "%s/database/freeitsm.sql" 2>&1', $mysql, escapeshellarg(DB_USERNAME), escapeshellarg(DB_SERVER), escapeshellarg($db), $ROOT));
    if (trim((string)$out) !== '') echo "import said: " . substr(trim($out), 0, 300) . "\n";
    $c = new PDO('mysql:host=' . DB_SERVER . ";dbname=$db;charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $id = fn() => (int)$c->lastInsertId();
    $set = function (string $k, string $v) use ($c) {
        $c->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
    };

    // Companies: the Default one, and two more.
    $D = (int)$c->query("SELECT id FROM tenants WHERE is_default = 1 LIMIT 1")->fetchColumn();
    $c->exec("INSERT INTO tenants (name, is_default, is_active) VALUES ('ZZ Acme', 0, 1)"); $A = $id();
    $c->exec("INSERT INTO tenants (name, is_default, is_active) VALUES ('ZZ Globex', 0, 1)"); $B = $id();
    $user = function (string $name, ?int $tenant) use ($c, $id): int {
        $c->prepare("INSERT INTO users (email, display_name, is_active, tenant_id) VALUES (?, ?, 1, ?)")->execute([strtolower(str_replace(' ', '.', $name)) . '@zz.invalid', $name, $tenant]);
        return $id();
    };
    $hrA = $user('ZZ HR Acme', $A); $staffA = $user('ZZ Staff Acme', $A); $hrB = $user('ZZ HR Globex', $B); $hrD = $user('ZZ HR Default', $D);
    $c->exec("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules)
              VALUES ('zz-owner', '!', 'ZZ Owner Person', 'zz-owner@zz.invalid', 1, 1, 1, 1)");
    $owner = $id();
    // People groups come from a directory: a provider, an HR group, and a group switched off.
    $c->exec("INSERT INTO auth_providers (display_name, issuer_url, client_id, protocol) VALUES ('ZZ Directory', 'https://zz.invalid', 'zz', 'oidc')"); $prov = $id();
    $group = function (string $name, int $active, array $members) use ($c, $id, $prov): int {
        $c->prepare("INSERT INTO directory_groups (provider_id, external_id, display_name, is_active) VALUES (?, ?, ?, ?)")->execute([$prov, 'zz-' . $name, $name, $active]);
        $g = $id();
        foreach ($members as $u) $c->prepare("INSERT INTO directory_group_members (group_id, user_id) VALUES (?, ?)")->execute([$g, $u]);
        return $g;
    };
    $hr  = $group('ZZ HR', 1, [$hrA, $hrB, $hrD]);
    $old = $group('ZZ Old', 0, [$staffA]);

    // Cases. Steps are subtasks of the case's task.
    $open = (int)$c->query("SELECT id FROM task_statuses WHERE is_closed = 0 ORDER BY id LIMIT 1")->fetchColumn();
    $closed = (int)$c->query("SELECT id FROM task_statuses WHERE is_closed = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $task = function (string $title, ?int $parent, int $status) use ($c, $id, $owner): int {
        $c->prepare("INSERT INTO tasks (title, parent_task_id, status_id, created_by_id) VALUES (?, ?, ?, ?)")->execute([$title, $parent, $status, $owner]);
        return $id();
    };
    $case = function (string $name, ?int $tenant, string $status, ?string $completed, array $steps = []) use ($c, $id, $task, $owner, $open, $closed): int {
        $parent = $task("ZZ case $name", null, $open);
        foreach ($steps as $i => $done) $task("ZZ step $i of $name", $parent, $done ? $closed : $open);
        $c->prepare("INSERT INTO jml_cases (tenant_id, kind, person_name, job_title, effective_date, status, completed_datetime, parent_task_id, owner_analyst_id, notes)
                     VALUES (?, 'leaver', ?, 'ZZ Accountant', '2026-12-31', ?, ?, ?, ?, 'ZZ secret note')")
          ->execute([$tenant, $name, $status, $completed, $parent, $owner]);
        return $id();
    };
    $recent = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
    $longAgo = gmdate('Y-m-d H:i:s', time() - 90 * 86400);
    $case('ZZ Acme open', $A, 'open', null, [true, false]);
    $case('ZZ Acme done recently', $A, 'done', $recent);
    $case('ZZ Acme done long ago', $A, 'done', $longAgo);
    $case('ZZ Acme cancelled', $A, 'cancelled', null);
    $case('ZZ Globex open', $B, 'open', null);
    $case('ZZ No company open', null, 'open', null);

    $api = 'api/self-service/starters.php';
    $names = fn($r) => array_column($r['cases'] ?? [], 'person_name');

    echo "\n1. Off by default\n";
    [$r] = run($db, 'portal', $hrA, $api);
    ok('a member of the group is refused until it is switched on', ($r['success'] ?? null) === false, json_encode($r));

    echo "\n2. On for the HR group\n";
    $set('self_service_jml_cases', 'groups');
    $set('self_service_jml_cases_groups', (string)$hr);
    [$r] = run($db, 'portal', $hrA, $api);
    ok('a member sees their own company\'s open case', $names($r) === ['ZZ Acme open'], json_encode($r));
    $x = $r['cases'][0] ?? [];
    ok('...with progress, date, kind, job title and owner', ($x['steps_done'] ?? 0) === 1 && ($x['steps_total'] ?? 0) === 2 && ($x['effective_date'] ?? '') === '2026-12-31'
       && ($x['kind'] ?? '') === 'leaver' && ($x['job_title'] ?? '') === 'ZZ Accountant' && ($x['owner_name'] ?? '') === 'ZZ Owner Person', json_encode($x));
    [$r2, $raw] = run($db, 'portal', $hrA, $api, ['view' => 'done']);
    ok('recently done: the recent one only - not the old one, not the cancelled one', $names($r2) === ['ZZ Acme done recently'], json_encode($r2));
    ok('the tab counts', ($r['counts'] ?? null) === ['open' => 1, 'done' => 1], json_encode($r['counts'] ?? null));
    ok('no notes go to the portal', !str_contains($raw, 'ZZ secret note') && !str_contains(json_encode($r), 'ZZ secret note'));
    [$r] = run($db, 'portal', $hrB, $api);
    ok('another company\'s member sees only theirs', $names($r) === ['ZZ Globex open'], json_encode($r));
    [$r] = run($db, 'portal', $hrD, $api);
    ok('the Default company\'s member sees the cases with no company', $names($r) === ['ZZ No company open'], json_encode($r));

    echo "\n3. Refused\n";
    [$r] = run($db, 'portal', $staffA, $api);
    ok('someone not in the group is refused', ($r['success'] ?? null) === false, json_encode($r));
    $set('self_service_jml_cases_groups', "$hr,$old");
    [$r] = run($db, 'portal', $staffA, $api);
    ok('a group switched off in the directory does not count', ($r['success'] ?? null) === false, json_encode($r));
    $c->exec("UPDATE directory_groups SET is_active = 1 WHERE id = $old");
    [$r] = run($db, 'portal', $staffA, $api);
    ok('...switched back on, it does (positive control)', ($r['success'] ?? null) === true, json_encode($r));
    $set('modules_disabled', json_encode(['starters']));
    [$r] = run($db, 'portal', $hrA, $api);
    ok('the module switched off: refused', ($r['success'] ?? null) === false, json_encode($r));
    $set('modules_disabled', '[]');

    echo "\n4. The page\n";
    [, $page] = run($db, 'portal', $hrA, 'self-service/starters.php');
    ok('drawn for a member', str_contains($page, 'id="jpList"'), substr($page, 0, 200));
    ok('...with the tab in the menu', str_contains($page, 'starters.php'));
    [, $page] = run($db, 'portal', $hrB + 1000, 'self-service/starters.php');   // nobody
    ok('nobody else gets the page', !str_contains($page, 'id="jpList"'));
    [, $page] = run($db, 'portal', $staffA, 'self-service/index.php');
    $set('self_service_jml_cases_groups', (string)$hr);
    [, $page2] = run($db, 'portal', $staffA, 'self-service/index.php');
    ok('the menu tab is hidden from someone not in the group', !str_contains($page2, 'href="starters.php"') && str_contains($page, 'href="starters.php"'));

    echo "\n5. The setting (System -> Self-service portal)\n";
    $s = 'api/system/self_service_portal.php';
    [$r] = run($db, 'analyst', $owner, $s, [], ['jml_cases' => 'all']);
    ok('"all" is refused - there is no everyone', ($r['success'] ?? null) === false, json_encode($r));
    [$r] = run($db, 'analyst', $owner, $s, [], ['jml_cases' => 'off', 'jml_cases_groups' => [$hr, 999999]]);
    $saved = $c->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'self_service_jml_cases%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok('off saved, and a group that does not exist dropped', ($r['success'] ?? null) === true && $saved['self_service_jml_cases'] === 'off'
       && $saved['self_service_jml_cases_groups'] === (string)$hr, json_encode([$r, $saved]));
    [$r] = run($db, 'portal', $hrA, $api);
    ok('...and off means off', ($r['success'] ?? null) === false);
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `$db`");
    foreach (glob(rtrim(preg_replace('/^\d+;/', '', session_save_path() ?: sys_get_temp_dir()), '/\\') . '/sess_zzportaljml*') ?: [] as $f) @unlink($f);
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
