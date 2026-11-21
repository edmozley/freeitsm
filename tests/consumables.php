<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Assets -> Consumables (5.8.0): the ledger's rules.
 *
 * Pins what the design promises (wiki Consumables-Design): stock is a ledger and
 * on hand always equals it; never below zero; an adjustment and finishing a stock
 * take need the adjust permission and a reason; a mistake is undone by a reversing
 * movement, once; a receipt at a new price becomes the item's price and each
 * movement keeps the price it was valued at; an issue against a ticket is in the
 * ticket's history; low stock tells the chosen analysts once per dip; a stock take
 * records expected, counted and the difference and its value at that moment, and
 * posts the difference - not the counted figure - when it is finished; custom
 * fields per type, recorded in the item's history.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/consumables.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/consumables.php';
require_once __DIR__ . '/../includes/documents.php';
require_once __DIR__ . '/../includes/services/forms.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}
/** The ServiceError code a call throws, or '' when it does not throw. */
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->errorCode; }
}

echo "\nConsumables\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes stock.\n");
}
if (!ConsumablesService::ready($conn)) { echo "  SKIP  run Database Verification first\n"; exit(0); }

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$plain = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id LIMIT 1")->fetchColumn();
$ticket = (int)$conn->query("SELECT id FROM tickets ORDER BY id DESC LIMIT 1")->fetchColumn();
$person = (int)$conn->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin || !$plain || !$ticket || !$person) { echo "  SKIP  needs an administrator, another analyst, a ticket and a person\n"; exit(0); }

