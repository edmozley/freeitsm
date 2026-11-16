<?php
/* 🔴 NEVER OVER THE WEB. See tests/web-exposure-guard.php. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Every table a demo module WRITES belongs to that module alone.
 *
 *   php tests/demo-data-ownership.php
 *
 * Importing a demo module first runs `DELETE FROM <table> WHERE is_demo = 1` on
 * every table its JSON inserts into (api/system/import_demo_data.php). is_demo
 * is a yes/no flag, not a module name, so if two modules wrote the same table,
 * re-importing either would silently delete the other's rows. Starters & leavers
 * (4.6.0) is the module this was nearly true of: its cases' tasks live in
 * tasks.json and its form in forms.json for exactly this reason.
 *
 * Also checks that each JSON parses and that every table it writes has is_demo
 * in the schema - an import into a table without it fails on the clearing step.
 * Static: reads the JSON files and includes/db_verify_schema.php, no database.
 */
require_once __DIR__ . '/../includes/demo_data.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nDemo data ownership\n" . str_repeat('=', 72) . "\n";
$schema = require __DIR__ . '/../includes/db_verify_schema.php';
$owner = [];
foreach (DEMO_MODULES as $module) {
    $data = json_decode((string)@file_get_contents(demoDataPath($module)), true);
    ok("$module: the JSON parses", is_array($data), json_last_error_msg());
    if (!is_array($data)) continue;
    foreach (demoTablesWithInserts($data) as $table) {
        $owner[$table][] = $module;
        if (is_array($schema) && isset($schema[$table])) {
            ok("$module: $table has is_demo", isset($schema[$table]['is_demo']));
        }
    }
}
$shared = array_filter($owner, fn($m) => count($m) > 1);
ok('no table is written by two demo modules', !$shared,
   implode('; ', array_map(fn($t, $m) => "$t: " . implode(', ', $m), array_keys($shared), $shared)));

// GH #366: no two rows a module inserts may clash on one of the table's unique
// keys once the columns they leave out take their schema default. lms.json's
// progress rows set only the legacy analyst_id, so all three for one course
// became ('analyst', 0, course) under uq_lp_learner_course and the import failed
// with a duplicate key. A column that is NULL in a row cannot clash (MySQL lets
// NULLs repeat in a unique key); "@table.key" references and tokens are compared
// as written, which is what each row will resolve to.
echo "\nDemo rows against unique keys\n" . str_repeat('=', 72) . "\n";
$uniques = [];
foreach (require __DIR__ . '/../includes/db_verify_indexes.php' as [$table, $name, $kind, $cols]) {
    if ($kind === 'unique') $uniques[$table][$name] = array_map(fn($c) => trim($c, '` '), explode(',', trim($cols, '()')));
}
$defaultOf = function (?string $def) {
    if ($def === null || !preg_match("/\\bDEFAULT\\s+('(?:[^']|'')*'|-?[0-9.]+|NULL)/i", $def, $m)) return null;
    if (strcasecmp($m[1], 'NULL') === 0) return null;
    return $m[1][0] === "'" ? str_replace("''", "'", substr($m[1], 1, -1)) : $m[1];
};
foreach (DEMO_MODULES as $module) {
    $data = json_decode((string)@file_get_contents(demoDataPath($module)), true);
    if (!is_array($data)) continue;
    foreach (['tier1', 'tier2', 'tier3', 'tier4', 'tier5'] as $tier) {
        foreach ($data[$tier] ?? [] as $table => $records) {
            foreach ($uniques[$table] ?? [] as $keyName => $keyCols) {
                $seen = []; $clash = '';
                foreach ($records as $record) {
                    if (!empty($record['_skip_insert'])) continue;
                    $values = [];
                    foreach ($keyCols as $col) {
                        $v = array_key_exists($col, $record) ? $record[$col] : $defaultOf($schema[$table][$col] ?? null);
                        // NULL never clashes; a "__GENERATE__"-style token becomes a fresh value per row.
                        if ($v === null || (is_string($v) && preg_match('/^__[A-Z_]+(?::[^_]*)?__$/', $v) && str_starts_with($v, '__GENERATE'))) { $values = null; break; }
                        $values[] = is_scalar($v) ? (string)$v : json_encode($v);
                    }
                    if ($values === null) continue;
                    $sig = implode("\x1f", $values);
                    if (isset($seen[$sig])) { $clash = '(' . implode(', ', $values) . ')'; break; }
                    $seen[$sig] = true;
                }
                if ($records) ok("$module: $table rows are distinct on $keyName", $clash === '', "two rows would be $clash");
            }
        }
    }
}

echo str_repeat('-', 72) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
