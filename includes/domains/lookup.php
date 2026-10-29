<?php
/**
 * Domains — asking the registry about a domain.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RDAP FIRST, WHOIS SECOND
 *
 * RDAP is the modern, JSON replacement for WHOIS, and every generic TLD has to
 * offer it. IANA publishes which server answers for which TLD
 * (data.iana.org/rdap/dns.json); that file is cached for a week in
 * system_settings rather than fetched per lookup.
 *
 * About a sixth of TLDs — mostly country codes: .de, .io, .co, .eu, .us, .jp —
 * publish no RDAP server. For those the old port-43 WHOIS is tried: IANA's own
 * WHOIS names the registry's WHOIS server, and the reply is plain text parsed
 * for the handful of lines that matter. Some registries (DENIC for .de) never
 * publish an expiry date at all; the result says so rather than guessing, and
 * the screen offers the field for a person to fill in.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS DOES NOT DO
 *
 * It never writes. It returns a normalised array and DomainsService::applyLookup()
 * decides what to keep, under the operator's "does the registry win?" setting,
 * with a history row for every field it changes.
 *
 * ⚠️ Registry servers rate-limit and publish no numbers. Callers that loop (the
 * scheduled run) space requests out per server — see domainLookupPace().
 */

require_once __DIR__ . '/names.php';
if (file_exists(__DIR__ . '/../ssl.php')) require_once __DIR__ . '/../ssl.php';

const DOMAIN_HTTP_UA = 'FreeITSM-Domains/1.0 (+https://freeitsm.co.uk)';

/**
 * One outbound GET. Never throws.
 *
 * @param string $url      the full http(s) URL to fetch
 * @param string $accept   the Accept header value
 * @param int    $timeout  whole-request timeout in seconds (connecting is capped at 6)
 *
 * @return array{0:int,1:string,2:string} [http code (0 = no answer), body, error]
 */
function domainHttpGet(string $url, string $accept = 'application/json', int $timeout = 12): array
{
    // ONE handle, reused. curl keeps the connection open between calls on the
    // same handle, so the twenty-odd DNS-over-HTTPS questions a check asks cost
    // one TLS handshake instead of twenty (measured: 7.5s → about 2s a domain).
    // curl_reset() clears the options, never the connection cache.
    static $ch = null;
    if ($ch === null) $ch = curl_init();
    else curl_reset($ch);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_HTTPHEADER     => ['Accept: ' . $accept],
        CURLOPT_USERAGENT      => DOMAIN_HTTP_UA,
    ]);
    if (function_exists('sslApplyCurl')) sslApplyCurl($ch);
    // Tests set this to an array to see every address Domains calls - how
    // tests/domains-dns-failures.php proves "this server only" calls nothing (GH #203).
    if (isset($GLOBALS['DOMAIN_HTTP_TRACE']) && is_array($GLOBALS['DOMAIN_HTTP_TRACE'])) $GLOBALS['DOMAIN_HTTP_TRACE'][] = $url;
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    return [$code, $body === false ? '' : (string)$body, $err];
}

// ============================================================================
//  RDAP bootstrap (which server answers for which TLD)
// ============================================================================

/**
 * TLD → RDAP base URL, from IANA's bootstrap file, cached for seven days.
 * A failed refresh keeps using the stale copy — a week-old map of registries is
 * far better than none, and they rarely move.
 *
 * @return array<string,string>
 */
