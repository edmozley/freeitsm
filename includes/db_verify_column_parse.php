<?php
/**
 * Column drift self-check — the sibling of db_verify_index_parse.php.
 *
 * The schema has TWO hand-maintained sources of truth and they must agree:
 *
 *   database/freeitsm.sql        what a FRESH install is built from
 *   includes/db_verify_schema.php   what Database Verification ALTERs an
 *                                   EXISTING install up to
 *
 * Indexes already have a guard, but they're the easy case: the index list is
 * GENERATED from freeitsm.sql, so drift only ever means "you forgot to
 * regenerate". Columns are harder — both files are written by hand, so they can
 * disagree in either direction, and each direction breaks a different install:
 *
 *   in db_verify but NOT freeitsm.sql → a NEW install is missing the column
 *                                       until someone runs Verification
 *   in freeitsm.sql but NOT db_verify → an EXISTING install never gains it
 *
 * The first of those shipped as a real bug: `asset_locations.tenant_id` was
 * added to Verification but not to freeitsm.sql, so a fresh install fell over on
 * the asset-locations screen while every upgraded install was fine.
 *
 * ⚠️ WHY THIS IS EASY TO MISS: while developing you only ever exercise the
 * UPGRADE path. You run Database Verification, it goes green, everything works.
 * The fresh-install path is invisible to you until a stranger downloads the
 * project — and then it's the ONLY path they take. Discipline can't close that;
 * a check that runs on every Verification can.
 *
 * Mirrors capSelfCheck() and the settings-manifest self-check: the source of
 * truth is code, and the app proves the mirror matches rather than trusting a
 * human to have kept it so.
 */

/**
 * Extract the declared COLUMNS of every table in freeitsm.sql:
 *   ['table' => ['column' => 'NORMALISED TYPE', ...], ...]
 *
 * Skips key/constraint lines (PRIMARY KEY, KEY, UNIQUE KEY, INDEX, CONSTRAINT,
 * FOREIGN KEY) and `--` comments, which is everything inside a CREATE TABLE
 * that isn't a column.
 */
function dbVerifyParseColumnsFromSql(string $sql): array {
    preg_match_all(
        '/CREATE TABLE(?: IF NOT EXISTS)?\s+`([a-zA-Z0-9_]+)`\s*\((.*?)\n\)\s*ENGINE/s',
        $sql, $blocks, PREG_SET_ORDER
    );
    $out = [];
    foreach ($blocks as $b) {
        $table = $b[1];
        $out[$table] = [];
        foreach (explode("\n", $b[2]) as $line) {
            $line = trim($line);
            if ($line === '' || strncmp($line, '--', 2) === 0) continue;
            if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX|CONSTRAINT|FOREIGN\s+KEY)\b/i', $line)) continue;
            // `column`  TYPE ...rest
            if (preg_match('/^`([a-zA-Z0-9_]+)`\s+(.+?),?\s*$/', $line, $m)) {
                $out[$table][$m[1]] = dbVerifyNormaliseColumnType($m[2]);
            }
        }
    }
    return $out;
}

/**
 * Reduce a column definition to a comparable shape.
 *
 * The two files are written by different hands in different styles, so a naive
 * string compare would cry drift constantly and the guard would get ignored —
 * alarm fatigue is how a check like this dies. We therefore compare only what
 * genuinely changes behaviour: the base type (with any length/precision), and
 * whether it is NOT NULL.
 *
 * Deliberately IGNORED: DEFAULT values, AUTO_INCREMENT, COMMENT, COLLATE,
 * ON UPDATE, and back-tick/whitespace/case differences. Those vary harmlessly
 * between the two files today, and a guard that fires on them is worse than no
 * guard at all.
 */
function dbVerifyNormaliseColumnType(string $def): string {
    $d = strtoupper(trim($def));
    $d = rtrim($d, ',');
    $notNull = (bool) preg_match('/\bNOT\s+NULL\b/', $d);
    // Base type + any (length) or (precision,scale), e.g. VARCHAR(100), DECIMAL(12,2)
    if (preg_match('/^([A-Z]+)\s*(\(\s*\d+\s*(?:,\s*\d+\s*)?\))?/', $d, $m)) {
        $base = $m[1] . (isset($m[2]) ? preg_replace('/\s+/', '', $m[2]) : '');
    } else {
        $base = preg_replace('/\s+.*$/', '', $d);
    }
    return $base . ($notNull ? ' NOT NULL' : ' NULL');
}

/**
 * Compare includes/db_verify_schema.php against a fresh parse of freeitsm.sql.
 * Returns human-readable problem strings; empty means they're in sync.
 *
 * Skips silently if freeitsm.sql isn't present (a trimmed deployment may not
 * ship it) — never cry drift when the source of truth isn't visible.
 *
 * $maxPerKind caps each category so one systemic mistake can't produce a
 * hundred-line card.
 */
function dbVerifyColumnSelfCheck(?string $sqlPath = null, ?string $schemaPath = null, int $maxPerKind = 6): array {
    $sqlPath    = $sqlPath    ?? __DIR__ . '/../database/freeitsm.sql';
    $schemaPath = $schemaPath ?? __DIR__ . '/db_verify_schema.php';

    if (!is_readable($sqlPath)) return [];
    if (!is_readable($schemaPath)) {
        return ['includes/db_verify_schema.php is missing — Database Verification cannot build any table.'];
    }

    $sqlTables = dbVerifyParseColumnsFromSql((string) file_get_contents($sqlPath));
    $expected  = require $schemaPath;

    $missingInSql = $missingInVerify = $typeDiff = $tableOnlyVerify = $tableOnlySql = [];

    foreach ($expected as $table => $cols) {
        if (!isset($sqlTables[$table])) {
            $tableOnlyVerify[] = $table;
            continue;
        }
        foreach ($cols as $col => $def) {
            if (!array_key_exists($col, $sqlTables[$table])) {
                $missingInSql[] = "$table.$col";
                continue;
            }
            $want = dbVerifyNormaliseColumnType($def);
            $have = $sqlTables[$table][$col];
            if ($want !== $have) {
                $typeDiff[] = "$table.$col (freeitsm.sql has $have, Verification expects $want)";
            }
        }
    }
    foreach ($sqlTables as $table => $cols) {
        if (!isset($expected[$table])) { $tableOnlySql[] = $table; continue; }
        foreach ($cols as $col => $_) {
            if (!array_key_exists($col, $expected[$table])) {
                $missingInVerify[] = "$table.$col";
            }
        }
    }

    $problems = [];
    $add = function (array $items, string $lead) use (&$problems, $maxPerKind) {
        if (!$items) return;
        $shown = array_slice($items, 0, $maxPerKind);
        $more  = count($items) - count($shown);
        $problems[] = $lead . ' ' . implode(', ', $shown) . ($more > 0 ? " (+$more more)" : '');
    };

    // Ordered worst-first: a fresh install being broken is the loudest failure.
    $add($missingInSql,
        '🔴 In Database Verification but MISSING from freeitsm.sql — a NEW install will not have:');
    $add($tableOnlyVerify,
        '🔴 Whole table in Database Verification but not in freeitsm.sql:');
    $add($missingInVerify,
        '🟠 In freeitsm.sql but MISSING from Database Verification — an EXISTING install will never gain:');
    $add($tableOnlySql,
        '🟠 Whole table in freeitsm.sql but not in Database Verification:');
    $add($typeDiff,
        '🟡 Declared differently in the two files:');

    return $problems;
}
