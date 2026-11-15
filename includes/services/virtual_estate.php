<?php
/**
 * The virtual estate (GH #199 Part A): hosts, Failover Clusters, cluster roles and VMs that
 * a source REPORTS. Hyper-V is the first source, pushed by scripts/Invoke-HyperVInventory.ps1
 * to POST /api/v1/virtual-estate/{connection}/reports. Named for the estate, not Hyper-V, so
 * vCenter, Proxmox and Cloud Director can move onto it later and the CMDB mapping (Part B)
 * and redundancy-aware impact (Part C) are built once.
 *
 * The rules are the inventory ingest's (wiki REST-API-Assets), applied to a whole estate:
 *   - observed_at per report. A report older than the newest applied one for the same SCOPE is
 *     stale; the same scope, time and content as one already applied is a replay (safe to
 *     resend for ever); the same scope and time with different content is a conflict. Each
 *     object also keeps the time it was last observed, so an older report sent under another
 *     scope can never wind an object back.
 *   - sections (clusters, hosts, cluster_roles, vms, vm_disks, vm_networks, storage_volumes), each complete,
 *     partial or left out. Each is applied inside its own savepoint: one that fails is rolled
 *     back alone, named, and the others apply (the adapter answers 207).
 *   - REMOVAL IS NOT DELETION. An object missing from a complete section whose scope covers
 *     it is marked not_seen_since. One on a host or cluster the report names as unreadable is
 *     left as it was and marked not_confirmed_since. Only a person, or a retention setting
 *     that is off by default, removes anything.
 *   - IDENTITY. A host is its hardware UUID; one without a usable UUID falls back to
 *     name:<host> and says so. A VM is its Get-VM Id. An object that comes back with a new
 *     identity while one of the same name stops being seen is raised for review - FreeITSM
 *     never decides it is the same machine, and never merges by name. decideReview() does
 *     what the person chose, and refuses if either object changed since the case was raised.
 *   - ONE COMPANY PER CONNECTION. Every object carries the connection's tenant_id.
 *
 * References inside a report are by NAME for hosts (a VM's host, a role's owner, possible
 * owners) - names are unique within one report, and resolving them per report is what keeps
 * a host rename from breaking the VMs on it.
 *
 * TRAP: Part B binds CIs to these rows by id (cmdb_object_sources). decideReview() therefore
 *   keeps the OLD row (and its id) and gives it the new identity - never the other way round -
 *   and the retention purge skips a bound object.
 *
 * @wiki Hyper-V-and-Cluster-Discovery-Design
 * @wiki Hyper-V-Discovery-Developer-Guide
 */
require_once dirname(__DIR__) . '/service_context.php';
require_once dirname(__DIR__) . '/tenancy.php';
require_once dirname(__DIR__) . '/job_runs.php';

final class VirtualEstateService
{
    public const SCHEMA_VERSION = 1;

    /** The sections, in the order they are applied: what is referenced comes first. */
    public const SECTIONS = ['clusters', 'hosts', 'cluster_roles', 'vms', 'vm_disks', 'vm_networks', 'storage_volumes'];

    /** The per-connection job key on System -> Background jobs. */
    public static function jobKey(int $connectionId): string
    {
        return 'virt_push_' . $connectionId;
    }

    // ======================================================================
    //  Ingest
    // ======================================================================

