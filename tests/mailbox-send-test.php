<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The mailbox Test button, and port/encryption mismatches (4.11.0).
 *
 *   php tests/mailbox-send-test.php
 *
 * 1. mailPortMismatch() - which ports expect which encryption, and what it says.
 * 2. The mailbox "!" warnings for a mismatch (mailboxHealthProblems()).
 * 3. mailboxTestResolve() - which settings and which PASSWORD the Test button uses.
 *    The security rule: the stored password is never sent to a server or username
 *    it was not saved for, or anybody with mailbox settings could point Test at a
 *    server of their own and collect it.
 * 4. imapSmtpSend() against a local server that hangs up without a greeting: the
 *    error names the step instead of 'expected 220, got ""'.
 *
 * No database, no network beyond 127.0.0.1, sends no email.
 */

// --- a server that accepts one connection and hangs up without a word ---
if (($argv[1] ?? '') === '--silent-server') {
    $srv = stream_socket_server('tcp://127.0.0.1:' . (int)$argv[2], $errno, $errstr);
    if (!$srv) exit(1);
    file_put_contents($argv[3], 'ready');
    $c = @stream_socket_accept($srv, 20);
    if ($c) { usleep(200000); fclose($c); }
    exit(0);
}

require_once __DIR__ . '/../includes/mail_ports.php';
require_once __DIR__ . '/../includes/mailbox_health.php';
require_once __DIR__ . '/../includes/mailbox_send.php';
require_once __DIR__ . '/../includes/mailbox_imap.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, $detail = null): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== null ? "\n        " . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n"; }
}

echo "== 1. Ports\n";
ok('SMTP 465 + STARTTLS: mismatch, and says to use SSL', str_contains((string)mailPortMismatch('smtp', 465, 'tls'), 'Port 465 expects SSL, not STARTTLS - set SMTP encryption to SSL'), mailPortMismatch('smtp', 465, 'tls'));
ok('SMTP 465 + SSL: fine', mailPortMismatch('smtp', 465, 'ssl') === null);
ok('SMTP 465 + none: mismatch (can never work)', mailPortMismatch('smtp', 465, 'none') !== null);
ok('SMTP 587 + SSL: mismatch, says STARTTLS', str_contains((string)mailPortMismatch('smtp', 587, 'ssl'), 'expects STARTTLS'));
ok('SMTP 587 + STARTTLS: fine', mailPortMismatch('smtp', 587, 'tls') === null);
ok('SMTP 587 + none: not flagged (a real, if unwise, choice)', mailPortMismatch('smtp', 587, 'none') === null);
ok('SMTP 25 and 2525: not judged - their encryption is not fixed by convention', mailPortMismatch('smtp', 25, 'ssl') === null && mailPortMismatch('smtp', 2525, 'tls') === null);
ok('IMAP 993 + STARTTLS: mismatch', str_contains((string)mailPortMismatch('imap', 993, 'tls'), 'set IMAP encryption to SSL'));
ok('IMAP 143 + SSL: mismatch', mailPortMismatch('imap', 143, 'ssl') !== null);
ok('IMAP 993 + SSL, 143 + STARTTLS: fine', mailPortMismatch('imap', 993, 'ssl') === null && mailPortMismatch('imap', 143, 'tls') === null);

