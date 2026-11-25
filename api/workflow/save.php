<?php
/**
 * API: Save (create or update) a workflow.
 *
 * Body shape:
 *   { id?, name, description, trigger_event, conditions: [...], actions: [...], is_active }
 *
 * conditions and actions are stored JSON-encoded — see workflow/includes/engine.php
 * for the contract each entry must obey.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../workflow/includes/engine.php';
require_once __DIR__ . '/../../includes/webhook_delivery.php';   // webhookEncrypt()
header('Content-Type: application/json');

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('workflow');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    echo json_encode(['success' => false, 'error' => 'Bad payload']);
    exit;
}

$id            = isset($in['id']) ? (int)$in['id'] : 0;
$name          = trim((string)($in['name'] ?? ''));
$description   = trim((string)($in['description'] ?? ''));
$triggerEvent  = trim((string)($in['trigger_event'] ?? ''));
$conditions    = is_array($in['conditions'] ?? null) ? $in['conditions'] : [];
$actions       = is_array($in['actions']    ?? null) ? $in['actions']    : [];
$isActive      = !empty($in['is_active']) ? 1 : 0;
$stopAfter     = !empty($in['stop_after']) ? 1 : 0;   // discussion #304: "Stop here when this one runs"

if ($name === '') {
    echo json_encode(['success' => false, 'error' => 'Name is required']);
    exit;
}
if (!array_key_exists($triggerEvent, WorkflowEngine::availableTriggers())) {
    echo json_encode(['success' => false, 'error' => 'Unknown trigger event']);
    exit;
}
// No "actions required" check — the engine tolerates 0 actions (the loop
// just doesn't run), and the client warns the user that the workflow is a
// no-op rather than blocking the save. Lets you save in-progress drafts.

// Validate each action's type is in the catalogue so we don't store something
// the engine can't execute.
// GH #364: at every depth - a branch's paths hold actions too - and every branch gets
// its Otherwise / Default, so an unexpected value always has somewhere to go.
if (($treeError = WorkflowEngine::validateActionTree($actions)) !== null) {
    echo json_encode(['success' => false, 'error' => $treeError]);
    exit;
}
$actions = WorkflowEngine::normaliseActionTree($actions);

try {
    $conn = connectToDatabase();

    // GH #209. Which company the workflow is for. A shared one (null) runs for
    // every company, so only someone who can see them all may write one; a
    // company's own, anyone who can see that company - both for the workflow as
    // stored and for where it is being moved. Not sent: an existing workflow
    // keeps its company, a new one is shared for an every-company analyst and
    // the switched-to company for anyone else.
    require_once __DIR__ . '/../../includes/workflow_tenancy.php';
    $analystId = (int)$_SESSION['analyst_id'];
    $tenancyOn = workflowTenancyActive($conn);
    $existing  = $id ? workflowRowTenant($conn, $id) : null;
    if ($id && (!$existing || !analystCanReadWorkflow($conn, $analystId, $existing))) {
        echo json_encode(['success' => false, 'error' => 'Workflow not found']);
        exit;
    }
    $newTenant = null;
    if ($tenancyOn) {
        if ($existing && !analystCanWriteWorkflowFor($conn, $analystId, $existing['tenant_id'])) {
            echo json_encode(['success' => false, 'error' => 'This workflow runs for every company, so only someone who can see every company can change it.']);
            exit;
        }
        if (array_key_exists('company_id', $in)) {
            [$ok, $newTenant, $err] = workflowParseCompany($conn, $in['company_id']);
            if (!$ok) { echo json_encode(['success' => false, 'error' => $err]); exit; }
        } elseif ($existing) {
            $newTenant = $existing['tenant_id'];
        } else {
            $newTenant = analystHasAllTenantAccess($conn, $analystId) ? null : getActiveTenantId($conn, $analystId);
        }
        if (!analystCanWriteWorkflowFor($conn, $analystId, $newTenant)) {
            echo json_encode(['success' => false, 'error' => $newTenant === null
                ? 'Only someone who can see every company can make a workflow that runs for all of them.'
                : 'You cannot see that company.']);
            exit;
        }
    }

    // Webhook credentials are encrypted at rest (see includes/webhook_delivery.php).
    // The URL is a bearer credential (anyone holding a Slack/Discord URL can post
    // to that channel) and the signing secret is a true secret — a reader of the
    // database could otherwise forge our signature.
    //
    // DELIBERATE DIVERGENCE from the API-key convention (encrypt + mask in the UI).
    // These are NOT masked back to the editor, and it is not an oversight:
    //
    // Masking means the browser posts back a "****" placeholder meaning "keep what
    // you already have" — so the server must work out WHICH stored secret that
    // refers to. On a settings page with one API key that's trivial. Here it isn't:
    // workflow actions are boxes on a canvas, and the engine orders them by their
    // vertical position, so an action's position IS its identity. Drag a box and
    // "action 1" becomes a different action. Restore secrets by position and you
    // hand Slack's secret to the Discord webhook — breaking both, silently, because
    // someone moved a node.
    //
    // Returning them decrypted leaves nothing to map, so nothing to get wrong.
    // Encryption at rest still does its job: it defends against a stolen database
    // or backup, not against an admin reading a workflow they own and could edit
    // anyway. If masking is ever wanted, give actions a STABLE ID first.
    $actions = webhookEncryptActions($actions);   // includes/webhook_delivery.php - every save route uses it

    $condJson = json_encode($conditions);
    $actJson  = json_encode($actions);
    if ($id) {
        $oldTrigger = (string)$conn->query("SELECT trigger_event FROM workflows WHERE id = " . (int)$id)->fetchColumn();
        $tSet = $tenancyOn ? ', tenant_id = ?' : '';
        $stmt = $conn->prepare(
            "UPDATE workflows
             SET name = ?, description = ?, trigger_event = ?, conditions = ?, actions = ?, is_active = ?$tSet
             WHERE id = ?"
        );
        $vals = [$name, $description, $triggerEvent, $condJson, $actJson, $isActive];
        if ($tenancyOn) $vals[] = $newTenant;
        $vals[] = $id;
        $stmt->execute($vals);
        if (WorkflowEngine::runOrderReady($conn)) {
            // Discussion #304. A workflow moved to another trigger joins the end of that trigger's order.
            $conn->prepare("UPDATE workflows SET stop_after = ?, run_order = IF(? = 1, run_order, 0) WHERE id = ?")
                 ->execute([$stopAfter, (int)($oldTrigger === $triggerEvent), $id]);
        }
    } else {
        $tCol = $tenancyOn ? ', tenant_id' : '';
        $stmt = $conn->prepare(
            "INSERT INTO workflows
             (name, description, trigger_event, conditions, actions, is_active, created_by, created_datetime, updated_datetime$tCol)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()" . ($tenancyOn ? ', ?' : '') . ")"
        );
        $vals = [
            $name, $description, $triggerEvent, $condJson, $actJson, $isActive,
            (int)$_SESSION['analyst_id'],
        ];
        if ($tenancyOn) $vals[] = $newTenant;
        $stmt->execute($vals);
        $id = (int)$conn->lastInsertId();
        if ($stopAfter && WorkflowEngine::runOrderReady($conn)) {
            $conn->prepare("UPDATE workflows SET stop_after = 1 WHERE id = ?")->execute([$id]);
        }
    }
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
