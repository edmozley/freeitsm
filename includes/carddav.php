<?php
/**
 * CardDAV — the transport under directory sync's policy layer.
 *
 * Directory sync already knows how to bring people into `users` safely: nobody
 * is ever deleted, a run that returns far fewer people than last time changes
 * nothing, and missing once is noise rather than a fact. None of that is about
 * LDAP, and none of it gets rebuilt here.
 *
 * What this file is for is the half that IS about LDAP. Every extension point
 * in `includes/directory_sync.php` assumes the transport is LDAP: the columns
 * are named `ldap_attr_*`, paging uses `LDAP_CONTROL_PAGEDRESULTS`, identity is
 * `objectGUID` or `entryUUID`, and a manager is a distinguished name. CardDAV
 * is HTTP and vCard — no DNs, no paged controls, and identity is a URL plus an
 * ETag. So this is a fetch-and-map layer, deliberately narrow.
 *
 * Asked for in https://github.com/edmozley/freeitsm/issues/133.
 * Wiki: CardDAV Contact Sync — Developer Guide.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴🔴 THE ONE THING TO KNOW BEFORE CHANGING ANYTHING HERE: AUTH.
 *
 * A stock Baikal — the standard sabre/dav server, and what the reporter runs —
 * ships `dav_auth_type: Digest`. Measured against 0.12.1:
 *
 *     Basic    -> 401
 *     Digest   -> 207
 *     anyauth  -> 207
 *
 * Basic is the obvious choice and what almost every REST integration reaches
 * for, and against a default install it fails outright. The operator then sees
 * "authentication failed", checks a password that is correct, and concludes
 * FreeITSM is broken. So: CURLAUTH_ANY, and the connection test reports which
 * scheme actually won, because that is the one thing they cannot discover for
 * themselves.
 * ──────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/functions.php';

/** Longest we will wait on any single DAV request, in seconds. */
const CARDDAV_TIMEOUT = 20;

/**
 * One HTTP request to a DAV server.
 *
 * Returns ['ok' => bool, 'status' => int, 'body' => string, 'error' => string,
 *          'auth' => string] where `auth` names the scheme that succeeded.
 *
 * @param array  $cfg     url, username, password, auth ('auto'|'digest'|'basic')
 * @param string $method  PROPFIND, REPORT, GET…
 * @param string $url     absolute URL
 * @param string $body    request body, may be ''
 * @param array  $headers extra headers
 */
