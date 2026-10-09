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
 * 3.3.0: a project can have a report drafted by itself each week, fortnight or
 * month (runSchedules(), from the alerts scan) - never approved by itself; and an
 * approved report can be emailed (send()) by the people who may approve it.
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
        $sent = self::scheduleReady($conn) ? 'r.sent_datetime, r.sent_to' : 'NULL AS sent_datetime, NULL AS sent_to';
        $st = $conn->prepare("SELECT r.id, r.kind, r.title, r.body, r.status, r.ai_drafted, r.ai_edited, r.ai_model,
                                     r.created_datetime, r.updated_datetime, r.approved_datetime, $sent,
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
            // 3.3.0: when it was last emailed, and to how many addresses in all.
            'sent_at' => $r['sent_datetime'] ?? null, 'sent_count' => ($r['sent_to'] ?? '') === '' || !isset($r['sent_to']) ? 0 : count(explode(',', $r['sent_to'])),
            'scheduled' => $r['created_by'] === null && ($r['kind'] ?? '') !== 'briefing',
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

    // ======================================================================
    //  3.3.0: scheduled drafts, and sending an approved report
    // ======================================================================

    const SCHEDULES = ['off', 'weekly', 'fortnightly', 'monthly'];
    const SEND_MAX = 50;

    /** Have the 3.3.0 columns arrived (projects.report_schedule, project_reports.sent_*)? */
    public static function scheduleReady(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try { $conn->query("SELECT report_schedule, report_schedule_kind FROM projects LIMIT 0"); $conn->query("SELECT sent_datetime, sent_to FROM project_reports LIMIT 0"); $ready = true; }
            catch (Throwable $e) { $ready = false; }
        }
        return $ready;
    }

    /** The project's schedule: {schedule: off|weekly|fortnightly|monthly, kind}. */
    public static function schedule(PDO $conn, int $projectId): ?array
    {
        if (!self::scheduleReady($conn)) return null;
        $st = $conn->prepare("SELECT report_schedule, report_schedule_kind FROM projects WHERE id = ?");
        $st->execute([$projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['schedule' => $r['report_schedule'], 'kind' => $r['report_schedule_kind']] : null;
    }

    /** Set it - anyone who may change the project. */
    public static function setSchedule(PDO $conn, ActorContext $ctx, int $projectId, string $schedule, string $kind): void
    {
        $project = ProjectsService::loadForActor($conn, $ctx, $projectId);
        ProjectsService::assertCanChange($conn, $ctx, $project);
        if (!self::scheduleReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        if (!in_array($schedule, self::SCHEDULES, true)) throw new ServiceError('validation', 'invalid_field', 'Choose off, weekly, every two weeks or monthly.');
        if (!in_array($kind, ['highlight', 'checkpoint', 'exception'], true)) throw new ServiceError('validation', 'invalid_field', 'Choose a highlight, checkpoint or exception report.');
        $cur = self::schedule($conn, $projectId);
        if ($cur['schedule'] === $schedule && $cur['kind'] === $kind) return;
        $conn->prepare("UPDATE projects SET report_schedule = ?, report_schedule_kind = ? WHERE id = ?")->execute([$schedule, $kind, $projectId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'report_schedule', $cur['schedule'] . ':' . $cur['kind'], $schedule . ':' . $kind, $ctx->source === 'api' ? 'api' : 'app');
    }

    /**
     * Who a report can be sent to from the project itself: its members with an
     * email address - analysts, the analysts in a member team, and People - and
     * the project manager. Each once, by address.
     */
    public static function recipients(PDO $conn, int $projectId): array
    {
        require_once __DIR__ . '/project_tools.php';
        $out = [];
        $add = function (?string $email, ?string $name, string $why) use (&$out) {
            $email = trim((string)$email);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($out[strtolower($email)])) return;
            $out[strtolower($email)] = ['email' => $email, 'name' => $name ?: $email, 'why' => $why];
        };
        $st = $conn->prepare("SELECT a.email, a.full_name FROM projects p JOIN analysts a ON a.id = p.owner_analyst_id WHERE p.id = ?");
        $st->execute([$projectId]);
        if ($o = $st->fetch(PDO::FETCH_ASSOC)) $add($o['email'], $o['full_name'], 'owner');
        foreach (ProjectToolsService::members($conn, $projectId) as $m) {
            if ($m['kind'] === 'team') {
                $t = $conn->prepare("SELECT a.email, a.full_name FROM analyst_teams at JOIN analysts a ON a.id = at.analyst_id WHERE at.team_id = ? AND a.is_active = 1 ORDER BY a.full_name");
                $t->execute([(int)$m['team_id']]);
                foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $a) $add($a['email'], $a['full_name'], $m['role_name'] ?: $m['name']);
            } else {
                $add($m['email'], $m['name'], (string)($m['role_name'] ?? ''));
            }
        }
        return array_values($out);
    }

    /**
     * Email an APPROVED report (3.3.0) - the people who may approve it may send it,
     * to any addresses (the page offers the project's members). Sent one by one
     * through the system mailbox (ssSendSystemEmail, logged as "Project report").
     * Returns ['sent' => [...], 'failed' => [...]]; what was sent is recorded on
     * the report and in the history.
     */
    public static function send(PDO $conn, ActorContext $ctx, int $projectId, int $id, array $emails, string $note = ''): array
    {
        $project = ProjectsService::loadForActor($conn, $ctx, $projectId);
        if ($ctx->actorId > 0 && !projectCanDelete($conn, $ctx->actorId, $project)) {
            throw new ServiceError('forbidden', 'forbidden', 'Only the project manager, the person who created the project or someone who manages Projects can send a report.');
        }
        if (!self::scheduleReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        $cur = self::load($conn, $projectId, $id);
        if ($cur['status'] !== 'approved') throw new ServiceError('validation', 'invalid_field', 'Approve the report before sending it.');
        $list = [];
        foreach ($emails as $e) {
            foreach (preg_split('/[\s,;]+/', (string)$e) as $one) {
                $one = trim($one);
                if ($one === '') continue;
                if (!filter_var($one, FILTER_VALIDATE_EMAIL)) throw new ServiceError('validation', 'invalid_field', 'Not an email address: ' . $one);
                $list[strtolower($one)] = $one;
            }
        }
        if (!$list) throw new ServiceError('validation', 'missing_field', 'Choose who to send it to.');
        if (count($list) > self::SEND_MAX) throw new ServiceError('validation', 'invalid_field', 'At most ' . self::SEND_MAX . ' people at once.');
        $note = mb_substr(trim($note), 0, 2000);

        require_once __DIR__ . '/../self_service_email.php';
        require_once __DIR__ . '/../template_email.php';
        $sender = $ctx->actorName !== '' ? $ctx->actorName : 'FreeITSM';
        $subject = $project['name'] . ' - ' . $cur['title'];
        $html = self::emailHtml($project, $cur, $sender, $note);
        $sent = []; $failed = [];
        foreach ($list as $to) {
            if (ssSendSystemEmail($conn, $to, $subject, $html, 'project_report')) $sent[] = $to; else $failed[] = $to;
        }
        if ($sent) {
            $prev = trim((string)($cur['sent_to'] ?? ''));
            $all = array_values(array_unique(array_merge($prev === '' ? [] : explode(',', $prev), $sent)));
            $conn->prepare("UPDATE project_reports SET sent_datetime = UTC_TIMESTAMP(), sent_by_id = ?, sent_to = ? WHERE id = ?")
                 ->execute([$ctx->actorId > 0 ? $ctx->actorId : null, mb_substr(implode(',', $all), 0, 60000), $id]);
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'report_sent', null, $cur['title'] . ' -> ' . count($sent), $ctx->source === 'api' ? 'api' : 'app');
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** The email: the report as the page shows it (the same three marks), in plain inline styles. */
    public static function emailHtml(array $project, array $report, string $sender, string $note): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $body = self::markdownHtml((string)$report['body']);
        return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1f2937;max-width:720px">'
            . ($note !== '' ? '<p style="white-space:pre-wrap;border-left:3px solid #e11d48;padding:4px 0 4px 12px;margin:0 0 18px">' . $e($note) . '</p>' : '')
            . '<div style="color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:.04em">' . $e(projectCode((int)$project['id'])) . ' &middot; ' . $e($project['name']) . '</div>'
            . '<h2 style="margin:4px 0 14px;font-size:20px">' . $e($report['title']) . '</h2>'
            . $body
            . '<p style="color:#6b7280;font-size:12px;margin-top:24px;border-top:1px solid #e5e7eb;padding-top:10px">'
            . $e('Approved' . (!empty($report['approved_datetime']) ? ' ' . substr((string)$report['approved_datetime'], 0, 10) : '') . ', sent by ' . $sender . ' from FreeITSM.') . '</p></div>';
    }

    /** Escape, then headings, bullets and **bold** - the marks the AI is told to use (projects-reports.js md()). */
    public static function markdownHtml(string $text): string
    {
        $inline = fn($s) => preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
        $html = ''; $list = false; $para = [];
        $flush = function () use (&$html, &$para, $inline) { if ($para) { $html .= '<p style="margin:0 0 10px">' . implode('<br>', array_map($inline, $para)) . '</p>'; $para = []; } };
        $close = function () use (&$html, &$list) { if ($list) { $html .= '</ul>'; $list = false; } };
        foreach (preg_split('/\r?\n/', $text) as $raw) {
            $line = trim($raw);
            if ($line === '') { $flush(); $close(); continue; }
            if (preg_match('/^#{1,4}\s+(.*)$/', $line, $m)) { $flush(); $close(); $html .= '<h3 style="font-size:15px;margin:16px 0 6px">' . $inline($m[1]) . '</h3>'; }
            elseif (preg_match('/^[-*]\s+(.*)$/', $line, $m)) { $flush(); if (!$list) { $html .= '<ul style="margin:0 0 10px;padding-left:20px">'; $list = true; } $html .= '<li>' . $inline($m[1]) . '</li>'; }
            else { $close(); $para[] = $line; }
        }
        $flush(); $close();
        return $html;
    }

    /**
     * Scheduled drafts (3.3.0), from projectAlertsScan(): each ACTIVE project with
     * a schedule gets one draft per period - weekly (ISO week), fortnightly (each
     * even-numbered ISO week's pair) or monthly - drafted by the AI when it is set
     * up, otherwise a draft holding the facts to write from. Never approved by
     * itself: the project manager is told (project.report_drafted) and approves,
     * edits or deletes it. One per period on the ledger, however often the scan runs.
     */
    public static function runSchedules(PDO $conn, ?int $projectId = null): int
    {
        if (!self::scheduleReady($conn)) return 0;
        $sql = "SELECT p.*, a.full_name AS owner_name FROM projects p LEFT JOIN analysts a ON a.id = p.owner_analyst_id
                 WHERE p.status = 'active' AND p.report_schedule <> 'off'";
        $args = [];
        if ($projectId !== null) { $sql .= ' AND p.id = ?'; $args[] = $projectId; }
        $st = $conn->prepare($sql);
        $st->execute($args);
        $claim = $conn->prepare("INSERT IGNORE INTO workflow_scheduled_emissions (trigger_event, entity_key, fingerprint, emitted_datetime) VALUES ('project.report_drafted', ?, ?, UTC_TIMESTAMP())");
        $made = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $period = self::periodKey($p['report_schedule']);
            if ($period === null) continue;
            try { $claim->execute(['project_report_schedule:' . (int)$p['id'], $period]); }
            catch (Throwable $e) { return $made; }   // no ledger: drafting without one would repeat every run
            if ($claim->rowCount() !== 1) continue;
            try {
                $id = self::draftScheduled($conn, $p);
            } catch (Throwable $e) {
                error_log('projects scheduled report ' . $p['id'] . ': ' . $e->getMessage());
                continue;
            }
            $made++;
            require_once __DIR__ . '/../projects/alerts.php';
            $r = self::load($conn, (int)$p['id'], $id);
            $payload = [
                'project' => projectEventPayload($p + ['shown_health' => null]),
                'report'  => ['id' => $id, 'kind' => $r['kind'], 'title' => $r['title'], 'ai_drafted' => (int)$r['ai_drafted'], 'schedule' => $p['report_schedule']],
                'notify_ids' => array_values(array_filter([(int)($p['owner_analyst_id'] ?? 0)])),
            ];
            projectAlertsAsSystem(fn() => projectDispatch('project.report_drafted', $payload));
        }
        return $made;
    }

    /** This period's ledger fingerprint, or null when this is not a drafting period (fortnightly, odd weeks). */
    public static function periodKey(string $schedule, ?int $now = null): ?string
    {
        $now = $now ?? time();
        $week = (int)gmdate('W', $now);
        switch ($schedule) {
            case 'weekly':      return 'W' . gmdate('o-W', $now);
            case 'fortnightly': return 'F' . gmdate('o', $now) . '-' . str_pad((string)($week - ($week % 2)), 2, '0', STR_PAD_LEFT);
            case 'monthly':     return 'M' . gmdate('Y-m', $now);
        }
        return null;
    }

    /** One scheduled draft: the AI's when it is set up and answers, otherwise the facts. */
    private static function draftScheduled(PDO $conn, array $p): int
    {
        $kind = in_array($p['report_schedule_kind'], ['highlight', 'checkpoint', 'exception'], true) ? $p['report_schedule_kind'] : 'highlight';
        $days = ['weekly' => 7, 'fortnightly' => 14, 'monthly' => 31][$p['report_schedule']] ?? 7;
        $facts = projectAiFacts($conn, $p, 0, $days);
        $text = null; $model = null;
        if (projectAiReady($conn)) {
            try {
                $ai = projectAiAsk($conn, 'Write ' . str_replace('{days}', (string)$days, projectAiReportKinds()[$kind]), $facts, 1800);
                $text = $ai['text']; $model = $ai['model'];
            } catch (Throwable $e) {
                error_log('projects scheduled report AI ' . $p['id'] . ': ' . $e->getMessage());
            }
        }
        if ($text === null) {
            require_once __DIR__ . '/../i18n.php';   // cron has not loaded it
            $text = "## " . t('projects.reports.scheduled_facts_title') . "\n\n" . t('projects.reports.scheduled_facts_intro') . "\n\n" . $facts;
        }
        $title = ucfirst($kind) . ' report - ' . gmdate('j M Y');
        $conn->prepare("INSERT INTO project_reports (project_id, kind, title, body, status, ai_drafted, ai_model, created_by_id, created_datetime, updated_datetime)
                        VALUES (?, ?, ?, ?, 'draft', ?, ?, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([(int)$p['id'], $kind, $title, $text, $model !== null ? 1 : 0, $model]);
        $id = (int)$conn->lastInsertId();
        ProjectsService::audit($conn, (int)$p['id'], 0, 'report_scheduled', null, $title, 'system');
        return $id;
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
