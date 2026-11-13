<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. Needs no database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The subject a reply or forward goes out with (GH #306): editable in the dialog,
 * but this ticket's [SDREF] always stays, so replies keep threading.
 *
 * Run: php tests/ticket-outbound-subject.php
 */

require_once __DIR__ . '/../includes/email_threading.php';

$pass = 0; $fail = 0;
function eq(string $what, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  PASS  {$what}\n"; }
    else { $fail++; echo "  FAIL  {$what}  <- got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"; }
}

$n = 'ANY-613-42297';
eq('the provider\'s number is kept, the reference stays where it is',
   ticketOutboundSubject("#TCK89633 - FW: Test with M42 [SDREF:$n]", $n), "#TCK89633 - FW: Test with M42 [SDREF:$n]");
eq('a removed reference is added back at the end',
   ticketOutboundSubject('#TCK89633 - FW: Test with M42', $n), "#TCK89633 - FW: Test with M42 [SDREF:$n]");
eq('the reference matches whatever its case and spacing', ticketOutboundSubject("RE: x [sdref: $n ]", $n), "RE: x [sdref: $n ]");
eq('another ticket\'s reference is taken out, and this one added',
   ticketOutboundSubject('RE: x [SDREF:OTHER-1]', $n), "RE: x [SDREF:$n]");
eq('line breaks cannot reach the header', ticketOutboundSubject("RE: one\r\nBcc: evil@example.org", $n), "RE: one Bcc: evil@example.org [SDREF:$n]");
eq('an empty subject becomes just the reference', ticketOutboundSubject('   ', $n), "[SDREF:$n]");
$long = str_repeat('word ', 80);
$out = ticketOutboundSubject($long, $n);
eq('a long subject fits 255 characters, the reference kept whole', mb_strlen($out) <= 255 && str_ends_with($out, "[SDREF:$n]"), true);
eq('multi-byte words are cut on a character, not a byte', mb_check_encoding(ticketOutboundSubject(str_repeat('é', 300), $n), 'UTF-8'), true);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