function domainRdapBootstrap(PDO $conn): array
{
    static $map = null;
    if ($map !== null) return $map;

    $cached = null; $cachedAt = 0;
    try {
        $st = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('domain_rdap_bootstrap', 'domain_rdap_bootstrap_at')");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            if ($k === 'domain_rdap_bootstrap') $cached = json_decode((string)$v, true);
            if ($k === 'domain_rdap_bootstrap_at') $cachedAt = (int)$v;
        }
    } catch (Throwable) {}

    if (is_array($cached) && $cached && (time() - $cachedAt) < 7 * 86400) {
        return $map = $cached;
    }

    [$code, $body] = domainHttpGet('https://data.iana.org/rdap/dns.json', 'application/json', 20);
    $j = $code === 200 ? json_decode($body, true) : null;
    if (is_array($j) && !empty($j['services'])) {
        $fresh = [];
        foreach ($j['services'] as $svc) {
            $base = $svc[1][0] ?? null;
            // Prefer an https URL when a registry lists both.
            foreach ($svc[1] ?? [] as $u) if (strncmp($u, 'https://', 8) === 0) { $base = $u; break; }
            if (!$base) continue;
            foreach ($svc[0] as $tld) $fresh[strtolower($tld)] = rtrim($base, '/') . '/';
        }
        if ($fresh) {
            try {
                foreach (['domain_rdap_bootstrap' => json_encode($fresh), 'domain_rdap_bootstrap_at' => (string)time()] as $k => $v) {
                    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())
                                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()")
                         ->execute([$k, $v]);
                }
            } catch (Throwable) {}
            return $map = $fresh;
        }
    }
    return $map = (is_array($cached) ? $cached : []);
}

// ============================================================================
//  The lookup
// ============================================================================

/**
 * Look a domain up at its registry.
 *
 * @param string $name  a stored domain name, already through domainNormalise() (ASCII, lower case)
 *
 * @return array The normalised result. Always has 'ok' and 'source'. On success:
 *   registrar, registrant, registration_date, expiry_date, updated_date,
 *   last_renewed_date (Y-m-d or null), statuses (list, lower-case words),
 *   nameservers (list), dnssec (bool|null), transfer_lock (bool|null),
 *   registry_lock (bool|null), redemption (bool), pending_delete (bool),
 *   expiry_published (bool — false when the registry does not publish one).
 *   On failure: error (plain English), not_found (bool — the registry says
 *   there is no such registration).
 */
function domainLookup(PDO $conn, string $name): array
{
    $tld  = domainTld($name);
    $boot = domainRdapBootstrap($conn);

    if (isset($boot[$tld])) {
        $r = domainRdapLookup($boot[$tld], $name);
        // A registry that does RDAP but is having a bad minute: try WHOIS rather
        // than report nothing. A clean "not found" is an answer, not a failure.
        if ($r['ok'] || !empty($r['not_found'])) return $r;
        $w = domainWhoisLookup($conn, $name);
        return $w['ok'] ? $w : $r;
    }
    return domainWhoisLookup($conn, $name);
}

/**
 * Looks a domain up at its registry's RDAP server and reads the dates, statuses, locks, registrar, name servers, DNSSEC and registrant into the standard result shape.
 *
 * A 404 comes back with not_found set, which callers treat as an answer rather than a failure.
 *
 * @param string $base  the registry's RDAP base URL from domainRdapBootstrap(), ending in "/"
 * @param string $name  a stored domain name, already through domainNormalise()
 */
