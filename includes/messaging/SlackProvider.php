<?php
/**
 * SlackProvider — Slack as a messaging channel, via a Slack app the CUSTOMER owns.
 *
 * ⚠️ Deliberately NOT an "integration provider" in the Jira / Azure DevOps sense.
 * Slack has no work items and no states, so none of that engine applies. It is a
 * conversation channel, exactly like WhatsApp and web chat, and it rides the same
 * MessagingProvider contract: verify the webhook, parse an inbound message, send a
 * reply, download a file.
 *
 * ── BRING YOUR OWN APP ────────────────────────────────────────────────────────
 * There is no FreeITSM-hosted Slack app and there never will be. Publishing one
 * would mean we host the OAuth redirect and every install's events flow through
 * our server — permanent infrastructure, and we would become a data processor for
 * other people's companies. Instead the customer creates an app in their own
 * workspace from the manifest we generate (slack_manifest.php), and their Slack
 * talks straight to their FreeITSM. Nothing touches us.
 *
 * Credentials JSON (messaging_channels.credentials, encrypted at rest):
 *   {
 *     "bot_token":      "xoxb-…",   // Bot User OAuth Token
 *     "signing_secret": "…",        // Basic Information → Signing Secret
 *     "watch_channel":  "C08ABCDE"  // optional: only ingest from this channel
 *   }
 * messaging_channels.channel_ref holds the Slack team (workspace) id.
 *
 * Inbound: Slack POSTs Events API JSON. Authenticity is the v0 signature — see
 * verifyWebhook, which also enforces the replay window Slack requires.
 */

require_once __DIR__ . '/MessagingProvider.php';

class SlackProvider extends MessagingProvider
{
    /** Slack's own guidance: reject anything older than five minutes. */
    private const MAX_SKEW_SECONDS = 300;

    private const API = 'https://slack.com/api/';

    // ---------------------------------------------------------------- inbound

    /**
     * Slack's one-off endpoint check. It arrives as a POSTed JSON body of type
     * url_verification, NOT a GET — so the base class's GET-shaped hook can't
     * serve it. The webhook endpoint calls verifyUrlChallenge() instead; this
     * stays null so nothing tries the Meta handshake on a Slack channel.
     *
     * @param array $get  the request's query parameters (not used - Slack's handshake is a POST)
     */
    public function verifyChallenge(array $get): ?string
    {
        return null;
    }

    /**
     * Slack's url_verification handshake: echo back the challenge.
     *
     * ⚠️ THE SETUP DEADLOCK, and why this is allowed through unsigned.
     *
     * Slack verifies the request URL the instant the app is created from the
     * manifest. But the signing secret does not exist until the app exists — so
     * at that moment FreeITSM cannot possibly hold it. Requiring a valid
     * signature here therefore makes setup impossible, not merely awkward:
     *
     *     create the app  →  Slack POSTs the challenge  →  403 (no secret yet)
     *     →  Slack refuses the URL  →  you never get the secret  →  ↺
     *
     * Found by actually doing it, not by testing: every unit test passed.
     *
     * So the handshake is answered without a signature ONLY while the channel
     * has no signing secret — the setup window, which lasts minutes. Once the
     * secret is stored the endpoint verifies this like anything else.
     *
     * What this does NOT open up: a real message. `event_callback` always
     * requires a valid signature, secret or no secret, so nothing can be
     * injected into the ticket system through the gap. The worst an outsider can
     * do is learn that a FreeITSM install answers at a URL they already knew.
     *
     * @param string $rawBody  the raw JSON request body; only a url_verification payload gives a challenge
     */
    public function verifyUrlChallenge(string $rawBody): ?string
    {
        $payload = json_decode($rawBody, true);
        if (is_array($payload) && ($payload['type'] ?? '') === 'url_verification') {
            $challenge = (string) ($payload['challenge'] ?? '');
            return $challenge !== '' ? $challenge : null;
        }
        return null;
    }

    /**
     * Has this channel been given its signing secret yet? Drives the one-time
     * exemption above — see verifyUrlChallenge().
     */
    public function hasSigningSecret(): bool
    {
        return trim((string) ($this->channel['credentials']['signing_secret'] ?? '')) !== '';
    }

