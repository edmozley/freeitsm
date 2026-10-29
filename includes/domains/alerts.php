<?php
/**
 * Domains — who hears about what, and when.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THREE KINDS OF NEWS
 *
 *   expiry    a registration is N days from lapsing (the days list), or has
 *             lapsed and is still rescuable (a chase every 7 days for 45 days —
 *             roughly the registry grace + redemption window)
 *   ssl       a certificate on the domain is within the warning window
 *   changed   change detection saw something important move (see monitor.php)
 *
 * Each reaches three audiences, independently:
 *
 *   events    domain.expiring / domain.ssl_expiring / domain.changed through
 *             WorkflowEngine::dispatch() — so the notification bell (the owner)
 *             and any workflow hear it. ALWAYS fired: they are internal, and a
 *             workflow author should not have to switch e-mail on to get a trigger.
 *   e-mail    one DIGEST per recipient per run, not one mail per domain — three
 *             hundred domains renewing in the same month must not be three hundred
 *             e-mails. OFF until Domains → Settings → Alerts switches it on.
 *   actions   optionally a task or a ticket at the renewal window, once per
 *             expiry date, so renewing is somebody's job with a due date.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FIRE ONCE
 *
 * domain_alerts_sent holds (domain, kind, fingerprint). The fingerprint carries
 * the date the alert is about, so renewing a domain (a new expiry date) re-arms
 * every window with nothing to clear. Each audience has its own kind — mail_*,
 * evt_*, act_* — so switching e-mail on later still sends the mail for a window
 * whose event already fired.
 *
 * 🔑 CLAIM BEFORE SENDING, GIVE IT BACK ON FAILURE — the LMS reminders rule: an
 * INSERT IGNORE claim makes an overlapping cron + page-load run send once, and a
 * failed send releases its claim so the failure is not permanent and silent.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../services/domains.php';
require_once __DIR__ . '/../self_service_email.php';   // ssSendSystemEmail()
if (is_file(__DIR__ . '/../public_url.php')) require_once __DIR__ . '/../public_url.php';

/**
 * Claim an alert. true = ours to send; false = already sent (or claimed a moment ago).
 *
 * @param int    $domainId     the domain the alert is about
 * @param string $kind         audience prefix + kind, e.g. 'mail_expiry', 'evt_ssl', 'act_renew', 'mail_changed'
 * @param string $fingerprint  what makes this alert unique within the kind - normally the date it is about
 */
function domainAlertClaim(PDO $conn, int $domainId, string $kind, string $fingerprint): bool
{
    $st = $conn->prepare("INSERT IGNORE INTO domain_alerts_sent (domain_id, alert_kind, fingerprint, sent_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())");
    $st->execute([$domainId, $kind, $fingerprint]);
    return $st->rowCount() === 1;
}

/**
 * Gives back a claimed alert, so the next run tries it again - used when every e-mail for it failed.
 *
 * @param int    $domainId     the domain the alert is about
 * @param string $kind         the kind it was claimed under, e.g. 'mail_expiry'
 * @param string $fingerprint  the fingerprint it was claimed with
 *
 * @writes domain_alerts_sent - removes the claim row
 */
function domainAlertRelease(PDO $conn, int $domainId, string $kind, string $fingerprint): void
{
    $conn->prepare("DELETE FROM domain_alerts_sent WHERE domain_id = ? AND alert_kind = ? AND fingerprint = ?")->execute([$domainId, $kind, $fingerprint]);
}

/**
 * True when this alert (domain, kind, fingerprint) has already been claimed or sent.
 *
 * @param int    $domainId     the domain the alert is about
 * @param string $kind         the kind to look for, e.g. 'mail_ssl'
 * @param string $fingerprint  the fingerprint to look for
 */
function domainAlertAlreadySent(PDO $conn, int $domainId, string $kind, string $fingerprint): bool
{
    $st = $conn->prepare("SELECT 1 FROM domain_alerts_sent WHERE domain_id = ? AND alert_kind = ? AND fingerprint = ?");
    $st->execute([$domainId, $kind, $fingerprint]);
    return (bool)$st->fetchColumn();
}

