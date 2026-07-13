<?php
/**
 * API Endpoint: test a messaging channel. Three read-mostly checks, each safe to run:
 *
 *   credentials   — validate the stored credentials against the provider (read-only API call).
 *   reachability  — FreeITSM calls the channel's OWN public webhook URL and confirms it
 *                   round-trips back to this script (catches a down tunnel / wrong base URL).
 *   simulate      — run a synthetic inbound message through the real ingest, confirm a
 *                   ticket is created, then delete the test ticket/message/user.
 *   all (default from the UI) — run all three and return a structured result.
 *
 * No real WhatsApp message is ever sent.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/messaging/messaging.php';
require_once '../../includes/messaging/ingest.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Messaging settings tab — sends a real message.
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MESSAGING);

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $channelId = (int) ($input['id'] ?? $_GET['id'] ?? 0);
    $mode = $input['mode'] ?? ($_GET['mode'] ?? 'all');
    if ($channelId <= 0) {
        throw new Exception('Channel ID is required');
    }

    $conn = connectToDatabase();
    $channel = loadMessagingChannel($conn, $channelId);
    if (!$channel) {
        throw new Exception('Channel not found');
    }

    $results = [];
    if ($mode === 'credentials' || $mode === 'all') {
        $results['credentials'] = testCredentials($channel);
    }
    if ($mode === 'reachability' || $mode === 'all') {
        $results['reachability'] = testReachability($conn, $channelId);
    }
    if ($mode === 'simulate' || $mode === 'all') {
        $results['simulation'] = testSimulation($conn, $channel);
    }

    echo json_encode(['success' => true, 'results' => $results]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/** Validate credentials with the provider (read-only). */
function testCredentials(array $channel): array
{
    try {
        $detail = messagingProvider($channel)->testConnection();
        return ['ok' => true, 'detail' => $detail];
    } catch (Exception $e) {
        return ['ok' => false, 'detail' => $e->getMessage()];
    }
}

/** Confirm the channel's own public webhook URL is reachable from the internet. */
function testReachability(PDO $conn, int $channelId): array
{
    $url  = messagingWebhookUrl($conn, $channelId);
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return ['ok' => false, 'detail' => "Public base URL is \"$host\" — set your real domain or tunnel address (Public base URL, above) so the webhook can be reached from the internet."];
    }

    $nonce = bin2hex(random_bytes(8));
    $probe = $url . '&ping=' . $nonce;

    $ch = curl_init($probe);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $verify = defined('SSL_VERIFY_PEER') ? SSL_VERIFY_PEER : true;
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'detail' => "Couldn't reach $url — $err. Check the tunnel/server is running and the Public base URL is correct."];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($body, true);
    if ($code === 200 && is_array($json) && ($json['pong'] ?? '') === $nonce) {
        return ['ok' => true, 'detail' => "Reachable — the public webhook URL responded correctly ($host)."];
    }
    return ['ok' => false, 'detail' => "Reached $host but got HTTP $code with an unexpected response — a proxy, firewall or login page may be intercepting the URL before it reaches FreeITSM."];
}

/** Run a synthetic inbound message through ingest, then clean up everything it created. */
function testSimulation(PDO $conn, array $channel): array
{
    // A clearly-fake, unique sender so it always opens a fresh ticket we can delete.
    $sender = '+99999' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    $channelType = $channel['channel_type'] ?? 'whatsapp';
    $prevStamp = null;

    try {
        // Remember the channel's last-inbound stamp so we can restore it afterwards.
        $st = $conn->prepare("SELECT last_inbound_datetime FROM messaging_channels WHERE id = ?");
        $st->execute([(int) $channel['id']]);
        $prevStamp = $st->fetchColumn();

        $msg = [
            'from'            => $sender,
            'to'              => $channel['phone_number'] ?? '',
            'body'            => 'FreeITSM webhook self-test — please ignore.',
            'profile_name'    => 'Webhook Self-Test',
            'provider_msg_id' => 'SELFTEST-' . bin2hex(random_bytes(6)),
            'media'           => [],
            'timestamp'       => null,
        ];

        $r = ingestInboundMessage($conn, $channel, $msg);
        $ticketId = $r['ticket_id'] ?? null;
        if (($r['status'] ?? '') !== 'created' || !$ticketId) {
            // Best-effort cleanup if something partial happened, then report.
            simCleanup($conn, $ticketId, $sender, $channelType, (int) $channel['id'], $prevStamp);
            return ['ok' => false, 'detail' => 'The simulated message did not create a ticket as expected (status: ' . ($r['status'] ?? '?') . ').'];
        }

        $num = $conn->query("SELECT ticket_number FROM tickets WHERE id = " . (int) $ticketId)->fetchColumn();
        simCleanup($conn, (int) $ticketId, $sender, $channelType, (int) $channel['id'], $prevStamp);

        return ['ok' => true, 'detail' => "Inbound handling works — a test message became a ticket (was $num) which has now been removed."];
    } catch (Exception $e) {
        simCleanup($conn, null, $sender, $channelType, (int) $channel['id'], $prevStamp);
        return ['ok' => false, 'detail' => 'Ingest failed: ' . $e->getMessage()];
    }
}

/** Remove everything a simulation created (ticket, its messages, the placeholder user) and restore the channel stamp. */
function simCleanup(PDO $conn, ?int $ticketId, string $sender, string $channelType, int $channelId, $prevStamp): void
{
    try {
        if ($ticketId) {
            $conn->prepare("DELETE FROM emails WHERE ticket_id = ?")->execute([$ticketId]);
            $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$ticketId]);
        }
        // The placeholder requester keyed by the fake number, only if it has no other tickets.
        $pseudo = ltrim(normaliseChannelIdentifier($sender), '+') . '@' . $channelType . '.local';
        $uid = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $uid->execute([$pseudo]);
        $userId = $uid->fetchColumn();
        if ($userId) {
            $cnt = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ?");
            $cnt->execute([$userId]);
            if ((int) $cnt->fetchColumn() === 0) {
                $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            }
        }
        // Restore the channel's last-inbound stamp the simulation touched.
        $conn->prepare("UPDATE messaging_channels SET last_inbound_datetime = ? WHERE id = ?")
             ->execute([$prevStamp ?: null, $channelId]);
    } catch (Exception $e) { /* best effort */ }
}
