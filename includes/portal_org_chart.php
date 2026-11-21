<?php
/**
 * The organisation chart on the self-service portal (GH #414).
 *
 * The SAME chart as People -> Organisation (people/org.php): the markup is
 * includes/org_chart_view.php, the script assets/js/people-org.js, the queries the
 * peopleOrg*() functions in includes/people.php - asked as a PeopleOrgReader instead
 * of an analyst, through api/self-service/org.php. What differs is decided there and here:
 *
 *   - OFF unless an administrator switches it on (System -> Self-service portal),
 *     for everybody or only for members of chosen People groups - the same shape
 *     promised for portal People lookups on discussion #330. Internal IT wants it;
 *     a service provider whose portal serves many customers must not get it by surprise.
 *   - Only the reader's OWN company, and only current staff.
 *   - Name, job title, department and who reports to whom - no side panel of
 *     contact details, no gaps, no leavers box. No email, phone, office
 *     or employee number - things a colleague already knows, nothing more. The one
 *     exception: somebody with no name is shown by their email (peopleName()), so
 *     the gap is visible and gets fixed rather than the person silently missing.
 *
 * TRAP: the page, the nav item and the endpoint all ask portalOrgChartAllowed().
 * A hidden tab is not a permission - the endpoint is the rule.
 */

require_once __DIR__ . '/self_service_settings.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/module_availability.php';
require_once __DIR__ . '/people.php';   // the chart's queries, PeopleOrgReader

/**
 * May this portal user see the organisation chart?
 *
 * Fails closed: the People module switched off, the setting off, a group rule the
 * person is not in, or any error - all no.
 *
 * @param int $userId  users.id of the signed-in portal user
 */
function portalOrgChartAllowed(PDO $conn, int $userId): bool
{
    try {
        if ($userId <= 0 || !moduleIsEnabled($conn, 'people')) return false;
        $s = selfServicePortalSettings($conn);
        if ($s['org_chart'] === 'all') return true;
        if ($s['org_chart'] !== 'groups' || !$s['org_chart_groups']) return false;
        $ids = array_map('intval', $s['org_chart_groups']);
        $st = $conn->prepare(
            "SELECT 1 FROM directory_group_members m JOIN directory_groups g ON g.id = m.group_id AND g.is_active = 1
              WHERE m.user_id = ? AND m.group_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") LIMIT 1"
        );
        $st->execute(array_merge([$userId], $ids));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('portal_org_chart: ' . $e->getMessage());
        return false;
    }
}

/**
 * The search box: up to 10 current people in the reader's company whose name, job
 * title or department contains $q. Somebody with no name is found, and named, by
 * their email - the rule the chart itself follows (peopleName()).
 *
 * @param string $q  what was typed; under 2 characters finds nobody
 * @return array [['id','name','job_title','department'], ...]
 */
function portalOrgChartSearch(PDO $conn, PeopleOrgReader $reader, string $q): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $like = likeContainsPattern($q);
    [$scope, $args] = peopleOrgScope($conn, $reader, 'u.tenant_id');
    $st = $conn->prepare(
        "SELECT u.id, u.display_name, u.preferred_name, u.email, u.username, u.job_title, u.department
           FROM users u
          WHERE u.is_active = 1$scope
            AND (u.preferred_name LIKE ? OR u.display_name LIKE ? OR u.job_title LIKE ? OR u.department LIKE ?
                 OR (COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, '')) IS NULL AND u.email LIKE ?))
          ORDER BY COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email)
          LIMIT 10"
    );
    $st->execute(array_merge($args, [$like, $like, $like, $like, $like]));
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => peopleName($r), 'job_title' => $r['job_title'], 'department' => $r['department']],
        $st->fetchAll(PDO::FETCH_ASSOC));
}
