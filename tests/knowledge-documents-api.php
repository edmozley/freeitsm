<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Documents on Knowledge articles in REST v1 (GH #416).
 *
 * Over HTTP with real API keys and sessions, against the install FREEITSM_TEST_URL serves:
 *   - #416's acceptance: a restricted draft gets one file and one link through REST; both
 *     are in the native Documents panel, and one added there is in the REST list; a retried
 *     upload makes no duplicate; another company's key and a reader without access get 404;
 *     revoking access stops the download; detaching from one article leaves the document on
 *     another;
 *   - the Help Centre setting is hidden by default for a file, a link and an attached
 *     document, written with the link, and per article;
 *   - client_reference: same request = 200 and nothing written (no metadata, setting or
 *     revision reset), different request = 409, removed since = 404 and not attached again;
 *   - expected_revision stops a write on a stale revision; documents move the revision;
 *   - title and description of a document attached elsewhere are refused (document_shared);
 *   - content needs documents.download, is logged with the key and is article-specific;
 *   - the session url: analyst and portal user against THIS article, portal only when shown,
 *     nobody signed in gets nothing;
 *   - a key scoped to another company than its analyst's default reaches that company's
 *     article (the access is the key's, not the analyst's header switcher);
 *   - no AI: the article's embedding does not change.
 *
 * Writes ZZ analysts, keys, articles, documents, a company and a portal user; removes them
 * by the ids it created in `finally`.
 *
 * Run: FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/knowledge-documents-api.php
 */
chdir(__DIR__ . '/..');
if (($argv[1] ?? '') === '--analyst-rule') {
    // A fresh process (tenantCount() is cached per process): what the analyst's own rule -
    // documentCanWriteParent(), which DocumentsService::add() applies - says without a session.
    require_once 'config.php';
    require_once 'includes/functions.php';
    require_once 'includes/documents.php';
    $c = connectToDatabase();
    echo json_encode(['write' => documentCanWriteParent($c, (int)$argv[2], null, 'knowledge_article', (int)$argv[3]),
                      'see'   => documentCanView($c, (int)$argv[2], null, (int)$argv[4])]);
    exit;
}
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
require_once 'includes/tenancy.php';
require_once 'includes/services/knowledge_documents.php';
require_once 'includes/api_security.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(1); }
if (!documentHelpCentreReady($conn)) { echo "(skipped: run Database Verification)\n"; exit(1); }

$origin = preg_replace('~^(https?://[^/]+).*$~', '$1', $base);
$urlPrefix = rtrim(parse_url($base, PHP_URL_PATH) ?: '', '/') . '/api/knowledge/document.php?article_id=';
$prefix = 'zz_kd_' . bin2hex(random_bytes(4));
$made = ['analysts' => [], 'keys' => [], 'articles' => [], 'docs' => [], 'tenants' => [], 'users' => [], 'sessions' => [], 'tmp' => []];
$docDir = dirname(__DIR__) . '/uploads/' . DOCUMENT_STORAGE_DIR;
$filesOnDisk = fn() => is_dir($docDir) ? count(array_diff(scandir($docDir), ['.', '..', '.htaccess', 'index.html', 'web.config'])) : 0;

/** One API call. $file = [path, name, mime] for a multipart upload, with $json as its text fields. */
$call = function (string $key, string $method, string $path, ?array $json = null, ?array $file = null, string $query = '') use ($base): array {
    $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($path) . ($query !== '' ? "&$query" : ''));
    $h = ['Authorization: Bearer ' . $key];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true];
    if ($file !== null) {
        $o[CURLOPT_POSTFIELDS] = ($json ?? []) + ['file' => new CURLFile($file[0], $file[2], $file[1])];
    } elseif ($json !== null) {
        $h[] = 'Content-Type: application/json';
        $o[CURLOPT_POSTFIELDS] = json_encode($json);
    }
    $o[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $o);
    $raw = (string)curl_exec($ch);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($raw, $hs);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($body, true), $body, substr($raw, 0, $hs)];
};
/** A GET of a page URL, as somebody with this session (or nobody). */
$get = function (string $url, ?string $session = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    if ($session) curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . $session);
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $raw];
};
/** A session file, as the app would write it (session_name() and save_path from the same php.ini as the server). */
$session = function (array $values) use (&$made): string {
    $id = bin2hex(random_bytes(16));
    $contents = '';
    foreach ($values as $name => $value) $contents .= $name . '|' . serialize($value);
    $file = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/\\') . '/sess_' . $id;
    file_put_contents($file, $contents);
    $made['sessions'][] = $file;
    return $id;
};
$mkKey = function (int $analystId, array $perms, ?array $companies = null) use ($conn, $prefix, &$made): string {
    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, rate_limit_per_minute, active, created_datetime) VALUES (?, ?, ?, ?, ?, ?, 1000, 1, UTC_TIMESTAMP())')
         ->execute([$prefix, substr($key, 0, 12), hash('sha256', $key), $analystId, json_encode($perms), $companies === null ? null : json_encode($companies)]);
    $made['keys'][] = (int)$conn->lastInsertId();
    return $key;
};
$mkFile = function (string $bytes) use (&$made): string {
    $path = tempnam(sys_get_temp_dir(), 'zzkd');
    file_put_contents($path, $bytes);
    $made['tmp'][] = $path;
    return $path;
};
$revision = function (int $articleId) use ($conn): string {
    return KnowledgeTransferService::revision($conn, 'article', $articleId);
};
$link = function (int $documentId, int $articleId) use ($conn): ?array {
    $st = $conn->prepare("SELECT show_in_help_centre FROM document_links WHERE document_id = ? AND parent_type = 'knowledge_article' AND parent_id = ?");
    $st->execute([$documentId, $articleId]);
    $v = $st->fetchColumn();
    return $v === false ? null : ['shown' => (int)$v === 1];
};
$count = fn(string $sql, array $args = []) => (function () use ($conn, $sql, $args) { $st = $conn->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); })();

