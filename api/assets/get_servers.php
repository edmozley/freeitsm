<?php
/**
 * API Endpoint: Get servers list from database
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, vm_id, name, power_state, memory_gb, num_cpu, ip_address, hard_disk_size_gb, host, cluster, guest_os, raw_data, DATE_FORMAT(last_synced, '%Y-%m-%d %H:%i:%s') as last_synced FROM servers ORDER BY name";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build summary stats - separate ESXi hosts from VMs
    $esxiHosts = [];
    $vmServers = [];
    foreach ($servers as $s) {
        if ($s['guest_os'] === 'VMware ESXi') {
            $esxiHosts[] = $s;
        } else {
            $vmServers[] = $s;
        }
    }

    $totalVMs = count($vmServers);
    $activeVMs = 0;
    $offlineVMs = 0;
    $totalMemoryGB = 0;
    $totalCPU = 0;
    $totalDiskGB = 0;
    $clusters = [];

    foreach ($vmServers as $s) {
        if ($s['power_state'] === 'active') {
            $activeVMs++;
        } else {
            $offlineVMs++;
        }
        $totalMemoryGB += (float)$s['memory_gb'];
        $totalCPU += (int)$s['num_cpu'];
        $totalDiskGB += (float)$s['hard_disk_size_gb'];
        if (!empty($s['cluster']) && !in_array($s['cluster'], $clusters)) {
            $clusters[] = $s['cluster'];
        }
    }

    $lastSynced = null;
    if ($totalVMs > 0) {
        $lastSynced = $servers[0]['last_synced'];
    }

    echo json_encode([
        'success' => true,
        'servers' => $servers,
        'summary' => [
            'total_vms' => $totalVMs,
            'active_vms' => $activeVMs,
            'offline_vms' => $offlineVMs,
            'total_memory_gb' => round($totalMemoryGB, 1),
            'total_cpu' => $totalCPU,
            'total_disk_gb' => round($totalDiskGB, 1),
            'host_count' => count($esxiHosts),
            'cluster_count' => count($clusters),
            'last_synced' => $lastSynced
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
