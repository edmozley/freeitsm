<?php
/**
 * ProjectReportsService - a project's briefing and reports (3.2.0), and the
 * rules for who may write, approve and delete them. The AI side is
 * includes/projects/ai.php.
 *
 *   briefing    the Overview's "Brief me": anyone who can open the project may
 *               ask; one is kept per project (the latest), and a new one is
 *               not drafted while the last is under 10 minutes old - each costs
 *               a call to the provider.
 *   highlight / exception / checkpoint
 *               reports. Drafted by the AI or written by a person, by anyone
 *               who may change the project. A draft can be edited; editing an
 *               AI draft marks it ai_edited. APPROVING is the project
 *               manager's, the creator's or a Projects manager's (the same
 *               people who may delete the project) - a report is what the board
 *               reads under the project's name. An approved report is final:
 *               it cannot be edited, and only those same people may delete it.
 *
 * Every write is in the project's history.
 */

require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/../projects/ai.php';

class ProjectReportsService
{
    const KINDS = ['highlight', 'exception', 'checkpoint', 'closure'];
    const BRIEFING_COOLDOWN_MINUTES = 10;

    public static function ready(PDO $conn): bool
    {
        try { $conn->query("SELECT 1 FROM project_reports LIMIT 0"); return true; } catch (Throwable $e) { return false; }
    }

