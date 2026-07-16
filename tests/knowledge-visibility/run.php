<?php
/**
 * Run every Knowledge visibility harness. Exit code 0 = all green.
 *
 *   php tests/knowledge-visibility/run.php
 *
 * See README.md in this folder — DEV INSTALLS ONLY.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$files = glob(__DIR__ . '/0*.php');
sort($files);
$failed = [];

foreach ($files as $f) {
    $name = basename($f);
    echo "\n=== $name " . str_repeat('=', max(0, 60 - strlen($name))) . "\n";
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    echo implode("\n", $out) . "\n";
    if ($code !== 0) $failed[] = $name;
}

echo "\n" . str_repeat('=', 64) . "\n";
if ($failed) {
    echo 'FAILED: ' . implode(', ', $failed) . "\n";
    exit(1);
}
echo "All Knowledge visibility harnesses passed.\n";
