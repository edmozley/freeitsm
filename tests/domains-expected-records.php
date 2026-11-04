<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Expected DNS records on an internal domain (GH #243, #203 slice 3).
 *
 *   php tests/domains-expected-records.php
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/domains-expected-records.php   (part 2 as well)
 *
 * PART 1 - no database: values are compared normalised and sorted (order, case, a
 *          trailing dot and IPv6 spelling are never a change); no values means the
 *          type only has to exist; a missing record and wrong values are problems;
 *          a resolver that could not be asked is "not checked", never a pass. The
 *          checks run through a stub resolver - never real DNS.
 * PART 2 - DomainsService on a test domain: what may be saved, and what is refused.
 *
 * Control: a domain with no expected records produces exactly the findings it did
 * before slice 3.
 *
 * @wiki Domains-Internal-Monitoring-Design
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/domains.php';

$pass = 0; $fail = 0; $skip = 0;
function ok(string $what, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}

echo "PART 1 - the comparison\n";
ok('values are trimmed, lower-cased, de-dotted, de-duplicated and sorted',
   domainRecordValues(DomainRecordType::CNAME, [' App.Example.Test. ']) === ['app.example.test']
   && domainRecordValues(DomainRecordType::A, ['10.0.0.6', '10.0.0.5', '10.0.0.5']) === ['10.0.0.5', '10.0.0.6']);
ok('IPv6 is written one way', domainRecordValues(DomainRecordType::AAAA, ['2001:DB8:0:0:0:0:0:1']) === ['2001:db8::1']);
ok('a value that is not valid for the type is dropped, never compared', domainRecordValues(DomainRecordType::A, ['10.0.0.999', 'host.test']) === []);

$zone = 'zz.example.test';
$dns = [   // what the stub resolver answers: host|type => records, or false for "could not ask"
    "$zone|A" => ['10.0.0.5', '10.0.0.6'], "$zone|AAAA" => [],
    "app.$zone|CNAME" => ['web01.' . $zone], "app.$zone|A" => ['10.0.0.9'], "app.$zone|AAAA" => [],
    "v6.$zone|AAAA" => ['2001:db8:0:0:0:0:0:1'], "v6.$zone|A" => [],
    "$zone|SOA" => ['ns1.' . $zone],
    "gone.$zone|CNAME" => [], "gone.$zone|A" => ['10.0.0.1'], "gone.$zone|AAAA" => [],
    "flaky.$zone|A" => false, "flaky.$zone|AAAA" => false,
];
$resolve = function (string $h, string $t) use ($dns) {
    $r = $dns["$h|$t"] ?? [];
    return $r === false ? ['ok' => false, 'nxdomain' => false, 'records' => [], 'error' => 'DNS query failed'] : ['ok' => true, 'nxdomain' => false, 'records' => $r, 'error' => null];
};
$row = ['domain_name' => $zone, 'ssl_hosts' => ''];
$find = fn(array $res, string $host, string $type) => array_values(array_filter($res['findings'], fn($f) => str_starts_with($f['key'], 'int_expected_') && $f['params']['host'] === $host && $f['params']['type'] === $type))[0] ?? null;

$base = domainRunInternalChecks($row, [], $resolve);
ok('control: no expected records - only the slice 1 findings', !array_filter($base['findings'], fn($f) => str_starts_with($f['key'], 'int_expected_')) && !$base['problem'] && $base['completed']);

$res = domainRunInternalChecks($row, [
    ['host' => $zone, 'record_type' => 'A', 'expected_values' => "10.0.0.6\n10.0.0.5"],
    ['host' => "app.$zone", 'record_type' => 'CNAME', 'expected_values' => null],
    ['host' => "v6.$zone", 'record_type' => 'AAAA', 'expected_values' => '2001:DB8::1'],
    ['host' => $zone, 'record_type' => 'SOA', 'expected_values' => 'NS1.zz.example.test.'],
], $resolve);
ok('the same set in another order matches', ($find($res, $zone, 'A')['key'] ?? '') === 'int_expected_ok');
ok('no values: the record only has to exist', ($find($res, "app.$zone", 'CNAME')['key'] ?? '') === 'int_expected_ok');
ok('IPv6 written another way still matches', ($find($res, "v6.$zone", 'AAAA')['key'] ?? '') === 'int_expected_ok');
ok('an SOA is compared by its primary name server', ($find($res, $zone, 'SOA')['key'] ?? '') === 'int_expected_ok');
ok('all as expected: healthy and complete', !$res['problem'] && $res['completed']);

$res = domainRunInternalChecks($row, [['host' => $zone, 'record_type' => 'A', 'expected_values' => "10.0.0.5\n10.0.0.7"]], $resolve);
$f = $find($res, $zone, 'A');
ok('different values are a problem, saying what was found', ($f['key'] ?? '') === 'int_expected_mismatch' && $f['params']['found'] === '10.0.0.5, 10.0.0.6' && $res['problem']);

$res = domainRunInternalChecks($row, [['host' => "gone.$zone", 'record_type' => 'CNAME', 'expected_values' => null]], $resolve);
ok('a record that is not there is a problem', ($find($res, "gone.$zone", 'CNAME')['key'] ?? '') === 'int_expected_missing' && $res['problem']);

$res = domainRunInternalChecks(['domain_name' => $zone, 'ssl_hosts' => ''], [['host' => "flaky.$zone", 'record_type' => 'A', 'expected_values' => '10.0.0.1']], $resolve);
ok('a resolver that could not be asked is "not checked", never a pass or a problem',
   ($find($res, "flaky.$zone", 'A')['key'] ?? '') === 'int_expected_not_checked' && !$res['problem'] && !$res['completed']);

echo "\nPART 2 - through DomainsService on the test database\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $dbName) {
    $skip++; echo "  SKIP  PART 2 - set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (this one is $dbName)\n";
} elseif (!DomainsService::expectedReady($conn) || !domainScopeReady($conn)) {
    $skip++; echo "  SKIP  PART 2 - run Database Verification on this database first\n";
} else {
    $admin = (int)$conn->query("SELECT a.id FROM analysts a WHERE a.is_active = 1 ORDER BY a.id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui', actorName: 'test');
    $made = [];
    $refused = function (callable $fn): string { try { $fn(); return ''; } catch (ServiceError $e) { return $e->getMessage(); } };
    try {
        $iname = 'zz-ex-' . bin2hex(random_bytes(3)) . '.test';
        $id = DomainsService::createDomain($conn, $ctx, ['domain_name' => $iname, 'scope' => 'internal'], null);
        $made[] = $id;
        $pub = DomainsService::createDomain($conn, $ctx, ['domain_name' => 'zz-pub-' . bin2hex(random_bytes(3)) . '.com', 'scope' => 'public', 'purpose' => 'parked'], null);
        $made[] = $pub;

        $x = DomainsService::saveExpectedRecord($conn, $ctx, ['domain_id' => $id, 'host' => "App.$iname", 'record_type' => 'a', 'expected_values' => "10.0.0.6\n 10.0.0.5 \n10.0.0.5"]);
        ok('a valid record is saved, its values normalised (control)', $x['host'] === "app.$iname" && $x['record_type'] === 'A' && $x['expected_values'] === "10.0.0.5\n10.0.0.6");
        $y = DomainsService::saveExpectedRecord($conn, $ctx, ['domain_id' => $id, 'host' => $iname, 'record_type' => 'NS', 'expected_values' => '']);
        ok('no values is stored as "the type only has to exist" (control)', $y['expected_values'] === null);
        ok('it is in the domain\'s history', (int)$conn->query("SELECT COUNT(*) FROM domain_audit WHERE domain_id = $id AND field_name = 'expected_record'")->fetchColumn() === 2);

        ok('a name outside the domain is refused', $refused(fn() => DomainsService::saveExpectedRecord($conn, $ctx, ['domain_id' => $id, 'host' => 'example.org', 'record_type' => 'A'])) !== '');
        ok('a value that is not valid for the type is refused, naming it', str_contains($refused(fn() => DomainsService::saveExpectedRecord($conn, $ctx, ['domain_id' => $id, 'host' => $iname, 'record_type' => 'A', 'expected_values' => "10.0.0.1\n10.0.0.999"])), '10.0.0.999'));
        ok('an unknown record type is refused', $refused(fn() => DomainsService::saveExpectedRecord($conn, $ctx, ['domain_id' => $id, 'host' => $iname, 'record_type' => 'TXT'])) !== '');
        ok('two CNAMEs are refused', $refused(fn() => DomainsService::saveExpectedRecord($conn, $ctx, ['domain_id' => $id, 'host' => "w.$iname", 'record_type' => 'CNAME', 'expected_values' => "a.$iname\nb.$iname"])) !== '');
        ok('the same name and type twice is refused', $refused(fn() => DomainsService::saveExpectedRecord($conn, $ctx, ['domain_id' => $id, 'host' => "app.$iname", 'record_type' => 'A'])) !== '');
        ok('a public domain is refused', $refused(fn() => DomainsService::saveExpectedRecord($conn, $ctx, ['domain_id' => $pub, 'host' => 'x', 'record_type' => 'A'])) !== '');

        DomainsService::deleteExpectedRecord($conn, $ctx, (int)$y['id']);
        ok('a record can be removed', count(DomainsService::expectedRecords($conn, $id)) === 1);

        // Deleting the domain takes its expected records with it - there is no foreign key
        // to cascade, so deleteDomain() must remove them itself.
        DomainsService::deleteDomain($conn, $ctx, $id);
        $made = array_values(array_diff($made, [$id]));
        ok('deleting the domain deletes its expected records', (int)$conn->query("SELECT COUNT(*) FROM domain_expected_records WHERE domain_id = $id")->fetchColumn() === 0);
    } finally {
        foreach ($made as $m) {
            $conn->prepare("DELETE FROM domain_expected_records WHERE domain_id = ?")->execute([$m]);
            DomainsService::deleteDomain($conn, $ctx, $m);
        }
    }
}

echo "\n$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail ? 1 : 0);
