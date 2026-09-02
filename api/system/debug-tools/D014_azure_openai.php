<?php
/**
 * Debug Tool D014 — Azure OpenAI deployment endpoints
 *
 * Answers one question end to end: "this install is configured for Azure
 * OpenAI and the AI features are not working — where exactly does it break?"
 *
 * 🔴 WHY THIS EXISTS AT ALL. Azure support (discussion #86) was built without an
 * Azure subscription to test it against. Every byte FreeITSM SENDS is covered by
 * tests/azure-openai/, but nothing here has ever spoken to a real tenant, so the
 * first person to run it in anger is doing the verification. This tool is what
 * makes that a single round trip instead of five: it prints the exact URL built,
 * the exact headers (key redacted), what Azure said back verbatim, and a reading
 * of the most common failures. Paste the output into the discussion and the
 * answer is in it.
 *
 * READ-ONLY apart from the live call itself, which spends a few tokens against
 * the configured deployment exactly as the Test button does.
 *
 * ⚠️ PRINTS NO SECRETS. The api-key is shown as its first four characters and a
 * length, which is enough to tell "wrong key" from "no key" without putting one
 * in a GitHub comment.
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D014';
$DIAG_NAME = 'Azure OpenAI deployment endpoints';

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Debug tools are administrators-only (issue #34). Fail closed.
try {
    $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id']);
} catch (Throwable $e) {
    $__dbgAdmin = false;
}
if (!$__dbgAdmin) {
    http_response_code(403);
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "Administrator access required.\n";
    exit;
}

require_once __DIR__ . '/../../../includes/ai_settings.php';

$sections = [];
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
function emit_and_exit($sections) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n\n", $sections) . "\n";
    exit;
}
/** First four characters and a length — enough to tell a wrong key from no key. */
function keyHint(string $k): string {
    if ($k === '') return '(none saved)';
    return substr($k, 0, 4) . '… (' . strlen($k) . ' characters)';
}

$conn = connectToDatabase();

// ---- 1. WHICH FEATURES ARE POINTED AT AZURE ----------------------------
$azureNs = [];
$lines   = [];
foreach (aiSettingsRegistry() as $ns => $entry) {
    try {
        $cfg = aiSettingsLoad($conn, $ns);
    } catch (Throwable $e) {
        $lines[] = sprintf('%-22s  could not be read: %s', $ns, $e->getMessage());
        continue;
    }
    $lines[] = sprintf('%-22s  %-10s  %s', $ns, $cfg['provider'],
        $cfg['provider'] === 'azure'
            ? ('deployment "' . $cfg['azure_deployment'] . '"')
            : ('model ' . $cfg['model']));
    if ($cfg['provider'] === 'azure') {
        $azureNs[$ns] = $cfg;
    }
}
addSection($sections, 'AI FEATURES AND THEIR PROVIDERS', $lines);

if (!$azureNs) {
    addSection($sections, 'VERDICT', [
        'No AI feature on this install is set to Azure OpenAI, so there is nothing for',
        'this tool to test.',
        '',
        'To use Azure: open any module\'s AI settings, choose "Azure OpenAI (your own',
        'deployment)", and fill in the endpoint, deployment name and API version from',
        'the Azure portal.',
    ]);
    emit_and_exit($sections);
}

