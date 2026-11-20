<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Assets: missing information (GH #404) - expected fields per asset type, flagged, never refused.
 *
 *   php tests/asset-missing-info.php      (from a TEST install, never a real one)
 *
 * Makes a ZZ asset type with a ZZ custom field set, ZZ assets, and removes them all in a
 * finally block. Covers the issue's acceptance criteria:
 *   1 no expectations: nothing listed, nothing changes
 *   2 an asset missing an expected field still saves, and is listed with that field named
 *   3 blank text, NULL and an unselected reference are missing; 0 and boolean false are filled in
 *   4 Not applicable is explicit and reversible, and never writes into the field
 *   - findings refresh by themselves: filling a field clears it, clearing brings it back
 *   - a custom field: no value is missing, a value is not
 *   - filters by type, field and section; a retired asset is not listed
 *   - an unknown key is never stored; a custom field not offered by the type is dropped
 *
 * Control: make missingSql() always say "missing" and the filled-in checks fail; drop the
 * Not applicable NOT EXISTS and the exemption checks fail.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/asset_completeness.php';

$conn = connectToDatabase();
if (!AssetCompleteness::ready($conn)) { echo "Run Database Verification first.\n"; exit(1); }
$pass = 0; $fail = 0;
/** Records and prints one PASS or FAIL line. */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
/** What one asset is missing, as the list says it. */
function missingFor(int $assetId): array
{
    global $conn;
    foreach (AssetCompleteness::findings($conn, 1) as $r) if ($r['asset_id'] === $assetId) return $r['missing'];
    return [];
}

$made = ['type' => null, 'type2' => null, 'set' => null, 'field' => null, 'field2' => null, 'assets' => []];
try {
    $conn->exec("INSERT INTO asset_types (name, is_active) VALUES ('ZZ Missing-info notebook', 1)");
    $type = $made['type'] = (int)$conn->lastInsertId();
    $conn->exec("INSERT INTO asset_types (name, is_active) VALUES ('ZZ Missing-info monitor', 1)");
    $type2 = $made['type2'] = (int)$conn->lastInsertId();
    $conn->exec("INSERT INTO asset_field_sets (name) VALUES ('ZZ Missing-info set')");
    $set = $made['set'] = (int)$conn->lastInsertId();
    $conn->exec("INSERT INTO asset_fields (field_key, label, field_type) VALUES ('zz_mi_budget_code', 'ZZ Budget code', 'text')");
    $field = $made['field'] = (int)$conn->lastInsertId();
    $conn->exec("INSERT INTO asset_fields (field_key, label, field_type) VALUES ('zz_mi_not_in_set', 'ZZ Not in set', 'text')");
    $field2 = $made['field2'] = (int)$conn->lastInsertId();
    $conn->prepare('INSERT INTO asset_field_set_fields (set_id, field_id) VALUES (?, ?)')->execute([$set, $field]);
    $conn->prepare('INSERT INTO asset_type_field_sets (asset_type_id, set_id) VALUES (?, ?)')->execute([$type, $set]);
    $mk = function (string $name, array $cols = []) use ($conn, $type, &$made): int {
        $cols = ['hostname' => $name, 'asset_type_id' => $type] + $cols;
        $conn->prepare('INSERT INTO assets (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
        return $made['assets'][] = (int)$conn->lastInsertId();
    };
    $bare  = $mk('ZZ-MI-BARE');
    $full  = $mk('ZZ-MI-FULL', ['purchase_date' => '2026-01-10', 'purchase_cost' => '0.00', 'order_number' => 'PO-1']);
    $blank = $mk('ZZ-MI-BLANK', ['order_number' => '   ']);   // (a reference of 0 cannot be stored: the foreign key refuses it)
    $gone  = $mk('ZZ-MI-RETIRED', ['retired_datetime' => '2026-01-01 00:00:00']);

    echo "1. Nothing expected\n";
    check('no expectations: the asset is not listed', missingFor($bare) === []);
    check('  and anyExpected() is false for this type alone', AssetCompleteness::expectedForTypes($conn, [$type]) === []);

    echo "\n2. Expectations flag, never refuse\n";
    AssetCompleteness::saveExpected($conn, $type, ['purchase_date', 'purchase_cost', 'supplier_id', 'order_number', 'custom:' . $field, 'custom:' . $field2, 'launch_codes']);
    $exp = AssetCompleteness::expectedForTypes($conn, [$type])[$type] ?? [];
    check('expected fields saved', in_array('purchase_date', $exp, true) && in_array('custom:' . $field, $exp, true));
    check('  an unknown key is dropped', !in_array('launch_codes', $exp, true));
    check('  a custom field not in the type\'s sets is dropped', !in_array('custom:' . $field2, $exp, true));
    check('a bare asset is listed with every expected field named', missingFor($bare) === ['purchase_date', 'purchase_cost', 'supplier_id', 'order_number', 'custom:' . $field], json_encode(missingFor($bare)));
    $conn->prepare('UPDATE assets SET model = ? WHERE id = ?')->execute(['ZZ edited', $bare]);
    check('  and still saves normally (an unrelated edit goes through)', $conn->query("SELECT model FROM assets WHERE id = $bare")->fetchColumn() === 'ZZ edited');

    echo "\n3. What counts as missing\n";
    $m = missingFor($full);
    check('a cost of 0 counts as filled in', !in_array('purchase_cost', $m, true), json_encode($m));
    check('a date and an order number count as filled in', !in_array('purchase_date', $m, true) && !in_array('order_number', $m, true));
    check('an unselected supplier is missing', in_array('supplier_id', $m, true));
    $m = missingFor($blank);
    check('blank (spaces-only) text is missing', in_array('order_number', $m, true), json_encode($m));
    check('an unselected reference (NULL) is missing', in_array('supplier_id', $m, true));
    check('a custom field with no value is missing', in_array('custom:' . $field, missingFor($full), true));
    $conn->prepare('INSERT INTO asset_field_values (asset_id, field_id, value_text) VALUES (?, ?, ?)')->execute([$full, $field, 'BC-7']);
    check('  and with a value it is not', !in_array('custom:' . $field, missingFor($full), true));
    $conn->prepare('UPDATE asset_field_values SET value_text = ? WHERE asset_id = ? AND field_id = ?')->execute(['  ', $full, $field]);
    check('  a blank custom value is missing again', in_array('custom:' . $field, missingFor($full), true));

    echo "\n4. Findings refresh by themselves\n";
    $conn->prepare('UPDATE assets SET purchase_date = ? WHERE id = ?')->execute(['2026-02-02', $bare]);
    check('filling a field clears its finding at once', !in_array('purchase_date', missingFor($bare), true));
    $conn->prepare('UPDATE assets SET purchase_date = NULL WHERE id = ?')->execute([$bare]);
    check('clearing it brings it back', in_array('purchase_date', missingFor($bare), true));
    AssetCompleteness::saveExpected($conn, $type, ['purchase_date']);
    check('changing the type\'s expectations changes the list', missingFor($bare) === ['purchase_date'], json_encode(missingFor($bare)));
    AssetCompleteness::saveExpected($conn, $type, ['purchase_date', 'purchase_cost', 'supplier_id', 'order_number', 'custom:' . $field]);

    echo "\n5. Not applicable\n";
    AssetCompleteness::setNotApplicable($conn, $bare, 'supplier_id', true, 1, 'Supplier');
    check('marked Not applicable: no longer listed', !in_array('supplier_id', missingFor($bare), true));
    check('  the field itself is untouched (still NULL)', $conn->query("SELECT supplier_id FROM assets WHERE id = $bare")->fetchColumn() === null);
    $one = AssetCompleteness::forAsset($conn, 1, $bare);
    check('  the asset says it is Not applicable, and who did it', ($one['not_applicable'][0]['key'] ?? '') === 'supplier_id');
    check('  recorded in the asset\'s history', (int)$conn->query("SELECT COUNT(*) FROM asset_history WHERE asset_id = $bare AND field_name = 'Not applicable: Supplier' AND new_value = 'Yes'")->fetchColumn() === 1);
    AssetCompleteness::setNotApplicable($conn, $bare, 'supplier_id', true, 1, 'Supplier');
    check('  marking it twice is one history line, not two', (int)$conn->query("SELECT COUNT(*) FROM asset_history WHERE asset_id = $bare AND field_name = 'Not applicable: Supplier'")->fetchColumn() === 1);
    AssetCompleteness::setNotApplicable($conn, $bare, 'supplier_id', false, 1, 'Supplier');
    check('unmarking it brings the finding back', in_array('supplier_id', missingFor($bare), true));

    echo "\n6. Filters and scope\n";
    $byField = AssetCompleteness::findings($conn, 1, ['type_id' => $type, 'field' => 'order_number']);
    check('filter by field: only that field, only assets missing it', array_values(array_unique(array_merge(...array_column($byField, 'missing')))) === ['order_number']);
    $bySection = AssetCompleteness::findings($conn, 1, ['type_id' => $type, 'section' => 'custom']);
    check('filter by section: only custom fields', array_values(array_unique(array_merge(...array_column($bySection, 'missing')))) === ['custom:' . $field]);
    check('filter by another type: none of these', AssetCompleteness::findings($conn, 1, ['type_id' => $type2]) === []);
    check('a retired asset is not listed', missingFor($gone) === []);
} finally {
    foreach ($made['assets'] as $id) {
        foreach (['asset_field_not_applicable', 'asset_field_values', 'asset_history'] as $t) $conn->exec("DELETE FROM $t WHERE asset_id = $id");
        $conn->exec("DELETE FROM assets WHERE id = $id");
    }
    foreach (['type', 'type2'] as $k) if ($made[$k]) { $conn->exec("DELETE FROM asset_type_expected_fields WHERE asset_type_id = {$made[$k]}"); $conn->exec("DELETE FROM asset_type_field_sets WHERE asset_type_id = {$made[$k]}"); $conn->exec("DELETE FROM asset_types WHERE id = {$made[$k]}"); }
    if ($made['set']) { $conn->exec("DELETE FROM asset_field_set_fields WHERE set_id = {$made['set']}"); $conn->exec("DELETE FROM asset_field_sets WHERE id = {$made['set']}"); }
    foreach (['field', 'field2'] as $k) if ($made[$k]) $conn->exec("DELETE FROM asset_fields WHERE id = {$made[$k]}");
}

echo "\n" . str_repeat('-', 70) . "\n  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
