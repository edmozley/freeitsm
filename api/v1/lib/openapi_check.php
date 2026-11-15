<?php
/**
 * FreeITSM REST API v1 — OpenAPI self-check (CLI).
 *
 *   php api/v1/lib/openapi_check.php
 *
 * Verifies the invariants that keep the generated OpenAPI document valid and
 * in step with the live API, using no external tooling:
 *   1. drift    — every route in the table has a docs/spec entry and vice versa
 *                 (so the spec can never silently omit or invent an endpoint)
 *   2. refs     — every $ref resolves to a defined component schema
 *   3. opIds    — operationIds are present and unique
 *   4. responses— every operation declares at least one response
 *   5. shape    — no empty array is emitted where OpenAPI requires an object
 *   6. perms    — every operation that needs a key permission says which (x-permission),
 *                 and every extra apiRequirePermission() a handler makes is listed
 *                 (x-permissions-also) - discussion #319
 *
 * Exits non-zero on any failure, so it can gate CI. For full JSON-Schema
 * conformance, additionally run `spectral lint` or `swagger-cli validate`
 * against /api/v1/openapi.json in a Node environment.
 */

require __DIR__ . '/openapi.php';

$fail = 0;
$line = fn($ok, $msg) => print(($ok ? "  ok   " : "  FAIL ") . $msg . "\n");

// --- 1. Drift: spec.json endpoints <-> route table --------------------------
$routes = require __DIR__ . '/routes.php';
$spec   = json_decode(file_get_contents(__DIR__ . '/../spec.json'), true);
$specEps = [];
foreach ($spec['spec'] as $sec) foreach ($sec['items'] as $it) $specEps[] = [$it['m'], $it['p']];
$coveredRoute = []; $unmapped = [];
foreach ($specEps as [$m, $p]) {
    $probe = preg_replace('/\{[^}]+\}/', '1', $p);
    $hit = null;
    foreach ($routes as $i => [$rm, $pat]) { if ($rm === $m && preg_match($pat, $probe)) { $hit = $i; break; } }
    if ($hit === null) $unmapped[] = "$m $p"; else $coveredRoute[$hit] = true;
}
$uncovered = [];
foreach ($routes as $i => [$rm, $pat, $perm, $h]) if (empty($coveredRoute[$i])) $uncovered[] = "$rm $h";
$driftOk = !$unmapped && !$uncovered;
$line($driftOk, "drift: " . count($specEps) . " spec endpoints <-> " . count($routes) . " routes"
    . ($driftOk ? " (clean bijection)" : " — unmapped=" . implode(',', $unmapped) . " uncovered=" . implode(',', $uncovered)));
if (!$driftOk) $fail++;

// --- Build the document once for the remaining checks -----------------------
$doc = apiV1BuildOpenApi();

// --- 2. $ref resolution ------------------------------------------------------
$defined = array_keys($doc['components']['schemas']);
$refs = [];
$walk = function ($n) use (&$walk, &$refs) { if (is_array($n)) foreach ($n as $k => $v) { if ($k === '$ref' && is_string($v)) $refs[] = $v; else $walk($v); } };
$walk($doc);
$dangling = [];
foreach (array_unique($refs) as $r) { $name = preg_replace('~^#/components/schemas/~', '', $r); if (!in_array($name, $defined, true)) $dangling[] = $r; }
$line(!$dangling, "refs: " . count(array_unique($refs)) . " distinct, " . count($defined) . " schemas defined" . ($dangling ? " — dangling: " . implode(',', $dangling) : ", 0 dangling"));
if ($dangling) $fail++;

// --- 3. operationId uniqueness ----------------------------------------------
$ids = [];
foreach ($doc['paths'] as $p) foreach ($p as $op) if (isset($op['operationId'])) $ids[] = $op['operationId'];
$opOk = count($ids) === count(array_unique($ids)) && !in_array('', $ids, true);
$line($opOk, "operationIds: " . count($ids) . " total, " . count(array_unique($ids)) . " unique");
if (!$opOk) $fail++;

// --- 4. every operation has responses ---------------------------------------
$noResp = 0; $ops = 0;
foreach ($doc['paths'] as $p) foreach ($p as $op) { $ops++; if (empty($op['responses'])) $noResp++; }
$line($noResp === 0, "responses: $ops operations, $noResp missing a responses object");
if ($noResp) $fail++;

// --- 5. no empty array where an object is required (JSON serialization) ------
$json = json_encode($doc, JSON_UNESCAPED_SLASHES);
$badEmpties = preg_match_all('/"(items|properties|schema|responses|content|components)":\[\]/', $json);
$line($badEmpties === 0, "shape: $badEmpties object-typed fields wrongly serialized as []");
if ($badEmpties) $fail++;

