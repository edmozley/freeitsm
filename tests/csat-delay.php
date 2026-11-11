<?php
/* 🔴 NEVER OVER THE WEB - it writes to the database. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * CSAT "Delay before sending" (GitHub #301): a close with a delay queues the
 * survey instead of sending it; the csat_delayed job sends it once due - only if
 * the ticket is still closed - and a second close moves the due time.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/csat-delay.php
 *
 * 🔴 It never sends real mail: every csat_request email template is switched off
 * for the run, so sendCsatSurvey() stops at its "no template" pre-flight - which
 * proves the job reached the send without anything leaving the building. The
 * templates, settings, ticket status and queue are all put back in `finally`.
 */

chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE to a throwaway database)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/csat.php';
require_once 'includes/job_registry.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
exec(escapeshellarg(PHP_BINARY) . ' scripts/db_verify_cli.php --apply 2>&1', $o, $code);
if ($code !== 0) { check('Database Verify ran', false); exit(1); }

/** csatGetSetting() caches per request; a test that changes settings must read them fresh. */
function setSetting(PDO $conn, string $k, ?string $v): void {
    if ($v === null) { $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]); return; }
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
}
$getSetting = function (string $k) use ($conn) { $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? null : $v; };

$ticketId = (int)$conn->query("SELECT id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$closed = (int)$conn->query("SELECT id FROM ticket_statuses WHERE is_closed = 1 ORDER BY id LIMIT 1")->fetchColumn();
$open = (int)$conn->query("SELECT id FROM ticket_statuses WHERE is_closed = 0 ORDER BY id LIMIT 1")->fetchColumn();
$saved = ['mode' => $getSetting('csat_mode'), 'delay' => $getSetting('csat_delay_minutes')];
$oldStatus = $conn->query("SELECT status_id FROM tickets WHERE id = $ticketId")->fetchColumn();
$activeTpl = $conn->query("SELECT id FROM ticket_email_templates WHERE event_trigger = 'csat_request' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
$respMark = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM ticket_csat_responses")->fetchColumn();
$queueBefore = $conn->query("SELECT ticket_id FROM csat_queue")->fetchAll(PDO::FETCH_COLUMN);
$runCsat = function () use (&$conn): array {
    // A fresh process each time, as the scheduler runs it - and so csatGetSetting()'s cache is fresh too.
    exec(escapeshellarg(PHP_BINARY) . ' cron/csat_delayed.php 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

try {
    if (!$ticketId || !$closed || !$open) { check('a ticket and open / closed statuses exist', false); exit(1); }
    if ($queueBefore) { check('the queue is empty before the run (it holds real surveys)', false); exit(1); }
    if ($activeTpl) $conn->exec("UPDATE ticket_email_templates SET is_active = 0 WHERE id IN (" . implode(',', array_map('intval', $activeTpl)) . ")");
    $conn->prepare("UPDATE tickets SET status_id = ? WHERE id = ?")->execute([$closed, $ticketId]);

    echo "The close path\n";
    $src = file_get_contents('includes/services/tickets.php');
    check('closing a ticket goes through csatAutoOnClose()', str_contains($src, 'csatAutoOnClose($conn, $ticketId, $actorId)') && !str_contains($src, "=== 'auto') {\n                        sendCsatSurvey"));

    echo "\nWith a delay\n";
    setSetting($conn, 'csat_mode', 'auto'); setSetting($conn, 'csat_delay_minutes', '30');
    csatSettingsForget(); csatAutoOnClose($conn, $ticketId, null);
    $q = $conn->query("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), due_datetime) FROM csat_queue WHERE ticket_id = $ticketId")->fetchColumn();
    check('a close with a 30-minute delay queues the survey, due in about 30 minutes', $q !== false && (int)$q >= 29 && (int)$q <= 30);
    check('...and sends nothing yet (no survey row)', (int)$conn->query("SELECT COUNT(*) FROM ticket_csat_responses WHERE id > $respMark")->fetchColumn() === 0);
    [$code, $out] = $runCsat();
    check('the job leaves a survey that is not yet due', (int)$conn->query("SELECT COUNT(*) FROM csat_queue WHERE ticket_id = $ticketId")->fetchColumn() === 1);
    $jobs = jobRegistry();
    check('the job is registered, every 5 minutes, and needed while a delay is set', isset($jobs['csat_delayed']) && $jobs['csat_delayed']['interval'] === 5 && $jobs['csat_delayed']['needed']($conn) === true);

    echo "\nDue\n";
    $conn->exec("UPDATE csat_queue SET due_datetime = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE ticket_id = $ticketId");
    [$code, $out] = $runCsat();
    check('once due, the job takes it off the queue', (int)$conn->query("SELECT COUNT(*) FROM csat_queue WHERE ticket_id = $ticketId")->fetchColumn() === 0);
    check('...and tries to send it (stopped by the switched-off template, so nothing left the building)', str_contains($out, '0 survey(s)') && str_contains($out, '1 not sent'));
    $run = $conn->query("SELECT outcome, counts_json FROM job_runs WHERE job_key = 'csat_delayed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('the run is recorded for Background jobs', $run && $run['outcome'] === 'ok');

    echo "\nReopened before it was due\n";
    $conn->exec("INSERT INTO csat_queue (ticket_id, due_datetime, analyst_id, created_datetime) VALUES ($ticketId, UTC_TIMESTAMP() - INTERVAL 1 MINUTE, NULL, UTC_TIMESTAMP())");
    $conn->prepare("UPDATE tickets SET status_id = ? WHERE id = ?")->execute([$open, $ticketId]);
    [$code, $out] = $runCsat();
    check('a ticket reopened before its survey was due gets no survey', str_contains($out, '1 ticket(s) reopened')
        && (int)$conn->query("SELECT COUNT(*) FROM csat_queue WHERE ticket_id = $ticketId")->fetchColumn() === 0);

    echo "\nClosed twice\n";
    $conn->prepare("UPDATE tickets SET status_id = ? WHERE id = ?")->execute([$closed, $ticketId]);
    $conn->exec("INSERT INTO csat_queue (ticket_id, due_datetime, analyst_id, created_datetime) VALUES ($ticketId, UTC_TIMESTAMP() + INTERVAL 5 MINUTE, NULL, UTC_TIMESTAMP())");
    csatSettingsForget(); csatAutoOnClose($conn, $ticketId, null);
    $rows = $conn->query("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), due_datetime) FROM csat_queue WHERE ticket_id = $ticketId")->fetchAll(PDO::FETCH_COLUMN);
    check('a second close keeps one queued survey and moves it to the new delay', count($rows) === 1 && (int)$rows[0] >= 29);
    $conn->exec("DELETE FROM csat_queue WHERE ticket_id = $ticketId");

    echo "\nNo delay - as before\n";
    setSetting($conn, 'csat_delay_minutes', '0');
    csatSettingsForget(); csatAutoOnClose($conn, $ticketId, null);
    check('with no delay nothing is queued (the survey is sent at once, as it always was)', (int)$conn->query("SELECT COUNT(*) FROM csat_queue")->fetchColumn() === 0);
    $jobs = jobRegistry();
    check('...and the job is not needed, so it is never reported overdue', $jobs['csat_delayed']['needed']($conn) === false);

    echo "\nThe survey link, sent from the job (no web request)\n";
    require_once 'includes/public_url.php';
    $savedPublic = $getSetting('public_base_url');
    setSetting($conn, 'public_base_url', null); publicBaseUrlForget();
    $conn->exec("INSERT INTO csat_queue (ticket_id, due_datetime, analyst_id, link_base, created_datetime) VALUES ($ticketId, UTC_TIMESTAMP() + INTERVAL 30 MINUTE, NULL, NULL, UTC_TIMESTAMP())");
    $_SERVER['HTTP_HOST'] = 'desk.example.test'; publicBaseUrlForget();
    csatSettingsForget(); setSetting($conn, 'csat_delay_minutes', '30'); csatSettingsForget();
    csatAutoOnClose($conn, $ticketId, null);
    unset($_SERVER['HTTP_HOST']); publicBaseUrlForget();
    $base = (string)$conn->query("SELECT link_base FROM csat_queue WHERE ticket_id = $ticketId")->fetchColumn();
    check("closing remembers the site address it was closed from ($base)", str_starts_with($base, 'http://desk.example.test'));
    csatUseLinkBase($conn, $base);
    $url = csatBuildUrl('abc123', $conn);
    check("the job's link uses it, with no public address configured ($url)", $url === $base . '/csat?token=abc123');
    setSetting($conn, 'public_base_url', 'https://help.example.test'); publicBaseUrlForget();
    csatUseLinkBase($conn, $base);
    $url = csatBuildUrl('abc123', $conn);
    check("a configured public address always wins ($url)", str_starts_with($url, 'https://help.example.test') && str_ends_with($url, '/csat?token=abc123'));
    setSetting($conn, 'public_base_url', $savedPublic); publicBaseUrlForget();
} finally {
    if ($activeTpl) $conn->exec("UPDATE ticket_email_templates SET is_active = 1 WHERE id IN (" . implode(',', array_map('intval', $activeTpl)) . ")");
    $conn->prepare("UPDATE tickets SET status_id = ? WHERE id = ?")->execute([$oldStatus, $ticketId]);
    $conn->exec("DELETE FROM csat_queue WHERE ticket_id = $ticketId");
    $conn->exec("DELETE FROM ticket_csat_responses WHERE id > $respMark AND ticket_id = $ticketId");
    setSetting($conn, 'csat_mode', $saved['mode']); setSetting($conn, 'csat_delay_minutes', $saved['delay']);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