/**
 * Everything due today for expiry and certificates.
 *
 * @param array $s  every Domains setting, as domainSettings() returns them
 *
 * @return array<int,array> each: domain row fields + kind ('expiry'|'expired'|'ssl'), days, date, fingerprint
 */
function domainAlertsDue(PDO $conn, array $s): array
{
    $days     = domainParseDays((string)$s['domain_alert_days']);
    $chase    = $s['domain_alert_chase_expired'] === '1';
    $autoToo  = $s['domain_alert_auto_renew'] === '1';
    $sslOn    = $s['domain_ssl_alerts'] === '1';
    $sslDays  = (int)$s['domain_ssl_warn_days'];
    $today    = new DateTimeImmutable(gmdate('Y-m-d'));

    require_once __DIR__ . '/subdomains.php';
    $subReady = domainSubdomainsReady($conn);
    $rows = $conn->query(
        "SELECT d.id, d.domain_name, d.display_name, d.expiry_date, d.ssl_expiry_date, d.ssl_issuer, d.renewal_mode,
                " . ($subReady ? 'd.parent_domain_id' : 'NULL AS parent_domain_id') . ",
                " . (domainScopeReady($conn) ? 'd.scope' : "'public' AS scope") . ",
                d.owner_analyst_id, d.tenant_id, d.purpose, d.status_id, d.registrar_name, d.security_grade,
                a.full_name AS owner_name, a.email AS owner_email, t.name AS company_name
           FROM domains d
      LEFT JOIN domain_statuses s ON s.id = d.status_id
      LEFT JOIN analysts a ON a.id = d.owner_analyst_id AND a.is_active = 1
      LEFT JOIN tenants t ON t.id = d.tenant_id
          WHERE (s.id IS NULL OR s.alerts_enabled = 1)
            AND d.renewal_mode <> 'do_not_renew'
            AND (d.expiry_date IS NOT NULL OR d.ssl_expiry_date IS NOT NULL)"
    )->fetchAll(PDO::FETCH_ASSOC);

    $due = [];
    foreach ($rows as $r) {
        // A subdomain's registration is its parent's: the parent is the one reminded.
        // An internal domain has no registration (GH #203) - a date left from when it
        // was public is not a renewal anybody needs reminding of.
        if (!empty($r['expiry_date']) && empty($r['parent_domain_id']) && $r['scope'] !== 'internal' && ($autoToo || $r['renewal_mode'] !== 'auto')) {
            $left = (int)$today->diff(new DateTimeImmutable($r['expiry_date']))->format('%r%a');
            if ($left >= 0 && in_array($left, $days, true)) {
                $due[] = $r + ['kind' => 'expiry', 'days' => $left, 'date' => $r['expiry_date'], 'fingerprint' => $r['expiry_date'] . ':' . $left];
            } elseif ($left < 0 && $chase && -$left <= 45 && (-$left - 1) % 7 === 0) {
                // Day 1 after lapsing, then every 7 days, for 45 days.
                $due[] = $r + ['kind' => 'expired', 'days' => $left, 'date' => $r['expiry_date'], 'fingerprint' => $r['expiry_date'] . ':x' . intdiv(-$left, 7)];
            }
        }
        if ($sslOn && !empty($r['ssl_expiry_date'])) {
            $left = (int)$today->diff(new DateTimeImmutable($r['ssl_expiry_date']))->format('%r%a');
            if ($left <= $sslDays && $left >= -7) {
                // Once per certificate expiry date — a renewed certificate has a new date.
                $due[] = $r + ['kind' => 'ssl', 'days' => $left, 'date' => $r['ssl_expiry_date'], 'fingerprint' => $r['ssl_expiry_date']];
            }
        }
    }

    // Certificates recorded by hand: the same alert, the same window, one per
    // certificate. Fingerprinted by certificate AND date ("m12:2027-03-01"), so
    // each fires once, a renewed one (new date) fires again, and it can never
    // collide with the checked certificate's own date fingerprint.
    if ($sslOn && domainManualCertsReady($conn)) {
        $certs = $conn->query(
            "SELECT d.id, d.domain_name, d.display_name, d.expiry_date, d.ssl_issuer, d.renewal_mode,
                    d.owner_analyst_id, d.tenant_id, d.purpose, d.status_id, d.registrar_name, d.security_grade,
                    a.full_name AS owner_name, a.email AS owner_email, t.name AS company_name,
                    m.id AS cert_id, m.purpose AS cert_purpose, m.expiry_date AS cert_expiry
               FROM domain_manual_certificates m
               JOIN domains d ON d.id = m.domain_id
          LEFT JOIN domain_statuses s ON s.id = d.status_id
          LEFT JOIN analysts a ON a.id = d.owner_analyst_id AND a.is_active = 1
          LEFT JOIN tenants t ON t.id = d.tenant_id
              WHERE (s.id IS NULL OR s.alerts_enabled = 1)
                AND d.renewal_mode <> 'do_not_renew'"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($certs as $r) {
            $left = (int)$today->diff(new DateTimeImmutable($r['cert_expiry']))->format('%r%a');
            if ($left <= $sslDays && $left >= -7) {
                $due[] = $r + ['ssl_expiry_date' => $r['cert_expiry'], 'certificate' => $r['cert_purpose'],
                               'kind' => 'ssl', 'days' => $left, 'date' => $r['cert_expiry'], 'fingerprint' => 'm' . $r['cert_id'] . ':' . $r['cert_expiry']];
            }
        }
    }
    return $due;
}

