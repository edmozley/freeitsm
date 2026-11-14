<?php
/* 🔴 Served ONLY by the built-in server that tests/ai-compatible/run.php starts
   with `php -S`. Under Apache, nginx or php-fpm this is an unauthenticated
   endpoint that writes a file to disk, so it refuses there. */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

/**
 * A stand-in for a self-hosted OpenAI-compatible model server (GH #192) -
 * shaped on Ollama's /v1 API, which the issue names as the first target.
 *
 * ⚠️ What it CANNOT prove: how a real model behaves. It answers the way Ollama
 * documents, so it proves what FreeITSM SENDS (address, auth header, model,
 * tools) and how FreeITSM reads each kind of answer.
 *
 * Behaviour is switched by the MODEL name, as a real server's would be:
 *   ok         → a normal completion
 *   tools-ok   → asks for the `ping` tool once, then answers OK
 *   no-tools   → refuses any request carrying tools, in Ollama's words
 *   missing    → 404, in Ollama's words for a model that has not been pulled
 *   needs-key  → 401 unless an Authorization header is sent
 *   not-chat   → 200 with JSON that is not a chat completion
 * and any path other than /v1/chat/completions is a plain-text 404, as Ollama
 * answers a base URL without its /v1.
 */

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$uri  = $_SERVER['REQUEST_URI'] ?? '';
$h = [];
foreach ((function_exists('getallheaders') ? getallheaders() : []) as $k => $v) { $h[strtolower($k)] = $v; }

$logFile = __DIR__ . '/last-request.json';
$all = is_file($logFile) ? (json_decode((string)file_get_contents($logFile), true) ?: []) : [];
$all[] = [
    'uri'           => $uri,
    'authorization' => $h['authorization'] ?? null,
    'api_key'       => $h['api-key'] ?? null,          // Azure's header (tests/ai-provider-enum.php)
    'referer'       => $h['http-referer'] ?? null,     // OpenRouter's attribution headers
    'title'         => $h['x-title'] ?? null,
    'model'         => $body['model'] ?? null,
    'has_tools'     => !empty($body['tools']),
    'roles'         => array_map(function ($m) { return $m['role'] ?? ''; }, $body['messages'] ?? []),
    // Every message's text, for tests that check what a prompt carried (tests/cmdb-assistant.php, tests/projects-assistant.php).
    'texts'         => array_map(function ($m) { $c = $m['content'] ?? ''; return mb_substr(is_string($c) ? $c : json_encode($c), 0, 40000); }, $body['messages'] ?? []),
    'tools'         => array_map(function ($t) { return $t['function']['name'] ?? ''; }, $body['tools'] ?? []),
];
file_put_contents($logFile, json_encode($all));

$model = (string)($body['model'] ?? '');

/* Embeddings (the help assistant's matching by meaning - tests/help-vectors.php).
   A real server's /v1/models and /v1/embeddings, shaped on Ollama's and OpenAI's:
     fake-embed    a deterministic 64-number vector per input - each word adds to
                   the slots its hash picks, so texts sharing words point the same way
     fake-embed-b  the same, hashed differently: its vectors mean nothing to
                   fake-embed's, like two real models
     fake-broken   HTTP 500                                                        */
$path = parse_url($uri, PHP_URL_PATH);
if ($path === '/v1/models') {
    header('Content-Type: application/json');
    echo json_encode(['object' => 'list', 'data' => [['id' => 'fake-embed', 'object' => 'model'], ['id' => 'fake-embed-b', 'object' => 'model']]]);
    exit;
}
if ($path === '/v1/embeddings') {
    header('Content-Type: application/json');
    if ($model === 'fake-broken') {
        http_response_code(500);
        echo json_encode(['error' => ['message' => 'the mock was told to fail', 'type' => 'api_error']]);
        exit;
    }
    $texts = is_array($body['input'] ?? null) ? $body['input'] : [(string)($body['input'] ?? '')];
    $data = [];
    foreach ($texts as $i => $t) {
        $v = array_fill(0, 64, 0.0);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)$t), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (mb_strlen($w) < 3) continue;
            $hash = crc32(($model === 'fake-embed-b' ? 'b:' : '') . $w);
            $v[$hash % 64] += 1.0;
            $v[($hash >> 8) % 64] += 0.5;
        }
        $data[] = ['object' => 'embedding', 'index' => $i, 'embedding' => $v];
    }
    echo json_encode(['object' => 'list', 'data' => $data, 'model' => $model]);
    exit;
}

if ($path !== '/v1/chat/completions') {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo '404 page not found';
    exit;
}

header('Content-Type: application/json');

