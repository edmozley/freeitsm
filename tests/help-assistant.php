<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The help assistant, step 3 (wiki Help-Assistant-Design): System -> Help
 * assistant, the window's endpoints, and the reply.
 *
 * Part 1, no database: which kind of reply a set of results gets (the canned line
 * is chosen by it - none / weak / clear / module / several, at the thresholds set
 * from the question set), and that every kind has wording in English, German and
 * Spanish.
 *
 * Part 2, over HTTP against a throwaway install (FREEITSM_TEST_URL +
 * FREEITSM_TEST_DATABASE, sessions in FREEITSM_SESS_DIR, default C:/wamp64/tmp),
 * with a ZZ administrator and a ZZ analyst made for the run and removed after:
 * - an ordinary analyst cannot read or change the settings
 * - off by default; the window refuses an analyst who does not have it, and the
 *   header draws them no button
 * - saving switches it on for exactly who is ticked; the header then has the button
 * - asking: a canned line and results (title, page, preview, link), a right answer
 *   among them; nothing found gives the "none" line; an empty question is refused
 * - the conversation: history oldest first, Reset empties it
 * - Rebuild runs in the background and finishes on its own (about 25 seconds)
 * The switch and every analyst's flag are put back in finally.
 *
 *   php tests/help-assistant.php
 *   FREEITSM_TEST_URL=http://localhost/<install> FREEITSM_TEST_DATABASE=<its db> php tests/help-assistant.php
 *
 * @wiki Help-Assistant-Design
 */
chdir(__DIR__ . '/..');
require_once 'includes/help_assistant.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

// ─── Part 1 ─────────────────────────────────────────────────────────────────
echo "Which reply a set of results gets\n";
$r = fn(float ...$scores) => array_map(fn($s) => ['score' => $s, 'module' => 'system'], $scores);
check('nothing found -> none', helpAssistantReplyKind([]) === 'none');
check('a weak best match -> weak, however far ahead', helpAssistantReplyKind($r(8.5, 2.0)) === 'weak');
check('one clear winner (at least 1.25x the runner-up) -> clear', helpAssistantReplyKind($r(20.0, 15.0)) === 'clear');
check('...or the only result -> clear', helpAssistantReplyKind($r(12.0)) === 'clear');
check('several close, the best from the module the asker is in -> module', helpAssistantReplyKind($r(20.0, 18.0), 'system') === 'module');
check('several close, from elsewhere -> several', helpAssistantReplyKind($r(20.0, 18.0), 'tickets') === 'several');

echo "\nThe canned lines\n";
foreach (['en', 'de', 'es'] as $L) {
    I18n::setLocale($L);
    $missing = [];
    foreach (['none', 'weak', 'clear', 'module', 'several'] as $kind) {
        $text = helpAssistantPhrase($kind);
        if ($text === '' || str_starts_with($text, 'help_assistant.')) $missing[] = $kind;
    }
    check("every kind of reply has wording in $L", !$missing, implode(', ', $missing));
}
I18n::setLocale('de');
check('a German asker gets a German line', in_array(helpAssistantPhrase('clear'), ['Das sieht genau nach dem aus, was Sie brauchen:', 'Gute Nachricht - die Hilfe deckt das ab:', 'Ich glaube, das ist es:'], true));
I18n::setLocale('en');
$seen = [];
for ($i = 0; $i < 60; $i++) $seen[helpAssistantPhrase('several')] = true;
check('the lines vary - all four "several" lines turn up', count($seen) === 4, (string)count($seen));
$en = require 'lang/en/help_assistant.php';
$claims = array_filter(array_merge(...array_values(array_map('array_values', $en['phrase']))), fn($l) => preg_match('/\b(I think the answer|I understand|I read)\b/i', $l));
check('no line claims an AI read anything', !$claims, implode(' | ', $claims));

