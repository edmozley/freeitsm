<?php
/**
 * Generator: rebuild includes/db_verify_indexes.php from database/freeitsm.sql.
 *
 * freeitsm.sql is the source of truth for the schema. This script extracts every
 * named secondary index (UNIQUE KEY / KEY / INDEX — not PRIMARY, not FOREIGN KEY)
 * with its columns, and writes them as a PHP array the Database Verification
 * endpoint uses to restore any index a grown install is missing.
 *
 * Run after adding or changing an index in freeitsm.sql:
 *   php scripts/gen_db_verify_indexes.php
 *
 * Then review the diff and commit both files together. Keeping this list in step
 * with freeitsm.sql is the same discipline as db_verify's $schema (see the
 * Database-Verification-Developer-Guide wiki page).
 */

$root = dirname(__DIR__);
$sqlPath = $root . '/database/freeitsm.sql';
$outPath = $root . '/includes/db_verify_indexes.php';

$sql = file_get_contents($sqlPath);
if ($sql === false) {
    fwrite(STDERR, "Cannot read $sqlPath\n");
    exit(1);
}

// Split into CREATE TABLE blocks so each index is attributed to its table.
preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`([a-zA-Z0-9_]+)`\s*\((.*?)\n\)\s*ENGINE/s', $sql, $blocks, PREG_SET_ORDER);

$rows = [];
foreach ($blocks as $b) {
    $table = $b[1];
    foreach (explode("\n", $b[2]) as $line) {
        $line = trim($line);
        // [UNIQUE] KEY|INDEX `name` (cols)  — cols may contain nested () for
        // prefix lengths, e.g. (`display_name`(400)), so match to the last ).
        if (preg_match('/^(UNIQUE\s+)?(?:KEY|INDEX)\s+`([a-zA-Z0-9_]+)`\s*(\(.*\))\s*,?\s*$/i', $line, $m)) {
            $rows[] = [
                'table'  => $table,
                'name'   => $m[2],
                'unique' => trim($m[1]) !== '',
                'cols'   => preg_replace('/\s+/', '', $m[3]),
            ];
        }
    }
}

$out  = "<?php\n";
$out .= "/**\n";
$out .= " * GENERATED — do not edit by hand.\n";
$out .= " *\n";
$out .= " * Every named secondary index in database/freeitsm.sql: [table, name, isUnique,\n";
$out .= " * columns]. Consumed by api/system/db_verify.php to restore indexes a grown\n";
$out .= " * install is missing. Regenerate with scripts/gen_db_verify_indexes.php after\n";
$out .= " * changing an index in freeitsm.sql.\n";
$out .= " */\n";
$out .= "return [\n";
foreach ($rows as $r) {
    $out .= sprintf(
        "    ['%s', '%s', %s, '%s'],\n",
        $r['table'], $r['name'], $r['unique'] ? 'true' : 'false', $r['cols']
    );
}
$out .= "];\n";

file_put_contents($outPath, $out);
fwrite(STDERR, sprintf("Wrote %d indexes to %s\n", count($rows), $outPath));
