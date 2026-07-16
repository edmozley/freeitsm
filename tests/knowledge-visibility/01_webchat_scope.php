<?php
/**
 * The web chat KB leak: can one company's anonymous website visitor be answered
 * out of another company's knowledge base?
 */
$APP = dirname(__DIR__, 2);
require_once __DIR__ . '/_bootstrap.php';
require_once $APP . '/config.php';
require_once $APP . '/includes/functions.php';
require_once $APP . '/includes/knowledge/kb_ai.php';

$c = connectToDatabase();
$pass = 0; $fail = 0;
function check(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS  %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n        -> %s\n", $label, $detail); }
}
function ids(array $r): array {
    return array_map(function ($a) { return (int)$a['id']; }, $r['articles']);
}

// Two companies to play with.
$edMozley = (int)$c->query("SELECT id FROM tenants WHERE name LIKE 'Ed Mozley%' LIMIT 1")->fetchColumn();
$dream    = (int)$c->query("SELECT id FROM tenants WHERE name LIKE 'Dream Holidays%' LIMIT 1")->fetchColumn();
echo "companies: Ed Mozley Ltd=#$edMozley  Dream Holidays=#$dream\n\n";

// Borrow a real embedding so all three test articles are found by the vector path.
$src = $c->query("SELECT embedding FROM knowledge_articles WHERE embedding IS NOT NULL AND LENGTH(embedding) > 0 LIMIT 1")->fetchColumn();

$mk = function (string $title, string $body, ?int $tenant, string $audience) use ($c, $src) {
    $st = $c->prepare("INSERT INTO knowledge_articles (title, body, is_published, is_archived, created_datetime, embedding, embedding_updated, tenant_id, audience)
                       VALUES (?,?,1,0,UTC_TIMESTAMP(),?,UTC_TIMESTAMP(),?,?)");
    $st->execute([$title, $body, $src, $tenant, $audience]);
    return (int)$c->lastInsertId();
};

$secret = $mk('ZZ-EDMOZLEY-VPN',   'The Ed Mozley Ltd VPN shared key is hunter2.', $edMozley, 'public');
$theirs = $mk('ZZ-DREAM-BOOKING',  'How to change your Dream Holidays booking.',   $dream,    'public');
$shared = $mk('ZZ-SHARED-RESET',   'How to reset your password: click Forgot.',    null,      'public');
$intern = $mk('ZZ-DREAM-INTERNAL', 'Dream Holidays admin console password is s3cr3t.', $dream, 'internal');
echo "seeded: edMozley-public=#$secret  dream-public=#$theirs  shared=#$shared  dream-INTERNAL=#$intern\n\n";

// --- What a Dream Holidays website visitor may be told ---
echo "=== anonymous visitor on the DREAM HOLIDAYS widget ===\n";
$r = kbRetrieveArticles($c, 'ZZ', 50, $dream, Audience::PUBLIC);
$got = ids($r);
check('CANNOT see Ed Mozley Ltd\'s article', !in_array($secret, $got, true), 'LEAK: got #' . $secret);
check('CAN see Dream Holidays\' own public article', in_array($theirs, $got, true));
check('CAN see the shared (tenant NULL) article', in_array($shared, $got, true));
check('CANNOT see Dream Holidays\' own INTERNAL article', !in_array($intern, $got, true),
      'LEAK: internal article served to the public');
$ctx = kbBuildContext($r['articles']);
check('"hunter2" is not in the AI prompt', strpos($ctx, 'hunter2') === false);
check('"s3cr3t" is not in the AI prompt', strpos($ctx, 's3cr3t') === false);

// --- The mirror image ---
echo "\n=== anonymous visitor on the ED MOZLEY widget ===\n";
$r = kbRetrieveArticles($c, 'ZZ', 50, $edMozley, Audience::PUBLIC);
$got = ids($r);
check('CANNOT see Dream Holidays\' article', !in_array($theirs, $got, true), 'LEAK: got #' . $theirs);
check('CAN see its own article', in_array($secret, $got, true));
check('CAN see the shared article', in_array($shared, $got, true));

// --- A widget with no company ---
echo "\n=== widget with NO company (tenantId = null) ===\n";
$r = kbRetrieveArticles($c, 'ZZ', 50, null, Audience::PUBLIC);
$got = ids($r);
check('sees ONLY shared articles', in_array($shared, $got, true) && !in_array($secret, $got, true) && !in_array($theirs, $got, true));

// --- The audience ladder, holding the company constant ---
// NOTE: tenantId=null means "no company context => shared only", so an analyst read
// must still name the company it is looking at. A helper that scopes to "every
// company this analyst can access" is step 6, not built yet.
echo "\n=== Dream Holidays company, read at ANALYST level ===\n";
$r = kbRetrieveArticles($c, 'ZZ', 50, $dream, Audience::INTERNAL);
$got = ids($r);
check('the SAME internal article IS visible at analyst level', in_array($intern, $got, true),
      'the audience ladder is not letting analysts through');
check('  (and its public sibling too)', in_array($theirs, $got, true));

echo "\n=== Dream Holidays company, read at CUSTOMER level ===\n";
$r = kbRetrieveArticles($c, 'ZZ', 50, $dream, Audience::CUSTOMER);
$got = ids($r);
check('a signed-in customer does NOT see the internal article', !in_array($intern, $got, true));
check('but DOES see the public one', in_array($theirs, $got, true));

// --- The default must be the safe one ---
echo "\n=== a caller that forgets to pass scope ===\n";
$r = kbRetrieveArticles($c, 'ZZ', 50);
$got = ids($r);
check('defaults to PUBLIC + shared-only, never everything',
      !in_array($intern, $got, true) && !in_array($secret, $got, true) && !in_array($theirs, $got, true),
      'careless default leaked: ' . implode(',', $got));

foreach ([$secret, $theirs, $shared, $intern] as $id) {
    $c->prepare("DELETE FROM knowledge_articles WHERE id = ?")->execute([$id]);
}
echo "\ncleaned up\n";
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
