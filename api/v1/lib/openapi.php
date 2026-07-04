<?php
/**
 * FreeITSM REST API v1 — OpenAPI 3.0.3 document generator.
 *
 * Derives the spec at request time from the same sources the product already
 * maintains, so it cannot drift from the live API:
 *   - lib/routes.php      — the authoritative route table (paths, methods,
 *                           permission tuples, handler names)
 *   - spec.json           — the documentation catalogue shared with the
 *                           interactive docs page (summaries, descriptions,
 *                           parameters, request examples, endpoint-specific errors)
 *   - lib/permissions.php — the permission catalogue (security scopes)
 *   - lib/openapi_schemas.php — typed component schemas + per-endpoint response
 *                           bindings (verified against live responses)
 *   - the resource files  — scanned for their apiRespond() status codes so each
 *                           operation declares the success code it actually returns
 *
 * 3.0.3 is targeted deliberately for the widest tooling compatibility (Swagger
 * UI, Redoc, Postman, Insomnia, client generators). Emitted as JSON (canonical)
 * or YAML (same document, human-readable).
 */

require_once __DIR__ . '/permissions.php';

/** Build the complete OpenAPI 3.0.3 document as a PHP array. */
function apiV1BuildOpenApi(): array {
    $spec    = json_decode(file_get_contents(__DIR__ . '/../spec.json'), true);
    $routes  = require __DIR__ . '/routes.php';
    $schemas = file_exists(__DIR__ . '/openapi_schemas.php') ? (require __DIR__ . '/openapi_schemas.php') : ['schemas' => [], 'responses' => []];
    $catalog = apiV1PermissionCatalog();

    $endpointToHandler = apiV1MapEndpointsToHandlers($routes);
    $handlerSuccess    = apiV1HandlerSuccessCodes();

    // --- Tags (one per docs section) --------------------------------------
    $tags = [];
    foreach ($spec['spec'] as $section) {
        $tags[] = ['name' => $section['section'], 'description' => 'Endpoints in the ' . $section['section'] . ' area.'];
    }

    // --- Paths ------------------------------------------------------------
    $paths = [];
    foreach ($spec['spec'] as $section) {
        foreach ($section['items'] as $ep) {
            $path   = $ep['p'];
            $method = strtolower($ep['m']);
            $key    = $ep['m'] . ' ' . $ep['p'];
            $extras = $spec['extras'][$key] ?? [];

            $op = apiV1BuildOperation($ep, $section['section'], $extras, $endpointToHandler, $handlerSuccess, $schemas);
            $paths[$path][$method] = $op;
        }
    }

    $document = [
        'openapi' => '3.0.3',
        'info' => [
            'title'       => 'FreeITSM REST API',
            'version'     => '1.0.0',
            'description' => apiV1OpenApiInfoDescription(),
            'contact'     => ['name' => 'FreeITSM', 'url' => 'https://freeitsm.co.uk'],
            'license'     => ['name' => 'MIT', 'url' => 'https://github.com/edmozley/freeitsm/blob/main/LICENSE'],
        ],
        // Relative server URL resolves against wherever the document is served
        // from, so the same spec is correct on every install without a hostname.
        'servers' => [['url' => '/api/v1', 'description' => 'This install']],
        'tags'    => $tags,
        'security' => [['bearerAuth' => []]],
        'paths'   => $paths,
        'components' => [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type'   => 'http',
                    'scheme' => 'bearer',
                    'description' => 'An API key from System &rsaquo; API, sent as "Authorization: Bearer fitsm_...". '
                        . 'Keys carry granular per-resource permissions, an optional company scope, and act as a named analyst.',
                ],
            ],
            'schemas'   => apiV1BaseSchemas() + ($schemas['schemas'] ?? []),
        ],
    ];

    return $document;
}

/** The document-level description shown at the top of Swagger UI / Redoc. */
function apiV1OpenApiInfoDescription(): string {
    return "The FreeITSM REST API. Every request is authenticated with an API key "
        . "(`Authorization: Bearer fitsm_...`); keys start with zero permissions and are "
        . "granted granular per-resource, per-action rights, optionally scoped to specific "
        . "companies.\n\n"
        . "Success responses are `{ \"data\": ... }`, with a `meta` block on paginated lists. "
        . "Errors are `{ \"error\": { \"code\": ..., \"message\": ... } }` with a real HTTP status. "
        . "Timestamps are UTC ISO 8601, except the calendar module, which uses naive "
        . "server-local datetimes (noted on those operations). Writes behave exactly like the "
        . "web UI — audit rows, workflow events, emails and SLA all fire.\n\n"
        . "The default rate limit is 60 requests/minute per key; every response carries "
        . "`X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`.";
}

