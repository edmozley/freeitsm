<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Software licences: how a licence is COUNTED (licence_type) and how long it LASTS
 * (licence_term: subscription | perpetual | perpetual_maintenance). 5.11.0, asked for
 * by a supporter of the project whose Microsoft volume licences are per device AND
 * perpetual - which the old single list ("Per User ... Subscription ... Other")
 * could not say.
 *
 *   1. SoftwareService: type + term saved; a perpetual licence loses its renewal date
 *      and notice period; maintenance keeps them; a bad term and "neither" refused;
 *      an update that does not send the term keeps it; null clears it.
 *   2. The old types: "Subscription" sent as a type becomes the term; "Perpetual" with
 *      a date becomes perpetual with maintenance.
 *   3. migrateLegacyTerms(): stored "Subscription" / "Perpetual" rows moved, anything
 *      else untouched, and a second run moves nothing.
 *   4. The endpoints: the licence list (api/software/get_licences.php) and the REST
 *      handlers carry licence_term; REST create / PATCH accept it.
 *   5. Report Packs: "Per device · Perpetual".
 *   6. Before Database Verification (no column): saving, the list and the REST API
 *      work as before, and the migration does nothing.
 *
 * 🔴 Never touches the install's own database: imports database/freeitsm.sql into a
 * throwaway zz_lterm_* database and drops it at the end, pass or fail. The phases
 * run in their own processes (termReady() is cached per process), and so does each
 * endpoint call (endpoints exit).
 *
 * Run: php tests/software-licence-term.php
 */

$ROOT = dirname(__DIR__);

