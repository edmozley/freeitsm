<?php
/* Never over the web. Synthetic browser fixtures only; no database or collector. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * The actual Servers storage renderer, English strings and delivered styles, in Chrome.
 * Run: php tests/virtual-estate-storage-browser.php (optionally CHROME_BIN).
 * Covers both palettes, a narrow viewport, unknown/zero, failures and untrusted text.
 * The pre-feature renderer fails the storage inventory checks.
 */
function cdpEvaluate(string $wsUrl, string $expression, int $timeout): string
{
    $u = parse_url($wsUrl);
    $sock = @stream_socket_client('tcp://' . $u['host'] . ':' . $u['port'], $errno, $error, 5);
    if (!$sock) throw new RuntimeException('Cannot reach the browser: ' . $error);
    stream_set_timeout($sock, $timeout);
    $key = base64_encode(random_bytes(16));
    fwrite($sock, "GET {$u['path']} HTTP/1.1\r\nHost: {$u['host']}:{$u['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && !feof($sock)) $head .= fread($sock, 1);
    if (!str_contains($head, ' 101 ')) throw new RuntimeException('The browser refused the DevTools connection.');

    $send = function (string $text) use ($sock) {
        $len = strlen($text);
        $frame = chr(0x81) . ($len < 126 ? chr(0x80 | $len) : ($len < 65536 ? chr(0x80 | 126) . pack('n', $len) : chr(0x80 | 127) . pack('J', $len)));
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) $masked .= $text[$i] ^ $mask[$i % 4];
        fwrite($sock, $frame . $mask . $masked);
    };
    $read = function (int $n) use ($sock) {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($sock, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) throw new RuntimeException('The browser closed the DevTools connection or timed out.');
            $buf .= $chunk;
        }
        return $buf;
    };
    $receive = function () use ($read) {
        $message = '';
        do {
            [$b1, $b2] = array_values(unpack('C2', $read(2)));
            $len = $b2 & 0x7f;
            if ($len === 126) $len = unpack('n', $read(2))[1];
            elseif ($len === 127) $len = unpack('J', $read(8))[1];
            $message .= $len ? $read($len) : '';
        } while (!($b1 & 0x80));
        return $message;
    };

    $send(json_encode(['id' => 1, 'method' => 'Runtime.evaluate',
        'params' => ['expression' => $expression, 'awaitPromise' => true, 'returnByValue' => true]]));
    $deadline = time() + $timeout;
    while (time() < $deadline) {
        $msg = json_decode($receive(), true);
        if (($msg['id'] ?? null) !== 1) continue;           // an event, not our answer
        fclose($sock);
        $value = $msg['result']['result']['value'] ?? null;
        if (!is_string($value)) throw new RuntimeException('Browser harness failed: ' . json_encode($msg));
        return $value;
    }
    fclose($sock);
    throw new RuntimeException('Browser test timed out.');
}

