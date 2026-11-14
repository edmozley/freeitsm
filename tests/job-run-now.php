<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Run now on Background jobs: jobRunNow() starts one scheduled job at once,
 * recorded as manual with who pressed it, and refuses with a reason.
 *
 *   php tests/job-run-now.php
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/job-run-now.php
 *
 * Part 1 needs no database: how a job started by Run now names its trigger.
 * Part 2 writes job_runs rows inside a transaction that is rolled back. Nothing
 * is really started: the spawn is a stand-in that records what it was given.
 */
chdir(dirname(__DIR__));
require 'config.php';
require 'includes/functions.php';
require 'includes/job_runs.php';
require 'includes/job_scheduler.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}

echo "Part 1 - the trigger a started job reads\n";
putenv('FREEITSM_JOB_TRIGGER');
check('from a schedule: cron', JobRun::scheduledTrigger() === 'cron');
putenv('FREEITSM_JOB_TRIGGER=page');
check('from the fallback: page', JobRun::scheduledTrigger() === 'page');
putenv('FREEITSM_JOB_TRIGGER=manual');
check('from Run now: manual', JobRun::scheduledTrigger() === 'manual');
putenv('FREEITSM_JOB_TRIGGER=anything');
check('anything else is a schedule, never taken as given', JobRun::scheduledTrigger() === 'cron');
putenv('FREEITSM_JOB_TRIGGER');

