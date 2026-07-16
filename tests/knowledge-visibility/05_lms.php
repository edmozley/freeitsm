<?php
/**
 * LMS may only build lessons from SHARED articles.
 *
 * The picker hiding a title is not enough — it posts an id, and ai_author.php is
 * gated on LMS_MANAGE rather than on Knowledge. The real test is whether a
 * guessed id still yields a body.
 */
$APP = dirname(__DIR__, 2);
require_once __DIR__ . '/_bootstrap.php';
require_once $APP . '/config.php';
require_once $APP . '/includes/functions.php';
require_once $APP . '/includes/lms/knowledge_source.php';

$c = connectToDatabase();
$pass = 0; $fail = 0;
function check(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS  %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n        -> %s\n", $label, $detail); }
}
function hit(string $url, string $sid, ?array $post = null) {
    $ch = curl_init(BASE_TEST_URL . '' . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sid);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true);
}

$dream = (int)$c->query("SELECT id FROM tenants WHERE name LIKE 'Dream Holidays%' LIMIT 1")->fetchColumn();

$mk = function (string $title, ?int $tenant) use ($c) {
    $st = $c->prepare("INSERT INTO knowledge_articles (title, body, author_id, is_published, is_archived, created_datetime, modified_datetime, tenant_id, audience)
                       VALUES (?, ?, 1, 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, 'internal')");
    $st->execute([$title, '<p>The Dream Holidays booking admin password is s3cr3t-lms.</p>', $tenant]);
    return (int)$c->lastInsertId();
};
$owned  = $mk('ZZ-LMS-DREAM-ONLY', $dream);
$shared = $mk('ZZ-LMS-SHARED',     null);
echo "articles: company-owned=#$owned  shared=#$shared\n\n";

echo "=== the rule, directly ===\n";
check('a shared article MAY feed a lesson', lmsCanUseArticle($c, $shared));
check('a company-owned article MAY NOT', !lmsCanUseArticle($c, $owned), 'IT WAS ALLOWED');
check('a nonexistent id is refused', !lmsCanUseArticle($c, 999999));

// An LMS author (admin session — LMS_MANAGE, no Knowledge module needed).
$sid = 'zzlms' . random_int(1000, 9999);
file_put_contents(SESS_DIR . "/sess_$sid",
    'analyst_id|i:1;analyst_username|s:5:"admin";analyst_name|s:5:"Admin";analyst_email|s:15:"admin@localhost";');

echo "\n=== the picker ===\n";
$d = hit('api/lms/knowledge_articles.php', $sid);
$titles = array_column($d['data'] ?? [], 'title');
check('offers the shared article', in_array('ZZ-LMS-SHARED', $titles), implode(',', array_filter($titles, function($t){ return strpos($t,'ZZ-LMS')===0; })));
check('does NOT offer the company-owned one', !in_array('ZZ-LMS-DREAM-ONLY', $titles), 'LEAK in the picker');

echo "\n=== posting the hidden id anyway (the real test) ===\n";
$d = hit('api/lms/ai_author.php', $sid, ['mode' => 'lesson', 'article_id' => $owned]);
$body = json_encode($d);
check('refused', empty($d['success']), 'IT WAS ACCEPTED: ' . substr($body, 0, 160));
check('the secret is not in the response', strpos($body, 's3cr3t-lms') === false, 'BODY LEAKED');
echo '        response: ' . substr($d['error'] ?? $body, 0, 110) . "\n";

@unlink(SESS_DIR . "/sess_$sid");
foreach ([$owned, $shared] as $id) $c->prepare("DELETE FROM knowledge_articles WHERE id = ?")->execute([$id]);
echo "\ncleaned up\n";
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
