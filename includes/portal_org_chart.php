<?php
/**
 * The organisation chart on the self-service portal (GH #414).
 *
 * The People module's chart (people/org.php) is for analysts: it crosses companies,
 * shows leavers and opens a contact panel. This is the portal's own, deliberately
 * smaller view of the same reporting lines:
 *
 *   - OFF unless an administrator switches it on (System -> Self-service portal),
 *     for everybody or only for members of chosen People groups - the same shape
 *     promised for portal People lookups on discussion #330. Internal IT wants it;
 *     a service provider whose portal serves many customers must not get it by surprise.
 *   - Only the reader's OWN company, and only current staff.
 *   - Name, job title, department and who reports to whom. No email, phone, office
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
require_once __DIR__ . '/people.php';   // peopleName()

/** The most people one chart returns. Past this the chart says it is incomplete. */
const PORTAL_ORG_CHART_MAX = 5000;

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
 * Everybody on the reader's chart: current staff in the reader's own company.
 *
 * On a multi-company install a person with no company counts as the Default
 * company's, the rule everywhere else (peopleCompanyMatch()).
 *
 * @param int $userId  users.id of the signed-in portal user
 * @return array ['people' => [['id','name','job_title','department','manager_id'], ...], 'truncated' => bool]
 */
function portalOrgChartPeople(PDO $conn, int $userId): array
{
    $where = 'u.is_active = 1';
    $args = [];
    if (isMultiTenant($conn)) {
        $st = $conn->prepare("SELECT tenant_id FROM users WHERE id = ?");
        $st->execute([$userId]);
        $default = getDefaultTenantId($conn);
        $tenant = (int)($st->fetchColumn() ?: 0) ?: $default;
        $where .= $tenant === $default ? ' AND (u.tenant_id = ? OR u.tenant_id IS NULL)' : ' AND u.tenant_id = ?';
        $args[] = $tenant;
    }
    $st = $conn->prepare(
        "SELECT u.id, u.display_name, u.preferred_name, u.email, u.username, u.job_title, u.department, u.manager_id
           FROM users u
          WHERE $where
          ORDER BY COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email, u.username)
          LIMIT " . (PORTAL_ORG_CHART_MAX + 1)
    );
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $truncated = count($rows) > PORTAL_ORG_CHART_MAX;
    $rows = array_slice($rows, 0, PORTAL_ORG_CHART_MAX);

    $people = [];
    foreach ($rows as $r) {
        // Everybody is shown, even with no name: then peopleName() falls back to
        // their email, username or #id, and the box itself says there is a record
        // to fix (Ed, 2026-11-20). Nobody else's email ever goes out.
        $people[] = [
            'id' => (int)$r['id'], 'name' => peopleName($r),
            'job_title' => $r['job_title'] !== null && trim($r['job_title']) !== '' ? trim($r['job_title']) : null,
            'department' => $r['department'] !== null && trim($r['department']) !== '' ? trim($r['department']) : null,
            'manager_id' => $r['manager_id'] !== null ? (int)$r['manager_id'] : null,
        ];
    }
    return ['people' => $people, 'truncated' => $truncated];
}