echo "Part 2 - jobRunNow()\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    echo "  skip  set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this part writes job runs\n";
} else {
    // A scheduled job this database needs, and one it does not.
    $needed = $notNeeded = null;
    foreach (jobRegistry() as $key => $def) {
        if (jobSchedulerCommand($def['command']) === null) continue;
        try { $n = (bool)$def['needed']($conn); } catch (Throwable) { continue; }
        if ($n && !$needed) $needed = $key;
        if (!$n && !$notNeeded) $notNeeded = $key;
    }
    $calls = [];
    $spawn = function (string $php, string $script, array $args, array $env = []) use (&$calls): bool {
        $calls[] = ['script' => basename($script), 'args' => $args,
                    'trigger' => $env['FREEITSM_JOB_TRIGGER'] ?? null, 'analyst' => $env['FREEITSM_JOB_ANALYST'] ?? null,
                    'this_process' => getenv('FREEITSM_JOB_TRIGGER')];
        return true;
    };
    $conn->beginTransaction();
    try {
        $lastRun = function (string $job) use ($conn) {
            $s = $conn->prepare("SELECT trigger_kind, outcome, analyst_id, summary FROM job_runs WHERE job_key = ? ORDER BY id DESC LIMIT 1");
            $s->execute([$job]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        check('an unknown job is refused', jobRunNow($conn, 'zz-no-such-job', 7, $spawn) === 'unknown' && !$calls);
        if ($notNeeded) {
            check("a job not needed here is refused ($notNeeded)", jobRunNow($conn, $notNeeded, 7, $spawn) === 'not_needed' && !$calls);
            $r = $lastRun($notNeeded);
            check('...and the refused press is on the record: skipped, Run now, by whom, why', $r && $r['trigger_kind'] === 'manual'
                && $r['outcome'] === 'skipped' && (int)$r['analyst_id'] === 7 && str_contains((string)$r['summary'], 'not needed'), json_encode($r));
        } else {
            echo "  skip  every scheduled job is needed on this database\n";
        }

        if ($needed) {
            $def = jobRegistry()[$needed];
            $r = jobRunNow($conn, $needed, 7, $spawn);
            check("POSITIVE CONTROL: a needed job is started ($needed)", $r === 'started' && count($calls) === 1, $r);
            check('...its own script, from the registry', ($calls[0]['script'] ?? '') === basename(jobSchedulerCommand($def['command'])[0]));
            check('...told it is Run now, and by whom', ($calls[0]['trigger'] ?? '') === 'manual' && ($calls[0]['analyst'] ?? '') === '7', json_encode($calls[0] ?? null));
            check('...given to the new process only - this one never says so, not even while starting it',
                ($calls[0]['this_process'] ?? null) === false && getenv('FREEITSM_JOB_TRIGGER') === false && getenv('FREEITSM_JOB_ANALYST') === false);

            // The child records itself as manual, with the analyst.
            putenv('FREEITSM_JOB_TRIGGER=manual'); putenv('FREEITSM_JOB_ANALYST=7');
            $runId = JobRun::start($conn, $needed, JobRun::scheduledTrigger());
            putenv('FREEITSM_JOB_TRIGGER'); putenv('FREEITSM_JOB_ANALYST');
            $row = $conn->prepare("SELECT trigger_kind, analyst_id, outcome FROM job_runs WHERE id = ?");
            $row->execute([$runId]);
            $run = $row->fetch(PDO::FETCH_ASSOC);
            check('the run it makes says manual, and who pressed it', $run && $run['trigger_kind'] === 'manual' && (int)$run['analyst_id'] === 7, json_encode($run));

            $calls = [];
            check('while it runs, a second press is refused', jobRunNow($conn, $needed, 7, $spawn) === 'running' && !$calls);
            $conn->prepare("UPDATE job_runs SET started_datetime = UTC_TIMESTAMP() - INTERVAL ? MINUTE WHERE id = ?")
                 ->execute([(int)($def['max_runtime'] ?? 60) + 5, $runId]);
            check('...but not by a run stuck past its maximum time', jobRunNow($conn, $needed, 7, $spawn) === 'started');
            $failing = fn() => false;
            $conn->prepare("UPDATE job_runs SET outcome = 'ok' WHERE id = ?")->execute([$runId]);
            check('a process PHP could not start is said so', jobRunNow($conn, $needed, 7, $failing) === 'failed_to_start');
            $r = $lastRun($needed);
            check('...and recorded, by whom', $r && $r['outcome'] === 'skipped' && (int)$r['analyst_id'] === 7 && str_contains((string)$r['summary'], 'could not start'), json_encode($r));

            $s = $conn->prepare("SELECT next_due_at FROM job_schedules WHERE job_key = ?");
            $s->execute([$needed]);
            $before = $s->fetchColumn();
            jobRunNow($conn, $needed, 7, $spawn);
            $s->execute([$needed]);
            check('its schedule is left as it was', $s->fetchColumn() === $before);
        } else {
            echo "  skip  no scheduled job is needed on this database\n";
        }
    } finally {
        $conn->rollBack();
    }
}

echo "Part 3 - starting a process for real\n";
// A stand-in job: writes what it was told into a file beside itself.
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz_jrn_' . bin2hex(random_bytes(4));
@mkdir($dir);
$child = $dir . DIRECTORY_SEPARATOR . 'child.php';
file_put_contents($child, '<?php file_put_contents(__FILE__ . ".out", json_encode([getenv("FREEITSM_JOB_TRIGGER"), getenv("FREEITSM_JOB_ANALYST")]));');
$ok = jobSchedulerSpawn(PHP_BINARY, $child, [], ['FREEITSM_JOB_TRIGGER' => 'manual', 'FREEITSM_JOB_ANALYST' => '42']);
for ($i = 0; $i < 100 && !is_file("$child.out"); $i++) usleep(100000);
$got = is_file("$child.out") ? json_decode((string)file_get_contents("$child.out"), true) : null;
check('POSITIVE CONTROL: the process starts, and is told it is Run now and by whom', $ok && $got === ['manual', '42'], json_encode([$ok, $got]));
check('...while this process is not', getenv('FREEITSM_JOB_TRIGGER') === false && getenv('FREEITSM_JOB_ANALYST') === false);

// A server whose disable_functions removes popen and proc_open: PHP 8 throws calling
// one, so the spawn must say "could not start" (failed_to_start, recorded) instead.
$probe = $dir . DIRECTORY_SEPARATOR . 'disabled.php';
file_put_contents($probe, '<?php require ' . var_export(realpath(__DIR__ . '/../includes/job_scheduler.php'), true) . ';'
    . ' echo json_encode([jobSchedulerSpawn(PHP_BINARY, __FILE__, []), jobSchedulerSpawn(PHP_BINARY, __FILE__, [], ["A" => "1"])]);');
$out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=popen,proc_open ' . escapeshellarg($probe) . ' 2>&1'));
check('with popen and proc_open disabled, a spawn answers false rather than failing', $out === '[false,false]', $out);
foreach (["$child.out", $child, $probe] as $f) @unlink($f);
@rmdir($dir);

echo "Part 4 - the endpoint\n";
$base = getenv('FREEITSM_BASE_URL');
if (!$base || !$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    echo "  skip  set FREEITSM_BASE_URL and FREEITSM_TEST_DATABASE for the throwaway install\n";
} else {
    require_once 'includes/rbac.php';
    $tag = 'zzjrn' . bin2hex(random_bytes(3));
    $made = ['analyst' => 0, 'role' => 0];
    $sessions = [];
    $call = function (int $analystId, int $isAdmin, string $method, array $body = [], string $query = '') use ($base, &$sessions): array {
        $sid = 'zzjrn' . bin2hex(random_bytes(6));
        $csrf = bin2hex(random_bytes(32));
        $file = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/' . chr(92)) . '/sess_' . $sid;
        file_put_contents($file, 'analyst_id|i:' . $analystId . ';analyst_name|s:2:"ZZ";is_admin|i:' . $isAdmin . ';csrf_token|s:64:"' . $csrf . '";');
        $sessions[] = $file;
        $ch = curl_init(rtrim($base, '/') . '/api/reporting/job_health.php' . $query);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 30];
        if ($method === 'POST') {
            $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
                      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode($raw, true) ?? ['raw' => substr($raw, 0, 200)]];
    };
    $runs = fn() => (int)$conn->query("SELECT COUNT(*) FROM job_runs")->fetchColumn();
    try {
        $admin = (int)$conn->query('SELECT MIN(id) FROM analysts WHERE is_admin = 1 AND is_active = 1')->fetchColumn();
        // Somebody who may look at Background jobs but not change them.
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants)
                        VALUES (?, '!', 'ZZ job viewer', ?, 1, 0, 1, 1)")->execute([$tag, "$tag@example.invalid"]);
        $made['analyst'] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO rbac_roles (name, is_active) VALUES (?, 1)")->execute(["$tag viewer"]);
        $made['role'] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO rbac_role_capabilities (role_id, capability_key) VALUES (?, ?)")->execute([$made['role'], capKey(Cap::REPORTING_JOB_HEALTH)]);
        $conn->prepare("INSERT INTO rbac_analyst_roles (analyst_id, role_id) VALUES (?, ?)")->execute([$made['analyst'], $made['role']]);

        // A real job's key, so a refusal is about who asks - never about the job.
        $key = null;
        foreach (jobRegistry() as $k => $def) { if (jobSchedulerCommand($def['command']) !== null) { $key = $k; break; } }

        [$code, $j] = $call($made['analyst'], 0, 'GET');
        check('POSITIVE CONTROL: the viewer can see Background jobs', $code === 200 && ($j['success'] ?? false) === true, json_encode([$code, $j['error'] ?? null]));
        $before = $runs();
        [$code, $j] = $call($made['analyst'], 0, 'POST', ['action' => 'run_now', 'job' => $key]);
        check('somebody who may not change the jobs cannot press Run now (403)', $code === 403 && ($j['code'] ?? '') === 'forbidden' && $runs() === $before, json_encode([$code, $j]));
        $before = $runs();
        [$code, $j] = $call($admin, 1, 'GET', [], '?action=run_now&job=' . rawurlencode((string)$key));
        check('a GET never runs a job', $runs() === $before && !isset($j['result']), json_encode([$code, array_keys($j)]));
        [$code, $j] = $call($admin, 1, 'POST', ['action' => 'run_now', 'job' => 'zz-no-such-job']);
        check('a job key that is not in the registry is refused', ($j['success'] ?? true) === false && ($j['result'] ?? '') === 'unknown' && $runs() === $before, json_encode([$code, $j]));
    } catch (Throwable $e) {
        check('the endpoint checks completed', false, get_class($e) . ': ' . $e->getMessage());
    } finally {
        foreach ($sessions as $f) @unlink($f);
        if ($made['role']) {
            $conn->prepare("DELETE FROM rbac_analyst_roles WHERE role_id = ?")->execute([$made['role']]);
            $conn->prepare("DELETE FROM rbac_role_capabilities WHERE role_id = ?")->execute([$made['role']]);
            $conn->prepare("DELETE FROM rbac_roles WHERE id = ?")->execute([$made['role']]);
        }
        if ($made['analyst']) $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$made['analyst']]);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
