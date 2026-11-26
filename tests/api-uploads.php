<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Files sent to the REST API in pieces, and their hand-over (GH #416, #417).
 *
 * Over HTTP with real API keys, against the install FREEITSM_TEST_URL serves - the design's
 * test list (wiki API-Uploads-and-Knowledge-Documents-Design, section 8):
 *   - a 30 MB document in 4 MiB pieces assembles byte for byte; a piece at the wrong offset
 *     is 409 with `received`; what a dropped piece left behind is discarded and the upload
 *     resumes; a piece over chunk_bytes is 413; a wrong sha256 is refused at the end;
 *   - a purpose's size and extension are refused at the start, the content's type again at
 *     the hand-over (a PHP file renamed .png, a text file named .pdf; never SVG), and the
 *     size against the limit in force at the hand-over;
 *   - another key - even one acting as the same analyst - cannot read, send to, hand over
 *     or delete someone else's upload; a used upload cannot be handed over twice, or to
 *     another article; a retry of the hand-over returns the same record;
 *   - pieces are never served over HTTP; ten uploads per key that are not handed over -
 *     finished or not - and no more; an expired upload and an orphaned piece file are gone;
 *   - #416's acceptance through pieces: a restricted draft gets a file via an upload; it is
 *     in the native Documents panel, the article's revision moves, a retry makes nothing new;
 *   - run the server with PHP's defaults (upload_max_filesize=2M, post_max_size=8M): the
 *     30 MB document goes up anyway, while a 3 MB multipart upload is refused (CONTROL).
 *
 * Writes ZZ analysts, keys, articles, documents, pictures and uploads; restores the two
 * size settings it changes and removes everything by the ids it made, in `finally`.
 *
 * Run: FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/api-uploads.php
 */
chdir(__DIR__ . '/..');
$pass = 0;
$fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n";
    $ok ? $pass++ : $fail++;
}

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/services/api_uploads.php';
require_once 'includes/services/knowledge_documents.php';
require_once 'includes/services/knowledge_images.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(1); }
try { $conn->query('SELECT 1 FROM api_uploads LIMIT 0'); }
catch (Throwable $e) { echo "(skipped: run Database Verification - api_uploads is missing)\n"; exit(1); }

$origin = preg_replace('~^(https?://[^/]+).*$~', '$1', $base);
$appPath = rtrim(parse_url($base, PHP_URL_PATH) ?: '', '/');
$prefix = 'zz_up_' . bin2hex(random_bytes(4));
$made = ['analysts' => [], 'keys' => [], 'articles' => [], 'tmp' => []];
$settings = ['attachment_max_mb', 'knowledge_image_max_mb'];
$oldSettings = [];
foreach ($settings as $k) {
    $st = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $st->execute([$k]);
    $oldSettings[$k] = $st->fetchColumn();
}
$set = function (string $k, ?string $v) use ($conn): void {
    $conn->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([$k]);
    if ($v !== null && $v !== false) $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$k, $v]);
};

/**
 * One API call: $json as JSON, $raw as a raw body (a piece), $file = [path, name, mime] multipart.
 * Returns [status, decoded JSON, body].
 */
