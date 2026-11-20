<?php
/**
 * "Why is this in my bell?" - one notification, explained (5.8.0).
 *
 * Shared by the bell's (i) panel (api/notifications/explain.php) and debug tool D021,
 * so the two can never give different answers about the same row.
 *
 * A bell row holds an event type, a copy of a name and - from 5.8.0 - what made the
 * change (actor_kind, actor_analyst_id, source, workflow_id, api_key_id) and why the
 * reader was told (reason). The ticket's own history is matched to it BY KIND AND
 * TIME, because nothing links the two: on screen the history line is written by a
 * separate request from the change.
 *
 *   bell event                 history it is matched to
 *   ticket.team_assigned       ticket_audit Team
 *   ticket.assigned/reassigned ticket_audit Owner
 *   ticket.status_changed      ticket_audit Status
 *   ticket.priority_changed    ticket_audit Priority
 *   ticket.created             ticket_audit Ticket Created
 *   ticket.note_added          the note itself (ticket_notes; analyst_id NULL = a workflow)
 *   ticket.reply_received      the inbound email
 */

const NOTIFICATION_MATCH_WINDOW = 120;   // seconds either side

/** The ticket_audit field a bell event is matched to, lower-cased; null when it has none. */
function notificationAuditFieldFor(string $eventType): ?string
{
    return [
        'ticket.team_assigned' => 'team', 'ticket.assigned' => 'owner', 'ticket.reassigned' => 'owner',
        'ticket.status_changed' => 'status', 'ticket.priority_changed' => 'priority', 'ticket.created' => 'ticket created',
    ][$eventType] ?? null;
}

/**
 * The history that matches one bell event, or null when nothing within the window does.
 * Returns ['kind' => 'audit'|'note'|'email', 'id', 'field', 'old', 'new', 'by_kind' =>
 * 'analyst'|'system'|'former'|'requester', 'by_name', 'time', 'gap']. For an event type
 * no history records, returns ['kind' => 'none'].
 *
 * @param int $ts  the bell event's time (unix, UTC)
 * @param array<int, bool> $excludeAuditIds  audit rows already paired (D021 pairs a whole ticket)
 */
function notificationHistoryMatch(PDO $conn, int $ticketId, string $eventType, int $ts, array $excludeAuditIds = []): ?array
{
    $at = gmdate('Y-m-d H:i:s', $ts);
    $name = function (?int $id) use ($conn): array {
        if ($id === null) return ['system', null];
        $s = $conn->prepare('SELECT full_name FROM analysts WHERE id = ?'); $s->execute([$id]);
        $n = $s->fetchColumn();
        return $n !== false ? ['analyst', (string)$n] : ['former', null];
    };
    if ($field = notificationAuditFieldFor($eventType)) {
        $st = $conn->prepare('SELECT id, analyst_id, field_name, old_value, new_value, created_datetime FROM ticket_audit
                               WHERE ticket_id = ? AND LOWER(field_name) = ? AND ABS(TIMESTAMPDIFF(SECOND, created_datetime, ?)) <= ?
                               ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_datetime, ?)), id');
        $st->execute([$ticketId, $field, $at, NOTIFICATION_MATCH_WINDOW, $at]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (isset($excludeAuditIds[(int)$a['id']])) continue;
            [$kind, $who] = $name($a['analyst_id'] !== null ? (int)$a['analyst_id'] : null);
            return ['kind' => 'audit', 'id' => (int)$a['id'], 'field' => $a['field_name'], 'old' => $a['old_value'], 'new' => $a['new_value'],
                    'by_kind' => $kind, 'by_name' => $who, 'time' => $a['created_datetime'], 'gap' => abs(strtotime($a['created_datetime'] . ' UTC') - $ts)];
        }
        return null;
    }
    if ($eventType === 'ticket.note_added') {
        $st = $conn->prepare('SELECT id, analyst_id, created_datetime FROM ticket_notes WHERE ticket_id = ? AND ABS(TIMESTAMPDIFF(SECOND, created_datetime, ?)) <= ?
                               ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_datetime, ?)) LIMIT 1');
        $st->execute([$ticketId, $at, NOTIFICATION_MATCH_WINDOW, $at]);
        if (!($r = $st->fetch(PDO::FETCH_ASSOC))) return null;
        [$kind, $who] = $name($r['analyst_id'] !== null ? (int)$r['analyst_id'] : null);
        return ['kind' => 'note', 'id' => (int)$r['id'], 'field' => 'Note', 'old' => null, 'new' => null,
                'by_kind' => $kind, 'by_name' => $who, 'time' => $r['created_datetime'], 'gap' => abs(strtotime($r['created_datetime'] . ' UTC') - $ts)];
    }
    if ($eventType === 'ticket.reply_received') {
        // A reply is announced when it is FETCHED, which can be well after it was sent.
        $st = $conn->prepare('SELECT id, from_name, from_address, received_datetime FROM emails WHERE ticket_id = ? AND ABS(TIMESTAMPDIFF(SECOND, received_datetime, ?)) <= 86400
                               ORDER BY ABS(TIMESTAMPDIFF(SECOND, received_datetime, ?)) LIMIT 1');
        $st->execute([$ticketId, $at, $at]);
        if (!($r = $st->fetch(PDO::FETCH_ASSOC))) return null;
        return ['kind' => 'email', 'id' => (int)$r['id'], 'field' => 'Email', 'old' => null, 'new' => null, 'by_kind' => 'requester',
                'by_name' => trim(($r['from_name'] ?? '') . ' <' . ($r['from_address'] ?? '') . '>'), 'time' => $r['received_datetime'],
                'gap' => abs(strtotime($r['received_datetime'] . ' UTC') - $ts)];
    }
    return ['kind' => 'none'];
}

