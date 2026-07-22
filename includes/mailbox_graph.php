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
 * the DECRYPTED row (azure_* fields in clear text).
 */

if (!function_exists('mailboxAppOnlyToken')) {

    /**
     * App-only access token via the client-credentials flow (no user, no interactive
     * sign-in). Cached in target_mailboxes.token_data until it expires (app-only tokens
     * carry no refresh token — we just re-fetch).
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
        if (curl_errno($ch)) { $err = curl_error($ch); curl_close($ch); throw new Exception('cURL error: ' . $err); }
        curl_close($ch);

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
             ->execute([$cacheJson, $mailbox['id']]);

        return $data['access_token'];
    }

    /**
     * Pull the signed-in user's address straight out of a Graph access token. The
     * token is a JWT whose payload carries the user's UPN / email — so we can read
     * it offline, with NO Graph permission. This matters because the usual mailbox
     * scopes (Mail.*) do NOT include User.Read, so a /me call 403s and can't tell us
     * who signed in. Returns a lowercased email, or '' if the token has no usable claim.
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
        return ($email && strpos($email, '@') !== false) ? strtolower(trim($email)) : '';
    }

    /**
     * Who does a (delegated) access token belong to? Returns the lowercased email,
     * or '' if it can't be determined. Used to back-fill authenticated_as for
     * mailboxes that signed in before we started recording it.
     *
     * Reads the token's own JWT claims first (offline, no permission needed); only
     * falls back to Graph /me if the token isn't a readable JWT (needs User.Read).
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
        curl_close($ch);
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
     */
    function mailboxFetchAddresses($accessToken) {
        $ch = curl_init('https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName,proxyAddresses');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        sslApplyCurl($ch);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) return [];
        $data = json_decode($response, true);
        if (!is_array($data)) return [];

        $set = [];
        foreach ([$data['mail'] ?? '', $data['userPrincipalName'] ?? ''] as $a) {
            if ($a && strpos($a, '@') !== false) $set[] = strtolower(trim($a));
        }
        foreach (($data['proxyAddresses'] ?? []) as $p) {
            // proxyAddresses look like "SMTP:ed@x" (primary) / "smtp:alias@x" (secondary).
            $addr = preg_replace('/^smtp:/i', '', (string) $p);
            if ($addr && strpos($addr, '@') !== false) $set[] = strtolower(trim($addr));
        }
        return array_values(array_unique($set));
    }

    /**
     * Build the identity record for a freshly-authenticated delegated token:
     *   ['primary' => <display address>, 'addresses' => [every address this mailbox owns]].
     * Combines the token's own claim (always available, offline) with the Graph alias
     * list (when User.Read is granted). 'primary' is what we show; 'addresses' is what
     * we match the configured target against.
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
     */
    function mailboxIdentityMismatch($mailbox) {
        if (($mailbox['provider'] ?? 'microsoft') !== 'microsoft') return null;
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
     */
    function mailboxBackfillIdentity($conn, $mailbox, $accessToken) {
        if (($mailbox['provider'] ?? 'microsoft') !== 'microsoft') return $mailbox;
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
     */
    function mailboxGraphBase($set = null) {
        static $base = '/me';
        if ($set !== null) $base = $set;
        return $base;
    }

    /**
     * Resolve the base path for a mailbox from its auth_mode (Microsoft only).
     * Google mailboxes always behave as delegated.
     */
    function mailboxResolveGraphBase($mailbox) {
        $isAppOnly = (($mailbox['provider'] ?? 'microsoft') === 'microsoft')
                  && (($mailbox['auth_mode'] ?? 'delegated') === 'app_only');
        return mailboxGraphBase($isAppOnly
            ? '/users/' . rawurlencode(trim((string) $mailbox['target_mailbox']))
            : '/me');
    }
}
