<?php
/**
 * Debug Endpoint: Dump ALL raw fields from vCenter REST API
 * Read-only - does NOT write to the database
 * Shows every field returned by the API, including ones not used in get_vcenter.php
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Dumps every raw field vCenter returns, so it belongs to whoever administers the
// vCenter connection — not to anyone who happens to be logged in. It had no module
// guard at all before this.
requireModuleAccessJson('assets');
requireCapabilityJson(Cap::ASSETS_VCENTER);

set_time_limit(300);

// Optional: limit to a single VM by name or ID via query param
$filterVm = $_GET['vm'] ?? null;
// Optional: limit how many VMs to return detail for (default 10 to avoid timeout)
$limit = (int)($_GET['limit'] ?? 10);

try {
    $conn = connectToDatabase();

    // Get vCenter settings
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
        echo json_encode(['success' => false, 'error' => 'vCenter settings not configured.']);
        exit;
    }

    $baseUrl = "https://{$vcServer}";
    $output = [
        'success' => true,
        'vcenter_server' => $vcServer,
        'parameters' => ['vm' => $filterVm, 'limit' => $limit],
        'endpoints' => []
    ];

    // 1. Authenticate
    $sessionToken = vcenterAuth($baseUrl, $vcUser, $vcPass);

    // 2. Fetch all top-level inventory endpoints (raw)
    $inventoryEndpoints = [
        'hosts'       => '/api/vcenter/host',
        'clusters'    => '/api/vcenter/cluster',
        'datastores'  => '/api/vcenter/datastore',
        'networks'    => '/api/vcenter/network',
        'folders'     => '/api/vcenter/folder',
        'datacenters' => '/api/vcenter/datacenter',
        'resource_pools' => '/api/vcenter/resource-pool',
    ];

    foreach ($inventoryEndpoints as $label => $path) {
        try {
            $output['endpoints'][$label] = [
                'path' => $path,
                'data' => vcenterGetRaw($baseUrl, $path, $sessionToken)
            ];
        } catch (Exception $e) {
            $output['endpoints'][$label] = [
                'path' => $path,
                'error' => $e->getMessage()
            ];
        }
    }

    // 3. Get VM list (raw)
    $vmListRaw = vcenterGetRaw($baseUrl, '/api/vcenter/vm', $sessionToken);
    $output['endpoints']['vm_list'] = [
        'path' => '/api/vcenter/vm',
        'total_count' => is_array($vmListRaw) ? count($vmListRaw) : 0,
        'data' => $vmListRaw
    ];

    // 4. Get full detail for each VM (with limit)
    $vmDetails = [];
    $vmList = is_array($vmListRaw) ? $vmListRaw : [];

    // If filtering by name or ID, narrow the list
    if ($filterVm) {
        $vmList = array_filter($vmList, function($vm) use ($filterVm) {
            return (isset($vm['vm']) && strcasecmp($vm['vm'], $filterVm) === 0)
                || (isset($vm['name']) && strcasecmp($vm['name'], $filterVm) === 0)
                || (isset($vm['name']) && stripos($vm['name'], $filterVm) !== false);
        });
        $vmList = array_values($vmList);
    }

    $count = 0;
    foreach ($vmList as $vm) {
        if ($count >= $limit) break;
        $vmId = $vm['vm'] ?? '';
        $vmName = $vm['name'] ?? $vmId;
        if (empty($vmId)) continue;

        $vmEntry = [
            'vm_id' => $vmId,
            'vm_name' => $vmName,
            'list_fields' => $vm
        ];

        // Full VM detail
        try {
            $vmEntry['detail'] = [
                'path' => "/api/vcenter/vm/{$vmId}",
                'data' => vcenterGetRaw($baseUrl, '/api/vcenter/vm/' . $vmId, $sessionToken)
            ];
        } catch (Exception $e) {
            $vmEntry['detail'] = ['error' => $e->getMessage()];
        }

        // Guest identity (requires VMware Tools + powered on)
        try {
            $vmEntry['guest_identity'] = [
                'path' => "/api/vcenter/vm/{$vmId}/guest/identity",
                'data' => vcenterGetRaw($baseUrl, '/api/vcenter/vm/' . $vmId . '/guest/identity', $sessionToken)
            ];
        } catch (Exception $e) {
            $vmEntry['guest_identity'] = ['error' => $e->getMessage()];
        }

        // Guest networking
        try {
            $vmEntry['guest_networking'] = [
                'path' => "/api/vcenter/vm/{$vmId}/guest/networking/interfaces",
                'data' => vcenterGetRaw($baseUrl, '/api/vcenter/vm/' . $vmId . '/guest/networking/interfaces', $sessionToken)
            ];
        } catch (Exception $e) {
            $vmEntry['guest_networking'] = ['error' => $e->getMessage()];
        }

        // Guest local filesystem
        try {
            $vmEntry['guest_local_filesystem'] = [
                'path' => "/api/vcenter/vm/{$vmId}/guest/local-filesystem",
                'data' => vcenterGetRaw($baseUrl, '/api/vcenter/vm/' . $vmId . '/guest/local-filesystem', $sessionToken)
            ];
        } catch (Exception $e) {
            $vmEntry['guest_local_filesystem'] = ['error' => $e->getMessage()];
        }

        $vmDetails[] = $vmEntry;
        $count++;
    }

    $output['vm_details'] = [
        'showing' => count($vmDetails),
        'total_vms' => count(is_array($vmListRaw) ? $vmListRaw : []),
        'vms' => $vmDetails
    ];

    // 5. Logout
    vcenterLogout($baseUrl, $sessionToken);

    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
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
        CURLOPT_TIMEOUT => 30
    ]);
    sslApplyCurl($ch);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('vCenter connection failed: ' . $curlError);
    }
    if ($httpCode !== 201) {
        throw new Exception('vCenter authentication failed (HTTP ' . $httpCode . ')');
    }

    $token = json_decode($response, true);
    if (is_string($token)) return $token;
    if (isset($token['value'])) return $token['value'];

    throw new Exception('Invalid session token received from vCenter');
}

/**
 * Raw GET request - returns decoded JSON without unwrapping 'value' key
 * so we can see the exact API response structure
 */
function vcenterGetRaw($baseUrl, $path, $sessionToken) {
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'vmware-api-session-id: ' . $sessionToken,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 60
    ]);
    sslApplyCurl($ch);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('vCenter API error: ' . $curlError);
    }
    if ($httpCode !== 200) {
        throw new Exception('HTTP ' . $httpCode . ' on ' . $path);
    }

    return json_decode($response, true);
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
        CURLOPT_TIMEOUT => 10
    ]);
    sslApplyCurl($ch);
    curl_exec($ch);
    curl_close($ch);
}
?>