/**
 * The run: events, e-mail digests and renewal actions.
 *
 * @param bool $dryRun count what WOULD be sent and send nothing — the settings
 *                     screen shows this before anybody switches e-mail on.
 * @return array{due:int, events:int, emails:int, recipients:int, failed:int, actions:int, would_email:array}
 */
function domainAlertsRun(PDO $conn, bool $dryRun = false): array
{
    $s   = domainSettings($conn, true);
    $due = domainAlertsDue($conn, $s);
    $out = ['due' => count($due), 'events' => 0, 'emails' => 0, 'recipients' => 0, 'failed' => 0, 'actions' => 0, 'would_email' => []];

    // ---- 1. events (bell + workflows) — always, fire-once -------------------
    if (!$dryRun) {
        foreach ($due as $a) {
            $evKind = 'evt_' . $a['kind'];
            if (!domainAlertClaim($conn, (int)$a['id'], $evKind, $a['fingerprint'])) continue;
            $event = $a['kind'] === 'ssl' ? 'domain.ssl_expiring' : 'domain.expiring';
            WorkflowEngine::dispatch($event, [
                'domain'         => domainAlertPayload($a),
                'days_remaining' => (int)$a['days'],
                'expired'        => $a['days'] < 0 ? 1 : 0,
                // A certificate recorded by hand says which one; '' for the checked one.
                'certificate'    => (string)($a['certificate'] ?? ''),
            ]);
            $out['events']++;
        }
    }

    // ---- 2. e-mail digests --------------------------------------------------
    $emailOn = $s['domain_alerts_enabled'] === '1';
    if ($emailOn || $dryRun) {
        $byRecipient = [];
        foreach ($due as $a) {
            $mailKind = 'mail_' . $a['kind'];
            if (domainAlertAlreadySent($conn, (int)$a['id'], $mailKind, $a['fingerprint'])) continue;
            foreach (domainAlertRecipients($a, $s) as $email => $name) {
                $byRecipient[$email]['name'] = $name;
                $byRecipient[$email]['items'][] = $a;
            }
        }
        if ($dryRun) {
            foreach ($byRecipient as $email => $r) $out['would_email'][] = ['email' => $email, 'count' => count($r['items'])];
            $out['recipients'] = count($byRecipient);
        } else {
            // Claim every item once, then send each digest; an item whose every
            // digest failed is released so the next run tries again.
            $claimed = []; $delivered = [];
            foreach ($byRecipient as $email => $r) {
                $items = [];
                foreach ($r['items'] as $a) {
                    $key = $a['id'] . '|' . $a['kind'] . '|' . $a['fingerprint'];
                    if (!isset($claimed[$key])) {
                        $claimed[$key] = domainAlertClaim($conn, (int)$a['id'], 'mail_' . $a['kind'], $a['fingerprint']);
                    }
                    if ($claimed[$key]) $items[] = $a;
                }
                if (!$items) continue;
                $msg = domainAlertDigest($conn, $r['name'] ?? '', $items);
                $ok = false;
                try { $ok = ssSendSystemEmail($conn, $email, $msg['subject'], $msg['body'], 'domain_alert'); } catch (Throwable) { $ok = false; }
                if ($ok) {
                    $out['emails']++;
                    foreach ($items as $a) $delivered[$a['id'] . '|' . $a['kind'] . '|' . $a['fingerprint']] = true;
                } else {
                    $out['failed']++;
                }
            }
            foreach ($claimed as $key => $mine) {
                if ($mine && !isset($delivered[$key])) {
                    [$id, $kind, $fp] = explode('|', $key, 3);
                    domainAlertRelease($conn, (int)$id, 'mail_' . $kind, $fp);
                }
            }
            $out['recipients'] = count($byRecipient);
        }
    }

    // ---- 3. renewal actions (task / ticket) ----------------------------------
    if (!$dryRun && in_array($s['domain_renewal_action'], ['task', 'ticket'], true)) {
        $out['actions'] = domainRenewalActionsRun($conn, $s);
    }

    if (!$dryRun) domainSettingWrite($conn, DOMAIN_SETTING_LAST_RUN, gmdate('Y-m-d H:i:s'));
    return $out;
}

