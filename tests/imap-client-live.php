<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Basic IMAP against REAL IMAP servers, with no imap extension (GH #257, #259).
 *
 *   php tests/imap-client-live.php
 *
 * Needs one or both test servers running in Docker (nothing else - no database,
 * no FreeITSM install, no real mailbox):
 *
 *   docker run -d --name freeitsm-imaptest-dovecot -e USER_PASSWORD='{PLAIN}pass' \
 *       -p 31143:31143 -p 31993:31993 dovecot/dovecot:latest
 *   docker run -d --name freeitsm-imaptest-greenmail \
 *       -e GREENMAIL_OPTS='-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.auth.disabled' \
 *       -p 3143:3143 -p 3993:3993 greenmail/standalone:2.1.3
 *
 * A server that is not running is SKIPPED and named, never counted as a pass.
 * Each run signs in as a brand-new user, so it starts from an empty mailbox and
 * nothing is left behind that matters (remove the containers when done).
 *
 * It drives the SAME functions the mailbox poll uses - imapGetEmails(),
 * imapHandleAfterProcessing(), imapVerifyFolder() - with six test emails that
 * cover the cases that break mail readers: encoded subjects and names, HTML with
 * a plain alternative, attachments with non-English filenames, an inline image,
 * a Latin-1 body, an attached email, and a message with no Message-ID.
 */

require_once __DIR__ . '/../includes/mailbox_imap.php';

// Where the test servers are. Inside the FreeITSM Docker image, run with
// FREEITSM_IMAP_TEST_HOST=host.docker.internal to reach the ones on the host.
$host = getenv('FREEITSM_IMAP_TEST_HOST') ?: '127.0.0.1';
echo "Test servers on {$host}. imap extension loaded: " . (extension_loaded('imap') ? 'YES' : 'no') . "\n";

$pass = 0; $fail = 0; $skipped = [];
function ok(string $what, bool $cond, $detail = null): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== null ? "\n        " . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : '') . "\n"; }
}
/** Runs $fn and returns the exception message it threw, or '' if it did not throw. */
function thrown(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}
function portOpen(int $port): bool {
    $s = @fsockopen($GLOBALS['host'], $port, $e, $es, 1);
    if ($s) { fclose($s); return true; }
    return false;
}

$servers = [
    'Dovecot'   => ['ssl' => 31993, 'tls' => 31143, 'plain' => 31143, 'password' => 'pass', 'plainLoginRefused' => true],
    'GreenMail' => ['ssl' => 3993,  'tls' => null,  'plain' => 3143,  'password' => 'anything', 'plainLoginRefused' => false],
];
foreach ($servers as $name => $s) {
    if (!portOpen($s['ssl'])) { $skipped[] = $name; unset($servers[$name]); }
}
if (!$servers) {
    echo "No test IMAP server is running - see the top of this file. SKIPPED: " . implode(', ', $skipped) . "\n";
    exit(2);
}

