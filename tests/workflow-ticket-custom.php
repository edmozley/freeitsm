<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Workflows: {{ticket.custom.<field name>}} (discussion #304, slice 5 of wiki
 * Workflow-Order-Design). A ticket's custom fields by name, as a person reads the
 * value; empty (not the code itself) when not filled in; offered in the picker and
 * not flagged as unknown by the editor. Rolled back.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/workflow-ticket-custom.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/ticket_fields.php';
require_once __DIR__ . '/../workflow/includes/engine.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}
echo "\nWorkflow merge codes: ticket custom fields\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) exit("Set FREEITSM_TEST_DATABASE to the throwaway database.\n");
if (!TicketFieldsService::schemaReady($conn)) { echo "  SKIP  no ticket custom fields here\n"; exit(0); }
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ticket = $conn->query("SELECT id, tenant_id, ticket_type_id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$admin || !$ticket) { echo "  SKIP  needs an administrator and a ticket\n"; exit(0); }

$render = new ReflectionMethod('WorkflowEngine', 'renderTemplate');
$render->setAccessible(true);

$conn->beginTransaction();
try {
    $ctx = new ActorContext($admin, null, 'ui', 'en', 'Test');
    $tag = 'ZZ' . bin2hex(random_bytes(2));
    $f1 = TicketFieldsService::saveField($conn, $ctx, ['label' => "$tag Start date", 'field_type' => 'text', 'all_types' => 1]);
    $f2 = TicketFieldsService::saveField($conn, $ctx, ['label' => "$tag Laptop", 'field_type' => 'boolean', 'all_types' => 1]);
    $f3 = TicketFieldsService::saveField($conn, $ctx, ['label' => "$tag Empty one", 'field_type' => 'text', 'all_types' => 1]);
    $defs = TicketFieldsService::fieldsForTicket($conn, $ticket);
    $byLabel = array_column(array_map(fn($d) => ['l' => $d['label'], 'k' => $d['key']], $defs), 'k', 'l');
    $k1 = $byLabel["$tag Start date"] ?? null; $k2 = $byLabel["$tag Laptop"] ?? null;
    ok('three ticket fields made and the ticket has them', $k1 && $k2 && isset($defs[$k1], $defs[$k2]), json_encode(array_keys($defs)));
    TicketFieldsService::write($conn, $ctx, (int)$ticket['id'], $defs, [$k1 => '2026-12-01', $k2 => true]);

    $p = WorkflowEngine::enrichPayloadForTemplates($conn, ['ticket' => ['id' => (int)$ticket['id']]]);
    ok('the values are under ticket.custom, by name', ($p['ticket']['custom']["$tag Start date"] ?? null) === '2026-12-01', json_encode($p['ticket']['custom'] ?? null));
    ok('a yes/no reads as a person reads it', ($p['ticket']['custom']["$tag Laptop"] ?? null) === 'Yes');
    $out = $render->invoke(null, "New starter: {{ticket.custom.$tag Start date}}, laptop {{ticket.custom.$tag Laptop}}, [{{ticket.custom.$tag Empty one}}]", $p);
    ok('the merge codes render', $out === "New starter: 2026-12-01, laptop Yes, []", $out);
    ok('a payload that already has ticket.custom is left alone', WorkflowEngine::enrichWithTicketCustom($conn, ['ticket' => ['id' => (int)$ticket['id'], 'custom' => ['x' => 'y']]])['ticket']['custom'] === ['x' => 'y']);
    ok('no ticket, nothing added', !isset(WorkflowEngine::enrichWithTicketCustom($conn, ['task' => ['id' => 1]])['ticket']));

    $trigger = 'ticket.created';
    $paths = array_column(WorkflowEngine::availableVariables($trigger), 'path');
    ok('the picker offers it on a ticket trigger', in_array('ticket.custom.Your field name', $paths, true));
    ok('the editor does not flag it as unknown', in_array('ticket.custom.', WorkflowEngine::variablePrefixes($trigger), true));
} finally {
    $conn->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