/**
 * email => display name, for one due item.
 *
 * @param array $a  one due item from domainAlertsDue() (or a domain row with owner_email/owner_name added)
 * @param array $s  every Domains setting, as domainSettings() returns them - reads the recipients mode and list
 */
function domainAlertRecipients(array $a, array $s): array
{
    $to = [];
    $mode = $s['domain_alert_recipients'];
    if (in_array($mode, ['owner', 'both'], true) && !empty($a['owner_email']) && filter_var($a['owner_email'], FILTER_VALIDATE_EMAIL)) {
        $to[strtolower($a['owner_email'])] = (string)$a['owner_name'];
    }
    if (in_array($mode, ['list', 'both'], true) || ($mode === 'owner' && !$to)) {
        // An ownerless domain still reaches somebody: the list is the fallback.
        foreach (preg_split('/[\s,;]+/', (string)$s['domain_alert_emails']) as $e) {
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $to[strtolower($e)] = $to[strtolower($e)] ?? '';
        }
    }
    return $to;
}

/**
 * Shapes a due domain row into the 'domain' payload the domain.expiring and domain.ssl_expiring workflow events carry.
 *
 * @param array $a  one due item from domainAlertsDue() - a domain row with its fields
 */
function domainAlertPayload(array $a): array
{
    return [
        'id' => (int)$a['id'], 'name' => $a['domain_name'], 'expiry_date' => $a['expiry_date'],
        'ssl_expiry_date' => $a['ssl_expiry_date'] ?? null, 'status_id' => $a['status_id'] !== null ? (int)$a['status_id'] : null,
        'purpose' => $a['purpose'], 'owner_analyst_id' => $a['owner_analyst_id'] !== null ? (int)$a['owner_analyst_id'] : null,
        'registrar' => $a['registrar_name'], 'company_id' => $a['tenant_id'] !== null ? (int)$a['tenant_id'] : null,
        'security_grade' => $a['security_grade'] ?? null,
    ];
}

/**
 * Absolute link to a domain, for mail sent by a cron with no request host.
 *
 * @param int $id  the domain's id
 */
