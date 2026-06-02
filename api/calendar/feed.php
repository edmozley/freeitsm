<?php
/**
 * API Endpoint: Calendar subscription feed (iCalendar / .ics)
 *
 * Read-only iCalendar feed for subscribing in Apple Calendar / Google Calendar /
 * Outlook. Authenticated by a per-analyst capability token (no session — a phone's
 * calendar app can't carry a login cookie), passed as ?token=. The token maps to
 * an analyst via user_preferences (preference_key = 'calendar_feed_token'); the
 * feed content is the shared team calendar (the same events every analyst sees).
 *
 * Rotate the token from the calendar sidebar ("Reset link") to revoke an old URL.
 */
require_once '../../config.php';
require_once '../../includes/functions.php';

function feed_deny($code, $msg) {
    header($_SERVER['SERVER_PROTOCOL'] . ' ' . $code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$token = $_GET['token'] ?? '';
// Shape-check before touching the DB (hex capability token)
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    feed_deny('403 Forbidden', 'Invalid or missing calendar token.');
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT analyst_id FROM user_preferences
         WHERE preference_key = 'calendar_feed_token' AND preference_value = ? LIMIT 1"
    );
    $stmt->execute([$token]);
    $analystId = $stmt->fetchColumn();
    if (!$analystId) {
        feed_deny('403 Forbidden', 'Invalid or missing calendar token.');
    }

    // Shared team calendar. Bound the window (recent past + all future) so the
    // feed stays small no matter how much history accumulates.
    $stmt = $conn->prepare(
        "SELECT e.id, e.title, e.description, e.location,
                e.start_datetime, e.end_datetime, e.all_day, e.updated_at, e.created_at,
                c.name AS category_name
         FROM calendar_events e
         LEFT JOIN calendar_categories c ON e.category_id = c.id
         WHERE COALESCE(e.end_datetime, e.start_datetime) >= (NOW() - INTERVAL 1 YEAR)
         ORDER BY e.start_datetime"
    );
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    feed_deny('500 Internal Server Error', 'Calendar feed error.');
}

$tz = date_default_timezone_get() ?: 'UTC';

/** Escape a text value per RFC 5545 (backslash, newline, comma, semicolon). */
function ics_escape($s) {
    $s = (string)$s;
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace(["\r\n", "\n", "\r"], '\\n', $s);
    $s = str_replace(',', '\\,', $s);
    $s = str_replace(';', '\\;', $s);
    return $s;
}

/** Fold a content line to <=75 octets; continuation lines start with a space. */
function ics_fold($line) {
    if (strlen($line) <= 75) return $line;
    $out = '';
    $first = true;
    while (strlen($line) > 0) {
        $take = $first ? 75 : 74;
        $out .= ($first ? '' : "\r\n ") . substr($line, 0, $take);
        $line = substr($line, $take);
        $first = false;
    }
    return $out;
}

$host   = $_SERVER['HTTP_HOST'] ?? 'freeitsm';
$domain = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host) ?: 'freeitsm';

$lines = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'VERSION:2.0';
$lines[] = 'PRODID:-//FreeITSM//Calendar//EN';
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'METHOD:PUBLISH';
$lines[] = 'X-WR-CALNAME:FreeITSM';
$lines[] = 'X-WR-TIMEZONE:' . $tz;
$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT6H';
$lines[] = 'X-PUBLISHED-TTL:PT6H';

foreach ($events as $ev) {
    try {
        $start = new DateTime($ev['start_datetime'], new DateTimeZone($tz));
    } catch (Exception $e) {
        continue; // skip unparseable rows
    }
    $endRaw = !empty($ev['end_datetime']) ? $ev['end_datetime'] : $ev['start_datetime'];
    try {
        $end = new DateTime($endRaw, new DateTimeZone($tz));
    } catch (Exception $e) {
        $end = clone $start;
    }

    $stampSrc = $ev['updated_at'] ?: ($ev['created_at'] ?: 'now');
    $stamp    = gmdate('Ymd\THis\Z', strtotime($stampSrc) ?: time());
    $uid      = 'event-' . (int)$ev['id'] . '@' . $domain;

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $uid;
    $lines[] = 'DTSTAMP:' . $stamp;

    if ((int)$ev['all_day'] === 1) {
        // All-day uses DATE values; DTEND is exclusive (day after the last day).
        $endExclusive = (clone $end)->modify('+1 day');
        $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
        $lines[] = 'DTEND;VALUE=DATE:' . $endExclusive->format('Ymd');
    } else {
        $startUtc = (clone $start)->setTimezone(new DateTimeZone('UTC'));
        $endUtc   = (clone $end)->setTimezone(new DateTimeZone('UTC'));
        if ($endUtc <= $startUtc) {
            $endUtc = (clone $startUtc)->modify('+30 minutes');
        }
        $lines[] = 'DTSTART:' . $startUtc->format('Ymd\THis\Z');
        $lines[] = 'DTEND:'   . $endUtc->format('Ymd\THis\Z');
    }

    $lines[] = ics_fold('SUMMARY:' . ics_escape($ev['title']));
    if (!empty($ev['description'])) {
        $lines[] = ics_fold('DESCRIPTION:' . ics_escape($ev['description']));
    }
    if (!empty($ev['location'])) {
        $lines[] = ics_fold('LOCATION:' . ics_escape($ev['location']));
    }
    if (!empty($ev['category_name'])) {
        $lines[] = ics_fold('CATEGORIES:' . ics_escape($ev['category_name']));
    }
    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="freeitsm.ics"');
header('Cache-Control: private, max-age=300');
echo implode("\r\n", $lines) . "\r\n";
