<?php
/**
 * TwilioProvider — WhatsApp via Twilio's Messaging API.
 *
 * This is the recommended first-build provider because Twilio offers a WhatsApp
 * **sandbox**: you can send/receive end-to-end on a dev box (WAMP + ngrok) with
 * no Meta business verification. The production path is identical once your own
 * WhatsApp sender is approved.
 *
 * Credentials JSON (messaging_channels.credentials, encrypted at rest):
 *   { "account_sid": "ACxxxx", "auth_token": "xxxx" }
 * The business WhatsApp number is messaging_channels.phone_number (the "From").
 *
 * Inbound: Twilio POSTs application/x-www-form-urlencoded with From/To/Body/
 * MessageSid/ProfileName/NumMedia/MediaUrl{n}/MediaContentType{n}. Authenticity is
 * the X-Twilio-Signature header: base64(HMAC-SHA1(authToken, url + sorted POST kv)).
 */

require_once __DIR__ . '/MessagingProvider.php';

class TwilioProvider extends MessagingProvider
{
    public function verifyWebhook(string $rawBody, array $headers, array $params, string $url): bool
    {
        $signature = $headers['x-twilio-signature'] ?? '';
        $authToken = $this->channel['credentials']['auth_token'] ?? '';
        if ($signature === '' || $authToken === '') {
            return false;
        }
        // Twilio's scheme: the full URL, then every POST param appended as key+value
        // in alphabetical key order, HMAC-SHA1'd with the auth token, base64-encoded.
        $data = $url;
        ksort($params);
        foreach ($params as $key => $value) {
            $data .= $key . $value;
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));
        return hash_equals($expected, $signature);
    }

    public function parseInbound(string $rawBody, array $params): array
    {
        // A status callback (delivered/read) has MessageStatus but no Body/From pair
        // we care about — skip anything without a sender + a message id.
        $from = $this->stripPrefix($params['From'] ?? '');
        $msgId = $params['MessageSid'] ?? ($params['SmsSid'] ?? '');
        if ($from === '' || $msgId === '') {
            return [];
        }

        $media = [];
        $numMedia = (int) ($params['NumMedia'] ?? 0);
        for ($i = 0; $i < $numMedia; $i++) {
            if (!empty($params["MediaUrl$i"])) {
                $media[] = [
                    'url'          => $params["MediaUrl$i"],
                    'content_type' => $params["MediaContentType$i"] ?? 'application/octet-stream',
                ];
            }
        }

        return [[
            'from'            => $from,
            'to'              => $this->stripPrefix($params['To'] ?? ''),
            'body'            => trim($params['Body'] ?? ''),
            'profile_name'    => trim($params['ProfileName'] ?? ''),
            'provider_msg_id' => $msgId,
            'media'           => $media,
            'timestamp'       => null,
        ]];
    }

    public function sendMessage(string $to, string $body): string
    {
        $sid   = $this->channel['credentials']['account_sid'] ?? '';
        $token = $this->channel['credentials']['auth_token'] ?? '';
        $from  = $this->channel['phone_number'] ?? '';
        if ($sid === '' || $token === '' || $from === '') {
            throw new Exception('Twilio channel is missing its Account SID, Auth Token or From number.');
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
        $post = http_build_query([
            'From' => 'whatsapp:' . $this->ensurePlus($from),
            'To'   => 'whatsapp:' . $this->ensurePlus($to),
            'Body' => $body,
        ]);

        [$code, $resp] = $this->httpRequest($url, [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body'    => $post,
            'auth'    => "$sid:$token",
        ]);

        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300) {
            $msg = $json['message'] ?? ('HTTP ' . $code);
            throw new Exception('Twilio rejected the message: ' . $msg);
        }
        return $json['sid'] ?? '';
    }

    /** Drop Twilio's "whatsapp:" channel prefix, leaving the bare number. */
    private function stripPrefix(string $addr): string
    {
        $addr = trim($addr);
        if (stripos($addr, 'whatsapp:') === 0) {
            $addr = substr($addr, strlen('whatsapp:'));
        }
        return trim($addr);
    }

    private function ensurePlus(string $number): string
    {
        $number = ltrim(trim($number), '+');
        return '+' . $number;
    }
}
