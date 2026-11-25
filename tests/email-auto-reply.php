<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Automatic replies (out-of-office, auto-responders) are recognised on inbound mail
 * (GH #432) and never act as a customer reply.
 *
 * Part 1, no database: emailIsAutoReply() on header sets, and the header maps built
 * from an IMAP FETCH block and from Microsoft Graph's internetMessageHeaders.
 *
 * Part 2, FREEITSM_TEST_DATABASE (the throwaway database config.php uses, after
 * Database Verify): the REAL importer, saveEmailToDatabase(), lifted out of the
 * endpoint by token (as tests/email-thread-replies.php does). An automatic reply on
 * a closed ticket is stored, flagged, and leaves the ticket closed; a human reply
 * reopens it. ZZ rows, removed in finally. Nothing is emailed.
 *
 *   php tests/email-auto-reply.php
 *   FREEITSM_TEST_DATABASE=<the throwaway db> php tests/email-auto-reply.php
 *
 * @wiki Mail-Collection-Developer-Guide
 */
chdir(__DIR__ . '/..');
require_once 'includes/email_auto_reply.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

echo "Which headers mean an automatic reply\n";
foreach ([
    [['auto-submitted' => 'auto-replied'], true, 'Auto-Submitted: auto-replied (an out-of-office)'],
    [['auto-submitted' => 'Auto-Generated'], true, 'Auto-Submitted: auto-generated, any case'],
    [['auto-submitted' => 'auto-notified'], true, 'Auto-Submitted: auto-notified'],
    [['x-autoreply' => 'yes'], true, 'X-Autoreply'],
    [['x-autorespond' => 'x'], true, 'X-Autorespond'],
    [['precedence' => 'auto_reply'], true, 'Precedence: auto_reply'],
    [['precedence' => ' Auto_Reply '], true, 'Precedence: auto_reply, spaces and case'],
    [[], false, 'no headers: a person'],
    [['auto-submitted' => 'no'], false, 'Auto-Submitted: no is a person'],
    [['auto-submitted' => ' NO '], false, 'Auto-Submitted: NO with spaces is a person'],
    [['precedence' => 'bulk'], false, 'Precedence: bulk is not counted (list mail can be a person)'],
    [['precedence' => 'list'], false, 'Precedence: list is not counted'],
    [['x-auto-response-suppress' => 'All'], false, 'X-Auto-Response-Suppress alone is a request to others, not an auto-reply'],
    [['x-autoreply' => ''], false, 'an empty X-Autoreply is not counted'],
] as [$h, $want, $what]) {
    check($what, emailIsAutoReply($h) === $want, json_encode($h));
}

echo "\nHeader maps\n";
$map = emailHeaderMap("Auto-Submitted: auto-replied\r\nIn-Reply-To: <a@b>\r\nX-Long: one\r\n two\r\n\r\n");
check('an IMAP header block: names lower-cased, values trimmed', ($map['auto-submitted'] ?? null) === 'auto-replied' && ($map['in-reply-to'] ?? null) === '<a@b>', json_encode($map));
check('...folded lines joined', ($map['x-long'] ?? null) === 'one two', json_encode($map));
check('...so an out-of-office FETCH is an automatic reply', emailIsAutoReply($map));
check('...and the same FETCH without the header is not', !emailIsAutoReply(emailHeaderMap("In-Reply-To: <a@b>\r\n\r\n")));
check('an empty block: an empty map', emailHeaderMap('') === []);
$item = ['UID' => '5', 'BODY[HEADER.FIELDS (REFERENCES IN-REPLY-TO AUTO-SUBMITTED PRECEDENCE X-AUTOREPLY X-AUTORESPOND)]' => "Precedence: auto_reply\r\n\r\n"];
check('an IMAP FETCH result: the header block is found by its key', emailIsAutoReply(emailImapHeaderMap($item)));
check('...a FETCH with no header block: empty map', emailImapHeaderMap(['UID' => '5']) === []);
$graph = emailHeaderMapFromList([['name' => 'Auto-Submitted', 'value' => 'auto-replied'], ['name' => 'X-MS-Has-Attach', 'value' => '']]);
check('Graph internetMessageHeaders: name/value pairs to a lower-cased map', emailIsAutoReply($graph) && array_key_exists('x-ms-has-attach', $graph), json_encode($graph));
check('...missing (null): empty map, not an error', emailHeaderMapFromList(null) === []);

echo "\nThe acknowledgement\n";
check('a new ticket from a person is acknowledged', inboundShouldAcknowledge(true, false) === true);
check('a new ticket from an automatic reply is not', inboundShouldAcknowledge(true, true) === false);
check('a reply on an existing ticket is never acknowledged', inboundShouldAcknowledge(false, false) === false);

check('the importer reads Graph headers too', inboundIsAutoReply(['internetMessageHeaders' => [['name' => 'Auto-Submitted', 'value' => 'auto-replied']]]));
check('...or the provider flag', inboundIsAutoReply(['auto_reply' => true]) && !inboundIsAutoReply(['auto_reply' => false]) && !inboundIsAutoReply([]));

$expected = getenv('FREEITSM_TEST_DATABASE');
if ($expected) {
    require_once 'config.php';
    require_once 'includes/functions.php';
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
        echo "  (skipped the database checks: config.php does not use $expected)\n";
    } elseif (!emailAutoReplyReady($conn)) {
        check('emails.is_auto_reply exists - run Database Verify first', false);
    } else {
        require_once 'includes/attachment_limits.php';
        echo "\nThe oversize auto-reply\n";
        check('is never sent to an automatic reply', oversizeReplySkipReason($conn, 0, 'zz432@example.invalid', 'mb@example.invalid', true) === 'an automatic reply');
        check('...but still to a person (negative control)', oversizeReplySkipReason($conn, 0, 'zz432-person@example.invalid', 'mb@example.invalid') === null);

        echo "\nThe real importer\n";
        $tag = 'zz432-' . bin2hex(random_bytes(3));
        $made = ['tickets' => [], 'emails' => [], 'mailboxes' => []];
        $oldReopen = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'reopen_on_customer_reply'")->fetchColumn();
        try {
            $lift = function (string $name, array $tokens): string {
                $src = ''; $depth = 0; $on = false;
                foreach ($tokens as $k => $t) {
                    $text = is_array($t) ? $t[1] : $t;
                    if (!$on && is_array($t) && $t[0] === T_FUNCTION) {
                        for ($j = $k + 1; is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
                        if (is_array($tokens[$j]) && $tokens[$j][1] === $name) $on = true;
                    }
                    if (!$on) continue;
                    $src .= $text;
                    if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
                    if ($text === '}') { $depth--; if ($depth === 0) break; }
                }
                // In eval'd code __DIR__ is this test's folder; the endpoint's paths are relative to its own.
                return str_replace('__DIR__', var_export(realpath('api/tickets'), true), $src);
            };
            $tokens = token_get_all(file_get_contents('api/tickets/check_mailbox_email.php'));
            foreach (['saveEmailToDatabase', 'extractTicketReference', 'findTicketByNumber', 'getOrCreateUser', 'stripInboundThread', 'logEmailImport'] as $fn) {
                if (!function_exists($fn) && ($part = $lift($fn, $tokens)) !== '') eval($part);
            }
            require_once 'includes/ticket_numbering.php';
            require_once 'includes/ticket_reply.php';
            require_once 'includes/ticket_snooze.php';
            require_once 'includes/uploads.php';
            check('the import function was found', function_exists('saveEmailToDatabase'));
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('reopen_on_customer_reply', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();

            $conn->prepare("INSERT INTO target_mailboxes (name, target_mailbox, azure_tenant_id, azure_client_id, azure_client_secret, oauth_redirect_uri, imap_server, is_active)
                            VALUES (?, ?, '', '', '', '', '', 0)")->execute([$tag, "$tag@example.invalid"]);
            $mailboxId = $made['mailboxes'][] = (int)$conn->lastInsertId();
            $closedId = (int)$conn->query("SELECT id FROM ticket_statuses WHERE is_closed = 1 ORDER BY id LIMIT 1")->fetchColumn();
            if (!$closedId) throw new RuntimeException('Needs a closed ticket status.');
            $ticket = function (string $suffix, ?string $snoozedUntil = null) use ($conn, $tag, $closedId, &$made): array {
                $num = strtoupper("$tag-$suffix");
                $conn->prepare("INSERT INTO tickets (ticket_number, subject, status_id, snoozed_until, created_datetime, updated_datetime)
                                VALUES (?, 'ZZ432 auto-reply test', ?, ?, '2020-01-01 00:00:00', '2020-01-01 00:00:00')")
                     ->execute([$num, $snoozedUntil ? null : $closedId, $snoozedUntil]);
                return [$made['tickets'][] = (int)$conn->lastInsertId(), $num];
            };
            $row = function (int $id) use ($conn): array {
                $st = $conn->prepare("SELECT status_id, snoozed_until, updated_datetime FROM tickets WHERE id = ?");
                $st->execute([$id]);
                return $st->fetch(PDO::FETCH_ASSOC);
            };
            $import = function (string $num, string $suffix, array $extra) use ($conn, $tag, $mailboxId, &$made): array {
                $r = saveEmailToDatabase($conn, $extra + [
                    'id' => "$tag-msg-$suffix", 'subject' => "Automatic reply: Re: ZZ432 [SDREF:$num]",
                    'from' => ['emailAddress' => ['address' => 'zz432-sender@example.invalid', 'name' => 'ZZ432']],
                    'body' => ['contentType' => 'html', 'content' => '<p>I am away.</p>'], 'hasAttachments' => false,
                ], 'graph-token-not-used', $mailboxId);
                $st = $conn->prepare("SELECT id, ticket_id, is_auto_reply FROM emails WHERE exchange_message_id = ?");
                $st->execute(["$tag-msg-$suffix"]);
                $e = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($e) $made['emails'][] = (int)$e['id'];
                return [$r, $e];
            };

            [$t1, $n1] = $ticket('a');
            [$r, $e] = $import($n1, 'a', ['auto_reply' => true]);
            check('an automatic reply is stored on its ticket', (int)($e['ticket_id'] ?? 0) === $t1, json_encode($e));
            check('...flagged as one', (int)($e['is_auto_reply'] ?? 0) === 1);
            check('...and the importer says so', !empty($r['auto_reply']) && empty($r['is_new_ticket']), json_encode($r));
            $after = $row($t1);
            check('...the closed ticket stays closed', (int)$after['status_id'] === $closedId, json_encode($after));
            check('...and its last update is unchanged', $after['updated_datetime'] === '2020-01-01 00:00:00', json_encode($after));

            $until = gmdate('Y-m-d H:i:s', time() + 86400);
            [$t2, $n2] = $ticket('b', $until);
            [, $e] = $import($n2, 'b', ['internetMessageHeaders' => [['name' => 'Auto-Submitted', 'value' => 'auto-replied']]]);
            check('a Microsoft Graph automatic reply (headers only) is flagged', (int)($e['is_auto_reply'] ?? 0) === 1);
            check('...and the snoozed ticket stays asleep', $row($t2)['snoozed_until'] === $until, json_encode($row($t2)));

            [$t3, $n3] = $ticket('c');
            [$r, $e] = $import($n3, 'c', ['internetMessageHeaders' => [['name' => 'Auto-Submitted', 'value' => 'no']]]);
            check("a person's reply (Auto-Submitted: no) is not flagged", (int)($e['is_auto_reply'] ?? 1) === 0 && empty($r['auto_reply']));
            $after = $row($t3);
            check('...and reopens the closed ticket (negative control)', (int)$after['status_id'] !== $closedId, json_encode($after));
            check('...and bumps its last update', $after['updated_datetime'] !== '2020-01-01 00:00:00');
            check('a reply from someone not on the ticket still joins it by [SDREF], as before', (int)($e['ticket_id'] ?? 0) === $t3);
        } finally {
            foreach ($made['emails'] as $id) $conn->prepare("DELETE FROM emails WHERE id = ?")->execute([$id]);
            foreach ($made['tickets'] as $id) {
                $conn->prepare("DELETE FROM ticket_audit WHERE ticket_id = ?")->execute([$id]);
                $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$id]);
            }
            foreach ($made['mailboxes'] as $id) $conn->prepare("DELETE FROM target_mailboxes WHERE id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM users WHERE email = 'zz432-sender@example.invalid'")->execute();
            if ($oldReopen === false) $conn->prepare("DELETE FROM system_settings WHERE setting_key = 'reopen_on_customer_reply'")->execute();
            else $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'reopen_on_customer_reply'")->execute([$oldReopen]);
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
