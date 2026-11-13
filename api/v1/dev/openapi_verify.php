<?php
/**
 * Verify the typed OpenAPI response schemas against LIVE responses from a
 * running install. For every GET endpoint it resolves any {id} parameters from
 * a sibling collection, fetches the real response, and validates the `data`
 * payload against the bound schema — OpenAPI-aware ($ref, allOf, nullable).
 * Reports type violations AND response fields the schema fails to document.
 *
 * Usage (CLI only):
 *   php api/v1/dev/openapi_verify.php <api_key> [base_url]
 *   FITSM_API_KEY=fitsm_... php api/v1/dev/openapi_verify.php
 *
 * The key needs read access to the resources you want checked. base_url
 * defaults to the local install's front controller. Run openapi_fix.php first
 * to auto-apply the fixes this reports, then re-run this to confirm zero.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$KEY  = $argv[1] ?? getenv('FITSM_API_KEY') ?: '';
$BASE = $argv[2] ?? getenv('FITSM_API_BASE') ?: 'http://localhost/freeitsm-app/api/v1/index.php';
if ($KEY === '') { fwrite(STDERR, "Provide an API key: php openapi_verify.php <key> [base_url]\n"); exit(2); }

require __DIR__ . '/../lib/openapi.php';
$doc = apiV1BuildOpenApi();
$components = $doc['components']['schemas'];
$respMap = (require __DIR__ . '/../lib/openapi_schemas.php')['responses'];

/**
 * GETs one API URL with the bearer key and returns [HTTP code, decoded JSON].
 *
 * @param mixed $url  the full API URL to fetch
 * @param mixed $key  the API key, sent as a bearer token
 */
function oav_get($url, $key) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key], CURLOPT_TIMEOUT=>20]);
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, json_decode($b, true)];
}
/**
 * Follows a schema's $ref chain into the component schemas and returns the schema it
 * ends on (an empty array when a name is missing).
 *
 * @param mixed $schema  a schema that may be a $ref to a component schema
 * @param mixed $components  the component schemas, keyed by name
 */
function oav_resolveRef($schema, $components) {
    $g = 0;
    while (isset($schema['$ref']) && $g++ < 20) { $n = preg_replace('~^#/components/schemas/~', '', $schema['$ref']); $schema = $components[$n] ?? []; }
    return $schema;
}
/**
 * Validates a live value against an OpenAPI schema ($ref, allOf, nullable) and returns
 * every problem as "path: message" - type mismatches, missing required properties and
 * response fields the schema does not document.
 *
 * @param mixed $data  the live value from the API response
 * @param mixed $schema  the documented schema for it
 * @param mixed $components  the component schemas, keyed by name, for $ref lookups
 * @param mixed $path  where $data sits in the response, for error messages, e.g. data.items/0
 * @param mixed $depth  how deep the recursion is; it stops past 40
 */
