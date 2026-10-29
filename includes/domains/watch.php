<?php
/**
 * Domains — watching the outside world for your names.
 *
 * Two optional scans, both OFF by default (Domains → Settings → Monitoring):
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CERTIFICATE TRANSPARENCY (crt.sh)
 *
 * Every publicly trusted certificate is published in public logs. crt.sh
 * indexes them, so asking it "what certificates exist for example.com?" shows
 * every one ever issued — including one somebody obtained without you knowing,
 * which is the first visible step of a lot of phishing. New ones are stored
 * unacknowledged and shown on the domain; the FIRST scan of a domain records
 * everything already there as acknowledged, so switching the watch on does not
 * produce a wall of "new" certificates that are years old.
 *
 * ⚠️ crt.sh is a free community service with no published terms and a low rate
 * limit (a handful of queries a minute per address). The scheduled run asks
 * about each domain at most weekly and paces the queries. It is slow and
 * sometimes times out; a failure is simply retried next time.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LOOK-ALIKES (typo-squatting)
 *
 * The names one slip from yours — examp1e.com, exmaple.com, example-login.com,
 * example.co — generated here the way dnstwist does it, then resolved. A
 * look-alike that resolves exists; one with MAIL servers can receive the
 * invoices and password resets people mistype. Existence is all DNS can tell
 * us — whether it is malicious is a person's judgement, so each one can be
 * dismissed.
 *
 * Only primary and secondary domains are scanned: the look-alikes of a parked
 * name are not worth a thousand DNS queries.
 */

require_once __DIR__ . '/lookup.php';
require_once __DIR__ . '/dns.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

// ============================================================================
//  Certificate Transparency
// ============================================================================

/**
 * Asks crt.sh for the unexpired certificates issued for a domain and stores any
 * not seen before; the first scan stores them as acknowledged, later ones as new
 * and fire domain.certificate_issued.
 *
 * @param int    $domainId  the domain the certificates are stored against
 * @param string $name      the stored domain name to ask crt.sh about
 *
 * @writes domain_certificates - one row per crt.sh certificate id
 * @return array{ok:bool, new:int, total:int, error:?string, first_scan:bool}
 */
function domainCtScan(PDO $conn, int $domainId, string $name): array
{
    // GH #203: the gate, asked here as well as by the callers - crt.sh is a public service.
    if (!domainIdMayLeaveNetwork($conn, $domainId)) {
        return ['ok' => false, 'new' => 0, 'total' => 0, 'first_scan' => false, 'error' => 'This is an internal domain: it is never sent to Certificate Transparency.'];
    }
    [$code, $body, $err] = domainHttpGet('https://crt.sh/?q=' . rawurlencode($name) . '&output=json&exclude=expired', 'application/json', 45);
    if ($code !== 200) {
        return ['ok' => false, 'new' => 0, 'total' => 0, 'first_scan' => false, 'error' => 'crt.sh did not answer' . ($code ? " (HTTP $code)" : '') . ($err ? ": $err" : '')];
    }
    $rows = json_decode($body, true);
    if (!is_array($rows)) return ['ok' => false, 'new' => 0, 'total' => 0, 'first_scan' => false, 'error' => 'crt.sh sent an answer that could not be read'];

    $st = $conn->prepare("SELECT COUNT(*) FROM domain_certificates WHERE domain_id = ?");
    $st->execute([$domainId]);
    $firstScan = (int)$st->fetchColumn() === 0;

    $ins = $conn->prepare(
        "INSERT IGNORE INTO domain_certificates (domain_id, crtsh_id, common_name, name_value, issuer, not_before, not_after, first_seen_datetime, acknowledged)
         VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)"
    );
    $new = 0; $newRows = [];
    foreach ($rows as $r) {
        if (empty($r['id'])) continue;
        $ins->execute([
            $domainId, (int)$r['id'],
            mb_substr((string)($r['common_name'] ?? ''), 0, 255),
            mb_substr((string)($r['name_value'] ?? ''), 0, 5000),
            mb_substr(domainCtIssuer((string)($r['issuer_name'] ?? '')), 0, 500),
            domainCtDate($r['not_before'] ?? null), domainCtDate($r['not_after'] ?? null),
            $firstScan ? 1 : 0,
        ]);
        if ($ins->rowCount() === 1 && !$firstScan) { $new++; $newRows[] = $r; }
    }
    if ($new) {
        WorkflowEngine::dispatch('domain.certificate_issued', [
            'domain' => ['id' => $domainId, 'name' => $name],
            'count'  => $new,
            'issuers' => implode(', ', array_unique(array_map(fn($r) => domainCtIssuer((string)($r['issuer_name'] ?? '')), $newRows))),
            'names'  => implode(', ', array_slice(array_unique(array_map(fn($r) => (string)($r['common_name'] ?? ''), $newRows)), 0, 10)),
        ]);
    }
    return ['ok' => true, 'new' => $new, 'total' => count($rows), 'first_scan' => $firstScan, 'error' => null];
}

