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
    $spawn = function (string $php, string $script, array $args) use (&$calls): bool {
        $calls[] = ['script' => basename($script), 'args' => $args,
                    'trigger' => getenv('FREEITSM_JOB_TRIGGER'), 'analyst' => getenv('FREEITSM_JOB_ANALYST')];
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
            check('...and this process is not left saying so', getenv('FREEITSM_JOB_TRIGGER') === false && getenv('FREEITSM_JOB_ANALYST') === false);

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

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
