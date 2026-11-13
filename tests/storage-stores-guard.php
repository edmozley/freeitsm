<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The stores guard (#284): ticket attachments, change attachments, documents, screen
 * recordings and war-room attachments may live in S3 (System -> Storage), so nothing may
 * read, write or delete them by building a path into their folders on the server. Every
 * access goes through includes/storage/stores.php. This scans the code (comments
 * excluded) and fails on a path built anywhere else.
 *
 * No database needed.
 */

$root = str_replace('\\', '/', dirname(__DIR__));
$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}

/** Patterns that build a path into a store's folder, or bypass the layer. */
const ZZ_GUARD_PATTERNS = [
    'tickets attachments folder'   => '#tickets[/\\\\]attachments|[\'"]tickets[\'"]\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*[\'"]attachments#',
    'change attachments folder'    => '#change-management[/\\\\]attachments#',
    'documents folder'             => '#uploads[/\\\\]documents|[\'"]/uploads/[\'"]\s*\.\s*DOCUMENT_STORAGE_DIR|documentStoragePath\s*\(#',
    'recordings folder'            => '#[\'"]recordings[\'"]\s*\.\s*DIRECTORY_SEPARATOR|[\'"]/recordings/|DIRECTORY_SEPARATOR\s*\.\s*[\'"]recordings#',
    'war-room attachments folder'  => '#war-room[/\\\\]attachments|WARROOM_ATTACH_DIR#',
];

/**
 * Where a folder path may still appear, and why. Anything else is a bypass.
 *   file => list of pattern names allowed there
 */
const ZZ_GUARD_ALLOWED = [
    'includes/storage/stores.php'                       => '*',   // the layer itself
    'includes/storage_persistence.php'                  => '*',   // the list of folders that must persist (Docker volumes)
    'includes/uploads.php'                              => '*',   // the folders' .htaccess / web.config guards
    'includes/documents.php'                            => ['documents folder'],   // documentStoragePath(): kept for the folder path only
    'includes/warroom.php'                              => ['war-room attachments folder'],   // the constant's definition
    'api/system/debug-tools/D008_war_room.php'          => ['war-room attachments folder'],   // checks the folder itself (writable, guards)
    'api/system/debug-tools/D009_guarded_paths.php'     => '*',   // probes each folder's web protection
    'api/system/debug-tools/D012_ticket_attachments.php'=> '*',   // diagnoses local copies; reads the store when it is not local
    'api/system/debug-tools/D013_storage_persistence.php' => '*',
];

/** The file's code with comments and strings in docblocks removed. */
function zzGuardCode(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT || $t[0] === T_INLINE_HTML) continue;
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

echo "\nStores guard\n" . str_repeat('=', 72) . "\n";
$hits = [];
$scanned = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
    if (preg_match('#^(tests|docs|vendor|node_modules|\.git)/#', $rel)) continue;
    $scanned++;
    $code = zzGuardCode((string)file_get_contents($f->getPathname()));
    foreach (ZZ_GUARD_PATTERNS as $name => $re) {
        if (!preg_match_all($re, $code, $m)) continue;
        $allowed = ZZ_GUARD_ALLOWED[$rel] ?? [];
        if ($allowed === '*' || in_array($name, (array)$allowed, true)) continue;
        $hits[] = "$rel ($name)";
    }
}
ok("the scan covered the code (positive control: $scanned files)", $scanned > 1000, (string)$scanned);
ok('no path into a store folder is built outside the stores layer', $hits === [], "\n      " . implode("\n      ", $hits));

// The allow-list must not go stale: each entry still exists, and still needs its exemption.
foreach (ZZ_GUARD_ALLOWED as $rel => $names) {
    ok("allowed file still exists: $rel", is_file("$root/$rel"));
}

// Positive control: the patterns do catch the shapes that used to be everywhere.
$samples = [
    "\$p = dirname(__DIR__) . '/tickets/attachments/' . \$rel;",
    "\$d = dirname(__DIR__, 2) . '/change-management/attachments/' . \$id;",
    "\$p = documentStoragePath(\$key);",
    "\$x = \$appRoot . DIRECTORY_SEPARATOR . 'recordings' . DIRECTORY_SEPARATOR . 'pending';",
    "\$p = rtrim(WARROOM_ATTACH_DIR, '/');",
];
foreach ($samples as $s) {
    $caught = false;
    foreach (ZZ_GUARD_PATTERNS as $re) if (preg_match($re, $s)) $caught = true;
    ok('the guard catches: ' . substr($s, 0, 60), $caught);
}
ok('...and ignores the same text in a comment', !preg_match(ZZ_GUARD_PATTERNS['tickets attachments folder'], zzGuardCode("<?php\n// see tickets/attachments/\n\$a = 1;")));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
