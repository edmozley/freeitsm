<?php
/**
 * API Endpoint: Get servers list from database
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/virtual_estate.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, vm_id, name, power_state, memory_gb, num_cpu, ip_address, hard_disk_size_gb, host, cluster, guest_os, raw_data, DATE_FORMAT(last_synced, '%Y-%m-%d %H:%i:%s') as last_synced FROM servers ORDER BY name";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($servers as &$row) {
        $row['source'] = 'vcenter';
        $row['source_name'] = 'vCenter';
    }
    unset($row);

    // Proxmox VMs and containers, synced by cron/proxmox_sync.php or the Sync button,
    // shaped like the vCenter rows so the table, filters and totals treat them the same.
    // Nodes are hypervisors, not guests, so they are not listed here.
    $pxStmt = $conn->prepare(
        "SELECT v.vmid, v.vm_type, v.connection_id, c.name AS conn_name, v.name, v.status, v.vcpus, v.memory_mb,
                v.disk_gb, v.ip_addresses, v.node_name, v.cluster_name, v.os_type, v.details_json,
                DATE_FORMAT(v.last_seen_datetime, '%Y-%m-%d %H:%i:%s') AS last_seen
         FROM proxmox_vms v JOIN proxmox_connections c ON c.id = v.connection_id
         ORDER BY v.name"
    );
    $pxStmt->execute();
    foreach ($pxStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $servers[] = [
            'id'                  => 'px-' . $v['connection_id'] . '-' . $v['vmid'],
            'vm_id'               => 'proxmox-' . $v['connection_id'] . '-' . $v['vmid'],
            'name'                => (string)$v['name'],
            'power_state'         => $v['status'] === 'running' ? 'active' : 'offline',
            'memory_gb'           => $v['memory_mb'] !== null ? round($v['memory_mb'] / 1024, 2) : 0,
            'num_cpu'             => (int)$v['vcpus'],
            'ip_address'          => $v['ip_addresses'] ? explode(', ', $v['ip_addresses'])[0] : '',
            'hard_disk_size_gb'   => $v['disk_gb'] !== null ? (float)$v['disk_gb'] : 0,
            'host'                => (string)$v['node_name'],
            'cluster'             => (string)$v['cluster_name'],
            'guest_os'            => (string)($v['os_type'] ?? ''),
            'raw_data'            => $v['details_json'],
            'last_synced'         => $v['last_seen'],
            'source'              => 'proxmox',
            'source_name'         => 'Proxmox · ' . $v['conn_name'],
        ];
    }
    // VMware Cloud Director VMs, shaped like the rows above (same table, filters, totals).
    $vcStmt = $conn->prepare(
        "SELECT v.vm_uuid, v.connection_id, c.name AS conn_name, v.name, v.org_vdc, v.status, v.vcpus, v.memory_mb,
                v.disk_gb, v.ip_addresses, v.details_json, DATE_FORMAT(v.last_seen_datetime, '%Y-%m-%d %H:%i:%s') AS last_seen
         FROM vcloud_vms v JOIN vcloud_connections c ON c.id = v.connection_id"
    );
    $vcStmt->execute();
    foreach ($vcStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $servers[] = [
            'id'                  => 'vc-' . $v['connection_id'] . '-' . $v['vm_uuid'],
            'vm_id'               => 'vcloud-' . $v['connection_id'] . '-' . $v['vm_uuid'],
            'name'                => (string)$v['name'],
            'power_state'         => $v['status'] === 'running' ? 'active' : 'offline',
            'memory_gb'           => $v['memory_mb'] !== null ? round($v['memory_mb'] / 1024, 2) : 0,
            'num_cpu'             => (int)$v['vcpus'],
            'ip_address'          => $v['ip_addresses'] ? explode(', ', $v['ip_addresses'])[0] : '',
            'hard_disk_size_gb'   => $v['disk_gb'] !== null ? (float)$v['disk_gb'] : 0,
            'host'                => (string)$v['org_vdc'],
            'cluster'             => (string)$v['org_vdc'],
            'guest_os'            => '',
            'raw_data'            => $v['details_json'],
            'last_synced'         => $v['last_seen'],
            'source'              => 'vcloud',
            'source_name'         => 'vCloud · ' . $v['conn_name'],
        ];
    }
    // Hyper-V (GH #199): pushed by a collector, so no Sync - the panel says when each
    // connection last reported instead. Unlike the three sources above these belong to a
    // company, so they are scoped to the analyst's. A VM not seen, or on a host the last
    // report could not read, is still listed and says so - nothing vanishes for a bad read.
    $hyperv = ['connections' => [], 'clusters' => [], 'hosts' => [], 'storage' => []];
    if (VirtualEstateService::ready($conn)) {
        $analystId = (int)$_SESSION['analyst_id'];
        [$tf, $ta] = isMultiTenant($conn) ? allAccessibleTenantsFilter($conn, $analystId, 'c.tenant_id') : ['', []];
        $cs = $conn->prepare("SELECT c.* FROM virt_connections c WHERE 1 = 1$tf ORDER BY c.name");
        $cs->execute($ta);
        $connIds = [];
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $connIds[] = (int)$c['id'];
            $hyperv['connections'][] = ['id' => (int)$c['id'], 'name' => $c['name'], 'active' => (bool)$c['is_active'],
                'freshness' => VirtualEstateService::freshness($c), 'last_report_at' => $c['last_report_at'], 'last_observed_at' => $c['last_observed_at'],
                'interval' => (int)$c['expected_interval_minutes'], 'last_outcome' => $c['last_outcome'], 'last_summary' => $c['last_summary']];
        }
        if ($connIds) {
            $in = implode(',', $connIds);
            $hyperv['storage'] = VirtualEstateService::storageInventory($conn, $connIds);
            $names = [];
            foreach ($conn->query("SELECT id, name FROM virt_hosts WHERE connection_id IN ($in)")->fetchAll(PDO::FETCH_KEY_PAIR) as $hid => $hn) { $names[(int)$hid] = $hn; }
            $vms = $conn->query("SELECT v.*, h.name AS host_name, cl.name AS cluster_name, c.name AS conn_name,
                    (SELECT SUM(d.size_bytes) FROM virt_vm_disks d WHERE d.vm_id = v.id) AS disk_bytes
                FROM virt_vms v JOIN virt_connections c ON c.id = v.connection_id
                LEFT JOIN virt_hosts h ON h.id = v.host_id LEFT JOIN virt_clusters cl ON cl.id = v.cluster_id
                WHERE v.connection_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($vms as $v) {
                $owners = array_map(fn($id) => $names[(int)$id] ?? ('#' . $id), json_decode((string)$v['possible_owners_json'], true) ?: []);
                $servers[] = [
                    'id'                => 'hv-' . $v['id'],
                    'vm_id'             => 'hyperv-' . $v['connection_id'] . '-' . $v['external_id'],
                    'name'              => (string)$v['name'],
                    'power_state'       => $v['not_seen_since'] === null && strtolower((string)$v['state']) === 'running' ? 'active' : 'offline',
                    'memory_gb'         => $v['memory_bytes'] !== null ? round($v['memory_bytes'] / 1024 ** 3, 2) : 0,
                    'num_cpu'           => (int)$v['cpu_count'],
                    'ip_address'        => $v['guest_addresses'] ? explode(', ', $v['guest_addresses'])[0] : '',
                    'hard_disk_size_gb' => $v['disk_bytes'] !== null ? round($v['disk_bytes'] / 1024 ** 3, 1) : 0,
                    'host'              => (string)$v['host_name'],
                    'cluster'           => (string)$v['cluster_name'],
                    'guest_os'          => (string)($v['os_name'] ?? ''),
                    'raw_data'          => json_encode(['state' => $v['state'], 'highly_available' => $v['is_clustered'] === null ? null : (bool)$v['is_clustered'],
                        'possible_owners' => $owners, 'generation' => $v['generation'] !== null ? (int)$v['generation'] : null, 'vm_id' => $v['external_id'],
                        'first_seen' => $v['first_seen_at'], 'last_observed' => $v['last_seen_observed_at'],
                        'not_seen_since' => $v['not_seen_since'], 'not_confirmed_since' => $v['not_confirmed_since']]),
                    'last_synced'       => $v['last_seen_observed_at'],
                    'source'            => 'hyperv',
                    'source_name'       => 'Hyper-V · ' . $v['conn_name'],
                    'highly_available'  => $v['is_clustered'] === null ? null : (bool)$v['is_clustered'],
                    'not_seen_since'    => $v['not_seen_since'],
                    'not_confirmed_since' => $v['not_confirmed_since'],
                ];
            }
            $hyperv['clusters'] = $conn->query("SELECT cl.id, cl.name, cl.quorum_type, cl.witness, cl.not_seen_since, cl.not_confirmed_since, c.name AS conn_name,
                    (SELECT COUNT(*) FROM virt_hosts h WHERE h.cluster_id = cl.id AND h.not_seen_since IS NULL) AS nodes,
                    (SELECT COUNT(*) FROM virt_vms v WHERE v.cluster_id = cl.id AND v.not_seen_since IS NULL) AS vms
                FROM virt_clusters cl JOIN virt_connections c ON c.id = cl.connection_id WHERE cl.connection_id IN ($in) ORDER BY cl.name")->fetchAll(PDO::FETCH_ASSOC);
            $hyperv['hosts'] = $conn->query("SELECT h.id, h.name, h.node_state, h.identity_kind, h.os_version, h.not_seen_since, h.not_confirmed_since,
                    cl.name AS cluster_name, c.name AS conn_name,
                    (SELECT COUNT(*) FROM virt_vms v WHERE v.host_id = h.id AND v.not_seen_since IS NULL) AS vms
                FROM virt_hosts h JOIN virt_connections c ON c.id = h.connection_id LEFT JOIN virt_clusters cl ON cl.id = h.cluster_id
                WHERE h.connection_id IN ($in) ORDER BY cl.name IS NULL, cl.name, h.name")->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    usort($servers, function ($a, $b) { return strcasecmp((string)$a['name'], (string)$b['name']); });

    $estate = serversEstate($conn, $servers, $hyperv);

    $totalVMs = count($servers);
    $activeVMs = 0;
    $offlineVMs = 0;
    $totalMemoryGB = 0;
    $totalCPU = 0;
    $totalDiskGB = 0;

    foreach ($servers as $s) {
        if ($s['power_state'] === 'active') {
            $activeVMs++;
        } else {
            $offlineVMs++;
        }
        $totalMemoryGB += (float)$s['memory_gb'];
        $totalCPU += (int)$s['num_cpu'];
        $totalDiskGB += (float)$s['hard_disk_size_gb'];
    }

    $lastSynced = null;
    if ($totalVMs > 0) {
        $lastSynced = $servers[0]['last_synced'];
    }

    echo json_encode([
        'success' => true,
        'servers' => $servers,
        'hyperv' => $hyperv,
        'estate' => $estate['groups'],
        'summary' => [
            'total_vms' => $totalVMs,
            'active_vms' => $activeVMs,
            'offline_vms' => $offlineVMs,
            'total_memory_gb' => round($totalMemoryGB, 1),
            'total_cpu' => $totalCPU,
            'total_disk_gb' => round($totalDiskGB, 1),
            'host_count' => $estate['host_count'],
            'cluster_count' => $estate['cluster_count'],
            'last_synced' => $lastSynced
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * The physical side of every source, for the Hosts and Clusters tiles and the tree they
 * open: one group per source connection, its clusters with their hosts, standalone hosts,
 * and VM counts per host taken from the VM rows above (so a filter by host finds the same
 * VMs the count says).
 *
 * Hyper-V hosts and clusters are its own records (a host or cluster not seen is listed but
 * not counted); Proxmox nodes are proxmox_nodes; vCenter hosts and clusters are the
 * distinct names on its VMs, the only place its sync records them. Cloud Director never
 * shows its hosts to a tenant, so its virtual data centres are listed and nothing counted -
 * an org VDC is not a cluster.
 *
 * @param array $servers  the VM rows built above, every source
 * @param array $hyperv   the Hyper-V block built above (already scoped to the analyst's companies)
 * @return array{groups: array, host_count: int, cluster_count: int}
 */
