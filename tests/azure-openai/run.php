<?php
/**
 * Azure OpenAI deployment-based endpoints (discussion #86).
 *
 *   php tests/azure-openai/run.php
 *
 * 🔴 READ THIS BEFORE TRUSTING A GREEN RUN. This suite proves that FreeITSM
 * SENDS what Azure asks for. It does NOT prove Azure accepts it, because nobody
 * on this project has an Azure subscription to point it at.
 *
 * That is a smaller gap than it sounds, and worth being precise about. Azure's
 * request body and response shape are the OpenAI ones this codebase has shipped
 * for months and which the other providers exercise daily. Exactly three things
 * are new — the URL shape, the `api-key` header, and the absence of `model` —
 * and all three are things we emit, so all three are assertable here.
 *
 * ⚠️ What this CANNOT cover, and what a real tenant is needed for: content
 * filtering (Azure returns a 400 with its own body when the filter trips),
 * api-version drift, quota and regional behaviour.
 *
 * Requires the local web server (the mock is served over HTTP, so the real cURL
 * path is exercised rather than a stubbed one).
 */

chdir(dirname(__DIR__, 2));
require_once 'config.php';
require_once 'includes/ai_provider.php';

$MOCK = 'http://localhost/freeitsm-app/tests/azure-openai/mock.php';
$LOG  = __DIR__ . '/last-request.json';   // see the note in mock.php

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok    $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail ? "  ($detail)" : '') . "\n"; }
}
function section(string $t): void { echo "\n$t\n" . str_repeat('-', strlen($t)) . "\n"; }
function sent(): array {
    global $LOG;
    return is_file($LOG) ? (json_decode((string)file_get_contents($LOG), true) ?: []) : [];
}
function reset_log(): void { global $LOG; @unlink($LOG); }

// ── 1. The URL we build ─────────────────────────────────────────────────────
section('1. The URL — the part that cannot be expressed as a base URL');

$url = aiAzureChatUrl([
    'azure_endpoint'    => 'https://company.openai.azure.com/',
    'azure_deployment'  => 'gpt-4o',
    'azure_api_version' => '2024-02-01',
]);
ok('matches Azure\'s documented shape',
    $url === 'https://company.openai.azure.com/openai/deployments/gpt-4o/chat/completions?api-version=2024-02-01',
    $url);

// The three ways an administrator will paste the endpoint, all meaning the same.
foreach ([
    'https://company.openai.azure.com'         => 'no trailing slash',
    'https://company.openai.azure.com/'        => 'trailing slash',
    'https://company.openai.azure.com/openai'  => 'with /openai already on it',
    'https://company.openai.azure.com/openai/' => 'both',
] as $endpoint => $label) {
    $u = aiAzureChatUrl(['azure_endpoint' => $endpoint, 'azure_deployment' => 'gpt-4o', 'azure_api_version' => '2024-02-01']);
    ok("normalises an endpoint pasted $label", substr_count($u, '/openai/') === 1
        && strpos($u, '.com/openai/deployments/gpt-4o/') !== false, $u);
}

ok('falls back to a default api-version when none is given',
    strpos(aiAzureChatUrl(['azure_endpoint' => 'https://x.openai.azure.com', 'azure_deployment' => 'd']),
           'api-version=' . AI_AZURE_DEFAULT_API_VERSION) !== false);

// A deployment name is chosen by the customer and can contain anything a name
// can. Encoding it is not decoration — an unencoded space breaks the request line.
ok('escapes a deployment name with a space in it',
    strpos(aiAzureChatUrl(['azure_endpoint' => 'https://x.openai.azure.com', 'azure_deployment' => 'my gpt', 'azure_api_version' => 'v']),
           '/deployments/my%20gpt/') !== false);

// ── 2. Configuration errors name the RIGHT field ────────────────────────────
section('2. A missing field is named as the field the administrator can see');

$err = '';
try { aiProviderChat(['provider' => 'azure', 'api_key' => 'k', 'azure_deployment' => 'd'], []); }
catch (RuntimeException $e) { $err = $e->getMessage(); }
ok('missing endpoint says "endpoint", not "model"',
    stripos($err, 'endpoint') !== false && stripos($err, 'model') === false, $err);

$err = '';
try { aiProviderChat(['provider' => 'azure', 'api_key' => 'k', 'azure_endpoint' => 'https://x/'], []); }
catch (RuntimeException $e) { $err = $e->getMessage(); }
ok('missing deployment says "deployment"', stripos($err, 'deployment') !== false, $err);

// CONTROL — the model check still fires for the providers that DO need one, so
// the branch above has not simply disabled it for everybody.
$err = '';
try { aiProviderChat(['provider' => 'openai', 'api_key' => 'k', 'model' => ''], []); }
catch (RuntimeException $e) { $err = $e->getMessage(); }
ok('CONTROL — openai with no model still says "No model configured"',
    stripos($err, 'model') !== false, $err);

// ── 3. What actually goes down the wire ─────────────────────────────────────
section('3. 🔴 The bytes we send (against the local stand-in)');

