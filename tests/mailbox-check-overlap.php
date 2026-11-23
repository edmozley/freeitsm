<?php
/* 🔴 NEVER OVER THE WEB - see tests/README.md. This one writes to the real
   tables (a throwaway mailbox and email, both named ZZMB and removed at the
   end, including on failure; no ticket is created, so no ticket id is used up) and calls the real endpoint over HTTP through
   the local web server. The mailbox points at a closed local port, so no real
   mailbox is ever read. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #183: overlapping mailbox checks.
 *
 *  - while another check of a mailbox holds its lock, a check stands down;
 *  - an automatic poll stands down when the mailbox was checked moments ago,
 *    and a manual check does not;
 *  - a stand-down leaves the mailbox's last-checked time alone;
 *  - an email that is already in the database comes back as "already
 *    imported", with the ticket it went to - never as a success with no ticket.
 *
 * Run:  php tests/mailbox-check-overlap.php [base-url]
 *       base-url defaults to http://localhost/freeitsm-app/
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'includes/functions.php';

$base = rtrim($argv[1] ?? 'http://localhost/freeitsm-app/', '/') . '/';
$pass = 0; $fail = 0;
function is_(string $what, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %-64s\n", $what); }
    else { $fail++; printf("  FAIL  %-64s got %s, wanted %s\n", $what, json_encode($got), json_encode($want)); }
}
function check(string $base, string $sid, int $mailboxId, bool $auto): array {
    $ch = curl_init($base . 'api/tickets/check_mailbox_email.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Cookie: PHPSESSID=' . $sid, 'X-CSRF-Token: zzmb-token', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['mailbox_id' => $mailboxId, 'auto' => $auto]),
    ]);
    $out = (string)curl_exec($ch);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['raw' => substr($out, 0, 300)];
}

$conn = connectToDatabase();
echo "\nMailbox checks that overlap (GH #183)\n" . str_repeat('=', 78) . "\n";

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$sid = 'zzmb-admin';
$dir = rtrim(preg_replace('/^\d+;/', '', session_save_path() ?: sys_get_temp_dir()), '/\\');
$mailbox = null; $email = null;
// The ticket the stored email points at. emails.ticket_id has no foreign key
// and the de-dup only reads it back, so an id no ticket will ever have does.
$ticket = 2147480001;

try {
    file_put_contents("$dir/sess_$sid", 'analyst_id|' . serialize($admin) . 'analyst_name|' . serialize('ZZMB')
        . 'username|' . serialize('admin') . 'is_admin|' . serialize(1) . 'csrf_token|' . serialize('zzmb-token'));

    // A basic IMAP mailbox on a closed local port: a real check fails at once.
    $conn->prepare("INSERT INTO target_mailboxes (name, provider, azure_tenant_id, azure_client_id, azure_client_secret,
                        oauth_redirect_uri, imap_server, imap_port, target_mailbox, is_active, last_checked_datetime)
                    VALUES ('ZZMB overlap', 'imap', '', '', '', '', '127.0.0.1', 1, 'zzmb@zzmb.invalid', 1, NULL)")->execute();
    $mailbox = (int)$conn->lastInsertId();
    $lastChecked = fn() => $conn->query("SELECT last_checked_datetime FROM target_mailboxes WHERE id = $mailbox")->fetchColumn();

    echo "\nOne check at a time:\n";
    $lock = $conn->prepare("SELECT GET_LOCK(CONCAT('freeitsm_mbx_', LEFT(SHA1(DATABASE()), 12), '_', ?), 0)");
    $lock->execute([$mailbox]);
    is_('the test holds the mailbox\'s lock', (int)$lock->fetchColumn(), 1);
    $r = check($base, $sid, $mailbox, false);
    is_('a check while another runs stands down', [$r['success'] ?? null, $r['skipped'] ?? null], [true, 'busy']);
    is_('...saves nothing', $r['details']['emails_saved'] ?? null, 0);
    is_('...and leaves last-checked alone', $lastChecked(), null);
    $conn->prepare("SELECT RELEASE_LOCK(CONCAT('freeitsm_mbx_', LEFT(SHA1(DATABASE()), 12), '_', ?))")->execute([$mailbox]);

    echo "\nAutomatic polls share one check a minute:\n";
    $conn->exec("UPDATE target_mailboxes SET last_checked_datetime = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 SECOND) WHERE id = $mailbox");
    $before = $lastChecked();
    $r = check($base, $sid, $mailbox, true);
    is_('an automatic poll 10s after a check stands down', [$r['success'] ?? null, $r['skipped'] ?? null], [true, 'recent']);
    is_('...and leaves last-checked alone', $lastChecked(), $before);
    $r = check($base, $sid, $mailbox, false);
    is_('a manual check 10s after a check still runs', isset($r['skipped']), false);
    is_('...and records that it ran', $lastChecked() !== $before, true);
    $conn->exec("UPDATE target_mailboxes SET last_checked_datetime = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 70 SECOND) WHERE id = $mailbox");
    $r = check($base, $sid, $mailbox, true);
    is_('an automatic poll 70s after a check runs', isset($r['skipped']), false);

    echo "\nAn email that is already in the database:\n";
    // saveEmailToDatabase() lives in the endpoint, after code that answers the
    // request, so its definition is lifted out by token rather than including
    // the file. Only its first lines run here: the email is found and it returns.
    $tokens = token_get_all(file_get_contents('api/tickets/check_mailbox_email.php'));
    $src = ''; $depth = 0; $on = false;
    foreach ($tokens as $i => $t) {
        $text = is_array($t) ? $t[1] : $t;
        if (!$on && is_array($t) && $t[0] === T_FUNCTION) {
            for ($j = $i + 1; is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
            if (is_array($tokens[$j]) && $tokens[$j][1] === 'saveEmailToDatabase') $on = true;
        }
        if (!$on) continue;
        $src .= $text;
        if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
        if ($text === '}') { $depth--; if ($depth === 0) break; }
    }
    is_('the import function was found', str_starts_with($src, 'function saveEmailToDatabase'), true);
    eval($src);

    $conn->prepare("INSERT INTO emails (exchange_message_id, subject, from_address, ticket_id, is_initial, direction, mailbox_id, processed_datetime)
                    VALUES ('<zzmb-overlap@zzmb.invalid>', 'ZZMB overlap', 'zzmb@zzmb.invalid', ?, 1, 'Inbound', ?, UTC_TIMESTAMP())")->execute([$ticket, $mailbox]);
    $email = (int)$conn->lastInsertId();
    $emailsBefore = (int)$conn->query("SELECT COUNT(*) FROM emails")->fetchColumn();
    $r = saveEmailToDatabase($conn, ['internet_message_id' => '<zzmb-overlap@zzmb.invalid>', 'id' => '1', 'subject' => 'ZZMB overlap'], 'imap-session', $mailbox);
    is_('it comes back as already imported, not false', $r['already_imported'] ?? null, true);
    is_('...naming the ticket it went to', $r['ticket_id'] ?? null, $ticket);
    is_('...and not as a new ticket', $r['is_new_ticket'] ?? null, false);
    is_('...and nothing new was saved', (int)$conn->query("SELECT COUNT(*) FROM emails")->fetchColumn(), $emailsBefore);
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected: " . $e->getMessage() . "\n";
} finally {
    if ($email) $conn->exec("DELETE FROM emails WHERE id = $email");
    if ($mailbox) {
        $conn->exec("DELETE FROM mailbox_activity_log WHERE mailbox_id = $mailbox");
        $conn->exec("DELETE FROM target_mailboxes WHERE id = $mailbox");
    }
    @unlink("$dir/sess_$sid");
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM target_mailboxes WHERE name LIKE 'ZZMB %')
                                    + (SELECT COUNT(*) FROM emails WHERE exchange_message_id = '<zzmb-overlap@zzmb.invalid>')")->fetchColumn();
    is_('cleanup: nothing named ZZMB is left', $left, 0);
}

echo "\n" . str_repeat('-', 78) . "\n  $pass passed, $fail failed\n\n";
exit($fail ? 1 : 0);