$call = function (string $key, string $method, string $path, ?array $json = null, ?string $raw = null, string $query = '', ?array $file = null, array $headers = []) use ($base): array {
    $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($path) . ($query !== '' ? "&$query" : ''));
    $h = array_merge(['Authorization: Bearer ' . $key], $headers);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CUSTOMREQUEST => $method];
    if ($raw !== null) {
        $h[] = 'Content-Type: application/octet-stream';
        $o[CURLOPT_POSTFIELDS] = $raw;
    } elseif ($file !== null) {
        $o[CURLOPT_POSTFIELDS] = ($json ?? []) + ['file' => new CURLFile($file[0], $file[2], $file[1])];
    } elseif ($json !== null) {
        $h[] = 'Content-Type: application/json';
        $o[CURLOPT_POSTFIELDS] = json_encode($json);
    }
    $o[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $o);
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($body, true), $body];
};
$mkKey = function (int $analystId, array $perms) use ($conn, $prefix, &$made): array {
    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, rate_limit_per_minute, active, created_datetime) VALUES (?, ?, ?, ?, ?, 5000, 1, UTC_TIMESTAMP())')
         ->execute([$prefix, substr($key, 0, 12), hash('sha256', $key), $analystId, json_encode($perms)]);
    $made['keys'][] = (int)$conn->lastInsertId();
    return [$key, (int)$conn->lastInsertId()];
};
$mkFile = function (string $bytes) use (&$made): string {
    $path = tempnam(sys_get_temp_dir(), 'zzup');
    file_put_contents($path, $bytes);
    $made['tmp'][] = $path;
    return $path;
};
$row = function (string $uploadId) use ($conn): ?array {
    $st = $conn->prepare('SELECT * FROM api_uploads WHERE upload_id = ?');
    $st->execute([$uploadId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
/** Send a whole file in pieces of $piece bytes from where the upload stands (as a client resumes); returns the last reply. */
$sendAll = function (string $key, string $uploadId, string $bytes, int $piece) use ($call): array {
    $last = $call($key, 'GET', "/uploads/$uploadId");
    if ($last[0] !== 200) return $last;
    $offset = $last[1]['data']['received'];
    while ($offset < strlen($bytes)) {
        $last = $call($key, 'PUT', "/uploads/$uploadId", null, substr($bytes, $offset, $piece), "offset=$offset");
        if ($last[0] !== 200) return $last;
        $offset = $last[1]['data']['received'];
    }
    return $last;
};
$count = fn(string $sql, array $args = []) => (function () use ($conn, $sql, $args) { $st = $conn->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); })();

try {
    // ── Set up ──────────────────────────────────────────────────────────────
    $set('attachment_max_mb', '40');
    $set('knowledge_image_max_mb', null);   // the 10 MiB default
    $conn->prepare('INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules) VALUES (?, ?, ?, ?, 1, 1, 1, 1)')
         ->execute([$prefix, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'ZZ UP importer', $prefix . '@example.test']);
    $actor = $made['analysts'][] = (int)$conn->lastInsertId();
    $perms = ['knowledge' => ['read', 'create', 'update', 'delete'], 'knowledge_access' => ['read', 'manage'], 'knowledge_imports' => ['manage'],
              'knowledge_documents' => ['read', 'create', 'delete'], 'knowledge_images' => ['read', 'create', 'delete'], 'documents' => ['download']];
    [$key, $keyId] = $mkKey($actor, $perms);
    [$key2] = $mkKey($actor, $perms);                                   // another key, the same analyst
    [$readKey] = $mkKey($actor, ['knowledge_documents' => ['read'], 'knowledge_images' => ['read']]);

    $access = ['is_restricted' => true, 'inherits' => false, 'entries' => [['principal_type' => 'analyst', 'principal_id' => $actor]]];
    [$c, $j] = $call($key, 'POST', '/knowledge/articles', ['title' => 'ZZ UP restricted draft', 'body_html' => '<p>ZZ</p>',
        'audience' => 'internal', 'tenant_id' => null, 'access' => $access, 'is_published' => false, 'processing' => 'suppress']);
    $a = (int)($j['data']['id'] ?? 0);
    if ($a) $made['articles'][] = $a;
    [$c2, $j] = $call($key, 'POST', '/knowledge/articles', ['title' => 'ZZ UP second article', 'body_html' => '<p>ZZ</p>', 'is_published' => true, 'processing' => 'suppress']);
    $b = (int)($j['data']['id'] ?? 0);
    if ($b) $made['articles'][] = $b;
    if ($c !== 201 || $c2 !== 201) throw new RuntimeException("articles: $c $c2");

    // ── Limits ──────────────────────────────────────────────────────────────
    [$c, $j] = $call($key, 'GET', '/uploads/limits');
    $limits = $j['data'] ?? [];
    check('GET /uploads/limits: chunk_bytes 4 MiB, 10 per key, 24 hours',
        $c === 200 && ($limits['chunk_bytes'] ?? 0) === 4194304 && ($limits['max_unfinished'] ?? 0) === 10 && ($limits['expires_after_hours'] ?? 0) === 24, json_encode($j));
    check('...each purpose\'s limit as configured now (documents 40 MB here, pictures 10 MiB), with formats',
        ($limits['purposes']['knowledge_document']['max_bytes'] ?? 0) === 40 * 1048576
        && ($limits['purposes']['knowledge_image']['max_bytes'] ?? 0) === 10485760
        && in_array('png', array_column($limits['purposes']['knowledge_image']['formats'] ?? [], 'extension'), true)
        && !in_array('svg', array_column($limits['purposes']['knowledge_image']['formats'] ?? [], 'extension'), true));

    // ── Refused at the start ────────────────────────────────────────────────
    foreach ([
        [['purpose' => 'ticket_attachment', 'filename' => 'a.txt', 'size' => 10], 422, 'invalid_purpose'],
        [['purpose' => 'knowledge_document', 'filename' => 'shell.php', 'size' => 10], 422, 'invalid_type'],
        [['purpose' => 'knowledge_image', 'filename' => 'logo.svg', 'size' => 10], 422, 'invalid_type'],
        [['purpose' => 'knowledge_document', 'filename' => 'big.txt', 'size' => 40 * 1048576 + 1], 413, 'too_large'],
        [['purpose' => 'knowledge_document', 'filename' => 'zero.txt', 'size' => 0], 422, 'invalid_field'],
        [['purpose' => 'knowledge_document', 'filename' => 'a.txt', 'size' => 10, 'sha256' => 'nope'], 422, 'invalid_field'],
        [['purpose' => 'knowledge_document', 'filename' => 'a.txt', 'size' => 10, 'extra' => 1], 422, 'unknown_field'],
    ] as [$in, $status, $code]) {
        [$c, $j] = $call($key, 'POST', '/uploads', $in);
        check("start refused: $code (" . ($in['filename'] ?? '') . ')', $c === $status && ($j['error']['code'] ?? '') === $code, "$c " . json_encode($j));
    }
    [$c] = $call($readKey, 'POST', '/uploads', ['purpose' => 'knowledge_document', 'filename' => 'a.txt', 'size' => 10]);
    check('a key without the purpose\'s create permission cannot start one (403)', $c === 403);
    check('...and nothing was created by any of those', $count('SELECT COUNT(*) FROM api_uploads WHERE api_key_id = ?', [$keyId]) === 0);

    // ── A 30 MB document in pieces, on a server with PHP's default limits ──
    $line = str_repeat('ZZ upload line - ', 4) . "\n";
    $big = '';
    while (strlen($big) < 30 * 1048576) $big .= bin2hex(random_bytes(31)) . ' ' . $line;
    $big = substr($big, 0, 30 * 1048576);
    $bigSha = hash('sha256', $big);
    [$c, $j] = $call($key, 'POST', '/uploads', ['purpose' => 'knowledge_document', 'filename' => 'Network runbook.txt', 'size' => strlen($big), 'sha256' => $bigSha]);
    $up = $j['data']['upload_id'] ?? '';
    check('POST /uploads is 201 with a random upload_id and chunk_bytes', $c === 201 && preg_match('/^u_[0-9a-f]{32}$/', $up) && ($j['data']['received'] ?? -1) === 0
        && ($j['data']['chunk_bytes'] ?? 0) === 4194304 && ($j['data']['complete'] ?? true) === false, "$c " . json_encode($j));
    $chunk = 4194304;
    [$c, $j] = $call($key, 'PUT', "/uploads/$up", null, substr($big, 0, $chunk), 'offset=0');
    check('the first piece is taken', $c === 200 && ($j['data']['received'] ?? 0) === $chunk, "$c " . json_encode($j));
    [$c, $j] = $call($key, 'PUT', "/uploads/$up", null, substr($big, 0, $chunk), 'offset=0');
    check('the same piece again is 409 offset_mismatch with received - never written twice',
        $c === 409 && ($j['error']['code'] ?? '') === 'offset_mismatch' && ($j['error']['details']['received'] ?? 0) === $chunk
        && (int)$row($up)['received_bytes'] === $chunk, "$c " . json_encode($j));
    [$c, $j] = $call($key, 'PUT', "/uploads/$up", null, substr($big, $chunk, $chunk + 1), "offset=$chunk");
    check('a piece over chunk_bytes is 413 piece_too_large, and nothing of it is kept', $c === 413 && ($j['error']['code'] ?? '') === 'piece_too_large'
        && (int)$row($up)['received_bytes'] === $chunk, "$c " . json_encode($j));
    // A "dropped connection": bytes of a cut-off piece left beyond what was recorded.
    $part = ApiUploadService::path($row($up));
    file_put_contents($part, 'GARBAGE FROM A DROPPED PIECE', FILE_APPEND);
    [$c, $j] = $call($key, 'GET', "/uploads/$up");
    check('after a dropped piece GET says where to carry on', $c === 200 && ($j['data']['received'] ?? 0) === $chunk && ($j['data']['complete'] ?? true) === false);
    $j = $sendAll($key, $up, $big, $chunk)[1];
    check('the rest goes up in pieces: complete, the sha256 matches', ($j['data']['complete'] ?? false) === true && ($j['data']['received'] ?? 0) === strlen($big)
        && ($j['data']['sha256'] ?? '') === $bigSha, json_encode($j));
    check('...the assembled file is byte for byte the original (the dropped bytes were cut off)', hash_file('sha256', $part) === $bigSha);
    [$c, $j] = $call($key, 'PUT', "/uploads/$up", null, 'x', 'offset=' . strlen($big));
    check('a piece after the last is 409 upload_complete', $c === 409 && ($j['error']['code'] ?? '') === 'upload_complete');
    [$c, $raw] = (function () use ($origin, $appPath, $part) {
        $ch = curl_init($origin . $appPath . '/uploads/api-incoming/' . basename($part));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $r = (string)curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $r];
    })();
    check('the pieces are never served over HTTP', $c !== 200 && !str_contains($raw, 'ZZ upload line'), (string)$c);

    // Another key, the same analyst: none of it.
    [$c1] = $call($key2, 'GET', "/uploads/$up");
    [$c2] = $call($key2, 'PUT', "/uploads/$up", null, 'x', 'offset=' . strlen($big));
    [$c3] = $call($key2, 'POST', "/knowledge/articles/$a/documents", ['upload_id' => $up]);
    [$c4] = $call($key2, 'DELETE', "/uploads/$up");
    check('another key - even acting as the same analyst - cannot read, send to, hand over or delete it (404)', [$c1, $c2, $c3, $c4] === [404, 404, 404, 404], json_encode([$c1, $c2, $c3, $c4]));

    // CONTROL: PHP's own limits are the defaults here - a 3 MB multipart upload cannot get in.
    $three = $mkFile(substr($big, 0, 3 * 1048576));
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$b/documents", ['title' => 'ZZ UP multipart'], null, '', [$three, 'three.txt', 'text/plain']);
    check('CONTROL: on this server a 3 MB multipart upload is refused (upload_max_filesize = 2M)', $c === 422, "$c " . json_encode($j));

    // ── Hand-over to a restricted draft (#416 through pieces) ──────────────
    $rev0 = KnowledgeTransferService::revision($conn, 'article', $a);
    $docsBefore = $count('SELECT COUNT(*) FROM documents');
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents", ['upload_id' => $up, 'title' => 'ZZ UP runbook', 'client_reference' => "$prefix:doc"]);
    $doc = (int)($j['data']['id'] ?? 0);
    check('the upload is handed to the draft as a document (201), hidden from the Help Centre', $c === 201 && $doc > 0 && ($j['data']['size_bytes'] ?? 0) === strlen($big)
        && ($j['data']['sha256'] ?? '') === $bigSha && ($j['data']['filename'] ?? '') === 'Network runbook.txt' && ($j['data']['show_in_help_centre'] ?? true) === false, "$c " . json_encode($j));
    check('...the article\'s revision moved', KnowledgeTransferService::revision($conn, 'article', $a) !== $rev0);
    $panel = DocumentsService::listFor($conn, $actor, null, 'knowledge_article', $a);
    check('...it is in the native Documents panel', in_array($doc, array_map(fn($r) => (int)$r['id'], $panel['rows']), true));
    check('...the pieces are gone; the upload is kept as the receipt of what it became', !is_file($part) && (int)($row($up)['used_object_id'] ?? 0) === $doc);
    [$c, $j] = $call($key, 'GET', "/uploads/$up");
    check('GET /uploads/{id} says what it became', $c === 200 && ($j['data']['used']['parent_id'] ?? 0) === $a && ($j['data']['used']['object_id'] ?? 0) === $doc, json_encode($j));
    $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode("/knowledge/articles/$a/documents/$doc/content"));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
    $downloaded = (string)curl_exec($ch);
    check('the 30 MB document downloads byte for byte', (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && hash('sha256', $downloaded) === $bigSha);
    unset($downloaded);

    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents", ['upload_id' => $up, 'title' => 'ZZ UP runbook', 'client_reference' => "$prefix:doc"]);
    check('a retry of the hand-over returns the same document (200, replayed), nothing new',
        $c === 200 && ($j['data']['id'] ?? 0) === $doc && ($j['data']['replayed'] ?? false) === true && $count('SELECT COUNT(*) FROM documents') === $docsBefore + 1, "$c " . json_encode($j));
    foreach ([
        ['/knowledge/articles/' . $a . '/documents', ['upload_id' => $up, 'title' => 'ZZ UP runbook', 'client_reference' => "$prefix:other"], 'another client_reference'],
        ['/knowledge/articles/' . $a . '/documents', ['upload_id' => $up, 'title' => 'ZZ UP runbook'], 'no client_reference'],
        ['/knowledge/articles/' . $b . '/documents', ['upload_id' => $up, 'title' => 'ZZ UP runbook', 'client_reference' => "$prefix:doc"], 'the same reference, another article'],
    ] as [$path, $in, $what]) {
        [$c, $j] = $call($key, 'POST', $path, $in);
        check("a used upload cannot be handed over again: $what (409 upload_used)", $c === 409 && ($j['error']['code'] ?? '') === 'upload_used', "$c " . json_encode($j));
    }
    check('...and none of those made a document', $count('SELECT COUNT(*) FROM documents') === $docsBefore + 1);
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/images", ['upload_id' => $up]);
    check('a document upload is not a picture (422 upload_purpose)', $c === 422 && ($j['error']['code'] ?? '') === 'upload_purpose', "$c " . json_encode($j));

    // ── The content is checked at the hand-over ─────────────────────────────
    $php = "<?php echo 'owned';\n";
    [$c, $j] = $call($key, 'POST', '/uploads', ['purpose' => 'knowledge_image', 'filename' => 'shell.png', 'size' => strlen($php)]);
    $fakePng = $j['data']['upload_id'] ?? '';
    $sendAll($key, $fakePng, $php, $chunk);
    $imagesBefore = $count('SELECT COUNT(*) FROM knowledge_images');
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/images", ['upload_id' => $fakePng]);
    check('a PHP file named .png is refused at the hand-over (422 invalid_image), nothing stored', $c === 422 && ($j['error']['code'] ?? '') === 'invalid_image'
        && $count('SELECT COUNT(*) FROM knowledge_images') === $imagesBefore, "$c " . json_encode($j));
    $text = "Not a PDF at all\n";
    [$c, $j] = $call($key, 'POST', '/uploads', ['purpose' => 'knowledge_document', 'filename' => 'fake.pdf', 'size' => strlen($text)]);
    $fakePdf = $j['data']['upload_id'] ?? '';
    $sendAll($key, $fakePdf, $text, $chunk);
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents", ['upload_id' => $fakePdf]);
    check('a text file named .pdf is refused at the hand-over (422 invalid_file)', $c === 422 && ($j['error']['code'] ?? '') === 'invalid_file', "$c " . json_encode($j));
    $pdfRow = $row($fakePdf);
    check('...and stays unused, so it can be deleted or expire', $pdfRow !== null && $pdfRow['used_datetime'] === null);

    // A wrong sha256 is refused at the last piece, and the upload goes.
    [$c, $j] = $call($key, 'POST', '/uploads', ['purpose' => 'knowledge_document', 'filename' => 'wrong.txt', 'size' => strlen($text), 'sha256' => str_repeat('a', 64)]);
    $wrong = $j['data']['upload_id'] ?? '';
    [$c, $j] = $call($key, 'PUT', "/uploads/$wrong", null, $text, 'offset=0');
    check('a file that does not match its sha256 is refused at the end (422 sha256_mismatch)', $c === 422 && ($j['error']['code'] ?? '') === 'sha256_mismatch', "$c " . json_encode($j));
    check('...and is discarded', $row($wrong) === null && $call($key, 'GET', "/uploads/$wrong")[0] === 404);

    // ── A picture in pieces; the limit in force at the hand-over ────────────
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII=');
    [$c, $j] = $call($key, 'POST', '/uploads', ['purpose' => 'knowledge_image', 'filename' => 'diagram.png', 'size' => strlen($png), 'sha256' => hash('sha256', $png)]);
    $pic = $j['data']['upload_id'] ?? '';
    // Sent with the method override, for clients that can only POST.
    [$c, $j] = $call($key, 'POST', "/uploads/$pic", null, $png, 'offset=0', null, ['X-HTTP-Method-Override: PUT']);
    check('a piece also arrives as POST with X-HTTP-Method-Override: PUT', $c === 200 && ($j['data']['complete'] ?? false) === true, "$c " . json_encode($j));
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/images", ['upload_id' => $pic, 'client_reference' => "$prefix:img"]);
    $img = (int)($j['data']['id'] ?? 0);
    check('a picture handed over from an upload (201)', $c === 201 && $img > 0 && ($j['data']['sha256'] ?? '') === hash('sha256', $png), "$c " . json_encode($j));
    [$c, , $raw] = $call($key, 'GET', "/knowledge/images/$img/content");
    check('...its bytes come back unchanged', $c === 200 && $raw === $png);
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/images", ['upload_id' => $pic, 'client_reference' => "$prefix:img"]);
    check('...and its retry is the same picture (200)', $c === 200 && ($j['data']['id'] ?? 0) === $img, "$c " . json_encode($j));
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/images", ['upload_id' => $pic, 'content_base64' => base64_encode($png)]);
    check('upload_id and content_base64 together are refused', $c === 422);

    $bigPng = "\x89PNG\r\n\x1a\n" . random_bytes(6 * 1048576);
    [$c, $j] = $call($key, 'POST', '/uploads', ['purpose' => 'knowledge_image', 'filename' => 'photo.png', 'size' => strlen($bigPng)]);
    $bigPic = $j['data']['upload_id'] ?? '';
    $sendAll($key, $bigPic, $bigPng, $chunk);
    $set('knowledge_image_max_mb', '5');   // an administrator lowers the limit meanwhile
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/images", ['upload_id' => $bigPic]);
    check('the size is checked against the limit in force at the hand-over (413 image_too_large)', $c === 413 && ($j['error']['code'] ?? '') === 'image_too_large', "$c " . json_encode($j));
    $set('knowledge_image_max_mb', null);

    // ── Ten per key, finished or not; DELETE frees one ──────────────────────
    $open = $count('SELECT COUNT(*) FROM api_uploads WHERE api_key_id = ? AND used_datetime IS NULL', [$keyId]);   // fakePng, fakePdf, bigPic
    $ids = [];
    for ($i = $open; $i < ApiUploadService::MAX_UNFINISHED; $i++) {
        [$c, $j] = $call($key, 'POST', '/uploads', ['purpose' => 'knowledge_document', 'filename' => "n$i.txt", 'size' => 100]);
        $ids[] = $j['data']['upload_id'] ?? '';
    }
    [$c, $j] = $call($key, 'POST', '/uploads', ['purpose' => 'knowledge_document', 'filename' => 'one-too-many.txt', 'size' => 100]);
    check('the eleventh upload not handed over is 409 too_many_uploads (finished ones waiting count)', $open === 3 && $c === 409 && ($j['error']['code'] ?? '') === 'too_many_uploads', "$open $c " . json_encode($j));
    [$c2] = $call($key2, 'POST', '/uploads', ['purpose' => 'knowledge_document', 'filename' => 'other-key.txt', 'size' => 100]);
    check('...the limit is per key', $c2 === 201);
    $doomed = $row($ids[0]);
    [$c, $j] = $call($key, 'DELETE', '/uploads/' . $ids[0]);
    check('DELETE throws an upload away', $c === 200 && ($j['data']['deleted'] ?? false) === true && $row($ids[0]) === null && !is_file(ApiUploadService::path($doomed)));
    [$c] = $call($key, 'POST', '/uploads', ['purpose' => 'knowledge_document', 'filename' => 'room-again.txt', 'size' => 100]);
    check('...and frees its place', $c === 201);

    // ── Expiry ──────────────────────────────────────────────────────────────
    $old = $row($ids[1]);
    $conn->prepare('UPDATE api_uploads SET expires_datetime = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$old['id']]);
    $orphan = ApiUploadService::path(['stored_name' => bin2hex(random_bytes(16)) . '.part']);
    file_put_contents($orphan, 'ZZ orphan');
    touch($orphan, time() - 3 * 86400);
    [$c] = $call($key, 'GET', '/uploads/' . $ids[1]);
    check('an expired upload is 404 at once', $c === 404);
    [$c] = $call($key2, 'POST', '/uploads', ['purpose' => 'knowledge_document', 'filename' => 'sweep.txt', 'size' => 100]);
    check('the next start deletes expired uploads, their pieces, and a piece file no upload names',
        $c === 201 && $row($ids[1]) === null && !is_file(ApiUploadService::path($old)) && !is_file($orphan), (string)$c);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    foreach ($settings as $k) $set($k, $oldSettings[$k] === false ? null : $oldSettings[$k]);
    require_once 'includes/search/documents_index.php';
    if ($made['articles']) {
        $marks = implode(',', array_fill(0, count($made['articles']), '?'));
        $st = $conn->prepare("SELECT DISTINCT document_id FROM document_links WHERE parent_type = 'knowledge_article' AND parent_id IN ($marks)");
        $st->execute($made['articles']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $d = (int)$d;
            $k = $conn->query('SELECT storage_key FROM documents WHERE id = ' . $d)->fetchColumn();
            if ($k) @unlink(documentStoragePath((string)$k));
            foreach (['document_access_log', 'document_links'] as $t) $conn->prepare("DELETE FROM $t WHERE document_id = ?")->execute([$d]);
            try { $conn->prepare('DELETE FROM document_text WHERE document_id = ?')->execute([$d]); } catch (Throwable) {}
            try { searchUnindexDocument($conn, $d); } catch (Throwable) {}
            $conn->prepare('DELETE FROM documents WHERE id = ?')->execute([$d]);
        }
        $st = $conn->prepare("SELECT stored_name FROM knowledge_images WHERE article_id IN ($marks)");
        $st->execute($made['articles']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) @unlink(KnowledgeImageService::directory() . '/' . $n);
        foreach ($made['articles'] as $id) {
            foreach (['knowledge_images', 'knowledge_article_tags', 'knowledge_article_versions'] as $t) $conn->prepare("DELETE FROM $t WHERE article_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM knowledge_acl WHERE object_type = 'article' AND object_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM knowledge_audit WHERE object_type = 'article' AND object_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM search_documents WHERE source_type = 'kb_article' AND source_id = ?")->execute([$id]);
            $conn->prepare('DELETE FROM knowledge_articles WHERE id = ?')->execute([$id]);
        }
    }
    foreach ($made['keys'] as $k) {
        $st = $conn->prepare('SELECT stored_name FROM api_uploads WHERE api_key_id = ?');
        $st->execute([$k]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) @unlink(ApiUploadService::path(['stored_name' => $n]));
        $conn->prepare('DELETE FROM api_uploads WHERE api_key_id = ?')->execute([$k]);
        foreach (['api_key_rate_limits', 'api_key_events', 'api_request_log'] as $t) {
            try { $conn->prepare("DELETE FROM $t WHERE api_key_id = ?")->execute([$k]); } catch (Throwable) {}
        }
        $conn->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$k]);
    }
    foreach ($made['analysts'] as $id) {
        $conn->prepare('DELETE FROM knowledge_api_references WHERE analyst_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM document_access_log WHERE analyst_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM analysts WHERE id = ?')->execute([$id]);
    }
    foreach ($made['tmp'] as $f) if (is_file($f)) @unlink($f);
    $left = $count("SELECT (SELECT COUNT(*) FROM knowledge_articles WHERE title LIKE 'ZZ UP%') + (SELECT COUNT(*) FROM api_keys WHERE name = ?)", [$prefix]);
    check('cleanup: nothing this run made is left, the settings are as they were', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
