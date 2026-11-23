<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables - it creates forms,
   submissions and an analyst, and tidies them up in its finally block. See
   tests/README.md. Run it against a disposable install. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Calculated answers, option values and bundles (GH #160 Part D).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/form-calculated.php
 *
 * Covers:
 *   - option values save, keyed by option text; values on a deleted option are dropped;
 *     a negative value is refused;
 *   - a Calculated question saves: lines pointing at new questions ("idx:N") are
 *     stored as their ids; a line pointing at the wrong kind of question is refused;
 *   - the server works it out: fixed x quantity, chosen option x quantity, a table's
 *     amount x quantity down the rows, a bundle of catalogue items at their own prices;
 *   - a total posted by the browser is ignored; a hidden question adds nothing;
 *   - an unreadable answer (an item with no price, or in another currency) leaves it blank;
 *   - a bundle follows the item's newest version and its price as it is now;
 *   - the total is frozen: changing a price later does not change the request;
 *   - a new version of the form remaps the lines to its own questions;
 *   - an approval step "Only when Order total is more than 5000" uses the total, and a
 *     blank total makes it run;
 *   - the answer reads back as money ("€3,000.00").
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/forms.php';
require_once __DIR__ . '/../includes/catalogue_approvals.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  - $detail" : '') . "\n"; }
}
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

echo "Calculated answers and option values (GH #160 Part D)\n" . str_repeat('=', 64) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $conn->query('SELECT DATABASE()')->fetchColumn()) { echo "  SKIPPED - set FREEITSM_TEST_DATABASE to this install's (throwaway) database.\n"; exit(0); }

