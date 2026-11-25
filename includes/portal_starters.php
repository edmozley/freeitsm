<?php
/**
 * Starters & leavers cases on the self-service portal (5.11.0). Asked for by a
 * supporter of the project: HR staff, who are portal users rather than analysts,
 * want to see how a new starter's or a leaver's case is coming along.
 *
 *   - OFF unless an administrator switches it on (System -> Self-service portal),
 *     and then ONLY for members of chosen People groups - there is no "everyone",
 *     unlike the organisation chart: a leaver's case can be a dismissal, and the
 *     portal is where the whole company signs in.
 *   - Only the reader's OWN company's cases (a case with no company belongs to the
 *     Default company, the same rule as every list).
 *   - Read-only, and only what HR needs to know: the person, starter / mover /
 *     leaver, job title, the date, how many steps are done, the status and who owns
 *     it. Not the notes, not the steps, not the kit, not the history - those are the
 *     IT team's working record.
 *   - Opening a case from the portal is already possible: a form in the request
 *     catalogue with the "Open a Starters & leavers case" action.
 *
 * TRAP: the page, the nav item and the endpoint all ask portalStartersAllowed().
 * A hidden tab is not a permission - the endpoint is the rule.
 */

require_once __DIR__ . '/self_service_settings.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/module_availability.php';
require_once __DIR__ . '/people.php';               // PeopleOrgReader, peopleCompanyMatch()
require_once __DIR__ . '/starters/service.php';     // StartersService::progressSql(), ready()

/** How long a finished case stays on the Recently done tab. */
const PORTAL_STARTERS_DONE_DAYS = 60;

/**
 * May this portal user see Starters & leavers cases?
 *
 * Fails closed: the module switched off, the setting off, no groups chosen, a group
 * rule the person is not in, the tables not there yet, or any error - all no.
 *
 * @param int $userId  users.id of the signed-in portal user
 */
function portalStartersAllowed(PDO $conn, int $userId): bool
{
    try {
        if ($userId <= 0 || !moduleIsEnabled($conn, 'starters') || !StartersService::ready($conn)) return false;
        $s = selfServicePortalSettings($conn);
        if ($s['jml_cases'] !== 'groups' || !$s['jml_cases_groups']) return false;
        $ids = array_map('intval', $s['jml_cases_groups']);
        $st = $conn->prepare(
            "SELECT 1 FROM directory_group_members m JOIN directory_groups g ON g.id = m.group_id AND g.is_active = 1
              JOIN users u ON u.id = m.user_id AND u.is_active = 1
              WHERE m.user_id = ? AND m.group_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") LIMIT 1"
        );
        $st->execute(array_merge([$userId], $ids));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('portal_starters: ' . $e->getMessage());
        return false;
    }
}

/**
 * The cases the reader may see: open ones, or those finished in the last
 * PORTAL_STARTERS_DONE_DAYS days. Cancelled cases are never shown. The caller must
 * have asked portalStartersAllowed() first.
 *
 * @param int $userId  users.id of the signed-in portal user
 * @param string $view  'open' (soonest date first) or 'done' (latest date first)
 * @return array{cases: array, counts: array{open:int, done:int}}
 */
function portalStartersCases(PDO $conn, int $userId, string $view): array
{
    $reader = PeopleOrgReader::portal($conn, $userId);
    [$scope, $args] = peopleCompanyMatch($conn, $reader->tenantId, 'c.tenant_id');
    $doneSince = gmdate('Y-m-d H:i:s', time() - PORTAL_STARTERS_DONE_DAYS * 86400);
    $openWhere = "c.status = 'open'";
    $doneWhere = "c.status = 'done' AND c.completed_datetime >= ?";

    $counts = [];
    foreach (['open' => [$openWhere, []], 'done' => [$doneWhere, [$doneSince]]] as $k => [$w, $a]) {
        $st = $conn->prepare("SELECT COUNT(*) FROM jml_cases c WHERE $w$scope");
        $st->execute(array_merge($a, $args));
        $counts[$k] = (int)$st->fetchColumn();
    }

    $done = $view === 'done';
    $st = $conn->prepare(
        "SELECT c.id, c.kind, c.person_name, c.job_title, c.effective_date, c.status, c.completed_datetime,
                a.full_name AS owner_name, " . StartersService::progressSql($conn) . "
           FROM jml_cases c
      LEFT JOIN analysts a ON a.id = c.owner_analyst_id
          WHERE " . ($done ? $doneWhere : $openWhere) . "$scope
          ORDER BY c.effective_date " . ($done ? 'DESC' : 'ASC') . ", c.id
          LIMIT 500");
    $st->execute(array_merge($done ? [$doneSince] : [], $args));
    $cases = array_map(fn($r) => [
        'id'             => (int)$r['id'],
        'kind'           => (string)$r['kind'],
        'person_name'    => (string)$r['person_name'],
        'job_title'      => $r['job_title'] !== null ? (string)$r['job_title'] : null,
        'effective_date' => (string)$r['effective_date'],
        'status'         => (string)$r['status'],
        'owner_name'     => $r['owner_name'] !== null ? (string)$r['owner_name'] : null,
        'steps_total'    => (int)$r['steps_total'],
        'steps_done'     => (int)$r['steps_done'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    return ['cases' => $cases, 'counts' => $counts];
}
