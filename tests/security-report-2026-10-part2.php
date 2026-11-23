<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The fixes for the SECOND private security report, October 2026 (items 7-24 and
 * additions to 1-4), each proved against the real code. Part 1 is
 * tests/security-report-2026-10.php.
 *
 *   php tests/security-report-2026-10-part2.php
 *
 * Runs against the real database. Everything it makes is named ZZ (analysts, a
 * ticket, a Knowledge folder and article, an API key, a form submission, feed
 * tokens) and is removed in the finally block; the one setting-like row it can
 * touch - this machine's row in ip_login_bans - is snapshotted and put back.
 *
 * 🔴 Deliberately NOT done here, because each would act on real data: creating a
 * ticket (the workflows that email requesters fire on ticket.created), saving a
 * Knowledge article (it can call an AI provider for the embedding), and sending
 * mail. Those paths are proved by their refusals, plus a positive control that
 * the same request is otherwise reached.
 *
 * The HTTP checks need the app at http://localhost/<folder>/ (or FREEITSM_URL) and
 * are skipped, with a note, when it does not answer.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/portal_visibility.php';
require_once __DIR__ . '/../includes/password_policy.php';
require_once __DIR__ . '/../includes/db_errors.php';
require_once __DIR__ . '/../includes/webhook_delivery.php';
require_once __DIR__ . '/../includes/catalogue_approvals.php';
require_once __DIR__ . '/../includes/services/knowledge.php';
require_once __DIR__ . '/../includes/search/search.php';
require_once __DIR__ . '/../includes/search/indexer.php';
require_once __DIR__ . '/../includes/watchtower_settings.php';

$pass = 0; $fail = 0; $skip = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
function skip(string $what, string $why): void { global $skip; $skip++; echo "  SKIP  {$what}  ({$why})\n"; }
function src(string $rel): string { return (string)file_get_contents(__DIR__ . '/../' . $rel); }

$conn = connectToDatabase();
$tag  = 'zz' . bin2hex(random_bytes(3));
$made = ['analysts' => [], 'tickets' => [], 'tasks' => [], 'folders' => [], 'articles' => [], 'acl' => [],
         'keys' => [], 'submissions' => [], 'prefs' => [], 'users' => [], 'domains' => [], 'sessions' => [], 'search' => []];
$base = rtrim(getenv('FREEITSM_URL') ?: 'http://localhost/' . basename(dirname(__DIR__)) . '/', '/') . '/';
$sessDir = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', '/\\');

