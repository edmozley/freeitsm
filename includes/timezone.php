<?php
/**
 * Per-analyst timezone support.
 *
 * The app stores every datetime in UTC (UTC_TIMESTAMP() on write). This helper
 * resolves the *display* timezone for the current request — the logged-in
 * analyst's `timezone` user preference, falling back to the server default
 * (date_default_timezone_get(), i.e. the value set in config.php) for analysts
 * who haven't chosen one and for any no-user context (cron, workers, email).
 *
 * Mirrors the I18n bootstrap pattern: call Tz::init() once per page after the
 * session is available, then use Tz::current() / fmt_local() (PHP-rendered
 * dates) and Tz::scriptTag() to hand the zone to the browser (JS-rendered
 * dates convert via window.USER_TIMEZONE).
 *
 * Deliberately side-effect free: it does NOT call date_default_timezone_set(),
 * so server-side date math on a page is unaffected. Conversion is always
 * explicit (fmt_local / setTimezone), which keeps UTC-at-rest the single
 * source of truth.
 */
class Tz {
    /** Effective display zone for this request. Null until init(); then always a valid IANA id. */
    private static $zone = null;

    /**
     * Resolve the display zone for this request, in priority order:
     *   1. Logged-in analyst's `timezone` user preference (if a valid IANA id)
     *   2. Server default (date_default_timezone_get() — set in config.php)
     *
     * Safe to call without a database — falls back to the server default if
     * config/DB aren't loaded or the analyst has no preference.
     */
    public static function init() {
        // Self-load the DB helper if the including page hasn't (matches I18n).
        if (!function_exists('connectToDatabase') && is_file(__DIR__ . '/functions.php')) {
            require_once __DIR__ . '/functions.php';
        }

        self::$zone = date_default_timezone_get();
        $conn = null;

        if (function_exists('connectToDatabase')) {
            try {
                $conn = connectToDatabase();
                if (!empty($_SESSION['analyst_id'])) {
                    $stmt = $conn->prepare(
                        "SELECT preference_value FROM user_preferences
                         WHERE analyst_id = ? AND preference_key = 'timezone' LIMIT 1"
                    );
                    $stmt->execute([(int)$_SESSION['analyst_id']]);
                    $value = $stmt->fetchColumn();
                    if ($value && self::isValid($value)) {
                        self::$zone = $value;
                    }
                }
            } catch (Throwable $e) {
                // Server default stands
            }
        }

        // Zone and format are published together by scriptTag(), so they are
        // resolved together — a page that set up one and not the other would
        // hand the browser a correct timezone beside a default format, which is
        // exactly the bug the first live run of the settings page found. The
        // connection is passed on so this costs no second connect.
        DateFmt::init($conn);
    }

    /** The effective display zone. Lazily initialises to the server default if init() wasn't called. */
    public static function current() {
        if (self::$zone === null) {
            self::$zone = date_default_timezone_get();
        }
        return self::$zone;
    }

    /** True if $tz is a known IANA identifier. */
    public static function isValid($tz) {
        return is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true);
    }

    /**
     * A <script> tag that publishes the effective zone to the browser so JS
     * date helpers can convert UTC → the analyst's zone. Emit once in <head>,
     * before any script that formats dates.
     *
     * Also publishes the analyst's date/time FORMAT choice and the month and
     * weekday names for the interface language (see DateFmt below), so the JS
     * formatters in assets/js/tz.js have everything they need. Riding this one
     * tag — already emitted by every analyst page — means the format feature
     * needed no new plumbing on 144 pages.
     *
     * The names are published here rather than read from window.translations
     * because that object is namespace-scoped per page, and a page that does
     * not export 'common' would otherwise render months as dotted key paths.
     */
    public static function scriptTag() {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        return '<script>window.USER_TIMEZONE = ' . json_encode(self::current(), $flags) . ';'
             . 'window.DATE_FORMAT = ' . json_encode(DateFmt::jsPayload(), $flags) . ';</script>';
    }
}

/**
 * Format a UTC datetime (as stored in the DB) in the current analyst's display
 * zone. Returns '' for null/empty and passes the raw value through unchanged if
 * it can't be parsed, so a bad value is visible rather than fatal.
 *
 * @param ?string $utc    A UTC datetime string (e.g. '2026-07-05 14:30:00') or null.
 * @param string  $format A date() format string.
 */
function fmt_local(?string $utc, string $format = 'Y-m-d H:i'): string {
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(Tz::current()));
        return $dt->format($format);
    } catch (Throwable $e) {
        return (string)$utc;
    }
}

