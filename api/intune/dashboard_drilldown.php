<?php
/**
 * API: Intune Dashboard Drill-down
 *
 * Returns the list of intune_devices matching a chart-click filter,
 * paginated for the modal table or returned as CSV for export.
 *
 * GET parameters:
 *   filter      Required. One of:
 *                 - compliance       value = compliance_state (e.g. "compliant")
 *                 - os               value = operating_system  (e.g. "Windows")
 *                 - owner            value = managed_device_owner_type (e.g. "company")
 *                 - manufacturer     value = manufacturer (e.g. "Apple")
 *                 - os_version       value = "<os>||<version>"
 *                 - last_sync        value = bucket: today | week | month | quarter | old | never
 *                 - enrolment_day    value = YYYY-MM-DD
 *                 - encryption_os    value = "0||<os>" or "1||<os>"
 *                 - kpi_stale        value ignored — devices not synced in 30+ days (or never)
 *                 - kpi_recent       value ignored — devices enrolled in last 30 days
 *                 - kpi_compliant    value ignored — compliance_state = 'compliant'
 *                 - kpi_encrypted    value ignored — is_encrypted = 1
 *   value       Filter value (see above).
 *   page        1-based page index. Default 1.
 *   page_size   Rows per page. Default 25, capped at 100.
 *   format      "json" (default) or "csv". CSV ignores pagination and returns
 *               all matching rows as a downloadable attachment.
 */

session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$filter   = $_GET['filter']    ?? '';
$value    = $_GET['value']     ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$pageSize = max(1, min(100, (int)($_GET['page_size'] ?? 25)));
$format   = ($_GET['format'] ?? 'json') === 'csv' ? 'csv' : 'json';

/**
 * Resolve a filter type + value into a SQL WHERE fragment + bound params.
 * All filter types are validated against this whitelist; nothing user-supplied
 * goes into the SQL string directly.
 */
function buildFilter(string $filter, string $value, ?string &$friendly): array
{
    $where  = '1=1';
    $params = [];

    switch ($filter) {
        case 'compliance':
            $where  = 'compliance_state = ?';
            $params = [$value];
            $friendly = 'Compliance: ' . ucwords(str_replace(['_', '-'], ' ', $value));
            break;

        case 'os':
            $where  = "COALESCE(NULLIF(operating_system,''), 'Unknown') = ?";
            $params = [$value];
            $friendly = 'Operating System: ' . $value;
            break;

        case 'owner':
            $where  = "COALESCE(NULLIF(managed_device_owner_type,''), 'Unknown') = ?";
            $params = [$value];
            $friendly = 'Owner type: ' . ucwords(str_replace(['_', '-'], ' ', $value));
            break;

        case 'manufacturer':
            $where  = "COALESCE(NULLIF(manufacturer,''), 'Unknown') = ?";
            $params = [$value];
            $friendly = 'Manufacturer: ' . $value;
            break;

        case 'os_version':
            // Value is "<os>||<version>"
            $parts = explode('||', $value, 2);
            if (count($parts) !== 2) {
                throw new RuntimeException('Invalid os_version value');
            }
            $where  = "COALESCE(NULLIF(operating_system,''), '?') = ? AND COALESCE(NULLIF(os_version,''), '?') = ?";
            $params = [$parts[0], $parts[1]];
            $friendly = 'OS version: ' . $parts[0] . ' ' . $parts[1];
            break;

        case 'last_sync':
            switch ($value) {
                case 'today':
                    $where = 'last_sync_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)';
                    $friendly = 'Last sync: Today';
                    break;
                case 'week':
                    $where = "last_sync_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
                              AND last_sync_datetime <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)";
                    $friendly = 'Last sync: 1-7 days ago';
                    break;
                case 'month':
                    $where = "last_sync_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                              AND last_sync_datetime <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)";
                    $friendly = 'Last sync: 8-30 days ago';
                    break;
                case 'quarter':
                    $where = "last_sync_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)
                              AND last_sync_datetime <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)";
                    $friendly = 'Last sync: 31-90 days ago';
                    break;
                case 'old':
                    $where = 'last_sync_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)';
                    $friendly = 'Last sync: 90+ days ago';
                    break;
                case 'never':
                    $where = 'last_sync_datetime IS NULL';
                    $friendly = 'Last sync: Never';
                    break;
                default:
                    throw new RuntimeException('Invalid last_sync value');
            }
            break;

        case 'enrolment_day':
            // Value is YYYY-MM-DD
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new RuntimeException('Invalid enrolment_day value');
            }
            $where  = 'DATE(enrolled_datetime) = ?';
            $params = [$value];
            $friendly = 'Enrolled on ' . $value;
            break;

        case 'encryption_os':
            // Value is "<encrypted>||<os>" where encrypted is "1" or "0"
            $parts = explode('||', $value, 2);
            if (count($parts) !== 2 || !in_array($parts[0], ['0', '1'], true)) {
                throw new RuntimeException('Invalid encryption_os value');
            }
            if ($parts[0] === '1') {
                $where = "is_encrypted = 1 AND COALESCE(NULLIF(operating_system,''), 'Unknown') = ?";
                $friendly = 'Encrypted ' . $parts[1];
            } else {
                $where = "(is_encrypted = 0 OR is_encrypted IS NULL) AND COALESCE(NULLIF(operating_system,''), 'Unknown') = ?";
                $friendly = 'Not encrypted ' . $parts[1];
            }
            $params = [$parts[1]];
            break;

        case 'kpi_stale':
            $where = 'last_sync_datetime IS NULL OR last_sync_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)';
            $friendly = 'Stale (no sync in 30+ days)';
            break;

        case 'kpi_recent':
            $where = 'enrolled_datetime IS NOT NULL AND enrolled_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)';
            $friendly = 'Enrolled in last 30 days';
            break;

        case 'kpi_compliant':
            $where = "compliance_state = 'compliant'";
            $friendly = 'Compliant devices';
            break;

        case 'kpi_encrypted':
            $where = 'is_encrypted = 1';
            $friendly = 'Encrypted devices';
            break;

        default:
            throw new RuntimeException('Unknown filter: ' . $filter);
    }

    return [$where, $params];
}