$conn->beginTransaction();
try {
    // The plain analyst holds no role that grants adjusting, whatever the database has.
    $conn->prepare("DELETE FROM rbac_analyst_roles WHERE analyst_id = ?")->execute([$plain]);
    $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$plain]);
    $A = new ActorContext($admin, null, 'ui', 'en', 'Test admin');
    $P = new ActorContext($plain, null, 'ui', 'en', 'Test analyst');
    $tag = 'zz' . bin2hex(random_bytes(3));
    $onHand = function (int $item) use ($conn): int {
        $st = $conn->prepare("SELECT on_hand FROM consumable_stock WHERE item_id = ?");
        $st->execute([$item]);
        return (int)$st->fetchColumn();
    };
    $ledger = function (int $item) use ($conn): int {
        $st = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM consumable_movements WHERE item_id = ?");
        $st->execute([$item]);
        return (int)$st->fetchColumn();
    };
    $audit = function (int $item, string $field) use ($conn): array {
        $st = $conn->prepare("SELECT old_value, new_value FROM consumable_audit WHERE item_id = ? AND field_name = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$item, $field]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // ---- types, items, fields ---------------------------------------------
    $toner = ConsumablesService::saveType($conn, $A, ['name' => "$tag Toner"]);
    $paper = ConsumablesService::saveType($conn, $A, ['name' => "$tag Paper"]);
    $yield = RecordFieldsService::saveField($conn, $A, 'consumable', ['label' => "$tag Yield", 'field_type' => 'number', 'scope_id' => $toner]);
    $gsm   = RecordFieldsService::saveField($conn, $A, 'consumable', ['label' => "$tag Weight", 'field_type' => 'number', 'scope_id' => $paper]);
    $item = ConsumablesService::saveItem($conn, $A, ['name' => "$tag HP 26A", 'type_id' => $toner, 'unit' => 'each',
        'min_level' => 1, 'reorder_point' => 3, 'fields' => [$yield['key'] => 3100, $gsm['key'] => 80]]);
    ok('an item is made, and its making recorded', ($audit($item, 'created')['new_value'] ?? '') === "$tag HP 26A");
    $got = ConsumablesService::getItem($conn, $A, $item);
    $keys = array_column($got['fields'], 'key');
    ok('it has its own type\'s field', in_array($yield['key'], $keys, true));
    ok('...and not another type\'s', !in_array($gsm['key'], $keys, true));
    ok('a field answer is in the item\'s history', ($audit($item, "$tag Yield")['new_value'] ?? '') === '3100');
    ok('starts with nothing on hand, status out', $got['on_hand'] === 0 && $got['status'] === 'out');

    ConsumablesService::saveItem($conn, $A, ['id' => $item, 'name' => "$tag HP 26A", 'type_id' => $toner, 'unit' => 'each', 'min_level' => 1, 'reorder_point' => 4]);
    ok('a changed level is recorded, old and new', ($audit($item, 'reorder_point') ?: []) == ['old_value' => '3', 'new_value' => '4']);

    // ---- receive, issue, never below zero -------------------------------------
    $r = ConsumablesService::move($conn, $A, 'receive', $item, ['quantity' => 10, 'unit_cost' => '12.50', 'invoice_ref' => 'INV-1', 'invoice_date' => '2026-11-01']);
    ok('received 10', $r['on_hand'] === 10 && $onHand($item) === 10);
    ok('the delivery\'s price is the item\'s price now, and recorded', ConsumablesService::getItem($conn, $A, $item)['unit_cost'] === 12.5
        && ($audit($item, 'unit_cost')['new_value'] ?? '') === '12.50');
    $issue = ConsumablesService::move($conn, $A, 'issue', $item, ['quantity' => 4, 'ticket_id' => $ticket, 'recipient_user_id' => $person]);
    ok('issued 4: 6 left', $issue['on_hand'] === 6);
    $ta = $conn->prepare("SELECT new_value FROM ticket_audit WHERE ticket_id = ? AND field_name = 'Consumable issued' ORDER BY id DESC LIMIT 1");
    $ta->execute([$ticket]);
    ok('the ticket\'s history says what went out', str_contains((string)$ta->fetchColumn(), "4 x $tag HP 26A"));
    $mv = ConsumablesService::movements($conn, ['ticket' => $ticket])[0] ?? [];
    ok('the movement keeps who, the person and its value', $mv['recipient_user_id'] === $person && $mv['value'] === -50.0 && $mv['analyst_id'] === $admin);
    ok('more than is there is refused', refused(fn() => ConsumablesService::move($conn, $A, 'issue', $item, ['quantity' => 7])) === 'not_enough' && $onHand($item) === 6);
    ok('a quantity of 0 or less is refused', refused(fn() => ConsumablesService::move($conn, $A, 'issue', $item, ['quantity' => 0])) === 'invalid_field'
        && refused(fn() => ConsumablesService::move($conn, $A, 'receive', $item, ['quantity' => -2])) === 'invalid_field');

    // ---- adjust: the permission and a reason ------------------------------------
    ok('an analyst without the permission cannot adjust', refused(fn() => ConsumablesService::move($conn, $P, 'adjust', $item, ['quantity' => -1, 'reason' => 'x'])) === 'forbidden');
    ok('an adjustment needs a reason', refused(fn() => ConsumablesService::move($conn, $A, 'adjust', $item, ['quantity' => -1])) === 'missing_field');
    ConsumablesService::move($conn, $A, 'adjust', $item, ['quantity' => -1, 'reason' => 'Damaged']);
    ok('an administrator adjusts: 5', $onHand($item) === 5);

    // ---- undo: once, and never an undo ------------------------------------------
    $rev = ConsumablesService::reverse($conn, $A, $issue['movement_id'], 'Issued to the wrong ticket');
    ok('undoing the issue puts the 4 back: 9', $rev['on_hand'] === 9);
    ok('it cannot be undone twice', refused(fn() => ConsumablesService::reverse($conn, $A, $issue['movement_id'], 'again')) === 'already_reversed');
    ok('an undo cannot be undone', refused(fn() => ConsumablesService::reverse($conn, $A, $rev['movement_id'], 'x')) === 'not_reversible');
    ok('an undo needs a reason', refused(fn() => ConsumablesService::move($conn, $A, 'issue', $item, ['quantity' => 1])) === ''
        && refused(fn() => ConsumablesService::reverse($conn, $A, (int)$conn->query("SELECT MAX(id) FROM consumable_movements")->fetchColumn(), ' ')) === 'missing_field');
    ok('the original movement was never changed', (int)$conn->query("SELECT quantity FROM consumable_movements WHERE id = " . (int)$issue['movement_id'])->fetchColumn() === -4);

    // ---- low stock: once per dip --------------------------------------------------
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('consumables_low_notify', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string)$plain]);
    $bells = function () use ($conn, $plain, $item): int {
        $st = $conn->prepare("SELECT COALESCE(SUM(event_count), COUNT(*)) FROM notifications WHERE analyst_id = ? AND entity_type = 'consumable' AND entity_id = ?");
        $st->execute([$plain, $item]);
        return (int)$st->fetchColumn();
    };
    $before = $bells();
    ConsumablesService::move($conn, $A, 'issue', $item, ['quantity' => 4]);   // 8 -> 4: at the reorder point
    ok('falling to the reorder point tells the chosen analyst', $bells() === $before + 1, "bells {$bells()}");
    ConsumablesService::move($conn, $A, 'issue', $item, ['quantity' => 1]);
    ok('...once, not on every issue after', $bells() === $before + 1);
    ok('the list shows it as low', in_array($item, array_column(ConsumablesService::listItems($conn, $A, ['status' => 'low']), 'id'), true));
    ConsumablesService::move($conn, $A, 'receive', $item, ['quantity' => 10]);
    $st = $conn->prepare("SELECT low_notified_datetime FROM consumable_stock WHERE item_id = ?");
    $st->execute([$item]);
    ok('back above it, the next dip will tell again', $st->fetchColumn() === null);

    // ---- stock take ----------------------------------------------------------------
    $now = $onHand($item);   // 13
    $count = ConsumablesService::startCount($conn, $A, 'Basement');
    ok('only one stock take at a time', refused(fn() => ConsumablesService::startCount($conn, $A)) === 'count_open');
    $line = ConsumablesService::countLine($conn, $P, $count, $item, $now - 2);
    ok('counting 2 short records expected, counted, -2 and its value', $line['expected'] === $now && $line['difference'] === -2 && $line['value_difference'] === -25.0);
    ConsumablesService::move($conn, $A, 'issue', $item, ['quantity' => 1]);   // taken from the shelf after counting
    ok('an analyst without the permission cannot finish it', refused(fn() => ConsumablesService::finishCount($conn, $P, $count)) === 'forbidden');
    $fin = ConsumablesService::finishCount($conn, $A, $count);
    ok('finishing posts the difference, not the counted figure', $onHand($item) === $now - 1 - 2, "on hand {$onHand($item)}");
    ok('...and reports it', $fin['differences'] === 1 && $fin['units'] === -2 && $fin['value'] === -25.0);
    ok('the count is closed and cannot be counted again', refused(fn() => ConsumablesService::countLine($conn, $A, $count, $item, 1)) === 'count_closed');
    ok('a stock take\'s posting cannot be undone', refused(fn() => ConsumablesService::reverse($conn, $A,
        (int)$conn->query("SELECT id FROM consumable_movements WHERE count_id = $count")->fetchColumn(), 'x')) === 'not_reversible');
    $rep = ConsumablesService::differences($conn, $A, 3);
    $mine = array_values(array_filter($rep['items'], fn($r) => $r['id'] === $item))[0] ?? [];
    ok('the differences report has it, losses apart', ($mine['units_lost'] ?? null) === -2 && ($mine['value_lost'] ?? null) === -25.0 && ($mine['units_found'] ?? null) === 0);
    ok('...in this month\'s figures', end($rep['series'])['units_lost'] <= -2);

    // ---- deliveries: one order, several items, one place for its paperwork -----------
    $mouse = ConsumablesService::saveItem($conn, $A, ['name' => "$tag Mouse", 'type_id' => $paper, 'unit' => 'each']);
    $kb    = ConsumablesService::saveItem($conn, $A, ['name' => "$tag Keyboard", 'type_id' => $paper, 'unit' => 'each']);
    $before = [$onHand($item), $onHand($mouse), $onHand($kb)];
    $del = ConsumablesService::receiveDelivery($conn, $A, ['order_ref' => "PO-$tag", 'invoice_ref' => 'INV-77', 'invoice_date' => '2026-11-20',
        'lines' => [['item_id' => $item, 'quantity' => 10, 'unit_cost' => '12.50'], ['item_id' => $mouse, 'quantity' => 10, 'unit_cost' => '8'], ['item_id' => $kb, 'quantity' => 5, 'unit_cost' => '11.49']]]);
    ok('a delivery of three items books each in', $onHand($item) === $before[0] + 10 && $onHand($mouse) === 10 && $onHand($kb) === 5);
    $got = ConsumablesService::getDelivery($conn, $A, $del['delivery_id']);
    ok('...as one delivery, three lines, with its order number and value', count($got['lines']) === 3 && $got['order_ref'] === "PO-$tag" && $got['value'] === 262.45, json_encode([count($got['lines']), $got['order_ref'], $got['value']]));
    ok('each line shows its order number from the item\'s side', (ConsumablesService::movements($conn, ['item' => $mouse])[0]['order_ref'] ?? null) === "PO-$tag");
    ok('a delivery is all or nothing', refused(fn() => ConsumablesService::receiveDelivery($conn, $A, ['lines' => [['item_id' => $mouse, 'quantity' => 2], ['item_id' => $kb, 'quantity' => 0]]])) === 'invalid_field'
        && $onHand($mouse) === 10);
    ok('a delivery needs at least one line', refused(fn() => ConsumablesService::receiveDelivery($conn, $A, ['lines' => []])) === 'missing_field');
    $one = ConsumablesService::move($conn, $A, 'receive', $mouse, ['quantity' => 3, 'order_ref' => 'PO-single']);
    $did = (int)$conn->query("SELECT delivery_id FROM consumable_movements WHERE id = " . (int)$one['movement_id'])->fetchColumn();
    ok('a single Receive makes its own one-line delivery, order number and all', $did > 0 && ConsumablesService::getDelivery($conn, $A, $did)['order_ref'] === 'PO-single');
    ok('the order number is optional', ConsumablesService::move($conn, $A, 'receive', $kb, ['quantity' => 1])['on_hand'] === 6);
    ConsumablesService::saveDelivery($conn, $A, $del['delivery_id'], ['order_ref' => "PO-$tag-B"]);
    $h = ConsumablesService::getDelivery($conn, $A, $del['delivery_id'])['audit'];
    ok('changing the order number is recorded, old and new', (bool)array_filter($h, fn($a) => $a['field_name'] === 'delivery.order_ref' && $a['old_value'] === "PO-$tag" && $a['new_value'] === "PO-$tag-B"));
    ok('documents can hang off a delivery', documentEntityDef('consumable_delivery') !== null);
    ok('...and the list finds it by an item on it', in_array($del['delivery_id'], array_column(ConsumablesService::deliveries($conn, $A, ['q' => "$tag Keyboard"]), 'id'), true));
    $n = ConsumablesService::reverseDelivery($conn, $A, $del['delivery_id'], 'Booked in twice');
    ok('undoing the delivery takes all three back out', $n === 3 && $onHand($mouse) === 3 && $onHand($kb) === 1);
    ok('...once', refused(fn() => ConsumablesService::reverseDelivery($conn, $A, $del['delivery_id'], 'again')) === 'already_reversed');
    ok('the undos stay with the delivery', count(ConsumablesService::getDelivery($conn, $A, $del['delivery_id'])['lines']) === 6);

    // ---- using consumables on a ticket (Ed: "we definitely need a way of using consumables") ----
    $tk = $conn->query("SELECT id, tenant_id, user_id FROM tickets WHERE deleted_datetime IS NULL AND user_id IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($tk) {
        $tkId = (int)$tk['id'];
        // the ticket's company must be the item's: put the item in it
        $conn->prepare("UPDATE consumable_items SET tenant_id = ? WHERE id IN (?, ?)")->execute([$tk['tenant_id'] !== null ? (int)$tk['tenant_id'] : getDefaultTenantId($conn), $mouse, $kb]);
        ConsumablesService::move($conn, $A, 'receive', $mouse, ['quantity' => 5]);
        $listed = array_column(ConsumablesService::ticketItems($conn, $A, $tkId), 'id');
        ok('the ticket\'s menu lists its company\'s items', in_array($mouse, $listed, true));
        $before = $onHand($mouse);
        $u = ConsumablesService::issueToTicket($conn, $A, $tkId, $mouse, 1);
        $mv = $conn->query("SELECT ticket_id, recipient_user_id, source FROM consumable_movements WHERE id = " . (int)$u['movement_id'])->fetch(PDO::FETCH_ASSOC);
        ok('using one issues it against the ticket, to its requester', $onHand($mouse) === $before - 1 && (int)$mv['ticket_id'] === $tkId
            && (int)$mv['recipient_user_id'] === (int)$tk['user_id'] && $mv['source'] === 'ticket');
        ok('...and the ticket lists it as used', in_array((int)$u['movement_id'], array_column(ConsumablesService::forTicket($conn, $A, $tkId)['issued'], 'id'), true));
        // an item in ANOTHER company's stock cannot go on this ticket
        $other = (int)$conn->query("SELECT id FROM tenants WHERE id <> " . ($tk['tenant_id'] !== null ? (int)$tk['tenant_id'] : getDefaultTenantId($conn)) . " ORDER BY id LIMIT 1")->fetchColumn();
        if ($other) {
            $conn->prepare("UPDATE consumable_items SET tenant_id = ? WHERE id = ?")->execute([$other, $kb]);
            ok('an item from another company\'s stock is refused on the ticket', refused(fn() => ConsumablesService::issueToTicket($conn, $A, $tkId, $kb, 1)) === 'invalid_field');
            $conn->prepare("UPDATE consumable_items SET tenant_id = ? WHERE id = ?")->execute([$tk['tenant_id'], $kb]);
        }
        // a form on the ticket asks for a keyboard: the ticket shows it, not yet used
        $conn->prepare("INSERT INTO forms (title) VALUES (?)")->execute(["$tag Supplies request"]);
        $fid = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO form_fields (form_id, field_type, label, config) VALUES (?, 'lookup', 'Which item?', ?)")->execute([$fid, json_encode(['lookup_source' => 'consumable'])]);
        $ffid = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO form_submissions (form_id, ticket_id) VALUES (?, ?)")->execute([$fid, $tkId]);
        $sid = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO form_submission_data (submission_id, field_id, field_value) VALUES (?, ?, ?)")->execute([$sid, $ffid, json_encode(['id' => $kb, 'label' => "$tag Keyboard"])]);
        $req = ConsumablesService::forTicket($conn, $A, $tkId)['requested'];
        $r0 = array_values(array_filter($req, fn($r) => $r['item_id'] === $kb))[0] ?? null;
        ok('a consumable asked for on the ticket\'s form is listed, not yet used', $r0 && $r0['issued_here'] === 0 && $r0['form'] === "$tag Supplies request", json_encode($req));
        ok('asking moves no stock', $onHand($kb) === 1);
        ConsumablesService::issueToTicket($conn, $A, $tkId, $kb, 1);
        $r1 = array_values(array_filter(ConsumablesService::forTicket($conn, $A, $tkId)['requested'], fn($r) => $r['item_id'] === $kb))[0] ?? null;
        ok('once used, it says so', $r1 && $r1['issued_here'] === 1);
        ok('the forms lookup offers consumables', isset(FormsService::LOOKUP_SOURCES['consumable']) && !empty(FormsService::LOOKUP_SOURCES['consumable']['portal_safe']));
    } else {
        echo "  SKIP  no ticket with a requester: the ticket checks\n";
    }

    // ---- the books balance --------------------------------------------------------
    ok('on hand equals the ledger', $onHand($item) === $ledger($item));
    ok('verify() finds nothing out of step', !array_filter(ConsumablesService::verify($conn), fn($r) => (int)$r['item_id'] === $item));
    $seq = $conn->prepare("SELECT quantity, balance_after FROM consumable_movements WHERE item_id = ? ORDER BY id");
    $seq->execute([$item]);
    $run = 0; $okRun = true;
    foreach ($seq->fetchAll(PDO::FETCH_ASSOC) as $m) { $run += (int)$m['quantity']; $okRun = $okRun && $run === (int)$m['balance_after']; }
    ok('every movement\'s balance after adds up', $okRun);
} finally {
    $conn->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