function cardDavRequest(array $cfg, string $method, string $url, string $body = '', array $headers = []): array
{
    $out = ['ok' => false, 'status' => 0, 'body' => '', 'error' => '', 'auth' => ''];

    if (!function_exists('curl_init')) {
        $out['error'] = 'PHP cURL is not available, so FreeITSM cannot talk to a CardDAV server.';
        return $out;
    }

    // ⚠️ CURLAUTH_ANY, not CURLAUTH_BASIC. See the header of this file — this
    // single constant is the difference between working against a stock Baikal
    // and a 401 that looks like a bad password.
    $authMode = CURLAUTH_ANY;
    if (($cfg['auth'] ?? 'auto') === 'digest') $authMode = CURLAUTH_DIGEST;
    if (($cfg['auth'] ?? 'auto') === 'basic')  $authMode = CURLAUTH_BASIC;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CARDDAV_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPAUTH       => $authMode,
        CURLOPT_USERPWD        => ($cfg['username'] ?? '') . ':' . ($cfg['password'] ?? ''),
        // ⚠️ Follow redirects: a DAV server very often answers the collection
        // URL with a 301 to the same path with a trailing slash, and an
        // implementation that does not follow reports "not found" for a server
        // that is working perfectly.
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        // 🔑 A real User-Agent, deliberately. A bodiless PHP cURL request sends
        // NO user agent at all, and that alone is enough for a WAF or a
        // reverse proxy to refuse it — which cost a whole investigation once
        // already, diagnosed as a "WAF block" when the client was at fault.
        CURLOPT_USERAGENT      => 'FreeITSM/' . (defined('FREEITSM_VERSION') ? FREEITSM_VERSION : 'dev') . ' (CardDAV)',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $used   = (int) curl_getinfo($ch, CURLINFO_HTTPAUTH_AVAIL);
    $err    = curl_error($ch);
    curl_close($ch);

    $out['status'] = $status;
    $out['body']   = is_string($resp) ? $resp : '';

    if ($resp === false) {
        $out['error'] = $err !== '' ? $err : 'The request failed with no further detail.';
        return $out;
    }

    // What the SERVER offered. Reported rather than inferred, because "it
    // worked" and "it worked over Basic" are different facts to an operator
    // deciding whether their setup is safe over plain HTTP.
    $offered = [];
    if ($used & CURLAUTH_DIGEST)    $offered[] = 'Digest';
    if ($used & CURLAUTH_BASIC)     $offered[] = 'Basic';
    if ($used & CURLAUTH_NEGOTIATE) $offered[] = 'Negotiate';
    $out['auth'] = $offered ? implode(', ', $offered) : 'none offered';

    // 207 Multi-Status is the success case for PROPFIND and REPORT; 200 for GET.
    $out['ok'] = ($status >= 200 && $status < 300);
    if (!$out['ok']) {
        $out['error'] = cardDavExplainStatus($status);

        // ⭐ When the operator has PINNED a scheme and the server does not offer
        // it, say exactly that instead of the generic 401 advice. This is the
        // difference between "check your password" — which sends somebody to
        // re-type a password that was always correct — and "your server wants
        // Digest and you told FreeITSM to use Basic", which they can fix in one
        // click. The information is right here in the response and throwing it
        // away is what makes auth failures take an afternoon.
        $mode = $cfg['auth'] ?? 'auto';
        if ($status === 401 && $mode !== 'auto' && $offered) {
            $want = ucfirst($mode);
            if (!in_array($want, $offered, true)) {
                $out['error'] = "The server does not accept $want authentication. It offers "
                    . implode(' or ', $offered)
                    . '. Set the authentication setting back to Automatic, or choose '
                    . $offered[0] . '.';
            }
        }
    }
    return $out;
}

/**
 * Turn an HTTP status into something an operator can act on.
 *
 * Deliberately not "HTTP 401" — the whole point of these strings is that the
 * person reading them is configuring a server, not debugging one.
 */
function cardDavExplainStatus(int $status): string
{
    switch (true) {
        case $status === 0:
            return 'Could not reach the server at all. Check the address, and that FreeITSM can reach it over the network.';
        case $status === 401:
            return 'The server refused the username and password. If they are definitely correct, the server may be offering an authentication scheme FreeITSM was told not to use — try leaving the authentication setting on Automatic.';
        case $status === 403:
            return 'The server accepted the sign-in but would not allow access to that address book. The account may not have permission to read it.';
        case $status === 404:
            return 'Nothing was found at that address. Check the path — for a Baikal or sabre/dav server it usually ends in /dav.php/addressbooks/<user>/.';
        case $status === 405:
            return 'The server would not accept a PROPFIND request, which means the address is reachable but is not a CardDAV endpoint. This is often a web page rather than the DAV path.';
        case $status >= 500:
            return "The server returned an error of its own (HTTP $status). Its log will say more than FreeITSM can.";
        default:
            return "The server answered with HTTP $status, which was not expected here.";
    }
}

/**
 * List the address books an account can see.
 *
 * One `PROPFIND` with `Depth: 1` against the given URL. A collection that
 * carries the `{urn:ietf:params:xml:ns:carddav}addressbook` resource type is an
 * address book; everything else in the response — including the container
 * itself — is not, which is how the parent gets excluded without special-casing
 * it by URL.
 *
 * @return array ['ok'=>bool, 'books'=>[['href'=>…,'name'=>…,'description'=>…]], 'auth'=>…, 'error'=>…, 'status'=>int]
 */
