<?php
/**
 * API Endpoint: save the mandatory-fields-at-closure settings.
 *
 * POST {
 *   fields: ["category", "resolution_code", ...],   // keys of MandatoryFieldsService::FIELDS
 *   mode:   "warn" | "notify" | "block",
 *   notify: "sdm@example.com, lead@example.com",     // required when mode = notify
 *   record: true | false
 * }
 *
 * Install-wide for now. Read through tenantSetting(), so a per-company column can
 * be added to the tab later without touching the reading side.
 *
 * Unknown field keys and invalid addresses are REFUSED rather than dropped: a
 * list that quietly saved as something other than what was typed is how an
 * administrator ends up believing a rule is in force that is not.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/service_context.php';
require_once '../../includes/services/mandatory_fields.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MANDATORY_FIELDS);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$fields = $in['fields'] ?? [];
if (!is_array($fields)) $fields = [];
$fields = array_values(array_unique(array_map('strval', $fields)));
foreach ($fields as $f) {
    if (!isset(MandatoryFieldsService::FIELDS[$f])) {
        echo json_encode(['success' => false, 'error' => 'Unknown field: ' . $f]);
        exit;
    }
}

$mode = (string) ($in['mode'] ?? '');
if (!in_array($mode, MandatoryFieldsService::MODES, true)) {
    echo json_encode(['success' => false, 'error' => 'Choose what happens when fields are empty.']);
    exit;
}

$rawNotify = trim((string) ($in['notify'] ?? ''));
$typed = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $rawNotify) ?: []), 'strlen'));
// Each entry is checked on its own. Comparing counts would also trip on a
// duplicate, which parseAddresses() drops on purpose, and name no culprit.
foreach ($typed as $a) {
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Not a valid email address: ' . $a]);
        exit;
    }
}
$notify = MandatoryFieldsService::parseAddresses($rawNotify);
if (count($notify) > MandatoryFieldsService::MAX_NOTIFY) {
    echo json_encode(['success' => false, 'error' => 'At most ' . MandatoryFieldsService::MAX_NOTIFY . ' addresses.']);
    exit;
}
if ($mode === 'notify' && !$notify) {
    echo json_encode(['success' => false, 'error' => 'Add at least one address to notify.']);
    exit;
}

$record = !empty($in['record']);

try {
    $conn = connectToDatabase();
    // GH #218: Department cannot be required at closure where it is hidden - a
    // field nobody can fill would trap tickets. Refused, naming where.
    if (in_array('department', $fields, true)) {
        require_once '../../includes/ticket_department_rules.php';
        $hiddenFor = ticketDepartmentConflicts($conn, null, [], true);
        if ($hiddenFor) {
            echo json_encode(['success' => false, 'error' => 'Department is hidden for ' . ticketDepartmentPlaceList($hiddenFor)
                . ' (Tickets -> Settings -> Custom fields -> Department field), so it cannot be required at closure. Show it there first.']);
            exit;
        }
    }
    // setting_key is the PRIMARY KEY of system_settings, so the upsert is safe.
    $st = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
              VALUES (?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                 updated_datetime = UTC_TIMESTAMP()"
    );
    $conn->beginTransaction();
    $st->execute([SETTING_TICKET_MANDATORY_FIELDS, implode(',', $fields)]);
    $st->execute([SETTING_TICKET_MANDATORY_MODE,   $mode]);
    $st->execute([SETTING_TICKET_MANDATORY_NOTIFY, implode(', ', $notify)]);
    $st->execute([SETTING_TICKET_MANDATORY_RECORD, $record ? '1' : '0']);
    $conn->commit();

    // Read it back rather than echoing the input: the stored value is what the
    // next close will use, and the two must not be able to disagree.
    echo json_encode(['success' => true, 'settings' => MandatoryFieldsService::settings($conn, null)]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
