<?php
/**
 * ProjectTemplatesService - start a project from a template, save a project as
 * one, and look after the list (3.2.0).
 *
 * The template format and the built-ins are in includes/projects/templates.php.
 *
 * 🔑 A PROJECT FROM A TEMPLATE IS BUILT THROUGH THE SAME DOORS AS ONE BUILT BY
 * HAND: createProject() (who may create, which company), and
 * createTaskInProject() for every task, so each is an ordinary task with its
 * events, search entry and company - not rows slipped into the table. Stages,
 * scope, RAID, tolerances and targets have no side effects and are written
 * directly, with ONE history line ("started from <template>") instead of forty.
 *
 * No transaction: TasksService opens its own, and PDO cannot nest them. If
 * anything fails after the project exists, the half-built project and its tasks
 * are removed again (cleanup()), so a failure never leaves a stray project.
 */

require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/../projects/templates.php';

class ProjectTemplatesService
{
    /**
     * Create a project from template $key. $in is the new-project form (name,
     * start_date, ...): anything it sets wins over the template. Returns the id.
     */
    public static function createFromTemplate(PDO $conn, ActorContext $ctx, string $key, array $in, ?int $tenantId): int
    {
        $tpl = projectTemplateLoad($conn, $key);
        if (!$tpl) throw new ServiceError('validation', 'invalid_field', 'That template is not available.');
        [$tplName, $c] = $tpl;

        foreach (['methodology', 'colour', 'icon', 'goal', 'summary'] as $f) {
            if (!isset($in[$f]) || $in[$f] === '' || $in[$f] === null) { if ($c[$f] !== null) $in[$f] = $c[$f]; }
        }
        // Day 0 is the start date - today when the form leaves it empty, because
        // a template's dates mean nothing without one.
        if (empty($in['start_date'])) $in['start_date'] = gmdate('Y-m-d');
        // TRAP: read the date as UTC midnight. strtotime("2026-10-07") is LOCAL midnight,
        // which gmdate() below turns into the day before whenever the server is ahead of
        // UTC (British Summer Time) - every stage and task a day early.
        $d0 = strtotime($in['start_date'] . ' 00:00:00 UTC');
        if ($d0 === false) throw new ServiceError('validation', 'invalid_field', 'The start date is not a date.');
        $at = fn($day) => $day === null ? null : gmdate('Y-m-d', $d0 + (int)$day * 86400);
        if (empty($in['target_end_date']) && $c['duration_days'] !== null) $in['target_end_date'] = $at($c['duration_days']);
        if ($c['tailoring']) $in['tailoring'] = $c['tailoring'];

        $pid = ProjectsService::createProject($conn, $ctx, $in, $tenantId);
        $taskIds = [];
        try {
            $project = ProjectsService::loadRow($conn, $pid);
            $preset = projectMethodologies()[$project['methodology']] ?? projectMethodologies()['simple'];
            $ins = $conn->prepare("INSERT INTO project_stages (project_id, kind, name, goal, start_date, end_date, position, status, created_datetime, updated_datetime)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'planned', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $addTask = function (array $tk, ?int $stageId) use ($conn, $ctx, $pid, $at, &$taskIds) {
                $taskIds[] = ProjectsService::createTaskInProject($conn, $ctx, $pid, $stageId, array_filter([
                    'title' => $tk['title'], 'description' => $tk['description'] ?? null, 'due_date' => $at($tk['due_day'] ?? null),
                ], fn($v) => $v !== null));
            };
            foreach ($c['stages'] as $i => $s) {
                $start = $at($s['start_day']); $end = $at($s['end_day']);
                if ($start && $end && $end < $start) $end = $start;
                $ins->execute([$pid, $preset['timebox'], $s['name'], $s['goal'], $start, $end, $i + 1]);
                $sid = (int)$conn->lastInsertId();
                foreach ($s['tasks'] as $tk) $addTask($tk, $sid);
            }
            foreach ($c['tasks'] as $tk) $addTask($tk, null);

            if ($c['items'] && projectsPhase2Ready($conn)) {
                $st = $conn->prepare("INSERT INTO project_items (project_id, title, description, moscow, status, position, created_datetime, updated_datetime)
                                      VALUES (?, ?, ?, ?, 'proposed', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
                foreach ($c['items'] as $i => $it) $st->execute([$pid, $it['title'], $it['description'], $it['moscow'], $i + 1]);
            }
            if ($c['raid'] && projectsPhase2Ready($conn)) {
                $st = $conn->prepare("INSERT INTO project_raid (project_id, type, title, description, probability, impact, response, response_plan, status, raised_by_id, raised_datetime, updated_datetime)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
                foreach ($c['raid'] as $r) $st->execute([$pid, $r['type'], $r['title'], $r['description'], $r['probability'], $r['impact'], $r['response'], $r['response_plan'], $ctx->actorId > 0 ? $ctx->actorId : null]);
            }
            if ($c['tolerances'] && projectsPhase2Ready($conn)) {
                $st = $conn->prepare("INSERT INTO project_tolerances (project_id, stage_id, dimension, value) VALUES (?, NULL, ?, ?)");
                foreach ($c['tolerances'] as $dim => $v) $st->execute([$pid, $dim, $v]);
            }
            $skipped = 0;
            if ($c['targets'] && projectTargetsReady($conn)) {
                $opts = projectTargetOptions($conn, $project);
                $byName = ['status' => array_column($opts['statuses'], 'id', 'name'), 'location' => array_column($opts['locations'], 'id', 'name')];
                $st = $conn->prepare("INSERT INTO project_asset_targets (project_id, name, scope, scope_type_id, scope_field, scope_value, done_field, done_op, done_value, target_date, position, created_by_analyst_id)
                                      VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, NULL, ?, ?)");
                foreach ($c['targets'] as $i => $tg) {
                    if (isset($byName[$tg['done_field']])) {
                        $id = $byName[$tg['done_field']][$tg['done_value']] ?? null;
                        if ($id === null) { $skipped++; continue; }   // no "Retired" here - leave it out, never guess
                        $tg['done_value'] = (string)$id;
                    }
                    try { $t = projectTargetNormalise($tg); } catch (ServiceError $e) { $skipped++; continue; }
                    $st->execute([$pid, $t['name'], $t['scope'], $t['scope_field'], $t['scope_value'], $t['done_field'], $t['done_op'], $t['done_value'], $i + 1, $ctx->actorId > 0 ? $ctx->actorId : null]);
                }
            }
            ProjectsService::audit($conn, $pid, $ctx->actorId, 'template_used', null, $tplName, $ctx->source === 'api' ? 'api' : 'app');
        } catch (Throwable $e) {
            self::cleanup($conn, $pid, $taskIds);
            throw $e;
        }
        // createProject drew the end date; the stages came after it.
        ProjectsService::syncCalendar($conn);
        return $pid;
    }

    /** Remove a half-built project and the tasks made for it. */
    private static function cleanup(PDO $conn, int $pid, array $taskIds): void
    {
        try {
            if ($taskIds) {
                $in = implode(',', array_map('intval', $taskIds));
                $conn->exec("DELETE FROM tasks WHERE id IN ($in)");
            }
            foreach (['project_asset_targets', 'project_tolerances', 'project_raid', 'project_items', 'project_stages', 'project_audit'] as $t) {
                try { $conn->prepare("DELETE FROM $t WHERE project_id = ?")->execute([$pid]); } catch (Throwable $e) { /* table may predate Verification */ }
            }
            $conn->prepare("DELETE FROM projects WHERE id = ?")->execute([$pid]);
        } catch (Throwable $e) { /* best effort - the original error is what matters */ }
        ProjectsService::syncCalendar($conn);   // its end date was already drawn
    }

    /**
     * Save a project as a template. $parts: plan, scope, raid, tolerances,
     * targets. Needs the Templates capability - templates are shared by every
     * company on the install.
     *
     * With $in['id'] it REPLACES that saved template's plan instead - the way a
     * template is edited: start a project from it, change the project, save it
     * back. The template keeps its id and whether it is offered; projects
     * already started from it are untouched. Built-ins have no id, so they can
     * only be copied, never overwritten.
     */
    public static function saveFromProject(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        self::assertManage($conn, $ctx);
        if (!projectTemplatesReady($conn)) throw new ServiceError('unavailable', 'not_ready', 'Run Database Verification first.');
        $project = ProjectsService::loadForActor($conn, $ctx, $projectId);
        $replaceId = (int)($in['id'] ?? 0);
        if ($replaceId > 0) self::row($conn, $replaceId);
        [$name, $desc] = self::nameDesc($in);
        $parts = array_values(array_intersect((array)($in['parts'] ?? []), ['plan', 'scope', 'raid', 'tolerances', 'targets']));
        $content = json_encode(projectTemplateCapture($conn, $project, $parts), JSON_UNESCAPED_UNICODE);
        if ($replaceId > 0) {
            $conn->prepare("UPDATE project_templates SET name = ?, description = ?, content = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$name, $desc, $content, $replaceId]);
            $id = $replaceId;
        } else {
            $conn->prepare("INSERT INTO project_templates (name, description, content, is_active, created_by_analyst_id, created_datetime, updated_datetime)
                            VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                 ->execute([$name, $desc, $content, $ctx->actorId > 0 ? $ctx->actorId : null]);
            $id = (int)$conn->lastInsertId();
        }
        ProjectsService::audit($conn, $projectId, $ctx->actorId, $replaceId > 0 ? 'template_replaced' : 'template_saved', null, $name, 'app');
        return $id;
    }

    public static function update(PDO $conn, ActorContext $ctx, int $id, array $in): void
    {
        self::assertManage($conn, $ctx);
        self::row($conn, $id);
        [$name, $desc] = self::nameDesc($in);
        $conn->prepare("UPDATE project_templates SET name = ?, description = ?, is_active = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$name, $desc, !empty($in['is_active']) ? 1 : 0, $id]);
    }

    public static function delete(PDO $conn, ActorContext $ctx, int $id): void
    {
        self::assertManage($conn, $ctx);
        self::row($conn, $id);
        // Projects made from it are untouched - a template is only a starting point.
        $conn->prepare("DELETE FROM project_templates WHERE id = ?")->execute([$id]);
    }

    /** Hide or show a built-in template in the new-project picker. */
    public static function setBuiltinHidden(PDO $conn, ActorContext $ctx, string $key, bool $hidden): void
    {
        self::assertManage($conn, $ctx);
        if (!isset(projectBuiltinTemplates()[$key])) throw new ServiceError('not_found', 'not_found', 'There is no such built-in template.');
        $list = array_values(array_filter(explode(',', projectSetting($conn, 'project_hidden_templates'))));
        $list = $hidden ? array_values(array_unique(array_merge($list, [$key]))) : array_values(array_diff($list, [$key]));
        projectSettingWrite($conn, 'project_hidden_templates', implode(',', $list));
        projectSettings($conn, true);
    }

    private static function assertManage(PDO $conn, ActorContext $ctx): void
    {
        if ($ctx->actorId <= 0) return;
        if (!analystHasCapability($conn, $ctx->actorId, Cap::PROJECTS_TEMPLATES)) {
            throw new ServiceError('forbidden', 'forbidden', 'Looking after project templates needs the Templates permission (Projects - Settings).');
        }
    }

    private static function nameDesc(array $in): array
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give the template a name.');
        if (mb_strlen($name) > 150) throw new ServiceError('validation', 'invalid_field', 'The name is too long (150 characters at most).');
        $desc = trim((string)($in['description'] ?? ''));
        return [$name, $desc === '' ? null : mb_substr($desc, 0, 500)];
    }

    private static function row(PDO $conn, int $id): array
    {
        if (!projectTemplatesReady($conn)) throw new ServiceError('not_found', 'not_found', 'That template does not exist.');
        $st = $conn->prepare("SELECT * FROM project_templates WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That template does not exist.');
        return $r;
    }
}
