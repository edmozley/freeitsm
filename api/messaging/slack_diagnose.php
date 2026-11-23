<?php
/**
 * API Endpoint: a full health check of one Slack channel.
 *
 * POST slack_diagnose.php   { "id": 11 }
 *
 * Every check here is one I had to run by hand while getting the first
 * workspace working. Each corresponds to a real failure, and — this is the
 * point — most of those failures look like nothing at all: the app is connected,
 * the token is valid, tickets even arrive, and one thing is quietly wrong.
 *
 * ⚠️ Its own endpoint rather than another mode on test_channel.php. That file is
 * the shared "test a channel" for every provider (credentials / reachability /
 * simulate); these checks are Slack-specific and would have made it a file of
 * two unrelated halves.
 *
 * Every check returns the same shape so the UI never has to special-case one:
 *   ['key','label','status' => ok|warn|fail|skip,'detail','fix' => '']
 *
 * `fix` is the sentence that tells someone what to DO. A diagnostic that says
 * "missing_scope" and stops has moved the problem, not solved it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/messaging/messaging.php';
require_once '../../includes/messaging/slack_manifest.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$rawIn = file_get_contents('php://input');
$in    = json_decode($rawIn, true);
$id    = (int) ($in['id'] ?? 0);

// Same two doors as the other channel endpoints — see
// messagingAdminMayAdministerChannel().
try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database unavailable']);
    exit;
}
$isAdminSlackPath = messagingAdminMayAdministerChannel($conn, $id);
if (!$isAdminSlackPath) {
    requireModuleAccessJson('tickets');
    requireCapabilityJson(Cap::TICKETS_MESSAGING);
}

// ⚠️ ...and may you administer THIS channel? The twin of the check added to
// test_channel.php, and the more revealing of the two: these checks report the
// workspace name, the bot's identity, the granted scopes and the configured URLs
// for the channel named in the request. Pointed at another company's pinned Slack
// channel by a capability-holding analyst from elsewhere, it was a tidy summary of
// that company's integration. NULL tenant_id = shared intake, so those stay open to
// anyone with the capability; the admin-Slack carve-out is preserved as designed.
if (!$isAdminSlackPath && !analystCanAccessChannel($conn, (int) $_SESSION['analyst_id'], $id)) {
    echo json_encode(['success' => false, 'error' => 'Slack channel not found']);
    exit;
}

$checks = [];
/**
 * Appends one result row (key, label, status, detail and an optional fix) to the global
 * $checks list the diagnosis returns.
 *
 * @param string $key  a stable id for the check, used by the page
 * @param string $label  the check's name as shown
 * @param string $status  'ok', 'warn', 'fail' or 'skip'
 * @param string $detail  what was found, in plain words
 * @param string $fix  what to do about it; empty when there is nothing to do
 */
function addCheck(string $key, string $label, string $status, string $detail, string $fix = ''): void
{
    global $checks;
    $checks[] = compact('key', 'label', 'status', 'detail', 'fix');
}

