<?php
/**
 * Members-only projects (3.3.0) - the ONE place that decides who may see a
 * project beyond its company.
 *
 * projects.visibility is 'everyone' (the default: anybody who can open Projects
 * in the project's company, as before) or 'members'. A members-only project is
 * seen only by its team - the project manager, whoever created it, and its
 * members, directly or through a team (projectIsTeam's rule) - and by people
 * who manage Projects (admins always do).
 *
 * 🔑 Everything that lists or opens projects asks here: the portfolio
 * (projectListRows), the project page (ProjectsService::loadForActor), global
 * search, People, the recent trail, Documents, Watchtower, Warbot, MCP, the
 * REST API and Report Packs. A project somebody may not see is NOT FOUND to
 * them, never "hidden". The shared Calendar never shows a members-only
 * project's dates; Capacity still counts its work (people's load is real) but
 * names it only to those who may see it.
 *
 * What it does not hide: tasks stay Tasks records, seen by whoever Tasks lets
 * see them (an assignee who is not a member still sees their task).
 *
 * A system actor (analyst id 0 - scheduled work, demo import) sees everything
 * unless the caller says otherwise (Report Packs do: a pack never carries a
 * members-only project unless the person it is built for may see it).
 */

require_once __DIR__ . '/settings.php';

if (!defined('PROJECT_VISIBILITY_LOADED')) {
    define('PROJECT_VISIBILITY_LOADED', true);

    function projectVisibilities(): array
    {
        return ['everyone', 'members'];
    }

    /** Has Database Verification added projects.visibility? Before it, everything is visible. */
    function projectVisibilityReady(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try { $conn->query("SELECT visibility FROM projects LIMIT 0"); $ready = true; }
            catch (Throwable $e) { $ready = false; }
        }
        return $ready;
    }

    /** p.visibility, or 'everyone' before Verification - for a SELECT list. */
    function projectVisibilityColumn(PDO $conn, string $alias = 'p'): string
    {
        return projectVisibilityReady($conn) ? "$alias.visibility" : "'everyone' AS visibility";
    }

    /** May this analyst see every members-only project? (Manage Projects; admins.) */
    function projectSeesAllPrivate(PDO $conn, int $analystId): bool
    {
        static $cache = [];
        if (!isset($cache[$analystId])) $cache[$analystId] = projectIsManager($conn, $analystId);
        return $cache[$analystId];
    }

    /**
     * " AND (...)" limiting $alias to the projects this analyst may see, with its
     * arguments. Empty when nothing is hidden from them.
     */
    function projectVisibleSql(PDO $conn, int $analystId, string $alias = 'p', bool $systemSeesAll = true): array
    {
        if (!projectVisibilityReady($conn)) return ['', []];
        if ($analystId <= 0) return $systemSeesAll ? ['', []] : [" AND $alias.visibility <> 'members'", []];
        if (projectSeesAllPrivate($conn, $analystId)) return ['', []];
        return [" AND ($alias.visibility <> 'members' OR $alias.owner_analyst_id = ? OR $alias.created_by_id = ?
                     OR EXISTS (SELECT 1 FROM project_members vm
                                LEFT JOIN analyst_teams vat ON vat.team_id = vm.team_id AND vat.analyst_id = ?
                                    WHERE vm.project_id = $alias.id AND (vm.analyst_id = ? OR vat.analyst_id IS NOT NULL)))",
                [$analystId, $analystId, $analystId, $analystId]];
    }

    /** May this analyst see this project row (needs id, owner_analyst_id, created_by_id, visibility)? */
    function projectVisibleTo(PDO $conn, int $analystId, array $project): bool
    {
        if (($project['visibility'] ?? 'everyone') !== 'members') return true;
        if ($analystId <= 0) return true;
        return projectSeesAllPrivate($conn, $analystId) || projectIsTeam($conn, $analystId, $project);
    }

    /** The same, by id - for callers holding only an id (Documents, the recent trail). */
    function projectIdVisibleTo(PDO $conn, int $analystId, int $projectId): bool
    {
        if (!projectVisibilityReady($conn)) return true;
        $st = $conn->prepare("SELECT id, owner_analyst_id, created_by_id, visibility FROM projects WHERE id = ?");
        $st->execute([$projectId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? projectVisibleTo($conn, $analystId, $row) : false;
    }
}
