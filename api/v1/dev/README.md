# OpenAPI dev tools

Maintenance tooling for the generated OpenAPI document (`/api/v1/openapi.json`).
All are **CLI-only** (they refuse to run over the web) and need nothing installed
beyond PHP — no Node, Python or Composer.

**`../spec.json` is the single source of truth for the endpoint catalogue** —
summaries, descriptions, parameters, request examples and endpoint-specific
errors. It is read by BOTH the interactive docs page (`system/api/docs.php`) and
the OpenAPI generator (`../lib/openapi.php`), so you edit it in one place and
both update. The generator also reads the route table (`../lib/routes.php`) and
the typed schemas (`../lib/openapi_schemas.php`). These tools keep everything in
step and prove the result is valid and accurate.

## When you add or change an API endpoint or module

1. Add the route(s) to `../lib/routes.php` and write the handler(s); add any new
   permission to `../lib/permissions.php`. (Business as usual.)
2. Scaffold the catalogue entries and paste them into the right section of
   `../spec.json`, then fill in the summary, description, params, examples and
   any endpoint-specific errors:
   ```
   php openapi_stub.php
   ```
   This one edit updates both the docs page and the OpenAPI document.
3. Create a temporary read key (System → API) with the permissions you need, then
   bring the typed schemas in line with the live serializers:
   ```
   php openapi_fix.php <read_key>     # re-run until it reports 0 patches
   ```
4. Confirm every schema matches a real response:
   ```
   php openapi_verify.php <read_key>  # aim for 0 failed (a few id-only endpoints may SKIP)
   ```
5. Confirm the invariants (drift, refs, operationIds, responses, shape, nullable,
   catalogue):
   ```
   php ../lib/openapi_check.php
   ```
6. Confirm conformance to the official OpenAPI 3.0 meta-schema:
   ```
   curl -s http://localhost/freeitsm-app/api/v1/openapi.json > /tmp/o.json
   php jsonschema4.php /tmp/o.json oas-3.0-schema.json
   ```
7. For the authoritative linter pass (optional, needs Node):
   ```
   npx @stoplight/spectral-cli lint http://localhost/freeitsm-app/api/v1/openapi.json
   npx swagger-cli validate http://localhost/freeitsm-app/api/v1/openapi.json
   ```

The drift-guard in step 5 fails if a route has no catalogue entry (or vice
versa), so you cannot forget step 2.

## Files

| File | What it does |
|---|---|
| `openapi_verify.php` | Fetches every GET endpoint live and validates `data` against its bound schema (OpenAPI-aware: `$ref`, `allOf`, `nullable`, `additionalProperties`). Reports type violations and undocumented response fields. An `allOf` of objects is merged and checked as one object; a key covered by `additionalProperties` is documented. Give the read key a high rate limit or later endpoints SKIP with 429. |
| `openapi_fix.php` | Auto-patches the typed schemas from live responses — adds missing fields (type inferred from the live value), marks `nullable`, relaxes over-strict `required`. Idempotent. **TRAP: it rewrites `openapi_schemas.php` through `var_export`, which strips the hand-written comments and drops the top-level `requestContentTypes` and `responseContents` sections. Never run it on the real file** - run it on a copy and use the difference as a list, then add the fields by hand. It also infers a type from ONE sample (a map keyed by form labels becomes fixed properties), so read what it proposes. |
| `jsonschema4.php` | Minimal draft-04 JSON-Schema validator; checks the generated document against `oas-3.0-schema.json`. Proven to reject malformed documents. |
| `oas-3.0-schema.json` | The official OpenAPI 3.0 meta-schema (from spec.openapis.org), for offline validation. |
| `openapi_stub.php` | Scaffolds ready-to-paste `spec.json` entries for any route not yet documented. |

The permanent, dependency-free self-check that gates the invariants lives one
level up at `../lib/openapi_check.php`.