/** Base component schemas that always exist (envelope, error, pagination). */
function apiV1BaseSchemas(): array {
    return [
        'PaginationMeta' => [
            'type' => 'object',
            'description' => 'Present on paginated list responses.',
            'properties' => [
                'page'        => ['type' => 'integer', 'example' => 1],
                'per_page'    => ['type' => 'integer', 'example' => 25],
                'total'       => ['type' => 'integer', 'example' => 137],
                'total_pages' => ['type' => 'integer', 'example' => 6],
            ],
        ],
        'ErrorResponse' => [
            'type' => 'object',
            'required' => ['error'],
            'properties' => [
                'error' => [
                    'type' => 'object',
                    'required' => ['code', 'message'],
                    'properties' => [
                        'code'    => ['type' => 'string', 'description' => 'A stable machine-readable error slug.', 'example' => 'invalid_field'],
                        'message' => ['type' => 'string', 'description' => 'A human-readable explanation.'],
                        'details' => ['type' => 'object', 'nullable' => true, 'description' => 'Optional structured context.'],
                    ],
                ],
            ],
        ],
    ];
}

/** Build one OpenAPI Operation Object for a docs endpoint. */
function apiV1BuildOperation(array $ep, string $section, array $extras, array $endpointToHandler, array $handlerSuccess, array $schemas): array {
    $key = $ep['m'] . ' ' . $ep['p'];
    $op = [
        'tags'        => [$section],
        'summary'     => $ep['s'],
        'operationId' => apiV1OperationId($ep['m'], $ep['p']),
    ];
    if (!empty($ep['d'])) {
        $op['description'] = $ep['d'];
    }
    // A spec.json entry with "deprecated": true marks the whole operation
    // deprecated (tools strike it through / warn).
    if (!empty($ep['deprecated'])) {
        $op['deprecated'] = true;
    }

    // --- Parameters -------------------------------------------------------
    $parameters = [];
    // Path parameters from the {tokens} in the template (all ids are integers here).
    if (preg_match_all('/\{([^}]+)\}/', $ep['p'], $m)) {
        foreach ($m[1] as $name) {
            $desc = apiV1ParamDescription($ep, $name, 'path');
            $parameters[] = [
                'name' => $name, 'in' => 'path', 'required' => true,
                'schema' => ['type' => 'integer'],
                'description' => $desc !== '' ? $desc : ucfirst(str_replace('_', ' ', $name)) . '.',
            ];
        }
    }
    // Query parameters from the docs params (combined "a / b" names are split).
    foreach ($ep['params'] ?? [] as $p) {
        if (($p['in'] ?? '') !== 'query') continue;
        foreach (array_map('trim', explode('/', $p['name'])) as $qname) {
            if ($qname === '') continue;
            $param = [
                'name' => $qname, 'in' => 'query',
                'required' => (bool)($p['req'] ?? false),
                'schema' => ['type' => 'string'],
                'description' => $p['desc'] ?? '',
            ];
            // A param entry with "deprecated": true marks just that parameter.
            if (!empty($p['deprecated'])) {
                $param['deprecated'] = true;
            }
            $parameters[] = $param;
        }
    }
    if ($parameters) {
        $op['parameters'] = $parameters;
    }

    // --- Request body -----------------------------------------------------
    if (in_array($ep['m'], ['POST', 'PATCH'], true)) {
        $examples = [];
        foreach ($extras['examples'] ?? [] as $i => $ex) {
            if (!array_key_exists('body', $ex)) continue;
            $examples['example' . ($i + 1)] = array_filter([
                'summary'     => $ex['title'] ?? null,
                'description' => $ex['note'] ?? null,
                'value'       => $ex['body'],
            ], fn($v) => $v !== null);
        }
        if (!$examples && isset($ep['body'])) {
            $examples['example1'] = ['value' => $ep['body']];
        }
        $bodySchema = $schemas['requestBodies'][$key] ?? ['type' => 'object'];
        $content = ['schema' => $bodySchema];
        if ($examples) {
            $content['examples'] = $examples;
        }
        $op['requestBody'] = [
            'required' => $ep['m'] === 'POST',
            'content'  => ['application/json' => $content],
        ];
    }

    // --- Responses --------------------------------------------------------
    $op['responses'] = apiV1BuildResponses($ep, $extras, $endpointToHandler, $handlerSuccess, $schemas);

    return $op;
}

