<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Basic IMAP mailboxes: save a copy of sent mail to the Sent folder (GH #270).
 *
 *   php tests/mailbox-sent-copy.php
 *
 * No database, no Docker, no real mailbox: the test starts its own tiny SMTP and IMAP
 * servers on 127.0.0.1 (this same file, run as a child process with --fake-smtp /
 * --fake-imap), sends through the real imapSmtpSend(), and checks what the fake IMAP
 * server was asked to APPEND. tests/imap-client-live.php does the same against real
 * Dovecot and GreenMail servers when they are running in Docker.
 *
 * @wiki Basic-IMAP-Sent-Copy-Developer-Guide
 */

// --- child: a fake SMTP server that takes one email and writes what it received -------
if (($argv[1] ?? '') === '--fake-smtp') {
    [$port, $ready, $out] = [(int)$argv[2], $argv[3], $argv[4]];
    $srv = stream_socket_server('tcp://127.0.0.1:' . $port, $e1, $e2);
    if (!$srv) exit(1);
    file_put_contents($ready, 'ready');
    $c = @stream_socket_accept($srv, 20);
    if (!$c) exit(1);
    $say = fn(string $l) => fwrite($c, $l . "\r\n");
    $say('220 fake ESMTP');
    $data = null;
    $auth = 0;   // AUTH LOGIN: 1 = the username is next, 2 = the password is next
    while (($line = fgets($c)) !== false) {
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($auth === 1) { $auth = 2; $say('334 UGFzc3dvcmQ6'); continue; }
        if ($auth === 2) { $auth = 0; $say('235 ok'); continue; }
        if ($cmd === 'EHLO') $say('250 fake');
        elseif ($cmd === 'AUTH') { $auth = 1; $say('334 VXNlcm5hbWU6'); }
        elseif ($cmd === 'MAIL' || $cmd === 'RCPT') $say('250 ok');
        elseif ($cmd === 'DATA') {
            $say('354 go');
            $data = '';
            while (($l = fgets($c)) !== false && $l !== ".\r\n") $data .= $l;
            $say('250 queued');
        } elseif ($cmd === 'QUIT') { $say('221 bye'); break; }
        else $say('500 unknown');
    }
    file_put_contents($out, (string)$data);
    exit(0);
}

// --- child: a fake IMAP server that takes one connection and records any APPEND -------
if (($argv[1] ?? '') === '--fake-imap') {
    [$port, $ready, $out, $mode] = [(int)$argv[2], $argv[3], $argv[4], $argv[5]];
    $lists = [
        // SPECIAL-USE: the Sent folder is called "Sent Items" and marked \Sent.
        'special' => ['(\\HasNoChildren) "/" "INBOX"', '(\\HasNoChildren \\Sent) "/" "Sent Items"', '(\\HasNoChildren) "/" "Archive"'],
        // No SPECIAL-USE flags, but a folder with a common name.
        'named'   => ['(\\HasNoChildren) "." "INBOX"', '(\\HasNoChildren) "." "INBOX.Sent"', '(\\HasNoChildren) "." "Sent"'],
        // Nothing that looks like Sent.
        'none'    => ['(\\HasNoChildren) "/" "INBOX"', '(\\HasNoChildren) "/" "Archive"'],
    ][$mode];
    $names = array_map(fn($l) => trim(substr($l, strrpos($l, ' "') + 1), '"'), $lists);
    $srv = stream_socket_server('tcp://127.0.0.1:' . $port, $e1, $e2);
    if (!$srv) exit(1);
    file_put_contents($ready, 'ready');
    $c = @stream_socket_accept($srv, 20);
    if (!$c) exit(1);
    $say = fn(string $l) => fwrite($c, $l . "\r\n");
    $say('* OK fake IMAP ready');
    $rec = ['appends' => []];
    while (($line = fgets($c)) !== false) {
        [$tag, $cmd] = array_pad(explode(' ', rtrim($line, "\r\n"), 3), 2, '');
        $cmd = strtoupper($cmd);
        if ($cmd === 'CAPABILITY') { $say('* CAPABILITY IMAP4rev1 SPECIAL-USE'); $say("$tag OK done"); }
        elseif ($cmd === 'LOGIN') $say("$tag OK signed in");
        elseif ($cmd === 'LIST') { foreach ($lists as $l) $say("* LIST $l"); $say("$tag OK listed"); }
        elseif ($cmd === 'APPEND' && preg_match('/^\S+ APPEND "((?:[^"\\\\]|\\\\.)*)" \(([^)]*)\) \{(\d+)\}\r\n$/', $line, $m)) {
            $say('+ go ahead');
            $n = (int)$m[3]; $body = '';
            while (strlen($body) < $n && ($chunk = fread($c, $n - strlen($body))) !== false && $chunk !== '') $body .= $chunk;
            fgets($c);   // the CRLF that ends the command
            $folder = stripslashes($m[1]);
            $rec['appends'][] = ['folder' => $folder, 'flags' => $m[2], 'message' => $body];
            in_array($folder, $names, true) ? $say("$tag OK appended") : $say("$tag NO [TRYCREATE] Mailbox doesn't exist: $folder");
        }
        elseif ($cmd === 'LOGOUT') { $say('* BYE'); $say("$tag OK bye"); break; }
        else $say("$tag BAD unexpected: $cmd");
    }
    file_put_contents($out, json_encode($rec));
    exit(0);
}

require_once __DIR__ . '/../includes/mailbox_imap.php';
require_once __DIR__ . '/../includes/mailbox_send.php';
require_once __DIR__ . '/../includes/email_log.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, $detail = null): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== null ? "\n        " . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n"; }
}

