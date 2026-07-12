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
$catalogue = WorkflowEngine::availableActions();
foreach ($actions as $a) {
    $type = $a['type'] ?? '';
    if (!isset($catalogue[$type])) {
        echo json_encode(['success' => false, 'error' => "Unknown action type: {$type}"]);
        exit;
    }
}

try {
    $conn = connectToDatabase();

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
    foreach ($actions as $i => $a) {
        if (($a['type'] ?? '') !== 'send_webhook') continue;
        foreach (['url', 'secret'] as $k) {
            if (isset($a['args'][$k]) && $a['args'][$k] !== '') {
                // encryptValue() won't double-encrypt an already-"ENC:" value.
                $actions[$i]['args'][$k] = webhookEncrypt((string)$a['args'][$k]);
            }
        }
    }

    $condJson = json_encode($conditions);
    $actJson  = json_encode($actions);
    if ($id) {
        $stmt = $conn->prepare(
            "UPDATE workflows
             SET name = ?, description = ?, trigger_event = ?, conditions = ?, actions = ?, is_active = ?
             WHERE id = ?"
        );
        $stmt->execute([$name, $description, $triggerEvent, $condJson, $actJson, $isActive, $id]);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO workflows
             (name, description, trigger_event, conditions, actions, is_active, created_by, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute([
            $name, $description, $triggerEvent, $condJson, $actJson, $isActive,
            (int)$_SESSION['analyst_id'],
        ]);
        $id = (int)$conn->lastInsertId();
    }
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
