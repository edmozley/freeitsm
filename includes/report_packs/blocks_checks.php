<?php
/**
 * Report Packs: Morning Checks blocks (5.11.0) - the week's or the month's checks in
 * a pack, where the Morning Checks page's own "Save to PDF" prints one day. Asked for
 * by a supporter of the project.
 *
 * THE DAYS THAT COUNT
 *   A day counts for a check when the check was DUE that day (its schedule -
 *   MorningCheckSchedule::isDue(), the same answer the round and Watchtower give),
 *   from the day the check was made, up to today - never a day still to come. A
 *   result recorded on a day it was not due still counts: a weekly check done a
 *   day late is better counted than not.
 *
 * NOT CHECKED: a due day with no result, for a check that is active now. A check
 * switched off since is shown with the results it has, but its empty days are not
 * held against it.
 *
 * EXCEPTIONS: the results whose status ASKS FOR NOTES (morningChecks_Statuses.
 * RequiresNotes) - Amber and Red as shipped. The statuses are the install's own,
 * so "what is a problem" is the install's own answer too, not a colour guessed here.
 *
 * Morning Checks are not split by company, so the pack's company does not narrow them.
 * Module 'morning-checks'.
 */

require_once __DIR__ . '/../morning_check_schedule.php';

/** Today, in the pack's time zone, as Y-m-d. */
function rpChecksToday(array $range): string
{
    try { return (new DateTimeImmutable('now', new DateTimeZone($range['tz'] ?? date_default_timezone_get())))->format('Y-m-d'); }
    catch (Throwable $e) { return date('Y-m-d'); }
}

/** The statuses, in their order: [StatusID => [label, colour, notes]]. Inactive ones too - old results may use them. */
function rpChecksStatuses(PDO $conn): array
{
    $out = [];
    foreach ($conn->query("SELECT StatusID, Label, Colour, RequiresNotes, IsActive FROM morningChecks_Statuses ORDER BY SortOrder, StatusID") as $r) {
        $out[(int)$r['StatusID']] = ['label' => (string)$r['Label'], 'colour' => (string)$r['Colour'],
                                     'notes' => (int)$r['RequiresNotes'] === 1, 'active' => (int)$r['IsActive'] === 1];
    }
    return $out;
}

/**
 * Every check that matters to the period - active now, or with a result in it - and,
 * per check, the period's results by day and its due days without one.
 *
 * @return array<int, array{check: array, results: array<string, array>, missed: string[]}>  keyed by CheckID, in round order
 */