    /** Reports (not briefings), newest first, with who wrote and approved them. */
    public static function listFor(PDO $conn, int $projectId): array
    {
        if (!self::ready($conn)) return [];
        $st = $conn->prepare("SELECT r.id, r.kind, r.title, r.body, r.status, r.ai_drafted, r.ai_edited, r.ai_model,
                                     r.created_datetime, r.updated_datetime, r.approved_datetime,
                                     c.full_name AS created_by, u.full_name AS updated_by, ap.full_name AS approved_by
                                FROM project_reports r
                           LEFT JOIN analysts c ON c.id = r.created_by_id
                           LEFT JOIN analysts u ON u.id = r.updated_by_id
                           LEFT JOIN analysts ap ON ap.id = r.approved_by_id
                               WHERE r.project_id = ? AND r.kind <> 'briefing'
                            ORDER BY r.id DESC");
        $st->execute([$projectId]);
        return array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function latestBriefing(PDO $conn, int $projectId): ?array
    {
        if (!self::ready($conn)) return null;
        $st = $conn->prepare("SELECT r.id, r.kind, r.title, r.body, r.status, r.ai_drafted, r.ai_edited, r.ai_model, r.created_datetime, r.updated_datetime,
                                     r.approved_datetime, c.full_name AS created_by, NULL AS updated_by, NULL AS approved_by
                                FROM project_reports r LEFT JOIN analysts c ON c.id = r.created_by_id
                               WHERE r.project_id = ? AND r.kind = 'briefing' ORDER BY r.id DESC LIMIT 1");
        $st->execute([$projectId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::shape($row) : null;
    }

    private static function shape(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'body' => (string)$r['body'], 'status' => $r['status'],
            'ai_drafted' => (bool)(int)$r['ai_drafted'], 'ai_edited' => (bool)(int)$r['ai_edited'], 'ai_model' => $r['ai_model'],
            'created_by' => $r['created_by'], 'created_at' => $r['created_datetime'], 'updated_by' => $r['updated_by'], 'updated_at' => $r['updated_datetime'],
            'approved_by' => $r['approved_by'], 'approved_at' => $r['approved_datetime'],
        ];
    }

    /** The Overview's briefing: the latest, or a fresh one if it is older than the cooldown. */
    public static function briefing(PDO $conn, ActorContext $ctx, int $projectId, bool $refresh): array
    {
        $project = ProjectsService::loadForActor($conn, $ctx, $projectId);
        $last = self::latestBriefing($conn, $projectId);
        if ($last && (!$refresh || strtotime($last['created_at'] . ' UTC') > time() - self::BRIEFING_COOLDOWN_MINUTES * 60)) {
            return $last + ['fresh' => false];
        }
        $facts = projectAiFacts($conn, $project, $ctx->actorId, 7);
        $ai = projectAiAsk($conn, projectAiBriefingTask(), $facts, 700);
        $conn->prepare("DELETE FROM project_reports WHERE project_id = ? AND kind = 'briefing'")->execute([$projectId]);
        $conn->prepare("INSERT INTO project_reports (project_id, kind, title, body, status, ai_drafted, ai_model, created_by_id, created_datetime, updated_datetime)
                        VALUES (?, 'briefing', 'Briefing', ?, 'draft', 1, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$projectId, $ai['text'], $ai['model'], $ctx->actorId > 0 ? $ctx->actorId : null]);
        return self::latestBriefing($conn, $projectId) + ['fresh' => true];
    }

    /** Draft a report with the AI. Saved as a draft for a person to edit and approve. */
    public static function draftWithAi(PDO $conn, ActorContext $ctx, int $projectId, string $kind, int $days = 14): int
    {
        $project = ProjectsService::loadForActor($conn, $ctx, $projectId);
        ProjectsService::assertCanChange($conn, $ctx, $project);
        if (!in_array($kind, self::KINDS, true)) throw new ServiceError('validation', 'invalid_field', 'Choose a highlight, exception, checkpoint or closure report.');
        $days = max(1, min(90, $days));
        $task = str_replace('{days}', (string)$days, projectAiReportKinds()[$kind]);
        $ai = projectAiAsk($conn, 'Write ' . $task, projectAiFacts($conn, $project, $ctx->actorId, $days), 1800);
        $title = ucfirst($kind) . ' report - ' . gmdate('j M Y');
        $conn->prepare("INSERT INTO project_reports (project_id, kind, title, body, status, ai_drafted, ai_model, created_by_id, created_datetime, updated_datetime)
                        VALUES (?, ?, ?, ?, 'draft', 1, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$projectId, $kind, $title, $ai['text'], $ai['model'], $ctx->actorId > 0 ? $ctx->actorId : null]);
        $id = (int)$conn->lastInsertId();
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'report_drafted', null, $title, $ctx->source === 'api' ? 'api' : 'app');
        return $id;
    }

    /** Write a report by hand (no id) or edit a draft (id). */
    public static function save(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        $project = ProjectsService::loadForActor($conn, $ctx, $projectId);
        ProjectsService::assertCanChange($conn, $ctx, $project);
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') throw new ServiceError('validation', 'missing_field', 'Give the report a title.');
        if (mb_strlen($title) > 200) throw new ServiceError('validation', 'invalid_field', 'The title is too long.');
        $body = (string)($in['body'] ?? '');
        if (mb_strlen($body) > 100000) throw new ServiceError('validation', 'invalid_field', 'The report is too long.');
        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            $cur = self::load($conn, $projectId, $id);
            if ($cur['status'] === 'approved') throw new ServiceError('validation', 'invalid_field', 'An approved report cannot be changed.');
            $edited = (int)$cur['ai_drafted'] === 1 && ($cur['body'] !== $body || $cur['title'] !== $title) ? 1 : (int)$cur['ai_edited'];
            $conn->prepare("UPDATE project_reports SET title = ?, body = ?, ai_edited = ?, updated_by_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$title, $body, $edited, $ctx->actorId > 0 ? $ctx->actorId : null, $id]);
            return $id;
        }
        $kind = (string)($in['kind'] ?? 'highlight');
        if (!in_array($kind, self::KINDS, true)) throw new ServiceError('validation', 'invalid_field', 'Choose a highlight, exception, checkpoint or closure report.');
        $conn->prepare("INSERT INTO project_reports (project_id, kind, title, body, status, ai_drafted, created_by_id, created_datetime, updated_datetime)
                        VALUES (?, ?, ?, ?, 'draft', 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$projectId, $kind, $title, $body, $ctx->actorId > 0 ? $ctx->actorId : null]);
        $id = (int)$conn->lastInsertId();
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'report_added', null, $title, $ctx->source === 'api' ? 'api' : 'app');
        return $id;
    }

    /** Approve a draft. The project manager's, the creator's or a Projects manager's. */
    public static function approve(PDO $conn, ActorContext $ctx, int $projectId, int $id): void
    {
        $project = ProjectsService::loadForActor($conn, $ctx, $projectId);
        if ($ctx->actorId > 0 && !projectCanDelete($conn, $ctx->actorId, $project)) {
            throw new ServiceError('forbidden', 'forbidden', 'Only the project manager, the person who created the project or someone who manages Projects can approve a report.');
        }
        $cur = self::load($conn, $projectId, $id);
        if ($cur['status'] === 'approved') return;
        if (trim((string)$cur['body']) === '') throw new ServiceError('validation', 'invalid_field', 'An empty report cannot be approved.');
        $conn->prepare("UPDATE project_reports SET status = 'approved', approved_by_id = ?, approved_datetime = UTC_TIMESTAMP() WHERE id = ? AND status = 'draft'")
             ->execute([$ctx->actorId > 0 ? $ctx->actorId : null, $id]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'report_approved', null, $cur['title'], $ctx->source === 'api' ? 'api' : 'app');
    }

    /** Delete a draft (anyone who may change the project) or an approved report (those who may approve). */
    public static function delete(PDO $conn, ActorContext $ctx, int $projectId, int $id): void
    {
        $project = ProjectsService::loadForActor($conn, $ctx, $projectId);
        $cur = self::load($conn, $projectId, $id);
        if ($cur['status'] === 'approved') {
            if ($ctx->actorId > 0 && !projectCanDelete($conn, $ctx->actorId, $project)) {
                throw new ServiceError('forbidden', 'forbidden', 'Only the project manager, the person who created the project or someone who manages Projects can delete an approved report.');
            }
        } else {
            ProjectsService::assertCanChange($conn, $ctx, $project);
        }
        $conn->prepare("DELETE FROM project_reports WHERE id = ?")->execute([$id]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'report_removed', $cur['title'], null, $ctx->source === 'api' ? 'api' : 'app');
    }

    private static function load(PDO $conn, int $projectId, int $id): array
    {
        $st = $conn->prepare("SELECT * FROM project_reports WHERE id = ? AND project_id = ? AND kind <> 'briefing'");
        $st->execute([$id, $projectId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'That report is not part of this project.');
        return $row;
    }
}