/**
 * "C=US, O=Let's Encrypt, CN=R11" → "Let's Encrypt (R11)"
 *
 * @param string $dn  an issuer distinguished name as crt.sh gives it, e.g. "C=US, O=Let's Encrypt, CN=R11"
 */
function domainCtIssuer(string $dn): string
{
    $o = preg_match('/(?:^|,\s*)O=("?)([^,"]+)\1/', $dn, $m) ? trim($m[2]) : '';
    $cn = preg_match('/(?:^|,\s*)CN=("?)([^,"]+)\1/', $dn, $m) ? trim($m[2]) : '';
    if ($o && $cn && $cn !== $o) return "$o ($cn)";
    return $o ?: ($cn ?: $dn);
}

/**
 * Turns a crt.sh timestamp (UTC, with or without a Z) into a 'Y-m-d H:i:s' UTC string, or null.
 *
 * @param mixed $v  a crt.sh timestamp string, e.g. '2026-01-02T03:04:05'; empty or null gives null
 */
function domainCtDate($v): ?string
{
    if (!$v) return null;
    $t = strtotime($v . (!str_contains((string)$v, 'Z') ? ' UTC' : ''));
    return $t ? gmdate('Y-m-d H:i:s', $t) : null;
}

// ============================================================================
//  Look-alikes
// ============================================================================

/**
 * The look-alike candidates for a name, keyed by candidate => technique.
 * Deliberately bounded (a few hundred at most): every candidate is two DNS
 * queries.
 *
 * @param string $name  the stored domain name; the first label is varied and the rest kept as the ending
 */
function domainLookalikeCandidates(string $name): array
{
    $dot = strpos($name, '.');
    if ($dot === false) return [];
    $label = substr($name, 0, $dot);            // "example"
    $suffix = substr($name, $dot);              // ".com" / ".co.uk"
    if (strlen($label) < 3) return [];
    $out = [];
    $add = function (string $l, string $tech, ?string $sfx = null) use (&$out, $label, $suffix, $name) {
        $l = trim($l, '-');
        if ($l === '' || strlen($l) > 63 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $l)) return;
        $cand = $l . ($sfx ?? $suffix);
        if ($cand !== $name && !isset($out[$cand])) $out[$cand] = $tech;
    };
    $len = strlen($label);

    // omission: exmple
    for ($i = 0; $i < $len; $i++) $add(substr($label, 0, $i) . substr($label, $i + 1), 'omission');
    // repetition: exxample
    for ($i = 0; $i < $len; $i++) $add(substr($label, 0, $i + 1) . $label[$i] . substr($label, $i + 1), 'repetition');
    // transposition: exmaple
    for ($i = 0; $i < $len - 1; $i++) $add(substr($label, 0, $i) . $label[$i + 1] . $label[$i] . substr($label, $i + 2), 'transposition');
    // keyboard replacement: exsmple
    $kb = ['q' => 'wa', 'w' => 'qes', 'e' => 'wrd', 'r' => 'etf', 't' => 'ryg', 'y' => 'tuh', 'u' => 'yij', 'i' => 'uok', 'o' => 'ipl', 'p' => 'o',
           'a' => 'qsz', 's' => 'awdz', 'd' => 'sefx', 'f' => 'drgc', 'g' => 'fthv', 'h' => 'gyjb', 'j' => 'hukn', 'k' => 'jilm', 'l' => 'kop',
           'z' => 'asx', 'x' => 'zsdc', 'c' => 'xdfv', 'v' => 'cfgb', 'b' => 'vghn', 'n' => 'bhjm', 'm' => 'njk'];
    for ($i = 0; $i < $len; $i++) {
        foreach (str_split($kb[$label[$i]] ?? '') as $r) $add(substr($label, 0, $i) . $r . substr($label, $i + 1), 'replacement');
    }
    // homoglyphs: examp1e, exarnple
    $glyph = ['o' => ['0'], 'l' => ['1', 'i'], 'i' => ['1', 'l'], 'e' => ['3'], 'a' => ['4'], 's' => ['5'], 'b' => ['8'], 'g' => ['9', 'q'], 'm' => ['rn'], 'w' => ['vv'], 'd' => ['cl']];
    for ($i = 0; $i < $len; $i++) {
        foreach ($glyph[$label[$i]] ?? [] as $g) $add(substr($label, 0, $i) . $g . substr($label, $i + 1), 'homoglyph');
    }
    if (str_contains($label, 'rn')) $add(str_replace('rn', 'm', $label), 'homoglyph');
    // hyphenation: ex-ample
    for ($i = 1; $i < $len; $i++) $add(substr($label, 0, $i) . '-' . substr($label, $i), 'hyphenation');
    // vowel swap: exumple
    $vowels = ['a', 'e', 'i', 'o', 'u'];
    for ($i = 0; $i < $len; $i++) {
        if (!in_array($label[$i], $vowels, true)) continue;
        foreach ($vowels as $v) if ($v !== $label[$i]) $add(substr($label, 0, $i) . $v . substr($label, $i + 1), 'vowel-swap');
    }
    // common additions phishers use
    foreach (['login', 'secure', 'support', 'account', 'mail', 'online', 'app', 'portal', 'pay'] as $w) {
        $add($label . '-' . $w, 'addition');
        $add($w . '-' . $label, 'addition');
    }
    // other endings
    foreach (['.com', '.net', '.org', '.co', '.io', '.co.uk', '.uk', '.info', '.biz', '.online', '.app', '.eu', '.de', '.us'] as $tld) {
        if ($tld !== $suffix) $add($label, 'tld-swap', $tld);
    }
    return $out;
}

