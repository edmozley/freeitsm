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
            // What goes on the shared Calendar: nothing, target end dates, or end
            // dates and stage ends (includes/projects/calendar.php).
            'project_calendar'         => ['all',      'calendar',    'general'],
            // How a project announces planned disruption on Service Status (Ed's
            // call, 2026-10-08: a setting, not a decision made for him):
            //   off      no Announce button
            //   now      an ordinary incident straight away, at the level chosen
            //   planned  planned maintenance: shown as upcoming, and it becomes an
            //            incident by itself at its start and resolves at its end
            'project_disruption'       => ['planned',  'disruption',  'general'],
            // 3.3.0. The four priority words, lowest first (empty = the defaults in
            // each viewer's language, as the RAID scales); how the portfolio is
            // sorted until somebody picks another order; and what the burn-up counts.
            'project_priority_labels'  => ['',         'labels4',     'general'],
            'project_portfolio_sort'   => ['target',   'sort',        'general'],
            'project_burnup_measure'   => ['tasks',    'measure',     'general'],
            // ---- Health -----------------------------------------------------
            // Amber when the target is this close and less than this share is done.
            'project_amber_days'       => ['14',       'int:1:120',   'health'],
            'project_amber_progress'   => ['75',       'int:1:100',   'health'],
            // Red when this share (%) or more of the open work is overdue.
            'project_red_overdue_pct'  => ['25',       'int:1:100',   'health'],
            // Amber when this many linked tickets were raised in the last 7 days -
            // the jump after a go-live. 0 = not used.
            'project_ticket_amber'     => ['5',        'int:0:500',   'health'],
            // Capacity (3.3.0) - includes/projects/capacity.php. The hours a person
            // works in a week, the load (%) that shows amber (over 100% is red), and
            // how many live projects at once is "several".
            'project_capacity_hours'    => ['37.5',     'num:1:80',    'health'],
            'project_capacity_amber'    => ['85',       'int:50:100',  'health'],
            'project_capacity_projects' => ['3',        'int:2:20',    'health'],
            // Which days are working days (ISO 1 = Monday ... 7 = Sunday) - where
            // capacity spreads a task's hours - and whether rota shifts count as time
            // away from projects (some rotas are on-call only).
            'project_capacity_days'     => ['1,2,3,4,5', 'weekdays',   'health'],
            'project_capacity_desk'     => ['1',        'bool',        'health'],
            // What a missed milestone, and a dependency or decision past its date, do
            // to worked-out health: nothing, amber (the default) or red.
            'project_health_milestones' => ['amber',    'effect',      'health'],
            'project_health_raid_late'  => ['amber',    'effect',      'health'],
            // ---- RAID -------------------------------------------------------
            // Five labels each, lowest first, stored as a JSON array. Empty means
            // "the defaults in the viewer's language" - see projectScaleLabels().
            'project_probability_labels' => ['', 'labels5', 'raid'],
            'project_impact_labels'      => ['', 'labels5', 'raid'],
            // ---- Budget (3.2.0) - includes/projects/budget.php ---------------
            // The currency new projects take (each project keeps the one it was
            // given), whether a project may choose another, and how labour is
            // costed: hours only, one rate (with a per-project override), or a
            // rate per analyst. Rates themselves live in project_labour_rates.
            'project_currency'             => ['GBP',   'currency', 'budget'],
            'project_currency_per_project' => ['0',     'bool',     'budget'],
            'project_labour_mode'          => ['hours', 'labour',   'budget'],
            // ---- Templates --------------------------------------------------
            // Built-in template keys not offered for new projects. Written only by
            // ProjectTemplatesService::setBuiltinHidden(), never by the settings save.
            'project_hidden_templates'   => ['', 'builtin_keys', 'templates'],
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
        $v = is_array($raw) ? '' : trim((string)$raw);
        if ($rule === 'method') {
            if (!isset(projectMethodologies()[$v])) throw new InvalidArgumentException('Choose a way of running projects.');
            return $v;
        }
        if ($rule === 'create') {
            if (!in_array($v, ['anyone', 'managers'], true)) throw new InvalidArgumentException('Choose who may create projects.');
            return $v;
        }
        if ($rule === 'currency') {
            $v = strtoupper($v);
            if (!preg_match('/^[A-Z]{3}$/', $v)) throw new InvalidArgumentException('Enter a three-letter currency code, like GBP, EUR or USD.');
            return $v;
        }
        if ($rule === 'bool') {
            return in_array($v, ['1', 'true', 'on'], true) ? '1' : '0';
        }
        if ($rule === 'labour') {
            if (!in_array($v, ['hours', 'rate', 'analyst'], true)) throw new InvalidArgumentException('Choose how labour is costed.');
            return $v;
        }
        if ($rule === 'disruption') {
            if (!in_array($v, ['off', 'now', 'planned'], true)) throw new InvalidArgumentException('Choose how disruption is announced.');
            return $v;
        }
        if ($rule === 'calendar') {
            if (!in_array($v, ['off', 'ends', 'all'], true)) throw new InvalidArgumentException('Choose what goes on the Calendar.');
            return $v;
        }
        if ($rule === 'change') {
            if (!in_array($v, ['anyone', 'team'], true)) throw new InvalidArgumentException('Choose who may change a project.');
            return $v;
        }
        if (strpos($rule, 'num:') === 0) {
            // A number with up to two decimals (37.5 hours a week).
            [, $min, $max] = explode(':', $rule);
            $v = str_replace(',', '.', $v);
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $v) || (float)$v < (float)$min || (float)$v > (float)$max) {
                throw new InvalidArgumentException("Enter a number from $min to $max.");
            }
            return (string)(float)$v;
        }
        if (strpos($rule, 'int:') === 0) {
            [, $min, $max] = explode(':', $rule);
            if (!preg_match('/^\d+$/', $v) || (int)$v < (int)$min || (int)$v > (int)$max) {
                throw new InvalidArgumentException("Enter a whole number from $min to $max.");
            }
            return (string)(int)$v;
        }
        if ($rule === 'labels5' || $rule === 'labels4') {
            $n = (int)substr($rule, 6);
            $parts = is_array($raw) ? array_map(fn($p) => trim((string)$p), array_values($raw)) : projectScaleParse($v);
            if (count($parts) !== $n || in_array('', $parts, true)) throw new InvalidArgumentException($n === 5 ? 'Give a word for each of the five steps.' : 'Give a word for each of the four steps.');
            foreach ($parts as $p) if (mb_strlen($p) > 40) throw new InvalidArgumentException('Each label must be 40 characters or fewer.');
            // The defaults, unchanged, are stored as "not set" so the scale keeps
            // following each viewer's language.
            if ($parts === projectScaleDefaults(substr($key, 8, -7))) return '';
            return json_encode($parts, JSON_UNESCAPED_UNICODE);
        }
        if ($rule === 'sort') {
            if (!in_array($v, ['target', 'priority', 'health', 'name'], true)) throw new InvalidArgumentException('Choose how the portfolio is sorted.');
            return $v;
        }
        if ($rule === 'measure') {
            if (!in_array($v, ['tasks', 'hours'], true)) throw new InvalidArgumentException('Choose tasks or hours.');
            return $v;
        }
        if ($rule === 'effect') {
            if (!in_array($v, ['off', 'amber', 'red'], true)) throw new InvalidArgumentException('Choose nothing, amber or red.');
            return $v;
        }
        if ($rule === 'weekdays') {
            $days = is_array($raw) ? $raw : explode(',', $v);
            $days = array_values(array_unique(array_filter(array_map('intval', $days), fn($d) => $d >= 1 && $d <= 7)));
            sort($days);
            if (!$days) throw new InvalidArgumentException('Choose at least one working day.');
            return implode(',', $days);
        }
        if ($rule === 'builtin_keys') {
            throw new InvalidArgumentException('Hide or show templates on the Templates tab.');
        }
        throw new InvalidArgumentException("Unknown setting: $key");
    }

    // ======================================================================
    //  RAID risk scales - five words each, lowest first
    // ======================================================================

    /**
     * The five default words for a scale ('probability' or 'impact') in the
     * viewer's language. They live in lang/<locale>/projects.php, not in the
     * setting, so an install that never changed them reads them in German for a
     * German analyst.
     */
    function projectScaleDefaults(string $scale): array
    {
        if (!class_exists('I18n')) {
            require_once __DIR__ . '/../i18n.php';
            I18n::initFromSession();
        }
        $out = [];
        for ($i = 1; $i <= projectScaleSize($scale); $i++) $out[] = t('projects.scale.' . $scale . '_' . $i);
        return $out;
    }

    /**
     * Read a stored scale. A JSON array is the format; a comma-separated string
     * is what the first 3.2.0 builds wrote, read the same way so nothing needs
     * migrating (it is rewritten as JSON the next time the tab is saved).
     */
    function projectScaleParse(string $stored): array
    {
        $stored = trim($stored);
        if ($stored === '') return [];
        if ($stored[0] === '[') {
            $a = json_decode($stored, true);
            return is_array($a) ? array_map(fn($p) => trim((string)$p), array_values($a)) : [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $stored)), fn($p) => $p !== ''));
    }

    /** How many steps a scale has: five for the RAID scales, four for priority (3.3.0). */
    function projectScaleSize(string $scale): int
    {
        return $scale === 'priority' ? 4 : 5;
    }

    /** The words to show for a scale: the saved ones, or the translated defaults. */
    function projectScaleLabels(PDO $conn, string $scale): array
    {
        $saved = projectScaleParse(projectSetting($conn, 'project_' . $scale . '_labels'));
        return count($saved) === projectScaleSize($scale) ? $saved : projectScaleDefaults($scale);
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
