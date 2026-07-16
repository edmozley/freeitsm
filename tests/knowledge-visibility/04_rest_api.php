<?php
/**
 * REST API v1 knowledge: a key scoped to one company must not see another's
 * articles — but MUST still see the shared ones. That second half is the trap:
 * the generic apiKeyTenantFilter treats NULL as "the Default company's", which
 * would hide every shared article from a non-Default key.
 */
$APP = dirname(__DIR__, 2);
require_once __DIR__ . '/_bootstrap.php';
require_once $APP . '/config.php';
require_once $APP . '/includes/functions.php';

$c = connectToDatabase();
$pass = 0; $fail = 0;
function check(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS  %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n        -> %s\n", $label, $detail); }
}
function api(string $path, string $key) {
    $ch = curl_init(BASE_TEST_URL . 'api/v1/' . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $key]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($r, true)];
}

$ed    = (int)$c->query("SELECT id FROM tenants WHERE name LIKE 'Ed Mozley%' LIMIT 1")->fetchColumn();
$dream = (int)$c->query("SELECT id FROM tenants WHERE name LIKE 'Dream Holidays%' LIMIT 1")->fetchColumn();

// An analyst restricted to Dream Holidays, and a key owned by them.
$c->exec("DELETE FROM api_keys WHERE name = 'zz-rest-test'");
$c->exec("DELETE FROM analysts WHERE username = 'zz-restkey'");
$c->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules, created_datetime)
             VALUES ('zz-restkey', ?, 'ZZ RestKey', 'zz@restkey.test', 1, 0, 0, 1, UTC_TIMESTAMP())")
  ->execute([password_hash('x', PASSWORD_DEFAULT)]);
$rid = (int)$c->lastInsertId();
$c->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$rid, $dream]);

// REST v1 uses api_keys (underscore), stores a sha256 of the raw key, and takes
// its company scope from the key's OWN company_ids — not from the analyst.
$key = 'zzrest' . bin2hex(random_bytes(16));
$c->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, active, created_datetime)
             VALUES ('zz-rest-test', 'zztest', ?, ?, ?, ?, 1, UTC_TIMESTAMP())")
  ->execute([hash('sha256', $key), $rid,
             // knowledge_versions is a SEPARATE resource from knowledge — grant both,
             // or the versions check below fails on permissions rather than on scope.
             json_encode(['knowledge' => ['read', 'create', 'update'], 'knowledge_versions' => ['read']]),
             json_encode([$dream])]);
echo "key owned by analyst #$rid, company_ids scoped to Dream Holidays (#$dream)\n";

$mk = function (string $title, ?int $tenant, string $aud) use ($c) {
    $st = $c->prepare("INSERT INTO knowledge_articles (title, body, author_id, is_published, is_archived, created_datetime, modified_datetime, tenant_id, audience)
                       VALUES (?, '<p>x</p>', 1, 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)");
    $st->execute([$title, $tenant, $aud]);
    return (int)$c->lastInsertId();
};
$aEd     = $mk('ZZ-API-EDMOZLEY', $ed,    'internal');
$aDream  = $mk('ZZ-API-DREAM',    $dream, 'internal');
$aShared = $mk('ZZ-API-SHARED',   null,   'public');
echo "articles: ed=#$aEd dream=#$aDream shared=#$aShared\n\n";

echo "=== GET /knowledge/articles ===\n";
[$code, $d] = api('knowledge/articles?per_page=100', $key);
check('200', $code === 200, "HTTP $code " . json_encode($d));
$titles = array_column($d['data'] ?? [], 'title');
$zz = array_values(array_filter($titles, function ($t) { return strpos($t, 'ZZ-API-') === 0; }));
check('sees its own company', in_array('ZZ-API-DREAM', $zz), implode(',', $zz));
check('sees SHARED articles (the NULL trap)', in_array('ZZ-API-SHARED', $zz),
      'shared vanished — apiKeyTenantFilter semantics leaked in: ' . implode(',', $zz));
check('does NOT see the other company', !in_array('ZZ-API-EDMOZLEY', $zz), 'LEAK: ' . implode(',', $zz));

echo "\n=== new fields in the response ===\n";
$one = null;
foreach (($d['data'] ?? []) as $r) if ($r['title'] === 'ZZ-API-DREAM') $one = $r;
check('company is exposed', $one && isset($one['company']) && (int)$one['company']['id'] === $dream, json_encode($one['company'] ?? null));
check('audience is exposed', $one && ($one['audience'] ?? '') === 'internal', json_encode($one['audience'] ?? null));
foreach (($d['data'] ?? []) as $r) if ($r['title'] === 'ZZ-API-SHARED') {
    check('a shared article reports company = null', $r['company'] === null, json_encode($r['company']));
}

echo "\n=== GET /knowledge/articles/{id} ===\n";
[$code, $d] = api('knowledge/articles/' . $aDream, $key);
check('own article: 200', $code === 200, "HTTP $code");
[$code, $d] = api('knowledge/articles/' . $aEd, $key);
check('other company\'s article: 404 (not 403 — no probing)', $code === 404, "HTTP $code " . json_encode($d));
[$code, $d] = api('knowledge/articles/' . $aShared, $key);
check('shared article: 200', $code === 200, "HTTP $code");

echo "\n=== versions of another company's article ===\n";
[$code, $d] = api('knowledge/articles/' . $aEd . '/versions', $key);
check('refused', $code === 404, "HTTP $code");

echo "\n=== ?company= and ?audience= filters ===\n";
[$code, $d] = api('knowledge/articles?company=shared&per_page=100', $key);
$titles = array_column($d['data'] ?? [], 'title');
check('?company=shared returns only shared', in_array('ZZ-API-SHARED', $titles) && !in_array('ZZ-API-DREAM', $titles), implode(',', array_filter($titles, function($t){return strpos($t,'ZZ-API-')===0;})));
[$code, $d] = api('knowledge/articles?company=' . $ed, $key);
check('?company=<one I cannot access> is 403', $code === 403, "HTTP $code " . json_encode($d));
[$code, $d] = api('knowledge/articles?audience=nonsense', $key);
check('?audience=nonsense is 400', $code === 400, "HTTP $code");

// tidy
foreach ([$aEd, $aDream, $aShared] as $id) $c->prepare("DELETE FROM knowledge_articles WHERE id = ?")->execute([$id]);
$c->exec("DELETE FROM api_keys WHERE name = 'zz-rest-test'");
$c->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$rid]);
$c->prepare("DELETE FROM analysts WHERE id = ?")->execute([$rid]);
echo "\ncleaned up\n";
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
