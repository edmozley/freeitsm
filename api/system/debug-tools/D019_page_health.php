<?php
/**
 * Debug Tool D019 — Page health: the list of pages to check.
 *
 * The check itself runs in the administrator's BROWSER (it has to: a server
 * cannot run JavaScript - which is exactly why `php -l` passed on the broken
 * Assets page in 4.3.0, GH #222). This endpoint only says what to check:
 * every page from includes/page_health.php, with the pages of switched-off
 * modules marked to skip (they redirect to the launcher by design), and the
 * last run's result.
 *
 * READ-ONLY. Lists files and reads one setting. JSON, not the usual plain text,
 * because a script reads it - the report the person copies is built in the page.
 * The runner is system/debug-tools/runners/page-health.php.
 */

@session_start();

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/page_health.php';
require_once __DIR__ . '/../../../includes/module_availability.php';
require_once __DIR__ . '/../../../includes/version.php';

header('Content-Type: application/json; charset=utf-8');

// Debug tools are administrators-only (issue #34). Fail closed.
try {
    $conn = connectToDatabase();
    $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin($conn, (int)$_SESSION['analyst_id']);
} catch (Throwable $e) {
    $__dbgAdmin = false;
}
if (!$__dbgAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Administrator access required.']);
    exit;
}

$off = [];
try { $off = disabledModules($conn); } catch (Throwable $e) { /* fails open: every module counts as on */ }

$pages = pageHealthPages(dirname(__DIR__, 3));
foreach ($pages as &$p) {
    if ($p['skip'] === null && in_array($p['module'], $off, true)) $p['skip'] = 'module switched off';
}
unset($p);

$last = null;
try {
    $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'page_health_last'");
    $s->execute();
    $v = $s->fetchColumn();
    if ($v !== false) $last = json_decode((string) $v, true);
} catch (Throwable $e) { /* no last run to show */ }

echo json_encode([
    'success' => true,
    'version' => freeitsmVersion(),
    'pages'   => $pages,
    'last'    => $last,
], JSON_UNESCAPED_SLASHES);
