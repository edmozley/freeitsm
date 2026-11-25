<?php
/* 🔴 NEVER OVER THE WEB. See tests/web-exposure-guard.php. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Every foreign-key name in database/freeitsm.sql, and in Database Verification's
 * own lists (api/system/db_verify.php), is used for ONE foreign key only.
 *
 *   php tests/constraint-names-unique.php
 *
 * 🔴 MySQL and MariaDB name foreign keys per DATABASE, not per table. 5.8.0 gave
 * consumable_field_values a key called fk_cfv_field, the name company_field_values
 * already had, so importing freeitsm.sql stopped there with error 1826 - and the
 * Docker quickstart, which loads freeitsm.sql as its first-run script, never
 * reached the admin account seeded after it. Database Verification's
 * try { ADD CONSTRAINT } catch {} hid the same clash on upgraded installs: the key
 * was simply never added. Nothing reads a key's name, so a clash only shows when
 * the database refuses it.
 *
 * Checks: the scanner on a small fixture (a clash it must find, two tables it must
 * not confuse), then the two real sources. A name may appear in both sources for
 * the SAME table (Verification adds the key a fresh install already has) - only
 * one name on two different tables is a clash.
 */

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %s\n", $label); }
    else       { $fail++; printf("  FAIL %s %s\n", $label, $detail); }
}

/** [name => [table, ...]] from CREATE TABLE ... CONSTRAINT `x` FOREIGN KEY blocks. */
function sqlForeignKeys(string $sql): array {
    $out = [];
    if (!preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\)\s*ENGINE/si', $sql, $tables, PREG_SET_ORDER)) return $out;
    foreach ($tables as [, $table, $body]) {
        preg_match_all('/CONSTRAINT\s+`?(\w+)`?\s+FOREIGN KEY/i', $body, $m);
        foreach ($m[1] as $name) $out[$name][] = $table;
    }
    // ALTER TABLE x ADD CONSTRAINT y FOREIGN KEY, outside the CREATE blocks
    preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+ADD CONSTRAINT\s+`?(\w+)`?\s+FOREIGN KEY/i', $sql, $m, PREG_SET_ORDER);
    foreach ($m as [, $table, $name]) $out[$name][] = $table;
    return $out;
}

/** [name => [table, ...]] from db_verify.php's FK lists and its ALTER TABLE strings. */
function verifyForeignKeys(string $php): array {
    $out = [];
    // ['table', 'fk_name', 'ref_table', ...] rows
    preg_match_all("/\[\s*'(\w+)'\s*,\s*'(fk_\w+)'\s*,/", $php, $m, PREG_SET_ORDER);
    foreach ($m as [, $table, $name]) $out[$name][] = $table;
    preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+ADD CONSTRAINT\s+`?(\w+)`?\s+FOREIGN KEY/i', $php, $m, PREG_SET_ORDER);
    foreach ($m as [, $table, $name]) $out[$name][] = $table;
    return $out;
}

/** Names used on more than one table. */
function clashes(array ...$sources): array {
    $all = [];
    foreach ($sources as $src) foreach ($src as $name => $tables) foreach ($tables as $t) $all[$name][$t] = true;
    return array_map('array_keys', array_filter($all, fn($t) => count($t) > 1));
}

echo "\nForeign-key names are unique per database\n" . str_repeat('=', 60) . "\n";

echo "\n1. The scanner\n";
$fixture = "CREATE TABLE `a` (\n `id` INT,\n CONSTRAINT `fk_x` FOREIGN KEY (`id`) REFERENCES `z` (`id`)\n) ENGINE=InnoDB;\n"
         . "CREATE TABLE `b` (\n `id` INT,\n CONSTRAINT `fk_x` FOREIGN KEY (`id`) REFERENCES `z` (`id`),\n CONSTRAINT `fk_y` FOREIGN KEY (`id`) REFERENCES `z` (`id`)\n) ENGINE=InnoDB;\n";
$fk = sqlForeignKeys($fixture);
ok('finds every key with its table', ($fk['fk_x'] ?? []) === ['a', 'b'] && ($fk['fk_y'] ?? []) === ['b'], json_encode($fk));
ok('finds the clash', array_keys(clashes($fk)) === ['fk_x']);
ok('the same name for the same table in two sources is not a clash',
   clashes(['fk_y' => ['b']], ['fk_y' => ['b']]) === []);
ok('reads Verification rows', verifyForeignKeys("['t1', 'fk_q', 'r', 'c', 'id', 'CASCADE'],\n['t2', 'fk_q', 'r', 'c', 'id', null],") === ['fk_q' => ['t1', 't2']]);

echo "\n2. The real sources\n";
$sql = sqlForeignKeys(file_get_contents("$ROOT/database/freeitsm.sql"));
$ver = verifyForeignKeys(file_get_contents("$ROOT/api/system/db_verify.php"));
ok('freeitsm.sql has foreign keys to check (' . count($sql) . ')', count($sql) > 100);
ok('db_verify.php has foreign keys to check (' . count($ver) . ')', count($ver) > 50);
$c = clashes($sql);
ok('freeitsm.sql: no name on two tables', !$c, json_encode($c));
$c = clashes($ver);
ok('db_verify.php: no name on two tables', !$c, json_encode($c));
$c = clashes($sql, $ver);
ok('together: no name on two tables', !$c, json_encode($c));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
