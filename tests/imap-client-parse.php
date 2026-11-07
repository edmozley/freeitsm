<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The IMAP client's parsing, with no server (GH #257, #259).
 *
 *   php tests/imap-client-parse.php
 *
 * includes/imap_client.php replaces PHP's imap extension. Everything that turns a
 * server's reply into values - lists, quoted strings, literals, NIL, BODYSTRUCTURE,
 * ENVELOPE, encoded headers and filenames - is tested here against reply text
 * copied from the shapes real servers send. The talking-to-a-server half is
 * tests/imap-client-live.php.
 *
 * Reads nothing, writes nothing, needs no database and no network.
 */

require_once __DIR__ . '/../includes/imap_client.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, $detail = null): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== null ? "\n        " . json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '') . "\n"; }
}

echo "== Values\n";
$v = imapParseValues('(\\HasNoChildren \\Sent) "/" "Sent Items"');
ok('a list, a quoted delimiter, a quoted name', $v === [['\\HasNoChildren', '\\Sent'], '/', 'Sent Items'], $v);
$v = imapParseValues('(\\Noselect) NIL INBOX');
ok('NIL is null, a bare atom is a string', $v === [['\\Noselect'], null, 'INBOX'], $v);
$v = imapParseValues('"say \\"hi\\" \\\\ bye"');
ok('quoted string unescapes \\" and \\\\', $v === ['say "hi" \\ bye'], $v);
$v = imapParseValues("(UID 7 BODY[1.2] {10}\r\nline1\r\nend FLAGS (\\Seen))");
ok('a literal keeps its CRLFs and the list carries on after it', $v === [['UID', '7', 'BODY[1.2]', "line1\r\nend", 'FLAGS', ['\\Seen']]], $v);
$v = imapParseValues('(BODY[HEADER.FIELDS (SUBJECT FROM)] {0}' . "\r\n" . ')');
ok('a section with spaces inside brackets is one atom', ($v[0][0] ?? null) === 'BODY[HEADER.FIELDS (SUBJECT FROM)]' && ($v[0][1] ?? null) === '', $v);
$v = imapParseValues('(a (b (c)) d');
ok('a truncated list returns what it has instead of looping', $v === [['a', ['b', ['c']], 'd']], $v);
$v = imapParseValues("{5}\r\nab");
ok('a literal shorter than declared does not run past the end', $v === ['ab'], $v);

echo "\n== Arguments we send (the injection guard)\n";
ok('plain value is quoted', imapString('support@example.com') === '"support@example.com"');
ok('a quote and a backslash are escaped', imapString('pa"ss\\word') === '"pa\\"ss\\\\word"', imapString('pa"ss\\word'));
$lit = imapString("pass\r\nA0002 DELETE INBOX");
ok('CRLF is sent as a literal, so it cannot start a new command', str_starts_with($lit, "{24}\r\n") && substr($lit, 6) === "pass\r\nA0002 DELETE INBOX", $lit);
ok('non-ASCII is sent as a literal with its BYTE length', imapString('pässwörd') === "{10}\r\npässwörd", imapString('pässwörd'));

echo "\n== Folder names (modified UTF-7)\n";
ok('ASCII names pass through', imapFolderEncode('INBOX.Support') === 'INBOX.Support');
ok('Übergabe encodes', imapFolderEncode('Support/Übergabe') === 'Support/&ANw-bergabe', imapFolderEncode('Support/Übergabe'));
ok('and decodes back', imapFolderDecode('Support/&ANw-bergabe') === 'Support/Übergabe', imapFolderDecode('Support/&ANw-bergabe'));
ok('a literal & round-trips', imapFolderDecode(imapFolderEncode('R&D')) === 'R&D', imapFolderEncode('R&D'));

