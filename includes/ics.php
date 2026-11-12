<?php
/**
 * iCalendar (RFC 5545) output, shared by every feed FreeITSM publishes.
 *
 * There are two: the shared team calendar (api/calendar/feed.php) and an
 * analyst's own scheduled work (api/tickets/schedule_feed.php). They must agree
 * on escaping, line folding and — the fiddly one — how an all-day event is
 * expressed, so it is stated once here rather than copied.
 *
 * ⚠️ THE ALL-DAY RULE IS THE PART PEOPLE GET WRONG. An all-day VEVENT uses DATE
 * values, and DTEND is EXCLUSIVE: the day AFTER the last day. A single all-day
 * event on the 2nd is DTSTART 20260902 / DTEND 20260903. Get it wrong and every
 * all-day entry is either invisible or a day long in the wrong direction. (Graph
 * has the same rule with different spelling — see MicrosoftCalendarProvider.)
 */

/**
 * Escape a text value per RFC 5545 (backslash, newline, comma, semicolon).
 *
 * @param mixed $s  the text value, cast to string; null gives ''
 */
function icsEscape($s): string
{
    $s = (string)$s;
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace(["\r\n", "\n", "\r"], '\\n', $s);
    $s = str_replace(',', '\\,', $s);
    $s = str_replace(';', '\\;', $s);
    return $s;
}

/**
 * Fold a content line to <=75 octets; continuation lines start with a space.
 *
 * Byte-based, not character-based, deliberately: the limit in RFC 5545 is
 * octets, and a subject with an accent or an emoji in it is longer than it
 * looks. Calendar clients are unforgiving about over-long lines.
 *
 * TRAP: cut with mb_strcut(), never substr(). A byte count can land in the middle of
 * a multi-byte character; substr() then splits it across the fold and the client
 * shows two broken characters (until 5.1.0 an accented subject could do this).
 * mb_strcut() backs off to the character boundary, so a chunk is at most the limit.
 *
 * @param string $line  one complete content line, e.g. 'SUMMARY:...', already escaped
 */
function icsFold(string $line): string
{
    if (strlen($line) <= 75) return $line;
    $out = '';
    $first = true;
    while (strlen($line) > 0) {
        $take  = $first ? 75 : 74;
        $chunk = mb_strcut($line, 0, $take, 'UTF-8');
        if ($chunk === '') $chunk = substr($line, 0, $take);   // not UTF-8 at all - cut by bytes
        $out  .= ($first ? '' : "\r\n ") . $chunk;
        $line  = substr($line, strlen($chunk));
        $first = false;
    }
    return $out;
}

/**
 * The VCALENDAR header lines. $name is what the client shows as the calendar's name.
 *
 * @param string $name         the calendar's display name (X-WR-CALNAME)
 * @param string $tz           the feed's IANA time zone name, e.g. 'Europe/London' (X-WR-TIMEZONE)
 * @param int $refreshHours    how often clients are asked to refresh, in hours - only a hint
 */
function icsHeader(string $name, string $tz, int $refreshHours = 6): array
{
    return [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//FreeITSM//Calendar//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        icsFold('X-WR-CALNAME:' . icsEscape($name)),
        'X-WR-TIMEZONE:' . $tz,
        // A hint, and only a hint. Outlook and Google both refresh internet
        // calendars on their own schedule and routinely ignore this — which is
        // why a subscribed feed is never the right answer for something that has
        // to be current within the hour.
        'REFRESH-INTERVAL;VALUE=DURATION:PT' . $refreshHours . 'H',
        'X-PUBLISHED-TTL:PT' . $refreshHours . 'H',
    ];
}

/**
 * One VEVENT.
 *
 * @param array $ev [
 *   'uid'         => 'ticket-635@host',      // required, stable across refreshes
 *   'summary'     => 'TICKET-000105 — …',    // required
 *   'description' => '…',                    // optional
 *   'location'    => '…',                    // optional
 *   'categories'  => '…',                    // optional
 *   'url'         => 'https://…',            // optional
 *   'start'       => '2026-09-01 14:00:00',  // naive wall clock, in $tz
 *   'end'         => '2026-09-01 16:00:00',
 *   'all_day'     => false,
 *   'stamp'       => 'Y-m-d H:i:s' | null,   // last-modified; defaults to now
 * ]
 * @param string $tz  the IANA time zone name that start and end are written in, e.g. 'Europe/London'
 * @return string[] lines, or [] when the row cannot be parsed (skipped, never fatal:
 *                  one bad row must not take the whole feed down)
 */
function icsEvent(array $ev, string $tz): array
{
    try {
        $start = new DateTime($ev['start'], new DateTimeZone($tz));
    } catch (Exception) {
        return [];
    }
    try {
        $end = new DateTime($ev['end'] ?: $ev['start'], new DateTimeZone($tz));
    } catch (Exception) {
        $end = clone $start;
    }

    $stamp = gmdate('Ymd\THis\Z', strtotime((string)($ev['stamp'] ?? '')) ?: time());

    $lines   = [];
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $ev['uid'];
    $lines[] = 'DTSTAMP:' . $stamp;

    if (!empty($ev['all_day'])) {
        // DATE values, and DTEND is EXCLUSIVE — the day after the last day.
        $endExclusive = (clone $end)->modify('+1 day');
        $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
        $lines[] = 'DTEND;VALUE=DATE:' . $endExclusive->format('Ymd');
    } else {
        $startUtc = (clone $start)->setTimezone(new DateTimeZone('UTC'));
        $endUtc   = (clone $end)->setTimezone(new DateTimeZone('UTC'));
        // A zero-length or inverted event renders as nothing at all in most
        // clients, which reads as the entry being missing rather than wrong.
        if ($endUtc <= $startUtc) {
            $endUtc = (clone $startUtc)->modify('+30 minutes');
        }
        $lines[] = 'DTSTART:' . $startUtc->format('Ymd\THis\Z');
        $lines[] = 'DTEND:'   . $endUtc->format('Ymd\THis\Z');
    }

    $lines[] = icsFold('SUMMARY:' . icsEscape($ev['summary'] ?? ''));
    foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION',
              'categories'  => 'CATEGORIES',  'url'      => 'URL'] as $key => $prop) {
        if (!empty($ev[$key])) {
            // URL is a value type of its own and must NOT be text-escaped —
            // escaping the commas and semicolons in a query string breaks the link.
            $lines[] = icsFold($prop . ':' . ($key === 'url' ? $ev[$key] : icsEscape($ev[$key])));
        }
    }
    $lines[] = 'END:VEVENT';
    return $lines;
}

/**
 * Emit the finished calendar with the right headers.
 *
 * @param array $lines      every line so far - icsHeader() then icsEvent() output; END:VCALENDAR is added here
 * @param string $filename  the file name for Content-Disposition, e.g. 'calendar.ics'
 */
function icsRespond(array $lines, string $filename): void
{
    $lines[] = 'END:VCALENDAR';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=300');
    echo implode("\r\n", $lines) . "\r\n";
}
