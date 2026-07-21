<?php
/**
 * API Endpoint: the install's merge policy, for the merge dialog.
 *
 * GET -> { reference_mode, originals_mode, ai_summary }
 *
 * A READ of policy, not of credentials, so it stops at module access — every analyst
 * who can merge needs to know what merging will do before they press the button. The
 * capability (Cap::TICKETS_MERGE) guards CHANGING the policy, on the settings tab.
 *
 * Exists as its own endpoint rather than reusing get_system_settings.php so the merge
 * dialog does not depend on a settings-level read that an ordinary analyst may not be
 * entitled to.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/ticket_merge.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    echo json_encode(['success' => true, 'settings' => mergeSettings($conn)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
