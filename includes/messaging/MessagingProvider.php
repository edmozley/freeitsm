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
    /**
     * Keeps the decrypted messaging_channels row the provider works with.
     *
     * readonly (4.0): the row holds DECRYPTED credentials, so nothing may swap or alter
     * it once the provider has it - set here, once, and never again.
     *
     * @param array $channel  the messaging_channels row, decrypted (credentials already a PHP array)
     */
    public function __construct(protected readonly array $channel) {}

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
     *
     * @param array $get  the request's query parameters ($_GET)
     */
    public function verifyChallenge(array $get): ?string
    {
        return null;
    }

    /**
     * Parse an inbound webhook into zero or more normalised messages (see the
     * file header for the shape). Returns [] for non-message events (status
     * callbacks, etc.) so the caller can simply skip them.
     *
     * @param string $rawBody  the raw request body
     * @param array $params  the parsed POST parameters (form-posting providers such as Twilio)
     */
    abstract public function parseInbound(string $rawBody, array $params): array;

    /**
     * Send a free-text message to a recipient (within the 24h service window).
     * Returns the provider's message id. Throws on failure.
     *
     * @param string $to  the recipient, in the provider's address form (e.g. '+447700900123')
     * @param string $body  the plain-text message
     */
    abstract public function sendMessage(string $to, string $body): string;

    /**
     * Send a pre-approved template message (the 24h-window escape hatch).
     *
     * @param string $to       recipient, '+digits'
     * @param array  $template a messaging_templates row (provider_ref, language, body…)
     * @param array  $vars     ordered placeholder values for {{1}}, {{2}}, …
     * @return string provider message id
     */
    public function sendTemplate(string $to, array $template, array $vars): string
    {
        throw new Exception('Template messages are not supported for this provider.');
    }

    /**
     * Ask the customer to rate the service 1–5 (CSAT). Default: a plain message
     * saying to reply with one digit, which the webhook then records (see
     * csatPendingRequestForChat()). A channel with real buttons overrides this
     * to show them; $responseId ties a button press back to its csat row.
     *
     * @param string $to  the recipient, in the provider's address form
     * @param string $text  the question to send
     * @param int $responseId  the ticket_csat_responses id a button press is tied back to
     */
    public function sendRatingRequest(string $to, string $text, int $responseId): string
    {
        return $this->sendMessage($to, $text);
    }

    /**
     * Send a single local file (image, document, …) to a recipient — the
     * outbound twin of downloadMedia(). Returns the provider's message id.
     * Throws on failure, and by default for any provider that doesn't
     * support this yet.
     *
     * Two ways to reach the file, because providers disagree on which they
     * need:
     *   $filePath  — a path on THIS server's disk (already validated — see
     *                uploadStoreFile()). Meta and Telegram upload these
     *                bytes directly (multipart).
     *   $publicUrl — a short-lived, unguessable URL to the SAME file
     *                (messagingOutboundMediaUrl()). Twilio's API takes no
     *                upload at all — it fetches the media itself from a URL
     *                you give it — so a provider that needs this throws if
     *                it's blank rather than silently sending nothing.
     * A provider uses whichever it needs and ignores the other.
     *
     *   $filename  — the name the RECIPIENT sees. On disk the file has our own
     *                random name (uploadStoreFile()), which is right for the
     *                server and meaningless to a customer. Blank falls back to
     *                the stored name.
     *
     * @param string $to  the recipient, in the provider's address form
     * @param string $filePath  the file on this server's disk, already validated
     * @param string $mimeType  the file's MIME type
     * @param string $caption  text sent with the file; '' for none
     * @param string $publicUrl  a short-lived URL to the same file, for providers that fetch it themselves; '' if none
     * @param string $filename  the name the recipient sees; '' uses the stored name
     */
    public function sendMedia(string $to, string $filePath, string $mimeType, string $caption = '', string $publicUrl = '', string $filename = ''): string
    {
        throw new Exception('Sending attachments is not supported for this channel yet.');
    }

    /**
     * Verify the channel's credentials against the provider with a lightweight,
     * read-only API call (no message is sent). Returns a short human-readable
     * success detail (e.g. the account/number it reached). Throws on failure with
     * a message suitable for showing the analyst.
     */
    public function testConnection(): string
    {
        throw new Exception('Connection test not supported for this provider.');
    }

    /**
     * Download one inbound media item (an entry from parseInbound's 'media' array).
     * Returns ['data' => binary, 'content_type' => string, 'filename' => string].
     * Throws on failure.
     *
     * @param array $item  one entry from parseInbound()'s 'media' list
     */
    public function downloadMedia(array $item): array
    {
        throw new Exception('Media download not supported for this provider.');
    }

    /** @var callable|null see httpRequest() - tests only */
    public static $testTransport = null;

    /**
     * Shared cURL helper. Returns [httpCode, bodyString]. No exceptions on HTTP
     * error codes — the caller decides what a bad status means.
     *
     * @param string $url  the full URL to call
     * @param array $opts ['method'=>'POST', 'headers'=>[], 'body'=>string, 'auth'=>'user:pass']
     */
    protected function httpRequest(string $url, array $opts = []): array
    {
        // Tests only: fn(string $url, array $opts): [int $code, string $body].
        // Lets a test run the real path and see what would have been sent,
        // without a network call. Never set outside tests/.
        if (self::$testTransport !== null) {
            return (self::$testTransport)($url, $opts);
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        // Honour the app-wide SSL verification setting (config.php SSL_VERIFY_PEER),
        // exactly like the email/AI/vCenter cURL calls — so a dev box without a CA
        // bundle behaves consistently, and production can turn verification back on.
        sslApplyCurl($ch);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opts['method'] ?? 'GET');
        if (!empty($opts['follow'])) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        }
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
            throw new Exception('Network error talking to messaging provider: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, $body];
    }

    /**
     * POST multipart/form-data — for uploading a file to a provider. Returns
     * [httpCode, bodyString], like httpRequest().
     *
     * Separate from httpRequest() because a multipart upload is the one shape that
     * helper cannot express: it always sends a STRING body, and a file upload needs
     * cURL to build the body AND the boundary header itself from an ARRAY of
     * fields (a CURLFile among them). Do not set a Content-Type header here; cURL
     * must write it, boundary included.
     *
     * PR #159 wrote this out twice (Telegram and Meta). One copy means the SSL
     * setting, timeout and error wording cannot drift between providers.
     *
     * @param string $url  the full URL to POST to
     * @param array $fields  form fields; a file is a CURLFile
     * @param array $headers extra headers, e.g. ["Authorization: Bearer …"]
     */
    protected function httpMultipart(string $url, array $fields, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);   // an upload, not a ping
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        sslApplyCurl($ch);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            throw new Exception('Network error uploading to messaging provider: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, $body];
    }

    /**
     * The name a recipient sees for an outbound file: the given one, else the stored one.
     *
     * @param string $filePath  the file's path on disk; its base name is the fallback
     * @param string $filename  the name asked for; CR, LF and double quotes are stripped
     */
    protected function outboundFilename(string $filePath, string $filename): string
    {
        $name = trim(str_replace(["\r", "\n", '"'], '', $filename));
        return $name !== '' ? $name : basename($filePath);
    }
}
