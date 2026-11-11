<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A reply without a ticket number joins the ticket its conversation belongs to
 * (GH #120) - when Tickets -> Settings -> General -> "A reply without a ticket
 * number" says so; otherwise it starts a new ticket, as always.
 *
 * Part 1, no database: the Basic IMAP thread key from References / In-Reply-To /
 * Message-ID, and reading those headers from a FETCH (folded lines, any case).
 *
 * Part 2, FREEITSM_TEST_DATABASE (the throwaway database config.php uses, after
 * Database Verify): emailThreadTicket() - same conversation, another mailbox, an
 * email stored before thread keys, In-Reply-To, a merged ticket - and the REAL
 * importer, saveEmailToDatabase(), lifted out of the endpoint by token (as
 * tests/mailbox-check-overlap.php does), putting a Graph-shaped reply with no
 * [SDREF] on the existing ticket. A ZZ mailbox and ZZ emails, removed in finally;
 * the reply joins an existing ticket, so no ticket is created.
 *
 *   php tests/email-thread-replies.php
 *   FREEITSM_TEST_DATABASE=<the throwaway db> php tests/email-thread-replies.php
 *
 * @wiki Mail-Collection-Developer-Guide
 */
chdir(__DIR__ . '/..');
require_once 'includes/email_threading.php';
require_once 'includes/mailbox_imap.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

echo "Basic IMAP thread keys\n";
check('a reply: the first Message-ID in References (the conversation root)',
    emailImapThreadKey('<root@a.example> <mid@b.example>', '<mid@b.example>', '<new@c.example>') === '<root@a.example>');
check('no References: In-Reply-To', emailImapThreadKey('', '<orig@a.example>', '<new@c.example>') === '<orig@a.example>');
check('a first email: its own Message-ID, so a reply finds it', emailImapThreadKey('', '', '<orig@a.example>') === '<orig@a.example>');
check('nothing usable: no key', emailImapThreadKey('', '', '') === null);
[$r, $i] = imapReplyHeaders(['UID' => '5', 'body[HEADER.FIELDS (REFERENCES IN-REPLY-TO)]' =>
    "References: <root@a.example>\r\n <mid@b.example>\r\nIn-Reply-To: <mid@b.example>\r\n\r\n"]);
check('headers are read from the FETCH, folded lines joined, whatever the case of the key',
    $r === '<root@a.example> <mid@b.example>' && $i === '<mid@b.example>', json_encode([$r, $i]));
check('no header lines: empty, not an error', imapReplyHeaders(['UID' => '5']) === ['', '']);

$expected = getenv('FREEITSM_TEST_DATABASE');
if ($expected) {
    require_once 'config.php';
    require_once 'includes/functions.php';
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
        echo "  (skipped the database checks: config.php does not use $expected)\n";
    } elseif (!emailThreadingReady($conn)) {
        check('emails.thread_key exists - run Database Verify first', false);
    } else {
        echo "\nMatching a conversation to its ticket\n";
        $tag = 'zz120-' . bin2hex(random_bytes(3));
        $made = ['mailboxes' => [], 'emails' => []];
        $oldSetting = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_thread_replies'")->fetchColumn();
        $merge = null;
        try {
            $mbox = function (string $name) use ($conn, &$made): int {
                $conn->prepare("INSERT INTO target_mailboxes (name, target_mailbox, is_active) VALUES (?, ?, 0)")->execute([$name, "$name@example.invalid"]);
                return $made['mailboxes'][] = (int)$conn->lastInsertId();
            };
            $mA = $mbox("$tag-a");
            $mB = $mbox("$tag-b");
            [$t1, $t2] = array_map('intval', $conn->query("SELECT id FROM tickets WHERE merged_into_id IS NULL ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
            if (!$t1 || !$t2) throw new RuntimeException('Needs two tickets in the database.');
            $email = function (int $mailbox, ?int $ticket, ?string $threadKey, string $messageId) use ($conn, &$made): int {
                $conn->prepare("INSERT INTO emails (exchange_message_id, subject, from_address, ticket_id, is_initial, direction, mailbox_id, thread_key, processed_datetime)
                                VALUES (?, 'ZZ120 original', 'zz120@example.invalid', ?, 1, 'Inbound', ?, ?, UTC_TIMESTAMP())")
                     ->execute([$messageId, $ticket, $mailbox, $threadKey]);
                return $made['emails'][] = (int)$conn->lastInsertId();
            };
            $email($mA, $t1, "$tag-conv", "$tag-graph-id-1");
            check('a reply in the same conversation finds its ticket', emailThreadTicket($conn, $mA, "$tag-conv") === $t1);
            check('...but not from another mailbox', emailThreadTicket($conn, $mB, "$tag-conv") === null);
            check('an unknown conversation finds nothing', emailThreadTicket($conn, $mA, "$tag-other") === null);
            $email($mA, $t1, null, "<$tag-legacy@example.invalid>");
            check('a Basic IMAP reply finds an original stored before thread keys existed, by its Message-ID',
                emailThreadTicket($conn, $mA, "<$tag-legacy@example.invalid>") === $t1);
            check('...or by In-Reply-To', emailThreadTicket($conn, $mA, null, "<$tag-legacy@example.invalid>") === $t1);
            $email($mA, $t2, "$tag-merged", "$tag-graph-id-2");
            $conn->prepare("UPDATE tickets SET merged_into_id = ? WHERE id = ?")->execute([$t1, $t2]);
            $merge = $t2;
            check('a conversation on a ticket since merged goes to the ticket it was merged into', emailThreadTicket($conn, $mA, "$tag-merged") === $t1);
            $conn->prepare("UPDATE tickets SET merged_into_id = NULL WHERE id = ?")->execute([$t2]);
            $merge = null;

            echo "\nThe real importer\n";
            $tokens = token_get_all(file_get_contents('api/tickets/check_mailbox_email.php'));
            $src = ''; $depth = 0; $on = false;
            foreach ($tokens as $k => $t) {
                $text = is_array($t) ? $t[1] : $t;
                if (!$on && is_array($t) && $t[0] === T_FUNCTION) {
                    for ($j = $k + 1; is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
                    if (is_array($tokens[$j]) && $tokens[$j][1] === 'saveEmailToDatabase') $on = true;
                }
                if (!$on) continue;
                $src .= $text;
                if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
                if ($text === '}') { $depth--; if ($depth === 0) break; }
            }
            // The functions it calls on the way to a reply live in the same endpoint; lift those too.
            foreach (['extractTicketReference', 'findTicketByNumber', 'getOrCreateUser', 'stripInboundThread', 'logEmailImport'] as $fn) {
                if (function_exists($fn)) continue;
                $part = ''; $depth = 0; $on = false;
                foreach ($tokens as $k => $t) {
                    $text = is_array($t) ? $t[1] : $t;
                    if (!$on && is_array($t) && $t[0] === T_FUNCTION) {
                        for ($j = $k + 1; is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
                        if (is_array($tokens[$j]) && $tokens[$j][1] === $fn) $on = true;
                    }
                    if (!$on) continue;
                    $part .= $text;
                    if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
                    if ($text === '}') { $depth--; if ($depth === 0) break; }
                }
                eval($part);
            }
            require_once 'includes/ticket_numbering.php';
            require_once 'includes/ticket_reply.php';
            require_once 'includes/ticket_snooze.php';
            require_once 'includes/uploads.php';
            check('the import function was found', str_starts_with($src, 'function saveEmailToDatabase'));
            // In eval'd code __DIR__ is this test's folder; the endpoint's paths are relative to its own.
            eval(str_replace('__DIR__', var_export(realpath('api/tickets'), true), $src));

            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('ticket_thread_replies', 'same_ticket') ON DUPLICATE KEY UPDATE setting_value = 'same_ticket'")->execute();
            $r = saveEmailToDatabase($conn, [
                'id' => "$tag-graph-id-3", 'conversationId' => "$tag-conv", 'subject' => 'Re: forgot the attachment',
                'from' => ['emailAddress' => ['address' => 'zz120@example.invalid', 'name' => 'ZZ120']],
                'body' => ['contentType' => 'html', 'content' => '<p>Here it is.</p>'], 'hasAttachments' => false,
            ], 'graph-token-not-used', $mA);
            $saved = $conn->prepare("SELECT id, ticket_id, thread_key, is_initial FROM emails WHERE exchange_message_id = ?");
            $saved->execute(["$tag-graph-id-3"]);
            $row = $saved->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($row['id'])) $made['emails'][] = (int)$row['id'];
            check('a Graph reply with no [SDREF] joins the ticket its conversation belongs to',
                ($r['ticket_id'] ?? null) === $t1 && empty($r['is_new_ticket']) && (int)($row['ticket_id'] ?? 0) === $t1, json_encode([$r, $row]));
            check('...stored with its thread key, as a reply (not an opening message)',
                ($row['thread_key'] ?? null) === "$tag-conv" && (int)($row['is_initial'] ?? 1) === 0, json_encode($row));
        } catch (Throwable $e) {
            check('the database checks ran: ' . $e->getMessage(), false);
        } finally {
            if ($merge) $conn->prepare("UPDATE tickets SET merged_into_id = NULL WHERE id = ?")->execute([$merge]);
            if ($oldSetting === false) $conn->exec("DELETE FROM system_settings WHERE setting_key = 'ticket_thread_replies'");
            else $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'ticket_thread_replies'")->execute([$oldSetting]);
            // By mailbox, so a row the importer saved before a failure goes too.
            foreach ($made['mailboxes'] as $id) {
                $conn->prepare("DELETE FROM emails WHERE mailbox_id = ?")->execute([$id]);
                $conn->prepare("DELETE FROM system_logs WHERE log_type = 'email_import' AND JSON_EXTRACT(details, '$.mailbox_id') = ?")->execute([$id]);
                $conn->prepare("DELETE FROM target_mailboxes WHERE id = ?")->execute([$id]);
            }
        }
    }
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
