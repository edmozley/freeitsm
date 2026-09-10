<?php
/**
 * Generator: rebuild includes/db_verify_indexes.php from database/freeitsm.sql.
 *
 * freeitsm.sql is the source of truth for the schema. This script extracts every
 * named secondary index (UNIQUE KEY / FULLTEXT KEY / KEY / INDEX — not PRIMARY,
 * not FOREIGN KEY) with its columns, and writes them as a PHP array the Database
 * Verification endpoint uses to restore any index a grown install is missing.
 *
 * Run after adding or changing an index in freeitsm.sql:
 *   php scripts/gen_db_verify_indexes.php
 *
 * Then review the diff and commit both files together. Keeping this list in step
 * with freeitsm.sql is the same discipline as db_verify's $schema (see the
 * Database-Verification-Developer-Guide wiki page).
 */

// CLI ONLY. scripts/ is inside the web root on a normal install, and this one
// WRITES includes/db_verify_indexes.php — a PHP file the application then
// executes. Served over HTTP without this, an unauthenticated request could
// rewrite code inside the app. The check is in PHP rather than in the web
// server because it has to hold on nginx and IIS too, where an .htaccess deny
// is simply ignored.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is command-line only.\n");
}

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

$rows = dbVerifyParseIndexesFromSql($sql);   // [ [table, name, type, cols], ... ]

$out  = "<?php\n";
$out .= "/**\n";
$out .= " * GENERATED — do not edit by hand.\n";
$out .= " *\n";
$out .= " * Every named secondary index in database/freeitsm.sql: [table, name, type,\n";
$out .= " * columns], where type is 'unique', 'fulltext' or 'key'. Consumed by\n";
$out .= " * api/system/db_verify.php to restore indexes a grown install is missing.\n";
$out .= " * Regenerate with scripts/gen_db_verify_indexes.php after changing an index in\n";
$out .= " * freeitsm.sql.\n";
$out .= " */\n";
$out .= "return [\n";
foreach ($rows as $r) {
    // $r = [table, name, type, cols]
    $out .= sprintf(
        "    ['%s', '%s', '%s', '%s'],\n",
        $r[0], $r[1], $r[2], $r[3]
    );
}
$out .= "];\n";

// --check: is the committed file already what this script would write?
//
// ⚠️ WHY THIS EXISTS. The Database Verification screen has always detected this
// drift, and told the administrator to run this script. But nothing ran it
// BEFORE a release, so a shipped version went out with 16 indexes in
// freeitsm.sql and missing from the list — and every administrator who ran
// Verification, on every update, was shown a developer instruction they could do
// nothing useful with (GH #113). Detecting a mistake at the far end, in somebody
// else's install, is not the same as catching it.
//
// Exits 1 when they differ, so this can gate a release rather than rely on
// remembering. Writes nothing in this mode.
if (in_array('--check', $argv, true)) {
    $current = is_readable($outPath) ? file_get_contents($outPath) : '';
    if ($current === $out) {
        echo "db_verify_indexes.php is up to date with freeitsm.sql (" . count($rows) . " indexes).\n";
        exit(0);
    }
    fwrite(STDERR, "OUT OF DATE: includes/db_verify_indexes.php does not match database/freeitsm.sql.\n");
    fwrite(STDERR, "Run: php scripts/gen_db_verify_indexes.php   then commit BOTH files.\n");
    // Name what differs, so the failure is actionable without a diff tool.
    $committed = is_readable($outPath) ? (array) (require $outPath) : [];
    $key = fn(array $r) => $r[0] . '.' . $r[1];
    $freshKeys = array_map($key, $rows);
    $commKeys  = array_map($key, $committed);
    foreach (array_diff($freshKeys, $commKeys) as $k) {
        fwrite(STDERR, "  missing from the list: $k\n");
    }
    foreach (array_diff($commKeys, $freshKeys) as $k) {
        fwrite(STDERR, "  in the list but not in freeitsm.sql: $k\n");
    }
    exit(1);
}

file_put_contents($outPath, $out);

// Report the breakdown, not just the total — a FULLTEXT index silently parsed as
// an ordinary KEY would still make the count look right.
$byType = array_count_values(array_column($rows, 2));
ksort($byType);
$parts = [];
foreach ($byType as $t => $n) $parts[] = "$n $t";
fwrite(STDERR, sprintf("Wrote %d indexes to %s (%s)\n", count($rows), $outPath, implode(', ', $parts)));
