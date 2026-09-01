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
require_once '../../includes/ics.php';
require_once '../../includes/timezone.php';   // naive_now() — calendar events are naive (GH #126)

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
         WHERE COALESCE(e.end_datetime, e.start_datetime) >= (? - INTERVAL 1 YEAR)
         ORDER BY e.start_datetime"
    );
    // ⚠️ A wall clock. A calendar event's times are stored NAIVE — the service
    // writes them through parseNaiveDatetime(), so "2pm" means 2pm and is never
    // converted. Comparing that to a UTC instant mixes the two kinds (GH #126).
    $stmt->execute([naive_now()]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    feed_deny('500 Internal Server Error', 'Calendar feed error.');
}
$tz = date_default_timezone_get() ?: 'UTC';

$host   = $_SERVER['HTTP_HOST'] ?? 'freeitsm';
$domain = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host) ?: 'freeitsm';

// Escaping, folding and the all-day rule live in includes/ics.php, shared with
// the analyst's own scheduled-work feed (api/tickets/schedule_feed.php). They
// have to agree, and DTEND-is-exclusive is exactly the sort of detail that
// drifts when it is written down twice.
$lines = icsHeader('FreeITSM', $tz);

foreach ($events as $ev) {
    $lines = array_merge($lines, icsEvent([
        'uid'         => 'event-' . (int)$ev['id'] . '@' . $domain,
        'summary'     => $ev['title'],
        'description' => $ev['description'] ?? '',
        'location'    => $ev['location'] ?? '',
        'categories'  => $ev['category_name'] ?? '',
        'start'       => $ev['start_datetime'],
        'end'         => !empty($ev['end_datetime']) ? $ev['end_datetime'] : $ev['start_datetime'],
        'all_day'     => (int)$ev['all_day'] === 1,
        'stamp'       => $ev['updated_at'] ?: ($ev['created_at'] ?: null),
    ], $tz));
}

icsRespond($lines, 'freeitsm.ics');