// ─── Part 2 ─────────────────────────────────────────────────────────────────
$url = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
$expected = getenv('FREEITSM_TEST_DATABASE');
if ($url === '' || !$expected) {
    echo "\n  (skipped the HTTP checks: set FREEITSM_TEST_URL and FREEITSM_TEST_DATABASE)\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/entity_links.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    echo "\n  (skipped the HTTP checks: config.php does not use $expected)\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
if (!helpAssistantReady($conn)) { check('the tables exist - run Database Verify first', false); exit(1); }
$sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp'), '/');

$tag = 'zzha' . bin2hex(random_bytes(3));
$made = [];
$sessions = [];
$oldEnabled = helpAssistantSetting($conn, 'help_assistant_enabled');
$oldFlags = $conn->query("SELECT id, help_assistant FROM analysts")->fetchAll(PDO::FETCH_KEY_PAIR);
$oldState = helpAssistantSetting($conn, 'help_index_build_state');
// Written answers (step 5) off for this run: these checks are about search, and an
// AI answering would change them - and spend the install's credit. Put back in finally.
$oldAsk = helpAssistantSetting($conn, 'help_ask_enabled');
helpAssistantSetSetting($conn, 'help_ask_enabled', '0');

/** A ZZ analyst and a forged session for them. */
$analyst = function (bool $admin) use ($conn, $tag, $sessDir, &$made, &$sessions): array {
    $u = $tag . ($admin ? 'admin' : 'user');
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', ?, ?, 1, ?, 1, 1)")
         ->execute([$u, "ZZ $u", "$u@example.invalid", $admin ? 1 : 0]);
    $id = $made[] = (int)$conn->lastInsertId();
    $sid = $tag . ($admin ? 'a' : 'u');
    file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $id . ';analyst_username|s:' . strlen($u) . ':"' . $u . '";analyst_name|s:2:"ZZ";csrf_token|s:1:"x";');
    $sessions[] = "$sessDir/sess_$sid";
    return ['id' => $id, 'sid' => $sid];
};
$call = function (array $who, string $path, ?array $post = null) use ($url): array {
    $ch = curl_init("$url/$path");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x', 'Cookie: PHPSESSID=' . $who['sid']]];
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($post); }
    curl_setopt_array($ch, $o);
    $body = (string)curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $body, json_decode($body, true)];
};

