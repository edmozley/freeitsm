<?php
/**
 * API: Send a TEST outbound webhook from the workflow editor — the "Send test"
 * button. Takes the send_webhook action's current config, renders its templates
 * against a representative sample payload, and delivers it synchronously so the
 * user sees the endpoint's real response before saving the workflow.
 *
 * Unlike a real fire this does NOT enqueue or touch the delivery log — it's a
 * one-off preview. It uses the exact same request builder (WorkflowEngine::
 * buildWebhookRequest) and transport (webhookHttpSend) as a real delivery, so
 * "it worked in test" means it will work live.
 *
 * Body: { url, preset?, message?, body?, secret?, trigger_event? }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../workflow/includes/engine.php';
require_once __DIR__ . '/../../includes/webhook_delivery.php';
require_once __DIR__ . '/../v1/lib/response.php';
require_once __DIR__ . '/../v1/resources/tickets.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('workflow');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];

$args = [
    'preset'  => (string)($in['preset'] ?? 'custom'),
    'url'     => (string)($in['url'] ?? ''),
    'message' => (string)($in['message'] ?? ''),
    'body'    => (string)($in['body'] ?? ''),
    'secret'  => (string)($in['secret'] ?? ''),
];
// The editor holds these in plaintext (get.php decrypts on load), so they normally
// arrive plain. Decrypt defensively anyway: a stale client could post back a stored
// "ENC:" value, and we'd otherwise POST to a base64 blob and sign with ciphertext.
foreach (['url', 'secret'] as $k) {
    if ($args[$k] !== '') $args[$k] = webhookDecrypt($args[$k]);
}

// A representative sample payload so {{ticket.*}} / {{event}} variables render
// to realistic values in the test send. We prefer the most recent REAL ticket
// (so the Full-record format has an actual object to send and template vars look
// true-to-life); if the install has no tickets yet, we fall back to synthetic.
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$appBase = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;
$conn = connectToDatabase();

// The sample MUST mirror the real dispatch payload, key for key. It previously
// invented fields (ticket.priority, ticket.status, ticket.company, ticket.url)
// that no real ticket event carries — so a test would render "High", you'd ship
// it, and in production the same code would render an empty string. A preview
// that shows values production won't produce is worse than no preview.
// The canonical shape is WorkflowEngine::availableFields('ticket.created').
$sample = [
    'event'  => (string)($in['trigger_event'] ?? 'ticket.updated'),
    'ticket' => [
        'id'                  => 1024,
        // Synthetic fallback only (used when the install has no tickets yet).
        // With a real ticket, enrichPayloadForTemplates() fills this from
        // tickets.ticket_number and this placeholder is never seen.
        'number'              => 'ABC-123-45678',
        'subject'             => 'Sample ticket — webhook test',
        'priority_id'         => 1,
        'status_id'           => 1,
        'department_id'       => null,
        'type_id'             => null,
        'assigned_analyst_id' => null,
        'owner_id'            => null,
        'origin_id'           => null,
        'created_by'          => null,
        'requester_email'     => 'jane@example.com',
    ],
];

try {
    $realId = (int)($conn->query("SELECT id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
    if ($realId) {
        $stmt = $conn->prepare(apiTicketSelect() . ' WHERE t.id = ? LIMIT 1');
        $stmt->execute([$realId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $ser = apiSerializeTicket($row);
            // Same keys a real ticket.* dispatch carries — nothing invented.
            // `full` is genuine: it's what {{ticket.full}} resolves to at run time.
            $sample['ticket'] = [
                'id'                  => $ser['id'],
                'subject'             => $ser['subject'],
                'priority_id'         => $ser['priority']['id']         ?? null,
                'status_id'           => $ser['status']['id']           ?? null,
                'department_id'       => $ser['department']['id']       ?? null,
                'type_id'             => $ser['ticket_type']['id']      ?? null,
                'assigned_analyst_id' => $ser['assigned_analyst']['id'] ?? null,
                'owner_id'            => $ser['owner']['id']            ?? null,
                'origin_id'           => $ser['origin']['id']           ?? null,
                'created_by'          => null,
                'requester_email'     => $ser['requester']['email']     ?? null,
                'full'                => $ser,
            ];
        }
    }
} catch (Throwable $e) { /* keep the synthetic sample */ }

// Enrich exactly as the engine does at run time — same single entry point — so
// {{ticket.priority_name}} previews as "Critical" and {{ticket.number}} as the
// real reference, and both render identically in production. The preview cannot
// drift from the real thing because they call the same function.
$sample = WorkflowEngine::enrichPayloadForTemplates($conn, $sample);

// 1) Build the request the same way a real send would (validates url, renders
//    templates, builds the preset/custom body, signs if a secret is set).
try {
    $req = WorkflowEngine::buildWebhookRequest($args, $sample);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'stage'   => 'build',
        'error'   => $e->getMessage(),
    ]);
    exit;
}

// 2) Send it synchronously over the identical transport used by the worker.
$res = webhookHttpSend($req['url'], $req['headers'], $req['body'], 'POST');
$delivered = ($res['body'] !== false && $res['error'] === '' && $res['status'] >= 200 && $res['status'] < 300);

echo json_encode([
    'success'   => true,
    'delivered' => $delivered,
    'request'   => [
        'url'     => $req['url'],
        'preset'  => $req['preset'],
        'signed'  => $req['signed'],
        'headers' => $req['headers'],
        'body'    => $req['body'],
    ],
    'response'  => [
        'status' => $res['status'],
        'ms'     => $res['ms'],
        'error'  => $res['error'],
        'body'   => $res['body'] === false ? null : mb_substr((string)$res['body'], 0, 20000),
    ],
    // A raw transport error ("unable to get local issuer certificate") says what
    // broke, not what to do about it. Where we can recognise the cause, send the
    // client a plain-English explanation and a link to the fix.
    'diagnosis' => webhookDiagnoseError($res['error']),
]);
