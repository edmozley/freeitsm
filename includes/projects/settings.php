<?php
/**
 * Projects - settings: the keys, their defaults, the one reader, and the
 * permission rules they drive (3.2.0).
 *
 * Every key lives in `system_settings` and is written only by
 * api/projects/settings.php, which validates it and checks the capability of
 * the tab it belongs to (projects/settings/manifest.php explains why the
 * generic settings writer may not touch these).
 *
 * 🔑 THE DEFAULTS ARE THE BEHAVIOUR PHASE 1 SHIPPED WITH, except one: changing a
 * project defaults to its TEAM (project manager, creator, members, Projects
 * managers) rather than everyone with the module - Ed's call, 2026-10-06, because
 * a project page is somebody's plan and the delete button sat on it.
 */

require_once __DIR__ . '/methodologies.php';

if (!defined('PROJECT_SETTINGS_LOADED')) {
    define('PROJECT_SETTINGS_LOADED', true);

    /** key => [default, validator, tab]. */
    function projectSettingDefinitions(): array
    {
        return [
            // ---- General ----------------------------------------------------
            'project_default_method'   => ['simple',   'method',      'general'],
            // Who may create a project: anyone who can open Projects, or only
            // those holding Manage Projects.
            'project_create_policy'    => ['anyone',   'create',      'general'],
            // Who may change one: everyone who can open Projects, or its team.
            'project_change_policy'    => ['team',     'change',      'general'],
            // ---- Health -----------------------------------------------------
            // Amber when the target is this close and less than this share is done.
            'project_amber_days'       => ['14',       'int:1:120',   'health'],
            'project_amber_progress'   => ['75',       'int:1:100',   'health'],
            // Red when this share (%) or more of the open work is overdue.
            'project_red_overdue_pct'  => ['25',       'int:1:100',   'health'],
            // ---- RAID -------------------------------------------------------
            // Five labels each, lowest first.
            'project_probability_labels' => ['Rare,Unlikely,Possible,Likely,Almost certain', 'labels5', 'raid'],
            'project_impact_labels'      => ['Negligible,Minor,Moderate,Major,Severe',       'labels5', 'raid'],
        ];
    }

    /** Every setting with its default applied. Cached; $fresh after a save. */
    function projectSettings(PDO $conn, bool $fresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$fresh) return $cache;
        $out = [];
        foreach (projectSettingDefinitions() as $k => $d) $out[$k] = $d[0];
        try {
            $keys = array_keys($out);
            $in = implode(',', array_fill(0, count($keys), '?'));
            $st = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
            $st->execute($keys);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['setting_value'] !== null && $r['setting_value'] !== '') $out[$r['setting_key']] = (string)$r['setting_value'];
            }
        } catch (Throwable $e) {
            // Before Database Verification: the defaults.
        }
        return $cache = $out;
    }

    function projectSetting(PDO $conn, string $key): string
    {
        return (string)(projectSettings($conn)[$key] ?? '');
    }

    function projectSettingWrite(PDO $conn, string $key, string $value): void
    {
        $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
             VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()"
        )->execute([$key, $value]);
    }

    /** Validate one incoming value; throws InvalidArgumentException with a message fit to show. */
    function projectSettingValidate(string $key, $raw): string
    {
        $defs = projectSettingDefinitions();
        if (!isset($defs[$key])) throw new InvalidArgumentException("Unknown setting: $key");
        $rule = $defs[$key][1];
        $v = trim((string)$raw);
        if ($rule === 'method') {
            if (!isset(projectMethodologies()[$v])) throw new InvalidArgumentException('Choose a way of running projects.');
            return $v;
        }
        if ($rule === 'create') {
            if (!in_array($v, ['anyone', 'managers'], true)) throw new InvalidArgumentException('Choose who may create projects.');
            return $v;
        }
        if ($rule === 'change') {
            if (!in_array($v, ['anyone', 'team'], true)) throw new InvalidArgumentException('Choose who may change a project.');
            return $v;
        }
        if (strpos($rule, 'int:') === 0) {
            [, $min, $max] = explode(':', $rule);
            if (!preg_match('/^\d+$/', $v) || (int)$v < (int)$min || (int)$v > (int)$max) {
                throw new InvalidArgumentException("Enter a whole number from $min to $max.");
            }
            return (string)(int)$v;
        }
        if ($rule === 'labels5') {
            $parts = array_values(array_filter(array_map('trim', explode(',', $v)), fn($s) => $s !== ''));
            if (count($parts) !== 5) throw new InvalidArgumentException('Give exactly five labels, lowest first, separated by commas.');
            foreach ($parts as $p) if (mb_strlen($p) > 40) throw new InvalidArgumentException('Each label must be 40 characters or fewer.');
            return implode(',', $parts);
        }
        throw new InvalidArgumentException("Unknown setting: $key");
    }

    // ======================================================================
    //  Permissions (the General tab's rules, applied everywhere)
    // ======================================================================

    /** Holds Manage Projects (admins always do - analystHasCapability short-circuits is_admin). */
    function projectIsManager(PDO $conn, int $analystId): bool
    {
        if ($analystId <= 0) return false;
        try {
            require_once __DIR__ . '/../rbac.php';
            return analystHasCapability($conn, $analystId, Cap::PROJECTS_MANAGE);
        } catch (Throwable $e) {
            return false;
        }
    }

    function projectCanCreate(PDO $conn, int $analystId): bool
    {
        return projectSetting($conn, 'project_create_policy') === 'anyone' || projectIsManager($conn, $analystId);
    }

    /**
     * Is this analyst on the project's team: its manager, its creator or a
     * member? (Members arrive with project_members; until that table exists the
     * team is the manager and the creator.)
     */
    function projectIsTeam(PDO $conn, int $analystId, array $project): bool
    {
        if ($analystId <= 0) return false;
        if ((int)($project['owner_analyst_id'] ?? 0) === $analystId || (int)($project['created_by_id'] ?? 0) === $analystId) return true;
        try {
            $st = $conn->prepare("SELECT 1 FROM project_members m
                                   LEFT JOIN analyst_teams at ON at.team_id = m.team_id AND at.analyst_id = ?
                                   WHERE m.project_id = ? AND (m.analyst_id = ? OR at.analyst_id IS NOT NULL) LIMIT 1");
            $st->execute([$analystId, (int)$project['id'], $analystId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    function projectCanChange(PDO $conn, int $analystId, array $project): bool
    {
        if (projectSetting($conn, 'project_change_policy') === 'anyone') return true;
        return projectIsTeam($conn, $analystId, $project) || projectIsManager($conn, $analystId);
    }

    /** Deleting is never "anyone": the project manager, its creator, or Manage Projects. */
    function projectCanDelete(PDO $conn, int $analystId, array $project): bool
    {
        if ((int)($project['owner_analyst_id'] ?? 0) === $analystId || (int)($project['created_by_id'] ?? 0) === $analystId) return true;
        return projectIsManager($conn, $analystId);
    }
}
