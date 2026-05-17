<?php
/**
 * SLA Engine — Service Level Agreement computation.
 *
 * Design: see docs/sla.md. The principle is **compute on read** — no stored
 * counters on the ticket, no background jobs, no drift. Each call walks the
 * ticket's status-change audit history, splits the lifetime into "running"
 * vs. "paused" intervals based on which statuses are flagged `pauses_sla`,
 * intersects each running interval with the priority's business calendar
 * (week pattern + holidays + timezone), and sums to get elapsed business
 * minutes.
 *
 * Two public functions:
 *   sla_get_state($conn, $ticket_id)   — returns the full SLA state of a ticket
 *   sla_business_minutes($start, $end, $calendar)
 *                                       — pure function, the intersection helper
 *
 * Both treat all DateTimes as UTC unless explicitly converted via the
 * calendar's timezone for day-walking. The DB stores everything in UTC.
 */

/**
 * Compute the SLA state of a single ticket. Returns:
 *   [
 *     'enabled'         => bool,
 *     'reason_disabled' => ?string,
 *     'priority'        => ?array,
 *     'calendar'        => ?array,
 *     'response'        => ?array (target_minutes, elapsed_minutes, remaining_minutes,
 *                                  percent, breached, achieved_at, achieved_minutes),
 *     'resolution'      => ?array (same shape as response, or null if no target),
 *   ]
 *
 * If `enabled` is false, the other fields are best-effort / informational only.
 */
