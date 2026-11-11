<?php
/**
 * Build the help assistant's index (help_sections) from the help pages.
 *
 *   php scripts/help_index_build.php                 English plus the languages analysts use
 *   php scripts/help_index_build.php --locales=de,es English plus German and Spanish
 *
 * System -> Help assistant -> Rebuild runs this in the background (with a job id
 * of 0, which means the default languages) and shows its progress from the
 * help_index_build_state setting it writes.
 *
 * Run Database Verify first (it creates help_sections). Safe to run any time: an
 * unchanged section is left alone, a changed one updated, a removed one deleted.
 * See includes/help_index.php and wiki Help-Assistant-Design.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/help_index.php';
require_once 'includes/help_assistant.php';

$locales = null;
// TRAP: started from System -> Help assistant (job id 0), this runs in the
// background with its output already closed - intuneSpawnGenericWorker() pclose()s
// the pipe at once. On Windows the first write to it kills the process, so the
// build died after one page. In the background it prints NOTHING; progress goes
// to the help_index_build_state setting instead.
$background = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--locales=')) $locales = array_filter(array_map('trim', explode(',', substr($a, 10))));
    if ($a === '0') $background = true;
}
$say = $background ? fn(string $s) => null : fn(string $s) => print($s);
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$locales ??= helpIndexLocalesInUse($conn);
$started = gmdate('Y-m-d H:i:s');
helpAssistantSetSetting($conn, 'help_index_build_state', json_encode(['state' => 'running', 'started' => $started, 'locales' => $locales]));
$t = microtime(true);
try {
    $stats = helpIndexBuild($conn, $locales, fn(string $line) => $say("  $line\n"));
} catch (Throwable $e) {
    helpAssistantSetSetting($conn, 'help_index_build_state', json_encode(['state' => 'failed', 'started' => $started, 'finished' => gmdate('Y-m-d H:i:s'), 'error' => $e->getMessage()]));
    $say($e->getMessage() . "\n");
    exit(1);
}
helpAssistantSetSetting($conn, 'help_index_build_state', json_encode(['state' => $stats['failed'] ? 'failed' : 'done', 'started' => $started,
    'finished' => gmdate('Y-m-d H:i:s'), 'locales' => array_values(array_unique(array_merge(['en'], $locales))), 'stats' => $stats]));
$say(sprintf("\n%d pages, %d sections (English): %d added, %d changed, %d removed, in %.1fs\n",
    $stats['pages'], $stats['sections'], $stats['added'], $stats['changed'], $stats['removed'], microtime(true) - $t));
if ($stats['failed']) { $say("FAILED to render: " . implode(', ', $stats['failed']) . "\n"); exit(1); }
