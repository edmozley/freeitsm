<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The shared embedding code (includes/ai_embeddings.php) - step 1 of the help
 * assistant (wiki Help-Assistant-Design).
 *
 * - the vector comparison: same, opposite, unrelated, all zeros, unequal lengths
 * - the text an article is embedded as: title, then plain text
 * - the request body: a long text in a multi-byte script is cut on a character
 *   boundary and still encodes - with a control showing the old substr() cut
 *   produced nothing to send
 * - a guard: nothing else in the codebase calls the embeddings API or defines a
 *   vector comparison, so a fifth copy cannot creep back in
 *
 * No database, no network:  php tests/ai-embeddings.php
 *
 * @wiki Help-Assistant-Design
 */
chdir(__DIR__ . '/..');
require_once 'includes/ai_embeddings.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}
$near = fn(float $a, float $b) => abs($a - $b) < 1e-9;

echo "Comparing vectors\n";
check('the same direction scores 1', $near(aiCosineSimilarity([1, 2, 3], [2, 4, 6]), 1.0));
check('the opposite direction scores -1', $near(aiCosineSimilarity([1, 0], [-1, 0]), -1.0));
check('unrelated (at right angles) scores 0', $near(aiCosineSimilarity([1, 0], [0, 1]), 0.0));
check('an all-zero vector scores 0, not a division by zero', aiCosineSimilarity([0, 0], [1, 1]) === 0.0);
check('only the length both share is compared', $near(aiCosineSimilarity([1, 0, 99], [1, 0]), 1.0));

echo "\nThe text an article is embedded as\n";
check('title, a blank line, then the body as plain text',
    aiEmbeddingText('Reset a password', "<p>Open <b>People</b> &amp;\n\n  choose   <i>Reset</i>.</p>") === "Reset a password\n\nOpen People & choose Reset.");
$bad = "Body with a broken byte \xC3 in it";
check('a body with an invalid byte is kept, not dropped', str_contains(aiEmbeddingText('T', $bad), 'Body with a broken byte'));

echo "\nThe request body\n";
$long = str_repeat('é', 20000);                       // 40,000 bytes - over the limit
$body = aiEmbeddingRequestBody($long);
$sent = $body !== null ? (json_decode($body, true)['input'] ?? null) : null;
check('a long text in a multi-byte script still encodes', is_string($sent), var_export($body !== null, true));
check('...cut to the byte limit on a character boundary',
    is_string($sent) && strlen($sent) <= AI_EMBEDDING_MAX_BYTES && strlen($sent) > AI_EMBEDDING_MAX_BYTES - 4 && mb_check_encoding($sent, 'UTF-8'),
    is_string($sent) ? (string)strlen($sent) : '');
check('control: the old substr() cut split a character and gave json_encode() nothing to send',
    json_encode(['input' => substr(str_repeat('é', 20000), 0, 30001)]) === false);
check('the model is the one every stored vector was made with', str_contains((string)aiEmbeddingRequestBody('x'), '"model":"text-embedding-3-small"'));
check('a short text is sent whole', (json_decode((string)aiEmbeddingRequestBody('hello'), true)['input'] ?? '') === 'hello');

echo "\nOne copy, not five\n";
$others = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = str_replace('\\', '/', $f->getPathname());
    if (!str_ends_with($path, '.php') || preg_match('~^\./(tests|vendor|local|node_modules|lang)/~', $path) || $path === './includes/ai_embeddings.php') continue;
    $src = @file_get_contents($path);
    if ($src === false) continue;
    if (str_contains($src, 'v1/embeddings') || preg_match('/function\s+\w*cosine\w*\s*\(/i', $src)) $others[] = $path;
}
check('nothing else calls the embeddings API or defines a vector comparison', !$others, implode(', ', $others));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