$root = dirname(__DIR__);
$browser = getenv('CHROME_BIN') ?: null;
foreach (['C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $candidate) {
    if (!$browser && is_file($candidate)) { $browser = $candidate; }
}
if (!$browser || !is_file($browser)) { fwrite(STDERR, "Set CHROME_BIN to Chrome, Chromium or Edge.\n"); exit(1); }
$source = file_get_contents($root . '/asset-management/servers/index.php');
$start = strpos($source, '        const HV =');
$end = strpos($source, '        function showEmptyState(', $start);
$renderer = substr($source, $start, $end - $start);
preg_match('~        function escapeHtml\(text\) \{.*?\n        \}~s', $source, $escape);
preg_match_all('~<style>(.*?)</style>~s', $source, $css);
$styles = '<style>' . file_get_contents($root . '/assets/css/theme.css') . '</style>'
    . '<style>' . file_get_contents($root . '/assets/css/inbox.css') . '</style>'
    . '<style>' . implode("\n", $css[1]) . '</style>'
    . '<style>' . file_get_contents($root . '/assets/css/mobile.css') . '</style>';
$panelStart = strpos($source, '<div class="hv-panel" id="hvPanel"');
$panelEnd = strpos($source, '<!-- Toolbar -->', $panelStart);
$panel = preg_replace('~<\?php.*?\?>~s', '', substr($source, $panelStart, $panelEnd - $panelStart));
$translations = ['asset-management' => require $root . '/lang/en/asset-management.php'];
$at = '2026-01-01 10:00:00';
$volume = ['id' => 1, 'name' => 'ZZ <img src=x onerror="window.storageXss=true">',
    'path' => 'C:\\ClusterStorage\\ZZ-' . str_repeat('long-path-', 12), 'filesystem' => 'ReFS', 'state' => 'Online',
    'coordinator_node' => 'ZZ-HV-1', 'total_bytes' => 1073741824, 'free_bytes' => 0, 'used_bytes' => 1073741824,
    'last_seen_observed_at' => $at, 'not_seen_since' => null, 'not_confirmed_since' => $at];
$data = ['connections' => [['id' => 1, 'name' => 'ZZ connection', 'active' => true, 'freshness' => 'on_time',
    'last_report_at' => $at, 'interval' => 15, 'last_summary' => null, 'silent_minutes' => 3],
    ['id' => 2, 'name' => 'ZZ quiet', 'active' => true, 'freshness' => 'overdue',
    'last_report_at' => $at, 'interval' => 15, 'last_summary' => null, 'silent_minutes' => 300]], 'storage' => [
    ['connection_id' => 1, 'connection_name' => 'ZZ connection', 'cluster_id' => 1, 'cluster_name' => 'ZZ cluster A',
        'failing_since' => $at, 'failed_runs' => 3,
        'last_attempted_at' => $at, 'last_observed_at' => $at, 'read_error' => 'ZZ read failed <svg onload="window.storageXss=true">',
        'volumes' => [$volume, array_replace($volume, ['id' => 2, 'name' => 'ZZ unknown', 'total_bytes' => null, 'free_bytes' => null, 'used_bytes' => null])]],
    ['connection_id' => 1, 'connection_name' => 'ZZ connection', 'cluster_id' => 2, 'cluster_name' => 'ZZ cluster B',
        'last_attempted_at' => $at, 'last_observed_at' => $at, 'read_error' => null, 'volumes' => []],
    ['connection_id' => 1, 'connection_name' => 'ZZ connection', 'cluster_id' => 3, 'cluster_name' => null, 'cluster_external_id' => 'zz-unnamed-cluster-id',
        'last_attempted_at' => null, 'last_observed_at' => null, 'read_error' => null, 'volumes' => []],
]];
$frame = '<!DOCTYPE html><html lang="en" data-theme="default"><head><meta charset="utf-8">' . $styles . '</head>'
    . '<body><main class="servers-content">' . $panel . '</main><script>window.translations = '
    . json_encode($translations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script><script>'
    . file_get_contents($root . '/assets/js/i18n.js') . '</script><script>' . file_get_contents($root . '/assets/js/tz.js')
    . '</script><script>' . $escape[0] . "\n" . $renderer . "\nwindow.fixture = "
    . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    . '; renderHyperv(window.fixture);</script></body></html>';
$parent = <<<'HTML'
<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>
<iframe id="frame" src="frame.html" style="width:1366px;height:900px;border:0"></iframe><pre id="result"></pre>
<script>
const results = [];
const record = (label, pass, detail = '') => results.push({label, pass:!!pass, detail});
const frame = document.getElementById('frame');
const pause = () => new Promise(resolve => setTimeout(resolve, 80));
frame.onload = async () => {
    try {
        const w = frame.contentWindow, d = frame.contentDocument;
        const text = () => d.getElementById('hvPanel').textContent;
        record('three independently labelled clusters', d.querySelectorAll('.storage-cluster').length === 3);
        record('one row per reported partition', d.querySelectorAll('.storage-table tbody tr').length === 2);
        record('zero free bytes remain visible', /0 B/.test(text()));
        record('unknown capacity is explicit', text().includes('Unknown'));
        record('read failure keeps data and is visible', text().includes('ZZ read failed') && text().includes('Not confirmed'));
        record('successful empty and never collected are distinct', text().includes('No volumes reported') && text().includes('Storage not collected'));
        // #296 review: the collector warnings in words.
        record('an overdue connection says how long it has been silent', text().includes('No report for 5 hours') && d.querySelectorAll('.hv-silent').length === 2);
        record('storage failing for several reports says so', text().includes('in the last 3 reports'));
        record('a cluster with no name shows its identifier', text().includes('zz-unnamed-cluster-id') && !text().includes('null'));
        record('source fields are escaped, not executable HTML', !d.querySelector('#hvPanel img, #hvPanel svg') && !w.storageXss && text().includes('<img'));
        for (const theme of ['default', 'dark']) {
            d.documentElement.dataset.theme = theme;
            await pause();
            const panelStyle = w.getComputedStyle(d.getElementById('hvPanel'));
            const cell = d.querySelector('.storage-table td');
            record(theme + ': text differs from panel background', cell && w.getComputedStyle(cell).color !== panelStyle.backgroundColor);
            frame.style.width = '360px';
            await pause();
            record(theme + ': narrow viewport contains horizontal overflow', d.documentElement.scrollWidth <= 361,
                {page:d.documentElement.scrollWidth, viewport:d.documentElement.clientWidth});
            const scroller = d.querySelector('.storage-scroll');
            record(theme + ': narrow table scrolls with keyboard focus', scroller && scroller.tabIndex === 0 && scroller.scrollWidth > scroller.clientWidth);
            frame.style.width = '1366px';
        }
        w.renderHyperv({connections:[],storage:[]});
        record('no authorized estate hides the panel', d.getElementById('hvPanel').style.display === 'none');
        document.getElementById('result').textContent = JSON.stringify(results);
    } catch (error) { document.getElementById('result').textContent = JSON.stringify([{label:'harness',pass:false,detail:String(error)}]); }
};
</script></body></html>
HTML;
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freeitsm_storage_' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$server = null;
$browserProcess = null;
$exit = 1;
try {
    file_put_contents($tmp . '/index.html', $parent);
    file_put_contents($tmp . '/frame.html', $frame);
    // Let the OS pick a free loopback port; no existing web server is involved.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot allocate a loopback port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr(strrchr($address, ':'), 1);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $tmp],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']], $serverPipes);
    if (!is_resource($server)) throw new RuntimeException('Cannot start the fixture server.');
    fclose($serverPipes[0]);
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(50000);
    }
    if (!$ready) throw new RuntimeException('Fixture server did not become ready.');
    $debugSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$debugSocket) throw new RuntimeException('Cannot allocate the browser debugging port.');
    $debugPort = (int)substr(strrchr(stream_socket_get_name($debugSocket, false), ':'), 1);
    fclose($debugSocket);
    $browserProcess = proc_open([$browser, '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--remote-debugging-port=' . $debugPort,
        '--user-data-dir=' . $tmp . '/profile', 'http://127.0.0.1:' . $port . '/index.html'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/browser.log', 'a'], 2 => ['file', $tmp . '/browser.log', 'a']], $browserPipes);
    if (!is_resource($browserProcess)) throw new RuntimeException('Cannot start headless Chromium.');
    fclose($browserPipes[0]);
    // Real animation frames matter to ResizeObserver, and the page waits on
    // them: a virtual-time --dump-dom run never advances them and the checks
    // stall. So the browser runs in real time and is asked for the result over
    // its DevTools socket.
    $page = null;
    for ($i = 0; $i < 50 && !$page; $i++) {
        $list = @file_get_contents('http://127.0.0.1:' . $debugPort . '/json/list');
        foreach ((array)json_decode((string)$list, true) as $p) {
            if (($p['type'] ?? '') === 'page' && substr($p['url'] ?? '', -11) === '/index.html') { $page = $p; break; }
        }
        if (!$page) usleep(100000);
    }
    if (!$page) throw new RuntimeException('Headless browser page unavailable.');
    $value = cdpEvaluate($page['webSocketDebuggerUrl'],
        "new Promise(resolve => { const poll = setInterval(() => { const r = document.getElementById('result'); if (r && r.textContent.startsWith('[')) { clearInterval(poll); resolve(r.textContent); } }, 100); })",
        25);
    file_put_contents($tmp . '/result.json', $value);
    $checks = json_decode(file_get_contents($tmp . '/result.json'), true);
    if (!is_array($checks) || count($checks) !== 17) throw new RuntimeException('Browser did not finish all seventeen checks.');
    $failed = 0;
    foreach ($checks as $check) {
        echo ($check['pass'] ? '  PASS  ' : '  FAIL  ') . $check['label'] . ' ' . json_encode($check['detail']) . "\n";
        if (!$check['pass']) $failed++;
    }
    echo (count($checks) - $failed) . " passed, $failed failed\n";
    $exit = $failed ? 1 : 0;
} catch (Throwable $e) {
    $message = str_replace([$tmp, getenv('USERPROFILE') ?: $tmp], ['[temporary directory]', '[user profile]'], $e->getMessage());
    fwrite(STDERR, $message . "\n");
} finally {
    foreach ([$browserProcess, $server] as $process) {
        if (!is_resource($process)) continue;
        // TRAP: on Windows proc_terminate() ends only the process it started; Chrome's
        // renderer and GPU children live on and pile up. Kill the whole tree.
        if (PHP_OS_FAMILY === 'Windows') {
            $pid = proc_get_status($process)['pid'] ?? 0;
            if ($pid) exec('taskkill /T /F /PID ' . (int)$pid . ' 2>NUL');
        }
        proc_terminate($process); proc_close($process);
    }
    // Only our unique temporary directory, never a user's existing profile.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) @rmdir($file->getPathname());
        else @unlink($file->getPathname());
    }
    @rmdir($tmp);
}
exit($exit);
