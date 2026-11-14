<?php
/* 🔴 NEVER OVER THE WEB. See tests/web-exposure-guard.php. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Every folder the application stores files in survives a Docker rebuild.
 *
 *   php tests/docker-storage-volumes.php
 *
 * 🔴 A folder that is not on a volume is deleted by the next
 * `docker compose up -d --build`. Three places have to agree, and they had drifted:
 *
 *   storagePersistenceDirectories()  the list System -> Setup and D013 warn from, and
 *                                    build docker-compose.override.yml from;
 *   docker-compose.yml               the volumes an install gets without doing anything;
 *   the Dockerfile                   which creates each folder as www-data's - a volume on
 *                                    a folder the image lacks is root's, and PHP cannot
 *                                    write to it.
 *
 * forms/images (pictures on forms and catalogue items) and system/uploads/label-fonts
 * were in none of them, and the compose file mounted three folders of the eleven -
 * so documents attached to tickets were lost on every rebuild of a default install.
 *
 * The last part scans the code: a folder that something stores uploads in must be
 * in the list, so the next new one cannot be forgotten the same way.
 *
 * Reads files only: no database, no web server, no Docker.
 */

$root = str_replace('\\', '/', dirname(__DIR__));
require_once $root . '/includes/storage_persistence.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  - $detail" : '') . "\n"; }
}

echo "Every folder the application stores files in survives a Docker rebuild\n";
echo str_repeat('=', 72) . "\n";

// The app's folders - not the encryption key's, which the compose file mounts on its own path.
$listed = [];
foreach (storagePersistenceDirectories() as $d) {
    if (!empty($d['critical'])) continue;
    $listed[] = trim($d['rel'], '/');
}
check('the list names the folders it was missing', in_array('forms/images', $listed, true) && in_array('system/uploads/label-fonts', $listed, true));

$compose = str_replace("", '', (string)file_get_contents($root . '/docker-compose.yml'));
$dockerfile = (string)file_get_contents($root . '/Dockerfile');
preg_match_all('~^\s*-\s*([a-z0-9-]+):/var/www/html/([^\s#]+)~m', $compose, $m, PREG_SET_ORDER);
$mounts = [];
foreach ($m as $row) $mounts[trim($row[2], '/')] = $row[1];
// The top-level volumes: block - the last one, after the services.
$tail = substr($compose, (int)strrpos($compose, "\nvolumes:"));
preg_match_all('~^  ([a-z0-9-]+):\s*$~m', $tail, $top);
$declared = $top[1];

foreach ($listed as $rel) {
    check("$rel is on a volume in docker-compose.yml", isset($mounts[$rel]));
    if (isset($mounts[$rel])) {
        // The name System -> Setup gives it, so an install that already made its own
        // override keeps the same volume - except the three the file has always had.
        $legacy = ['tickets/attachments' => 'attachments', 'change-management/attachments' => 'cm-attachments'];
        $want = $legacy[$rel] ?? storagePersistenceVolumeName($rel);
        check("...named $want", $mounts[$rel] === $want, 'is ' . $mounts[$rel]);
        check("...and declared under volumes:", in_array($mounts[$rel], $declared, true));
    }
    $made = preg_match('~/var/www/html/' . preg_quote($rel, '~') . '(\s|\\\\)~', $dockerfile) === 1
         || is_dir($root . '/' . $rel);   // shipped in the repo, so in the image
    check("...and exists in the image", $made);
}
check('uploads/files keeps its own volume (Files)', ($mounts['uploads/files'] ?? '') === 'files');

// ── the code: every folder uploads are stored in is in the list ──────────────
// A path built from __DIR__ (or dirname of it) in a file that stores uploads.
$writers = '~uploadStoreFile\(|uploadPrepareDir\(|uploadPrepareWebServableDir\(|uploadStoreBytes\(|move_uploaded_file\(~';
// Folders that are not user data: losing them on a rebuild loses nothing that matters.
$notData = ['logs'];
$skipDirs = ['vendor', 'tests', 'engineering-wiki', 'scripts', 'node_modules', '.git'];
$found = [];
// Pruned as it walks: never into .git, the vendored code, or the stored files themselves.
$prune = array_merge($skipDirs, $listed, ['uploads']);
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    function ($f) use ($root, $prune) {
        if (!$f->isDir()) return true;
        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
        return !in_array($rel, $prune, true) && !in_array($f->getFilename(), ['.git', 'node_modules', 'vendor'], true);
    }
));
foreach ($it as $f) {
    $path = str_replace('\\', '/', $f->getPathname());
    if (substr($path, -4) !== '.php') continue;
    $relFile = substr($path, strlen($root) + 1);
    if (in_array(explode('/', $relFile)[0], $skipDirs, true)) continue;
    $src = (string)file_get_contents($path);
    if (!preg_match($writers, $src)) continue;
    $dir = dirname($path);
    preg_match_all("~(__DIR__|dirname\\(__DIR__(?:,\\s*(\\d))?\\)|dirname\\(dirname\\(__DIR__\\)\\))\\s*\\.\\s*['\"]([^'\"]+)['\"]~", $src, $hits, PREG_SET_ORDER);
    foreach ($hits as $h) {
        $base = $dir;
        if ($h[1] === 'dirname(dirname(__DIR__))') $base = dirname($dir, 2);
        elseif (str_starts_with($h[1], 'dirname')) $base = dirname($dir, (int)($h[2] ?: 1));
        $lit = $h[3];
        if (preg_match('~\.[a-z0-9]{1,5}$~i', $lit)) continue;   // a file (a require), not a folder
        $full = $base . '/' . ltrim($lit, '/');
        $parts = [];
        foreach (explode('/', $full) as $seg) {
            if ($seg === '..') array_pop($parts); elseif ($seg !== '.' && $seg !== '') $parts[] = $seg;
        }
        $norm = implode('/', $parts);
        $rootNorm = implode('/', array_filter(explode('/', $root), fn($s) => $s !== ''));
        if (!str_starts_with($norm, $rootNorm . '/')) continue;
        $rel = trim(substr($norm, strlen($rootNorm) + 1), '/');
        if ($rel === '' || in_array(explode('/', $rel)[0], ['includes', 'api', 'assets', 'lang', 'vendor', 'workflow'], true)) continue;
        $found[$rel][] = $relFile;
    }
}
check('the scan found the folders uploads are stored in', count($found) >= 5, implode(', ', array_keys($found)));
foreach ($found as $rel => $files) {
    $covered = in_array(explode('/', $rel)[0], $notData, true);
    foreach ($listed as $l) if ($rel === $l || str_starts_with($rel . '/', $l . '/')) $covered = true;
    check("$rel (stored in by " . implode(', ', array_unique($files)) . ') is in the list', $covered);
}

echo str_repeat('-', 72) . "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
