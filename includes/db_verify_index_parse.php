<?php
/**
 * Shared parser + drift self-check for the index backfill list.
 *
 * `database/freeitsm.sql` is the source of truth for the schema;
 * `includes/db_verify_indexes.php` is a GENERATED mirror of its indexes that
 * Database Verification uses to restore missing ones. The risk is the classic
 * one: someone adds an index to freeitsm.sql and forgets to regenerate, so the
 * mirror goes stale and grown installs silently miss the new index — the very
 * drift the backfill was built to end.
 *
 * These two functions close that loop. ONE parser produces the list (via
 * scripts/gen_db_verify_indexes.php) and audits it (via the self-check that
 * db_verify runs on every pass), so the generator and the checker can never
 * disagree. Mirrors the capSelfCheck() / settings-manifest self-check pattern.
 */

/**
 * Extract every named secondary index from freeitsm.sql text:
 * [table, name, isUnique, columns]. Skips PRIMARY KEY, FOREIGN KEY, CONSTRAINT.
 */
function dbVerifyParseIndexesFromSql(string $sql): array {
    preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`([a-zA-Z0-9_]+)`\s*\((.*?)\n\)\s*ENGINE/s', $sql, $blocks, PREG_SET_ORDER);
    $rows = [];
    foreach ($blocks as $b) {
        foreach (explode("\n", $b[2]) as $line) {
            $line = trim($line);
            // cols may contain nested () for prefix lengths, e.g. (`name`(400)),
            // so capture through to the last ).
            if (preg_match('/^(UNIQUE\s+)?(?:KEY|INDEX)\s+`([a-zA-Z0-9_]+)`\s*(\(.*\))\s*,?\s*$/i', $line, $m)) {
                $rows[] = [$b[1], $m[2], trim($m[1]) !== '', preg_replace('/\s+/', '', $m[3])];
            }
        }
    }
    return $rows;
}

/**
 * Compare the committed generated list against a fresh parse of freeitsm.sql.
 * Returns human-readable problem strings; an empty array means they're in sync.
 *
 * Skips silently if freeitsm.sql isn't present (a trimmed deployment may not ship
 * it) — we never cry drift when we can't see the source of truth.
 */
function dbVerifyIndexListSelfCheck(?string $sqlPath = null, ?string $listPath = null): array {
    $sqlPath  = $sqlPath  ?? __DIR__ . '/../database/freeitsm.sql';
    $listPath = $listPath ?? __DIR__ . '/db_verify_indexes.php';

    if (!is_readable($sqlPath)) return [];
    if (!is_readable($listPath)) {
        return ['includes/db_verify_indexes.php is missing — run scripts/gen_db_verify_indexes.php.'];
    }

    $fresh     = dbVerifyParseIndexesFromSql((string)file_get_contents($sqlPath));
    $committed = require $listPath;

    // Key each index by "table.name" -> a signature of "unique + columns".
    $sig = fn($r) => ($r[2] ? 'UNIQUE ' : 'KEY ') . $r[3];
    $freshMap = $commMap = [];
    foreach ($fresh as $r)     $freshMap[$r[0] . '.' . $r[1]] = $sig($r);
    foreach ($committed as $r) $commMap[$r[0] . '.' . $r[1]] = $sig($r);

    $problems = [];
    foreach ($freshMap as $k => $v) {
        if (!isset($commMap[$k]))       $problems[] = "Index $k is in freeitsm.sql but missing from the backfill list.";
        elseif ($commMap[$k] !== $v)    $problems[] = "Index $k differs — freeitsm.sql has [$v], the list has [{$commMap[$k]}].";
    }
    foreach ($commMap as $k => $v) {
        if (!isset($freshMap[$k]))      $problems[] = "Index $k is in the backfill list but no longer in freeitsm.sql.";
    }
    return $problems;
}
