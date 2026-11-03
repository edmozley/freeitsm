<?php
/**
 * API: the Department field's settings, install-wide and per company (GH #218 part A).
 *
 * GET  { multi_tenant, required_at_close,
 *        default:   { shown: bool, department_id: int|null },
 *        companies: [{ id, name, shown: bool|null, department_id: int|0|null }],
 *        departments: [{ id, name }],                      active ones, for the pickers
 *        stale:     [{ place, name }] }                    defaults pointing at a department no longer active
 * POST { default: { shown, department_id }, companies: [{ id, shown, department_id }] }
 *
 * Per company: null = follow the install-wide value (its row is deleted);
 * department_id 0 = an explicit "no default". Tickets -> Settings -> Custom fields,
 * under that tab's permission.
 *
 * Refused, saving nothing: a default that is not an active department, and Department
 * hidden anywhere Mandatory fields requires it at closure (ticket_department_rules.php).
 * Hiding is presentation only - nothing stored on a ticket changes.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_department_rules.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_CUSTOM_FIELDS);

try {
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    $multi = isMultiTenant($conn);
    $active = [];
    foreach ($conn->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY display_order, name") as $r) $active[(int) $r['id']] = $r['name'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $shownRows = tenantSettingsForKey($conn, SETTING_TICKET_DEPARTMENT_SHOWN);
        $defRows   = tenantSettingsForKey($conn, SETTING_TICKET_DEPARTMENT_DEFAULT);
        $installDef = ticketDefaultDepartmentId($conn, null);
        $companies = [];
        $stale = [];
        $check = function (?int $deptId, string $place) use (&$stale, $active, $conn) {
            if ($deptId === null || $deptId <= 0 || isset($active[$deptId])) return;
            $st = $conn->prepare("SELECT name FROM departments WHERE id = ?");
            $st->execute([$deptId]);
            $stale[] = ['place' => $place, 'name' => (string) ($st->fetchColumn() ?: ('#' . $deptId))];
        };
        $check($installDef, '');
        if ($multi) {
            foreach (getAllTenants($conn) as $t) {
                $tid = (int) $t['id'];
                if (!analystCanAccessTenant($conn, $analystId, $tid)) continue;
                $dep = array_key_exists($tid, $defRows) ? (int) $defRows[$tid] : null;
                $companies[] = ['id' => $tid, 'name' => $t['name'],
                                'shown' => array_key_exists($tid, $shownRows) ? $shownRows[$tid] !== '0' : null,
                                'department_id' => $dep];
                $check($dep, (string) $t['name']);
            }
        }
        echo json_encode([
            'success' => true, 'multi_tenant' => $multi,
            'required_at_close' => ticketDepartmentRequiredAtClose($conn, null),
            'default' => ['shown' => ticketDepartmentShown($conn, null), 'department_id' => $installDef],
            'companies' => $companies,
            'departments' => array_map(fn($id, $n) => ['id' => $id, 'name' => $n], array_keys($active), $active),
            'stale' => $stale,
        ]);
        exit;
    }

    // Changes data: never on a GET, which a link on another site can send (csrf.php).
    csrfRequireWriteMethod();
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    $fail = function (string $msg) { echo json_encode(['success' => false, 'error' => $msg]); exit; };
    $deptOk = fn($v) => $v === null || (int) $v === 0 || isset($active[(int) $v]);

    $default = is_array($in['default'] ?? null) ? $in['default'] : null;
    if ($default !== null && !$deptOk($default['department_id'] ?? null)) $fail('Choose an active department as the default.');
    $companies = [];
    foreach ((array) ($in['companies'] ?? []) as $c) {
        $tid = (int) ($c['id'] ?? 0);
        if ($tid <= 0 || !analystCanAccessTenant($conn, $analystId, $tid)) continue;   // only companies this analyst may administer
        if (!$deptOk($c['department_id'] ?? null)) $fail('Choose an active department as the default.');
        $companies[$tid] = $c;
    }

    // Guard 1: never hidden where Mandatory fields requires it at closure.
    $proposedShown = [];
    foreach ($companies as $tid => $c) if (array_key_exists('shown', $c)) $proposedShown[$tid] = $c['shown'] === null ? null : (bool) $c['shown'];
    $conflicts = ticketDepartmentConflicts($conn, $default !== null && array_key_exists('shown', $default) ? (bool) $default['shown'] : null, $proposedShown);
    if ($conflicts) {
        $fail('Department is required at closure (Tickets -> Settings -> Mandatory fields), so it cannot be hidden for '
            . ticketDepartmentPlaceList($conflicts) . '. Untick it on Mandatory fields first, or keep Department shown.');
    }

    $conn->beginTransaction();
    try {
        $put = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($default !== null) {
            if (array_key_exists('shown', $default)) $put->execute([SETTING_TICKET_DEPARTMENT_SHOWN, !empty($default['shown']) ? '1' : '0']);
            if (array_key_exists('department_id', $default)) $put->execute([SETTING_TICKET_DEPARTMENT_DEFAULT, (string) max(0, (int) ($default['department_id'] ?? 0))]);
        }
        foreach ($companies as $tid => $c) {
            if (array_key_exists('shown', $c)) {
                setTenantSetting($conn, $tid, SETTING_TICKET_DEPARTMENT_SHOWN, $c['shown'] === null ? null : (!empty($c['shown']) ? '1' : '0'));
            }
            if (array_key_exists('department_id', $c)) {
                setTenantSetting($conn, $tid, SETTING_TICKET_DEPARTMENT_DEFAULT, $c['department_id'] === null ? null : (string) max(0, (int) $c['department_id']));
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    tenantSettingForget();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('department_field_settings: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not save the Department settings.']);
}
