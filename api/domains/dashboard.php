<?php
/**
 * GET — the numbers behind Domains → Dashboard, over the same scoped register
 * the list shows (so the dashboard can never count a domain the list hides):
 * totals, the attention list, grades, email-security posture, the next twelve
 * months of renewals by month with their cost, and spend by registrar / company.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
require_once __DIR__ . '/../../includes/domains/read.php';

domainApiRun(function () use ($conn, $analystId) {
    $rows = domainListRows($conn, $analystId);
    $currency = null; $mixedCurrency = false;

    $stats = ['total' => count($rows), 'expired' => 0, 'expiring_30' => 0, 'expiring_90' => 0, 'unlocked' => 0,
              'no_dnssec' => 0, 'ssl_expiring' => 0, 'auto_renew' => 0, 'lookalikes' => 0, 'new_certificates' => 0,
              'never_checked' => 0, 'annual_cost' => 0.0,
              // GH #203: internal domains, by health. They are in none of the public
              // counts below - no grade, no email posture, no registration.
              'internal' => 0, 'internal_healthy' => 0, 'internal_problem' => 0, 'internal_not_checked' => 0, 'internal_stale' => 0];
    $grades = ['A+' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0, 'none' => 0];
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $k = gmdate('Y-m', strtotime(gmdate('Y-m-01') . " +$i month"));
        $months[$k] = ['month' => $k, 'count' => 0, 'cost' => 0.0];
    }
    $byRegistrar = []; $byCompany = []; $byPurpose = []; $attention = [];
    $email = ['spf_ok' => 0, 'spf_bad' => 0, 'dmarc_enforced' => 0, 'dmarc_monitor' => 0, 'dmarc_missing' => 0, 'lockdown_ok' => 0, 'lockdown_bad' => 0];

    $findingsSt = $conn->prepare("SELECT check_results FROM domains WHERE id = ?");
    foreach ($rows as $r) {
        if ($r['scope'] === 'internal') {
            $stats['internal']++;
            $stats['internal_' . $r['health']['state']]++;
            if ($r['annual_cost'] !== null) $stats['annual_cost'] += $r['annual_cost'];
            if ($r['attention']) {
                $attention[] = ['id' => $r['id'], 'domain_name' => $r['display_name'] ?: $r['domain_name'], 'company_name' => $r['company_name'],
                                'reasons' => $r['attention'], 'days_left' => null, 'ssl_days_left' => null,
                                'security_grade' => null, 'health' => $r['health']['state']];
            }
            continue;
        }
        $live = !in_array($r['renewal_mode'], ['do_not_renew'], true) && ($r['alerts_enabled'] ?? true) !== false;
        if ($live && $r['days_left'] !== null) {
            if ($r['days_left'] < 0) $stats['expired']++;
            elseif ($r['days_left'] <= 30) $stats['expiring_30']++;
            if ($r['days_left'] >= 0 && $r['days_left'] <= 90) $stats['expiring_90']++;
        }
        if ($r['transfer_lock'] === false) $stats['unlocked']++;
        if ($r['dnssec'] === false) $stats['no_dnssec']++;
        if ($r['ssl_days_left'] !== null && $r['ssl_days_left'] <= 21) $stats['ssl_expiring']++;
        if ($r['renewal_mode'] === 'auto') $stats['auto_renew']++;
        $stats['lookalikes'] += (int)$r['lookalike_count'];
        $stats['new_certificates'] += (int)$r['new_certificate_count'];
        if ($r['last_check_datetime'] === null) $stats['never_checked']++;
        $grades[$r['security_grade'] ?: 'none'] = ($grades[$r['security_grade'] ?: 'none'] ?? 0) + 1;

        if ($r['annual_cost'] !== null) {
            $stats['annual_cost'] += $r['annual_cost'];
            if ($r['currency']) {
                if ($currency === null) $currency = $r['currency'];
                elseif ($currency !== $r['currency']) $mixedCurrency = true;
            }
        }
        if ($live && $r['expiry_date']) {
            $k = substr($r['expiry_date'], 0, 7);
            if (isset($months[$k])) {
                $months[$k]['count']++;
                $months[$k]['cost'] += (float)($r['cost'] ?? 0);
            }
        }
        $reg = $r['supplier_name'] ?: ($r['registrar_name'] ?: '—');
        $byRegistrar[$reg] = ($byRegistrar[$reg] ?? ['name' => $reg, 'count' => 0, 'cost' => 0.0]);
        $byRegistrar[$reg]['count']++;
        $byRegistrar[$reg]['cost'] += (float)($r['annual_cost'] ?? 0);
        if (!empty($r['company_name'])) {
            $c = $r['company_name'];
            $byCompany[$c] = ($byCompany[$c] ?? ['name' => $c, 'count' => 0, 'cost' => 0.0]);
            $byCompany[$c]['count']++;
            $byCompany[$c]['cost'] += (float)($r['annual_cost'] ?? 0);
        }
        $byPurpose[$r['purpose']] = ($byPurpose[$r['purpose']] ?? 0) + 1;

        // Email posture, from the stored findings.
        $findingsSt->execute([$r['id']]);
        $cr = json_decode((string)$findingsSt->fetchColumn(), true);
        $keys = array_column($cr['findings'] ?? [], 'key');
        if (array_intersect($keys, ['spf_hardfail', 'spf_softfail'])) $email['spf_ok']++;
        if (array_intersect($keys, ['spf_missing', 'spf_multiple', 'spf_pass_all', 'spf_neutral', 'spf_too_many_lookups'])) $email['spf_bad']++;
        if (array_intersect($keys, ['dmarc_reject', 'dmarc_quarantine'])) $email['dmarc_enforced']++;
        if (in_array('dmarc_monitor_only', $keys, true)) $email['dmarc_monitor']++;
        if (in_array('dmarc_missing', $keys, true)) $email['dmarc_missing']++;
        if (in_array('lockdown_spf_ok', $keys, true) && in_array('lockdown_dmarc_ok', $keys, true)) $email['lockdown_ok']++;
        if (array_intersect($keys, ['lockdown_spf', 'lockdown_dmarc', 'lockdown_mx'])) $email['lockdown_bad']++;

        if ($r['attention']) {
            $attention[] = ['id' => $r['id'], 'domain_name' => $r['display_name'] ?: $r['domain_name'], 'company_name' => $r['company_name'],
                            'reasons' => $r['attention'], 'days_left' => $r['days_left'], 'ssl_days_left' => $r['ssl_days_left'],
                            'security_grade' => $r['security_grade']];
        }
    }
    usort($attention, fn($a, $b) => ($a['days_left'] ?? 99999) <=> ($b['days_left'] ?? 99999));
    $sortByCount = fn(array $x) => (usort($x, fn($a, $b) => $b['count'] <=> $a['count']) ? $x : $x);
    $stats['annual_cost'] = round($stats['annual_cost'], 2);

    domainApiOk([
        'stats'        => $stats,
        'currency'     => $currency,
        'mixed_currency' => $mixedCurrency,
        'grades'       => $grades,
        'months'       => array_values($months),
        'by_registrar' => array_slice($sortByCount(array_values($byRegistrar)), 0, 12),
        'by_company'   => $sortByCount(array_values($byCompany)),
        'by_purpose'   => $byPurpose,
        'email'        => $email,
        'attention'    => array_slice($attention, 0, 50),
        'multi_company'=> isMultiTenant($conn),
    ]);
});
