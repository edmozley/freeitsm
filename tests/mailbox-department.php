<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Mailboxes: a department per mailbox (GH #315).
 *
 *   - a mailbox with no department behaves exactly as before (the company's default);
 *   - with one, a new emailed ticket starts in it (ticketDepartmentForMailbox(), which
 *     check_mailbox_email.php uses);
 *   - a department switched off since falls back to the default, with a note;
 *   - the real save endpoint stores and returns it, refuses an inactive department
 *     newly chosen, and leaving the field out keeps it;
 *   - deleting the department returns the mailbox to the default (ON DELETE SET NULL).
 *
 * Creates an IMAP mailbox and two departments named ZZMB, removes them in `finally`.
 *
 * Run: FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/mailbox-department.php
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/tenancy.php';
require_once 'includes/tenant_settings.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
if (!$conn->query("SHOW COLUMNS FROM target_mailboxes LIKE 'department_id'")->fetch()) { echo "(skipped: run Database Verification)\n"; exit(1); }

$sessDir = rtrim(ini_get('session.save_path') ?: 'C:/wamp64/tmp', '/\\');
$adminId = 0; $mailboxId = 0; $sessFile = '';
try {
    $conn->exec("INSERT INTO departments (name, is_active, display_order) VALUES ('ZZMB HR', 1, 995), ('ZZMB Old', 0, 996)");
    $hr  = (int)$conn->query("SELECT id FROM departments WHERE name = 'ZZMB HR'")->fetchColumn();
    $old = (int)$conn->query("SELECT id FROM departments WHERE name = 'ZZMB Old'")->fetchColumn();
    $conn->exec("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES ('zzmbadmin', '!', 'ZZMB admin', 'zzmbadmin@example.invalid', 1, 1, 1, 1)");
    $adminId = (int)$conn->lastInsertId();
    $sid = 'zzmb' . bin2hex(random_bytes(6));
    $sessFile = "$sessDir/sess_$sid";
    file_put_contents($sessFile, 'analyst_id|i:' . $adminId . ';analyst_username|s:9:"zzmbadmin";analyst_name|s:10:"ZZMB admin";csrf_token|s:1:"x";');
    $post = function (string $path, array $body) use ($base, $sid) {
        $ch = curl_init("$base/$path");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x']]);
        return json_decode((string)curl_exec($ch), true) ?: [];
    };
    $mailbox = function () use ($base, $sid, &$mailboxId) {
        $ch = curl_init("$base/api/tickets/get_mailboxes.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid"]);
        $all = json_decode((string)curl_exec($ch), true)['mailboxes'] ?? [];
        foreach ($all as $m) if ((int)$m['id'] === $mailboxId) return $m;
        return null;
    };
    $fields = ['provider' => 'imap', 'name' => 'ZZMB mailbox', 'target_mailbox' => 'zzmb@example.invalid', 'imap_server' => 'imap.example.invalid',
               'imap_username' => 'zzmb', 'imap_password' => 'zzmb-not-real', 'azure_client_id' => '', 'azure_tenant_id' => '', 'oauth_redirect_uri' => '', 'smtp_server' => 'smtp.example.invalid', 'is_active' => 0];

    echo "Saving through the real endpoint\n";
    $r = $post('api/tickets/save_mailbox.php', $fields);
    $mailboxId = (int)($r['id'] ?? 0);
    check('a new mailbox with no department is saved', $mailboxId > 0, json_encode($r));
    $m0 = $mailbox();
    check('...and has none', is_array($m0) && array_key_exists('department_id', $m0) && $m0['department_id'] === null, json_encode($m0['department_id'] ?? 'missing'));
    $r = $post('api/tickets/save_mailbox.php', $fields + ['id' => $mailboxId, 'department_id' => $hr]);
    check('a department is stored and returned', ($r['success'] ?? false) && ($mailbox()['department_id'] ?? null) === $hr, json_encode($r));
    $r = $post('api/tickets/save_mailbox.php', $fields + ['id' => $mailboxId]);
    check('saving without the field keeps it', ($mailbox()['department_id'] ?? null) === $hr);
    $r = $post('api/tickets/save_mailbox.php', $fields + ['id' => $mailboxId, 'department_id' => $old]);
    check('an inactive department cannot be newly chosen', ($r['success'] ?? true) === false && ($mailbox()['department_id'] ?? null) === $hr, json_encode($r));

    echo "Where a new emailed ticket starts\n";
    $tenant = (int)getDefaultTenantId($conn);
    [$dflt] = ticketDepartmentForNewTicket($conn, $tenant, null);
    [$d] = ticketDepartmentForMailbox($conn, $mailboxId, $tenant);
    check('in the mailbox\'s department', $d === $hr, var_export($d, true));
    $conn->exec("UPDATE target_mailboxes SET department_id = NULL WHERE id = $mailboxId");
    [$d, $note] = ticketDepartmentForMailbox($conn, $mailboxId, $tenant);
    check('no department: exactly what it was before (the company\'s default)', $d === $dflt && $note === null, var_export([$d, $dflt], true));
    $conn->exec("UPDATE target_mailboxes SET department_id = $old WHERE id = $mailboxId");
    [$d, $note] = ticketDepartmentForMailbox($conn, $mailboxId, $tenant);
    check('a department switched off since: the default, with a note for the history', $d === $dflt && str_contains((string)$note, 'ZZMB Old'), var_export([$d, $note], true));
    [$d] = ticketDepartmentForMailbox($conn, null, $tenant);
    check('no mailbox at all: the default', $d === $dflt);

    echo "Deleting the department\n";
    $conn->exec("UPDATE target_mailboxes SET department_id = $hr WHERE id = $mailboxId");
    $conn->exec("DELETE FROM departments WHERE id = $hr");
    check('the mailbox returns to the default', $conn->query("SELECT department_id FROM target_mailboxes WHERE id = $mailboxId")->fetchColumn() === null);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($mailboxId) $conn->exec("DELETE FROM target_mailboxes WHERE id = $mailboxId");
    $conn->exec("DELETE FROM departments WHERE name LIKE 'ZZMB %'");
    if ($adminId) { $conn->exec("DELETE FROM user_preferences WHERE analyst_id = $adminId"); $conn->exec("DELETE FROM analysts WHERE id = $adminId"); }
    if ($sessFile) @unlink($sessFile);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM target_mailboxes WHERE name = 'ZZMB mailbox') + (SELECT COUNT(*) FROM departments WHERE name LIKE 'ZZMB %') + (SELECT COUNT(*) FROM analysts WHERE username = 'zzmbadmin')")->fetchColumn();
    check('cleanup: nothing named ZZMB is left', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