function rpChecksPeriod(PDO $conn, array $range): array
{
    $from = $range['from_date']; $to = min($range['to_date'], rpChecksToday($range));
    $st = $conn->prepare(
        "SELECT r.ResultID, r.CheckID, DATE(r.CheckDate) AS day, r.StatusID, r.Status, r.Notes,
                COALESCE(NULLIF(r.ModifiedBy, ''), r.CreatedBy) AS who
           FROM morningChecks_Results r
          WHERE r.CheckDate >= ? AND r.CheckDate < DATE_ADD(?, INTERVAL 1 DAY)
          ORDER BY r.CheckDate, r.ResultID");
    $st->execute([$from, $range['to_date']]);
    $results = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $results[(int)$r['CheckID']][(string)$r['day']] = $r;   // the last one on a day stands

    $checks = $conn->query(
        "SELECT c.*, DATE(c.CreatedDate) AS made, g.GroupName, COALESCE(g.SortOrder, -1) AS gsort
           FROM morningChecks_Checks c
      LEFT JOIN morningChecks_Groups g ON g.GroupID = c.GroupID
          ORDER BY gsort, g.GroupName, c.SortOrder, c.CheckName")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($checks as $c) {
        $id = (int)$c['CheckID'];
        $mine = $results[$id] ?? [];
        if ((int)$c['IsActive'] !== 1 && !$mine) continue;
        $missed = [];
        if ((int)$c['IsActive'] === 1 && $from <= $to) {
            $d = new DateTimeImmutable(max($from, (string)$c['made']));
            $end = new DateTimeImmutable($to);
            for (; $d <= $end; $d = $d->modify('+1 day')) {
                $ymd = $d->format('Y-m-d');
                if (!isset($mine[$ymd]) && MorningCheckSchedule::isDue($c, $ymd)) $missed[] = $ymd;
            }
        }
        $out[$id] = ['check' => $c, 'results' => $mine, 'missed' => $missed];
    }
    return $out;
}

/** A result's status label: the status's own, else the label kept on the result (a status since deleted). */
function rpChecksStatusLabel(array $statuses, array $r): string
{
    $sid = $r['StatusID'] !== null ? (int)$r['StatusID'] : 0;
    return $statuses[$sid]['label'] ?? (string)($r['Status'] ?? '');
}

/**
 * Table: one row per check - how many days it was given each status, and the due
 * days nobody checked it.
 *
 * @param array $o  the block's cleaned options: group (show each check's group)
 * @param array $range  the pack's range (rpResolveRange)
 * @param mixed $tenant  not used - Morning Checks are not split by company
 */
function rpChecksSummary(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $statuses = rpChecksStatuses($conn);
    $period = rpChecksPeriod($conn, $range);
    // A column for each active status, and for any other status the period used.
    $used = [];
    foreach ($period as $p) foreach ($p['results'] as $r) $used[(int)$r['StatusID']] = true;
    $cols = [['key' => 'check', 'label' => t('reporting.packs.checks.col_check'), 'w' => $o['group'] ? 26 : 38]];
    if ($o['group']) $cols[] = ['key' => 'group', 'label' => t('reporting.packs.checks.col_group'), 'w' => 16];
    $shown = [];
    foreach ($statuses as $sid => $s) {
        if (!$s['active'] && empty($used[$sid])) continue;
        $shown[] = $sid;
        $cols[] = ['key' => 's' . $sid, 'label' => $s['label'], 'w' => 10, 'align' => 'right'];
    }
    $orphans = !empty($used[0]);
    if ($orphans) $cols[] = ['key' => 's0', 'label' => t('reporting.packs.checks.other_status'), 'w' => 10, 'align' => 'right'];
    $cols[] = ['key' => 'missed', 'label' => t('reporting.packs.checks.not_checked'), 'w' => 12, 'align' => 'right'];

    $rows = [];
    foreach ($period as $p) {
        $n = array_fill_keys(array_merge($shown, $orphans ? [0] : []), 0);
        foreach ($p['results'] as $r) {
            $sid = $r['StatusID'] !== null && isset($statuses[(int)$r['StatusID']]) ? (int)$r['StatusID'] : 0;
            if (isset($n[$sid])) $n[$sid]++;
        }
        $row = ['check' => (string)$p['check']['CheckName']];
        if ($o['group']) $row['group'] = (string)($p['check']['GroupName'] ?? '');
        foreach ($n as $sid => $v) $row['s' . $sid] = $v ? (string)$v : '-';
        $row['missed'] = $p['missed'] ? (string)count($p['missed']) : '-';
        $rows[] = $row;
    }
    return ['kind' => 'table', 'rows' => $rows, 'columns' => $cols, 'empty' => t('reporting.packs.checks.empty')];
}

/**
 * Table: the days that need a look - every result whose status asks for notes,
 * with the notes, and (optionally) the due days nobody checked. Oldest first.
 *
 * @param array $o  the block's cleaned options: not_checked (include due days with no result), limit
 */
function rpChecksExceptions(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $statuses = rpChecksStatuses($conn);
    $items = [];
    foreach (rpChecksPeriod($conn, $range) as $p) {
        $name = (string)$p['check']['CheckName'];
        foreach ($p['results'] as $day => $r) {
            $sid = $r['StatusID'] !== null ? (int)$r['StatusID'] : 0;
            if (empty($statuses[$sid]['notes'])) continue;
            $notes = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$r['Notes']), ENT_QUOTES, 'UTF-8')));
            $items[] = [$day, $name, rpChecksStatusLabel($statuses, $r), mb_strimwidth($notes, 0, 400, '…'), (string)($r['who'] ?? '')];
        }
        if ($o['not_checked']) foreach ($p['missed'] as $day) $items[] = [$day, $name, t('reporting.packs.checks.not_checked'), '', ''];
    }
    usort($items, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $more = count($items) > $o['limit'];
    $rows = array_map(fn($i) => ['day' => rpFmtDate($i[0]), 'check' => $i[1], 'status' => $i[2], 'notes' => $i[3], 'who' => $i[4]],
                      array_slice($items, 0, $o['limit']));
    return ['kind' => 'table', 'rows' => $rows,
            'columns' => [
                ['key' => 'day',    'label' => t('reporting.packs.checks.col_day'),    'w' => 13],
                ['key' => 'check',  'label' => t('reporting.packs.checks.col_check'),  'w' => 24],
                ['key' => 'status', 'label' => t('reporting.packs.checks.col_status'), 'w' => 12],
                ['key' => 'notes',  'label' => t('reporting.packs.checks.col_notes'),  'w' => 37],
                ['key' => 'who',    'label' => t('reporting.packs.checks.col_who'),    'w' => 14],
            ],
            'note'  => $more ? t('reporting.packs.truncated', ['n' => $o['limit']]) : null,
            'empty' => t('reporting.packs.checks.empty_exceptions')];
}

/**
 * Chart: the period's checks by status, in each status's own colour, with the due
 * days nobody checked as a grey slice.
 *
 * @param array $o  the block's cleaned options: chart, not_checked
 */
function rpChecksByStatus(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $statuses = rpChecksStatuses($conn);
    $n = []; $missed = 0;
    foreach (rpChecksPeriod($conn, $range) as $p) {
        foreach ($p['results'] as $r) {
            $label = rpChecksStatusLabel($statuses, $r);
            $sid = $r['StatusID'] !== null ? (int)$r['StatusID'] : 0;
            $n[$label] ??= ['label' => $label, 'value' => 0, 'colour' => $statuses[$sid]['colour'] ?? null, 'order' => array_search($sid, array_keys($statuses), true)];
            $n[$label]['value']++;
        }
        $missed += count($p['missed']);
    }
    $rows = array_values($n);
    usort($rows, fn($a, $b) => ($a['order'] === false ? 999 : $a['order']) <=> ($b['order'] === false ? 999 : $b['order']));
    if ($o['not_checked'] && $missed) $rows[] = ['label' => t('reporting.packs.checks.not_checked'), 'value' => $missed, 'colour' => '#94a3b8'];
    return rpChartFromRows($rows, 50, $o['chart'], t('reporting.packs.checks.series'));
}