/**
 * Per-user date & time FORMAT (GH #105).
 *
 * Deliberately SEPARATE from Tz above, because they answer different questions:
 *   Tz      -> WHICH INSTANT      ('14:30' vs '15:30')
 *   DateFmt -> WHAT IT LOOKS LIKE ('14:30' vs '2:30 PM')
 * Changing one must never move the other. DateFmt receives a datetime that Tz
 * has already placed in the right zone and only decides how to write it down.
 *
 * Format is NOT inferred from the interface language. German speakers at a UK
 * MSP may want German labels and 25/08/2026, and inside one country people
 * disagree about 25/08/2026 vs 25.08.2026 vs 25 Aug 2026. So we ask.
 *
 * Resolution order mirrors Tz::init():
 *   1. The analyst's `date_format` / `time_format` user preference
 *   2. The install-wide system_settings row. No UI surfaces this yet - it is the
 *      fallback the self-service portal needs, since portal users are not
 *      analysts and have no preferences to read.
 *   3. The built-in default, chosen to reproduce what the app rendered BEFORE
 *      this feature existed, so no existing install sees a date change.
 *
 * The stored value is a KEY ('dmy_dot'), never a pattern string - an unvalidated
 * free-text pattern is a footgun, and PHP date() and JS Intl share no syntax.
 * Both renderers consume the SAME token template below, so they cannot drift.
 */
class DateFmt {
    /** Reproduces pre-#105 output: en-GB {day:'2-digit',month:'short',year:'numeric'} + {hour,minute}. */
    const DEFAULT_DATE = 'd_mon_y';
    const DEFAULT_TIME = '24h';

    /**
     * Tokens: DD 2-digit day | D numeric day | MM 2-digit month | MON short
     * month name | MONTH full month name | YYYY 4-digit year | YY 2-digit year.
     * Anything that is not a token is a literal.
     */
    const DATE_TEMPLATES = [
        'd_mon_y'   => 'DD MON YYYY',
        'd_month_y' => 'D MONTH YYYY',
        'mon_d_y'   => 'MON D, YYYY',
        'dmy_slash' => 'DD/MM/YYYY',
        'dmy_dot'   => 'DD.MM.YYYY',
        'dmy_dash'  => 'DD-MM-YYYY',
        'dmy_short' => 'DD/MM/YY',
        'mdy_slash' => 'MM/DD/YYYY',
        'iso'       => 'YYYY-MM-DD',
    ];

    /** Tokens: HH 2-digit 24h hour | h 12h hour | mi 2-digit minute | A AM/PM. */
    const TIME_TEMPLATES = [
        '24h' => 'HH:mi',
        '12h' => 'h:mi A',
    ];

    /** Day-and-month-only (compact chips), derived from the chosen date format. */
    const DAY_MONTH_TEMPLATES = [
        'd_mon_y'   => 'DD MON',
        'd_month_y' => 'D MONTH',
        'mon_d_y'   => 'MON D',
        'dmy_slash' => 'DD/MM',
        'dmy_dot'   => 'DD.MM',
        'dmy_dash'  => 'DD-MM',
        'dmy_short' => 'DD/MM',
        'mdy_slash' => 'MM/DD',
        'iso'       => 'MM-DD',
    ];

    private static $date = null;
    private static $time = null;

    /**
     * Resolve both formats for this request. Safe without a DB - falls back to
     * the defaults. Normally reached via Tz::init(), which passes its own
     * connection; call it directly only in a context that has no Tz.
     */
    public static function init(?PDO $existing = null) {
        if (!function_exists('connectToDatabase') && is_file(__DIR__ . '/functions.php')) {
            require_once __DIR__ . '/functions.php';
        }

        self::$date = self::DEFAULT_DATE;
        self::$time = self::DEFAULT_TIME;
        if ($existing === null && !function_exists('connectToDatabase')) return;

        try {
            $conn = $existing ?: connectToDatabase();

            // Level 2 first, so a level-1 hit overwrites it.
            $stmt = $conn->prepare(
                "SELECT setting_key, setting_value FROM system_settings
                 WHERE setting_key IN ('date_format','time_format')"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                self::apply($row['setting_key'], $row['setting_value']);
            }

            if (!empty($_SESSION['analyst_id'])) {
                $stmt = $conn->prepare(
                    "SELECT preference_key, preference_value FROM user_preferences
                     WHERE analyst_id = ? AND preference_key IN ('date_format','time_format')"
                );
                $stmt->execute([(int)$_SESSION['analyst_id']]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    // '' means "follow the install default" - the same idiom
                    // default_landing_page uses on the preferences page.
                    self::apply($row['preference_key'], $row['preference_value']);
                }
            }
        } catch (Throwable $e) {
            // Defaults stand
        }
    }

