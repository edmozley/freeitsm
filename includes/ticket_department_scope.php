<?php
/**
 * Which departments' tickets an analyst may see - applied wherever tickets are read.
 *
 * THE RULE (one place; the folders, the counts, the list, search and every
 * single-ticket endpoint all use it):
 *
 *   - An analyst in NO team, or an administrator, is unrestricted.
 *   - Otherwise an analyst sees a ticket when ANY of these is true:
 *       its department is one of their teams' departments (department_teams),
 *       it has no department,
 *       they own it (owner_id),
 *       it is assigned to one of their teams (assigned_team_id),
 *       they are watching it (ticket_watchers).
 *
 * The last three keep escalation working: a ticket handed to someone outside the
 * department must never disappear from the person now responsible for it.
 *
 * TRAP: this sits BESIDE ticketTenantFilter(), not inside it. That helper is
 * also used on tables that have no department (problems, alias 'p'), so the
 * department clause is added only at queries that read the tickets table.
 *
 * @wiki Security
 */

/**
 * The analyst's department scope, or null when unrestricted.
 *
 * @return ?array{departments: int[], teams: int[]}
 */
function ticketDepartmentScope(PDO $conn, int $analystId): ?array
{
    static $cache = [];
    if ($analystId <= 0) return null;   // internal callers (cron, workflow) - no analyst to narrow by
    if (array_key_exists($analystId, $cache)) return $cache[$analystId];
    try {
        $st = $conn->prepare("SELECT is_admin FROM analysts WHERE id = ?");
        $st->execute([$analystId]);
        if ((int)$st->fetchColumn() === 1) return $cache[$analystId] = null;
        $st = $conn->prepare("SELECT team_id FROM analyst_teams WHERE analyst_id = ?");
        $st->execute([$analystId]);
        $teams = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$teams) return $cache[$analystId] = null;
        $in = implode(',', $teams);   // ints from the database
        $depts = array_map('intval', $conn->query("SELECT DISTINCT department_id FROM department_teams WHERE team_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
        return $cache[$analystId] = ['departments' => $depts, 'teams' => $teams];
    } catch (Exception $e) {
        // A table missing on a part-migrated install: the teams feature is not
        // there to restrict by. Anything else is a real failure - fail closed.
        if (str_contains($e->getMessage(), "doesn't exist")) return $cache[$analystId] = null;
        throw $e;
    }
}

/**
 * The SQL clause scoping ticket rows to the analyst's departments.
 *
 * @param string $alias  the tickets table's alias in the calling query; '' for the bare table name
 * @param string $named  '' for ? placeholders; otherwise a prefix for NAMED ones (:{prefix}o, :{prefix}w),
 *                       for queries that already use named parameters
 * @return array [sqlFragment, params] - append to a WHERE clause, merge the params
 */
function ticketDepartmentScopeSql(PDO $conn, int $analystId, string $alias = 't', string $named = ''): array
{
    $scope = ticketDepartmentScope($conn, $analystId);
    if ($scope === null) return ['', []];
    $p = $alias === '' ? 'tickets.' : "$alias.";
    $ph1 = $named === '' ? '?' : ":{$named}o";
    $ph2 = $named === '' ? '?' : ":{$named}w";
    $or = ["{$p}department_id IS NULL", "{$p}owner_id = $ph1"];
    $params = $named === '' ? [$analystId] : [":{$named}o" => $analystId];
    if ($scope['departments']) $or[] = "{$p}department_id IN (" . implode(',', $scope['departments']) . ")";
    $or[] = "{$p}assigned_team_id IN (" . implode(',', $scope['teams']) . ")";
    $or[] = "EXISTS (SELECT 1 FROM ticket_watchers tds_w WHERE tds_w.ticket_id = {$p}id AND tds_w.analyst_id = $ph2)";
    if ($named === '') $params[] = $analystId; else $params[":{$named}w"] = $analystId;
    return [' AND (' . implode(' OR ', $or) . ')', $params];
}

/**
 * May the analyst see this one ticket, by department? (The company check is
 * separate - analystCanAccessTicket() does both.)
 *
 * @param mixed $ticketId  the ticket id, any form
 */
function ticketDepartmentAllows(PDO $conn, int $analystId, $ticketId): bool
{
    if (ticketDepartmentScope($conn, $analystId) === null) return true;
    [$sql, $params] = ticketDepartmentScopeSql($conn, $analystId, 't');
    $st = $conn->prepare("SELECT 1 FROM tickets t WHERE t.id = ?" . $sql);
    $st->execute(array_merge([(int)$ticketId], $params));
    return (bool)$st->fetchColumn();
}

/**
 * The company AND department clauses together, for a query on the tickets table.
 *
 * @param string $alias  the tickets table's alias; '' for the bare table name
 * @param bool $forceSingle  as ticketTenantFilter()
 */
function ticketScopeFilter(PDO $conn, int $analystId, string $alias = 't', bool $forceSingle = false): array
{
    [$tSql, $tParams] = ticketTenantFilter($conn, $analystId, $alias, $forceSingle);
    [$dSql, $dParams] = ticketDepartmentScopeSql($conn, $analystId, $alias);
    return [$tSql . $dSql, array_merge($tParams, $dParams)];
}