// ------------------------------------------------------------------ the six emails
$pdfBytes = "%PDF-1.4\n" . str_repeat("binary \x00\x01\xFF bytes\n", 40);
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$emails = [
    // 1 - plain text, encoded subject and sender name, a Cc.
    "From: =?UTF-8?B?SsO2cmcgTcO8bGxlcg==?= <jorg@example.com>\r\nTo: Support <support@freeitsm.test>\r\nCc: ann@example.com\r\n"
    . "Subject: =?UTF-8?Q?Drucker_im_B=C3=BCro?=\r\nDate: Tue, 03 Nov 2026 09:15:00 +0000\r\nMessage-ID: <zz-1@example.com>\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\nThe printer <b>is</b> jammed.\r\nSecond line.\r\n",
    // 2 - HTML with a plain alternative: the HTML must win.
    "From: bob@example.com\r\nTo: support@freeitsm.test\r\nSubject: Alternative\r\nDate: Tue, 03 Nov 2026 09:16:00 +0000\r\nMessage-ID: <zz-2@example.com>\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"alt\"\r\n\r\n"
    . "--alt\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nplain version\r\n"
    . "--alt\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<p>HTML version =E2=9C=93</p>\r\n--alt--\r\n",
    // 3 - mixed: related{html + inline logo} + a PDF with a non-English filename (RFC 2231).
    "From: carol@example.com\r\nTo: support@freeitsm.test\r\nSubject: Report attached\r\nDate: Tue, 03 Nov 2026 09:17:00 +0000\r\nMessage-ID: <zz-3@example.com>\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"mix\"\r\n\r\n"
    . "--mix\r\nContent-Type: multipart/related; boundary=\"rel\"\r\n\r\n"
    . "--rel\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>See logo <img src=\"cid:logo@freeitsm\"></p>\r\n"
    . "--rel\r\nContent-Type: image/png; name=\"logo.png\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <logo@freeitsm>\r\nContent-Disposition: inline; filename=\"logo.png\"\r\n\r\n"
    . chunk_split(base64_encode($pngBytes)) . "--rel--\r\n"
    . "--mix\r\nContent-Type: application/pdf\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename*=utf-8''%C3%9Cbersicht%20Q3.pdf\r\n\r\n"
    . chunk_split(base64_encode($pdfBytes)) . "--mix--\r\n",
    // 4 - a Latin-1, quoted-printable plain body.
    "From: dave@example.com\r\nTo: support@freeitsm.test\r\nSubject: Latin-1\r\nDate: Tue, 03 Nov 2026 09:18:00 +0000\r\nMessage-ID: <zz-4@example.com>\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nCaf=E9 machine is broken\r\n",
    // 5 - an attached email (message/rfc822).
    "From: erin@example.com\r\nTo: support@freeitsm.test\r\nSubject: Fwd: original\r\nDate: Tue, 03 Nov 2026 09:19:00 +0000\r\nMessage-ID: <zz-5@example.com>\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"fw\"\r\n\r\n"
    . "--fw\r\nContent-Type: text/plain\r\n\r\nForwarding the original.\r\n"
    . "--fw\r\nContent-Type: message/rfc822\r\nContent-Disposition: attachment; filename=\"original.eml\"\r\n\r\n"
    . "From: someone@example.com\r\nSubject: original\r\n\r\nInner body.\r\n--fw--\r\n",
    // 6 - no Message-ID header at all.
    "From: frank@example.com\r\nTo: support@freeitsm.test\r\nSubject: No id\r\nDate: Tue, 03 Nov 2026 09:20:00 +0000\r\n\r\nNo Message-ID here.\r\n",
];

