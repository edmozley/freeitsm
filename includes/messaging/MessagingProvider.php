<?php
/**
 * MessagingProvider — the provider-agnostic contract for a chat channel
 * (WhatsApp today; SMS/others later). Everything above this line — webhook
 * ingestion, ticket creation, the reply path — talks to this interface and
 * never knows whether Twilio or Meta Cloud is actually live.
 *
 * Concrete providers: TwilioProvider, MetaCloudProvider. They are constructed
 * with a decrypted messaging_channels row (see messaging.php → messagingProvider()).
 *
 * A "normalised inbound message" (the shape parseInbound returns) is:
 *   [
 *     'from'           => '+447700900123',   // sender, normalised (+ and digits)
 *     'to'             => '+14155238886',    // the business number it arrived on
 *     'body'           => 'Hi, my laptop…',  // plain text
 *     'profile_name'   => 'Jane Doe',        // sender display name, or ''
 *     'provider_msg_id'=> 'SMxxxx',          // provider's message id (dedupe)
 *     'media'          => [ ['url'=>…, 'content_type'=>…], … ],
 *     'timestamp'      => 1719500000,        // unix seconds, or null
 *   ]
 */

abstract class MessagingProvider
{
    /** @var array decrypted messaging_channels row (credentials already a PHP array) */
    protected $channel;

    public function __construct(array $channel)
    {
        $this->channel = $channel;
    }

    /** Channel type this instance serves, e.g. 'whatsapp'. */
    public function getType(): string
    {
        return $this->channel['channel_type'] ?? 'whatsapp';
    }

    /**
     * Verify an inbound webhook is genuinely from the provider (signature /
     * shared-secret check). Return false to reject — the endpoint will 403.
     *
     * @param string $rawBody raw request body
     * @param array  $headers request headers, lower-cased keys
     * @param array  $params  parsed POST params (form providers like Twilio)
     * @param string $url     the full public URL the provider hit (Twilio signs it)
     */
    abstract public function verifyWebhook(string $rawBody, array $headers, array $params, string $url): bool;

    /**
     * Meta-style GET verification handshake (hub.challenge). Return the challenge
     * string to echo, or null if this provider/request doesn't use it (Twilio).
     */
    public function verifyChallenge(array $get): ?string
    {
        return null;
    }

    /**
     * Parse an inbound webhook into zero or more normalised messages (see the
     * file header for the shape). Returns [] for non-message events (status
     * callbacks, etc.) so the caller can simply skip them.
     */
    abstract public function parseInbound(string $rawBody, array $params): array;

    /**
     * Send a free-text message to a recipient (within the 24h service window).
     * Returns the provider's message id. Throws on failure.
     */
    abstract public function sendMessage(string $to, string $body): string;

    /**
     * Send a pre-approved template message (the 24h-window escape hatch).
     * Phase 3 — providers may leave this unimplemented for now.
     */
    public function sendTemplate(string $to, string $template, array $vars): string
    {
        throw new Exception('Template messages are not yet supported for this provider.');
    }

    /**
     * Shared cURL helper. Returns [httpCode, bodyString]. No exceptions on HTTP
     * error codes — the caller decides what a bad status means.
     *
     * @param array $opts ['method'=>'POST', 'headers'=>[], 'body'=>string, 'auth'=>'user:pass']
     */
    protected function httpRequest(string $url, array $opts = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opts['method'] ?? 'GET');
        if (!empty($opts['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
        }
        if (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        if (!empty($opts['auth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $opts['auth']);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Network error talking to messaging provider: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    }
}
