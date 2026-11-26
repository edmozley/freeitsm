<?php
/* 🔴 NEVER OVER THE WEB. See the note this file is about, below. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Nothing in tests/ may be reachable over HTTP.
 *
 *   php tests/web-exposure-guard.php
 *
 * 🔴 WHY THIS EXISTS. FreeITSM is deployed by putting the repository in the
 * document root — that is what the Dockerfile's `COPY . /var/www/html/` does and
 * how a hand install is normally laid out. For a long time that published this
 * directory. A request to /tests/<anything>.php returned 200 and ran it.
 *
 * That is worse than it sounds, because these are not pure unit tests. They
 * drive the real code against the real database: they create forms, assets,
 * contracts, documents and working analyst accounts — one of them with the
 * password 'x', which is printed in a public repository — and each deletes what
 * it made only in its closing lines. Over HTTP that becomes:
 *
 *   - an unauthenticated write endpoint anybody who reads the repo can trigger;
 *   - which a client can abandon, or a web SAPI's 30-second limit can cut off,
 *     part way through, leaving the account or the rows behind (the command line
 *     has no such limit, which is why this never happened to a developer);
 *   - whose failure messages print real records back to the requester — one
 *     prints the titles of actual knowledge articles;
 *   - and which deletes by pattern, so `DELETE FROM assets WHERE hostname LIKE
 *     'ZZIMP-%'` takes a customer's matching row with it.
 *
 * 🔑 THE DEFENCE HAS THREE LAYERS AND THIS TEST CHECKS ALL THREE, because each
 * one covers a case the others cannot:
 *
 *   1. A `PHP_SAPI !== 'cli'` guard on the first line of every script. This is
 *      the only layer that travels WITH the file, so it works in a git clone, on
 *      nginx, on Apache without AllowOverride, and inside an image somebody
 *      built themselves. It cannot cover the .html and .sh files.
 *   2. tests/.htaccess and tests/web.config — the whole directory denied, which
 *      does cover those files. Apache and IIS only.
 *   3. tests/ excluded from .dockerignore and denied in deploy/nginx, so the
 *      official image never contains it and the shipped nginx config blocks it.
 *
 * ⚠️ Layer 1 is the one that matters most and the one most easily forgotten:
 * a new test file is written by copying an old one or from scratch, and nothing
 * about leaving the guard off would ever fail. That is the shape this codebase
 * keeps being bitten by — a guard a human has to remember is not a guard — so
 * the list of files is taken from the DIRECTORY, never from a list kept here.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  — $detail" : '') . "\n"; }
}

echo "Nothing in tests/ may be reachable over HTTP\n";
echo str_repeat('=', 64) . "\n";

/* ---- Layer 1: every script refuses for itself ----------------------------
   🔑 Walked from disk. A file added tomorrow is checked tomorrow, with nobody
   having to add it to anything. */
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests'));
$scripts = [];
foreach ($rii as $f) {
    if ($f->isDir() || strtolower($f->getExtension()) !== 'php') continue;
    $scripts[] = str_replace('\\', '/', $f->getPathname());
}
sort($scripts);

check('there are test scripts to check at all', count($scripts) > 10,
    'found ' . count($scripts) . ' — if this is near zero the walk is broken and every result below is worthless');

$unguarded = [];
foreach ($scripts as $path) {
    $src = (string)file_get_contents($path);
    /* The guard must be in the first few lines. Further down is not a guard:
       anything above it has already run, and these files connect to the
       database and start writing within a dozen lines. */
    $head = substr($src, 0, 1200);
    if (!preg_match("~PHP_SAPI\s*!==\s*'cli(-server)?'~", $head)) {
        $unguarded[] = substr($path, strlen($root) + 1);
    }
}
check('every .php in tests/ refuses to run unless PHP_SAPI is cli',
    $unguarded === [],
    count($unguarded) . ' without a guard: ' . implode(', ', array_slice($unguarded, 0, 8)));

/* 🔑 mock.php is the ONE exception and is allowed a DIFFERENT guard: it is a
   stand-in Azure endpoint, so it must answer an HTTP request — but only from
   the `php -S` that tests/azure-openai/run.php starts itself. Named here so the
   exception stays deliberate rather than becoming a hole anyone may widen. */
/* GH #192 added a second stand-in, for custom OpenAI-compatible endpoints. The
   exceptions are named by PATH: this used to excuse any file called mock.php,
   so a third one anywhere in tests/ would have passed without anybody deciding
   it should. */