/**
 * Resolve the candidates and store the ones that exist.
 *
 * @param int    $domainId       the domain the look-alikes are stored against
 * @param string $name           the stored domain name to make variations of
 * @param string $resolver       auto | system | google | cloudflare, as for domainDns()
 * @param float  $budgetSeconds  wall-clock seconds to spend before stopping with the rest unchecked
 *
 * @return array{checked:int, found:int, new:int, with_mx:int}
 */
function domainLookalikeScan(PDO $conn, int $domainId, string $name, string $resolver = 'auto', float $budgetSeconds = 60): array
{
    // GH #203: look-alikes of an internal name are public DNS questions about it.
    if (!domainIdMayLeaveNetwork($conn, $domainId)) return ['checked' => 0, 'found' => 0, 'new' => 0, 'with_mx' => 0, 'internal' => true];
    $cands = domainLookalikeCandidates($name);
    $start = microtime(true);
    $out = ['checked' => 0, 'found' => 0, 'new' => 0, 'with_mx' => 0];
    $upsert = $conn->prepare(
        "INSERT INTO domain_lookalikes (domain_id, lookalike, technique, has_a, has_mx, first_seen_datetime, last_seen_datetime)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE has_a = VALUES(has_a), has_mx = VALUES(has_mx), last_seen_datetime = UTC_TIMESTAMP()"
    );
    // The first scan of a domain is a baseline, like the CT watch: announcing
    // every look-alike that already existed as "new" would be noise.
    $st = $conn->prepare("SELECT COUNT(*) FROM domain_lookalikes WHERE domain_id = ?");
    $st->execute([$domainId]);
    $firstScan = (int)$st->fetchColumn() === 0;
    $newOnes = [];
    foreach ($cands as $cand => $tech) {
        if ((microtime(true) - $start) > $budgetSeconds) break;
        $out['checked']++;
        $a  = domainDns($cand, 'A', $resolver);
        if ($a['nxdomain']) continue;
        $mx = domainDns($cand, 'MX', $resolver);
        $hasA = $a['ok'] && $a['records'] ? 1 : 0;
        $hasMx = $mx['ok'] && $mx['records'] && !(count($mx['records']) === 1 && $mx['records'][0][1] === '') ? 1 : 0;
        if (!$hasA && !$hasMx) continue;
        $upsert->execute([$domainId, $cand, $tech, $hasA, $hasMx]);
        $out['found']++;
        if ($hasMx) $out['with_mx']++;
        if ($upsert->rowCount() === 1) { $out['new']++; $newOnes[] = $cand; }   // 1 = inserted, 2 = updated
    }
    if ($newOnes && !$firstScan) {
        WorkflowEngine::dispatch('domain.lookalike_found', [
            'domain' => ['id' => $domainId, 'name' => $name],
            'count' => count($newOnes),
            'lookalikes' => implode(', ', array_slice($newOnes, 0, 20)),
        ]);
    }
    return $out;
}
