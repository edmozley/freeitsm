<?php
/**
 * Domains - internal scope (GH #203, slice 1).
 *
 * A domain is PUBLIC (everything Domains has always done) or INTERNAL: an
 * intranet, an internal application, a name that only means something inside
 * the network. Scope is not another Purpose - Purpose says what a public name is
 * for; Scope says whether it is public at all. Design and review:
 * wiki Domains-Internal-Monitoring-Design.
 *
 * WHAT AN INTERNAL DOMAIN NEVER DOES
 * ----------------------------------
 * Leave the network. No public DNS (not even as a fallback - DNS goes to this
 * server's resolver only, the slice 0 "system_only" code), no registry lookup
 * (RDAP, WHOIS, IANA's bootstrap file), no Certificate Transparency, no
 * look-alike scan, and - in slice 1 - no HTTPS connection at all: connecting to
 * internal addresses needs the slice 2 protections (administrator-only targets,
 * permitted networks and ports, resolved-address connections), so it arrives
 * with them.
 *
 * 🔑 ONE GATE. Every outbound path asks domainMayLeaveNetwork() for the domain
 * it is about to work on. A new route out added later must ask it too - and
 * tests/domains-internal-scope.php traces every route (HTTP, WHOIS, TLS and
 * system DNS) to prove an internal domain takes none but its own DNS.
 *
 * WHAT IT GETS INSTEAD OF A GRADE
 * -------------------------------
 * A health status, from DNS alone: does each name it knows (the domain and the
 * names listed for it) resolve, through this server. Two dates are kept, as the
 * review asked - the last COMPLETED check (one that ran to an answer, including
 * an answer that found a problem: that is a fresh observation) and the last
 * HEALTHY result - and a known problem stays on screen, marked, when later
 * checks cannot complete. Staleness is worked out when the page is read, so a
 * stopped scheduler shows without another run.
 */

/** Whether a domain is public or only exists inside the network. */
enum DomainScope: string
{
    case Public   = 'public';     // today's behaviour, and the upgrade default
    case Internal = 'internal';   // GH #203: never leaves the network
}

/** The intervals the internal checks may run at, in minutes. */
const DOMAIN_INTERNAL_INTERVALS = [15, 30, 60, 240, 1440];

/** Has Database Verification added domains.scope and the health columns yet? */
function domainScopeReady(PDO $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $conn->query("SELECT scope, health_status, health_completed_datetime, health_healthy_datetime, health_incomplete FROM domains LIMIT 0"); return $ok = true; }
    catch (Throwable) { return $ok = false; }
}

/** Is this domain row internal? A row from before the column existed is public. */
function domainIsInternal(array $d): bool
{
    return ($d['scope'] ?? '') === DomainScope::Internal->value;
}

/**
 * THE GATE: may anything about this domain be sent outside the network - to a
 * public DNS service, a registry, crt.sh, or a connection to its hosts?
 *
 * Every outbound path asks this for the domain it is about to work on. DNS
 * through this server's own resolver is not "leaving" (the domain's own checks
 * use it, forced to system_only); everything else is.
 *
 * @param array $d  the domains row (or at least its scope)
 */
function domainMayLeaveNetwork(array $d): bool
{
    return !domainIsInternal($d);
}

/**
 * The gate, for a function that only has the domain's id (the watches). Reads
 * the stored scope; before Database Verification there is no internal domain,
 * so the answer is yes.
 */
function domainIdMayLeaveNetwork(PDO $conn, int $domainId): bool
{
    if (!domainScopeReady($conn)) return true;
    $st = $conn->prepare("SELECT scope FROM domains WHERE id = ?");
    $st->execute([$domainId]);
    return domainMayLeaveNetwork(['scope' => $st->fetchColumn() ?: '']);
}

/** How often internal domains are checked, in minutes (Domains -> Settings -> Monitoring). */
function domainInternalInterval(array $settings): int
{
    $m = (int)($settings['domain_internal_interval'] ?? 60);
    return in_array($m, DOMAIN_INTERNAL_INTERVALS, true) ? $m : 60;
}