function domainRdapLookup(string $base, string $name): array
{
    [$code, $body, $err] = domainHttpGet($base . 'domain/' . rawurlencode($name), 'application/rdap+json, application/json');
    if ($code === 404) {
        return ['ok' => false, 'source' => 'rdap', 'not_found' => true,
                'error' => 'The registry has no registration for this name. It may be unregistered, or a sub-domain (look up the name it belongs to instead).'];
    }
    if ($code === 429) return ['ok' => false, 'source' => 'rdap', 'error' => 'The registry asked us to slow down (rate limited). The next scheduled run will try again.'];
    if ($code !== 200) {
        return ['ok' => false, 'source' => 'rdap', 'error' => 'The registry lookup failed' . ($code ? " (HTTP $code)" : '') . ($err ? ": $err" : '.')];
    }
    $j = json_decode($body, true);
    if (!is_array($j)) return ['ok' => false, 'source' => 'rdap', 'error' => 'The registry sent an answer that could not be read.'];

    $out = domainBlankResult('rdap');

    foreach ($j['events'] ?? [] as $ev) {
        $d = domainDateOnly($ev['eventDate'] ?? '');
        switch (strtolower($ev['eventAction'] ?? '')) {
            case 'registration':   $out['registration_date'] = $d; break;
            case 'expiration':     $out['expiry_date'] = $d; break;
            case 'last changed':   $out['updated_date'] = $d; break;
            case 'reregistration': $out['last_renewed_date'] = $d; break;
        }
    }
    $out['statuses'] = domainNormaliseStatuses($j['status'] ?? []);

    foreach ($j['nameservers'] ?? [] as $ns) {
        $n = strtolower(rtrim((string)($ns['ldhName'] ?? ''), '.'));
        if ($n !== '') $out['nameservers'][] = $n;
    }
    $out['nameservers'] = array_values(array_unique($out['nameservers']));
    sort($out['nameservers']);

    if (isset($j['secureDNS']['delegationSigned'])) {
        $out['dnssec'] = (bool)$j['secureDNS']['delegationSigned'];
    }

    foreach ($j['entities'] ?? [] as $en) {
        $roles = array_map('strtolower', $en['roles'] ?? []);
        $fn = domainVcardField($en['vcardArray'] ?? [], 'fn');
        $org = domainVcardField($en['vcardArray'] ?? [], 'org');
        if (in_array('registrar', $roles, true) && $fn) $out['registrar'] = $fn;
        if (in_array('registrant', $roles, true)) {
            $who = $org ?: $fn;
            if ($who && !domainLooksRedacted($who)) $out['registrant'] = $who;
        }
    }

    domainDeriveFlags($out, $name);
    if ($out['expiry_date'] === null) $out['expiry_published'] = false;
    return $out;
}

/**
 * The result shape every source fills in.
 *
 * @param string $source  where the answer came from - 'rdap' or 'whois'
 */
function domainBlankResult(string $source): array
{
    return [
        'ok' => true, 'source' => $source,
        'registrar' => null, 'registrant' => null,
        'registration_date' => null, 'expiry_date' => null, 'updated_date' => null, 'last_renewed_date' => null,
        'statuses' => [], 'nameservers' => [], 'dnssec' => null,
        'transfer_lock' => null, 'registry_lock' => null,
        'redemption' => false, 'pending_delete' => false, 'expiry_published' => true, 'transfer_lock_na' => false,
    ];
}

/**
 * "client transfer prohibited" (RDAP) and "clientTransferProhibited
 * https://icann.org/epp#…" (WHOIS) → "client transfer prohibited".
 *
 * @param array $raw  status strings as the registry sent them, RDAP words or WHOIS EPP codes with URLs
 */
function domainNormaliseStatuses(array $raw): array
{
    $out = [];
    foreach ($raw as $s) {
        $s = trim(preg_replace('#\s+https?://\S+#', '', (string)$s));
        $s = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $s));
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if ($s !== '') $out[$s] = true;
    }
    $list = array_keys($out);
    sort($list);
    return $list;
}

/**
 * Locks and states the statuses imply.
 *
 * ⚠️ REGISTRY LOCK IS INFERRED. It is the three server*Prohibited statuses
 * together — which is how registries apply it, but a registry can set them for
 * other reasons too (a legal hold). The screen says "likely registry-locked".
 *
 * @param array  $r     the lookup result being built (domainBlankResult() shape) - its lock and state flags are set in place
 * @param string $name  the domain name, used only to spot TLDs where a transfer lock does not apply ('' for none)
 */
