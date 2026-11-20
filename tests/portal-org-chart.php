<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The organisation chart on the self-service portal (GH #414).
 *
 * Pins who may see it - nobody until switched on, then everybody or only members
 * of chosen (active) People groups - and what it shows: the reader's own company,
 * current staff, names / job titles / departments / manager ids and nothing else.
 * No email address may come back, not even as the name of somebody who has no name.
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
    $chart = portalOrgChartPeople($conn, $me);
    $ids = array_column($chart['people'], 'id');
    ok('their manager and colleague are on it', in_array($boss, $ids, true) && in_array($peer, $ids, true));
    ok('a leaver is not', !in_array($gone, $ids, true));
    ok('somebody with no name is not (their email would be the name)', !in_array($noName, $ids, true));
    $json = json_encode($chart);
    ok('no email address anywhere in the answer', !str_contains($json, '@example.test'));
    $mine = array_values(array_filter($chart['people'], fn($p) => $p['id'] === $me))[0] ?? [];
    ok('each person is name, job title, department and manager only', array_keys($mine) === ['id', 'name', 'job_title', 'department', 'manager_id'], json_encode(array_keys($mine)));
    ok('the reader\'s manager id is there', ($mine['manager_id'] ?? null) === $boss);
    if ($other) ok('nobody from another company', !in_array($away, $ids, true));
    else echo "  SKIP  one company only: the company rule is not exercised\n";

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
