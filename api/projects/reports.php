<?php
/**
 * Projects - briefing and reports, and the AI project manager (3.2.0).
 * Rules: includes/services/project_reports.php; the AI: includes/projects/ai.php.
 *
 * GET  ?project_id=N        reports, the latest briefing, whether AI is set up,
 *                           and what this analyst may do (write / approve)
 * POST {action, project_id, ...}
 *      briefing  {refresh?}       the Overview's briefing (cached for 10 minutes)
 *      draft     {kind, days?}    the AI drafts a highlight / exception / checkpoint / closure report
 *      save      {id?, kind?, title, body}   write one, or edit a draft
 *      approve   {id}
 *      schedule  {schedule, kind}  a draft each week / fortnight / month (3.3.0)
 *      send      {id, emails[], note?}  email an approved report (3.3.0)
 *      delete    {id}
 *
 * The AI only PROPOSES: a draft is a draft until a person approves it.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
require_once __DIR__ . '/../../includes/services/project_reports.php';
require_once __DIR__ . '/../../includes/i18n.php';
I18n::initFromSession();   // the AI writes in the analyst's language

/** A provider problem in words the page can show. */
function projectReportsAiFail(RuntimeException $e): void
{
    if ($e instanceof PDOException) throw $e;   // a database error is not a provider problem
    if ($e->getMessage() === 'not_configured') {
        projectApiOk(['ai_error' => 'not_configured']);
    }
    error_log('projects ai: ' . $e->getMessage());
    projectApiOk(['ai_error' => 'unreachable', 'detail' => mb_substr($e->getMessage(), 0, 300)]);
}

projectApiRun(function () use ($conn, $ctx, $analystId) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $pid = (int)($_GET['project_id'] ?? 0);
        $project = ProjectsService::loadForActor($conn, $ctx, $pid);
        projectApiOk([
            'ready'       => ProjectReportsService::ready($conn),
            'ai_ready'    => projectAiReady($conn),
            'can_set_up'  => analystHasCapability($conn, $analystId, Cap::PROJECTS_AI),
            'can_write'   => projectCanChange($conn, $analystId, $project),
            'can_approve' => projectCanDelete($conn, $analystId, $project),
            'briefing'    => ProjectReportsService::latestBriefing($conn, $pid),
            'reports'     => ProjectReportsService::listFor($conn, $pid),
            // 3.3.0
            'schedule'    => ProjectReportsService::schedule($conn, $pid),
            'recipients'  => projectCanDelete($conn, $analystId, $project) ? ProjectReportsService::recipients($conn, $pid) : [],
        ]);
    }
    $in = projectApiBody();
    $pid = (int)($in['project_id'] ?? 0);
    switch ($in['action'] ?? '') {
        case 'briefing':
            try { projectApiOk(['briefing' => ProjectReportsService::briefing($conn, $ctx, $pid, !empty($in['refresh']))]); }
            catch (RuntimeException $e) { projectReportsAiFail($e); }
            break;
        case 'draft':
            try { projectApiOk(['id' => ProjectReportsService::draftWithAi($conn, $ctx, $pid, (string)($in['kind'] ?? ''), (int)($in['days'] ?? 14)), 'reports' => ProjectReportsService::listFor($conn, $pid)]); }
            catch (RuntimeException $e) { projectReportsAiFail($e); }
            break;
        case 'save':
            projectApiOk(['id' => ProjectReportsService::save($conn, $ctx, $pid, $in), 'reports' => ProjectReportsService::listFor($conn, $pid)]);
            break;
        case 'approve':
            ProjectReportsService::approve($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk(['reports' => ProjectReportsService::listFor($conn, $pid)]);
            break;
        case 'schedule':
            ProjectReportsService::setSchedule($conn, $ctx, $pid, (string)($in['schedule'] ?? ''), (string)($in['kind'] ?? 'highlight'));
            projectApiOk(['schedule' => ProjectReportsService::schedule($conn, $pid)]);
        case 'send':
            $r = ProjectReportsService::send($conn, $ctx, $pid, (int)($in['id'] ?? 0), (array)($in['emails'] ?? []), (string)($in['note'] ?? ''));
            projectApiOk($r + ['reports' => ProjectReportsService::listFor($conn, $pid)]);
        case 'delete':
            ProjectReportsService::delete($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk(['reports' => ProjectReportsService::listFor($conn, $pid)]);
            break;
    }
    projectApiFail('Unknown action.');
});