try {
    $conn = connectToDatabase();

    $friendly = '';
    [$where, $params] = buildFilter($filter, $value, $friendly);

    $countSql = "SELECT COUNT(*) FROM intune_devices WHERE $where";
    $stmt = $conn->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $selectCols = "id, intune_id, device_name, user_principal_name, user_display_name,
                   operating_system, os_version, compliance_state, management_state,
                   managed_device_owner_type, manufacturer, model,
                   is_encrypted, last_sync_datetime, enrolled_datetime";

    if ($format === 'csv') {
        $sql = "SELECT $selectCols FROM intune_devices WHERE $where ORDER BY device_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        // Stream as CSV with a sensible filename
        $filename = 'intune-devices-' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $filter . '_' . $value) . '.csv';
        $filename = preg_replace('/_+/', '_', $filename);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel opens it correctly
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Device Name', 'User', 'User Email', 'OS', 'OS Version',
            'Compliance', 'Owner Type', 'Manufacturer', 'Model',
            'Encrypted', 'Last Sync', 'Enrolled'
        ]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $r['device_name'],
                $r['user_display_name'],
                $r['user_principal_name'],
                $r['operating_system'],
                $r['os_version'],
                $r['compliance_state'],
                $r['managed_device_owner_type'],
                $r['manufacturer'],
                $r['model'],
                $r['is_encrypted'] === null ? '' : ($r['is_encrypted'] ? 'Yes' : 'No'),
                $r['last_sync_datetime'],
                $r['enrolled_datetime'],
            ]);
        }
        fclose($out);
        exit;
    }

    // JSON paginated path
    $offset = ($page - 1) * $pageSize;

    $sql = "SELECT $selectCols
              FROM intune_devices
             WHERE $where
          ORDER BY device_name
             LIMIT $pageSize OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['is_encrypted'] = $r['is_encrypted'] === null ? null : (bool)$r['is_encrypted'];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success'      => true,
        'total'        => $total,
        'page'         => $page,
        'page_size'    => $pageSize,
        'total_pages'  => $total === 0 ? 1 : (int)ceil($total / $pageSize),
        'friendly'     => $friendly,
        'devices'      => $rows,
    ]);

} catch (Throwable $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
