<?php
/* 🔴 NEVER OVER THE WEB. See tests/web-exposure-guard.php. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * No endpoint may change data on a GET.
 *
 *   php tests/get-must-not-write.php
 *
 * 🔴 Reported privately, October 2026. includes/csrf.php checks the token and the
 * origin on POST, PUT, PATCH and DELETE, and said in its own header that GET "must
 * not change anything" - but nothing made that true. The session cookie is
 * SameSite=Lax, which browsers DO send on a top-level GET from another site, so a
 * link on any web page could make whoever clicked it:
 *   - permanently empty their company's ticket trash (api/tickets/empty_trash.php);
 *   - run Database Verification, or delete a ticket through debug tool D002;
 *   - switch module permissions from "least" to "most", blank the branding, wipe
 *     the SLA settings, clear the public base URL - endpoints that read only a JSON
 *     body, where a link sends no body, every field falls back to its default, and
 *     the defaults passed validation and were saved.
 * About twenty endpoints in all. The fix is one rule: an endpoint that writes calls
 * csrfRequireWriteMethod() before it writes. This test is the rule's enforcement.
 *
 * Checks, in order:
 *   1. the scanner itself, against small fixtures - including a check that only
 *      appears in a comment, and SQL that only appears in a comment;
 *   2. every PHP file under api/ that contains write SQL;
 *   3. that every entry on the allow-list below is still a real file, still
 *      writes, and still has no check (a stale entry is an exemption nobody needs).
 *
 * Reads files only: no database, no web server.
 *
 * TRAP: "it writes" is decided by SQL in the file itself. An endpoint that writes
 *   only through an include function (no SQL of its own) is not seen here - give
 *   it the check anyway.
 *
 * TRAP: a file that reads $_SERVER['REQUEST_METHOD'] passes, because this cannot
 *   tell a check that guards the writes from one that does not. Those 51 files were
 *   read by hand in October 2026, and one was wrong: knowledge/permission_model.php
 *   sent ?action=set on a GET straight to the save. In a file that routes on the
 *   method, put csrfRequireWriteMethod() in each write branch.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/csrf.php';   // CSRF_EXEMPT_PATHS, csrfIsExempt()

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  - $detail" : '') . "\n"; }
}

/**
 * Writers that are allowed to run on a GET, each with the reason. Keep it short:
 * every entry is a page somebody could make a victim's browser load.
 */
const GET_WRITE_ALLOWED = [
    'api/documents/download.php'                 => 'records the download in document_access_log - a log of a read',
    'api/knowledge/document.php'                 => 'records an article document download in document_access_log - a log of a read (GH #416)',
    'api/knowledge/knowledge_article.php'        => 'adds one to the article view counter',
    'api/self-service/get_knowledge_article.php' => 'adds one to the article view counter',
    'api/tickets/get_email_detail.php'           => 'marks the email read for the analyst opening it',
    'api/auth/oidc_callback.php'                 => 'the identity provider redirects back with a GET; checks its own state against the session',
    'api/system/debug-tools/D011_directory_sync_readiness.php' => 'the SQL is text it prints as advice',
];

