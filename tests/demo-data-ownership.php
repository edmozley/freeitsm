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

echo str_repeat('-', 72) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
