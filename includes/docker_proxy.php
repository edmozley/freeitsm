<?php
/**
 * System -> Docker: "Behind a reverse proxy" (GH #410) - what the container is doing
 * with TRUSTED_PROXIES, and a test of the request in front of you.
 *
 * The setting itself lives in docker-compose.yml and is applied by docker/entrypoint.sh,
 * which writes /etc/apache2/conf-available/freeitsm-remoteip.conf and enables it only
 * when Apache accepts it. So the state shown here is read from what Apache actually
 * loaded (conf-enabled), not just from the environment variable - "set" and "working"
 * are not the same thing, and the page must not say "on" when Apache refused it.
 *
 * Only reads. Nothing here can change how addresses are recorded; that needs the
 * environment variable and a container restart, on purpose.
 */

require_once __DIR__ . '/api_security.php';   // apiIpAllowed() - the same range matching the API allow-list uses

const DOCKER_PROXY_CONF_ENABLED = '/etc/apache2/conf-enabled/freeitsm-remoteip.conf';

/**
 * What the container is doing with TRUSTED_PROXIES.
 *
 * @return array{state: string, list: string[], header: string, wide: string[], names: string[], resolved: string[]}
 *         state: 'off' (not set), 'on' (Apache loaded it) or 'refused' (set, but entrypoint.sh
 *         or Apache turned it down - docker logs says why)
 */
function dockerProxyState(): array
{
    $env    = trim((string)getenv('TRUSTED_PROXIES'));
    $header = trim((string)getenv('TRUSTED_PROXY_HEADER')) ?: 'X-Forwarded-For';
    $list   = [];
    $state  = 'off';

    $conf = @file_get_contents(DOCKER_PROXY_CONF_ENABLED);
    if (is_string($conf) && preg_match('/^RemoteIPInternalProxy\s+(.+)$/m', $conf, $m)) {
        $state = 'on';
        $list  = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
        if (preg_match('/^RemoteIPHeader\s+(\S+)/m', $conf, $h)) $header = $h[1];
    } elseif ($env !== '') {
        $state = 'refused';
        $list  = preg_split('/[\s,]+/', $env, -1, PREG_SPLIT_NO_EMPTY);
    }
    return ['state' => $state, 'header' => $header] + dockerProxyListFacts($list);
}

/**
 * The entries of a proxy list, sorted into what the page warns about.
 * Same thresholds as entrypoint.sh: an IPv4 range wider than /24, an IPv6 range wider than /64.
 *
 * @param string[] $list  the TRUSTED_PROXIES entries
 * @return array{list: string[], wide: string[], names: string[], resolved: string[]}
 *         resolved: every entry as addresses/ranges, host names looked up now
 */
function dockerProxyListFacts(array $list): array
{
    $wide = []; $names = []; $resolved = [];
    foreach ($list as $p) {
        if (str_contains($p, '/')) {
            [$net, $bits] = explode('/', $p, 2);
            $isV6 = str_contains($net, ':');
            if (ctype_digit($bits) && (int)$bits < ($isV6 ? 64 : 24)) $wide[] = $p;
            $resolved[] = $p;
        } elseif (@inet_pton($p) !== false) {
            $resolved[] = $p;
        } else {
            $names[] = $p;
            foreach (@gethostbynamel($p) ?: [] as $ip) $resolved[] = $ip;
        }
    }
    return ['list' => array_values($list), 'wide' => $wide, 'names' => $names, 'resolved' => array_values(array_unique($resolved))];
}

/**
 * The test: what FreeITSM recorded for THIS request, and what that means.
 *
 * Behind mod_remoteip, REMOTE_ADDR is already the client's address and the proxy's own
 * address is gone from PHP's view; the forwarding headers may also have been trimmed. So
 * the verdict says what it can prove and asks the person to confirm the rest - "is this
 * your computer's address?" - rather than claim more than it knows.
 *
 * @param array $state   dockerProxyState()
 * @param array $server  $_SERVER (a parameter so tests can pass their own)
 * @return array{recorded: string, verdict: string, headers: array<string,string>, suggest: ?string}
 *   verdict:
 *     'direct'          off, and no proxy header: the connection is the person's own - nothing to do
 *     'off_proxy'       off, and a proxy header arrived: everyone is recorded as the proxy's address
 *     'working'         on, and the recorded address is not one of the proxies: it came from the header
 *     'still_proxy'     on, but the recorded address IS a listed proxy: the proxy sent no usable header
 *     'refused'         set, but not in force (see docker logs)
 *   suggest: for 'off_proxy', the value TRUSTED_PROXIES would need (the recorded address = the proxy)
 */
function dockerProxyCheck(array $state, array $server): array
{
    $recorded = (string)($server['REMOTE_ADDR'] ?? '');
    $headers = [];
    foreach (['X-Forwarded-For' => 'HTTP_X_FORWARDED_FOR', 'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP',
              'X-Real-IP' => 'HTTP_X_REAL_IP', 'Forwarded' => 'HTTP_FORWARDED'] as $name => $key) {
        if (isset($server[$key]) && $server[$key] !== '') $headers[$name] = substr((string)$server[$key], 0, 300);
    }
    $suggest = null;
    if ($state['state'] === 'refused') {
        $verdict = 'refused';
    } elseif ($state['state'] === 'off') {
        $verdict = $headers ? 'off_proxy' : 'direct';
        if ($headers) $suggest = $recorded;
    } else {
        $isProxy = $recorded !== '' && $state['resolved'] && apiIpAllowed(implode("\n", $state['resolved']), $recorded);
        $verdict = $isProxy ? 'still_proxy' : 'working';
    }
    return ['recorded' => $recorded, 'verdict' => $verdict, 'headers' => $headers, 'suggest' => $suggest];
}