    /** Accept a candidate value for one of the two keys, ignoring blanks and unknown values. */
    private static function apply($key, $value) {
        if ($value === null || $value === '') return;
        if ($key === 'date_format' && isset(self::DATE_TEMPLATES[$value])) self::$date = $value;
        if ($key === 'time_format' && isset(self::TIME_TEMPLATES[$value])) self::$time = $value;
    }

    /**
     * The effective date-format key.
     *
     * Lazily RESOLVES rather than lazily defaulting. The difference matters: the
     * first live run of the settings page published the built-in default to the
     * browser while the database said dmy_dot, because nothing had called init()
     * and the accessor quietly answered with the default. A wrong format that
     * looks like a deliberate choice is invisible; resolving here means a
     * context nobody thought about - a cron, an email template, a PDF - still
     * gets the truth.
     */
    public static function dateKey() {
        if (self::$date === null) self::init();
        return self::$date;
    }

    /** The effective time-format key. Lazily resolves - see dateKey(). */
    public static function timeKey() {
        if (self::$time === null) self::init();
        return self::$time;
    }

    /** Ordered month names for the interface language. $short picks the abbreviated set. */
    /**
     * Ordered month names. $short picks the abbreviated set.
     *
     * $inDate asks for the form a month takes WHEN A DAY NUMBER IS BESIDE IT.
     * In English there is no difference, but Slavic languages inflect: Russian
     * standing alone is "март", inside a date it is "5 марта"; Polish
     * "marzec" becomes "5 marca"; Ukrainian "березень" becomes "5 березня".
     * Rendering the standalone form in a date reads plainly wrong to a native
     * speaker, and it is a full month name so the abbreviation cannot hide it.
     *
     * A locale supplies 'months_in_date' only if it needs one; everything else
     * falls through to 'months' and is unaffected.
     */
    public static function months($short = false, $inDate = false) {
        $keys = [
            'january','february','march','april','may','june',
            'july','august','september','october','november','december',
        ];
        if ($inDate && !$short) {
            $declined = self::names('months_in_date', false, $keys, true);
            if ($declined !== null) return $declined;
        }
        return self::names('months', $short, $keys);
    }

    /** Ordered weekday names, MONDAY FIRST (index 0 = Monday). */
    public static function weekdays($short = false) {
        return self::names('weekdays', $short, [
            'monday','tuesday','wednesday','thursday','friday','saturday','sunday',
        ]);
    }

    /**
     * @param bool $optional When true, return NULL if the locale does not define
     *                       this group at all, rather than falling back. Used by
     *                       months_in_date, which most languages do not need.
     */
    private static function names($group, $short, array $keys, $optional = false) {
        if (!function_exists('t') && is_file(__DIR__ . '/i18n.php')) {
            require_once __DIR__ . '/i18n.php';
        }
        $ns  = 'common.calendar.' . $group . ($short ? '_short' : '');
        if ($optional) {
            // t() falls back to English, and English has no months_in_date, so a
            // missing key comes back as the key path - that is the "not defined"
            // signal. Probing one key is enough: the block is added whole.
            //
            // ⚠️ lang/en MUST NOT gain a months_in_date block. English does not
            // inflect, so it would only ever duplicate 'months' - but its mere
            // presence would make this probe succeed for EVERY locale, and the
            // English fallback would then feed English month names into every
            // other language's dates. The absence is load-bearing. The i18n
            // audit reports months_in_date as an EXTRA key in ru/pl/uk for this
            // reason; that is correct, not drift.
            $probe = function_exists('t') ? t("$ns." . $keys[0]) : "$ns." . $keys[0];
            if ($probe === "$ns." . $keys[0] || $probe === '') return null;
        }
        $out = [];
        foreach ($keys as $k) {
            // A locale that has no _short block falls back to ENGLISH, per the
            // standard I18n fallback chain — so a German analyst sees 'Wed'
            // beside 'Mittwoch' until lang/de gains the 19 short strings. That
            // is deliberate: behaving differently here than every other string
            // in the app would be the worse surprise. Filling _short for all
            // locales is a required step, not an optional polish.
            //
            // The guard below only catches a key missing from English too, i.e.
            // somebody deleting a string — it prints the English word rather
            // than a dotted key path in the middle of a date.
            $val = function_exists('t') ? t("$ns.$k") : ucfirst($k);
            $out[] = ($val === "$ns.$k" || $val === '') ? ucfirst($k) : $val;
        }
        return $out;
    }

