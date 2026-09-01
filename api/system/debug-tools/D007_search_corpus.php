<?php
/**
 * D007 — Search corpus health.
 *
 * Full-text search depends on things that fail SILENTLY. MySQL has a handful of
 * server settings that decide which words it will even remember, and none of
 * them produce an error when they are wrong — searches simply come back empty
 * and everyone assumes the feature is broken. The worst offender is
 * innodb_ft_max_token_size: leave it at a low value and every word longer than
 * that is quietly ignored, so "authentication" is unfindable while "printer"
 * works perfectly.
 *
 * Nobody should have to hold that in their head. This tool looks at the whole
 * chain in one place — the table, its indexes, the foreign key, the server
 * settings, and a real search performed live — and ends in a plain-English
 * verdict with the fix.
 *
 * ⚠️ It writes ONE probe row and deletes it. That is deliberate and unavoidable:
 * InnoDB does not expose rows inserted in an uncommitted transaction to
 * MATCH...AGAINST (the full-text cache is flushed at commit), so a search cannot
 * be proven from inside a rolled-back transaction. The row uses a reserved
 * source_type that nothing else writes, and is removed in a finally block.
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D007';
$DIAG_NAME = 'Search corpus health';

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Debug tools are administrators-only (issue #34). Fail closed.
try {
    $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id']);
} catch (Throwable $e) {
    $__dbgAdmin = false;
}
if (!$__dbgAdmin) {
    http_response_code(403);
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "Administrator access required.\n";
    exit;
}

$sections = [];
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
function yn($v) { return $v ? 'YES' : 'NO'; }
function emit_and_exit($sections) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n\n", $sections) . "\n";
    exit;
}

$TABLE    = 'search_documents';
$PROBE    = '_d007_probe';       // reserved source_type — nothing else ever writes it
$problems = [];                  // plain-English problems, collected for the verdict
$fixes    = [];

// ---- 1. CONNECTION -----------------------------------------------------
try {
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbName = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
} catch (Throwable $e) {
    addSection($sections, "CONNECTION", "Could not connect to the database: " . $e->getMessage());
    emit_and_exit($sections);
}
$serverVersion = (string)$conn->query("SELECT VERSION()")->fetchColumn();
addSection($sections, "ENVIRONMENT", [
    "PHP version   : " . PHP_VERSION,
    "MySQL version : " . $serverVersion,
    "Database      : " . $dbName,
]);

// ---- 2. THE TABLE ------------------------------------------------------
$exists = (int)$conn->query("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema = " . $conn->quote($dbName) . "
                               AND table_name   = " . $conn->quote($TABLE))->fetchColumn() > 0;

if (!$exists) {
    addSection($sections, "THE TABLE", [
        "$TABLE exists : NO",
        "",
        "Nothing else can be checked until the table is created.",
    ]);
    addSection($sections, "VERDICT", [
        "PROBLEM — the search corpus table does not exist.",
        "",
        "FIX: open System -> Database Verification and run it. The table and all of",
        "its indexes are created automatically.",
    ]);
    emit_and_exit($sections);
}

$engine = (string)$conn->query("SELECT ENGINE FROM information_schema.tables
                                WHERE table_schema = " . $conn->quote($dbName) . "
                                  AND table_name   = " . $conn->quote($TABLE))->fetchColumn();
$cols = $conn->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.columns
                      WHERE table_schema = " . $conn->quote($dbName) . "
                        AND table_name   = " . $conn->quote($TABLE) . "
                      ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_NUM);

$expectedCols = ['id','source_type','source_id','ticket_id','tenant_id','tenant_scope',
                 'is_internal','title','body','source_datetime','indexed_datetime'];
$haveCols  = array_column($cols, 0);
$missingCols = array_diff($expectedCols, $haveCols);
if ($missingCols) {
    $problems[] = "The table is missing columns: " . implode(', ', $missingCols) . ".";
    $fixes[]    = "Run System -> Database Verification, which adds missing columns.";
}
if (strtoupper($engine) !== 'INNODB') {
    $problems[] = "The table uses the $engine engine, not InnoDB.";
    $fixes[]    = "Full-text search here is built for InnoDB; converting the table is a DBA task.";
}

$lines = ["$TABLE exists : YES", "Engine        : $engine", "Columns       : " . count($cols) . " (expected " . count($expectedCols) . ")"];
if ($missingCols) $lines[] = "MISSING       : " . implode(', ', $missingCols);
foreach ($cols as [$n, $t]) $lines[] = sprintf("   %-17s %s", $n, $t);
addSection($sections, "THE TABLE", $lines);

// ---- 3. INDEXES --------------------------------------------------------
$idx = $conn->query("SELECT DISTINCT INDEX_NAME, INDEX_TYPE, NON_UNIQUE FROM information_schema.statistics
                     WHERE table_schema = " . $conn->quote($dbName) . "
                       AND table_name   = " . $conn->quote($TABLE) . "
                     ORDER BY INDEX_NAME")->fetchAll(PDO::FETCH_NUM);
$idxByName = [];
foreach ($idx as [$n, $t, $nu]) $idxByName[$n] = ['type' => $t, 'unique' => !$nu];

// The two full-text indexes are what searching actually depends on.
$needFulltext = ['ft_search_docs' => 'title + body (the main search)',
                 'ft_search_docs_title' => 'title only (searching subjects / filenames alone)'];
$lines = [];
foreach ($idxByName as $n => $m) {
    $lines[] = sprintf("   %-24s %-9s %s", $n, $m['type'], $m['unique'] ? '(unique)' : '');
}
foreach ($needFulltext as $n => $why) {
    if (!isset($idxByName[$n])) {
        $problems[] = "The full-text index `$n` is missing — $why will not work.";
        $fixes[]    = "Run System -> Database Verification; it restores missing indexes.";
        $lines[]    = "   MISSING: $n  ($why)";
    } elseif (strtoupper($idxByName[$n]['type']) !== 'FULLTEXT') {
        // The quiet failure: an index of the right NAME but the wrong KIND.
        $problems[] = "`$n` exists but is a " . $idxByName[$n]['type'] . " index, not FULLTEXT — searching will find nothing.";
        $fixes[]    = "Drop that index and re-run Database Verification so it is rebuilt as FULLTEXT.";
    }
}
addSection($sections, "INDEXES", $lines ?: "(none)");

// ---- 4. FOREIGN KEY ----------------------------------------------------
$fk = $conn->query("SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.referential_constraints
                    WHERE constraint_schema = " . $conn->quote($dbName) . "
                      AND table_name        = " . $conn->quote($TABLE))->fetchAll(PDO::FETCH_NUM);
$cascade = false;
foreach ($fk as [$n, $rule]) if (strtoupper($rule) === 'CASCADE') $cascade = true;
if (!$cascade) {
    $problems[] = "The link to tickets is not set to delete corpus rows when a ticket is deleted.";
    $fixes[]    = "Run System -> Database Verification, which adds the foreign key.";
}
addSection($sections, "DELETING A TICKET REMOVES ITS SEARCHABLE COPY", [
    "This matters more than tidiness: the corpus holds a COPY of ticket text that",
    "can be searched without going through the ticket's own permission check, so a",
    "deleted ticket whose words were still findable would be a privacy problem.",
    "",
    $fk ? "Foreign keys:" : "Foreign keys: (none found)",
    ...array_map(fn($r) => sprintf("   %-26s ON DELETE %s", $r[0], $r[1]), $fk),
]);

// ---- 5. THE SETTINGS THAT SILENTLY BREAK SEARCH ------------------------
// None of these raise an error when wrong. They just make words vanish.
$get = function (string $v) use ($conn) {
    try { return (string)$conn->query("SELECT @@$v")->fetchColumn(); } catch (Throwable $e) { return '(unavailable)'; }
};
$minTok  = $get('innodb_ft_min_token_size');
$maxTok  = $get('innodb_ft_max_token_size');
$stopOn  = $get('innodb_ft_enable_stopword');

$lines = [
    "These decide which words MySQL bothers to remember. When one is wrong nothing",
    "errors — searches just quietly return nothing for certain words.",
    "",
    sprintf("   Shortest word remembered : %s  (MySQL default 3)", $minTok),
    sprintf("   Longest word remembered  : %s  (MySQL default 84)", $maxTok),
    sprintf("   Common words ignored     : %s  (MySQL default 1 = yes)", $stopOn),
    "",
];
if (is_numeric($maxTok) && (int)$maxTok < 40) {
    $lines[]    = "   ⚠️  ANY WORD LONGER THAN {$maxTok} LETTERS IS IGNORED COMPLETELY.";
    $lines[]    = "       e.g. \"authentication\", \"configuration\", \"workstation\" would never be found.";
    $problems[] = "This server ignores words longer than $maxTok letters, so searches for long words find nothing.";
    $fixes[]    = "Set innodb_ft_max_token_size=84 in the MySQL config (my.ini / my.cnf), restart MySQL, then run Database Verification to rebuild the index.";
}
if (is_numeric($minTok) && (int)$minTok > 3) {
    $lines[]    = "   ⚠️  Words shorter than {$minTok} letters are ignored — short codes and abbreviations will not be found.";
    $problems[] = "This server ignores words shorter than $minTok letters.";
    $fixes[]    = "Set innodb_ft_min_token_size=3 in the MySQL config, restart MySQL, then run Database Verification.";
}
if ((string)$minTok === '0' || (string)$stopOn === '0') {
    $lines[] = "   NOTE: this server is more permissive than a standard MySQL, so search will";
    $lines[] = "         behave BETTER here than on a customer's default install. Fine for a";
    $lines[] = "         development box — just don't judge search quality only from here.";
}
addSection($sections, "SERVER SETTINGS THAT AFFECT WHAT CAN BE FOUND", $lines);

// ---- 6. WHAT IS IN THE CORPUS -----------------------------------------
$rows  = (int)$conn->query("SELECT COUNT(*) FROM `$TABLE`")->fetchColumn();
$lines = ["Rows in the corpus : " . number_format($rows)];
if ($rows) {
    foreach ($conn->query("SELECT source_type, COUNT(*) FROM `$TABLE` GROUP BY source_type ORDER BY 2 DESC") as $r) {
        $lines[] = sprintf("   %-14s %s", $r[0], number_format((int)$r[1]));
    }
    $last = (string)$conn->query("SELECT MAX(indexed_datetime) FROM `$TABLE`")->fetchColumn();
    $lines[] = "Last indexed       : " . ($last ?: '(never)');
} else {
    $lines[] = "";
    $lines[] = "Empty is expected today — nothing writes to the corpus yet. It becomes";
    $lines[] = "meaningful once ticket content is being indexed.";
}
addSection($sections, "CORPUS CONTENTS", $lines);

// ---- 7. LIVE SEARCH -----------------------------------------------------
// The only check that proves searching actually works end to end. Must commit —
// see the header note about InnoDB and uncommitted rows.
$liveLines = [];
$searchWorks = null;
try {
    $conn->prepare("DELETE FROM `$TABLE` WHERE source_type = ?")->execute([$PROBE]);
    $ins = $conn->prepare("INSERT INTO `$TABLE` (source_type, source_id, tenant_scope, title, body, source_datetime)
                           VALUES (?, 1, 'default', ?, ?, UTC_TIMESTAMP())");
    $ins->execute([$PROBE, 'D007 probe document', 'the quick brown fox jumps over the lazy dog']);

    $q = $conn->prepare("SELECT COUNT(*) FROM `$TABLE` WHERE source_type = ? AND MATCH(title,body) AGAINST (? IN BOOLEAN MODE)");
    $q->execute([$PROBE, '+brown +fox']);
    $found = (int)$q->fetchColumn();
    $q->execute([$PROBE, '+zzzznotpresentzzzz']);
    $absent = (int)$q->fetchColumn();

    $searchWorks = ($found === 1 && $absent === 0);
    $liveLines[] = "Wrote one probe row, searched for it, then deleted it.";
    $liveLines[] = "";
    $liveLines[] = "   searching for a word that IS present     : " . ($found === 1 ? "found it — OK" : "FOUND $found (expected 1)");
    $liveLines[] = "   searching for a word that is NOT present : " . ($absent === 0 ? "found nothing — OK" : "FOUND $absent (expected 0)");
    if (!$searchWorks) {
        $problems[] = "A live search did not behave correctly on a document this tool wrote itself.";
        $fixes[]    = "Check the index section above, then re-run Database Verification.";
    }
} catch (Throwable $e) {
    $searchWorks = false;
    $liveLines[] = "The live search test could not run: " . $e->getMessage();
    $problems[]  = "A live search could not be performed: " . $e->getMessage();
} finally {
    try { $conn->prepare("DELETE FROM `$TABLE` WHERE source_type = ?")->execute([$PROBE]); } catch (Throwable $e) {}
}
$leftover = 0;
try {
    $s = $conn->prepare("SELECT COUNT(*) FROM `$TABLE` WHERE source_type = ?");
    $s->execute([$PROBE]);
    $leftover = (int)$s->fetchColumn();
} catch (Throwable $e) {}
$liveLines[] = "";
$liveLines[] = "Probe rows left behind : " . $leftover . ($leftover === 0 ? "  (cleaned up)" : "  ⚠️ NOT CLEANED UP");
if ($leftover !== 0) $problems[] = "The tool left $leftover probe row(s) behind in the corpus.";
addSection($sections, "LIVE SEARCH TEST", $liveLines);

// ---- 8. VERDICT --------------------------------------------------------
if (!$problems) {
    addSection($sections, "VERDICT", [
        "ALL GOOD — the search corpus is present, correctly indexed, protected by the",
        "delete cascade, and a real search performed on it worked.",
        "",
        $rows === 0
            ? "The corpus is empty, which is expected until ticket content is indexed."
            : "It currently holds " . number_format($rows) . " searchable rows.",
    ]);
} else {
    $v = ["PROBLEMS FOUND — " . count($problems) . ":", ""];
    foreach ($problems as $i => $p) $v[] = sprintf("  %d. %s", $i + 1, $p);
    $v[] = "";
    $v[] = "WHAT TO DO:";
    foreach (array_values(array_unique($fixes)) as $i => $f) $v[] = sprintf("  %d. %s", $i + 1, $f);
    addSection($sections, "VERDICT", $v);
}

emit_and_exit($sections);
