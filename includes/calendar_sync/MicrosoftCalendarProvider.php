<?php
/**
 * MicrosoftCalendarProvider — scheduled work into an analyst's Exchange calendar
 * via Microsoft Graph (GH discussion #75).
 *
 * 🔑 APP-ONLY, NOT PER-ANALYST OAUTH. The app authenticates as itself with the
 * client-credentials flow and writes to /users/<address>/events. There is no
 * consent flow, no refresh token and no per-analyst grant — an administrator
 * adds Calendars.ReadWrite (APPLICATION, not delegated) to the app registration
 * once, consents once, and every analyst can be enrolled. This is the single
 * fact that makes the feature practical on an install with fifty analysts.
 *
 * ⚠️ THAT PERMISSION REACHES EVERY MAILBOX IN THE TENANT. Microsoft's mitigation
 * is an Application Access Policy scoping the app to a mail-enabled security
 * group. The setup screen must say so plainly — an administrator discovering the
 * scope for themselves, after the fact, is how trust in an integration dies.
 *
 * Verifying a mailbox uses /users/<address>/calendar rather than /users/<address>
 * ON PURPOSE: the latter needs User.Read.All, a second application permission,
 * for information we do not otherwise want. Reading the calendar we are about to
 * write to is covered by the permission we already require.
 */

require_once __DIR__ . '/CalendarSyncProvider.php';
require_once __DIR__ . '/../ssl.php';
require_once __DIR__ . '/../encryption.php';

class MicrosoftCalendarProvider extends CalendarSyncProvider
{
    const GRAPH = 'https://graph.microsoft.com/v1.0';

    // $conn (the optional PDO for persisting the token cache) is declared on the
    // base class, so assigning it is valid against the abstract type.

    /**
     * Microsoft Graph supports all-day events, HTML bodies, checking a mailbox exists, and change notifications.
     *
     * @param string $capability  a CalendarSyncProvider::CAP_* key
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, [
            self::CAP_ALL_DAY,
            self::CAP_BODY_HTML,
            self::CAP_VERIFY_TARGET,
            self::CAP_NOTIFY,
        ], true);
    }

    // ------------------------------------------------------------------ auth

    /**
     * An app-only access token, cached on the connection until it expires.
     *
     * App-only tokens carry no refresh token — when one expires we simply ask
     * for another. The 300-second margin is so a token that is valid when we
     * check cannot expire mid-request.
     */
    private function token(): string
    {
        $cached = json_decode((string)($this->connection['token_data'] ?? ''), true);
        if (is_array($cached) && !empty($cached['access_token'])
            && isset($cached['expires_at']) && $cached['expires_at'] > time() + 300) {
            return $cached['access_token'];
        }

        $creds = $this->connection['credentials'] ?? [];
        foreach (['tenant_id', 'client_id', 'client_secret'] as $k) {
            if (empty($creds[$k])) {
                throw new Exception('Calendar connection is missing ' . $k . '.');
            }
        }

        $ch = curl_init('https://login.microsoftonline.com/' . $creds['tenant_id'] . '/oauth2/v2.0/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'grant_type'    => 'client_credentials',
            'scope'         => 'https://graph.microsoft.com/.default',
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        sslApplyCurl($ch);
        $response = curl_exec($ch);
        $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) { $e = curl_error($ch); throw new Exception('cURL error: ' . $e); }

        if ($http !== 200) {
            throw new Exception('Token request failed (HTTP ' . $http . '): ' . $this->briefly($response));
        }
        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new Exception('No access_token in the token response.');
        }

