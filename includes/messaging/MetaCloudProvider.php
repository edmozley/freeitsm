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
                    $type = $msg['type'] ?? '';
                    $entry = [
                        'from'            => $this->ensurePlus($msg['from'] ?? ''),
                        'to'              => $this->ensurePlus($value['metadata']['display_phone_number'] ?? ''),
                        'body'            => '',
                        'profile_name'    => $names[$msg['from'] ?? ''] ?? '',
                        'provider_msg_id' => $msg['id'] ?? '',
                        'media'           => [],
                        'timestamp'       => isset($msg['timestamp']) ? (int) $msg['timestamp'] : null,
                    ];
                    if ($type === 'text') {
                        $entry['body'] = trim($msg['text']['body'] ?? '');
                    } elseif (in_array($type, ['image', 'document', 'audio', 'video', 'voice', 'sticker'], true)) {
                        // Media arrives as an id to fetch later (see downloadMedia); caption (if any) is the body.
                        $m = $msg[$type] ?? [];
                        $entry['body'] = trim($m['caption'] ?? '');
                        $entry['media'][] = [
                            'id'           => $m['id'] ?? '',
                            'content_type' => $m['mime_type'] ?? 'application/octet-stream',
                            'filename'     => $m['filename'] ?? '',
                        ];
                    } else {
                        // location / contacts / interactive etc. — not handled yet.
                        continue;
                    }
                    $out[] = $entry;
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

    public function sendTemplate(string $to, array $template, array $vars): string
    {
        $phoneId = $this->channel['credentials']['phone_number_id'] ?? '';
        $token   = $this->channel['credentials']['access_token'] ?? '';
        $name    = $template['provider_ref'] ?? '';
        $lang    = $template['language'] ?? 'en';
        if ($phoneId === '' || $token === '') {
            throw new Exception('Meta channel is missing its phone number id or access token.');
        }
        if ($name === '') {
            throw new Exception('This template has no Meta template name set.');
        }

        $templatePayload = [
            'name'     => $name,
            'language' => ['code' => $lang],
        ];
        if (!empty($vars)) {
            $templatePayload['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(function ($v) {
                    return ['type' => 'text', 'text' => (string) $v];
                }, array_values($vars)),
            ]];
        }

        $version = $this->channel['credentials']['graph_version'] ?? self::GRAPH_VERSION;
        $url = 'https://graph.facebook.com/' . $version . "/$phoneId/messages";
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to'                => ltrim($this->ensurePlus($to), '+'),
            'type'              => 'template',
            'template'          => $templatePayload,
        ]);

        [$code, $resp] = $this->httpRequest($url, [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json', "Authorization: Bearer $token"],
            'body'    => $payload,
        ]);
        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300) {
            throw new Exception('Meta rejected the template: ' . ($json['error']['message'] ?? ('HTTP ' . $code)));
        }
        return $json['messages'][0]['id'] ?? '';
    }

    public function downloadMedia(array $item): array
    {
        $token = $this->channel['credentials']['access_token'] ?? '';
        $id    = $item['id'] ?? '';
        if ($token === '') {
            throw new Exception('Meta channel is missing its access token.');
        }
        if ($id === '') {
            throw new Exception('Media item has no id.');
        }
        $version = $this->channel['credentials']['graph_version'] ?? self::GRAPH_VERSION;

        // Step 1: resolve the media id to a (short-lived, auth-gated) download URL.
        [$c1, $r1] = $this->httpRequest("https://graph.facebook.com/$version/$id", [
            'method'  => 'GET',
            'headers' => ["Authorization: Bearer $token"],
        ]);
        $meta = json_decode($r1, true);
        if ($c1 < 200 || $c1 >= 300 || empty($meta['url'])) {
            throw new Exception('Meta media lookup failed: ' . ($meta['error']['message'] ?? ('HTTP ' . $c1)));
        }

        // Step 2: download the bytes (also requires the bearer token).
        [$c2, $body] = $this->httpRequest($meta['url'], [
            'method'  => 'GET',
            'headers' => ["Authorization: Bearer $token"],
            'follow'  => true,
        ]);
        if ($c2 < 200 || $c2 >= 300 || $body === '') {
            throw new Exception('Meta media download failed (HTTP ' . $c2 . ').');
        }

        $mime = $item['content_type'] ?: ($meta['mime_type'] ?? 'application/octet-stream');
        $filename = ($item['filename'] ?? '') !== '' ? $item['filename'] : ('media.' . messagingExtForMime($mime));
        return ['data' => $body, 'content_type' => $mime, 'filename' => $filename];
    }

    public function testConnection(): string
    {
        $phoneId = $this->channel['credentials']['phone_number_id'] ?? '';
        $token   = $this->channel['credentials']['access_token'] ?? '';
        if ($phoneId === '' || $token === '') {
            throw new Exception('Missing Phone number ID or Access token.');
        }

        // Read-only: fetch the phone number resource. Validates the token + id.
        $version = $this->channel['credentials']['graph_version'] ?? self::GRAPH_VERSION;
        $url = 'https://graph.facebook.com/' . $version . "/$phoneId?fields=display_phone_number,verified_name";
        [$code, $resp] = $this->httpRequest($url, [
            'method'  => 'GET',
            'headers' => ["Authorization: Bearer $token"],
        ]);
        $json = json_decode($resp, true);

        if ($code < 200 || $code >= 300) {
            throw new Exception($json['error']['message'] ?? ('Meta returned HTTP ' . $code));
        }

        $name   = $json['verified_name'] ?? '';
        $number = $json['display_phone_number'] ?? $phoneId;
        return 'Connected to Meta WhatsApp number ' . ($name !== '' ? "\"$name\" ($number)." : "$number.");
    }

    private function ensurePlus(string $number): string
    {
        $number = ltrim(trim($number), '+');
        return $number === '' ? '' : '+' . $number;
    }
}
