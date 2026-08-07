<?php
/**
 * External issue tracker poll — cron entry point.
 *
 * Refreshes the cached status of every linked issue, so the pill on a ticket
 * says what the tracker actually says. Without this, a link's status is frozen
 * at whatever it was when the issue was raised.
 *
 * ⚠️ THIS IS THE ONLY WAY STATUS EVER CHANGES TODAY. Webhooks are V2. An install
 * that never schedules this will show issues as "To Do" forever, which is why
 * the settings screen should say so and why this file logs loudly when it has
 * nothing to do.
 *
 * Each connection has its own `poll_interval_minutes`, honoured here, so a busy
 * tracker can be checked often and a quiet one rarely without a global setting.
 *
 * SECURITY (HTTP invocation only), mirroring cron/webhook_deliveries.php:
 *   - Shared-secret token via ?token=<value> matching `integration_cron_token`
 *     in system_settings (hash_equals; seeded by Database Verification).
 *   - Min interval between runs (`integration_cron_min_interval_seconds`,
 *     default 60s) for CLI + HTTP, defeating double-scheduling.
 * CLI invocation skips the token (there is no untrusted caller).
 *
 *   php cron/integration_poll.php
 *   curl "https://your-install/cron/integration_poll.php?token=…"
 */

set_time_limit(300);
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';   // decryptValue() for the cron token
require_once __DIR__ . '/../includes/integrations/integrations.php';

$isCli = (PHP_SAPI === 'cli');

try {
    $conn = connectToDatabase();

    // Nothing to do — and NOT an error. An install that has not run Database
    // Verification, or never configured a tracker, must get a calm answer here
    // rather than a stack trace in a cron mailbox every minute.
    if (!integrationsSchemaReady($conn)) {
        echo "Integration tables not present — nothing to poll.\n";
        exit;
    }

    $settings = [];
    $q = $conn->query(
        "SELECT setting_key, setting_value FROM system_settings
         WHERE setting_key IN ('integration_cron_token','integration_cron_min_interval_seconds','integration_cron_last_run')"
    );
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $settings[$r['setting_key']] = $r['setting_value'];
    $minInterval = max(0, (int)($settings['integration_cron_min_interval_seconds'] ?? 60));

    // ---- HTTP token auth ----
    if (!$isCli) {
        // Stored encrypted (isEncryptedSettingKey matches *_token). decryptValue()
        // returns an unencrypted value untouched, so installs that pre-date the
        // change keep authenticating on the same plaintext token.
        $expected = decryptValue($settings['integration_cron_token'] ?? null);
        if (empty($expected)) {
            http_response_code(503);
            echo "Cron token not set. Run Database Verification to seed integration_cron_token.\n";
            exit;
        }
        if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }
    }

    // ---- Min interval between runs (CLI + HTTP) ----
    if ($minInterval > 0 && !empty($settings['integration_cron_last_run'])) {
        $ageStmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP())");
        $ageStmt->execute([$settings['integration_cron_last_run']]);
        $age = (int)$ageStmt->fetchColumn();
        if ($age >= 0 && $age < $minInterval) {
            http_response_code(429);
            echo "Rate limited. Last run {$age}s ago; minimum interval is {$minInterval}s.\n";
            exit;
        }
    }
    $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES ('integration_cron_last_run', UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = UTC_TIMESTAMP()"
    )->execute();

    // ---- Poll each connection that is due ----
    //
    // `last_poll_datetime IS NULL` catches a brand-new connection, which should
    // be checked on the very next run rather than waiting out its interval.
    $due = $conn->query(
        "SELECT * FROM integration_connections
         WHERE is_active = 1
           AND (last_poll_datetime IS NULL
                OR last_poll_datetime < UTC_TIMESTAMP() - INTERVAL poll_interval_minutes MINUTE)
         ORDER BY last_poll_datetime IS NOT NULL, last_poll_datetime"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$due) {
        echo "No connections due.\n";
        exit;
    }

    $totalChecked = 0; $totalChanged = 0; $totalComments = 0; $failed = 0;

    foreach ($due as $row) {
        $connection = integrationsLoadConnection($conn, (int)$row['id']);
        if (!$connection) continue;

        $name = $connection['name'];
        try {
            $res = integrationsRefreshConnection($conn, $connection);
            $totalChecked += $res['checked'];
            $totalChanged += $res['changed'];

            printf("%-28s checked %3d, changed %2d\n", mb_substr($name, 0, 28), $res['checked'], $res['changed']);

            // Category moves are the ones worth reporting — "In Progress" to
            // "In progress" is not news. V2 turns these into tracker.* workflow
            // triggers; for now they are visible in the cron output so an admin
            // can see the poll is doing something.
            foreach ($res['changes'] as $c) {
                printf("    %s  %s -> %s\n",
                    $c['link']['external_key'] ?: $c['link']['external_id'],
                    $c['from'] ?: 'unknown',
                    $c['to']   ?: 'unknown');
            }

            // Comments coming back. Silent unless the connection has inbound
            // switched on, and the FIRST run after switching it on imports
            // nothing by design — it only sets the watermark, so turning this on
            // cannot tip a tracker's whole comment history onto old tickets.
            $com = integrationsPullComments($conn, $connection);
            $totalComments += $com['imported'];
            if ($com['pulled'] > 0 || $com['imported'] > 0) {
                $skipped = '';
                foreach ($com['skipped'] as $reason => $n) $skipped .= " $reason=$n";
                printf("    comments: %d seen, %d imported%s\n",
                    $com['pulled'], $com['imported'], $skipped !== '' ? ' (skipped:' . $skipped . ')' : '');
            }
        } catch (Exception $e) {
            // One unreachable tracker must not stop the others being polled.
            $failed++;
            printf("%-28s FAILED: %s\n", mb_substr($name, 0, 28), $e->getMessage());
            $conn->prepare("UPDATE integration_links SET last_error = ? WHERE connection_id = ?")
                 ->execute([mb_substr($e->getMessage(), 0, 500), (int)$row['id']]);
        }

        // Stamp the attempt whether or not it succeeded — otherwise a permanently
        // broken connection is retried on every single run forever, hammering a
        // tracker that is already unhappy.
        $conn->prepare("UPDATE integration_connections SET last_poll_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([(int)$row['id']]);
    }

    printf("\n%d connection(s) polled, %d issue(s) checked, %d updated, %d comment(s) imported, %d failed.\n",
        count($due), $totalChecked, $totalChanged, $totalComments, $failed);
} catch (Exception $e) {
    if (!$isCli) http_response_code(500);
    echo "Integration poll failed: " . $e->getMessage() . "\n";
    error_log('integration_poll: ' . $e->getMessage());
}
