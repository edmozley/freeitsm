<?php
/**
 * Container detection under open_basedir (GH #127).
 *
 * WHAT THIS EXISTS TO CATCH
 * -------------------------
 * storagePersistenceInContainer() gates the issue #109 warning — the one telling
 * an operator that `docker compose up -d --build` is about to destroy their
 * uploads, unrecoverably. If it wrongly answers "no", the warning silently stops
 * appearing for the only people it was ever written for.
 *
 * 🔴 THE FAILURE MODE IS INVISIBLE ON A DEVELOPMENT MACHINE. It only appears when
 * `open_basedir` is set, which no developer here has, and its symptom is not an
 * error but SILENCE. That is exactly how the reported bug reached a user, and it
 * is why this file drives PHP as a subprocess with the restriction switched on
 * rather than testing the function in-process: the condition cannot be created
 * any other way.
 *
 * ⚠️ IT ALSO GUARDS AGAINST THE WRONG FIX. The reported warning goes away if you
 * simply re-point the check at a path open_basedir allows — and that tests clean
 * on any machine not running Docker, because there the right answer is false
 * anyway. Case 5 fails if anybody does that.
 *
 * Run:  php tests/container-detection.php
 * Safe: reads only. No database, no writes, nothing outside this repo.
 */

$root = dirname(__DIR__);
$php  = PHP_BINARY;
$pass = 0; $fail = 0;

function ok(string $what, bool $cond, string $detail = '') {
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    printf("  %s %-58s %s\n", $cond ? 'PASS' : 'FAIL', $what, $detail);
}

/**
 * Run a snippet in a fresh PHP process, optionally with open_basedir set and an
 * environment variable exported. Returns [stdout+stderr, exit code].
 */
function runPhp(string $code, ?string $basedir = null, array $env = []): array
{
    global $php, $root;
    $file = sys_get_temp_dir() . '/fi_cd_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, "<?php\nrequire '" . str_replace('\\', '/', $root)
        . "/includes/storage_persistence.php';\n" . $code);

    $args = [$php, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL'];
    if ($basedir !== null) $args[] = '-d';
    if ($basedir !== null) $args[] = 'open_basedir=' . $basedir;
    $args[] = $file;

    $cmd = implode(' ', array_map(fn($a) => escapeshellarg($a), $args));
    foreach ($env as $k => $v) putenv("$k=$v");
    $out = shell_exec($cmd . ' 2>&1');
    foreach ($env as $k => $v) putenv($k);          // unset again
    @unlink($file);
    return [(string) $out, 0];
}

// The restriction, pointed at this repo — the shape a control panel produces:
// a list of the site's own directories, and nothing above them.
$restricted = str_replace('\\', '/', $root) . PATH_SEPARATOR . sys_get_temp_dir();

echo "\n1. The reported symptom: no warning is printed any more\n";
[$out] = runPhp('var_dump(storagePersistenceInContainer());', $restricted);
ok('no open_basedir warning in the output',
   stripos($out, 'open_basedir') === false,
   stripos($out, 'open_basedir') === false ? '' : trim(substr($out, 0, 120)));
ok('still returns a bool', str_contains($out, 'bool('), trim($out));

echo "\n2. Positive control: the warning IS raised without the suppression\n";
// 🔑 Without this, case 1 would pass on a machine where open_basedir simply is
// not in force, and the test would be agreeing with the bug rather than the fix.
[$ctl] = runPhp('var_dump(file_exists("/.dockerenv"));', $restricted);
if (stripos($ctl, 'open_basedir') === false) {
    echo "  SKIP this platform does not raise the warning, so case 1 proves nothing here.\n";
} else {
    ok('an unsuppressed read does warn', true, 'the restriction is really in force');
}

echo "\n3. The environment marker is believed, with no disk access at all\n";
[$out] = runPhp('var_dump(storagePersistenceInContainer());', $restricted,
                ['FREEITSM_CONTAINER' => '1']);
ok('marker set  -> detected as a container', str_contains($out, 'bool(true)'), trim($out));
ok('and no warning while doing it', stripos($out, 'open_basedir') === false);

echo "\n4. The marker only ever says YES, never NO\n";
[$out] = runPhp('var_dump(storagePersistenceInContainer());', $restricted,
                ['FREEITSM_CONTAINER' => '0']);
ok('marker "0" -> falls through, does not force false',
   str_contains($out, 'bool(false)'), trim($out));   // no /.dockerenv on this box either

echo "\n5. 🔴 The check still asks about the ROOT of the filesystem\n";
// The wrong fix re-points this at DOCUMENT_ROOT or similar. That silences the
// warning and permanently breaks detection, and nothing else in this suite would
// notice, because on a machine that is not running Docker both answers are false.
$src = file_get_contents($root . '/includes/storage_persistence.php');
preg_match('/function storagePersistenceInContainer.*?\n\}/s', $src, $m);
$body = $m[0] ?? '';
ok("looks for '/.dockerenv' literally", str_contains($body, "'/.dockerenv'"));
ok('does NOT build the path from DOCUMENT_ROOT',
   stripos($body, 'DOCUMENT_ROOT') === false,
   stripos($body, 'DOCUMENT_ROOT') === false ? '' : 'RE-POINTED — Docker users would lose the #109 warning');
ok('the suppression is present', str_contains($body, '@file_exists'));

echo "\n6. The Dockerfile still sets the marker\n";
$dockerfile = (string) @file_get_contents($root . '/Dockerfile');
ok('Dockerfile sets FREEITSM_CONTAINER',
   (bool) preg_match('/^ENV\s+FREEITSM_CONTAINER=1/m', $dockerfile),
   $dockerfile === '' ? 'Dockerfile not readable' : '');

echo "\n7. open_basedir is reported honestly, not as "."\"not a container\"\n";
[$out] = runPhp('var_dump(storagePersistenceRootReadable());', $restricted);
ok('root reported as unreadable under the restriction', str_contains($out, 'bool(false)'), trim($out));
[$out] = runPhp('var_dump(storagePersistenceRootReadable());');
ok('and readable without it', str_contains($out, 'bool(true)'), trim($out));

echo "\n" . str_repeat('-', 78) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
