<?php
/**
 * Page health - the list of every page an analyst can open, for checking that
 * each one's JavaScript parses (Debug Tools D019, and the "Page health" CI job).
 *
 * Why it exists: 4.3.0 shipped an Assets page that never loaded (GH #222). One
 * comment swallowed the code after it, so the page's whole script failed to
 * parse. `php -l` passed (the PHP was fine), the page returned HTTP 200, and no
 * test opened it in a browser. A syntax error in a page's script is the worst
 * kind - the browser runs NONE of that script - and the cheapest to catch.
 *
 * ONE list, two users:
 *   - D019 in System -> Debug Tools fetches each page as the signed-in admin and
 *     compiles every script in their browser (system/debug-tools/runners/page-health.php).
 *   - scripts/page_health_ci.php reads the same list in CI, where there is no
 *     database to sign in to, and checks the scripts in the page SOURCE instead.
 *
 * Nothing here is hand-maintained. A page is any PHP file in a module folder that
 * writes an HTML document itself or through a shared page template; a new page
 * is found the day it is written.
 *
 * TRAP: the module -> folder map is READ from includes/waffle-menu.php's source,
 * not copied. That file both defines the modules AND prints the menu, so it
 * cannot be included just to read the list - and a copy would drift the first
 * time a module was added.
 *
 * @wiki Page-Health-Developer-Guide
 */

/** Folders that hold no pages an analyst opens: code, data, tooling, and setup. */
const PAGE_HEALTH_SKIP_DIRS = [
    'api', 'includes', 'assets', 'vendor', 'node_modules', 'tests', 'scripts', 'cron', 'setup',
    'lang', 'data', 'database', 'deploy', 'docker', 'docs', 'local', 'logs', 'recordings',
    'releases', 'samples', 'uploads', 'browser-extension', '.git', '.github',
];

/**
 * Pages never opened by the check, with why. Opening a page must change nothing,
 * and these do something just by being opened.
 */
const PAGE_HEALTH_NEVER_OPEN = [
    'auth/logout.php'          => 'signs you out',
    'auth/analyst_logout.php'  => 'signs you out',
    'analyst_logout.php'       => 'signs you out',
    'self-service/logout.php'  => 'signs the portal user out',
];

/**
 * Every module's key and folder, read from the waffle menu's source.
 *
 * @return array<string,string> e.g. ['assets' => 'asset-management', 'tickets' => 'tickets', ...]
 */
function pageHealthModuleDirs(string $root): array {
    $src = @file_get_contents($root . '/includes/waffle-menu.php') ?: '';
    $start = strpos($src, '$modules = [');
    if ($start === false) return [];
    preg_match_all("/^\s{4}'([a-z0-9-]+)'\s*=>\s*\[.*?'path'\s*=>\s*'([a-z0-9-]+)\/'/ms", substr($src, $start), $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $row) $out[$row[1]] = $row[2];
    return $out;
}

/**
 * Whether a PHP file writes an HTML document as raw HTML - `<!DOCTYPE html>` at
 * the start of the file or straight after a `?>`. An HTML string a function
 * merely echoes (csrf.php's "Request refused") does not count, or every handler
 * that includes csrf.php would be taken for a page and opened.
 */
function pageHealthWritesDocument(string $src): bool {
    return (bool) preg_match('/(?:\A|\?>)\s*<!DOCTYPE html/i', $src);
}

/**
 * Shared page templates: PHP files under an includes/ folder that write an HTML
 * document, so a two-line page that requires one IS a page (a Debug Tools page,
 * a landing page).
 *
 * @return string[] basenames, e.g. ['tool-page.php', 'landing.php']
 */
function pageHealthTemplates(string $root): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (substr($p, -4) !== '.php' || !preg_match('#/includes/#', substr($p, strlen($root)))) continue;
        if (preg_match('#/(vendor|node_modules|tests|\.git)/#', $p)) continue;
        if (pageHealthWritesDocument((string) @file_get_contents($p))) $out[] = basename($p);
    }
    return array_values(array_unique($out));
}

/**
 * Every page, in a stable order.
 *
 * @return array<int,array{path:string,module:string,group:string,skip:?string}>
 *   path   relative to the app root, e.g. 'asset-management/index.php'
 *   module the module key ('assets'), 'system', 'portal', or 'home'
 *   group  the label to group by on screen (the folder)
 *   skip   why the check must not open it, or null
 */
function pageHealthPages(string $root): array {
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $dirToKey = array_flip(pageHealthModuleDirs($root));
    $templates = pageHealthTemplates($root);
    $tplRe = $templates ? '#(' . implode('|', array_map(fn($t) => preg_quote($t, '#'), $templates)) . ")['\"]#" : null;

    $pages = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($f) use ($root) {
                if (!$f->isDir()) return true;
                $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
                return !in_array(basename($rel), PAGE_HEALTH_SKIP_DIRS, true);
            }
        )
    );
    foreach ($it as $f) {
        $abs = str_replace('\\', '/', $f->getPathname());
        if (substr($abs, -4) !== '.php') continue;
        $rel = substr($abs, strlen($root) + 1);
        if (basename($rel)[0] === '_') continue;                 // _registry.php and friends
        $src = (string) @file_get_contents($abs);
        $isPage = pageHealthWritesDocument($src) || ($tplRe && preg_match($tplRe, $src));
        if (!$isPage) continue;

        $top = strpos($rel, '/') === false ? '' : substr($rel, 0, strpos($rel, '/'));
        $module = $top === '' ? 'home' : ($top === 'system' ? 'system' : ($top === 'self-service' ? 'portal' : ($top === 'auth' ? 'auth' : ($dirToKey[$top] ?? $top))));
        $pages[] = [
            'path'   => $rel,
            'module' => $module,
            'group'  => $top === '' ? '/' : $top,
            'skip'   => PAGE_HEALTH_NEVER_OPEN[$rel] ?? null,
        ];
    }
    usort($pages, fn($a, $b) => strcmp($a['path'], $b['path']));
    return $pages;
}