/** A free port on 127.0.0.1. */
function freePort(): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $p = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $p;
}

/** Starts a fake server child and waits until it is listening. @return array{0:resource,1:int,2:string} */
function fake(string $kind, string $mode = ''): array {
    $port = freePort();
    $ready = tempnam(sys_get_temp_dir(), 'rdy'); @unlink($ready);
    $out = tempnam(sys_get_temp_dir(), 'out'); @unlink($out);
    $args = [PHP_BINARY, __FILE__, '--fake-' . $kind, (string)$port, $ready, $out];
    if ($mode !== '') $args[] = $mode;
    $proc = proc_open($args, [], $pipes);
    for ($i = 0; $i < 50 && !file_exists($ready); $i++) usleep(100000);
    @unlink($ready);
    return [$proc, $port, $out];
}

/**
 * One send through the real imapSmtpSend(), with fake servers. $imapMode null = no IMAP server.
 * @return array{error:string, smtp:string, imap:array, outcome:?array}
 */
function sendOnce(array $mbExtra, ?string $imapMode, bool $saveCopy = true): array {
    [$sp, $sport, $sout] = fake('smtp');
    $ip = null; $iout = null; $iport = freePort();
    if ($imapMode !== null) [$ip, $iport, $iout] = fake('imap', $imapMode);
    $mb = array_merge([
        'id' => 0, 'name' => 'Help', 'provider' => 'imap', 'target_mailbox' => 'help@x.test',
        'smtp_server' => '127.0.0.1', 'smtp_port' => $sport, 'smtp_encryption' => 'none',
        'imap_server' => '127.0.0.1', 'imap_port' => $iport, 'imap_encryption' => 'none',
        'imap_username' => 'help@x.test', 'imap_password' => 'pw',
    ], $mbExtra);
    $err = '';
    try {
        imapSmtpSend($mb, 'to@x.test', 'cc@x.test', 'Re: printer [SDREF:1]', "<p>Hello</p>\n<p>.a line that starts with a dot</p>", [], $saveCopy);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    proc_close($sp);
    if ($ip) proc_close($ip);
    $smtp = (string)@file_get_contents($sout); @unlink($sout);
    $imap = $iout ? (json_decode((string)@file_get_contents($iout), true) ?: ['appends' => []]) : ['appends' => []];
    if ($iout) @unlink($iout);
    return ['error' => $err, 'smtp' => $smtp, 'imap' => $imap, 'outcome' => imapSentCopyOutcome()];
}
$unstuff = fn(string $s) => rtrim(preg_replace('/^\.\./m', '.', $s), "\r\n");

echo "== 1. Off (the default): nothing is saved\n";
$r = sendOnce([], null);
ok('the email is sent', $r['error'] === '' && $r['smtp'] !== '', $r['error']);
ok('...and no copy is attempted', $r['outcome'] === null, $r['outcome']);

echo "\n== 2. On, folder blank: the folder the server marks \\Sent\n";
$r = sendOnce(['save_sent_copy' => 1], 'special');
$a = $r['imap']['appends'][0] ?? null;
ok('the email is sent', $r['error'] === '', $r['error']);
ok('one copy is appended', count($r['imap']['appends']) === 1, $r['imap']);
ok('...to "Sent Items", which the server marks \\Sent', ($a['folder'] ?? '') === 'Sent Items', $a['folder'] ?? null);
ok('...marked as read', ($a['flags'] ?? '') === '\\Seen', $a['flags'] ?? null);
ok('...byte for byte the email that went out (without SMTP\'s dot-stuffing)', $a && rtrim($a['message'], "\r\n") === $unstuff($r['smtp']));
ok('...with its To, Cc and Subject', $a && str_contains($a['message'], 'To: to@x.test') && str_contains($a['message'], 'Cc: cc@x.test') && str_contains($a['message'], 'Subject: Re: printer [SDREF:1]'));
ok('the outcome names the folder (the Test button shows it)', ($r['outcome']['folder'] ?? '') === 'Sent Items' && $r['outcome']['error'] === null, $r['outcome']);

echo "\n== 3. Internal notifications are never filed\n";
$r = sendOnce(['save_sent_copy' => 1], null, false);
ok('saveCopy false: sent, and no copy attempted even with the toggle on', $r['error'] === '' && $r['outcome'] === null, $r['outcome']);
$src = file_get_contents(__DIR__ . '/../includes/template_email.php');
ok('the internal-notification send passes false', str_contains($src, "imapSmtpSend(\$mailbox, \$to, '', \$subject, \$bodyHtml, [], false)"));

echo "\n== 4. A named folder, matched to the server's own spelling\n";
$r = sendOnce(['save_sent_copy' => 1, 'sent_folder' => 'inbox/sent'], 'named');
ok('"inbox/sent" is saved to "INBOX.Sent"', ($r['imap']['appends'][0]['folder'] ?? '') === 'INBOX.Sent', $r['imap']);
$r = sendOnce(['save_sent_copy' => 1], 'named');
ok('blank, with no \\Sent flag: the common name "Sent" is used', ($r['imap']['appends'][0]['folder'] ?? '') === 'Sent', $r['imap']);

echo "\n== 5. When the copy fails, the email still counts as sent\n";
$r = sendOnce(['save_sent_copy' => 1], 'none');
ok('no Sent folder on the server: the send does not fail', $r['error'] === '', $r['error']);
ok('...nothing appended, and the reason says to name the folder', !$r['imap']['appends'] && str_contains((string)($r['outcome']['error'] ?? ''), 'marks no folder as Sent'), $r['outcome']);
$r = sendOnce(['save_sent_copy' => 1, 'sent_folder' => 'Gone'], 'special');
ok('a folder that does not exist: the send does not fail', $r['error'] === '', $r['error']);
ok('...and the reason is the server\'s own words, naming the folder', str_contains((string)($r['outcome']['error'] ?? ''), 'to "Gone"') && str_contains((string)($r['outcome']['error'] ?? ''), 'TRYCREATE'), $r['outcome']);
$r = sendOnce(['save_sent_copy' => 1, 'imap_port' => freePort()], null);
ok('the IMAP server is down: the send does not fail, the reason says so', $r['error'] === '' && str_contains((string)($r['outcome']['error'] ?? ''), 'IMAP connection'), $r['outcome']);
ok('failures have their own route in the outbound email log', isset(EMAIL_LOG_ROUTES['sent_copy']));

echo "\n== 6. A failed SEND is never filed as sent\n";
[$ip, $iport, $iout] = fake('imap', 'special');
$err = '';
try {
    imapSmtpSend(['smtp_server' => '127.0.0.1', 'smtp_port' => freePort(), 'smtp_encryption' => 'none', 'imap_server' => '127.0.0.1', 'imap_port' => $iport,
                  'imap_encryption' => 'none', 'imap_username' => 'u', 'imap_password' => 'p', 'target_mailbox' => 'help@x.test', 'save_sent_copy' => 1], 'to@x.test', '', 'S', '<p>b</p>');
} catch (Throwable $e) { $err = $e->getMessage(); }
// The fake IMAP server is still waiting for a connection that never comes: knock once so it exits.
$k = @stream_socket_client('tcp://127.0.0.1:' . $iport, $e1, $e2, 2); if ($k) { fwrite($k, "a LOGOUT\r\n"); usleep(200000); fclose($k); }
proc_close($ip);
$rec = json_decode((string)@file_get_contents($iout), true) ?: ['appends' => []]; @unlink($iout);
ok('SMTP refused: the send fails, and nothing is appended', $err !== '' && !$rec['appends'] && imapSentCopyOutcome() === null, [$err, $rec]);

echo "\n== 7. The Test button (mailboxTestResolve)\n";
$stored = ['provider' => 'imap', 'smtp_server' => 'smtp.x.test', 'imap_server' => 'imap.x.test', 'imap_username' => 'help@x.test', 'imap_password' => 'saved', 'smtp_username' => '', 'save_sent_copy' => 0];
$form = ['provider' => 'imap', 'smtp_server' => 'smtp.x.test', 'imap_server' => 'imap.x.test', 'imap_username' => 'help@x.test', 'imap_password' => '********', 'smtp_same_as_imap' => true, 'save_sent_copy' => true, 'sent_folder' => ' Sent '];
[$mb] = mailboxTestResolve($stored, $form);
ok('tests the form\'s choice: on, with the folder as typed', $mb && (int)$mb['save_sent_copy'] === 1 && $mb['sent_folder'] === 'Sent', $mb);
[$mb] = mailboxTestResolve($stored, array_merge($form, ['imap_server' => 'evil.example']));
ok('IMAP server changed and no password typed: the saved password is NOT sent to it - the copy is not tried', $mb && (int)$mb['save_sent_copy'] === 0 && str_contains((string)($mb['sent_copy_not_tried'] ?? ''), 'type the IMAP password'), $mb);
[$mb] = mailboxTestResolve($stored, array_merge($form, ['imap_server' => 'evil.example', 'imap_password' => 'typed']));
ok('...with the password typed, it is tried', $mb && (int)$mb['save_sent_copy'] === 1);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
