<?php
/* 🔴 Served ONLY by the built-in server that tests/warroom-translate/run.php starts
   with `php -S`. Under Apache, nginx or php-fpm it refuses. */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
/**
 * A stand-in translation model for the war room (5.9.0). It answers the request
 * warRoomTranslatePrompt() writes the way a model is asked to: a JSON array, one
 * object per message, with the detected language and the translation - so the test
 * proves what FreeITSM sends, stores and shows. It cannot prove translation quality.
 *
 * "Detection" is by a word: Bonjour/serveur = fr, Hallo/Server = de, Hola = es,
 * anything else en. The "translation" is "[<target>] <original>".
 *
 * By model name:  translate = normal · prose = answers in prose (no JSON) · down = HTTP 500
 * Every call is appended to calls.log (one line: how many messages it carried).
 */
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$model = (string) ($body['model'] ?? '');
$user = '';
foreach ((array) ($body['messages'] ?? []) as $m) if (($m['role'] ?? '') === 'user') $user = (string) $m['content'];

preg_match('/Target language: ([a-z-]+)/i', $user, $tm);
$target = strtolower($tm[1] ?? 'en');
$pos = strpos($user, "Messages:\n");
$items = $pos !== false ? (json_decode(substr($user, $pos + 10), true) ?: []) : [];
file_put_contents(__DIR__ . '/calls.log', count($items) . "\n", FILE_APPEND);

if ($model === 'down') { http_response_code(500); echo '{"error":{"message":"down"}}'; exit; }

$out = [];
foreach ($items as $it) {
    $t = (string) $it['text'];
    $lang = preg_match('/\b(Bonjour|serveur)\b/u', $t) ? 'fr' : (preg_match('/\b(Hallo|Server)\b/u', $t) ? 'de' : (preg_match('/\bHola\b/u', $t) ? 'es' : 'en'));
    $out[] = $lang === $target
        ? ['id' => $it['id'], 'lang' => $lang, 'same' => true]
        : ['id' => $it['id'], 'lang' => $lang, 'same' => false, 'text' => "[$target] $t"];
}
$content = $model === 'prose' ? 'Sure! Here are the translations you asked for.' : json_encode($out, JSON_UNESCAPED_UNICODE);
header('Content-Type: application/json');
echo json_encode([
    'id' => 'mock', 'object' => 'chat.completion', 'model' => $model,
    'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
], JSON_UNESCAPED_UNICODE);
