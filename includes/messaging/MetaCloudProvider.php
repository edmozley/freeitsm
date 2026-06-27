<?php
/**
 * MetaCloudProvider — WhatsApp via Meta's WhatsApp Cloud API (direct, no BSP).
 *
 * Cheapest long-term path, but requires Meta business verification before it can
 * send/receive — which is why TwilioProvider is the recommended first build. The
 * class exists now so "abstract both providers" is real from day one; the Twilio
 * path is the tested one for Phase 1.
 *
 * Credentials JSON (messaging_channels.credentials, encrypted at rest):
 *   { "phone_number_id": "1234567890", "access_token": "EAAxxxx", "app_secret": "xxxx" }
 * messaging_channels.verify_token holds the hub.verify_token used for the GET
 * subscription handshake.
 *
 * Inbound: Meta POSTs JSON (entry[].changes[].value.messages[]). Authenticity is
 * the X-Hub-Signature-256 header: 'sha256=' . HMAC-SHA256(app_secret, rawBody).
 */

require_once __DIR__ . '/MessagingProvider.php';

class MetaCloudProvider extends MessagingProvider
{
    // Default Graph API version. Meta deprecates a version roughly two years after
    // release, so this is bumped periodically. An install can override it per channel
    // without a code change by adding "graph_version" to the credentials JSON.
    private const GRAPH_VERSION = 'v21.0';

    public function verifyChallenge(array $get): ?string
    {
        $mode      = $get['hub_mode']         ?? ($get['hub.mode'] ?? '');
        $token     = $get['hub_verify_token'] ?? ($get['hub.verify_token'] ?? '');
        $challenge = $get['hub_challenge']    ?? ($get['hub.challenge'] ?? '');
        $expected  = $this->channel['verify_token'] ?? '';
        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, (string) $token)) {
            return (string) $challenge;
        }
        return null;
    }

    public function verifyWebhook(string $rawBody, array $headers, array $params, string $url): bool
    {
        $sig = $headers['x-hub-signature-256'] ?? '';
        $secret = $this->channel['credentials']['app_secret'] ?? '';
        if ($sig === '' || $secret === '' || strpos($sig, 'sha256=') !== 0) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $sig);
    }

    public function parseInbound(string $rawBody, array $params): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [];
        }
        $out = [];
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                // Map waid → profile name from the contacts block.
                $names = [];
                foreach (($value['contacts'] ?? []) as $contact) {
                    $names[$contact['wa_id'] ?? ''] = $contact['profile']['name'] ?? '';
                }
                foreach (($value['messages'] ?? []) as $msg) {
                    if (($msg['type'] ?? '') !== 'text') {
                        // Phase 1 handles text; media/interactive come in Phase 3.
                        continue;
                    }
                    $from = $this->ensurePlus($msg['from'] ?? '');
                    $out[] = [
                        'from'            => $from,
                        'to'              => $this->ensurePlus($value['metadata']['display_phone_number'] ?? ''),
                        'body'            => trim($msg['text']['body'] ?? ''),
                        'profile_name'    => $names[$msg['from'] ?? ''] ?? '',
                        'provider_msg_id' => $msg['id'] ?? '',
                        'media'           => [],
                        'timestamp'       => isset($msg['timestamp']) ? (int) $msg['timestamp'] : null,
                    ];
                }
            }
        }
        return $out;
    }

    public function sendMessage(string $to, string $body): string
    {
        $phoneId = $this->channel['credentials']['phone_number_id'] ?? '';
        $token   = $this->channel['credentials']['access_token'] ?? '';
        if ($phoneId === '' || $token === '') {
            throw new Exception('Meta channel is missing its phone number id or access token.');
        }

        $version = $this->channel['credentials']['graph_version'] ?? self::GRAPH_VERSION;
        $url = 'https://graph.facebook.com/' . $version . "/$phoneId/messages";
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to'                => ltrim($this->ensurePlus($to), '+'),
            'type'              => 'text',
            'text'              => ['body' => $body],
        ]);

        [$code, $resp] = $this->httpRequest($url, [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json', "Authorization: Bearer $token"],
            'body'    => $payload,
        ]);

        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $code);
            throw new Exception('Meta rejected the message: ' . $msg);
        }
        return $json['messages'][0]['id'] ?? '';
    }

    private function ensurePlus(string $number): string
    {
        $number = ltrim(trim($number), '+');
        return $number === '' ? '' : '+' . $number;
    }
}
