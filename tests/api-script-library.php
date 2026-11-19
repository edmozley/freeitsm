<?php
/* NEVER OVER THE WEB. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * The API script library (System -> API -> Scripts) stays honest.
 *
 *   php tests/api-script-library.php
 *
 * People copy these scripts and run them with admin API keys, trusting that they
 * work and do only what they say. So, for every script in script-library/:
 *
 *   - the header is complete and valid, and identical in both languages;
 *   - it exists in BOTH PowerShell and Python;
 *   - every endpoint in @endpoints is a real route, and every permission that
 *     route needs is in @permissions (and nothing in @permissions is made up);
 *   - every API path the code calls is one of its @endpoints;
 *   - no API key is written into it;
 *   - a script that changes data has a preview mode (-Apply / --apply);
 *   - Python imports only the standard library;
 *   - it parses (python -m py_compile; the PowerShell parser where available).
 *
 * Plus the folder is denied to direct web requests on Apache, IIS and nginx,
 * and debug tool D009 probes it. Static: no database, no network.
 */
chdir(__DIR__ . '/..');
require_once 'includes/api_script_library.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . ($ok || $why === '' ? '' : "  [$why]") . "\n";
    $ok ? $pass++ : $fail++;
}

// ---- The real route table ---------------------------------------------------
$routes = require 'api/v1/lib/routes.php';
$permCatalog = (function () { require_once 'api/v1/lib/permissions.php'; return function_exists('apiPermissionCatalog') ? apiPermissionCatalog() : null; })();
/** Which route a documented "METHOD /path" is; {id} and {key} stand for a value. */
function routeFor(array $routes, string $method, string $path): ?array
{
    $sample = preg_replace(['/\{id\}|\{[a-z_]*id\}/', '/\{[a-z_]+\}/'], ['7', 'sample'], $path);
    foreach ($routes as $r) {
        if ($r[0] === $method && @preg_match($r[1], $sample)) return $r;
    }
    return null;
}
/** Collapse ids and variables so "/tickets/$id/notes" and "/tickets/{id}/notes" compare equal. */
function shape(string $path): string
{
    $segs = explode('/', trim($path, '/'));
    foreach ($segs as &$s) {
        if ($s === '' ) continue;
        if (preg_match('/[\$\{\}\(\)\+]|^\d+$|%s|%d/', $s)) $s = '*';
    }
    unset($s);
    return '/' . implode('/', $segs);
}
$resources = [];
foreach ($routes as $r) if (preg_match('#\^/([a-z][a-z0-9-]*)#', $r[1], $m)) $resources[$m[1]] = true;

$stdlib = ['argparse', 'base64', 'csv', 'datetime', 'json', 'os', 'pathlib', 're', 'ssl', 'sys', 'time',
    'urllib', 'urllib.request', 'urllib.parse', 'urllib.error', 'collections', 'html', 'io', 'textwrap',
    'socket', 'subprocess', 'platform', 'shutil', 'getpass', 'typing', 'mimetypes', 'itertools', 'math', 'calendar', 'zoneinfo'];

$cat = apiScriptCatalogue();
check('the library has scripts', count($cat) > 0, 'none found in script-library/');

$pythonBin = null;
foreach (['python3', 'python'] as $p) { $v = @shell_exec("$p --version 2>&1"); if ($v && stripos($v, 'Python 3') === 0) { $pythonBin = $p; break; } }
// The PowerShell parse check lives in a temporary .ps1 rather than a -Command
// string: on Linux (CI) the shell would expand $e and $PSVersionTable inside a
// double-quoted command and the check would silently do nothing.
$psChecker = sys_get_temp_dir() . '/freeitsm_ps_parse_check.ps1';
file_put_contents($psChecker, "param(\$Path)\n\$e = \$null\n[void][System.Management.Automation.Language.Parser]::ParseFile(\$Path, [ref]\$null, [ref]\$e)\nif (\$e) { \$e | ForEach-Object { \$_.Message }; exit 1 }\n'parsed'\n");
$psBin = null;
foreach (['pwsh', 'powershell'] as $p) {
    $v = @shell_exec("$p -NoProfile -ExecutionPolicy Bypass -File " . escapeshellarg($psChecker) . ' ' . escapeshellarg($psChecker) . ' 2>&1');
    if ($v && trim($v) === 'parsed') { $psBin = $p; break; }
}