/** Assemble the responses map: success code(s) + every error the endpoint can return. */
function apiV1BuildResponses(array $ep, array $extras, array $endpointToHandler, array $handlerSuccess, array $schemas): array {
    $key = $ep['m'] . ' ' . $ep['p'];
    $responses = [];

    // Success codes come from the handler's actual apiRespond() calls.
    $handler = $endpointToHandler[$key] ?? null;
    $codes = $handler !== null ? ($handlerSuccess[$handler] ?? [200]) : [200];
    if (!$codes) $codes = [200];

    $dataSchema = $schemas['responses'][$key] ?? null; // typed binding, else generic
    foreach ($codes as $code) {
        $envelope = apiV1SuccessEnvelope($dataSchema);
        $responses[(string)$code] = [
            'description' => $code === 201 ? 'Created.' : 'Success.',
            'content' => ['application/json' => ['schema' => $envelope]],
        ];
    }

    // Errors: endpoint-specific (from extras) merged with the derived defaults.
    foreach (apiV1EndpointErrors($ep, $extras) as $code => $description) {
        if (isset($responses[(string)$code])) continue;
        $responses[(string)$code] = [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
        ];
    }
    ksort($responses, SORT_STRING);
    return $responses;
}

/** Wrap a data schema (or a generic object) in the { data, meta? } envelope. */
function apiV1SuccessEnvelope(?array $dataSchema): array {
    $data = $dataSchema ?? ['description' => 'The response payload.'];
    return [
        'type' => 'object',
        'properties' => [
            'data' => $data,
            'meta' => ['allOf' => [['$ref' => '#/components/schemas/PaginationMeta']], 'nullable' => true],
        ],
    ];
}

/**
 * The errors an endpoint can return: the common ones derived from its shape,
 * plus any endpoint-specific ones from the docs extras. Mirrors the docs page's
 * errorsOf() so the two never disagree. Returns [code => description].
 */
function apiV1EndpointErrors(array $ep, array $extras): array {
    $glossary = [
        400 => 'The request body is not valid JSON, or a parameter has an invalid value.',
        401 => 'The API key is missing or invalid.',
        403 => 'The key lacks the permission this endpoint requires.',
        404 => 'No record with that id (or it is outside the key\'s company scope).',
        405 => 'The path exists but not with this HTTP method.',
        409 => 'The request collides with current state.',
        422 => 'A required field is missing, or a field value is not acceptable.',
        429 => 'The key exceeded its per-minute rate limit.',
        500 => 'An unexpected server-side error.',
    ];
    $out = [];
    // Specific errors first (their wording wins).
    foreach ($extras['errors'] ?? [] as $e) {
        $out[(int)$e['code']] = $e['when'] ?? ($glossary[(int)$e['code']] ?? 'Error.');
    }
    // Derived defaults.
    $add = function ($code, $desc) use (&$out) { if (!isset($out[$code])) $out[$code] = $desc; };
    $add(401, $glossary[401]);
    if (($ep['perm'] ?? 'none') !== 'none') {
        $add(403, 'The key does not have the ' . $ep['perm'] . ' permission.');
    }
    if (strpos($ep['p'], '{') !== false) {
        $add(404, $glossary[404]);
    }
    if (in_array($ep['m'], ['POST', 'PATCH'], true)) {
        $add(400, 'The request body is not valid JSON.');
        $add(422, $glossary[422]);
    }
    $add(429, $glossary[429]);
    ksort($out);
    return $out;
}

/** A stable, unique operationId from method + path. */
function apiV1OperationId(string $method, string $path): string {
    $slug = strtolower($method) . '_' . trim(preg_replace('/[^a-z0-9]+/i', '_', str_replace(['{', '}'], '', $path)), '_');
    return trim(preg_replace('/_+/', '_', $slug), '_');
}

/** Pull a parameter description from the docs params for a given name/in. */
function apiV1ParamDescription(array $ep, string $name, string $in): string {
    foreach ($ep['params'] ?? [] as $p) {
        if (($p['in'] ?? '') !== $in) continue;
        foreach (array_map('trim', explode('/', $p['name'])) as $pn) {
            if ($pn === $name) return $p['desc'] ?? '';
        }
    }
    return '';
}

/**
 * Match every docs endpoint (method + path template) to its handler function by
 * testing the template against the route regexes. Returns ['M /path' => handler].
 */
function apiV1MapEndpointsToHandlers(array $routes): array {
    $spec = json_decode(file_get_contents(__DIR__ . '/../spec.json'), true);
    $map = [];
    foreach ($spec['spec'] as $section) {
        foreach ($section['items'] as $ep) {
            $probe = preg_replace('/\{[^}]+\}/', '1', $ep['p']); // {id} -> 1
            foreach ($routes as [$rMethod, $pattern, $perm, $handler]) {
                if ($rMethod !== $ep['m']) continue;
                if (preg_match($pattern, $probe)) {
                    $map[$ep['m'] . ' ' . $ep['p']] = $handler;
                    break;
                }
            }
        }
    }
    return $map;
}

