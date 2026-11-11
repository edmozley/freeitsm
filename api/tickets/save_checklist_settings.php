<?php
/**
 * API Endpoint: save the two checklist closure settings (PR #141).
 *
 * POST { mode: "per_template" | "block_all", empty_mode: "off" | "warn" | "block" }
 *
 *   mode        what to do about outstanding mandatory steps. 'per_template'
 *               honours the gate on each checklist; 'block_all' overrides them
 *               and refuses every close with steps outstanding.
 *   empty_mode  what to do about a ticket with no checklist attached at all.
 *
 * Install-wide for now. Both values are read through tenantSetting(), which
 * already resolves a per-company override over this default, so a Companies
 * column can be added to the tab later without touching the reading side or
 * migrating anything — the same shape the three classification switches use.
 *
 * ⚠️ These decide whether a close is ALLOWED. Neither decides whether it is
 * RECORDED: ChecklistsService writes its audit note either way, so loosening a
 * gate here loses no audit trail.
 *
 * 🔴 'mode' was "warn"|"block" before per-template gating. The stored values
 * still read correctly — ticketChecklistClosureMode() maps a legacy 'block' to
 * 'block_all' and anything else to 'per_template' — but this endpoint only
 * accepts the new pair, so anything posting the old words must be updated.
 */
session_start(['read_and_close' => true]);

require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenant_settings.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_CHECKLISTS);

$in        = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$mode      = (string)($in['mode'] ?? '');
$emptyMode = (string)($in['empty_mode'] ?? '');

// ⚠️ Both refuse an unknown value rather than falling back to a default. A
// silent fallback here would quietly LOOSEN a gate somebody had tightened, and
// report success while doing it.
if ($mode !== 'per_template' && $mode !== 'block_all') {
    echo json_encode(['success' => false, 'error' => 'Mode must be "per_template" or "block_all".']);
    exit;
}
if (!in_array($emptyMode, ['off', 'warn', 'block'], true)) {
    echo json_encode(['success' => false, 'error' => 'Empty mode must be "off", "warn" or "block".']);
    exit;
}

try {
    $conn = connectToDatabase();

    // setting_key is the PRIMARY KEY of system_settings, so the upsert is safe.
    $upsert = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
              VALUES (?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                 updated_datetime = UTC_TIMESTAMP()"
    );
    $upsert->execute([SETTING_TICKET_CHECKLIST_CLOSURE, $mode]);
    $upsert->execute([SETTING_TICKET_CHECKLIST_EMPTY_CLOSURE, $emptyMode]);
    // GH #272: "Forms on tickets". Only written when sent, so an older caller leaves it alone.
    if (array_key_exists('forms_enabled', $in)) {
        $upsert->execute(['tickets_forms_enabled', !empty($in['forms_enabled']) ? '1' : '0']);
    }

    // Read them back rather than echoing the input: the stored values are what
    // the next page load will show, and the two must not be able to disagree.
    echo json_encode([
        'success'    => true,
        'mode'       => ticketChecklistClosureMode($conn, null),
        'empty_mode' => ticketChecklistEmptyClosureMode($conn, null),
        'forms_enabled' => (string)($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'tickets_forms_enabled'")->fetchColumn() ?: '0') === '1',
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
