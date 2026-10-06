<?php
/**
 * Projects joined to the rest of FreeITSM (3.2.0). Every rule lives in
 * includes/projects/links.php; this only routes.
 *
 * GET  ?project_id=N                   everything linked to a project, per kind
 *                                      (a kind the analyst cannot use is left out)
 * GET  ?project_id=N&search=KIND&q=    records that could be linked to it
 * POST {action:'add'|'remove', project_id, kind, target_id}
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
require_once __DIR__ . '/../../includes/projects/links.php';

projectApiRun(function () use ($conn, $ctx) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $projectId = (int)($_GET['project_id'] ?? 0);
        if (isset($_GET['search'])) {
            projectApiOk(['results' => projectLinkSearch($conn, $ctx, $projectId, (string)$_GET['search'], (string)($_GET['q'] ?? ''))]);
        }
        projectApiOk(['links' => projectLinks($conn, $ctx, $projectId), 'ready' => projectLinksReady($conn)]);
    }

    $in = projectApiBody();
    $projectId = (int)($in['project_id'] ?? 0);
    switch ($in['action'] ?? '') {
        case 'add':
            projectApiOk(['added' => projectLinkAdd($conn, $ctx, $projectId, (string)($in['kind'] ?? ''), (int)($in['target_id'] ?? 0))]);
        case 'remove':
            projectLinkRemove($conn, $ctx, $projectId, (string)($in['kind'] ?? ''), (int)($in['target_id'] ?? 0));
            projectApiOk();
    }
    projectApiFail('Unknown action.');
});