function cardDavListAddressBooks(array $cfg): array
{
    $url = rtrim((string)($cfg['url'] ?? ''), '/') . '/';

    $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:cs="http://calendarserver.org/ns/">' .
            '<d:prop>' .
              '<d:displayname/>' .
              '<d:resourcetype/>' .
              '<cs:getctag/>' .
            '</d:prop>' .
            '</d:propfind>';

    $res = cardDavRequest($cfg, 'PROPFIND', $url, $body, [
        'Depth: 1',
        'Content-Type: application/xml; charset=utf-8',
    ]);

    $result = [
        'ok'     => false,
        'books'  => [],
        'auth'   => $res['auth'],
        'status' => $res['status'],
        'error'  => $res['error'],
    ];
    if (!$res['ok']) {
        return $result;
    }

    $books = cardDavParseAddressBooks($res['body']);
    if ($books === null) {
        $result['error'] = 'The server answered, but not with something FreeITSM could read as a DAV response. Check that the address points at the DAV path rather than a web page.';
        return $result;
    }

    $result['ok']    = true;
    $result['books'] = $books;
    return $result;
}

/**
 * Pull the address books out of a PROPFIND multistatus body.
 *
 * Returns null when the body is not parseable as DAV at all — which is a
 * different answer from "parsed fine, found none", and the two must not be
 * collapsed: the first is a wrong URL, the second is an empty account.
 */
function cardDavParseAddressBooks(string $xml): ?array
{
    if (trim($xml) === '') return null;

    // ⚠️ Namespaces are not optional here. Servers use different prefixes for
    // the same namespaces — Baikal says `d:` and `card:`, others use `D:` and
    // `C:` — so anything matching on prefixes works against one server and
    // silently finds nothing against the next. Register the URIs and query
    // those.
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $loaded = $doc->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) return null;

    $xp = new DOMXPath($doc);
    $xp->registerNamespace('d', 'DAV:');
    $xp->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');

    $responses = $xp->query('//d:response');
    if ($responses === false) return null;
    // A valid DAV body always has at least the requested resource in it. No
    // responses at all means this was not a multistatus.
    if ($responses->length === 0) return null;

    $books = [];
    foreach ($responses as $resp) {
        $isBook = $xp->query('.//d:resourcetype/card:addressbook', $resp);
        if ($isBook === false || $isBook->length === 0) {
            continue;   // the container itself, or a calendar, or a principal
        }
        $hrefNode = $xp->query('./d:href', $resp);
        $href = ($hrefNode && $hrefNode->length) ? trim($hrefNode->item(0)->textContent) : '';
        if ($href === '') continue;

        $nameNode = $xp->query('.//d:displayname', $resp);
        $name = ($nameNode && $nameNode->length) ? trim($nameNode->item(0)->textContent) : '';

        $books[] = [
            'href' => $href,
            // Fall back to the last path segment. A book with no displayname is
            // legal, and "(unnamed)" in a picker is worse than "itsm".
            'name' => $name !== '' ? $name : trim(basename(rtrim($href, '/'))),
        ];
    }
    return $books;
}

/**
 * Fetch every vCard in one address book, in a single request.
 *
 * An `addressbook-query` REPORT with `address-data` returns the cards inline,
 * so this is one round trip rather than a PROPFIND followed by a GET per card —
 * which on an address book of a few thousand contacts is the difference between
 * one request and a few thousand.
 *
 * @return array ['ok'=>bool, 'cards'=>[['href'=>…,'etag'=>…,'vcard'=>…]], 'error'=>…, 'status'=>int]
 */