// ---- 2. THE CONFIGURATION, AND THE URL IT BUILDS -----------------------
foreach ($azureNs as $ns => $cfg) {
    $url = '';
    $err = '';
    try {
        $url = aiAzureChatUrl($cfg);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    addSection($sections, 'CONFIGURATION — ' . $ns, [
        'Endpoint as saved : ' . ($cfg['azure_endpoint'] ?: '(empty — REQUIRED)'),
        'Deployment        : ' . ($cfg['azure_deployment'] ?: '(empty — REQUIRED)'),
        'API version       : ' . ($cfg['azure_api_version'] ?: '(empty — defaults to ' . AI_AZURE_DEFAULT_API_VERSION . ')'),
        'API key           : ' . keyHint($cfg['api_key']),
        '',
        'URL FreeITSM will call:',
        '  ' . ($url ?: '(could not be built: ' . $err . ')'),
        '',
        'Headers sent:',
        '  api-key: ' . keyHint($cfg['api_key']),
        '  content-type: application/json',
        '  (deliberately NO "Authorization: Bearer" — Azure uses api-key)',
        '',
        'Body: the ordinary OpenAI chat-completions shape, with NO "model" field —',
        'on a deployment endpoint the deployment already decides the model.',
    ]);
}

// ---- 3. A LIVE CALL ----------------------------------------------------
$out = [];
foreach ($azureNs as $ns => $cfg) {
    $out[] = "--- {$ns} ---";
    if ($cfg['api_key'] === '') {
        $out[] = 'SKIPPED: no API key saved for this feature.';
        $out[] = '';
        continue;
    }
    $t0 = microtime(true);
    try {
        $r = aiProviderChat($cfg, [
            'system'     => 'You are a connection test. Reply with the single word: OK',
            'user'       => 'Reply with the single word: OK',
            'max_tokens' => 16,
        ]);
        $out[] = 'RESULT   : SUCCESS';
        $out[] = 'Reply    : ' . trim((string)$r['content']);
        $out[] = 'Tokens   : ' . (int)$r['tokens_in'] . ' in, ' . (int)$r['tokens_out'] . ' out';
        $out[] = 'Took     : ' . (int)$r['duration_ms'] . 'ms';
        if (trim((string)$r['content']) === '') {
            $out[] = '';
            $out[] = '⚠️  The call worked but the answer was EMPTY. finish_reason was "'
                   . (string)($r['finish_reason'] ?? '?') . '". If that is "length", the';
            $out[] = '    deployment spent its whole budget before writing anything — raise the';
            $out[] = '    token limit or use a deployment that does not reason at length.';
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $out[] = 'RESULT   : FAILED';
        $out[] = 'Azure said (verbatim, first 1200 characters):';
        $out[] = '  ' . str_replace("\n", "\n  ", substr($msg, 0, 1200));
        $out[] = '';
        $out[] = 'READING:';
        // The failures worth naming, rather than making somebody guess.
        if (stripos($msg, '404') !== false || stripos($msg, 'DeploymentNotFound') !== false) {
            $out[] = '  404 / DeploymentNotFound — the endpoint is reachable but the DEPLOYMENT';
            $out[] = '  NAME does not exist on it. That name is the one YOU chose in the portal,';
            $out[] = '  which need not match the model it runs. Check it under your resource →';
            $out[] = '  Deployments, and check the endpoint belongs to the same resource.';
        } elseif (stripos($msg, '401') !== false || stripos($msg, 'AccessDenied') !== false) {
            $out[] = '  401 — the key is wrong, or it belongs to a different Azure resource.';
            $out[] = '  Azure keys are per-resource; a key from another resource returns exactly';
            $out[] = '  this even when the endpoint and deployment are both correct.';
        } elseif (stripos($msg, 'api-version') !== false) {
            $out[] = '  The api-version is not one this deployment accepts. It is shown beside';
            $out[] = '  the deployment in the portal. Leaving the field blank uses '
                   . AI_AZURE_DEFAULT_API_VERSION . '.';
        } elseif (stripos($msg, 'content') !== false && stripos($msg, 'filter') !== false) {
            $out[] = '  Azure\'s CONTENT FILTER refused the request. That is a policy on your';
            $out[] = '  deployment rather than a fault here — the same prompt would be refused';
            $out[] = '  from any client. Adjust the filter, or use a deployment without it.';
        } elseif (stripos($msg, 'max_completion_tokens') !== false) {
            $out[] = '  This deployment wants max_completion_tokens rather than max_tokens.';
            $out[] = '  FreeITSM retries automatically when Azure names it, so seeing this here';
            $out[] = '  means the RETRY also failed — please paste this whole output into';
            $out[] = '  discussion #86.';
        } elseif (stripos($msg, 'timed out') !== false || stripos($msg, 'Could not resolve') !== false
               || stripos($msg, 'certificate') !== false) {
            $out[] = '  The request never reached Azure. This is a network or TLS problem rather';
            $out[] = '  than a configuration one — run D006 (SSL / HTTPS verification) next, and';
            $out[] = '  check whether this server is allowed out to *.openai.azure.com.';
        } else {
            $out[] = '  Not a failure this tool recognises. Please paste this whole output into';
            $out[] = '  discussion #86 — Azure support was written without a tenant to test it';
            $out[] = '  against, so an unrecognised error here is genuinely useful to us.';
        }
    }
    $out[] = 'Elapsed  : ' . (int)((microtime(true) - $t0) * 1000) . 'ms';
    $out[] = '';
}
addSection($sections, 'LIVE CALL', $out);

addSection($sections, 'IF YOU ARE REPORTING THIS', [
    'Paste this entire output into https://github.com/edmozley/freeitsm/discussions/86.',
    'It contains no API key — only the first four characters and a length — and no',
    'prompt or ticket content.',
]);

emit_and_exit($sections);
