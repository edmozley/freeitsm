<?php
/**
 * The API script library (System -> API -> Scripts).
 *
 * Ready-made PowerShell and Python scripts that use the REST API, shipped as
 * FILES in script-library/ - never stored in the database. Decided with Ed,
 * 19 Nov 2026: people run these on their own machines with admin API keys, so a
 * script must carry the same trust as the code itself. In the database, anyone
 * who could write to it could add one line that sends the key elsewhere, to
 * everybody who copies the script afterwards. Wiki: API-Script-Library-Design.
 *
 * Every script starts with a header of `# @tag value` lines - the same in both
 * languages, so one parser reads both:
 *
 *   # @title       Export open tickets to a CSV file
 *   # @summary     One sentence saying what it does.
 *   # @module      tickets
 *   # @changes     no            (yes = it writes; previews unless -Apply / --apply)
 *   # @level       starter       (starter | intermediate | advanced)
 *   # @endpoints   GET /tickets, GET /statuses
 *   # @permissions tickets.read, reference.read
 *
 * TRAP: nothing here joins a name from the request onto a path. apiScriptRead()
 * looks the slug up in the catalogue built from the folder listing, and the slug
 * itself must match ^[a-z0-9-]+$. tests/api-script-library.php checks every
 * header, that each script exists in both languages, and that each endpoint and
 * permission it names is real.
 */

const API_SCRIPT_LANGUAGES = [
    'powershell' => ['dir' => 'powershell', 'ext' => 'ps1', 'label' => 'PowerShell'],
    'python'     => ['dir' => 'python',     'ext' => 'py',  'label' => 'Python'],
];
const API_SCRIPT_TAGS = ['title', 'summary', 'module', 'changes', 'level', 'endpoints', 'permissions'];
const API_SCRIPT_LEVELS = ['starter', 'intermediate', 'advanced'];

function apiScriptLibraryDir(): string
{
    return dirname(__DIR__) . '/script-library';
}

/**
 * Reads the `# @tag value` header from the top of a script (first 60 lines).
 * A tag may continue on following `#` lines indented further than the tag column.
 *
 * @return array<string,string>
 */
function apiScriptParseHeader(string $text): array
{
    $tags = [];
    $last = null;
    foreach (array_slice(preg_split('/\r?\n/', $text), 0, 60) as $line) {
        if (preg_match('/^#\s*@([a-z]+)\s+(.*)$/', $line, $m)) {
            $last = $m[1];
            $tags[$last] = trim($m[2]);
        } elseif ($last !== null && preg_match('/^#\s{6,}(\S.*)$/', $line, $m)) {
            $tags[$last] .= ' ' . trim($m[1]);       // continuation line
        } else {
            $last = null;
        }
    }
    return $tags;
}

/** "GET /tickets, POST /tickets/{id}/notes" -> [['GET','/tickets'], ...] */
function apiScriptSplitEndpoints(string $value): array
{
    $out = [];
    foreach (preg_split('/\s*,\s*/', trim($value)) as $e) {
        if ($e === '') continue;
        $p = preg_split('/\s+/', $e, 2);
        $out[] = [strtoupper($p[0]), $p[1] ?? ''];
    }
    return $out;
}

/**
 * Every script, grouped by slug: one entry per script with the languages it is
 * available in. Sorted by module, then title.
 *
 * @return array<string,array>
 */
function apiScriptCatalogue(): array
{
    $byslug = [];
    foreach (API_SCRIPT_LANGUAGES as $lang => $def) {
        foreach (glob(apiScriptLibraryDir() . '/' . $def['dir'] . '/*.' . $def['ext']) ?: [] as $file) {
            $slug = basename($file, '.' . $def['ext']);
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) continue;
            $tags = apiScriptParseHeader((string)file_get_contents($file));
            $entry = $byslug[$slug] ?? ['slug' => $slug, 'languages' => []];
            // The header is the same in both files; the first one read wins, and
            // the test fails if the two disagree.
            foreach (API_SCRIPT_TAGS as $t) if (!isset($entry[$t]) && isset($tags[$t])) $entry[$t] = $tags[$t];
            $entry['languages'][] = $lang;
            $entry['files'][$lang] = $file;
            $byslug[$slug] = $entry;
        }
    }
    foreach ($byslug as &$e) {
        $e['changes'] = (($e['changes'] ?? 'no') === 'yes');
        $e['endpoints'] = apiScriptSplitEndpoints($e['endpoints'] ?? '');
        // "none" = the script needs no permission (only /ping does that).
        $e['permissions'] = array_values(array_filter(preg_split('/\s*,\s*/', $e['permissions'] ?? ''),
            static fn($p) => $p !== '' && $p !== 'none'));
    }
    unset($e);
    uasort($byslug, static fn($a, $b) => [($a['module'] ?? ''), ($a['title'] ?? '')] <=> [($b['module'] ?? ''), ($b['title'] ?? '')]);
    return $byslug;
}

/** The catalogue as the screen needs it: no file paths. */
function apiScriptCataloguePublic(): array
{
    return array_values(array_map(static function (array $e): array {
        unset($e['files']);
        return $e;
    }, apiScriptCatalogue()));
}

/**
 * One script's text, or null when there is no such script in that language.
 * The file is found through the catalogue, never by building a path from input.
 */
function apiScriptRead(string $slug, string $lang): ?array
{
    if (!preg_match('/^[a-z0-9-]+$/', $slug) || !isset(API_SCRIPT_LANGUAGES[$lang])) return null;
    $cat = apiScriptCatalogue();
    $file = $cat[$slug]['files'][$lang] ?? null;
    if ($file === null || !is_file($file)) return null;
    return ['slug' => $slug, 'lang' => $lang, 'filename' => basename($file), 'text' => (string)file_get_contents($file)];
}
