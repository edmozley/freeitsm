<?php
/** KnowledgeService write path: company + audience validation. */
$APP = dirname(__DIR__, 2);
require_once __DIR__ . '/_bootstrap.php';
require_once $APP . '/config.php';
require_once $APP . '/includes/functions.php';
require_once $APP . '/includes/services/knowledge.php';

$c = connectToDatabase();
$pass = 0; $fail = 0;
function check(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS  %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n        -> %s\n", $label, $detail); }
}
function row(PDO $c, int $id): array {
    $s = $c->prepare("SELECT tenant_id, audience FROM knowledge_articles WHERE id = ?");
    $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC);
}

$dream = (int)$c->query("SELECT id FROM tenants WHERE name LIKE 'Dream Holidays%' LIMIT 1")->fetchColumn();

// An all-access actor (companyScope null = every company).
$all = new ActorContext(actorId: 1, companyScope: null, source: 'ui', locale: 'en', actorName: 'Admin');
// An actor restricted to ONE company that is NOT Dream Holidays.
$restricted = new ActorContext(actorId: 1, companyScope: [1], source: 'ui', locale: 'en', actorName: 'Limited');

$made = [];

echo "=== defaults ===\n";
$r = KnowledgeService::saveArticle($c, $all, ['title' => 'ZZ-DEFAULTS', 'body_html' => 'x']);
$made[] = $r['id']; $got = row($c, $r['id']);
check('a new article defaults to shared (tenant NULL)', $got['tenant_id'] === null, var_export($got['tenant_id'], true));
check('a new article defaults to internal', $got['audience'] === 'internal', $got['audience']);

echo "\n=== setting both ===\n";
$r = KnowledgeService::saveArticle($c, $all, ['title' => 'ZZ-SET', 'body_html' => 'x', 'tenant_id' => $dream, 'audience' => 'public']);
$made[] = $r['id']; $got = row($c, $r['id']);
check('company stored', (int)$got['tenant_id'] === $dream, var_export($got['tenant_id'], true));
check('audience stored', $got['audience'] === 'public', $got['audience']);

echo "\n=== update ===\n";
KnowledgeService::saveArticle($c, $all, ['id' => $made[1], 'audience' => 'customer']);
$got = row($c, $made[1]);
check('audience updated', $got['audience'] === 'customer', $got['audience']);
check('company left alone when not sent', (int)$got['tenant_id'] === $dream, var_export($got['tenant_id'], true));

KnowledgeService::saveArticle($c, $all, ['id' => $made[1], 'tenant_id' => '']);
$got = row($c, $made[1]);
check('empty company => back to shared', $got['tenant_id'] === null, var_export($got['tenant_id'], true));

echo "\n=== a partial save must NOT silently reset visibility ===\n";
KnowledgeService::saveArticle($c, $all, ['id' => $made[1], 'tenant_id' => $dream, 'audience' => 'public']);
KnowledgeService::saveArticle($c, $all, ['id' => $made[1], 'title' => 'ZZ-SET-RENAMED']);   // no visibility keys
$got = row($c, $made[1]);
check('title-only update keeps the company', (int)$got['tenant_id'] === $dream, var_export($got['tenant_id'], true));
check('title-only update keeps the audience', $got['audience'] === 'public', $got['audience']);

echo "\n=== validation ===\n";
try {
    KnowledgeService::saveArticle($c, $all, ['title' => 'ZZ-BAD', 'audience' => 'everyone']);
    check('a bogus audience is rejected', false, 'it was accepted');
} catch (ServiceError $e) {
    check('a bogus audience is rejected', $e->kind === 'validation', $e->kind . ': ' . $e->getMessage());
}
try {
    KnowledgeService::saveArticle($c, $all, ['title' => 'ZZ-BAD2', 'tenant_id' => 99999]);
    check('an unknown company is rejected', false, 'it was accepted');
} catch (ServiceError $e) {
    check('an unknown company is rejected', $e->kind === 'validation', $e->kind);
}
try {
    KnowledgeService::saveArticle($c, $restricted, ['title' => 'ZZ-BAD3', 'tenant_id' => $dream]);
    check('filing against a company you cannot access is rejected', false, 'IT WAS ACCEPTED');
} catch (ServiceError $e) {
    check('filing against a company you cannot access is rejected', $e->kind === 'forbidden', $e->kind);
}

// Tidy: catch anything that slipped through validation too.
foreach ($made as $id) $c->prepare("DELETE FROM knowledge_articles WHERE id = ?")->execute([$id]);
$c->exec("DELETE FROM knowledge_articles WHERE title LIKE 'ZZ-%'");

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
