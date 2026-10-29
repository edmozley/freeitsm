<?php
/**
 * GET ?id= - one case for its page: the case, the person, its steps (from Tasks),
 * the kit they hold (from Assets) and its history. Out of scope reads as not found.
 *
 * Every link to another module comes from entityLink(), so this module never
 * spells out where a task, a person or an asset lives - and only when the
 * analyst can open that module.
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';
require_once __DIR__ . '/../../includes/entity_links.php';

startersApiRun(function () use ($conn, $ctx, $analystId) {
    $case = StartersService::getCase($conn, $ctx, (int)($_GET['id'] ?? 0));
    $can = [
        'tasks'  => analystCanAccessModule($conn, $analystId, 'tasks'),
        'assets' => analystCanAccessModule($conn, $analystId, 'assets'),
        'people' => analystCanAccessModule($conn, $analystId, 'people'),
        'manage' => analystHasCapability($conn, $analystId, Cap::STARTERS_MANAGE),
    ];
    foreach ($case['steps'] as &$s) $s['url'] = $can['tasks'] ? entityLink('task', $s['id']) : null;
    unset($s);
    foreach ($case['kit'] as &$a) $a['url'] = entityLink('asset', (int)$a['id']);
    unset($a);
    startersApiOk([
        'case'  => $case,
        'links' => [
            'task'     => $can['tasks'] && $case['parent_task_id'] ? entityLink('task', (int)$case['parent_task_id']) : null,
            'person'   => $can['people'] && $case['user_id'] ? entityLink('person', (int)$case['user_id']) : null,
            'handover' => $can['assets'] && $case['user_id'] ? 'asset-management/handover.php?user_id=' . (int)$case['user_id'] : null,
        ],
        'can' => $can + ['change_status' => $can['manage'] || (int)$case['owner_analyst_id'] === $analystId],
        'multi' => isMultiTenant($conn),   // the company is only worth showing when there is more than one
    ]);
});