/** The file's PHP with comments removed, so neither a check nor SQL in a comment counts. */
function codeOnly(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
function writesData(string $code): bool {
    return (bool) preg_match('/\b(INSERT\s+(IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM|ALTER\s+TABLE|CREATE\s+TABLE|DROP\s+(TABLE|COLUMN|INDEX))\b/i', $code);
}
function checksMethod(string $code): bool {
    // csrfRequireToken() is the EventSource case: a GET that writes, carrying the token.
    return (bool) preg_match('/\bcsrfRequireWriteMethod\s*\(|\bcsrfRequireToken\s*\(|\$_SERVER\s*\[\s*[\'"]REQUEST_METHOD[\'"]\s*\]/', $code);
}
/** The verdict for one file's source: 'ok', 'reads' (no writes) or 'missing'. */
function verdict(string $src): string {
    $code = codeOnly($src);
    if (!writesData($code)) return 'reads';
    return checksMethod($code) ? 'ok' : 'missing';
}

echo "No endpoint may change data on a GET (csrf.php, csrfRequireWriteMethod)\n";
echo str_repeat('=', 72) . "\n";

// ---------------------------------------------------------------- 1. scanner
echo "\nThe scanner\n";
$fixtures = [
    'NEGATIVE CONTROL: empty_trash.php as shipped in 3.15.0 is flagged' =>
        ["<?php\nrequire_once '../../includes/functions.php';\n\$conn->prepare(\"DELETE FROM tickets WHERE id IN (\$place)\")->execute(\$ids);\n", 'missing'],
    'the check in a COMMENT does not count' =>
        ["<?php\n// csrfRequireWriteMethod();\n\$conn->exec(\"UPDATE system_settings SET setting_value = 1\");\n", 'missing'],
    'SQL in a COMMENT is not a write' =>
        ["<?php\n/* this used to run DELETE FROM tickets */\necho json_encode([]);\n", 'reads'],
    'csrfRequireWriteMethod() satisfies it' =>
        ["<?php\ncsrfRequireWriteMethod();\n\$conn->exec(\"INSERT INTO x (a) VALUES (1)\");\n", 'ok'],
    'an explicit REQUEST_METHOD check satisfies it' =>
        ["<?php\nif (\$_SERVER['REQUEST_METHOD'] !== 'POST') exit;\n\$conn->exec(\"REPLACE INTO x (a) VALUES (1)\");\n", 'ok'],
    'csrfRequireToken() (a GET that writes, with the token) satisfies it' =>
        ["<?php\ncsrfRequireToken();\n\$conn->exec(\"INSERT INTO x (a) VALUES (1)\");\n", 'ok'],
    'INSERT IGNORE is a write' =>
        ["<?php\n\$conn->exec(\"INSERT IGNORE INTO x (a) VALUES (1)\");\n", 'missing'],
    'DDL is a write' =>
        ["<?php\n\$conn->exec(\"ALTER TABLE x ADD COLUMN y INT\");\n", 'missing'],
    'a read-only endpoint is left alone' =>
        ["<?php\n\$s = \$conn->query(\"SELECT * FROM tickets WHERE status = 'UPDATE'\");\n", 'reads'],
];
foreach ($fixtures as $label => [$src, $want]) {
    $got = verdict($src);
    check($label, $got === $want, "got '$got', wanted '$want'");
}

// ---------------------------------------------------------------- 2. api/
echo "\nEvery endpoint under api/ that writes\n";
$missing = []; $writers = 0; $seenAllowed = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/api", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $root)) + 1);
    // Cross-origin by design and never use the login cookie - nothing to forge.
    if (csrfIsExempt($rel)) continue;
    $v = verdict(file_get_contents($f->getPathname()));
    if ($v === 'reads') continue;
    $writers++;
    if (isset(GET_WRITE_ALLOWED[$rel])) { $seenAllowed[$rel] = $v; continue; }
    if ($v === 'missing') $missing[] = $rel;
}
check("all $writers writers refuse a GET (or are on the allow-list)", !$missing,
      count($missing) . " without csrfRequireWriteMethod():\n          " . implode("\n          ", $missing));
check('the scan found the writers at all (more than 250)', $writers > 250, "only $writers");

// ---------------------------------------------------------------- 3. allow-list
echo "\nThe allow-list\n";
foreach (GET_WRITE_ALLOWED as $rel => $why) {
    $state = $seenAllowed[$rel] ?? (is_file("$root/$rel") ? 'reads' : 'gone');
    check("$rel - $why", $state === 'missing',
          $state === 'gone' ? 'the file no longer exists - remove the entry'
          : ($state === 'reads' ? 'no longer writes - remove the entry' : 'now checks the method - remove the entry'));
}

echo "\n" . str_repeat('=', 72) . "\n";
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