// Child: one app endpoint as an analyst.   --run <db> <analystId> <script>
if (($argv[1] ?? '') === '--run') {
    [, , $db, $aid, $script] = $argv;
    define('DB_NAME', $db);
    ini_set('display_errors', 'stderr');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_FILENAME'] = "$ROOT/$script";
    $_SERVER['SCRIPT_NAME'] = '/freeitsm-app/' . $script;
    $_SERVER['HTTP_HOST'] = 'localhost';
    session_id('zzlterm' . $aid);
    @session_start();
    $_SESSION = ['analyst_id' => (int)$aid, 'analyst_name' => 'ZZ'];
    session_write_close();
    chdir(dirname("$ROOT/$script"));
    require "$ROOT/$script";
    exit;
}
// Child: one REST handler with an every-company key.   --rest <db> <analystId> <handler> <params b64>   body on STDIN
if (($argv[1] ?? '') === '--rest') {
    [, , $db, $aid, $fn, $params] = $argv;
    define('DB_NAME', $db);
    ini_set('display_errors', 'stderr');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    require "$ROOT/api/v1/lib/bootstrap.php";
    require "$ROOT/api/v1/resources/software.php";
    $body = json_decode(stream_get_contents(STDIN), true) ?: [];
    $fn(connectToDatabase(), ['id' => 0, 'analyst_id' => (int)$aid, 'company_scope' => null, 'analyst_name' => 'ZZ'], json_decode(base64_decode($params), true) ?: [], $body);
    exit;
}

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-72s\n", $label); }
    else       { $fail++; printf("  FAIL %-72s %s\n", $label, $detail); }
}
function runChild(string $cmd, string $stdin = ''): array {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $start = strpos($out, '{');
    $j = $start === false ? null : json_decode(substr($out, $start), true);
    return is_array($j) ? $j : ['raw' => substr($out . ' | ' . $err, 0, 400)];
}
function call(string $db, int $aid, string $script): array {
    return runChild(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run ' . escapeshellarg($db) . " $aid " . escapeshellarg($script));
}
function rest(string $db, int $aid, string $fn, array $params = [], ?array $body = null): array {
    $r = runChild(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --rest ' . escapeshellarg($db) . " $aid $fn " . base64_encode(json_encode($params)),
                  $body === null ? '' : json_encode($body));
    return $r['data'] ?? $r;
}

// Child: one phase of assertions.   --phase <db> <ready|nocol> <fixture b64>
if (($argv[1] ?? '') === '--phase') {
    [, , $db, $phase, $fx] = $argv;
    $fx = json_decode(base64_decode($fx), true);
    define('DB_NAME', $db);
    ini_set('display_errors', 'stderr');
    $er = error_reporting(E_ALL & ~E_WARNING);
    require "$ROOT/config.php";
    error_reporting($er);
    require_once "$ROOT/includes/functions.php";
    require_once "$ROOT/includes/i18n.php";
    require_once "$ROOT/includes/services/software.php";
    require_once "$ROOT/includes/report_packs/blocks_software.php";
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ctx = ActorContext::fromApiKey(['analyst_id' => (int)$fx['analyst'], 'company_scope' => null, 'analyst_name' => 'ZZ']);
    $app = (int)$fx['app'];
    $row = function (int $id) use ($conn): array {
        $s = $conn->prepare("SELECT * FROM software_licences WHERE id = ?"); $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $save = function (array $in) use ($conn, $ctx): array {
        try { return SoftwareService::saveLicence($conn, $ctx, $in); }
        catch (ServiceError $e) { return ['error' => $e->getMessage()]; }
    };

    if ($phase === 'ready') {
        echo "\n1. Counted by and term\n";
        ok('termReady() sees the column', SoftwareService::termReady($conn));
        $r = $save(['app_id' => $app, 'licence_type' => 'Per Device', 'licence_term' => 'perpetual', 'quantity' => 40,
                    'renewal_date' => '2027-01-01', 'notice_period_days' => 30]);
        $perp = $row((int)($r['id'] ?? 0));
        ok('per device + perpetual saved', ($perp['licence_type'] ?? '') === 'Per Device' && ($perp['licence_term'] ?? '') === 'perpetual', json_encode($r));
        ok('perpetual: renewal date and notice period cleared', $perp && $perp['renewal_date'] === null && $perp['notice_period_days'] === null, json_encode($perp));
        $r = $save(['app_id' => $app, 'licence_type' => 'Per Device', 'licence_term' => 'perpetual_maintenance', 'renewal_date' => '2027-03-31', 'notice_period_days' => 60]);
        $sa = $row((int)($r['id'] ?? 0));
        ok('with maintenance: the date and notice kept', ($sa['renewal_date'] ?? '') === '2027-03-31' && (int)($sa['notice_period_days'] ?? 0) === 60, json_encode($sa));
        $r = $save(['app_id' => $app, 'licence_type' => 'Per User', 'licence_term' => 'forever']);
        ok('an unknown term is refused', str_contains((string)($r['error'] ?? ''), 'licence_term'), json_encode($r));
        $r = $save(['app_id' => $app, 'licence_type' => '']);
        ok('neither counted-by nor term is refused', str_contains((string)($r['error'] ?? ''), "'licence_type'"), json_encode($r));
        $r = $save(['app_id' => $app, 'licence_term' => 'subscription', 'renewal_date' => '2027-06-30']);
        ok('a term alone is enough', !empty($r['id']) && ($row($r['id'])['licence_type'] ?? 'x') === '', json_encode($r));
        $save(['id' => $sa['id'], 'quantity' => 12]);
        ok('an update without the term keeps it', ($row($sa['id'])['licence_term'] ?? '') === 'perpetual_maintenance');
        $save(['id' => $sa['id'], 'licence_term' => null]);
        ok('null clears it', $row($sa['id'])['licence_term'] === null);

        echo "\n2. The old types, sent\n";
        $r = $save(['app_id' => $app, 'licence_type' => 'Subscription', 'renewal_date' => '2027-02-01']);
        $x = $row((int)($r['id'] ?? 0));
        ok('"Subscription" as a type becomes the term', ($x['licence_term'] ?? '') === 'subscription' && $x['licence_type'] === '', json_encode($x));
        $r = $save(['app_id' => $app, 'licence_type' => 'perpetual', 'renewal_date' => '2027-02-01']);
        $x = $row((int)($r['id'] ?? 0));
        ok('"Perpetual" with a date = perpetual with maintenance, date kept', ($x['licence_term'] ?? '') === 'perpetual_maintenance' && $x['renewal_date'] === '2027-02-01', json_encode($x));
        $r = $save(['app_id' => $app, 'licence_type' => 'Perpetual']);
        ok('"Perpetual" without one = perpetual', ($row((int)($r['id'] ?? 0))['licence_term'] ?? '') === 'perpetual');
        $r = $save(['app_id' => $app, 'licence_type' => 'Subscription', 'licence_term' => 'perpetual']);
        ok('a term sent as well wins', ($row((int)($r['id'] ?? 0))['licence_term'] ?? '') === 'perpetual');

        echo "\n3. Moving stored rows (Database Verification)\n";
        $ins = $conn->prepare("INSERT INTO software_licences (app_id, licence_type, renewal_date, status) VALUES (?, ?, ?, 'Active')");
        $legacy = [];
        foreach ([['Subscription', '2027-05-01'], [' subscription ', null], ['Perpetual', null], ['Perpetual', '2027-09-01'], ['Per User', '2027-05-01'], ['Volume (MAK)', null]] as [$t, $d]) {
            $ins->execute([$app, $t, $d]); $legacy[] = (int)$conn->lastInsertId();
        }
        $moved = SoftwareService::migrateLegacyTerms($conn);
        ok('four rows moved', $moved === 4, (string)$moved);
        $got = array_map(fn($id) => [$row($id)['licence_type'], $row($id)['licence_term']], $legacy);
        ok('Subscription -> subscription', $got[0] === ['', 'subscription'] && $got[1] === ['', 'subscription'], json_encode($got));
        ok('Perpetual -> perpetual, or with maintenance when it had a date', $got[2] === ['', 'perpetual'] && $got[3] === ['', 'perpetual_maintenance'], json_encode($got));
        ok('other types untouched', $got[4] === ['Per User', null] && $got[5] === ['Volume (MAK)', null], json_encode($got));
        ok('a second run moves nothing', SoftwareService::migrateLegacyTerms($conn) === 0);

        echo "\n4. The screens' data and the REST API\n";
        $l = call($db, $fx['analyst'], 'api/software/get_licences.php');
        $byId = array_column($l['licences'] ?? $l['data'] ?? [], null, 'id');
        ok('the licence list carries licence_term', ($byId[$perp['id']]['licence_term'] ?? '') === 'perpetual', substr(json_encode($l), 0, 300));
        $g = rest($db, $fx['analyst'], 'apiSoftwareLicencesGet', [(int)$perp['id']]);
        ok('REST get: licence_term', ($g['licence_term'] ?? '') === 'perpetual' && $g['renewal_date'] === null, json_encode($g));
        $c = rest($db, $fx['analyst'], 'apiSoftwareLicencesCreate', [], ['app_id' => $app, 'licence_type' => 'Site', 'licence_term' => 'perpetual_maintenance', 'renewal_date' => '2027-12-31']);
        ok('REST create with licence_term', ($c['licence_term'] ?? '') === 'perpetual_maintenance' && ($c['licence_type'] ?? '') === 'Site', json_encode($c));
        $u = rest($db, $fx['analyst'], 'apiSoftwareLicencesUpdate', [(int)($c['id'] ?? 0)], ['licence_term' => 'perpetual']);
        ok('REST PATCH to perpetual clears the renewal date', ($u['licence_term'] ?? '') === 'perpetual' && array_key_exists('renewal_date', $u) && $u['renewal_date'] === null, json_encode($u));
        $u = rest($db, $fx['analyst'], 'apiSoftwareLicencesUpdate', [(int)($c['id'] ?? 0)], ['licence_term' => 'yearly']);
        ok('REST PATCH with a bad term is refused', isset($u['error']), json_encode($u));
        $a = rest($db, $fx['analyst'], 'apiSoftwareAppsGet', [$app]);
        ok('REST app: its licences carry licence_term', in_array('perpetual', array_column($a['licences'] ?? [], 'licence_term'), true), substr(json_encode($a), 0, 300));

        echo "\n5. Report Packs\n";
        ok('"Per device · Perpetual"', rpSoftwareLicenceTypeText('Per Device', 'perpetual') === t('software.licences.type_per_device') . ' · ' . t('software.licences.term_perpetual'));
        ok('a type the app does not offer is shown as it is', rpSoftwareLicenceTypeText('Volume (MAK)', null) === 'Volume (MAK)');
        ok('no counted-by: the term alone', rpSoftwareLicenceTypeText('', 'subscription') === t('software.licences.term_subscription'));
    }

    if ($phase === 'nocol') {
        echo "\n6. Before Database Verification (no licence_term)\n";
        ok('termReady() says not yet', !SoftwareService::termReady($conn));
        $r = $save(['app_id' => $app, 'licence_type' => 'Per User', 'licence_term' => 'perpetual', 'quantity' => 3]);
        ok('saving still works', !empty($r['id']), json_encode($r));
        ok('the migration does nothing', SoftwareService::migrateLegacyTerms($conn) === 0);
        $l = call($db, $fx['analyst'], 'api/software/get_licences.php');
        ok('the licence list still works', ($l['success'] ?? false) === true, substr(json_encode($l), 0, 300));
        $g = rest($db, $fx['analyst'], 'apiSoftwareLicencesGet', [(int)$r['id']]);
        ok('REST get still works, licence_term null', array_key_exists('licence_term', $g) && $g['licence_term'] === null, json_encode($g));
    }
    printf("\n__RESULT__ %d %d\n", $pass, $fail);
    exit(0);
}

// ---------------------------------------------------------------------------
// Parent: scratch database, seed, run, drop.
// ---------------------------------------------------------------------------
require "$ROOT/config.php";
$mysql = getenv('MYSQL_BIN') ?: (glob('C:/wamp64/bin/mysql/*/bin/mysql.exe')[0] ?? 'mysql');
$db = 'zz_lterm_' . getmypid();
echo "\nSoftware licences: counted by + term\n" . str_repeat('=', 80) . "\nScratch database: $db\n";
$admin = new PDO('mysql:host=' . DB_SERVER . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$total = [0, 0];
try {
    putenv('MYSQL_PWD=' . DB_PASSWORD);
    $out = shell_exec(sprintf('"%s" -u %s -h %s %s < "%s/database/freeitsm.sql" 2>&1', $mysql, escapeshellarg(DB_USERNAME), escapeshellarg(DB_SERVER), escapeshellarg($db), $ROOT));
    if (trim((string)$out) !== '') echo "import said: " . substr(trim($out), 0, 300) . "\n";
    $c = new PDO('mysql:host=' . DB_SERVER . ";dbname=$db;charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $c->exec("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules)
              VALUES ('zz-lic', '!', 'ZZ lic', 'zz-lic@zz.invalid', 1, 1, 1, 1)");
    $analyst = (int)$c->lastInsertId();
    $c->exec("INSERT INTO software_inventory_apps (display_name, publisher) VALUES ('ZZ Office LTSC', 'ZZ Corp')");
    $fx = ['app' => (int)$c->lastInsertId(), 'analyst' => $analyst];
    $phase = function (string $name) use ($db, $fx, &$total) {
        $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " --phase $db $name " . base64_encode(json_encode($fx)) . ' 2>&1');
        echo preg_replace('/^__RESULT__.*$/m', '', $out);
        if (preg_match('/__RESULT__ (\d+) (\d+)/', $out, $m)) { $total[0] += (int)$m[1]; $total[1] += (int)$m[2]; }
        else { $total[1]++; echo "  FAIL phase $name did not finish\n"; }
    };
    $phase('ready');
    $c->exec("ALTER TABLE software_licences DROP COLUMN licence_term");
    $phase('nocol');
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `$db`");
    foreach (glob(rtrim(preg_replace('/^\d+;/', '', session_save_path() ?: sys_get_temp_dir()), '/\\') . '/sess_zzlterm*') ?: [] as $f) @unlink($f);
}
printf("\n%d passed, %d failed\n", $total[0], $total[1]);
exit($total[1] ? 1 : 0);