/**
 * The DNS-only check run for an internal domain.
 *
 * Each name - the domain, then the names listed for it - is asked for A and AAAA
 * through this server's resolver and nothing else (system_only). There is no www
 * assumption and no certificate connection.
 *
 *   both answered, with addresses   → resolves (pass)
 *   both answered, with none        → no address (a confirmed problem: this
 *                                     server's resolver says the name has none)
 *   either could not be answered    → not checked (says nothing about the name)
 *
 * TRAP: PHP's resolver cannot tell NXDOMAIN from "no records of this type" -
 * dns_get_record() returns an empty list for both, and false only when the query
 * itself failed. So "no address" covers both, and only `false` is "could not ask".
 *
 * @param array $d  the domains row
 * @return array{findings:array, completed:bool, problem:bool}
 *   completed: every question got an answer, or a problem was confirmed (a fresh
 *   observation either way). problem: at least one name has no address.
 */
function domainRunInternalChecks(array $d): array
{
    $name  = (string)$d['domain_name'];
    $hosts = array_merge([$name], domainParseSslHosts($d['ssl_hosts'] ?? '', $name));
    $f = [];
    $add = function (string $key, string $level, array $params) use (&$f) {
        $f[] = ['key' => $key, 'area' => 'health', 'level' => $level, 'weight' => 0, 'params' => $params];
    };

    $problem = false;
    $failed  = false;
    foreach ($hosts as $h) {
        // TRAP: always system_only here, whatever the install-wide resolver says -
        // 'system' falls back to Google, and 'auto' IS Google on Windows.
        $a  = domainDns($h, 'A', 'system_only');
        $aa = domainDns($h, 'AAAA', 'system_only');
        $addresses = array_merge($a['ok'] ? $a['records'] : [], $aa['ok'] ? $aa['records'] : []);
        if ($addresses) {
            // Either answer with an address is enough: the name resolves.
            $add('int_resolves', 'pass', ['host' => $h, 'addresses' => implode(', ', array_slice($addresses, 0, 6))]);
        } elseif (!$a['ok']) {
            $failed = true;
            $add('int_not_checked', 'info', ['host' => $h, 'error' => (string)$a['error']]);
        } else {
            // TRAP: on Windows, PHP answers a name that does not exist with an
            // EMPTY A list but a FAILED AAAA query ("DNS Query failed") - measured
            // on PHP 8.4. Requiring both to answer made a missing name "not
            // checked" for ever on a Windows server, so the one thing this check
            // is for - noticing a name that stopped resolving - never fired. The
            // A answer is a real answer: no IPv4 address. Where AAAA could not be
            // asked, the finding says so rather than hiding it.
            $problem = true;
            $add('int_no_address', 'fail', ['host' => $h, 'ipv6_unknown' => !$aa['ok']]);
        }
    }
    // Said once: what slice 1 deliberately does not do, so an empty certificate
    // section reads as "not checked here", never as "fine".
    $add('int_scope_note', 'info', []);

    return ['findings' => $f, 'completed' => $problem || !$failed, 'problem' => $problem];
}

/**
 * An internal domain's health, as the screens, the API and the counts show it.
 *
 *   state   healthy | problem | not_checked | stale
 *   known   the last COMPLETED result (healthy | problem), or null when no check
 *           has ever completed - shown beside a stale or not-checked state, so a
 *           known problem never disappears into "unknown"
 *   since_incomplete  the latest attempt did not complete (a resolver error)
 *
 * Stale: the last completed check is older than twice the interval. Worked out
 * here, at read time - a stopped scheduler shows without another run.
 *
 * @param array $d         the domains row
 * @param int   $interval  domainInternalInterval(), in minutes
 * @param ?int  $now       unix time, for tests
 */
function domainHealth(array $d, int $interval, ?int $now = null): array
{
    $now = $now ?? time();
    $known      = in_array($d['health_status'] ?? null, ['healthy', 'problem'], true) ? $d['health_status'] : null;
    $completed  = $d['health_completed_datetime'] ?? null;
    $healthy    = $d['health_healthy_datetime'] ?? null;
    $incomplete = !empty($d['health_incomplete']);

    if ($known === null || $completed === null) {
        $state = 'not_checked';
    } elseif (strtotime($completed . ' UTC') < $now - 2 * $interval * 60) {
        $state = 'stale';
    } elseif ($incomplete) {
        $state = 'not_checked';
    } else {
        $state = $known;
    }
    return [
        'state'            => $state,
        'known'            => $known,
        'last_completed'   => $completed,
        'last_healthy'     => $healthy,
        'since_incomplete' => $incomplete,
        'interval_minutes' => $interval,
    ];
}
