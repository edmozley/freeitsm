<?php
/* NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Embedded task forms must grow AFTER their asynchronous load, and shrink when
 * conditional fields disappear. Observing a viewport-sized body left long forms
 * clipped at the loading card's 600px, with overflow hidden and no submit button.
 *
 * Run: php tests/task-form-embed.php
 * Requires Node.js 22+ and headless Chromium (CHROME_BIN, or installed Chrome/Edge).
 * Uses the real fill-page styles, height reporter and task-window receiver in
 * temporary same-origin pages. No database, login, forms or settings are touched.
 * The pre-fix code fails the delayed-growth and shrink assertions (the control).
 */

$root = dirname(__DIR__);
$browser = getenv('CHROME_BIN') ?: null;
foreach ([
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
] as $candidate) {
    if (!$browser && is_file($candidate)) $browser = $candidate;
}
if (!$browser || !is_file($browser)) {
    fwrite(STDERR, "Set CHROME_BIN to a Chrome, Chromium or Edge executable.\n");
    exit(1);
}

$fill = file_get_contents($root . '/forms/fill.php');
$tasks = file_get_contents($root . '/assets/js/tasks.js');
// Lift the loaded CSS IN ORDER, including mobile.css after the embed overrides.
// A comment mentions "inline <style>"; it is not an actual style element.
$markup = preg_replace('~<!--.*?-->~s', '', $fill);
preg_match_all('~<link\b[^>]*href="\.\./(assets/css/[^"?]+)[^"]*"[^>]*>|<style>(.*?)</style>~s', $markup, $matches, PREG_SET_ORDER);
$styles = '';
foreach ($matches as $match) {
    $styles .= '<style>' . (!empty($match[1]) ? file_get_contents($root . '/' . $match[1]) : $match[2]) . '</style>';
}
$reportStart = strpos($fill, '// Embedded: say how tall');
$reportEnd = $reportStart === false ? false : strpos($fill, '/** Posts a task form action.', $reportStart);
$receiveStart = strpos($tasks, '// The embedded form says how tall');
$receiveEnd = $receiveStart === false ? false : strpos($tasks, "\n});", $receiveStart);
if (!$styles || $reportStart === false || $reportEnd === false || $receiveStart === false || $receiveEnd === false) {
    fwrite(STDERR, "Cannot locate the production embed styles or message handlers.\n");
    exit(1);
}
$reporter = substr($fill, $reportStart, $reportEnd - $reportStart);
$receiver = substr($tasks, $receiveStart, $receiveEnd + 5 - $receiveStart);
$frame = '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
    . $styles . '</head><body class="fill-embed"><div class="main-container fill-container">'
    . '<div class="fill-content"><div class="fill-card" id="formCard">Loading</div></div></div><script>'
    . 'const TASK_FORM_ID = 1; const mode = new URLSearchParams(location.search).get("mode");'
    . 'if (mode === "poll") window.ResizeObserver = undefined;'
    . 'if (mode !== "no-reporter") {' . $reporter . '}</script></body></html>';
