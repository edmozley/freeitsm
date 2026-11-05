<?php
/**
 * API Endpoint: save a standard-change template's working draft, and optionally submit it
 * for approval as a new version (#180 §8).
 *
 *   POST { id?, name, scope_use, scope_not, values: {change column: value}, open_fields: [],
 *          team_ids: [], submit: bool }
 *
 * Behind "Write standard-change templates". Saving the draft never touches an approved
 * version: the version in use stays in use until a submitted one is approved.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/changes/templates.php';
require_once '../../includes/service_context.php';
require_once '../../includes/changes/risk_policy.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');
requireModuleWriteJson('changes');
requireCapabilityJson(Cap::CHANGES_TEMPLATES);   // settings tab - see docs/design/rbac.md

try {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new Exception(t('change-management.templates.name_required'));

    $values = is_array($in['values'] ?? null) ? $in['values'] : [];
    $cols = []; $args = [];
    foreach (ChangeTemplates::FIELDS as $col => [$tcol, $kind]) {
        $v = $values[$col] ?? null;
        $v = ($v === '' || $v === null) ? null : ($kind === 'short' ? (int)$v : (string)$v);
        $cols[] = $tcol;
        $args[] = $v;
    }
    $open = array_values(array_intersect(is_array($in['open_fields'] ?? null) ? $in['open_fields'] : [], array_keys(ChangeTemplates::FIELDS)));
    $teamIds = array_values(array_unique(array_filter(array_map('intval', is_array($in['team_ids'] ?? null) ? $in['team_ids'] : []))));

    $conn = connectToDatabase();
    $id = (int)($in['id'] ?? 0);

    // Risk ratings belong to a risk policy (#246). Templates are install-wide, so they use
    // the install's policy. Ratings sent back unchanged keep the scale they were given on;
    // new ones are checked against the policy in use and remember it.
    $riskReady = ChangeRiskPolicy::ready($conn);
    $riskVersion = null;
    if ($riskReady) {
        $policy = ChangeRiskPolicy::current($conn, null);
        $old = ['risk_likelihood' => null, 'risk_impact_score' => null, 'risk_policy_version_id' => null];
        if ($id > 0) {
            $o = $conn->prepare("SELECT risk_likelihood, risk_impact_score, risk_policy_version_id FROM change_templates WHERE id = ?");
            $o->execute([$id]);
            $old = $o->fetch(PDO::FETCH_ASSOC) ?: $old;
        }
        $li = array_search('risk_likelihood', array_keys(ChangeTemplates::FIELDS), true);
        $ii = array_search('risk_impact_score', array_keys(ChangeTemplates::FIELDS), true);
        $newL = $args[$li]; $newI = $args[$ii];
        $oldL = $old['risk_likelihood'] !== null ? (int)$old['risk_likelihood'] : null;
        $oldI = $old['risk_impact_score'] !== null ? (int)$old['risk_impact_score'] : null;
        if ($newL === $oldL && $newI === $oldI && empty($in['risk_reassess'])) {
            $riskVersion = $old['risk_policy_version_id'] !== null ? (int)$old['risk_policy_version_id'] : null;
        } else {
            $args[$li] = ChangeRiskPolicy::rating($newL, 'risk_likelihood', $policy['content'], 'likelihood');
            $args[$ii] = ChangeRiskPolicy::rating($newI, 'risk_impact_score', $policy['content'], 'impact');
            $riskVersion = ($args[$li] !== null || $args[$ii] !== null) ? $policy['version_id'] : null;
        }
        $cols[] = 'risk_policy_version_id';
        $args[] = $riskVersion;
    }

    $conn->beginTransaction();
    if ($id > 0) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $conn->prepare("UPDATE change_templates SET name = ?, scope_use = ?, scope_not = ?, open_fields = ?, $set WHERE id = ?")
             ->execute(array_merge([$name, trim((string)($in['scope_use'] ?? '')) ?: null, trim((string)($in['scope_not'] ?? '')) ?: null, implode(',', $open)], $args, [$id]));
    } else {
        $conn->prepare("INSERT INTO change_templates (name, scope_use, scope_not, open_fields, " . implode(', ', $cols) . ", created_by_id, created_datetime)
                        VALUES (?, ?, ?, ?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, UTC_TIMESTAMP())")
             ->execute(array_merge([$name, trim((string)($in['scope_use'] ?? '')) ?: null, trim((string)($in['scope_not'] ?? '')) ?: null, implode(',', $open)], $args, [(int)$_SESSION['analyst_id']]));
        $id = (int)$conn->lastInsertId();
    }
    $conn->prepare("DELETE FROM change_template_teams WHERE template_id = ?")->execute([$id]);
    $ins = $conn->prepare("INSERT INTO change_template_teams (template_id, team_id) VALUES (?, ?)");
    foreach ($teamIds as $tid) $ins->execute([$id, $tid]);

    $versionId = null;
    if (!empty($in['submit'])) {
        $versionId = ChangeTemplates::submitVersion($conn, $id, (int)$_SESSION['analyst_id']);
    }
    $conn->commit();
    echo json_encode(['success' => true, 'id' => $id, 'version_id' => $versionId]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