foreach ($servers as $serverName => $srv) {
    echo "\n######## {$serverName}\n";
    $user = 'zz-imaptest-' . date('His') . '-' . bin2hex(random_bytes(2));
    $base = [
        'imap_server' => $host, 'imap_port' => $srv['ssl'], 'imap_encryption' => 'ssl',
        'imap_username' => $user, 'imap_password' => $srv['password'], 'email_folder' => 'INBOX', 'max_emails_per_check' => 10,
    ];
    static $mailboxId = 9000;

    // ---- Certificate verification comes FIRST: SSL_VERIFY_PEER is not defined yet, so it is ON.
    if (!defined('SSL_VERIFY_PEER')) {
        echo "== Certificates are checked (the test servers' are self-signed)\n";
        $c = new ImapClient($host, $srv['ssl'], 'ssl', 10);
        $err = thrown(fn () => $c->connect());
        ok('CONTROL: a self-signed certificate is REFUSED while verification is on', $err !== '' && stripos($err, 'IMAP connection') !== false, $err);
        // From here on, the install-wide switch an operator would use for a self-signed server.
        define('SSL_VERIFY_PEER', false);
    }

    echo "== Signing in\n";
    $c = new ImapClient($host, $srv['ssl'], 'ssl', 10);
    $c->connect();
    $c->login($user, $srv['password']);
    ok('SSL on port ' . $srv['ssl'] . ': connected and signed in', true);
    ok('capabilities read after sign-in', $c->hasCapability('IMAP4REV1') || $c->hasCapability('IMAP4REV2'), $c->capability());
    echo "        (server offers MOVE: " . ($c->hasCapability('MOVE') ? 'yes' : 'no - the COPY fallback is tested') . ", UIDPLUS: " . ($c->hasCapability('UIDPLUS') ? 'yes' : 'no') . ")\n";

    if ($srv['tls']) {
        $t = new ImapClient($host, $srv['tls'], 'tls', 10);
        $err = thrown(function () use ($t, $user, $srv) { $t->connect(); $t->login($user, $srv['password']); $t->select('INBOX'); });
        ok('STARTTLS on port ' . $srv['tls'] . ': upgraded, signed in, opened INBOX', $err === '', $err);
    }
    if ($srv['plainLoginRefused']) {
        $p = new ImapClient($host, $srv['plain'], 'none', 10);
        $err = thrown(function () use ($p, $user, $srv) { $p->connect(); $p->login($user, $srv['password']); });
        ok('unencrypted: the server\'s LOGINDISABLED is explained, the password is not sent', str_contains($err, 'unencrypted connection'), $err);
    } else {
        $p = new ImapClient($host, $srv['plain'], 'none', 10);
        $err = thrown(function () use ($p, $user, $srv) { $p->connect(); $p->login($user, $srv['password']); $p->select('INBOX'); });
        ok('unencrypted on port ' . $srv['plain'] . ' (this server allows it): signed in', $err === '', $err);
    }

    if ($serverName === 'Dovecot') {
        $w = new ImapClient($host, $srv['ssl'], 'ssl', 10);
        $w->connect();
        $err = thrown(fn () => $w->login($user, 'wrong-password'));
        ok('a wrong password says sign-in failed, in the server\'s words', str_starts_with($err, 'IMAP sign-in failed:'), $err);
        $w = new ImapClient($host, $srv['ssl'], 'ssl', 10);
        $w->connect();
        $err = thrown(fn () => $w->login($user, "pass\r\nA9999 LOGOUT"));
        ok('INJECTION: a password with a line break is one value - sign-in fails, nothing else runs', str_starts_with($err, 'IMAP sign-in failed:'), $err);
        $err = thrown(fn () => $w->select('INBOX'));
        ok('...and the connection is still in step (it answers the next command sensibly)', $err !== '' && stripos($err, 'closed') === false && stripos($err, 'timed out') === false, $err);
    }
    $err = thrown(function () { $x = new ImapClient('127.0.0.1', 1, 'ssl', 3); $x->connect(); });
    ok('nothing listening: a clear connection error', str_starts_with($err, 'IMAP connection to 127.0.0.1:1 failed'), $err);

    // ---- Put the six emails in, and make the folders the post-import actions use.
    foreach ($emails as $raw) {
        $c->command('APPEND "INBOX" ' . imapString($raw . (preg_match('/[\x80-\xFF]/', $raw) ? '' : '')));
    }
    $c->command('CREATE ' . imapString(imapFolderEncode('Übergabe')));
    $c->command('CREATE "Processed"');
    $c->logout();

    echo "== Reading - through imapGetEmails(), as the poll does\n";
    $mb = array_replace($base, ['id' => ++$mailboxId]);
    $got = imapGetEmails($mb);
    ok('all six read, oldest first', count($got) === 6 && $got[0]['internet_message_id'] === '<zz-1@example.com>', array_column($got, 'subject'));
    [$m1, $m2, $m3, $m4, $m5, $m6] = $got + array_fill(0, 6, []);

    ok('1: encoded subject decoded', ($m1['subject'] ?? '') === 'Drucker im Büro', $m1['subject'] ?? null);
    ok('1: encoded sender name decoded, address split', ($m1['from'] ?? null) === ['emailAddress' => ['name' => 'Jörg Müller', 'address' => 'jorg@example.com']], $m1['from'] ?? null);
    ok('1: To and Cc', ($m1['toRecipients'][0]['emailAddress'] ?? null) === ['name' => 'Support', 'address' => 'support@freeitsm.test'] && ($m1['ccRecipients'][0]['emailAddress']['address'] ?? '') === 'ann@example.com', [$m1['toRecipients'] ?? null, $m1['ccRecipients'] ?? null]);
    ok('1: date from the Date header, in UTC', ($m1['receivedDateTime'] ?? '') === '2026-11-03T09:15:00Z', $m1['receivedDateTime'] ?? null);
    ok('1: plain body is escaped and line-broken, as before', str_contains($m1['body']['content'] ?? '', 'The printer &lt;b&gt;is&lt;/b&gt; jammed.<br />'), $m1['body']['content'] ?? null);
    ok('2: the HTML alternative wins over the plain one', trim($m2['body']['content'] ?? '') === '<p>HTML version ✓</p>', $m2['body']['content'] ?? null);

    $att = [];
    foreach ($m3['attachments_inline'] ?? [] as $a) { $att[$a['name']] = $a; }
    ok('3: HTML body found inside multipart/mixed > related', str_contains($m3['body']['content'] ?? '', 'cid:logo@freeitsm'), $m3['body']['content'] ?? null);
    ok('3: two attachments, hasAttachments set', count($att) === 2 && ($m3['hasAttachments'] ?? false) === true, array_keys($att));
    ok('3: the PDF\'s non-English filename decoded (RFC 2231)', isset($att['Übersicht Q3.pdf']), array_keys($att));
    ok('3: the PDF\'s bytes are identical', isset($att['Übersicht Q3.pdf']) && base64_decode($att['Übersicht Q3.pdf']['contentBytes']) === $pdfBytes && $att['Übersicht Q3.pdf']['contentType'] === 'application/pdf');
    ok('3: the PDF is not inline', isset($att['Übersicht Q3.pdf']) && $att['Übersicht Q3.pdf']['isInline'] === false);
    ok('3: the logo is inline with its Content-ID, bytes identical', isset($att['logo.png']) && $att['logo.png']['isInline'] === true && $att['logo.png']['contentId'] === 'logo@freeitsm' && base64_decode($att['logo.png']['contentBytes']) === $pngBytes, $att['logo.png'] ?? null);
    ok('4: Latin-1 body converted to UTF-8', str_contains($m4['body']['content'] ?? '', 'Café machine is broken'), $m4['body']['content'] ?? null);
    $fwd = $m5['attachments_inline'][0] ?? [];
    ok('5: an attached email is an attachment, whole', ($fwd['name'] ?? '') === 'original.eml' && ($fwd['contentType'] ?? '') === 'message/rfc822' && str_contains(base64_decode($fwd['contentBytes'] ?? ''), 'Inner body.'), $fwd ? ['name' => $fwd['name'], 'type' => $fwd['contentType']] : null);
    ok('5: and its own text is the body', str_contains($m5['body']['content'] ?? '', 'Forwarding the original.'), $m5['body']['content'] ?? null);
    ok('6: no Message-ID: the mailbox-scoped fallback key', ($m6['internet_message_id'] ?? '') === 'imap-' . $mailboxId . '-' . ($m6['id'] ?? '?'), $m6['internet_message_id'] ?? null);

    echo "== Nothing is marked read by reading\n";
    $check = new ImapClient($host, $srv['ssl'], 'ssl', 10);
    $check->connect(); $check->login($user, $srv['password']); $check->select('INBOX');
    ok('PEEK: all six are still unread after the fetch', count($check->uidSearch('UNSEEN')) === 6, $check->uidSearch('UNSEEN'));
    $mbCap = array_replace($base, ['id' => ++$mailboxId, 'max_emails_per_check' => 2]);
    $two = imapGetEmails($mbCap);
    ok('max_emails_per_check = 2 reads the two OLDEST', array_column($two, 'internet_message_id') === ['<zz-1@example.com>', '<zz-2@example.com>'], array_column($two, 'internet_message_id'));

    echo "== After import - through imapHandleAfterProcessing()\n";
    imapHandleAfterProcessing($mb, (int) $m1['id'], 'mark_read');
    $check->select('INBOX');
    ok('mark_read: one fewer unread', count($check->uidSearch('UNSEEN')) === 5);
    imapHandleAfterProcessing($mb, (int) $m2['id'], 'move_to_folder', 'Übergabe');
    $inbox = $check->select('INBOX');
    $moved = $check->select('Übergabe');
    ok('move_to_folder "Übergabe" (a non-English name): gone from INBOX, in the folder', $inbox === 5 && $moved === 1, ['inbox' => $inbox, 'folder' => $moved]);
    imapHandleAfterProcessing($mb, (int) $m3['id'], 'delete');
    ok('delete: gone', $check->select('INBOX') === 4);
    imapHandleAfterProcessing($mb, (int) $m4['id'], 'move_to_folder', null);
    ok('move_to_folder with no folder deletes, as before', $check->select('INBOX') === 3);
    $err = thrown(fn () => imapHandleAfterProcessing($mb, (int) $m5['id'], 'move_to_folder', 'No Such Folder'));
    ok('a move to a folder that does not exist names it', str_starts_with($err, 'IMAP move to "No Such Folder" failed:'), $err);
    ok('...and the message stays put', $check->select('INBOX') === 3);

    echo "== Verify - through imapVerifyFolder(), as the mailbox screen does\n";
    $mbV = array_replace($base, ['id' => ++$mailboxId]);
    $v = imapVerifyFolder($mbV, 'Übergabe');
    ok('a non-English folder is found, with its counts', $v['displayName'] === 'Übergabe' && $v['totalItemCount'] === 1 && $v['unreadItemCount'] === 1, $v);
    $v = imapVerifyFolder($mbV, 'processed');
    ok('found whatever the capitals', $v['displayName'] === 'Processed', $v);
    $err = thrown(fn () => imapVerifyFolder($mbV, 'Nope'));
    ok('a missing folder is named, and the real ones listed', str_contains($err, 'Folder "Nope" was not found') && str_contains($err, 'Übergabe'), $err);
    $mbBad = array_replace($base, ['id' => ++$mailboxId, 'imap_password' => 'wrong-password']);
    if ($serverName === 'Dovecot') {
        $err = thrown(fn () => imapVerifyFolder($mbBad, 'INBOX'));
        ok('wrong password through Verify: a sign-in failure, not "folder not found"', str_starts_with($err, 'IMAP sign-in failed:'), $err);
    }
    echo "== An older server: no MOVE, no UIDPLUS\n";
    // Both test servers have MOVE and UIDPLUS, so hide them to drive the fallback:
    // COPY + \Deleted + a plain EXPUNGE, which is what MOVE does in one step.
    $old = new class($host, $srv['ssl'], 'ssl', 10) extends ImapClient {
        public array $sent = [];
        public function hasCapability(string $name): bool {
            return in_array(strtoupper($name), ['MOVE', 'UIDPLUS'], true) ? false : parent::hasCapability($name);
        }
        public function command(string $command): array { $this->sent[] = strtok($command, ' '); return parent::command($command); }
    };
    $old->connect(); $old->login($user, $srv['password']);
    $before = $old->select('INBOX');
    $uids = $old->uidSearch('ALL');
    $old->uidMove($uids[0], 'Processed');
    ok('move without MOVE: COPY then EXPUNGE, not MOVE', in_array('UID', $old->sent, true) && in_array('EXPUNGE', $old->sent, true), $old->sent);
    ok('...and the message really moved', $old->select('INBOX') === $before - 1 && $old->select('Processed') === 1);
    $old->select('INBOX');
    $old->uidDelete($old->uidSearch('ALL')[0]);
    ok('delete without UIDPLUS: plain EXPUNGE removes it', $old->select('INBOX') === $before - 2);
    $old->logout();

    echo "== Mail arriving mid-session (unrequested replies)\n";
    // A second connection delivers a message while the first has INBOX open. The
    // server then volunteers "* n EXISTS" ahead of the next command's own results.
    $a = new ImapClient($host, $srv['ssl'], 'ssl', 10);
    $a->connect(); $a->login($user, $srv['password']);
    $start = $a->select('INBOX');
    $b = new ImapClient($host, $srv['ssl'], 'ssl', 10);
    $b->connect(); $b->login($user, $srv['password']);
    $b->command('APPEND "INBOX" ' . imapString("From: late@example.com\r\nSubject: Late\r\nMessage-ID: <zz-late@example.com>\r\n\r\nArrived late.\r\n"));
    $b->logout();
    // An open folder learns of new mail when it next talks to the server - NOOP is
    // the "anything new?" command. Dovecot answers it with "* n EXISTS"; the poll
    // never needs this, as it opens a fresh connection each time.
    $noop = $a->command('NOOP');
    ok('NOOP: the server volunteers the new message count, and the client reads past it', (bool) preg_grep('/^\* ' . ($start + 1) . ' EXISTS/i', $noop), $noop);
    $unseen = $a->uidSearch('UNSEEN');
    $item = $a->uidFetch(end($unseen), '(UID ENVELOPE)');
    ok('the search still works, and sees the new message', imapEnvelope($item['ENVELOPE'] ?? null)['message_id'] === '<zz-late@example.com>', $item);
    $a->logout();

    $check->logout();
}