function cardDavFetchCards(array $cfg, string $bookHref): array
{
    $url = cardDavAbsoluteUrl($cfg['url'] ?? '', $bookHref);

    $body = '<?xml version="1.0" encoding="utf-8"?>' .
            '<card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">' .
            '<d:prop><d:getetag/><card:address-data/></d:prop>' .
            '</card:addressbook-query>';

    $res = cardDavRequest($cfg, 'REPORT', $url, $body, [
        // Depth: 1 — the cards inside this collection, not the collection tree.
        'Depth: 1',
        'Content-Type: application/xml; charset=utf-8',
    ]);

    $out = ['ok' => false, 'cards' => [], 'error' => $res['error'], 'status' => $res['status']];
    if (!$res['ok']) return $out;

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($res['body']);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) {
        $out['error'] = 'The server answered the contact request with something FreeITSM could not read as a DAV response.';
        return $out;
    }

    $xp = new DOMXPath($doc);
    $xp->registerNamespace('d', 'DAV:');
    $xp->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');

    foreach ($xp->query('//d:response') as $resp) {
        $hrefN = $xp->query('./d:href', $resp);
        $dataN = $xp->query('.//card:address-data', $resp);
        if (!$dataN || $dataN->length === 0) continue;   // e.g. the collection itself
        $etagN = $xp->query('.//d:getetag', $resp);
        $out['cards'][] = [
            'href'  => ($hrefN && $hrefN->length) ? trim($hrefN->item(0)->textContent) : '',
            'etag'  => ($etagN && $etagN->length) ? trim($etagN->item(0)->textContent) : '',
            'vcard' => $dataN->item(0)->textContent,
        ];
    }
    $out['ok'] = true;
    return $out;
}

/**
 * Resolve an href from a DAV response against the configured base URL.
 *
 * ⚠️ A server may answer with an absolute URL, a root-relative path, or a bare
 * segment, and all three are legal. Concatenating blindly produces
 * `https://host/dav.php/addressbooks/jsmith//dav.php/addressbooks/jsmith/itsm/`
 * — a 404 that looks like a missing address book rather than a client bug.
 */
function cardDavAbsoluteUrl(string $baseUrl, string $href): string
{
    if ($href === '') return $baseUrl;
    if (preg_match('#^https?://#i', $href)) return $href;

    $parts = parse_url($baseUrl);
    if ($parts === false || empty($parts['host'])) return $href;
    $origin = ($parts['scheme'] ?? 'http') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

    if ($href[0] === '/') return $origin . $href;
    return rtrim($baseUrl, '/') . '/' . $href;
}

/**
 * Unfold a vCard into logical lines.
 *
 * 🔴 NOT OPTIONAL. RFC 6350 says a line longer than 75 octets is folded by
 * inserting CRLF followed by a single space or tab, and servers do it — a long
 * `CATEGORIES` list is exactly the kind of property that gets folded. Parsing
 * the raw text line by line therefore reads `CATEGORIES:customer,supplier,pa`
 * and then a separate line ` rtner`, so the last value in a long list silently
 * goes missing and everything still looks like it worked.
 */
function cardDavUnfold(string $vcard): array
{
    $norm = str_replace(["\r\n", "\r"], "\n", $vcard);
    // A continuation is a newline followed by exactly one space or tab.
    $norm = preg_replace("/\n[ \t]/", '', $norm);
    return array_values(array_filter(array_map('trim', explode("\n", $norm)), function ($l) {
        return $l !== '';
    }));
}

/**
 * Read one property's values out of an unfolded vCard.
 *
 * ⚠️ Matches on the property NAME before any parameters: `CATEGORIES:a,b` and
 * `CATEGORIES;TYPE=x:a,b` are the same property, and an implementation that
 * looks for `CATEGORIES:` misses the second. Comparison is case-insensitive
 * because the spec says property names are.
 */
function cardDavProperty(array $lines, string $name): array
{
    $found = [];
    $needle = strtoupper($name);
    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) continue;
        $left = substr($line, 0, $colon);
        // Strip parameters, then compare.
        $semi = strpos($left, ';');
        $prop = strtoupper(trim($semi === false ? $left : substr($left, 0, $semi)));
        if ($prop !== $needle) continue;
        $found[] = trim(substr($line, $colon + 1));
    }
    return $found;
}

