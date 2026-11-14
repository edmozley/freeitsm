<?php
/**
 * API: the Intune kind-of-device -> asset type mapping (GH #336).
 *
 *   GET                              the active types, the saved mapping, and a
 *                                    suggestion for each kind with nothing mapped
 *   POST { action: 'create_missing', map?: {kind: type id} }
 *                                    every kind with nothing mapped (in `map`, the
 *                                    mapping on screen, else the saved one) gets the
 *                                    type it is known by, or a new one - and it is saved
 *
 * Saving a mapping chosen by hand goes with the rest of the Intune settings
 * (save_system_settings.php, intune_type_map). The rules are in
 * includes/intune_asset_type.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/i18n.php';
require_once '../../includes/intune_asset_type.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');
requireCapabilityJson(Cap::ASSETS_INTUNE);

try {
    $conn = connectToDatabase();
    $created = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfRequireWriteMethod();
        // Creating asset types is the asset types' own permission too.
        requireCapabilityJson(Cap::ASSETS_TYPES);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        if (($in['action'] ?? '') !== 'create_missing') {
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
            exit;
        }
        I18n::initFromSession();
        $names = [];
        foreach (IntuneDeviceKind::cases() as $k) $names[$k->value] = t('asset-management.settings.intune_new_type_' . $k->value);
        // The mapping as it is on screen, so a choice not saved yet is kept.
        $start = isset($in['map']) && is_array($in['map']) ? $in['map'] : null;
        $created = intuneCreateMissingTypes($conn, $names, $start)['created'];
    }
    $types = intuneActiveAssetTypes($conn);
    $map = intuneTypeMap($conn);
    echo json_encode(['success' => true, 'types' => $types, 'map' => (object)$map,
                      'suggested' => (object)intuneSuggestTypeMap($types, $map), 'created' => $created]);
} catch (Throwable $e) {
    // Never the exception's own text: it can carry SQL and column names.
    error_log('intune type_map: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The asset types for Intune could not be read or saved.']);
}