try {
    $channel = loadMessagingChannel($conn, $id);
    if (!$channel || MessagingProviderKind::tryFrom((string)($channel['provider'] ?? '')) !== MessagingProviderKind::Slack) {
        echo json_encode(['success' => false, 'error' => 'Slack channel not found']);
        exit;
    }
    $creds    = $channel['credentials'] ?? [];

    // messagingProvider() is typed to the abstract MessagingProvider, but the
    // checks below call methods that only SlackProvider has. The row's provider
    // was already verified above, so this cannot fail — it makes that fact
    // explicit rather than implied, and turns a would-be fatal into an error
    // message if the two ever drift apart.
    $provider = messagingProvider($channel);
    if (!$provider instanceof SlackProvider) {
        echo json_encode(['success' => false, 'error' => 'That channel is not a Slack channel.']);
        exit;
    }

    // ── 1. Is it switched on at all? ────────────────────────────────────────
    if (empty($channel['is_active'])) {
        addCheck('active', 'Switched on', 'fail',
            'This workspace is marked inactive, so nothing is accepted from it.',
            'Edit the workspace and tick Active.');
    } else {
        addCheck('active', 'Switched on', 'ok', 'Active.');
    }

    // ── 2. The two secrets ──────────────────────────────────────────────────
    $hasToken  = trim((string) ($creds['bot_token'] ?? '')) !== '';
    $hasSecret = trim((string) ($creds['signing_secret'] ?? '')) !== '';

    if (!$hasToken || !$hasSecret) {
        $missing = [];
        if (!$hasToken)  $missing[] = 'bot token';
        if (!$hasSecret) $missing[] = 'signing secret';
        addCheck('secrets', 'Credentials stored', 'fail',
            'Missing the ' . implode(' and the ', $missing) . '.',
            'Both come from your Slack app: the token from OAuth & Permissions, the signing secret from Basic Information.');
    } else {
        addCheck('secrets', 'Credentials stored', 'ok', 'Bot token and signing secret are both set.');
    }

    // ── 3. Does Slack accept the token? ─────────────────────────────────────
    $authOk = false;
    if ($hasToken) {
        try {
            $detail = $provider->testConnection();
            $authOk = true;
            addCheck('auth', 'Slack accepts the token', 'ok', $detail);
        } catch (Exception $e) {
            addCheck('auth', 'Slack accepts the token', 'fail', $e->getMessage(),
                'Reinstall the app in Slack and copy the Bot User OAuth Token again.');
        }
    } else {
        addCheck('auth', 'Slack accepts the token', 'skip', 'No bot token to test.');
    }

    // ── 4. Permissions ──────────────────────────────────────────────────────
    // The check that catches the trap nobody sees: scopes are granted at INSTALL
    // time, so an app built from a manifest is under-permissioned until it is
    // reinstalled — and nothing appears broken when it is.
    if ($authOk) {
        $granted  = $provider->grantedScopes();
        $required = array_keys(slackManifestScopes());
        $missing  = array_values(array_diff($required, $granted));

        if (!$granted) {
            addCheck('scopes', 'Permissions', 'warn',
                'Slack did not report which permissions this token holds.',
                'Not necessarily a problem — the other checks below still tell you what works.');
        } elseif (!$missing) {
            addCheck('scopes', 'Permissions', 'ok',
                'All ' . count($required) . ' permissions granted.');
        } else {
            $harmless = array_diff($missing, ['channels:history', 'chat:write']);
            addCheck('scopes', 'Permissions', count($harmless) === count($missing) ? 'warn' : 'fail',
                'Missing: ' . implode(', ', $missing) . '.',
                'Slack grants permissions when an app is INSTALLED, so an app created from a manifest keeps an under-powered token until you reinstall it. In Slack go to OAuth & Permissions and click "Reinstall to Workspace".');
        }
    } else {
        addCheck('scopes', 'Permissions', 'skip', 'Cannot check without a working token.');
    }

    // ── 5. Can it read a person's name? ─────────────────────────────────────
    // The failure this catches is cosmetic but permanent-looking: tickets arrive
    // named "Slack user @U0A1B2C3" and nothing says why.
    if ($authOk) {
        $probe = $provider->lookupUser((string) ($provider->botUserId() ?: ''));
        if ($probe['name'] !== '') {
            addCheck('profiles', 'Can read names', 'ok',
                'Requesters will show their real name.');
        } else {
            addCheck('profiles', 'Can read names', 'warn',
                'Profile lookup returned nothing, so tickets will be raised as "Slack user @U…".',
                'Usually the users:read permission — reinstall the app in Slack. Existing requesters correct themselves on their next message.');
        }
    } else {
        addCheck('profiles', 'Can read names', 'skip', 'Cannot check without a working token.');
    }

    // ── 6. The address Slack has to reach ───────────────────────────────────
    $webhookUrl = messagingWebhookUrl($conn, $id);
    $problem    = slackWebhookUrlProblem($webhookUrl);
    if ($problem !== '') {
        addCheck('url', 'Address Slack sends to', 'fail', $problem,
            'Set a public address under Tickets → Settings → Messaging, or put this install behind a public HTTPS name.');
    } else {
        addCheck('url', 'Address Slack sends to', 'ok', $webhookUrl);
    }

    // ── 7. …and can the outside world actually reach it? ────────────────────
    // FreeITSM calls its OWN public URL. If that fails, Slack's attempt will too,
    // and Slack's error message says nothing useful about why.
    if ($problem === '') {
        // ⚠️ Tried TWICE before reporting a failure, and pinned to HTTP/1.1.
        //
        // The first version of this check failed with "Error in the HTTP2 framing
        // layer" against a tunnel that was demonstrably up — a transient blip on
        // a free ngrok endpoint. A health check that cries wolf is worse than no
        // health check: people learn to ignore the one time it is right.
        $ok = false; $err = ''; $code = 0;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $nonce = bin2hex(random_bytes(6));
            $ch = curl_init($webhookUrl . '&ping=' . $nonce);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_FOLLOWLOCATION => true,
                // Tunnels negotiate HTTP/2 inconsistently; 1.1 is what every one
                // of them handles, and this request is a 30-byte ping.
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);
            sslApplyCurl($ch);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $decoded = json_decode((string) $body, true);
            if (is_array($decoded) && ($decoded['pong'] ?? '') === $nonce) {
                $ok = true;
                break;
            }
            usleep(400000);   // a blip deserves another go, not a verdict
        }

        $why = $err !== ''
            ? ('Could not connect: ' . $err)
            : ('The address answered HTTP ' . $code . ' instead of this install.');

        if ($ok) {
            addCheck('reach', 'Reachable from the internet', 'ok',
                'The address answered correctly from outside.');
        } elseif ($channel['last_inbound_datetime'] ?? null) {
            // ⚠️ Do NOT claim it is broken. This test makes THIS server call its
            // own public address, which loops back in through the same web server
            // and is throttled or dropped by some tunnels even when the tunnel is
            // working perfectly — observed failing twice in three runs against an
            // ngrok endpoint that was demonstrably up.
            //
            // And we have proof to the contrary: a message HAS arrived from Slack,
            // which it could only do by reaching this address. So report what is
            // actually known. A check that cries wolf gets ignored the one time it
            // is right.
            addCheck('reach', 'Reachable from the internet', 'warn',
                'Could not confirm it from this server (' . $why . '), but Slack has reached it before — the last message arrived '
                . $channel['last_inbound_datetime'] . ' (UTC). This test loops back through your own web server and some tunnels drop that.',
                'Nothing to do unless messages have stopped arriving. Post one in Slack to be certain.');
        } else {
            addCheck('reach', 'Reachable from the internet', 'fail',
                $why . ' Tried three times, and no message has ever arrived from Slack either.',
                'Slack will fail the same way. If you use a tunnel such as ngrok, check it is still running — its address changes unless you reserved one.');
        }
    } else {
        addCheck('reach', 'Reachable from the internet', 'skip', 'Skipped — the address cannot work as it stands.');
    }

    // ── 8. Is the app in the channel? ───────────────────────────────────────
    // The most common failure of all, and completely silent.
    $watch = trim((string) ($creds['watch_channel'] ?? ''));
    if (!$authOk) {
        addCheck('member', 'In the channel', 'skip', 'Cannot check without a working token.');
    } elseif ($watch === '') {
        addCheck('member', 'In the channel', 'warn',
            'No channel is set, so every channel the app is invited to raises tickets.',
            'On a busy channel that means a ticket per message. Name one channel unless you meant this.');
    } else {
        $m = $provider->channelMembership($watch);
        if ($m['in']) {
            addCheck('member', 'In the channel', 'ok', 'The app can read ' . $watch . '.');
        } else {
            addCheck('member', 'In the channel', 'fail', $m['error'],
                'In Slack, open that channel and type /invite followed by the app name. An app cannot read a channel it is not in, and nothing warns you.');
        }
    }

    // ── 9. Has anything ever actually arrived? ──────────────────────────────
    // The end-to-end proof. A message that got in also proves the signing secret
    // is right, because an unsigned or wrongly-signed one is refused.
    $last = $channel['last_inbound_datetime'] ?? null;
    if ($last) {
        addCheck('traffic', 'Messages arriving', 'ok',
            'Last message received ' . $last . ' (UTC). This also proves the signing secret is correct.');
    } else {
        addCheck('traffic', 'Messages arriving', 'warn',
            'No message has ever arrived on this workspace.',
            'Post something in the channel the app watches. If nothing appears, the checks above will say why.');
    }

    $worst = 'ok';
    foreach ($checks as $c) {
        if ($c['status'] === 'fail') { $worst = 'fail'; break; }
        if ($c['status'] === 'warn') { $worst = 'warn'; }
    }

    echo json_encode(['success' => true, 'overall' => $worst, 'checks' => $checks]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