/**
 * Scan the resource files for each handler's apiRespond() status codes, so an
 * operation declares the success code(s) it genuinely returns (201 on create,
 * 200 otherwise, both where a handler upserts). Returns [handler => [codes]].
 */
function apiV1HandlerSuccessCodes(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (glob(__DIR__ . '/../resources/*.php') as $file) {
        $src = file_get_contents($file);
        // Split into function bodies.
        if (!preg_match_all('/function\s+(api\w+)\s*\(/', $src, $fm, PREG_OFFSET_CAPTURE)) continue;
        $count = count($fm[1]);
        for ($i = 0; $i < $count; $i++) {
            $name  = $fm[1][$i][0];
            $start = $fm[0][$i][1];
            $end   = ($i + 1 < $count) ? $fm[0][$i + 1][1] : strlen($src);
            $body  = substr($src, $start, $end - $start);
            $codes = [];
            // Any apiRespond(..., 201) => a 201 path; any apiRespond without a
            // 201 status => a 200 path.
            $has201 = preg_match('/apiRespond\s*\([^;]*?,\s*201\b/s', $body);
            $respondCalls = preg_match_all('/apiRespond\s*\(/', $body);
            $explicit201  = preg_match_all('/,\s*201\b/', $body);
            if ($has201) $codes[] = 201;
            if ($respondCalls > $explicit201) $codes[] = 200; // at least one non-201 respond
            if (!$codes) $codes[] = 200;
            $cache[$name] = array_values(array_unique($codes));
            sort($cache[$name]);
        }
    }
    return $cache;
}

// ---------------------------------------------------------------------------
// YAML emitter — same document, human-readable. Minimal + conservative: any
// scalar that isn't an unambiguous plain string is double-quoted, which
// sidesteps every YAML type-coercion footgun (the "Norway problem", version
// numbers, leading zeros, dates). No dependency on ext-yaml.
// ---------------------------------------------------------------------------

function apiV1OpenApiToYaml(array $data): string {
    return "# FreeITSM REST API — OpenAPI 3.0.3 (generated)\n" . apiV1YamlNode($data, 0);
}

function apiV1YamlNode($value, int $indent): string {
    $pad = str_repeat('  ', $indent);
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (empty($value)) {
            return $isList ? "[]\n" : "{}\n";
        }
        $out = '';
        if ($isList) {
            foreach ($value as $item) {
                if (is_array($item) && !empty($item)) {
                    $child = apiV1YamlNode($item, $indent + 1);
                    // Hang the first child key on the "- " line.
                    $child = preg_replace('/^' . preg_quote(str_repeat('  ', $indent + 1), '/') . '/', $pad . '- ', $child, 1);
                    $out .= $child;
                } else {
                    $out .= $pad . '- ' . apiV1YamlScalar($item) . "\n";
                }
            }
        } else {
            foreach ($value as $k => $v) {
                $keyStr = apiV1YamlKey((string)$k);
                if (is_array($v) && !empty($v)) {
                    $out .= $pad . $keyStr . ":\n" . apiV1YamlNode($v, $indent + 1);
                } elseif (is_array($v)) {
                    $out .= $pad . $keyStr . ': ' . (array_keys($v) === range(0, count($v) - 1) ? "[]" : "{}") . "\n";
                } else {
                    $out .= $pad . $keyStr . ': ' . apiV1YamlScalar($v) . "\n";
                }
            }
        }
        return $out;
    }
    return $pad . apiV1YamlScalar($value) . "\n";
}

function apiV1YamlKey(string $k): string {
    return preg_match('/^[A-Za-z_][A-Za-z0-9_\-\.\/{}$]*$/', $k) ? $k : apiV1YamlQuote($k);
}

function apiV1YamlScalar($v): string {
    if ($v === null)  return 'null';
    if ($v === true)  return 'true';
    if ($v === false) return 'false';
    if (is_int($v) || is_float($v)) return (string)$v;
    $s = (string)$v;
    // Plain scalar only if it's clearly a safe string; otherwise quote.
    if ($s !== '' && preg_match('/^[A-Za-z][A-Za-z0-9 _\-\.\/]*$/', $s)
        && !preg_match('/^(true|false|null|yes|no|on|off|y|n)$/i', $s)) {
        return $s;
    }
    return apiV1YamlQuote($s);
}

function apiV1YamlQuote(string $s): string {
    $s = str_replace(['\\', '"', "\n", "\t", "\r"], ['\\\\', '\\"', '\\n', '\\t', '\\r'], $s);
    return '"' . $s . '"';
}
