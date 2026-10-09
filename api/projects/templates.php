<?php
/**
 * Projects - templates (3.2.0). Thin adapter over ProjectTemplatesService.
 *
 * GET                                       every template, hidden and inactive ones
 *                                           too (the Settings tab) - needs Templates
 * POST {action:'save_from_project', project_id, name, description?, parts:[...], id?}
 *                                           parts: plan, scope, raid, benefits (3.3.0), tolerances, targets;
 *                                           id = replace that saved template's plan
 * POST {action:'update', id, name, description?, is_active}
 * POST {action:'delete', id}
 * POST {action:'builtin_hidden', key, hidden}
 *
 * Starting a project FROM a template is not here: it is api/projects/save.php
 * with `template`, so it passes through the same create rules as any project.
 * The picker's list comes from api/projects/lookups.php.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
require_once __DIR__ . '/../../includes/services/project_templates.php';

projectApiRun(function () use ($conn, $ctx, $analystId) {
    if (!analystHasCapability($conn, $analystId, Cap::PROJECTS_TEMPLATES)) {
        projectApiFail('Looking after project templates needs the Templates permission (Projects - Settings).', 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        projectApiOk(['templates' => projectTemplateList($conn, true), 'ready' => projectTemplatesReady($conn)]);
    }

    $in = projectApiBody();
    switch ((string)($in['action'] ?? '')) {
        case 'save_from_project':
            $id = ProjectTemplatesService::saveFromProject($conn, $ctx, (int)($in['project_id'] ?? 0), $in);
            projectApiOk(['id' => $id, 'key' => 'saved:' . $id]);
        case 'update':
            ProjectTemplatesService::update($conn, $ctx, (int)($in['id'] ?? 0), $in);
            break;
        case 'delete':
            ProjectTemplatesService::delete($conn, $ctx, (int)($in['id'] ?? 0));
            break;
        case 'builtin_hidden':
            $key = (string)($in['key'] ?? '');
            if (strpos($key, 'builtin:') === 0) $key = substr($key, 8);
            ProjectTemplatesService::setBuiltinHidden($conn, $ctx, $key, !empty($in['hidden']));
            break;
        default:
            projectApiFail('Unknown action.');
    }
    projectApiOk(['templates' => projectTemplateList($conn, true)]);
});
