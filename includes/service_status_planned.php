<?php
/**
 * Service Status - planned maintenance (3.2.0).
 *
 * "Move day: phones down Saturday 08:00-14:00", announced on Tuesday.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔑 NOT AN INCIDENT UNTIL IT STARTS
 *
 * About fifteen readers decide whether an incident is open from its STATUS
 * (service_incident_statuses.is_resolved) and take its start from
 * created_datetime: the board, the portal, Watchtower, uptime, Report Packs, the
 * REST API, Warbot. None of them knows a start time. An incident created on
 * Tuesday for Saturday would show the phones "Under Maintenance" for four days
 * and count from Tuesday.
 *
 * So the plan waits in status_planned, shown as Upcoming, and nothing else in
 * the product sees it. At planned_start it becomes an ORDINARY incident, made
 * through ServiceStatusService::saveIncident() - so its workflows, update log
 * and every reader behave exactly as for one typed in by hand - and its
 * timestamps are BACKDATED to planned_start, so a cron that runs at 08:47 still
 * gives an uptime record that starts at 08:00. At planned_end it is resolved the
 * same way. Not one existing reader had to change.
 *
 *   scheduled ─(planned_start)→ started ─(planned_end)→ finished
 *       └─ cancelled (by hand, before it starts)
 *
 * planned_end NULL = no automatic end: somebody resolves the incident by hand.
 * Resolving it by hand early is respected - the end step only records
 * "finished" for an incident that is already resolved.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHO MOVES IT ON
 *
 * statusPlannedRun(): the scheduled workflow cron (includes/workflow_scheduled.php),
 * and statusPlannedDue() at the top of the readers people look at - the board,
 * the portal, Watchtower - so a start is seen on time even with no cron. Each
 * step is claimed with a compare-and-set on `state`, so overlapping runs cannot
 * start one plan twice.
 *
 * The incident is made AS the person who scheduled it (created_by_id), so its
 * history says who planned the work rather than "System".
 */

require_once __DIR__ . '/services/service_status.php';
require_once __DIR__ . '/service_impact_levels.php';

const STATUS_PLANNED_STATES = ['scheduled', 'started', 'finished', 'cancelled'];

/** Has Database Verification created the tables? */
function statusPlannedReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM status_planned LIMIT 0"); $conn->query("SELECT 1 FROM status_planned_services LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** 'Y-m-d H:i:s' UTC from anything strtotime() reads as UTC (the page sends ISO from inputToUTC()). */
function statusPlannedUtc($v): ?string
{
    if ($v === null || $v === '') return null;
    $ts = strtotime((string)$v . (preg_match('/(Z|[+-]\d{2}:?\d{2}|UTC)$/i', (string)$v) ? '' : ' UTC'));
    if ($ts === false) throw new ServiceError('validation', 'invalid_field', 'That is not a date and time.');
    return gmdate('Y-m-d H:i:s', $ts);
}

/**
 * Schedule (no id) or change (id, while still scheduled) planned maintenance.
 * $in: title, comment, start, end?, services [{service_id, impact_level_id}],
 *      project_id?. A start that has already passed starts it at once.
 * Returns the id.
 */
