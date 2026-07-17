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

// The parse lives in one place, shared with db_verify's drift self-check, so the
// generator and the checker can never disagree about what an index "is".
require_once $root . '/includes/db_verify_index_parse.php';

$sql = file_get_contents($sqlPath);
if ($sql === false) {
    fwrite(STDERR, "Cannot read $sqlPath\n");
    exit(1);
}

$rows = dbVerifyParseIndexesFromSql($sql);   // [ [table, name, isUnique, cols], ... ]

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
    // $r = [table, name, isUnique, cols]
    $out .= sprintf(
        "    ['%s', '%s', %s, '%s'],\n",
        $r[0], $r[1], $r[2] ? 'true' : 'false', $r[3]
    );
}
$out .= "];\n";

file_put_contents($outPath, $out);
fwrite(STDERR, sprintf("Wrote %d indexes to %s\n", count($rows), $outPath));
