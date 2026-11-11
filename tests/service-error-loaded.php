<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Every file that throws ServiceError loads its definition itself (GH #288).
 *
 * includes/changes/approvals.php threw ServiceError without loading
 * includes/service_context.php. It worked wherever the caller happened to have
 * loaded it first - and fataled ("Class ServiceError not found", HTTP 500) on the
 * one page that had not: a change's details with Enforce approvals on. 26 files
 * relied on a caller like that. A file that throws it must load it.
 *
 * No database, no server: php tests/service-error-loaded.php
 *
 * @wiki Code-Traps
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (!str_ends_with($path, '.php')) continue;
    $rel = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
    if (preg_match('#^(vendor|tests|engineering-wiki|local|node_modules)/#', $rel)) continue;
    if ($rel === 'includes/service_context.php') continue;
    $src = (string) file_get_contents($path);
    if (!str_contains($src, 'new ServiceError')) continue;
    if (str_contains($src, 'service_context.php')) { $pass++; continue; }
    $fail++;
    echo "  [FAIL] $rel throws ServiceError but never loads includes/service_context.php\n";
}
echo "$pass files that throw ServiceError load it, $fail do not\n";
echo ($fail ? $fail : 0) === 0 ? "1 passed, 0 failed\n" : "0 passed, 1 failed\n";
exit($fail ? 1 : 0);
