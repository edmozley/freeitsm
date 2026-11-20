<?php
/**
 * API: Get a single workflow with its conditions + actions decoded, plus
 * the latest execution rows so the editor can show a recent-runs sidebar.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../includes/webhook_delivery.php';   // webhookDecrypt()
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('workflow');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT * FROM workflows WHERE id = ?");
    $stmt->execute([$id]);
    $wf = $stmt->fetch(PDO::FETCH_ASSOC);
    // GH #209: another company's workflow is not found, not forbidden.
    require_once __DIR__ . '/../../includes/workflow_tenancy.php';
    $analystId = (int)$_SESSION['analyst_id'];
    if (!$wf || !analystCanReadWorkflow($conn, $analystId, $wf)) {
        echo json_encode(['success' => false, 'error' => 'Workflow not found']);
        exit;
    }
    $wfTenant = isset($wf['tenant_id']) && $wf['tenant_id'] !== null ? (int)$wf['tenant_id'] : null;
    // A shared workflow runs for every company, so someone who cannot see them
    // all may read it but not change it - and does not get its webhook secrets.
    $wf['can_edit'] = analystCanWriteWorkflowFor($conn, $analystId, $wfTenant);
    $wf['company']  = $wfTenant === null ? null : ['id' => $wfTenant, 'name' => workflowCompanyName($conn, $wfTenant)];

    // Decode JSON columns for the client.
    $wf['conditions'] = json_decode($wf['conditions'] ?: '[]', true) ?: [];
    $wf['actions']    = json_decode($wf['actions']    ?: '[]', true) ?: [];

    // Webhook URL + signing secret are encrypted at rest — decrypt for the editor,
    // which needs to show the analyst the endpoint their workflow posts to and the
    // secret it signs with. (Plaintext values written before encryption was added
    // pass straight through.) See the divergence note in save.php for why these
    // are not masked the way API keys are.
    // At every depth (GH #364): a webhook inside a branch left encrypted here would be
    // encrypted AGAIN on the next save - see webhookMapWebhooks().
    $canEdit = !empty($wf['can_edit']);
    $wf['actions'] = webhookMapWebhooks($wf['actions'], function (array $a) use ($canEdit): array {
        foreach (['url', 'secret'] as $k) {
            if (isset($a['args'][$k]) && $a['args'][$k] !== '') $a['args'][$k] = $canEdit ? webhookDecrypt((string)$a['args'][$k]) : '(hidden)';
        }
        return $a;
    });

    // Last 20 executions, newest first. step_log comes along so the editor can
    // expand a run — it's what makes a dry run readable ("this is what it
    // would have done"), not just a status pill.
    // GH #209: only the runs for companies this analyst can see.
    [$eSql, $eArgs] = workflowExecutionFilter($conn, $analystId, 'tenant_id');
    $execStmt = $conn->prepare(
        "SELECT id, trigger_event, status, is_dry_run, started_datetime, finished_datetime, step_log, error_message
         FROM workflow_executions
         WHERE workflow_id = ?$eSql
         ORDER BY started_datetime DESC, id DESC
         LIMIT 20"
    );
    $execStmt->execute(array_merge([$id], $eArgs));
    $executions = $execStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($executions as &$ex) {
        $ex['is_dry_run'] = (int)$ex['is_dry_run'];
        $ex['step_log']   = json_decode($ex['step_log'] ?: '[]', true) ?: [];
    }
    unset($ex);

    echo json_encode(['success' => true, 'workflow' => $wf, 'executions' => $executions]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