// --- 6. no `nullable` without a `type` (meta-schema allows it, linters don't) -
// A strict linter treats `nullable` as requiring a sibling `type` — `allOf`,
// `anyOf`, `oneOf` and a bare `$ref` do NOT satisfy it (a nullable $ref must be
// written as `type` + `allOf: [{$ref}]`), so this flags all of those too.
$bareNullable = 0;
$walkN = function ($n) use (&$walkN, &$bareNullable) {
    if (!is_array($n)) return;
    if (isset($n['nullable']) && !isset($n['type'])) $bareNullable++;
    foreach ($n as $v) if (is_array($v)) $walkN($v);
};
$walkN($doc['components']['schemas']);
$line($bareNullable === 0, "nullable: $bareNullable schema node(s) use `nullable` without a `type`");
if ($bareNullable) $fail++;

// --- 7. spec.json catalogue is well-formed (safe hand-editing) ---------------
// spec.json is the single source read by both the docs page and this generator,
// so a malformed entry would break both. Check each item and every extras key.
$catErrs = [];
$validMethods = ['GET', 'POST', 'PATCH', 'DELETE'];
$specKeys = [];
foreach ($spec['spec'] as $si => $sec) {
    if (!isset($sec['section']) || !isset($sec['items']) || !is_array($sec['items'])) { $catErrs[] = "section[$si] missing 'section' or 'items'"; continue; }
    foreach ($sec['items'] as $ii => $it) {
        $where = "'{$sec['section']}'[$ii]";
        if (!isset($it['m']) || !in_array($it['m'], $validMethods, true)) $catErrs[] = "$where: bad or missing method 'm'";
        if (!isset($it['p']) || $it['p'] === '' || $it['p'][0] !== '/') $catErrs[] = "$where: 'p' must be a path starting with '/'";
        if (!isset($it['s']) || trim((string)$it['s']) === '') $catErrs[] = "$where ({$it['p']}): missing summary 's'";
        if (!array_key_exists('perm', $it)) $catErrs[] = "$where ({$it['p']}): missing 'perm'";
        foreach ($it['params'] ?? [] as $pi => $p) {
            if (!isset($p['name']) || $p['name'] === '') $catErrs[] = "$where ({$it['p']}) param[$pi]: missing 'name'";
            if (!isset($p['in']) || !in_array($p['in'], ['path', 'query', 'body'], true)) $catErrs[] = "$where ({$it['p']}) param[$pi]: 'in' must be path|query|body";
        }
        if (isset($it['m'], $it['p'])) $specKeys[$it['m'] . ' ' . $it['p']] = true;
    }
}
foreach (array_keys($spec['extras'] ?? []) as $k) {
    if (!preg_match('/^(GET|POST|PATCH|DELETE) \//', $k)) { $catErrs[] = "extras key '$k' is not \"METHOD /path\""; continue; }
    if (!isset($specKeys[$k])) $catErrs[] = "extras key '$k' has no matching endpoint in the catalogue";
}
$line(!$catErrs, "catalogue: " . count($specKeys) . " endpoints well-formed" . ($catErrs ? " — " . count($catErrs) . " problem(s): " . implode('; ', array_slice($catErrs, 0, 5)) : ", extras keys all resolve"));
if ($catErrs) $fail++;

// --- 6. Permissions: x-permission on every operation that needs one -------------
$doc = apiV1BuildOpenApi();
$permErrs = []; $withAlso = 0; $withPerm = 0;
$routePerm = apiV1RoutePermissions();
$e2h = apiV1MapEndpointsToHandlers($routes);
foreach ($doc['paths'] as $p => $ops) foreach ($ops as $m => $op) {
    $h = $e2h[strtoupper($m) . ' ' . $p] ?? null;
    $want = $h !== null ? ($routePerm[$h] ?? null) : null;
    if (($op['x-permission'] ?? null) !== $want) $permErrs[] = strtoupper($m) . " $p: x-permission " . json_encode($op['x-permission'] ?? null) . ', route says ' . json_encode($want);
    if (isset($op['x-permission'])) { $withPerm++; if (!preg_match('/^\w+\.\w+$/', $op['x-permission'])) $permErrs[] = "$p: malformed x-permission"; }
    if (!empty($op['x-permissions-also'])) $withAlso++;
}
// Every handler-level check must surface somewhere (a helper that is not a route handler would not).
$handlers = array_flip(array_values($e2h));
foreach (apiV1HandlerExtraPermissions() as $h => $ps) if (!isset($handlers[$h])) $permErrs[] = "$h checks " . implode(',', $ps) . ' but is not a route handler: move the check or list it by hand';
$line(!$permErrs, "perms: $withPerm operations carry x-permission, $withAlso also x-permissions-also" . ($permErrs ? ' — ' . implode('; ', array_slice($permErrs, 0, 5)) : ''));
if ($permErrs) $fail++;

echo $fail === 0 ? "\nOpenAPI self-check: PASS\n" : "\nOpenAPI self-check: $fail FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