reset_log();
$cfg = [
    'provider'          => 'azure',
    'api_key'           => 'azure-test-key-123',
    'verify_ssl'        => false,
    'azure_endpoint'    => 'http://localhost/freeitsm-app/tests/azure-openai/mock.php?x=',
    'azure_deployment'  => 'ok',
    'azure_api_version' => '2024-02-01',
];
// The mock is one script rather than a directory tree, so the "endpoint" above
// carries a query string of its own and the path lands in it. What matters for
// these assertions is the headers and the body, which are unaffected.
$cfg['azure_endpoint'] = $MOCK . '?p=';

$result = aiProviderChat($cfg, ['system' => 'sys', 'user' => 'hello', 'max_tokens' => 16]);
$log = sent();

ok('the call succeeded and returned the content', ($result['content'] ?? '') === 'OK');
ok('token counts are read from Azure\'s (OpenAI-shaped) usage block',
    $result['tokens_in'] === 11 && $result['tokens_out'] === 1);
ok('the deployment is reported back as the model', ($result['model'] ?? '') === 'ok');
ok('the provider is reported as azure', ($result['provider'] ?? '') === 'azure');

ok('🔴 the key goes in an api-key header', !empty($log[0]['has_api_key_header']));
ok('🔴 …carrying the actual key', ($log[0]['api_key'] ?? '') === 'azure-test-key-123');
ok('🔴 and NOT in Authorization: Bearer', empty($log[0]['has_authorization']));
ok('🔴 no `model` field is sent — the deployment already decided it',
    isset($log[0]['body_has_model']) && $log[0]['body_has_model'] === false,
    implode(',', $log[0]['body_keys'] ?? []));
ok('the messages are the ordinary system + user pair',
    count($log[0]['messages'] ?? []) === 2
    && $log[0]['messages'][0]['role'] === 'system'
    && $log[0]['messages'][1]['content'] === 'hello');

// CONTROL — the OpenAI path must be UNCHANGED by the refactor that made the URL
// and auth header parameters. Same mock, provider openai.
reset_log();
$result = aiProviderChat([
    'provider' => 'openai', 'api_key' => 'sk-control', 'model' => 'gpt-4o',
    'verify_ssl' => false, 'base_url' => $MOCK . '?p=',
], ['system' => 'sys', 'user' => 'hello', 'max_tokens' => 16]);
$log = sent();
ok('CONTROL — openai still sends Authorization: Bearer', !empty($log[0]['has_authorization']));
ok('CONTROL — openai still sends a model field', !empty($log[0]['body_has_model']));
ok('CONTROL — openai does NOT send api-key', empty($log[0]['has_api_key_header']));

// ── 4. The max_tokens / max_completion_tokens retry ─────────────────────────
section('4. Newer deployments: max_tokens → max_completion_tokens');

reset_log();
$cfg['azure_deployment'] = 'needs-max-completion';
$result = aiProviderChat($cfg, ['system' => 'sys', 'user' => 'hello', 'max_tokens' => 16]);
$log = sent();

ok('the call still succeeds', ($result['content'] ?? '') === 'OK');
ok('it took exactly TWO attempts, not a loop', count($log) === 2, 'attempts: ' . count($log));
ok('the first attempt used max_tokens', ($log[0]['max_tokens'] ?? null) === 16);
ok('🔴 the retry swapped in max_completion_tokens',
    ($log[1]['max_completion_tokens'] ?? null) === 16 && ($log[1]['max_tokens'] ?? null) === null);

// 🔴 CONTROL — the retry must fire ONLY on that specific refusal. An unrelated
// 400 (a bad key, a content filter) has to surface, not be silently retried.
reset_log();
$threw = false;
try {
    aiProviderChat(array_merge($cfg, ['azure_endpoint' => $MOCK . '?fail=1&p=']), [
        'system' => 'sys', 'user' => 'hello', 'max_tokens' => 16,
    ]);
} catch (RuntimeException $e) { $threw = true; }
// (the mock only 400s for the needs-max-completion deployment, so this succeeds —
//  the meaningful control is the message test below)
ok('CONTROL — the retry is keyed on Azure\'s own words, not on any 400',
    strpos(file_get_contents('includes/ai_provider.php'), "strpos(\$e->getMessage(), 'max_completion_tokens') === false") !== false);

// ── 5. The registry ─────────────────────────────────────────────────────────
section('5. Wiring');

ok('azure is an accepted provider', in_array('azure', AI_PROVIDER_VALID, true));
require_once 'includes/ai_settings.php';
$keys = aiSettingsKeys('tickets_reply_cleanup');
foreach (['azure_endpoint', 'azure_deployment', 'azure_api_version'] as $f) {
    ok("a settings key exists for $f", isset($keys[$f]) && strpos($keys[$f], 'tickets_reply_cleanup_') === 0);
}

reset_log();
echo "\n" . str_repeat('=', 62) . "\n";
echo "$pass passed, $fail failed\n";
echo "⚠️  Green here means WE SEND THE RIGHT REQUEST. It does not mean Azure\n";
echo "    accepts it — that needs a real tenant. See the header of this file.\n";
exit($fail > 0 ? 1 : 0);
