<?php
/* Never over the web. This suite writes synthetic fixtures only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Cluster storage reports (#296): identity, observations and failures are per cluster.
 * Run: FREEITSM_TEST_DATABASE=<disposable database> php tests/virtual-estate-storage.php
 * Run Database Verification first. The collector itself is never executed by this test.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/virtual_estate.php';
require_once __DIR__ . '/fixtures/virtual-estate/synthetic.php';

$testDatabase = getenv('FREEITSM_TEST_DATABASE');
if (!$testDatabase) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to a disposable database.\n";
    exit(0);
}
$conn = connectToDatabase();
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $testDatabase) {
    echo "SKIPPED: FREEITSM_TEST_DATABASE does not match; no fixtures were written.\n";
    exit(0);
}
$pass = 0;
$fail = 0;
$connectionIds = [];
$keyIds = [];
$tenantId = null;
function storageCheck(string $label, bool $condition): void
{
    global $pass, $fail;
    if ($condition) { $pass++; echo "  PASS $label\n"; }
    else { $fail++; echo "  FAIL $label\n"; }
}
$start = time() - 7200;
$at = function (int $n) use ($start): string { return gmdate('Y-m-d\TH:i:s\Z', $start + $n * 60); };
$dbAt = function (int $n) use ($start): string { return gmdate('Y-m-d H:i:s', $start + $n * 60); };
$estate = ['clusters' => ['ZZ-CL-A' => veUuid('zz-cl-a'), 'ZZ-CL-B' => veUuid('zz-cl-b')],
    'hosts' => ['ZZ-HV-A' => [veUuid('zz-host-a'), 'ZZ-CL-A'], 'ZZ-HV-B' => [veUuid('zz-host-b'), 'ZZ-CL-B']],
    'vms' => ['ZZ-VM-A' => [veUuid('zz-vm-a'), 'ZZ-HV-A', true]]];
$volume = function (string $partition, $total = 1000, $free = 400): array {
    return ['resource_id' => veUuid('zz-storage-resource'), 'volume_id' => 'volume:' . $partition,
        'name' => 'ZZ Shared disk', 'path' => 'C:\\ClusterStorage\\ZZ-' . $partition,
        'filesystem' => 'ReFS', 'state' => 'Online', 'coordinator_node' => 'ZZ-HV-A',
        'total_bytes' => $total, 'free_bytes' => $free];
};
$group = function (string $name, array $items, bool $complete = true, ?string $error = null) use ($estate): array {
    return ['cluster_id' => $estate['clusters'][$name], 'complete' => $complete, 'items' => $items, 'error' => $error];
};
$report = function (int $n, array $groups, ?array $scope = null) use ($estate, $at): array {
    $r = veReport($estate, $at($n));
    $r['sections'] = ['storage_volumes' => ['clusters' => $groups]];
    if ($scope !== null) { $r['scope'] = $scope; }
    return $r;
};

try {
    $conn->prepare('INSERT INTO tenants (name, is_active) VALUES (?, 1)')->execute(['ZZCSV company ' . bin2hex(random_bytes(4))]);
    $tenantId = (int)$conn->lastInsertId();
    $create = function (?int $tenant = null) use ($conn, &$connectionIds): int {
        $conn->prepare("INSERT INTO virt_connections (name, source_kind, tenant_id) VALUES (?, 'hyperv', ?)")
            ->execute(['ZZCSV-' . bin2hex(random_bytes(5)), $tenant ?? getDefaultTenantId($conn)]);
        return $connectionIds[] = (int)$conn->lastInsertId();
    };
    $cid = $create();
    VirtualEstateService::submit($conn, $cid, veReport($estate, $at(0)));
    $rows = function (?int $connection = null) use ($conn, $cid): array {
        $st = $conn->prepare('SELECT v.*, c.external_id AS cluster_external_id FROM virt_storage_volumes v
            JOIN virt_clusters c ON c.id = v.cluster_id WHERE v.connection_id = ? ORDER BY c.external_id, v.volume_id');
        $st->execute([$connection ?? $cid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $find = function (string $cluster, string $partition) use ($rows, $estate): array {
        foreach ($rows() as $row) {
            if ($row['cluster_external_id'] === $estate['clusters'][$cluster] && $row['volume_id'] === 'volume:' . $partition) { return $row; }
        }
        throw new RuntimeException('Missing synthetic storage partition.');
    };
    $a = $group('ZZ-CL-A', [$volume('one'), $volume('two', 1000, 0)]);
    $b = $group('ZZ-CL-B', [$volume('one', null, null)]);
    $r = VirtualEstateService::submit($conn, $cid, $report(1, [$a, $b]));
    storageCheck('storage section is accepted', $r['status'] === 200);
    storageCheck('one row per partition and cluster, never per host', count($rows()) === 3);
    $first = $find('ZZ-CL-A', 'one');
    storageCheck('capacity is from the volume, with used = total - free', (int)$first['total_bytes'] === 1000 && (int)$first['used_bytes'] === 600);
    storageCheck('zero free is real, unknown stays null', (int)$find('ZZ-CL-A', 'two')['free_bytes'] === 0
        && $find('ZZ-CL-B', 'one')['total_bytes'] === null && $find('ZZ-CL-B', 'one')['used_bytes'] === null);
    storageCheck('connection company is retained', (int)$first['tenant_id'] === getDefaultTenantId($conn));
    $r = VirtualEstateService::submit($conn, $cid, $report(1, [$a, $b]));
    storageCheck('exact retry is a replay', $r['outcome'] === 'replayed' && count($rows()) === 3);
    $conflict = null;
    try { VirtualEstateService::submit($conn, $cid, $report(1, [$a])); }
    catch (ServiceError $e) { $conflict = $e->errorCode; }
    storageCheck('different content at the same time conflicts', $conflict === 'observation_conflict');

    $a['items'][0]['name'] = 'ZZ renamed disk';
    $a['items'][0]['path'] = 'C:\\ClusterStorage\\ZZ-renamed';
    $a['items'][0]['coordinator_node'] = 'ZZ-HV-B';
    VirtualEstateService::submit($conn, $cid, $report(2, [$a]));
    $renamed = $find('ZZ-CL-A', 'one');
    storageCheck('rename, path and coordinator change keep the same identity', $renamed['id'] === $first['id']
        && $renamed['first_seen_at'] === $first['first_seen_at'] && $renamed['coordinator_node'] === 'ZZ-HV-B');
    storageCheck('omitted cluster remains untouched', $find('ZZ-CL-B', 'one')['last_seen_observed_at'] === $dbAt(1));

    $failedA = $group('ZZ-CL-A', [], false, 'ZZ read refused');
    $b['items'][0]['total_bytes'] = 2000;
    $b['items'][0]['free_bytes'] = 500;
    $mixed = $report(3, [$failedA, $b]);
    $mixed['sections']['vms'] = veReport($estate, $at(3))['sections']['vms'];
    $r = VirtualEstateService::submit($conn, $cid, $mixed);
    $failed = $find('ZZ-CL-A', 'one');
    storageCheck('failed cluster beside successful cluster is partial', $r['status'] === 207 && $r['sections']['storage_volumes']['outcome'] === 'partial');
    storageCheck('failed read preserves facts and actual observation time', $failed['total_bytes'] === $renamed['total_bytes']
        && $failed['last_seen_observed_at'] === $dbAt(2) && $failed['not_confirmed_since'] === $dbAt(3) && $failed['not_seen_since'] === null);
    storageCheck('other cluster still applies', (int)$find('ZZ-CL-B', 'one')['used_bytes'] === 1500 && $find('ZZ-CL-B', 'one')['not_confirmed_since'] === null);
    storageCheck('VMs read successfully are unaffected', $r['sections']['vms']['outcome'] === 'applied'
        && !$conn->query("SELECT not_confirmed_since FROM virt_vms WHERE connection_id = $cid")->fetchColumn());
    $snapshot = $rows();
    VirtualEstateService::submit($conn, $cid, veReport($estate, $at(4)));
    storageCheck('old collector omitting storage changes no storage facts', $rows() === $snapshot);
    $scopeA = [['kind' => 'cluster', 'id' => $estate['clusters']['ZZ-CL-A']]];
    VirtualEstateService::submit($conn, $cid, $report(2, [$a], $scopeA));
    storageCheck('older success in a different scope cannot clear a newer failure', $find('ZZ-CL-A', 'one') === $failed);
    VirtualEstateService::submit($conn, $cid, $report(5, [$a], $scopeA));
    storageCheck('new success confirms previous failed read', $find('ZZ-CL-A', 'one')['not_confirmed_since'] === null);

    $partial = $group('ZZ-CL-A', [$volume('one', 2000, 1000)], false);
    VirtualEstateService::submit($conn, $cid, $report(6, [$partial], $scopeA));
    storageCheck('partial enumeration keeps absent partition, marked unconfirmed', $find('ZZ-CL-A', 'two')['not_seen_since'] === null
        && $find('ZZ-CL-A', 'two')['not_confirmed_since'] === $dbAt(6));
    $r = VirtualEstateService::submit($conn, $cid, $report(7, [$group('ZZ-CL-A', [])], $scopeA));
    storageCheck('successful empty list marks missing without deletion', count($rows()) === 3
        && $find('ZZ-CL-A', 'one')['not_seen_since'] === $dbAt(7) && $find('ZZ-CL-A', 'two')['not_seen_since'] === $dbAt(7));
    storageCheck('complete empty cluster does not mark another cluster missing', $find('ZZ-CL-B', 'one')['not_seen_since'] === null);
    VirtualEstateService::submit($conn, $cid, $report(8, [$a], $scopeA));
    storageCheck('reappearance clears missing without a new row', $find('ZZ-CL-A', 'one')['id'] === $first['id'] && $find('ZZ-CL-A', 'one')['not_seen_since'] === null);

    $invalid = $group('ZZ-CL-A', [$volume('one', -1, 100), $volume('two', '9223372036854775808', 3.5)]);
    VirtualEstateService::submit($conn, $cid, $report(9, [$invalid], $scopeA));
    storageCheck('negative, overflowing and fractional readings are unknown', $find('ZZ-CL-A', 'one')['total_bytes'] === null
        && $find('ZZ-CL-A', 'two')['total_bytes'] === null && $find('ZZ-CL-A', 'two')['free_bytes'] === null);
    VirtualEstateService::submit($conn, $cid, $report(10, [$group('ZZ-CL-A', [$volume('one', 100, 200)])], $scopeA));
    storageCheck('free greater than total never becomes negative used', $find('ZZ-CL-A', 'one')['free_bytes'] === null && $find('ZZ-CL-A', 'one')['used_bytes'] === null);

    $beforeDuplicate = $find('ZZ-CL-A', 'one');
    $badA = $group('ZZ-CL-A', [$volume('one'), $volume('one')]);
    $r = VirtualEstateService::submit($conn, $cid, $report(11, [$badA, $b]));
    storageCheck('duplicate identity fails its cluster while the good cluster applies', $r['status'] === 207
        && $find('ZZ-CL-A', 'one')['last_seen_observed_at'] === $beforeDuplicate['last_seen_observed_at']
        && $find('ZZ-CL-B', 'one')['last_seen_observed_at'] === $dbAt(11));
    $r = VirtualEstateService::submit($conn, $cid, $report(12, [$b], $scopeA));
    storageCheck('cluster outside report scope is refused', $r['status'] === 422 && $find('ZZ-CL-B', 'one')['last_seen_observed_at'] === $dbAt(11));

    $other = $create($tenantId);
    VirtualEstateService::submit($conn, $other, veReport($estate, $at(0)));
    $r = VirtualEstateService::submit($conn, $other, $report(1, [$failedA]));
    $firstFailure = VirtualEstateService::storageInventory($conn, [$other]);
    storageCheck('first failed read is visible without inventing a volume or observation', $r['status'] === 422
        && $firstFailure[0]['last_observed_at'] === null && $firstFailure[0]['read_error'] !== null && $firstFailure[0]['volumes'] === []);
    VirtualEstateService::submit($conn, $other, $report(2, [$a, $b]));
    storageCheck('identical identifiers in another connection stay separate', count($rows($other)) === 3
        && $rows($other)[0]['id'] !== $rows()[0]['id']);
    $foreign = $group('ZZ-CL-A', []);
    $foreign['cluster_id'] = veUuid('zz-unknown-cluster');
    $beforeForeign = $rows();
    $r = VirtualEstateService::submit($conn, $cid, $report(13, [$foreign]));
    storageCheck('unknown cluster never adopts another connection or empties storage', $r['status'] === 422 && $rows() === $beforeForeign);

    $unreadable = $report(14, [], $scopeA);
    $unreadable['unreadable'] = [['kind' => 'cluster', 'id' => $estate['clusters']['ZZ-CL-A'], 'error' => 'ZZ cluster unavailable']];
    $beforeUnavailable = $find('ZZ-CL-A', 'one');
    VirtualEstateService::submit($conn, $cid, $unreadable);
    $inventory = VirtualEstateService::storageInventory($conn, [$cid]);
    $aRead = array_values(array_filter($inventory, function ($c) use ($estate) { return $c['cluster_external_id'] === $estate['clusters']['ZZ-CL-A']; }))[0];
    storageCheck('opted-in collector with unreadable cluster records storage failure', $aRead['last_attempted_at'] === $dbAt(14)
        && $aRead['read_error'] !== null && $find('ZZ-CL-A', 'one')['last_seen_observed_at'] === $beforeUnavailable['last_seen_observed_at']);

    $inventory = VirtualEstateService::storageInventory($conn, [$cid]);
    storageCheck('read model groups volumes by the requested connection and cluster', count($inventory) === 2
        && array_sum(array_map(function ($c) { return count($c['volumes']); }, $inventory)) === 3
        && (int)$inventory[0]['connection_id'] === $cid);
    storageCheck('empty authorized connection list returns no storage', VirtualEstateService::storageInventory($conn, []) === []);
    $base = rtrim(getenv('FREEITSM_TEST_URL') ?: '', '/');
    if ($base === '') { throw new RuntimeException('Set FREEITSM_TEST_URL to test REST authorization and storage reads.'); }
    $analyst = (int)$conn->query('SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $makeKey = function (array $permissions, ?array $companies = null) use ($conn, &$keyIds, $analyst): array {
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['ZZCSV key', substr($key, 0, 12), hash('sha256', $key), $analyst, json_encode($permissions), $companies === null ? null : json_encode($companies)]);
        $id = (int)$conn->lastInsertId();
        $keyIds[] = $id;
        return [$key, $id];
    };
    $request = function (string $key, string $method, int $id, ?array $body = null) use ($base): array {
        $path = '/virtual-estate/' . $id . ($method === 'POST' ? '/reports' : '');
        $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode($path));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        $result = json_decode((string)curl_exec($ch), true);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, $result];
    };
    [$own, $ownId] = $makeKey(['virtual_estate' => ['create', 'read']]);
    [$stranger] = $makeKey(['virtual_estate' => ['create', 'read']]);
    [$writeOnly] = $makeKey(['virtual_estate' => ['create']]);
    [$scoped] = $makeKey(['virtual_estate' => ['read']], [$tenantId]);
    $conn->prepare('UPDATE virt_connections SET api_key_id = ? WHERE id = ?')->execute([$ownId, $cid]);
    $apiB = $group('ZZ-CL-B', [$volume('one', null, null)]);
    [$status] = $request($stranger, 'POST', $cid, $report(15, [$a, $b]));
    storageCheck('REST storage ingest refuses another key', $status === 403);
    [$status] = $request($own, 'POST', $cid, $report(15, [$a, $apiB]));
    storageCheck('REST own key can submit storage', $status === 200);
    [$status, $response] = $request($own, 'GET', $cid);
    $data = $response['data'] ?? [];
    storageCheck('REST returns scoped storage and counts', $status === 200 && ($data['counts']['storage_volumes'] ?? null) === 3
        && count($data['storage'] ?? []) === 2 && ($data['storage'][0]['connection_id'] ?? null) === $cid);
    storageCheck('REST storage observation timestamps are UTC', ($data['storage'][0]['last_attempted_at'] ?? null) === $at(15)
        && ($data['storage'][0]['volumes'][0]['last_seen_observed_at'] ?? null) === $at(15));
    storageCheck('REST preserves zero and null as distinct capacity values', ($data['storage'][0]['volumes'][1]['free_bytes'] ?? null) === 0
        && array_key_exists('total_bytes', $data['storage'][1]['volumes'][0] ?? []) && $data['storage'][1]['volumes'][0]['total_bytes'] === null);
    [$status] = $request($writeOnly, 'GET', $cid);
    storageCheck('REST read needs the read permission', $status === 403);
    [$status] = $request($scoped, 'GET', $cid);
    storageCheck('REST company scope hides the estate and its storage', $status === 404);

    $badSection = $report(16, []);
    $badSection['sections']['storage_volumes'] = 'not an object';
    $badSection['sections']['vms'] = veReport($estate, $at(16))['sections']['vms'];
    $badResult = null;
    try { $badResult = VirtualEstateService::submit($conn, $cid, $badSection); }
    catch (Throwable $e) { /* The assertion below must fail if a section aborts the report. */ }
    storageCheck('malformed storage section cannot roll back the successful VM section', ($badResult['status'] ?? null) === 207
        && ($badResult['sections']['vms']['outcome'] ?? null) === 'applied');
    $conn->prepare("UPDATE virt_clusters SET not_seen_since = '2020-01-01 00:00:00' WHERE connection_id = ?")->execute([$cid]);
    $conn->prepare('UPDATE virt_connections SET retention_days = 1 WHERE id = ?')->execute([$cid]);
    VirtualEstateService::submit($conn, $cid, $report(17, []));
    storageCheck('VM retention does not delete storage or its cluster grouping', count($rows()) === 3
        && count(VirtualEstateService::storageInventory($conn, [$cid])) === 2);
    $withoutId = $volume('one');
    unset($withoutId['volume_id']);
    $r = VirtualEstateService::submit($conn, $cid, $report(18, [$group('ZZ-CL-A', [$withoutId]), $b]));
    storageCheck('missing partition identity fails only that cluster, never falls back to name or path', $r['status'] === 207
        && count($rows()) === 3 && $find('ZZ-CL-A', 'one')['last_seen_observed_at'] === $dbAt(15));
    $beforeStaleFailure = $find('ZZ-CL-B', 'one');
    $scopeB = [['kind' => 'cluster', 'id' => $estate['clusters']['ZZ-CL-B']]];
    VirtualEstateService::submit($conn, $cid, $report(17, [$group('ZZ-CL-B', [], false, 'ZZ older failure')], $scopeB));
    storageCheck('older failure under another scope cannot taint a newer successful read', $find('ZZ-CL-B', 'one') === $beforeStaleFailure);
} catch (Throwable $e) {
    $fail++;
    echo '  FAIL unexpected: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    foreach ($connectionIds as $id) {
        $conn->prepare('DELETE FROM job_run_sources WHERE job_key = ?')->execute(['virt_push_' . $id]);
        $conn->prepare('DELETE FROM job_runs WHERE job_key = ?')->execute(['virt_push_' . $id]);
        $conn->prepare('DELETE FROM virt_connections WHERE id = ?')->execute([$id]);
        $remaining = $conn->prepare('SELECT COUNT(*) FROM virt_storage_volumes WHERE connection_id = ?');
        $remaining->execute([$id]);
        storageCheck('connection deletion cascades to its storage', (int)$remaining->fetchColumn() === 0);
        // Also clean up on an incomplete schema upgrade, so a failing test leaves no fixtures.
        $conn->prepare('DELETE FROM virt_storage_volumes WHERE connection_id = ?')->execute([$id]);
    }
    foreach ($keyIds as $id) {
        $conn->prepare('DELETE FROM api_key_rate_limits WHERE api_key_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM api_key_events WHERE api_key_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM api_request_log WHERE api_key_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$id]);
    }
    if ($tenantId !== null) { $conn->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tenantId]); }
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
