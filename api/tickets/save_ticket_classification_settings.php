<?php
/**
 * API Endpoint: save the three "does this field appear on a ticket" switches (#1540).
 *
 * POST {
 *   default:   { category: bool, closure_category: bool, resolution_code: bool },
 *   companies: [ { id: N, category: bool|null, closure_category: bool|null, resolution_code: bool|null } ]
 * }
 *
 * ⚠️ `null` for a company means "follow the install default" and DELETES its
 * override — it is not the same as false. Without that distinction a company
 * could be given an answer and never handed back to the default again.
 *
 * ⚠️ NOTHING IS DELETED BY TURNING THESE OFF. A ticket already carrying a
 * category keeps it, and it reappears untouched when the switch goes back on.
 * These decide what is SHOWN, never what is stored.
 *
 * 🔑 The three are INDEPENDENT and nothing here couples them. "Category off,
 * category at close on" is a real service desk — don't make the person raising
 * the ticket guess, let the analyst classify once they actually know.
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
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_CATEGORIES);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$KEYS = [
    'category'         => SETTING_TICKET_CATEGORY,
    'closure_category' => SETTING_TICKET_CLOSURE_CATEGORY,
    'resolution_code'  => SETTING_TICKET_RESOLUTION_CODE,
];

try {
    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    $conn->beginTransaction();
    try {
        if (isset($in['default']) && is_array($in['default'])) {
            foreach ($KEYS as $field => $key) {
                if (!array_key_exists($field, $in['default'])) continue;
                $conn->prepare(
                    "INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
                )->execute([$key, !empty($in['default'][$field]) ? '1' : '0']);
            }
        }

        if (isset($in['companies']) && is_array($in['companies'])) {
            foreach ($in['companies'] as $c) {
                $tid = (int) ($c['id'] ?? 0);
                if ($tid <= 0) continue;
                // ⚠️ Only companies this analyst may administer. Without this an
                // analyst scoped to one client could change another client's.
                if (!analystCanAccessTenant($conn, $analystId, $tid)) continue;

                foreach ($KEYS as $field => $key) {
                    if (!array_key_exists($field, $c)) continue;
                    $v = $c[$field];
                    setTenantSetting($conn, $tid, $key, $v === null ? null : (!empty($v) ? '1' : '0'));
                }
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