/** Workflow runs on a ticket within the window of $ts: [['id', 'name', 'event', 'status', 'time']]; null when they cannot be read. */
function notificationWorkflowRunsNear(PDO $conn, int $ticketId, int $ts): ?array
{
    try {
        $st = $conn->prepare("SELECT workflow_id, workflow_name, trigger_event, status, started_datetime FROM workflow_executions
                               WHERE JSON_UNQUOTE(JSON_EXTRACT(trigger_payload, '$.ticket.id')) = ? AND is_dry_run = 0
                                 AND ABS(TIMESTAMPDIFF(SECOND, started_datetime, ?)) <= ? ORDER BY started_datetime");
        $st->execute([(string)$ticketId, gmdate('Y-m-d H:i:s', $ts), NOTIFICATION_MATCH_WINDOW]);
        return array_map(fn($r) => ['id' => (int)$r['workflow_id'], 'name' => $r['workflow_name'], 'event' => $r['trigger_event'],
                                    'status' => $r['status'], 'time' => $r['started_datetime']], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Everything the (i) panel shows for one of the analyst's OWN notifications, or null
 * when it is not theirs. Never reveals a ticket they can no longer see: the history
 * part is then replaced by 'history_note' => 'no_access'.
 */
function notificationExplain(PDO $conn, int $analystId, int $notificationId): ?array
{
    $st = $conn->prepare('SELECT * FROM notifications WHERE id = ? AND analyst_id = ?');
    $st->execute([$notificationId, $analystId]);
    $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) return null;

    $grouped = (int)$n['event_count'] > 1;
    $ts = strtotime(($grouped ? $n['updated_datetime'] : $n['created_datetime']) . ' UTC');
    $recorded = array_key_exists('actor_kind', $n) && $n['actor_kind'] !== null;
    $out = [
        'id'          => (int)$n['id'],
        'event_type'  => $n['event_type'],
        'entity_type' => $n['entity_type'],
        'entity_ref'  => $n['entity_ref'],
        'time'        => gmdate('Y-m-d\TH:i:s\Z', $ts),
        'grouped'     => $grouped,
        'event_count' => (int)$n['event_count'],
        'recorded'    => $recorded,   // false = written before 5.8.0: only a name was kept
        'actor'       => [
            'kind'   => $n['actor_kind'] ?? null,
            'name'   => $n['actor_name'],
            'source' => $n['source'] ?? null,
            'workflow_id' => isset($n['workflow_id']) ? ($n['workflow_id'] !== null ? (int)$n['workflow_id'] : null) : null,
            'via_api'     => !empty($n['api_key_id']),
        ],
        'reason'       => $n['reason'] ?? null,
        'history'      => null,
        'history_note' => null,   // no_access | not_found | none | not_ticket
        'workflow_runs'=> [],
    ];
    if ($n['entity_type'] !== 'ticket') {
        $out['history_note'] = 'not_ticket';
        return $out;
    }
    require_once __DIR__ . '/tenancy.php';
    if (!analystCanAccessTicket($conn, $analystId, (int)$n['entity_id'])) {
        $out['history_note'] = 'no_access';   // the bell may outlive their access to the ticket
        return $out;
    }
    $h = notificationHistoryMatch($conn, (int)$n['entity_id'], (string)$n['event_type'], $ts);
    if ($h === null) $out['history_note'] = 'not_found';
    elseif ($h['kind'] === 'none') $out['history_note'] = 'none';
    else $out['history'] = ['field' => $h['field'], 'old' => $h['old'], 'new' => $h['new'], 'by_kind' => $h['by_kind'],
                            'by_name' => $h['by_name'], 'time' => gmdate('Y-m-d\TH:i:s\Z', strtotime($h['time'] . ' UTC')), 'gap' => $h['gap'], 'kind' => $h['kind']];
    $out['workflow_runs'] = array_map(fn($r) => ['name' => $r['name'], 'time' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['time'] . ' UTC'))],
                                      notificationWorkflowRunsNear($conn, (int)$n['entity_id'], $ts) ?? []);
    return $out;
}
