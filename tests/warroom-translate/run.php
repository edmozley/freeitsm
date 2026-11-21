<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * War room translation (5.9.0) against a stand-in model (mock.php, `php -S`).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/warroom-translate/run.php
 *
 * Proves: off unless switched on; each message's language detected once; a message
 * already in the reader's language is never sent again; translations stored once per
 * language and reused (the call log proves no second call); edits forget them; a
 * provider failure leaves the originals; only messages of a readable channel.
 * Everything in a transaction that is always rolled back.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/warroom.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}
echo "\nWar room - translation\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at.\n");
}
try { $conn->query("SELECT 1 FROM warroom_translations LIMIT 0"); }
catch (Throwable $e) { echo "  SKIP  run Database Verification first\n"; exit(0); }

// ---- the stand-in model
$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$log = __DIR__ . '/calls.log';
@unlink($log);
// TRAP: bypass_shell, as tests/ai-compatible does. Without it Windows starts the server
// through cmd.exe, proc_terminate() stops cmd and leaves php -S running - holding the
// test's output open so the run never ends.
$devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$proc = proc_open(escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'mock.php'),
                  [0 => ['file', $devNull, 'r'], 1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']],
                  $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($proc)) { fwrite(STDERR, "could not start the stand-in model\n"); exit(1); }
register_shutdown_function(function () use (&$proc) { if (is_resource($proc)) proc_terminate($proc); });
$up = false;
for ($i = 0; $i < 100 && !$up; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); $up = true; } else usleep(100000); }
if (!$up) { fwrite(STDERR, "the stand-in model never came up on port $port\n"); exit(1); }
$calls = fn() => is_file($log) ? count(file($log, FILE_IGNORE_NEW_LINES)) : 0;

$a = (int) $conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$b = (int) $conn->query("SELECT id FROM analysts WHERE is_active = 1 AND id <> $a ORDER BY id LIMIT 1")->fetchColumn();
$all = (int) $conn->query("SELECT id FROM warroom_channels WHERE kind = 'all' LIMIT 1")->fetchColumn();

/** Upserts one system setting inside the transaction. */
$set = function (string $k, string $v) use ($conn) {
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
};