function domainPublicLink(PDO $conn, int $id): string
{
    $base = function_exists('publicBaseUrl') ? rtrim(publicBaseUrl($conn), '/') : rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
    return $base . '/domains/view.php?id=' . $id;
}

/**
 * Subject and HTML for one recipient's digest.
 *
 * @param string $name   the recipient's display name; '' gives a plain "Hello,"
 * @param array  $items  that recipient's due items from domainAlertsDue() (expiry, expired and ssl mixed)
 */
function domainAlertDigest(PDO $conn, string $name, array $items): array
{
    usort($items, fn($x, $y) => $x['days'] <=> $y['days']);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $nExp = count(array_filter($items, fn($i) => $i['kind'] !== 'ssl'));
    $nSsl = count($items) - $nExp;
    $bits = [];
    if ($nExp) $bits[] = $nExp . ' domain' . ($nExp === 1 ? '' : 's') . ' renewing';
    if ($nSsl) $bits[] = $nSsl . ' certificate' . ($nSsl === 1 ? '' : 's') . ' expiring';
    $subject = 'Domains: ' . implode(', ', $bits);
    if (count($items) === 1) {
        $i = $items[0];
        $dn = $i['display_name'] ?: $i['domain_name'];
        $subject = $i['kind'] === 'ssl'
            ? (!empty($i['certificate']) ? "Certificate \"{$i['certificate']}\" ($dn) expires " : "Certificate for $dn expires ") . domainAlertWhen($i['days'])
            : ($i['days'] < 0 ? "$dn has EXPIRED" : "$dn expires " . domainAlertWhen($i['days']));
    }

    $rows = '';
    foreach ($items as $i) {
        $dn = $i['display_name'] ?: $i['domain_name'];
        $what = $i['kind'] === 'ssl' ? 'Certificate' . (!empty($i['certificate']) ? ': ' . $e($i['certificate']) : '') : 'Registration';
        $colour = $i['days'] < 0 ? '#dc2626' : ($i['days'] <= 7 ? '#d97706' : '#374151');
        $rows .= '<tr>'
              . '<td style="padding:8px 10px;border-bottom:1px solid #eee;"><a href="' . $e(domainPublicLink($conn, (int)$i['id'])) . '" style="color:#4d7c0f;font-weight:bold;text-decoration:none;">' . $e($dn) . '</a>'
              . (!empty($i['company_name']) ? '<br><span style="color:#888;font-size:12px;">' . $e($i['company_name']) . '</span>' : '') . '</td>'
              . '<td style="padding:8px 10px;border-bottom:1px solid #eee;">' . $what . '</td>'
              . '<td style="padding:8px 10px;border-bottom:1px solid #eee;color:' . $colour . ';font-weight:bold;">' . $e(domainAlertWhen($i['days'])) . '<br><span style="color:#888;font-weight:normal;font-size:12px;">' . $e(fmt_domain_date($i['date'])) . '</span></td>'
              . '<td style="padding:8px 10px;border-bottom:1px solid #eee;color:#555;">' . $e($i['kind'] === 'ssl' ? ($i['ssl_issuer'] ?? '') : ($i['renewal_mode'] === 'auto' ? 'Auto-renew is on - check the payment card' : ($i['registrar_name'] ?? ''))) . '</td>'
              . '</tr>';
    }
    $hello = $name !== '' ? 'Hello ' . $e($name) . ',' : 'Hello,';
    $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;line-height:1.5;">'
          . '<p>' . $hello . '</p><p>These need attention in the domain register:</p>'
          . '<table style="border-collapse:collapse;width:100%;max-width:720px;font-size:14px;">'
          . '<tr style="background:#f7f7f7;text-align:left;"><th style="padding:8px 10px;">Domain</th><th style="padding:8px 10px;">What</th><th style="padding:8px 10px;">When</th><th style="padding:8px 10px;">Note</th></tr>'
          . $rows . '</table>'
          . '<p style="color:#888;font-size:12px;margin-top:18px;">Sent by FreeITSM Domains. Change who receives these, or when, in Domains &rarr; Settings &rarr; Alerts.</p>'
          . '</div>';
    return ['subject' => $subject, 'body' => $body];
}