echo "\n== 2. The mailbox \"!\" warnings\n";
$keys = fn (array $mb) => array_column(mailboxHealthProblems($mb + ['default_origin_id' => 1], ['origin_names' => [1 => 'Email']]), 'key');
$zoho = ['provider' => 'imap', 'smtp_server' => 'smtp.zoho.eu', 'smtp_port' => 465, 'smtp_encryption' => 'tls', 'imap_port' => 993, 'imap_encryption' => 'ssl'];
ok('the Zoho case - 465 with STARTTLS - is flagged', in_array('smtp_port_encryption', $keys($zoho), true), $keys($zoho));
ok('...and it is a dismissible warning, not an error', (fn ($p) => $p && $p[0]['severity'] === 'warning' && $p[0]['dismissible'])(array_values(array_filter(mailboxHealthProblems($zoho + ['default_origin_id' => 1], ['origin_names' => [1 => 'Email']]), fn ($p) => $p['key'] === 'smtp_port_encryption'))));
ok('fixed (465 with SSL): no warning', !in_array('smtp_port_encryption', $keys(['smtp_encryption' => 'ssl'] + $zoho), true));
ok('IMAP 993 with STARTTLS is flagged separately', in_array('imap_port_encryption', $keys(['imap_encryption' => 'tls'] + $zoho), true));
ok('no SMTP server: no port warning (the "no SMTP server" one covers it)', !in_array('smtp_port_encryption', $keys(['smtp_server' => ''] + $zoho), true));
ok('a Microsoft mailbox is never judged on these columns', !array_intersect(['smtp_port_encryption', 'imap_port_encryption'], $keys(['provider' => 'microsoft'] + $zoho)));
ok('a dismissed one stays listed, marked dismissed', (fn ($p) => $p && $p[0]['dismissed'] === true)(array_values(array_filter(mailboxHealthProblems($zoho + ['default_origin_id' => 1, 'health_dismissed' => '["smtp_port_encryption"]'], ['origin_names' => [1 => 'Email']]), fn ($p) => $p['key'] === 'smtp_port_encryption'))));

echo "\n== 3. Which settings and password the Test button uses\n";
$stored = ['id' => 4, 'provider' => 'imap', 'name' => 'Zoho', 'target_mailbox' => 'help@zoho.test',
    'imap_server' => 'imap.zoho.eu', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_username' => 'help@zoho.test', 'imap_password' => 'STORED-SECRET',
    'smtp_server' => 'smtp.zoho.eu', 'smtp_port' => 465, 'smtp_encryption' => 'tls', 'smtp_username' => '', 'smtp_password' => ''];
