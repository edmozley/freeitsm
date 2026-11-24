<?php
/**
 * API: List workflows for the landing page.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../workflow/includes/engine.php';   // runOrderReady()
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('workflow');

try {
    $conn = connectToDatabase();
    // GH #209: the shared workflows plus the switched-to company's own, each
    // with the company it is for (null = shared) and whether this analyst may
    // change it. Nothing changes on a single-company install.
    require_once __DIR__ . '/../../includes/workflow_tenancy.php';
    $analystId = (int)$_SESSION['analyst_id'];
    $ready = workflowTenancyReady($conn);
    [$tSql, $tArgs] = workflowListFilter($conn, $analystId, 'w.tenant_id');
    $stmt = $conn->prepare(
        "SELECT w.id, w.name, w.description, w.trigger_event, w.is_active,"
        . (WorkflowEngine::runOrderReady($conn) ? " w.run_order, w.stop_after," : " 0 AS run_order, 0 AS stop_after,") . "
                w.last_run_datetime, w.last_run_status, w.run_count,
                w.created_datetime, w.updated_datetime"
        . ($ready ? ", w.tenant_id, tn.name AS company_name" : ", NULL AS tenant_id, NULL AS company_name") . "
         FROM workflows w"
        . ($ready ? " LEFT JOIN tenants tn ON tn.id = w.tenant_id" : '') . "
         WHERE 1=1$tSql
         ORDER BY w.updated_datetime DESC, w.id DESC"
    );
    $stmt->execute($tArgs);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $multi = workflowTenancyActive($conn);
    foreach ($rows as &$row) {
        $tid = $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null;
        $row['company']  = $tid === null ? null : ['id' => $tid, 'name' => (string)$row['company_name']];
        $row['can_edit'] = analystCanWriteWorkflowFor($conn, $analystId, $tid);
        unset($row['tenant_id'], $row['company_name']);
    }
    unset($row);
    echo json_encode(['success' => true, 'workflows' => $rows, 'multi_company' => $multi]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