/**
 * What can this address book be scoped BY?
 *
 * Reads every card once and reports the two things somebody might want to
 * filter on, so the settings screen can offer a list instead of a text box:
 *
 *   - groups     — `KIND:group` cards (how Apple Contacts stores a group), each
 *                  with how many MEMBERs it names
 *   - categories — every distinct `CATEGORIES` value across the cards, with a
 *                  count (how Thunderbird and many Android clients do it)
 *
 * 🔑 Both are reported, always, because a server can use either or both and the
 * operator is the only one who knows which of their groups is the real one.
 * `contacts` is the count of ordinary (non-group) cards, which is what "bring
 * in everything" would import.
 */
function cardDavScanBook(array $cfg, string $bookHref): array
{
    $res = cardDavFetchCards($cfg, $bookHref);
    $out = [
        'ok'         => false,
        'contacts'   => 0,
        'groups'     => [],
        'categories' => [],
        'error'      => $res['error'],
        'status'     => $res['status'],
    ];
    if (!$res['ok']) return $out;

    $catCounts = [];
    foreach ($res['cards'] as $card) {
        $lines = cardDavUnfold($card['vcard']);

        $kind = strtolower(trim(cardDavProperty($lines, 'KIND')[0] ?? ''));
        if ($kind === 'group') {
            $uid  = cardDavProperty($lines, 'UID')[0] ?? '';
            $name = cardDavProperty($lines, 'FN')[0] ?? '';
            $out['groups'][] = [
                'uid'     => $uid,
                'name'    => $name !== '' ? $name : ($uid !== '' ? $uid : '(unnamed group)'),
                'members' => count(cardDavProperty($lines, 'MEMBER')),
            ];
            // ⚠️ A group card is not a person and must not be counted as one,
            // nor imported as one — "ITSM" is not a contact called ITSM.
            continue;
        }

        $out['contacts']++;

        foreach (cardDavProperty($lines, 'CATEGORIES') as $raw) {
            // Comma-separated, and a value may legally contain an escaped comma.
            foreach (preg_split('/(?<!\\\\),/', $raw) as $cat) {
                $cat = trim(str_replace('\\,', ',', $cat));
                if ($cat === '') continue;
                $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
            }
        }
    }

    // Most-used first: the group somebody wants is far more likely to be the
    // one on eighty cards than the one on a stray two.
    arsort($catCounts);
    foreach ($catCounts as $name => $n) {
        $out['categories'][] = ['name' => $name, 'contacts' => $n];
    }

    $out['ok'] = true;
    return $out;
}

/**
 * Read `carddav_scope_value` into a list.
 *
 * Newline-separated in the column, same as `sync_ou_includes`. Deduplicated
 * case-insensitively but stored as typed, because a CATEGORIES value is
 * matched case-insensitively by every client that writes one, while a group UID
 * is opaque and must survive untouched.
 */
function cardDavScopeList($stored): array
{
    $out = [];
    foreach (preg_split('/\R/', (string)$stored) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $out[mb_strtolower($line)] = $line;
        // A browser is not a promise: cap it rather than storing an essay.
        if (count($out) >= 200) break;
    }
    return array_values($out);
}

/**
 * Build the config array a request needs from an `auth_providers` row.
 *
 * ⚠️ Decrypts here and nowhere earlier: the row travels around as stored, and
 * the plaintext exists only for the length of one call. Same handling as
 * `ldap_bind_password`.
 */
function cardDavConfigFromProvider(array $provider, ?string $overridePassword = null): array
{
    $password = $overridePassword;
    if ($password === null) {
        $stored = (string)($provider['carddav_password'] ?? '');
        $password = $stored !== '' ? decryptValue($stored) : '';
    }
    return [
        'url'      => (string)($provider['carddav_url'] ?? ''),
        'username' => (string)($provider['carddav_username'] ?? ''),
        'password' => (string)$password,
        'auth'     => (string)($provider['carddav_auth'] ?? 'auto'),
    ];
}
