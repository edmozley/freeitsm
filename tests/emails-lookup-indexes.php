<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The two lookups every install makes on `emails` all day are indexed (GH #280):
 *
 *   - the duplicate check on every imported message, by Message-ID
 *     (api/tickets/check_mailbox_email.php: WHERE exchange_message_id = ?)
 *   - every conversation, read by ticket (WHERE ticket_id = ?)
 *
 * Neither had an index, so both scanned the whole table and got slower as ticket
 * history grew. Static checks always; with FREEITSM_TEST_DATABASE set to the
 * database config.php uses, EXPLAIN shows MySQL using them (run Database Verify
 * first on an existing install).
 *
 * @wiki Mail-Collection-Developer-Guide
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$sql = (string) file_get_contents($root . '/database/freeitsm.sql');
$a = strpos($sql, 'CREATE TABLE IF NOT EXISTS `emails` (');
$table = $a === false ? '' : substr($sql, $a, strpos($sql, ') ENGINE=', $a) - $a);
check('freeitsm.sql indexes emails.exchange_message_id', str_contains($table, 'KEY `idx_emails_exchange_message_id` (`exchange_message_id`)'));
check('freeitsm.sql indexes emails.ticket_id', str_contains($table, 'KEY `idx_emails_ticket` (`ticket_id`)'));

$verify = (string) file_get_contents($root . '/includes/db_verify_indexes.php');
check('Database Verify adds the Message-ID index to existing installs', str_contains($verify, "['emails', 'idx_emails_exchange_message_id', 'key', '(`exchange_message_id`)']"));
check('Database Verify adds the ticket index to existing installs', str_contains($verify, "['emails', 'idx_emails_ticket', 'key', '(`ticket_id`)']"));

$expected = getenv('FREEITSM_TEST_DATABASE');
if ($expected) {
    require_once $root . '/config.php';
    require_once $root . '/includes/functions.php';
    $conn = connectToDatabase();
    if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
        echo "  (skipped the EXPLAIN checks: config.php does not use $expected)\n";
    } else {
        $plan = function (string $q) use ($conn): array {
            return $conn->query('EXPLAIN ' . $q)->fetch(PDO::FETCH_ASSOC) ?: [];
        };
        $p = $plan("SELECT id, ticket_id FROM emails WHERE exchange_message_id = 'zz-280' LIMIT 1");
        check('the duplicate check uses the index, not a full scan', ($p['key'] ?? null) === 'idx_emails_exchange_message_id', json_encode(['type' => $p['type'] ?? null, 'key' => $p['key'] ?? null]));
        $p = $plan("SELECT id FROM emails WHERE ticket_id = 0");
        check('reading a conversation uses the index, not a full scan', ($p['key'] ?? null) === 'idx_emails_ticket', json_encode(['type' => $p['type'] ?? null, 'key' => $p['key'] ?? null]));
    }
}

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
