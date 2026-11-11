<?php
require_once __DIR__ . '/service_context.php';   // ServiceError, thrown below (GH #288)
/**
 * Money - the one place FreeITSM knows about currencies (GH #189).
 *
 * Before this, five modules each had their own idea: Contracts a hardcoded list
 * of 19, Software Licences a list of 3 with its own symbol map, and Domains,
 * Leases and Projects a free-text box. So adding the Indonesian rupiah meant
 * editing two lists and still got the decimals wrong. Now every dropdown, every
 * validator and every formatter reads the registry in includes/currencies.php.
 *
 * WHAT A ROW STORES: the ISO code ('GBP'). See currencies.php for why not an id.
 *
 * HOW AN AMOUNT IS WRITTEN is a "format": three choices joined by colons,
 * e.g. 'dot_comma:symbol_after:std' -> 1.234,56 €
 *   separators  SEPARATORS keys  (1,234.56 / 1.234,56 / 1 234,56 / ...)
 *   symbol      SYMBOLS keys     (£1,234 / 1,234 £ / GBP 1,234 / ...)
 *   decimals    'std' (the currency's own - JPY 0, GBP 2, KWD 3) | '0' | '2'
 * Stored as a KEY, never a pattern - the same rule DateFmt follows, and for the
 * same reason: PHP and JS then render it with the same code and cannot drift.
 *
 * WHICH FORMAT APPLIES, first match wins - mirrors DateFmt's analyst-then-install:
 *   1. the analyst's own format for this currency   user_preferences currency_formats (JSON map)
 *   2. the analyst's own format for every currency  user_preferences currency_format
 *   3. the install's format for this currency       system_settings  currency_formats (JSON map)
 *   4. the install's format for every currency      system_settings  currency_format
 *   5. FORMAT_DEFAULT
 * An analyst who sets (2) has said "this is how I read money", so it beats the
 * install's per-currency rule (3) - a personal choice wins, as it does for dates.
 *
 * THE DEFAULT CURRENCY and the FAVOURITES (up to 5, shown first in every
 * dropdown) are install settings: currency_default, currency_favourites (CSV).
 * The default is always a favourite - the System page enforces it and
 * favourites() repairs it on read, so a hand-edited row cannot break a dropdown.
 *
 * Everything that reads a setting validates it on the way in and ignores what it
 * does not recognise, because the generic settings writer and the preference
 * writer store strings as given.
 *
 * The browser half is assets/js/money.js, fed by Money::scriptTag().
 *
 * @wiki Currencies
 */
class Money {
    const FORMAT_DEFAULT     = 'comma_dot:symbol_before:std';
    const DEFAULT_CURRENCY   = 'GBP';
    /** What a new install shows first: the three Software Licences offered before #189. */
    const DEFAULT_FAVOURITES = ['GBP', 'EUR', 'USD'];
    const MAX_FAVOURITES     = 5;

    /** key => [thousands separator, decimal mark, grouping]. 'indian' groups 12,34,567. */
    const SEPARATORS = [
        'comma_dot'   => [',', '.', 'three'],
        'dot_comma'   => ['.', ',', 'three'],
        'space_comma' => ["\u{00A0}", ',', 'three'],
        'space_dot'   => ["\u{00A0}", '.', 'three'],
        'apos_dot'    => ['’', '.', 'three'],
        'none_dot'    => ['', '.', 'none'],
        'indian'      => [',', '.', 'indian'],
    ];

    const SYMBOLS  = ['symbol_before', 'symbol_before_space', 'symbol_after', 'code_before', 'code_after'];
    const DECIMALS = ['std', '0', '2'];

    private static $registry = null;
    private static $ready = false;
    private static $default = self::DEFAULT_CURRENCY;
    private static $favourites = self::DEFAULT_FAVOURITES;
    private static $sysAll = null;
    private static $sysPer = [];
    private static $userAll = null;
    private static $userPer = [];

    // ---- The registry -------------------------------------------------------

    /** Every currency, retired ones included: code => [name, symbol, decimals, retired]. */
    public static function registry(): array {
        if (self::$registry === null) {
            self::$registry = [];
            foreach (require __DIR__ . '/currencies.php' as $code => $r) {
                self::$registry[$code] = [$r[0], $r[1], (int)$r[2], !empty($r[3])];
            }
        }
        return self::$registry;
    }