foreach ($cat as $slug => $e) {
    echo "\n== $slug\n";
    check("$slug exists in PowerShell and Python", count($e['languages']) === count(API_SCRIPT_LANGUAGES), implode(',', $e['languages']));
    $headers = [];
    foreach ($e['files'] as $lang => $file) {
        $text = (string)file_get_contents($file);
        $h = apiScriptParseHeader($text);
        $headers[$lang] = $h;
        $missing = array_diff(API_SCRIPT_TAGS, array_keys(array_filter($h, 'strlen')));
        check("$lang: header has every tag", !$missing, 'missing ' . implode(', ', $missing));
        check("$lang: @changes is yes or no", in_array($h['changes'] ?? '', ['yes', 'no'], true), $h['changes'] ?? '');
        check("$lang: @level is starter/intermediate/advanced", in_array($h['level'] ?? '', API_SCRIPT_LEVELS, true), $h['level'] ?? '');
        check("$lang: no API key written in", !preg_match('/fitsm_[0-9a-f]{16,}/i', $text));
        if (($h['changes'] ?? '') === 'yes') {
            $preview = $lang === 'powershell' ? preg_match('/\[switch\]\s*\$Apply\b/i', $text) : (bool)preg_match('/[\'"]--apply[\'"]/', $text);
            check("$lang: changes data, so it previews unless told to apply", (bool)$preview, $lang === 'powershell' ? 'needs [switch]$Apply' : "needs a '--apply' argument");
        }
        // Every API path the code calls must be one of its @endpoints.
        $declared = array_map(static fn($ep) => shape($ep[1]), apiScriptSplitEndpoints($h['endpoints'] ?? ''));
        // A {...} block is read whole, so f"/tickets/{t['id']}/notes" is one path
        // (/tickets/*/notes), not "/tickets/{t" cut off at the inner quote.
        preg_match_all('#[\'"`](/[a-z][a-z0-9-]*(?:/(?:\{[^}]*\}|\$\([^)]*\)|[^\s\'"`?{])*)*)#', $text, $mm);
        $undeclared = [];
        foreach (array_unique($mm[1]) as $p) {
            $first = explode('/', trim($p, '/'))[0];
            if (!isset($resources[$first])) continue;              // not an API path (a file path, a URL piece)
            if (!in_array(shape($p), $declared, true)) $undeclared[] = $p;
        }
        check("$lang: calls only the endpoints in its header", !$undeclared, implode(' ', $undeclared));
        if ($lang === 'python') {
            preg_match_all('/^\s*(?:from\s+([a-zA-Z0-9_.]+)\s+import|import\s+([a-zA-Z0-9_., ]+))/m', $text, $im, PREG_SET_ORDER);
            $bad = [];
            foreach ($im as $i) foreach (preg_split('/\s*,\s*/', trim(($i[1] ?? '') !== '' ? $i[1] : $i[2])) as $mod) {
                $mod = trim(preg_split('/\s+as\s+/', $mod)[0]);
                if ($mod !== '' && !in_array($mod, $stdlib, true) && !in_array(explode('.', $mod)[0], $stdlib, true)) $bad[] = $mod;
            }
            check('python: standard library only', !$bad, implode(', ', $bad));
            if ($pythonBin) {
                // ast.parse, not py_compile: py_compile writes a __pycache__ folder into the library.
                $out = []; exec("$pythonBin -c " . escapeshellarg("import ast,sys; ast.parse(open(sys.argv[1], 'rb').read(), sys.argv[1])") . ' ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
                check('python: compiles', $rc === 0, implode(' ', $out));
            }
        } elseif ($psBin) {
            $out = []; exec("$psBin -NoProfile -ExecutionPolicy Bypass -File " . escapeshellarg($psChecker) . ' ' . escapeshellarg(realpath($file)) . ' 2>&1', $out, $rc);
            check('powershell: parses', $rc === 0, implode(' ', $out));
        }
    }
    if (count($headers) === 2) {
        $a = array_intersect_key($headers['powershell'], array_flip(API_SCRIPT_TAGS));
        $b = array_intersect_key($headers['python'], array_flip(API_SCRIPT_TAGS));
        ksort($a); ksort($b);
        check('the two headers are identical', $a === $b, json_encode(array_diff_assoc($a, $b)));
    }
    // Endpoints are real, and every permission they need is declared.
    $needs = [];
    foreach ($e['endpoints'] as [$method, $path]) {
        $r = routeFor($routes, $method, $path);
        check("endpoint $method $path exists", $r !== null);
        if ($r && is_array($r[2])) $needs[] = $r[2][0] . '.' . $r[2][1];
    }
    $needs = array_values(array_unique($needs));
    $missingPerm = array_diff($needs, $e['permissions']);
    check('@permissions covers every endpoint', !$missingPerm, 'add ' . implode(', ', $missingPerm));
    $extra = array_diff($e['permissions'], $needs);
    check('@permissions lists nothing it does not use', !$extra, 'remove ' . implode(', ', $extra));
}

echo "\n== The folder is never served directly\n";
$ht = (string)@file_get_contents('script-library/.htaccess');
check('Apache: script-library/.htaccess denies everything', stripos($ht, 'Require all denied') !== false);
check('IIS: script-library/web.config exists', is_file('script-library/web.config'));
check('nginx: deploy config denies /script-library/', str_contains((string)@file_get_contents('deploy/nginx/freeitsm.conf'), '/script-library/'));
check('D009 probes script-library', str_contains((string)@file_get_contents('api/system/debug-tools/D009_guarded_paths.php'), "'script-library'"));
check('the endpoint never builds a path from the request', !preg_match('/\$_GET[^;]*\.\s*[\'"]\.(ps1|py)/', (string)file_get_contents('api/system/api_keys/scripts.php')));
if (!$pythonBin) echo "  (python not found - compile checks skipped)\n";
if (!$psBin) echo "  (PowerShell not found - parse checks skipped)\n";

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