echo "\n== Encoded headers (RFC 2047)\n";
ok('B (base64) UTF-8', imapDecodeMimeHeader('=?UTF-8?B?w5xiZXJzaWNodA==?=') === 'Übersicht');
ok('Q with underscores as spaces', imapDecodeMimeHeader('=?UTF-8?Q?Printer_on_floor_3?=') === 'Printer on floor 3');
ok('ISO-8859-1 converts to UTF-8', imapDecodeMimeHeader('=?ISO-8859-1?Q?caf=E9?=') === 'café');
ok('Windows-1252 curly quote and euro', imapDecodeMimeHeader('=?windows-1252?Q?=93=80100=94?=') === '“€100”');
ok('space BETWEEN two encoded words is dropped', imapDecodeMimeHeader('=?UTF-8?Q?Hel?= =?UTF-8?Q?lo?=') === 'Hello');
ok('plain text around an encoded word is kept', imapDecodeMimeHeader('Re: =?UTF-8?B?w5xiZXI=?= now') === 'Re: Über now');
ok('nothing encoded: returned as it is', imapDecodeMimeHeader('Password reset') === 'Password reset');
ok('an unknown charset does not throw', is_string(imapDecodeMimeHeader('=?x-made-up?Q?abc?=')));

echo "\n== BODYSTRUCTURE\n";
// A one-part plain text message.
[$bs] = imapParseValues('("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "QUOTED-PRINTABLE" 120 4 NIL NIL NIL NIL)');
$p = imapBodyStructure($bs);
ok('single part: text/plain, charset, encoding', $p['type'] === 'text' && $p['subtype'] === 'plain' && $p['params']['charset'] === 'utf-8' && $p['encoding'] === 'quoted-printable' && $p['parts'] === [], $p);

// multipart/mixed { multipart/alternative { plain, html }, pdf attachment }.
$raw = '((("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 20 1 NIL NIL NIL NIL)'
     . '("TEXT" "HTML" ("CHARSET" "utf-8") NIL NIL "7BIT" 40 1 NIL NIL NIL NIL) "ALTERNATIVE" ("BOUNDARY" "b2") NIL NIL NIL)'
     . '("APPLICATION" "PDF" ("NAME" "report.pdf") NIL NIL "BASE64" 9000 NIL ("ATTACHMENT" ("FILENAME" "report.pdf")) NIL NIL) "MIXED" ("BOUNDARY" "b1") NIL NIL NIL)';
[$bs] = imapParseValues($raw);
$p = imapBodyStructure($bs);
ok('nested multipart: mixed > alternative > plain, html', $p['type'] === 'multipart' && $p['subtype'] === 'mixed' && count($p['parts']) === 2
    && $p['parts'][0]['subtype'] === 'alternative' && $p['parts'][0]['parts'][1]['subtype'] === 'html', $p);
$pdf = $p['parts'][1];
ok('attachment: type, base64, disposition and filename', $pdf['type'] === 'application' && $pdf['encoding'] === 'base64' && $pdf['disposition'] === 'attachment' && imapPartFilename($pdf) === 'report.pdf', $pdf);

// An inline image with a Content-ID.
[$bs] = imapParseValues('("IMAGE" "PNG" ("NAME" "logo.png") "<logo@freeitsm>" NIL "BASE64" 500 NIL ("INLINE" ("FILENAME" "logo.png")) NIL NIL)');
$p = imapBodyStructure($bs);
ok('inline image: content id and inline disposition', $p['id'] === '<logo@freeitsm>' && $p['disposition'] === 'inline', $p);

// An attached email: message/rfc822 has envelope, body and lines BEFORE its disposition.
[$bs] = imapParseValues('("MESSAGE" "RFC822" NIL NIL NIL "7BIT" 800 ("Mon, 1 Jan 2026 10:00:00 +0000" "Fwd" NIL NIL NIL NIL NIL NIL NIL NIL) ("TEXT" "PLAIN" NIL NIL NIL "7BIT" 10 1 NIL NIL NIL NIL) 20 NIL ("ATTACHMENT" ("FILENAME" "original.eml")) NIL NIL)');
$p = imapBodyStructure($bs);
ok('message/rfc822: disposition found after envelope, body and lines', $p['type'] === 'message' && $p['disposition'] === 'attachment' && imapPartFilename($p) === 'original.eml', $p);

