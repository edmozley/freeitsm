<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * People - the organisation chart: who is drawn, under whom, and for which reader.
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/people-org.php
 *
 * Through the real peopleOrgLevel(), peopleOrgPath(), peopleOrgBranch() and
 * peopleOrgGaps(). Builds two companies and a small organisation in them,
 * including a leaver who still manages people, a manager in the other company,
 * a reporting line that loops, a long chain and a manager of 160 people.
 *
 * It writes rows, so it runs only when FREEITSM_TEST_DATABASE names the
 * database config.php points at, and everything happens inside a transaction
 * that is ALWAYS rolled back.
 *
 * Controls: an analyst who can see every company sees the manager the
 * restricted one may not, and leavers appear when asked for, so a missing
 * person means "left out", not "broken".
 *
 * @wiki Organisation-Chart-Developer-Guide
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/people.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (this one is {$dbName}).\n";
    exit(0);
}

$tag = 'zz-org-' . bin2hex(random_bytes(3));
$conn->beginTransaction();
try {
    // Two companies of our own, created before anything asks how many there are.
    $co = function (string $name) use ($conn): int {
        $conn->prepare("INSERT INTO tenants (name, is_active, is_default) VALUES (?, 1, 0)")->execute([$name]);
        return (int)$conn->lastInsertId();
    };
    $a = $co("$tag A");
    $b = $co("$tag B");
    $analyst = function (bool $all) use ($conn, $tag): int {
        $n = $tag . ($all ? '-all' : '-a');
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, can_access_all_tenants, can_access_all_modules)
                        VALUES (?, 'x', ?, ?, 1, ?, 1)")->execute([$n, $n, "$n@example.invalid", $all ? 1 : 0]);
        return (int)$conn->lastInsertId();
    };
    $r = $analyst(false);                     // can see company A only
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$r, $a]);
    $all = $analyst(true);                    // can see every company
    check('the restricted analyst sees company A only (setup)', getAccessibleTenantIds($conn, $r) === [$a], json_encode(getAccessibleTenantIds($conn, $r)));

    $n = 0;
    $mk = function (int $tenant, string $name, ?int $manager = null, bool $active = true, bool $managed = true) use ($conn, $tag, &$n): int {
        $n++;
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, manager_id, is_active, is_managed) VALUES (?, ?, ?, ?, ?, ?)")
             ->execute(["$tag-$n@example.invalid", "$tag $name", $tenant, $manager, $active ? 1 : 0, $managed ? 1 : 0]);
        return (int)$conn->lastInsertId();
    };
    $boss      = $mk($a, 'Boss');
    $mid       = $mk($a, 'Mid', $boss);
    $leaf1     = $mk($a, 'Leaf One', $mid);
    $leaf2     = $mk($a, 'Leaf Two', $mid);
    $goneQuiet = $mk($a, 'Gone Quiet', $boss, false);          // a leaver nobody reports to
    $goneMgr   = $mk($a, 'Gone Manager', $boss, false);        // a leaver who still manages somebody
    $stranded  = $mk($a, 'Stranded', $goneMgr);
    $orphan    = $mk($a, 'Orphan');                            // from a directory, no manager, manages nobody
    $byHand    = $mk($a, 'By Hand', null, true, false);        // typed in, no manager: normal, not a gap
    $theirBoss = $mk($b, 'Their Boss');                        // company B
    $other     = $mk($b, 'Other', $boss);                      // company B, reports into company A
    $under     = $mk($a, 'Under', $theirBoss);                 // company A, manager in company B
    $under2    = $mk($a, 'Under Two', $under);
    $loop1     = $mk($a, 'Loop One');
    $loop2     = $mk($a, 'Loop Two', $loop1);
    $conn->prepare("UPDATE users SET manager_id = ? WHERE id = ?")->execute([$loop2, $loop1]);   // a loop bad data can make
    $loop3     = $mk($a, 'Loop Three', $loop1);
    $ids = fn(array $nodes) => array_column($nodes, 'id');
    $same = function (array $x, array $y): bool { sort($x); sort($y); return $x === $y; };
    $named = fn(array $nodes) => array_column($nodes, 'reports', 'id');

    echo "The top of the chart\n";
    $top = peopleOrgLevel($conn, $r, 0, false)['people'];
    check('a manager with nobody above them is at the top', in_array($boss, $ids($top), true));
    check('somebody whose manager is in a company the reader cannot see starts a branch there', in_array($under, $ids($top), true), json_encode($ids($top)));
    check('...and that manager is never named', !str_contains(json_encode(peopleOrgLevel($conn, $r, 0, true)), 'Their Boss'));
    check('somebody who manages nobody is not at the top', !array_intersect([$orphan, $byHand, $leaf1], $ids($top)));
    $topAll = peopleOrgLevel($conn, $all, 0, false)['people'];
    check('CONTROL: an analyst who can see both companies has that manager at the top instead', in_array($theirBoss, $ids($topAll), true) && !in_array($under, $ids($topAll), true));

    echo "One level down\n";
    $lv = peopleOrgLevel($conn, $r, $boss, false)['people'];
    check('the people who report to the boss, in the reader\'s companies', $same($ids($lv), [$mid, $goneMgr]), json_encode($ids($lv)));
    check('a leaver who still manages somebody is shown, so the gap is', in_array($goneMgr, $ids($lv), true));
    check('a leaver nobody reports to is hidden by default', !in_array($goneQuiet, $ids($lv), true));
    check('CONTROL: ...and shown when leavers are asked for', in_array($goneQuiet, $ids(peopleOrgLevel($conn, $r, $boss, true)['people']), true));
    check('a report in a company the reader cannot see is left out', !in_array($other, $ids($lv), true));
    check('CONTROL: ...and included for an analyst who can see it', in_array($other, $ids(peopleOrgLevel($conn, $all, $boss, false)['people']), true));
    check('each person says how many the reader would see under them', ($named($lv)[$mid] ?? null) === 2 && ($named($lv)[$goneMgr] ?? null) === 1, json_encode($named($lv)));
    check("the boss's own count matches the level below", ($named($top)[$boss] ?? null) === count($lv), json_encode($named($top)));
    check('a person out of reach has no level at all', peopleOrgLevel($conn, $r, $theirBoss, true)['people'] === []);

    echo "The line from the top to one person\n";
    check('down to a leaf', (peopleOrgPath($conn, $r, $leaf1)['ids'] ?? null) === [$boss, $mid, $leaf1], json_encode(peopleOrgPath($conn, $r, $leaf1)));
    check('...naming the person it ends at', (peopleOrgPath($conn, $r, $leaf1)['name'] ?? null) === "$tag Leaf One");
    check('it stops at a manager the reader cannot see', (peopleOrgPath($conn, $r, $under2)['ids'] ?? null) === [$under, $under2]);
    check('a person out of reach has no line', peopleOrgPath($conn, $r, $other) === null);
    $lp = peopleOrgPath($conn, $r, $loop3);
    check('a loop ends, each person at most once', $lp !== null && count($lp['ids']) === count(array_unique($lp['ids'])) && count($lp['ids']) <= 3, json_encode($lp));

    echo "One branch, for the chart\n";
    $br = peopleOrgBranch($conn, $r, $boss, false);
    $kids = $br ? $ids($br['root']['children']) : [];
    check('the branch starts from the person asked for', ($br['root']['id'] ?? 0) === $boss && $br['manager'] === null);
    check('...with the same people under them as the tree', $same($kids, [$mid, $goneMgr]), json_encode($kids));
    $midNode = $br ? array_values(array_filter($br['root']['children'], fn($c) => $c['id'] === $mid))[0] ?? null : null;
    check('...and the level below that', $midNode && count($midNode['children']) === 2 && $midNode['more'] === 0);
    check('a branch says who the person reports to, when the reader may see them', (peopleOrgBranch($conn, $r, $mid, false)['manager']['id'] ?? 0) === $boss);
    $ub = peopleOrgBranch($conn, $r, $under, false);
    check('...and not when the reader may not', $ub !== null && $ub['manager'] === null, json_encode($ub['manager'] ?? 'no branch'));
    check('a branch out of reach is not found', peopleOrgBranch($conn, $r, $theirBoss, false) === null);
    $whole = peopleOrgBranch($conn, $r, 0, false);
    check('with nobody chosen, the chart is the whole organisation: the same people at the top as the tree',
        $whole !== null && $whole['root']['id'] === 0 && $same($ids($whole['root']['children']), $ids($top)), json_encode($whole ? $ids($whole['root']['children']) : null));
    check('...which never names the manager the reader may not see', !str_contains(json_encode($whole), 'Their Boss'));
    check('...and stays within the limit', $whole['total'] <= PEOPLE_ORG_CHART_MAX && $whole['manager'] === null);
    $bossTop = array_values(array_filter($whole['root']['children'], fn($c) => $c['id'] === $boss))[0] ?? null;
    $leafOf = function ($n, $id) use (&$leafOf) { if ($n['id'] === $id) return true; foreach ($n['children'] as $c) if ($leafOf($c, $id)) return true; return false; };
    check('...and the top row does not use up a level: a leaf two below the top is drawn', $bossTop && $leafOf($bossTop, $leaf1));
    $lb = peopleOrgBranch($conn, $r, $loop1, false);
    check('a loop is drawn once and ends', $lb !== null && $lb['total'] <= 3, json_encode($lb['total'] ?? null));

    $d = [$mk($a, 'Depth 0')];
    for ($i = 1; $i <= 4; $i++) $d[] = $mk($a, "Depth $i", $d[$i - 1]);
    $db = peopleOrgBranch($conn, $r, $d[0], false);
    $deep = $db['root'];
    for ($i = 0; $i < PEOPLE_ORG_CHART_LEVELS; $i++) $deep = $deep['children'][0] ?? null;
    check('it stops ' . PEOPLE_ORG_CHART_LEVELS . ' levels down, and says what is left', $deep && $deep['id'] === $d[PEOPLE_ORG_CHART_LEVELS] && $deep['children'] === [] && $deep['more'] === 1 && $db['truncated'] === true);
    $db4 = peopleOrgBranch($conn, $r, $d[0], false, 4);
    $deep = $db4['root'];
    for ($i = 0; $i < 4; $i++) $deep = $deep['children'][0] ?? null;
    check('...or as many as asked: 4 levels reach the end of the line, with nothing left out', $deep && $deep['id'] === $d[4] && $deep['more'] === 0 && $db4['truncated'] === false);
    // The levels asked for, one check each: held between 1 and the limit, 0 and
    // "all" are the limit, and anything that is not a whole number is the default.
    $max = PEOPLE_ORG_CHART_LEVELS_MAX;
    $def = PEOPLE_ORG_CHART_LEVELS;
    foreach ([
        ['nothing asked (null)', null, $def], ['an empty string', '', $def], ["'1'", '1', 1], ["'7'", '7', 7],
        ["'$max', the limit", (string)$max, $max], ['99', 99, $max], ['a number past any integer', '99999999999999999999', $max],
        ["'0', no limit", '0', $max], ['0 as a number', 0, $max], ["'00', no limit too", '00', $max], ["'000'", '000', $max], ["'all'", 'all', $max],
        ["' 7 ', padded", ' 7 ', 7], ["'007'", '007', 7],
        ['-2', -2, $def], ["'-1'", '-1', $def], ["'2.5'", '2.5', $def], ["'1e3'", '1e3', $def], ["'0x10'", '0x10', $def],
        ["'abc'", 'abc', $def], ["'ALL'", 'ALL', $def], ['a NUL byte', "\0", $def], ['a list', ['5'], $def],
    ] as [$label, $asked, $want]) {
        $got = peopleOrgChartLevels($asked);
        check("levels $label is $want", $got === $want, "got $got");
    }
    $dall = peopleOrgBranch($conn, $r, $d[0], false, peopleOrgChartLevels('all'));
    check('...and all of them draws the whole line, with nothing left out', $dall && $dall['total'] === 5 && $dall['truncated'] === false);
    $cx = peopleOrgContext($conn, $r, $d[1], false, 1);
    $nodeOf = function ($n, $id) use (&$nodeOf) { if ($n['id'] === $id) return $n; foreach ($n['children'] as $c) if ($f = $nodeOf($c, $id)) return $f; return null; };
    $deep = $cx ? $nodeOf($cx['root'], $d[1]) : null;
    check("the person's place in the organisation takes the levels too", $deep && count($deep['children']) === 1 && $deep['children'][0]['children'] === [] && $deep['children'][0]['more'] === 1);

    $big = $mk($a, 'Big');
    $ins = $conn->prepare("INSERT INTO users (email, display_name, tenant_id, manager_id, is_active) VALUES (?, ?, ?, ?, 1)");
    for ($i = 0; $i < 160; $i++) $ins->execute(["$tag-big-$i@example.invalid", "$tag Big report $i", $a, $big]);
    $bb = peopleOrgBranch($conn, $r, $big, false);
    check('it never draws more than ' . PEOPLE_ORG_CHART_MAX . ' people', $bb['total'] === PEOPLE_ORG_CHART_MAX && $bb['truncated'] === true, json_encode([$bb['total'], $bb['truncated']]));
    check('...and says how many it left out', $bb['root']['more'] === 160 - (PEOPLE_ORG_CHART_MAX - 1), json_encode($bb['root']['more']));

    echo "A person in the organisation\n";
    $cx = peopleOrgContext($conn, $r, $leaf1, false);
    // $walk, not $n: $n is the counter $mk() makes unique emails from.
    $line = []; $walk = $cx['root'] ?? null;
    while ($walk) {
        $line[] = $walk['id'];
        $next = null;
        foreach ($walk['children'] as $c) if ($c['children'] || $c['id'] === $leaf1) { $next = $c; }
        $walk = ($walk['id'] === $leaf1) ? null : $next;
    }
    check('it starts at the top and runs down to the person', $cx !== null && $line === [$boss, $mid, $leaf1], json_encode($line));
    check('...and says who it is about', ($cx['focus'] ?? 0) === $leaf1);
    $bossKids = $cx ? $ids($cx['root']['children']) : [];
    check("each manager's team is beside the line (the boss's other report is there, closed)", in_array($goneMgr, $bossKids, true));
    check('...but not people the reader may not see', !in_array($other, $bossKids, true));
    $cxu = peopleOrgContext($conn, $r, $under2, false);
    check('the line stops at a manager the reader may not see', ($cxu['root']['id'] ?? 0) === $under && !str_contains(json_encode($cxu), 'Their Boss'));
    check('a person out of reach has no place to show', peopleOrgContext($conn, $r, $other, false) === null);
    $bigKid = (int)$conn->query("SELECT id FROM users WHERE email = '$tag-big-100@example.invalid'")->fetchColumn();
    $cxb = peopleOrgContext($conn, $r, $bigKid, false);
    check('a large team on the way up shows the person and ' . PEOPLE_ORG_CONTEXT_PEERS . ' others, and counts the rest',
        $cxb && count($cxb['root']['children']) === PEOPLE_ORG_CONTEXT_PEERS + 1 && in_array($bigKid, $ids($cxb['root']['children']), true)
        && $cxb['root']['more'] === 160 - (PEOPLE_ORG_CONTEXT_PEERS + 1) && $cxb['truncated'] === true,
        json_encode([$cxb ? count($cxb['root']['children']) : null, $cxb['root']['more'] ?? null]));

    echo "Leavers on the line\n";
    // Old top left, and so did the only person under them, Old boss - who still
    // has somebody current. With leavers hidden the tree starts at Old boss.
    $oldTop  = $mk($a, 'Old top', null, false);
    $oldBoss = $mk($a, 'Old boss', $oldTop, false);
    $still   = $mk($a, 'Still here', $oldBoss);
    $topNow = $ids(peopleOrgLevel($conn, $r, 0, false)['people']);
    check('CONTROL: with leavers hidden the tree starts at the leaver who still manages somebody', in_array($oldBoss, $topNow, true) && !in_array($oldTop, $topNow, true));
    $p = peopleOrgPath($conn, $r, $still, false);
    check('...and so does the line down to the person under them', ($p['ids'] ?? null) === [$oldBoss, $still], json_encode($p['ids'] ?? null));
    check('...which starts higher with leavers shown, as the tree does', (peopleOrgPath($conn, $r, $still, true)['ids'] ?? null) === [$oldTop, $oldBoss, $still]);
    $cx = peopleOrgContext($conn, $r, $still, false);
    check('...and their place in the organisation starts there too', ($cx['root']['id'] ?? 0) === $oldBoss, json_encode($cx['root']['id'] ?? null));

    // A manager with two current people and two who left.
    $cBoss = $mk($a, 'Context boss');
    $cOne  = $mk($a, 'Context one', $cBoss);
    $mk($a, 'Context two', $cBoss);
    $mk($a, 'Context gone 1', $cBoss, false);
    $mk($a, 'Context gone 2', $cBoss, false);
    $cx = peopleOrgContext($conn, $r, $cOne, false);
    check('a manager on the line does not count hidden leavers as people left out', ($cx['root']['id'] ?? 0) === $cBoss
        && $cx['root']['more'] === 0 && count($cx['root']['children']) === 2 && $cx['truncated'] === false,
        json_encode([$cx['root']['more'] ?? null, count($cx['root']['children'] ?? []), $cx['truncated'] ?? null]));
    $cx = peopleOrgContext($conn, $r, $cOne, true);
    check('CONTROL: ...and draws them with leavers shown', count($cx['root']['children'] ?? []) === 4 && $cx['root']['more'] === 0);

    // A branch drawn from a leaver the tree hides.
    $goneRoot = $mk($a, 'Gone root', null, false);
    $goneMid  = $mk($a, 'Gone middle', $goneRoot, false);
    $mk($a, 'Under gone', $goneMid);
    $gb = peopleOrgBranch($conn, $r, $goneRoot, false);
    check('a branch from a hidden leaver counts what is drawn under them', ($gb['root']['reports'] ?? null) === 1
        && count($gb['root']['children']) === 1 && $gb['root']['more'] === 0, json_encode([$gb['root']['reports'] ?? null, $gb['root']['more'] ?? null]));
    $alone = $mk($a, 'Gone alone', null, false);
    $mk($a, 'Gone too', $alone, false);
    $ga = peopleOrgBranch($conn, $r, $alone, false);
    check('...and nothing missing under a leaver whose people have all left', ($ga['root']['more'] ?? null) === 0 && $ga['truncated'] === false,
        json_encode([$ga['root']['reports'] ?? null, $ga['root']['more'] ?? null, $ga['truncated'] ?? null]));
    check('CONTROL: ...who is drawn with them when leavers are shown', count(peopleOrgBranch($conn, $r, $alone, true)['root']['children'] ?? []) === 1);

    echo "The gaps\n";
    $g = peopleOrgGaps($conn, $r);
    $noMgr = $ids($g['no_manager']['people']);
    check('a directory person with no manager who manages nobody is a gap', in_array($orphan, $noMgr, true));
    check('somebody typed in by hand with no manager is not', !in_array($byHand, $noMgr, true));
    check('a manager at the top is not', !in_array($boss, $noMgr, true));
    $leftRows = array_column($g['left_with_reports']['people'], 'reports', 'id');
    check('a leaver who still manages somebody is a gap, with how many', ($leftRows[$goneMgr] ?? null) === 1, json_encode($leftRows));
    check('a leaver nobody reports to is not', !isset($leftRows[$goneQuiet]));
    check('the gaps never name somebody out of reach', !str_contains(json_encode($g), 'Their Boss') && !str_contains(json_encode($g), "$tag Other"));

    echo "One person's panel\n";
    $pp = peopleOrgPerson($conn, $r, $leaf1);
    check('it says how to reach somebody the reader can see', ($pp['email'] ?? null) === "$tag-3@example.invalid", json_encode($pp));
    check('somebody out of reach has no panel', peopleOrgPerson($conn, $r, $theirBoss) === null);
    check('CONTROL: ...but does for an analyst who can see them', peopleOrgPerson($conn, $all, $theirBoss) !== null);

    echo "The departments the colours are handed out by\n";
    $conn->prepare("UPDATE users SET department = ? WHERE id = ?")->execute(["$tag Finance", $mid]);
    $conn->prepare("UPDATE users SET department = ? WHERE id = ?")->execute(["$tag Secret", $theirBoss]);
    $depts = peopleOrgDepartments($conn, $r);
    check('a department in the reader\'s companies is listed', in_array("$tag Finance", $depts, true));
    check('...one only in another company is not', !in_array("$tag Secret", $depts, true));
    check('CONTROL: ...and is for an analyst who can see it', in_array("$tag Secret", peopleOrgDepartments($conn, $all), true));
} catch (Throwable $e) {
    check('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = $conn->prepare("SELECT COUNT(*) FROM users WHERE email LIKE ?");
$left->execute(["$tag-%"]);
check('nothing survived the run (rolled back)', (int)$left->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
