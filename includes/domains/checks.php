<?php
/**
 * Domains — the health and security checks, and the grade they add up to.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT A FINDING IS
 *
 *   ['key' => 'spf_missing', 'area' => 'email', 'level' => 'fail',
 *    'weight' => 10, 'params' => ['...']]
 *
 * `key` names a pair of strings in lang/en/domains.php — check.<key>.title and
 * check.<key>.advice — so the words are translated and the stored JSON is not
 * English. `level` is pass | info | warn | fail; only warn and fail cost points.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔑 THE PURPOSE CHANGES THE QUESTION
 *
 * A domain nobody sends mail from is not "missing" SPF — it should have a
 * DIFFERENT SPF, one that says "nobody sends mail as me" (v=spf1 -all), plus a
 * DMARC reject policy and a null MX. Otherwise it is the perfect address for
 * somebody else to send phishing from: unused, trusted, and unmonitored. So
 * defensive / parked / redirect domains get the lockdown checks instead of the
 * mail-sending ones. Most tools grade every domain the same and call a parked
 * domain "fine" because it has no mail to fail.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE GRADE
 *
 * 100 minus the weight of every warn and fail, clamped to 0-100, then A+ / A /
 * B / C / D / F. An expired or redemption-period domain is an F whatever else
 * it gets right — nothing else matters if the name is about to be lost.
 */

require_once __DIR__ . '/names.php';
require_once __DIR__ . '/dns.php';
require_once __DIR__ . '/tls.php';
require_once __DIR__ . '/scope.php';   // GH #203: the gate domainRunChecks() asks

/**
 * Run every check for one domain row.
 *
 * @param array $d  the `domains` row
 * @param array $settings domainSettings()
 * @return array{findings:array, score:int, grade:string, ssl_expiry_date:?string,
 *               ssl_issuer:?string, snapshot:array, certificates:array}
 */