    /**
     * Whether a code is one FreeITSM recognises - retired ones included, so an old
     * record keeps saving. Exact match: callers upper-case first.
     *
     * @param mixed $code  the candidate code, e.g. 'IDR'
     */
    public static function isCode($code): bool {
        return is_string($code) && isset(self::registry()[$code]);
    }

    /**
     * Whether a code may be OFFERED (recognised and still issued).
     *
     * @param mixed $code  the candidate code
     */
    public static function isCurrent($code): bool {
        return self::isCode($code) && !self::registry()[$code][3];
    }

    /**
     * Upper-cases and checks a code from a form, an import or the API, and throws
     * the message every module shows when it is not one. Null for blank.
     *
     * @param mixed $raw  the value as sent (untrusted)
     * @throws InvalidArgumentException when it is not a recognised code
     */
    public static function normalise($raw): ?string {
        $c = strtoupper(trim((string)($raw ?? '')));
        if ($c === '') return null;
        if (!self::isCode($c)) {
            throw new InvalidArgumentException("'$c' is not a currency FreeITSM recognises. Use a three-letter ISO 4217 code, such as GBP, EUR or USD.");
        }
        return $c;
    }

    /**
     * The English name, for server-rendered text (PDFs, the assistant). The
     * browser shows the name in the viewer's language instead.
     *
     * @param string $code  a currency code
     */
    public static function name(string $code): string {
        return self::registry()[$code][0] ?? $code;
    }

    // ---- Settings -----------------------------------------------------------

    /**
     * Resolve the install's and the signed-in analyst's settings for this
     * request. Safe without a database - the defaults stand. Called lazily by
     * everything below, so no page needs to remember to call it.
     *
     * @param ?PDO $existing  a connection to reuse; null opens one
     * @param bool $fresh     true to re-read (after a save)
     */
    public static function init(?PDO $existing = null, bool $fresh = false): void {
        if (self::$ready && !$fresh) return;
        self::$ready = true;
        self::$default = self::DEFAULT_CURRENCY;
        self::$favourites = self::DEFAULT_FAVOURITES;
        self::$sysAll = self::$userAll = null;
        self::$sysPer = self::$userPer = [];

        if ($existing === null && !function_exists('connectToDatabase') && is_file(__DIR__ . '/functions.php')) {
            require_once __DIR__ . '/functions.php';
        }
        if ($existing === null && !function_exists('connectToDatabase')) return;

        try {
            $conn = $existing ?: connectToDatabase();
            $st = $conn->prepare(
                "SELECT setting_key, setting_value FROM system_settings
                 WHERE setting_key IN ('currency_default','currency_favourites','currency_format','currency_formats')"
            );
            $st->execute();
            $sys = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $sys[$r['setting_key']] = (string)$r['setting_value'];

            if (isset($sys['currency_default']) && self::isCurrent($sys['currency_default'])) self::$default = $sys['currency_default'];
            if (isset($sys['currency_favourites']) && $sys['currency_favourites'] !== '') {
                self::$favourites = self::cleanFavourites(explode(',', $sys['currency_favourites']));
            }
            self::$favourites = self::withDefault(self::$favourites, self::$default);
            self::$sysAll = self::cleanFormat($sys['currency_format'] ?? null);
            self::$sysPer = self::cleanFormatMap($sys['currency_formats'] ?? null);

            if (!empty($_SESSION['analyst_id'])) {
                $st = $conn->prepare(
                    "SELECT preference_key, preference_value FROM user_preferences
                     WHERE analyst_id = ? AND preference_key IN ('currency_format','currency_formats')"
                );
                $st->execute([(int)$_SESSION['analyst_id']]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ($r['preference_key'] === 'currency_format')  self::$userAll = self::cleanFormat($r['preference_value']);
                    if ($r['preference_key'] === 'currency_formats') self::$userPer = self::cleanFormatMap($r['preference_value']);
                }
            }
        } catch (Throwable) {
            // Before Database Verification, or no database: the defaults stand.
        }
    }

    /** The install's default currency - what a new contract, licence, domain, lease or project starts in. */
    public static function defaultCurrency(): string {
        self::init();
        return self::$default;
    }

    /** The install's favourites, default first, at most MAX_FAVOURITES. */
    public static function favourites(): array {
        self::init();
        return self::$favourites;
    }