$httpExceptions = ['tests/azure-openai/mock.php', 'tests/ai-compatible/mock.php', 'tests/warroom-translate/mock.php'];
foreach ($httpExceptions as $rel) {
    $mock = (string)@file_get_contents($root . '/' . $rel);
    check("the HTTP exception $rel is limited to php -S",
        str_contains($mock, "PHP_SAPI !== 'cli-server'"),
        'a stand-in endpoint should accept the built-in server and nothing else');
}
/* ⚠️ Asked of each file's HEAD, not its whole body. Asked of the whole body this
   check failed against a correct tree — because THIS file names the exception in
   order to describe it, and matched itself. Same trap as the first draft of the
   grid-container check in field-widths-agree.php, a week apart: a test that
   searches a whole file finds the documentation as readily as the code. */
$claimers = [];
foreach ($scripts as $p) {
    if (in_array(str_replace('\\', '/', substr($p, strlen($root) + 1)), $httpExceptions, true)) continue;
    if (str_contains(substr((string)file_get_contents($p), 0, 1200), "!== 'cli-server'")) {
        $claimers[] = substr($p, strlen($root) + 1);
    }
}
check('…and no OTHER file claims that exception', $claimers === [],
    'also allowing the built-in server: ' . implode(', ', $claimers));

/* ---- Layer 2: the directory is denied for the files that cannot refuse ---- */
check('tests/.htaccess exists (Apache)',  is_file($root . '/tests/.htaccess'));
check('tests/web.config exists (IIS)',    is_file($root . '/tests/web.config'));
$ht = (string)@file_get_contents($root . '/tests/.htaccess');
check('tests/.htaccess denies the whole directory',
    stripos($ht, 'Require all denied') !== false || stripos($ht, 'Deny from all') !== false);

/* ---- Layer 3: it never ships, and nginx blocks it ------------------------ */
$di = (string)@file_get_contents($root . '/.dockerignore');
check('.dockerignore keeps tests/ out of the image',
    (bool)preg_match('~^\s*tests/?\s*$~m', $di),
    'the Dockerfile does COPY . — without this line the suite lands in the web root of the image');

$ngx = (string)@file_get_contents($root . '/deploy/nginx/freeitsm.conf');
/* Delimiter is '#', not '~': the nginx prefix operator IS `^~`, so a tilde
   delimiter ends the pattern in the middle of the thing being matched. The first
   draft did exactly that and PHP warned "Unknown modifier" while the check
   still reported ok, because the || fell through to a loose strpos. */
check('the shipped nginx config returns 404 for /tests/',
    (bool)preg_match('#location\s+\^~\s*/tests/\s*\{[^}]*return\s+404#', $ngx),
    'nginx has no .htaccess equivalent, so the rule has to be in the config we ship');

/* ---- scripts/: the command-line tools ------------------------------------
   Added 2026-10-04 after an audit found 13 of 26 scripts with no guard - the
   i18n tools and gen_portal_flow.php, which writes files - each answering an
   anonymous request with HTTP 200. Unlike tests/, scripts/ ships in production
   (the Intune workers, directory sync, cron tokens), so it cannot simply be left
   out of the image: every script refuses for itself, and the directory refuses
   PHP on Apache, IIS and nginx.

   🔑 Stricter than the tests/ check above: the guard must be the FIRST
   STATEMENT, found with the tokenizer, so a long comment header can be any
   length and a line of code slipped above the guard is still caught. */