function domainRunChecks(array $d, array $settings): array
{
    // GH #203: these are the PUBLIC checks - DS over public DNS, connections to the
    // domain's hosts. An internal domain must never get here; DomainsService::runChecks
    // sends it to domainRunInternalChecks(). Refused rather than trusted, so a new
    // caller that forgets cannot leak an internal name.
    if (!domainMayLeaveNetwork($d)) throw new LogicException('domainRunChecks() is for public domains; internal ones use domainRunInternalChecks().');
    $name     = (string)$d['domain_name'];
    $purpose  = (string)($d['purpose'] ?? 'primary');
    $resolver = (string)($settings['domain_dns_resolver'] ?? 'auto');
    $sslWarn  = (int)($settings['domain_ssl_warn_days'] ?? 21);
    $f = [];
    $add = function (string $key, string $area, string $level, int $weight = 0, array $params = []) use (&$f) {
        $f[] = ['key' => $key, 'area' => $area, 'level' => $level, 'weight' => in_array($level, ['warn', 'fail'], true) ? $weight : 0, 'params' => $params];
    };
    $isLive    = in_array($purpose, ['primary', 'secondary', 'email_only', 'campaign'], true);
    $isNonMail = domainPurposeIsNonMail($purpose);

    // TRAP: a lookup that FAILED is not a record that is MISSING. Every DNS
    //   question goes through $ask, which remembers the ones that could not be
    //   answered; $txt returns null for those, and each check below says nothing
    //   rather than "No SPF record". Before this, a server that could not reach
    //   its resolver reported every domain as having no SPF and no DMARC
    //   (reported by a user, October 2026: both records were there).
    $failed = [];
    $unsupported = [];   // record types this-server-only could not ask (GH #203)
    $ask = function (string $q, string $type) use ($resolver, &$failed, &$unsupported): array {
        $r = domainDns($q, $type, $resolver);
        // Unsupported (this-server-only, GH #203) is not a failure: it must not hold back the grade.
        if (!$r['ok'] && empty($r['unsupported'])) $failed["$type $q"] = (string)$r['error'];
        if (!empty($r['unsupported'])) $unsupported[$type] = true;
        return $r;
    };
    $txt = function (string $q, string $prefix) use ($ask): ?array {
        $r = $ask($q, 'TXT');
        if (!$r['ok']) return null;
        return array_values(array_filter($r['records'], fn($t) => stripos(ltrim($t), $prefix) === 0));
    };

    // A subdomain (includes/domains/subdomains.php): its registration is its
    // parent's, so none of the registration checks are its own; and what DNS
    // keeps at the parent's zone - name servers, DNSSEC, CAA, DMARC - is read
    // there when the subdomain has none of its own.
    $parent = (string)($d['parent_name'] ?? '');
    $isSub  = !empty($d['parent_domain_id']) && $parent !== '';

    // ------------------------------------------------------------------ registration
    if ($isSub) {
        $add('sub_registration', 'registration', 'info', 0, ['parent' => $parent]);
    } else {
    $statuses = array_filter(array_map('trim', explode(',', (string)($d['registry_statuses'] ?? ''))));
    if (in_array('redemption period', $statuses, true) || in_array('pending delete', $statuses, true)) {
        $add('reg_redemption', 'registration', 'fail', 40);
    }

    $today = gmdate('Y-m-d');
    if (!empty($d['expiry_date'])) {
        $days = (int)floor((strtotime($d['expiry_date'] . ' 00:00:00 UTC') - strtotime($today . ' 00:00:00 UTC')) / 86400);
        if ($days < 0)        $add('reg_expired', 'registration', 'fail', 40, ['date' => $d['expiry_date'], 'days' => -$days]);
        elseif ($days <= 30)  $add('reg_expiring', 'registration', 'warn', 8, ['date' => $d['expiry_date'], 'days' => $days]);
        else                  $add('reg_expiry_ok', 'registration', 'pass', 0, ['date' => $d['expiry_date'], 'days' => $days]);
    } else {
        $add('reg_expiry_unknown', 'registration', 'info');
    }

    if ($d['transfer_lock'] === null && domainTransferLockNotApplicable($name)) $add('reg_transfer_uk', 'registration', 'info');
    elseif ($d['transfer_lock'] === null || $d['transfer_lock'] === '') $add('reg_transfer_unknown', 'registration', 'info');
    elseif ((int)$d['transfer_lock'] === 1)                          $add('reg_transfer_locked', 'registration', 'pass');
    else                                                             $add('reg_transfer_unlocked', 'registration', 'fail', 12);

    if ((int)($d['registry_lock'] ?? 0) === 1)  $add('reg_registry_locked', 'registration', 'pass');
    elseif ($purpose === 'primary')             $add('reg_registry_lock_suggest', 'registration', 'info');

    $renew = (string)($d['renewal_mode'] ?? 'unknown');
    if ($renew === 'auto')          $add('renew_auto', 'registration', 'pass');
    elseif ($renew === 'manual')    $add('renew_manual', 'registration', 'info');
    elseif ($renew === 'unknown')   $add('renew_unknown', 'registration', 'warn', 3);
    }

    // ------------------------------------------------------------------ DNS
    $nsQ = $ask($name, 'NS');
    $dnsNs = $nsQ['records'];
    if ($nsQ['nxdomain']) {
        $add('dns_nxdomain', 'dns', 'fail', 25);
    } elseif ($nsQ['ok'] && !$dnsNs && $isSub) {
        // Normal for a subdomain: it lives in its parent's zone, answered by the
        // parent's name servers. Only a delegated subdomain has name servers of its own.
        $add('dns_sub_parent_zone', 'dns', 'pass', 0, ['parent' => $parent]);
    } elseif ($nsQ['ok'] && !$dnsNs) {
        $add('dns_no_ns', 'dns', 'fail', 20);
    } elseif ($dnsNs) {
        if (count($dnsNs) < 2) $add('dns_single_ns', 'dns', 'warn', 5, ['ns' => implode(', ', $dnsNs)]);
        else                   $add('dns_ns_ok', 'dns', 'pass', 0, ['count' => count($dnsNs)]);
        $regNs = array_values(array_filter(array_map(fn($x) => strtolower(rtrim(trim($x), '.')), preg_split('/\R/', (string)($d['nameservers'] ?? '')))));
        sort($regNs);
        if ($regNs && array_diff($regNs, $dnsNs) && array_diff($dnsNs, $regNs)) {
            $add('dns_ns_mismatch', 'dns', 'warn', 5, ['registry' => implode(', ', $regNs), 'dns' => implode(', ', $dnsNs)]);
        }
    }

    // DNSSEC — the registry's word first, a DS lookup when it gave none.
    $dnssec = ($d['dnssec'] === null || $d['dnssec'] === '') ? null : (int)$d['dnssec'] === 1;
    // A subdomain in its parent's zone is signed (or not) with that zone: there
    // is no DS record at the subdomain to find, so its own lookup would say "off".
    $dsName = $name;
    if ($dnssec === null && $isSub && !$dnsNs) {
        $pd = $d['parent_dnssec'] ?? null;
        if ($pd !== null && $pd !== '') $dnssec = (int)$pd === 1;
        else $dsName = $parent;
    }
    if ($dnssec === null) {
        // 'system' asks Google for DS, as it always has; 'system_only' never leaves this server.
        $ds = domainDns($dsName, 'DS', $resolver === 'system' ? 'google' : $resolver);
        if ($ds['ok']) $dnssec = (bool)$ds['records'];
        elseif (!empty($ds['unsupported'])) $unsupported['DS'] = true;
    }
    if ($dnssec === true)                         $add('dnssec_on', 'dns', 'pass');
    elseif ($dnssec === false && $isLive)         $add('dnssec_off', 'dns', 'warn', 4);
    elseif ($dnssec === false)                    $add('dnssec_off', 'dns', 'info');

    $caa = $ask($name, 'CAA');
    // CAA climbs the tree (RFC 8659): with none at the subdomain, the parent's apply.
    if ($isSub && $caa['ok'] && !$caa['records']) $caa = $ask($parent, 'CAA');
    if ($caa['ok'] && $caa['records']) {
        $issuers = [];
        foreach ($caa['records'] as $c) if (in_array($c[1], ['issue', 'issuewild'], true)) $issuers[] = $c[2];
        $add('caa_present', 'dns', 'pass', 0, ['issuers' => implode(', ', array_unique($issuers)) ?: '-']);
    } elseif ($caa['ok'] && in_array($purpose, ['primary', 'secondary'], true)) {
        $add('caa_missing', 'dns', 'warn', 2);
    }

    // ------------------------------------------------------------------ email
    $mxQ = $ask($name, 'MX');
    $mx = $mxQ['records'];
    $nullMx = count($mx) === 1 && $mx[0][1] === '';
    $mxHosts = $nullMx ? [] : array_map(fn($m) => $m[1], $mx);
    // null = could not be looked up (see $ask); [] = looked up, and none there.
    $spfs   = $txt($name, 'v=spf1');
    $dmarcs = $txt('_dmarc.' . $name, 'v=DMARC1');
    $dmarc  = $dmarcs ? domainParseTags($dmarcs[0]) : [];
    // DMARC for a subdomain with no record of its own is its parent's (RFC 7489
    // §6.6.3: the organisational domain's record, with sp= - the subdomain
    // policy - in place of p= when it is there). Not "No DMARC record".
    $inherited = null;
    if ($isSub && $dmarcs === []) {
        $pDmarcs = $txt('_dmarc.' . $parent, 'v=DMARC1');
        if ($pDmarcs === null) {
            $dmarcs = null;                        // could not ask the parent: say nothing
        } elseif (count($pDmarcs) === 1) {
            $tags = domainParseTags($pDmarcs[0]);
            $inherited = strtolower($tags['sp'] ?? $tags['p'] ?? '');
            $dmarcs = $pDmarcs;
            $dmarc  = $tags + ['p' => ''];
            $dmarc['p'] = $inherited;              // the policy that applies HERE
            $add('dmarc_inherited', 'email', 'info', 0, ['parent' => $parent, 'policy' => $inherited ?: 'none']);
        }
    }

    if ($isNonMail) {
        // Lock-down: this name should never send or receive mail.
        if ($mxQ['ok']) {
            if ($mxHosts) $add('lockdown_mx', 'email', 'warn', 6, ['mx' => implode(', ', $mxHosts)]);
            else          $add('lockdown_mx_ok', 'email', 'pass', 0, ['null' => $nullMx ? 1 : 0]);
        }

        if ($spfs !== null) {
            $spfLocked = count($spfs) === 1 && preg_match('/^v=spf1\s+-all\s*$/i', trim($spfs[0]));
            if ($spfLocked) $add('lockdown_spf_ok', 'email', 'pass');
            else            $add('lockdown_spf', 'email', 'fail', 10, ['current' => $spfs ? implode(' | ', $spfs) : '']);
        }

        if ($dmarcs !== null) {
            if (strtolower($dmarc['p'] ?? '') === 'reject') $add('lockdown_dmarc_ok', 'email', 'pass');
            else                                            $add('lockdown_dmarc', 'email', 'fail', 10, ['current' => $dmarc['p'] ?? '', 'domain' => $name]);
        }
    } else {
        if (!$mxQ['ok'])   { /* could not look it up - said once, below */ }
        elseif ($nullMx)   $add('mx_null', 'email', 'info');
        elseif ($mxHosts)  $add('mx_present', 'email', 'pass', 0, ['mx' => implode(', ', array_slice($mxHosts, 0, 4))]);
        else               $add('mx_none', 'email', 'info');

        // SPF
        if ($spfs === null) {
            // could not look it up - said once, below
        } elseif (!$spfs) {
            $add('spf_missing', 'email', 'fail', 10);
        } elseif (count($spfs) > 1) {
            $add('spf_multiple', 'email', 'fail', 10, ['count' => count($spfs)]);
        } else {
            $spf = trim($spfs[0]);
            if (preg_match('/(^|\s)\+?all(\s|$)/i', $spf) && !preg_match('/[-~?]all/i', $spf)) {
                $add('spf_pass_all', 'email', 'fail', 15, ['record' => $spf]);
            } elseif (preg_match('/-all\s*$/i', $spf)) {
                $add('spf_hardfail', 'email', 'pass', 0, ['record' => $spf]);
            } elseif (preg_match('/~all\s*$/i', $spf)) {
                $add('spf_softfail', 'email', 'pass', 0, ['record' => $spf]);
            } else {
                $add('spf_neutral', 'email', 'warn', 6, ['record' => $spf]);
            }
            $broken = [];
            $lookups = domainSpfLookupCount($spf, $resolver, 0, $broken);
            if ($lookups > 10) $add('spf_too_many_lookups', 'email', 'fail', 8, ['count' => $lookups]);
            foreach (array_slice($broken, 0, 3) as $b) $add('spf_broken_include', 'email', 'warn', 4, ['name' => $b]);
        }

        // DMARC
        if ($dmarcs === null) {
            // could not look it up - said once, below
        } elseif (!$dmarcs) {
            $add('dmarc_missing', 'email', 'fail', 12, ['domain' => $isSub ? $parent : $name]);   // a subdomain: one record at the parent covers it
        } elseif (count($dmarcs) > 1) {
            $add('dmarc_multiple', 'email', 'fail', 10);
        } else {
            $p = strtolower($dmarc['p'] ?? '');
            if ($p === 'reject')          $add('dmarc_reject', 'email', 'pass');
            elseif ($p === 'quarantine')  $add('dmarc_quarantine', 'email', 'pass');
            else                          $add('dmarc_monitor_only', 'email', 'warn', 8, ['policy' => $p ?: 'none']);
            if (isset($dmarc['pct']) && (int)$dmarc['pct'] < 100) $add('dmarc_partial', 'email', 'warn', 3, ['pct' => (int)$dmarc['pct']]);
            if (empty($dmarc['rua'])) $add('dmarc_no_reports', 'email', 'info');
        }

        // DKIM — selectors cannot be listed, so the ones on the record are
        // checked, and a few common defaults are tried quietly when there are none.
        $configured = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($d['dkim_selectors'] ?? '')))));
        if ($configured) {
            foreach ($configured as $sel) {
                $key = $txt(strtolower($sel) . '._domainkey.' . $name, '');
                if ($key === null) continue;   // could not look it up - said once, below
                $key = array_filter($key, fn($t) => preg_match('/^\s*(v=DKIM1|k=|p=)/i', $t));
                if ($key) $add('dkim_found', 'email', 'pass', 0, ['selector' => $sel]);
                else      $add('dkim_selector_missing', 'email', 'warn', 5, ['selector' => $sel]);
            }
        } elseif ($mxHosts || $spfs) {
            $found = null;
            $unsure = false;
            // Microsoft 365, Google Workspace, generic, Mailchimp, and two common
            // defaults. A CNAME-published key is followed by the TXT query itself.
            foreach (['selector1', 'google', 'default', 'k1', 's1', 'dkim'] as $sel) {
                $selTxt = $ask($sel . '._domainkey.' . $name, 'TXT');
                if (!$selTxt['ok']) $unsure = true;
                if ($selTxt['ok'] && $selTxt['records']) { $found = $sel; break; }
            }
            if ($found)       $add('dkim_found', 'email', 'pass', 0, ['selector' => $found]);
            elseif (!$unsure) $add('dkim_unknown', 'email', 'info');
        }

        if ($mxHosts) {
            $sts = $txt('_mta-sts.' . $name, 'v=STSv1');
            if ($sts) $add('mta_sts_on', 'email', 'pass');
            elseif ($sts !== null) $add('mta_sts_off', 'email', 'info');
            $rpt = $txt('_smtp._tls.' . $name, 'v=TLSRPTv1');
            if ($rpt) $add('tls_rpt_on', 'email', 'pass');
            elseif ($rpt !== null) $add('tls_rpt_off', 'email', 'info');
        }
    }

    // ------------------------------------------------------------------ web / certificates
    $hosts = array_merge([$name, 'www.' . $name], domainParseSslHosts($d['ssl_hosts'] ?? '', $name));
    $soonest = null; $soonestIssuer = null; $certs = [];
    foreach ($hosts as $i => $h) {
        $isExtra = $i >= 2;
        $a  = $ask($h, 'A');
        $aa = $ask($h, 'AAAA');
        if (!($a['records'] || $aa['records'])) {
            // "No address" only when both were looked up and both said so.
            if ($isExtra && $a['ok'] && $aa['ok']) $add('web_no_host', 'web', 'warn', 3, ['host' => $h]);
            continue;
        }
        $c = domainTlsCertificate($h);
        $certs[] = $c;
        $wantsWeb = !in_array($purpose, ['email_only', 'defensive', 'parked'], true) || $isExtra;
        if (!$c['ok']) {
            $add('tls_unavailable', 'web', $wantsWeb ? 'warn' : 'info', 6, ['host' => $h, 'error' => (string)$c['error']]);
            continue;
        }
        if ($c['days_left'] !== null && $c['days_left'] < 0) {
            $add('tls_expired', 'web', 'fail', 15, ['host' => $h, 'date' => $c['valid_to']]);
        } elseif ($c['days_left'] !== null && $c['days_left'] <= $sslWarn) {
            $add('tls_expiring', 'web', 'warn', 6, ['host' => $h, 'date' => $c['valid_to'], 'days' => $c['days_left']]);
        }
        if ($c['hostname_match'] === false) {
            $add('tls_name_mismatch', 'web', 'fail', 10, ['host' => $h, 'names' => implode(', ', array_slice($c['sans'], 0, 5))]);
        } elseif ($c['chain_valid'] === false) {
            $add('tls_untrusted', 'web', 'warn', 8, ['host' => $h, 'issuer' => (string)$c['issuer']]);
        } elseif ($c['days_left'] !== null && $c['days_left'] > $sslWarn) {
            $add('tls_ok', 'web', 'pass', 0, ['host' => $h, 'date' => $c['valid_to'], 'issuer' => (string)$c['issuer']]);
        }
        if ($c['valid_to'] && ($soonest === null || $c['valid_to'] < $soonest)) {
            $soonest = $c['valid_to'];
            $soonestIssuer = $c['issuer'];
        }
    }

    // ------------------------------------------------------------------ lookups not made
    // GH #203: with "this server only", record types that need DNS-over-HTTPS are not
    // asked at all. Said once, as information - those checks are simply not done here,
    // which is neither a failure nor a pass, and costs the domain nothing.
    if ($unsupported) {
        $add('dns_server_only', 'dns', 'info', 0, ['types' => implode(', ', array_keys($unsupported))]);
    }

    // ------------------------------------------------------------------ lookups that failed
    // Said once, with what failed and why, instead of a wrong answer per check.
    // The grade is withheld: a score from the checks that happened to work
    // would look like a clean bill of health it is not.
    $dnsFailed = (bool)$failed;
    if ($dnsFailed) {
        $add('dns_lookup_failed', 'dns', 'warn', 0, [
            'count'    => count($failed),
            'names'    => implode(', ', array_slice(array_keys($failed), 0, 4)) . (count($failed) > 4 ? ', …' : ''),
            'error'    => (string)reset($failed),
            'resolver' => $resolver,
        ]);
    }

    // ------------------------------------------------------------------ grade
    [$score, $grade] = $dnsFailed ? [null, null] : domainGrade($f);

    return [
        'findings'        => $f,
        'score'           => $score,
        'grade'           => $grade,
        'ssl_expiry_date' => $soonest,
        'ssl_issuer'      => $soonestIssuer,
        'certificates'    => $certs,
        // What change detection compares tomorrow (see monitor.php).
        'snapshot'        => [
            'dns_ns' => $dnsNs,
            'mx'     => $mxHosts,
            'spf'    => $spfs ? trim($spfs[0]) : '',
            'dmarc'  => $dmarcs ? trim($dmarcs[0]) : '',
        ],
    ];
}

