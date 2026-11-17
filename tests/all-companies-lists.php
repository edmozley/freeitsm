<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Change Management follows "All companies"; CMDB and Starters say they don't (GH #373).
 *
 *   php tests/all-companies-lists.php      (run from a TEST install, never a real one)
 *
 * Under All companies the change list and calendar showed only the LAST company
 * picked, with nothing on screen to say so: they used activeTenantFilter(), which
 * always answers with one company (the TRAP on activeTenantReadFilter()). They now
 * widen, and each card names its company. The ticket's "Link to change" picker
 * (list.php?for_ticket=) follows the TICKET's company instead - a picker follows
 * the record's own company, and another company's change could not be linked.
 * CMDB and Starters & leavers stay on one company (their pickers follow the
 * selected company) and say which: oneCompanyNoteName().
 *
 * Drives the REAL endpoints in-process (api/change-management/list.php and
 * get_calendar_changes.php).
 * Writes, and removes in a finally block by the ids it got back:
 *   - one company "ZZ All companies lists" (a single-company install becomes
 *     multi-company for the run - that is why this must be a test install);
 *   - one change in the Default company and one in the ZZ company.
 * Reads one existing Default-company ticket for the picker; changes nothing on it.
 * Control: with the old activeTenantFilter() the All companies checks fail.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';

$pass = 0; $fail = 0;
/**
 * Records and prints one PASS or FAIL line.
 *
 * @param string $what  what is being checked, in a few words
 * @param bool $cond  true when the check passed
 * @param string $detail  shown after a failure - the values that disagreed
 */
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

/**
 * Runs a Change Management endpoint in this process with the session and query
 * given and returns its decoded JSON.
 *
 * @param string $file  the endpoint's file name under api/change-management/
 * @param array $session  analyst_id, active_tenant_id, active_tenant_all
 * @param array $get  the query string
 */
function callChanges(string $file, array $session, array $get): array {
    $endpoint = realpath(__DIR__ . '/../api/change-management/' . $file);
    $_SESSION = $session;
    $_GET = $get;
    $cwd = getcwd();
    // After this script has printed, the endpoint's session_start() and header()
    // can only warn on the command line. Swallow exactly those two.
    set_error_handler(function ($no, $msg) {
        return stripos($msg, 'session') !== false || stripos($msg, 'headers already sent') !== false;
    });
    ob_start();
    try {
        chdir(dirname($endpoint));
        include $endpoint;
    } finally {
        $raw = (string)ob_get_clean();
        chdir($cwd);
        restore_error_handler();
    }
    $d = json_decode(trim($raw), true);
    if (!is_array($d) || empty($d['success'])) {
        echo "  FAIL  $file did not answer: " . substr(trim($raw), 0, 300) . "\n";
        $GLOBALS['fail']++;
        return [];
    }
    return $d;
}

echo "\nAll companies: Change Management widens, CMDB and Starters say they don't (GH #373)\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
echo "  database: $db\n";
if (!preg_match('/test/i', $db)) { echo "  STOP  this test adds a company; run it only on a test install\n"; exit(1); }

$viewer = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$viewer) { echo "  SKIP  no active analyst with access to every company\n"; exit(0); }