// A part with no extension data at all (some servers omit it).
[$bs] = imapParseValues('("TEXT" "HTML" ("CHARSET" "iso-8859-1") NIL NIL "8BIT" 30 2)');
$p = imapBodyStructure($bs);
ok('missing extension data: no disposition, no error', $p['disposition'] === null && $p['params']['charset'] === 'iso-8859-1', $p);

echo "\n== Filenames\n";
ok('RFC 2231: filename* with charset and %XX', imapPartFilename(['dparams' => ['filename*' => "utf-8''%C3%9Cbersicht.pdf"], 'params' => []]) === 'Übersicht.pdf');
ok('RFC 2231: continuations filename*0* + filename*1*', imapPartFilename(['dparams' => ['filename*0*' => "utf-8''Quarterly%20", 'filename*1*' => 'r%C3%A9sum%C3%A9.docx'], 'params' => []]) === 'Quarterly résumé.docx');
ok('RFC 2047 inside a quoted filename', imapPartFilename(['dparams' => ['filename' => '=?UTF-8?B?w5xiZXJzaWNodC5wZGY=?='], 'params' => []]) === 'Übersicht.pdf');
ok('both forms sent: the RFC 2231 name WINS over the ASCII fallback', imapPartFilename(['dparams' => ['filename' => 'Bericht f__r Sie.pdf', 'filename*' => "UTF-8''Bericht%20f%C3%BCr%20Sie.pdf"], 'params' => []]) === 'Bericht für Sie.pdf');
ok('falls back to the type\'s name parameter', imapPartFilename(['dparams' => [], 'params' => ['name' => 'scan.jpg']]) === 'scan.jpg');
ok('no name at all is an empty string', imapPartFilename(['dparams' => [], 'params' => []]) === '');

echo "\n== ENVELOPE\n";
[$env] = imapParseValues('("Tue, 3 Nov 2026 09:15:00 +0000" "=?UTF-8?Q?Caf=C3=A9_printer?=" (("=?UTF-8?B?SsO2cmc=?=" NIL "jorg" "example.com")) NIL NIL '
    . '((NIL NIL "support" "freeitsm.test") (NIL NIL "undisclosed" NIL) (NIL NIL NIL NIL)) (("Ann" NIL "ann" "example.com")) NIL NIL "<abc123@example.com>")');
$e = imapEnvelope($env);
ok('subject decoded', $e['subject'] === 'Café printer', $e['subject']);
ok('sender name decoded, address joined', $e['from'] === [['emailAddress' => ['name' => 'Jörg', 'address' => 'jorg@example.com']]], $e['from']);
ok('a group marker is skipped, real addresses kept', $e['to'] === [['emailAddress' => ['name' => '', 'address' => 'support@freeitsm.test']]], $e['to']);
ok('cc and message id', $e['cc'][0]['emailAddress']['address'] === 'ann@example.com' && $e['message_id'] === '<abc123@example.com>', $e);
$e = imapEnvelope(null);
ok('a missing envelope gives empty fields, not an error', $e['subject'] === '' && $e['from'] === [], $e);

echo "\n== Transfer encodings\n";
ok('base64', imapDecodeTransfer("SGVsbG8g\r\nd29ybGQ=", 'base64') === 'Hello world');
ok('quoted-printable with a soft line break', imapDecodeTransfer("caf=C3=A9 =\r\nau lait", 'QUOTED-PRINTABLE') === 'café au lait');
ok('7bit is untouched', imapDecodeTransfer('plain', '7bit') === 'plain');
ok('broken base64 gives a string, not false', is_string(imapDecodeTransfer('***', 'base64')));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