    /** Render a DateTime through a token template. The single PHP-side renderer. */
    public static function render(DateTime $dt, $template) {
        // A day number beside the month selects the in-date month form for the
        // languages that inflect (see months()). "March 2026" on a calendar
        // header stays nominative; "5 March 2026" does not.
        $withDay     = strpos($template, 'D') !== false;
        $months      = self::months(false, $withDay);
        $monthsShort = self::months(true);
        $hour24      = (int)$dt->format('G');
        $hour12      = $hour24 % 12;
        if ($hour12 === 0) $hour12 = 12;

        // Longest token first, so MONTH is not eaten by MON, nor YYYY by YY.
        $map = [
            'MONTH' => $months[(int)$dt->format('n') - 1],
            'YYYY'  => $dt->format('Y'),
            'MON'   => $monthsShort[(int)$dt->format('n') - 1],
            'DD'    => $dt->format('d'),
            'MM'    => $dt->format('m'),
            'YY'    => $dt->format('y'),
            'mi'    => $dt->format('i'),
            'HH'    => sprintf('%02d', $hour24),
            'D'     => (string)(int)$dt->format('j'),
            'h'     => (string)$hour12,
            'A'     => $hour24 < 12 ? 'AM' : 'PM',
        ];
        return strtr($template, $map);
    }

    /**
     * Everything the browser needs to render dates itself, published by
     * Tz::scriptTag(). Kept here so the PHP and JS renderers read one source.
     */
    public static function jsPayload() {
        return [
            'dateTemplate'     => self::DATE_TEMPLATES[self::dateKey()],
            'timeTemplate'     => self::TIME_TEMPLATES[self::timeKey()],
            'dayMonthTemplate' => self::DAY_MONTH_TEMPLATES[self::dateKey()],
            'months'           => self::months(false),
            'monthsInDate'     => self::months(false, true),
            'monthsShort'      => self::months(true),
            'weekdays'         => self::weekdays(false),
            'weekdaysShort'    => self::weekdays(true),
        ];
    }
}

// ---------------------------------------------------------------------------
// PHP DISPLAY formatters. Each takes a UTC datetime string as stored in the DB,
// converts it to the analyst's display zone, and renders it in the chosen
// format.
//
// For MACHINE output - SQL values, <input type="date">, sort keys, ICS - use
// fmt_local($utc, 'Y-m-d') with an explicit pattern, which never consults the
// format setting. Routing a sort key through this display family would reorder
// tables the moment somebody picks a different format.
// ---------------------------------------------------------------------------

/** Internal: UTC string -> DateTime in the display zone, or null if unparseable. */
function _fmt_dt(?string $utc): ?DateTime {
    if ($utc === null || $utc === '') return null;
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(Tz::current()));
        return $dt;
    } catch (Throwable $e) {
        return null;
    }
}

/** '25 Aug 2026' */
function fmt_date(?string $utc): string {
    if ($utc === null || $utc === '') return '';
    $dt = _fmt_dt($utc);
    if (!$dt) return (string)$utc;
    return DateFmt::render($dt, DateFmt::DATE_TEMPLATES[DateFmt::dateKey()]);
}

/** '14:30' */
function fmt_time(?string $utc): string {
    if ($utc === null || $utc === '') return '';
    $dt = _fmt_dt($utc);
    if (!$dt) return (string)$utc;
    return DateFmt::render($dt, DateFmt::TIME_TEMPLATES[DateFmt::timeKey()]);
}

/** '25 Aug 2026 14:30' */
function fmt_datetime(?string $utc): string {
    if ($utc === null || $utc === '') return '';
    $dt = _fmt_dt($utc);
    if (!$dt) return (string)$utc;
    return DateFmt::render($dt, DateFmt::DATE_TEMPLATES[DateFmt::dateKey()])
         . ' ' . DateFmt::render($dt, DateFmt::TIME_TEMPLATES[DateFmt::timeKey()]);
}

/** '25 Aug' - compact chips and list rows where the year is implied. */
function fmt_day_month(?string $utc): string {
    if ($utc === null || $utc === '') return '';
    $dt = _fmt_dt($utc);
    if (!$dt) return (string)$utc;
    return DateFmt::render($dt, DateFmt::DAY_MONTH_TEMPLATES[DateFmt::dateKey()]);
}