try {
    // Everything this run records (unanswered questions, their bell rows) is after these - for finally.
    $unansweredMark = [(int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM help_assistant_unanswered")->fetchColumn(),
                       (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM notifications")->fetchColumn()];
    $admin = $analyst(true);
    $user = $analyst(false);
    $conn->exec("UPDATE analysts SET help_assistant = 0");
    helpAssistantSetSetting($conn, 'help_assistant_enabled', '0');

    echo "\nSystem -> Help assistant\n";
    [$code] = $call($user, 'api/system/help_assistant.php');
    check('an ordinary analyst cannot read the settings', $code === 403 || $code === 401, (string)$code);
    [$code] = $call($user, 'api/system/help_assistant.php', ['action' => 'save', 'enabled' => true, 'analysts' => [$user['id']]]);
    check('...or change them', ($code === 403 || $code === 401) && !helpAssistantEnabled($conn), (string)$code);
    [$code, , $j] = $call($admin, 'api/system/help_assistant.php');
    check('an administrator reads them: off, and the index reported', $code === 200 && ($j['enabled'] ?? null) === false && isset($j['index']['entries']), substr(json_encode($j), 0, 160));
    [$code, $page] = $call($admin, 'system/help-assistant/');
    check('the page renders, opening with "works without any AI"', $code === 200 && str_contains($page, 'id="haEnabled"') && str_contains($page, 'works without any AI'), (string)$code);

    echo "\nOff until switched on, and only for who is ticked\n";
    [$code] = $call($user, 'api/help_assistant/ask.php', ['question' => 'create a department']);
    check('the window refuses an analyst who does not have it', $code === 403, (string)$code);
    [, $page] = $call($user, 'tickets/');
    check('...and the header draws them no button', $page !== '' && !str_contains($page, 'hlpaLaunch'));
    [$code, , $j] = $call($admin, 'api/system/help_assistant.php', ['action' => 'save', 'enabled' => true, 'analysts' => [$user['id']]]);
    $on = array_column(array_filter($j['analysts'] ?? [], fn($a) => $a['on']), 'id');
    check('saving switches it on, for exactly who is ticked', $code === 200 && ($j['enabled'] ?? false) === true && $on === [$user['id']], json_encode($on));
    [, $page] = $call($user, 'tickets/');
    check('the header now draws the button, with the page\'s module', str_contains($page, 'id="hlpaLaunch"') && str_contains($page, '"module":"tickets"'));
    [, $page] = $call($admin, 'tickets/');
    check('...but not for the administrator, who was not ticked', !str_contains($page, 'hlpaLaunch'));

    echo "\nAsking\n";
    [$code, , $j] = $call($user, 'api/help_assistant/ask.php', ['question' => 'how do I create a ticketing department', 'module' => 'tickets']);
    $res = $j['reply']['results'] ?? [];
    check('a canned line and results', $code === 200 && ($j['reply']['text'] ?? '') !== '' && count($res) > 0, substr(json_encode($j), 0, 160));
    check('...each with a title, its page, a preview and a link', $res && $res[0]['title'] !== '' && $res[0]['page_title'] !== '' && $res[0]['snippet'] !== '' && str_contains($res[0]['url'], 'help'), json_encode($res[0] ?? null));
    check('...a right answer among them', (bool)array_filter($res, fn($x) => str_contains($x['url'], 'tickets/help.php#settings') || str_contains($x['url'], 'teams.php#departments')));
    [, , $j] = $call($user, 'api/help_assistant/ask.php', ['question' => 'xyzzy plugh frobnicate']);
    check('nothing found: the "none" line and no results', ($j['reply']['kind'] ?? '') === 'none' && ($j['reply']['results'] ?? null) === [] && ($j['reply']['text'] ?? '') !== '');
    [$code] = $call($user, 'api/help_assistant/ask.php', ['question' => '  ']);
    check('an empty question is refused', $code === 400, (string)$code);
    [, , $j] = $call($user, 'api/help_assistant/ask.php', ['question' => 'reset an analyst password']);
    check('a System question finds nothing in System help for an analyst without System', !array_filter($j['reply']['results'] ?? [], fn($x) => $x['module'] === 'system'));

    echo "\nThe conversation\n";
    [, , $j] = $call($user, 'api/help_assistant/history.php');
    $roles = array_column($j['messages'] ?? [], 'role');
    check('history: every question and reply, oldest first', $roles === ['user', 'assistant', 'user', 'assistant', 'user', 'assistant'] && !empty($j['messages'][1]['results']), json_encode($roles));
    [, , $j] = $call($user, 'api/help_assistant/reset.php', []);
    check('Reset removes them', ($j['removed'] ?? null) === 6);
    [, , $j] = $call($user, 'api/help_assistant/history.php');
    check('...and history is empty', ($j['messages'] ?? null) === []);

    echo "\nUnanswered questions (step 6)\n";
    // The ZZ administrator was made for this run, so every help.unanswered row of
    // theirs is from it. The "nothing found" question asked above already rang it
    // once - so these check how the count MOVES (they coalesce into one row).
    $firstQ = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM help_assistant_unanswered")->fetchColumn();
    $call($admin, 'api/system/help_assistant.php', ['action' => 'save', 'enabled' => true, 'analysts' => [$user['id'], $admin['id']], 'named' => []]);
    $bell = function (int $analystId) use ($conn): array {
        $st = $conn->prepare("SELECT COUNT(*) n_rows, COALESCE(SUM(event_count), 0) n_events FROM notifications WHERE analyst_id = ? AND event_type = 'help.unanswered'");
        $st->execute([$analystId]);
        return array_map('intval', $st->fetch(PDO::FETCH_ASSOC));
    };
    $b0 = $bell($admin['id']);
    $call($user, 'api/help_assistant/ask.php', ['question' => 'xyzzy plugh frobnicate']);
    $row = $conn->query("SELECT * FROM help_assistant_unanswered WHERE id > $firstQ ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    check('a question nothing answers is kept, as asked', ($row['question'] ?? '') === 'xyzzy plugh frobnicate' && ($row['kind'] ?? '') === 'none', json_encode($row));
    check('...without the asker\'s name - nobody chose to record it', array_key_exists('analyst_id', $row) && $row['analyst_id'] === null);
    $b = $bell($admin['id']);
    check('the administrators\' bell rings, linked to the list', $b['n_rows'] === 1 && $b['n_events'] === $b0['n_events'] + 1 && entityLink('help_unanswered', 1) === 'system/help-assistant/#unanswered', json_encode([$b0, $b]));
    check('...and the asker\'s never does', $bell($user['id'])['n_events'] === 0);
    $call($user, 'api/help_assistant/ask.php', ['question' => 'qwzx vbnm plkj']);
    $b = $bell($admin['id']);
    check('another joins the same notification - one row, the count goes up', $b['n_rows'] === 1 && $b['n_events'] === $b0['n_events'] + 2, json_encode($b));
    $call($admin, 'api/help_assistant/ask.php', ['question' => 'zzxq wwvq jjkq']);
    check('an administrator who asks is never told about their own question', $bell($admin['id'])['n_events'] === $b['n_events']);
    $call($user, 'api/help_assistant/ask.php', ['question' => 'how do I create a ticketing department', 'module' => 'tickets']);
    check('a question the help answers is not kept', (int)$conn->query("SELECT COUNT(*) FROM help_assistant_unanswered WHERE id > $firstQ")->fetchColumn() === 3);
    $call($user, 'api/help_assistant/ask.php', ['question' => 'reset an analyst password']);
    check('...nor one the help answers in a module the asker cannot open - the help is not missing',
        (int)$conn->query("SELECT COUNT(*) FROM help_assistant_unanswered WHERE id > $firstQ")->fetchColumn() === 3);

    $call($admin, 'api/system/help_assistant.php', ['action' => 'save', 'enabled' => true, 'analysts' => [$user['id'], $admin['id']], 'named' => [$user['id']]]);
    [, $page] = $call($user, 'tickets/');
    check('Record who asked on: the analyst\'s window is told', str_contains($page, '"named":true'));
    [, $page] = $call($admin, 'tickets/');
    check('...and nobody else\'s is', str_contains($page, '"named":false'));
    $call($user, 'api/help_assistant/ask.php', ['question' => 'grblx frnkt zzapq']);
    $named = $conn->query("SELECT analyst_id FROM help_assistant_unanswered WHERE id > $firstQ ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('...and their name is kept with the question', (int)$named === $user['id']);

    [$code] = $call($user, 'api/system/help_assistant.php', ['action' => 'unanswered']);
    check('an ordinary analyst cannot read the list', $code === 403 || $code === 401, (string)$code);
    [, , $j] = $call($admin, 'api/system/help_assistant.php', ['action' => 'unanswered', 'status' => 'new']);
    $mine = array_values(array_filter($j['items'] ?? [], fn($i) => $i['id'] > $firstQ));
    check('administrators see them, newest first, with the name only where it was recorded',
        count($mine) === 4 && $mine[0]['asker'] === "ZZ {$tag}user" && $mine[1]['asker'] === null, json_encode(array_column($mine, 'asker')));
    $n = (int)$j['count'];
    [, , $j] = $call($admin, 'api/system/help_assistant.php', ['action' => 'mark', 'id' => $mine[0]['id'], 'dealt' => true]);
    check('marking one handled takes it off the waiting count', ($j['success'] ?? false) && (int)$j['count'] === $n - 1);
    [, , $j] = $call($admin, 'api/system/help_assistant.php', ['action' => 'unanswered', 'status' => 'done']);
    $done = array_values(array_filter($j['items'] ?? [], fn($i) => $i['id'] === $mine[0]['id']));
    check('...it is listed as handled, by whom', $done && $done[0]['dealt_by'] === "ZZ {$tag}admin");
    [, , $j] = $call($admin, 'api/system/help_assistant.php', ['action' => 'mark', 'id' => $mine[0]['id'], 'dealt' => false]);
    check('...and can be reopened', (int)$j['count'] === $n);
    require_once 'includes/services/notifications.php';
    check('Preferences offers the notification to administrators only',
        array_key_exists('help.unanswered', NotificationsService::effectivePreferences($conn, $admin['id']))
        && !array_key_exists('help.unanswered', NotificationsService::effectivePreferences($conn, $user['id'])));

    echo "\nRebuilding the index\n";
    $conn->exec("DELETE FROM system_settings WHERE setting_key = 'help_index_build_state'");
    [$code, , $j] = $call($admin, 'api/system/help_assistant.php', ['action' => 'rebuild']);
    check('Rebuild starts in the background', $code === 200 && !empty($j['rebuild_started']) && !empty($j['index']['running']));
    $t = time(); $last = null;
    while (time() - $t < 300) {
        sleep(4);
        [, , $j] = $call($admin, 'api/system/help_assistant.php');
        if (empty($j['index']['running'])) { $last = $j['index']['last'] ?? null; break; }
    }
    check('...and finishes on its own', ($last['state'] ?? '') === 'done', json_encode($last));
    printf("  (%ds)\n", time() - $t);
} catch (Throwable $e) {
    check('the HTTP checks ran: ' . $e->getMessage(), false);
} finally {
    foreach ($sessions as $f) @unlink($f);
    if (isset($unansweredMark)) {
        [$q0, $n0] = $unansweredMark;
        $conn->exec("DELETE FROM help_assistant_unanswered WHERE id > " . (int)$q0);
        $conn->exec("DELETE FROM notifications WHERE event_type = 'help.unanswered' AND id > " . (int)$n0);
    }
    foreach ($made as $id) {
        $conn->prepare("DELETE FROM help_assistant_messages WHERE analyst_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$id]);
    }
    if ($oldEnabled === null) $conn->exec("DELETE FROM system_settings WHERE setting_key = 'help_assistant_enabled'");
    else helpAssistantSetSetting($conn, 'help_assistant_enabled', $oldEnabled);
    $u = $conn->prepare("UPDATE analysts SET help_assistant = ? WHERE id = ?");
    foreach ($oldFlags as $id => $v) $u->execute([$v, $id]);
    if ($oldAsk === null) $conn->exec("DELETE FROM system_settings WHERE setting_key = 'help_ask_enabled'");
    else helpAssistantSetSetting($conn, 'help_ask_enabled', $oldAsk);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