/**
 * Says when in words - "expired 3 days ago", "today", "tomorrow", "in 30 days".
 *
 * @param int $days  days until the date; negative for days since it passed
 */
function domainAlertWhen(int $days): string
{
    if ($days < 0)  return 'expired ' . (-$days) . ' day' . ($days === -1 ? '' : 's') . ' ago';
    if ($days === 0) return 'today';
    if ($days === 1) return 'tomorrow';
    return 'in ' . $days . ' days';
}

/**
 * 'YYYY-MM-DD' → '20 November 2026'. Plain and not zone-shifted.
 *
 * @param ?string $ymd  a 'YYYY-MM-DD' date (anything after the first 10 characters is ignored); null or '' gives ''
 */
function fmt_domain_date(?string $ymd): string
{
    if (!$ymd) return '';
    $dt = DateTime::createFromFormat('Y-m-d', substr($ymd, 0, 10));
    return $dt ? $dt->format('j F Y') : (string)$ymd;
}

/**
 * Change detection's e-mail. Called by DomainsService::runChecks() when
 * something serious moved — separate from the daily digest because a possible
 * hijack cannot wait for tomorrow's run.
 *
 * @param array $row      the domain row as loaded by DomainsService::runChecks()
 * @param array $serious  the changes domainChangeIsSerious() kept, each array{field:string,old:string,new:string}
 */
