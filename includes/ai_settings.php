<?php
/**
 * Per-namespace AI configuration storage, shared by every module that offers
 * an AI provider/model/key panel.
 *
 * Config for a namespace `<ns>` lives in three system_settings rows:
 *   <ns>_provider     anthropic | openai | openrouter
 *   <ns>_model        model id (curated for anthropic/openai; namespaced for openrouter)
 *   <ns>_api_key      encrypted at rest (key must be in ENCRYPTED_SETTING_KEYS + MASKED_SETTING_KEYS)
 *
 * SSL certificate verification is NOT configured here — it is a single global
 * switch (SSL_VERIFY_PEER in config.php, applied via sslApplyCurl). There is no
 * per-provider toggle: the tick-box that used to live on each AI settings page
 * was confusing (it ANDed with the global) and gave the false impression the UI
 * could enable verification the server couldn't actually perform.
 *
 * SECURITY: the registry is an allowlist. The generic api/system/ai/* endpoints
 * only ever read/write keys derived from a REGISTERED namespace, so they can't be
 * abused to read or overwrite arbitrary system_settings (e.g. other secrets).
 */

require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/ai_provider.php';

/**
 * Allowlisted AI config namespaces. Adding a module = one line here + dropping
 * renderAiSettingsPanel('<ns>') on its settings page. The <ns>_api_key MUST also
 * be added to ENCRYPTED_SETTING_KEYS + MASKED_SETTING_KEYS in encryption.php.
 */
function aiSettingsRegistry(): array
{
    return [
        'knowledge_ai' => [
            'label'           => 'Knowledge AI',
            'default_provider'=> 'anthropic',
            'default_model'   => 'claude-haiku-4-5-20251001',
        ],
        'cmdb_ai' => [
            'label'           => 'CMDB AI',
            'default_provider'=> 'anthropic',
            'default_model'   => 'claude-haiku-4-5-20251001',
        ],
        'workflow_ai' => [
            'label'           => 'Workflow AI',
            'default_provider'=> 'anthropic',
            'default_model'   => 'claude-sonnet-4-6',
        ],
        'forms_ai' => [
            'label'           => 'Forms AI',
            'default_provider'=> 'anthropic',
            'default_model'   => 'claude-sonnet-4-6',
        ],
        'tickets_reply_cleanup' => [
            'label'           => 'Tickets Reply Cleanup',
            'default_provider'=> 'anthropic',
            'default_model'   => 'claude-haiku-4-5-20251001',
        ],
        'problem_ai' => [
            'label'           => 'Problem AI',
            'default_provider'=> 'anthropic',
            'default_model'   => 'claude-sonnet-4-6',
        ],
        'lms_ai' => [
            'label'           => 'LMS AI',
            'default_provider'=> 'anthropic',
            'default_model'   => 'claude-sonnet-4-6',
        ],
        // Deferred: 'rfp_ai' (RFP Builder) — needs real OpenRouter SSE streaming first.
    ];
}

function aiSettingsIsValidNs(string $ns): bool
{
    return array_key_exists($ns, aiSettingsRegistry());
}

function aiSettingsEntry(string $ns): array
{
    $reg = aiSettingsRegistry();
    if (!isset($reg[$ns])) {
        throw new RuntimeException('Unknown AI settings namespace: ' . $ns);
    }
    return $reg[$ns];
}

/** The 4 system_settings keys for a namespace. */
function aiSettingsKeys(string $ns): array
{
    return [
        'provider'   => $ns . '_provider',
        'model'      => $ns . '_model',
        'api_key'    => $ns . '_api_key',
    ];
}

/**
 * Load a ready-to-use config (decrypted key) for aiProviderChat().
 * Defaults: provider/model from the registry; verify_ssl from the row, else the
 * global SSL_VERIFY_PEER constant (preserves existing localhost behaviour).
 */
function aiSettingsLoad(PDO $conn, string $ns): array
{
    $entry = aiSettingsEntry($ns);
    $keys  = aiSettingsKeys($ns);

    $rows = [];
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (?,?,?)");
    $stmt->execute(array_values($keys));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[$r['setting_key']] = $r['setting_value'];
    }

    $provider = $rows[$keys['provider']] ?? $entry['default_provider'];
    if (!in_array($provider, AI_PROVIDER_VALID, true)) {
        $provider = $entry['default_provider'];
    }

    $model = $rows[$keys['model']] ?? '';
    if ($model === '' || $model === null) {
        $model = $entry['default_model'];
    }

    $apiKey = '';
    if (!empty($rows[$keys['api_key']])) {
        $apiKey = (string)decryptValue($rows[$keys['api_key']]);
    }

    // SSL verification is a single global switch now (SSL_VERIFY_PEER, applied
    // via sslApplyCurl) — there is no per-namespace row or toggle. We still
    // surface 'verify_ssl' here, mirroring the global, so existing callers that
    // read $cfg['verify_ssl'] keep working; the value no longer drives any curl
    // handle (they all go through sslApplyCurl), it is informational only.
    return [
        'provider'   => $provider,
        'model'      => $model,
        'api_key'    => $apiKey,
        'verify_ssl' => defined('SSL_VERIFY_PEER') ? (bool)SSL_VERIFY_PEER : true,
    ];
}

/**
 * UI-safe view of the config — never returns the plaintext key, only a mask
 * and a has_key flag.
 */
function aiSettingsForUi(PDO $conn, string $ns): array
{
    $cfg = aiSettingsLoad($conn, $ns);
    return [
        'provider'   => $cfg['provider'],
        'model'      => $cfg['model'],
        'has_key'    => $cfg['api_key'] !== '',
        'masked_key' => $cfg['api_key'] !== '' ? maskSecret($cfg['api_key']) : '',
    ];
}

/**
 * Persist a config. Validates provider; encrypts the key; honours
 * masked/empty "no change" so saving provider+model alone never wipes the key.
 *
 * @param array $data ['provider','model','api_key'?]
 */
function aiSettingsSave(PDO $conn, string $ns, array $data): void
{
    aiSettingsEntry($ns); // throws on bad ns
    $keys = aiSettingsKeys($ns);

    $provider = $data['provider'] ?? 'anthropic';
    if (!in_array($provider, AI_PROVIDER_VALID, true)) {
        throw new InvalidArgumentException('Invalid provider');
    }
    $model = trim((string)($data['model'] ?? ''));

    $upsert = function (string $key, string $value) use ($conn) {
        // UPDATE-then-INSERT (matches save_email_settings.php) keeps it portable.
        $upd = $conn->prepare("UPDATE system_settings SET setting_value = :v WHERE setting_key = :k");
        $upd->execute([':k' => $key, ':v' => $value]);
        if ($upd->rowCount() === 0) {
            $ins = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (:k, :v)
                                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $ins->execute([':k' => $key, ':v' => $value]);
        }
    };

    $upsert($keys['provider'], $provider);
    $upsert($keys['model'], $model);

    // Key: only write when the user actually entered a new one.
    $rawKey = $data['api_key'] ?? '';
    if (!isMaskedNoChangeValue($rawKey)) {
        $upsert($keys['api_key'], encryptValue(trim((string)$rawKey)));
    }
}
