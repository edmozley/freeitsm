<?php
/** CLI-only integration regression for the migration API (synthetic fixtures only). */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$conn = connectToDatabase();
if (!getenv('FREEITSM_TEST_DATABASE') || $conn->query('SELECT DATABASE()')->fetchColumn() !== getenv('FREEITSM_TEST_DATABASE')) {
    fwrite(STDERR, "Set FREEITSM_TEST_DATABASE to the disposable install's database.\n");
    exit(1);
}
$base = rtrim(getenv('FREEITSM_TEST_URL') ?: '', '/');
if ($base === '') { fwrite(STDERR, "FREEITSM_TEST_URL is required.\n"); exit(1); }
if (($argv[1] ?? '') === '--race-worker') {
    require_once dirname(__DIR__) . '/includes/services/knowledge_transfer.php';
    $ctx = new ActorContext((int)$argv[2], null, 'api');
    $in = ['name' => 'ZZ concurrent folder', 'client_reference' => $argv[3]];
    $result = KnowledgeTransferService::createOnce($conn, $ctx, 'folder', 0, $in, static function () use ($conn, $ctx, $in) {
        usleep(250000);
        return KnowledgeTransferService::createFolder($conn, $ctx, $in);
    });
    echo json_encode($result);
    exit;
}
$origin = preg_replace('~^(https?://[^/]+).*$~', '$1', $base);
$imagePrefix = rtrim(parse_url($base, PHP_URL_PATH) ?: '', '/') . '/api/knowledge/image.php?id=';
$prefix = 'zz_kb_' . bin2hex(random_bytes(5));
$analysts = [];
$keyIds = [];
$articles = [];
$folders = [];
$sessions = [];
$tenants = [];
$users = [];
$passed = 0;
$failed = 0;
function kbCheck(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS $name\n"; }
    else { $failed++; echo "FAIL $name\n"; }
}
function kbRequest(string $method, string $path, string $key, ?array $body = null): array
{
    global $base;
    $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode($path));
    $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/json'];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException(curl_error($ch));
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($raw, true), $raw];
}
function kbGetUrl(string $url, ?string $session = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    if ($session) curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . $session);
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException(curl_error($ch));
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $raw];
}
function kbSession(array $values): string
{
    global $sessions;
    $id = bin2hex(random_bytes(16));
    $contents = '';
    foreach ($values as $name => $value) $contents .= $name . '|' . serialize($value);
    $file = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/\\') . '/sess_' . $id;
    file_put_contents($file, $contents);
    $sessions[] = $file;
    return $id;
}
function kbSessionPost(string $endpoint, string $session, string $csrf, array $body): array
{
    global $base;
    $ch = curl_init($base . '/api/knowledge/' . $endpoint);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIE => session_name() . '=' . $session, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException(curl_error($ch));
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($raw, true)];
}
try {
    $conn->prepare('INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules) VALUES (?, ?, ?, ?, 1, 1, 1, 1)')
        ->execute([$prefix, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'ZZ API tester', $prefix . '@example.test']);
    $actor = (int)$conn->lastInsertId();
    $analysts[] = $actor;
    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, rate_limit_per_minute, created_datetime) VALUES (?, ?, ?, ?, ?, 1000, UTC_TIMESTAMP())')
        ->execute([$prefix, substr($key, 0, 12), hash('sha256', $key), $actor, json_encode([
            'knowledge' => ['read', 'create', 'update', 'delete', 'purge'],
            'knowledge_folders' => ['read', 'create'], 'knowledge_access' => ['read', 'manage'],
            'knowledge_images' => ['read', 'create', 'delete'], 'knowledge_imports' => ['manage'],
        ])]);
    $keyIds[] = (int)$conn->lastInsertId();
    [$status, $reply] = kbRequest('POST', '/knowledge/folders', $key, ['name' => 'ZZ root', 'client_reference' => $prefix . ':folder']);
    $folder = (int)($reply['data']['id'] ?? 0);
    if ($folder) $folders[] = $folder;
    kbCheck('REST creates a folder', $status === 201 && $folder > 0);
    if (!$folder) throw new RuntimeException('Folder creation failed: HTTP ' . $status . ' ' . json_encode($reply));
    [$status, $reply] = kbRequest('POST', '/knowledge/folders', $key, ['client_reference' => $prefix . ':folder', 'name' => 'ZZ root']);
    kbCheck('reordered JSON retry resolves the original folder', $status === 200 && ($reply['data']['id'] ?? 0) === $folder && ($reply['data']['replayed'] ?? false));
    [$status] = kbRequest('POST', '/knowledge/folders', $key, ['name' => 'ZZ different', 'client_reference' => $prefix . ':folder']);
    kbCheck('changed request under a used reference conflicts', $status === 409);
    [$status] = kbRequest('POST', '/knowledge/folders', $key, ['name' => 'ZZ invalid reference', 'client_reference' => $prefix . ':folder ']);
    kbCheck('reference validation prevents database trailing-space collisions', $status === 422);
    [$status, $reply] = kbRequest('POST', '/knowledge/folders', $key, ['name' => 'ZZ child', 'parent_id' => $folder]);
    $child = (int)($reply['data']['id'] ?? 0);
    if ($child) $folders[] = $child;
    kbCheck('folder hierarchy retains parent identity', $status === 201 && ($reply['data']['parent_id'] ?? 0) === $folder);

    // Real independent PHP/MySQL connections race the same source reference.
    $workers = [];
    for ($n = 0; $n < 2; $n++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--race-worker', (string)$actor, $prefix . ':race'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start concurrency worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $race = [];
    foreach ($workers as [$process, $pipes]) {
        $result = json_decode(stream_get_contents($pipes[1]), true);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !$result) throw new RuntimeException('Concurrency worker failed: ' . $error);
        $folders[] = (int)$result['id'];
        $race[] = $result;
    }
    $folders = array_values(array_unique($folders));
    kbCheck('simultaneous creates serialize to one folder', $race[0]['id'] === $race[1]['id'] && $race[0]['replayed'] !== $race[1]['replayed']);

    $makeKey = static function (int $analyst, array $permissions, ?array $companies = null) use ($conn, $prefix, &$keyIds): string {
        $token = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, rate_limit_per_minute, created_datetime) VALUES (?, ?, ?, ?, ?, ?, 1000, UTC_TIMESTAMP())')
            ->execute([$prefix, substr($token, 0, 12), hash('sha256', $token), $analyst, json_encode($permissions), $companies === null ? null : json_encode($companies)]);
        $keyIds[] = (int)$conn->lastInsertId();
        return $token;
    };
    $readKey = $makeKey($actor, ['knowledge' => ['read'], 'knowledge_folders' => ['read'], 'knowledge_images' => ['read']]);
    $writeOnly = $makeKey($actor, ['knowledge' => ['create', 'update']]);
    $conn->prepare('INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules) VALUES (?, ?, ?, ?, 1, 0, 1, 1)')
        ->execute([$prefix . '_reader', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'ZZ restricted reader', $prefix . '_reader@example.test']);
    $reader = (int)$conn->lastInsertId();
    $analysts[] = $reader;
    $readerKey = $makeKey($reader, ['knowledge' => ['read', 'create', 'update'], 'knowledge_images' => ['read', 'create'], 'knowledge_access' => ['read', 'manage']]);
    $csrf = bin2hex(random_bytes(32));
    $adminSession = kbSession(['analyst_id' => $actor, 'csrf_token' => $csrf]);
    [$status, $reply] = kbSessionPost('folders.php', $adminSession, $csrf, ['action' => 'create', 'name' => 'ZZ UI folder', 'parent_id' => $folder]);
    $uiFolder = (int)($reply['id'] ?? 0);
    if ($uiFolder) $folders[] = $uiFolder;
    kbCheck('existing folder UI uses the shared create path', $status === 200 && ($reply['success'] ?? false) && $uiFolder > 0);
    $access = ['is_restricted' => true, 'inherits' => false, 'entries' => [['principal_type' => 'analyst', 'principal_id' => $actor]]];
    $create = ['title' => 'ZZ migration article', 'body_html' => '<h2 id="step">Example</h2>', 'folder_id' => $child,
        'audience' => 'internal', 'tenant_id' => null, 'access' => $access, 'is_published' => false,
        'processing' => 'suppress', 'client_reference' => $prefix . ':article'];
    [$status, $reply] = kbRequest('POST', '/knowledge/articles', $key, $create);
    $article = (int)($reply['data']['id'] ?? 0);
    if ($article) $articles[] = $article;
    kbCheck('protected draft is created with folder, company and audience', $status === 201 && $article > 0
        && ($reply['data']['folder_id'] ?? 0) === $child && ($reply['data']['is_published'] ?? true) === false
        && ($reply['data']['audience'] ?? '') === 'internal' && array_key_exists('tenant_id', $reply['data'] ?? []));
    if (!$article) throw new RuntimeException('Article creation failed: ' . json_encode($reply));
    kbCheck('import explicitly suppresses AI and workflow effects', ($reply['data']['processing']['ai'] ?? '') === 'suppressed'
        && ($reply['data']['processing']['workflows'] ?? '') === 'suppressed');
    $revision = $reply['data']['revision'];
    [$status, $reply] = kbRequest('GET', '/knowledge/articles/' . $article . '/access', $key);
    kbCheck('access settings round-trip exactly', $status === 200 && ($reply['data']['entries'] ?? []) === $access['entries']
        && ($reply['data']['is_restricted'] ?? false) && !($reply['data']['inherits'] ?? true));
    [$status] = kbRequest('GET', '/knowledge/articles/' . $article, $readerKey);
    kbCheck('restricted draft never readable by an excluded analyst', $status === 404);
    [$status] = kbRequest('GET', '/knowledge/articles/' . $article . '/access', $readerKey);
    kbCheck('API ACL permission alone does not grant the administrator floor', $status === 403);
    [$status] = kbRequest('POST', '/knowledge/articles', $writeOnly, $create);
    kbCheck('article write permission does not grant access management/import control', $status === 403);
    [$status, $reply] = kbRequest('POST', '/knowledge/articles', $key, $create);
    kbCheck('article retry neither duplicates nor repeats side effects', $status === 200 && ($reply['data']['id'] ?? 0) === $article
        && ($reply['data']['processing']['workflows'] ?? '') === 'not_repeated');
    $invalid = $create;
    $invalid['client_reference'] .= ':invalid';
    $invalid['access']['entries'][0]['principal_id'] = 2147483647;
    $countBefore = (int)$conn->query('SELECT COUNT(*) FROM knowledge_articles')->fetchColumn();
    [$status] = kbRequest('POST', '/knowledge/articles', $key, $invalid);
    kbCheck('invalid ACL rolls back article creation and its reference', $status === 422
        && (int)$conn->query('SELECT COUNT(*) FROM knowledge_articles')->fetchColumn() === $countBefore);
    [$status] = kbRequest('PATCH', '/knowledge/articles/' . $article, $key,
        ['is_published' => true, 'processing' => 'suppress']);
    kbCheck('controlled import update requires a revision', $status === 422);
    // Manual edit deliberately keeps the timestamp unchanged: second-resolution clocks cannot detect it.
    $conn->prepare('UPDATE knowledge_articles SET title = ? WHERE id = ?')->execute(['ZZ manual edit', $article]);
    [$status] = kbRequest('PATCH', '/knowledge/articles/' . $article, $key,
        ['title' => 'ZZ must not overwrite', 'expected_revision' => $revision, 'processing' => 'suppress']);
    kbCheck('same-second manual edit is reported as a conflict', $status === 409);
    [$status, $reply] = kbRequest('GET', '/knowledge/articles/' . $article, $key);
    $revision = $reply['data']['revision'];
    kbCheck('conflict leaves the manual edit intact', ($reply['data']['title'] ?? '') === 'ZZ manual edit');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII=');
    $imageInput = ['filename' => 'ZZ image.png', 'content_base64' => base64_encode($png), 'client_reference' => $prefix . ':image'];
    [$status, $reply] = kbRequest('POST', '/knowledge/articles/' . $article . '/images', $key, $imageInput);
    $imageId = (int)($reply['data']['id'] ?? 0);
    $imageUrl = $reply['data']['url'] ?? '';
    kbCheck('image upload returns a durable subfolder-aware reference', $status === 201 && $imageId > 0
        && str_starts_with($imageUrl, $imagePrefix));
    if (!$imageId) throw new RuntimeException('Image creation failed: ' . json_encode($reply));
    [$status, $reply] = kbRequest('POST', '/knowledge/articles/' . $article . '/images', $key, $imageInput);
    kbCheck('image retry returns the same image', $status === 200 && ($reply['data']['id'] ?? 0) === $imageId);
    [$status, , $raw] = kbRequest('GET', '/knowledge/images/' . $imageId . '/content', $key);
    kbCheck('authorized image download returns identical bytes', $status === 200 && $raw === $png);
    [$status] = kbRequest('GET', '/knowledge/images/' . $imageId . '/content', $readerKey);
    kbCheck('image URL cannot bypass article ACL', $status === 404);
    [$status] = kbRequest('POST', '/knowledge/articles/' . $article . '/images', $readKey, $imageInput);
    kbCheck('read-only API key cannot upload images', $status === 403);
    [$status] = kbRequest('POST', '/knowledge/articles/' . $article . '/images', $readerKey, $imageInput);
    kbCheck('image-create permission cannot bypass article ACL', $status === 404);
    foreach ([['filename' => 'ZZ.svg', 'content_base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>')],
        ['filename' => 'ZZ.png', 'content_base64' => base64_encode('<?php echo 1; ?>')],
        ['filename' => 'ZZ.png', 'content_base64' => '%%%']] as $badImage) {
        [$status] = kbRequest('POST', '/knowledge/articles/' . $article . '/images', $key, $badImage);
        kbCheck('invalid/active image content is refused', $status === 422);
    }
    [$status] = kbGetUrl($origin . $imageUrl);
    kbCheck('anonymous request cannot retrieve a restricted image', $status === 404);
    [$status] = kbRequest('PATCH', '/knowledge/articles/' . $article, $key,
        ['is_published' => true, 'expected_revision' => $revision, 'processing' => 'suppress']);
    kbCheck('image changes invalidate the publication revision', $status === 409);
    [$status, $reply] = kbRequest('GET', '/knowledge/articles/' . $article, $key);
    $revision = $reply['data']['revision'];
    [$status, $reply] = kbRequest('PATCH', '/knowledge/articles/' . $article, $key,
        ['is_published' => true, 'expected_revision' => $revision, 'processing' => 'suppress', 'required_image_ids' => [2147483647]]);
    kbCheck('missing required image prevents publication', $status === 422 && ($reply['error']['code'] ?? '') === 'missing_image');
    [$status, $reply] = kbRequest('PATCH', '/knowledge/articles/' . $article, $key,
        ['is_published' => true, 'expected_revision' => $revision, 'processing' => 'suppress', 'required_image_ids' => [$imageId],
            'body_html' => '<p><img src="' . $imageUrl . '"></p>']);
    kbCheck('completed article publishes with its protected inline image', $status === 200 && ($reply['data']['is_published'] ?? false));
    if ($status !== 200) throw new RuntimeException('Publication failed: ' . json_encode($reply));
    $revision = $reply['data']['revision'];
    [$status] = kbRequest('PATCH', '/knowledge/articles/' . $article, $key,
        ['is_published' => true, 'expected_revision' => $revision, 'processing' => 'suppress', 'folder_id' => 'typo']);
    kbCheck('invalid folder cannot silently become the root', $status === 422);
    $grant = $access;
    $grant['entries'][] = ['principal_type' => 'analyst', 'principal_id' => $reader];
    $st = $conn->prepare("SELECT id FROM knowledge_acl WHERE object_type = 'article' AND object_id = ? AND principal_type = 'analyst' AND principal_id = ?");
    $st->execute([$article, $actor]);
    $entryBefore = (int)$st->fetchColumn();
    [$status, $reply] = kbRequest('PATCH', '/knowledge/articles/' . $article . '/access', $key,
        ['access' => $grant, 'expected_revision' => $revision]);
    kbCheck('access replacement uses the current revision', $status === 200);
    $st->execute([$article, $actor]);
    kbCheck('unchanged access entries keep their IDs for open UI dialogs', (int)$st->fetchColumn() === $entryBefore);
    [$status, , $raw] = kbRequest('GET', '/knowledge/images/' . $imageId . '/content', $readerKey);
    kbCheck('positive control: newly granted reader can fetch image', $status === 200 && $raw === $png);
    $revision = $reply['data']['revision'];
    [$status] = kbRequest('PATCH', '/knowledge/articles/' . $article . '/access', $key,
        ['access' => $access, 'expected_revision' => $revision]);
    [$status] = kbRequest('GET', '/knowledge/images/' . $imageId . '/content', $readerKey);
    kbCheck('revoked image access takes effect immediately', $status === 404);
    [$status, $raw] = kbGetUrl($origin . $imageUrl, $adminSession);
    kbCheck('inline session URL renders authorized article image bytes', $status === 200 && $raw === $png);
    [$status, $reply] = kbSessionPost('permissions.php', $adminSession, $csrf,
        ['action' => 'add', 'object_type' => 'article', 'object_id' => $article, 'principal_type' => 'analyst', 'principal_id' => $reader]);
    kbCheck('existing access UI uses the shared write path', $status === 200 && ($reply['success'] ?? false));
    [$status, , $raw] = kbRequest('GET', '/knowledge/images/' . $imageId . '/content', $readerKey);
    kbCheck('UI grant is immediately reflected in REST image access', $status === 200 && $raw === $png);
    $conn->prepare('UPDATE analysts SET can_access_all_modules = 0 WHERE id = ?')->execute([$reader]);
    [$status] = kbRequest('POST', '/knowledge/articles/' . $article . '/images', $readerKey, $imageInput);
    kbCheck('API create permission cannot replace missing analyst module access', $status === 403);

    // Creating a second company enables real REST scope filtering without changing settings.
    $conn->prepare('INSERT INTO tenants (name, is_active) VALUES (?, 1)')->execute([$prefix . '_company']);
    $company = (int)$conn->lastInsertId();
    $tenants[] = $company;
    $conn->prepare('UPDATE knowledge_articles SET tenant_id = ? WHERE id = ?')->execute([$company, $article]);
    $scopedKey = $makeKey($actor, ['knowledge' => ['read'], 'knowledge_images' => ['read']], []);
    [$status] = kbRequest('GET', '/knowledge/articles/' . $article, $scopedKey);
    kbCheck('company-scoped key cannot read outside its scope', $status === 404);
    [$status] = kbRequest('GET', '/knowledge/images/' . $imageId . '/content', $scopedKey);
    kbCheck('image retrieval preserves the key company boundary', $status === 404);
    // Restore only this test-owned row; session tests below use a shared customer article.
    $conn->prepare("UPDATE knowledge_articles SET tenant_id = NULL, audience = 'customer', is_restricted = 0, inherit_permissions = 0 WHERE id = ?")->execute([$article]);
    $conn->prepare("DELETE FROM knowledge_acl WHERE object_type = 'article' AND object_id = ?")->execute([$article]);
    $conn->prepare('INSERT INTO users (email, display_name, tenant_id) VALUES (?, ?, ?)')->execute([$prefix . '@example.test', 'ZZ portal reader', $company]);
    $portalUser = (int)$conn->lastInsertId();
    $users[] = $portalUser;
    $portalSession = kbSession(['ss_user_id' => $portalUser]);
    [$status, $raw] = kbGetUrl($origin . $imageUrl, $portalSession);
    kbCheck('portal reader can fetch images of an accessible customer article', $status === 200 && $raw === $png);
    $conn->prepare('UPDATE knowledge_articles SET is_published = 0 WHERE id = ?')->execute([$article]);
    [$status] = kbGetUrl($origin . $imageUrl, $portalSession);
    kbCheck('unpublishing immediately hides images from portal readers', $status === 404);
    $conn->prepare("UPDATE knowledge_articles SET is_published = 1, audience = 'public' WHERE id = ?")->execute([$article]);
    // Ed, 19 Nov 2026: nothing is served to somebody who is not signed in, even from a public article.
    [$status, $raw] = kbGetUrl($origin . $imageUrl);
    kbCheck('without a session, even a public shared article\'s image is not served', $status === 404 && $raw !== $png);
    [$status, $raw] = kbGetUrl($origin . $imageUrl, $portalSession);
    kbCheck('positive control: a signed-in portal reader gets the public article\'s image', $status === 200 && $raw === $png);
    [$status] = kbRequest('DELETE', '/knowledge/articles/' . $article . '/images/' . $imageId, $key);
    kbCheck('image deletion succeeds', $status === 200);
    [$status] = kbRequest('POST', '/knowledge/articles/' . $article . '/images', $key, $imageInput);
    kbCheck('retry after deletion cannot resurrect an image', $status === 404);
    // Test the normal post-commit path only when no external AI key is configured.
    // A draft has no publication event, so this cannot trigger real workflows.
    $st = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'");
    if (!$st->fetchColumn()) {
        [$status, $reply] = kbRequest('POST', '/knowledge/articles', $key, ['title' => 'ZZ ordinary draft', 'is_published' => false]);
        if (!empty($reply['data']['id'])) $articles[] = (int)$reply['data']['id'];
        kbCheck('normal draft save reports no configured AI and no publication workflow', $status === 201
            && ($reply['data']['processing']['ai'] ?? '') === 'not_configured'
            && ($reply['data']['processing']['workflows'] ?? '') === 'not_applicable');
    } else {
        echo "SKIP normal AI-processing check: an external provider is configured.\n";
    }
    // A joined transaction must have no post-commit side effects while uncommitted.
    require_once dirname(__DIR__) . '/includes/services/knowledge.php';
    $conn->beginTransaction();
    $pending = KnowledgeService::saveArticle($conn, new ActorContext($actor, null, 'api'), ['title' => 'ZZ rolled back']);
    $pendingId = $pending['id'];
    kbCheck('service joins an existing transaction and defers processing', $conn->inTransaction() && !isset($pending['processing']));
    $st = $conn->prepare('SELECT is_published, tenant_id, audience FROM knowledge_articles WHERE id = ?');
    $st->execute([$pendingId]);
    $defaults = $st->fetch(PDO::FETCH_ASSOC);
    kbCheck('legacy save defaults remain published, shared and internal', (int)$defaults['is_published'] === 1 && $defaults['tenant_id'] === null && $defaults['audience'] === 'internal');
    $conn->rollBack();
    $st = $conn->prepare('SELECT COUNT(*) FROM knowledge_articles WHERE id = ?');
    $st->execute([$pendingId]);
    kbCheck('caller rollback removes the whole staged save', (int)$st->fetchColumn() === 0);

    // ---- GH #417: the administrator's image size limit, the constraints endpoint, the stable error
    require_once dirname(__DIR__) . '/includes/services/knowledge_images.php';
    $create417 = ['client_reference' => $prefix . ':article417', 'title' => 'ZZ size limit article'] + $create;
    [$status, $reply] = kbRequest('POST', '/knowledge/articles', $key, $create417);
    $sizeArticle = (int)($reply['data']['id'] ?? 0);
    if ($sizeArticle) $articles[] = $sizeArticle;
    $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_image_max_mb'");
    $st->execute();
    $kbImgOriginal = $st->fetchColumn();   // false = no row; put back exactly as found in finally
    $setMb = function ($v) use ($conn) {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = 'knowledge_image_max_mb'")->execute();
        if ($v !== null) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('knowledge_image_max_mb', ?)")->execute([(string)$v]);
    };
    $sized = function (int $n) use ($png) { return $png . str_repeat("\0", $n - strlen($png)); };   // a real PNG, padded to exactly $n bytes

    $setMb(null);
    [$status, $reply] = kbRequest('GET', '/knowledge/images/constraints', $readKey);
    $c = $reply['data'] ?? [];
    kbCheck('#417 constraints: 10 MiB when no administrator has chosen (an upgrade changes nothing)', $status === 200
        && ($c['max_bytes'] ?? 0) === 10485760 && ($c['default_max_bytes'] ?? 0) === 10485760 && ($c['max_base64_length'] ?? 0) === 13981016);
    $exts = array_column($c['formats'] ?? [], 'extension');
    kbCheck('#417 constraints list the formats, and never SVG', in_array('png', $exts, true) && in_array('heic', $exts, true) && !in_array('svg', $exts, true));
    kbCheck('#417 constraints report the request ceiling and whether it fits', array_key_exists('request_max_bytes', $c) && is_bool($c['within_server_limits'] ?? null));
    $setMb('7');
    [, $reply] = kbRequest('GET', '/knowledge/images/constraints', $readKey);
    kbCheck('#417 a stored size that is not one of the choices falls back to 10 MiB', ($reply['data']['max_bytes'] ?? 0) === 10485760);

    // Exact boundary through the service itself - independent of PHP's request limits.
    $setMb('5');
    $ctx417 = new ActorContext($actor, null, 'api');
    try {
        $r = KnowledgeImageService::create($conn, $ctx417, $sizeArticle, ['filename' => 'ZZ exact.png', 'content_base64' => base64_encode($sized(5242880))]);
        $exactOk = $r['id'] > 0;
    } catch (ServiceError $e) { $exactOk = false; fwrite(STDERR, $e->getMessage() . "\n"); }
    kbCheck('#417 an image of exactly the limit is accepted', $exactOk);
    try {
        KnowledgeImageService::create($conn, $ctx417, $sizeArticle, ['filename' => 'ZZ over.png', 'content_base64' => base64_encode($sized(5242881))]);
        $overCode = '';
    } catch (ServiceError $e) { $overCode = $e->errorCode; }
    kbCheck('#417 one byte over is image_too_large', $overCode === 'image_too_large');

    // Over HTTP at 5 MiB (fits an 8 MB post_max_size): the stable error.
    [$status, $reply] = kbRequest('POST', '/knowledge/articles/' . $sizeArticle . '/images', $key, ['filename' => 'ZZ over.png', 'content_base64' => base64_encode($sized(5242881))]);
    kbCheck('#417 oversized upload: 413 image_too_large stating the limit', $status === 413 && ($reply['error']['code'] ?? '') === 'image_too_large'
        && ($reply['error']['details']['max_bytes'] ?? 0) === 5242880 && str_contains($reply['error']['message'] ?? '', '5242880'));
    [$status, $reply] = kbRequest('POST', '/knowledge/articles/' . $sizeArticle . '/images', $key, ['filename' => 'ZZ.png', 'content_base64' => '%%%']);
    kbCheck('#417 malformed base64 is still invalid_image (422), not a size error', $status === 422 && ($reply['error']['code'] ?? '') === 'invalid_image');

    // Raised above the old fixed 10 MiB: a 10 MiB + 1 image round-trips - where PHP here can take it.
    $setMb('20');
    [, $reply] = kbRequest('GET', '/knowledge/images/constraints', $readKey);
    $c = $reply['data'] ?? [];
    kbCheck('#417 the chosen size is reported', ($c['max_bytes'] ?? 0) === 20971520);
    if (!empty($c['within_server_limits'])) {
        $big = $sized(10485761);
        $bigIn = ['filename' => 'ZZ big.png', 'content_base64' => base64_encode($big), 'client_reference' => $prefix . ':big'];
        [$status, $reply] = kbRequest('POST', '/knowledge/articles/' . $sizeArticle . '/images', $key, $bigIn);
        $bigId = (int)($reply['data']['id'] ?? 0);
        kbCheck('#417 above 10 MiB uploads once the limit is raised', $status === 201 && $bigId > 0 && ($reply['data']['size_bytes'] ?? 0) === 10485761);
        [$status, $reply] = kbRequest('POST', '/knowledge/articles/' . $sizeArticle . '/images', $key, $bigIn);
        kbCheck('#417 ...replays to the same image', $status === 200 && ($reply['data']['id'] ?? 0) === $bigId);
        [$status, , $raw] = kbRequest('GET', '/knowledge/images/' . $bigId . '/content', $key);
        kbCheck('#417 ...and downloads byte for byte', $status === 200 && $raw === $big);
        [$status] = kbRequest('GET', '/knowledge/images/' . $bigId . '/content', $readerKey);
        kbCheck('#417 ...with the same access rules (the reader has no access by now)', in_array($status, [403, 404], true));
    } else {
        echo "  SKIP  #417 10 MiB + 1 round trip: PHP here cannot take it (request_max_bytes " . var_export($c['request_max_bytes'] ?? null, true) . ")\n";
    }

    // ---- GH #423: a picture EMBEDDED in body_html meets the same limit as an uploaded one
    require_once dirname(__DIR__) . '/includes/services/knowledge.php';
    $setMb('5');
    $embed = fn(int $n, string $text = '') => '<p>ZZ embedded' . $text . '</p><p><img src="data:image/png;base64,' . base64_encode($sized($n)) . '" alt=""></p>';
    $saveCode = function (array $in) use ($conn, $ctx417) {
        try { KnowledgeService::saveArticle($conn, $ctx417, $in); return ''; }
        catch (ServiceError $e) { return $e->errorCode . '|' . ($e->httpStatus ?? 0) . '|' . json_encode($e->details); }
    };
    kbCheck('#423 an embedded picture of exactly the limit is accepted', $saveCode(['id' => $sizeArticle, 'body_html' => $embed(5242880)]) === '');
    $r = $saveCode(['id' => $sizeArticle, 'body_html' => $embed(5242880) . $embed(5242881, ' two')]);
    kbCheck('#423 one byte over is embedded_image_too_large, 413, naming picture 2, its size and the limit',
        str_starts_with($r, 'embedded_image_too_large|413|') && str_contains($r, '"picture":2') && str_contains($r, '"bytes":5242881') && str_contains($r, '"max_bytes":5242880'));
    // A picture already in the article (from before the limit, or a larger limit) does not block an edit.
    $conn->prepare('UPDATE knowledge_articles SET body = ? WHERE id = ?')->execute([$embed(6000000), $sizeArticle]);
    kbCheck('#423 an article already holding a larger picture can still be edited around it', $saveCode(['id' => $sizeArticle, 'body_html' => $embed(6000000, ' edited')]) === '');
    kbCheck('#423 ...but a NEW larger picture added to it is refused', str_starts_with($saveCode(['id' => $sizeArticle, 'body_html' => $embed(6000000, ' edited') . $embed(5300000)]), 'embedded_image_too_large|413|'));
    $conn->prepare('UPDATE knowledge_articles SET body = ? WHERE id = ?')->execute(['<p>ZZ</p>', $sizeArticle]);
    // Over HTTP: the clear error a migration script gets (the 5 MiB + 1 picture fits an 8 MB post_max_size).
    [$status, $reply] = kbRequest('POST', '/knowledge/articles', $key, ['client_reference' => $prefix . ':embed413', 'title' => 'ZZ embedded too large', 'body_html' => $embed(5242881)] + $create);
    if (!empty($reply['data']['id'])) $articles[] = (int)$reply['data']['id'];
    kbCheck('#423 over HTTP: 413 embedded_image_too_large with picture, type, bytes and max_bytes, and nothing created',
        $status === 413 && ($reply['error']['code'] ?? '') === 'embedded_image_too_large'
        && ($reply['error']['details']['picture'] ?? 0) === 1 && ($reply['error']['details']['type'] ?? '') === 'image/png'
        && ($reply['error']['details']['bytes'] ?? 0) === 5242881 && ($reply['error']['details']['max_bytes'] ?? 0) === 5242880
        && str_contains($reply['error']['message'] ?? '', 'POST /knowledge/articles/{id}/images') && empty($reply['data']['id']));
    [$status, $reply] = kbRequest('POST', '/knowledge/articles', $key, ['client_reference' => $prefix . ':embedok', 'title' => 'ZZ embedded small', 'body_html' => $embed(2000)] + $create);
    if (!empty($reply['data']['id'])) $articles[] = (int)$reply['data']['id'];
    kbCheck('#423 CONTROL: a small embedded picture is still accepted over HTTP', $status === 201);
} catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, $e->getMessage() . "\n");
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    if (isset($kbImgOriginal)) {   // GH #417: the image size setting, exactly as it was
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = 'knowledge_image_max_mb'")->execute();
        if ($kbImgOriginal !== false) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('knowledge_image_max_mb', ?)")->execute([$kbImgOriginal]);
    }
    foreach ($articles as $id) {
        $st = $conn->prepare('SELECT stored_name FROM knowledge_images WHERE article_id = ?');
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $path = dirname(__DIR__) . '/uploads/knowledge/' . $name;
            if (is_file($path)) unlink($path);
        }
        foreach (['knowledge_images', 'knowledge_article_tags', 'knowledge_article_versions'] as $table) {
            $conn->prepare("DELETE FROM $table WHERE article_id = ?")->execute([$id]);
        }
        $conn->prepare("DELETE FROM knowledge_acl WHERE object_type = 'article' AND object_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM knowledge_audit WHERE object_type = 'article' AND object_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM search_documents WHERE source_type = 'kb_article' AND source_id = ?")->execute([$id]);
        $conn->prepare('DELETE FROM knowledge_articles WHERE id = ?')->execute([$id]);
    }
    foreach (array_reverse($folders) as $id) {
        $conn->prepare("DELETE FROM knowledge_acl WHERE object_type = 'folder' AND object_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM knowledge_audit WHERE object_type = 'folder' AND object_id = ?")->execute([$id]);
        $conn->prepare('DELETE FROM knowledge_folders WHERE id = ?')->execute([$id]);
    }
    foreach ($keyIds as $id) {
        $conn->prepare('DELETE FROM api_key_rate_limits WHERE api_key_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$id]);
    }
    foreach ($analysts as $id) {
        $conn->prepare('DELETE FROM knowledge_api_references WHERE analyst_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM analysts WHERE id = ?')->execute([$id]);
    }
    foreach ($users as $id) $conn->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    foreach ($tenants as $id) $conn->prepare('DELETE FROM tenants WHERE id = ?')->execute([$id]);
    foreach ($sessions as $file) if (is_file($file)) unlink($file);
}
echo "$passed passed; $failed failed\n";
exit($failed ? 1 : 0);
