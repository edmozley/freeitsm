<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The shared PDF writer (assets/js/pdf-writer.js, GH #254), in a real browser.
 *
 *   php tests/pdf-writer.php
 *   FREEITSM_BASE_URL=http://localhost/other-install php tests/pdf-writer.php
 *
 * Copies tests/pdf-writer-probe.html into the app root under a random name, opens it
 * in headless Chrome (or Edge) through the local web server, reads the checks it
 * wrote, and deletes the copy. The PDF it made is saved to the temp folder so a
 * person can LOOK at it - Polish, Russian, Hindi, Tamil and Bengali, a table over
 * several pages, shapes and an image.
 *
 * What it proves: the right font for each script, shaped measuring (a joined Hindi
 * letter is narrower than its parts), wrapping, the jsPDF-shaped drawing calls, the
 * table, page numbers, the image stored once - and that NOTHING is fetched from
 * anywhere but this server (an air-gapped install has all it needs).
 *
 * Needs: the app served at FREEITSM_BASE_URL (default http://localhost/freeitsm-app)
 * and Chrome or Edge (CHROME_BIN to point at another).
 */

$root = dirname(__DIR__);
$base = rtrim(getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app', '/') . '/';
$browser = getenv('CHROME_BIN') ?: null;
foreach ([$browser, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
          '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $b) {
    if ($b && is_file($b)) { $browser = $b; break; }
}
if (!$browser || !is_file($browser)) { echo "SKIPPED: no Chrome or Edge found (set CHROME_BIN).\n"; exit(2); }

$name = '_pdfprobe_' . bin2hex(random_bytes(6)) . '.html';
copy(__DIR__ . '/pdf-writer-probe.html', "$root/$name");
try {
    $cmd = escapeshellarg($browser) . ' --headless=old --disable-gpu --virtual-time-budget=60000 --dump-dom ' . escapeshellarg($base . $name);
    $dom = (string)shell_exec($cmd . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
} finally {
    @unlink("$root/$name");
}

if (!preg_match('#<pre id="result">(.*?)</pre>#s', $dom, $m) || trim($m[1]) === 'RUNNING') {
    echo "FAIL  the probe page did not finish - is the app served at $base ?\n";
    exit(1);
}
$res = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
if (!is_array($res)) { echo "FAIL  could not read the probe's result\n"; exit(1); }

$pass = 0; $fail = 0;
foreach ($res['checks'] as $c) {
    if ($c['pass']) { $pass++; echo "  PASS  {$c['label']}\n"; }
    else { $fail++; echo "  FAIL  {$c['label']}\n        " . json_encode($c['detail'], JSON_UNESCAPED_UNICODE) . "\n"; }
}
if (!empty($res['pdf'])) {
    $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freeitsm-pdf-writer-probe.pdf';
    file_put_contents($out, base64_decode($res['pdf']));
    echo "\n  The PDF, to look at: $out\n";
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
