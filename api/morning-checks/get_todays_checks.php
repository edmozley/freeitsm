<?php
/**
 * API Endpoint: Get Today's Morning Checks with Results
 *
 * Normalised: JOINs morningChecks_Results to morningChecks_Statuses
 * so the response carries the StatusID + Label + Colour + RequiresNotes
 * already. Orphan rows (StatusID NULL but Status string set, e.g. from
 * a since-deleted status) come back with StatusID = null and the
 * orphan label string in Status — the dashboard surfaces those in a
 * warning banner and offers a normalisation tool in Settings.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/morning_checks.php';   // resolveAssignment(); brings morning_check_schedule.php

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('morning-checks');

try {
    $checkDate = $_GET['date'] ?? date('Y-m-d');

    $dateObj = DateTime::createFromFormat('Y-m-d', $checkDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $checkDate) {
        $checkDate = date('Y-m-d');
    }

    $conn = connectToDatabase();

    // Discussion #64: the row now carries its group, its routing, and who
    // completed it. CreatedBy has been written by the service layer since the
    // service-layer refactor — it was simply never selected here, which is why
    // "who confirmed this?" had no answer on screen despite the data existing.
    $sql = "SELECT c.CheckID, c.CheckName, c.CheckDescription, c.SortOrder,
                   r.ResultID,
                   r.StatusID, r.Status AS OrphanLabel,
                   s.Label AS StatusLabel, s.Colour AS StatusColour,
                   s.RequiresNotes AS StatusRequiresNotes,
                   r.Notes,
                   r.CreatedBy, r.ModifiedBy, r.ModifiedDate AS CompletedAt,
                   c.GroupID, g.GroupName, g.SortOrder AS GroupSortOrder,
                   c.AssignedAnalystID,
                   ca.full_name AS CheckAnalystName,
                   g.AssignedAnalystID AS GroupAnalystID,
                   ga.full_name        AS GroupAnalystName,
                   g.AssignedTeamID    AS GroupTeamID,
                   gt.name             AS GroupTeamName,
                   c.ScheduleType, c.ScheduleDays, c.ScheduleMonthWhen, c.ScheduleMonthDay
            FROM morningChecks_Checks c
            LEFT JOIN morningChecks_Results r
                ON c.CheckID = r.CheckID AND r.CheckDate = ?
            LEFT JOIN morningChecks_Statuses s
                ON r.StatusID = s.StatusID
            LEFT JOIN morningChecks_Groups g ON g.GroupID = c.GroupID
            LEFT JOIN analysts ca ON ca.id = c.AssignedAnalystID
            LEFT JOIN analysts ga ON ga.id = g.AssignedAnalystID
            LEFT JOIN teams    gt ON gt.id = g.AssignedTeamID
            WHERE c.IsActive = 1
              AND (c.GroupID IS NULL OR g.IsActive = 1)
            -- Ungrouped checks sort last: a round with some grouped and some not
            -- reads better as \"the named sections, then the rest\" than as a
            -- nameless block at the top.
            ORDER BY (c.GroupID IS NULL), g.SortOrder, g.GroupName, c.SortOrder, c.CheckName";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$checkDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // GH #383: a check not due on this day is still listed (greyed, and it can still be
    // recorded), with when it was last done, when it is next due, and - when its last due
    // day went by with nothing recorded - that it was missed. One query for the lot: the
    // results of the scheduled checks over the last 62 days, which is as far back as any
    // schedule's previous due day can be.
    $scheduled = array_values(array_unique(array_map(fn($r) => (int)$r['CheckID'], array_filter($rows, fn($r) => !empty($r['ScheduleType'])))));
    $lastDone = [];   // [CheckID] => Y-m-d, on or before the day viewed
    if ($scheduled) {
        $in = implode(',', array_fill(0, count($scheduled), '?'));
        $st = $conn->prepare("SELECT CheckID, DATE_FORMAT(CheckDate, '%Y-%m-%d') AS d FROM morningChecks_Results
                                WHERE CheckID IN ($in) AND CheckDate <= ? AND CheckDate >= ? - INTERVAL 62 DAY");
        $st->execute(array_merge($scheduled, [$checkDate, $checkDate]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
            if (!isset($lastDone[(int)$x['CheckID']]) || $x['d'] > $lastDone[(int)$x['CheckID']]) $lastDone[(int)$x['CheckID']] = $x['d'];
        }
    }

    $checks = [];
    foreach ($rows as $r) {
        // Resolve the effective label / colour. For normalised rows
        // the JOIN gives us Label/Colour. For orphans (StatusID NULL
        // but OrphanLabel set) the dashboard falls back to the label
        // string with no colour (renders as grey unmapped status).
        $statusId       = $r['StatusID'] !== null ? (int)$r['StatusID'] : null;
        $statusLabel    = $r['StatusLabel'] ?: $r['OrphanLabel'];
        $statusColour   = $r['StatusColour'];
        $requiresNotes  = $r['StatusRequiresNotes'] !== null ? (bool)$r['StatusRequiresNotes'] : null;
        $isOrphan       = ($statusId === null && $statusLabel !== null && $statusLabel !== '');

        // Who it is routed to, most specific first — see
        // MorningChecksService::resolveAssignment() for the precedence.
        [$assignedAnalystId, $assignedLabel, $assignedSource] = MorningChecksService::resolveAssignment($r);

        $checks[] = [
            'CheckID'              => (int)$r['CheckID'],
            'CheckName'            => $r['CheckName'],
            'CheckDescription'     => $r['CheckDescription'],
            'SortOrder'            => (int)$r['SortOrder'],
            'StatusID'             => $statusId,
            'Status'               => $statusLabel,        // effective label (joined or orphan)
            'StatusColour'         => $statusColour,
            'StatusRequiresNotes'  => $requiresNotes,
            'IsOrphan'             => $isOrphan,
            'Notes'                => $r['Notes'],
            // Discussion #64
            'ResultID'             => $r['ResultID'] !== null ? (int)$r['ResultID'] : null,
            // ModifiedBy first: the person who set the CURRENT status is the one who
            // checked it. CreatedBy is the fallback for rows written before that
            // column existed, and for the v1 API's own meaning of created_by.
            'CompletedBy'          => $r['ModifiedBy'] ?: $r['CreatedBy'],
            'CompletedAt'          => $r['ResultID'] !== null ? $r['CompletedAt'] : null,
            'GroupID'              => $r['GroupID'] !== null ? (int)$r['GroupID'] : null,
            'GroupName'            => $r['GroupName'],
            'AssignedAnalystID'    => $assignedAnalystId,
            'AssignedLabel'        => $assignedLabel,
            'AssignedSource'       => $assignedSource,     // 'check' | 'group' | 'team' | null
            'AssignedTeamID'       => $assignedSource === 'team' && $r['GroupTeamID'] !== null ? (int)$r['GroupTeamID'] : null,
            // GH #383
            'Schedule'             => MorningCheckSchedule::toArray($r),
        ] + mcScheduleState($r, $checkDate, $lastDone);
    }

    echo json_encode($checks);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * GH #383: Due on this day, and - for a check that is not - when it was last done, when it is
 * next due, and the due day it missed (its last due day before this one, with nothing recorded).
 *
 * @param array $r         the check row with its Schedule* columns
 * @param array $lastDone  [CheckID] => Y-m-d
 */
function mcScheduleState(array $r, string $date, array $lastDone): array
{
    $id = (int)$r['CheckID'];
    if (MorningCheckSchedule::isDue($r, $date)) {
        return ['Due' => true, 'NextDue' => null, 'LastDone' => $lastDone[$id] ?? null, 'MissedOn' => null];
    }
    $prev = MorningCheckSchedule::previousDue($r, $date);
    // Missed = its last due day passed with nothing recorded on it or after it.
    $missed = ($prev !== null && !(isset($lastDone[$id]) && $lastDone[$id] >= $prev)) ? $prev : null;
    return [
        'Due'      => false,
        'NextDue'  => MorningCheckSchedule::nextDue($r, $date),
        'LastDone' => $lastDone[$id] ?? null,
        'MissedOn' => $missed,
    ];
}