function sla_get_state(PDO $conn, int $ticket_id): array {
    $state = [
        'enabled'         => false,
        'reason_disabled' => null,
        'priority'        => null,
        'calendar'        => null,
        'response'        => null,
        'resolution'      => null,
    ];

    // --- 1. Load ticket ---
    $stmt = $conn->prepare("SELECT t.id, t.ticket_number, t.created_datetime, t.priority_id, t.status_id,
                                   t.closed_datetime, ts.name AS current_status_name
                            FROM tickets t
                            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
                            WHERE t.id = ?");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        $state['reason_disabled'] = 'Ticket not found';
        return $state;
    }

    // --- 2. Check global enforcement ---
    $settings = sla_load_settings($conn);
    if (empty($settings['sla_enforce_from'])) {
        $state['reason_disabled'] = 'SLA enforcement is disabled globally';
        return $state;
    }
    $enforceFrom = new DateTimeImmutable($settings['sla_enforce_from'], new DateTimeZone('UTC'));
    $createdAt = new DateTimeImmutable($ticket['created_datetime'], new DateTimeZone('UTC'));
    if ($createdAt < $enforceFrom) {
        $state['reason_disabled'] = 'Ticket was created before the SLA enforcement cutoff';
        return $state;
    }

    // --- 3. Load priority and check for targets ---
    if (!$ticket['priority_id']) {
        $state['reason_disabled'] = 'Ticket has no priority assigned';
        return $state;
    }
    $stmt = $conn->prepare("SELECT id, name, colour, sla_response_minutes, sla_resolution_minutes, sla_calendar_id
                            FROM ticket_priorities WHERE id = ?");
    $stmt->execute([$ticket['priority_id']]);
    $priority = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$priority) {
        $state['reason_disabled'] = 'Ticket priority not found';
        return $state;
    }
    $state['priority'] = $priority;

    if (empty($priority['sla_response_minutes']) && empty($priority['sla_resolution_minutes'])) {
        $state['reason_disabled'] = 'Priority has no SLA target configured';
        return $state;
    }

    // --- 4. Load calendar (priority's calendar, or default if NULL) ---
    $calId = $priority['sla_calendar_id'];
    if (!$calId) {
        $defStmt = $conn->query("SELECT id FROM sla_calendars WHERE is_default = 1 AND is_active = 1 LIMIT 1");
        $row = $defStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $calId = (int)$row['id'];
    }
    if (!$calId) {
        $state['reason_disabled'] = 'No SLA calendar set on priority and no default calendar exists';
        return $state;
    }
    $calendar = sla_load_calendar($conn, (int)$calId);
    if (!$calendar) {
        $state['reason_disabled'] = 'SLA calendar not found';
        return $state;
    }
    $state['calendar'] = $calendar;

    // --- 5. Build status timeline from audit log ---
    // Initial state: default status when ticket was created (audit only records changes,
    // not the initial state). If we can't find a default status, fall back to "not pausing".
    $defStatusStmt = $conn->query("SELECT name, pauses_sla FROM ticket_statuses WHERE is_default = 1 LIMIT 1");
    $defStatus = $defStatusStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => null, 'pauses_sla' => 0];

    $auditStmt = $conn->prepare("SELECT new_value, created_datetime
                                 FROM ticket_audit
                                 WHERE ticket_id = ? AND field_name = 'Status'
                                 ORDER BY created_datetime ASC, id ASC");
    $auditStmt->execute([$ticket_id]);
    $statusChanges = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

    // Map status name → pauses_sla flag (case-insensitive)
    $statusFlags = [];
    $allStatusStmt = $conn->query("SELECT name, pauses_sla, is_closed FROM ticket_statuses");
    foreach ($allStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $statusFlags[strtolower($s['name'])] = ['pauses_sla' => (bool)$s['pauses_sla'], 'is_closed' => (bool)$s['is_closed']];
    }

    // Timeline: array of { start: DateTimeImmutable UTC, status: name, pauses: bool }
    $timeline = [['start' => $createdAt, 'status' => $defStatus['name'], 'pauses' => (bool)$defStatus['pauses_sla']]];
    foreach ($statusChanges as $sc) {
        $changedAt = new DateTimeImmutable($sc['created_datetime'], new DateTimeZone('UTC'));
        $newStatus = $sc['new_value'];
        $flags = $statusFlags[strtolower($newStatus)] ?? ['pauses_sla' => false, 'is_closed' => false];
        $timeline[] = ['start' => $changedAt, 'status' => $newStatus, 'pauses' => $flags['pauses_sla']];
    }

    // --- 6. Response SLA ---
    if (!empty($priority['sla_response_minutes'])) {
        $state['response'] = sla_compute_response($conn, $ticket, $priority, $calendar, $timeline, $settings);
    }

    // --- 7. Resolution SLA ---
    if (!empty($priority['sla_resolution_minutes'])) {
        $state['resolution'] = sla_compute_resolution($ticket, $priority, $calendar, $timeline);
    }

    $state['enabled'] = true;
    return $state;
}

/**
 * Compute response-time SLA state. The clock stops at the first response,
 * where "first response" is defined per the sla_first_response_definition
 * setting. v1 supports 'status_change' (first audit row that moves status
 * away from the default) for all three options — outbound-email detection
 * is deferred to a follow-up (see docs/sla.md).
 */
function sla_compute_response(PDO $conn, array $ticket, array $priority, array $calendar, array $timeline, array $settings): array {
    $target = (int)$priority['sla_response_minutes'];
    $createdAt = $timeline[0]['start'];

    // Find first response time. For v1: first non-default status change.
    // 'outbound_email' and 'either' fall through to this same detection in v1.
    $firstResponseAt = null;
    foreach ($timeline as $i => $segment) {
        if ($i === 0) continue; // skip the implicit initial segment
        $firstResponseAt = $segment['start'];
        break;
    }

    // Clock end = first response if it happened, else now
    $clockEnd = $firstResponseAt ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $elapsed = sla_elapsed_business_minutes($timeline, $createdAt, $clockEnd, $calendar);

    $remaining = $target - $elapsed;
    $percent   = $target > 0 ? min(100, max(0, ($elapsed / $target) * 100)) : 0;
    $breached  = $elapsed > $target;

    return [
        'target_minutes'     => $target,
        'elapsed_minutes'    => $elapsed,
        'remaining_minutes'  => $remaining,
        'percent'            => round($percent, 1),
        'breached'           => $breached,
        'achieved_at'        => $firstResponseAt ? $firstResponseAt->format('Y-m-d H:i:s') : null,
        'achieved_minutes'   => $firstResponseAt ? $elapsed : null,
    ];
}

