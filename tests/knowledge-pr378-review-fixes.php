<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * The four fixes made reviewing PR #378 (Knowledge migration API), through the
 * real endpoints, plus the Publish button for drafts that the review turned up.
 *
 *   1. knowledge_save.php takes only the keys the screen sends - the REST
 *      migration keys (expected_revision, access, processing...) are ignored.
 *   2. An access list holding a since-deleted person can still be added to and
 *      removed from (the PR's setAccess re-checked stored entries and locked it).
 *   3. api/knowledge/image.php serves nothing without a session, even an image
 *      from a public article (the PR served those to anonymous visitors).
 *   4. A draft can be published from the screen: knowledge_article.php says it
 *      is a draft, and saving is_published:true publishes it.
 *   5. revision is a keyed HMAC: stable, 64 hex, not the plain sha256.
 *
 * Disposable install only - it creates and deletes ZZ fixtures and forges a session:
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_TEST_URL=http://localhost/<install> php tests/knowledge-pr378-review-fixes.php
 */
chdir(__DIR__ . '/..');
$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "Set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL.\n"; exit(1); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/encryption.php';
require_once 'includes/services/knowledge.php';
require_once 'includes/services/knowledge_transfer.php';
require_once 'includes/services/knowledge_images.php';
$conn = connectToDatabase();
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "Wrong database.\n"; exit(1); }

$pass = 0; $fail = 0;
$check = function (string $label, bool $ok, string $why = '') use (&$pass, &$fail) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $why === '' ? '' : "  [$why]") . "\n";
    $ok ? $pass++ : $fail++;
};
$tag = 'zzkb378' . bin2hex(random_bytes(4));
$adminId = 0; $goneId = 0; $articles = []; $imageFile = ''; $sessFile = '';
$sessDir = rtrim(getenv('FREEITSM_SESS_DIR') ?: (ini_get('session.save_path') ?: sys_get_temp_dir()), '/\\');

