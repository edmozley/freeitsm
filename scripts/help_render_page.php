<?php
/**
 * Render ONE help page in ONE language and print its sections as JSON - for the
 * help assistant's index builder (includes/help_index.php, wiki Help-Assistant-Design).
 *
 *   php scripts/help_render_page.php <page, e.g. tickets/help.php> <locale> <analyst id>
 *
 * Why render instead of reading the source: many help pages build their sections
 * in loops through helpers ($h('...')), and the System help pages are written in
 * English in the page itself, so the strings cannot be found by reading t() calls.
 * Rendering gives exactly what a reader sees, in their language. What counts as a
 * section is helpRenderSections() in includes/help_index.php.
 *
 * One page per process: pages define functions and constants, call session_start()
 * and may exit(), so two in one process would collide. The analyst is only there to
 * pass the page's own module gate; nothing is written for them.
 *
 * Output (stdout, last line): {"ok":true,"sections":[{"id","title","text","cap","skip"}]}
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[, $page, $locale, $analystId] = $argv + [null, null, null, null];
$root = dirname(__DIR__);
$page = str_replace('\\', '/', (string)$page);
// A page may carry a query string - system/integrations/help.php?provider=jira.
[$page, $query] = array_pad(explode('?', $page, 2), 2, '');
if (!$page || !preg_match('~^[a-z0-9_-]+(/[a-z0-9_-]+)*/help[a-z0-9_-]*\.php$|^system/help/[a-z0-9-]+\.php$~', $page)
    || !is_file("$root/$page") || !$locale || (int)$analystId <= 0 || !preg_match('/^[a-z0-9_=&-]*$/', $query)) {
    fwrite(STDERR, "usage: php scripts/help_render_page.php <page> <locale> <analyst id>\n");
    exit(2);
}

require_once "$root/includes/i18n.php";
require_once "$root/includes/help_index.php";   // helpRenderSections()
I18n::pinLocale((string)$locale);

// The page's own session_start() is ignored, because this one is already running
// (it has to be, or the page would start an empty session over ours). PHP says so
// with a notice per render - 74 per language per build in the error log. Only that
// notice is silenced; everything else is reported as normal.
set_error_handler(fn(int $no, string $msg) => $no === E_NOTICE && str_contains($msg, 'session is already active'), E_NOTICE);

// The page sees an ordinary signed-in GET; a throwaway session, removed at the end.
session_id('helprender' . bin2hex(random_bytes(8)));
session_start();
$_SESSION['analyst_id'] = (int)$analystId;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $page;
$_SERVER['REQUEST_URI'] = '/' . $page . ($query !== '' ? "?$query" : '');
$_SERVER['QUERY_STRING'] = $query;
parse_str($query, $_GET);

ob_start();
register_shutdown_function(function () {
    $html = '';
    while (ob_get_level() > 0) $html = ob_get_clean() . $html;
    @session_destroy();
    echo "\n" . json_encode(['ok' => true, 'sections' => helpRenderSections($html)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
});

chdir(dirname("$root/$page"));
require "$root/$page";
