<?php
/**
 * Projects - the AI project assistant (3.3.0). Every rule is in
 * includes/projects/assistant_chat.php; this only routes.
 *
 * GET  ?project_id=N        the conversation (this person's, or the project's shared
 *                           one), whether AI is set up, whether they may change the
 *                           project, and where the project is in its set-up
 * POST {action, project_id, ...}
 *      open                 opening the panel: a greeting, a "since we last spoke", or nothing
 *      send   {text}        a message; the answer may carry proposals
 *      apply  {message_id, items:[i,...]}    apply the ticked proposals, as the caller
 *      dismiss {message_id, items:[i,...]}
 *      clear                start again
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
require_once __DIR__ . '/../../includes/projects/assistant_chat.php';
require_once __DIR__ . '/../../includes/i18n.php';
I18n::initFromSession();   // the assistant answers in the analyst's language

/** A provider problem in words the panel can show (as reports.php). */
function projectChatAiFail(RuntimeException $e): void
{
    if ($e instanceof PDOException) throw $e;
    if ($e->getMessage() === 'not_configured') projectApiOk(['ai_error' => 'not_configured']);
    error_log('projects assistant: ' . $e->getMessage());
    projectApiOk(['ai_error' => 'unreachable', 'detail' => mb_substr($e->getMessage(), 0, 300)]);
}

projectApiRun(function () use ($conn, $ctx, $analystId) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $project = ProjectsService::loadForActor($conn, $ctx, (int)($_GET['project_id'] ?? 0));
        $ready = projectChatReady($conn);
        $thread = $ready ? projectChatThread($conn, (int)$project['id'], $analystId, false) : null;
        projectApiOk([
            'ready'      => $ready,
            'ai_ready'   => projectAiReady($conn),
            'can_set_up' => analystHasCapability($conn, $analystId, Cap::PROJECTS_AI),
            'can_change' => projectCanChange($conn, $analystId, $project),
            'shared'     => projectSetting($conn, 'project_assistant_memory') === 'project',
            'maturity'   => projectChatMaturity($conn, $project),
            'messages'   => $thread ? projectChatMessages($conn, (int)$thread['id']) : [],
            'remembers'  => $thread && !empty($thread['summary']),
        ]);
    }
    $in = projectApiBody();
    $project = ProjectsService::loadForActor($conn, $ctx, (int)($in['project_id'] ?? 0));
    switch ($in['action'] ?? '') {
        case 'open':
            try { projectApiOk(projectChatOpen($conn, $ctx, $project)); } catch (RuntimeException $e) { projectChatAiFail($e); }
            break;
        case 'send':
            try { projectApiOk(projectChatTurn($conn, $ctx, $project, 'chat', (string)($in['text'] ?? ''))); } catch (RuntimeException $e) { projectChatAiFail($e); }
            break;
        case 'apply':
        case 'dismiss':
            $props = projectChatApply($conn, $ctx, $project, (int)($in['message_id'] ?? 0), (array)($in['items'] ?? []), $in['action'] === 'dismiss');
            projectApiOk(['proposals' => $props, 'maturity' => projectChatMaturity($conn, ProjectsService::loadRow($conn, (int)$project['id']))]);
        case 'clear':
            projectChatClear($conn, $ctx, $project);
            projectApiOk();
    }
    projectApiFail('Unknown action.');
});
