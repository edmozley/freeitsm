<?php
/* NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #418: on every module settings page, One page shows the sections in the TAB BAR's order,
 * and the index down the left lists them in the order they are drawn. Before the fix the index
 * followed the tabs and the page followed each page's HTML, so most pages disagreed.
 *
 * Drives every page that calls renderSettingsTabBar() in headless Chrome with a forged
 * administrator session, clicks One page, and compares three lists: the tabs, the sections as
 * drawn (top to bottom) and the index links. Pages without the switch (the SSO pages, the
 * Intune import) are listed as skipped.
 *
 * Run from the install it tests - it writes a throwaway page into it for a moment:
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_TEST_URL=http://localhost/<install> php tests/settings-one-page-order.php [filter]
 * Optional: CHROME_BIN, FREEITSM_TEST_SESSION_DIR (PHP's session folder, if not session.save_path).
 */
chdir(dirname(__DIR__));
require 'config.php';
require_once 'includes/functions.php';
$conn = connectToDatabase();
if (!getenv('FREEITSM_TEST_DATABASE') || $conn->query('SELECT DATABASE()')->fetchColumn() !== getenv('FREEITSM_TEST_DATABASE')) {
    exit("Set FREEITSM_TEST_DATABASE to this install's (throwaway) database.\n");
}
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if ($base === '') exit("FREEITSM_TEST_URL is required.\n");
$sessDir = rtrim((string)(getenv('FREEITSM_TEST_SESSION_DIR') ?: session_save_path() ?: sys_get_temp_dir()), '/\\');
$chrome = getenv('CHROME_BIN') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
$admin = (int)$conn->query('SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1')->fetchColumn();
$only = $argv[1] ?? '';

// Every page that draws a settings tab bar.
$pages = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', substr($f->getPathname(), 2));
    if (substr($p, -4) !== '.php' || preg_match('~^(tests|includes|vendor|lang|api|scripts)/~', $p)) continue;
    if (strpos((string)file_get_contents($f->getPathname()), 'renderSettingsTabBar(') !== false) $pages[] = $p;
}
sort($pages);

// Runs in a parent page around the settings page; writes its findings into <pre id="out">.
$js = <<<'JS'
const f = document.getElementById('f');
f.addEventListener('load', () => { setTimeout(() => {
  const w = f.contentWindow, d = w.document, out = document.getElementById('out');
  try {
    const btn = d.querySelector('.sv-switch button[data-view=page]');
    if (!btn) { out.textContent = JSON.stringify({ skip: 'no One page switch' }); return; }
    btn.click();
    setTimeout(() => {
      const tabBtns = Array.from(d.querySelectorAll('.tabs .tab[data-tab]')).filter(b => b.tagName !== 'A');
      const name = b => b.textContent.replace(/\s*\u2197\s*$/, '').trim();
      const label = p => { const b = tabBtns.find(b => p.id === b.dataset.tab + '-tab' || p.id === 'tab-' + b.dataset.tab || p.id === b.dataset.tab); return b ? name(b) : null; };
      const panels = Array.from(d.querySelectorAll('.tab-content')).filter(p => p.offsetParent !== null);
      const drawn = panels.sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top).map(label).filter(Boolean);
      out.textContent = JSON.stringify({
        tabs: tabBtns.map(name),
        index: Array.from(d.querySelectorAll('.sv-list .sv-link:not(.sv-out)')).map(a => a.textContent.trim()),
        drawn
      });
    }, 1200);
  } catch (e) { out.textContent = JSON.stringify({ error: String(e) }); }
}, 1500); });
JS;

$passed = 0; $failed = 0;
foreach ($pages as $page) {
    if ($only !== '' && strpos($page, $only) === false) continue;
    $sid = 'zzsv' . bin2hex(random_bytes(6));
    file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;');
    $frame = 'zzsvf_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($frame, "<?php setcookie('PHPSESSID', '$sid', 0, '/'); ?><!doctype html><html><body>"
        . "<iframe id=\"f\" src=\"$page\" style=\"width:1400px;height:900px\"></iframe><pre id=\"out\">pending</pre><script>$js</script></body></html>");
    $cmd = '"' . $chrome . '" --headless=new --disable-gpu --window-size=1450,950 --user-data-dir="' . sys_get_temp_dir() . '/zzsvprof' . getmypid()
        . '" --virtual-time-budget=9000 --dump-dom "' . $base . '/' . $frame . '"' . (DIRECTORY_SEPARATOR === '\\' ? ' 2>NUL' : ' 2>/dev/null');
    $dom = (string)shell_exec($cmd);
    @unlink($frame);
    @unlink("$sessDir/sess_$sid");
    preg_match('~<pre id="out">(.*?)</pre>~s', $dom, $m);
    $r = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
    if (!is_array($r)) { echo "  FAIL  $page: no result (" . substr($m[1] ?? 'no output', 0, 80) . ")\n"; $failed++; continue; }
    if (isset($r['skip'])) { echo "  skip  $page: {$r['skip']}\n"; continue; }
    if (isset($r['error'])) { echo "  FAIL  $page: {$r['error']}\n"; $failed++; continue; }
    $inTabOrder = array_values(array_filter($r['tabs'], fn($t) => in_array($t, $r['drawn'], true)));
    $ok = $r['index'] === $r['drawn'] && $r['drawn'] === $inTabOrder && count($r['drawn']) >= 2;
    if ($ok) { echo "  PASS  $page (" . count($r['drawn']) . " sections)\n"; $passed++; continue; }
    $failed++;
    echo "  FAIL  $page\n        tabs : " . implode(' | ', $r['tabs']) . "\n        drawn: " . implode(' | ', $r['drawn']) . "\n        index: " . implode(' | ', $r['index']) . "\n";
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