echo "\nNothing in scripts/ may run over HTTP\n";
echo str_repeat('=', 64) . "\n";
function firstStatement(string $src): string {
    $code = '';
    foreach (token_get_all($src) as $t) {
        if ($code === '' && is_array($t) && in_array($t[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
        $code .= is_array($t) ? $t[1] : $t;
        if (strlen($code) > 60) break;
    }
    return $code;
}
$firstGuardRe = "~^if\s*\(\s*PHP_SAPI\s*!==\s*'cli'\s*\)~";
$tools = glob($root . '/scripts/*.php') ?: [];
check('there are scripts to check at all', count($tools) > 10, 'found ' . count($tools));
$bad = [];
foreach ($tools as $path) {
    if (!preg_match($firstGuardRe, firstStatement((string)file_get_contents($path)))) {
        $bad[] = basename($path);
    }
}
check('every .php in scripts/ starts with the PHP_SAPI cli guard', $bad === [],
    count($bad) . ' without it as the first statement: ' . implode(', ', $bad));
$sht = (string)@file_get_contents($root . '/scripts/.htaccess');
check('scripts/.htaccess refuses .php (Apache)',
    (bool)preg_match('~<FilesMatch\s+"[^"]*php[^"]*">\s*<IfModule mod_authz_core\.c>\s*Require all denied~', $sht));
$swc = (string)@file_get_contents($root . '/scripts/web.config');
check('scripts/web.config refuses .php (IIS)',
    (bool)preg_match('~fileExtension="\.php"\s+allowed="false"~', $swc));
check('the shipped nginx config returns 404 for /scripts/*.php',
    (bool)preg_match('#location\s+~\*\s+\^/scripts/[^{]*php[^{]*\{\s*return\s+404#', $ngx));
check('CONTROL — the first-statement check SEES a guard after a long header',
    (bool)preg_match($firstGuardRe, firstStatement("<?php\n/** " . str_repeat('x', 5000) . " */\nif (PHP_SAPI !== 'cli') { exit; }")));
check('CONTROL — …and FAILS a file with code above its guard',
    !preg_match($firstGuardRe, firstStatement("<?php\n\$x = 1;\nif (PHP_SAPI !== 'cli') { exit; }")));

/* ---- system/uploads/label-fonts: denied on nginx and probed by D009 -------
   Fonts uploaded for asset label designs are served only through
   api/assets/label_font.php. The folder's .htaccess / web.config (PR #350) cover
   Apache and IIS; nginx has no in-folder equivalent, so the shipped config must
   carry the line, and D009 must probe the folder so a server without it shows. */
echo "\nsystem/uploads/label-fonts is never served directly\n";
echo str_repeat('=', 64) . "\n";
$lht = (string)@file_get_contents($root . '/system/uploads/label-fonts/.htaccess');
check('system/uploads/label-fonts/.htaccess denies the folder (Apache)',
    stripos($lht, 'Require all denied') !== false);
check('system/uploads/label-fonts/web.config exists (IIS)',
    is_file($root . '/system/uploads/label-fonts/web.config'));
check('the shipped nginx config returns 404 for /system/uploads/label-fonts/',
    (bool)preg_match('#location\s+\^~\s*/system/uploads/label-fonts/\s*\{[^}]*return\s+404#', $ngx));
$d009 = (string)@file_get_contents($root . '/api/system/debug-tools/D009_guarded_paths.php');
check('D009 probes system/uploads/label-fonts as a denied folder',
    (bool)preg_match("#'system/uploads/label-fonts'\s*=>\s*\[[^\]]*'deny'\s*\]#", $d009));

/* forms/images: pictures on forms and catalogue cards are served only through
   api/forms/image.php / card_image.php (which check the form's audience); the
   same three layers as label-fonts. */
echo "\nforms/images is never served directly\n";
check('forms/images/.htaccess denies the folder (Apache)',
    stripos((string)@file_get_contents($root . '/forms/images/.htaccess'), 'Require all denied') !== false
    || stripos((string)@file_get_contents($root . '/forms/images/.htaccess'), 'Deny from all') !== false);
check('the shipped nginx config returns 404 for /forms/images/',
    (bool)preg_match('#location\s+\^~\s*/forms/images/\s*\{[^}]*return\s+404#', $ngx));
check('D009 probes forms/images as a denied folder',
    (bool)preg_match("#'forms/images'\s*=>\s*\[[^\]]*'deny'\s*\]#", $d009));

echo "\nKnowledge inline images are never served directly\n";
$kbGuard = (string)@file_get_contents($root . '/uploads/knowledge/.htaccess');
check('uploads/knowledge denies direct reads on Apache', stripos($kbGuard, 'Require all denied') !== false);
check('uploads/knowledge ships an IIS guard', is_file($root . '/uploads/knowledge/web.config'));
check('nginx denies the parent uploads directory', (bool)preg_match('#location\s+\^~\s+/uploads/\s*\{\s*return\s+404#', $ngx));
check('D009 probes Knowledge image storage', strpos($d009, "'uploads/knowledge'") !== false);

echo "\nREST API uploads in progress are never served directly (GH #416, #417)\n";
$upGuard = (string)@file_get_contents($root . '/uploads/api-incoming/.htaccess');
check('uploads/api-incoming denies direct reads on Apache', stripos($upGuard, 'Require all denied') !== false);
check('uploads/api-incoming ships an IIS guard', is_file($root . '/uploads/api-incoming/web.config'));
check('D009 probes the REST upload store', (bool)preg_match("#'uploads/api-incoming'\s*=>\s*\[[^\]]*'deny'\s*\]#", $d009));

/* ---- CONTROLS — this checker must be able to fail ------------------------
   Every assertion above is pattern matching, and pattern matching that only
   ever passes is not evidence. These exercise the matcher itself. */
echo "\n";
$guardRe = "~PHP_SAPI\s*!==\s*'cli(-server)?'~";
check('CONTROL — the matcher SEES a real guard',
    (bool)preg_match($guardRe, "<?php\nif (PHP_SAPI !== 'cli') { exit; }"));
check('CONTROL — …and does NOT see one in a file without it',
    !preg_match($guardRe, "<?php\n// just a comment about cli\n\$x = 1;"));
check('CONTROL — a guard mentioned only in PROSE does not count as one',
    !preg_match($guardRe, "<?php\n/* this file should check PHP_SAPI one day */"));

echo "\n" . str_repeat('=', 64) . "\n";
echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
