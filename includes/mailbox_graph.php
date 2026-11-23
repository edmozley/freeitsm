<?php
/**
 * Shared Microsoft Graph helpers for target mailboxes.
 *
 * Used by api/tickets/check_mailbox_email.php, send_email.php and
 * verify_mailbox_folder.php to support the two authentication modes:
 *
 *   - delegated : OAuth sign-in; the token is a user's, so calls go to /me.
 *   - app_only  : client-credentials; the app authenticates itself and reads the
 *                 specific /users/<target_mailbox>.
 *
 * Delegated token refresh stays in each caller (legacy, unchanged). This file only
 * adds the app-only token + the per-request Graph base path.
 *
 * Requires config.php (for SSL_VERIFY_PEER) to be loaded already. $mailbox must be
 * the DECRYPTED row (azure_* fields in clear text) — which now includes token_data,
 * so the cached app-only token read below is plaintext on the way in and re-encrypted
 * on the way out.
 */
require_once __DIR__ . '/mailbox_provider.php';   // MailboxProvider
require_once __DIR__ . '/encryption.php';   // encryptValue() for the token cache write

if (!function_exists('mailboxAppOnlyToken')) {

    /**
     * App-only access token via the client-credentials flow (no user, no interactive
     * sign-in). Cached in target_mailboxes.token_data until it expires (app-only tokens
     * carry no refresh token — we just re-fetch).
     *
     * @param mixed $mailbox  the target_mailboxes row, decrypted (azure_tenant_id, azure_client_id, azure_client_secret, token_data, id)
     */
    function mailboxAppOnlyToken($conn, $mailbox) {
        $cached = json_decode(preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($mailbox['token_data'] ?? '')), true);
        if (is_array($cached) && !empty($cached['app_only']) && !empty($cached['access_token'])
            && isset($cached['expires_at']) && $cached['expires_at'] > time() + 300) {
            return $cached['access_token'];
        }

        $tokenUrl = 'https://login.microsoftonline.com/' . $mailbox['azure_tenant_id'] . '/oauth2/v2.0/token';
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $mailbox['azure_client_id'],
            'client_secret' => $mailbox['azure_client_secret'],
            'grant_type'    => 'client_credentials',
            'scope'         => 'https://graph.microsoft.com/.default',
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        sslApplyCurl($ch);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) { $err = curl_error($ch); throw new Exception('cURL error: ' . $err); }

        if ($httpCode !== 200) {
            throw new Exception('client-credentials token request failed (HTTP ' . $httpCode . '): ' . $response);
        }
        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new Exception('No access_token in client-credentials response: ' . $response);
        }

        $cacheJson = json_encode([
            'app_only'     => true,
            'access_token' => $data['access_token'],
            'expires_at'   => time() + ($data['expires_in'] ?? 3600),
            'created_at'   => time(),
        ]);
        $conn->prepare("UPDATE target_mailboxes SET token_data = ? WHERE id = ?")
             ->execute([encryptValue($cacheJson), $mailbox['id']]);

        return $data['access_token'];
    }

    /**
     * Pull the signed-in user's address straight out of a Graph access token. The
     * token is a JWT whose payload carries the user's UPN / email — so we can read
     * it offline, with NO Graph permission. This matters because the usual mailbox
     * scopes (Mail.*) do NOT include User.Read, so a /me call 403s and can't tell us
     * who signed in. Returns a lowercased email, or '' if the token has no usable claim.
     *
     * @param mixed $jwt  a Graph access token (a JWT); its payload is read without checking the signature
     */
    function mailboxIdentityFromToken($jwt) {
        $parts = explode('.', (string) $jwt);
        if (count($parts) < 2) return '';
        $payload = strtr($parts[1], '-_', '+/');
        $pad = strlen($payload) % 4;
        if ($pad) $payload .= str_repeat('=', 4 - $pad);
        $json = base64_decode($payload, true);
        if ($json === false) return '';
        $claims = json_decode($json, true);
        if (!is_array($claims)) return '';
        // upn / preferred_username are the email-like identity claims; unique_name/email are fallbacks.
        $email = $claims['upn'] ?? $claims['preferred_username'] ?? $claims['email'] ?? $claims['unique_name'] ?? '';
        return ($email && str_contains($email, '@')) ? strtolower(trim($email)) : '';
    }

    /**
     * Who does a (delegated) access token belong to? Returns the lowercased email,
     * or '' if it can't be determined. Used to back-fill authenticated_as for
     * mailboxes that signed in before we started recording it.
     *
     * Reads the token's own JWT claims first (offline, no permission needed); only
     * falls back to Graph /me if the token isn't a readable JWT (needs User.Read).
     *
     * @param mixed $accessToken  a delegated Graph access token
     */
    function mailboxDelegatedIdentity($accessToken) {
        $fromToken = mailboxIdentityFromToken($accessToken);
        if ($fromToken !== '') return $fromToken;

        $ch = curl_init('https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        sslApplyCurl($ch);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200) return '';
        $data = json_decode($response, true);
        $email = $data['mail'] ?? $data['userPrincipalName'] ?? '';
        return $email ? strtolower(trim($email)) : '';
    }

    /**
     * Fetch ALL email addresses for the signed-in (delegated) mailbox — primary SMTP,
     * UPN and every alias (proxyAddresses). This is how we tell that e.g. ed@ is just
     * an alias of the edmozley@ mailbox: the token only carries the UPN, so we have to
     * ask Graph for the alias list. Needs the lightweight User.Read scope; returns []
     * if it isn't granted (older mailboxes) so the caller can fall back to the token claim.
     *
     * @param mixed $accessToken  a delegated Graph access token; needs User.Read for the alias list
     */
    function mailboxFetchAddresses($accessToken) {
        $ch = curl_init('https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName,proxyAddresses');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        sslApplyCurl($ch);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200) return [];
        $data = json_decode($response, true);
        if (!is_array($data)) return [];

        $set = [];
        foreach ([$data['mail'] ?? '', $data['userPrincipalName'] ?? ''] as $a) {
            if ($a && str_contains($a, '@')) $set[] = strtolower(trim($a));
        }
        foreach (($data['proxyAddresses'] ?? []) as $p) {
            // proxyAddresses look like "SMTP:ed@x" (primary) / "smtp:alias@x" (secondary).
            $addr = preg_replace('/^smtp:/i', '', (string) $p);
            if ($addr && str_contains($addr, '@')) $set[] = strtolower(trim($addr));
        }
        return array_values(array_unique($set));
    }

    /**
     * Build the identity record for a freshly-authenticated delegated token:
     *   ['primary' => <display address>, 'addresses' => [every address this mailbox owns]].
     * Combines the token's own claim (always available, offline) with the Graph alias
     * list (when User.Read is granted). 'primary' is what we show; 'addresses' is what
     * we match the configured target against.
     *
     * @param mixed $accessToken  a freshly issued delegated Graph access token
     */
    function mailboxIdentityRecord($accessToken) {
        $claim     = mailboxIdentityFromToken($accessToken);   // UPN, offline
        $addresses = mailboxFetchAddresses($accessToken);      // full set incl. aliases
        if ($claim !== '' && !in_array($claim, $addresses, true)) $addresses[] = $claim;
        $primary = $claim !== '' ? $claim : ($addresses[0] ?? '');
        return ['primary' => $primary, 'addresses' => array_values(array_unique($addresses))];
    }

    /**
     * The set of addresses a (delegated) mailbox is known to own, read back from the
     * stored columns (authenticated_addresses JSON + authenticated_as). Offline, lowercased.
     * Empty means "we don't know yet" — callers treat that as grandfathered, not a mismatch.
     *
     * @param mixed $mailbox  the target_mailboxes row (authenticated_addresses JSON, authenticated_as)
     */
    function mailboxAcceptedSet($mailbox) {
        $set = [];
        $json = $mailbox['authenticated_addresses'] ?? '';
        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) $set = $decoded;
        }
        $as = strtolower(trim((string) ($mailbox['authenticated_as'] ?? '')));
        if ($as !== '') $set[] = $as;
        return array_values(array_unique(array_filter(array_map(
            fn($x) => strtolower(trim((string) $x)), $set
        ))));
    }

    /**
     * Offline "is this reading the wrong inbox?" check for delegated Microsoft mailboxes.
     * Returns an error message to show/throw, or null if it's fine. A configured target
     * that matches ANY owned address (primary OR alias) passes; an unknown identity set
     * (legacy mailboxes that predate alias capture) is grandfathered through.
     *
     * @param mixed $mailbox  the target_mailboxes row, decrypted (provider, auth_mode, target_mailbox, authenticated_*)
     */
    function mailboxIdentityMismatch($mailbox) {
        if (MailboxProvider::fromMailbox($mailbox) !== MailboxProvider::Microsoft) return null;
        if (($mailbox['auth_mode'] ?? 'delegated') === 'app_only') return null;
        $target = strtolower(trim((string) ($mailbox['target_mailbox'] ?? '')));
        if ($target === '') return null;
        $set = mailboxAcceptedSet($mailbox);
        if (empty($set)) return null;                       // unknown — don't block
        if (in_array($target, $set, true)) return null;     // matches a primary or alias
        $shown = trim((string) ($mailbox['authenticated_as'] ?? '')) ?: implode(', ', $set);
        return 'Authentication mismatch — this mailbox is set to read ' . $mailbox['target_mailbox']
             . ' but is authenticated as ' . $shown
             . '. Re-authenticate as the correct account, or switch it to app-only mode.';
    }

    /**
     * Populate authenticated_as / authenticated_addresses for a delegated Microsoft
     * mailbox that doesn't have them yet (signed in before we recorded identity, or
     * before alias capture). Returns the (possibly updated) $mailbox. No-op for Google
     * and app-only.
     *
     * @param mixed $mailbox  the target_mailboxes row, decrypted; returned with the identity columns filled in
     * @param mixed $accessToken  the mailbox's current delegated Graph access token
     */
    function mailboxBackfillIdentity($conn, $mailbox, $accessToken) {
        if (MailboxProvider::fromMailbox($mailbox) !== MailboxProvider::Microsoft) return $mailbox;
        if (($mailbox['auth_mode'] ?? 'delegated') === 'app_only') return $mailbox;
        if (!empty($mailbox['authenticated_as']) && !empty($mailbox['authenticated_addresses'])) {
            return $mailbox;
        }
        $rec = mailboxIdentityRecord($accessToken);
        if ($rec['primary'] === '' && empty($rec['addresses'])) return $mailbox;
        $addressesJson = json_encode($rec['addresses']);
        $conn->prepare("UPDATE target_mailboxes SET authenticated_as = ?, authenticated_addresses = ? WHERE id = ?")
             ->execute([$rec['primary'] ?: null, $addressesJson, $mailbox['id']]);
        $mailbox['authenticated_as'] = $rec['primary'];
        $mailbox['authenticated_addresses'] = $addressesJson;
        return $mailbox;
    }

    /**
     * Per-request Graph base path: '/me' (delegated) or '/users/<addr>' (app-only).
     * Set once after the mailbox is loaded; the Graph helpers read it back.
     *
     * ⚠️ This is per-REQUEST state with a '/me' default, so a caller that forgets to
     * resolve silently gets '/me', and a loop over several mailboxes keeps the previous
     * one's base. Read the return value of mailboxResolveGraphBase() and pass it along
     * explicitly rather than relying on this being set — see templateGraphContext().
     *
     * @param mixed $set  the new base path ('/me' or '/users/<address>'), or null just to read it
     */
    function mailboxGraphBase($set = null) {
        static $base = '/me';
        if ($set !== null) $base = $set;
        return $base;
    }

    /**
     * Is this a Microsoft mailbox authenticating as itself (client credentials)?
     * There is no signed-in user in that mode, so '/me' is meaningless to Graph and
     * every call has to name the mailbox: /users/<target_mailbox>.
     * Google and IMAP mailboxes always behave as delegated.
     *
     * @param mixed $mailbox  the target_mailboxes row (provider, auth_mode)
     */
    function mailboxIsAppOnly($mailbox) {
        return (MailboxProvider::fromMailbox($mailbox) === MailboxProvider::Microsoft)
            && (($mailbox['auth_mode'] ?? 'delegated') === 'app_only');
    }

    /**
     * Resolve the base path for a mailbox from its auth_mode (Microsoft only).
     * Google mailboxes always behave as delegated.
     *
     * @param mixed $mailbox  the target_mailboxes row, decrypted (provider, auth_mode, target_mailbox)
     */
    function mailboxResolveGraphBase($mailbox) {
        return mailboxGraphBase(mailboxIsAppOnly($mailbox)
            ? '/users/' . rawurlencode(trim((string) $mailbox['target_mailbox']))
            : '/me');
    }

    /**
     * Is this mailbox capable of sending right now?
     *
     * "Has non-empty token_data" is the DELEGATED test and is wrong for app-only: a
     * client-credentials mailbox that has never polled holds no cached token, but can
     * mint one from its client secret on demand. Testing for a stored token therefore
     * hides a perfectly working app-only mailbox — which is why a clean app-only
     * install reported "no email mailbox is configured" when one plainly was.
     *
     * @param mixed $mailbox  the target_mailboxes row, decrypted
     */
    function mailboxCanSend($mailbox) {
        $provider = MailboxProvider::fromMailbox($mailbox);
        if ($provider === MailboxProvider::Imap) {
            return !empty($mailbox['smtp_server']) && !empty($mailbox['imap_username']);
        }
        if (mailboxIsAppOnly($mailbox)) {
            return !empty($mailbox['azure_tenant_id'])
                && !empty($mailbox['azure_client_id'])
                && !empty($mailbox['azure_client_secret'])
                && !empty($mailbox['target_mailbox']);
        }
        // Delegated needs a stored token that is actually a DELEGATED one. A leftover
        // app-only token from a mode switch would pass a bare "is it empty" test and
        // then be refused by Graph at /me.
        if (empty($mailbox['token_data'])) return false;
        $td = json_decode(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $mailbox['token_data']), true);
        return is_array($td) && !empty($td['access_token']) && empty($td['app_only']);
    }

    /**
     * An authenticated Graph GET, shaped for mailboxResolveFolderId().
     * Every caller was writing the same twelve lines of curl.
     *
     * @param mixed $accessToken  a Graph access token for the mailbox
     * @param mixed $url  the full Graph URL to GET
     */
    function mailboxGraphGet($accessToken, $url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        sslApplyCurl($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_errno($ch) ? curl_error($ch) : null;
        if ($err !== null) {
            throw new Exception('cURL error talking to Graph: ' . $err);
        }
        return ['code' => (int) $code, 'body' => json_decode($response, true)];
    }

    /**
     * Turn a folder a human typed into the id Graph actually wants (GH #77).
     *
     * ⚠️ THE WHOLE POINT. `/mailFolders/<something>` does NOT accept folder names.
     * Graph accepts a short list of WELL-KNOWN aliases there — inbox, archive,
     * sentitems and the few below — and treats anything else as an opaque folder
     * ID. So reading from a folder called "freeitsm" produced:
     *
     *     400  ErrorInvalidIdMalformed — "Id is malformed."
     *
     * "INBOX" had always worked for exactly one reason: it is on the alias list.
     * Reading a folder BY NAME had never worked at all, for any name off that list.
     *
     * Nested folders are supported with a slash — "Inbox/freeitsm" — because
     * putting the intake folder under Inbox is the first thing most people try, and
     * a flat lookup answers "not found" for a folder they can plainly see. Each
     * segment after the first is resolved through childFolders, since
     * /mailFolders lists TOP-LEVEL folders only.
     *
     * @param mixed $folderName  the folder as typed in the mailbox settings, e.g. 'Inbox/freeitsm'; a slash means a nested folder
     * @param  callable $get  fn(string $url): array — performs an authenticated GET
     *                        and returns ['code' => int, 'body' => array|null]. Passed
     *                        in so this has no opinion about how callers do HTTP.
     * @return string   The folder id, or a well-known alias (also valid in a path).
     * @throws Exception with a message naming the segment that could not be found.
     */
    function mailboxResolveFolderId($folderName, callable $get) {
        static $cache = [];

        $folderName = trim((string) $folderName);
        if ($folderName === '') {
            throw new Exception('No mail folder configured.');
        }
        $cacheKey = mailboxGraphBase() . '|' . $folderName;   // ids differ per mailbox
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // Display name -> the alias Graph accepts directly in a path.
        $wellKnown = [
            'inbox'         => 'inbox',
            'drafts'        => 'drafts',
            'sent items'    => 'sentitems',
            'sentitems'     => 'sentitems',
            'deleted items' => 'deleteditems',
            'deleteditems'  => 'deleteditems',
            'junk email'    => 'junkemail',
            'junkemail'     => 'junkemail',
            'archive'       => 'archive',
            'outbox'        => 'outbox',
            'clutter'       => 'clutter',
            'conversationhistory' => 'conversationhistory',
        ];

        $segments = array_values(array_filter(array_map('trim', explode('/', $folderName)), 'strlen'));
        $current  = null;   // null until the first segment is resolved

        foreach ($segments as $i => $segment) {
            if ($i === 0 && isset($wellKnown[strtolower($segment)])) {
                $current = $wellKnown[strtolower($segment)];
                continue;
            }

            // Top-level folders come from /mailFolders; anything deeper from the
            // parent's /childFolders. /mailFolders never returns nested folders.
            $base = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase();
            $url  = $current === null
                ? $base . '/mailFolders'
                : $base . '/mailFolders/' . rawurlencode($current) . '/childFolders';

            // Ask for the name we want, but page through rather than trusting a
            // filter: displayName filtering is case-sensitive in Graph, and a folder
            // typed "Freeitsm" would otherwise read as missing.
            $url .= '?' . http_build_query(['$top' => 200, '$select' => 'id,displayName']);

            $res = $get($url);
            if (($res['code'] ?? 0) !== 200) {
                throw new Exception('Could not list mail folders while looking for "'
                    . $segment . '" (HTTP ' . ($res['code'] ?? '?') . ').');
            }

            $match = null;
            foreach (($res['body']['value'] ?? []) as $f) {
                if (isset($f['displayName']) && strcasecmp($f['displayName'], $segment) === 0) {
                    $match = $f['id'];
                    break;
                }
            }
            if ($match === null) {
                throw new Exception('Mail folder "' . $segment . '" was not found'
                    . ($current === null ? ' in the mailbox.' : ' inside "' . $segments[$i - 1] . '".')
                    . ' Check the spelling, and use a slash for a folder inside another'
                    . ' (for example "Inbox/' . $segment . '").');
            }
            $current = $match;
        }

        $cache[$cacheKey] = $current;
        return $current;
    }
}