$tenantZ = 0; $changes = [];
try {
    // The company first: tenantCount() caches, and nothing may ask it before this.
    $conn->prepare("INSERT INTO tenants (name) VALUES (?)")->execute(['ZZ All companies lists']);
    $tenantZ = (int)$conn->lastInsertId();
    $default = getDefaultTenantId($conn);
    ok('the install is multi-company for the run', isMultiTenant($conn));
    $ticket = (int)$conn->query("SELECT id FROM tickets WHERE tenant_id IS NULL OR tenant_id = $default ORDER BY id LIMIT 1")->fetchColumn();

    // Both changes sit in the calendar's window: today, an hour long.
    $mkChange = function (string $title, int $tenant) use ($conn, &$changes): int {
        $conn->prepare("INSERT INTO changes (title, tenant_id, work_start_datetime, work_end_datetime, created_datetime, modified_datetime)
                        VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 1 HOUR, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$title, $tenant]);
        return $changes[] = (int)$conn->lastInsertId();
    };
    $changeD = $mkChange('ZZ Allco change Default', $default);
    $changeZ = $mkChange('ZZ Allco change Other', $tenantZ);

    $all = ['analyst_id' => $viewer, 'active_tenant_id' => $default, 'active_tenant_all' => true];
    $one = ['analyst_id' => $viewer, 'active_tenant_id' => $default, 'active_tenant_all' => false];
    $oth = ['analyst_id' => $viewer, 'active_tenant_id' => $tenantZ, 'active_tenant_all' => false];
    $listIds = function (array $s, array $get = []) {
        $d = callChanges('list.php', $s, $get + ['search' => 'ZZ Allco change']);
        return [array_map('intval', array_column($d['changes'] ?? [], 'id')), $d];
    };

    echo "\nThe change list\n";
    [$ids, $d] = $listIds($all);
    ok('All companies lists the change in each company', in_array($changeD, $ids, true) && in_array($changeZ, $ids, true), json_encode($ids));
    $names = array_column($d['changes'] ?? [], 'company_name', 'id');
    ok('...and each card names its company', !empty($d['show_company']) && ($names[$changeZ] ?? '') === 'ZZ All companies lists'
        && ($names[$changeD] ?? '') !== '', json_encode($names));
    [$ids, $d] = $listIds($one);
    ok('one company still lists only its own change', $ids === [$changeD], json_encode($ids));
    ok('...without company names (one company: nothing to tell apart)', empty($d['show_company']));
    [$ids] = $listIds($oth);
    ok('the other company lists only its own change', $ids === [$changeZ], json_encode($ids));

    echo "\nThe ticket's Link to change picker follows the ticket's company\n";
    if ($ticket) {
        [$ids] = $listIds($all, ['for_ticket' => $ticket]);
        ok("All companies: a Default ticket's picker lists only Default changes", $ids === [$changeD], json_encode($ids));
        [$ids] = $listIds($oth, ['for_ticket' => $ticket]);
        ok("another company selected: the picker still lists the ticket's company", $ids === [$changeD], json_encode($ids));
    } else {
        echo "  SKIP  no Default-company ticket to open the picker for\n";
    }

    echo "\nThe change calendar\n";
    $calIds = fn(array $s) => array_map('intval', array_column(callChanges('get_calendar_changes.php', $s,
        ['start' => gmdate('Y-m-d', time() - 86400), 'end' => gmdate('Y-m-d', time() + 2 * 86400)])['events'] ?? [], 'id'));
    $c = $calIds($all);
    ok('All companies shows the change in each company', in_array($changeD, $c, true) && in_array($changeZ, $c, true), json_encode($c));
    $c = $calIds($one);
    ok("one company does not show the other company's change", in_array($changeD, $c, true) && !in_array($changeZ, $c, true), json_encode($c));

    echo "\nCMDB and Starters say which company they show\n";
    $_SESSION = $all;
    $defaultName = (string)(getTenantById($conn, $default)['name'] ?? '');
    ok('All companies: the note names the selected company', oneCompanyNoteName($conn, $viewer) === $defaultName, oneCompanyNoteName($conn, $viewer));
    $_SESSION = $one;
    ok('one company: no note', oneCompanyNoteName($conn, $viewer) === '');
    foreach (['cmdb', 'starters'] as $ns) {
        $lang = include __DIR__ . "/../lang/en/$ns.php";
        ok("$ns has the note in English", str_contains($lang['list']['one_company_note'] ?? '', '{company}'));
    }
} finally {
    if ($changes) {
        $in = implode(',', array_fill(0, count($changes), '?'));
        $conn->prepare("DELETE FROM changes WHERE id IN ($in)")->execute($changes);
    }
    if ($tenantZ) $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tenantZ]);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM tenants WHERE name = 'ZZ All companies lists')
                                    + (SELECT COUNT(*) FROM changes WHERE title LIKE 'ZZ Allco change %')")->fetchColumn();
    echo "\n";
    ok('everything the test wrote is gone', $left === 0, "$left rows left");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