$http = function (string $method, string $path, ?array $body = null, bool $session = true) use ($base, $tag): array {
    $ch = curl_init($base . '/' . $path);
    $headers = ['X-CSRF-Token: x'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADER => true]);
    if ($session) curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $tag);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $text = substr($raw, $hs);
    return ['code' => $code, 'type' => $type, 'body' => $text, 'json' => json_decode($text, true)];
};
$article = function (int $id) use ($conn): array {
    $st = $conn->prepare('SELECT * FROM knowledge_articles WHERE id = ?'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};

try {
    $ins = $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants)
        VALUES (?, '!', ?, ?, 1, ?, 1, 1)");
    $ins->execute([$tag, 'ZZ KB 378 admin', $tag . '@example.invalid', 1]);
    $adminId = (int)$conn->lastInsertId();
    $ins->execute([$tag . 'gone', 'ZZ KB 378 leaver', $tag . 'g@example.invalid', 0]);
    $goneId = (int)$conn->lastInsertId();
    $sessFile = $sessDir . '/sess_' . $tag;
    file_put_contents($sessFile, 'analyst_id|i:' . $adminId . ';csrf_token|s:1:"x";');
    $ctx = new ActorContext($adminId, null, 'ui');

    // A published, public, shared article and a draft.
    $pub = KnowledgeService::saveArticle($conn, $ctx, ['title' => "ZZ $tag public", 'body_html' => '<p>x</p>', 'audience' => 'public']);
    $articles[] = $pubId = (int)$pub['id'];
    $draft = KnowledgeService::saveArticle($conn, $ctx, ['title' => "ZZ $tag draft", 'body_html' => '<p>y</p>', 'is_published' => false]);
    $articles[] = $draftId = (int)$draft['id'];
    // Its own article: section 1 on the unfixed code restricts $pubId, which would hide the image anyway.
    $img = KnowledgeService::saveArticle($conn, $ctx, ['title' => "ZZ $tag image", 'body_html' => '<p>z</p>', 'audience' => 'public']);
    $articles[] = $imgArticleId = (int)$img['id'];

    echo "== 1. The screen's save takes only the screen's keys\n";
    $r = $http('POST', 'api/knowledge/knowledge_save.php', ['id' => $pubId, 'title' => "ZZ $tag renamed", 'expected_revision' => 'not-a-revision']);
    $check('a stale expected_revision sent by hand is ignored, not a 409', ($r['json']['success'] ?? false) === true, $r['body']);
    $check('...and the save still happened', $article($pubId)['title'] === "ZZ $tag renamed");
    $r = $http('POST', 'api/knowledge/knowledge_save.php', ['id' => $pubId, 'title' => "ZZ $tag access", 'access' => ['is_restricted' => true, 'inherits' => false, 'entries' => []]]);
    $check('an access list sent by hand does not change the article', ($r['json']['success'] ?? false) === true && (int)$article($pubId)['is_restricted'] === 0, $r['body']);
    $r = $http('POST', 'api/knowledge/knowledge_save.php', ['id' => $pubId, 'processing' => 'suppress', 'title' => "ZZ $tag again"]);
    $check('processing=suppress sent by hand is ignored (the save is a normal one)', ($r['json']['success'] ?? false) === true, $r['body']);

    echo "== 2. A deleted person on the list does not lock it\n";
    $r = $http('POST', 'api/knowledge/permissions.php', ['action' => 'add', 'object_type' => 'article', 'object_id' => $pubId, 'principal_type' => 'analyst', 'principal_id' => $goneId]);
    $check('add the person who is about to leave', ($r['json']['success'] ?? false) === true, $r['body']);
    $conn->prepare('DELETE FROM analysts WHERE id = ?')->execute([$goneId]);
    $r = $http('POST', 'api/knowledge/permissions.php', ['action' => 'add', 'object_type' => 'article', 'object_id' => $pubId, 'principal_type' => 'analyst', 'principal_id' => $adminId]);
    $check('someone can still be ADDED with a deleted person on the list', ($r['json']['success'] ?? false) === true, $r['body']);
    $r = $http('POST', 'api/knowledge/permissions.php', ['action' => 'add', 'object_type' => 'article', 'object_id' => $pubId, 'principal_type' => 'analyst', 'principal_id' => 999999999]);
    $check('a NEW entry naming nobody is still refused', ($r['json']['success'] ?? true) === false, $r['body']);
    $st = $conn->prepare("SELECT id FROM knowledge_acl WHERE object_type = 'article' AND object_id = ? AND principal_type = 'analyst' AND principal_id = ?");
    $st->execute([$pubId, $goneId]); $staleId = (int)$st->fetchColumn();
    $r = $http('POST', 'api/knowledge/permissions.php', ['action' => 'remove', 'object_type' => 'article', 'object_id' => $pubId, 'entry_id' => $staleId]);
    $st->execute([$pubId, $goneId]);
    $check('the deleted person can be REMOVED', ($r['json']['success'] ?? false) === true && !$st->fetchColumn(), $r['body']);

    echo "== 3. No session, no image\n";
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $stored = bin2hex(random_bytes(16)) . '.png';
    if (!is_dir(KnowledgeImageService::directory())) mkdir(KnowledgeImageService::directory(), 0775, true);
    $imageFile = KnowledgeImageService::directory() . '/' . $stored;
    file_put_contents($imageFile, $png);
    $conn->prepare('INSERT INTO knowledge_images (article_id, stored_name, original_name, mime_type, size_bytes, sha256, created_by_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$imgArticleId, $stored, 'zz.png', 'image/png', strlen($png), hash('sha256', $png), $adminId]);
    $imageId = (int)$conn->lastInsertId();
    $pa = $article($imgArticleId);
    $check('fixture: the article is published, public and shared with every company',
        (int)$pa['is_published'] === 1 && $pa['audience'] === 'public' && $pa['tenant_id'] === null, json_encode([$pa['is_published'], $pa['audience'], $pa['tenant_id']]));
    $r = $http('GET', 'api/knowledge/image.php?id=' . $imageId, null, true);
    $check('a signed-in analyst gets the image', $r['code'] === 200 && str_starts_with($r['type'], 'image/png'), $r['code'] . ' ' . $r['type']);
    $r = $http('GET', 'api/knowledge/image.php?id=' . $imageId, null, false);
    $check('nobody signed in gets 404, even for a public, shared, published article', $r['code'] === 404 && !str_starts_with($r['type'], 'image/'), $r['code'] . ' ' . $r['type']);

    echo "== 4. Drafts can be seen as drafts, and published\n";
    $r = $http('GET', 'api/knowledge/knowledge_article.php?id=' . $draftId);
    $check('the article endpoint says it is a draft', isset($r['json']['article']['is_published']) && (int)$r['json']['article']['is_published'] === 0, $r['body']);
    $r = $http('POST', 'api/knowledge/knowledge_save.php', ['id' => $draftId, 'is_published' => true]);
    $check('Publish publishes it', ($r['json']['success'] ?? false) === true && (int)$article($draftId)['is_published'] === 1, $r['body']);
    $check('...without touching the body', $article($draftId)['body'] === '<p>y</p>');

    echo "== 5. revision is keyed\n";
    $a = KnowledgeTransferService::revision($conn, 'article', $pubId);
    $check('stable and 64 hex characters', $a === KnowledgeTransferService::revision($conn, 'article', $pubId) && strlen($a) === 64 && ctype_xdigit($a));
} catch (Throwable $e) {
    $check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($imageFile !== '' && is_file($imageFile)) unlink($imageFile);
    foreach ($articles as $id) {
        $conn->prepare('DELETE FROM knowledge_images WHERE article_id = ?')->execute([$id]);
        $conn->prepare("DELETE FROM knowledge_acl WHERE object_type = 'article' AND object_id = ?")->execute([$id]);
        $conn->prepare('DELETE FROM knowledge_article_tags WHERE article_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM knowledge_article_versions WHERE article_id = ?')->execute([$id]);
        $conn->prepare("DELETE FROM knowledge_audit WHERE object_type = 'article' AND object_id = ?")->execute([$id]);
        $conn->prepare('DELETE FROM knowledge_articles WHERE id = ?')->execute([$id]);
    }
    foreach ([$adminId, $goneId] as $id) if ($id) {
        $conn->prepare('DELETE FROM user_preferences WHERE analyst_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM analysts WHERE id = ?')->execute([$id]);
    }
    if ($sessFile !== '' && is_file($sessFile)) unlink($sessFile);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