try {
    // ── Set up ──────────────────────────────────────────────────────────────
    $conn->prepare('INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules) VALUES (?, ?, ?, ?, 1, 1, 1, 1)')
         ->execute([$prefix, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'ZZ KD importer', $prefix . '@example.test']);
    $actor = $made['analysts'][] = (int)$conn->lastInsertId();
    $conn->prepare('INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules) VALUES (?, ?, ?, ?, 1, 0, 1, 1)')
         ->execute([$prefix . '_r', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'ZZ KD reader', $prefix . '_r@example.test']);
    $reader = $made['analysts'][] = (int)$conn->lastInsertId();

    $all = ['knowledge' => ['read', 'create', 'update', 'delete'], 'knowledge_access' => ['read', 'manage'], 'knowledge_imports' => ['manage'],
        'knowledge_documents' => ['read', 'create', 'delete'], 'documents' => ['download']];
    $key        = $mkKey($actor, $all);
    $keyId      = end($made['keys']);
    $noDownload = $mkKey($actor, ['knowledge_documents' => ['read']]);
    $readOnly   = $mkKey($actor, ['knowledge_documents' => ['read'], 'documents' => ['download']]);
    $readerKey  = $mkKey($reader, ['knowledge' => ['read'], 'knowledge_documents' => ['read', 'create', 'delete'], 'documents' => ['download']]);

    // A restricted draft, as a migration stages it: only the importer may read it.
    $access = ['is_restricted' => true, 'inherits' => false, 'entries' => [['principal_type' => 'analyst', 'principal_id' => $actor]]];
    [$c, $j] = $call($key, 'POST', '/knowledge/articles', ['title' => 'ZZ KD restricted draft', 'body_html' => '<p>ZZ</p>',
        'audience' => 'internal', 'tenant_id' => null, 'access' => $access, 'is_published' => false, 'processing' => 'suppress']);
    $a = (int)($j['data']['id'] ?? 0);
    if ($a) $made['articles'][] = $a;
    if ($c !== 201 || !$a) throw new RuntimeException("draft: $c " . json_encode($j));
    [$c, $j] = $call($key, 'POST', '/knowledge/articles', ['title' => 'ZZ KD second article', 'body_html' => '<p>ZZ</p>', 'audience' => 'internal', 'is_published' => true, 'processing' => 'suppress']);
    $b = (int)($j['data']['id'] ?? 0);
    if ($b) $made['articles'][] = $b;
    if ($c !== 201 || !$b) throw new RuntimeException("second: $c " . json_encode($j));
    $embedding = fn(int $id) => $conn->query('SELECT embedding FROM knowledge_articles WHERE id = ' . $id)->fetchColumn();
    $embeddingBefore = $embedding($a);

    // ── #416: one file and one link on a restricted draft ───────────────────
    $bytes = "ZZ KD runbook\n" . bin2hex(random_bytes(64));   // text: the upload checks the content against .txt
    $pdf = $mkFile($bytes);
    $rev0 = $revision($a);
    $filesBefore = $filesOnDisk();
    [$c, $j, $raw] = $call($key, 'POST', "/knowledge/articles/$a/documents",
        ['title' => 'ZZ KD runbook', 'client_reference' => "$prefix:file", 'expected_revision' => $rev0], [$pdf, 'runbook.txt', 'text/plain']);
    $fileDoc = (int)($j['data']['id'] ?? 0);
    if ($fileDoc) $made['docs'][] = $fileDoc;
    check('a file goes onto the restricted draft (201)', $c === 201 && $fileDoc > 0, "$c $raw");
    if (!$fileDoc) throw new RuntimeException('file upload failed');
    $d = $j['data'];
    check('...with its name, type, size, sha256 and a hidden Help Centre setting',
        $d['kind'] === 'file' && $d['filename'] === 'runbook.txt' && $d['size_bytes'] === strlen($bytes)
        && $d['sha256'] === hash('sha256', $bytes) && $d['show_in_help_centre'] === false && $d['client_reference'] === "$prefix:file"
        && $d['replayed'] === false, json_encode($d));
    check('...hidden in the same insert: the link row says 0', $link($fileDoc, $a) === ['shown' => false]);
    check('...a session url for the article\'s HTML, bound to THIS article (subfolder-aware)',
        $d['url'] === $urlPrefix . $a . '&id=' . $fileDoc, (string)$d['url']);
    check('...content_url and the article\'s new revision', $d['content_url'] === "/api/v1/knowledge/articles/$a/documents/$fileDoc/content"
        && is_string($d['article_revision']) && $d['article_revision'] !== $rev0 && $d['article_revision'] === $revision($a));
    $rev1 = $d['article_revision'];
    check('one file written to the document store', $filesOnDisk() === $filesBefore + 1);

    // Retry: the same request returns the same document, and writes nothing.
    $docsBefore = $count('SELECT COUNT(*) FROM documents');
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents",
        ['title' => 'ZZ KD runbook', 'client_reference' => "$prefix:file", 'expected_revision' => $rev0], [$pdf, 'runbook.txt', 'text/plain']);
    check('a retried upload is 200, replayed, the same id', $c === 200 && ($j['data']['id'] ?? 0) === $fileDoc && ($j['data']['replayed'] ?? false) === true, "$c " . json_encode($j));
    check('...no second document row, no second file, no revision move',
        $count('SELECT COUNT(*) FROM documents') === $docsBefore && $filesOnDisk() === $filesBefore + 1 && $revision($a) === $rev1);
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents",
        ['title' => 'ZZ KD runbook', 'client_reference' => "$prefix:file"], [$mkFile("other bytes"), 'runbook.txt', 'text/plain']);
    check('the same reference with different bytes is 409 reference_conflict', $c === 409 && ($j['error']['code'] ?? '') === 'reference_conflict', "$c " . json_encode($j));
    check('...and leaves nothing behind (no row, no file)', $count('SELECT COUNT(*) FROM documents') === $docsBefore && $filesOnDisk() === $filesBefore + 1);

    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents",
        ['external_url' => 'https://dms.example.test/zz-kd-guide.pdf', 'title' => 'ZZ KD guide', 'client_reference' => "$prefix:link"]);
    $linkDoc = (int)($j['data']['id'] ?? 0);
    if ($linkDoc) $made['docs'][] = $linkDoc;
    check('a link goes onto the draft (201), hidden, with no session url', $c === 201 && $linkDoc > 0 && $j['data']['kind'] === 'link'
        && $j['data']['url'] === null && $j['data']['show_in_help_centre'] === false && $link($linkDoc, $a) === ['shown' => false], "$c " . json_encode($j));
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents",
        ['title' => 'ZZ KD guide', 'client_reference' => "$prefix:link", 'external_url' => 'https://dms.example.test/zz-kd-guide.pdf']);
    check('a retried link (fields in another order) is the same document', $c === 200 && ($j['data']['id'] ?? 0) === $linkDoc);
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents",
        ['external_url' => 'https://dms.example.test/another.pdf', 'title' => 'ZZ KD guide', 'client_reference' => "$prefix:link"]);
    check('the same reference for another URL is 409', $c === 409 && ($j['error']['code'] ?? '') === 'reference_conflict');
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents",
        ['external_url' => 'https://dms.example.test/x.pdf', 'title' => 'ZZ KD stale', 'expected_revision' => $rev0]);
    if (!empty($j['data']['id'])) $made['docs'][] = (int)$j['data']['id'];
    check('a stale expected_revision is 409 revision_conflict, and nothing is added', $c === 409 && ($j['error']['code'] ?? '') === 'revision_conflict'
        && $count('SELECT COUNT(*) FROM documents WHERE title = ?', ['ZZ KD stale']) === 0, "$c " . json_encode($j));
    foreach ([[['external_url' => 'javascript:alert(1)'], 'invalid_field'], [['external_url' => 'https://x.test/a', 'surprise' => 1], 'unknown_field'],
              [['external_url' => 'https://x.test/a', 'show_in_help_centre' => 'yes'], 'invalid_field'], [['title' => 'no file or link'], 'missing_file'],
              [['external_url' => 'https://x.test/a', 'title' => str_repeat('x', 256)], 'invalid_field']] as [$bad, $code]) {
        [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents", $bad);
        if (!empty($j['data']['id'])) $made['docs'][] = (int)$j['data']['id'];
        check("refused, nothing written: $code (" . substr(json_encode($bad), 0, 50) . ')', $c === 422 && ($j['error']['code'] ?? '') === $code, "$c " . json_encode($j));
    }
    [$c] = $call($readOnly, 'POST', "/knowledge/articles/$a/documents", ['external_url' => 'https://x.test/ro']);
    check('a key without knowledge_documents.create cannot add (403)', $c === 403);

    // The native Documents panel sees both, and what it adds is in the REST list.
    $panel = DocumentsService::listFor($conn, $actor, null, 'knowledge_article', $a);
    $panelIds = array_map(fn($r) => (int)$r['id'], $panel['rows']);
    check('both appear in the native Documents panel', in_array($fileDoc, $panelIds, true) && in_array($linkDoc, $panelIds, true), json_encode($panelIds));
    $native = DocumentsService::add($conn, $actor, null, 'knowledge_article', $a, ['external_url' => 'https://dms.example.test/zz-kd-native', 'title' => 'ZZ KD native']);
    $nativeDoc = $made['docs'][] = (int)$native['id'];
    check('CONTROL: the Documents panel still shows a new article document by default', $link($nativeDoc, $a) === ['shown' => true]);
    [$c, $j] = $call($key, 'GET', "/knowledge/articles/$a/documents");
    $ids = array_map(fn($r) => $r['id'], $j['data'] ?? []);
    check('the REST list has every document on the article, the panel\'s too', $c === 200 && ($j['meta']['total'] ?? 0) === 3
        && in_array($nativeDoc, $ids, true) && in_array($fileDoc, $ids, true) && in_array($linkDoc, $ids, true), json_encode($j));
    [$c, $j] = $call($key, 'GET', "/knowledge/articles/$a/documents", null, null, 'client_reference=' . rawurlencode("$prefix:file"));
    check('?client_reference= finds the upload without sending it again', $c === 200 && count($j['data'] ?? []) === 1 && $j['data'][0]['id'] === $fileDoc);
    $rev2 = $revision($a);
    check('a document added in the app moves the article\'s revision too', $rev2 !== $rev1);

    // ── Content: documents.download, logged, article-specific ───────────────
    [$c, $j, $body, $head] = $call($key, 'GET', "/knowledge/articles/$a/documents/$fileDoc/content");
    check('the file comes back byte for byte, as an attachment', $c === 200 && $body === $bytes && stripos($head, 'Content-Disposition: attachment') !== false, "$c");
    check('...logged with the key and the bytes', $count('SELECT COUNT(*) FROM document_access_log WHERE document_id = ? AND api_key_id = ? AND bytes = ?', [$fileDoc, $keyId, strlen($bytes)]) === 1);
    [$c, $j] = $call($key, 'GET', "/knowledge/articles/$a/documents/$linkDoc/content");
    check('a link\'s content is its URL as JSON, not a redirect', $c === 200 && ($j['data']['external_url'] ?? '') === 'https://dms.example.test/zz-kd-guide.pdf');
    [$c] = $call($noDownload, 'GET', "/knowledge/articles/$a/documents/$fileDoc/content");
    check('without documents.download the content is 403', $c === 403);
    [$c] = $call($key, 'GET', "/knowledge/articles/$b/documents/$fileDoc/content");
    check('a document is never served through an article it is not on (404)', $c === 404);

    // ── Another article: attach, per-article Help Centre, retries ───────────
    $revB = $revision($b);
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$b/documents/$fileDoc/link", ['show_in_help_centre' => true, 'expected_revision' => $revB]);
    check('attaching an existing document is 201', $c === 201 && ($j['data']['id'] ?? 0) === $fileDoc && $j['data']['show_in_help_centre'] === true, "$c " . json_encode($j));
    check('...shown on B, still hidden on A: the setting is the link\'s', $link($fileDoc, $b) === ['shown' => true] && $link($fileDoc, $a) === ['shown' => false]);
    $revB2 = $revision($b);
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$b/documents/$fileDoc/link", ['show_in_help_centre' => false, 'expected_revision' => $revB]);
    check('attaching it again is 200 and changes nothing (no reset, no revision check)', $c === 200 && $link($fileDoc, $b) === ['shown' => true] && $revision($b) === $revB2, "$c " . json_encode($j));
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$b/documents/$linkDoc/link", []);
    check('an attached document is hidden by default', $c === 201 && $link($linkDoc, $b) === ['shown' => false]);
    $revA3 = $revision($a);
    $revB3 = $revision($b);
    check('attaching to B does not move A\'s revision', $revA3 === $rev2);

    // ── PATCH: per-article setting; shared title/description refused ───────
    [$c, $j] = $call($key, 'PATCH', "/knowledge/articles/$a/documents/$fileDoc", ['title' => 'ZZ KD renamed']);
    check('renaming a document attached elsewhere is 409 document_shared, nothing written', $c === 409 && ($j['error']['code'] ?? '') === 'document_shared'
        && $count('SELECT COUNT(*) FROM documents WHERE id = ? AND title = ?', [$fileDoc, 'ZZ KD runbook']) === 1, "$c " . json_encode($j));
    [$c, $j] = $call($key, 'PATCH', "/knowledge/articles/$a/documents/$fileDoc", ['show_in_help_centre' => true, 'expected_revision' => $revA3]);
    check('its Help Centre setting on A can change - A only, B\'s revision untouched', $c === 200 && ($j['data']['changed'] ?? []) === ['show_in_help_centre']
        && $link($fileDoc, $a) === ['shown' => true] && $link($fileDoc, $b) === ['shown' => true] && $revision($b) === $revB3, "$c " . json_encode($j));
    $revA4 = $revision($a);
    check('...and A\'s revision moved', $revA4 !== $revA3);
    // The link document back to A only, so its own title can change.
    [$c] = $call($key, 'DELETE', "/knowledge/articles/$b/documents/$linkDoc");
    check('the link document is taken off B (still on A)', $c === 200 && $link($linkDoc, $a) !== null);
    $revA5 = $revision($a);
    [$c, $j] = $call($key, 'PATCH', "/knowledge/articles/$a/documents/$linkDoc", ['title' => 'ZZ KD guide v2', 'description' => 'ZZ text', 'expected_revision' => $revA5]);
    check('a document only on A can be renamed', $c === 200 && $j['data']['title'] === 'ZZ KD guide v2' && $j['data']['description'] === 'ZZ text'
        && ($j['data']['changed'] ?? []) === ['title', 'description'], "$c " . json_encode($j));
    $revA6 = $revision($a);
    [$c, $j] = $call($key, 'PATCH', "/knowledge/articles/$a/documents/$linkDoc", ['title' => 'ZZ KD guide v2']);
    check('a PATCH that matches what is there writes nothing (revision unchanged)', $c === 200 && ($j['data']['changed'] ?? null) === [] && $revision($a) === $revA6);
    [$c, $j] = $call($key, 'PATCH', "/knowledge/articles/$a/documents/$linkDoc", ['title' => 'ZZ stale', 'expected_revision' => $revA5]);
    check('a stale expected_revision stops a PATCH', $c === 409 && ($j['error']['code'] ?? '') === 'revision_conflict');
    [$c, $j] = $call($key, 'PATCH', "/knowledge/articles/$a/documents/$linkDoc", ['title' => '  ']);
    check('an empty title is refused', $c === 422);

    // A retry of the first upload after people changed things: nothing is put back.
    [$c, $j] = $call($key, 'PATCH', "/knowledge/articles/$a/documents/$fileDoc", ['show_in_help_centre' => true]);
    $revA7 = $revision($a);
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents",
        ['title' => 'ZZ KD runbook', 'client_reference' => "$prefix:file", 'expected_revision' => $rev0], [$pdf, 'runbook.txt', 'text/plain']);
    check('a late retry returns the document as it is now: setting kept, revision kept', $c === 200 && $j['data']['show_in_help_centre'] === true
        && $link($fileDoc, $a) === ['shown' => true] && $revision($a) === $revA7, "$c " . json_encode($j));

    // ── Access: another reader, revoked, a grant through another article ────
    [$c] = $call($readerKey, 'GET', "/knowledge/articles/$a/documents");
    check('a reader outside the access list gets 404 for the list', $c === 404);
    [$c] = $call($readerKey, 'GET', "/knowledge/articles/$a/documents/$fileDoc/content");
    check('...and for the content - the document id does not get round the article', $c === 404);
    [$c, , $body] = $call($readerKey, 'GET', "/knowledge/articles/$b/documents/$fileDoc/content");
    check('CONTROL: the same reader reads it through B, which they can read', $c === 200 && $body === $bytes);
    [$c] = $call($readerKey, 'POST', "/knowledge/articles/$a/documents/$nativeDoc/link", []);
    check('...and cannot attach to A what they cannot reach', $c === 404);
    $grant = $access;
    $grant['entries'][] = ['principal_type' => 'analyst', 'principal_id' => $reader];
    [$c] = $call($key, 'PATCH', "/knowledge/articles/$a/access", ['access' => $grant, 'expected_revision' => $revision($a)]);
    [$c2, , $body] = $call($readerKey, 'GET', "/knowledge/articles/$a/documents/$fileDoc/content");
    check('granted access to A: the reader downloads through A', $c === 200 && $c2 === 200 && $body === $bytes, "$c $c2");
    [$c] = $call($key, 'PATCH', "/knowledge/articles/$a/access", ['access' => $access, 'expected_revision' => $revision($a)]);
    [$c2] = $call($readerKey, 'GET', "/knowledge/articles/$a/documents/$fileDoc/content");
    check('revoked: the next download through A is 404 at once', $c === 200 && $c2 === 404, "$c $c2");

    // ── The session url in the article's HTML ───────────────────────────────
    $csrf = bin2hex(random_bytes(16));
    $actorSession  = $session(['analyst_id' => $actor, 'csrf_token' => $csrf]);
    $readerSession = $session(['analyst_id' => $reader, 'csrf_token' => $csrf]);
    $fileUrl = $origin . $urlPrefix . $a . '&id=' . $fileDoc;
    [$c, $body] = $get($fileUrl, $actorSession);
    check('the session url serves the file to an analyst who can read the article', $c === 200 && $body === $bytes, (string)$c);
    check('...and logs it as theirs', $count('SELECT COUNT(*) FROM document_access_log WHERE document_id = ? AND analyst_id = ? AND api_key_id IS NULL', [$fileDoc, $actor]) >= 1);
    [$c] = $get($fileUrl, $readerSession);
    check('...is 404 for an analyst outside the article\'s access list', $c === 404);
    [$c, $body] = $get($origin . $urlPrefix . $b . '&id=' . $fileDoc, $readerSession);
    check('CONTROL: that analyst opens it through B\'s url', $c === 200 && $body === $bytes);
    [$c] = $get($fileUrl);
    check('nobody signed in gets nothing', $c === 404);
    [$c] = $get($origin . $urlPrefix . $b . '&id=' . $nativeDoc, $actorSession);
    check('a document not on that article is 404 through it', $c === 404);

    // Portal: a published, customer-facing article.
    [$c, $j] = $call($key, 'POST', '/knowledge/articles', ['title' => 'ZZ KD customer article', 'body_html' => '<p>ZZ</p>', 'audience' => 'customer', 'tenant_id' => null, 'is_published' => true, 'processing' => 'suppress']);
    $p = (int)($j['data']['id'] ?? 0);
    if ($p) $made['articles'][] = $p;
    $portalBytes = "ZZ KD portal\n" . bin2hex(random_bytes(16));
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$p/documents", ['title' => 'ZZ KD portal file'], [$mkFile($portalBytes), 'portal.txt', 'text/plain']);
    $portalDoc = (int)($j['data']['id'] ?? 0);
    if ($portalDoc) $made['docs'][] = $portalDoc;
    $conn->prepare('INSERT INTO users (email, display_name, tenant_id) VALUES (?, ?, ?)')->execute([$prefix . '@portal.example.test', 'ZZ KD portal reader', getDefaultTenantId($conn)]);
    $portalUser = $made['users'][] = (int)$conn->lastInsertId();
    $portalSession = $session(['ss_user_id' => $portalUser]);
    $portalUrl = $origin . $urlPrefix . $p . '&id=' . $portalDoc;
    [$c] = $get($portalUrl, $portalSession);
    check('a portal reader does not get a document hidden from the Help Centre', $portalDoc > 0 && $c === 404, (string)$c);
    $call($key, 'PATCH', "/knowledge/articles/$p/documents/$portalDoc", ['show_in_help_centre' => true]);
    [$c, $body] = $get($portalUrl, $portalSession);
    check('...gets it once it is shown', $c === 200 && $body === $portalBytes, (string)$c);
    [$c] = $get($origin . $urlPrefix . $a . '&id=' . $fileDoc, $portalSession);
    check('...never a document of an article they cannot read, even one shown there', $c === 404);
    $conn->prepare('UPDATE knowledge_articles SET is_published = 0 WHERE id = ?')->execute([$p]);
    [$c] = $get($portalUrl, $portalSession);
    check('...and not once the article is unpublished', $c === 404);

    // ── Detach: one link at a time; a removed document is not brought back ──
    [$c, $j] = $call($key, 'DELETE', "/knowledge/articles/$b/documents/$fileDoc", null, null, 'expected_revision=' . rawurlencode($rev0));
    check('a stale expected_revision stops a DELETE', $c === 409 && $link($fileDoc, $b) !== null);
    [$c, $j] = $call($key, 'DELETE', "/knowledge/articles/$b/documents/$fileDoc");
    check('detaching from B is 200, the document is not deleted', $c === 200 && ($j['data']['document_deleted'] ?? true) === false && $link($fileDoc, $b) === null, "$c " . json_encode($j));
    [$c, , $body] = $call($key, 'GET', "/knowledge/articles/$a/documents/$fileDoc/content");
    check('...and it is still on A, with its file', $c === 200 && $body === $bytes && $link($fileDoc, $a) !== null);
    $storage = $conn->query('SELECT storage_key FROM documents WHERE id = ' . $fileDoc)->fetchColumn();
    [$c, $j] = $call($key, 'DELETE', "/knowledge/articles/$a/documents/$fileDoc");
    check('its last link removed: deleted as in the app, file gone', $c === 200 && ($j['data']['document_deleted'] ?? false) === true
        && !is_file(documentStoragePath((string)$storage)), "$c " . json_encode($j));
    $docsBefore = $count('SELECT COUNT(*) FROM documents');
    [$c, $j] = $call($key, 'POST', "/knowledge/articles/$a/documents",
        ['title' => 'ZZ KD runbook', 'client_reference' => "$prefix:file", 'expected_revision' => $rev0], [$pdf, 'runbook.txt', 'text/plain']);
    check('replaying its upload is 404 and attaches nothing again', $c === 404 && $count('SELECT COUNT(*) FROM documents') === $docsBefore
        && $count("SELECT COUNT(*) FROM document_links WHERE parent_type = 'knowledge_article' AND parent_id = ? AND document_id = ?", [$a, $fileDoc]) === 0, "$c " . json_encode($j));
    [$c] = $call($key, 'DELETE', "/knowledge/articles/$a/documents/$fileDoc");
    check('detaching what is not there is 404', $c === 404);

    // ── Side effects ────────────────────────────────────────────────────────
    check('no AI: the article\'s embedding is untouched', $embedding($a) === $embeddingBefore);
    check('each change is in the article\'s history', $count("SELECT COUNT(*) FROM knowledge_audit WHERE object_type = 'article' AND object_id = ? AND action = 'edit' AND detail LIKE '%document_%'", [$a]) >= 5);

    // ── Archived articles take no new documents ─────────────────────────────
    [$c] = $call($key, 'DELETE', "/knowledge/articles/$b");
    [$c2, $j] = $call($key, 'POST', "/knowledge/articles/$b/documents", ['external_url' => 'https://x.test/archived']);
    if (!empty($j['data']['id'])) $made['docs'][] = (int)$j['data']['id'];
    check('an archived article refuses new documents (409 archived)', $c === 200 && $c2 === 409 && ($j['error']['code'] ?? '') === 'archived', "$c $c2");

    // ── Companies: the key's scope, not the analyst's default company ───────
    [$c, $j] = $call($key, 'POST', '/knowledge/articles', ['title' => 'ZZ KD company article', 'body_html' => '<p>ZZ</p>', 'audience' => 'internal', 'is_published' => true, 'processing' => 'suppress']);
    $q = (int)($j['data']['id'] ?? 0);
    if ($q) $made['articles'][] = $q;
    // A second company makes the install multi-company (removed in finally).
    $conn->prepare('INSERT INTO tenants (name, is_active) VALUES (?, 1)')->execute([$prefix . '_co']);
    $co = $made['tenants'][] = (int)$conn->lastInsertId();
    $conn->prepare('UPDATE knowledge_articles SET tenant_id = ?, is_published = 1 WHERE id IN (?, ?)')->execute([$co, $p, $q]);
    $coKey     = $mkKey($actor, ['knowledge_documents' => ['read', 'create', 'delete'], 'documents' => ['download']], [$co]);
    $sharedKey = $mkKey($actor, ['knowledge_documents' => ['read', 'create', 'delete'], 'documents' => ['download']], []);
    $rule = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg((string)php_ini_loaded_file()) . ' '
        . escapeshellarg(__FILE__) . " --analyst-rule $actor $p $portalDoc"), true);
    check('CONTROL: the analyst\'s own rules would refuse both - no session, so the default company', $rule === ['write' => false, 'see' => false], json_encode($rule));
    [$c, $j] = $call($coKey, 'POST', "/knowledge/articles/$p/documents", ['external_url' => 'https://dms.example.test/zz-kd-company', 'title' => 'ZZ KD company']);
    if (!empty($j['data']['id'])) $made['docs'][] = (int)$j['data']['id'];
    check('a key scoped to the article\'s company adds to it, though its analyst\'s default company is another', $c === 201, "$c " . json_encode($j));
    [$c, $j] = $call($coKey, 'POST', "/knowledge/articles/$q/documents/$portalDoc/link", []);
    [$c2] = $call($coKey, 'POST', "/knowledge/articles/$q/documents/$portalDoc/link", []);
    check('...attaches a document from one of that company\'s articles to another (201), and again is 200', $c === 201 && $c2 === 200, "$c $c2 " . json_encode($j));
    [$c] = $call($sharedKey, 'GET', "/knowledge/articles/$p/documents");
    [$c2] = $call($sharedKey, 'GET', "/knowledge/articles/$p/documents/$portalDoc/content");
    [$c3] = $call($sharedKey, 'POST', "/knowledge/articles/$p/documents", ['external_url' => 'https://x.test/other-company']);
    [$c4] = $call($sharedKey, 'POST', "/knowledge/articles/$a/documents/$portalDoc/link", []);
    check('another company\'s key gets 404 for the list, the content, a write - and cannot attach that company\'s document elsewhere', $c === 404 && $c2 === 404 && $c3 === 404 && $c4 === 404, "$c $c2 $c3 $c4");
    // The other direction, added when merging: the key is scoped to $co, its analyst
    // also sees the default company. A document on a default-company article must not
    // be attachable to one of $co's articles - and so never downloadable through it.
    // $a is moved into the OTHER company for this: an article with no company is
    // shared with every company in Knowledge, so the key could rightly reach it.
    $other = (int)$conn->query('SELECT id FROM tenants WHERE id <> ' . (int)$co . ' ORDER BY id LIMIT 1')->fetchColumn();
    $wasTenant = $conn->query('SELECT tenant_id FROM knowledge_articles WHERE id = ' . (int)$a)->fetchColumn();
    $conn->prepare('UPDATE knowledge_articles SET tenant_id = ? WHERE id = ?')->execute([$other, $a]);
    try {
        [$c, $j] = $call($coKey, 'POST', "/knowledge/articles/$q/documents/$nativeDoc/link", []);
        [$c2] = $call($coKey, 'GET', "/knowledge/articles/$q/documents/$nativeDoc/content");
        [$c3] = $call($key, 'GET', "/knowledge/articles/$a/documents");
    } finally {
        $conn->prepare('UPDATE knowledge_articles SET tenant_id = ? WHERE id = ?')->execute([$wasTenant === false ? null : $wasTenant, $a]);
    }
    check('a company-scoped key cannot attach a document on another company\'s article, though its analyst sees it (404, and no content)', $c === 404 && $c2 === 404, "$c $c2 " . json_encode($j));
    check('CONTROL: the analyst\'s unscoped key still reaches that article', $c3 === 200, (string)$c3);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    // Every document these articles hold, the ones made here included.
    if ($made['articles']) {
        $marks = implode(',', array_fill(0, count($made['articles']), '?'));
        $st = $conn->prepare("SELECT DISTINCT document_id FROM document_links WHERE parent_type = 'knowledge_article' AND parent_id IN ($marks)");
        $st->execute($made['articles']);
        $made['docs'] = array_values(array_unique(array_merge($made['docs'], array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)))));
    }
    require_once 'includes/search/documents_index.php';
    foreach ($made['docs'] as $d) {
        $st = $conn->prepare('SELECT storage_key FROM documents WHERE id = ?');
        $st->execute([$d]);
        if ($sk = $st->fetchColumn()) @unlink(documentStoragePath((string)$sk));
        foreach (['document_access_log', 'document_links'] as $t) $conn->prepare("DELETE FROM $t WHERE document_id = ?")->execute([$d]);
        try { $conn->prepare('DELETE FROM document_text WHERE document_id = ?')->execute([$d]); } catch (Throwable) {}
        try { searchUnindexDocument($conn, $d); } catch (Throwable) {}
        $conn->prepare('DELETE FROM documents WHERE id = ?')->execute([$d]);
    }
    foreach ($made['articles'] as $id) {
        foreach (['knowledge_article_tags', 'knowledge_article_versions', 'knowledge_images'] as $t) $conn->prepare("DELETE FROM $t WHERE article_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM knowledge_acl WHERE object_type = 'article' AND object_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM knowledge_audit WHERE object_type = 'article' AND object_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM search_documents WHERE source_type = 'kb_article' AND source_id = ?")->execute([$id]);
        $conn->prepare('DELETE FROM knowledge_articles WHERE id = ?')->execute([$id]);
    }
    foreach ($made['keys'] as $k) {
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
    foreach ($made['users'] as $id) $conn->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    foreach ($made['tenants'] as $id) $conn->prepare('DELETE FROM tenants WHERE id = ?')->execute([$id]);
    foreach (array_merge($made['sessions'], $made['tmp']) as $f) if (is_file($f)) @unlink($f);
    $left = $count("SELECT (SELECT COUNT(*) FROM documents WHERE title LIKE 'ZZ KD%') + (SELECT COUNT(*) FROM knowledge_articles WHERE title LIKE 'ZZ KD%') + (SELECT COUNT(*) FROM api_keys WHERE name = ?)", [$prefix]);
    check('cleanup: nothing this run made is left', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