function domainAlertOnChange(PDO $conn, array $row, array $serious): void
{
    $s = domainSettings($conn);
    if ($s['domain_alerts_enabled'] !== '1' || $s['domain_change_alerts'] !== '1' || !$serious) return;
    $fp = substr(hash('sha256', json_encode($serious)), 0, 40);
    if (!domainAlertClaim($conn, (int)$row['id'], 'mail_changed', $fp)) return;

    $owner = null;
    if (!empty($row['owner_analyst_id'])) {
        $st = $conn->prepare("SELECT full_name AS owner_name, email AS owner_email FROM analysts WHERE id = ? AND is_active = 1");
        $st->execute([(int)$row['owner_analyst_id']]);
        $owner = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $recips = domainAlertRecipients(array_merge($row, $owner ?: ['owner_email' => null, 'owner_name' => null]), $s);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $dn = $row['display_name'] ?: $row['domain_name'];
    $list = '';
    foreach ($serious as $c) {
        $list .= '<li><strong>' . $e(domainWatchLabel($c['field'])) . '</strong>: ' . $e($c['old']) . ' &rarr; <strong>' . $e($c['new']) . '</strong></li>';
    }
    $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;line-height:1.5;">'
          . '<p>Something important changed on <strong>' . $e($dn) . '</strong>:</p><ul>' . $list . '</ul>'
          . '<p>If nobody in your team made this change, treat it as urgent: it is what a hijacked domain looks like. '
          . 'Sign in to the registrar, check the account\'s recent activity, and put the settings back.</p>'
          . '<p><a href="' . $e(domainPublicLink($conn, (int)$row['id'])) . '" style="color:#4d7c0f;font-weight:bold;">Open ' . $e($dn) . ' in FreeITSM</a></p></div>';
    $anyOk = false;
    foreach ($recips as $email => $name) {
        try { $anyOk = ssSendSystemEmail($conn, $email, "Change detected on $dn", $body, 'domain_alert') || $anyOk; } catch (Throwable) {}
    }
    if (!$anyOk) domainAlertRelease($conn, (int)$row['id'], 'mail_changed', $fp);
}

/**
 * The plain-English name of a watched field for the change e-mail (registrar, locks, DNSSEC, name servers, MX, SPF, DMARC).
 *
 * @param string $field  a key from domainWatchedFields(), e.g. 'dns_ns'; an unknown key comes back unchanged
 */
function domainWatchLabel(string $field): string
{
    return [
        'registrar' => 'Registrar', 'transfer_lock' => 'Transfer lock', 'registry_lock' => 'Registry lock',
        'dnssec' => 'DNSSEC', 'dns_ns' => 'Name servers (live DNS)', 'mx' => 'Mail servers (MX)',
        'spf' => 'SPF record', 'dmarc' => 'DMARC record',
    ][$field] ?? $field;
}

/**
 * Raise a task or a ticket for each domain entering the renewal window. Once per
 * expiry date (a renewed domain re-arms). Ownerless domains get an unassigned
 * task — renewing is still somebody's job even when nobody has been named.
 *
 * @param array $s  every Domains setting, as domainSettings() returns them - reads the renewal action and window
 */
function domainRenewalActionsRun(PDO $conn, array $s): int
{
    $window = (int)$s['domain_renewal_action_days'];
    $st = $conn->prepare(
        "SELECT d.*, a.full_name AS owner_name, a.email AS owner_email
           FROM domains d
      LEFT JOIN domain_statuses s ON s.id = d.status_id
      LEFT JOIN analysts a ON a.id = d.owner_analyst_id AND a.is_active = 1
          WHERE d.expiry_date IS NOT NULL
            AND d.expiry_date >= ? AND d.expiry_date <= DATE_ADD(?, INTERVAL ? DAY)
            AND (s.id IS NULL OR s.alerts_enabled = 1)
            AND d.renewal_mode <> 'do_not_renew'"
    );
    $today = gmdate('Y-m-d');
    $st->execute([$today, $today, $window]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        if (!domainAlertClaim($conn, (int)$d['id'], 'act_renew', (string)$d['expiry_date'])) continue;
        $dn = $d['display_name'] ?: $d['domain_name'];
        $desc = "Renew the domain $dn before it expires on " . fmt_domain_date($d['expiry_date']) . '.'
              . ($d['registrar_name'] ? "\nRegistrar: {$d['registrar_name']}" : '')
              . ($d['renewal_mode'] === 'auto' ? "\nIt is set to auto-renew: confirm the payment card on the registrar account is still valid." : '')
              . "\n\n" . domainPublicLink($conn, (int)$d['id']);
        $actor = new ActorContext(0, null, 'system', 'en', 'FreeITSM Domains');
        try {
            if ($s['domain_renewal_action'] === 'task') {
                require_once __DIR__ . '/../services/tasks.php';
                $in = ['title' => "Renew $dn", 'description' => $desc, 'due_date' => $d['expiry_date']];
                if ($d['owner_analyst_id']) $in['assigned_analyst_id'] = (int)$d['owner_analyst_id'];
                if ($d['tenant_id'] !== null) $in['tenant_id'] = (int)$d['tenant_id'];
                TasksService::saveTask($conn, $actor, $in);
            } else {
                require_once __DIR__ . '/../services/tickets.php';
                $req = $d['owner_email'] ?: (preg_split('/[\s,;]+/', (string)$s['domain_alert_emails'])[0] ?? '');
                if (!$req || !filter_var($req, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('no requester address (set an owner or an alert address)');
                $tenant = $d['tenant_id'] !== null ? (int)$d['tenant_id'] : getDefaultTenantId($conn);
                TicketsService::createTicket($conn, $actor, $tenant, [
                    'subject' => "Renew domain $dn (expires " . fmt_domain_date($d['expiry_date']) . ')',
                    'description' => $desc,
                    'requester_email' => $req,
                    'requester_name' => $d['owner_name'] ?: '',
                ], $d['owner_analyst_id'] ? (int)$d['owner_analyst_id'] : null, 'Raised by Domains at the renewal window');
            }
            DomainsService::audit($conn, (int)$d['id'], null, 'renewal_' . $s['domain_renewal_action'], null, 'Raised for expiry ' . $d['expiry_date'], 'check');
            $n++;
        } catch (Throwable $e) {
            domainAlertRelease($conn, (int)$d['id'], 'act_renew', (string)$d['expiry_date']);
            error_log('domains renewal action: ' . $e->getMessage());
        }
    }
    return $n;
}
