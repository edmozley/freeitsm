<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #369: an upgrade not followed by Database Verification.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/db-behind-notice.php
 *
 * The reporter's install had no ticket_watchers table (added in 5.1.0), so every
 * analyst in a team got an empty inbox and a raw SQL error - ticketScopeFilter()
 * reads that table - while administrators, who skip the filter, saw nothing wrong.
 *
 * On the throwaway database this hides ticket_watchers (RENAME, put back in
 * finally) and calls the real inbox endpoints as an existing non-admin team member:
 * the error must now say what to do, not SQLSTATE. CONTROL: with the table back
 * the same calls succeed. Then the administrator's notice: shown when the recorded
 * verified version differs from the code's, not to a non-admin, gone once
 * Database Verification has run (which must record the version). The setting is
 * put back as it was.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_verify_status.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$sessDir = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\");
$sessions = []; $tokens = [];
$as = function (int $id, bool $admin) use ($sessDir, &$sessions, &$tokens): string {
    $sid = 'zz369' . bin2hex(random_bytes(8));
    $tokens[$sid] = bin2hex(random_bytes(32));
    file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $id . ';analyst_username|s:5:"zz369";analyst_name|s:5:"ZZ369";is_admin|i:' . ($admin ? 1 : 0)
        . ';csrf_token|s:64:"' . $tokens[$sid] . '";');
    return $sessions[] = $sid;
};
$get = function (string $sid, string $path, string $method = 'GET') use ($base, &$tokens): array {
    $ch = curl_init("$base/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_CUSTOMREQUEST => $method,
                            CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $tokens[$sid]]]);
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $raw, json_decode($raw, true)];
};

$adminId = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$memberId = (int)$conn->query("SELECT a.id FROM analysts a JOIN analyst_teams t ON t.analyst_id = a.id
                                WHERE a.is_active = 1 AND a.is_admin = 0 ORDER BY a.id LIMIT 1")->fetchColumn();
if (!$adminId || !$memberId) { echo "(skipped: needs an active administrator and an active non-admin analyst in a team)\n"; exit(0); }
$admin = $as($adminId, true);
$member = $as($memberId, false);

$keySel = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
$keySel->execute([DB_VERIFIED_VERSION_KEY]);
$savedVersion = $keySel->fetchColumn();
$setVersion = function (?string $v) use ($conn): void {
    if ($v === null) { $conn->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([DB_VERIFIED_VERSION_KEY]); return; }
    $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
         ->execute([DB_VERIFIED_VERSION_KEY, $v]);
};
$hidden = false;

try {
    echo "Recognising the error\n";
    $mk = function (string $state) { $e = new PDOException("SQLSTATE[$state]: x"); $e->errorInfo = [$state, 0, 'x']; return $e; };
    check('a missing table (42S02) is the database being behind', dbErrorIsSchemaBehind($mk('42S02')));
    check('a missing column (42S22) is too', dbErrorIsSchemaBehind($mk('42S22')));
    check('any other error is not, and keeps its own message', !dbErrorIsSchemaBehind($mk('23000')) && dbErrorForPeople($mk('23000')) === 'SQLSTATE[23000]: x');

    echo "The inbox with ticket_watchers missing (the reporter's install)\n";
    $conn->exec('RENAME TABLE ticket_watchers TO zz369_ticket_watchers');
    $hidden = true;
    [, $raw, $j] = $get($member, 'api/tickets/get_emails.php?');
    check('the list fails for a team member, as reported', ($j['success'] ?? null) === false, $raw);
    check('and says to run Database Verification, not SQL', str_contains((string)($j['error'] ?? ''), 'Database Verification') && !str_contains((string)($j['error'] ?? ''), 'SQLSTATE'), (string)($j['error'] ?? $raw));
    [, $raw, $j] = $get($member, 'api/tickets/get_ticket_counts.php');
    check('the folder counts say the same', ($j['success'] ?? null) === false && str_contains((string)($j['error'] ?? ''), 'Database Verification'), mb_strimwidth($raw, 0, 200));
    [, $raw, $j] = $get($admin, 'api/tickets/get_emails.php?');
    check('an administrator still lists tickets (skips the filter - why it went unnoticed)', ($j['success'] ?? null) === true, mb_strimwidth($raw, 0, 200));
    $conn->exec('RENAME TABLE zz369_ticket_watchers TO ticket_watchers');
    $hidden = false;
    [, $raw, $j] = $get($member, 'api/tickets/get_emails.php?');
    check('CONTROL: with the table back the team member lists tickets', ($j['success'] ?? null) === true, mb_strimwidth($raw, 0, 200));

    echo "The administrator's notice\n";
    $setVersion('0.0.1');
    [, $html] = $get($admin, 'tickets/');
    check('shown to an administrator when the database was verified for another version', str_contains($html, 'id="dbBehindNotice"') && str_contains($html, FREEITSM_VERSION));
    [, $html] = $get($member, 'tickets/');
    check('not shown to anyone else', !str_contains($html, 'id="dbBehindNotice"'));
    $setVersion(null);
    [, $html] = $get($admin, 'tickets/');
    check('shown when no run was ever recorded (every install upgraded before this)', str_contains($html, 'id="dbBehindNotice"'));
    [$code, $raw, $j] = $get($admin, 'api/system/db_verify.php', 'POST');
    check('Database Verification runs', $code === 200 && ($j['success'] ?? null) === true, mb_strimwidth($raw, 0, 200));
    $keySel->execute([DB_VERIFIED_VERSION_KEY]);
    check('and records the version it verified', $keySel->fetchColumn() === FREEITSM_VERSION);
    [, $html] = $get($admin, 'tickets/');
    check('after which the notice is gone', $html !== '' && !str_contains($html, 'id="dbBehindNotice"'));
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($hidden) { try { $conn->exec('RENAME TABLE zz369_ticket_watchers TO ticket_watchers'); } catch (Throwable) {} }
    $setVersion($savedVersion === false ? null : (string)$savedVersion);
    foreach ($sessions as $s) @unlink("$sessDir/sess_$s");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
