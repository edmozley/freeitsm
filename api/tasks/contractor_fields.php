<?php
/**
 * API: Contractor fields on tasks, per company (GH #218 part A).
 *
 * GET   { default: bool, companies: [{id, name, value: bool|null}], multi_tenant }
 * POST  { default: bool, companies: [{id, value: bool|null}] }
 *
 * Whether the task screen shows the Contractor and Contact there pair. Tasks ->
 * Settings -> Involved, under the same permission as the rest of that tab.
 *
 * ⚠️ `null` for a company means "follow the install default" and DELETES its
 * answer - not the same as false (see setTenantSetting()).
 *
 * ⚠️ PRESENTATION ONLY. Nothing is refused and nothing is cleared: Projects and the
 * REST API still assign contractors, and a task that has one keeps it and shows it
 * read-only. Switching contractors off for real is the Contracts module (#217).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/tenant_settings.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');
requireCapabilityJson(Cap::TASKS_INVOLVED);

try {
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    $multi = isMultiTenant($conn);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $overrides = tenantSettingsForKey($conn, SETTING_TASK_CONTRACTOR_FIELDS);
        $companies = [];
        if ($multi) {
            foreach (getAllTenants($conn) as $t) {
                $tid = (int) $t['id'];
                if (!analystCanAccessTenant($conn, $analystId, $tid)) continue;
                $companies[] = ['id' => $tid, 'name' => $t['name'],
                                'value' => array_key_exists($tid, $overrides) ? $overrides[$tid] !== '0' : null];
            }
        }
        echo json_encode(['success' => true, 'multi_tenant' => $multi,
                          'default' => taskContractorFieldsOn($conn, null), 'companies' => $companies]);
        exit;
    }

    // Changes data: never on a GET, which a link on another site can send (csrf.php).
    csrfRequireWriteMethod();
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    $conn->beginTransaction();
    try {
        if (array_key_exists('default', $in)) {
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                 ->execute([SETTING_TASK_CONTRACTOR_FIELDS, !empty($in['default']) ? '1' : '0']);
        }
        foreach ((array) ($in['companies'] ?? []) as $c) {
            $tid = (int) ($c['id'] ?? 0);
            // Only companies this analyst may administer.
            if ($tid <= 0 || !array_key_exists('value', (array) $c) || !analystCanAccessTenant($conn, $analystId, $tid)) continue;
            setTenantSetting($conn, $tid, SETTING_TASK_CONTRACTOR_FIELDS, $c['value'] === null ? null : (!empty($c['value']) ? '1' : '0'));
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    tenantSettingForget();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('contractor_fields: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not save the setting.']);
}