        // Cache it. Encrypted, because a bearer token IS calendar access on its
        // own — the same lesson target_mailboxes.token_data learned the hard way.
        if ($this->conn && !empty($this->connection['id'])) {
            $json = json_encode([
                'access_token' => $data['access_token'],
                'expires_at'   => time() + ($data['expires_in'] ?? 3600),
            ]);
            try {
                $this->conn->prepare("UPDATE calendar_connections SET token_data = ? WHERE id = ?")
                           ->execute([encryptValue($json), (int)$this->connection['id']]);
            } catch (Exception) {
                // A cache that cannot be written is a performance problem, not a
                // failure — the token in hand is still good for this request.
            }
        }
        $this->connection['token_data'] = json_encode([
            'access_token' => $data['access_token'],
            'expires_at'   => time() + ($data['expires_in'] ?? 3600),
        ]);
        return $data['access_token'];
    }

    /**
     * Mint a token. If that succeeds the credentials are right and
     * Calendars.ReadWrite was granted and consented; nothing else can be true
     * and this fail.
     */
    public function verifyConnection(): void
    {
        $this->token();
    }

    // ----------------------------------------------------------------- graph

    /**
     * One Graph call. Returns [httpCode, decodedBody].
     *
     * @param string $method  the HTTP method - GET, POST, PATCH or DELETE
     * @param string $path  the Graph path after /v1.0, e.g. '/users/{address}/events', already URL-encoded where needed
     * @param ?array $body  the JSON body to send; null sends none
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::GRAPH . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $headers = ['Authorization: Bearer ' . $this->token()];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        sslApplyCurl($ch);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) { $e = curl_error($ch); throw new Exception('cURL error: ' . $e); }
        return [$http, json_decode((string)$raw, true), (string)$raw];
    }

    /**
     * Translate our canonical event into Graph's shape.
     *
     * ⚠️ TWO THINGS GRAPH IS STRICT ABOUT, and both are easy to get wrong:
     *
     * 1. dateTime must carry NO zone suffix, with the zone named separately in
     *    timeZone. Our stored values are naive wall-clock precisely so that "2pm"
     *    means 2pm — appending a Z would reinterpret every one of them as UTC and
     *    land the event an hour out for half the year.
     *
     * 2. isAllDay REQUIRES both ends at midnight and the end EXCLUSIVE — the day
     *    after the last day. We store all-day as 00:00–23:59:59 so that readers
     *    ignoring the flag still see a sensible block, so it has to be converted
     *    here. Sending 23:59:59 with isAllDay:true is rejected outright.
     *
     * @param array $event  the canonical event shape (see CalendarSyncProvider) - naive start/end plus timezone
     */
    private function toGraphEvent(array $event): array
    {
        $tz = $event['timezone'] ?: date_default_timezone_get();

        if (!empty($event['all_day'])) {
            $startDay = substr($event['start'], 0, 10);
            $endDay   = (new DateTime(substr($event['end'], 0, 10)))->modify('+1 day')->format('Y-m-d');
            $start    = $startDay . 'T00:00:00';
            $end      = $endDay   . 'T00:00:00';
        } else {
            $start = str_replace(' ', 'T', substr($event['start'], 0, 19));
            $end   = str_replace(' ', 'T', substr($event['end'],   0, 19));
        }

        $body = (string)($event['body'] ?? '');
        if (!empty($event['url'])) {
            $body .= ($body === '' ? '' : "\n\n") . $event['url'];
        }

        return [
            'subject'  => (string)($event['subject'] ?? ''),
            'body'     => ['contentType' => 'Text', 'content' => $body],
            'start'    => ['dateTime' => $start, 'timeZone' => $tz],
            'end'      => ['dateTime' => $end,   'timeZone' => $tz],
            'isAllDay' => !empty($event['all_day']),
            // Nobody is being invited: this is a note-to-self about your own work,
            // and a Graph event with attendees sends meeting invitations.
            'isReminderOn' => false,
        ];
    }

    // -------------------------------------------------------------- outbound

    /**
     * Creates the event in the mailbox's calendar through Graph and returns Graph's event id.
     *
     * @param string $calendarAddress  the analyst's mailbox address (UPN), as Graph knows it
     * @param array $event  the canonical event shape (see CalendarSyncProvider)
     */
    public function createEvent(string $calendarAddress, array $event): string
    {
        [$http, $data, $raw] = $this->call(
            'POST', '/users/' . rawurlencode($calendarAddress) . '/events', $this->toGraphEvent($event)
        );
        if ($http !== 201 || empty($data['id'])) {
            throw new Exception('Could not create the calendar event (HTTP ' . $http . '): ' . $this->briefly($raw));
        }
        return (string)$data['id'];
    }

    /**
     * PATCHes the event through Graph; throws CalendarEventMissing on a 404 so the caller can create a fresh one.
     *
     * @param string $calendarAddress  the analyst's mailbox address (UPN), as Graph knows it
     * @param string $remoteEventId  Graph's event id, as createEvent() returned it
     * @param array $event  the canonical event shape (see CalendarSyncProvider)
     */
    public function updateEvent(string $calendarAddress, string $remoteEventId, array $event): void
    {
        [$http, , $raw] = $this->call(
            'PATCH',
            '/users/' . rawurlencode($calendarAddress) . '/events/' . rawurlencode($remoteEventId),
            $this->toGraphEvent($event)
        );
        // Somebody deleting a FreeITSM event from their own calendar is ordinary,
        // not an error. Say so precisely so the caller can put a fresh one back
        // rather than the analyst quietly losing the entry for good.
        if ($http === 404) {
            throw new CalendarEventMissing('The calendar event no longer exists.');
        }
        if ($http < 200 || $http >= 300) {
            throw new Exception('Could not update the calendar event (HTTP ' . $http . '): ' . $this->briefly($raw));
        }
    }

    /**
     * Deletes the event through Graph; a 404 counts as success.
     *
     * @param string $calendarAddress  the analyst's mailbox address (UPN), as Graph knows it
     * @param string $remoteEventId  Graph's event id, as createEvent() returned it
     */
    public function deleteEvent(string $calendarAddress, string $remoteEventId): void
    {
        [$http, , $raw] = $this->call(
            'DELETE',
            '/users/' . rawurlencode($calendarAddress) . '/events/' . rawurlencode($remoteEventId)
        );
        // 404 is SUCCESS. The desired end state is "not in their calendar", and
        // that is satisfied. Treating it as a failure would strand the map row
        // and retry for ever against an event that cannot come back.
        if ($http === 404 || ($http >= 200 && $http < 300)) {
            return;
        }
        throw new Exception('Could not remove the calendar event (HTTP ' . $http . '): ' . $this->briefly($raw));
    }

    // -------------------------------------------------------------- inbound

    /**
     * Read changes back out of the mailbox with a Graph delta query.
     *
     * 🔑 DELTA, NOT SUBSCRIPTIONS. Change notifications need a publicly
     * reachable HTTPS endpoint Microsoft can call, plus renewal before it
     * expires — impossible for the many FreeITSM installs that are internal
     * only. A delta query is an ordinary outbound GET on a cron: same
     * information, no inbound firewall hole.
     *
     * ⚠️ calendarView/delta needs a WINDOW, and it is the expanded view. The
     * window is deliberately narrow — recent past to a few months ahead — since
     * a wider one makes the first sync enormous for no benefit; nobody edits
     * last year's appointments.
     *
     * @param string $calendarAddress  the analyst's mailbox address (UPN), as Graph knows it
     * @param ?string $token  the full deltaLink the last poll returned; null or '' takes a baseline
     */
    public function pollChanges(string $calendarAddress, ?string $token): array
    {
        $out = ['token' => null, 'baseline' => false, 'changed' => [], 'removed' => []];

        if ($token) {
            $url = $token;                                   // a full deltaLink
        } else {
            $out['baseline'] = true;                         // no history yet
            $url = self::GRAPH . '/users/' . rawurlencode($calendarAddress) . '/calendarView/delta'
                 . '?startDateTime=' . gmdate('Y-m-d\TH:i:s\Z', strtotime('-1 month'))
                 . '&endDateTime='   . gmdate('Y-m-d\TH:i:s\Z', strtotime('+6 months'));
        }

        $tz    = date_default_timezone_get();
        $guard = 0;
        while ($url && $guard++ < 20) {                      // pages, bounded
            [$http, $body, $raw] = $this->callAbsolute($url);

            // 410 Gone: our token is no longer valid. Start again WITHOUT a
            // token — and the result is a baseline, so the caller applies
            // nothing. This is the case that would otherwise look like "every
            // event was deleted".
            if ($http === 410) {
                return $this->pollChanges($calendarAddress, null);
            }
            if ($http < 200 || $http >= 300) {
                throw new Exception('Delta query failed (HTTP ' . $http . '): ' . $this->briefly($raw));
            }

            foreach (($body['value'] ?? []) as $item) {
                $id = (string)($item['id'] ?? '');
                if ($id === '') continue;

                if (isset($item['@removed'])) {
                    $out['removed'][] = $id;
                    continue;
                }
                if (empty($item['start']['dateTime']) || empty($item['end']['dateTime'])) {
                    continue;                                // nothing we can use
                }
                // Graph answers in UTC unless asked otherwise; convert back to the
                // naive wall clock FreeITSM stores, or every change would come
                // back an hour out for half the year.
                $out['changed'][] = [
                    'remote_event_id' => $id,
                    'start'   => $this->toLocal($item['start'], $tz),
                    'end'     => $this->toLocal($item['end'], $tz),
                    'all_day' => !empty($item['isAllDay']),
                ];
            }

            $url = $body['@odata.nextLink'] ?? null;
            if (!$url && !empty($body['@odata.deltaLink'])) {
                $out['token'] = $body['@odata.deltaLink'];
            }
        }
        return $out;
    }

    /**
     * Graph's {dateTime,timeZone} to a naive wall clock in $tz.
     *
     * @param array $slot  Graph's {dateTime, timeZone} pair for an event's start or end
     * @param string $tz  the PHP time zone to give the naive wall-clock time in
     */
    private function toLocal(array $slot, string $tz): string
    {
        $from = new DateTimeZone(($slot['timeZone'] ?? 'UTC') === 'UTC' ? 'UTC' : $slot['timeZone']);
        $dt   = new DateTime(substr($slot['dateTime'], 0, 19), $from);
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * A GET against a URL Graph gave us (nextLink / deltaLink are absolute).
     *
     * @param string $url  an absolute Graph URL - a nextLink, a deltaLink, or the first delta query
     */
    private function callAbsolute(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token(),
            // Keeps a delta page to a sane size on a busy calendar.
            'Prefer: odata.maxpagesize=100',
        ]);
        sslApplyCurl($ch);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) { $e = curl_error($ch); throw new Exception('cURL error: ' . $e); }
        return [$http, json_decode((string)$raw, true), (string)$raw];
    }

    // --------------------------------------------------- change notifications

    /**
     * Graph caps calendar subscriptions at 4230 minutes (a shade under three
     * days). We ask for a little less so a clock skew cannot make the request
     * itself invalid, and the cron renews well before it lapses.
     */
    const SUBSCRIPTION_MINUTES = 4100;

    /**
     * Subscribes to updates and deletions in the mailbox's calendar, posting to $notifyUrl with $secret as clientState, for SUBSCRIPTION_MINUTES.
     *
     * @param string $calendarAddress  the analyst's mailbox address (UPN), as Graph knows it
     * @param string $notifyUrl  the public HTTPS endpoint Graph posts notifications to (Graph checks it during this call)
     * @param string $secret  sent as clientState and echoed back in every notification, to prove it came from Graph
     * @return array  ['id' => subscription id, 'expires' => naive local datetime]
     */
    public function createSubscription(string $calendarAddress, string $notifyUrl, string $secret): array
    {
        [$http, $data, $raw] = $this->call('POST', '/subscriptions', [
            'changeType'          => 'updated,deleted',
            'notificationUrl'     => $notifyUrl,
            'resource'            => 'users/' . $calendarAddress . '/events',
            'expirationDateTime'  => gmdate('Y-m-d\TH:i:s\Z', time() + self::SUBSCRIPTION_MINUTES * 60),
            'clientState'         => $secret,
        ]);
        if ($http !== 201 || empty($data['id'])) {
            // ⚠️ The most common failure here is not credentials — it is that
            // Graph could not reach the URL. It validates the endpoint
            // synchronously during this call, so "could not validate" means
            // your notification URL is wrong, unreachable, or not HTTPS.
            throw new Exception('Could not subscribe (HTTP ' . $http . '): ' . $this->briefly($raw));
        }
        return ['id' => (string)$data['id'], 'expires' => $this->expiryToLocal($data['expirationDateTime'] ?? null)];
    }

    /**
     * Extends a Graph subscription by SUBSCRIPTION_MINUTES; throws CalendarSubscriptionMissing when it has already lapsed.
     *
     * @param string $subscriptionId  Graph's subscription id, as createSubscription() returned it
     */
    public function renewSubscription(string $subscriptionId): array
    {
        [$http, $data, $raw] = $this->call('PATCH', '/subscriptions/' . rawurlencode($subscriptionId), [
            'expirationDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() + self::SUBSCRIPTION_MINUTES * 60),
        ]);
        // 404: it lapsed before we got to it. Say so precisely so the caller
        // creates a fresh one rather than retrying a renewal for ever.
        if ($http === 404) {
            throw new CalendarSubscriptionMissing('The subscription no longer exists.');
        }
        if ($http < 200 || $http >= 300) {
            throw new Exception('Could not renew the subscription (HTTP ' . $http . '): ' . $this->briefly($raw));
        }
        return ['id' => $subscriptionId, 'expires' => $this->expiryToLocal($data['expirationDateTime'] ?? null)];
    }

    /**
     * Removes a Graph subscription; one already gone counts as success.
     *
     * @param string $subscriptionId  Graph's subscription id, as createSubscription() returned it
     */
    public function deleteSubscription(string $subscriptionId): void
    {
        [$http, , $raw] = $this->call('DELETE', '/subscriptions/' . rawurlencode($subscriptionId));
        if ($http === 404 || ($http >= 200 && $http < 300)) return;   // already gone is success
        throw new Exception('Could not remove the subscription (HTTP ' . $http . '): ' . $this->briefly($raw));
    }

    /**
     * Graph's UTC expiry to the naive local datetime the column stores.
     *
     * @param ?string $utc  Graph's expirationDateTime (UTC, ISO 8601); null uses now plus SUBSCRIPTION_MINUTES
     */
    private function expiryToLocal(?string $utc): string
    {
        $ts = $utc ? strtotime($utc) : (time() + self::SUBSCRIPTION_MINUTES * 60);
        return date('Y-m-d H:i:s', $ts ?: time());
    }

    // ------------------------------------------------------------- discovery

    /**
     * True when Graph can read the mailbox's calendar - i.e. the address exists and the app can reach it.
     *
     * @param string $calendarAddress  the mailbox address (UPN) to check
     */
    public function verifyTarget(string $calendarAddress): bool
    {
        try {
            [$http] = $this->call('GET', '/users/' . rawurlencode($calendarAddress) . '/calendar?$select=id');
        } catch (Exception) {
            return false;
        }
        return $http >= 200 && $http < 300;
    }

    /**
     * Graph error bodies are long and carry request ids. Keep enough to diagnose
     * without putting a wall of JSON in last_error, which is 500 characters and
     * is shown to a human.
     *
     * @param ?string $raw  the raw response body from Graph or the token endpoint
     */
    private function briefly(?string $raw): string
    {
        $decoded = json_decode((string)$raw, true);
        $msg = $decoded['error']['message'] ?? (string)$raw;
        $msg = trim(preg_replace('/\s+/', ' ', $msg));
        return strlen($msg) > 300 ? substr($msg, 0, 297) . '…' : $msg;
    }
}