function domainDeriveFlags(array &$r, string $name = ''): void
{
    // 🔴 .uk: A PLAIN "active" IS NOT "UNLOCKED". Nominet moves a .uk domain by
    // changing its registrar TAG, not with an auth code, so most .uk domains
    // carry no transfer status at all. Reporting every one of them as
    // "transfer lock OFF" would be a false alarm on exactly the domains most of
    // FreeITSM's users hold. Some registrars DO set "client transfer
    // prohibited" on .uk (measured: heartinternet.uk has it, freeitsm.co.uk at
    // the same registrar does not), so when either transfer status is present
    // it still means locked; only its absence becomes "not applicable".
    $r['transfer_lock_na'] = domainTransferLockNotApplicable($name);
    $s = $r['statuses'];
    // Only statuses from the standard EPP vocabulary say anything about locks.
    // DENIC's "connect" says the domain is live and nothing about transfers, so
    // a .de domain's lock stays unknown (null) rather than reading as "off".
    $epp = false;
    foreach ($s as $st) {
        if (preg_match('/^(ok|active|inactive|(client|server) \w+ prohibited|pending \w+|add period|auto renew period|renew period|transfer period|redemption period)$/', $st)) { $epp = true; break; }
    }
    if ($epp) {
        // 🔴 GH #188: "active" (or "ok") with no transfer status is NOT proof the
        // lock is off. ON needs a transfer-prohibited status. OFF needs evidence
        // that this registry would have shown one: either it is a generic TLD,
        // where ICANN's RDAP profile obliges the registry to publish every EPP
        // status, or the reply carries some OTHER prohibition (a registry that
        // shows "delete prohibited" but not "transfer prohibited" is telling us).
        // Otherwise - a country registry answering just "active" - not known.
        $transfer = in_array('client transfer prohibited', $s, true) || in_array('server transfer prohibited', $s, true);
        $anyProhibition = (bool)preg_grep('/^(client|server) \w+ prohibited$/', $s);
        if ($transfer)                                                $r['transfer_lock'] = true;
        elseif ($anyProhibition || domainTldPublishesAllStatuses($name)) $r['transfer_lock'] = false;
        else                                                          $r['transfer_lock'] = null;
        $r['registry_lock'] = in_array('server transfer prohibited', $s, true)
                           && in_array('server delete prohibited', $s, true)
                           && in_array('server update prohibited', $s, true);
    }
    if ($r['transfer_lock_na'] && !in_array('server transfer prohibited', $s, true) && !in_array('client transfer prohibited', $s, true)) {
        $r['transfer_lock'] = null;
    }
    $r['redemption']     = in_array('redemption period', $s, true);
    $r['pending_delete'] = in_array('pending delete', $s, true);
}

/**
 * Returns one field's value (for example 'fn' or 'org') from an RDAP jCard, or null when absent or empty.
 *
 * @param array  $vcard  an RDAP entity's vcardArray (jCard) - ['vcard', [[name, params, type, value], ...]]
 * @param string $field  the jCard property name to read, e.g. 'fn' or 'org'
 */
function domainVcardField(array $vcard, string $field): ?string
{
    foreach ($vcard[1] ?? [] as $v) {
        if (($v[0] ?? '') !== $field) continue;
        $val = $v[3] ?? null;
        if (is_array($val)) $val = implode(' ', array_filter(array_map('strval', $val)));
        $val = trim((string)$val);
        return $val !== '' ? $val : null;
    }
    return null;
}

/**
 * True when a registrant value is a privacy placeholder ("REDACTED", "Domains By Proxy", GDPR wording) rather than a real name.
 *
 * @param string $v  the registrant name or organisation the registry gave
 */
function domainLooksRedacted(string $v): bool
{
    return (bool)preg_match('/redacted|privacy|withheld|not disclosed|data protected|gdpr|contact privacy|whoisguard|domains by proxy/i', $v);
}

/**
 * Any date string → Y-m-d, or null.
 *
 * @param string $raw  a date as a registry wrote it - ISO, yyyy.mm.dd, dd.mm.yyyy or anything strtotime() reads
 */
function domainDateOnly(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/^(\d{4})\.(\d{2})\.(\d{2})/', $raw, $m)) return "$m[1]-$m[2]-$m[3]";   // .jp / some ccTLDs
    if (preg_match('#^(\d{2})[./-](\d{2})[./-](\d{4})#', $raw, $m)) return "$m[3]-$m[2]-$m[1]";   // dd.mm.yyyy
    $t = strtotime($raw);
    return $t ? gmdate('Y-m-d', $t) : null;
}

