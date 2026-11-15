<?php
/**
 * Demo data: Virtualisation - System -> Demo Data (GH #199, #201).
 *
 * Unlike the other demo modules this is not a JSON file. A Hyper-V estate only means
 * anything once it has gone through the real ingest (identity, placements, a review, "not
 * seen"), the CMDB sync (source-bound CIs and the relationships it owns) and the asset <->
 * CI link rules, so it is built by calling those services, the way a collector and a person
 * would. Beside it go a Proxmox cluster, a Cloud Director organisation and a few vCenter
 * VMs, so Assets -> Servers shows every source at once.
 *
 * Removal finds its own rows and nothing else:
 *   - is_demo = 1 on the connection, link and VM rows it writes;
 *   - DEMO_VIRT_MARK as the description of the asset types, CMDB classes and relationship
 *     types it creates, because the Assets and CMDB demo modules also set is_demo on
 *     those tables and must not be swept up (nor sweep this up - see demoVirtRemove()).
 * Everything inside a demo class goes with it, including CIs a person made there while
 * trying it out, and so does every CI bound to the demo Hyper-V connection.
 *
 * @wiki Hyper-V-Discovery
 */
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/services/virtual_estate.php';
require_once __DIR__ . '/services/virtual_estate_cmdb.php';
require_once __DIR__ . '/services/asset_ci_links.php';
require_once __DIR__ . '/services/asset_review.php';
require_once __DIR__ . '/services/cmdb.php';

const DEMO_VIRT_MARK = 'Virtualisation demo data (System -> Demo Data).';
const DEMO_VIRT_CONNECTION = 'Demo Hyper-V estate';

