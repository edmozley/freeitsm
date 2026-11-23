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
require_once '../../includes/tenancy.php';
require_once '../../includes/messaging/messaging.php';
require_once '../../includes/messaging/ingest.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Reached from Tickets → Settings → Messaging (RBAC) or from System →
// Integrations → Slack (admin-only). messagingAdminMayAdministerChannel()
// explains why an admin is allowed here for a Slack channel and nothing else.
$rawIn = file_get_contents('php://input');
$input = json_decode($rawIn, true);
$isAdminSlackPath = messagingAdminMayAdministerChannel(connectToDatabase(), (int) ($input['id'] ?? $_GET['id'] ?? 0));
if (!$isAdminSlackPath) {
    requireModuleAccessJson('tickets');
    requireCapabilityJson(Cap::TICKETS_MESSAGING);
}

try {
    $channelId = (int) ($input['id'] ?? $_GET['id'] ?? 0);
    $mode = $input['mode'] ?? ($_GET['mode'] ?? 'all');
    if ($channelId <= 0) {
        throw new Exception('Channel ID is required');
    }

    $conn = connectToDatabase();

    // ⚠️ Company check. The two gates above answer "may you administer channels at
    // all?"; neither answers "may you administer THIS one?". An analyst holding
    // TICKETS_MESSAGING for company A could therefore point this at a channel pinned
    // to company B and run all three checks against it — validating B's stored
    // credentials with B's provider, and, in `simulate` mode, driving a synthetic
    // message through B's real ingest to create and delete a ticket in B's company.
    // Reported by Erlend Volden as one of the S1 siblings.
    //
    // analystCanAccessChannel() already encodes the rule this needs, including the
    // part that is easy to get wrong: a channel with tenant_id NULL is SHARED INTAKE,
    // not Default-owned, so it stays administerable by anyone with the capability.
    // Only a channel PINNED to a company is restricted.
    //
    // The admin-Slack path deliberately keeps its exemption. It is a documented,
    // narrow carve-out (System → Integrations → Slack), and is_admin is not the same
    // thing as can_access_all_tenants — applying the check to it as well would lock
    // administrators out of the Slack screen they are meant to own.
    if (!$isAdminSlackPath && !analystCanAccessChannel($conn, (int) $_SESSION['analyst_id'], $channelId)) {
        throw new Exception('Channel not found');   // same words as a missing id
    }

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

/**
 * Validate credentials with the provider (read-only).
 *
 * @param array $channel  the messaging_channels row as loaded by loadMessagingChannel()
 */
function testCredentials(array $channel): array
{
    try {
        $detail = messagingProvider($channel)->testConnection();
        return ['ok' => true, 'detail' => $detail];
    } catch (Exception $e) {
        return ['ok' => false, 'detail' => $e->getMessage()];
    }
}

/**
 * Confirm the channel's own public webhook URL is reachable from the internet.
 *
 * @param int $channelId  the messaging_channels id whose webhook URL is probed
 */
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
    // A free ngrok tunnel serves its own "you're about to visit" HTML
    // interstitial on a visitor's first hit, instead of proxying the request
    // through — which would otherwise make THIS self-test fail with "HTTP 200
    // but unexpected response" even though the tunnel is working fine. This is
    // ngrok's own documented, always-safe way to say "this is an automated
    // request, skip the interstitial" (ngrok-skip-browser-warning); a non-ngrok
    // host simply ignores an extra header it doesn't recognise.
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['ngrok-skip-browser-warning: true']);
    sslApplyCurl($ch);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        return ['ok' => false, 'detail' => "Couldn't reach $url — $err. Check the tunnel/server is running and the Public base URL is correct."];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $json = json_decode($body, true);
    if ($code === 200 && is_array($json) && ($json['pong'] ?? '') === $nonce) {
        return ['ok' => true, 'detail' => "Reachable — the public webhook URL responded correctly ($host)."];
    }
    if (str_contains($host, 'ngrok') && stripos((string) $body, 'ngrok') !== false) {
        return ['ok' => false, 'detail' => "Reached $host, but ngrok's own interstitial page answered instead of FreeITSM. A free ngrok tunnel shows this once per visitor/browser — it normally does NOT affect real webhook calls from Telegram/Meta/Twilio (they aren't browsers), only manual clicks and some automated health checks. If it keeps happening, open the URL once in a browser and click through, or use a paid ngrok plan / your own domain instead."];
    }
    return ['ok' => false, 'detail' => "Reached $host but got HTTP $code with an unexpected response — a proxy, firewall or login page may be intercepting the URL before it reaches FreeITSM."];
}

/**
 * Run a synthetic inbound message through ingest, then clean up everything it created.
 *
 * @param array $channel  the messaging_channels row as loaded by loadMessagingChannel()
 */
function testSimulation(PDO $conn, array $channel): array
{
    $channelType = $channel['channel_type'] ?? 'whatsapp';
    // A clearly-fake, unique sender so it always opens a fresh ticket we can
    // delete. Must be shaped like a REAL sender for this channel type —
    // normaliseChannelIdentifier() rejects anything else (e.g. a phone-shaped
    // string is not a valid Telegram chat id, and vice versa), which is
    // exactly the "no usable sender identifier" error this used to throw for
    // every non-phone channel.
    $sender = ($channelType === 'telegram')
        ? (string) random_int(900000000, 999999999) // looks like a real (positive) chat id
        : ('+99999' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT));
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
            // Telegram's identity gate (ingest.php) otherwise calls the REAL
            // Telegram API to message this (fake, nonexistent) chat id, which
            // breaks this file's own promise that a simulation never sends a
            // real message. This flag tells it to skip those calls.
            'is_test'         => true,
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

/**
 * Remove everything a simulation created (ticket, its messages, the placeholder user) and restore the channel stamp.
 *
 * @param string $sender  the fake sender the simulation used (a phone number or Telegram chat id)
 * @param string $channelType  the channel type, e.g. 'whatsapp' or 'telegram' - picks how $sender is normalised
 * @param int $channelId  the messaging_channels id whose last-inbound stamp is restored
 * @param mixed $prevStamp  the channel's last_inbound_datetime before the test; empty restores NULL
 */
function simCleanup(PDO $conn, ?int $ticketId, string $sender, string $channelType, int $channelId, $prevStamp): void
{
    try {
        if ($ticketId) {
            $conn->prepare("DELETE FROM emails WHERE ticket_id = ?")->execute([$ticketId]);
            $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$ticketId]);
        }
        // The placeholder requester keyed by the fake number, only if it has no other tickets.
        // Same normalisation the ingest used, or the cleanup looks for the wrong
        // pseudo-user and leaves the simulation's placeholder behind.
        $pseudo = ltrim(normaliseChannelIdentifier($sender, $channelType), '+') . '@' . $channelType . '.local';
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
