<?php
/**
 * Domains — settings: the keys, their defaults, and the one reader.
 *
 * Every key lives in `system_settings` and is written only by
 * api/domains/settings.php, which validates it and checks the capability of the
 * tab it belongs to (see domains/settings/manifest.php for why the generic
 * settings writer is deliberately NOT allowed to touch these).
 *
 * 🔑 THE DEFAULTS ARE CHOSEN SO THAT INSTALLING THE MODULE CHANGES NOTHING UNTIL
 * SOMEBODY DECIDES. Nothing is emailed until alerts are switched on, nothing
 * external is queried beyond the registry lookups a person asked for by adding a
 * domain, and the "noisy" scans (Certificate Transparency, look-alikes) are off.
 * The settings screen shows each default, so what is not configured is visible.
 *
 * ⚠️ The JS on domains/settings/index.php receives these defaults FROM this file
 * (via the settings endpoint) rather than repeating them, so the screen and the
 * server cannot disagree about what "not set yet" means.
 */

// GH #203: DomainScope, the gate and DOMAIN_INTERNAL_INTERVALS - every Domains
// entry point loads this file, so the gate is always there to ask.
require_once __DIR__ . '/scope.php';

if (!defined('DOMAIN_SETTINGS_LOADED')) {
    define('DOMAIN_SETTINGS_LOADED', true);

    /**
     * key => [default, validator, tab]. The tab decides which capability may
     * write the key; the validator is one of the names domainSettingValidate()
     * understands.
     */
    function domainSettingDefinitions(): array
    {
        return [
            // ---- Alerts tab -------------------------------------------------
            // Where expiry dates surface: off | dashboard | calendar | both — the
            // same four values Software and Assets use.
            'domain_expiry_surface'        => ['dashboard', 'surface',  'alerts'],
            // Where CERTIFICATE expiries surface - its own choice, the warranty /
            // lease rule: two kinds of date, two settings, two calendar markers,
            // so switching one off never removes the other's entries. Dashboard
            // by default: the Watchtower card always counted them.
            'domain_cert_surface'          => ['dashboard', 'surface',  'alerts'],
            // OFF until switched on. Upgrading must never email anybody.
            'domain_alerts_enabled'        => ['0',         'bool',     'alerts'],
            // Days before expiry to warn. 0 = on the day.
            'domain_alert_days'            => ['60,30,14,7,1', 'days',  'alerts'],
            // Keep reminding after a domain has expired (every N days, while the
            // registry grace period still lets it be rescued).
            'domain_alert_chase_expired'   => ['1',         'bool',     'alerts'],
            // Who hears: the domain's owner, a fixed list, or both.
            'domain_alert_recipients'      => ['owner',     'recipients', 'alerts'],
            'domain_alert_emails'          => ['',          'emails',   'alerts'],
            // Warn about a domain set to auto-renew too? Auto-renew fails quietly
            // when the card on the account has expired, so the default is yes.
            'domain_alert_auto_renew'      => ['1',         'bool',     'alerts'],
            // Certificates that are about to expire, and how far ahead.
            'domain_ssl_alerts'            => ['1',         'bool',     'alerts'],
            'domain_ssl_warn_days'         => ['21',        'int:1:120', 'alerts'],
            // Change detection: name servers, registrar, lock status changing.
            'domain_change_alerts'         => ['1',         'bool',     'alerts'],
            // What else to do when a domain reaches the renewal window: nothing,
            // raise a task, or raise a ticket. Once per expiry date.
            'domain_renewal_action'        => ['none',      'action',   'alerts'],
            'domain_renewal_action_days'   => ['30',        'int:1:365', 'alerts'],

            // ---- Monitoring tab ---------------------------------------------
            // Registry lookups (RDAP, WHOIS fallback): off | on_add | scheduled.
            'domain_lookup_mode'           => ['scheduled', 'lookup_mode', 'monitoring'],
            // When a lookup disagrees with what somebody typed: the registry wins
            // (always), or it only fills blanks (blanks).
            'domain_lookup_overwrite'      => ['always',    'overwrite', 'monitoring'],
            // How often a scheduled lookup refreshes a domain, in days.
            'domain_lookup_refresh_days'   => ['7',         'int:1:90', 'monitoring'],
            // DNS / email security / certificate checks.
            'domain_checks_enabled'        => ['1',         'bool',     'monitoring'],
            // Resolver: auto | system | google | cloudflare. AUTO is DNS-over-HTTPS
            // on Windows and the server's own resolver elsewhere: Windows' resolver
            // gives up after 10 seconds on a large TXT answer (measured: a domain
            // with 22 TXT records never answered, where DoH took 0.3s), and it
            // cannot ask for CAA or DS at all.
            'domain_dns_resolver'          => ['auto',      'resolver', 'monitoring'],
            // GH #203: how often INTERNAL domains are checked (DNS only, through this
            // server), in minutes. The scheduled job must run at least this often for
            // a short interval to happen - the settings screen says so beside it.
            'domain_internal_interval'     => ['60',        'interval', 'monitoring'],
            // Certificate Transparency watch via crt.sh — OFF: external, slow, and
            // rate-limited, so it is something to choose.
            'domain_ct_watch'              => ['0',         'bool',     'monitoring'],
            // Look-alike (typo-squat) scanner — OFF: it makes many DNS queries.
            'domain_lookalike_scan'        => ['0',         'bool',     'monitoring'],
            // Run a small batch of scheduled work when somebody opens the module,
            // for installs with no cron. At most once an hour.
            'domain_opportunistic'         => ['1',         'bool',     'monitoring'],

            // ---- Auth codes tab ---------------------------------------------
            // Record every time somebody reveals an auth code in the domain's
            // history. On by default: who looked at a transfer secret is exactly
            // the question somebody asks after a domain has been stolen.
            'domain_auth_code_audit'       => ['1',         'bool',     'auth-codes'],

            // ---- Service Status tab (3.0.0) ---------------------------------
            // What a domain's trouble does to the services linked to it:
            // off | suggest (a person raises it, one click) | auto (raised by the
            // scheduled run). Suggest by default: the status page is read by
            // customers, so nothing reaches it unless somebody chose that.
            'domain_status_mode'           => ['suggest',   'status_mode', 'service-status'],
            // Which trouble counts.
            'domain_status_on_expired'     => ['1',         'bool',     'service-status'],
            'domain_status_on_cert'        => ['1',         'bool',     'service-status'],
            // A certificate counts this many days BEFORE it expires (0 = once it
            // has expired).
            'domain_status_cert_days'      => ['0',         'int:0:60', 'service-status'],
            // The impact to record on each service: an impact level id, or blank
            // for the most severe level that counts as downtime (NOT Service
            // Status's default level, which is "Operational" on a stock install).
            'domain_status_impact'         => ['',          'impact',   'service-status'],
            // Publish the opening update to the status page, or keep it internal
            // until a person writes to customers. Internal by default - the
            // Service Status rule (#99) for anything that did not ask.
            'domain_status_public'         => ['0',         'bool',     'service-status'],
            // Close the incident by itself when the domain recovers.
            'domain_status_auto_resolve'   => ['1',         'bool',     'service-status'],
        ];
    }

    /** Internal bookkeeping keys — never edited on the screen. */
    define('DOMAIN_SETTING_LAST_RUN', 'domain_cron_last_run');
    define('DOMAIN_SETTING_CRON_TOKEN', 'domain_cron_token');

    /**
     * Every setting, with its default applied where nothing is stored.
     *
     * Cached for the rest of the request; pass $fresh = true after a save (the
     * settings endpoint does) so the answer reflects what was just written.
     *
     * @param bool $fresh  true to re-read from the database instead of using this request's cached copy
     *
     * @return array<string,string>
     */
    function domainSettings(PDO $conn, bool $fresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$fresh) return $cache;

        $out = [];
        foreach (domainSettingDefinitions() as $k => $d) $out[$k] = $d[0];
        $out[DOMAIN_SETTING_LAST_RUN] = '';

        try {
            $keys = array_keys($out);
            $in   = implode(',', array_fill(0, count($keys), '?'));
            $st   = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
            $st->execute($keys);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['setting_value'] !== null && $r['setting_value'] !== '') {
                    $out[$r['setting_key']] = (string)$r['setting_value'];
                } elseif ($r['setting_key'] === 'domain_alert_emails') {
                    $out[$r['setting_key']] = '';   // an emptied list IS a value
                }
            }
        } catch (Throwable) {
            // Settings unreachable: the defaults, which are the inert choices.
        }
        return $cache = $out;
    }

    /**
     * Returns one Domains setting as a string, from the cached settings (its default when not saved).
     *
     * @param string $key  a key from domainSettingDefinitions(), or DOMAIN_SETTING_LAST_RUN; unknown gives ''
     */
    function domainSetting(PDO $conn, string $key): string
    {
        return (string)(domainSettings($conn)[$key] ?? '');
    }

    /**
     * Write one setting (no validation — callers go through domainSettingValidate()).
     *
     * @param string $key    the system_settings key to write
     * @param string $value  the value to store, already validated
     */
    function domainSettingWrite(PDO $conn, string $key, string $value): void
    {
        $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
             VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()"
        )->execute([$key, $value]);
    }

    /**
     * Validate and normalise one incoming value. Returns the value to store, or
     * throws InvalidArgumentException with a message fit to show the operator.
     *
     * @param string $key  a key from domainSettingDefinitions(); unknown throws
     * @param mixed  $raw  the submitted value (untrusted) - a string, number or bool
     */
    function domainSettingValidate(string $key, $raw): string
    {
        $defs = domainSettingDefinitions();
        if (!isset($defs[$key])) throw new InvalidArgumentException("Unknown setting: $key");
        $rule = $defs[$key][1];
        $v = is_bool($raw) ? ($raw ? '1' : '0') : trim((string)$raw);

        switch (true) {
            case $rule === 'bool':
                return in_array($v, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
            case $rule === 'surface':
                if (!in_array($v, ['off', 'dashboard', 'calendar', 'both'], true)) throw new InvalidArgumentException('Choose where renewals are shown.');
                return $v;
            case $rule === 'status_mode':
                if (!in_array($v, ['off', 'suggest', 'auto'], true)) throw new InvalidArgumentException('Choose what a domain\'s trouble does on Service Status.');
                return $v;
            case $rule === 'impact':
                if ($v !== '' && !ctype_digit($v)) throw new InvalidArgumentException('Choose an impact level.');
                return $v;
            case $rule === 'recipients':
                if (!in_array($v, ['owner', 'list', 'both'], true)) throw new InvalidArgumentException('Choose who receives alerts.');
                return $v;
            case $rule === 'action':
                if (!in_array($v, ['none', 'task', 'ticket'], true)) throw new InvalidArgumentException('Choose what to raise at the renewal window.');
                return $v;
            case $rule === 'lookup_mode':
                if (!in_array($v, ['off', 'on_add', 'scheduled'], true)) throw new InvalidArgumentException('Choose when registry lookups run.');
                return $v;
            case $rule === 'overwrite':
                if (!in_array($v, ['always', 'blanks'], true)) throw new InvalidArgumentException('Choose how a lookup treats typed values.');
                return $v;
            case $rule === 'interval':
                if (!in_array((int)$v, DOMAIN_INTERNAL_INTERVALS, true) || (string)(int)$v !== trim($v)) throw new InvalidArgumentException('Choose how often internal domains are checked.');
                return (string)(int)$v;
            case $rule === 'resolver':
                if (!in_array($v, ['auto', 'system', 'system_only', 'google', 'cloudflare'], true)) throw new InvalidArgumentException('Choose a DNS resolver.');
                return $v;
            case $rule === 'days':
                $days = domainParseDays($v);
                if (!$days) throw new InvalidArgumentException('Enter at least one number of days, for example 60, 30, 7.');
                return implode(',', $days);
            case $rule === 'emails':
                $out = [];
                foreach (preg_split('/[\s,;]+/', $v) as $e) {
                    if ($e === '') continue;
                    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException("Not an email address: $e");
                    $out[strtolower($e)] = true;
                }
                return implode(', ', array_keys($out));
            case strncmp($rule, 'int:', 4) === 0:
                [, $min, $max] = explode(':', $rule);
                if (!ctype_digit($v)) throw new InvalidArgumentException('Enter a whole number.');
                $n = (int)$v;
                if ($n < (int)$min || $n > (int)$max) throw new InvalidArgumentException("Enter a number from $min to $max.");
                return (string)$n;
        }
        throw new InvalidArgumentException("No validator for $key");
    }

    /**
     * "60, 30, 7" → [60, 30, 7] — sorted high to low, de-duplicated, nonsense
     * dropped. 0 is allowed and means "on the day".
     *
     * @param string $raw  numbers of days separated by commas or spaces, e.g. "60, 30, 7"; over 365 is dropped
     */
    function domainParseDays(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\s]+/', trim($raw)) as $bit) {
            if ($bit === '' || !ctype_digit($bit)) continue;
            $n = (int)$bit;
            if ($n > 365) continue;
            $out[$n] = true;
        }
        $days = array_keys($out);
        rsort($days);
        return $days;
    }
}
