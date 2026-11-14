<?php
/**
 * The Starter classes plan (GH #313): nine generic classes with a handful of
 * properties each, and the two relationship types they need, for an empty or
 * young CMDB. A starting point, not a standard - kept short on purpose.
 *
 * It is an ordinary schema plan (includes/cmdb/schema_plan.php), so the preview,
 * the add-only rules and the one-transaction apply are the same code the CMDB AI
 * assistant will use.
 *
 * Names and descriptions come through t() and so follow the analyst's language;
 * the class and property KEYS are fixed English slugs, so the same starter class
 * is recognised whatever language it was added in.
 *
 * Seeded already by every install (freeitsm.sql): depends on / is depended on by
 * (to_from), connects to / is connected from (none), managed by / manages (none).
 * Added here if missing: runs on / runs (to_from - the host failing affects what
 * runs on it) and is part of / includes (from_to - a part failing affects the whole).
 *
 * @wiki CMDB-Developer-Guide
 */

require_once __DIR__ . '/../i18n.php';

/** The Starter classes plan, in the analyst's language. */
function cmdbStarterPlan(): array
{
    $s = function (string $k): string { return t('cmdb.starter.' . $k); };
    $p = function (string $key, string $type, array $extra = []) use ($s): array {
        return array_merge(['key' => $key, 'label' => $s('prop_' . $key), 'type' => $type], $extra);
    };
    $env = $p('environment', 'dropdown', ['options' => [$s('opt_production'), $s('opt_test'), $s('opt_development')]]);
    $team = $p('support_team', 'team');
    $os = $p('operating_system', 'text');
    $class = function (string $key, string $icon, array $props) use ($s): array {
        return ['key' => $key, 'name' => $s('class_' . $key), 'description' => $s('desc_' . $key), 'icon' => $icon, 'properties' => $props];
    };

    return [
        'title' => $s('title'),
        'relationship_types' => [
            ['verb' => $s('rel_runs_on'), 'inverse_verb' => $s('rel_runs'), 'impact_direction' => 'to_from', 'description' => $s('rel_runs_on_desc')],
            ['verb' => $s('rel_part_of'), 'inverse_verb' => $s('rel_includes'), 'impact_direction' => 'from_to', 'description' => $s('rel_part_of_desc')],
        ],
        'classes' => [
            $class('service', 'service', [
                $p('service_owner', 'person'),
                $team,
                $p('criticality', 'dropdown', ['options' => [$s('opt_low'), $s('opt_medium'), $s('opt_high'), $s('opt_critical')]]),
                $p('status', 'dropdown', ['options' => [$s('opt_live'), $s('opt_planned'), $s('opt_retiring')]]),
                $p('hours_of_service', 'text'),
            ]),
            $class('application', 'application', [
                $p('business_owner', 'person'),
                $p('technical_owner', 'analyst'),
                $team,
                $p('vendor', 'supplier'),
                $p('support_contract', 'contract'),
                $p('version', 'text'),
                $env,
            ]),
            $class('server', 'server', [
                $p('role', 'text'),
                $os,
                $env,
                $p('location', 'location'),
                $team,
            ]),
            $class('virtual_machine', 'vm', [
                $p('host', 'object_ref', ['target' => 'server', 'spreads_impact' => true]),
                $os,
                $env,
                $team,
            ]),
            $class('database', 'database', [
                $p('engine', 'dropdown', ['options' => ['SQL Server', 'MySQL', 'PostgreSQL', 'Oracle', $s('opt_other')]]),
                $p('version', 'text'),
                $env,
                $p('backed_up', 'boolean'),
                $team,
            ]),
            $class('network_device', 'network', [
                $p('device_type', 'dropdown', ['options' => [$s('opt_switch'), $s('opt_router'), $s('opt_firewall'), $s('opt_wireless_ap'), $s('opt_load_balancer')]]),
                $p('location', 'location'),
                $p('management_ip', 'text'),
                $p('firmware_version', 'text'),
                $p('support_contract', 'contract'),
            ]),
            $class('laptop_desktop', 'laptop', [
                $p('primary_user', 'person'),
                $os,
                $p('encrypted', 'boolean'),
                $p('location', 'location'),
            ]),
            $class('mobile_device', 'mobile', [
                $p('primary_user', 'person'),
                $p('platform', 'dropdown', ['options' => ['iOS', 'Android']]),
                $p('managed', 'boolean'),
                $p('phone_number', 'text'),
            ]),
            $class('subscription', 'key', [
                $p('vendor', 'supplier'),
                $p('contract', 'contract'),
                $p('seats', 'number'),
                $p('renewal_date', 'date'),
                $p('owner', 'person'),
            ]),
        ],
        // Guidance only: how the classes are meant to link. Nothing in the schema records it.
        'usage' => [
            ['from' => 'service', 'verb' => t('cmdb.starter.verb_depends_on'), 'to' => 'application'],
            ['from' => 'application', 'verb' => $s('rel_runs_on'), 'to' => 'server'],
            ['from' => 'application', 'verb' => $s('rel_runs_on'), 'to' => 'virtual_machine'],
            ['from' => 'database', 'verb' => $s('rel_runs_on'), 'to' => 'server'],
            ['from' => 'database', 'verb' => $s('rel_runs_on'), 'to' => 'virtual_machine'],
            ['from' => 'application', 'verb' => t('cmdb.starter.verb_depends_on'), 'to' => 'database'],
            ['from' => 'server', 'verb' => t('cmdb.starter.verb_connects_to'), 'to' => 'network_device'],
        ],
        'parent_child' => [],
    ];
}