    /**
     * Keep the recognised, still-issued, distinct codes, in order, up to the limit.
     *
     * @param array $codes  candidate codes (untrusted)
     */
    public static function cleanFavourites(array $codes): array {
        $out = [];
        foreach ($codes as $c) {
            $c = strtoupper(trim((string)$c));
            if (self::isCurrent($c) && !in_array($c, $out, true)) $out[] = $c;
        }
        return array_slice($out, 0, self::MAX_FAVOURITES);
    }

    /**
     * The favourites with the default at the front, trimmed back to the limit.
     *
     * @param array  $favs     cleaned favourites
     * @param string $default  the default currency
     */
    public static function withDefault(array $favs, string $default): array {
        $favs = array_values(array_diff($favs, [$default]));
        array_unshift($favs, $default);
        return array_slice($favs, 0, self::MAX_FAVOURITES);
    }

    /**
     * A format key if it is valid, else null.
     *
     * @param mixed $f  e.g. 'dot_comma:symbol_after:std'
     */
    public static function cleanFormat($f): ?string {
        if (!is_string($f) || $f === '') return null;
        $p = explode(':', $f);
        if (count($p) !== 3) return null;
        if (!isset(self::SEPARATORS[$p[0]]) || !in_array($p[1], self::SYMBOLS, true) || !in_array($p[2], self::DECIMALS, true)) return null;
        return $f;
    }

    /**
     * A per-currency map from its stored JSON, keeping only valid entries.
     *
     * @param mixed $json  '{"IDR":"dot_comma:symbol_before_space:std"}', an array, or null
     */
    public static function cleanFormatMap($json): array {
        $m = is_array($json) ? $json : json_decode((string)$json, true);
        if (!is_array($m)) return [];
        $out = [];
        foreach ($m as $code => $f) {
            $code = strtoupper((string)$code);
            if (self::isCode($code) && ($f = self::cleanFormat($f)) !== null) $out[$code] = $f;
        }
        ksort($out);
        return $out;
    }

    /**
     * The format that applies to one currency for the current viewer (the
     * resolution order in the file header).
     *
     * @param string $code  a currency code
     */
    public static function formatFor(string $code): string {
        self::init();
        if (isset(self::$userPer[$code])) return self::$userPer[$code];
        if (self::$userAll !== null)      return self::$userAll;
        if (isset(self::$sysPer[$code]))  return self::$sysPer[$code];
        return self::$sysAll ?? self::FORMAT_DEFAULT;
    }

    /** The install's own settings, for the System -> Currencies page. */
    public static function installSettings(): array {
        self::init();
        return [
            'default'    => self::$default,
            'favourites' => self::$favourites,
            'format'     => self::$sysAll ?? self::FORMAT_DEFAULT,
            'formats'    => self::$sysPer,
        ];
    }

    /** The analyst's own settings, for Preferences ('' = follow the install). */
    public static function personalSettings(): array {
        self::init();
        return ['format' => self::$userAll ?? '', 'formats' => self::$userPer];
    }

    // ---- Writing an amount --------------------------------------------------

    /**
     * Write an amount for a person to read: '£1,234.56', '1.234,56 €', 'Rp 1.500.000'.
     * Not for exports, CSV or anything a machine reads back - use the raw number.
     * assets/js/money.js format() is the same algorithm; change both together.
     *
     * @param mixed   $amount  a number or numeric string; null or '' gives ''
     * @param ?string $code    the currency; null or '' means the install's default
     * @param ?string $format  a format key to use instead of the viewer's (previews)
     */
    public static function format($amount, ?string $code = null, ?string $format = null): string {
        if ($amount === null || $amount === '' || !is_numeric($amount)) return '';
        $code = $code ? strtoupper($code) : self::defaultCurrency();
        $info = self::registry()[$code] ?? [$code, $code, 2, false];
        $fmt  = explode(':', self::cleanFormat($format) ?? self::formatFor($code));

        $dp = $fmt[2] === 'std' ? $info[2] : (int)$fmt[2];
        $v  = (float)$amount;
        $neg = $v < 0;
        $s  = number_format(abs(round($v, $dp)), $dp, '.', '');
        $parts = explode('.', $s);
        [$thou, $mark, $grouping] = self::SEPARATORS[$fmt[0]];
        $num = self::group($parts[0], $thou, $grouping) . (isset($parts[1]) ? $mark . $parts[1] : '');
        if ($neg && (float)$s !== 0.0) $num = '-' . $num;

        $nb = "\u{00A0}";
        $sym = $info[1];
        switch ($fmt[1]) {
            case 'symbol_after':        return $num . $nb . $sym;
            case 'symbol_before_space': return $sym . $nb . $num;
            case 'code_before':         return $code . $nb . $num;
            case 'code_after':          return $num . $nb . $code;
            default:
                // A symbol that ends in a letter ('CHF', 'Rp', 'kr') needs a space or it runs into the digits.
                return $sym . (preg_match('/\pL$/u', $sym) ? $nb : '') . $num;
        }
    }