/** 'August 2026' - calendar headers. Always the full month name. */
function fmt_month_year(?string $utc): string {
    if ($utc === null || $utc === '') return '';
    $dt = _fmt_dt($utc);
    if (!$dt) return (string)$utc;
    return DateFmt::render($dt, 'MONTH YYYY');
}

/**
 * 🔴 "NOW" FOR A NAIVE WALL-CLOCK COLUMN — not for anything else.
 *
 * Most datetimes in FreeITSM are UTC instants, and the way to ask the database
 * what time it is now is `UTC_TIMESTAMP()`. A few columns are deliberately NOT
 * instants: change windows, scheduled work and PIR actuals are stored as NAIVE
 * WALL CLOCKS, without a zone, so that "2pm" reads 2pm for everybody wherever
 * they are. See the three kinds of stored date in Timezones-and-Time-Handling.
 *
 * ⚠️ You cannot compare one of those against `UTC_TIMESTAMP()`. "Is this change
 * window open right now" asks whether a wall clock has passed, and the answer
 * has to be a wall clock too, or every window is judged an hour or two early.
 *
 * Those queries used bare `NOW()`, which worked only by accident: MySQL evaluated
 * it in the DATABASE SERVER'S OS ZONE, which nothing declares and nothing
 * documents. Pinning the connection to UTC (GH #126, see config.php) removed that
 * accident, so the wall clock is now stated explicitly and comes from the
 * application's own configured zone — `date_default_timezone_set()` in config.php
 * — which is the zone every other "local" reading in the product already uses.
 * Where the database server and PHP agreed, which is the ordinary case, nothing
 * about these queries changes.
 *
 * 📌 OPEN QUESTION, deliberately not answered here: arguably this should be the
 * VIEWER's display zone rather than the installation's, since the naive value is
 * shown to them unconverted — an analyst in Vienna reads "14:00" and the dashboard
 * decides whether that is in progress using London's clock. Making it per-viewer
 * would mean two analysts seeing different counts on a shared dashboard, which is
 * its own problem. Left as it was rather than changed on the way past.
 */
function naive_now(): string {
    return date('Y-m-d H:i:s');
}

/**
 * 🔴 "TODAY" FOR A BARE DATE COLUMN — the SQL literal, not a bound parameter.
 *
 * The third kind of stored date has no time and no zone at all: a contract's end,
 * an asset's warranty expiry, a task's due date, a licence renewal, a knowledge
 * article's review date. "Is this contract expiring in the next 30 days" is a
 * question about a CALENDAR, and the answer must come from a calendar in the same
 * frame the date was typed in.
 *
 * ⚠️ `CURDATE()` is not that any more. With the connection pinned to UTC (GH #126,
 * config.php) it returns the UTC date, which rolls over an hour before the local
 * one in British summer time and two hours before it in central Europe. Left
 * alone, "due today" on Watchtower would change its answer for an hour every
 * night — the kind of off-by-one that gets reported as a ghost.
 *
 * 🔑 WHY A LITERAL AND NOT A PLACEHOLDER. Every caller below builds SQL by
 * assembling fragments and collecting bound parameters separately, several of
 * them into a `$where[]` array. Threading an extra parameter through each one
 * means getting its POSITION right in a list built somewhere else, which is a
 * much better way to introduce a bug than the one it would be preventing.
 *
 * This is not user input and cannot become user input: the value is produced by
 * PHP's own `date()` and the format is asserted before it is returned, so what
 * comes back is always exactly `'YYYY-MM-DD'`, quotes included. If the assertion
 * could ever fail it falls back to `CURDATE()`, which is wrong by an hour rather
 * than wrong by being a syntax error on somebody's dashboard.
 *
 * @return string A quoted SQL date literal, e.g. `'2026-09-01'`.
 */
function naive_today_sql(): string {
    $d = date('Y-m-d');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? "'" . $d . "'" : 'CURDATE()';
}

/** 'Tuesday' / 'Tue' */
function fmt_weekday(?string $utc, bool $short = false): string {
    if ($utc === null || $utc === '') return '';
    $dt = _fmt_dt($utc);
    if (!$dt) return (string)$utc;
    // format('N'): 1 = Monday, matching DateFmt::weekdays() index 0.
    return DateFmt::weekdays($short)[(int)$dt->format('N') - 1];
}