$conn->beginTransaction();
try {
    $set('warroom_ai_provider', 'openai_compatible');
    $set('warroom_ai_compatible_base_url', "http://127.0.0.1:$port/v1");
    $set('warroom_ai_model', 'translate');
    $set('warroom_translate', '0');
    ok('control: off by default - nothing is translated', !warRoomTranslateEnabled($conn));
    $set('warroom_translate', '1');
    ok('on once an administrator switches it on and a provider is set', warRoomTranslateEnabled($conn));

    $fr = warRoomSend($conn, $a, $all, 'Bonjour, le serveur est de retour @Sarah (INC-1042)');
    $de = warRoomSend($conn, $b, $all, 'Hallo, der Server läuft wieder');
    $en = warRoomSend($conn, $a, $all, 'Thanks, closing the bridge');

    // An English reader.
    $r = warRoomTranslate($conn, $b, $all, [$fr, $de, $en], 'en');
    ok('one call for the three messages', $calls() === 1, (string) $calls());
    ok('a French message comes back in English, marked French', ($r[$fr]['lang'] ?? '') === 'fr' && ($r[$fr]['text'] ?? '') === '[en] Bonjour, le serveur est de retour @Sarah (INC-1042)');
    ok('an English message needs nothing (text null)', array_key_exists($en, $r) && $r[$en]['text'] === null && $r[$en]['lang'] === 'en');
    ok('each language is stored on its message', $conn->query("SELECT lang FROM warroom_messages WHERE id = $fr")->fetchColumn() === 'fr'
        && $conn->query("SELECT lang FROM warroom_messages WHERE id = $en")->fetchColumn() === 'en');
    ok('translations are stored once per language', (int) $conn->query("SELECT COUNT(*) FROM warroom_translations WHERE message_id IN ($fr, $de, $en) AND lang = 'en'")->fetchColumn() === 2);

    $again = warRoomTranslate($conn, $a, $all, [$fr, $de, $en], 'en');
    ok('asking again (another English reader) costs no call', $calls() === 1 && ($again[$fr]['text'] ?? '') === $r[$fr]['text']);

    // A German reader: German message is theirs, the rest translated - only two sent.
    $before = $calls();
    $rd = warRoomTranslate($conn, $a, $all, [$fr, $de, $en], 'de');
    ok('a German reader: their own language is not translated', isset($rd[$de]) && array_key_exists('text', $rd[$de]) && $rd[$de]['text'] === null);
    ok('...and it was not even sent (its language is already known)', (int) trim(file($log)[$before] ?? '0') === 2, trim(file($log)[$before] ?? ''));
    ok('...the others come back in German', ($rd[$en]['text'] ?? '') === '[de] Thanks, closing the bridge');

    // The poll brings stored translations with the messages.
    $ms = warRoomMessages($conn, $all, 0, 200, $a, 0, 'de');
    $byId = []; foreach ($ms as $m) $byId[$m['id']] = $m;
    ok('the poll carries the stored translation for the reader', ($byId[$fr]['tr'] ?? '') === '[de] Bonjour, le serveur est de retour @Sarah (INC-1042)' && ($byId[$fr]['lang'] ?? '') === 'fr');
    ok('...and none for a message in their language', $byId[$de]['tr'] === null);
    ok('the original is untouched', $byId[$fr]['body'] === 'Bonjour, le serveur est de retour @Sarah (INC-1042)');

    // Edit forgets.
    warRoomEditMessage($conn, $a, $fr, 'Bonjour, finalement le serveur est encore en panne');
    ok('an edit forgets the translations and the language', (int) $conn->query("SELECT COUNT(*) FROM warroom_translations WHERE message_id = $fr")->fetchColumn() === 0
        && $conn->query("SELECT lang FROM warroom_messages WHERE id = $fr")->fetchColumn() === null);
    $re = warRoomTranslate($conn, $b, $all, [$fr], 'en');
    ok('...and the new text is translated fresh', ($re[$fr]['text'] ?? '') === '[en] Bonjour, finalement le serveur est encore en panne');

    // Delete forgets.
    warRoomDeleteMessage($conn, $a, $fr);
    ok('deleting removes its translations', (int) $conn->query("SELECT COUNT(*) FROM warroom_translations WHERE message_id = $fr")->fetchColumn() === 0);
    ok('a deleted message is not translated', !isset(warRoomTranslate($conn, $b, $all, [$fr], 'en')[$fr]));

    // Access.
    $priv = warRoomCreateChannel($conn, $a, 'ZZ tr ' . bin2hex(random_bytes(3)), '', true);
    $pm = warRoomSend($conn, $a, $priv, 'Hola, esto es privado');
    ok('nothing from a channel the reader cannot read', warRoomTranslate($conn, $b, $priv, [$pm], 'en') === []);
    ok('ids from another channel are ignored', !isset(warRoomTranslate($conn, $b, $all, [$pm], 'en')[$pm]));

    // Provider trouble leaves originals.
    $es = warRoomSend($conn, $a, $all, 'Hola, todo bien');
    $set('warroom_ai_model', 'prose');
    $rp = warRoomTranslate($conn, $b, $all, [$es], 'en');
    ok('an answer that is not JSON stores nothing and shows the original', !isset($rp[$es]) && (int) $conn->query("SELECT COUNT(*) FROM warroom_translations WHERE message_id = $es")->fetchColumn() === 0);
    $set('warroom_ai_model', 'down');
    $threw = false;
    try { warRoomTranslate($conn, $b, $all, [$es], 'en'); } catch (RuntimeException $e) { $threw = true; }
    ok('a provider that is down is reported, not hidden (the page keeps the original)', $threw);
    $set('warroom_ai_model', 'translate');
    $ok2 = warRoomTranslate($conn, $b, $all, [$es], 'en');
    ok('...and once it is back the message is translated', ($ok2[$es]['text'] ?? '') === '[en] Hola, todo bien');
} finally {
    $conn->rollBack();
    proc_terminate($proc);
    @unlink($log);
}

echo str_repeat('-', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
