<?php
/**
 * API Endpoint: standard-change templates for Settings → Templates (#180 §8).
 *
 * Each template with its working draft, who may use it, and every version (state, who
 * submitted and approved it). Open to holders of "Write standard-change templates" and of
 * "Approve standard-change templates" - an approver has to be able to read what they
 * approve. includes/changes/templates.php has the model.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/changes/templates.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');

try {
    $conn = connectToDatabase();
    $me = (int)$_SESSION['analyst_id'];
    $canWrite   = analystHasCapability($conn, $me, Cap::CHANGES_TEMPLATES);
    $canApprove = analystMayAct($conn, $me, Cap::CHANGES_TEMPLATES_APPROVE);
    if (!$canWrite && !$canApprove) {
        requireCapabilityJson(Cap::CHANGES_TEMPLATES, $conn);   // the ordinary refusal
    }

    $templates = [];
    foreach ($conn->query("SELECT * FROM change_templates ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $id = (int)$t['id'];
        $teams = $conn->prepare("SELECT team_id FROM change_template_teams WHERE template_id = ?");
        $teams->execute([$id]);
        $vers = $conn->prepare(
            "SELECT v.id, v.version_no, v.state, v.created_by_id, cb.full_name AS created_by_name, v.created_datetime,
                    v.approved_by_id, ap.full_name AS approved_by_name, v.approved_datetime, v.approval_note
               FROM change_template_versions v
               LEFT JOIN analysts cb ON cb.id = v.created_by_id
               LEFT JOIN analysts ap ON ap.id = v.approved_by_id
              WHERE v.template_id = ? ORDER BY v.version_no DESC"
        );
        $vers->execute([$id]);
        $draft = [];
        foreach (ChangeTemplates::FIELDS as $col => [$tcol, $_]) $draft[$col] = $t[$tcol];
        $templates[] = [
            'id'                 => $id,
            'name'               => $t['name'],
            'scope_use'          => $t['scope_use'],
            'scope_not'          => $t['scope_not'],
            'open_fields'        => array_values(array_filter(explode(',', (string)$t['open_fields']))),
            'values'             => $draft,
            'team_ids'           => array_map('intval', $teams->fetchAll(PDO::FETCH_COLUMN)),
            'current_version_id' => $t['current_version_id'] !== null ? (int)$t['current_version_id'] : null,
            // The risk policy its ratings were given under (#246); null = built-in.
            'risk_policy_version_id' => isset($t['risk_policy_version_id']) ? (int)$t['risk_policy_version_id'] ?: null : null,
            'retired'            => $t['retired_datetime'] !== null,
            'versions'           => $vers->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
    echo json_encode([
        'success'     => true,
        'templates'   => $templates,
        'can_write'   => $canWrite,
        'can_approve' => $canApprove,
        'me'          => $me,
        'fields'      => array_keys(ChangeTemplates::FIELDS),
        'teams'       => $conn->query("SELECT id, name FROM teams WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
        'enabled'     => changeApprovalSettings($conn)['templates_enabled'],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
