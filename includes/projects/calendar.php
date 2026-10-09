<?php
/**
 * Projects - the dates that matter, as all-day Calendar entries (3.2.0). Three kinds:
 *
 *   project_end        a project's target end date    "Project due: NAME (PRJ-0042)"
 *   project_stage      each open stage's end date     "Stage ends: STAGE - NAME"
 *   project_milestone  each milestone not yet reached "Milestone: MILESTONE - NAME" (3.3.0)
 *
 * The same machinery as warranties, software renewals and Domains, reused rather
 * than copied (Warranty-and-Lease-Alerts-Developer-Guide on the wiki):
 *
 *  - 🔑 EACH KIND HAS ITS OWN `source` MARKER. Sync clears and rebuilds one
 *    marker at a time, so nothing a person typed into the Calendar is touched,
 *    and switching stage ends off removes only stage entries.
 *
 *  - Both kinds file into ONE category, "Projects", found by name the first
 *    time and remembered by id after that (`<source>_category_id`), so an
 *    operator can rename it into their own language and the next sync keeps
 *    using it. Adoption runs BEFORE the delete, because the entries about to be
 *    cleared are the only record of which category they were in.
 *
 *  - A full resync after every change that can move a date. Projects are few
 *    and stages fewer; a per-project update would need a reference column that
 *    calendar_events does not have.
 *
 * Only LIVE plans are drawn: a proposed or active project. A closed or
 * cancelled one's dates are history, and an on-hold one's are known to be
 * wrong until it restarts. A stage is drawn until it closes.
 *
 * ⚠️ Calendar entries have no company. On a multi-company install every
 * analyst who can open the Calendar sees every project's dates, as they already
 * see contract and warranty dates - the Projects help page says so.
 *
 * ⚠️ The titles are stored rows, so they are English, as the warranty and
 * Domains entries are. The category name is the part an operator can change.
 */

require_once __DIR__ . '/../asset_warranty_calendar.php';   // awcEnsureCategory / awcAdoptExistingCategory / awcColumnExists
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/read.php';                          // projectCode()

/**
 * Redraw both kinds. Never throws: a calendar that is out of step must never
 * stop a project being saved.
 */
function projectSyncCalendar(PDO $conn): array
{
    try {
        if (!awcColumnExists($conn, 'calendar_events', 'source') || !awcColumnExists($conn, 'project_stages', 'end_date')) {
            return ['success' => false, 'error' => 'Schema not ready'];
        }
        // off | ends | all - Projects -> Settings -> General.
        $mode = projectSettings($conn, true)['project_calendar'] ?? 'all';
        $live = "p.status IN ('proposed', 'active')";

        $ends = projectSyncCalendarKind($conn, 'project_end', in_array($mode, ['ends', 'all'], true),
            "SELECT p.id, p.name, p.target_end_date AS on_date
               FROM projects p
              WHERE p.target_end_date IS NOT NULL AND $live",
            fn($r) => 'Project due: ' . $r['name'] . ' (' . projectCode((int)$r['id']) . ')',
            fn($r) => 'Auto-generated from Projects (' . projectCode((int)$r['id']) . '). Change the target end date on the project to move this.');

        $kinds = ['phase' => 'Phase', 'stage' => 'Stage', 'sprint' => 'Sprint'];
        $stages = projectSyncCalendarKind($conn, 'project_stage', $mode === 'all',
            "SELECT p.id, p.name, s.name AS stage_name, s.kind, s.end_date AS on_date
               FROM project_stages s
               JOIN projects p ON p.id = s.project_id
              WHERE s.end_date IS NOT NULL AND s.status <> 'closed' AND $live",
            fn($r) => ($kinds[$r['kind']] ?? 'Stage') . ' ends: ' . $r['stage_name'] . ' - ' . $r['name'],
            fn($r) => 'Auto-generated from Projects (' . projectCode((int)$r['id']) . '). Change the end date on the project\'s plan to move this; closing the '
                     . strtolower($kinds[$r['kind']] ?? 'stage') . ' removes it.');

        // Milestones (3.3.0) - with stage ends, under "all". Drawn until reached.
        $milestones = 0;
        require_once __DIR__ . '/milestones.php';
        if (projectMilestonesReady($conn)) {
            $milestones = projectSyncCalendarKind($conn, 'project_milestone', $mode === 'all',
                "SELECT p.id, p.name, m.name AS milestone_name, m.due_date AS on_date
                   FROM project_milestones m
                   JOIN projects p ON p.id = m.project_id
                  WHERE m.done_date IS NULL AND $live",
                fn($r) => 'Milestone: ' . $r['milestone_name'] . ' - ' . $r['name'],
                fn($r) => 'Auto-generated from Projects (' . projectCode((int)$r['id']) . '). Move the milestone on the project\'s Timeline to move this; marking it reached removes it.');
        }

        return ['success' => true, 'synced' => $ends, 'synced_stages' => $stages, 'synced_milestones' => $milestones];
    } catch (Throwable $e) {
        error_log('projects calendar sync: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** One kind: adopt its category, clear its entries, and - when on - draw one all-day entry per row. */
function projectSyncCalendarKind(PDO $conn, string $source, bool $on, string $sql, callable $title, callable $description): int
{
    awcAdoptExistingCategory($conn, $source);                       // BEFORE the delete - see its comment
    $conn->prepare("DELETE FROM calendar_events WHERE source = ?")->execute([$source]);
    if (!$on) return 0;

    $cat = awcEnsureCategory($conn, $source, 'Projects', '#f43f5e');   // the module's coral
    $ins = $conn->prepare(
        "INSERT INTO calendar_events (title, description, category_id, start_datetime, end_datetime, all_day, created_by, source)
         VALUES (?, ?, ?, ?, ?, 1, 0, ?)"
    );
    $n = 0;
    foreach ($conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dt = substr((string)$r['on_date'], 0, 10) . ' 00:00:00';
        $ins->execute([mb_substr($title($r), 0, 255), $description($r), $cat, $dt, $dt, $source]);
        $n++;
    }
    return $n;
}