    /**
     * Apply one report from a connection.
     *
     * @return array{outcome:string, status:int, sections:array<string,array>, unreadable:int, review_raised:int}
     *         outcome: applied | partial | failed | stale | replayed; status: the HTTP status the adapter answers
     */
    public static function submit(PDO $conn, int $connectionId, array $report, ?int $apiKeyId = null): array
    {
        $c = self::connection($conn, $connectionId);
        if (!(int)$c['is_active']) {
            throw new ServiceError('validation', 'connection_inactive', 'This connection is switched off.');
        }
        [$observed, $scope, $sections, $unreadable, $collector] = self::envelope($report);
        $scopeKey = self::scopeKey($scope);
        $fingerprint = hash('sha256', json_encode([$scope, $sections, $unreadable]));

        // Stale, replayed or conflicting - judged against the newest report for the same scope.
        $prev = $conn->prepare('SELECT observed_at, fingerprint, outcome FROM virt_reports
            WHERE connection_id = ? AND scope_key = ? AND outcome IN (\'applied\', \'partial\')
            ORDER BY observed_at DESC, id DESC LIMIT 1');
        $prev->execute([$connectionId, $scopeKey]);
        $last = $prev->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($last && $last['observed_at'] > $observed) {
            return self::record($conn, $c, $scopeKey, $observed, $fingerprint, 'stale', [], $unreadable, $collector, $apiKeyId, 200);
        }
        if ($last && $last['observed_at'] === $observed) {
            if ($last['fingerprint'] !== $fingerprint) {
                self::record($conn, $c, $scopeKey, $observed, $fingerprint, 'conflict', [], $unreadable, $collector, $apiKeyId, 409);
                throw new ServiceError('conflict', 'observation_conflict',
                    'A different report for the same scope and observed_at was already applied. Send a new observed_at.');
            }
            if ($last['outcome'] === 'applied') {
                return self::record($conn, $c, $scopeKey, $observed, $fingerprint, 'replayed', [], $unreadable, $collector, $apiKeyId, 200);
            }
            // A partial one is applied again: every write below is idempotent, and that is how
            // resending the same report retries the sections that failed.
        }

        $run = JobRun::start($conn, self::jobKey($connectionId), 'push');
        $ctx = ['conn' => $c, 'tenant' => $c['tenant_id'] === null ? null : (int)$c['tenant_id'], 'observed' => $observed,
            'scope' => $scope, 'unreadable' => $unreadable, 'touched' => [], 'hosts_by_name' => []];
        $results = [];
        $raised = 0;
        $conn->beginTransaction();
        try {
            // Hosts are referenced by name; start from what is known, the hosts section refreshes it.
            $ctx['hosts_by_name'] = self::knownHostsByName($conn, $connectionId);
            foreach (self::SECTIONS as $name) {
                if (!array_key_exists($name, $sections)) { continue; }
                $conn->exec('SAVEPOINT virt_section');
                try {
                    if (!is_array($sections[$name])) {
                        throw new ServiceError('validation', 'invalid_section', "$name must be an object.");
                    }
                    $apply = 'apply_' . $name;
                    $results[$name] = self::$apply($conn, $ctx, $sections[$name]) + ['outcome' => 'applied'];
                    $conn->exec('RELEASE SAVEPOINT virt_section');
                } catch (ServiceError $e) {
                    $conn->exec('ROLLBACK TO SAVEPOINT virt_section');
                    $results[$name] = ['outcome' => 'failed', 'error' => $e->errorCode, 'message' => $e->getMessage()];
                }
            }
            self::markUnconfirmed($conn, $ctx);
            $raised = self::raiseIdentityReviews($conn, $ctx);
            self::purge($conn, $c);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            JobRun::fail($conn, $run, $e);
            throw $e;
        }

        $failed = array_keys(array_filter($results, fn($r) => $r['outcome'] === 'failed'));
        $applied = count($results) - count($failed);
        $partial = array_filter($results, fn($r) => $r['outcome'] === 'partial');
        $outcome = !$failed && !$partial ? 'applied' : ($applied ? 'partial' : 'failed');
        $summary = self::summary($results, $unreadable);
        JobRun::finish($conn, $run, $outcome === 'applied' && !$unreadable ? 'ok' : ($outcome === 'failed' ? 'failed' : 'partial'),
            array_map(fn($r) => ($r['added'] ?? 0) + ($r['updated'] ?? 0), $results), $summary);
        $out = self::record($conn, $c, $scopeKey, $observed, $fingerprint, $outcome, $results, $unreadable, $collector, $apiKeyId,
            $outcome === 'applied' ? 200 : ($outcome === 'partial' ? 207 : 422));
        $out['review_raised'] = $raised;
        // Part B: keep the CMDB in step, if this connection's mapping is switched on. Its own
        // transaction, after the report's: a CMDB problem never loses the report.
        try {
            require_once __DIR__ . '/virtual_estate_cmdb.php';
            $out['cmdb'] = VirtualEstateCmdbService::sync($conn, $connectionId);
        } catch (Throwable $e) {
            error_log('virtual estate CMDB sync, connection ' . $connectionId . ': ' . $e->getMessage());
            $out['cmdb'] = ['error' => 'The CMDB could not be updated from this report; see the server log.'];
        }
        return $out;
    }

    // ---- the sections ------------------------------------------------------

    private static function apply_clusters(PDO $conn, array &$ctx, array $section): array
    {
        $items = self::items($section, 'clusters', function (array $i) {
            return ['external_id' => self::id($i, 'id', 'clusters'), 'name' => self::str($i, 'name', 255),
                'quorum_type' => self::str($i, 'quorum_type', 100), 'witness' => self::str($i, 'witness', 255)];
        });
        $stats = self::upsert($conn, $ctx, 'virt_clusters', $items, ['name', 'quorum_type', 'witness']);
        if (!empty($section['complete'])) {
            $stats['not_seen'] = self::markNotSeen($conn, $ctx, 'virt_clusters', array_column($items, 'external_id'),
                self::scopeClusterIds($conn, $ctx), 'id');
        }
        return $stats;
    }

    private static function apply_hosts(PDO $conn, array &$ctx, array $section): array
    {
        $raw = self::items($section, 'hosts', function (array $i) {
            return ['uuid' => self::str($i, 'id', 100), 'name' => self::str($i, 'name', 255, true),
                'cluster' => self::str($i, 'cluster_id', 100), 'node_state' => self::str($i, 'node_state', 30),
                'os_version' => self::str($i, 'os_version', 100), 'cpu_count' => self::int($i, 'cpu_count'),
                'memory_bytes' => self::int($i, 'memory_bytes')];
        });
        // A usable hardware UUID is the identity; a missing, all-zero, all-F or repeated one is not.
        $count = array_count_values(array_filter(array_map(fn($h) => self::uuid($h['uuid']), $raw)));
        $clusterIds = self::idMap($conn, $ctx, 'virt_clusters');
        $items = [];
        foreach ($raw as $h) {
            $u = self::uuid($h['uuid']);
            $byUuid = $u !== null && ($count[$u] ?? 0) === 1;
            $items[] = ['external_id' => $byUuid ? $u : 'name:' . mb_strtolower($h['name']), 'identity_kind' => $byUuid ? 'source' : 'name',
                'name' => $h['name'], 'cluster_id' => $h['cluster'] !== null ? ($clusterIds[strtolower($h['cluster'])] ?? null) : null,
                'node_state' => $h['node_state'], 'os_version' => $h['os_version'], 'cpu_count' => $h['cpu_count'], 'memory_bytes' => $h['memory_bytes']];
        }
        $stats = self::upsert($conn, $ctx, 'virt_hosts', $items, ['identity_kind', 'name', 'cluster_id', 'node_state', 'os_version', 'cpu_count', 'memory_bytes']);
        $ctx['hosts_by_name'] = self::knownHostsByName($conn, (int)$ctx['conn']['id']);
        if (!empty($section['complete'])) {
            $stats['not_seen'] = self::markNotSeen($conn, $ctx, 'virt_hosts', array_column($items, 'external_id'), self::scopeHostIds($conn, $ctx), 'id');
        }
        return $stats;
    }

    private static function apply_cluster_roles(PDO $conn, array &$ctx, array $section): array
    {
        $clusterIds = self::idMap($conn, $ctx, 'virt_clusters');
        $items = self::items($section, 'cluster_roles', function (array $i) use ($ctx, $clusterIds) {
            $owners = array_values(array_filter(array_map(fn($n) => $ctx['hosts_by_name'][mb_strtolower((string)$n)] ?? null, (array)($i['preferred_owners'] ?? []))));
            return ['external_id' => self::id($i, 'id', 'cluster_roles'), 'name' => self::str($i, 'name', 255),
                'cluster_id' => ($cl = self::str($i, 'cluster_id', 100)) !== null ? ($clusterIds[strtolower($cl)] ?? null) : null,
                'role_type' => self::str($i, 'role_type', 60), 'state' => self::str($i, 'state', 30),
                'owner_host_id' => ($o = self::str($i, 'owner_host', 255)) !== null ? ($ctx['hosts_by_name'][mb_strtolower($o)] ?? null) : null,
                'preferred_owners_json' => $owners ? json_encode($owners) : null];
        });
        $stats = self::upsert($conn, $ctx, 'virt_cluster_roles', $items, ['name', 'cluster_id', 'role_type', 'state', 'owner_host_id', 'preferred_owners_json']);
        if (!empty($section['complete'])) {
            $stats['not_seen'] = self::markNotSeen($conn, $ctx, 'virt_cluster_roles', array_column($items, 'external_id'),
                self::scopeClusterIds($conn, $ctx), 'cluster_id');
        }
        return $stats;
    }

    private static function apply_vms(PDO $conn, array &$ctx, array $section): array
    {
        $roleIds = self::idMap($conn, $ctx, 'virt_cluster_roles');
        $hostCluster = [];
        foreach ($conn->query('SELECT id, cluster_id FROM virt_hosts WHERE connection_id = ' . (int)$ctx['conn']['id'])->fetchAll(PDO::FETCH_KEY_PAIR) as $h => $cl) {
            $hostCluster[(int)$h] = $cl === null ? null : (int)$cl;
        }
        $items = self::items($section, 'vms', function (array $i) use ($ctx, $roleIds, $hostCluster) {
            $hostName = self::str($i, 'host', 255);
            $hostId = $hostName !== null ? ($ctx['hosts_by_name'][mb_strtolower($hostName)] ?? null) : null;
            $owners = array_values(array_filter(array_map(fn($n) => $ctx['hosts_by_name'][mb_strtolower((string)$n)] ?? null, (array)($i['possible_owners'] ?? []))));
            $addresses = array_slice(array_map('strval', (array)($i['guest_addresses'] ?? [])), 0, 30);
            return ['external_id' => self::id($i, 'id', 'vms'), 'name' => self::str($i, 'name', 255, true),
                'host_id' => $hostId, 'cluster_id' => $hostId !== null ? ($hostCluster[$hostId] ?? null) : null,
                'state' => self::str($i, 'state', 30),
                'is_clustered' => array_key_exists('is_clustered', $i) && $i['is_clustered'] !== null ? ($i['is_clustered'] ? 1 : 0) : null,
                'cluster_role_id' => ($r = self::str($i, 'cluster_role_id', 100)) !== null ? ($roleIds[strtolower($r)] ?? null) : null,
                'possible_owners_json' => $owners ? json_encode($owners) : null,
                'generation' => self::int($i, 'generation'), 'cpu_count' => self::int($i, 'cpu_count'), 'memory_bytes' => self::int($i, 'memory_bytes'),
                'guest_addresses' => $addresses ? mb_substr(implode(', ', $addresses), 0, 1000) : null, 'os_name' => self::str($i, 'os_name', 255)];
        });
        $cols = ['name', 'host_id', 'cluster_id', 'state', 'is_clustered', 'cluster_role_id', 'possible_owners_json', 'generation',
            'cpu_count', 'memory_bytes', 'guest_addresses', 'os_name'];
        $stats = self::upsert($conn, $ctx, 'virt_vms', $items, $cols, function (int $id, ?array $before, array $after) use ($conn, $ctx) {
            self::place($conn, $ctx, $id, $before, $after);
        });
        if (!empty($section['complete'])) {
            $stats['not_seen'] = self::markNotSeen($conn, $ctx, 'virt_vms', array_column($items, 'external_id'), self::scopeHostIds($conn, $ctx), 'host_id');
        }
        return $stats;
    }

    private static function apply_vm_disks(PDO $conn, array &$ctx, array $section): array
    {
        return self::vmChildren($conn, $ctx, $section, 'vm_disks', 'virt_vm_disks', function (array $i) {
            return ['external_id' => self::id($i, 'id', 'vm_disks', 191), 'path' => self::str($i, 'path', 500),
                'size_bytes' => self::int($i, 'size_bytes'), 'used_bytes' => self::int($i, 'used_bytes'), 'controller' => self::str($i, 'controller', 60)];
        });
    }

    private static function apply_vm_networks(PDO $conn, array &$ctx, array $section): array
    {
        return self::vmChildren($conn, $ctx, $section, 'vm_networks', 'virt_vm_networks', function (array $i) {
            $ips = array_slice(array_map('strval', (array)($i['ip_addresses'] ?? [])), 0, 30);
            return ['external_id' => self::id($i, 'id', 'vm_networks', 191), 'name' => self::str($i, 'name', 255),
                'switch_name' => self::str($i, 'switch_name', 255), 'mac_address' => self::str($i, 'mac_address', 20),
                'ip_addresses' => $ips ? mb_substr(implode(', ', $ips), 0, 1000) : null, 'vlan_id' => self::int($i, 'vlan_id')];
        });
    }

    /**
     * Shared volumes have completeness and failures per CLUSTER, independently of host/VM
     * reads. An omitted section (old collector), or omitted cluster, changes nothing.
     * Each cluster owns a savepoint so a bad partition cannot erase a good cluster's read.
     * TRAP: never put storage errors in the report's top-level unreadable list: doing that
     * would mark successfully read VMs unconfirmed too.
     */
    private static function apply_storage_volumes(PDO $conn, array &$ctx, array $section): array
    {
        // Before Database Verification has added storage, only this section fails (207); the VMs still apply.
        if (!self::storageReady($conn)) {
            throw new ServiceError('validation', 'storage_unavailable', 'Run Database Verification to accept storage.');
        }
        if (!is_array($section['clusters'] ?? null) || !array_is_list($section['clusters'])) {
            throw new ServiceError('validation', 'invalid_section', 'storage_volumes needs clusters (a list of cluster reads).');
        }
        $clusterIds = self::idMap($conn, $ctx, 'virt_clusters');
        $scopeIds = self::scopeClusterIds($conn, $ctx);
        $reads = [];
        foreach ($section['clusters'] as $read) {
            if (!is_array($read)) {
                throw new ServiceError('validation', 'invalid_item', 'Every storage cluster read must be an object.');
            }
            $external = self::id($read, 'cluster_id', 'storage_volumes');
            $id = $clusterIds[$external] ?? null;
            if ($id === null || !in_array($id, $scopeIds, true)) {
                throw new ServiceError('validation', 'unknown_cluster', 'Storage cluster must belong to this connection and report scope.');
            }
            if (isset($reads[$id])) {
                throw new ServiceError('validation', 'duplicate_cluster', 'Send only one storage read per cluster.');
            }
            $reads[$id] = ['external_id' => $external, 'read' => $read];
        }
        // Get-Cluster itself can fail before the collector learns its id. Only an opted-in
        // report reaches this method: old collectors must leave storage entirely untouched.
        $blocked = self::unreadableIds($conn, $ctx);
        $externalById = array_flip($clusterIds);
        foreach (array_intersect($blocked['clusters'], $scopeIds) as $id) {
            if (!isset($reads[$id])) {
                $reads[$id] = ['external_id' => $externalById[$id],
                    'read' => ['complete' => false, 'items' => [], 'error' => 'Cluster could not be read.']];
            }
        }
        $stats = ['added' => 0, 'updated' => 0, 'not_seen' => 0, 'stale_objects' => 0, 'clusters' => []];
        $failed = 0;
        foreach ($reads as $clusterId => $entry) {
            $read = $entry['read'];
            $external = $entry['external_id'];
            $st = $conn->prepare('SELECT storage_last_attempted_at FROM virt_clusters WHERE id = ? AND connection_id = ? FOR UPDATE');
            $st->execute([$clusterId, (int)$ctx['conn']['id']]);
            $lastAttempt = $st->fetchColumn();
            // The watermark includes failed/empty reads, not only the last successful row.
            // Otherwise an older report with a different scope could resurrect stale facts.
            if ($lastAttempt !== null && $lastAttempt > $ctx['observed']) {
                $stats['clusters'][$external] = ['outcome' => 'stale'];
                $stats['stale_objects']++;
                continue;
            }
            $conn->exec('SAVEPOINT virt_storage_cluster');
            $error = null;
            try {
                $items = self::items($read, 'storage_volumes', function (array $i) use ($external, $clusterId) {
                    $resource = self::id($i, 'resource_id', 'storage_volumes', 191);
                    $volume = self::id($i, 'volume_id', 'storage_volumes', 191);
                    $total = self::storageBytes($i['total_bytes'] ?? null);
                    $free = self::storageBytes($i['free_bytes'] ?? null);
                    if ($total !== null && $free !== null && $free > $total) { $free = null; }
                    return ['external_id' => hash('sha256', json_encode([$external, $resource, $volume])),
                        'cluster_id' => $clusterId, 'resource_id' => $resource, 'volume_id' => $volume,
                        'name' => self::str($i, 'name', 255), 'path' => self::str($i, 'path', 500),
                        'filesystem' => self::str($i, 'filesystem', 60), 'state' => self::str($i, 'state', 30),
                        'coordinator_node' => self::str($i, 'coordinator_node', 255),
                        'total_bytes' => $total, 'free_bytes' => $free,
                        'used_bytes' => $total !== null && $free !== null ? $total - $free : null];
                });
                if (!array_is_list($read['items'])) {
                    throw new ServiceError('validation', 'invalid_item', 'Storage items must be a list.');
                }
                $ids = array_column($items, 'external_id');
                if (count($ids) !== count(array_unique($ids))) {
                    throw new ServiceError('validation', 'duplicate_volume', 'Send each resource and volume identity only once per cluster.');
                }
                $error = self::str($read, 'error', 300);
                if ($error !== null && ($read['complete'] || $items)) {
                    throw new ServiceError('validation', 'invalid_item', 'A failed storage read must have complete=false and no items.');
                }
                if (in_array($clusterId, $blocked['clusters'], true)) {
                    throw new ServiceError('validation', 'cluster_unreadable', 'Storage cannot be confirmed while its cluster is unreadable.');
                }
                $cols = ['cluster_id', 'resource_id', 'volume_id', 'name', 'path', 'filesystem', 'state',
                    'coordinator_node', 'total_bytes', 'free_bytes', 'used_bytes'];
                $s = self::upsert($conn, $ctx, 'virt_storage_volumes', $items, $cols);
                $notSeen = 0;
                if ($read['complete']) {
                    $notSeen = self::markNotSeen($conn, $ctx, 'virt_storage_volumes', $ids, [$clusterId], 'cluster_id');
                } else {
                    $error = $error ?? 'Storage read was incomplete.';
                }
                $stats['added'] += $s['added'];
                $stats['updated'] += $s['updated'];
                $stats['not_seen'] += $notSeen;
                $stats['stale_objects'] += $s['stale_objects'] ?? 0;
                $stats['clusters'][$external] = ['outcome' => $error === null ? 'applied' : ($items ? 'partial' : 'failed')]
                    + $s + ['not_seen' => $notSeen];
                $conn->exec('RELEASE SAVEPOINT virt_storage_cluster');
            } catch (ServiceError $e) {
                $conn->exec('ROLLBACK TO SAVEPOINT virt_storage_cluster');
                $error = $e->getMessage();
                $stats['clusters'][$external] = ['outcome' => 'failed', 'error' => $e->errorCode];
            }
            if ($error !== null) {
                $failed++;
                $stats['clusters'][$external]['message'] = $error;
                $conn->prepare('UPDATE virt_storage_volumes SET not_confirmed_since = COALESCE(not_confirmed_since, ?)
                    WHERE connection_id = ? AND cluster_id = ? AND not_seen_since IS NULL
                    AND (last_seen_observed_at IS NULL OR last_seen_observed_at < ?)')
                    ->execute([$ctx['observed'], (int)$ctx['conn']['id'], $clusterId, $ctx['observed']]);
            }
            // failing_since / failed_runs: how long storage has been failing, for the Servers page
            // and the collector alert (watch()). A good read resets both.
            $conn->prepare('UPDATE virt_clusters SET storage_last_attempted_at = ?, storage_read_error = ?,
                storage_last_observed_at = CASE WHEN ? IS NULL THEN ? ELSE storage_last_observed_at END,
                storage_failing_since = CASE WHEN ? IS NULL THEN NULL ELSE COALESCE(storage_failing_since, ?) END,
                storage_failed_runs = CASE WHEN ? IS NULL THEN 0 ELSE storage_failed_runs + 1 END
                WHERE id = ? AND connection_id = ?')
                ->execute([$ctx['observed'], $error, $error, $ctx['observed'], $error, $ctx['observed'], $error,
                    $clusterId, (int)$ctx['conn']['id']]);
        }
        $someApplied = array_filter($stats['clusters'], fn($r) => $r['outcome'] !== 'failed');
        $stats['outcome'] = !$failed ? 'applied' : ($someApplied ? 'partial' : 'failed');
        return $stats;
    }

    /** A capacity is an exact, non-negative signed BIGINT, or unknown. Never coerce null to zero. */
    private static function storageBytes($value): ?int
    {
        if (!is_int($value) && !is_string($value)) { return null; }
        $text = (string)$value;
        if (!preg_match('/^\d+$/D', $text)) { return null; }
        $text = ltrim($text, '0');
        if ($text === '') { return 0; }
        if (strlen($text) > 19 || (strlen($text) === 19 && strcmp($text, '9223372036854775807') > 0)) { return null; }
        return (int)$text;
    }

    /**
     * Disks or adapters, grouped by VM. A complete section makes each VM it names, and each
     * VM in scope it does not name, hold exactly the rows sent. VMs not in this connection are
     * refused as a whole section, so a typo cannot quietly drop data.
     */
    private static function vmChildren(PDO $conn, array &$ctx, array $section, string $name, string $table, callable $shape): array
    {
        $vmIds = self::idMap($conn, $ctx, 'virt_vms');
        $byVm = [];
        foreach (self::items($section, $name, function (array $i) use ($shape, $vmIds, $name) {
            $vm = self::id($i, 'vm_id', $name);
            if (!isset($vmIds[strtolower($vm)])) {
                throw new ServiceError('validation', 'unknown_vm', "$name: VM $vm is not in this report or this connection.");
            }
            return ['vm' => $vmIds[strtolower($vm)]] + $shape($i);
        }) as $row) {
            $byVm[$row['vm']][] = $row;
        }
        $added = 0; $updated = 0; $removed = 0;
        $observed = $ctx['observed'];
        foreach ($byVm as $vmId => $rows) {
            foreach ($rows as $r) {
                $vmIdFor = $r['vm']; unset($r['vm']);
                $cols = array_keys($r);
                $sql = "INSERT INTO $table (vm_id, " . implode(', ', $cols) . ', observed_at) VALUES (?' . str_repeat(', ?', count($cols)) . ', ?)
                    ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = IF(observed_at IS NULL OR observed_at <= VALUES(observed_at), VALUES($c), $c)", $cols))
                    . ', observed_at = GREATEST(COALESCE(observed_at, VALUES(observed_at)), VALUES(observed_at))';
                $st = $conn->prepare($sql);
                $st->execute(array_merge([$vmIdFor], array_values($r), [$observed]));
                if ($st->rowCount() === 1) { $added++; } elseif ($st->rowCount() === 2) { $updated++; }
            }
        }
        if (!empty($section['complete'])) {
            // Every VM in scope (or named here) holds exactly what was sent.
            $vms = array_unique(array_merge(array_keys($byVm), self::scopeVmIds($conn, $ctx)));
            foreach ($vms as $vmId) {
                $keep = array_map(fn($r) => $r['external_id'], $byVm[$vmId] ?? []);
                $sql = "DELETE FROM $table WHERE vm_id = ? AND (observed_at IS NULL OR observed_at <= ?)"
                    . ($keep ? ' AND external_id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')' : '');
                $st = $conn->prepare($sql);
                $st->execute(array_merge([$vmId, $observed], $keep));
                $removed += $st->rowCount();
            }
        }
        return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
    }

    /**
     * Storage grouped by connection and cluster, including never-read and empty clusters.
     * Callers pass only connection ids they already authorized (session company filter or
     * apiVirtConnection). An empty list must never mean all companies.
     */
    public static function storageInventory(PDO $conn, array $connectionIds): array
    {
        // Before Database Verification: nothing, and the Servers page says "Storage not collected."
        if (!$connectionIds || !self::storageReady($conn)) { return []; }
        $in = implode(',', array_map('intval', $connectionIds));
        $clusters = $conn->query("SELECT cl.id AS cluster_id, cl.external_id AS cluster_external_id, cl.name AS cluster_name,
                cl.connection_id, c.name AS connection_name, cl.storage_last_attempted_at AS last_attempted_at,
                cl.storage_last_observed_at AS last_observed_at, cl.storage_read_error AS read_error,
                cl.storage_failing_since AS failing_since, cl.storage_failed_runs AS failed_runs
            FROM virt_clusters cl JOIN virt_connections c ON c.id = cl.connection_id
            WHERE cl.connection_id IN ($in) ORDER BY c.name, c.id, cl.name, cl.id")->fetchAll(PDO::FETCH_ASSOC);
        $byCluster = [];
        foreach ($clusters as $i => &$cluster) {
            $cluster['cluster_id'] = (int)$cluster['cluster_id'];
            $cluster['connection_id'] = (int)$cluster['connection_id'];
            $cluster['failed_runs'] = (int)$cluster['failed_runs'];
            $cluster['volumes'] = [];
            $byCluster[$cluster['cluster_id']] = $i;
        }
        unset($cluster);
        $volumes = $conn->query("SELECT id, connection_id, cluster_id, external_id, resource_id, volume_id, name, path,
                filesystem, state, coordinator_node, total_bytes, free_bytes, used_bytes, first_seen_at,
                last_seen_observed_at, not_seen_since, not_confirmed_since
            FROM virt_storage_volumes WHERE connection_id IN ($in) ORDER BY name, volume_id, id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($volumes as $volume) {
            foreach (['id', 'connection_id', 'cluster_id', 'total_bytes', 'free_bytes', 'used_bytes'] as $column) {
                if ($volume[$column] !== null) { $volume[$column] = (int)$volume[$column]; }
            }
            if (isset($byCluster[$volume['cluster_id']])) {
                $clusters[$byCluster[$volume['cluster_id']]]['volumes'][] = $volume;
            }
        }
        return $clusters;
    }

    // ---- shared mechanics ----------------------------------------------------

    /**
     * Insert or update discovered objects by (connection, external_id). An object last
     * observed AFTER this report is left alone (an older report never winds it back). Seen
     * now: not_seen_since and not_confirmed_since are cleared.
     *
     * @param ?callable $after  fn(int id, ?array before, array after) - for placement history
     */
    private static function upsert(PDO $conn, array &$ctx, string $table, array $items, array $cols, ?callable $after = null): array
    {
        $added = 0; $updated = 0; $skipped = 0;
        $connId = (int)$ctx['conn']['id'];
        $observed = $ctx['observed'];
        // Every existing row of this connection, read (and locked) once rather than per item:
        // an 8,000-VM report would otherwise be 8,000 extra round trips.
        $existing = [];
        $all = $conn->prepare("SELECT * FROM $table WHERE connection_id = ? FOR UPDATE");
        $all->execute([$connId]);
        foreach ($all->fetchAll(PDO::FETCH_ASSOC) as $r) { $existing[$r['external_id']] = $r; }
        foreach ($items as $i) {
            $row = $existing[$i['external_id']] ?? null;
            $vals = array_map(fn($c) => $i[$c] ?? null, $cols);
            if (!$row) {
                $conn->prepare("INSERT INTO $table (connection_id, tenant_id, external_id, " . implode(', ', $cols) . ', first_seen_at, last_seen_observed_at)
                    VALUES (?, ?, ?' . str_repeat(', ?', count($cols)) . ', ?, ?)')
                    ->execute(array_merge([$connId, $ctx['tenant'], $i['external_id']], $vals, [$observed, $observed]));
                $id = (int)$conn->lastInsertId();
                $added++;
                $ctx['touched'][$table]['new'][] = $id;
                if ($after) { $after($id, null, $i); }
                continue;
            }
            if ($row['last_seen_observed_at'] !== null && $row['last_seen_observed_at'] > $observed) { $skipped++; continue; }
            $conn->prepare("UPDATE $table SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ',
                    last_seen_observed_at = ?, not_seen_since = NULL, not_confirmed_since = NULL WHERE id = ?')
                ->execute(array_merge($vals, [$observed, (int)$row['id']]));
            $updated++;
            if ($after) { $after((int)$row['id'], $row, $i); }
        }
        return ['added' => $added, 'updated' => $updated] + ($skipped ? ['stale_objects' => $skipped] : []);
    }

    /**
     * A complete section: what its scope covers and it did not name is not seen since this
     * report - unless it sits on something the report could not read (markUnconfirmed()).
     *
     * @param int[] $scopeParents  the ids that bound the scope (clusters, hosts) - null = the whole connection
     * @param string $parentCol  the column holding that id on $table ('id' when the table is the scope itself)
     */
    private static function markNotSeen(PDO $conn, array &$ctx, string $table, array $seen, ?array $scopeParents, string $parentCol): int
    {
        if ($scopeParents === []) { return 0; }
        $args = [(int)$ctx['conn']['id'], $ctx['observed']];
        $sql = "SELECT id FROM $table WHERE connection_id = ? AND not_seen_since IS NULL AND (last_seen_observed_at IS NULL OR last_seen_observed_at < ?)";
        if ($scopeParents !== null) {
            $sql .= " AND $parentCol IN (" . implode(',', array_map('intval', $scopeParents)) . ')';
        }
        if ($seen) {
            $sql .= ' AND external_id NOT IN (' . implode(',', array_fill(0, count($seen), '?')) . ')';
            $args = array_merge($args, $seen);
        }
        $blocked = self::unreadableIds($conn, $ctx);
        $st = $conn->prepare($sql);
        $st->execute($args);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (self::onUnreadable($conn, $table, (int)$id, $blocked)) { continue; }
            $conn->prepare("UPDATE $table SET not_seen_since = ? WHERE id = ?")->execute([$ctx['observed'], (int)$id]);
            $ctx['touched'][$table]['gone'][] = (int)$id;
            $n++;
        }
        return $n;
    }

    /** Everything on a host or cluster the report could not read: kept exactly as it was, marked not confirmed. */
    private static function markUnconfirmed(PDO $conn, array $ctx): void
    {
        $b = self::unreadableIds($conn, $ctx);
        $connId = (int)$ctx['conn']['id'];
        $o = $ctx['observed'];
        if ($b['hosts']) {
            $in = implode(',', $b['hosts']);
            $conn->prepare("UPDATE virt_hosts SET not_confirmed_since = COALESCE(not_confirmed_since, ?) WHERE connection_id = ? AND id IN ($in)")->execute([$o, $connId]);
            $conn->prepare("UPDATE virt_vms SET not_confirmed_since = COALESCE(not_confirmed_since, ?) WHERE connection_id = ? AND host_id IN ($in) AND not_seen_since IS NULL")->execute([$o, $connId]);
        }
        if ($b['clusters']) {
            $in = implode(',', $b['clusters']);
            foreach (['virt_clusters' => 'id', 'virt_hosts' => 'cluster_id', 'virt_cluster_roles' => 'cluster_id', 'virt_vms' => 'cluster_id'] as $t => $col) {
                $conn->prepare("UPDATE $t SET not_confirmed_since = COALESCE(not_confirmed_since, ?) WHERE connection_id = ? AND $col IN ($in) AND not_seen_since IS NULL")->execute([$o, $connId]);
            }
        }
    }

    /** Placement history: a VM whose host changed closes its open placement and opens a new one. */
    private static function place(PDO $conn, array $ctx, int $vmId, ?array $before, array $after): void
    {
        $newHost = $after['host_id'] ?? null;
        if ($before !== null && (int)($before['host_id'] ?? 0) === (int)($newHost ?? 0)) { return; }
        if ($newHost === null && $before !== null) { return; }   // host not resolvable this time: no claim either way
        $conn->prepare('UPDATE virt_placements SET to_observed_at = ? WHERE vm_id = ? AND to_observed_at IS NULL')->execute([$ctx['observed'], $vmId]);
        $conn->prepare('INSERT INTO virt_placements (vm_id, host_id, cluster_id, from_observed_at) VALUES (?, ?, ?, ?)')
            ->execute([$vmId, $newHost, $after['cluster_id'] ?? null, $ctx['observed']]);
    }

    // ---- identity --------------------------------------------------------------

    /**
     * Pairs that may be one machine under a new identity: a VM (or host) first seen at or
     * after the moment another of the same name was last seen, and that other one now not
     * seen. A host with a name-based identity has only its name, so for those a rename in the
     * same cluster is raised too. Raised once per pair; never decided here.
     */
    private static function raiseIdentityReviews(PDO $conn, array $ctx): int
    {
        $connId = (int)$ctx['conn']['id'];
        $n = 0;
        $exists = $conn->prepare('SELECT 1 FROM virt_identity_reviews WHERE object_kind = ? AND old_object_id = ? AND new_object_id = ?');
        $ins = $conn->prepare("INSERT INTO virt_identity_reviews (connection_id, tenant_id, object_kind, old_object_id, new_object_id, name,
                old_external_id, new_external_id, status, raised_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', UTC_TIMESTAMP())");
        foreach (['vm' => 'virt_vms', 'host' => 'virt_hosts'] as $kind => $table) {
            $ids = array_merge($ctx['touched'][$table]['new'] ?? [], $ctx['touched'][$table]['gone'] ?? []);
            if (!$ids) { continue; }
            $hostRule = $kind === 'host'
                ? " OR (o.identity_kind = 'name' AND n.identity_kind = 'name' AND o.cluster_id IS NOT NULL AND o.cluster_id = n.cluster_id)" : '';
            $in = implode(',', array_map('intval', $ids));
            $st = $conn->query("SELECT o.id AS old_id, n.id AS new_id, n.name, o.external_id AS old_ext, n.external_id AS new_ext
                FROM $table o JOIN $table n ON n.connection_id = o.connection_id AND n.id <> o.id
                WHERE o.connection_id = $connId AND o.not_seen_since IS NOT NULL AND n.not_seen_since IS NULL
                  AND n.first_seen_at >= o.last_seen_observed_at
                  AND (LOWER(n.name) = LOWER(o.name)$hostRule)
                  AND (o.id IN ($in) OR n.id IN ($in))");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $exists->execute([$kind, $p['old_id'], $p['new_id']]);
                if ($exists->fetchColumn()) { continue; }
                $ins->execute([$connId, $ctx['tenant'], $kind, $p['old_id'], $p['new_id'], $p['name'], $p['old_ext'], $p['new_ext']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * A person's decision on an identity case.
     *
     * same: the OLD record takes the new identity and the new one's current facts; its first
     * seen, placement history (and later its CI binding) stay; the new row's disks, adapters,
     * placements and anything pointing at it move to the old one, then it goes.
     * different: both are kept. Either is refused (review_changed) if the old one is seen again
     * or the new one has gone since the case was raised - the person decides on what they saw.
     *
     * @param string $decision  same | different
     */
    public static function decideReview(PDO $conn, ActorContext $ctx, int $reviewId, string $decision): array
    {
        if (!in_array($decision, ['same', 'different'], true)) {
            throw new ServiceError('validation', 'invalid_field', 'Decide same or different.');
        }
        $conn->beginTransaction();
        try {
            $st = $conn->prepare('SELECT * FROM virt_identity_reviews WHERE id = ? FOR UPDATE');
            $st->execute([$reviewId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || ($ctx->companyScope !== null && !in_array($r['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$r['tenant_id'], $ctx->companyScope, true))) {
                throw new ServiceError('not_found', 'not_found', 'Review case not found.');
            }
            if ($r['status'] !== 'open') {
                throw new ServiceError('conflict', 'already_decided', 'This case has already been decided.');
            }
            $table = $r['object_kind'] === 'host' ? 'virt_hosts' : 'virt_vms';
            $get = $conn->prepare("SELECT * FROM $table WHERE id = ? FOR UPDATE");
            $get->execute([(int)$r['old_object_id']]);
            $old = $get->fetch(PDO::FETCH_ASSOC);
            $get->execute([(int)$r['new_object_id']]);
            $new = $get->fetch(PDO::FETCH_ASSOC);
            if (!$old || !$new || $old['not_seen_since'] === null || $new['not_seen_since'] !== null) {
                $conn->prepare("UPDATE virt_identity_reviews SET status = 'superseded', decided_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$reviewId]);
                $conn->commit();
                throw new ServiceError('conflict', 'review_changed', 'Something changed since this case was raised - the old one was seen again or the new one has gone. It has been closed.');
            }
            if ($decision === 'same') {
                self::rebind($conn, $table, $old, $new);
            }
            $conn->prepare('UPDATE virt_identity_reviews SET status = ?, decided_by_id = ?, decided_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$decision, $ctx->actorId > 0 ? $ctx->actorId : null, $reviewId]);
            // Any other open case naming the row that just went is moot.
            if ($decision === 'same') {
                $conn->prepare("UPDATE virt_identity_reviews SET status = 'superseded', decided_at = UTC_TIMESTAMP()
                    WHERE status = 'open' AND object_kind = ? AND (old_object_id = ? OR new_object_id = ?)")
                    ->execute([$r['object_kind'], (int)$new['id'], (int)$new['id']]);
            }
            $conn->commit();
            return ['id' => $reviewId, 'status' => $decision, 'kept_id' => (int)$old['id']];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
    }

    /** "Same machine": the old row (and its id) carries on under the new identity. */
    private static function rebind(PDO $conn, string $table, array $old, array $new): void
    {
        $oldId = (int)$old['id']; $newId = (int)$new['id'];
        // Part B: the old row keeps its CI binding; a binding the new row had moves or ends.
        require_once __DIR__ . '/virtual_estate_cmdb.php';
        VirtualEstateCmdbService::rebound($conn, $table === 'virt_hosts' ? 'host' : 'vm', $oldId, $newId);
        if ($table === 'virt_vms') {
            $conn->prepare('DELETE FROM virt_vm_disks WHERE vm_id = ?')->execute([$oldId]);
            $conn->prepare('DELETE FROM virt_vm_networks WHERE vm_id = ?')->execute([$oldId]);
            $conn->prepare('UPDATE virt_vm_disks SET vm_id = ? WHERE vm_id = ?')->execute([$oldId, $newId]);
            $conn->prepare('UPDATE virt_vm_networks SET vm_id = ? WHERE vm_id = ?')->execute([$oldId, $newId]);
            $conn->prepare('UPDATE virt_placements SET to_observed_at = COALESCE(to_observed_at, ?) WHERE vm_id = ?')->execute([$old['not_seen_since'], $oldId]);
            $conn->prepare('UPDATE virt_placements SET vm_id = ? WHERE vm_id = ?')->execute([$oldId, $newId]);
            $copy = ['name', 'host_id', 'cluster_id', 'state', 'is_clustered', 'cluster_role_id', 'possible_owners_json', 'generation',
                'cpu_count', 'memory_bytes', 'guest_addresses', 'os_name'];
        } else {
            $conn->prepare('UPDATE virt_vms SET host_id = ? WHERE host_id = ?')->execute([$oldId, $newId]);
            $conn->prepare('UPDATE virt_cluster_roles SET owner_host_id = ? WHERE owner_host_id = ?')->execute([$oldId, $newId]);
            $conn->prepare('UPDATE virt_placements SET host_id = ? WHERE host_id = ?')->execute([$oldId, $newId]);
            $copy = ['identity_kind', 'name', 'cluster_id', 'node_state', 'os_version', 'cpu_count', 'memory_bytes'];
        }
        $conn->prepare("DELETE FROM $table WHERE id = ?")->execute([$newId]);
        $conn->prepare("UPDATE $table SET external_id = ?, " . implode(', ', array_map(fn($c) => "$c = ?", $copy)) . ',
                last_seen_observed_at = ?, not_seen_since = NULL, not_confirmed_since = ? WHERE id = ?')
            ->execute(array_merge([$new['external_id']], array_map(fn($c) => $new[$c], $copy), [$new['last_seen_observed_at'], $new['not_confirmed_since'], $oldId]));
    }

    // ======================================================================
    //  Connections
    // ======================================================================

    /** A connection row, or not found. */
    public static function connection(PDO $conn, int $id): array
    {
        $st = $conn->prepare('SELECT * FROM virt_connections WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new ServiceError('not_found', 'not_found', 'Connection not found.'); }
        return $row;
    }

    /**
     * Is a connection on time? late after its interval, overdue after interval + grace,
     * never when nothing has arrived. The same words the Servers page and job health use.
     */
    public static function freshness(array $c, ?int $now = null): string
    {
        if (empty($c['last_report_at'])) { return 'never'; }
        $age = ($now ?? time()) - strtotime($c['last_report_at'] . ' UTC');
        $interval = 60 * max(1, (int)$c['expected_interval_minutes']);
        if ($age <= $interval) { return 'on_time'; }
        return $age <= $interval + 60 * max(0, (int)$c['grace_minutes']) ? 'late' : 'overdue';
    }

    /**
     * A new key for a connection - the only key its reports are accepted from. The old one
     * (if any) stops working at once. The key acts as the analyst who made it, can do one
     * thing (virtual_estate.create), and is limited to the connection's company. Returned
     * once; only its hash is stored. The caller holds the transaction.
     */
    public static function mintKey(PDO $conn, int $analystId, int $connectionId): string
    {
        $c = self::connection($conn, $connectionId);
        if ($c['api_key_id']) {
            $conn->prepare('UPDATE api_keys SET active = 0 WHERE id = ?')->execute([(int)$c['api_key_id']]);
        }
        $companies = isMultiTenant($conn) ? [$c['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$c['tenant_id']] : null;
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, active, created_by, created_datetime)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, UTC_TIMESTAMP())')
            ->execute([mb_substr('Hyper-V collector: ' . $c['name'], 0, 100), substr($key, 0, 14), hash('sha256', $key), $analystId,
                json_encode(['virtual_estate' => ['create']]), $companies ? json_encode($companies) : null, $analystId]);
        $conn->prepare('UPDATE virt_connections SET api_key_id = ? WHERE id = ?')->execute([(int)$conn->lastInsertId(), $connectionId]);
        return $key;
    }

    /** Has Database Verification created the tables? */
    public static function ready(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try { $conn->query('SELECT 1 FROM virt_connections LIMIT 0'); $ready = true; }
            catch (PDOException) { $ready = false; }
        }
        return $ready;
    }

    /** Tests only: pretend storage is (not) ready. null = ask the database. */
    public static ?bool $storageReadyOverride = null;

    /**
     * Has Database Verification added cluster storage (#296) - the table and the cluster's
     * storage columns? Until it has, storage reads as "not collected", a report's storage
     * section fails on its own (the rest applies) and retention leaves storage alone.
     * TRAP: an upgraded install runs the new code BEFORE anyone runs Database Verification;
     * every storage query must sit behind this.
     */
    public static function storageReady(PDO $conn): bool
    {
        if (self::$storageReadyOverride !== null) { return self::$storageReadyOverride; }
        static $ready = null;
        if ($ready === null) {
            try {
                $conn->query('SELECT storage_last_attempted_at, storage_failing_since, storage_failed_runs, storage_alerted_at FROM virt_clusters LIMIT 0');
                $conn->query('SELECT 1 FROM virt_storage_volumes LIMIT 0');
                $ready = true;
            } catch (PDOException) { $ready = false; }
        }
        return $ready;
    }

    // ======================================================================
    //  Watching the collectors (#296 review): somebody hears when one stops
    // ======================================================================

    /** The connections' alert column, added by Database Verification with storage. */
    private static function watchReady(PDO $conn): bool
    {
        try { $conn->query('SELECT stale_alerted_at FROM virt_connections LIMIT 0'); return true; }
        catch (PDOException) { return false; }
    }

    /**
     * Run by the virt_watch background job (cron/virt_watch.php), hourly. A collector that
     * has stopped sends nothing, so nothing in submit() can notice it - a schedule has to.
     *
     *  - A switched-on connection whose last report is older than its own interval + grace
     *    (freshness() = overdue, the Servers page's Overdue) tells the analysts who manage
     *    its Hyper-V connections ONCE (stale_alerted_at); a report on time clears it, so the
     *    next silence tells them again. A connection that has never reported is left to
     *    System -> Background jobs (Never seen) - it is usually one still being set up.
     *  - A cluster whose storage read is failing (storage_read_error) tells them once
     *    (storage_alerted_at), cleared by a good read.
     *
     * Retention can never empty a silent connection: purge() only runs inside submit(),
     * after a report that was not stale or a replay, so no report means no clean-up.
     *
     * @param int[]|null $only  just these connections (tests); null = every one
     * @return array{stale:int, storage:int, cleared:int, told:int}
     */
    public static function watch(PDO $conn, ?int $now = null, ?array $only = null): array
    {
        $out = ['stale' => 0, 'storage' => 0, 'cleared' => 0, 'told' => 0];
        if (!self::ready($conn) || !self::watchReady($conn)) { return $out; }
        $nowSql = gmdate('Y-m-d H:i:s', $now ?? time());
        $limit = $only === null ? '' : ' AND id IN (' . (implode(',', array_map('intval', $only)) ?: '0') . ')';
        $rows = $conn->query('SELECT * FROM virt_connections WHERE is_active = 1' . $limit)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $c) {
            $id = (int)$c['id'];
            if (self::freshness($c, $now) === 'overdue') {
                if ($c['stale_alerted_at'] !== null) { continue; }
                $hours = intdiv(($now ?? time()) - strtotime($c['last_report_at'] . ' UTC'), 3600);
                $out['told'] += self::tell($conn, $c, 'Hyper-V collector stopped reporting: ' . $c['name'],
                    sprintf('No report for %s (expected every %d min). Check the scheduled task that runs Invoke-HyperVInventory.ps1.',
                        $hours >= 1 ? $hours . ' hour' . ($hours === 1 ? '' : 's') : 'over ' . (int)$c['expected_interval_minutes'] . ' min',
                        (int)$c['expected_interval_minutes']));
                $conn->prepare('UPDATE virt_connections SET stale_alerted_at = ? WHERE id = ?')->execute([$nowSql, $id]);
                $out['stale']++;
            } elseif ($c['stale_alerted_at'] !== null) {
                $conn->prepare('UPDATE virt_connections SET stale_alerted_at = NULL WHERE id = ?')->execute([$id]);
                $out['cleared']++;
            }
        }
        if (!self::storageReady($conn)) { return $out; }
        $byId = array_column($rows, null, 'id');
        $clusters = $conn->query('SELECT cl.id, cl.connection_id, cl.name, cl.external_id, cl.storage_read_error, cl.storage_failing_since,
                cl.storage_failed_runs, cl.storage_alerted_at
            FROM virt_clusters cl JOIN virt_connections c ON c.id = cl.connection_id AND c.is_active = 1' . str_replace('id IN', 'c.id IN', $limit) . '
            WHERE (cl.storage_read_error IS NOT NULL AND cl.storage_alerted_at IS NULL)
               OR (cl.storage_read_error IS NULL AND cl.storage_alerted_at IS NOT NULL)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($clusters as $cl) {
            if ($cl['storage_read_error'] === null) {
                $conn->prepare('UPDATE virt_clusters SET storage_alerted_at = NULL WHERE id = ?')->execute([(int)$cl['id']]);
                $out['cleared']++;
                continue;
            }
            $c = $byId[$cl['connection_id']] ?? null;
            if (!$c) { continue; }
            $name = $cl['name'] ?: $cl['external_id'];
            $out['told'] += self::tell($conn, $c, 'Cluster storage could not be read: ' . $name . ' (' . $c['name'] . ')',
                sprintf('%s Failing since %s UTC, %d report(s) in a row.', $cl['storage_read_error'],
                    substr((string)$cl['storage_failing_since'], 0, 16), max(1, (int)$cl['storage_failed_runs'])));
            $conn->prepare('UPDATE virt_clusters SET storage_alerted_at = ? WHERE id = ?')->execute([$nowSql, (int)$cl['id']]);
            $out['storage']++;
        }
        return $out;
    }

    /**
     * Who hears about a connection: every active analyst who could open its Hyper-V
     * connections - Assets, the Hyper-V settings capability, and the connection's company.
     *
     * @return int[] analyst ids
     */
    public static function watchRecipients(PDO $conn, array $c): array
    {
        require_once __DIR__ . '/../rbac.php';
        require_once __DIR__ . '/../tenancy.php';
        $tenant = $c['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$c['tenant_id'];
        $out = [];
        foreach ($conn->query('SELECT id FROM analysts WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $a) {
            $a = (int)$a;
            if (analystCanAccessModule($conn, $a, 'assets') && analystHasCapability($conn, $a, Cap::ASSETS_HYPERV)
                && analystCanAccessTenant($conn, $a, $tenant)) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /** The bell, to watchRecipients(); returns how many were told. Never throws. */
    private static function tell(PDO $conn, array $c, string $title, string $body): int
    {
        try {
            require_once __DIR__ . '/notifications.php';
            $told = 0;
            foreach (self::watchRecipients($conn, $c) as $a) {
                $told += NotificationsService::notify($conn, ['analyst_id' => $a, 'event_type' => 'hyperv.collector',
                    'entity_type' => 'virt_connection', 'entity_id' => (int)$c['id'], 'title' => $title, 'body' => $body]) ? 1 : 0;
            }
            return $told;
        } catch (Throwable $e) {
            error_log('[virt watch] bell: ' . $e->getMessage());
            return 0;
        }
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /** Validate the envelope; returns [observed (UTC 'Y-m-d H:i:s'), scope, sections, unreadable, collector]. */
    private static function envelope(array $r): array
    {
        if ((int)($r['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new ServiceError('validation', 'invalid_field', "schema_version must be " . self::SCHEMA_VERSION . '.');
        }
        $ts = isset($r['observed_at']) && is_string($r['observed_at']) && preg_match('/(Z|[+-]\d\d:?\d\d)$/', $r['observed_at']) ? strtotime($r['observed_at']) : false;
        if ($ts === false) {
            throw new ServiceError('validation', 'invalid_field', 'observed_at must be an RFC 3339 time with a timezone, e.g. 2026-10-26T09:14:00Z.');
        }
        if ($ts > time() + 300) {
            throw new ServiceError('validation', 'invalid_field', 'observed_at is in the future.');
        }
        $scope = [];
        foreach ((array)($r['scope'] ?? []) as $s) {
            if (!is_array($s) || !in_array($s['kind'] ?? '', ['cluster', 'host'], true) || !is_string($s['name'] ?? $s['id'] ?? null)) {
                throw new ServiceError('validation', 'invalid_field', 'Each scope entry needs kind (cluster or host) and a name or id.');
            }
            $scope[] = ['kind' => $s['kind'], 'id' => isset($s['id']) ? strtolower((string)$s['id']) : null, 'name' => isset($s['name']) ? mb_strtolower((string)$s['name']) : null];
        }
        if (!$scope) {
            throw new ServiceError('validation', 'missing_field', 'scope is required: the clusters and standalone hosts this report covers.');
        }
        usort($scope, fn($a, $b) => [$a['kind'], $a['id'], $a['name']] <=> [$b['kind'], $b['id'], $b['name']]);
        $sections = $r['sections'] ?? [];
        if (!is_array($sections) || array_diff(array_keys($sections), self::SECTIONS)) {
            throw new ServiceError('validation', 'invalid_field', 'sections may contain only: ' . implode(', ', self::SECTIONS) . '.');
        }
        $unreadable = [];
        foreach ((array)($r['unreadable'] ?? []) as $u) {
            if (!is_array($u) || !in_array($u['kind'] ?? '', ['cluster', 'host'], true)) {
                throw new ServiceError('validation', 'invalid_field', 'Each unreadable entry needs kind (cluster or host) and a name or id.');
            }
            $unreadable[] = ['kind' => $u['kind'], 'id' => isset($u['id']) ? strtolower((string)$u['id']) : null,
                'name' => isset($u['name']) ? mb_strtolower((string)$u['name']) : null, 'error' => mb_substr((string)($u['error'] ?? ''), 0, 300)];
        }
        $collector = is_array($r['collector'] ?? null) ? array_map(fn($v) => mb_substr((string)$v, 0, 100), array_intersect_key($r['collector'], array_flip(['name', 'version', 'powershell', 'host']))) : [];
        return [gmdate('Y-m-d H:i:s', $ts), $scope, $sections, $unreadable, $collector];
    }

    private static function scopeKey(array $scope): string
    {
        return substr(hash('sha256', json_encode(array_map(fn($s) => [$s['kind'], $s['id'] ?? $s['name']], $scope))), 0, 64);
    }

    /**
     * The items of a section, each through its shaper. A bad item fails the whole section
     * (rolled back alone) rather than being skipped, so nothing is half-applied.
     */
    private static function items(array $section, string $name, callable $shape): array
    {
        if (!array_key_exists('complete', $section) || !is_bool($section['complete']) || !is_array($section['items'] ?? null)) {
            throw new ServiceError('validation', 'invalid_section', "$name needs complete (true or false) and items (a list).");
        }
        $out = [];
        foreach ($section['items'] as $i => $item) {
            if (!is_array($item)) {
                throw new ServiceError('validation', 'invalid_item', "$name item $i is not an object.");
            }
            $out[] = $shape($item);
        }
        return $out;
    }

    private static function id(array $i, string $key, string $section, int $max = 100): string
    {
        $v = $i[$key] ?? null;
        if (!is_string($v) || trim($v) === '' || mb_strlen($v) > $max) {
            throw new ServiceError('validation', 'invalid_item', "$section: every item needs '$key' (text, at most $max characters).");
        }
        return strtolower(trim($v));
    }

    private static function str(array $i, string $key, int $max, bool $required = false): ?string
    {
        $v = $i[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) { throw new ServiceError('validation', 'invalid_item', "Every item needs '$key'."); }
            return null;
        }
        if (!is_scalar($v)) { throw new ServiceError('validation', 'invalid_item', "'$key' must be text."); }
        return mb_substr(trim((string)$v), 0, $max);
    }

    private static function int(array $i, string $key): ?int
    {
        $v = $i[$key] ?? null;
        if ($v === null || $v === '') { return null; }
        if (!is_numeric($v)) { throw new ServiceError('validation', 'invalid_item', "'$key' must be a number, or null when it could not be read."); }
        return (int)$v;
    }

    /** A usable hardware UUID, normalised, or null for a missing or placeholder one. */
    private static function uuid(?string $v): ?string
    {
        if ($v === null) { return null; }
        $v = strtolower(trim($v, " {}\t"));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v)) { return null; }
        $hex = str_replace('-', '', $v);
        return (trim($hex, '0') === '' || trim($hex, 'f') === '') ? null : $v;
    }

    /** external_id (lower case) => row id, for one table of this connection. */
    private static function idMap(PDO $conn, array $ctx, string $table): array
    {
        $st = $conn->prepare("SELECT external_id, id FROM $table WHERE connection_id = ?");
        $st->execute([(int)$ctx['conn']['id']]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $e => $id) { $out[strtolower((string)$e)] = (int)$id; }
        return $out;
    }

    private static function idOf(PDO $conn, array $ctx, string $table, string $externalId): ?int
    {
        $st = $conn->prepare("SELECT id FROM $table WHERE connection_id = ? AND external_id = ?");
        $st->execute([(int)$ctx['conn']['id'], $externalId]);
        return ($id = $st->fetchColumn()) ? (int)$id : null;
    }

    /** lower(name) => host id, the newest-seen live one when a name repeats. */
    private static function knownHostsByName(PDO $conn, int $connId): array
    {
        $st = $conn->prepare('SELECT id, name FROM virt_hosts WHERE connection_id = ? ORDER BY not_seen_since IS NULL, last_seen_observed_at');
        $st->execute([$connId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) { $out[mb_strtolower((string)$h['name'])] = (int)$h['id']; }
        return $out;
    }

    /** The cluster row ids the report's scope names. */
    private static function scopeClusterIds(PDO $conn, array $ctx): array
    {
        $ids = [];
        $byExt = self::idMap($conn, $ctx, 'virt_clusters');
        $byName = [];
        foreach ($conn->query('SELECT id, name FROM virt_clusters WHERE connection_id = ' . (int)$ctx['conn']['id'])->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $byName[mb_strtolower((string)$c['name'])] = (int)$c['id'];
        }
        foreach ($ctx['scope'] as $s) {
            if ($s['kind'] !== 'cluster') { continue; }
            $id = ($s['id'] !== null ? ($byExt[$s['id']] ?? null) : null) ?? ($s['name'] !== null ? ($byName[$s['name']] ?? null) : null);
            if ($id) { $ids[] = $id; }
        }
        return $ids;
    }

    /** The host row ids the scope covers: hosts in its clusters, and its standalone hosts. */
    private static function scopeHostIds(PDO $conn, array $ctx): array
    {
        $ids = [];
        $clusters = self::scopeClusterIds($conn, $ctx);
        if ($clusters) {
            $ids = array_map('intval', $conn->query('SELECT id FROM virt_hosts WHERE cluster_id IN (' . implode(',', $clusters) . ')')->fetchAll(PDO::FETCH_COLUMN));
        }
        foreach ($ctx['scope'] as $s) {
            if ($s['kind'] !== 'host') { continue; }
            $id = ($s['name'] !== null ? ($ctx['hosts_by_name'][$s['name']] ?? null) : null)
                ?? ($s['id'] !== null ? self::idOf($conn, $ctx, 'virt_hosts', self::uuid($s['id']) ?? $s['id']) : null);
            if ($id) { $ids[] = $id; }
        }
        return array_values(array_unique($ids));
    }

    private static function scopeVmIds(PDO $conn, array $ctx): array
    {
        $hosts = self::scopeHostIds($conn, $ctx);
        if (!$hosts) { return []; }
        return array_map('intval', $conn->query('SELECT id FROM virt_vms WHERE not_seen_since IS NULL AND host_id IN (' . implode(',', $hosts) . ')')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** The host and cluster row ids the report named as unreadable. */
    private static function unreadableIds(PDO $conn, array $ctx): array
    {
        $hosts = []; $clusters = [];
        $clByExt = self::idMap($conn, $ctx, 'virt_clusters');
        foreach ($ctx['unreadable'] as $u) {
            if ($u['kind'] === 'host') {
                $id = ($u['name'] !== null ? ($ctx['hosts_by_name'][$u['name']] ?? null) : null)
                    ?? ($u['id'] !== null ? self::idOf($conn, $ctx, 'virt_hosts', self::uuid($u['id']) ?? $u['id']) : null);
                if ($id) { $hosts[] = $id; }
            } else {
                $id = $u['id'] !== null ? ($clByExt[$u['id']] ?? null) : null;
                if (!$id && $u['name'] !== null) {
                    $st = $conn->prepare('SELECT id FROM virt_clusters WHERE connection_id = ? AND LOWER(name) = ?');
                    $st->execute([(int)$ctx['conn']['id'], $u['name']]);
                    $id = ($x = $st->fetchColumn()) ? (int)$x : null;
                }
                if ($id) { $clusters[] = $id; }
            }
        }
        return ['hosts' => array_values(array_unique($hosts)), 'clusters' => array_values(array_unique($clusters))];
    }

    /** Does this object sit on something unreadable (so it must not be called gone)? */
    private static function onUnreadable(PDO $conn, string $table, int $id, array $b): bool
    {
        if (!$b['hosts'] && !$b['clusters']) { return false; }
        $row = $conn->query("SELECT * FROM $table WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        if ($table === 'virt_hosts') { return in_array($id, $b['hosts'], true) || in_array((int)$row['cluster_id'], $b['clusters'], true); }
        if ($table === 'virt_clusters') { return in_array($id, $b['clusters'], true); }
        if ($table === 'virt_vms') { return in_array((int)$row['host_id'], $b['hosts'], true) || in_array((int)$row['cluster_id'], $b['clusters'], true); }
        return in_array((int)($row['cluster_id'] ?? 0), $b['clusters'], true);
    }

    /** The retention setting (off by default): remove objects not seen for longer than it. */
    private static function purge(PDO $conn, array $c): void
    {
        $days = (int)($c['retention_days'] ?? 0);
        if ($days <= 0) { return; }
        $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        require_once __DIR__ . '/virtual_estate_cmdb.php';
        $bindKind = ['virt_vms' => 'vm', 'virt_cluster_roles' => 'role', 'virt_hosts' => 'host', 'virt_clusters' => 'cluster'];
        $cmdb = VirtualEstateCmdbService::ready($conn);
        // Storage volumes follow the same rule as VMs (Ed, #296 review): gone once not seen for
        // longer than the setting - and so are the volumes of a cluster that is itself being
        // removed (its storage cannot have been read since it went). Before the clusters, so a
        // cluster is kept only while it still has volumes.
        $storage = self::storageReady($conn);
        if ($storage) {
            $clusterBound = $cmdb ? ' AND ' . VirtualEstateCmdbService::boundIdsSql('cluster') : '';
            $conn->prepare("DELETE FROM virt_storage_volumes WHERE connection_id = ? AND (
                    (not_seen_since IS NOT NULL AND not_seen_since < ?)
                    OR cluster_id IN (SELECT id FROM (SELECT id FROM virt_clusters WHERE connection_id = ?
                        AND not_seen_since IS NOT NULL AND not_seen_since < ?$clusterBound) gone))")
                ->execute([(int)$c['id'], $cut, (int)$c['id'], $cut]);
        }
        foreach ($bindKind as $t => $bk) {
            $kind = $t === 'virt_vms' ? 'vm' : ($t === 'virt_hosts' ? 'host' : null);
            $openCase = $kind ? " AND id NOT IN (SELECT old_object_id FROM virt_identity_reviews WHERE status = 'open' AND object_kind = '$kind')" : '';
            // A discovered object with a CI is never purged: the CI, and the binding's history, need it.
            $bound = $cmdb ? ' AND ' . VirtualEstateCmdbService::boundIdsSql($bk) : '';
            // A cluster with volumes left (seen, or not yet past the setting) stays with them.
            if ($t === 'virt_clusters' && $storage) {
                $bound .= ' AND id NOT IN (SELECT cluster_id FROM virt_storage_volumes WHERE connection_id = ' . (int)$c['id'] . ')';
            }
            $conn->prepare("DELETE FROM $t WHERE connection_id = ? AND not_seen_since IS NOT NULL AND not_seen_since < ?$openCase$bound")->execute([(int)$c['id'], $cut]);
        }
    }

    private static function summary(array $results, array $unreadable): string
    {
        $bits = [];
        foreach ($results as $name => $r) {
            $bits[] = $r['outcome'] !== 'applied' ? "$name {$r['outcome']}" : sprintf('%s %d', $name, ($r['added'] ?? 0) + ($r['updated'] ?? 0));
        }
        if ($unreadable) { $bits[] = count($unreadable) . ' unreadable'; }
        return implode(', ', $bits);
    }

    /** Store the report's record and the connection's latest state; returns the adapter's answer. */
    private static function record(PDO $conn, array $c, string $scopeKey, string $observed, string $fingerprint, string $outcome,
        array $results, array $unreadable, array $collector, ?int $apiKeyId, int $status): array
    {
        $conn->prepare('INSERT INTO virt_reports (connection_id, scope_key, observed_at, received_at, fingerprint, outcome, sections_json, unreadable_json, collector_json, api_key_id)
            VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?)')
            ->execute([(int)$c['id'], $scopeKey, $observed, $fingerprint, $outcome, json_encode($results), $unreadable ? json_encode($unreadable) : null,
                $collector ? json_encode($collector) : null, $apiKeyId]);
        // A stale or replayed report still proves the collector is alive.
        $conn->prepare('UPDATE virt_connections SET last_report_at = UTC_TIMESTAMP(), last_outcome = ?, last_summary = ?, collector_json = COALESCE(?, collector_json),
                last_observed_at = CASE WHEN ? IN (\'applied\', \'partial\') AND (last_observed_at IS NULL OR last_observed_at < ?) THEN ? ELSE last_observed_at END
            WHERE id = ?')
            ->execute([$outcome, mb_substr(self::summary($results, $unreadable) ?: $outcome, 0, 1000), $collector ? json_encode($collector) : null,
                $outcome, $observed, $observed, (int)$c['id']]);
        return ['outcome' => $outcome, 'status' => $status, 'observed_at' => $observed, 'sections' => $results,
            'unreadable' => count($unreadable), 'review_raised' => 0];
    }
}
