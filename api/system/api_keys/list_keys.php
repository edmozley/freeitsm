<?php
/**
 * API Endpoint (internal, session): list REST API v1 keys + everything the
 * System > API page needs to render its create/edit form (permission catalog,
 * analysts, companies).
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../../includes/functions.php';
require_once '../../../includes/tenancy.php';
require_once '../../../api/v1/lib/permissions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $keys = [];
    try {
        // GH #287: the key's restrictions and why it was switched off, once Database Verification has added them.
        require_once '../../../includes/api_security.php';
        // ...and today's calls and failures from the activity log.
        $act = apiActivityReady($conn)
            ? '(SELECT COUNT(*) FROM api_request_log r WHERE r.api_key_id = k.id AND r.created_datetime >= UTC_DATE()) AS calls_today,
               (SELECT COUNT(*) FROM api_request_log r WHERE r.api_key_id = k.id AND r.created_datetime >= UTC_DATE() AND r.status >= 400) AS failures_today,'
            : '0 AS calls_today, 0 AS failures_today,';
        $sec = apiSecurityReady($conn)
            ? 'k.allowed_ips, k.allowed_hours, k.download_alert_files, k.download_limit_files, k.download_limit_mb, k.suspended_reason,
               (SELECT COUNT(*) FROM document_access_log l WHERE l.api_key_id = k.id AND l.action = \'download\' AND l.created_datetime > UTC_TIMESTAMP() - INTERVAL 60 MINUTE) AS downloads_hour,'
            : 'NULL AS allowed_ips, NULL AS allowed_hours, NULL AS download_alert_files, NULL AS download_limit_files, NULL AS download_limit_mb, NULL AS suspended_reason, 0 AS downloads_hour,';
        $rows = $conn->query(
            "SELECT k.id, k.name, k.key_prefix, k.analyst_id, k.permissions, k.company_ids, $sec $act a.is_admin AS analyst_is_admin,
                    k.rate_limit_per_minute, k.active, k.expires_at, k.last_used_at, k.last_used_ip,
                    k.created_datetime, a.full_name AS analyst_name
             FROM api_keys k
             LEFT JOIN analysts a ON a.id = k.analyst_id
             ORDER BY k.created_datetime DESC, k.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $companyIds = json_decode((string)$r['company_ids'], true);
            $keys[] = [
                'id'           => (int)$r['id'],
                'name'         => $r['name'],
                'key_prefix'   => $r['key_prefix'],
                'analyst_id'   => (int)$r['analyst_id'],
                'analyst_name' => $r['analyst_name'],
                'permissions'  => apiV1NormalisePermissions(json_decode((string)$r['permissions'], true)),
                'company_ids'  => is_array($companyIds) ? array_map('intval', $companyIds) : null,
                'rate_limit_per_minute' => $r['rate_limit_per_minute'] !== null ? (int)$r['rate_limit_per_minute'] : null,
                'active'       => (bool)$r['active'],
                'expires_at'   => $r['expires_at'],
                'last_used_at' => $r['last_used_at'],
                'last_used_ip' => $r['last_used_ip'],
                'created_at'   => $r['created_datetime'],
                'allowed_ips'  => $r['allowed_ips'],
                'allowed_hours' => $r['allowed_hours'] ? json_decode($r['allowed_hours'], true) : null,
                'download_alert_files' => $r['download_alert_files'] !== null ? (int)$r['download_alert_files'] : null,
                'download_limit_files' => $r['download_limit_files'] !== null ? (int)$r['download_limit_files'] : null,
                'download_limit_mb'    => $r['download_limit_mb'] !== null ? (int)$r['download_limit_mb'] : null,
                'suspended_reason'     => $r['suspended_reason'],
                'downloads_hour'       => (int)$r['downloads_hour'],
                'calls_today'          => (int)$r['calls_today'],
                'failures_today'       => (int)$r['failures_today'],
                'analyst_is_admin'     => (int)$r['analyst_is_admin'] === 1,
            ];
        }
        $tableReady = true;
    } catch (Exception $e) {
        // api_keys table not created yet — page shows the "run Database Verify" notice.
        $tableReady = false;
    }

    $analysts = $conn->query(
        "SELECT id, full_name, is_admin FROM analysts WHERE is_active = 1 ORDER BY full_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $companies = array_map(function ($t) {
        return ['id' => $t['id'], 'name' => $t['name'], 'is_default' => $t['is_default']];
    }, getAllTenants($conn, true));

    echo json_encode([
        'success'       => true,
        'table_ready'   => $tableReady,
        'keys'          => $keys,
        'catalog'       => apiV1PermissionCatalog(),
        'analysts'      => array_map(function ($a) {
            return ['id' => (int)$a['id'], 'name' => $a['full_name'], 'is_admin' => (int)$a['is_admin'] === 1];
        }, $analysts),
        'companies'     => $companies,
        'multi_tenant'  => isMultiTenant($conn),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