/**
 * Compute resolution-time SLA state. The clock stops at ticket close time
 * (read from tickets.closed_datetime or the first audit row that lands on a
 * status with is_closed = 1).
 */
function sla_compute_resolution(array $ticket, array $priority, array $calendar, array $timeline): array {
    $target = (int)$priority['sla_resolution_minutes'];
    $createdAt = $timeline[0]['start'];

    $closedAt = null;
    if (!empty($ticket['closed_datetime'])) {
        $closedAt = new DateTimeImmutable($ticket['closed_datetime'], new DateTimeZone('UTC'));
    } else {
        // Walk the timeline for the first transition into a closed status
        foreach ($timeline as $segment) {
            if (!empty($segment['_is_closed'])) {
                $closedAt = $segment['start'];
                break;
            }
        }
    }

    $clockEnd = $closedAt ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $elapsed = sla_elapsed_business_minutes($timeline, $createdAt, $clockEnd, $calendar);

    $remaining = $target - $elapsed;
    $percent   = $target > 0 ? min(100, max(0, ($elapsed / $target) * 100)) : 0;
    $breached  = $elapsed > $target;

    return [
        'target_minutes'     => $target,
        'elapsed_minutes'    => $elapsed,
        'remaining_minutes'  => $remaining,
        'percent'            => round($percent, 1),
        'breached'           => $breached,
        'achieved_at'        => $closedAt ? $closedAt->format('Y-m-d H:i:s') : null,
        'achieved_minutes'   => $closedAt ? $elapsed : null,
    ];
}

/**
 * Walk the status timeline, summing business minutes in intervals where the
 * status was NOT pausing. Bounded between [start, end].
 */
function sla_elapsed_business_minutes(array $timeline, DateTimeImmutable $start, DateTimeImmutable $end, array $calendar): int {
    if ($end <= $start) return 0;

    $total = 0;
    $n = count($timeline);
    for ($i = 0; $i < $n; $i++) {
        $segStart = $timeline[$i]['start'];
        $segEnd   = ($i + 1 < $n) ? $timeline[$i + 1]['start'] : $end;
        $pauses   = $timeline[$i]['pauses'];

        if ($pauses) continue; // clock was paused for this segment

        // Intersect [segStart, segEnd] with [start, end]
        $iStart = $segStart > $start ? $segStart : $start;
        $iEnd   = $segEnd   < $end   ? $segEnd   : $end;
        if ($iEnd <= $iStart) continue;

        $total += sla_business_minutes($iStart, $iEnd, $calendar);
    }

    return $total;
}

/**
 * Pure function: total business minutes in the interval [start, end] given
 * the calendar's timezone, weekly working hours, and holiday list.
 *
 * @param DateTimeImmutable $start  UTC
 * @param DateTimeImmutable $end    UTC
 * @param array $calendar           { timezone, hours: [{weekday, start_time, end_time}], holidays: [{holiday_date}] }
 */
