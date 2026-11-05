<?php
/**
 * API Endpoint: save the change risk policy as its next version, or go back to the
 * built-in 5 x 5 (Change Management → Settings → Risk policy, #246).
 *
 *   POST { action: 'save', policy: { name, likelihood, impact, results, matrix } }
 *   POST { action: 'builtin' }
 *
 * Behind Cap::CHANGES_RISK_POLICY, marked sensitive. Nothing already assessed changes:
 * a saved version is never edited, and every change keeps the version it was rated under.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/service_context.php';
require_once '../../includes/changes/risk_policy.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();   // the refusal messages in the analyst's language

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');
requireModuleWriteJson('changes');
requireCapabilityJson(Cap::CHANGES_RISK_POLICY);   // settings tab - see docs/design/rbac.md

try {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $conn = connectToDatabase();
    if (!ChangeRiskPolicy::ready($conn)) {
        throw new Exception(t('change-management.risk_policy.not_ready'));
    }
    $actor = (int)$_SESSION['analyst_id'];
    $action = (string)($in['action'] ?? '');
    if ($action === 'save') {
        $vid = ChangeRiskPolicy::save($conn, is_array($in['policy'] ?? null) ? $in['policy'] : [], $actor, null);
        echo json_encode(['success' => true, 'version_id' => $vid]);
    } elseif ($action === 'builtin') {
        ChangeRiskPolicy::useBuiltin($conn, $actor, null);
        echo json_encode(['success' => true, 'version_id' => null]);
    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
