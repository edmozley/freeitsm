<?php
/**
 * Build the help assistant's index (help_sections) from the help pages.
 *
 *   php scripts/help_index_build.php                 English
 *   php scripts/help_index_build.php --locales=de,es English plus German and Spanish
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

$locales = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--locales=')) $locales = array_filter(array_map('trim', explode(',', substr($a, 10))));
}
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$t = microtime(true);
$stats = helpIndexBuild($conn, $locales, fn(string $line) => print("  $line\n"));
printf("\n%d pages, %d sections (English): %d added, %d changed, %d removed, in %.1fs\n",
    $stats['pages'], $stats['sections'], $stats['added'], $stats['changed'], $stats['removed'], microtime(true) - $t);
if ($stats['failed']) { echo "FAILED to render: " . implode(', ', $stats['failed']) . "\n"; exit(1); }
