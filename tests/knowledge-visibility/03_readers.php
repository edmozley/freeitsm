<?php
/**
 * The reader sweep: every analyst-facing surface must respect the company the
 * analyst has switched to. Drives the REAL endpoints over HTTP with a forged
 * session, as a RESTRICTED analyst.
 */
$APP = dirname(__DIR__, 2);
require_once __DIR__ . '/_bootstrap.php';
require_once $APP . '/config.php';
require_once $APP . '/includes/functions.php';
require_once $APP . '/includes/tenancy.php';

$c = connectToDatabase();
$pass = 0; $fail = 0;
function check(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS  %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n        -> %s\n", $label, $detail); }
}
function hit(string $url, string $sid) {
    $ch = curl_init(BASE_TEST_URL . '' . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sid);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true);
}

$edMozley = (int)$c->query("SELECT id FROM tenants WHERE name LIKE 'Ed Mozley%' LIMIT 1")->fetchColumn();
$dream    = (int)$c->query("SELECT id FROM tenants WHERE name LIKE 'Dream Holidays%' LIMIT 1")->fetchColumn();

// A restricted analyst who can ONLY see Dream Holidays.
$c->prepare("DELETE FROM analysts WHERE username = 'zz-restricted'")->execute();
$c->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules, created_datetime)
             VALUES ('zz-restricted', ?, 'ZZ Restricted', 'zz@restricted.test', 1, 0, 0, 1, UTC_TIMESTAMP())")
  ->execute([password_hash('x', PASSWORD_DEFAULT)]);
$rid = (int)$c->lastInsertId();
$c->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$rid, $dream]);
echo "restricted analyst #$rid can see ONLY Dream Holidays (#$dream)\n";

// Articles in each company.
$mk = function (string $title, ?int $tenant) use ($c, $rid) {
    $st = $c->prepare("INSERT INTO knowledge_articles (title, body, author_id, is_published, is_archived, created_datetime, modified_datetime, tenant_id, audience, next_review_date)
                       VALUES (?, '<p>x</p>', 1, 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, 'internal', '2020-01-01')");
    $st->execute([$title, $tenant]);
    return (int)$c->lastInsertId();
};
$aEd     = $mk('ZZ-R-EDMOZLEY', $edMozley);
$aDream  = $mk('ZZ-R-DREAM',    $dream);
$aShared = $mk('ZZ-R-SHARED',   null);
echo "articles: edMozley=#$aEd dream=#$aDream shared=#$aShared\n\n";

// Forge a session for the restricted analyst, active company = Dream.
$sid = 'zzread' . random_int(1000, 9999);
file_put_contents(SESS_DIR . "/sess_$sid",
    'analyst_id|i:' . $rid . ';analyst_username|s:13:"zz-restricted";analyst_name|s:13:"ZZ Restricted";'
  . 'analyst_email|s:18:"zz@restricted.test";active_tenant_id|i:' . $dream . ';');

echo "=== article list ===\n";
$d = hit('api/knowledge/knowledge_articles.php', $sid);
$titles = array_column($d['articles'] ?? [], 'title');
$zz = array_values(array_filter($titles, function ($t) { return strpos($t, 'ZZ-R-') === 0; }));
check('sees its own company + shared', in_array('ZZ-R-DREAM', $zz) && in_array('ZZ-R-SHARED', $zz), implode(',', $zz));
check('does NOT see the other company', !in_array('ZZ-R-EDMOZLEY', $zz), 'LEAK: ' . implode(',', $zz));

echo "\n=== single article by id (guessing the id) ===\n";
$d = hit('api/knowledge/knowledge_article.php?id=' . $aDream, $sid);
check('own article opens', !empty($d['success']), json_encode($d));
$d = hit('api/knowledge/knowledge_article.php?id=' . $aEd, $sid);
check('another company\'s article is refused', empty($d['success']), 'LEAK: ' . json_encode($d));
$d = hit('api/knowledge/knowledge_article.php?id=' . $aShared, $sid);
check('a shared article opens', !empty($d['success']), json_encode($d));

echo "\n=== review list (was un-gated) ===\n";
$d = hit('api/knowledge/get_review_list.php', $sid);
$titles = array_column($d['articles'] ?? [], 'title');
$zz = array_values(array_filter($titles, function ($t) { return strpos($t, 'ZZ-R-') === 0; }));
check('review list is scoped', !in_array('ZZ-R-EDMOZLEY', $zz), 'LEAK: ' . implode(',', $zz));

echo "\n=== embedding stats / backfill list ===\n";
$d = hit('api/knowledge/get_embedding_stats.php', $sid);
check('stats respond', isset($d['total']) || isset($d['success']), json_encode($d));
$d = hit('api/knowledge/get_articles_for_embedding.php', $sid);
$titles = array_column($d['articles'] ?? [], 'title');
$zz = array_values(array_filter($titles, function ($t) { return strpos($t, 'ZZ-R-') === 0; }));
check('backfill list is scoped', !in_array('ZZ-R-EDMOZLEY', $zz), 'LEAK: ' . implode(',', $zz));

echo "\n=== watchtower knowledge card ===\n";
$d = hit('api/watchtower/get_dashboard.php', $sid);
$recent = $d['knowledge']['recent'] ?? $d['knowledge']['recent_articles'] ?? [];
$titles = array_column($recent, 'title');
check('watchtower recent articles are scoped', !in_array('ZZ-R-EDMOZLEY', $titles), 'LEAK: ' . implode(',', $titles));

// tidy
@unlink(SESS_DIR . "/sess_$sid");
foreach ([$aEd, $aDream, $aShared] as $id) $c->prepare("DELETE FROM knowledge_articles WHERE id = ?")->execute([$id]);
$c->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$rid]);
$c->prepare("DELETE FROM analysts WHERE id = ?")->execute([$rid]);
echo "\ncleaned up\n";

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
