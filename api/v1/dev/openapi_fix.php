<?php
/**
 * Auto-fix the typed OpenAPI schemas against LIVE responses: add fields the
 * serializers return but the schema omits (type inferred from the live value),
 * mark `nullable` where live data is null, and relax over-strict `required`.
 * Patches the shared component schemas in lib/openapi_schemas.php so a fix
 * propagates to every user. Idempotent — run until openapi_verify.php is clean.
 *
 * Usage (CLI only):
 *   php api/v1/dev/openapi_fix.php <api_key> [base_url]
 *
 * Run this after adding or changing a module's serializer to bring the typed
 * schemas back in line with reality, then run openapi_verify.php to confirm,
 * then openapi_check.php + `spectral lint` for full validation.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$KEY  = $argv[1] ?? getenv('FITSM_API_KEY') ?: '';
$BASE = $argv[2] ?? getenv('FITSM_API_BASE') ?: 'http://localhost/freeitsm-app/api/v1/index.php';
if ($KEY === '') { fwrite(STDERR, "Provide an API key: php openapi_fix.php <key> [base_url]\n"); exit(2); }

$SCHEMA_FILE = __DIR__ . '/../lib/openapi_schemas.php';
require __DIR__ . '/../lib/openapi.php';

$data = require $SCHEMA_FILE;
$schemas = $data['schemas']; $responses = $data['responses']; $reqBodies = $data['requestBodies'] ?? [];

function oaf_get($url, $key) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key], CURLOPT_TIMEOUT=>20]);
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, json_decode($b, true)];
}
function oaf_infer($v) {
    if ($v === null)   return ['nullable' => true];
    if (is_bool($v))   return ['type' => 'boolean'];
    if (is_int($v))    return ['type' => 'integer'];
    if (is_float($v))  return ['type' => 'number'];
    if (is_string($v)) return ['type' => 'string', 'nullable' => true];
    if (is_array($v)) {
        $isList = $v === [] || array_keys($v) === range(0, count($v)-1);
        if ($isList) { $s = ['type' => 'array']; if (isset($v[0])) $s['items'] = oaf_infer($v[0]); return $s; }
        $props = []; foreach ($v as $k => $vv) $props[$k] = oaf_infer($vv);
        return ['type' => 'object', 'nullable' => true, 'properties' => $props];
    }
    return [];
}
$patches = 0;
function oaf_patch(&$node, $data, &$schemas, $depth = 0) {
    global $patches;
    if ($depth > 40 || !is_array($node)) return;
    $g = 0;
    while (isset($node['$ref']) && $g++ < 20) { $n = preg_replace('~^#/components/schemas/~', '', $node['$ref']); if (!isset($schemas[$n])) return; oaf_patch($schemas[$n], $data, $schemas, $depth+1); return; }
    if (isset($node['allOf'])) { foreach ($node['allOf'] as &$s) oaf_patch($s, $data, $schemas, $depth+1); return; }
    if ($data === null) { if (empty($node['nullable'])) { $node['nullable'] = true; $patches++; } return; }
    if (!isset($node['type'])) {
        // Previously typeless (e.g. added from a null sample) — a concrete value
        // now reveals the type. Upgrade in place, preserving nullable, then let
        // the object/array handling below merge fields across rows.
        $node['type'] = is_bool($data) ? 'boolean'
            : (is_int($data) ? 'integer'
            : (is_float($data) ? 'number'
            : (is_string($data) ? 'string'
            : (is_array($data) && ($data === [] || array_keys($data) === range(0, count($data)-1)) ? 'array' : 'object'))));
        $patches++;
    }
    if ($node['type'] === 'array' && is_array($data)) {
        if (!isset($node['items'])) $node['items'] = [];
        foreach ($data as $item) oaf_patch($node['items'], $item, $schemas, $depth+1);
        if ($node['items'] === []) unset($node['items']);
    } elseif ($node['type'] === 'object' && is_array($data)) {
        if (!isset($node['properties'])) $node['properties'] = [];
        foreach ($data as $k => $v) {
            if (isset($node['properties'][$k])) oaf_patch($node['properties'][$k], $v, $schemas, $depth+1);
            else { $node['properties'][$k] = oaf_infer($v); $patches++; }
        }
        if ($node['properties'] === []) unset($node['properties']);
        if (isset($node['required'])) {
            $keep = array_values(array_filter($node['required'], fn($r) => array_key_exists($r, $data)));
            if (count($keep) !== count($node['required'])) { $node['required'] = $keep; $patches++; }
        }
    }
}
$idCache = [];
function oaf_resolvePath($template, $base, $key) {
    global $idCache;
    while (preg_match('/\{([^}]+)\}/', $template, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1]; $prefix = rtrim(substr($template, 0, $pos), '/');
        if (!isset($idCache[$prefix])) { [$c, $j] = oaf_get($base . $prefix, $key); $id = null; if ($c === 200 && isset($j['data'])) { $d = $j['data']; if (isset($d[0]['id'])) $id = $d[0]['id']; elseif (isset($d['id'])) $id = $d['id']; } $idCache[$prefix] = $id; }
        if ($idCache[$prefix] === null) return null;
        $template = substr($template,0,$pos) . $idCache[$prefix] . substr($template,$pos+strlen($m[0][0]));
    }
    return $template;
}

$doc = apiV1BuildOpenApi();
foreach ($doc['paths'] as $tmpl => $ops) {
    if (!isset($ops['get'])) continue;
    $key = 'GET ' . $tmpl;
    if (!isset($responses[$key])) continue;
    $real = oaf_resolvePath($tmpl, $BASE, $KEY); if ($real === null) continue;
    [$code, $json] = oaf_get($BASE . $real, $KEY);
    if ($code !== 200 || !array_key_exists('data', $json)) continue;
    oaf_patch($responses[$key], $json['data'], $schemas);
}
$body = "<?php\n/**\n * FreeITSM REST API v1 — typed component schemas + per-endpoint response bindings\n"
      . " * for the OpenAPI generator. Derived from the resource serializers and verified\n"
      . " * against live responses (api/v1/dev/openapi_verify.php). Consumed by lib/openapi.php.\n */\n"
      . "return " . var_export(['schemas'=>$schemas, 'responses'=>$responses, 'requestBodies'=>$reqBodies], true) . ";\n";
file_put_contents($SCHEMA_FILE, $body);
echo "applied $patches patches; rewrote lib/openapi_schemas.php\n";
