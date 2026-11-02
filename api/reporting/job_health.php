<?php
/**
 * API: background job health (#182) - the Background jobs tab, the Watchtower card and the System notice.
 *
 * @input view  GET: health (every job, worked out now), runs (the history, filtered), run (one run with its
 *     sources) or summary (only what needs attention - for Watchtower and the System page)
 * @input job, outcome, trigger, days, limit, offset  GET filters for view=runs
 * @input id  GET: the run for view=run
 * @input action  POST JSON: config - {job, interval (minutes, empty = the default), not_used (bool)}
 * @output JSON {success, ...}
 *
 * Gated on "View background job health" (an administrator holds it), and on reaching every
 * company - see jobHealthMayView(). The summary view answers {visible:false} rather than an
 * error to somebody without it, because Watchtower asks on every load and must stay quiet.
 * Changing a job's expectations needs "Change what background jobs are expected to do".
 *
 * @wiki Background-Jobs-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/job_health.php';
require_once '../../includes/job_runs.php';      // JobRun::TRIGGERS for the filter
I18n::initFromSession();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();
    $view = (string)($_GET['view'] ?? 'health');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $view === 'summary') {
        if (!jobHealthMayView($conn, $analystId)) {
            echo json_encode(['success' => true, 'visible' => false]);
            exit;
        }
        $s = jobHealthSummary($conn);
        echo json_encode(['success' => true, 'visible' => true, 'healthy' => $s['healthy'], 'checked_utc' => $s['checked_utc'],
            'unhealthy' => array_map(fn($j) => ['key' => $j['key'], 'name' => $j['name'], 'affects' => $j['affects'],
                                                 'status' => $j['status']], $s['unhealthy'])]);
        exit;
    }

    requireModuleAccessJson('reporting');
    if (!jobHealthMayView($conn, $analystId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => t('reporting.jobs.no_permission'), 'code' => 'forbidden']);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!in_array($in['action'] ?? '', ['config', 'fallback'], true)) throw new InvalidArgumentException('Unknown action.');
        if (!jobHealthMayConfigure($conn, $analystId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => t('reporting.jobs.no_config_permission'), 'code' => 'forbidden']);
            exit;
        }
        if ($in['action'] === 'fallback') {
            // #233: start due jobs from analysts' pages when no scheduled task runs them. Off by default.
            require_once '../../includes/job_scheduler.php';
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                 ->execute([JOB_SCHEDULER_FALLBACK, !empty($in['on']) ? '1' : '0']);
            echo json_encode(['success' => true]);
            exit;
        }
        $job = (string)($in['job'] ?? '');
        $registry = jobRegistryAll($conn);
        if (!isset($registry[$job])) throw new InvalidArgumentException('Unknown job.');

        $cfg = jobHealthConfig($conn);
        $mine = $cfg[$job] ?? [];
        $interval = (int)($in['interval'] ?? 0);
        $notUsed = !empty($in['not_used']);
        // #233: a scheduled job's schedule IS what health expects of it - one setting, not two.
        // Daily is expected once a day; off is "not used here".
        $mode = (string)($in['mode'] ?? '');
        $atTime = (string)($in['at_time'] ?? '');
        if ($mode !== '') {
            require_once '../../includes/job_scheduler.php';
            if (!isset(jobRegistry()[$job]) || !in_array($mode, JOB_SCHEDULE_MODES, true)) throw new InvalidArgumentException('Unknown schedule.');
            if ($mode === 'daily' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $atTime)) {
                echo json_encode(['success' => false, 'error' => t('reporting.jobs.time_invalid')]);
                exit;
            }
            if ($mode === 'daily') $interval = 1440;
            $notUsed = $mode === 'off';
        }
        if ($interval > 0 && $interval !== (int)$registry[$job]['interval']) $mine['interval'] = max(1, min(60 * 24 * 31, $interval));
        elseif ($mode !== 'off') unset($mine['interval']);
        if ($notUsed && empty($mine['not_used'])) {
            // Who said so, and when - "not used here" silences a job's alarm, so it is on the record.
            $mine['not_used'] = true;
            $mine['not_used_by'] = (string)($_SESSION['analyst_name'] ?? ('#' . $analystId));
            $mine['not_used_at'] = gmdate('Y-m-d H:i:s');
        } elseif (!$notUsed) {
            unset($mine['not_used'], $mine['not_used_by'], $mine['not_used_at']);
        }
        if ($mine) $cfg[$job] = $mine; else unset($cfg[$job]);
        $conn->beginTransaction();
        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('job_health_config', ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([json_encode($cfg)]);
        if ($mode !== '') {
            jobScheduleSave($conn, $job, $mode, $mode === 'interval' ? ($mine['interval'] ?? (int)$registry[$job]['interval']) : null,
                            $mode === 'daily' ? $atTime : null, $analystId);
        }
        $conn->commit();
        echo json_encode(['success' => true, 'job' => jobHealthOne($conn, $registry[$job], $cfg[$job] ?? [])]);
        exit;
    }

    if ($view === 'health') {
        require_once '../../includes/job_scheduler.php';
        // #233: whether the one-line scheduler is running, and each job's schedule.
        require_once '../../includes/storage_persistence.php';
        $beat = jobSetting($conn, JOB_SCHEDULER_BEAT);
        // In the Docker image the container runs the scheduler itself (docker/entrypoint.sh)
        // unless FREEITSM_SCHEDULER turns it off, and the timezone comes from TZ, UTC when unset.
        $container = storagePersistenceInContainer();
        $tz = getenv('TZ');
        echo json_encode([
            'success'         => true,
            'jobs'            => array_values(jobHealthAll($conn)),
            'scheduler'       => [
                'last_beat' => $beat ?: null,
                'running'   => jobSchedulerRunning($conn),
                'line'      => jobSchedulerLine(),
                'timezone'  => date_default_timezone_get(),
                'builtin'   => $container && !in_array(strtolower((string)getenv('FREEITSM_SCHEDULER')), ['0', 'false', 'no', 'off'], true),
                'tz_unset'  => $container && (!is_string($tz) || !in_array($tz, timezone_identifiers_list(), true)),
                'fallback'        => jobSetting($conn, JOB_SCHEDULER_FALLBACK) === '1',
                'fallback_beat'   => jobSetting($conn, JOB_SCHEDULER_FALLBACK_BEAT) ?: null,
                'fallback_error'  => jobSetting($conn, JOB_SCHEDULER_FALLBACK_ERROR) ?: null,
            ],
            'schedules'       => jobSchedules($conn),
            'can_configure'   => jobHealthMayConfigure($conn, $analystId),
            'recording_since' => jobSetting($conn, 'job_runs_recording_since') ?: null,
            'retention_months'=> jobHealthRetentionMonths($conn),
            'checked_utc'     => gmdate('Y-m-d H:i:s'),
        ]);
        exit;
    }

    if ($view === 'runs') {
        $where = [];
        $args = [];
        $job = (string)($_GET['job'] ?? '');
        if ($job !== '' && isset(jobRegistryAll($conn)[$job])) { $where[] = 'job_key = ?'; $args[] = $job; }
        $outcome = (string)($_GET['outcome'] ?? '');
        if (in_array($outcome, ['running', 'ok', 'partial', 'failed', 'skipped'], true)) { $where[] = 'outcome = ?'; $args[] = $outcome; }
        $trigger = (string)($_GET['trigger'] ?? '');
        if (in_array($trigger, JobRun::TRIGGERS, true)) { $where[] = 'trigger_kind = ?'; $args[] = $trigger; }
        $days = (int)($_GET['days'] ?? 0);
        if ($days > 0) { $where[] = 'started_datetime >= UTC_TIMESTAMP() - INTERVAL ? DAY'; $args[] = min($days, 3660); }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $c = $conn->prepare("SELECT COUNT(*) FROM job_runs{$sqlWhere}");
        $c->execute($args);
        $s = $conn->prepare("SELECT r.id, r.job_key, r.trigger_kind, r.outcome, r.started_datetime, r.finished_datetime,
                                    r.duration_ms, r.counts_json, r.summary, a.full_name AS analyst_name,
                                    (SELECT COUNT(*) FROM job_run_sources s WHERE s.run_id = r.id AND s.ok = 0) AS failed_sources
                               FROM job_runs r LEFT JOIN analysts a ON a.id = r.analyst_id{$sqlWhere}
                              ORDER BY r.id DESC LIMIT {$limit} OFFSET {$offset}");
        $s->execute($args);
        $names = [];
        foreach (jobRegistryAll($conn) as $k => $d) $names[$k] = jobHealthName($d);
        $runs = array_map(function ($r) use ($names) {
            $r['job_name'] = $names[$r['job_key']] ?? $r['job_key'];
            $r['counts'] = $r['counts_json'] ? (json_decode($r['counts_json'], true) ?: []) : [];
            unset($r['counts_json']);
            return $r;
        }, $s->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['success' => true, 'runs' => $runs, 'total' => (int)$c->fetchColumn()]);
        exit;
    }

    if ($view === 'run') {
        $id = (int)($_GET['id'] ?? 0);
        $r = $conn->prepare("SELECT r.*, a.full_name AS analyst_name FROM job_runs r LEFT JOIN analysts a ON a.id = r.analyst_id WHERE r.id = ?");
        $r->execute([$id]);
        $run = $r->fetch(PDO::FETCH_ASSOC);
        if (!$run) throw new InvalidArgumentException('Run not found.');
        $run['counts'] = $run['counts_json'] ? (json_decode($run['counts_json'], true) ?: []) : [];
        unset($run['counts_json']);
        $s = $conn->prepare("SELECT source_key, source_label, ok, message, created_datetime FROM job_run_sources WHERE run_id = ? ORDER BY ok, id");
        $s->execute([$id]);
        echo json_encode(['success' => true, 'run' => $run, 'sources' => $s->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    throw new InvalidArgumentException('Unknown view.');
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
