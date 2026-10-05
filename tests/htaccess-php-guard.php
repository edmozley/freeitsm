<?php
/* 🔴 NEVER OVER THE WEB. See tests/web-exposure-guard.php. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * No .htaccess may carry a PHP directive outside <IfModule mod_php*.c>.
 *
 *   php tests/htaccess-php-guard.php
 *
 * 🔴 GH #115. php_flag and php_value are mod_php commands. On Apache running PHP
 * through PHP-FPM, mod_php is not loaded, Apache does not know the command, and
 * it answers EVERY request for every file in that folder with a 500. Every
 * upload folder carried an unguarded `php_flag engine off` - nine shipped files
 * and three templates that write more at run time - so on PHP-FPM the logo, the
 * portal logo and LMS course content never loaded.
 *
 * Checks, in order:
 *   1. the scanner itself, against small fixtures, including the shapes it
 *      must NOT be fooled by (a negated IfModule, a FilesMatch on its own);
 *   2. every .htaccess in the repository;
 *   3. what the three run-time templates actually write, by running them into a
 *      temporary folder - and that the no-execute protection is still there;
 *   4. the repair that Database Verify runs over an upgraded install.
 *
 * Reads and writes only a temporary folder: no database, no web server.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/uploads.php';
require_once $root . '/includes/lms_package.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  - $detail" : '') . "\n"; }
}

echo "PHP directives in .htaccess must be inside <IfModule mod_php*.c> (GH #115)\n";
echo str_repeat('=', 72) . "\n";

// ---------------------------------------------------------------- 1. scanner
echo "\nThe scanner\n";
$bareOld = "# Branding uploads.\nphp_flag engine off\nRemoveHandler .php\n<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n";
check('NEGATIVE CONTROL: the file as shipped in 3.1.0 is flagged on line 2',
      htaccessUnguardedPhpDirectives($bareOld) === [2], json_encode(htaccessUnguardedPhpDirectives($bareOld)));
$cases = [
    'inside <IfModule mod_php.c>'            => ["<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n", []],
    'inside <IfModule mod_php7.c>'           => ["<IfModule mod_php7.c>\n  php_value x 1\n</IfModule>\n", []],
    'inside <IfModule php_module>'           => ["<IfModule php_module>\n  php_value x 1\n</IfModule>\n", []],
    'inside <IfModule !mod_php.c> is NOT a guard' => ["<IfModule !mod_php.c>\n  php_flag engine off\n</IfModule>\n", [2]],
    'inside <FilesMatch> alone is NOT a guard'    => ["<FilesMatch \"x\">\n  php_flag engine off\n</FilesMatch>\n", [2]],
    'FilesMatch inside a mod_php guard'      => ["<IfModule mod_php.c>\n<FilesMatch \"x\">\nphp_flag engine off\n</FilesMatch>\n</IfModule>\n", []],
    'after a guard has CLOSED'               => ["<IfModule mod_php.c>\n</IfModule>\nphp_value x 1\n", [3]],
    'php_admin_value is caught too'          => ["php_admin_value x 1\n", [1]],
    'case does not hide it (Apache ignores case)' => ["PHP_FLAG engine off\n", [1]],
    'a comment is not a directive'           => ["# php_flag engine off\n", []],
    'CRLF line endings'                      => ["# x\r\nphp_flag engine off\r\n", [2]],
];
foreach ($cases as $label => [$text, $want]) {
    $got = htaccessUnguardedPhpDirectives($text);
    check($label, $got === $want, 'got ' . json_encode($got) . ', want ' . json_encode($want));
}

// ---------------------------------------------------------------- 2. the repository
echo "\nEvery .htaccess in the repository\n";
$files = [];
$ls = @shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -- "*.htaccess" ".htaccess" 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
foreach (preg_split('/\r?\n/', trim((string)$ls)) as $f) {
    if ($f !== '' && basename($f) === '.htaccess') $files[] = $f;
}
check('found the shipped .htaccess files through git (at least 10)', count($files) >= 10, count($files) . ' found');
foreach ($files as $f) {
    $bad = htaccessUnguardedPhpDirectives((string)file_get_contents($root . '/' . $f));
    check($f, $bad === [], 'unguarded PHP directive on line(s) ' . implode(', ', $bad));
}

// ---------------------------------------------------------------- 3. run-time templates
echo "\nWhat the run-time templates write\n";
$tmp = sys_get_temp_dir() . '/freeitsm-ht-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0755, true);
$written = [
    'uploadPrepareDir()'            => function ($d) { uploadPrepareDir($d); },
    'uploadPrepareWebServableDir()' => function ($d) { uploadPrepareWebServableDir($d); },
    'lmsHardenContentDir()'         => function ($d) { lmsHardenContentDir($d); },
];
$i = 0;
foreach ($written as $label => $make) {
    $dir = $tmp . '/t' . (++$i);
    $make($dir);
    $text = (string)@file_get_contents($dir . '/.htaccess');
    check("$label writes an .htaccess", $text !== '');
    check("$label: no unguarded PHP directive", htaccessUnguardedPhpDirectives($text) === []);
    // The protection must survive the fix: still switched off for mod_php, and
    // script-like names still refused on every Apache.
    check("$label: still turns PHP off under mod_php", (bool)preg_match('#<IfModule mod_php\.c>\s*php_flag engine off\s*</IfModule>#i', $text));
    check("$label: still refuses script-like file names", stripos($text, 'Require all denied') !== false);
}

// ---------------------------------------------------------------- 4. the repair
echo "\nThe repair Database Verify runs\n";
$fixed = htaccessGuardPhpDirectives($bareOld);
check('repairs the 3.1.0 file', htaccessUnguardedPhpDirectives($fixed) === []);
check('keeps a mod_php 8 copy of the directive', (bool)preg_match('#<IfModule mod_php\.c>\s*php_flag engine off\s*</IfModule>#', $fixed));
check('keeps a mod_php 7 copy of the directive', (bool)preg_match('#<IfModule mod_php7\.c>\s*php_flag engine off\s*</IfModule>#', $fixed));
check('leaves every other line alone', strpos($fixed, "RemoveHandler .php\n") !== false && strpos($fixed, '# Branding uploads.') === 0);
check('is idempotent', htaccessGuardPhpDirectives($fixed) === $fixed);
$crlf = htaccessGuardPhpDirectives("# x\r\nphp_flag engine off\r\n");
check('keeps CRLF line endings', strpos($crlf, "\r\n") !== false && preg_match("/(?<!\r)\n/", $crlf) === 0);
$clean = "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n";
check('does not touch a file that is already right', htaccessGuardPhpDirectives($clean) === $clean);

// The tree walk, on a fake application root shaped like an upgraded install.
$app = $tmp . '/app';
foreach (['system/uploads/branding/portal', 'tickets/attachments/0/42', 'lms/content', 'auth'] as $d) @mkdir("$app/$d", 0755, true);
file_put_contents("$app/system/uploads/branding/portal/.htaccess", $bareOld);
file_put_contents("$app/tickets/attachments/0/42/.htaccess", $bareOld);
file_put_contents("$app/lms/content/.htaccess", $clean);
file_put_contents("$app/auth/.htaccess", $bareOld);   // outside the upload folders: not ours to touch
check('repairs the two broken files under the upload folders', uploadRepairHtaccessTree($app) === 2);
check('the portal logo folder is now clean', htaccessUnguardedPhpDirectives(file_get_contents("$app/system/uploads/branding/portal/.htaccess")) === []);
check('a second run changes nothing', uploadRepairHtaccessTree($app) === 0);
check('a folder outside the upload roots is left alone', file_get_contents("$app/auth/.htaccess") === $bareOld);

// Tidy up.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($tmp);

echo "\n" . str_repeat('=', 72) . "\n";
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