$form = ['provider' => 'imap', 'imap_username' => 'help@zoho.test', 'imap_password' => '', 'smtp_server' => 'smtp.zoho.eu',
    'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'smtp_same_as_imap' => true];

[$mb, $err, $saved] = mailboxTestResolve($stored, $form);
ok('unsaved change (STARTTLS -> SSL) is what gets tested', $err === null && $mb['smtp_encryption'] === 'ssl' && $saved === false, [$err, $mb['smtp_encryption'] ?? null]);
ok('same server, same username, blank password: the stored one is used', ($mb['imap_password'] ?? null) === 'STORED-SECRET');
[$mb, $err] = mailboxTestResolve($stored, ['imap_password' => '********'] + $form);
ok('a masked "********" counts as blank, not as the password', ($mb['imap_password'] ?? null) === 'STORED-SECRET', $mb['imap_password'] ?? $err);

[$mb, $err] = mailboxTestResolve($stored, ['smtp_server' => 'smtp.attacker.example'] + $form);
ok('SECURITY: changed server + blank password - refused, stored password NOT used', $mb === null && str_contains((string)$err, 'Type the password'), $err);
[$mb, $err] = mailboxTestResolve($stored, ['smtp_server' => '  SMTP.ZOHO.EU '] + $form);
ok('...the same server in other capitals or with spaces is still the same server', $err === null && ($mb['imap_password'] ?? '') === 'STORED-SECRET', $err);
[$mb, $err] = mailboxTestResolve($stored, ['imap_username' => 'someone@else.test'] + $form);
ok('SECURITY: changed username + blank password - refused', $mb === null, $err);
[$mb, $err] = mailboxTestResolve($stored, ['smtp_server' => 'smtp.new.example', 'imap_password' => 'typed-now'] + $form);
ok('changed server WITH a typed password - allowed, uses what was typed', $err === null && $mb['imap_password'] === 'typed-now' && $mb['smtp_server'] === 'smtp.new.example');

$storedSep = ['smtp_username' => 'sender@zoho.test', 'smtp_password' => 'SMTP-SECRET'] + $stored;
$formSep = ['smtp_same_as_imap' => false, 'smtp_username' => 'sender@zoho.test', 'smtp_password' => ''] + $form;
[$mb, $err] = mailboxTestResolve($storedSep, $formSep);
ok('separate SMTP login, unchanged: stored SMTP password used', $err === null && $mb['smtp_password'] === 'SMTP-SECRET' && $mb['smtp_username'] === 'sender@zoho.test', $err);
[$mb, $err] = mailboxTestResolve($storedSep, ['smtp_server' => 'smtp.attacker.example'] + $formSep);
ok('SECURITY: separate SMTP login, changed server - refused', $mb === null && str_contains((string)$err, 'SMTP password'), $err);
[$mb, $err] = mailboxTestResolve($storedSep, ['smtp_username' => 'other@zoho.test'] + $formSep);
ok('SECURITY: separate SMTP login, changed SMTP username - refused', $mb === null, $err);

[$mb, $err] = mailboxTestResolve(null, $form);
ok('a new, unsaved IMAP mailbox with no password typed - asks for it', $mb === null && str_contains((string)$err, 'Type the password'), $err);
[$mb, $err] = mailboxTestResolve(null, ['imap_password' => 'typed', 'smtp_server' => 'smtp.x.test'] + $form);
ok('a new, unsaved IMAP mailbox with the password typed - tests', $err === null && $mb['imap_password'] === 'typed');
[$mb, $err] = mailboxTestResolve($stored, ['smtp_server' => ''] + $form);
ok('no SMTP server - says so', $mb === null && str_contains((string)$err, 'SMTP server'), $err);

$ms = ['id' => 1, 'provider' => 'microsoft', 'name' => 'ITSM'];
[$mb, $err, $saved] = mailboxTestResolve($ms, ['provider' => 'microsoft']);
ok('Microsoft: tested as SAVED, and says so', $mb === $ms && $err === null && $saved === true);
[$mb, $err] = mailboxTestResolve(null, ['provider' => 'google']);
ok('Google, never saved: asks to save and connect first', $mb === null && str_contains((string)$err, 'Save the mailbox and connect it first'), $err);
[$mb, $err] = mailboxTestResolve($ms, ['provider' => 'imap', 'imap_password' => 'x', 'smtp_server' => 'smtp.x.test'] + $form);
ok('provider changed to IMAP but not saved: tests the IMAP form values (nothing stored is reused)', $err === null && $mb['provider'] === 'imap' && $mb['imap_password'] === 'x', $err);
[$mb, $err] = mailboxTestResolve(['provider' => 'imap'] + $stored, ['provider' => 'google']);
ok('provider changed to Google but not saved: asks to save first', $mb === null && str_contains((string)$err, 'provider has been changed'), $err);

echo "\n== 4. A server that never says hello\n";
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$flag = tempnam(sys_get_temp_dir(), 'silent');
@unlink($flag);
$proc = proc_open([PHP_BINARY, __FILE__, '--silent-server', (string)$port, $flag], [], $pipes);
for ($i = 0; $i < 50 && !file_exists($flag); $i++) usleep(100000);
$err = '';
try {
    imapSmtpSend(['smtp_server' => '127.0.0.1', 'smtp_port' => $port, 'smtp_encryption' => 'tls', 'imap_username' => 'u', 'imap_password' => 'p', 'target_mailbox' => 'help@x.test'], 'to@x.test', '', 'S', '<p>b</p>');
} catch (Throwable $e) {
    $err = $e->getMessage();
}
proc_close($proc);
@unlink($flag);
ok('no greeting: says the server never said hello, not \'expected 220, got ""\'', str_contains($err, 'never said hello (no SMTP greeting)') && !str_contains($err, 'expected 220'), $err);
ok('...and, on a port that does not say, gives the general advice', str_contains($err, '465 with SSL, 587 with STARTTLS'), $err);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
