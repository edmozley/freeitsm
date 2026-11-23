<?php
/* 🔴 NEVER OVER THE WEB - see tests/README.md. This one writes to the real
   tables (a throwaway analyst limited to one company, two repeating tasks, all
   named ZZRS and removed at the end, including on failure) and calls the real
   endpoints over HTTP through the local web server. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Repeating tasks respect company scope: setting, stopping and previewing a
 * repeat, and the calendar's projected occurrences, only reach tasks in the
 * companies the analyst can see.
 *
 * Needs a multi-company install (it skips otherwise) and the app served
 * locally. Writes session files to PHP's session.save_path.
 *
 * Run:  php tests/task-recurrence-company-scope.php [base-url]
 *       base-url defaults to http://localhost/freeitsm-app/
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/tenancy.php';

$base = rtrim($argv[1] ?? 'http://localhost/freeitsm-app/', '/') . '/';
$pass = 0; $fail = 0;
function is_(string $what, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %-64s\n", $what); }
    else { $fail++; printf("  FAIL  %-64s got %s, wanted %s\n", $what, json_encode($got), json_encode($want)); }
}
/** A session file for an analyst, with a known CSRF token. */
function session(string $sid, array $vars): void {
    $dir = session_save_path() ?: sys_get_temp_dir();
    $dir = preg_replace('/^\d+;/', '', $dir);
    $raw = '';
    foreach ($vars as $k => $v) $raw .= $k . '|' . serialize($v);
    file_put_contents(rtrim($dir, '/\\') . '/sess_' . $sid, $raw);
}
/** Calls an endpoint as the given session; returns the decoded JSON (or the raw body). */
function call(string $base, string $sid, string $path, ?array $body = null) {
    $ch = curl_init($base . $path);
    $headers = ['Cookie: PHPSESSID=' . $sid, 'X-CSRF-Token: zzrs-token'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
    $out = curl_exec($ch);
    $j = json_decode((string)$out, true);
    return is_array($j) ? $j : ['raw' => substr((string)$out, 0, 300)];
}

$conn = connectToDatabase();
echo "\nRepeating tasks: company scope\n" . str_repeat('=', 78) . "\n";
if (!isMultiTenant($conn)) { echo "  SKIP  single-company install - nothing to cross\n\n"; exit(0); }

$def = getDefaultTenantId($conn);
$other = (int)$conn->query("SELECT id FROM tenants WHERE id <> " . (int)$def . " ORDER BY id LIMIT 1")->fetchColumn();
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
$analyst = null; $tasks = []; $rules = []; $sids = ['zzrs-limited', 'zzrs-admin'];

try {
    // A throwaway analyst who can open Tasks and reach ONE company - not the default one.
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants)
                    VALUES ('zzrs-scope', '!', 'ZZRS Scope', 'zzrs@zzrs.invalid', 1, 0, 0)")->execute();
    $analyst = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$analyst, $other]);
    $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key) VALUES (?, 'tasks')")->execute([$analyst]);

    // Two repeating tasks, on a fixed schedule so the calendar projects them.
    $status = (int)$conn->query("SELECT id FROM task_statuses WHERE is_closed = 0 ORDER BY display_order, id LIMIT 1")->fetchColumn();
    foreach (['theirs' => $def, 'mine' => $other] as $k => $tenant) {
        $conn->prepare("INSERT INTO task_recurrences (mode, freq, interval_n, ends_mode, next_due_date, is_active, created_datetime)
                        VALUES ('schedule', 'monthly', 1, 'never', DATE_ADD(CURDATE(), INTERVAL 20 DAY), 1, UTC_TIMESTAMP())")->execute();
        $rules[$k] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO tasks (title, status_id, created_by_id, created_datetime, due_date, tenant_id, recurrence_id)
                        VALUES (?, ?, ?, UTC_TIMESTAMP(), CURDATE(), ?, ?)")
             ->execute(['ZZRS ' . $k, $status, $admin, $tenant, $rules[$k]]);
        $tasks[$k] = (int)$conn->lastInsertId();
    }

    session('zzrs-limited', ['analyst_id' => $analyst, 'analyst_name' => 'ZZRS Scope', 'username' => 'zzrs-scope', 'allowed_modules' => ['tasks'], 'csrf_token' => 'zzrs-token']);
    session('zzrs-admin',   ['analyst_id' => $admin, 'analyst_name' => 'ZZRS Admin', 'username' => 'admin', 'is_admin' => 1, 'csrf_token' => 'zzrs-token']);
    $rule = ['mode' => 'schedule', 'freq' => 'monthly', 'interval_n' => 1, 'ends_mode' => 'never'];

    echo "\nAn analyst who can reach only one company:\n";
    // The calendar first, while both repeats are certainly still running.
    $r = call($base, 'zzrs-limited', 'api/tasks/projected.php?from=' . date('Y-m-d') . '&to=' . date('Y-m-d', strtotime('+90 days')));
    $titles = array_values(array_unique(array_column($r['projected'] ?? [], 'title')));
    is_('the calendar projects their company\'s repeat', in_array('ZZRS mine', $titles, true), true);
    is_('...and not another company\'s', in_array('ZZRS theirs', $titles, true), false);
    $r = call($base, 'zzrs-limited', 'api/tasks/recurrence_preview.php', ['task_id' => $tasks['theirs']] + $rule);
    is_('preview of another company\'s task is refused', [$r['success'] ?? null, $r['error'] ?? null], [false, 'No such task']);
    is_('...and does not reveal its title', !str_contains(json_encode($r), 'ZZRS theirs'), true);
    $r = call($base, 'zzrs-limited', 'api/tasks/save_recurrence.php', ['task_id' => $tasks['theirs'], 'off' => 1]);
    is_('stopping another company\'s repeat is refused', [$r['success'] ?? null, $r['error'] ?? null], [false, 'No such task']);
    is_('...and it is still running', (int)$conn->query("SELECT is_active FROM task_recurrences WHERE id = " . $rules['theirs'])->fetchColumn(), 1);
    $r = call($base, 'zzrs-limited', 'api/tasks/recurrence_preview.php', ['task_id' => $tasks['mine']] + $rule);
    is_('preview of their own company\'s task works', $r['success'] ?? null, true);

    echo "\nPositive control - an administrator who can reach every company:\n";
    $r = call($base, 'zzrs-admin', 'api/tasks/recurrence_preview.php', ['task_id' => $tasks['theirs']] + $rule);
    is_('preview of the Default company\'s task works', $r['success'] ?? null, true);
    $r = call($base, 'zzrs-admin', 'api/tasks/projected.php?from=' . date('Y-m-d') . '&to=' . date('Y-m-d', strtotime('+90 days')));
    $titles = array_values(array_unique(array_column($r['projected'] ?? [], 'title')));
    // The calendar follows the same rule as the task board: the company you are
    // working in (or every company under "All companies"). The admin's is Default.
    is_('the calendar projects their working company\'s repeat', in_array('ZZRS theirs', $titles, true), true);
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected: " . $e->getMessage() . "\n";
} finally {
    // #181's history outlives its tasks on purpose (no foreign keys) - remove it explicitly.
    if ($tasks) $conn->exec("DELETE FROM task_events WHERE task_id IN (" . implode(',', $tasks) . ")");
    if ($tasks) $conn->exec("DELETE FROM tasks WHERE id IN (" . implode(',', $tasks) . ")");
    if ($rules) $conn->exec("DELETE FROM task_events WHERE recurrence_id IN (" . implode(',', $rules) . ")");
    if ($rules) $conn->exec("DELETE FROM task_recurrence_versions WHERE recurrence_id IN (" . implode(',', $rules) . ")");
    if ($rules) $conn->exec("DELETE FROM task_recurrences WHERE id IN (" . implode(',', $rules) . ")");
    if ($analyst) {
        $conn->exec("DELETE FROM analyst_modules WHERE analyst_id = $analyst");
        $conn->exec("DELETE FROM analyst_tenant_access WHERE analyst_id = $analyst");
        $conn->exec("DELETE FROM analysts WHERE id = $analyst");
    }
    $dir = preg_replace('/^\d+;/', '', session_save_path() ?: sys_get_temp_dir());
    foreach ($sids as $sid) @unlink(rtrim($dir, '/\\') . '/sess_' . $sid);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM tasks WHERE title LIKE 'ZZRS %') + (SELECT COUNT(*) FROM analysts WHERE username = 'zzrs-scope')")->fetchColumn();
    is_('cleanup: nothing named ZZRS is left', $left, 0);
}

echo "\n" . str_repeat('-', 78) . "\n  $pass passed, $fail failed\n\n";
exit($fail ? 1 : 0);