$made = ['forms' => [], 'analysts' => [], 'users' => []];
$tag = 'zzcal' . bin2hex(random_bytes(3));
try {
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin) VALUES (?, 'x', 'ZZCAL admin', ?, 1, 1)")
         ->execute([$tag, $tag . '@example.invalid']);
    $admin = $made['analysts'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin) VALUES (?, 'x', 'ZZCAL board', ?, 1, 0)")
         ->execute([$tag . 'b', $tag . 'b@example.invalid']);
    $board = $made['analysts'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, 'ZZCAL Tom', UTC_TIMESTAMP())")->execute([$tag . 'tom@example.invalid']);
    $tom = $made['users'][] = (int)$conn->lastInsertId();
    $_SESSION = ['analyst_id' => $admin];
    $ctx = new ActorContext(actorId: $admin, source: 'ui');
    $save = function (array $in) use ($conn, $ctx, &$made) {
        $r = FormsService::saveForm($conn, $ctx, $in + ['is_portal_visible' => 1]);
        if (!in_array((int)$r['id'], $made['forms'], true)) $made['forms'][] = (int)$r['id'];
        return (int)$r['id'];
    };
    $fieldIds = fn(int $formId) => $conn->query("SELECT label, id FROM form_fields WHERE form_id = $formId AND is_deleted = 0")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cfgOf = fn(int $fid) => json_decode((string)$conn->query("SELECT config FROM form_fields WHERE id = $fid")->fetchColumn(), true);
    $submit = function (int $formId, array $byLabel) use ($conn, $fieldIds, $tom) {
        $f = $fieldIds($formId);
        $data = [];
        foreach ($byLabel as $l => $v) $data[$f[$l]] = $v;
        $sid = FormsService::submitForm($conn, new ActorContext(actorId: 0, source: 'portal'), $formId, $data, $tom);
        $t = $conn->prepare("SELECT field_value FROM form_submission_data WHERE submission_id = ? AND field_id = ?");
        $t->execute([$sid, $f['Order total']]);
        $v = $t->fetchColumn();
        return [$sid, $v === false ? null : (string)$v];
    };

    // ---- 1. Option values ----
    $laptop = [
        ['field_type' => 'dropdown', 'label' => 'Model', 'options' => ['Standard', 'Pro'],
         'config' => ['option_values' => ['Standard' => '900', 'Pro' => '1500.50', 'Gone' => '5']]],
        ['field_type' => 'number', 'label' => 'Quantity'],
        ['field_type' => 'checkbox', 'label' => 'Add a dock?'],
        ['field_type' => 'number', 'label' => 'Docks', 'config' => ['visible_if' => ['match' => 'all', 'rules' => [['field' => 'idx:2', 'op' => 'equals', 'value' => '1']]]]],
        ['field_type' => 'calculated', 'label' => 'Order total', 'is_required' => 1, 'config' => [
            'calc_currency' => 'EUR',
            'calc_lines' => [
                ['kind' => 'option', 'field' => 'idx:0', 'times' => 'idx:1'],
                ['kind' => 'fixed', 'amount' => '120', 'times' => 'idx:3'],
            ]]],
    ];
    $f1 = $save(['title' => 'ZZCAL New laptop', 'fields' => $laptop]);
    $ids = $fieldIds($f1);
    $model = $cfgOf($ids['Model']);
    check('option values save, keyed by text; one on a missing option is dropped', ($model['option_values'] ?? null) === ['Standard' => '900', 'Pro' => '1500.5']);
    $calc = $cfgOf($ids['Order total']);
    check('new questions in the lines are stored as their ids', ($calc['calc_lines'][0]['field'] ?? null) === $ids['Model'] && ($calc['calc_lines'][0]['times'] ?? null) === $ids['Quantity']
        && ($calc['calc_lines'][1]['times'] ?? null) === $ids['Docks'], json_encode($calc));
    check('a calculated answer is never required', (int)$conn->query("SELECT is_required FROM form_fields WHERE id = " . $ids['Order total'])->fetchColumn() === 0);
    check('a negative option value is refused', str_contains(refused(fn() => $save(['title' => 'ZZCAL bad', 'fields' => [
        ['field_type' => 'dropdown', 'label' => 'M', 'options' => ['A'], 'config' => ['option_values' => ['A' => '-1']]]]])), '0 or more'));
    check('a line pointing at the wrong kind of question is refused', str_contains(refused(fn() => $save(['title' => 'ZZCAL bad', 'fields' => [
        ['field_type' => 'text', 'label' => 'T'],
        ['field_type' => 'calculated', 'label' => 'X', 'config' => ['calc_lines' => [['kind' => 'option', 'field' => 'idx:0']]]]]])), "'text'"));
    check('a calculated answer with no lines is refused', str_contains(refused(fn() => $save(['title' => 'ZZCAL bad', 'fields' => [
        ['field_type' => 'calculated', 'label' => 'X', 'config' => ['calc_lines' => []]]]])), 'at least one line'));

    // ---- 2. Working it out ----
    [$s, $v] = $submit($f1, ['Model' => 'Pro', 'Quantity' => '2', 'Order total' => '1']);
    check('option x quantity: 2 x 1500.50 = 3001.00 (and the posted 1 is ignored)', $v === '3001.00', (string)$v);
    [$s, $v] = $submit($f1, ['Model' => 'Standard', 'Quantity' => '3', 'Add a dock?' => '1', 'Docks' => '2']);
    check('...plus a fixed amount x a shown quantity: 2700 + 240 = 2940.00', $v === '2940.00', (string)$v);
    [$s, $v] = $submit($f1, ['Model' => 'Standard', 'Quantity' => '3', 'Add a dock?' => '0', 'Docks' => '2']);
    check('a hidden question adds nothing: 2700.00', $v === '2700.00', (string)$v);
    $text = catalogueSubmissionAnswers($conn, $s);
    $shown = array_values(array_filter($text, fn($a) => $a['label'] === 'Order total'))[0]['value'] ?? '';
    check('the answer reads back as money', str_contains($shown, '2') && (str_contains($shown, '€') || str_contains($shown, 'EUR')), $shown);

    // ---- 3. A basket ----
    $f2 = $save(['title' => 'ZZCAL Basket', 'fields' => [
        ['field_type' => 'grid', 'label' => 'Items', 'config' => ['columns' => [
            ['id' => 1, 'label' => 'What', 'type' => 'text'], ['id' => 2, 'label' => 'Price', 'type' => 'number'], ['id' => 3, 'label' => 'How many', 'type' => 'number']]]],
        ['field_type' => 'calculated', 'label' => 'Order total', 'config' => ['calc_currency' => 'GBP',
            'calc_lines' => [['kind' => 'grid', 'field' => 'idx:0', 'value_col' => 2, 'qty_col' => 3]]]],
    ]]);
    [$s, $v] = $submit($f2, ['Items' => [['1' => 'Mouse', '2' => '19.99', '3' => '2'], ['1' => 'Monitor', '2' => '150', '3' => '1'], ['1' => 'Nothing', '2' => '5']]]);
    check('a table: 2 x 19.99 + 1 x 150 = 189.98 (a row with no quantity adds nothing)', $v === '189.98', (string)$v);
    check('a table line must name a number column', str_contains(refused(fn() => $save(['title' => 'ZZCAL bad', 'fields' => [
        ['field_type' => 'grid', 'label' => 'G', 'config' => ['columns' => [['id' => 1, 'label' => 'W', 'type' => 'text']]]],
        ['field_type' => 'calculated', 'label' => 'X', 'config' => ['calc_lines' => [['kind' => 'grid', 'field' => 'idx:0', 'value_col' => 1]]]]]])), 'number column'));

    // ---- 4. A bundle of catalogue items ----
    $notebook = $save(['title' => 'ZZCAL Notebook', 'fields' => []]);
    $monitor  = $save(['title' => 'ZZCAL Monitor', 'fields' => []]);
    $headset  = $save(['title' => 'ZZCAL Headset', 'fields' => []]);
    $price = $conn->prepare('UPDATE forms SET catalogue_price = ?, catalogue_currency = ? WHERE id = ?');
    $price->execute(['1200.00', 'EUR', $notebook]);
    $price->execute(['250.00', 'EUR', $monitor]);
    $f3 = $save(['title' => 'ZZCAL Workplace set', 'fields' => [
        ['field_type' => 'calculated', 'label' => 'Order total', 'config' => ['calc_currency' => 'EUR', 'calc_lines' => [
            ['kind' => 'item', 'form_id' => $notebook, 'qty' => '1'], ['kind' => 'item', 'form_id' => $monitor, 'qty' => '2']]]],
    ]]);
    [$s, $v] = $submit($f3, []);
    check('a bundle adds up its items at their own prices: 1200 + 2 x 250 = 1700.00', $v === '1700.00', (string)$v);
    // The monitor is edited: a new version with a new price. The bundle follows it.
    $mv2 = (int)FormsService::createVersion($conn, $ctx, $monitor)['id'];
    $made['forms'][] = $mv2;
    $price->execute(['300.00', 'EUR', $mv2]);
    [$s2, $v2] = $submit($f3, []);
    check("a bundle follows the item's newest version and price: 1800.00", $v2 === '1800.00', (string)$v2);
    $kept = (string)$conn->query("SELECT field_value FROM form_submission_data WHERE submission_id = $s AND field_id = " . $fieldIds($f3)['Order total'])->fetchColumn();
    check('the earlier request keeps the total it was made at', $kept === '1700.00', $kept);
    $f4 = $save(['title' => 'ZZCAL Set with headset', 'fields' => [
        ['field_type' => 'calculated', 'label' => 'Order total', 'config' => ['calc_currency' => 'EUR', 'calc_lines' => [
            ['kind' => 'item', 'form_id' => $notebook, 'qty' => '1'], ['kind' => 'item', 'form_id' => $headset, 'qty' => '1']]]],
    ]]);
    [$s, $v] = $submit($f4, []);
    check('an item with no price leaves the total blank, never a guess', $v === null, (string)$v);
    $price->execute(['40.00', 'GBP', $headset]);
    [$s, $v] = $submit($f4, []);
    check('an item in another currency leaves it blank, never converted', $v === null, (string)$v);

    // ---- 5. A new version keeps its lines pointing at its own questions ----
    $v2id = (int)FormsService::createVersion($conn, $ctx, $f1)['id'];
    $made['forms'][] = $v2id;
    $nids = $fieldIds($v2id);
    $nc = $cfgOf($nids['Order total']);
    check("a new version's lines point at the new version's questions", ($nc['calc_lines'][0]['field'] ?? null) === $nids['Model']
        && ($nc['calc_lines'][0]['times'] ?? null) === $nids['Quantity'] && ($nc['calc_lines'][1]['times'] ?? null) === $nids['Docks']);
    $nm = $cfgOf($nids['Model']);
    check('...and keeps the option values', ($nm['option_values']['Pro'] ?? null) === '1500.5');

    // ---- 6. A threshold step uses the total ----
    if (catalogueStepsAvailable($conn)) {
        $f5 = $save(['title' => 'ZZCAL Laptop with board', 'requires_approval' => 1, 'fields' => $laptop,
            'approval_steps' => [['name' => 'Board', 'who' => 'person', 'approver_id' => $board,
                'when' => ['match' => 'all', 'rules' => [['question' => 'Order total', 'op' => 'greater_than', 'value' => '5000']]]]]]);
        [$s, $v] = $submit($f5, ['Model' => 'Pro', 'Quantity' => '2']);
        $st = (string)$conn->query("SELECT approval_status FROM form_submissions WHERE id = $s")->fetchColumn();
        check('a total of 3001 skips the "more than 5000" step', $st === 'approved', $st);
        [$s, $v] = $submit($f5, ['Model' => 'Pro', 'Quantity' => '4']);
        $r = $conn->query("SELECT approval_status, approver_id FROM form_submissions WHERE id = $s")->fetch(PDO::FETCH_ASSOC);
        check('a total of 6002 runs it', $v === '6002.00' && $r['approval_status'] === 'pending' && (int)$r['approver_id'] === $board, json_encode($r));
    }
} finally {
    foreach ($made['forms'] as $fid) {
        foreach ($conn->query("SELECT ticket_id FROM form_submissions WHERE form_id = " . (int)$fid . " AND ticket_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            foreach (['emails', 'ticket_audit'] as $tb) { try { $conn->prepare("DELETE FROM $tb WHERE ticket_id = ?")->execute([$tid]); } catch (Throwable $e) {} }
            $conn->prepare("UPDATE form_submissions SET ticket_id = NULL WHERE ticket_id = ?")->execute([$tid]);
            try { $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$tid]); } catch (Throwable $e) {}
        }
        $conn->prepare("DELETE sd FROM form_submission_data sd JOIN form_submissions s ON s.id = sd.submission_id WHERE s.form_id = ?")->execute([$fid]);
        $conn->prepare("DELETE FROM form_submissions WHERE form_id = ?")->execute([$fid]);
    }
    rsort($made['forms']);   // newest versions first: a parent cannot go before its child
    foreach ($made['forms'] as $fid) {
        $conn->prepare("DELETE FROM form_fields WHERE form_id = ?")->execute([$fid]);
        try { $conn->prepare("DELETE FROM forms WHERE id = ?")->execute([$fid]); } catch (Throwable $e) { echo "  (form $fid: " . $e->getMessage() . ")\n"; }
    }
    foreach ($made['users'] as $uid) { try { $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]); } catch (Throwable $e) {} }
    foreach ($made['analysts'] as $aid) {
        try { $conn->prepare("DELETE FROM notifications WHERE analyst_id = ?")->execute([$aid]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$aid]); } catch (Throwable $e) {}
    }
    $left = (int)$conn->query("SELECT COUNT(*) FROM forms WHERE title LIKE 'ZZCAL %'")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE username LIKE '$tag%'")->fetchColumn();
    check('cleanup removed everything it made', $left === 0, "$left left");
    echo str_repeat('-', 64) . "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
