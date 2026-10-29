<?php
/**
 * GET - what the screens need to choose from: templates, teams, analysts,
 * checklist templates (for template steps) and asset statuses (for recovered
 * kit). GET ?people=<search> - people to open a case for, through the Assets
 * service's own scoped people search.
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';

startersApiRun(function () use ($conn, $ctx, $analystId) {
    if (isset($_GET['people'])) {
        $rows = AssetsService::people($conn, $ctx, (string)$_GET['people'], 'everyone', 25);
        startersApiOk(['people' => array_map(function ($p) {
            return ['id' => (int)$p['id'], 'name' => $p['display_name'] ?: $p['email'], 'email' => $p['email'] ?? null,
                    'job_title' => $p['job_title'] ?? null, 'is_active' => !empty($p['is_active'])];
        }, $rows)]);
    }
    $teams = $conn->query("SELECT id, name FROM teams WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $analysts = $conn->query("SELECT id, full_name AS name FROM analysts WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $statuses = [];
    if (analystCanAccessModule($conn, $analystId, 'assets')) {
        $statuses = getTenantConfigRows($conn, 'asset_status_types', 'asset_status_type', getActiveTenantId($conn, $analystId), 'id, name', '', 'display_order, name');
    }
    startersApiOk([
        'templates'  => StartersService::templates($conn, null, true),
        'teams'      => $teams,
        'analysts'   => $analysts,
        'checklists' => ChecklistsService::tasksEnabled($conn) ? ChecklistsService::templatesForTask($conn, 0) : [],
        // The forms a template can ask (Forms switched on): its newest version of each.
        'forms'      => StartersService::formChoices($conn),
        'asset_statuses' => $statuses,
    ]);
});