function oav_validate($data, $schema, $components, $path = 'data', $depth = 0) {
    $errs = [];
    if ($depth > 40) return $errs;
    $schema = oav_resolveRef($schema, $components);
    if (!$schema) return $errs;
    if (isset($schema['allOf'])) {
        // An allOf of objects (TicketDetail = Ticket + the detail-only fields) is ONE object: merge the
        // parts and check it once. Checked part by part, every field documented in the other part
        // was reported as undocumented (GH #328 tidy).
        $parts = array_map(fn($p) => oav_resolveRef($p, $components), $schema['allOf']);
        if ($parts && !array_filter($parts, fn($p) => !$p || ($p['type'] ?? 'object') !== 'object' || isset($p['allOf']))) {
            $merged = ['type' => 'object', 'properties' => [], 'required' => []];
            foreach ($parts as $p) {
                $merged['properties'] = array_merge($merged['properties'], $p['properties'] ?? []);
                $merged['required'] = array_merge($merged['required'], $p['required'] ?? []);
                if (!empty($p['nullable'])) $merged['nullable'] = true;
                if (isset($p['additionalProperties'])) $merged['additionalProperties'] = $p['additionalProperties'];
            }
            return oav_validate($data, $merged, $components, $path, $depth+1);
        }
        foreach ($schema['allOf'] as $s) $errs = array_merge($errs, oav_validate($data, $s, $components, $path, $depth+1));
        return $errs;
    }
    if ($data === null) {
        if (!empty($schema['nullable']) || !isset($schema['type'])) return $errs;
        return ["$path: got null but schema is not nullable"];
    }
    if (!isset($schema['type'])) return $errs;
    switch ($schema['type']) {
        case 'integer': if (!is_int($data)) $errs[] = "$path: expected integer, got ".gettype($data); break;
        case 'number':  if (!is_int($data) && !is_float($data)) $errs[] = "$path: expected number, got ".gettype($data); break;
        case 'string':  if (!is_string($data)) $errs[] = "$path: expected string, got ".gettype($data); break;
        case 'boolean': if (!is_bool($data)) $errs[] = "$path: expected boolean, got ".gettype($data); break;
        case 'array':
            if (!is_array($data) || ($data !== [] && array_keys($data) !== range(0, count($data)-1))) { $errs[] = "$path: expected array, got ".gettype($data); break; }
            if (isset($schema['items'])) foreach ($data as $i => $v) $errs = array_merge($errs, oav_validate($v, $schema['items'], $components, "$path/$i", $depth+1));
            break;
        case 'object':
            if (!is_array($data) || ($data !== [] && array_is_list($data))) { $errs[] = "$path: expected object, got ".(is_array($data)?'array':gettype($data)); break; }
            $props = $schema['properties'] ?? [];
            foreach ($props as $k => $psub) {
                if (array_key_exists($k, $data)) $errs = array_merge($errs, oav_validate($data[$k], $psub, $components, "$path.$k", $depth+1));
                elseif (in_array($k, $schema['required'] ?? [], true)) $errs[] = "$path.$k: required property missing from response";
            }
            // additionalProperties: a map (permissions by resource, custom fields by key, an event payload) -
            // other keys ARE documented; a schema there is what each must match.
            $extra = $schema['additionalProperties'] ?? null;
            foreach ($data as $k => $v) {
                if (isset($props[$k])) continue;
                if (is_array($extra)) $errs = array_merge($errs, oav_validate($v, $extra, $components, "$path.$k", $depth+1));
                elseif ($extra !== true && $props) $errs[] = "$path.$k: response field NOT documented in schema";
            }
            break;
    }
    return $errs;
}
$idCache = [];
/**
 * Turns a path template into a real path by replacing each {id} with the first id its
 * parent collection returns - null when there is no row to use. Ids are cached per
 * collection.
 *
 * @param mixed $template  an OpenAPI path template, e.g. /tickets/{id}/notes
 * @param mixed $base  the API's base URL, e.g. http://localhost/freeitsm-app/api/v1/index.php
 * @param mixed $key  the API key, sent as a bearer token
 */
function oav_resolvePath($template, $base, $key) {
    global $idCache;
    while (preg_match('/\{([^}]+)\}/', $template, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1]; $prefix = rtrim(substr($template, 0, $pos), '/');
        if (!isset($idCache[$prefix])) {
            [$c, $j] = oav_get($base . $prefix, $key); $id = null;
            if ($c === 200 && isset($j['data'])) { $d = $j['data']; if (isset($d[0]['id'])) $id = $d[0]['id']; elseif (isset($d['id'])) $id = $d['id']; }
            $idCache[$prefix] = $id;
        }
        if ($idCache[$prefix] === null) return null;
        $template = substr($template,0,$pos) . $idCache[$prefix] . substr($template,$pos+strlen($m[0][0]));
    }
    return $template;
}

$pass = 0; $skip = 0; $failEps = []; $problems = [];
foreach ($doc['paths'] as $tmpl => $ops) {
    if (!isset($ops['get'])) continue;
    $key = 'GET ' . $tmpl;
    if (!isset($respMap[$key])) continue;
    $real = oav_resolvePath($tmpl, $BASE, $KEY);
    if ($real === null) { $skip++; $problems[] = "SKIP  $key (no live id to resolve)"; continue; }
    [$code, $json] = oav_get($BASE . $real, $KEY);
    if ($code !== 200 || !array_key_exists('data', $json)) { $skip++; $problems[] = "SKIP  $key (HTTP $code)"; continue; }
    $errs = oav_validate($json['data'], $respMap[$key], $components, 'data');
    if (!$errs) $pass++; else { $failEps[] = $key; foreach (array_slice($errs, 0, 6) as $e) $problems[] = "FAIL  $key -> $e"; }
}
echo "LIVE VERIFICATION: $pass passed, ".count($failEps)." failed, $skip skipped\n\n";
foreach ($problems as $p) echo $p . "\n";
exit(count($failEps) ? 1 : 0);