    /**
     * An amount with NO currency: the viewer's separators, two decimals, no
     * symbol - never a guessed one. money.js number() is the twin.
     *
     * @param mixed $amount  a number or numeric string; null or '' gives ''
     */
    public static function number($amount): string {
        if ($amount === null || $amount === '' || !is_numeric($amount)) return '';
        self::init();
        $f  = explode(':', self::$userAll ?? self::$sysAll ?? self::FORMAT_DEFAULT);
        $dp = $f[2] === '0' ? 0 : 2;
        $v  = (float)$amount;
        $s  = number_format(abs(round($v, $dp)), $dp, '.', '');
        $parts = explode('.', $s);
        [$thou, $mark, $grouping] = self::SEPARATORS[$f[0]];
        return ($v < 0 && (float)$s !== 0.0 ? '-' : '') . self::group($parts[0], $thou, $grouping) . (isset($parts[1]) ? $mark . $parts[1] : '');
    }

    /**
     * Insert the thousands separator into a string of digits.
     *
     * @param string $int       the whole-number digits, no sign
     * @param string $sep       the separator
     * @param string $grouping  'three' | 'indian' | 'none'
     */
    private static function group(string $int, string $sep, string $grouping): string {
        if ($grouping === 'none' || strlen($int) <= 3) return $int;
        // From the right: the last three digits, then threes - or, Indian, twos (12,34,567).
        // Never strrev(): the separator can be a multi-byte character.
        $size = $grouping === 'indian' ? 2 : 3;
        $out  = substr($int, -3);
        $rest = substr($int, 0, -3);
        while ($rest !== '') {
            $out  = substr($rest, -$size) . $sep . $out;
            $rest = strlen($rest) > $size ? substr($rest, 0, -$size) : '';
        }
        return $out;
    }

    // ---- The browser --------------------------------------------------------

    /** Everything assets/js/money.js needs. Compact: it rides every page that shows money. */
    public static function jsPayload(): array {
        self::init();
        $c = [];
        foreach (self::registry() as $code => $r) $c[$code] = $r[3] ? [$r[0], $r[1], $r[2], 1] : [$r[0], $r[1], $r[2]];
        $per = self::$userPer;
        if (self::$userAll === null) $per += self::$sysPer;
        $l = [];
        if (function_exists('t')) {
            foreach (['favourites', 'all', 'none', 'retired', 'unknown', 'sep', 'symbol', 'decimals', 'dec_std', 'dec_0', 'dec_2'] as $k) {
                $l[$k] = t('common.currency.' . $k);
            }
        }
        return [
            'l'    => (object)$l,
            'c'    => $c,
            'def'  => self::$default,
            'fav'  => self::$favourites,
            'all'  => self::$userAll ?? self::$sysAll ?? self::FORMAT_DEFAULT,
            'per'  => (object)$per,
            'sep'  => self::SEPARATORS,
            'max'  => self::MAX_FAVOURITES,
        ];
    }

    /** The <script> that publishes jsPayload() as window.MONEY. Put it before money.js. */
    public static function scriptTag(): string {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        return '<script>window.MONEY = ' . json_encode(self::jsPayload(), $flags) . ';</script>';
    }
}

/**
 * Money::normalise() for the service layer: the same check, thrown as the
 * ServiceError every module's API already turns into a 400. One function so a
 * contract, a licence, a domain and a lease refuse a bad code in the same words.
 * Null for blank - each caller decides what blank means (none, or the default).
 *
 * @param mixed $raw  the currency as sent by a form, an import or the REST API
 */
function moneyServiceCurrency($raw): ?string {
    try {
        return Money::normalise($raw);
    } catch (InvalidArgumentException $e) {
        throw new ServiceError('validation', 'invalid_field', $e->getMessage());
    }
}
