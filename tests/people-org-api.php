<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * People - the organisation chart's API, over HTTP: what api/people/org.php
 * makes of what it is sent. tests/people-org.php covers the functions behind
 * it; this covers the request around them - the levels as they arrive in a URL
 * (padded, in hex, as a list, past the limit), the id, the leavers switch, an
 * unknown action, and who may ask at all.
 *
 *   FREEITSM_TEST_URL=https://freeitsm.example.lan \
 *   FREEITSM_TEST_DATABASE=<the database that installation and config.php use> \
 *   php tests/people-org-api.php
 *
 * FREEITSM_TEST_INSECURE_TLS=1 skips checking the certificate, for a
 * development installation on a certificate of its own.
 *
 * The server must read the database this test writes to, so the rows cannot
 * sit in a transaction: it makes two companies, two analysts and a small
 * organisation, all named with one random tag, and deletes every one of them
 * in finally. It checks first that the server sees them, and stops if not.
 *
 * @wiki Organisation-Chart-Developer-Guide
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/people.php';
require_once __DIR__ . '/support/ticket-workspace-fixture.php';   // ticketWorkspaceHttp(): one request, status and JSON

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$url = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if ($url === '' || !$expected || $expected !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_URL to the installation, and FREEITSM_TEST_DATABASE to the throwaway database it and config.php use (config.php's is {$dbName}).\n";
    exit(0);
}

$tag = 'zz-orgapi-' . bin2hex(random_bytes(3));
$password = bin2hex(random_bytes(20));
$made = ['users' => [], 'analysts' => [], 'tenants' => []];

/** A cURL handle for the installation: certificate checked unless told otherwise. */
function orgApiCurl()
{
    $curl = curl_init();
    if (getenv('FREEITSM_TEST_INSECURE_TLS') === '1') {
        curl_setopt_array($curl, [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
    }
    return $curl;
}

try {
    $co = function (string $name) use ($conn, &$made): int {
        $conn->prepare("INSERT INTO tenants (name, is_active, is_default) VALUES (?, 1, 0)")->execute([$name]);
        return $made['tenants'][] = (int)$conn->lastInsertId();
    };
    $a = $co("$tag A");
    $b = $co("$tag B");

    // An analyst in company A, with People or without it.
    $analyst = function (string $suffix, string $module) use ($conn, $tag, $password, $a, &$made): array {
        $username = "$tag-$suffix";
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants,
                                              can_access_all_modules, password_changed_datetime)
                        VALUES (?, ?, ?, ?, 1, 0, 0, 0, UTC_TIMESTAMP())")
             ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $username, "$username@example.invalid"]);
        $id = $made['analysts'][] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$id, $a]);
        $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key, access_level) VALUES (?, ?, 'full')")->execute([$id, $module]);
        $conn->prepare("INSERT INTO user_preferences (analyst_id, preference_key, preference_value) VALUES (?, 'interface_language', 'en')")->execute([$id]);
        return ['id' => $id, 'username' => $username];
    };
    $reader = $analyst('reader', 'people');
    $outsider = $analyst('outsider', 'tickets');

    $n = 0;
    $mk = function (int $tenant, string $name, ?int $manager = null, bool $active = true) use ($conn, $tag, &$n, &$made): int {
        $n++;
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, manager_id, is_active) VALUES (?, ?, ?, ?, ?)")
             ->execute(["$tag-$n@example.invalid", "$tag $name", $tenant, $manager, $active ? 1 : 0]);
        return $made['users'][] = (int)$conn->lastInsertId();
    };
    // A line of five, Top and four below, a leaver beside it, and somebody in the company the reader cannot see.
    $top = $mk($a, 'Top');
    $line = [$top];
    for ($i = 1; $i <= 4; $i++) $line[] = $mk($a, "Line $i", $line[$i - 1]);
    $mk($a, 'Leaver', $top, false);
    $mk($b, 'Hidden');

    // Signs in as somebody, and asks the API; each answer carries status, body and json.
    $login = function (array $who) use ($url, $password): array {
        $curl = orgApiCurl();
        $r = ticketWorkspaceHttp($curl, $url . '/auth/login.php', ['username' => $who['username'], 'password' => $password]);
        if ($r['status'] !== 302) throw new RuntimeException('Could not sign in as the test analyst (HTTP ' . $r['status'] . ').');
        return [$curl];
    };
    $ask = function ($curl, string $query) use ($url): array {
        return ticketWorkspaceHttp($curl, $url . '/api/people/org.php?' . $query);
    };

    echo "Who may ask\n";
    $anon = $ask(orgApiCurl(), 'action=branch&id=' . $top);
    check('nobody signed in gets nothing', ($anon['json']['success'] ?? null) === false && ($anon['json']['error'] ?? '') === 'Not authenticated'
        && !str_contains($anon['body'], "$tag Top"), substr($anon['body'], 0, 120));
    [$out] = $login($outsider);
    $denied = $ask($out, 'action=branch&id=' . $top);
    check('an analyst without People is refused', $denied['status'] === 403 && ($denied['json']['success'] ?? null) === false
        && !str_contains($denied['body'], "$tag Top"), $denied['status'] . ' ' . substr($denied['body'], 0, 120));

    [$curl] = $login($reader);
    $first = $ask($curl, 'action=branch&id=' . $top);
    if (($first['json']['branch']['root']['name'] ?? '') !== "$tag Top") {
        throw new RuntimeException('The server does not see this test\'s rows: it is not on ' . $dbName . '. ' . substr($first['body'], 0, 160));
    }
    check('the server reads the database this test writes to, and answers JSON (setup)', is_array($first['json']));

    echo "Levels, as they arrive in the URL\n";
    $max = PEOPLE_ORG_CHART_LEVELS_MAX;
    $def = PEOPLE_ORG_CHART_LEVELS;
    $cases = [
        ['no levels',                  '',                               $def, false],
        ['levels=1',                   'levels=1',                       1,    false],
        ['levels=2',                   'levels=2',                       2,    false],
        ['levels=0, no limit',         'levels=0',                       $max, true],
        ['levels=all',                 'levels=all',                     $max, true],
        ["levels=$max, the limit",     "levels=$max",                    $max, true],
        ['levels=' . ($max + 1),       'levels=' . ($max + 1),           $max, true],
        ['a number past any integer',  'levels=99999999999999999999',    $max, true],
        ['levels=-1',                  'levels=-1',                      $def, false],
        ['levels=abc',                 'levels=abc',                     $def, false],
        ['levels=2.5',                 'levels=2.5',                     $def, false],
        ['levels=1e3',                 'levels=1e3',                     $def, false],
        ['levels=0x10',                'levels=0x10',                    $def, false],
        ['levels with spaces round 7', 'levels=%207%20',                 7,    false],
        ['levels=007',                 'levels=007',                     7,    false],
        ['an empty levels',            'levels=',                        $def, false],
        ['a NUL byte',                 'levels=%00',                     $def, false],
        ['levels sent as a list',      'levels%5B%5D=5',                 $def, false],
        ['levels sent as a keyed list', 'levels%5Ba%5D=5',               $def, false],
    ];
    foreach ($cases as [$label, $q, $want, $all]) {
        $r = $ask($curl, 'action=branch&id=' . $top . ($q !== '' ? '&' . $q : ''));
        $j = $r['json'];
        check("$label draws " . ($all ? "every level ($want)" : $want),
            $r['status'] === 200 && is_array($j) && ($j['success'] ?? null) === true && ($j['levels'] ?? null) === $want && ($j['all'] ?? null) === $all,
            $r['status'] . ' ' . substr($r['body'], -80));
    }

    echo "The levels shape the drawing\n";
    $nodeOf = function ($node, int $id) use (&$nodeOf) {
        if (($node['id'] ?? 0) === $id) return $node;
        foreach ($node['children'] ?? [] as $c) if ($f = $nodeOf($c, $id)) return $f;
        return null;
    };
    $two = $ask($curl, 'action=branch&levels=2&id=' . $top)['json']['branch'] ?? [];
    $end = $nodeOf($two['root'] ?? [], $line[2]);
    check('levels=2 stops two below the person, and says there is more', $end && $end['children'] === [] && $end['more'] === 1 && ($two['truncated'] ?? null) === true,
        json_encode($end));
    $every = $ask($curl, 'action=branch&levels=0&id=' . $top)['json']['branch'] ?? [];
    check('levels=0 draws the line to its end, nothing left out', $nodeOf($every['root'] ?? [], $line[4]) !== null && ($every['truncated'] ?? null) === false);
    $ctx = $ask($curl, 'action=context&levels=1&id=' . $line[2])['json'] ?? [];
    $mid = $nodeOf($ctx['branch']['root'] ?? [], $line[2]);
    check('context takes the levels too: one below them, from the top down',
        ($ctx['levels'] ?? null) === 1 && ($ctx['branch']['root']['id'] ?? 0) === $top && $mid
        && count($mid['children']) === 1 && $mid['children'][0]['children'] === [] && $mid['children'][0]['more'] === 1, json_encode($mid));
    $ctxAll = $ask($curl, 'action=context&levels=all&id=' . $line[2])['json'] ?? [];
    check('...and all of them', ($ctxAll['all'] ?? null) === true && $nodeOf($ctxAll['branch']['root'] ?? [], $line[4]) !== null);

    echo "The id\n";
    $whole = $ask($curl, 'action=branch&id=abc');
    check('an id that is not a number is 0, the whole organisation', ($whole['json']['branch']['root']['id'] ?? null) === 0
        && $nodeOf($whole['json']['branch']['root'], $top) !== null);
    check('...which never names somebody in a company the reader cannot see', !str_contains($whole['body'], "$tag Hidden"));
    $list = $ask($curl, 'action=branch&id%5B%5D=' . $top);
    check('an id sent as a list is 0 too, not whoever (int) makes of it', ($list['json']['branch']['root']['id'] ?? null) === 0, substr($list['body'], 0, 120));
    $hiddenId = end($made['users']);
    foreach (['a person in another company' => $hiddenId, 'a negative id' => -5, 'an id nobody has' => 2000000000] as $label => $id) {
        $r = $ask($curl, 'action=branch&id=' . $id);
        check("$label is not found, and says no more", ($r['json']['success'] ?? null) === false && ($r['json']['error'] ?? '') === 'Person not found.'
            && !str_contains($r['body'], $tag), substr($r['body'], 0, 120));
    }
    $r = $ask($curl, 'action=context&id=' . $hiddenId);
    check('...nor is their place in the organisation', ($r['json']['success'] ?? null) === false && !str_contains($r['body'], $tag));

    echo "Leavers\n";
    $has = function (string $q) use ($ask, $curl, $tag): bool { return str_contains($ask($curl, $q)['body'], "$tag Leaver"); };
    check('a leaver is left out by default', !$has('action=branch&id=' . $top));
    check('...shown with leavers=1', $has('action=branch&leavers=1&id=' . $top));
    foreach (['true', 'yes', '01', '1%20'] as $v) {
        check("...and not with leavers=$v: only 1 asks for them", !$has('action=branch&leavers=' . $v . '&id=' . $top));
    }

    echo "Actions\n";
    foreach (['an unknown action' => 'action=drop', 'no action' => 'id=' . $top, 'an action as a list' => 'action%5B%5D=branch'] as $label => $q) {
        $r = $ask($curl, $q);
        check("$label is refused", ($r['json']['success'] ?? null) === false && ($r['json']['error'] ?? '') === 'Unknown action.' && !str_contains($r['body'], $tag),
            substr($r['body'], 0, 120));
    }
} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] " . $e->getMessage() . "\n";
} finally {
    // Every row this run made, people under their managers first.
    $conn->prepare('UPDATE users SET manager_id = NULL WHERE email LIKE ?')->execute(["$tag-%"]);
    foreach (array_reverse($made['users']) as $id) $conn->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    foreach ($made['analysts'] as $id) {
        foreach (['analyst_modules', 'analyst_tenant_access', 'user_preferences', 'system_logs'] as $t) {
            $conn->prepare("DELETE FROM $t WHERE analyst_id = ?")->execute([$id]);
        }
        $conn->prepare('DELETE FROM analysts WHERE id = ?')->execute([$id]);
    }
    foreach ($made['tenants'] as $id) $conn->prepare('DELETE FROM tenants WHERE id = ?')->execute([$id]);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM users WHERE email LIKE " . $conn->quote("$tag-%") . ")
                                    + (SELECT COUNT(*) FROM analysts WHERE username LIKE " . $conn->quote("$tag-%") . ")
                                    + (SELECT COUNT(*) FROM tenants WHERE name LIKE " . $conn->quote("$tag %") . ")")->fetchColumn();
    check('nothing this run made is left behind', $left === 0, "$left rows");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
