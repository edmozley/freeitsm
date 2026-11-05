<?php
/**
 * API Endpoint: the change risk policy (Change Management → Settings → Risk policy, #246).
 *
 * Behind Cap::CHANGES_RISK_POLICY. Returns the policy new assessments use (its content,
 * or the built-in 5 x 5), and every saved version with how many changes were rated under it.
 * The change screens do not call this: they are given every version with the page.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/service_context.php';
require_once '../../includes/changes/risk_policy.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();   // the refusal messages in the analyst's language

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');
requireCapabilityJson(Cap::CHANGES_RISK_POLICY);   // settings tab - see docs/design/rbac.md

try {
    $conn = connectToDatabase();
    $current = ChangeRiskPolicy::current($conn, null);
    $history = ChangeRiskPolicy::history($conn, null);
    echo json_encode([
        'success'    => true,
        'ready'      => ChangeRiskPolicy::ready($conn),
        'current'    => $current,
        'builtin'    => ChangeRiskPolicy::builtin(),
        'versions'   => $history['versions'],
        'builtin_count' => $history['builtin_count'],
        'limits'     => ['min_levels' => ChangeRiskPolicy::MIN_LEVELS, 'max_levels' => ChangeRiskPolicy::MAX_LEVELS,
                         'min_results' => ChangeRiskPolicy::MIN_RESULTS, 'max_results' => ChangeRiskPolicy::MAX_RESULTS,
                         'label_max' => ChangeRiskPolicy::LABEL_MAX, 'criteria_max' => ChangeRiskPolicy::CRITERIA_MAX,
                         'name_max' => ChangeRiskPolicy::NAME_MAX],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