/**
 * Scores a domain's health findings out of 100 (each takes off its weight) and
 * turns the score into a grade, A+ to F; an expired, redemption or NXDOMAIN
 * finding is always F.
 *
 * @param array $findings  the check findings, each with at least 'key' and 'weight' (points taken off)
 *
 * @return array{0:int,1:string}
 */
function domainGrade(array $findings): array
{
    $score = 100;
    $fatal = false;
    foreach ($findings as $x) {
        $score -= (int)$x['weight'];
        if (in_array($x['key'], ['reg_expired', 'reg_redemption', 'dns_nxdomain'], true)) $fatal = true;
    }
    $score = max(0, min(100, $score));
    if ($fatal)            $grade = 'F';
    elseif ($score >= 100) $grade = 'A+';
    elseif ($score >= 90)  $grade = 'A';
    elseif ($score >= 80)  $grade = 'B';
    elseif ($score >= 65)  $grade = 'C';
    elseif ($score >= 50)  $grade = 'D';
    else                   $grade = 'F';
    return [$score, $grade];
}

/**
 * "v=DMARC1; p=reject; rua=mailto:x" → ['v'=>'DMARC1','p'=>'reject','rua'=>'mailto:x']
 *
 * @param string $record  a tag=value record such as DMARC; parts without an "=" are skipped
 */