$parent = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><style>
* { box-sizing: border-box; } body { margin: 0; }
</style><style>__TASK_CSS__</style></head><body>
<div id="taskFormsSection"><iframe id="tfFrame1" class="tf-frame" title="ZZ embed test"></iframe></div>
<pre id="result">RUNNING</pre><script>
__RECEIVER__
const results = [];
const frame = document.getElementById('tfFrame1');
const record = (label, pass, detail) => results.push({label, pass, detail});
const pause = ms => new Promise(resolve => setTimeout(() => requestAnimationFrame(() => requestAnimationFrame(resolve)), ms));
const load = mode => new Promise(resolve => { frame.onload = resolve; frame.src = 'frame.html?mode=' + mode; });
const fits = () => {
    const card = frame.contentDocument.getElementById('formCard');
    return card.getBoundingClientRect().bottom <= frame.clientHeight + 1;
};
const dimensions = () => ({frame:frame.clientHeight, card:frame.contentDocument.getElementById('formCard').offsetHeight});
async function exercise(mode, delay) {
    frame.style.width = '1000px';
    frame.style.height = '420px';
    await load(mode);
    await pause(delay);
    const doc = frame.contentDocument;
    const card = doc.getElementById('formCard');
    card.innerHTML = '<form><div id="conditional" style="height:1600px">ZZ delayed fields</div><button type="submit">Submit</button></form>';
    await pause(delay);
    record(mode + ': delayed fields and submit button fit', fits(), dimensions());
    const expanded = frame.clientHeight;
    doc.getElementById('conditional').style.display = 'none';
    await pause(delay);
    record(mode + ': hidden fields shrink the frame', fits() && frame.clientHeight < expanded - 500, dimensions());
    card.innerHTML = '<p>' + 'ZZ conditional explanation and instructions. '.repeat(100) + '</p><button>Submit</button>';
    await pause(delay);
    const wide = frame.clientHeight;
    frame.style.width = '360px';
    await pause(delay);
    record(mode + ': narrow layout grows without clipping', fits() && frame.clientHeight > wide + 100, dimensions());
    frame.style.width = '1000px';
    await pause(delay);
    record(mode + ': wider layout shrinks again', fits() && frame.clientHeight < wide + 5, dimensions());
}
(async () => {
    await exercise('observer', 180);
    await exercise('poll', 1100);
    frame.style.height = '420px';
    await load('no-reporter');
    frame.contentDocument.getElementById('formCard').innerHTML = '<div style="height:1800px">ZZ fallback content</div><button>Submit</button>';
    await pause(180);
    frame.contentWindow.scrollTo(0, 900);
    const overflow = frame.contentWindow.getComputedStyle(frame.contentDocument.body).overflowY;
    record('reporter unavailable: remaining content can still be scrolled', frame.contentWindow.scrollY > 100 && !['hidden', 'clip'].includes(overflow),
        {scrollY:frame.contentWindow.scrollY,overflow});
    document.getElementById('result').textContent = JSON.stringify(results);
})().catch(error => { document.getElementById('result').textContent = JSON.stringify([{label:'harness',pass:false,detail:String(error)}]); });
</script></body></html>
HTML;
$parent = str_replace(['__TASK_CSS__', '__RECEIVER__'], [file_get_contents($root . '/assets/css/tasks.css'), $receiver], $parent);

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freeitsm_embed_' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$server = null;
$browserProcess = null;
$driverProcess = null;
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
    // Real animation frames matter to ResizeObserver. A virtual-time --dump-dom
    // run can advance timers without painting, falsely reporting a resize failure.
    $driver = <<<'JS'
const deadline = setTimeout(() => { console.error('Browser test timed out.'); process.exit(1); }, 25000);
let page;
for (let i = 0; i < 50; i++) {
    try {
        const pages = await (await fetch('http://127.0.0.1:' + process.argv[2] + '/json/list')).json();
        page = pages.find(p => p.type === 'page' && p.url.endsWith('/index.html'));
        if (page) break;
    } catch {}
    await new Promise(r => setTimeout(r, 100));
}
if (!page) throw new Error('Headless browser page unavailable.');
const socket = new WebSocket(page.webSocketDebuggerUrl);
socket.addEventListener('open', () => {
    socket.send(JSON.stringify({id:2,method:'Runtime.enable'}));
    socket.send(JSON.stringify({id:4,method:'Page.enable'}));
    socket.send(JSON.stringify({id:5,method:'Page.navigate',params:{url:page.url}}));
    socket.send(JSON.stringify({id:6,method:'Page.bringToFront'}));
});
const run = () => socket.send(JSON.stringify({id:1,method:'Runtime.evaluate',params:{
    expression:`new Promise(resolve => { const poll = setInterval(() => { const result = document.getElementById('result'); if (result && result.textContent.startsWith('[')) { clearInterval(poll); resolve(JSON.parse(result.textContent)); } }, 100); })`,
    awaitPromise:true,returnByValue:true
}}));
let started = false;
socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.method === 'Page.loadEventFired' && !started) { started = true; run(); }
    if (message.method === 'Runtime.exceptionThrown') console.error(JSON.stringify(message.params.exceptionDetails));
    if (message.id !== 1) return;
    if (!message.result?.result?.value) { console.error('Browser harness failed.'); process.exit(1); }
    process.stdout.write(JSON.stringify(message.result.result.value));
    clearTimeout(deadline);
    socket.send(JSON.stringify({id:7,method:'Browser.close'}));
});
JS;
    file_put_contents($tmp . '/driver.mjs', $driver);
    $driverProcess = proc_open([getenv('NODE_BIN') ?: 'node', $tmp . '/driver.mjs', (string)$debugPort],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/result.json', 'w'], 2 => ['file', $tmp . '/driver.log', 'w']], $driverPipes);
    if (!is_resource($driverProcess)) throw new RuntimeException('Cannot start Node.js (NODE_BIN).');
    fclose($driverPipes[0]);
    $driverExit = proc_close($driverProcess);
    $driverProcess = null;
    if ($driverExit !== 0) throw new RuntimeException('Browser driver failed: ' . file_get_contents($tmp . '/driver.log') . substr(file_get_contents($tmp . '/server.log'), -1500));
    $checks = json_decode(file_get_contents($tmp . '/result.json'), true);
    if (!is_array($checks) || count($checks) !== 9) throw new RuntimeException('Browser did not finish all nine checks.');
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
    foreach ([$driverProcess, $browserProcess, $server] as $process) {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
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