/** A stable, made-up UUID for a demo object, so a re-import gives the same identities. */
function demoVirtUuid(string $seed): string
{
    $h = md5('freeitsm-demo:' . $seed);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/**
 * One Hyper-V report of the demo estate, as scripts/Invoke-HyperVInventory.ps1 would send it.
 *
 * @param array $vms  name => [vm id, host, highly available, state, cpu, memory GB, os, address]
 */
function demoVirtReport(int $observedAt, array $vms): array
{
    $cl = demoVirtUuid('cluster:DEMO-CL01');
    $hosts = [
        'DEMO-HV01' => [demoVirtUuid('host:HV01'), $cl],
        'DEMO-HV02' => [demoVirtUuid('host:HV02'), $cl],
        // An all-zero hardware UUID: the host falls back to its name and says "Name only".
        'DEMO-HV03' => ['00000000-0000-0000-0000-000000000000', null],
    ];
    $sec = [
        'clusters' => ['complete' => true, 'items' => [['id' => $cl, 'name' => 'DEMO-CL01', 'quorum_type' => 'NodeAndFileShareMajority', 'witness' => '\\\\DEMO-FS01\\witness']]],
        'hosts' => ['complete' => true, 'items' => []],
        'cluster_roles' => ['complete' => true, 'items' => [
            ['id' => demoVirtUuid('role:FS'), 'name' => 'DEMO-FS01', 'cluster_id' => $cl, 'role_type' => 'FileServer', 'owner_host' => 'DEMO-HV02', 'state' => 'Online', 'preferred_owners' => []],
        ]],
        'vms' => ['complete' => true, 'items' => []],
        'vm_disks' => ['complete' => true, 'items' => []],
        'vm_networks' => ['complete' => true, 'items' => []],
    ];
    foreach ($hosts as $hn => [$u, $c]) {
        $sec['hosts']['items'][] = ['id' => $u, 'name' => $hn, 'cluster_id' => $c, 'node_state' => $c ? 'Up' : null,
            'os_version' => 'Windows Server 2022 Datacenter', 'cpu_count' => $c ? 64 : 24, 'memory_bytes' => ($c ? 512 : 128) * 1024 ** 3];
    }
    $clusterNodes = ['DEMO-HV01', 'DEMO-HV02'];
    foreach ($vms as $vn => [$id, $host, $ha, $state, $cpu, $gb, $os, $ip]) {
        $roleId = $ha ? demoVirtUuid("role:$vn") : null;
        if ($ha) {
            $sec['cluster_roles']['items'][] = ['id' => $roleId, 'name' => $vn, 'cluster_id' => $cl, 'role_type' => 'VirtualMachine',
                'owner_host' => $host, 'state' => $state === 'Running' ? 'Online' : 'Offline', 'preferred_owners' => []];
        }
        $sec['vms']['items'][] = ['id' => $id, 'name' => $vn, 'host' => $host, 'state' => $state, 'is_clustered' => $ha, 'cluster_role_id' => $roleId,
            'possible_owners' => $ha ? $clusterNodes : [], 'generation' => 2, 'cpu_count' => $cpu, 'memory_bytes' => $gb * 1024 ** 3,
            'guest_addresses' => $ip ? [$ip] : [], 'os_name' => $os];
        $sec['vm_disks']['items'][] = ['vm_id' => $id, 'id' => 'scsi-0-0', 'path' => ($hosts[$host][1] ? 'C:\\ClusterStorage\\Volume1\\' : 'D:\\Hyper-V\\') . $vn . '\\disk0.vhdx',
            'size_bytes' => 127 * 1024 ** 3, 'used_bytes' => 38 * 1024 ** 3, 'controller' => 'SCSI 0:0'];
        $sec['vm_networks']['items'][] = ['vm_id' => $id, 'id' => 'nic-0', 'name' => 'Network Adapter', 'switch_name' => 'vSwitch-Prod',
            'mac_address' => strtoupper(substr(md5($vn), 0, 12)), 'ip_addresses' => $ip ? [$ip] : [], 'vlan_id' => 20];
    }
    return ['schema_version' => 1, 'observed_at' => gmdate('Y-m-d\TH:i:s\Z', $observedAt),
        'scope' => [['kind' => 'cluster', 'id' => $cl, 'name' => 'DEMO-CL01'], ['kind' => 'host', 'id' => $hosts['DEMO-HV03'][0], 'name' => 'DEMO-HV03']],
        'collector' => ['name' => 'Invoke-HyperVInventory', 'version' => 'demo', 'powershell' => '5.1', 'host' => 'DEMO-MGMT01'],
        'unreadable' => [], 'sections' => $sec];
}

/** What is in place now: a count per part, all zero when the demo is not imported. */
function demoVirtStatus(PDO $conn): array
{
    $n = function (string $sql) use ($conn): int { try { return (int)$conn->query($sql)->fetchColumn(); } catch (Throwable) { return 0; } };
    $mark = $conn->quote(DEMO_VIRT_MARK);
    $out = [
        'hyperv_vms' => $n("SELECT COUNT(*) FROM virt_vms v JOIN virt_connections c ON c.id = v.connection_id WHERE c.is_demo = 1"),
        'proxmox_vms' => $n('SELECT COUNT(*) FROM proxmox_vms WHERE is_demo = 1'),
        'vcloud_vms' => $n('SELECT COUNT(*) FROM vcloud_vms WHERE is_demo = 1'),
        'vcenter_vms' => $n('SELECT COUNT(*) FROM servers WHERE is_demo = 1'),
        'cis' => $n("SELECT COUNT(*) FROM cmdb_objects o JOIN cmdb_classes k ON k.id = o.class_id WHERE k.is_demo = 1 AND k.description = $mark"),
        'assets' => $n("SELECT COUNT(*) FROM assets a JOIN asset_types t ON t.id = a.asset_type_id WHERE a.is_demo = 1 AND t.is_demo = 1 AND t.description = $mark"),
    ];
    $out['total'] = array_sum($out);
    return $out;
}

/**
 * Imports the demo, replacing any earlier copy. Not one transaction - the services it goes
 * through commit their own - so a failure part way removes what was made and rethrows.
 *
 * @return array the demoVirtStatus() counts afterwards
 */
function demoVirtImport(PDO $conn, ActorContext $ctx): array
{
    if (!VirtualEstateCmdbService::ready($conn) || !AssetCiLinkService::ready($conn)) {
        throw new RuntimeException('Run Database Verification first (System -> Database Verification).');
    }
    demoVirtRemove($conn, $ctx);
    try {
        demoVirtBuild($conn, $ctx);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        try { demoVirtRemove($conn, $ctx); } catch (Throwable) { /* report the first error */ }
        throw $e;
    }
    return demoVirtStatus($conn);
}

/** The building, in the order a real installation would get there. */
function demoVirtBuild(PDO $conn, ActorContext $ctx): void
{
    $now = time();
    $access = ['assets_read' => true, 'cmdb_read' => true, 'assets_write' => true, 'cmdb_write' => true];
    $q = fn(string $sql, array $p = []) => (function () use ($conn, $sql, $p) { $st = $conn->prepare($sql); $st->execute($p); return $st; })();

    // ---- Hyper-V: a two-node cluster and a standalone host, three reports an hour apart ----
    $q("INSERT INTO virt_connections (name, source_kind, tenant_id, expected_interval_minutes, grace_minutes, is_active, created_by_id, created_datetime, is_demo)
        VALUES (?, 'hyperv', NULL, 60, 30, 1, ?, UTC_TIMESTAMP(), 1)", [DEMO_VIRT_CONNECTION, $ctx->actorId ?: null]);
    $cid = (int)$conn->lastInsertId();

    $vms = [
        'DEMO-SQL01'   => [demoVirtUuid('vm:SQL01'), 'DEMO-HV01', true, 'Running', 8, 32, 'Windows Server 2022 Standard', '10.20.0.21'],
        'DEMO-WEB01'   => [demoVirtUuid('vm:WEB01'), 'DEMO-HV01', true, 'Running', 4, 8, 'Windows Server 2022 Standard', '10.20.0.31'],
        'DEMO-WEB02'   => [demoVirtUuid('vm:WEB02'), 'DEMO-HV01', true, 'Running', 4, 8, 'Windows Server 2022 Standard', '10.20.0.32'],
        // Runs on a node but is NOT a cluster role: no failover, so it is affected when its node fails.
        'DEMO-APP01'   => [demoVirtUuid('vm:APP01'), 'DEMO-HV02', false, 'Running', 4, 16, 'Windows Server 2019 Standard', '10.20.0.41'],
        'DEMO-DC01'    => [demoVirtUuid('vm:DC01'), 'DEMO-HV03', false, 'Running', 2, 4, 'Windows Server 2022 Standard', '10.20.0.10'],
        'DEMO-PRINT01' => [demoVirtUuid('vm:PRINT01'), 'DEMO-HV03', false, 'Off', 2, 4, 'Windows Server 2019 Standard', null],
        'DEMO-OLD01'   => [demoVirtUuid('vm:OLD01'), 'DEMO-HV03', false, 'Off', 2, 2, 'Windows Server 2012 R2', null],
        'DEMO-BUILD01' => [demoVirtUuid('vm:BUILD01'), 'DEMO-HV02', false, 'Running', 8, 16, 'Windows 11 Enterprise', '10.20.0.51'],
    ];
    VirtualEstateService::submit($conn, $cid, demoVirtReport($now - 7200, $vms));
    // An hour later WEB02 has been live-migrated to the other node.
    $vms['DEMO-WEB02'][1] = 'DEMO-HV02';
    VirtualEstateService::submit($conn, $cid, demoVirtReport($now - 3600, $vms));

    // ---- The CMDB mapping: the suggestion, marked as demo where it made something new ----
    $maxClass = (int)$conn->query('SELECT COALESCE(MAX(id), 0) FROM cmdb_classes')->fetchColumn();
    $maxType = (int)$conn->query('SELECT COALESCE(MAX(id), 0) FROM cmdb_relationship_types')->fetchColumn();
    $s = VirtualEstateCmdbService::suggest($conn);
    $q("INSERT INTO cmdb_classes (class_key, name, description, display_order, is_active, created_datetime, updated_datetime)
        VALUES ('demo_business_app', 'Business application (demo)', ?, 0, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [DEMO_VIRT_MARK]);
    $appClass = (int)$conn->lastInsertId();
    $dependsOn = (int)($q("SELECT id FROM cmdb_relationship_types WHERE LOWER(verb) = 'depends on' AND impact_direction = 'to_from' AND COALESCE(is_active, 1) = 1 LIMIT 1")->fetchColumn());
    if (!$dependsOn) {
        $q("INSERT INTO cmdb_relationship_types (verb, inverse_verb, description, impact_direction, redundancy, display_order, is_active, created_datetime)
            VALUES ('depends on', 'is depended on by', ?, 'to_from', 'none', 0, 1, UTC_TIMESTAMP())", [DEMO_VIRT_MARK]);
        $dependsOn = (int)$conn->lastInsertId();
    }
    $q("UPDATE cmdb_classes SET is_demo = 1, description = ? WHERE id > ?", [DEMO_VIRT_MARK, $maxClass]);
    $q("UPDATE cmdb_relationship_types SET is_demo = 1, description = ? WHERE id > ?", [DEMO_VIRT_MARK, $maxType]);
    $vmClass = (int)$s['vm_class_id'];

    // A CI someone made by hand with the same name as a discovered VM: a possible match,
    // never bound by name - it waits in the CMDB panel for a person.
    $dcManual = CmdbService::saveObject($conn, $ctx, ['name' => 'DEMO-DC01', 'class_id' => $vmClass])['id'];
    VirtualEstateCmdbService::saveMapping($conn, $ctx, $cid, $s + ['enabled' => 1, 'sync_names' => 1]);
    VirtualEstateCmdbService::sync($conn, $cid);

    // ---- The latest report: OLD01 has gone, BUILD01 came back with a new identity ----
    unset($vms['DEMO-OLD01']);
    $vms['DEMO-BUILD01'][0] = demoVirtUuid('vm:BUILD01-reimported');
    VirtualEstateService::submit($conn, $cid, demoVirtReport($now - 300, $vms));   // syncs the CMDB itself

    $ciOf = function (string $kind, string $name) use ($conn, $cid): int {
        $table = ['vm' => 'virt_vms', 'host' => 'virt_hosts', 'cluster' => 'virt_clusters'][$kind];
        $st = $conn->prepare("SELECT s.cmdb_object_id FROM cmdb_object_sources s JOIN $table v ON v.id = s.virt_object_id
            WHERE s.connection_id = ? AND s.object_kind = ? AND s.ended_at IS NULL AND v.name = ? ORDER BY s.id DESC LIMIT 1");
        $st->execute([$cid, $kind, $name]);
        return (int)$st->fetchColumn();
    };

    // ---- Business applications on top, so a failed host has something to break ----
    $app = fn(string $name) => CmdbService::saveObject($conn, $ctx, ['name' => $name, 'class_id' => $appClass])['id'];
    $rel = fn(int $from, int $to) => $to ? CmdbService::createRelationship($conn, $ctx, $from, ['to_object_id' => $to, 'relationship_type_id' => $dependsOn]) : null;
    $payroll = $app('Payroll (demo)');
    $rel($payroll, $ciOf('vm', 'DEMO-SQL01'));
    $rel($payroll, $ciOf('vm', 'DEMO-APP01'));
    $intranet = $app('Intranet (demo)');
    $rel($intranet, $ciOf('vm', 'DEMO-WEB01'));
    $rel($intranet, $ciOf('vm', 'DEMO-WEB02'));
    $rel($intranet, $dcManual);
    $oldWebCi = CmdbService::saveObject($conn, $ctx, ['name' => 'DEMO-WEB01 (old record)', 'class_id' => $vmClass])['id'];

    // Everything in the demo classes, and everything the sync bound, is demo data.
    $mark = $conn->quote(DEMO_VIRT_MARK);
    $conn->exec("UPDATE cmdb_objects o JOIN cmdb_classes k ON k.id = o.class_id SET o.is_demo = 1 WHERE k.is_demo = 1 AND k.description = $mark");
    $q('UPDATE cmdb_objects o JOIN cmdb_object_sources s ON s.cmdb_object_id = o.id SET o.is_demo = 1 WHERE s.connection_id = ?', [$cid]);
    $conn->exec('UPDATE cmdb_object_properties p JOIN cmdb_objects o ON o.id = p.object_id SET p.is_demo = 1 WHERE o.is_demo = 1');
    $conn->exec('UPDATE cmdb_object_relationships r JOIN cmdb_objects o ON o.id = r.from_object_id SET r.is_demo = 1 WHERE o.is_demo = 1');

    // ---- Assets, two types mapped to classes for "Create linked CI" ----
    $type = function (string $name, int $classId) use ($q, $conn): int {
        $q("INSERT INTO asset_types (name, description, is_active, display_order, cmdb_class_id, tenant_id, created_datetime, is_demo)
            VALUES (?, ?, 1, 90, ?, NULL, UTC_TIMESTAMP(), 1)", [$name, DEMO_VIRT_MARK, $classId]);
        return (int)$conn->lastInsertId();
    };
    $vmType = $type('Virtual server (demo)', $vmClass);
    $hostType = $type('Hyper-V host (demo)', (int)$s['host_class_id']);
    $status = $conn->query('SELECT id FROM asset_status_types WHERE is_active = 1 ORDER BY display_order, id LIMIT 1')->fetchColumn() ?: null;
    $asset = function (string $name, int $type, string $make, string $model, ?string $serial, string $os, int $gb) use ($q, $conn, $status): int {
        $q("INSERT INTO assets (hostname, manufacturer, model, memory, service_tag, operating_system, asset_type_id, asset_status_id,
                first_seen, last_seen, tenant_id, is_demo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() - INTERVAL 200 DAY, UTC_TIMESTAMP() - INTERVAL 1 HOUR, NULL, 1)",
            [$name, $make, $model, $gb * 1024 ** 3, $serial, $os, $type, $status]);
        return (int)$conn->lastInsertId();
    };
    $link = fn(int $assetId, int $ciId) => $ciId ? AssetCiLinkService::link($conn, $ctx, $access, $assetId, $ciId, false, [], 'ui') : null;
    $link($asset('DEMO-HV01', $hostType, 'Dell Inc.', 'PowerEdge R760', 'DEMOSV1', 'Windows Server 2022 Datacenter', 512), $ciOf('host', 'DEMO-HV01'));
    $link($asset('DEMO-HV02', $hostType, 'Dell Inc.', 'PowerEdge R760', 'DEMOSV2', 'Windows Server 2022 Datacenter', 512), $ciOf('host', 'DEMO-HV02'));
    $link($asset('DEMO-SQL01', $vmType, 'Microsoft Corporation', 'Virtual Machine', null, 'Windows Server 2022 Standard', 32), $ciOf('vm', 'DEMO-SQL01'));
    $web = $asset('DEMO-WEB01', $vmType, 'Microsoft Corporation', 'Virtual Machine', 'DEMO-VM-0031', 'Windows Server 2022 Standard', 8);
    $link($web, $ciOf('vm', 'DEMO-WEB01'));
    // The same machine recorded twice (same serial), each linked to a different CI: the merge
    // window has to ask which link ends.
    $webOld = $asset('DEMO-WEB01-OLD', $vmType, 'Microsoft Corporation', 'Virtual Machine', 'DEMO-VM-0031', 'Windows Server 2019 Standard', 8);
    $link($webOld, $oldWebCi);
    // Not linked: "Create linked CI" offers the discovered CI of the same name instead.
    $asset('DEMO-APP01', $vmType, 'Microsoft Corporation', 'Virtual Machine', null, 'Windows Server 2019 Standard', 16);
    // Not linked, no CI anywhere: "Create linked CI" makes one.
    $asset('DEMO-NEW01', $vmType, 'Microsoft Corporation', 'Virtual Machine', null, 'Windows Server 2022 Standard', 8);
    $q('UPDATE asset_ci_links l JOIN assets a ON a.id = l.asset_id SET l.is_demo = 1 WHERE a.is_demo = 1 AND a.asset_type_id IN (?, ?)', [$vmType, $hostType]);
    if (AssetReviewService::ready($conn)) {
        AssetReviewService::record($conn, getDefaultTenantId($conn), 'shared_serial', "serial:demo-vm-0031:$web:$webOld", $web, $webOld,
            null, json_encode(['serial' => 'DEMO-VM-0031']), false);
    }

    // ---- Proxmox: a three-node cluster, VMs and containers. Inactive, so nothing syncs it ----
    $q("INSERT INTO proxmox_connections (name, host, username, verify_ssl, is_active, sync_interval_minutes, last_sync_datetime, last_sync_status, last_sync_message, is_demo)
        VALUES ('Demo Proxmox cluster', 'pve.demo.invalid', 'demo@pve', 1, 0, 60, UTC_TIMESTAMP() - INTERVAL 20 MINUTE, 'success', 'Demo data - not a real cluster.', 1)");
    $px = (int)$conn->lastInsertId();
    // Nodes as the sync records them; they go with the connection (ON DELETE CASCADE).
    foreach (['pve1', 'pve2', 'pve3'] as $node) {
        $q("INSERT INTO proxmox_nodes (connection_id, node_name, cluster_name, status, cpu_cores, memory_mb, last_seen_datetime)
            VALUES (?, ?, 'demo-pve', 'online', 32, 262144, UTC_TIMESTAMP() - INTERVAL 20 MINUTE)", [$px, $node]);
    }
    $pxVms = [
        [101, 'qemu', 'pve1', 'demo-k8s-cp01', 'running', 4, 8192, 64, 'l26', '10.30.0.11'],
        [102, 'qemu', 'pve2', 'demo-k8s-w01', 'running', 8, 16384, 128, 'l26', '10.30.0.21'],
        [103, 'qemu', 'pve3', 'demo-k8s-w02', 'running', 8, 16384, 128, 'l26', '10.30.0.22'],
        [110, 'qemu', 'pve1', 'demo-gitlab', 'running', 4, 12288, 250, 'l26', '10.30.0.31'],
        [120, 'qemu', 'pve2', 'demo-win-jump', 'stopped', 2, 4096, 80, 'win11', null],
        [200, 'lxc', 'pve3', 'demo-dns01', 'running', 1, 512, 8, 'debian', '10.30.0.53'],
        [201, 'lxc', 'pve1', 'demo-proxy', 'running', 2, 1024, 16, 'alpine', '10.30.0.80'],
    ];
    foreach ($pxVms as [$vmid, $kind, $node, $name, $st, $cpu, $mb, $disk, $os, $ip]) {
        $q("INSERT INTO proxmox_vms (connection_id, vmid, vm_type, node_name, cluster_name, name, status, vcpus, memory_mb, disk_gb, os_type, ip_addresses, last_seen_datetime, is_demo)
            VALUES (?, ?, ?, ?, 'demo-pve', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() - INTERVAL 20 MINUTE, 1)", [$px, $vmid, $kind, $node, $name, $st, $cpu, $mb, $disk, $os, $ip]);
    }

    // ---- VMware Cloud Director: one organisation, two VDCs. Inactive too ----
    $q("INSERT INTO vcloud_connections (name, host, org, username, verify_ssl, is_active, sync_interval_minutes, last_sync_datetime, last_sync_status, last_sync_message, is_demo)
        VALUES ('Demo Cloud Director', 'vcd.demo.invalid', 'DemoOrg', 'demo', 1, 0, 60, UTC_TIMESTAMP() - INTERVAL 35 MINUTE, 'success', 'Demo data - not a real organisation.', 1)");
    $vc = (int)$conn->lastInsertId();
    foreach ([
        ['demo-crm-app01', 'DemoOrg-VDC-Prod', 'CRM', 'running', 4, 8192, 100, '172.16.10.11'],
        ['demo-crm-db01', 'DemoOrg-VDC-Prod', 'CRM', 'running', 8, 32768, 500, '172.16.10.12'],
        ['demo-test-web', 'DemoOrg-VDC-Dev', 'Test', 'stopped', 2, 4096, 40, null],
    ] as $i => [$name, $vdc, $vapp, $st, $cpu, $mb, $disk, $ip]) {
        $q("INSERT INTO vcloud_vms (connection_id, vm_uuid, name, org_vdc, vapp_name, status, vcpus, memory_mb, disk_gb, ip_addresses, last_seen_datetime, is_demo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() - INTERVAL 35 MINUTE, 1)", [$vc, demoVirtUuid("vcd:$i"), $name, $vdc, $vapp, $st, $cpu, $mb, $disk, $ip]);
    }

    // ---- vCenter: a few VMs on a vSAN cluster, in the table its sync fills ----
    foreach ([
        ['DEMO-EXCH01', 'active', 16, 4, '10.40.0.25', 400, 'esx01.demo.invalid', 'Microsoft Windows Server 2019 (64-bit)'],
        ['DEMO-FILE01', 'active', 8, 2, '10.40.0.26', 2000, 'esx02.demo.invalid', 'Microsoft Windows Server 2022 (64-bit)'],
        ['DEMO-RDS01', 'active', 32, 8, '10.40.0.27', 200, 'esx01.demo.invalid', 'Microsoft Windows Server 2022 (64-bit)'],
        ['demo-monitor', 'active', 4, 2, '10.40.0.40', 60, 'esx03.demo.invalid', 'Ubuntu Linux (64-bit)'],
        ['DEMO-LEGACY', 'offline', 4, 2, '', 80, 'esx03.demo.invalid', 'Microsoft Windows Server 2012 (64-bit)'],
    ] as $i => [$name, $power, $gb, $cpu, $ip, $disk, $host, $os]) {
        $raw = ['guest_identity' => ['full_name' => ['default_message' => $os], 'host_name' => strtolower($name) . '.demo.invalid', 'ip_address' => $ip,
            'family' => str_contains($os, 'Windows') ? 'WINDOWS' : 'LINUX', 'tools_version' => '12352'],
            'vm_detail' => ['disks' => ['2000' => ['label' => 'Hard disk 1', 'capacity' => $disk * 1024 ** 3, 'type' => 'SCSI', 'backing' => ['type' => 'VMDK_FILE']]]]];
        $q("INSERT INTO servers (vm_id, name, power_state, memory_gb, num_cpu, ip_address, hard_disk_size_gb, host, cluster, guest_os, raw_data, last_synced, is_demo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'DEMO-VSAN', ?, ?, UTC_TIMESTAMP() - INTERVAL 10 MINUTE, 1)",
            ['vm-demo-' . (1001 + $i), $name, $power, $gb, $cpu, $ip, $disk, $host, $os, json_encode($raw)]);
    }
}

/**
 * Removes the demo and only the demo. Safe to run again after a failure: each step looks
 * for what is left.
 */
function demoVirtRemove(PDO $conn, ActorContext $ctx): void
{
    $ids = fn(string $sql) => array_map('intval', $conn->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    $in = fn(array $a) => $a ? implode(',', $a) : '0';
    $mark = $conn->quote(DEMO_VIRT_MARK);

    $types = $ids("SELECT id FROM asset_types WHERE is_demo = 1 AND description = $mark");
    $assets = $ids('SELECT id FROM assets WHERE is_demo = 1 AND asset_type_id IN (' . $in($types) . ')');
    $classes = $ids("SELECT id FROM cmdb_classes WHERE is_demo = 1 AND description = $mark");
    $conns = $ids('SELECT id FROM virt_connections WHERE is_demo = 1');
    $cis = $ids('SELECT id FROM cmdb_objects WHERE class_id IN (' . $in($classes) . ')
        UNION SELECT cmdb_object_id FROM cmdb_object_sources WHERE connection_id IN (' . $in($conns) . ')');

    $conn->exec('DELETE FROM asset_ci_links WHERE is_demo = 1 OR asset_id IN (' . $in($assets) . ') OR cmdb_object_id IN (' . $in($cis) . ')');

    foreach ($conns as $cid) {
        $conn->beginTransaction();
        $key = $conn->query("SELECT api_key_id FROM virt_connections WHERE id = $cid")->fetchColumn();
        if ($key) { $conn->prepare('UPDATE api_keys SET active = 0 WHERE id = ?')->execute([(int)$key]); }
        $conn->prepare('DELETE FROM job_runs WHERE job_key = ?')->execute([VirtualEstateService::jobKey($cid)]);
        VirtualEstateCmdbService::connectionDeleted($conn, $ctx, $cid);
        $conn->exec("DELETE FROM cmdb_object_sources WHERE connection_id = $cid");   // demo: no history to keep
        if (VirtualEstateService::storageReady($conn)) {
            $conn->exec("DELETE FROM virt_storage_volumes WHERE connection_id = $cid");   // #296: not left to the FK
        }
        $conn->exec("DELETE FROM virt_connections WHERE id = $cid");                 // its estate goes by cascade
        $conn->commit();
    }

    foreach ($cis as $ci) {
        if (!$conn->query("SELECT 1 FROM cmdb_objects WHERE id = $ci")->fetchColumn()) { continue; }   // went with a parent
        CmdbService::deleteObject($conn, $ctx, $ci);
    }
    $conn->exec('DELETE FROM cmdb_object_sources WHERE cmdb_object_id IN (' . $in($cis) . ')');

    // A relationship type still used by someone's own relationship is left in place.
    $conn->exec("DELETE t FROM cmdb_relationship_types t WHERE t.is_demo = 1 AND t.description = $mark
        AND NOT EXISTS (SELECT 1 FROM cmdb_object_relationships r WHERE r.relationship_type_id = t.id)");
    if ($classes) {
        $conn->exec('UPDATE asset_types SET cmdb_class_id = NULL WHERE cmdb_class_id IN (' . $in($classes) . ')');
        $conn->exec('DELETE o FROM cmdb_class_property_options o JOIN cmdb_class_properties p ON p.id = o.property_id WHERE p.class_id IN (' . $in($classes) . ')');
        $conn->exec('DELETE FROM cmdb_class_properties WHERE class_id IN (' . $in($classes) . ')');
        $conn->exec('DELETE FROM cmdb_classes WHERE id IN (' . $in($classes) . ')');
    }

    if ($assets) {
        $a = $in($assets);
        $conn->exec("DELETE FROM asset_review_items WHERE asset_id IN ($a) OR other_asset_id IN ($a)");
        $conn->exec("DELETE FROM asset_history WHERE asset_id IN ($a)");
        $conn->exec("DELETE FROM assets WHERE id IN ($a)");
    }
    if ($types) { $conn->exec('DELETE FROM asset_types WHERE id IN (' . $in($types) . ')'); }

    $conn->exec('DELETE FROM proxmox_vms WHERE is_demo = 1');
    $conn->exec('DELETE FROM proxmox_connections WHERE is_demo = 1');
    $conn->exec('DELETE FROM vcloud_vms WHERE is_demo = 1');
    $conn->exec('DELETE FROM vcloud_connections WHERE is_demo = 1');
    $conn->exec('DELETE FROM servers WHERE is_demo = 1');
}