function sla_business_minutes(DateTimeImmutable $start, DateTimeImmutable $end, array $calendar): int {
    if ($end <= $start) return 0;

    $tz = new DateTimeZone($calendar['timezone'] ?? 'UTC');
    $startLocal = $start->setTimezone($tz);
    $endLocal   = $end->setTimezone($tz);

    // Build hours lookup: weekday (1-7) => ['start' => 'HH:MM:SS', 'end' => 'HH:MM:SS']
    $hoursByWd = [];
    foreach ($calendar['hours'] ?? [] as $h) {
        $hoursByWd[(int)$h['weekday']] = [
            'start' => substr($h['start_time'], 0, 8),
            'end'   => substr($h['end_time'], 0, 8),
        ];
    }
    $holidays = [];
    foreach ($calendar['holidays'] ?? [] as $h) {
        $holidays[$h['holiday_date']] = true;
    }

    $totalSeconds = 0;
    // Walk day by day in the calendar's local time
    $cursor = $startLocal->setTime(0, 0, 0);
    $lastDay = $endLocal->setTime(0, 0, 0);
    $guard = 0; // sanity: a single SLA computation shouldn't span > ~10 years
    while ($cursor <= $lastDay && $guard++ < 4000) {
        $cursorDate = $cursor->format('Y-m-d');
        $weekday    = (int)$cursor->format('N'); // 1=Mon ... 7=Sun

        // Skip holidays and non-working days
        if (!isset($holidays[$cursorDate]) && isset($hoursByWd[$weekday])) {
            $h = $hoursByWd[$weekday];
            // Day's working window, in this calendar's tz
            list($sh, $sm, $ss) = array_pad(explode(':', $h['start']), 3, '0');
            list($eh, $em, $ee) = array_pad(explode(':', $h['end']),   3, '0');
            $dayStart = $cursor->setTime((int)$sh, (int)$sm, (int)$ss);
            $dayEnd   = $cursor->setTime((int)$eh, (int)$em, (int)$ee);

            // Intersect [dayStart, dayEnd] with [startLocal, endLocal]
            $iStart = $dayStart > $startLocal ? $dayStart : $startLocal;
            $iEnd   = $dayEnd   < $endLocal   ? $dayEnd   : $endLocal;
            if ($iEnd > $iStart) {
                $totalSeconds += $iEnd->getTimestamp() - $iStart->getTimestamp();
            }
        }
        $cursor = $cursor->modify('+1 day');
    }

    return (int)round($totalSeconds / 60);
}

/**
 * Load the seven SLA system_settings rows into a plain array.
 */
function sla_load_settings(PDO $conn): array {
    $keys = [
        'sla_enforce_from',
        'sla_priority_change_behaviour',
        'sla_reopen_behaviour',
        'sla_warning_threshold_percent',
        'sla_notify_assignee_at_warning',
        'sla_notify_lead_at_breach',
        'sla_first_response_definition',
    ];
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($ph)");
    $stmt->execute($keys);
    $out = [];
    foreach ($keys as $k) $out[$k] = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}

/**
 * Load a calendar with its hours + holidays nested. Returns null if not found.
 */
function sla_load_calendar(PDO $conn, int $calendar_id): ?array {
    $stmt = $conn->prepare("SELECT id, name, timezone FROM sla_calendars WHERE id = ? AND is_active = 1");
    $stmt->execute([$calendar_id]);
    $cal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cal) return null;

    $hStmt = $conn->prepare("SELECT weekday, start_time, end_time FROM sla_calendar_hours WHERE calendar_id = ? ORDER BY weekday");
    $hStmt->execute([$calendar_id]);
    $cal['hours'] = $hStmt->fetchAll(PDO::FETCH_ASSOC);

    $holStmt = $conn->prepare("SELECT holiday_date FROM sla_calendar_holidays WHERE calendar_id = ?");
    $holStmt->execute([$calendar_id]);
    $cal['holidays'] = $holStmt->fetchAll(PDO::FETCH_ASSOC);

    return $cal;
}

/**
 * Format a minute count as a short human-friendly string: "1h 30m", "45m", "2h", "-15m" (negative).
 */
function sla_format_minutes(int $minutes): string {
    $neg = $minutes < 0 ? '-' : '';
    $m = abs($minutes);
    if ($m < 60) return $neg . $m . 'm';
    $h = intdiv($m, 60);
    $r = $m % 60;
    return $neg . ($r ? "{$h}h {$r}m" : "{$h}h");
}
