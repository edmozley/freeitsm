<?php
/* 🔴 NEVER OVER THE WEB. Only PHP's built-in server runs this, started by a test:
 *      php -S 127.0.0.1:<port> tests/support/fake-embedding-server.php
 * Apache answers 404. */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

/**
 * A fake OpenAI-compatible embedding server for tests/help-vectors.php - so the
 * help assistant's vectors (building, switching model, falling back) are tested
 * without paying for, or depending on, a real provider.
 *
 *   GET  /v1/models       two models: fake-embed and fake-embed-b
 *   POST /v1/embeddings   a deterministic 64-number vector per input: each word
 *                         adds to the slots its hash picks, so texts sharing words
 *                         point the same way. fake-embed-b hashes differently - its
 *                         vectors mean nothing to fake-embed's, like two real models.
 *   Model "fake-broken"   answers HTTP 500, to test the fallback to words.
 *   Each request is counted in a file the test reads (FAKE_EMBED_LOG).
 */
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$log = getenv('FAKE_EMBED_LOG');

if (str_ends_with($path, '/models')) {
    echo json_encode(['data' => [['id' => 'fake-embed'], ['id' => 'fake-embed-b']]]);
    return true;
}
if (!str_ends_with($path, '/embeddings')) { http_response_code(404); echo '{"error":"not found"}'; return true; }

$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$model = (string)($in['model'] ?? '');
if ($log) file_put_contents($log, json_encode(['model' => $model, 'n' => count((array)$in['input'])]) . "\n", FILE_APPEND);
if ($model === 'fake-broken') { http_response_code(500); echo json_encode(['error' => ['message' => 'the fake server was told to fail']]); return true; }

$texts = is_array($in['input'] ?? null) ? $in['input'] : [(string)($in['input'] ?? '')];
$data = [];
foreach ($texts as $i => $t) {
    $v = array_fill(0, 64, 0.0);
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)$t), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (mb_strlen($w) < 3) continue;
        $h = crc32(($model === 'fake-embed-b' ? 'b:' : '') . $w);
        $v[$h % 64] += 1.0;
        $v[($h >> 8) % 64] += 0.5;
    }
    $data[] = ['index' => $i, 'embedding' => $v];
}
echo json_encode(['data' => $data, 'model' => $model]);
return true;
