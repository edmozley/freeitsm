<?php
/**
 * API Endpoint: Sync VM data from vCenter REST API
 * Connects to vCenter, fetches all VMs with specs, saves to servers table
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

set_time_limit(300); // Allow up to 5 minutes for large environments

try {
    $conn = connectToDatabase();

    // Get vCenter settings from system_settings
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('vcenter_server', 'vcenter_user', 'vcenter_password')");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = decryptValue($row['setting_value']);
    }

    $vcServer = $settings['vcenter_server'] ?? '';
    $vcUser = $settings['vcenter_user'] ?? '';
    $vcPass = $settings['vcenter_password'] ?? '';

    if (empty($vcServer) || empty($vcUser) || empty($vcPass)) {
        echo json_encode(['success' => false, 'error' => 'vCenter settings not configured. Go to Settings to configure vCenter connection.']);
        exit;
    }

    $baseUrl = "https://{$vcServer}";

    // 1. Authenticate
    $sessionToken = vcenterAuth($baseUrl, $vcUser, $vcPass);

    // 2. Get hosts for name mapping + add as server records
    $hostMap = [];
    $hosts = [];
    try {
        $hosts = vcenterGet($baseUrl, '/api/vcenter/host', $sessionToken);
        foreach ($hosts as $h) {
            $hostMap[$h['host']] = $h['name'] ?? '';
        }
    } catch (Exception $e) {
        // Continue without host info
    }

    // 3. Get clusters for name mapping
    $clusterMap = [];
    try {
        $clusters = vcenterGet($baseUrl, '/api/vcenter/cluster', $sessionToken);
        foreach ($clusters as $c) {
            $clusterMap[$c['cluster']] = $c['name'] ?? '';
        }
    } catch (Exception $e) {
        // Continue without cluster info
    }

    // 3b. Build VM-to-host map by querying VMs per host
    $vmHostMap = [];
    foreach ($hostMap as $hostId => $hostName) {
        try {
            $hostVms = vcenterGet($baseUrl, '/api/vcenter/vm?hosts=' . $hostId, $sessionToken);
            foreach ($hostVms as $hv) {
                $vmHostMap[$hv['vm']] = $hostName;
            }
        } catch (Exception $e) {
            // Continue
        }
    }

    // 3c. Build VM-to-cluster map by querying VMs per cluster
    $vmClusterMap = [];
    foreach ($clusterMap as $clusterId => $clusterName) {
        try {
            $clusterVms = vcenterGet($baseUrl, '/api/vcenter/vm?clusters=' . $clusterId, $sessionToken);
            foreach ($clusterVms as $cv) {
                $vmClusterMap[$cv['vm']] = $clusterName;
            }
        } catch (Exception $e) {
            // Continue
        }
    }

    // 4. Get VM list
    $vms = vcenterGet($baseUrl, '/api/vcenter/vm', $sessionToken);
    $serverData = [];

    // Add ESXi hosts as server records
    foreach ($hosts as $h) {
        $hostId = $h['host'] ?? '';
        $hostPower = (($h['power_state'] ?? '') === 'POWERED_ON') ? 'active' : 'offline';
        $serverData[] = [
            'vm_id' => $hostId,
            'name' => $h['name'] ?? '',
            'power_state' => $hostPower,
            'memory_gb' => 0,
            'num_cpu' => 0,
            'ip_address' => '',
            'hard_disk_size_gb' => 0,
            'host' => '',
            'cluster' => '',
            'guest_os' => 'VMware ESXi',
            'raw_data' => json_encode(['host_info' => $h], JSON_UNESCAPED_SLASHES)
        ];
    }

    foreach ($vms as $vm) {
        $vmId = $vm['vm'] ?? '';
        $vmName = $vm['name'] ?? '';
        $powerState = (($vm['power_state'] ?? '') === 'POWERED_ON') ? 'active' : 'offline';
        $memoryGB = round(($vm['memory_size_MiB'] ?? 0) / 1024, 2);
        $numCpu = $vm['cpu_count'] ?? 0;

        // Collect all raw API data for this VM
        $rawData = ['vm_list_entry' => $vm];

        // Get VM detail for disks and guest OS
        $totalDiskGB = 0;
        $hostName = $vmHostMap[$vmId] ?? '';
        $clusterName = $vmClusterMap[$vmId] ?? '';
        $guestOS = '';

        try {
            $detail = vcenterGet($baseUrl, '/api/vcenter/vm/' . $vmId, $sessionToken);
            $rawData['vm_detail'] = $detail;

            // Calculate total disk size (capacity in bytes)
            if (isset($detail['disks'])) {
                foreach ($detail['disks'] as $disk) {
                    $capacity = 0;
                    if (isset($disk['capacity'])) {
                        $capacity = $disk['capacity'];
                    } elseif (isset($disk['value']['capacity'])) {
                        $capacity = $disk['value']['capacity'];
                    }
                    $totalDiskGB += $capacity / (1024 * 1024 * 1024);
                }
            }

            $guestOS = $detail['guest_OS'] ?? '';
        } catch (Exception $e) {
            // Continue with basic info
        }

        // Get IP address and detailed OS from guest identity (requires VMware Tools)
        $ipAddress = '';
        if ($powerState === 'active') {
            try {
                $guest = vcenterGet($baseUrl, '/api/vcenter/vm/' . $vmId . '/guest/identity', $sessionToken);
                $rawData['guest_identity'] = $guest;
                $ipAddress = $guest['ip_address'] ?? '';
                // full_name.default_message is more accurate than guest_OS
                if (isset($guest['full_name']['default_message']) && $guest['full_name']['default_message'] !== '') {
                    $guestOS = $guest['full_name']['default_message'];
                }
            } catch (Exception $e) {
                // VMware Tools may not be running - keep guest_OS from VM detail
            }

            // Guest networking info
            try {
                $rawData['guest_networking'] = vcenterGet($baseUrl, '/api/vcenter/vm/' . $vmId . '/guest/networking/interfaces', $sessionToken);
            } catch (Exception $e) {}

            // Guest local filesystem
            try {
                $rawData['guest_local_filesystem'] = vcenterGet($baseUrl, '/api/vcenter/vm/' . $vmId . '/guest/local-filesystem', $sessionToken);
            } catch (Exception $e) {}
        }

        $serverData[] = [
            'vm_id' => $vmId,
            'name' => $vmName,
            'power_state' => $powerState,
            'memory_gb' => $memoryGB,
            'num_cpu' => $numCpu,
            'ip_address' => $ipAddress,
            'hard_disk_size_gb' => round($totalDiskGB, 2),
            'host' => $hostName,
            'cluster' => $clusterName,
            'guest_os' => $guestOS,
            'raw_data' => json_encode($rawData, JSON_UNESCAPED_SLASHES)
        ];
    }

    // 5. Save to database (upsert by vm_id)
    foreach ($serverData as $server) {
        $checkStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM servers WHERE vm_id = ?");
        $checkStmt->execute([$server['vm_id']]);
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

        if ($exists) {
            $stmt = $conn->prepare("UPDATE servers SET name = ?, power_state = ?, memory_gb = ?, num_cpu = ?, ip_address = ?, hard_disk_size_gb = ?, host = ?, cluster = ?, guest_os = ?, raw_data = ?, last_synced = UTC_TIMESTAMP() WHERE vm_id = ?");
            $stmt->execute([
                $server['name'], $server['power_state'], $server['memory_gb'],
                $server['num_cpu'], $server['ip_address'], $server['hard_disk_size_gb'],
                $server['host'], $server['cluster'], $server['guest_os'],
                $server['raw_data'],
                $server['vm_id']
            ]);
        } else {
            $stmt = $conn->prepare("INSERT INTO servers (vm_id, name, power_state, memory_gb, num_cpu, ip_address, hard_disk_size_gb, host, cluster, guest_os, raw_data, last_synced) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            $stmt->execute([
                $server['vm_id'], $server['name'], $server['power_state'],
                $server['memory_gb'], $server['num_cpu'], $server['ip_address'],
                $server['hard_disk_size_gb'], $server['host'], $server['cluster'],
                $server['guest_os'], $server['raw_data']
            ]);
        }
    }

    // Remove servers no longer in vCenter
    $currentVmIds = array_column($serverData, 'vm_id');
    if (!empty($currentVmIds)) {
        $placeholders = implode(',', array_fill(0, count($currentVmIds), '?'));
        $deleteStmt = $conn->prepare("DELETE FROM servers WHERE vm_id NOT IN ({$placeholders})");
        $deleteStmt->execute($currentVmIds);
    }

    // 6. Logout
    vcenterLogout($baseUrl, $sessionToken);

    echo json_encode([
        'success' => true,
        'message' => 'Synced ' . count($serverData) . ' servers from vCenter',
        'count' => count($serverData)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Authenticate with vCenter REST API
 */