function serversEstate(PDO $conn, array $servers, array $hyperv): array
{
    // VMs per source group and host (or VDC), counting only VMs still seen.
    $per = [];
    foreach ($servers as $s) {
        if (!empty($s['not_seen_since'])) { continue; }
        $k = $s['source_name'] . '|' . ($s['source'] === 'vcloud' ? $s['cluster'] : $s['host']);
        $per[$k] ??= ['vms' => 0, 'running' => 0];
        $per[$k]['vms']++;
        if ($s['power_state'] === 'active') { $per[$k]['running']++; }
    }
    $host = function (string $group, string $name, array $extra = []) use ($per): array {
        return ['name' => $name, 'vms' => $per[$group . '|' . $name]['vms'] ?? 0, 'running' => $per[$group . '|' . $name]['running'] ?? 0] + $extra;
    };
    $group = function (string $source, string $label, array $byCluster, array $clusterExtra = []): array {
        $g = ['source' => $source, 'label' => $label, 'clusters' => [], 'standalone' => []];
        ksort($byCluster, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($byCluster as $cl => $hosts) {
            usort($hosts, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            if ($cl === '') { $g['standalone'] = $hosts; continue; }
            $g['clusters'][] = ['name' => (string)$cl, 'hosts' => $hosts] + ($clusterExtra[$cl] ?? []);
        }
        return $g;
    };
    $groups = [];

    // Hyper-V, one group per connection.
    $hvByConn = [];
    foreach ($hyperv['hosts'] as $h) {
        $label = 'Hyper-V · ' . $h['conn_name'];
        $hvByConn[$label][(string)($h['cluster_name'] ?? '')][] = $host($label, (string)$h['name'], [
            'state' => $h['node_state'], 'identity_kind' => $h['identity_kind'],
            'not_seen_since' => $h['not_seen_since'], 'not_confirmed_since' => $h['not_confirmed_since']]);
    }
    $hvClusters = [];
    foreach ($hyperv['clusters'] as $c) {
        $label = 'Hyper-V · ' . $c['conn_name'];
        $hvByConn[$label][(string)$c['name']] ??= [];
        $hvClusters[$label][(string)$c['name']] = ['quorum' => $c['quorum_type'], 'witness' => $c['witness'],
            'not_seen_since' => $c['not_seen_since'], 'not_confirmed_since' => $c['not_confirmed_since']];
    }
    foreach ($hvByConn as $label => $byCluster) { $groups[] = $group('hyperv', $label, $byCluster, $hvClusters[$label] ?? []); }

    // Proxmox, one group per server: its nodes, or the node names on its VMs if it has none recorded.
    $pxConns = $conn->query('SELECT id, name FROM proxmox_connections ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pxNodes = $conn->query('SELECT connection_id, node_name, cluster_name, status FROM proxmox_nodes')->fetchAll(PDO::FETCH_ASSOC);
    $pxVmNodes = $conn->query('SELECT DISTINCT connection_id, node_name, cluster_name FROM proxmox_vms WHERE node_name IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pxConns as $id => $name) {
        $label = 'Proxmox · ' . $name;
        $rows = array_filter($pxNodes, fn($n) => (int)$n['connection_id'] === (int)$id) ?: array_filter($pxVmNodes, fn($n) => (int)$n['connection_id'] === (int)$id);
        if (!$rows) { continue; }
        $byCluster = [];
        foreach ($rows as $n) { $byCluster[(string)($n['cluster_name'] ?? '')][] = $host($label, (string)$n['node_name'], ['state' => $n['status'] ?? null]); }
        $groups[] = $group('proxmox', $label, $byCluster);
    }

    // vCenter: the hosts and clusters named on its VMs.
    $vcByCluster = [];
    $seen = [];
    foreach ($servers as $s) {
        if ($s['source'] !== 'vcenter' || $s['host'] === '' || $s['host'] === null || isset($seen[$s['host']])) { continue; }
        $seen[$s['host']] = true;
        $vcByCluster[(string)$s['cluster']][] = $host('vCenter', (string)$s['host']);
    }
    if ($vcByCluster) { $groups[] = $group('vcenter', 'vCenter', $vcByCluster); }

    // Cloud Director: virtual data centres only.
    $vdcs = [];
    foreach ($servers as $s) {
        if ($s['source'] !== 'vcloud') { continue; }
        $vdcs[$s['source_name']][(string)$s['cluster']] = true;
    }
    foreach ($vdcs as $label => $names) {
        $names = array_keys($names);
        natcasesort($names);
        $groups[] = ['source' => 'vcloud', 'label' => $label, 'clusters' => [], 'standalone' => [], 'hosts_hidden' => true,
            'vdcs' => array_map(fn($n) => $host($label, $n), array_values($names))];
    }

    $hosts = 0; $clusters = 0;
    foreach ($groups as $g) {
        foreach ($g['clusters'] as $c) {
            if (empty($c['not_seen_since'])) { $clusters++; }
            foreach ($c['hosts'] as $h) { if (empty($h['not_seen_since'])) { $hosts++; } }
        }
        foreach ($g['standalone'] as $h) { if (empty($h['not_seen_since'])) { $hosts++; } }
    }
    return ['groups' => $groups, 'host_count' => $hosts, 'cluster_count' => $clusters];
}
?>