    /**
     * Slack request signing: HMAC-SHA256 of "v0:{timestamp}:{rawBody}" with the
     * signing secret, compared against the X-Slack-Signature header.
     *
     * The timestamp check is NOT optional decoration. Without it a captured
     * request stays valid forever, so anyone who once saw a legitimate payload
     * could replay it and inject tickets indefinitely. Slack documents the
     * five-minute window for exactly this reason.
     *
     * @param string $rawBody  the raw request body, exactly as received - it is part of the signed string
     * @param array $headers  the request headers, lower-cased keys (x-slack-signature, x-slack-request-timestamp)
     * @param array $params  the parsed POST parameters (not used)
     * @param string $url  the public URL that was hit (not used)
     */
    public function verifyWebhook(string $rawBody, array $headers, array $params, string $url): bool
    {
        $sig    = (string) ($headers['x-slack-signature'] ?? '');
        $ts     = (string) ($headers['x-slack-request-timestamp'] ?? '');
        $secret = (string) ($this->channel['credentials']['signing_secret'] ?? '');

        if ($sig === '' || $ts === '' || $secret === '') {
            return false;
        }
        if (!ctype_digit($ts)) {
            return false;
        }
        if (abs(time() - (int) $ts) > self::MAX_SKEW_SECONDS) {
            return false;   // replay, or a clock so wrong we cannot trust it
        }
        if (!str_starts_with($sig, 'v0=')) {
            return false;
        }

        $expected = 'v0=' . hash_hmac('sha256', 'v0:' . $ts . ':' . $rawBody, $secret);
        return hash_equals($expected, $sig);
    }