// ------------------------------------------------------------------ the round trip
// FreeITSM's own SMTP sender -> GreenMail -> FreeITSM's own IMAP reader. This is
// the test that found the RFC 2231 filename bug: each side passed on its own, and
// only sending what one wrote and reading it with the other showed the reader
// preferring the ASCII fallback name.
if (isset($servers['GreenMail']) && portOpen(3025)) {
    echo "\n######## Round trip: FreeITSM sends (SMTP), FreeITSM reads (IMAP)\n";
    $rt = 'zz-roundtrip-' . bin2hex(random_bytes(3)) . '@freeitsm.test';
    $mbRT = ['id' => 9999, 'name' => 'Support Desk', 'target_mailbox' => 'helpdesk@freeitsm.test',
        'smtp_server' => $host, 'smtp_port' => 3025, 'smtp_encryption' => 'none',
        'imap_server' => $host, 'imap_port' => 3143, 'imap_encryption' => 'none',
        'imap_username' => $rt, 'imap_password' => 'x', 'email_folder' => 'INBOX', 'max_emails_per_check' => 10];
    $rtPdf = "%PDF-1.4 round trip \x00\xFF";
    imapSmtpSend($mbRT, $rt, '', 'Re: [#1042] Ihr Drucker – erledigt ✓', '<p>Fixed. <img src="cid:sig@x"></p>', [
        ['name' => 'Bericht für Sie.pdf', 'contentType' => 'application/pdf', 'contentBytes' => base64_encode($rtPdf)],
        ['name' => 'sig.png', 'contentType' => 'image/png', 'contentBytes' => base64_encode("\x89PNG fake"), 'isInline' => true, 'contentId' => 'sig@x'],
    ]);
    $rtGot = imapGetEmails($mbRT);
    $rtM = $rtGot[0] ?? [];
    $rtAtt = array_column($rtM['attachments_inline'] ?? [], null, 'name');
    ok('the reply arrives and is read', count($rtGot) === 1, count($rtGot));
    ok('subject with a dash and a tick survives both ways', ($rtM['subject'] ?? '') === 'Re: [#1042] Ihr Drucker – erledigt ✓', $rtM['subject'] ?? null);
    ok('the mailbox name is the sender name', ($rtM['from']['emailAddress'] ?? null) === ['name' => 'Support Desk', 'address' => 'helpdesk@freeitsm.test'], $rtM['from'] ?? null);
    ok('the attachment keeps its real name, not the ASCII fallback', isset($rtAtt['Bericht für Sie.pdf']), array_keys($rtAtt));
    ok('...and its bytes', isset($rtAtt['Bericht für Sie.pdf']) && base64_decode($rtAtt['Bericht für Sie.pdf']['contentBytes']) === $rtPdf);
    ok('the inline image keeps its Content-ID', ($rtAtt['sig.png']['contentId'] ?? '') === 'sig@x' && ($rtAtt['sig.png']['isInline'] ?? false) === true, $rtAtt['sig.png'] ?? null);
}

echo "\n{$pass} passed, {$fail} failed" . ($skipped ? ' - SKIPPED (not running): ' . implode(', ', $skipped) : '') . "\n";
exit($fail ? 1 : 0);
