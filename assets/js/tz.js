/**
 * Shared per-analyst timezone helpers (Phase 2 of the per-user timezone rollout).
 *
 * `window.USER_TIMEZONE` is published server-side by Tz::scriptTag() (see
 * includes/timezone.php). Every datetime is stored UTC; these helpers render it
 * in the analyst's chosen zone. When USER_TIMEZONE is unset the `timeZone`
 * option is simply omitted, so dates fall back to the browser's own zone.
 *
 * Load this file BEFORE any module JS that formats dates. It exposes globals
 * that mirror the proven tickets/inbox.js implementation, so a module's date
 * formatters become timezone-aware by:
 *   1. parsing the DB string as UTC:      const d = parseUTCDate(str);
 *   2. spreading tzOpts() into Intl opts:  d.toLocaleString(locale, tzOpts({ ... }))
 *   3. bucketing Today/Yesterday via:      ymdInZone(d) === ymdInZone(new Date())
 */
(function () {
    // Parse a DB datetime string as UTC (append Z if it carries no zone marker),
    // returning an absolute-instant Date. Returns null for empty input.
    window.parseUTCDate = function (dateStr) {
        if (!dateStr) return null;
        if (!/[Z+\-]\d{0,4}$/.test(dateStr)) {
            dateStr = String(dateStr).replace(' ', 'T') + 'Z';
        }
        return new Date(dateStr);
    };

    // Merge the analyst's display zone into an Intl.DateTimeFormat / toLocale*
    // options object. Pass the options you'd normally use; the timeZone is added
    // only when the analyst has chosen one.
    window.tzOpts = function (extra) {
        var o = Object.assign({}, extra || {});
        if (window.USER_TIMEZONE) o.timeZone = window.USER_TIMEZONE;
        return o;
    };

    // 'YYYY-MM-DD' for a Date, evaluated in the analyst's display zone. Use for
    // Today/Yesterday bucketing against the same zone the time is shown in.
    window.ymdInZone = function (date) {
        if (!date) return '';
        return date.toLocaleDateString('en-CA', window.tzOpts());
    };

    // Parse a NAIVE wall-clock datetime (a user-entered scheduling value stored
    // WITHOUT a zone — change plan windows, ticket work-start, calendar events,
    // PIR actuals) into a Date built from its literal components, with NO zone
    // interpretation. Format the result WITHOUT tzOpts, so "2pm" reads 2pm for
    // every analyst. These values are NOT UTC instants — never run them through
    // parseUTCDate/tzOpts. See the "Timezones and Time Handling" design note.
    window.parseNaiveDate = function (str) {
        if (!str) return null;
        var m = String(str).replace('T', ' ')
            .match(/(\d{4})-(\d{2})-(\d{2})[ ](\d{1,2}):(\d{2})/);
        if (!m) return new Date(str);
        return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    };
})();
