<?php
/**
 * API: the install's calendar-sync connection and feed policy (GH #75).
 *
 *   GET                 -> current connection (WITHOUT secrets) + feed policy +
 *                          the Microsoft mailboxes whose credentials can be borrowed
 *   POST action=save    -> create/update the connection and the feed policy
 *   POST action=test    -> mint a token and prove the permission was granted
 *   POST action=delete  -> remove the connection
 *
 * ⚠️ SECRETS ARE NEVER RETURNED. The GET reports has_credentials as a boolean and
 * that is all, exactly as integrations.php does — a read that hands secrets back
 * to a browser needs the same care as one that writes them, and there is no
 * reason for the screen to know them.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // System administrators only
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/calendar_sync/calendar_sync.php';
require_once '../../includes/calendar_sync/pull.php';   // the accept-deletes setting

header('Content-Type: application/json');

$action = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['action'] ?? '') : '';

try {
    $conn = connectToDatabase();

    if (!calendarSyncSchemaReady($conn)) {
        echo json_encode([
            'success' => false,
            'needs_db_verify' => true,
            'error' => 'Calendar sync needs a database update — run System → Database Verification.',
        ]);
        exit;
    }

    // ── Read ────────────────────────────────────────────────────────────────
    if ($action === '') {
        $row = $conn->query("SELECT * FROM calendar_connections ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        // Only Microsoft mailboxes can lend an app registration, and only ones
        // that actually have Azure credentials on them — offering an IMAP mailbox
        // as a source would be offering something that cannot work.
        $mailboxes = [];
        foreach ($conn->query("SELECT id, name, provider, azure_client_id FROM target_mailboxes WHERE provider = 'microsoft' AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $mb) {
            if (!empty($mb['azure_client_id'])) {
                $mailboxes[] = ['id' => (int)$mb['id'], 'name' => $mb['name']];
            }
        }

        echo json_encode([
            'success'   => true,
            'connection' => $row ? [
                'id'         => (int)$row['id'],
                'name'       => $row['name'],
                'provider'   => $row['provider'],
                'mailbox_id' => $row['mailbox_id'] !== null ? (int)$row['mailbox_id'] : null,
                'is_active'  => (int)$row['is_active'] === 1,
                // A boolean and nothing more. See the header.
                'has_credentials'     => !empty($row['credentials']),
                'last_error'          => $row['last_error'],
                'last_error_datetime' => $row['last_error_datetime'],
            ] : null,
            'mailboxes'  => $mailboxes,
            'feed_mode'      => scheduleFeedMode($conn),
            'accept_deletes' => calendarAcceptDeletes($conn),
            'notify_url'     => calendarNotifyUrl($conn),
            // A SUGGESTION, never applied on its own. FreeITSM cannot know what
            // URL the outside world reaches it on — HTTP_HOST is whatever the
            // last request used, and behind a proxy or a tunnel it is routinely
            // wrong. Offering it saves typing; an admin still confirms it.
            'notify_default' => (!empty($_SERVER['HTTP_HOST'])
                ? 'https://' . $_SERVER['HTTP_HOST']
                  . (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/api/calendar/graph_notify.php'
                : ''),
            'subscriptions'  => (int)$conn->query(
                "SELECT COUNT(*) FROM calendar_enrolments WHERE subscription_id IS NOT NULL")->fetchColumn(),
            'enrolled'   => (int)$conn->query("SELECT COUNT(*) FROM calendar_enrolments WHERE mode <> 'off'")->fetchColumn(),

            // 🔑 HOW LONG SINCE ANYTHING WAS CHECKED — the one number that reveals
            // a scheduled job which has quietly stopped. Every other failure on
            // this screen announces itself; a cron that is no longer running looks
            // EXACTLY like a calendar in which nothing has changed, and it can sit
            // like that for weeks. NULL means it has genuinely never run.
            //
            // Measured in SQL rather than by differencing against the browser's
            // clock: delta_synced_datetime is written with UTC_TIMESTAMP(), so comparing it
            // to UTC_TIMESTAMP() keeps both sides on one clock and sidesteps the timezone
            // question altogether.
            'last_poll_minutes' => (function () use ($conn) {
                $v = $conn->query(
                    "SELECT TIMESTAMPDIFF(MINUTE, MAX(delta_synced_datetime), UTC_TIMESTAMP())
                       FROM calendar_enrolments
                      WHERE mode <> 'off' AND delta_synced_datetime IS NOT NULL"
                )->fetchColumn();
                return ($v === null || $v === false) ? null : (int)$v;
            })(),

            // Every active analyst, with the mailbox their work would go to.
            // LEFT JOIN, because an analyst who has never chosen has no enrolment
            // row and must still be listed — otherwise the one person an admin
            // most needs to fix is the one who is invisible.
            'analysts'   => $conn->query(
                "SELECT a.id, a.full_name, a.email, e.calendar_address, e.mode, e.task_mode, e.last_error,
                        e.subscription_id,
                        TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), e.subscription_expires) AS sub_hours,
                        TIMESTAMPDIFF(MINUTE, e.delta_synced_datetime, UTC_TIMESTAMP()) AS checked_minutes
                   FROM analysts a
                   LEFT JOIN calendar_enrolments e ON e.analyst_id = a.id
                  WHERE a.is_active = 1
                  ORDER BY a.full_name"
            )->fetchAll(PDO::FETCH_ASSOC),
        ]);
        exit;
    }

    // ── Write ───────────────────────────────────────────────────────────────
    if ($action === 'save') {
        $name      = trim((string)($_POST['name'] ?? 'Microsoft 365'));
        $source    = ($_POST['source'] ?? 'mailbox') === 'own' ? 'own' : 'mailbox';
        $mailboxId = ($_POST['mailbox_id'] ?? '') !== '' ? (int)$_POST['mailbox_id'] : null;
        $feedMode  = (string)($_POST['feed_mode'] ?? FEED_MODE_FULL);

        if (!in_array($feedMode, [FEED_MODE_OFF, FEED_MODE_REF, FEED_MODE_FULL], true)) {
            $feedMode = FEED_MODE_FULL;
        }
        $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([SCHEDULE_FEED_SETTING, $feedMode]);

        // Whether deleting one of these events in a calendar unschedules the
        // ticket. OFF by default: whether a personal tidy-up may reach shared
        // data is an organisation's call, and the safe answer changes nothing.
        if (array_key_exists('notify_url', $_POST)) {
            $conn->prepare(
                "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([CALENDAR_NOTIFY_URL, trim((string)$_POST['notify_url'])]);
        }

        if (array_key_exists('accept_deletes', $_POST)) {
            $conn->prepare(
                "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([CALENDAR_ACCEPT_DELETES, $_POST['accept_deletes'] === '1' ? '1' : '0']);
        }

        // The feed policy stands alone: an install with no calendar connection at
        // all still publishes subscribe links, and must be able to govern them.
        if (($_POST['policy_only'] ?? '') === '1') {
            echo json_encode(['success' => true, 'feed_mode' => $feedMode]);
            exit;
        }

        $existing = $conn->query("SELECT * FROM calendar_connections ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        $credentials = null;   // null = leave whatever is stored alone
        if ($source === 'own') {
            $tenant = trim((string)($_POST['tenant_id'] ?? ''));
            $client = trim((string)($_POST['client_id'] ?? ''));
            $secret = (string)($_POST['client_secret'] ?? '');
            // A blank or masked secret on an edit means "unchanged", so an admin
            // can rename the connection without retyping a secret they cannot see.
            if (isMaskedNoChangeValue($secret) && $existing && !empty($existing['credentials'])) {
                $old = calendarSyncDecodeCredentials($existing['credentials']);
                $secret = $old['client_secret'] ?? '';
            }
            if ($tenant === '' || $client === '' || $secret === '') {
                echo json_encode(['success' => false, 'error' => 'Tenant ID, client ID and client secret are all required.']);
                exit;
            }
            $credentials = calendarSyncEncodeCredentials([
                'tenant_id' => $tenant, 'client_id' => $client, 'client_secret' => $secret,
            ]);
            $mailboxId = null;               // own credentials win; don't leave a stale borrow
        } else {
            if (!$mailboxId) {
                echo json_encode(['success' => false, 'error' => 'Choose the mailbox to borrow credentials from.']);
                exit;
            }
            $credentials = '';               // '' = explicitly clear, so borrowing takes effect
        }

        if ($existing) {
            $conn->prepare(
                "UPDATE calendar_connections
                    SET name = ?, provider = 'microsoft', mailbox_id = ?, credentials = ?,
                        last_error = NULL, last_error_datetime = NULL,
                        token_data = NULL, updated_datetime = UTC_TIMESTAMP()
                  WHERE id = ?"
            )->execute([$name, $mailboxId, ($credentials === '' ? null : $credentials), (int)$existing['id']]);
            $id = (int)$existing['id'];
        } else {
            $conn->prepare(
                "INSERT INTO calendar_connections (name, provider, mailbox_id, credentials, created_by)
                 VALUES (?, 'microsoft', ?, ?, ?)"
            )->execute([$name, $mailboxId, ($credentials === '' ? null : $credentials), (int)$_SESSION['analyst_id']]);
            $id = (int)$conn->lastInsertId();
        }
        // token_data is cleared above on purpose: a cached token minted with the
        // OLD credentials would keep working for up to an hour and make a broken
        // change look fine until long after the admin walked away.
        echo json_encode(['success' => true, 'id' => $id, 'feed_mode' => $feedMode]);
        exit;
    }

    if ($action === 'test') {
        $row = $conn->query("SELECT id FROM calendar_connections ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'error' => 'Save the connection first.']); exit; }

        $connection = calendarSyncLoadConnection($conn, (int)$row['id']);
        if (!$connection) { echo json_encode(['success' => false, 'error' => 'The connection is not active.']); exit; }

        $creds = $connection['credentials'] ?? [];
        if (empty($creds['tenant_id']) || empty($creds['client_id']) || empty($creds['client_secret'])) {
            echo json_encode(['success' => false, 'error' =>
                'No usable credentials. If you chose to borrow them, check that mailbox still has its Azure details.']);
            exit;
        }

        try {
            $provider = calendarSyncProviderFor($connection);
            $provider->conn = $conn;

            // Two separate questions, reported separately, because they fail for
            // completely different reasons and need different fixes.
            $probe = trim((string)($_POST['probe'] ?? ''));
            $provider->verifyConnection();               // throws if credentials/consent are wrong

            $result = ['success' => true, 'token' => true, 'borrowed' => $connection['borrowed_from_mailbox'] ?? null];
            if ($probe !== '') {
                $result['probe']    = $probe;
                $result['probe_ok'] = $provider->verifyTarget($probe);
            }
            $conn->prepare("UPDATE calendar_connections SET last_error = NULL, last_error_datetime = NULL WHERE id = ?")
                 ->execute([(int)$row['id']]);
            echo json_encode($result);
        } catch (Exception $e) {
            $msg = substr($e->getMessage(), 0, 500);
            $conn->prepare("UPDATE calendar_connections SET last_error = ?, last_error_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$msg, (int)$row['id']]);
            echo json_encode(['success' => false, 'token' => false, 'error' => $msg]);
        }
        exit;
    }

    /**
     * Point one analyst's calendar sync at a particular mailbox.
     *
     * 🔴 ADMIN ONLY, AND DELIBERATELY NOT SOMETHING AN ANALYST CAN DO. The app
     * permission behind this can write to any mailbox in the tenant, so an
     * analyst able to set their own address could quietly fill a colleague's —
     * or the chief executive's — calendar with their tickets. An analyst
     * controls whether sync is on; an administrator controls where it goes.
     */
    if ($action === 'set_address') {
        $analystId = (int)($_POST['analyst_id'] ?? 0);
        $address   = trim((string)($_POST['calendar_address'] ?? ''));
        if (!$analystId) { echo json_encode(['success' => false, 'error' => 'Unknown analyst.']); exit; }

        if ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'That is not a valid email address.']);
            exit;
        }

        // Blank means "go back to inheriting analysts.email" rather than "sync to
        // nowhere" — an admin clearing the box is undoing an override, not
        // switching the analyst off.
        $conn->prepare(
            "INSERT INTO calendar_enrolments (analyst_id, calendar_address, mode)
             VALUES (?, ?, 'off')
             ON DUPLICATE KEY UPDATE calendar_address = VALUES(calendar_address), updated_datetime = UTC_TIMESTAMP()"
        )->execute([$analystId, ($address === '' ? null : $address)]);

        // Verify it while we are here, if we can, so the admin does not have to
        // press a second button to find out whether what they typed exists.
        $verified = null;
        if ($address !== '') {
            $row = $conn->query("SELECT id FROM calendar_connections WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                try {
                    $connection = calendarSyncLoadConnection($conn, (int)$row['id']);
                    $provider = calendarSyncProviderFor($connection);
                    $provider->conn = $conn;
                    $verified = $provider->verifyTarget($address);
                } catch (Exception $e) {
                    $verified = null;   // unknown, not false — a broken connection
                }                       // says nothing about whether the address is real
            }
        }
        echo json_encode(['success' => true, 'verified' => $verified]);
        exit;
    }

    if ($action === 'delete') {
        // Enrolments point at this connection with ON DELETE SET NULL, so nobody's
        // choice is silently destroyed — their mode simply has nothing to push to.
        $conn->exec("DELETE FROM calendar_connections");
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