function vcenterAuth($baseUrl, $user, $pass) {
    $ch = curl_init($baseUrl . '/api/session');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_SSL_VERIFYPEER => SSL_VERIFY_PEER,
        CURLOPT_SSL_VERIFYHOST => SSL_VERIFY_PEER ? 2 : 0,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('vCenter connection failed: ' . $curlError);
    }

    if ($httpCode !== 201) {
        throw new Exception('vCenter authentication failed (HTTP ' . $httpCode . '). Check credentials in Settings.');
    }

    $token = json_decode($response, true);
    if (is_string($token)) {
        return $token;
    }
    if (isset($token['value'])) {
        return $token['value'];
    }

    throw new Exception('Invalid session token received from vCenter');
}

/**
 * GET request to vCenter REST API
 */
function vcenterGet($baseUrl, $path, $sessionToken) {
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'vmware-api-session-id: ' . $sessionToken,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => SSL_VERIFY_PEER,
        CURLOPT_SSL_VERIFYHOST => SSL_VERIFY_PEER ? 2 : 0,
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('vCenter API error: ' . $curlError);
    }

    if ($httpCode !== 200) {
        throw new Exception('vCenter API error on ' . $path . ' (HTTP ' . $httpCode . ')');
    }

    $data = json_decode($response, true);

    // Handle both /api/ (returns array directly) and /rest/ (wraps in {value: ...}) formats
    if (isset($data['value']) && is_array($data['value'])) {
        return $data['value'];
    }

    return $data ?? [];
}

/**
 * Logout from vCenter session
 */
function vcenterLogout($baseUrl, $sessionToken) {
    $ch = curl_init($baseUrl . '/api/session');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'vmware-api-session-id: ' . $sessionToken
        ],
        CURLOPT_SSL_VERIFYPEER => SSL_VERIFY_PEER,
        CURLOPT_SSL_VERIFYHOST => SSL_VERIFY_PEER ? 2 : 0,
        CURLOPT_TIMEOUT => 10
    ]);
    curl_exec($ch);
    curl_close($ch);
}
?>
