<?php
/**
 * RELEASE STEP: write the ready-made help vectors that ship with a release
 * (data/help-vectors/openai-text-embedding-3-small-512.json.gz).
 *
 *   php scripts/help_vectors_export.php 5.0.0
 *
 * Run on an install whose help is exactly the release's, with matching by meaning
 * set up for OpenAI text-embedding-3-small (System -> Help assistant): it builds the
 * index (English) and its vectors first, so nothing is missing. An install on the
 * same model then takes these instead of paying to embed the help itself; a
 * section it has changed is embedded there. See RELEASING.md and wiki
 * Help-Assistant-Developer-Guide.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/help_index.php';
require_once 'includes/help_vectors.php';

$version = $argv[1] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) { fwrite(STDERR, "usage: php scripts/help_vectors_export.php <version, e.g. 5.0.0>\n"); exit(2); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cfg = helpVectorsConfig($conn);
if (aiEmbeddingModelId($cfg) !== HELP_VECTORS_SHIPPED_ID || !helpVectorsUsable($cfg) || !helpVectorsEnabled($conn)) {
    fwrite(STDERR, "Set up matching by meaning for OpenAI text-embedding-3-small first (System -> Help assistant).\n");
    exit(1);
}
echo "Building the English index...\n";
$s = helpIndexBuild($conn, []);
if ($s['failed']) { fwrite(STDERR, "Pages failed to render: " . implode(', ', $s['failed']) . "\n"); exit(1); }
echo "Embedding...\n";
$v = helpVectorsBuild($conn, fn(string $l) => print("$l\n"));
if ($v['error'] !== null || $v['missing'] > 0) { fwrite(STDERR, "Vectors incomplete: " . ($v['error'] ?? $v['missing'] . ' missing') . "\n"); exit(1); }
$n = helpVectorsExportShipped($conn, $version);
printf("Wrote %d vectors for %s to %s (%s KB)\n", $n, $version, HELP_VECTORS_SHIPPED_FILE, number_format(filesize(HELP_VECTORS_SHIPPED_FILE) / 1024));
