<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// No database is needed to prove URL validation and the invisible-upgrade default.
$file = __DIR__ . '/../includes/ticket_workspace.php';
if (is_file($file)) require $file;
if (!function_exists('ticketWorkspaceResolve')) {
    fwrite(STDERR, "FAIL: ticket layout preference resolution is not implemented.\n");
    exit(1);
}
$cases = [
    [null, null, 'inbox'], ['invalid', null, 'inbox'],
    ['workspace', null, 'workspace'], ['inbox', 'workspace', 'workspace'],
    ['workspace', 'inbox', 'inbox'], ['workspace', 'bad', 'workspace'],
    ['inbox', '<script>', 'inbox'],
];
$fail = 0;
foreach ($cases as [$saved, $requested, $expected]) {
    $actual = ticketWorkspaceResolve($saved, $requested);
    if ($actual !== $expected) $fail++;
}
echo (count($cases) - $fail) . " layout resolutions passed, $fail failed\n";
exit($fail ? 1 : 0);
