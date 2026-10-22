<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Feature-specific browser harness. cdpEvaluate is copied from task-form-embed.php.
function cdpEvaluate(string $wsUrl, string $expression, int $timeout, ?array $command = null, array $before = []): string
{
    if ($command && ($command['params'] ?? null) === []) $command['params'] = new stdClass();
    $u = parse_url($wsUrl);
    $sock = @stream_socket_client('tcp://' . $u['host'] . ':' . $u['port'], $errno, $error, 5);
    if (!$sock) throw new RuntimeException('Cannot reach the browser: ' . $error);
    stream_set_timeout($sock, $timeout);
    $key = base64_encode(random_bytes(16));
    fwrite($sock, "GET {$u['path']} HTTP/1.1\r\nHost: {$u['host']}:{$u['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $head = '';
    while (strpos($head, "\r\n\r\n") === false && !feof($sock)) $head .= fread($sock, 1);
    if (strpos($head, ' 101 ') === false) throw new RuntimeException('The browser refused the DevTools connection.');

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

    // Device emulation is scoped to a DevTools connection. Send setup and the
    // assertion/capture through this same socket, then detach without side effects.
    foreach ($before as $index=>$setup) {
        $id=100+$index;
        $send(json_encode(['id'=>$id]+$setup));
        $loaded=false;
        do {
            $setupResult=json_decode($receive(),true);
            if (($setupResult['method']??null)==='Page.loadEventFired') $loaded=true;
        } while (($setupResult['id']??null)!==$id);
        if(isset($setupResult['error']))throw new RuntimeException('Browser setup failed.');
        // With Page.enable on this connection, wait for a new document before
        // evaluating assertions. A reload acknowledgment is not DOM readiness.
        if ($setup['method']==='Page.reload') {
            while (!$loaded) {
                $event=json_decode($receive(),true);
                $loaded=($event['method']??null)==='Page.loadEventFired';
            }
        }
    }
    $send(json_encode(['id' => 1] + ($command ?? ['method' => 'Runtime.evaluate',
        'params' => ['expression' => $expression, 'awaitPromise' => true, 'returnByValue' => true]])));
    $deadline = time() + $timeout;
    while (time() < $deadline) {
        $msg = json_decode($receive(), true);
        if (($msg['id'] ?? null) !== 1) continue;           // an event, not our answer
        fclose($sock);
        if (isset($msg['error'])) throw new RuntimeException('Browser command failed: '.($msg['error']['message'] ?? 'unknown error'));
        if ($command) return json_encode($msg['result'] ?? []);
        if (isset($msg['result']['exceptionDetails'])) {
            $exception = $msg['result']['exceptionDetails'];
            throw new RuntimeException('Browser exception: ' . ($exception['exception']['description'] ?? $exception['text']));
        }
        $value = $msg['result']['result']['value'] ?? null;
        if (!is_string($value)) throw new RuntimeException('Browser expression did not return a string.');
        return $value;
    }
    fclose($sock);
    throw new RuntimeException('Browser test timed out.');
}

function ticketWorkspaceBrowser(array $pages, array $options = []): int
{
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
    throw new RuntimeException('A supported browser is required.');
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freeitsm_embed_' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$server = null;
$browserProcess = null;
$exit = 1;
try {
    foreach ($pages as $name => $html) {
        $directory = dirname($tmp . '/' . $name);
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        file_put_contents($tmp . '/' . $name, $html);
    }
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
    $startUrl = $options['url'] ?? 'http://127.0.0.1:' . $port . '/index.html';
    $browserProcess = proc_open([$browser, '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--window-size=1440,1000',
        '--disable-background-networking', '--remote-debugging-port=' . $debugPort,
        '--user-data-dir=' . $tmp . '/profile', $startUrl],
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
            if (($p['type'] ?? '') === 'page' && ($p['url'] ?? '') === $startUrl) { $page = $p; break; }
        }
        if (!$page) usleep(100000);
    }
    if (!$page) throw new RuntimeException('Headless browser page unavailable.');
    $value = isset($options['verify']) ? json_encode($options['verify']($page['webSocketDebuggerUrl'])) : cdpEvaluate($page['webSocketDebuggerUrl'],
        "new Promise(resolve => { const poll = setInterval(() => { const r = document.getElementById('result'); if (r && r.textContent.startsWith('[')) { clearInterval(poll); resolve(r.textContent); } }, 100); })",
        25);
    file_put_contents($tmp . '/result.json', $value);
    $checks = json_decode(file_get_contents($tmp . '/result.json'), true);
    if (!is_array($checks) || !$checks) throw new RuntimeException('Browser did not finish its checks.');
    $failed = 0;
    foreach ($checks as $check) {
        echo ($check['pass'] ? '  PASS  ' : '  FAIL  ') . $check['label'] . ' ' . json_encode(($check['detail'] ?? null)) . "\n";
        if (!$check['pass']) $failed++;
    }
    echo (count($checks) - $failed) . " passed, $failed failed\n";
    $exit = $failed ? 1 : 0;
} catch (Throwable $e) {
    $message = str_replace([$tmp, getenv('USERPROFILE') ?: $tmp], ['[temporary directory]', '[user profile]'], $e->getMessage());
    fwrite(STDERR, $message . "\n");
} finally {
    foreach ([$browserProcess, $server] as $process) {
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
return $exit;

}
