<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * System emails with attachments, and emailed reports (5.3.0).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/system-email-attachments.php
 *
 * 1. ssSendSystemEmail()'s attachment rules: file names (no path, no CR/LF, no
 *    quotes), the type allow-list, the 3 MB / 10 file caps, empty and malformed files.
 * 2. The Graph sendMail body: no 'attachments' key without files (the shape sent
 *    before 5.3.0), fileAttachment parts with them.
 * 3. The raw MIME SMTP and Gmail send: multipart/mixed, the PDF part named and
 *    byte-for-byte the same after base64.
 * 4. ssSendSystemEmail() through the REAL SMTP client to a fake SMTP server this
 *    test starts on 127.0.0.1 (a ZZ Basic IMAP mailbox points at it) - with files,
 *    without (still plain text/html), and over the cap (refused, nothing sent, logged).
 * 5. Download links (includes/report_downloads.php): only the token's hash is stored;
 *    the owner downloads and the count goes up; another analyst, expired, revoked and
 *    unknown are refused; every answer is audited; nothing-ticked settings are refused;
 *    the tidy-up deletes expired files; with the table missing everything degrades.
 * 6. Over HTTP (FREEITSM_TEST_URL): api/system/email_report.php refuses a GET, a
 *    missing CSRF token, a non-PDF and an oversized one, sends ONLY to the signed-in
 *    analyst's own address (a 'to' in the request is ignored), and applies the
 *    delivery settings; api/system/report_download.php and the admin API.
 *
 * Nothing is really emailed: the only sending mailbox during the run is the ZZ one.
 * Other active mailboxes are switched off for the run and back on in finally. ZZ
 * rows, files, sessions and settings are put back in finally.
 *
 * @wiki Outbound-Email-Attachments-Developer-Guide
 */