// ============================================================================
//  WHOIS fallback
// ============================================================================

/**
 * The WHOIS server for a TLD, from IANA's referral, cached with the bootstrap.
 *
 * @param string $tld  the top-level domain without a dot, e.g. 'de'
 */
function domainWhoisServer(PDO $conn, string $tld): ?string
{
    static $mem = [];
    if (array_key_exists($tld, $mem)) return $mem[$tld];

    $map = [];
    try {
        $v = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'domain_whois_servers'")->fetchColumn();
        $map = $v ? (json_decode((string)$v, true) ?: []) : [];
    } catch (Throwable) {}
    if (isset($map[$tld])) return $mem[$tld] = ($map[$tld] ?: null);

    $reply = domainWhoisQuery('whois.iana.org', $tld);
    $server = null;
    if ($reply !== null && preg_match('/^whois:\s*(\S+)/mi', $reply, $m)) $server = strtolower($m[1]);
    $map[$tld] = $server ?: '';
    try {
        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES ('domain_whois_servers', ?, UTC_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()")
             ->execute([json_encode($map)]);
    } catch (Throwable) {}
    return $mem[$tld] = $server;
}

/**
 * One port-43 query. null when the server could not be reached.
 *
 * @param string $server  the WHOIS server host name, e.g. 'whois.iana.org'
 * @param string $query   the line to send - normally the domain or TLD, with server-specific flags where needed
 */
function domainWhoisQuery(string $server, string $query): ?string
{
    // The same trace as domainHttpGet() (GH #203): WHOIS is port 43, not HTTP, so it needs its own line.
    if (isset($GLOBALS['DOMAIN_HTTP_TRACE']) && is_array($GLOBALS['DOMAIN_HTTP_TRACE'])) $GLOBALS['DOMAIN_HTTP_TRACE'][] = "whois://$server/$query";
    $f = @fsockopen($server, 43, $errno, $errstr, 8);
    if (!$f) return null;
    stream_set_timeout($f, 10);
    fwrite($f, $query . "\r\n");
    $out = '';
    while (!feof($f)) {
        $line = fgets($f, 4096);
        if ($line === false) break;
        $out .= $line;
        if (strlen($out) > 200000) break;
    }
    fclose($f);
    return $out;
}

/**
 * Looks a domain up over WHOIS, for registries without RDAP, and reads the dates, statuses, registrar, name servers and DNSSEC from the text reply.
 *
 * An answer saying the name is not registered comes back with not_found set.
 *
 * @param string $name  a stored domain name, already through domainNormalise()
 */
function domainWhoisLookup(PDO $conn, string $name): array
{
    $tld = domainTld($name);
    $server = domainWhoisServer($conn, $tld);
    if (!$server) {
        return ['ok' => false, 'source' => 'whois', 'error' => "The .$tld registry offers neither RDAP nor WHOIS lookups. Enter the dates by hand."];
    }
    // DENIC answers a bare query with almost nothing; this asks for the full record.
    $q = ($server === 'whois.denic.de') ? "-T dn,ace $name" : $name;
    $txt = domainWhoisQuery($server, $q);
    if ($txt === null) return ['ok' => false, 'source' => 'whois', 'error' => "The .$tld registry's WHOIS server did not answer."];

    if (preg_match('/^(no match|not found|no data found|no entries found|status:\s*free|domain not found|%% not found|no object found)/mi', $txt)) {
        return ['ok' => false, 'source' => 'whois', 'not_found' => true,
                'error' => 'The registry has no registration for this name. It may be unregistered, or a sub-domain.'];
    }

    $out = domainBlankResult('whois');
    $grab = function (string $re) use ($txt): ?string {
        return preg_match($re, $txt, $m) ? trim($m[1]) : null;
    };

    $out['expiry_date'] = domainDateOnly((string)$grab('/^\s*(?:Registry Expiry Date|Registrar Registration Expiration Date|Expiration Date|Expiry Date|Expiry date|paid-till|Expires On|Expiration Time|expires|Renewal date|validity)\s*:\s*(.+)$/mi'));
    $out['registration_date'] = domainDateOnly((string)$grab('/^\s*(?:Creation Date|Created On|Registration Time|Registered on|Registration Date|created)\s*:\s*(.+)$/mi'));
    $out['updated_date'] = domainDateOnly((string)$grab('/^\s*(?:Updated Date|Last Updated|Last updated|Changed|last-update|modified|Last Modified)\s*:\s*(.+)$/mi'));

    $reg = $grab('/^\s*(?:Registrar|Sponsoring Registrar|Registrar Name|registrar name)\s*:\s*(.+)$/mi');
    if ($reg !== null && $reg !== '' && !preg_match('/^(url|whois)/i', $reg)) $out['registrar'] = $reg;
    $org = $grab('/^\s*Registrant Organi[sz]ation\s*:\s*(.+)$/mi');
    if ($org && !domainLooksRedacted($org)) $out['registrant'] = $org;

    if (preg_match_all('/^\s*(?:Domain Status|Status|state)\s*:\s*(\S+)/mi', $txt, $m)) {
        $out['statuses'] = domainNormaliseStatuses($m[1]);
    }
    if (preg_match_all('/^\s*(?:Name Server|Nameserver|Nserver|nserver)\s*:\s*(\S+)/mi', $txt, $m)) {
        $ns = array_map(fn($n) => strtolower(rtrim($n, '.')), $m[1]);
        $ns = array_values(array_unique(array_filter($ns)));
        sort($ns);
        $out['nameservers'] = $ns;
    }
    $sec = $grab('/^\s*DNSSEC\s*:\s*(.+)$/mi');
    if ($sec !== null) $out['dnssec'] = (bool)preg_match('/signed|yes|active/i', $sec) && !preg_match('/unsigned/i', $sec);

    domainDeriveFlags($out, $name);
    if ($out['expiry_date'] === null) $out['expiry_published'] = false;
    return $out;
}

/**
 * Space out requests to one registry. Called between lookups in a batch: the
 * same RDAP/WHOIS host is not asked again within $gapSeconds.
 *
 * @param string $name        the domain about to be looked up - its TLD decides which registry host is paced
 * @param float  $gapSeconds  least time between two requests to the same host, in seconds
 */
function domainLookupPace(PDO $conn, string $name, float $gapSeconds = 1.5): void
{
    static $last = [];
    $boot = domainRdapBootstrap($conn);
    $tld  = domainTld($name);
    $host = isset($boot[$tld]) ? (string)parse_url($boot[$tld], PHP_URL_HOST) : ('whois:' . $tld);
    if (isset($last[$host])) {
        $wait = $gapSeconds - (microtime(true) - $last[$host]);
        if ($wait > 0) usleep((int)($wait * 1e6));
    }
    $last[$host] = microtime(true);
}

/**
 * Registries where a registrar "transfer lock" is not how transfers are
 * controlled, so its absence is not a weakness. .uk: Nominet registrar tags.
 *
 * @param string $name  a stored domain name; only its TLD is read
 */
function domainTransferLockNotApplicable(string $name): bool
{
    return in_array(domainTld($name), ['uk'], true);
}

/**
 * Is this a generic TLD (.com, .org, .app ...), whose registry must publish every
 * EPP status in RDAP under ICANN's gTLD RDAP profile - so the ABSENCE of
 * "transfer prohibited" there really means unlocked (GH #188)?
 *
 * Country-code TLDs are two letters and are not bound by that profile: many
 * answer just "active". An IDN TLD (xn--) can be either, so it is not assumed
 * to be generic - "not known" is the safe answer, never a false "off".
 *
 * @param string $name  a stored domain name; only its TLD is read ('' = not known)
 */
function domainTldPublishesAllStatuses(string $name): bool
{
    $tld = strtolower(domainTld(trim($name)));
    return $tld !== '' && str_contains($name, '.') && strlen($tld) > 2 && !str_starts_with($tld, 'xn--');
}
