<?php
/**
 * GET - what the Projects forms draw from: analysts, companies the analyst can
 * reach, methodologies, statuses, the colour palette and icon keys, and task
 * statuses and priorities for adding tasks.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
require_once __DIR__ . '/../../includes/i18n.php';
I18n::initFromSession();

projectApiRun(function () use ($conn, $analystId) {
    $analysts = $conn->query("SELECT id, full_name FROM analysts WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $companies = [];
    if (isMultiTenant($conn)) {
        $ids = getAccessibleTenantIds($conn, $analystId);
        foreach (getAllTenants($conn) as $t) {
            if (in_array((int)$t['id'], $ids, true)) $companies[] = ['id' => (int)$t['id'], 'name' => $t['name']];
        }
    }
    $methods = [];
    foreach (projectMethodologies() as $k => $m) {
        $methods[] = ['key' => $k, 'label' => t($m['label_key']), 'description' => t($m['desc_key']), 'timebox' => $m['timebox']];
    }
    $colours = [];
    foreach (projectColours() as $k => $c) $colours[] = ['key' => $k, 'from' => $c[0], 'to' => $c[1]];
    projectApiOk([
        'analysts'        => $analysts,
        'companies'       => $companies,
        'active_company'  => isMultiTenant($conn) ? getActiveTenantId($conn, $analystId) : null,
        'multi_company'   => isMultiTenant($conn),
        'default_method'  => projectSetting($conn, 'project_default_method'),
        'methodologies'   => $methods,
        'statuses'        => projectStatuses(),
        'colours'         => $colours,
        'icons'           => projectIcons(),
        'task_statuses'   => $conn->query("SELECT id, name, colour, is_closed FROM task_statuses WHERE is_active = 1 ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC),
        'task_priorities' => $conn->query("SELECT id, name, colour, is_default FROM task_priorities WHERE is_active = 1 ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC),
    ]);
});
