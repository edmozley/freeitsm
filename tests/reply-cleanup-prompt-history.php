<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The Reply Cleanup prompt: editable, with every version kept (GH #396).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> [FREEITSM_TEST_URL=<install url>] php tests/reply-cleanup-prompt-history.php
 *
 * Adds rows to ticket_reply_cleanup_prompts and removes exactly those (ids above the
 * highest one there before) in finally, so whatever prompt was in use is in use again.
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }
function throwsInvalid(callable $fn): bool { try { $fn(); return false; } catch (InvalidArgumentException $e) { return true; } }

$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/reply_cleanup_prompt.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
try { $conn->query("SELECT 1 FROM ticket_reply_cleanup_prompts LIMIT 0"); } catch (Throwable $e) { echo "(skipped: run Database Verification first)\n"; exit(0); }

$before = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM ticket_reply_cleanup_prompts")->fetchColumn();
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp'), '/');
$sids = [];
$textOf = function (int $id) use ($conn) { $s = $conn->prepare("SELECT prompt_text FROM ticket_reply_cleanup_prompts WHERE id = ?"); $s->execute([$id]); return $s->fetchColumn(); };

try {
    echo "Starting from the built-in prompt\n";
    replyCleanupSavePromptVersion($conn, null, $admin, 'reset');   // a known start, whatever was in use
    check('the built-in prompt is in use', replyCleanupActiveTemplate($conn) === null);
    check('and the cleanup is sent exactly the built-in prompt', buildReplyCleanupSystemPrompt('Sarah', 'Kind.', '', replyCleanupActiveTemplate($conn)) === buildReplyCleanupSystemPrompt('Sarah', 'Kind.'));

    echo "Editing\n";
    $mine = "ZZ test prompt. Greet {greeting_name}. Tone: {tone}.";
    $v1 = replyCleanupSavePromptVersion($conn, $mine, $admin, 'edit');
    check('a save makes a new version', $v1 > $before);
    check('and it is the one in use', replyCleanupActiveTemplate($conn) === $mine);
    check('the cleanup fills in the name and the tone', buildReplyCleanupSystemPrompt('Sarah', 'Kind.', '', replyCleanupActiveTemplate($conn)) === 'ZZ test prompt. Greet Sarah. Tone: Kind..');
    check('custom instructions still come after it', str_ends_with(buildReplyCleanupSystemPrompt('S', 'K', 'Sign off with Cheers', replyCleanupActiveTemplate($conn)), 'Sign off with Cheers'));
    check('saving the same text again adds nothing', replyCleanupSavePromptVersion($conn, $mine, $admin, 'edit') === 0);
    check('an empty prompt is refused', throwsInvalid(fn() => replyCleanupSavePromptVersion($conn, "  \n ", $admin, 'edit')));
    check('a prompt over the limit is refused', throwsInvalid(fn() => replyCleanupSavePromptVersion($conn, str_repeat('x', REPLY_CLEANUP_PROMPT_MAX + 1), $admin, 'edit')));
    $v2 = replyCleanupSavePromptVersion($conn, "ZZ broken prompt", $admin, 'edit');
    check('a second edit is a second version', $v2 > $v1 && replyCleanupActiveTemplate($conn) === 'ZZ broken prompt');

    echo "Going back\n";
    $v3 = replyCleanupSavePromptVersion($conn, (string)replyCleanupPromptVersionText($conn, $v1), $admin, 'restore', $v1);
    check('Restore makes a new version copying the old one', $v3 > $v2 && replyCleanupActiveTemplate($conn) === $mine);
    $vers = replyCleanupPromptVersions($conn);
    check('the history lists it first, as a restore of that version', $vers[0]['id'] === $v3 && $vers[0]['action'] === 'restore' && $vers[0]['restored_from'] === $v1);
    check('the broken version is still there, unchanged', $textOf($v2) === 'ZZ broken prompt');
    $v4 = replyCleanupSavePromptVersion($conn, null, $admin, 'reset');
    check('Reset brings back the built-in prompt', $v4 > $v3 && replyCleanupActiveTemplate($conn) === null);
    check('and is itself a version (text NULL)', $textOf($v4) === null && replyCleanupPromptVersionText($conn, $v4) === null);
    $v5 = replyCleanupSavePromptVersion($conn, replyCleanupDefaultTemplate(), $admin, 'edit');
    check('saving the built-in text unchanged adds nothing while it is in use', $v5 === 0);
    replyCleanupSavePromptVersion($conn, $mine, $admin, 'edit');
    $v6 = replyCleanupSavePromptVersion($conn, replyCleanupDefaultTemplate(), $admin, 'edit');
    check('saving the built-in text stores it as the default, so later improvements reach it', $v6 > 0 && $textOf($v6) === null);
    check('no version is ever changed: v1 still holds its text', $textOf($v1) === $mine);
    check('an unknown version is "none", not the default', replyCleanupPromptVersionText($conn, 999999999) === false);

    echo "The cleanup and the settings page use the version in use\n";
    check('ai_cleanup_reply.php passes it', str_contains((string)file_get_contents('api/tickets/ai_cleanup_reply.php'), 'replyCleanupActiveTemplate($conn)'));
    check('the settings preview passes it', str_contains((string)file_get_contents('api/tickets/get_reply_cleanup_settings.php'), 'replyCleanupActiveTemplate($conn)'));

    $base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
    if ($base) {
        echo "The endpoint\n";
        $sid = 'zzrcp' . bin2hex(random_bytes(4)); $sids[] = $sid;
        file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:4:"ZZRC";csrf_token|s:1:"x";');
        $call = function (string $method, string $q = '', ?array $body = null, bool $csrf = true) use ($base, $sid): array {
            $ch = curl_init("$base/api/tickets/reply_cleanup_prompt.php$q");
            $h = ['Cookie: PHPSESSID=' . $sid, 'Sec-Fetch-Site: same-origin', 'Content-Type: application/json'];
            if ($csrf) $h[] = 'X-CSRF-Token: x';
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $h];
            if ($method === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($body); }
            curl_setopt_array($ch, $o);
            $raw = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 200)]];
        };
        [$c, $d] = $call('GET');
        check('GET returns the prompt in use and the history', $c === 200 && ($d['success'] ?? false) && isset($d['versions'][0]) && $d['placeholders'] === ['{greeting_name}', '{tone}'], "$c");
        [$c, $d] = $call('POST', '', ['action' => 'save', 'text' => 'ZZ over HTTP {greeting_name} {tone}'], false);
        check('a save without the CSRF token is refused', $c >= 400 && !($d['success'] ?? false), "$c");
        [$c, $d] = $call('POST', '', ['action' => 'save', 'text' => 'ZZ over HTTP {greeting_name} {tone}']);
        check('a save with it makes a version', $c === 200 && ($d['changed'] ?? false) && replyCleanupActiveTemplate($conn) === 'ZZ over HTTP {greeting_name} {tone}', "$c " . json_encode($d));
        [$c, $d] = $call('POST', '', ['action' => 'restore', 'id' => $v2]);
        check('Restore over HTTP', $c === 200 && replyCleanupActiveTemplate($conn) === 'ZZ broken prompt', "$c");
        [$c, $d] = $call('GET', '?version=' . $v1);
        check('one version\'s text', $c === 200 && ($d['text'] ?? '') === $mine);
        [$c] = $call('POST', '', ['action' => 'restore', 'id' => 999999999]);
        check('restoring a version that does not exist is a 404', $c === 404, "$c");
        [$c] = $call('POST', '', ['action' => 'save', 'text' => '']);
        check('an empty save is a 422', $c === 422, "$c");
        [$c, $d] = $call('POST', '', ['action' => 'reset']);
        check('Reset over HTTP', $c === 200 && replyCleanupActiveTemplate($conn) === null, "$c");
        $none = 'zzrcn' . bin2hex(random_bytes(4)); $sids[] = $none;
        file_put_contents("$sessDir/sess_$none", '');
        $ch = curl_init("$base/api/tickets/reply_cleanup_prompt.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Cookie: PHPSESSID=' . $none]]);
        $d = json_decode((string)curl_exec($ch), true);
        check('nobody signed in gets nothing', !($d['success'] ?? false) && !isset($d['text']));
    } else {
        echo "(endpoint skipped: set FREEITSM_TEST_URL)\n";
    }
} finally {
    $conn->prepare("DELETE FROM ticket_reply_cleanup_prompts WHERE id > ?")->execute([$before]);
    foreach ($sids as $s) @unlink("$sessDir/sess_$s");
    echo "  (removed the test's versions; the prompt in use is what it was)\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