    /**
     * Parse an Events API callback into zero or more normalised messages.
     *
     * Returns [] for everything we do not handle, which is most of it — Slack
     * sends a great deal of traffic and the endpoint simply skips what it does
     * not recognise.
     *
     * ⚠️ The bot's OWN messages come back as events. Ingesting them would turn
     * every analyst reply into a new inbound message on the same ticket — an
     * endless loop of the ticket talking to itself. Three separate guards below,
     * because Slack marks bot messages inconsistently across message subtypes.
     *
     * @param string $rawBody  the raw JSON Events API body
     * @param array $params  the parsed POST parameters (not used)
     */
    public function parseInbound(string $rawBody, array $params): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || ($payload['type'] ?? '') !== 'event_callback') {
            return [];
        }

        $event = $payload['event'] ?? [];
        if (($event['type'] ?? '') !== 'message') {
            return [];
        }

        // --- echo guards ---
        if (!empty($event['bot_id']) || !empty($event['bot_profile'])) {
            return [];
        }
        if (($event['subtype'] ?? '') === 'bot_message') {
            return [];
        }
        // Edits, deletions, joins/leaves, channel topic changes… all carry a
        // subtype. Only a plain message (no subtype) or a file share is content.
        $subtype = (string) ($event['subtype'] ?? '');
        if ($subtype !== '' && $subtype !== 'file_share') {
            return [];
        }

        $user = (string) ($event['user'] ?? '');
        if ($user === '') {
            return [];
        }

        // Optional scoping: if the channel is configured to watch one Slack
        // channel, ignore everything else the app can see. Without this, adding
        // the app to a busy channel would raise a ticket per message.
        $slackChannel = (string) ($event['channel'] ?? '');
        $watch = (string) ($this->channel['credentials']['watch_channel'] ?? '');
        if ($watch !== '' && $slackChannel !== $watch) {
            return [];
        }

        // The conversation this belongs to: a reply carries thread_ts, a new
        // top-level message IS the thread root. Replies go back to this address,
        // so a thread maps to exactly one ticket.
        $threadTs = (string) ($event['thread_ts'] ?? ($event['ts'] ?? ''));
        if ($slackChannel === '' || $threadTs === '') {
            return [];
        }

        $media = [];
        foreach (($event['files'] ?? []) as $file) {
            $media[] = [
                'id'           => $file['id'] ?? '',
                'url'          => $file['url_private'] ?? '',
                'content_type' => $file['mimetype'] ?? 'application/octet-stream',
                'filename'     => $file['name'] ?? '',
            ];
        }

        return [[
            'from'            => $user,
            // 'to' is the REPLY ADDRESS, not a phone number: channel + thread.
            // ingest.php stores it so send_message.php knows where to answer.
            'to'              => $slackChannel . ':' . $threadTs,
            'body'            => $this->toPlainText((string) ($event['text'] ?? '')),
            'profile_name'    => '',   // resolved from the API by the ingest, not here
            'provider_msg_id' => $slackChannel . ':' . (string) ($event['ts'] ?? ''),
            'media'           => $media,
            'timestamp'       => isset($event['event_ts']) ? (int) $event['event_ts'] : null,
        ]];
    }

    // --------------------------------------------------------------- outbound

    /**
     * Post a reply. $to is the reply address parseInbound produced —
     * "C08ABCDE:1719500000.123456" — so the reply lands in the same thread
     * rather than as a new top-level message in the channel.
     *
     * @param string $to  the reply address "<slackChannel>:<threadTs>" from parseInbound()
     * @param string $body  the reply text; HTML from the editor is flattened to text
     */
    public function sendMessage(string $to, string $body): string
    {
        [$slackChannel, $threadTs] = $this->splitAddress($to);
        if ($slackChannel === '') {
            throw new Exception('No Slack channel to reply to for this ticket.');
        }

        $args = [
            'channel' => $slackChannel,
            'text'    => $this->toMrkdwn($body),
        ];
        if ($threadTs !== '') {
            $args['thread_ts'] = $threadTs;
        }

        $json = $this->call('chat.postMessage', $args);
        return (string) ($json['ts'] ?? '');
    }

    /**
     * Checks the bot token with auth.test and returns the workspace and bot name.
     *
     * Warns when the token lacks the chat:write scope, since replies would then fail.
     */
    public function testConnection(): string
    {
        $json = $this->call('auth.test');
        $team = (string) ($json['team'] ?? '');
        $user = (string) ($json['user'] ?? '');
        $detail = 'Connected to Slack workspace "' . ($team !== '' ? $team : 'unknown') . '"';
        if ($user !== '') {
            $detail .= ' as @' . $user;
        }

        // A token that authenticates but cannot post is a connection that "works"
        // right up until the first reply. Say so now rather than at 2am.
        $scopes = $this->lastScopes;
        if ($scopes !== null && $scopes !== '' && !str_contains($scopes, 'chat:write')) {
            $detail .= '. ⚠️ The token is missing the chat:write scope, so replies will fail — reinstall the app after adding it.';
        }
        return $detail . '.';
    }

    /**
     * Download a file shared in Slack. url_private needs the bot token as a
     * bearer header; without it Slack silently returns its sign-in HTML page
     * with HTTP 200, which is why the content type is checked rather than trusted.
     *
     * @param array $item  one entry from parseInbound()'s 'media' list (url, content_type, filename)
     */
    public function downloadMedia(array $item): array
    {
        $token = (string) ($this->channel['credentials']['bot_token'] ?? '');
        $url   = (string) ($item['url'] ?? '');
        if ($token === '') {
            throw new Exception('Slack channel is missing its bot token.');
        }
        if ($url === '') {
            throw new Exception('Slack file has no download URL (the app may be missing the files:read scope).');
        }

        [$code, $body] = $this->httpRequest($url, [
            'method'  => 'GET',
            'headers' => ['Authorization: Bearer ' . $token],
            'follow'  => true,
        ]);
        if ($code < 200 || $code >= 300 || $body === '') {
            throw new Exception('Slack file download failed (HTTP ' . $code . ').');
        }
        // The sign-in page trap: HTML back means the token wasn't accepted.
        if (stripos(substr($body, 0, 200), '<!DOCTYPE html') !== false) {
            throw new Exception('Slack returned its sign-in page instead of the file — the bot token was rejected or lacks files:read.');
        }

        $mime = (string) ($item['content_type'] ?: 'application/octet-stream');
        $name = (string) ($item['filename'] ?? '');
        if ($name === '') {
            $name = 'file.' . messagingExtForMime($mime);
        }
        return ['data' => $body, 'content_type' => $mime, 'filename' => $name];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The bot's own Slack user id, or '' if the token will not authenticate.
     * Used as a harmless probe subject for the profile-lookup health check —
     * a user that certainly exists and certainly is not somebody's real account.
     */
    public function botUserId(): string
    {
        try {
            return (string) ($this->call('auth.test')['user_id'] ?? '');
        } catch (Exception) {
            return '';
        }
    }

    /**
     * The scopes this token was actually granted, lowercased.
     *
     * ⚠️ This is the single most useful thing to check, because the way it goes
     * wrong is invisible: Slack grants an app its scopes at INSTALL time, so an
     * app created from a manifest holds a token missing most of them until
     * somebody clicks "Reinstall to Workspace". Nothing breaks — tickets simply
     * arrive without the sender's name. Comparing granted against required is
     * the only way to see it before a user reports it.
     *
     * Slack returns them in the X-OAuth-Scopes response header, so any call will
     * do; auth.test is the cheapest and needs no permission at all.
     */
    public function grantedScopes(): array
    {
        try {
            $this->call('auth.test');
        } catch (Exception) {
            return [];
        }
        if ($this->lastScopes === null || $this->lastScopes === '') {
            return [];
        }
        return array_values(array_filter(array_map(
            function ($s) { return strtolower(trim($s)); },
            explode(',', $this->lastScopes)
        )));
    }

    /**
     * Is the app actually a member of this Slack channel?
     *
     * ⚠️ The single most common setup failure, and it looks like nothing at all:
     * an app cannot read a channel it was not invited to, so messages simply
     * never arrive. No error anywhere.
     *
     * Deliberately asks conversations.history rather than conversations.info,
     * because history needs `channels:history` — which we already require — while
     * info needs `channels:read`, which we do not. Adding a scope purely to run a
     * diagnostic would mean asking every Slack admin to approve a permission the
     * product does not otherwise use.
     *
     * @param string $slackChannelId  the Slack channel id, e.g. 'C08ABCDE'
     * @return array ['in' => bool, 'error' => string]  error is '' when in.
     */
    public function channelMembership(string $slackChannelId): array
    {
        try {
            $this->call('conversations.history', ['channel' => $slackChannelId, 'limit' => 1]);
            return ['in' => true, 'error' => ''];
        } catch (Exception $e) {
            return ['in' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Look up a Slack user. Returns ['name' => …, 'email' => …]; either may be ''.
     * Never throws — identity is a nice-to-have and must not stop a ticket being
     * raised. A missing email simply means the app has no users:read.email scope.
     *
     * @param string $slackUserId  the Slack user id, e.g. 'U012ABC'
     */
    public function lookupUser(string $slackUserId): array
    {
        try {
            $json = $this->call('users.info', ['user' => $slackUserId]);
            $u = $json['user'] ?? [];
            $p = $u['profile'] ?? [];
            $name = (string) ($p['real_name'] ?? ($u['real_name'] ?? ($u['name'] ?? '')));
            return ['name' => $name, 'email' => (string) ($p['email'] ?? '')];
        } catch (Exception $e) {
            error_log('Slack users.info failed for ' . $slackUserId . ': ' . $e->getMessage());
            return ['name' => '', 'email' => ''];
        }
    }

    /**
     * Split "C08ABCDE:1719500000.123456" into [channel, threadTs].
     *
     * @param string $addr  the reply address "<slackChannel>:<threadTs>"; a missing part comes back as ''
     */
    private function splitAddress(string $addr): array
    {
        $parts = explode(':', trim($addr), 2);
        return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
    }

    /** Captured from the last API response so testConnection can report scopes. */
    private $lastScopes = null;

    /**
     * One Slack Web API call.
     *
     * ⚠️ Arguments are sent FORM-ENCODED, not as a JSON body, and that is not a
     * style choice. Slack's write methods (chat.postMessage) accept JSON, but its
     * read methods do not — users.info given a JSON body parses no arguments at
     * all and answers `user_not_found` for a user that plainly exists. Nothing
     * errors; the parameter is simply ignored.
     *
     * Cost me a live debugging session: auth.test takes no arguments, so it
     * worked, and every test I had written passed. Form encoding is accepted by
     * every method, so it is the one that cannot be wrong.
     *
     * ⚠️ Slack also answers HTTP 200 for its own errors and puts the truth in
     * {"ok": false, "error": "…"} — so a status-code check alone reports a failed
     * call as a success. The body is what matters.
     *
     * @param string $method  the Slack Web API method, e.g. 'chat.postMessage'
     * @param array $args  the method's arguments, sent form-encoded
     */
    private function call(string $method, array $args = []): array
    {
        $token = (string) ($this->channel['credentials']['bot_token'] ?? '');
        if ($token === '') {
            throw new Exception('Slack channel is missing its bot token.');
        }

        [$code, $resp, $headers] = $this->httpRequestWithHeaders(self::API . $method, [
            'method'  => 'POST',
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
                'Authorization: Bearer ' . $token,
            ],
            'body'    => http_build_query($args),
        ]);

        if (isset($headers['x-oauth-scopes'])) {
            $this->lastScopes = $headers['x-oauth-scopes'];
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            throw new Exception('Slack returned an unreadable response (HTTP ' . $code . ').');
        }
        if (empty($json['ok'])) {
            throw new Exception($this->explainError((string) ($json['error'] ?? ('HTTP ' . $code))));
        }
        return $json;
    }

    /**
     * Slack's error strings are terse machine tokens. An analyst reading
     * "not_in_channel" has no idea the fix is to invite the app to the channel,
     * so the common ones are translated into the action that fixes them.
     *
     * @param string $err  Slack's error token, e.g. 'not_in_channel'
     */
    private function explainError(string $err): string
    {
        $map = [
            'invalid_auth'         => 'Slack rejected the bot token. Reinstall the app and copy the Bot User OAuth Token again.',
            'account_inactive'     => 'The Slack app has been uninstalled or disabled in that workspace.',
            'not_in_channel'       => 'The app is not a member of that Slack channel — invite it with /invite @YourApp.',
            'channel_not_found'    => 'That Slack channel does not exist, or the app cannot see it (it must be invited to a private channel).',
            'missing_scope'        => 'The Slack app is missing a permission scope. Re-check the manifest and reinstall.',
            'ratelimited'          => 'Slack is rate-limiting this workspace — try again shortly.',
            'token_expired'        => 'The Slack bot token has expired. Reinstall the app.',
            'no_permission'        => 'The bot token does not have permission for that action.',
        ];
        return $map[$err] ?? ('Slack error: ' . $err);
    }

    /**
     * httpRequest() drops response headers, but Slack reports the token's granted
     * scopes in X-OAuth-Scopes and that is the only way to warn about a missing
     * one BEFORE it fails in use. Same cURL setup, including sslApplyCurl().
     *
     * @param string $url  the full URL to call
     * @param array $opts  method, headers and body, as for httpRequest()
     */
    private function httpRequestWithHeaders(string $url, array $opts = []): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        sslApplyCurl($ch);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opts['method'] ?? 'GET');
        if (!empty($opts['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
        }
        if (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        });
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            throw new Exception('Network error talking to Slack: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, $body, $headers];
    }

    /**
     * Slack text → plain text for the ticket body. Unwraps the link syntax
     * (<https://x|label> and <@U123>) which would otherwise show as raw angle
     * brackets, and decodes the three entities Slack escapes.
     *
     * @param string $text  message text in Slack's own markup
     */
    private function toPlainText(string $text): string
    {
        // <https://example.com|label> → label ; <https://example.com> → the url
        $text = preg_replace('/<((?:https?|mailto):[^|>]+)\|([^>]+)>/', '$2', $text);
        $text = preg_replace('/<((?:https?|mailto):[^>]+)>/', '$1', $text);
        // <@U123|name> / <@U123> → @name / @U123 ; <#C123|general> → #general
        $text = preg_replace('/<@([UW][A-Z0-9]+)\|([^>]+)>/', '@$2', $text);
        $text = preg_replace('/<@([UW][A-Z0-9]+)>/', '@$1', $text);
        $text = preg_replace('/<#(C[A-Z0-9]+)\|([^>]+)>/', '#$2', $text);
        // Slack escapes exactly these three, and only these three.
        return strtr($text, ['&amp;' => '&', '&lt;' => '<', '&gt;' => '>']);
    }

    /**
     * Plain text → Slack mrkdwn. Escapes the three characters Slack reserves.
     *
     * @param string $body  the analyst's reply, plain text or editor HTML
     */
    private function toMrkdwn(string $body): string
    {
        // Analyst replies can contain HTML from the editor; flatten to text first.
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text));
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }
}