$reply = function (array $message, string $finish = 'stop') use ($model) {
    echo json_encode([
        'id' => 'chatcmpl-mock', 'object' => 'chat.completion', 'model' => $model,
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant'] + $message, 'finish_reason' => $finish]],
        'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 1, 'total_tokens' => 10],
    ]);
    exit;
};

if ($model === 'missing') {
    http_response_code(404);
    echo json_encode(['error' => ['message' => 'model "missing" not found, try pulling it first', 'type' => 'api_error']]);
    exit;
}
if ($model === 'needs-key' && empty($h['authorization'])) {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'Unauthorized', 'type' => 'invalid_request_error']]);
    exit;
}
if ($model === 'no-tools' && !empty($body['tools'])) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'registry.ollama.ai/library/no-tools:latest does not support tools', 'type' => 'api_error']]);
    exit;
}
if ($model === 'not-chat') {
    echo json_encode(['status' => 'ok', 'version' => '0.0.0']);
    exit;
}
if ($model === 'tools-ok' && !empty($body['tools'])) {
    $roles = array_map(function ($m) { return $m['role'] ?? ''; }, $body['messages'] ?? []);
    if (!in_array('tool', $roles, true)) {
        $reply(['content' => '', 'tool_calls' => [[
            'id' => 'call_1', 'type' => 'function',
            'function' => ['name' => 'ping', 'arguments' => '{}'],
        ]]], 'tool_calls');
    }
}
/* The help assistant's written answers (tests/help-ask.php):
     help-answer  quotes the first section it was given - proof the sections arrived
     help-none    "the help does not cover it"
     help-search  asks search_help once, then cites the last section that search returned */
$userText = ''; $toolText = '';
foreach ($body['messages'] ?? [] as $m) {
    $c = is_string($m['content'] ?? null) ? $m['content'] : json_encode($m['content'] ?? '');
    if (($m['role'] ?? '') === 'user') $userText = $c;
    if (($m['role'] ?? '') === 'tool') $toolText .= $c;
}
if ($model === 'help-answer') {
    preg_match('/^\[1\] (.*)$/m', $userText, $first);
    $reply(['content' => "The help explains it: **" . trim($first[1] ?? '?') . "** [1]\nSOURCES: 1"]);
}
if ($model === 'help-none') {
    $reply(['content' => 'NOT_COVERED']);
}
if ($model === 'help-search') {
    if ($toolText === '') {
        $reply(['content' => '', 'tool_calls' => [[
            'id' => 'call_s', 'type' => 'function',
            'function' => ['name' => 'search_help', 'arguments' => json_encode(['words' => 'department create'])],
        ]]], 'tool_calls');
    }
    preg_match_all('/^\[(\d+)\]/m', $toolText, $nums);
    $n = $nums[1] ? end($nums[1]) : '1';
    $reply(['content' => "Found it after searching again. [$n]\nSOURCES: $n"]);
}
/* The conversation assistants (tests/projects-assistant.php, tests/cmdb-assistant.php):
     script  a memory fold (system starts "You keep the memory") answers "- MEMORY: folded";
             otherwise every line of the LAST user message of the form
               CALL tool_name {"json": "arguments"}
             is asked for as a tool call (all at once, once); after the results come back -
             or when there are no CALL lines - it answers "REPLY: " and the tool results
             joined with " | " (or the first 60 characters of the user message). */
if ($model === 'script') {
    $msgs = $body['messages'] ?? [];
    $sys = (string)($msgs[0]['content'] ?? '');
    if (str_starts_with($sys, 'You keep the memory')) $reply(['content' => '- MEMORY: folded']);
    $lastUser = -1;
    foreach ($msgs as $i => $m) if (($m['role'] ?? '') === 'user') $lastUser = $i;
    $after = array_slice($msgs, $lastUser + 1);
    $results = [];
    foreach ($after as $m) if (($m['role'] ?? '') === 'tool') $results[] = is_string($m['content'] ?? null) ? $m['content'] : json_encode($m['content'] ?? '');
    $text = $lastUser >= 0 ? (string)($msgs[$lastUser]['content'] ?? '') : '';
    preg_match_all('/^CALL ([a-z_]+) (\{.*\})\s*$/m', $text, $calls, PREG_SET_ORDER);
    if ($calls && !$results && !empty($body['tools'])) {
        $reply(['content' => '', 'tool_calls' => array_map(function ($c, $i) {
            return ['id' => 'call_' . $i, 'type' => 'function', 'function' => ['name' => $c[1], 'arguments' => $c[2]]];
        }, $calls, array_keys($calls))], 'tool_calls');
    }
    $reply(['content' => 'REPLY: ' . ($results ? implode(' | ', $results) : mb_substr($text, 0, 60))]);
}
$reply(['content' => 'OK']);