// --- fake SMTP server mode: php system-email-attachments.php --fake-smtp <port> <outfile> <stopfile>
if (($argv[1] ?? '') === '--fake-smtp') {
    $srv = stream_socket_server('tcp://127.0.0.1:' . (int)$argv[2], $errno, $errstr);
    if (!$srv) { fwrite(STDERR, "listen failed: $errstr\n"); exit(1); }
    echo "ready\n"; fflush(STDOUT);
    $deadline = time() + 300;
    while (time() < $deadline && !is_file($argv[4])) {
        $c = @stream_socket_accept($srv, 1);
        if (!$c) continue;
        stream_set_timeout($c, 30);
        $log = ['rcpt' => [], 'data' => ''];
        fwrite($c, "220 fake ESMTP\r\n");
        $inData = false; $auth = 0;
        while (($line = fgets($c)) !== false) {
            if ($inData) {
                if ($line === ".\r\n") { $inData = false; fwrite($c, "250 queued\r\n"); continue; }
                if (substr($line, 0, 2) === '..') $line = substr($line, 1);
                $log['data'] .= $line;
                continue;
            }
            if ($auth > 0) { fwrite($c, $auth++ === 1 ? "334 UGFzc3dvcmQ6\r\n" : "235 ok\r\n"); if ($auth > 2) $auth = 0; continue; }
            $cmd = strtoupper(substr($line, 0, 4));
            if ($cmd === 'EHLO') fwrite($c, "250-fake\r\n250 AUTH LOGIN\r\n");
            elseif ($cmd === 'AUTH') { $auth = 1; fwrite($c, "334 VXNlcm5hbWU6\r\n"); }
            elseif ($cmd === 'RCPT') { preg_match('/<([^>]*)>/', $line, $m); $log['rcpt'][] = $m[1] ?? ''; fwrite($c, "250 OK\r\n"); }
            elseif ($cmd === 'DATA') { $inData = true; fwrite($c, "354 go\r\n"); }
            elseif ($cmd === 'QUIT') { file_put_contents($argv[3], json_encode($log) . "\n", FILE_APPEND | LOCK_EX); fwrite($c, "221 bye\r\n"); break; }
            else fwrite($c, "250 OK\r\n");
        }
        fclose($c);
    }
    exit(0);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/self_service_email.php';
require_once __DIR__ . '/../includes/mime_message.php';
require_once __DIR__ . '/../includes/report_downloads.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}
/** The InvalidArgumentException message from $fn, or '' when it did not throw. */
function refused(callable $fn): string
{
    try { $fn(); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
}
/** Every leaf of a raw MIME message as [headers (lower-case keys), decoded body]. */
function mimeLeaves(string $raw): array
{
    [$head, $body] = explode("\r\n\r\n", $raw, 2) + [1 => ''];
    $headers = [];
    foreach (preg_split('/\r\n(?![ \t])/', $head) as $h) {
        if (!str_contains($h, ':')) continue;
        [$k, $v] = explode(':', $h, 2);
        $headers[strtolower(trim($k))] = trim($v);
    }
    $ct = $headers['content-type'] ?? 'text/plain';
    if (stripos($ct, 'multipart/') === 0 && preg_match('/boundary="([^"]+)"/', $ct, $m)) {
        $out = [[['__multipart' => strtolower(strtok($ct, ';'))] + $headers, null]];
        $chunks = explode('--' . $m[1], $body);
        array_shift($chunks); array_pop($chunks);
        foreach ($chunks as $ch) $out = array_merge($out, mimeLeaves(substr(rtrim($ch, "\r\n"), 2)));
        return $out;
    }
    $dec = strtolower($headers['content-transfer-encoding'] ?? '') === 'base64' ? base64_decode($body) : $body;
    return [[$headers, $dec]];
}
/** The PDF leaf of a message, or null. */
function pdfLeaf(array $leaves): ?array
{
    foreach ($leaves as [$h, $b]) if (str_starts_with($h['content-type'] ?? '', 'application/pdf')) return [$h, $b];
    return null;
}
/** The HTML body of a message. */
function htmlLeaf(array $leaves): string
{
    foreach ($leaves as [$h, $b]) if (str_starts_with($h['content-type'] ?? '', 'text/html')) return (string)$b;
    return '';
}

$tag = 'zz' . bin2hex(random_bytes(3));
$pdf = "%PDF-1.4\n% $tag\n" . random_bytes(2000) . "\n%%EOF\n";
$sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir();
$made = ['mailbox' => 0, 'sessions' => [], 'paused' => [], 'settings' => null, 'renamed' => false];
$startLog = (int)$conn->query('SELECT COALESCE(MAX(id), 0) FROM system_logs')->fetchColumn();
$startSend = (int)$conn->query('SELECT COALESCE(MAX(id), 0) FROM email_send_log')->fetchColumn();
$startDl = reportDownloadsReady($conn) ? (int)$conn->query('SELECT COALESCE(MAX(id), 0) FROM report_downloads')->fetchColumn() : 0;
$proc = null; $stop = tempnam(sys_get_temp_dir(), 'stop'); @unlink($stop);
$out = tempnam(sys_get_temp_dir(), 'smtp');

try {
    echo "1. The attachment rules\n";
    check('a path is dropped from the name', ssAttachmentFilename('../../etc/passwd') === 'passwd' && ssAttachmentFilename('C:\\Users\\x\\report.pdf') === 'report.pdf');
    $evil = ssAttachmentFilename("a\"b\r\nBcc: x@y.z.pdf");
    check('CR, LF and quotes are removed', !preg_match('/[\r\n"]/', $evil) && str_ends_with($evil, '.pdf'), $evil);
    $long = ssAttachmentFilename(str_repeat('n', 400) . '.pdf');
    check('a long name is cut to 150 with its extension kept', mb_strlen($long) === 150 && str_ends_with($long, '.pdf'));
    check('an empty name becomes "attachment"', ssAttachmentFilename('') === 'attachment' && ssAttachmentFilename('dir/') === 'attachment');
    $p = ssSystemEmailAttachmentParts([['name' => 'r.pdf', 'mime' => 'Application/PDF', 'content' => $pdf],
                                       ['name' => 'x.exe', 'mime' => 'application/x-msdownload', 'content' => 'MZ']]);
    check('an allowed type is kept', $p[0]['contentType'] === 'application/pdf' && $p[0]['@odata.type'] === '#microsoft.graph.fileAttachment');
    check('any other type is sent as application/octet-stream', $p[1]['contentType'] === 'application/octet-stream');
    check('the content is base64 of the bytes', base64_decode($p[0]['contentBytes']) === $pdf);
    check('no attachments is no parts', ssSystemEmailAttachmentParts([]) === []);
    check('exactly the cap is accepted', refused(fn() => ssSystemEmailAttachmentParts([['name' => 'a', 'content' => str_repeat('x', SYSTEM_EMAIL_ATTACH_MAX_BYTES)]])) === '');
    check('one byte over the cap is refused, with the limit said', str_contains(refused(fn() => ssSystemEmailAttachmentParts([
        ['name' => 'a', 'content' => str_repeat('x', SYSTEM_EMAIL_ATTACH_MAX_BYTES - 10)], ['name' => 'b', 'content' => str_repeat('x', 11)]])), '3 MB'));
    check('more than ' . SYSTEM_EMAIL_ATTACH_MAX_FILES . ' files are refused', refused(fn() => ssSystemEmailAttachmentParts(array_fill(0, SYSTEM_EMAIL_ATTACH_MAX_FILES + 1, ['name' => 'a', 'content' => 'x']))) !== '');
    check('an empty file is refused', refused(fn() => ssSystemEmailAttachmentParts([['name' => 'a', 'content' => '']])) !== '');
    check('a file with no content is refused', refused(fn() => ssSystemEmailAttachmentParts([['name' => 'a']])) !== '');

    echo "2. The Microsoft Graph body\n";
    $g0 = ssSystemEmailGraphMessage('me@example.test', 'S', '<p>x</p>');
    check('without files it has no attachments key (the shape sent before 5.3.0)', !array_key_exists('attachments', $g0['message'])
        && $g0['saveToSentItems'] === true && $g0['message']['toRecipients'][0]['emailAddress']['address'] === 'me@example.test');
    $g1 = ssSystemEmailGraphMessage('me@example.test', 'S', '<p>x</p>', $p);
    check('with files they are fileAttachment parts', count($g1['message']['attachments']) === 2
        && $g1['message']['attachments'][0]['name'] === 'r.pdf' && base64_decode($g1['message']['attachments'][0]['contentBytes']) === $pdf);

    echo "3. The raw message SMTP and Gmail send\n";
    $raw = mimeBuildMessage(['from' => 'desk@example.test', 'to' => ['me@example.test'], 'subject' => 'S', 'html' => '<p>hello</p>', 'parts' => [$p[0]]]);
    $leaves = mimeLeaves($raw);
    check('it is multipart/mixed', ($leaves[0][0]['__multipart'] ?? '') === 'multipart/mixed');
    $leaf = pdfLeaf($leaves);
    check('the PDF part is an attachment named r.pdf', $leaf && str_contains($leaf[0]['content-disposition'] ?? '', 'attachment') && str_contains($leaf[0]['content-disposition'], 'filename="r.pdf"'));
    check('the PDF comes back byte for byte', $leaf && $leaf[1] === $pdf);
    check('the HTML is still there', htmlLeaf($leaves) === '<p>hello</p>');

    echo "4. ssSendSystemEmail() through the real SMTP client\n";
    $port = 43500 + random_int(0, 999);
    $proc = proc_open([PHP_BINARY, __FILE__, '--fake-smtp', (string)$port, $out, $stop], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check('the fake SMTP server started', trim((string)fgets($pipes[1])) === 'ready');
    $made['paused'] = $conn->query('SELECT id FROM target_mailboxes WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
    if ($made['paused']) $conn->exec('UPDATE target_mailboxes SET is_active = 0 WHERE id IN (' . implode(',', array_map('intval', $made['paused'])) . ')');
    $conn->prepare("INSERT INTO target_mailboxes (name, provider, is_active, azure_tenant_id, azure_client_id, azure_client_secret, oauth_redirect_uri,
                        imap_server, imap_username, imap_password, smtp_server, smtp_port, smtp_encryption, target_mailbox, save_sent_copy)
                    VALUES (?, 'imap', 1, '', '', '', '', '127.0.0.1', 'zz', 'zz', '127.0.0.1', ?, 'none', ?, 0)")
         ->execute(["ZZ $tag", $port, "desk.$tag@example.test"]);
    $made['mailbox'] = (int)$conn->lastInsertId();
    $seen = 0;
    $next = function () use ($out, &$seen): ?array {
        $lines = array_values(array_filter(explode("\n", (string)@file_get_contents($out))));
        if (count($lines) <= $seen) return null;
        return json_decode($lines[$seen++], true);
    };
    check('the ZZ mailbox is the one that sends', (int)(ssGetSendingMailbox($conn)['id'] ?? 0) === $made['mailbox']);

    $ok = ssSendSystemEmail($conn, "me.$tag@example.test", "ZZ $tag with file", '<p>see attached</p>', 'report_to_self',
                            [['name' => 'what-if.pdf', 'mime' => 'application/pdf', 'content' => $pdf]]);
    $cap = $next();
    $leaves = mimeLeaves((string)($cap['data'] ?? ''));
    $leaf = pdfLeaf($leaves);
    check('it sends', $ok && $cap);
    check('to the one recipient', ($cap['rcpt'] ?? []) === ["me.$tag@example.test"], json_encode($cap['rcpt'] ?? null));
    check('with the PDF attached, byte for byte', $leaf && $leaf[1] === $pdf && str_contains($leaf[0]['content-disposition'] ?? '', 'what-if.pdf'));
    $ok = ssSendSystemEmail($conn, "me.$tag@example.test", "ZZ $tag no file", '<p>plain</p>');
    $cap = $next();
    $leaves = mimeLeaves((string)($cap['data'] ?? ''));
    check('without attachments it is plain text/html, as every caller had before', $ok && count($leaves) === 1 && str_starts_with($leaves[0][0]['content-type'] ?? '', 'text/html'));
    $ok = ssSendSystemEmail($conn, "me.$tag@example.test", "ZZ $tag too big", '<p>x</p>', 'report_to_self',
                            [['name' => 'big.pdf', 'mime' => 'application/pdf', 'content' => str_repeat('x', SYSTEM_EMAIL_ATTACH_MAX_BYTES + 1)]]);
    usleep(300000);
    check('over the cap it is refused and nothing is sent', !$ok && $next() === null);
    $st = $conn->prepare("SELECT status, error_message FROM email_send_log WHERE id > ? AND subject = ?");
    $st->execute([$startSend, "ZZ $tag too big"]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    check('...and the send log says why', $row && $row['status'] === 'failed' && str_contains((string)$row['error_message'], 'too large'), json_encode($row));
    $st->execute([$startSend, "ZZ $tag with file"]);
    check('the send with a file is logged as sent', ($st->fetch(PDO::FETCH_ASSOC)['status'] ?? '') === 'sent');

    echo "5. Download links\n";
    if (!reportDownloadsReady($conn)) {
        check('report_downloads exists (run Database Verification)', false);
    } else {
        $made['settings'] = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN
            ('report_email_body','report_email_attach','report_email_link','report_link_days')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $conn->exec("DELETE FROM system_settings WHERE setting_key IN ('report_email_body','report_email_attach','report_email_link','report_link_days')");
        check('the defaults: body and attachment, no link, 7 days', reportEmailSettings($conn) === ['body' => true, 'attach' => true, 'link' => false, 'link_days' => 7]);
        check('saving with nothing ticked is refused', refused(fn() => reportEmailSaveSettings($conn, ['link_days' => 7])) !== '');
        check('days other than 1, 7 or 30 are refused', refused(fn() => reportEmailSaveSettings($conn, ['body' => 1, 'link_days' => 5])) !== '');
        check('a valid choice saves', reportEmailSaveSettings($conn, ['link' => 1, 'link_days' => 30]) === ['body' => false, 'attach' => false, 'link' => true, 'link_days' => 30]);

        [$owner, $other] = $conn->query("SELECT id FROM analysts WHERE is_active = 1 AND email LIKE '%@%.%' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $owner = (int)$owner; $other = (int)$other;
        $l = reportDownloadCreate($conn, $owner, 'what-if.pdf', 'application/pdf', $pdf, 7);
        $row = $conn->query('SELECT * FROM report_downloads WHERE id = ' . (int)$l['id'])->fetch(PDO::FETCH_ASSOC);
        check('only the hash of the token is stored', $row['token_hash'] === hash('sha256', $l['token']) && !in_array($l['token'], $row, true)
            && !str_contains(json_encode($row), $l['token']));
        check('the link carries the token', str_contains($l['url'], 'api/system/report_download.php?t=' . $l['token']));
        $file = reportDownloadsDir() . '/' . $row['storage_path'];
        check('the file is stored under a random name, not the given one', is_file($file) && file_get_contents($file) === $pdf && preg_match('/^[0-9a-f]{32}\.bin$/', $row['storage_path']));
        check('the folder is denied to the web', is_file(reportDownloadsDir() . '/.htaccess') || is_file(reportDownloadsDir() . '/web.config'));
        $audits = function () use ($conn, &$startLog): array {
            $r = $conn->query("SELECT analyst_id, details FROM system_logs WHERE log_type = 'report_download' AND id > $startLog ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            return array_map(fn($x) => ['who' => $x['analyst_id'] === null ? null : (int)$x['analyst_id']] + (json_decode($x['details'], true) ?: []), $r);
        };
        $last = function () use ($audits): array { $a = $audits(); return end($a) ?: []; };
        check('the create is audited', ($last()['action'] ?? '') === 'create' && $last()['who'] === $owner && ($last()['download_id'] ?? 0) === $l['id']);

        $r = reportDownloadAuthorise($conn, $l['token'], $owner);
        check('the owner may download it', $r['ok'] && $r['path'] && file_get_contents($r['path']) === $pdf);
        check('...and the count goes up', (int)$conn->query('SELECT download_count FROM report_downloads WHERE id = ' . $l['id'])->fetchColumn() === 1);
        check('...audited as a download with IP and browser', ($last()['action'] ?? '') === 'download' && array_key_exists('ip_address', $last()) && array_key_exists('user_agent', $last()));
        $r = reportDownloadAuthorise($conn, $l['token'], $other);
        check('another analyst is refused (403)', !$r['ok'] && $r['status'] === 403 && $r['outcome'] === 'wrong_analyst');
        check('...audited with who tried and whose it is', ($last()['outcome'] ?? '') === 'wrong_analyst' && $last()['who'] === $other && ($last()['owner_id'] ?? 0) === $owner);
        check('...and the count did not move', (int)$conn->query('SELECT download_count FROM report_downloads WHERE id = ' . $l['id'])->fetchColumn() === 1);
        $r = reportDownloadAuthorise($conn, str_repeat('ab', 32), $owner);
        check('an unknown token is refused (404), audited', !$r['ok'] && $r['status'] === 404 && ($last()['outcome'] ?? '') === 'unknown');
        $r = reportDownloadAuthorise($conn, 'nonsense', $owner);
        check('a malformed token is refused', !$r['ok'] && $r['outcome'] === 'unknown');

        $conn->exec('UPDATE report_downloads SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ' . $l['id']);
        $r = reportDownloadAuthorise($conn, $l['token'], $owner);
        check('an expired link is refused (410), audited', !$r['ok'] && $r['status'] === 410 && $r['outcome'] === 'expired' && ($last()['outcome'] ?? '') === 'expired');
        $t = reportDownloadsTidy($conn);
        check('the tidy-up deletes the expired file and keeps the row', $t['files'] >= 1 && !is_file($file)
            && $conn->query('SELECT file_deleted_at IS NOT NULL FROM report_downloads WHERE id = ' . $l['id'])->fetchColumn() == 1);

        $l2 = reportDownloadCreate($conn, $owner, 'second.pdf', 'application/pdf', $pdf, 1);
        $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
        check('a link can be revoked', reportDownloadRevoke($conn, $l2['id'], $admin) && ($last()['action'] ?? '') === 'revoke');
        check('...not twice', !reportDownloadRevoke($conn, $l2['id'], $admin));
        $r = reportDownloadAuthorise($conn, $l2['token'], $owner);
        check('a revoked link is refused (410), audited', !$r['ok'] && $r['status'] === 410 && $r['outcome'] === 'revoked' && ($last()['outcome'] ?? '') === 'revoked');
        check('...and its file is already gone', !is_file(reportDownloadsDir() . '/' . $conn->query('SELECT storage_path FROM report_downloads WHERE id = ' . $l2['id'])->fetchColumn()));
        check('the list shows them with the owner', count(array_filter(reportDownloadsList($conn), fn($x) => in_array((int)$x['id'], [$l['id'], $l2['id']], true) && $x['owner_name'] !== null)) === 2);

        echo "   Before Database Verification (the table renamed away)\n";
        $conn->exec('RENAME TABLE report_downloads TO zz_report_downloads_' . $tag);
        $made['renamed'] = true;
        $ready = reportDownloadsReady($conn);
        $r = reportDownloadAuthorise($conn, $l['token'], $owner);
        $okList = reportDownloadsList($conn) === [];
        $tidy = reportDownloadsTidy($conn);
        $threw = '';
        try { reportDownloadCreate($conn, $owner, 'x.pdf', 'application/pdf', $pdf, 7); } catch (Throwable $e) { $threw = $e->getMessage(); }
        check('ready says no', !$ready);
        check('a download is refused as not set up, not an error', !$r['ok'] && $r['outcome'] === 'not_ready');
        check('the list is empty and the tidy-up does nothing', $okList && $tidy === ['files' => 0, 'rows' => 0, 'orphans' => 0]);
        check('making a link says to run Database Verification', str_contains($threw, 'Database Verification'));
        if ($base === '') {
            $conn->exec('RENAME TABLE zz_report_downloads_' . $tag . ' TO report_downloads');
            $made['renamed'] = false;
        }
    }

    if ($base !== '' && reportDownloadsReady($conn) === false && $made['renamed']) {
        echo "6. Over HTTP\n";
        $sess = function (int $analystId, bool $admin) use (&$made, $sessDir): array {
            $sid = $made['sessions'][] = 'zzea' . bin2hex(random_bytes(6));
            $csrf = bin2hex(random_bytes(32));
            file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $analystId . ';analyst_name|s:5:"zztst";is_admin|i:' . ($admin ? 1 : 0) . ';csrf_token|s:64:"' . $csrf . '";');
            return [$sid, $csrf];
        };
        $http = function (string $path, ?array $s, $post = null, bool $withCsrf = true) use ($base): array {
            $ch = curl_init("$base/$path");
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true];
            if ($s) $o[CURLOPT_COOKIE] = 'PHPSESSID=' . $s[0];
            if ($post !== null) {
                $h = ['Content-Type: application/json'];
                if ($withCsrf && $s) $h[] = 'X-CSRF-Token: ' . $s[1];
                $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => is_string($post) ? $post : json_encode($post), CURLOPT_HTTPHEADER => $h];
            }
            curl_setopt_array($ch, $o);
            $resp = (string)curl_exec($ch);
            $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $head = substr($resp, 0, $hs); $body = substr($resp, $hs);
            return ['code' => $code, 'head' => $head, 'body' => $body, 'json' => json_decode($body, true) ?: []];
        };
        $me = $conn->prepare('SELECT email FROM analysts WHERE id = ?');
        $me->execute([$owner]);
        $myEmail = (string)$me->fetchColumn();
        $so = $sess($owner, false);
        $req = ['subject' => "ZZ $tag report", 'html' => '<h2>What if</h2><table><tr><td>3 people</td></tr></table><script>alert(1)</script>',
                'filename' => '../what-if "x".pdf', 'pdf_base64' => base64_encode($pdf), 'to' => "attacker.$tag@example.test"];

        $r = $http('api/system/email_report.php', $so);
        check('a GET is refused (405)', $r['code'] === 405);
        $r = $http('api/system/email_report.php', $so, $req, false);
        check('without the CSRF token it is refused (403)', $r['code'] === 403 && str_starts_with((string)($r['json']['code'] ?? ''), 'csrf'), $r['code'] . ' ' . substr($r['body'], 0, 200));
        check('...and nothing was sent', $next() === null);
        $r = $http('api/system/email_report.php', $so, ['pdf_base64' => base64_encode('<html>not a pdf</html>')] + $req);
        check('a non-PDF is refused', ($r['json']['code'] ?? '') === 'not_pdf', $r['body']);
        $r = $http('api/system/email_report.php', $so, ['pdf_base64' => base64_encode('%PDF-' . str_repeat('x', SYSTEM_EMAIL_ATTACH_MAX_BYTES))] + $req);
        check('one over the cap is refused', ($r['json']['code'] ?? '') === 'too_large', substr($r['body'], 0, 200));
        check('...and nothing was sent', $next() === null);

        // Link is the only way ticked, and the table is missing: the PDF is attached instead.
        $r = $http('api/system/email_report.php', $so, $req);
        $cap = $next();
        $leaves = mimeLeaves((string)($cap['data'] ?? ''));
        check('before Verification a link-only setting still delivers - as an attachment', !empty($r['json']['success'])
            && ($r['json']['delivered'] ?? []) === ['body' => false, 'attachment' => true, 'link' => false] && pdfLeaf($leaves), $r['body']);
        check('it went ONLY to the analyst\'s own address (the "to" was ignored)', ($cap['rcpt'] ?? []) === [$myEmail] && ($r['json']['sent_to'] ?? '') === $myEmail, json_encode($cap['rcpt'] ?? null));
        $sa = $sess($admin, true);
        $r = $http('api/system/emailed_reports.php', $sa);
        check('the admin page\'s API answers before Verification too', !empty($r['json']['success']) && $r['json']['links_ready'] === false && $r['json']['links'] === []);
        $conn->exec('RENAME TABLE zz_report_downloads_' . $tag . ' TO report_downloads');
        $made['renamed'] = false;

        reportEmailSaveSettings($conn, ['body' => 1, 'attach' => 1, 'link' => 1, 'link_days' => 7]);
        $r = $http('api/system/email_report.php', $so, $req);
        $cap = $next();
        $leaves = mimeLeaves((string)($cap['data'] ?? ''));
        $html = htmlLeaf($leaves);
        $leaf = pdfLeaf($leaves);
        check('all three ticked: it sends all three', !empty($r['json']['success']) && ($r['json']['delivered'] ?? []) === ['body' => true, 'attachment' => true, 'link' => true], $r['body']);
        check('the body holds the report, cleaned (no script)', str_contains($html, 'What if') && str_contains($html, '3 people') && !str_contains($html, '<script'));
        check('the attachment is the PDF, under a safe name', $leaf && $leaf[1] === $pdf && !preg_match('#[/"]#', preg_replace('/^.*filename="([^"]*)".*$/', '$1', $leaf[0]['content-disposition'] ?? '/')), $leaf[0]['content-disposition'] ?? '');
        preg_match('/report_download\.php\?t=([0-9a-f]{64})/', $html, $m);
        $token = $m[1] ?? '';
        check('the body has the download link', $token !== '');

        $r = $http('api/system/report_download.php?t=' . $token, $so);
        check('the owner downloads the PDF', $r['code'] === 200 && $r['body'] === $pdf, $r['code'] . ' ' . substr($r['body'], 0, 100));
        check('...as an attachment, nosniff, not cached', stripos($r['head'], 'Content-Disposition: attachment') !== false
            && stripos($r['head'], 'X-Content-Type-Options: nosniff') !== false && stripos($r['head'], 'no-store') !== false);
        $st = $conn->prepare('SELECT download_count FROM report_downloads WHERE token_hash = ?');
        $st->execute([hash('sha256', $token)]);
        check('...and the count is 1', (int)$st->fetchColumn() === 1);
        $r = $http('api/system/report_download.php?t=' . $token, $sess($other, false));
        check('another analyst gets 403 and no file', $r['code'] === 403 && !str_contains($r['body'], '%PDF'));
        $r = $http('api/system/report_download.php?t=' . $token, null);
        check('signed out: sent to sign in, the link remembered', in_array($r['code'], [302, 303], true) && stripos($r['head'], 'auth/login.php') !== false
            && stripos($r['head'], 'freeitsm_report_return=' . $token) !== false);
        $r = $http('api/system/report_download.php?t=' . str_repeat('cd', 32), $so);
        check('an unknown link is a 404 page', $r['code'] === 404 && str_contains($r['body'], '<h1'));
        $conn->prepare('UPDATE report_downloads SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        $r = $http('api/system/report_download.php?t=' . $token, $so);
        check('an expired link is a 410 page', $r['code'] === 410 && !str_contains($r['body'], '%PDF'));

        $st = $conn->prepare("SELECT details FROM system_logs WHERE log_type = 'report_download' AND id > ? ORDER BY id");
        $st->execute([$startLog]);
        $outcomes = array_map(fn($d) => (json_decode($d, true)['outcome'] ?? '') . '/' . (json_decode($d, true)['action'] ?? ''), $st->fetchAll(PDO::FETCH_COLUMN));
        check('every HTTP answer was audited', count(array_intersect(['ok/create', 'ok/download', 'wrong_analyst/refused', 'unknown/refused', 'expired/refused'], $outcomes)) === 5, implode(',', $outcomes));

        $so2 = $sess($owner, false);
        $r = $http('api/system/emailed_reports.php', $so2);
        check('the admin API refuses a non-admin', empty($r['json']['success']) && !isset($r['json']['links']), $r['body']);
        $r = $http('api/system/emailed_reports.php', $sa, ['action' => 'save', 'body' => false, 'attach' => false, 'link' => false, 'link_days' => 7]);
        check('the admin API refuses nothing ticked', empty($r['json']['success']) && str_contains((string)($r['json']['error'] ?? ''), 'at least one'), $r['body']);
        $r = $http('api/system/emailed_reports.php', $sa, ['action' => 'save', 'body' => true, 'attach' => false, 'link' => true, 'link_days' => 1]);
        check('...and saves a valid choice', !empty($r['json']['success']) && $r['json']['settings'] === ['body' => true, 'attach' => false, 'link' => true, 'link_days' => 1], $r['body']);
        $r = $http('api/system/emailed_reports.php', $sa);
        $mine = array_values(array_filter($r['json']['links'] ?? [], fn($x) => (int)$x['id'] > $startDl && (int)$x['analyst_id'] === $owner && $x['revoked_at'] === null));
        check('the list shows the links', count($mine) >= 1, $r['body']);
        $r = $http('api/system/emailed_reports.php', $sa, ['action' => 'revoke', 'id' => (int)($mine[0]['id'] ?? 0)]);
        check('an admin revokes one', !empty($r['json']['success']), $r['body']);

        $sx = $sess(1, true);
        $em = (string)$conn->query('SELECT email FROM analysts WHERE id = 1')->fetchColumn();
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $r = $http('api/system/email_report.php', $sx, $req);
            check('an analyst with no valid email address is told so', ($r['json']['code'] ?? '') === 'no_email', $r['body']);
        }
        $conn->exec('UPDATE target_mailboxes SET is_active = 0 WHERE id = ' . $made['mailbox']);
        $r = $http('api/system/email_report.php', $so, $req);
        check('with no mailbox able to send it says so', ($r['json']['code'] ?? '') === 'no_mailbox', $r['body']);
        $st = $conn->prepare('SELECT revoked_at IS NOT NULL AND revoked_by = analyst_id FROM report_downloads WHERE id > ? AND analyst_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$startDl, $owner]);
        check('...and the link made for that failed email was revoked', (int)$st->fetchColumn() === 1);
    } elseif ($base === '') {
        echo "6. Over HTTP - skipped (set FREEITSM_TEST_URL)\n";
    }
} catch (Throwable $x) {
    check('no exception', false, get_class($x) . ': ' . $x->getMessage() . ' @ ' . basename($x->getFile()) . ':' . $x->getLine());
} finally {
    if ($made['renamed']) $conn->exec('RENAME TABLE zz_report_downloads_' . $tag . ' TO report_downloads');
    @touch($stop);
    if ($proc) { usleep(1500000); proc_terminate($proc); proc_close($proc); }
    @unlink($stop); @unlink($out);
    foreach ($made['sessions'] as $sid) @unlink("$sessDir/sess_$sid");
    if ($made['mailbox']) $conn->exec('DELETE FROM target_mailboxes WHERE id = ' . $made['mailbox']);
    if ($made['paused']) $conn->exec('UPDATE target_mailboxes SET is_active = 1 WHERE id IN (' . implode(',', array_map('intval', $made['paused'])) . ')');
    if (is_array($made['settings'])) {
        $conn->exec("DELETE FROM system_settings WHERE setting_key IN ('report_email_body','report_email_attach','report_email_link','report_link_days')");
        $ins = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($made['settings'] as $k => $v) $ins->execute([$k, $v]);
    }
    if (reportDownloadsReady($conn)) {
        foreach ($conn->query("SELECT storage_path FROM report_downloads WHERE id > $startDl")->fetchAll(PDO::FETCH_COLUMN) as $f) @unlink(reportDownloadsDir() . '/' . basename($f));
        $conn->exec("DELETE FROM report_downloads WHERE id > $startDl");
    }
    $conn->exec("DELETE FROM system_logs WHERE log_type = 'report_download' AND id > $startLog");
    $conn->exec("DELETE FROM email_send_log WHERE id > $startSend AND (subject LIKE 'ZZ $tag%' OR to_address LIKE '%$tag%')");
    $left = (int)$conn->query("SELECT COUNT(*) FROM target_mailboxes WHERE name = 'ZZ $tag'")->fetchColumn()
          + (reportDownloadsReady($conn) ? (int)$conn->query("SELECT COUNT(*) FROM report_downloads WHERE id > $startDl")->fetchColumn() : 1);
    check('cleaned up', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
