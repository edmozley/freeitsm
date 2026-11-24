<?php
/**
 * API: the order the workflows on one trigger run in, and nothing else (discussion #304).
 *
 * POST { trigger_event, ids: [workflow id, first to last] }
 *
 * Every workflow on that trigger must be in the list, exactly once - active or not - so the
 * order saved is the whole order, never half of one. They are numbered 10, 20, 30...
 * (WorkflowEngine::dispatch() runs them ORDER BY run_order).
 *
 * Who may: someone who may edit EVERY workflow on the trigger. On an install with
 * companies, moving a shared workflow, or another company's, would change when it runs for
 * somebody else - so an analyst who cannot edit one of them cannot reorder the trigger.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once __DIR__ . '/../../workflow/includes/engine.php';
require_once __DIR__ . '/../../includes/workflow_tenancy.php';
csrfRequireWriteMethod();
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('workflow');

try {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $trigger = trim((string)($in['trigger_event'] ?? ''));
    $ids = array_values(array_map('intval', (array)($in['ids'] ?? [])));
    $conn = connectToDatabase();
    if (!WorkflowEngine::runOrderReady($conn)) throw new InvalidArgumentException(t('workflow.order.needs_db_verify'));
    $analystId = (int)$_SESSION['analyst_id'];

    $hasTenant = workflowTenancyReady($conn);
    $st = $conn->prepare("SELECT id" . ($hasTenant ? ", tenant_id" : ", NULL AS tenant_id") . " FROM workflows WHERE trigger_event = ?");
    $st->execute([$trigger]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) throw new InvalidArgumentException(t('workflow.order.no_workflows'));
    foreach ($rows as $r) {
        if (!analystCanWriteWorkflowFor($conn, $analystId, $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null)) {
            throw new InvalidArgumentException(t('workflow.order.not_all_yours'));
        }
    }
    $all = array_map('intval', array_column($rows, 'id'));
    $a = $ids; sort($a); sort($all);
    if ($a !== $all) throw new InvalidArgumentException(t('workflow.order.list_changed'));

    $conn->beginTransaction();
    $up = $conn->prepare("UPDATE workflows SET run_order = ? WHERE id = ?");
    foreach ($ids as $i => $id) $up->execute([($i + 1) * 10, $id]);
    $conn->commit();
    echo json_encode(['success' => true]);
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('workflow save_order: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => t('workflow.order.save_failed')]);
}
