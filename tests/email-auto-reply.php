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

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
