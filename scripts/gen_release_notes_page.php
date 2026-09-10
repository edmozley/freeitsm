<?php
/**
 * Generate the website's Release notes page from `releases/*.md`.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS GENERATED AND NOT HAND-WRITTEN
 * ---------------------------------------------------------------------------
 * RELEASING.md exists to guarantee that the tag, the GitHub release body and
 * freeitsm.co.uk cannot disagree, by making `releases/X.Y.Z.md` the single
 * source for all three. A hand-maintained website page reintroduces exactly the
 * gap that file was created to close — and that gap is not hypothetical: the
 * site sat four releases behind before this page existed.
 *
 * So the page is built from the front matter (`version`, `date`, `headline`),
 * which RELEASING.md §6 already describes as the part "the website reads". No
 * summary is written twice, and adding a release to the site is one command.
 *
 * ---------------------------------------------------------------------------
 * HOW TO USE IT
 * ---------------------------------------------------------------------------
 *   php scripts/gen_release_notes_page.php
 *
 * Writes C:/wamp64/www/freeitsm/releases.html (override with --out=PATH).
 *
 * ⚠️ THE WEBSITE FOLDER IS NOT A GIT REPOSITORY. This script writes the file;
 * uploading it is a separate manual FTP step. It prints what to upload.
 *
 * Run it as step 7 of the release procedure, after `releases/X.Y.Z.md` exists.
 */

// CLI ONLY — scripts/ sits inside the web root on a normal install and this one
// writes a file. In PHP rather than the web server, so it holds on nginx and IIS
// where an .htaccess deny is ignored.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is command-line only.\n");
}

$appRoot     = dirname(__DIR__);
$releasesDir = $appRoot . '/releases';
$outPath     = 'C:/wamp64/www/freeitsm/releases.html';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) $outPath = substr($arg, 6);
}

if (!is_dir($releasesDir)) {
    fwrite(STDERR, "No releases directory at {$releasesDir}\n");
    exit(1);
}

/**
 * Read the YAML-ish front matter from a release note.
 *
 * Deliberately a tiny parser rather than a YAML library: the block is four
 * known scalar keys written by hand to a template, and pulling in a dependency
 * for `key: value` would be the larger risk.
 */
function readFrontMatter(string $file): ?array
{
    $fh = fopen($file, 'r');
    if (!$fh) return null;

    $first = fgets($fh);
    if ($first === false || rtrim($first, "\r\n") !== '---') { fclose($fh); return null; }

    $meta = [];
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '---') { fclose($fh); return $meta; }
        if (!preg_match('/^([a-z_]+):\s*(.*)$/', $line, $m)) continue;
        $meta[$m[1]] = trim($m[2], " \t\"'");
    }
    fclose($fh);
    return null;   // unterminated front matter — treat as malformed
}

/** Sort key so 1.10.0 lands after 1.9.0 rather than before it. */
function versionKey(string $v): array
{
    $parts = array_map('intval', explode('.', $v));
    return [$parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0];
}

$releases = [];
$skipped  = [];
foreach (glob($releasesDir . '/*.md') as $file) {
    $name = basename($file);
    if ($name === 'TEMPLATE.md') continue;

    $meta = readFrontMatter($file);
    if (!$meta || empty($meta['version']) || empty($meta['date']) || empty($meta['headline'])) {
        // ⚠️ Named out loud rather than silently dropped. A release missing from
        // the page because its front matter was malformed is the exact failure
        // this script exists to prevent.
        $skipped[] = $name . ' (front matter missing version, date or headline)';
        continue;
    }
    $meta['security'] = isset($meta['security']) && strtolower($meta['security']) === 'true';
    $releases[] = $meta;
}

if (!$releases) {
    fwrite(STDERR, "No usable release notes found in {$releasesDir}\n");
    exit(1);
}

usort($releases, fn($a, $b) => versionKey($b['version']) <=> versionKey($a['version']));

$latest      = $releases[0];
$count       = count($releases);
$pageDesc    = 'Every tagged release of FreeITSM, newest first, with a one-line summary and a link to the full notes on GitHub.';
$e           = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$prettyDate  = function (string $iso): string {
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    return $d ? $d->format('j F Y') : $iso;
};

// Structured data, matching what updates.php already publishes.
$ld = [
    '@context' => 'https://schema.org',
    '@type'    => 'ItemList',
    'name'     => 'FreeITSM Releases',
    'numberOfItems' => $count,
    'itemListElement' => [],
];
foreach ($releases as $i => $r) {
    $ld['itemListElement'][] = [
        '@type' => 'ListItem', 'position' => $i + 1,
        'item'  => [
            '@type' => 'SoftwareApplication',
            'name'  => 'FreeITSM ' . $r['version'],
            'softwareVersion' => $r['version'],
            'description'     => $r['headline'],
            'datePublished'   => $r['date'],
            'url' => 'https://github.com/edmozley/freeitsm/releases/tag/v' . $r['version'],
        ],
    ];
}

