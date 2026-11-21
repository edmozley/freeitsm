<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * File stores - one list, one helper (wiki File-Storage-Locations-Design, step 1).
 *
 *   php tests/file-stores.php
 *
 * 1. fileStoreDir() gives exactly today's folder for every store (step 1 moves nothing).
 * 2. Every store in FILE_STORES is a folder the persistence list already knows about.
 * 3. GUARD: for each store converted to the helper, no PHP outside the allow-list
 *    names its folder in a string - so nobody builds its path by hand again. Read
 *    with PHP's tokenizer, so a folder named in a comment is fine and one in code
 *    is not, however the line is laid out.
 * 4. Positive controls: the scanner flags a hand-built path, and ignores a comment.
 *
 * No database, no web server.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/file_stores.php';
require_once $root . '/includes/storage_persistence.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nFile stores\n" . str_repeat('=', 70) . "\n";

/**
 * Stores whose every path now comes from fileStoreDir() / fileStorePath().
 * Add a store here in the same commit that converts it.
 */
const CONVERTED = ['form-images', 'label-fonts', 'war-room'];

/**
 * Files allowed to name a converted store's folder, and why. Each is a LIST of
 * folders, not a path being built - and step 1 replaces the lists with FILE_STORES.
 */
const ALLOWED = [
    'includes/file_stores.php'                     => 'the one place',
    'includes/storage_persistence.php'             => 'list - what a container must persist',
    'includes/storage_inventory.php'               => 'list - Storage usage rows',
    'includes/uploads.php'                         => 'list - UPLOAD_HTACCESS_ROOTS',
    'api/system/debug-tools/D009_guarded_paths.php' => 'URL probes of the folders in the web root',
];

// ---- 1. Today's folders --------------------------------------------------
foreach (FILE_STORES as $id => $s) {
    ok("$id is today's folder ({$s['folder']})", fileStoreDir($id) === $root . '/' . $s['folder'], fileStoreDir($id));
}
ok("fileStorePath joins without doubling the slash",
   fileStorePath('form-images', '/12/ab.png') === $root . '/forms/images/12/ab.png');
ok("fileStorePath('') is the folder itself", fileStorePath('war-room', '') === fileStoreDir('war-room'));
$threw = false;
try { fileStoreDir('no-such-store'); } catch (InvalidArgumentException) { $threw = true; }
ok('an unknown store id is refused, not guessed', $threw);

// ---- 2. Known to the persistence list --------------------------------------
$persist = array_column(storagePersistenceDirectories(), 'rel');
foreach (FILE_STORES as $id => $s) {
    $f = $s['folder'];
    $known = in_array($f, $persist, true) || (str_starts_with($f, 'uploads/') && in_array('uploads', $persist, true));
    ok("$id is a folder a Docker install persists", $known, $f);
}

// ---- 3. Guard ------------------------------------------------------------
/** [file => [line, ...]] where a string token names $folder. */
function storeFolderInCode(string $code, string $folder): array
{
    $hits = [];
    foreach (token_get_all($code) as $t) {
        if (!is_array($t)) continue;
        if (!in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) continue;
        if (str_contains(str_replace('\\', '/', $t[1]), $folder)) $hits[] = $t[2];
    }
    return $hits;
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$php = [];
foreach ($files as $f) {
    if ($f->getExtension() !== 'php') continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if (preg_match('~^(tests|local|vendor|scripts|\.git|engineering-wiki)/~', $rel)) continue;
    $php[$rel] = $f->getPathname();
}
foreach (CONVERTED as $id) {
    $folder = FILE_STORES[$id]['folder'];
    $bad = [];
    foreach ($php as $rel => $abs) {
        if (isset(ALLOWED[$rel])) continue;
        $code = file_get_contents($abs);
        if (!str_contains(str_replace('\\', '/', $code), $folder)) continue;   // quick skip
        foreach (storeFolderInCode($code, $folder) as $line) $bad[] = "$rel:$line";
    }
    ok("$id: no path to '$folder' built by hand (" . count($php) . " files)", !$bad, implode(', ', array_slice($bad, 0, 8)));
}

// ---- 4. Positive controls ------------------------------------------------
ok('control: a hand-built path is caught',
   storeFolderInCode("<?php \$p = dirname(__DIR__) . '/forms/images/' . \$x;", 'forms/images') === [1]);
ok('control: a double-quoted one is caught too',
   storeFolderInCode("<?php \$p = \"{\$root}/forms/images/{\$x}\";", 'forms/images') !== []);
ok('control: a folder named only in a comment is not',
   storeFolderInCode("<?php // files live in forms/images\n/* and forms/images */ \$p = 1;", 'forms/images') === []);

echo str_repeat('-', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
