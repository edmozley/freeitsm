<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The organisation chart on the self-service portal (GH #414).
 *
 * Pins who may see it - nobody until switched on, then everybody or only members
 * of chosen (active) People groups - and what the People chart's queries show when
 * asked as the portal reader (PeopleOrgReader): the reader's own company, current
 * staff only, never a leaver - not even one with a team - and search by name, title
 * or department, not by a named person's email.
 * No email address comes back - except as the name of somebody who has no name, who is
 * shown by it so the missing name gets noticed and fixed (Ed, 2026-11-20).
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/portal-org-chart.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_org_chart.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nPortal organisation chart\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes people, groups and portal settings.\n");
}
if (!moduleIsEnabled($conn, 'people')) { echo "  SKIP  the People module is switched off on this database\n"; exit(0); }
try { $conn->query("SELECT 1 FROM directory_groups LIMIT 0"); }
catch (PDOException $e) { echo "  SKIP  run Database Verification first: no directory_groups table\n"; exit(0); }

$conn->beginTransaction();
try {
    $set = function (string $mode, array $groups = []) use ($conn): void {
        $st = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, NOW())
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st->execute(['self_service_org_chart', $mode]);
        $st->execute(['self_service_org_chart_groups', implode(',', $groups)]);
        selfServicePortalSettings($conn, true);
    };
    $tag = 'zz414' . bin2hex(random_bytes(3));
    // A second company for the company rule, on a one-company database: inside the
    // transaction, and before anything asks how many companies there are (the
    // answer is cached for the rest of the process).
    if ((int)$conn->query("SELECT COUNT(*) FROM tenants")->fetchColumn() === 1) {
        $conn->prepare("INSERT INTO tenants (name) VALUES (?)")->execute(["$tag company"]);
    }
    $multi = isMultiTenant($conn);
    $default = $multi ? getDefaultTenantId($conn) : null;
    $other = $multi ? (int)$conn->query("SELECT id FROM tenants WHERE id <> " . (int)$default . " ORDER BY id LIMIT 1")->fetchColumn() : 0;
    $person = function (string $name, ?int $manager = null, ?int $tenant = null, int $active = 1, ?string $email = null) use ($conn, $tag): int {
        $conn->prepare("INSERT INTO users (email, display_name, job_title, department, manager_id, tenant_id, is_active)
                        VALUES (?, ?, 'Tester', 'QA', ?, ?, ?)")
             ->execute([$email ?? "$tag-" . bin2hex(random_bytes(4)) . '@example.test', $name, $manager, $tenant, $active]);
        return (int)$conn->lastInsertId();
    };

    $tenant = $multi ? $default : null;
    $boss   = $person("$tag Boss", null, $tenant);
    $me     = $person("$tag Me", $boss, $tenant);
    $peer   = $person("$tag Peer", $boss, $tenant);
    $gone   = $person("$tag Leaver", $boss, $tenant, 0);
    $noName = $person('', $boss, $tenant, 1, "$tag-secret@example.test");
    $away   = $other ? $person("$tag Other company", null, $other) : 0;

    $set('off');
    ok('off: nobody sees it', !portalOrgChartAllowed($conn, $me));

    $set('all');
    ok('everyone: the reader sees it', portalOrgChartAllowed($conn, $me));
    // The analyst chart's own queries, asked as the portal reader (includes/people.php).
    $reader = PeopleOrgReader::portal($conn, $me);
    $team = peopleOrgLevel($conn, $reader, $boss, true)['people'];   // true: asked for leavers, which the portal ignores
    $ids = array_column($team, 'id');
    ok('their colleague is in their manager\'s team', in_array($me, $ids, true) && in_array($peer, $ids, true));
    ok('a leaver is not, even when leavers are asked for', !in_array($gone, $ids, true));
    ok('nor reachable one at a time', !peopleOrgCanSee($conn, $reader, $gone));
    $nameless = array_values(array_filter($team, fn($p) => $p['id'] === $noName))[0] ?? null;
    ok('somebody with no name is shown, by their email, so the gap gets fixed', ($nameless['name'] ?? '') === "$tag-secret@example.test");
    $json = json_encode(array_values(array_filter($team, fn($p) => $p['id'] !== $noName)));
    ok('nobody else\'s email address anywhere in the answer', !str_contains($json, '@example.test'));
    $mine = array_values(array_filter($team, fn($p) => $p['id'] === $me))[0] ?? [];
    ok('no company named on a box (it is always the reader\'s own)', array_key_exists('company', $mine) && $mine['company'] === null);
    $path = peopleOrgPath($conn, $reader, $me, false);
    ok('the line from the top runs down to the reader', ($path['ids'] ?? []) === [$boss, $me], json_encode($path));
    // A leaver who still has somebody under them: the analyst chart shows them (the gap);
    // the portal does not, and the person under them starts at the top instead.
    $leftBoss = $person("$tag Left boss", null, $tenant, 0);
    $under    = $person("$tag Under left boss", $leftBoss, $tenant);
    $person("$tag Under them", $under, $tenant);
    $top = array_column(peopleOrgLevel($conn, $reader, 0, true)['people'], 'id');
    ok('a leaver with a team is not at the top', !in_array($leftBoss, $top, true));
    ok('their team starts at the top instead', in_array($under, $top, true));
    $found = array_column(portalOrgChartSearch($conn, $reader, "$tag Me"), 'id');
    ok('search finds the reader by name', in_array($me, $found, true));
    ok('search does not look in a named person\'s email', portalOrgChartSearch($conn, $reader, "$tag-") === [] || !array_diff(array_column(portalOrgChartSearch($conn, $reader, "$tag-"), 'id'), [$noName]));
    ok('search finds somebody with no name by their email', in_array($noName, array_column(portalOrgChartSearch($conn, $reader, "$tag-secret"), 'id'), true));
    if ($other) {
        ok('nobody from another company', !peopleOrgCanSee($conn, $reader, $away)
            && !in_array($away, array_column(portalOrgChartSearch($conn, $reader, "$tag Other"), 'id'), true)
            && !in_array($away, $top, true));
    } else echo "  SKIP  one company only: the company rule is not exercised
";

    $prov = (int)$conn->query("SELECT id FROM auth_providers ORDER BY id LIMIT 1")->fetchColumn();
    if (!$prov) {
        $conn->exec("INSERT INTO auth_providers (protocol, display_name, issuer_url, client_id, enabled) VALUES ('oidc', '$tag', 'https://login.microsoftonline.com/zz/v2.0', 'zz', 1)");
        $prov = (int)$conn->lastInsertId();
    }
    $group = function (int $active = 1) use ($conn, $prov, $tag): int {
        $conn->prepare("INSERT INTO directory_groups (provider_id, external_id, display_name, is_active, member_count) VALUES (?, ?, ?, ?, 0)")
             ->execute([$prov, "$tag-" . bin2hex(random_bytes(4)), "$tag group", $active]);
        return (int)$conn->lastInsertId();
    };
    $g = $group();
    $set('groups', [$g]);
    ok('groups: somebody outside the group does not see it', !portalOrgChartAllowed($conn, $me));
    $conn->prepare("INSERT INTO directory_group_members (group_id, user_id) VALUES (?, ?)")->execute([$g, $me]);
    ok('groups: a member does', portalOrgChartAllowed($conn, $me));
    ok('groups: their colleague outside it still does not', !portalOrgChartAllowed($conn, $peer));
    $conn->prepare("UPDATE directory_groups SET is_active = 0 WHERE id = ?")->execute([$g]);
    ok('groups: a group no longer imported lets nobody in', !portalOrgChartAllowed($conn, $me));
    $set('groups', []);
    ok('groups with none chosen: nobody', !portalOrgChartAllowed($conn, $me));

    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('self_service_org_chart', 'everybody') ON DUPLICATE KEY UPDATE setting_value = 'everybody'")->execute();
    selfServicePortalSettings($conn, true);
    ok('an unknown stored value counts as off', !portalOrgChartAllowed($conn, $me));
} finally {
    $conn->rollBack();
    selfServicePortalSettings($conn, true);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