ob_start();
?><!DOCTYPE html>
<!--
    GENERATED FILE - DO NOT EDIT BY HAND.

    Built from freeitsm-app/releases/*.md by
    freeitsm-app/scripts/gen_release_notes_page.php, so the website, the git tag
    and the GitHub release body all read from one source and cannot drift apart.

    To change a summary, edit the `headline:` in that release's markdown file and
    re-run the script. Editing this file directly will be overwritten.
-->
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Release notes - FreeITSM</title>
    <meta name="description" content="<?php echo $e($pageDesc); ?>">
    <meta property="og:title" content="Release notes - FreeITSM">
    <meta property="og:description" content="<?php echo $e($pageDesc); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://freeitsm.co.uk/releases.html">
    <link rel="canonical" href="https://freeitsm.co.uk/releases.html">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="stylesheet" href="css/style.css?v=20260222b">
    <script type="application/ld+json"><?php echo json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
    <style>
        .rel-hero { padding: 56px 0 28px; }
        .rel-hero h1 { margin: 0 0 10px; }
        .rel-hero p { max-width: 760px; color: var(--text-muted, #5b6b7a); margin: 0 0 6px; }
        .rel-list { padding: 8px 0 64px; }
        .rel-item {
            border: 1px solid var(--border, #e3e8ee);
            border-radius: 10px;
            padding: 20px 22px;
            margin-bottom: 14px;
            background: #fff;
        }
        .rel-item.is-latest { border-color: #2d6a4f; box-shadow: 0 2px 10px rgba(45,106,79,.08); }
        .rel-head { display: flex; align-items: baseline; flex-wrap: wrap; gap: 10px 14px; margin-bottom: 8px; }
        .rel-ver { font-size: 20px; font-weight: 700; color: var(--text, #1b2733); }
        .rel-date { font-size: 13px; color: var(--text-muted, #6b7a88); }
        .rel-badge {
            font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase;
            padding: 2px 9px; border-radius: 999px; background: #e6f2ec; color: #2d6a4f;
        }
        .rel-badge.sec { background: #fdecea; color: #b42318; }
        .rel-headline { margin: 0 0 14px; line-height: 1.6; color: var(--text, #33414f); }
        .rel-link { font-weight: 600; text-decoration: none; }
        .rel-link:hover { text-decoration: underline; }
        .rel-note { font-size: 13px; color: var(--text-muted, #6b7a88); margin-top: 26px; }
        @media (max-width: 640px) { .rel-hero { padding: 36px 0 20px; } .rel-item { padding: 16px; } }
    </style>
</head>
<body>
    <a href="#main" class="skip-link">Skip to main content</a>

    <!-- HEADER -->
    <header class="site-header">
        <div class="container">
            <a href="/" class="logo"><div class="logo-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg></div><span>FreeITSM</span></a>
            <button class="hamburger" aria-label="Toggle navigation" aria-expanded="false"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg></button>
            <nav>
                <ul class="nav-links">
                    <li><a href="modules.html">Modules</a></li>
                    <li><a href="compare/">Compare</a></li>
                    <li><a href="ai.html">AI</a></li>
                    <li><a href="deep-dive/">Deep Dive</a></li>
                    <li><a href="screenshots.html">Screenshots</a></li>
                    <li><a href="videos.html">Videos</a></li>
                    <li><a href="getting-started.html">Getting Started</a></li>
                    <li class="nav-break" aria-hidden="true"></li>
                    <li><a href="releases.html" class="active">Release notes</a></li>
                    <li><a href="updates.php">Updates</a></li>
                    <li><a href="scripts.html">Scripts</a></li>
                    <li><a href="other-projects.html">Other Projects</a></li>
                    <li><a href="ethos.html">Ethos</a></li>
                    <li><a href="privacy.html">Privacy</a></li>
                    <li><a href="about.html">About</a></li>
                    <li><a href="contact.html">Contact</a></li>
                    <li><a href="https://github.com/edmozley/freeitsm" target="_blank" rel="noopener" class="nav-cta"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/></svg> GitHub</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main id="main">
        <section class="rel-hero">
            <div class="container">
                <h1>Release notes</h1>
                <p>Every tagged release of FreeITSM, newest first. Each one links to the full notes on GitHub, which say what changed, how to upgrade and whether you can roll back.</p>
                <p>The current release is <strong><?php echo $e($latest['version']); ?></strong>, published <?php echo $e($prettyDate($latest['date'])); ?>.</p>
            </div>
        </section>

        <section class="rel-list">
            <div class="container">
<?php foreach ($releases as $i => $r): ?>
                <article class="rel-item<?php echo $i === 0 ? ' is-latest' : ''; ?>">
                    <div class="rel-head">
                        <span class="rel-ver"><?php echo $e($r['version']); ?></span>
                        <span class="rel-date"><?php echo $e($prettyDate($r['date'])); ?></span>
<?php if ($i === 0): ?>
                        <span class="rel-badge">Current</span>
<?php endif; ?>
<?php if ($r['security']): ?>
                        <span class="rel-badge sec">Security</span>
<?php endif; ?>
                    </div>
                    <p class="rel-headline"><?php echo $e($r['headline']); ?></p>
                    <a class="rel-link" href="https://github.com/edmozley/freeitsm/releases/tag/v<?php echo $e($r['version']); ?>" target="_blank" rel="noopener">Read the full notes for <?php echo $e($r['version']); ?> &rarr;</a>
                </article>
<?php endforeach; ?>
                <p class="rel-note">Everything that shipped before <?php echo $e($releases[count($releases) - 1]['version']); ?> is listed individually on the <a href="updates.php">updates page</a>.</p>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="footer-bottom" style="border:none;">
                <span>&copy; 2026 FreeITSM &middot; freeitsm.co.uk</span>
                <span class="footer-tagline">"Built with care. Shared with joy. Free forever."</span>
            </div>
        </div>
    </footer>

    <script src="js/main.js"></script>
</body>
</html>
<?php
$html = ob_get_clean();

if (!is_dir(dirname($outPath))) {
    fwrite(STDERR, "Output directory does not exist: " . dirname($outPath) . "\n");
    exit(1);
}
file_put_contents($outPath, $html);

echo "Wrote " . $outPath . "\n";
echo "  " . $count . " releases, newest " . $latest['version'] . " (" . $latest['date'] . ")\n";
foreach ($skipped as $s) {
    echo "  ⚠️  SKIPPED " . $s . "\n";
}
echo "\n⚠️  The website folder is NOT a git repository — upload this by FTP:\n";
echo "      " . $outPath . "\n";
