<?php
/**
 * GET - every template, with its steps (anyone in the module: needed to open a case).
 * POST {action: save, template} / {action: delete, id} - needs Manage templates.
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';

startersApiRun(function () use ($conn, $ctx, $analystId) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $templates = StartersService::templates($conn);
        startersApiOk(['templates' => $templates]);
    }
    startersApiRequirePost();
    if (!analystHasCapability($conn, $analystId, Cap::STARTERS_TEMPLATES)) startersApiFail('You do not have permission to change templates.');
    $in = startersApiBody();
    if (($in['action'] ?? '') === 'delete') {
        StartersService::deleteTemplate($conn, (int)($in['id'] ?? 0));
        startersApiOk();
    }
    startersApiOk(['id' => StartersService::saveTemplate($conn, $ctx, (array)($in['template'] ?? []))]);
});