function domainParseTags(string $record): array
{
    $out = [];
    foreach (explode(';', $record) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) === 2) $out[strtolower(trim($kv[0]))] = trim($kv[1]);
    }
    return $out;
}

/**
 * How many DNS lookups evaluating this SPF record costs (RFC 7208 §4.6.4 caps it
 * at 10; past that, receivers treat the record as a permanent error — i.e. SPF
 * silently stops protecting the domain). Follows include: and redirect=.
 *
 * @param string $spf       the SPF record text, e.g. "v=spf1 include:_spf.example.com -all"
 * @param string $resolver  auto | system | google | cloudflare, as for domainDns()
 * @param int    $depth     how deep in the include/redirect chain this is - 0 for the top; past 10 counts nothing
 * @param array $broken collects include/redirect targets that have no SPF record
 * @param array  $seen      targets already followed (filled in as it goes), so a loop is counted once
 */
function domainSpfLookupCount(string $spf, string $resolver, int $depth, array &$broken, array &$seen = []): int
{
    if ($depth > 10) return 0;
    $count = 0;
    foreach (preg_split('/\s+/', trim($spf)) as $term) {
        $t = strtolower(ltrim($term, '+-~?'));
        if (preg_match('/^(a|mx|ptr|exists)([:\/]|$)/', $t)) {
            $count++;
        } elseif (preg_match('/^(include:|redirect=)(.+)$/', $t, $m)) {
            $count++;
            $target = rtrim($m[2], '.');
            if (str_contains($target, '%') || isset($seen[$target])) continue;   // macros: cannot follow
            $seen[$target] = true;
            $subQ = domainDns($target, 'TXT', $resolver);
            if (!$subQ['ok']) continue;   // could not look it up: not the same as broken
            $sub = domainTxtStartingWith($target, 'v=spf1', $resolver);
            if (!$sub) { $broken[] = $target; continue; }
            $count += domainSpfLookupCount($sub[0], $resolver, $depth + 1, $broken, $seen);
        }
    }
    return $count;
}
