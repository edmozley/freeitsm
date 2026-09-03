<?php
/**
 * config.php must not be load-bearing (GH #129).
 *
 * 🔴 WHAT THIS EXISTS TO CATCH. #1446 put dbConnectionOptions() into config.php and
 * changed eleven callers to use it. config.php is the OPERATOR'S file - it ships as
 * a template carrying a developer's own credentials path, so every install edits it
 * once and keeps that copy, and the Docker image copies docker/config.php straight
 * over the top of it. Upgrading therefore delivered the callers and left the
 * definition behind: HTTP 500, empty body, every page in the product.
 *
 * 🔑 The rule: config.php is for VALUES the operator chooses. Behaviour lives in
 * includes/, which upgrades with the product.
 *
 * ⚠️ Why the ordinary suite could not catch it: the development machine's config.php
 * IS the repository's config.php. On the one install where that file is not
 * customised, the function was present and everything worked. So this test must
 * never ask "does it work here" - it asks the structural question instead.
 *
 * Run: php tests/config-not-load-bearing.php
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-62s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL %-62s %s\n", $label, $detail); }
}

echo "\n1. config.php declares no functions at all\n";

// A `function foo(` at the start of a line in either config file is the exact
// shape of the #129 outage. Values are fine; behaviour is not.
foreach (['config.php', 'docker/config.php'] as $rel) {
    $src = file_get_contents("$root/$rel");
    preg_match_all('/^\s*function\s+(\w+)\s*\(/m', $src, $m);
    ok("$rel declares no functions", $m[1] === [],
       $m[1] ? 'FOUND: ' . implode(', ', $m[1]) : 'none');
}

echo "\n2. Every function the app calls has a home under includes/\n";

// The two that caused, or were one upgrade away from causing, #129.
$mustLiveInIncludes = [
    'dbConnectionOptions' => 'includes/db.php',
    'sslApplyCurl'        => 'includes/ssl.php',
    'sslResolveCaBundle'  => 'includes/ssl.php',
];
foreach ($mustLiveInIncludes as $fn => $home) {
    $src = file_exists("$root/$home") ? file_get_contents("$root/$home") : '';
    ok("$fn() is defined in $home", (bool)preg_match('/function\s+' . $fn . '\s*\(/', $src));
}

echo "\n3. Positive control: the app works with a config.php that defines nothing\n";

// This is the test that would have failed in #1446. It builds the situation every
// Docker user and every upgrader was actually in - a config.php supplying only
// values - and asks whether the product can still open a connection.
$stub = sys_get_temp_dir() . '/freeitsm_stub_config_' . getmypid() . '.php';
file_put_contents($stub, "<?php\n// values only, exactly like docker/config.php\ndefine('STUB_CONFIG_LOADED', true);\n");

$probe = sys_get_temp_dir() . '/freeitsm_probe_' . getmypid() . '.php';
file_put_contents($probe, '<?php
require_once ' . var_export($stub, true) . ';
require_once ' . var_export("$root/includes/db.php", true) . ';
require_once ' . var_export("$root/includes/ssl.php", true) . ';
echo function_exists("dbConnectionOptions") && function_exists("sslApplyCurl") ? "BOTH" : "MISSING";
$o = dbConnectionOptions();
echo isset($o[PDO::MYSQL_ATTR_INIT_COMMAND]) && strpos($o[PDO::MYSQL_ATTR_INIT_COMMAND], "+00:00") !== false ? "|UTC" : "|NOTUTC";
');
$out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1'));
ok('a values-only config.php still yields both functions', strpos($out, 'BOTH') !== false, $out);
ok('and the connection options still pin UTC', strpos($out, '|UTC') !== false, $out);

echo "\n4. The definition is guarded, so a stale config.php cannot redeclare it\n";

// An install upgrading from #1446 still carries the copy in its own config.php,
// and every caller loads config.php first. Without the guard those installs trade
// "undefined function" for "cannot redeclare" - the same outage, different message.
$legacy = sys_get_temp_dir() . '/freeitsm_legacy_' . getmypid() . '.php';
file_put_contents($legacy, '<?php
function dbConnectionOptions(): array { return [PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = \'+00:00\'"]; }
require_once ' . var_export("$root/includes/db.php", true) . ';
echo "SURVIVED";
');
$out2 = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($legacy) . ' 2>&1'));
ok('loading includes/db.php over a legacy definition does not fatal',
   strpos($out2, 'SURVIVED') !== false, $out2);

@unlink($stub); @unlink($probe); @unlink($legacy);

echo "\n" . str_repeat('-', 78) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
