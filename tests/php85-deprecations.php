<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * PHP 8.5: a deprecation must never stop FreeITSM working.
 *
 * Reported 23 Nov 2026 on PHP 8.5: replying to a ticket said "You do not have access
 * to this module". PHP 8.5 deprecated PDO::MYSQL_ATTR_INIT_COMMAND, so EVERY database
 * connection raised a deprecation; send_email.php turned every notice into an
 * exception; the module access check caught it and failed closed. This test pins:
 *
 *   1. includes/db.php picks Pdo\Mysql::ATTR_INIT_COMMAND when it exists, the old
 *      constant only where it does not - and both are the same number;
 *   2. no code calls curl_close() (a no-op since PHP 8.0, deprecated in 8.5);
 *   3. every endpoint that turns notices into exceptions lets deprecations through -
 *      proved by raising one through send_email.php's real handler, with a control
 *      showing the old handler would have thrown;
 *   4. the module access checks log why they refuse when an exception is the reason.
 *
 *   php tests/php85-deprecations.php
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

/** Every PHP file in the app, without vendored code. */
function appPhpFiles(): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (!str_ends_with($p, '.php') || preg_match('~/(vendor|node_modules|\.git)/~', $p)) continue;
        $out[] = $p;
    }
    return $out;
}
/** A file's code with comments and strings blanked, so only real calls count. */
function codeOnly(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            $out .= str_repeat(' ', strlen($t[1]));
        } else {
            $out .= is_array($t) ? $t[1] : $t;
        }
    }
    return $out;
}

echo "1. The database connection's options\n";
require_once 'includes/db.php';
$opts = dbConnectionOptions();
check('the time zone command is set, under the number PDO expects', ($opts[PDO::MYSQL_ATTR_INIT_COMMAND] ?? null) === "SET time_zone = '+00:00'", json_encode($opts));
if (defined('Pdo\Mysql::ATTR_INIT_COMMAND')) {
    check('Pdo\Mysql::ATTR_INIT_COMMAND (PHP 8.4+) is the same number as the old constant', constant('Pdo\Mysql::ATTR_INIT_COMMAND') === PDO::MYSQL_ATTR_INIT_COMMAND);
}
$files = appPhpFiles();
$bare = [];
foreach ($files as $p) {
    if ($p === './tests/php85-deprecations.php') continue;
    $raw = (string)file_get_contents($p);
    if (preg_match('/PDO::MYSQL_ATTR_[A-Z_]+/', codeOnly($raw)) && !str_contains($raw, "defined('Pdo\\Mysql::")) $bare[] = $p;
}
check('no file uses a PDO::MYSQL_ATTR_ constant without the PHP 8.4+ name first', !$bare, implode(', ', $bare));

echo "2. curl_close()\n";
$calls = [];
foreach ($files as $p) {
    if (preg_match('/\bcurl_close\s*\(/', codeOnly((string)file_get_contents($p)))) $calls[] = $p;
}
check('nothing calls curl_close() (it does nothing since PHP 8.0 and is deprecated in 8.5)', !$calls, implode(', ', array_slice($calls, 0, 8)));

echo "3. Handlers that turn notices into exceptions\n";
$throwers = [];
foreach ($files as $p) {
    if (!preg_match('~^\./api/~', $p)) continue;   // tests and CLI scripts may be as strict as they like
    $src = (string)file_get_contents($p);
    if (preg_match('/set_error_handler\s*\(\s*function[^{]*\{(.{0,400}?)throw new ErrorException/s', $src, $m) && !str_contains($m[1], 'E_DEPRECATED')) $throwers[] = $p;
}
check('every API endpoint that throws on notices lets deprecations through', !$throwers, implode(', ', $throwers));

// The real handler from send_email.php, run here.
$src = (string)file_get_contents('api/tickets/send_email.php');
preg_match('/set_error_handler\((function\(\$severity, \$message, \$file, \$line\) \{.*?\n\})\);/s', $src, $m);
check("send_email.php's handler was found", !empty($m[1]));
if (!empty($m[1])) {
    $handler = eval('return ' . $m[1] . ';');
    set_error_handler($handler);
    $thrown = null;
    try { trigger_error('PHP 8.5 style deprecation', E_USER_DEPRECATED); } catch (Throwable $e) { $thrown = $e; }
    check('a deprecation passes through it - nothing thrown, so the access check is not tripped', $thrown === null, $thrown ? $thrown->getMessage() : '');
    $thrown = null;
    try { trigger_error('a real warning', E_USER_WARNING); } catch (Throwable $e) { $thrown = $e; }
    check('...while a real warning still stops the send', $thrown instanceof ErrorException);
    restore_error_handler();
    // CONTROL: the handler as it was before the fix throws on the same deprecation.
    set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
    $thrown = null;
    try { trigger_error('PHP 8.5 style deprecation', E_USER_DEPRECATED); } catch (Throwable $e) { $thrown = $e; }
    restore_error_handler();
    check('CONTROL: the old handler threw on it (which is how replies were refused)', $thrown instanceof ErrorException);
}

echo "4. The access checks say why\n";
$fn = (string)file_get_contents('includes/functions.php');
check('requireModuleAccess / requireAnyModuleAccess / requireModuleAccessJson log the exception behind a refusal',
    substr_count($fn, "error_log('module access check failed: '") === 3);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