function forge(array $vals): string {
    global $sessDir, $made;
    $sid = 'zzsec' . bin2hex(random_bytes(5));
    $body = '';
    foreach ($vals as $k => $v) $body .= $k . '|' . serialize($v);
    file_put_contents("$sessDir/sess_$sid", $body);
    $made['sessions'][] = $sid;
    return $sid;
}
function http(string $method, string $path, ?string $sid = null, $body = null, array $headers = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
          CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers];
    if ($sid !== null) $o[CURLOPT_COOKIE] = 'PHPSESSID=' . $sid;
    if ($body !== null) $o[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $raw === false ? '' : (string)$raw];
}
$online = (function () use ($base) {
    $ch = curl_init($base . 'auth/login.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_NOBODY => true]);
    curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $c > 0 && $c < 500;
})();
$mkAnalyst = function (string $suffix, array $cols = []) use ($conn, $tag, &$made): int {
    $c = array_merge(['is_active' => 1, 'is_admin' => 0, 'can_access_all_tenants' => 1, 'can_access_all_modules' => 1], $cols);
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules, created_datetime)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
         ->execute(["$tag-$suffix", $c['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
                    "ZZ $suffix", "$tag-$suffix@zz.test", $c['is_active'], $c['is_admin'], $c['can_access_all_tenants'], $c['can_access_all_modules']]);
    return $made['analysts'][] = (int)$conn->lastInsertId();
};
$multi = isMultiTenant($conn);
$ipSnap = null;

echo "\nThe second October 2026 security report - every fix, against the real code\n" . str_repeat('=', 72) . "\n";
echo $online ? "(HTTP checks against $base)\n" : "(HTTP checks SKIPPED: nothing answers at $base)\n";

try {
    // ------------------------------------------------------------------ 7
    echo "\n7. D001 (the demo import) cannot run from a link\n";
    ok('D001 calls csrfRequireWriteMethod(), and runs as POST from the tool page',
       str_contains(src('api/system/debug-tools/D001_demo_core_import.php'), 'csrfRequireWriteMethod();')
       && (bool)preg_match("/'file'\s*=>\s*'D001_demo_core_import\.php'.*?'method'\s*=>\s*'POST'/s", src('system/debug-tools/includes/tools.php')));

    // ------------------------------------------------------------------ 8
    echo "\n8. Knowledge writes check the folder and the article, not just the company\n";
    $denied = $mkAnalyst('kbdenied');
    $owner  = $mkAnalyst('kbowner');
    $conn->prepare("INSERT INTO knowledge_folders (parent_id, name, is_restricted, inherit_permissions, created_datetime) VALUES (NULL, ?, 0, 0, UTC_TIMESTAMP())")
         ->execute(["ZZ $tag folder"]);
    $folder = $made['folders'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO knowledge_articles (title, body, author_id, is_published, is_archived, created_datetime, modified_datetime, tenant_id, audience, folder_id, is_restricted, inherit_permissions)
                    VALUES (?, '<p>ZZ secret</p>', ?, 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, 'internal', ?, 0, 1)")
         ->execute(["ZZ-$tag article", $owner, $folder]);
    $article = $made['articles'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO knowledge_acl (object_type, object_id, principal_type, principal_id, created_datetime) VALUES ('folder', ?, 'analyst', ?, UTC_TIMESTAMP())")
         ->execute([$folder, $denied]);
    $made['acl'][] = (int)$conn->lastInsertId();
    knowledgeAclResetCaches();
    $ctxDenied = new ActorContext($denied, null, 'ui', 'en', 'ZZ denied');
    $code = function (callable $fn): string { try { $fn(); return ''; } catch (ServiceError $e) { return $e->errorCode; } catch (Throwable $e) { return 'threw: ' . $e->getMessage(); } };
    ok('setup: the folder denies the analyst', !knowledgeCanRead($conn, KnowledgeViewer::forAnalyst($conn, $denied), $article, ['lifecycle' => 'any']));
    ok('POSITIVE CONTROL: and only them', knowledgeCanRead($conn, KnowledgeViewer::forAnalyst($conn, $owner), $article, ['lifecycle' => 'any']));
    ok('they cannot EDIT it by id', $code(fn() => KnowledgeService::saveArticle($conn, $ctxDenied, ['id' => $article, 'title' => 'ZZ hijacked', 'body_html' => '<p>x</p>'])) === 'not_found');
    ok('...archive it', $code(fn() => KnowledgeService::archiveArticle($conn, $ctxDenied, $article)) === 'not_found');
    ok('...restore it', $code(fn() => KnowledgeService::restoreArticle($conn, $ctxDenied, $article)) === 'not_found');
    ok('...or purge it', $code(fn() => KnowledgeService::purgeArticle($conn, $ctxDenied, $article)) === 'not_found');
    $t = $conn->prepare("SELECT title, is_archived FROM knowledge_articles WHERE id = ?"); $t->execute([$article]); $row = $t->fetch(PDO::FETCH_ASSOC);
    ok('...and the article is untouched', $row && $row['title'] === "ZZ-$tag article" && (int)$row['is_archived'] === 0, json_encode($row));
    ok('they cannot file a NEW article into the folder they cannot read',
       $code(fn() => KnowledgeService::saveArticle($conn, $ctxDenied, ['title' => "ZZ-$tag filed", 'body_html' => '<p>x</p>', 'folder_id' => $folder])) === 'invalid_field');
    $n = $conn->prepare("SELECT COUNT(*) FROM knowledge_articles WHERE title = ?"); $n->execute(["ZZ-$tag filed"]);
    ok('...and nothing was created', (int)$n->fetchColumn() === 0);
    ok('POSITIVE CONTROL: the owner may archive it', $code(fn() => KnowledgeService::archiveArticle($conn, new ActorContext($owner, null, 'ui', 'en', 'ZZ owner'), $article)) === '');
    ok('the REST create, update and restore hand back the article only through the key\'s read check',
       substr_count(src('api/v1/resources/knowledge.php'), "apiLoadArticle(\$conn, \$res['id'], \$apiKey)") === 2
       && str_contains(src('api/v1/resources/knowledge.php'), "apiLoadArticle(\$conn, \$params[0], \$apiKey), true)"));

    // ------------------------------------------------------------------ 9
    echo "\n9. Knowledge article bodies are cleaned before they reach the page\n";
    $kjs = src('assets/js/knowledge.js');
    ok('knowledge.js puts no raw body into the page', !preg_match('/innerHTML\s*=\s*(article|currentArticle)\.body|\$\{currentArticle\.body\}/', $kjs));
    ok('...it uses kbSafeBody() at all three places', substr_count($kjs, 'kbSafeBody(') >= 4);
    $kidx = src('knowledge/index.php');
    ok('knowledge/index.php loads safe-html.js before knowledge.js',
       ($a = strpos($kidx, 'safe-html.js')) !== false && ($b = strpos($kidx, 'knowledge.js?v=')) !== false && $a < $b);
    $chrome = null;
    foreach (['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'] as $c) {
        if (is_file($c)) { $chrome = $c; break; }
    }
    if ($chrome) {
        // The cleaner itself, in a real browser.
        $dir = sys_get_temp_dir() . '/zzsafehtml' . bin2hex(random_bytes(3));
        @mkdir($dir);
        // Inlined: a file:// page may not load a file:// script.
        file_put_contents("$dir/p.html", '<!doctype html><meta charset="utf-8"><script>' . file_get_contents(__DIR__ . '/../assets/js/safe-html.js') . '</script><pre id="out"></pre><script>
var r = {};
var a = safeHtmlFragment(\'<img src=x onerror="alert(1)"><p>ok</p><iframe src="https://www.youtube.com/embed/x" onload="alert(2)" width="560"></iframe>\', {allowEmbeds: true});
r.handler = /onerror|onload|alert/.test(a);
r.embedKept = /<iframe[^>]+src="https:\/\/www\.youtube\.com\/embed\/x"/.test(a);
r.embedSandboxed = /sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"/.test(a);
var b = safeHtmlFragment(\'<iframe data-safe-embed="1" src="https://evil.example/x"></iframe><iframe src="javascript:alert(3)"></iframe><iframe srcdoc="<script>alert(4)<\/script>"></iframe>\', {allowEmbeds: true});
r.badEmbeds = (b.match(/<iframe/g) || []).length;   /* only the https one may survive */
var c = safeHtmlFragment(\'<iframe data-safe-embed="1" src="https://evil.example/x"></iframe>\');
r.noOptIn = /<iframe/.test(c);
document.getElementById("out").textContent = "RESULT" + JSON.stringify(r) + "END";
</script>');
        $out = shell_exec('"' . $chrome . '" --headless=old --disable-gpu --user-data-dir="' . $dir . '/ud" --allow-file-access-from-files --dump-dom "file:///' . str_replace('\\', '/', $dir) . '/p.html" 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
        $res = preg_match('/RESULT(\{.*?\})END/', (string)$out, $m) ? json_decode(html_entity_decode($m[1]), true) : null;
        ok('the browser ran the cleaner (POSITIVE CONTROL for the lines below)', is_array($res), substr((string)$out, 0, 120));
        if (is_array($res)) {
            ok('an <img onerror> loses its handler', $res['handler'] === false);
            ok('a video embed survives as an https iframe', $res['embedKept'] === true);
            ok('...rebuilt with a sandbox', $res['embedSandboxed'] === true);
            ok('a forged "safe" marker, a javascript: frame and a srcdoc frame do not get through (only the https one)', $res['badEmbeds'] === 1, (string)$res['badEmbeds']);
            ok('without the opt-in, no iframe survives - every other caller is unchanged', $res['noOptIn'] === false);
            // A file:// page has no real origin, so this one is read from the code.
            ok('an embed of FreeITSM\'s OWN site is refused (allow-scripts + allow-same-origin would let it lift its sandbox)',
               str_contains(src('assets/js/safe-html.js'), "new URL(src).origin === window.location.origin"));
        }
        @array_map('unlink', glob("$dir/*.*") ?: []);
    } else {
        skip('the cleaner in a real browser', 'no Chrome found');
    }

    // ------------------------------------------------------------------ 10
    echo "\n10. Module access is read now, not from the copy taken at sign-in\n";
    $readers = [];
    foreach (array_merge(glob(__DIR__ . '/../api/*/*.php'), glob(__DIR__ . '/../api/*/*/*.php'), glob(__DIR__ . '/../includes/*.php'), glob(__DIR__ . '/../includes/*/*.php')) as $f) {
        foreach (preg_split('/\R/', (string)file_get_contents($f)) as $i => $line) {
            $t = ltrim($line);
            if ($t === '' || $t[0] === '*' || str_starts_with($t, '//')) continue;
            if (preg_match('/\$_SESSION\[\'allowed_modules\'\](?!\s*=[^=])/', $line)) $readers[] = basename($f) . ':' . ($i + 1);
        }
    }
    ok('nothing under api/ or includes/ reads $_SESSION[\'allowed_modules\'] (it is only written)', !$readers, implode(', ', $readers));
    $restricted = $mkAnalyst('mods', ['can_access_all_modules' => 0]);
    $_SESSION = ['analyst_id' => $restricted, 'allowed_modules' => null];   // a stale "every module"
    ok('analystAllowedModulesNow() ignores a stale session copy', analystAllowedModulesNow() === modulesWithoutSwitchedOff($conn, getAnalystAllowedModules($conn, $restricted)) && analystAllowedModulesNow() !== null);   // less any switched-off module, GH #217
    $_SESSION = [];
    ok('...and nobody signed in may use nothing', analystAllowedModulesNow() === []);

    // ------------------------------------------------------------------ 11
    echo "\n11. A deactivated analyst's feeds and Watchtower key stop working\n";
    if ($online) {
        $feedActive = $mkAnalyst('feedon');
        $feedGone   = $mkAnalyst('feedoff', ['is_active' => 0]);
        $tokens = [];
        foreach ([$feedActive, $feedGone] as $aid) {
            $tok = bin2hex(random_bytes(20));
            $conn->prepare("INSERT INTO user_preferences (analyst_id, preference_key, preference_value) VALUES (?, 'calendar_feed_token', ?)")->execute([$aid, $tok]);
            $made['prefs'][] = (int)$conn->lastInsertId();
            $tokens[$aid] = $tok;
        }
        [$c1] = http('GET', 'api/calendar/feed.php?token=' . $tokens[$feedGone]);
        [$c2, $b2] = http('GET', 'api/calendar/feed.php?token=' . $tokens[$feedActive]);
        ok('a deactivated analyst\'s calendar feed is refused (403)', $c1 === 403, "HTTP $c1");
        ok('POSITIVE CONTROL: an active analyst\'s feed is served', $c2 === 200 && str_contains($b2, 'BEGIN:VCALENDAR'), "HTTP $c2 " . substr($b2, 0, 80));
        $k = bin2hex(random_bytes(16));
        $conn->prepare("INSERT INTO apikeys (apikey, analyst_id, label) VALUES (?, ?, ?)")->execute([$k, $feedGone, "ZZ $tag"]);
        $made['keys'][] = (int)$conn->lastInsertId();
        $k2 = bin2hex(random_bytes(16));
        $conn->prepare("INSERT INTO apikeys (apikey, analyst_id, label) VALUES (?, NULL, ?)")->execute([$k2, "ZZ $tag agent"]);
        $made['keys'][] = (int)$conn->lastInsertId();
        $k3 = bin2hex(random_bytes(16));
        $conn->prepare("INSERT INTO apikeys (apikey, analyst_id, label) VALUES (?, ?, ?)")->execute([$k3, $feedActive, "ZZ $tag active"]);
        $made['keys'][] = (int)$conn->lastInsertId();
        [$c, $b] = http('GET', 'api/watchtower/get_dashboard_ext.php', null, null, ['Authorization: ' . $k3]);
        ok('POSITIVE CONTROL: an active analyst\'s Watchtower key is served', $c === 200 && str_contains($b, '"success":true'), "HTTP $c " . substr($b, 0, 100));
        foreach (['a deactivated analyst\'s key' => $k, 'a key with no analyst (an inventory agent\'s)' => $k2] as $what => $key) {
            [$c, $b] = http('GET', 'api/watchtower/get_dashboard_ext.php', null, null, ['Authorization: ' . $key, 'X-Api-Key: ' . $key]);
            ok("Watchtower refuses $what", $c === 403 || str_contains($b, 'Invalid authorization key'), "HTTP $c " . substr($b, 0, 100));
        }
    } else {
        skip('the feed and Watchtower checks', 'no web server');
    }
    ok('the schedule feed checks its owner too', str_contains(src('api/tickets/schedule_feed.php'), "analystCanAccessModule(\$conn, \$analystId, 'tickets')"));

    // ------------------------------------------------------------------ 12
    echo "\n12. A ticket moved to another company leaves the old company's search, and takes its tasks\n";
    $tenants = array_map('intval', $conn->query("SELECT id FROM tenants ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
    if ($multi && count($tenants) === 2 && searchCorpusReady($conn)) {
        [$tA, $tB] = $tenants;
        $word = 'zzq' . bin2hex(random_bytes(4));
        $conn->prepare("INSERT INTO tickets (ticket_number, subject, tenant_id, created_datetime, updated_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute(["ZZ-$tag-move", "ZZ $word subject", $tA]);
        $tk = $made['tickets'][] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO emails (subject, from_address, received_datetime, body_preview, body_content, ticket_id, direction, is_initial) VALUES ('ZZ', 'zz@zz.test', UTC_TIMESTAMP(), ?, ?, ?, 'Inbound', 1)")
             ->execute(["ZZ $word body", "<p>ZZ $word body</p>", $tk]);
        $conn->prepare("INSERT INTO tasks (title, ticket_id, tenant_id, created_by_id, created_datetime) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")->execute(["ZZ-$tag task", $tk, $tA, $owner]);
        $task = $made['tasks'][] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO tasks (title, parent_task_id, tenant_id, created_by_id, created_datetime) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")->execute(["ZZ-$tag subtask", $task, $tA, $owner]);
        $sub = $made['tasks'][] = (int)$conn->lastInsertId();
        searchIndexTicket($conn, $tk);
        $found = function (int $tenant) use ($conn, $word, $tk): bool {
            $r = searchCorpusQuery($conn, $word, ['tenant_id' => $tenant, 'include_default' => false, 'include_internal' => true]);
            return in_array($tk, array_column($r['results'] ?? [], 'ticket_id'), true);
        };
        ok("POSITIVE CONTROL: company $tA finds the ticket before the move", $found($tA));
        // A bulk path that forgets the follow-up - the triage sweeps' shape.
        $conn->prepare("UPDATE tickets SET tenant_id = ? WHERE id = ?")->execute([$tB, $tk]);
        ok("after a raw move, company $tA no longer finds it (search judges the live company)", !$found($tA));
        ticketsFollowCompanyMove($conn, [$tk], $tB);
        ok("after the follow-up, company $tB finds it", $found($tB));
        $tt = $conn->prepare("SELECT tenant_id FROM tasks WHERE id = ?");
        $tt->execute([$task]); $v1 = (int)$tt->fetchColumn();
        $tt->execute([$sub]);  $v2 = (int)$tt->fetchColumn();
        ok('its task and the task\'s subtask moved with it', $v1 === $tB && $v2 === $tB, "$v1 / $v2");
        ok('the move button, PATCH and triage routing all call it',
           str_contains(src('api/tickets/move_ticket_to_company.php'), 'ticketsFollowCompanyMove(')
           && str_contains(src('includes/services/tickets.php'), 'ticketsFollowCompanyMove(')
           && str_contains(src('api/tickets/resolve_triage.php'), 'ticketsFollowCompanyMove('));
    } else {
        skip('the search and task move checks', 'needs two companies and the search corpus');
    }

    // ------------------------------------------------------------------ 13
    echo "\n13. Workflow tickets get a company, and never step over an approval\n";
    $eng = src('workflow/includes/engine.php');
    ok('"Create a ticket" stores a company and numbers the ticket in it',
       str_contains($eng, 'user_id, tenant_id, created_datetime') && str_contains($eng, 'TicketNumbering::next($conn, $typeId ? (int)$typeId : null, $ticketTenant)'));
    $formId = (int)$conn->query("SELECT MIN(id) FROM forms")->fetchColumn();
    if ($formId) {
        foreach (['pending', 'rejected'] as $st) {
            $conn->prepare("INSERT INTO form_submissions (form_id, approval_status, submitted_date) VALUES (?, ?, UTC_TIMESTAMP())")->execute([$formId, $st]);
            $sid = $made['submissions'][] = (int)$conn->lastInsertId();
            $msg = '';
            try { catalogueRaiseTicketFromSubmissionId($conn, $sid); } catch (Exception $e) { $msg = $e->getMessage(); }
            ok("a $st catalogue request raises no ticket", $msg !== '' && stripos($msg, $st === 'pending' ? 'awaiting approval' : 'rejected') !== false, $msg);
            $x = $conn->prepare("SELECT ticket_id FROM form_submissions WHERE id = ?"); $x->execute([$sid]);
            ok('...and none was attached to it', $x->fetchColumn() === null);
        }
    } else {
        skip('the approval checks', 'no forms on this install');
    }

    // ------------------------------------------------------------------ 14
    echo "\n14. A new password cannot be the published default\n";
    ok('"freeitsm" is refused, in any case', passwordPolicyError('freeitsm') !== null && passwordPolicyError('FreeITSM') !== null);
    ok('the demo data\'s "demo1234" is refused', passwordPolicyError('demo1234') !== null);
    ok('shorter than 8 is refused', passwordPolicyError('a1b2c3') !== null);
    $h = password_hash('correct horse battery', PASSWORD_DEFAULT);
    ok('the current password again is refused', passwordPolicyError('correct horse battery', $h) !== null);
    ok('POSITIVE CONTROL: an ordinary new password is accepted', passwordPolicyError('a decent new passphrase', $h) === null);
    $setters = ['api/myaccount/change_password.php', 'api/auth/reset_password.php', 'api/tickets/reset_analyst_password.php', 'api/tickets/save_analyst.php',
                'api/self-service/change_password.php', 'api/self-service/register.php', 'api/self-service/reset_password.php', 'api/tickets/save_user.php'];
    $missing = array_filter($setters, fn($f) => !str_contains(src($f), 'passwordPolicyError('));
    ok('every place that sets a password uses the rule', !$missing, implode(', ', $missing));
    $cp = src('api/myaccount/change_password.php');
    ok('"must be different" is only checked AFTER the current password is proved (no guessing oracle)',
       strpos($cp, "passwordPolicyError(\$newPassword, \$analyst['password_hash'])") > strpos($cp, "password_verify(\$currentPassword"));

    // ------------------------------------------------------------------ 15
    echo "\n15. Portal privacy matches the requester's address exactly\n";
    $msg = fn(string $from, string $to, string $cc = '') => ['channel' => 'email', 'direction' => 'Inbound', 'from_address' => $from, 'to_recipients' => $to, 'cc_recipients' => $cc];
    ok('bob@acme.com is NOT part of mail to jimbob@acme.com', !portalEmailInvolvesRequester($msg('supplier@x.test', 'jimbob@acme.com'), 'bob@acme.com'));
    ok('...nor bob@acme.com.au', !portalEmailInvolvesRequester($msg('bob@acme.com.au', 'desk@x.test'), 'bob@acme.com'));
    ok('POSITIVE CONTROL: bob@acme.com in a "; " list is', portalEmailInvolvesRequester($msg('desk@x.test', 'alice@acme.com; Bob@Acme.com'), 'bob@acme.com'));
    ok('...and as "Bob <bob@acme.com>"', portalEmailInvolvesRequester($msg('desk@x.test', 'x@y.test', 'Bob Smith <bob@acme.com>'), 'bob@acme.com'));
    ok('...and in the older JSON shape', portalEmailInvolvesRequester($msg('desk@x.test', '[{"emailAddress":{"address":"bob@acme.com"}}]'), 'bob@acme.com'));

    // ------------------------------------------------------------------ 16
    echo "\n16. Errors before sign-in do not show database text\n";
    ok('a database error becomes the generic message', dbSafeClientMessage(new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'x'"), 'generic') === 'generic');
    ok('POSITIVE CONTROL: an identity provider\'s own message still shows', dbSafeClientMessage(new Exception('AADSTS50011: redirect URI mismatch'), 'generic') === 'AADSTS50011: redirect URI mismatch');
    ok('config.php no longer turns display_errors on', !preg_match("/ini_set\('display_errors',\s*1\)/", src('config.php')) && str_contains(src('config.php'), "FREEITSM_DISPLAY_ERRORS"));

    // ------------------------------------------------------------------ 17
    echo "\n17. Portal recordings go through includes/uploads.php\n";
    ok('upload_recording.php stores through uploadStoreFile()', str_contains(src('api/self-service/upload_recording.php'), 'uploadStoreFile(')
       && !str_contains(src('api/self-service/upload_recording.php'), "strpos(\$first, 'ftyp')"));
    $portalUser = (int)$conn->query("SELECT id FROM users WHERE COALESCE(is_active, 1) = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($online && $portalUser) {
        $tok = bin2hex(random_bytes(32));
        $sid = forge(['ss_user_id' => $portalUser, 'csrf_token' => $tok]);
        $tmp = tempnam(sys_get_temp_dir(), 'zzrec');
        file_put_contents($tmp, 'this is not a video at all');
        $before = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM ticket_recordings")->fetchColumn();
        [$c, $b] = http('POST', 'api/self-service/upload_recording.php', $sid,
                        ['file' => new CURLFile($tmp, 'video/mp4', 'screen-recording-zz.mp4')], ['X-CSRF-Token: ' . $tok]);
        ok('a text file DECLARED as video/mp4 is refused (it used to be accepted unchecked)', str_contains($b, '"success":false'), "HTTP $c $b");
        $after = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM ticket_recordings")->fetchColumn();
        ok('...and nothing was stored', $after === $before);
        if ($after > $before) $conn->exec("DELETE FROM ticket_recordings WHERE id > $before AND ticket_id IS NULL");
        @unlink($tmp);
    } else {
        skip('the recording upload check', $online ? 'no portal user' : 'no web server');
    }

    // ------------------------------------------------------------------ 18
    echo "\n18. The login lockout holds whatever PHP's time zone\n";
    if ($online) {
        $ipSnap = $conn->query("SELECT * FROM ip_login_bans WHERE ip_address IN ('127.0.0.1', '::1')")->fetchAll(PDO::FETCH_ASSOC);
        $locked = $mkAnalyst('locked', ['password_hash' => password_hash('ZZ right password 1', PASSWORD_DEFAULT)]);
        $conn->prepare("UPDATE analysts SET failed_login_count = 5, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) WHERE id = ?")->execute([$locked]);
        [$c, $b] = http('POST', 'auth/login.php', null, http_build_query(['username' => "$tag-locked", 'password' => 'ZZ wrong password']),
                        ['Content-Type: application/x-www-form-urlencoded']);
        ok('an account locked for 30 minutes says it is locked (in summer time it used to unlock at once)', stripos($b, 'Account locked') !== false, "HTTP $c " . substr(strip_tags($b), 0, 100));
        $l = $conn->prepare("SELECT locked_until IS NOT NULL FROM analysts WHERE id = ?"); $l->execute([$locked]);
        ok('...and the lock is still on the account', (int)$l->fetchColumn() === 1);
    } else {
        skip('the lockout check', 'no web server');
    }
    ok('every lockout / expiry parse in sign-in reads the database time as UTC',
       substr_count(src('auth/login.php'), ", new DateTimeZone('UTC'))") >= 3 + 3
       && str_contains(src('api/auth/reset_password.php'), "\$resetToken['expires_datetime'], new DateTimeZone('UTC')"));

    // ------------------------------------------------------------------ 19
    echo "\n19. Ticket history cannot be written freely\n";
    if ($online) {
        $statusRow = $conn->query("SELECT id, name FROM ticket_statuses WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $conn->prepare("INSERT INTO tickets (ticket_number, subject, status_id, created_datetime, updated_datetime) VALUES (?, 'ZZ audit', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute(["ZZ-$tag-audit", $statusRow['id']]);
        $at = $made['tickets'][] = (int)$conn->lastInsertId();
        $adminId = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $tok = bin2hex(random_bytes(32));
        $sid = forge(['analyst_id' => $adminId, 'analyst_name' => 'ZZ test', 'csrf_token' => $tok]);
        $post = fn(array $b) => http('POST', 'api/tickets/log_ticket_audit.php', $sid, json_encode(['ticket_id' => $at] + $b), ['Content-Type: application/json', 'X-CSRF-Token: ' . $tok]);
        [, $b1] = $post(['field_name' => 'Ticket Created', 'old_value' => null, 'new_value' => 'forged']);
        ok('an invented field is refused', str_contains($b1, '"success":false'), $b1);
        [, $b2] = $post(['field_name' => 'Status', 'old_value' => $statusRow['name'], 'new_value' => 'ZZ On Hold']);
        ok('a Status row that does not match the ticket is refused (no fake SLA pause)', str_contains($b2, '"success":false'), $b2);
        [, $b3] = $post(['field_name' => 'Status', 'old_value' => 'x', 'new_value' => $statusRow['name']]);
        ok('POSITIVE CONTROL: a Status row matching the real status is recorded', str_contains($b3, '"success":true'), $b3);
        [, $b4] = $post(['field_name' => 'Status', 'old_value' => 'x', 'new_value' => $statusRow['name']]);
        ok('...once - the same transition again is refused', str_contains($b4, '"success":false'), $b4);
        [, $b5] = $post(['field_name' => 'Priority', 'old_value' => 'Low', 'new_value' => 'High']);
        ok('POSITIVE CONTROL: an ordinary field the inbox logs is recorded', str_contains($b5, '"success":true'), $b5);
    } else {
        skip('the history checks', 'no web server');
    }

    // ------------------------------------------------------------------ 20
    echo "\n20. Webhook URLs and secrets are encrypted on every save route\n";
    if (webhookEncryptionAvailable()) {
        $enc = webhookEncryptActions([['type' => 'send_webhook', 'args' => ['url' => 'https://hooks.example/zz', 'secret' => 's3']], ['type' => 'add_note', 'args' => ['url' => 'leave me']]]);
        ok('the url and secret come back encrypted', str_starts_with($enc[0]['args']['url'], 'ENC:') && str_starts_with($enc[0]['args']['secret'], 'ENC:'));
        ok('...other actions are untouched', $enc[1]['args']['url'] === 'leave me');
        ok('...encrypting again changes nothing (a GET-then-PATCH round trip is safe)', webhookEncryptActions($enc) === $enc);
        ok('POSITIVE CONTROL: and they decrypt back', webhookDecryptActions($enc)[0]['args']['url'] === 'https://hooks.example/zz');
    } else {
        skip('the encryption round trip', 'no encryption key on this install');
    }
    ok('the browser save, REST create and update, and form action lists all use it',
       str_contains(src('api/workflow/save.php'), 'webhookEncryptActions(')
       && substr_count(src('api/v1/resources/workflows.php'), 'webhookEncryptActions(') === 2
       && str_contains(src('includes/services/forms.php'), 'webhookEncryptActions('));

    // ------------------------------------------------------------------ 21
    echo "\n21. Every debug tool is administrators-only\n";
    $ungated = array_map('basename', array_filter(glob(__DIR__ . '/../api/system/debug-tools/D*.php'), fn($f) => !str_contains((string)file_get_contents($f), '$__dbgAdmin')));
    ok('no debug tool lacks the administrator gate', !$ungated, implode(', ', $ungated));
    if ($online) {
        [$c, $b] = http('GET', 'api/system/debug-tools/D005_endpoint_permissions.php', forge([]));
        ok('D005 refuses someone signed out', $c === 403 && str_contains($b, 'Administrator access required'), "HTTP $c " . substr($b, 0, 80));
    }

    // ------------------------------------------------------------------ 22
    echo "\n22. The sign-in pages refuse a post from another website\n";
    if ($online) {
        $form = http_build_query(['username' => "$tag-nobody", 'password' => 'ZZ whatever 1']);
        [$c, $b] = http('POST', 'auth/login.php', null, $form, ['Content-Type: application/x-www-form-urlencoded', 'Sec-Fetch-Site: cross-site', 'Origin: https://evil.example']);
        ok('a cross-site login post, with no cookie at all, is refused', $c === 403 && stripos($b, 'another website') !== false, "HTTP $c");
        [$c, $b] = http('POST', 'auth/login.php', null, $form, ['Content-Type: application/x-www-form-urlencoded', 'Sec-Fetch-Site: same-origin']);
        ok('POSITIVE CONTROL: the same post from the login page itself is answered', $c !== 403 && stripos($b, 'Invalid username or password') !== false, "HTTP $c");
        [$c, $b] = http('POST', 'api/self-service/login.php', null, json_encode(['email' => "$tag@zz.test", 'password' => 'x']), ['Content-Type: application/json', 'Sec-Fetch-Site: cross-site']);
        ok('...and the portal sign-in', $c === 403, "HTTP $c");
    } else {
        skip('the login CSRF checks', 'no web server');
    }

    // ------------------------------------------------------------------ 23
    echo "\n23. An analyst with no company sees nothing, not the Default company\n";
    if ($multi) {
        $nobody = $mkAnalyst('nocompany', ['can_access_all_tenants' => 0]);
        $_SESSION = [];
        ok('setup: they can reach no company', getAccessibleTenantIds($conn, $nobody) === []);
        ok('ticket lists show them nothing', ticketTenantFilter($conn, $nobody, 't')[0] === ' AND 1 = 0');
        ok('...nor any other company-scoped list', activeTenantFilter($conn, $nobody, 'x')[0] === ' AND 1 = 0');
        ok('...Knowledge shows shared articles only', knowledgeTenantFilter($conn, $nobody, 'a')[0] === ' AND a.tenant_id IS NULL');
        ok('...search shows shared entries only', searchScopeForAnalyst($conn, $nobody)['tenant_id'] === -1);
        $everyone = $mkAnalyst('allcompanies');
        ok('POSITIVE CONTROL: an all-companies analyst is not filtered to nothing', ticketTenantFilter($conn, $everyone, 't')[0] !== ' AND 1 = 0');
    } else {
        skip('the no-company checks', 'single-company install');
    }

    // ------------------------------------------------------------------ 24
    echo "\n24. Watchtower counts follow the company; a failed scope lookup fails closed\n";
    if ($multi) {
        $wtAnalyst = $mkAnalyst('wt');
        ok('the company clause is the same one ticket lists use', wtCompanyClause($conn, $wtAnalyst, 't') === ticketTenantFilter($conn, $wtAnalyst, 't'));
        ok('...and every ticket, change, task and asset count carries it', substr_count(src('includes/watchtower_queries.php'), 'wtCompanyClause(') >= 6);
    }
    ok('a scope lookup that throws no longer means "all companies"', str_contains(src('includes/service_context.php'), "tenancyDegradeAllowed(\$e)) ? null : []"));

    // ------------------------------------------------------------------ 4b
    echo "\n4b. A company-limited key cannot name, or create, another company's requester when raising a ticket\n";
    if ($online && $multi && count($tenants) === 2) {
        [$tA, $tB] = $tenants;
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, created_at) VALUES (?, 'ZZ other company', ?, UTC_TIMESTAMP())")->execute(["$tag-other@zz.test", $tB]);
        $otherUser = $made['users'][] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO tenant_domains (tenant_id, domain) VALUES (?, ?)")->execute([$tB, "$tag-b.test"]);
        $made['domains'][] = (int)$conn->lastInsertId();
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $adminId = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $conn->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute(["ZZ $tag", substr($key, 0, 12), hash('sha256', $key), $adminId, json_encode(['tickets' => ['read', 'create']]), json_encode([$tA])]);
        $apiKeyId = (int)$conn->lastInsertId();
        $api = fn(array $b) => http('POST', 'api/v1/index.php?path=/tickets', null, json_encode($b), ['Content-Type: application/json', 'Authorization: Bearer ' . $key]);
        $ticketsBefore = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM tickets")->fetchColumn();
        [$c, $b] = $api(['subject' => 'ZZ must not exist', 'requester_email' => "$tag-x@zz.test", 'user_id' => $otherUser]);
        ok("naming company $tB's requester by id is refused", $c === 422 && str_contains($b, 'no longer exists'), "HTTP $c $b");
        [$c, $b] = $api(['subject' => 'ZZ must not exist', 'requester_email' => "$tag-new@$tag-b.test"]);
        ok("an address on company $tB's domain is refused before the requester is created", $c === 403, "HTTP $c $b");
        $n = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?"); $n->execute(["$tag-new@$tag-b.test"]);
        ok('...and no requester was created', (int)$n->fetchColumn() === 0);
        [$c, $b] = $api(['requester_email' => "$tag-y@zz.test"]);
        ok('POSITIVE CONTROL: the key is otherwise reaching the endpoint (a missing subject is its own 422)', $c === 422 && str_contains($b, 'subject'), "HTTP $c $b");
        ok('...and no ticket was created by any of it', (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM tickets")->fetchColumn() === $ticketsBefore);
        $conn->prepare("DELETE FROM api_keys WHERE id = ? AND name LIKE 'ZZ %'")->execute([$apiKeyId]);
    } else {
        skip('the REST ticket checks', 'needs the web server and two companies');
    }
    ok('the analyst New ticket form checks the company a new requester would land in', str_contains(src('api/tickets/create_ticket.php'), 'resolveTenantForNewUser($conn, $fromEmail)'));
} finally {
    $_SESSION = [];
    foreach ($made['acl'] as $id) $conn->prepare("DELETE FROM knowledge_acl WHERE id = ?")->execute([$id]);
    foreach ($made['articles'] as $id) $conn->prepare("DELETE FROM knowledge_articles WHERE id = ? AND title LIKE 'ZZ-%'")->execute([$id]);
    $conn->prepare("DELETE FROM knowledge_articles WHERE title LIKE ?")->execute(["ZZ-$tag%"]);
    foreach ($made['folders'] as $id) $conn->prepare("DELETE FROM knowledge_folders WHERE id = ? AND name LIKE 'ZZ %'")->execute([$id]);
    try { $conn->prepare("DELETE FROM knowledge_audit WHERE article_id IN (" . implode(',', array_map('intval', $made['articles'] ?: [0])) . ")")->execute(); } catch (Throwable $e) {}
    foreach ($made['tasks'] as $id) $conn->prepare("DELETE FROM tasks WHERE id = ? AND title LIKE 'ZZ-%'")->execute([$id]);
    foreach ($made['tickets'] as $id) {
        $conn->prepare("DELETE FROM search_documents WHERE ticket_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM emails WHERE ticket_id = ? AND subject = 'ZZ'")->execute([$id]);
        $conn->prepare("DELETE FROM ticket_audit WHERE ticket_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM tickets WHERE id = ? AND ticket_number LIKE 'ZZ-%'")->execute([$id]);
    }
    foreach ($made['submissions'] as $id) $conn->prepare("DELETE FROM form_submissions WHERE id = ?")->execute([$id]);
    foreach ($made['keys'] as $id) {
        try { $conn->prepare("DELETE FROM api_rate_limits WHERE apikey_id = ?")->execute([$id]); } catch (Throwable $e) {}
        $conn->prepare("DELETE FROM apikeys WHERE id = ? AND label LIKE 'ZZ %'")->execute([$id]);
    }
    foreach ($made['prefs'] as $id) $conn->prepare("DELETE FROM user_preferences WHERE id = ?")->execute([$id]);
    foreach ($made['users'] as $id) $conn->prepare("DELETE FROM users WHERE id = ? AND display_name LIKE 'ZZ%'")->execute([$id]);
    foreach ($made['domains'] as $id) $conn->prepare("DELETE FROM tenant_domains WHERE id = ? AND domain LIKE 'zz%'")->execute([$id]);
    foreach ($made['analysts'] as $id) {
        $conn->prepare("DELETE FROM system_logs WHERE analyst_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM analysts WHERE id = ? AND username LIKE 'zz%'")->execute([$id]);
    }
    if ($ipSnap !== null) {
        // This machine's login-ban row, exactly as it was.
        $conn->exec("DELETE FROM ip_login_bans WHERE ip_address IN ('127.0.0.1', '::1')");
        foreach ($ipSnap as $r) {
            $cols = array_keys($r);
            $conn->prepare("INSERT INTO ip_login_bans (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($r));
        }
    }
    foreach ($made['sessions'] as $sid) @unlink("$sessDir/sess_$sid");
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM analysts WHERE username LIKE '$tag-%')
        + (SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE 'ZZ-$tag-%')
        + (SELECT COUNT(*) FROM tasks WHERE title LIKE 'ZZ-$tag %')
        + (SELECT COUNT(*) FROM knowledge_articles WHERE title LIKE 'ZZ-$tag%')
        + (SELECT COUNT(*) FROM knowledge_folders WHERE name = 'ZZ $tag folder')
        + (SELECT COUNT(*) FROM users WHERE email LIKE '$tag%')
        + (SELECT COUNT(*) FROM apikeys WHERE label LIKE 'ZZ $tag%')
        + (SELECT COUNT(*) FROM api_keys WHERE name = 'ZZ $tag')")->fetchColumn();
    echo "\nCleanup: " . ($left === 0 ? 'everything this run made is gone' : "$left ROWS LEFT BEHIND - tag $tag") . "\n";
}

echo "\n" . str_repeat('=', 72) . "\n$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail ? 1 : 0);