function statusPlannedSave(PDO $conn, ActorContext $ctx, array $in): int
{
    if (!statusPlannedReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') throw new ServiceError('validation', 'missing_field', 'Give the maintenance a title.');
    if (mb_strlen($title) > 255) throw new ServiceError('validation', 'invalid_field', 'The title is too long.');
    $comment = trim((string)($in['comment'] ?? '')) ?: null;
    $start = statusPlannedUtc($in['start'] ?? null) ?? gmdate('Y-m-d H:i:s');
    $end   = statusPlannedUtc($in['end'] ?? null);
    if ($end !== null && $end <= $start) throw new ServiceError('validation', 'invalid_field', 'The end must be after the start.');
    $services = is_array($in['services'] ?? null) ? $in['services'] : [];
    if (!$services) throw new ServiceError('validation', 'missing_field', 'Choose at least one service it affects.');
    // The same rule a real incident is held to, so the start can never fail on a service.
    $links = ServiceStatusService::validateIncidentServices($conn, $services);
    $projectId = !empty($in['project_id']) ? (int)$in['project_id'] : null;

    $id = (int)($in['id'] ?? 0);
    $conn->beginTransaction();
    try {
        if ($id > 0) {
            $cur = statusPlannedLoad($conn, $id);
            if ($cur['state'] !== 'scheduled') throw new ServiceError('validation', 'invalid_field', 'It has already started. Change the incident instead.');
            $conn->prepare("UPDATE status_planned SET title = ?, comment = ?, planned_start_datetime = ?, planned_end_datetime = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$title, $comment, $start, $end, $id]);
            $conn->prepare("DELETE FROM status_planned_services WHERE planned_id = ?")->execute([$id]);
        } else {
            $conn->prepare("INSERT INTO status_planned (title, comment, planned_start_datetime, planned_end_datetime, state, project_id, created_by_id, created_datetime, updated_datetime)
                            VALUES (?, ?, ?, ?, 'scheduled', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                 ->execute([$title, $comment, $start, $end, $projectId, $ctx->actorId > 0 ? $ctx->actorId : null]);
            $id = (int)$conn->lastInsertId();
        }
        $ins = $conn->prepare("INSERT INTO status_planned_services (planned_id, service_id, impact_level_id) VALUES (?, ?, ?)");
        foreach ($links as [$serviceId, $impactId]) $ins->execute([$id, $serviceId, $impactId]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    // A start already passed - "straight away" - starts now, not at the next run.
    statusPlannedRun($conn, $id);
    return $id;
}

/** Cancel planned maintenance that has not started. */
function statusPlannedCancel(PDO $conn, ActorContext $ctx, int $id): void
{
    $cur = statusPlannedLoad($conn, $id);
    if ($cur['state'] !== 'scheduled') throw new ServiceError('validation', 'invalid_field', 'Only maintenance that has not started can be cancelled. Resolve the incident instead.');
    $conn->prepare("UPDATE status_planned SET state = 'cancelled', updated_datetime = UTC_TIMESTAMP() WHERE id = ? AND state = 'scheduled'")->execute([$id]);
}

function statusPlannedLoad(PDO $conn, int $id): array
{
    $st = $conn->prepare("SELECT * FROM status_planned WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new ServiceError('not_found', 'not_found', 'Planned maintenance not found.');
    return $row;
}

/**
 * Planned maintenance for a list: scheduled and in progress by default, soonest
 * first, each with its services. $opts: project_id, states, limit.
 */
function statusPlannedList(PDO $conn, array $opts = []): array
{
    if (!statusPlannedReady($conn)) return [];
    $states = $opts['states'] ?? ['scheduled', 'started'];
    $states = array_values(array_intersect($states, STATUS_PLANNED_STATES)) ?: ['scheduled'];
    $where = 'p.state IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
    $args = $states;
    if (isset($opts['project_id'])) { $where .= ' AND p.project_id = ?'; $args[] = (int)$opts['project_id']; }
    $st = $conn->prepare("SELECT p.id, p.title, p.comment, p.planned_start_datetime, p.planned_end_datetime, p.state, p.incident_id, p.project_id,
                                 a.full_name AS created_by_name, pr.name AS project_name
                            FROM status_planned p LEFT JOIN analysts a ON a.id = p.created_by_id
                       LEFT JOIN projects pr ON pr.id = p.project_id
                           WHERE $where
                        ORDER BY FIELD(p.state, 'started', 'scheduled', 'finished', 'cancelled'), p.planned_start_datetime
                           LIMIT " . max(1, min(500, (int)($opts['limit'] ?? 100))));
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $svc = $conn->prepare("SELECT ps.planned_id, ps.service_id, ps.impact_level_id, s.name AS service_name, il.name AS impact_name, il.colour AS impact_colour
                             FROM status_planned_services ps
                             JOIN status_services s ON s.id = ps.service_id
                        LEFT JOIN service_impact_levels il ON il.id = ps.impact_level_id
                            WHERE ps.planned_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                         ORDER BY s.display_order, s.name");
    $svc->execute($ids);
    $by = [];
    foreach ($svc->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $by[(int)$s['planned_id']][] = ['service_id' => (int)$s['service_id'], 'name' => $s['service_name'],
            'impact_level_id' => $s['impact_level_id'] !== null ? (int)$s['impact_level_id'] : null, 'impact' => $s['impact_name'], 'colour' => $s['impact_colour']];
    }
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'title' => $r['title'], 'comment' => $r['comment'],
        'start' => $r['planned_start_datetime'], 'end' => $r['planned_end_datetime'],
        'state' => $r['state'], 'incident_id' => $r['incident_id'] !== null ? (int)$r['incident_id'] : null,
        'project_id' => $r['project_id'] !== null ? (int)$r['project_id'] : null,
        'project_name' => $r['project_name'],
        'created_by' => $r['created_by_name'], 'services' => $by[(int)$r['id']] ?? [],
    ], $rows);
}

/** Is anything due to start or end? One indexed query - cheap enough for every page load. */
function statusPlannedDue(PDO $conn): void
{
    try {
        if (!statusPlannedReady($conn)) return;
        $due = $conn->query("SELECT 1 FROM status_planned
                              WHERE (state = 'scheduled' AND planned_start_datetime <= UTC_TIMESTAMP())
                                 OR (state = 'started' AND planned_end_datetime IS NOT NULL AND planned_end_datetime <= UTC_TIMESTAMP())
                              LIMIT 1")->fetchColumn();
        if ($due) statusPlannedRun($conn);
    } catch (Throwable $e) {
        error_log('planned maintenance: ' . $e->getMessage());
    }
}

/**
 * Start what is due and finish what has ended. $onlyId = one plan (just saved).
 * @return array{started:int, finished:int}
 */
function statusPlannedRun(PDO $conn, ?int $onlyId = null): array
{
    $out = ['started' => 0, 'finished' => 0];
    if (!statusPlannedReady($conn)) return $out;
    $only = $onlyId !== null ? ' AND id = ' . (int)$onlyId : '';

    $due = $conn->query("SELECT * FROM status_planned WHERE state = 'scheduled' AND planned_start_datetime <= UTC_TIMESTAMP()$only ORDER BY planned_start_datetime")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($due as $p) {
        // Claim it first: only the run whose update lands makes the incident.
        $claim = $conn->prepare("UPDATE status_planned SET state = 'started', updated_datetime = UTC_TIMESTAMP() WHERE id = ? AND state = 'scheduled'");
        $claim->execute([(int)$p['id']]);
        if ($claim->rowCount() !== 1) continue;
        try {
            statusPlannedStart($conn, $p);
            $out['started']++;
        } catch (Throwable $e) {
            // Give it back, so the next run tries again rather than losing it.
            $conn->prepare("UPDATE status_planned SET state = 'scheduled' WHERE id = ? AND incident_id IS NULL")->execute([(int)$p['id']]);
            error_log('planned maintenance ' . $p['id'] . ' could not start: ' . $e->getMessage());
        }
    }

    $ended = $conn->query("SELECT * FROM status_planned WHERE state = 'started' AND planned_end_datetime IS NOT NULL AND planned_end_datetime <= UTC_TIMESTAMP()$only")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ended as $p) {
        $claim = $conn->prepare("UPDATE status_planned SET state = 'finished', updated_datetime = UTC_TIMESTAMP() WHERE id = ? AND state = 'started'");
        $claim->execute([(int)$p['id']]);
        if ($claim->rowCount() !== 1) continue;
        try {
            statusPlannedFinish($conn, $p);
            $out['finished']++;
        } catch (Throwable $e) {
            $conn->prepare("UPDATE status_planned SET state = 'started' WHERE id = ?")->execute([(int)$p['id']]);
            error_log('planned maintenance ' . $p['id'] . ' could not finish: ' . $e->getMessage());
        }
    }
    return $out;
}

/** The person who scheduled it, as the actor of the incident's history. */
function statusPlannedActor(PDO $conn, array $p): ActorContext
{
    $id = (int)($p['created_by_id'] ?? 0);
    $name = 'System';
    if ($id > 0) {
        $st = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
        $st->execute([$id]);
        $name = (string)($st->fetchColumn() ?: 'System');
    }
    return new ActorContext($id, null, 'system', 'en', $name);
}

/**
 * The status a started maintenance incident takes: the first open status that
 * is not the starting one ("Identified" on a stock install). "Investigating"
 * would say nobody knows what is happening - the opposite of planned work.
 */
function statusPlannedStatusId(PDO $conn): ?int
{
    $id = $conn->query("SELECT id FROM service_incident_statuses WHERE is_active = 1 AND is_resolved = 0 AND is_default = 0 ORDER BY display_order, id LIMIT 1")->fetchColumn();
    if ($id === false) $id = $conn->query("SELECT id FROM service_incident_statuses WHERE is_active = 1 AND is_resolved = 0 ORDER BY is_default DESC, display_order, id LIMIT 1")->fetchColumn();
    return $id === false ? null : (int)$id;
}

function statusPlannedStart(PDO $conn, array $p): void
{
    $svc = $conn->prepare("SELECT service_id, impact_level_id FROM status_planned_services WHERE planned_id = ?");
    $svc->execute([(int)$p['id']]);
    $services = array_map(fn($r) => ['service_id' => (int)$r['service_id'], 'impact_level_id' => $r['impact_level_id']], $svc->fetchAll(PDO::FETCH_ASSOC));
    $in = ['title' => $p['title'], 'comment' => $p['comment'], 'services' => $services,
           // Announced in advance on purpose: the portal shows external updates only.
           'is_internal' => false];
    $statusId = statusPlannedStatusId($conn);
    if ($statusId !== null) $in['status_id'] = $statusId;
    $incidentId = ServiceStatusService::saveIncident($conn, statusPlannedActor($conn, $p), $in);
    // Backdate to the planned start: uptime and the update log begin when the
    // work did, not when a cron happened to notice. Never forward - a plan saved
    // for a start already passed starts now.
    $at = min($p['planned_start_datetime'], gmdate('Y-m-d H:i:s'));
    $conn->prepare("UPDATE status_incidents SET created_datetime = ? WHERE id = ?")->execute([$at, $incidentId]);
    try { $conn->prepare("UPDATE status_incident_updates SET created_datetime = ? WHERE incident_id = ?")->execute([$at, $incidentId]); }
    catch (Throwable $e) { /* no update log on this install */ }
    $conn->prepare("UPDATE status_planned SET incident_id = ? WHERE id = ?")->execute([$incidentId, (int)$p['id']]);
}

function statusPlannedFinish(PDO $conn, array $p): void
{
    $incidentId = (int)($p['incident_id'] ?? 0);
    if ($incidentId <= 0) return;
    $st = $conn->prepare("SELECT si.resolved_datetime, COALESCE(sst.is_resolved, 0) AS is_resolved
                            FROM status_incidents si LEFT JOIN service_incident_statuses sst ON sst.id = si.status_id WHERE si.id = ?");
    $st->execute([$incidentId]);
    $inc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inc || (int)$inc['is_resolved'] === 1) return;   // deleted, or somebody resolved it already - theirs stands
    $resolved = $conn->query("SELECT id FROM service_incident_statuses WHERE is_active = 1 AND is_resolved = 1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
    if ($resolved === false) return;   // no resolved status defined: leave it for a person
    ServiceStatusService::saveIncident($conn, statusPlannedActor($conn, $p), [
        'id' => $incidentId, 'status_id' => (int)$resolved, 'is_internal' => false,
        'comment' => 'Planned maintenance finished.',
    ]);
    // Backdate the end to the planned end, as the start was.
    $at = min($p['planned_end_datetime'], gmdate('Y-m-d H:i:s'));
    $conn->prepare("UPDATE status_incidents SET resolved_datetime = ? WHERE id = ?")->execute([$at, $incidentId]);
    try {
        $conn->prepare("UPDATE status_incident_updates SET created_datetime = ? WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM status_incident_updates WHERE incident_id = ?) x)")
             ->execute([$at, $incidentId]);
    } catch (Throwable $e) { /* no update log */ }
}
