<?php
/**
 * REST API v1 - which module a request belongs to, so a module switched off for
 * the install answers 403 module_disabled (GH #217).
 *
 * The API authorises by its own permission map (lib/permissions.php), not by
 * analystCanAccessModule(), so it cannot inherit the switch the way the analyst
 * pages do: it needs to know each resource's module. That is this file.
 *
 * TRAP: a NEW resource for a switchable module must be added to the map below, or
 * it keeps answering while its module is off. tests/module-availability.php fails
 * for any permission resource that is in neither map.
 */

/**
 * Permission resource => the module(s) it needs. Several modules = it needs them
 * all (a link between an asset and a CI is refused when either is off). Tickets and
 * System resources are listed as such: those modules cannot be switched off, and
 * listing them is what lets the test tell "forgotten" from "always on".
 */
const API_V1_RESOURCE_MODULES = [
    // Tickets (always on)
    'tickets' => ['tickets'], 'ticket_notes' => ['tickets'], 'ticket_thread' => ['tickets'],
    'ticket_watchers' => ['tickets'],   // GH #277
    'ticket_forms' => ['tickets', 'forms'],   // GH #272
    'ticket_checklists' => ['tickets', 'checklists'],   // GH #273: refused while Checklists is switched off
    // A Tickets feature with its own switch (slice 2) is listed beside Tickets.
    'ticket_audit' => ['tickets'], 'ticket_sla' => ['tickets', 'sla'], 'ticket_time_entries' => ['tickets', 'time-tracking'],
    'users' => ['tickets'],
    // System (always on)
    'analysts' => ['system'], 'analyst_access' => ['system'], 'companies' => ['system'],
    'cost_centres' => ['system'], 'accounts' => ['system'], 'job_health' => ['system'], 'mcp' => ['system'],
    // Assets
    'assets' => ['assets'], 'asset_assignments' => ['assets'], 'asset_history' => ['assets'],
    'asset_inventory' => ['assets'], 'virtual_estate' => ['assets'],
    'asset_ci_links' => ['assets', 'cmdb'],
    // Problems / Changes
    'problems' => ['problems'], 'problem_notes' => ['problems'], 'problem_audit' => ['problems'],
    'problem_links' => ['problems'],
    'changes' => ['changes'], 'change_attachments' => ['changes'], 'change_comments' => ['changes'],
    'change_audit' => ['changes'], 'change_cab' => ['changes'], 'change_approvals' => ['changes'],
    'change_revisions' => ['changes'],
    // Knowledge
    'knowledge' => ['knowledge'], 'knowledge_versions' => ['knowledge'],
    // Tasks
    'tasks' => ['tasks'], 'task_comments' => ['tasks'], 'task_forms' => ['tasks', 'forms'],
    'task_checklists' => ['tasks', 'checklists'],   // GH #273
    // CMDB
    'cmdb_classes' => ['cmdb'], 'cmdb_objects' => ['cmdb'], 'cmdb_relationships' => ['cmdb'],
    'cmdb_ticket_links' => ['cmdb'],
    // Contracts
    'contracts' => ['contracts'], 'contract_terms' => ['contracts'], 'suppliers' => ['contracts'],
    'supplier_contacts' => ['contracts'],
    // The rest, one module each
    'calendar_events' => ['calendar'],
    'software_inventory' => ['software'], 'software_licences' => ['software'],
    'services' => ['service-status'], 'service_incidents' => ['service-status'],
    'morning_checks' => ['morning-checks'], 'morning_check_results' => ['morning-checks'],
    'forms' => ['forms'], 'form_submissions' => ['forms'],
    'workflows' => ['workflow'], 'workflow_executions' => ['workflow'],
    'network_diagrams' => ['network-mapper'],
    'domains' => ['domains'],
    'projects' => ['projects'],
];

/**
 * The `reference` permission covers lookup lists for every module, so those
 * routes are mapped by path instead. First match wins; a path not listed needs
 * no switchable module (the ticket lookups - statuses, priorities and so on).
 */
const API_V1_REFERENCE_PATH_MODULES = [
    '#^/(asset-types|asset-statuses|asset-locations)#'                             => ['assets'],
    '#^/(problem-statuses|problem-priorities)#'                                    => ['problems'],
    '#^/change-(statuses|types|priorities|impacts|categories)#'                    => ['changes'],
    '#^/knowledge/tags#'                                                           => ['knowledge'],
    '#^/task-(statuses|priorities|tags)#'                                          => ['tasks'],
    '#^/cmdb-relationship-types#'                                                  => ['cmdb'],
    '#^/cmdb-icons#'                                                               => ['network-mapper'],
    '#^/(suppliers|contract-statuses|payment-schedules|supplier-types|supplier-statuses|contract-term-tabs)#' => ['contracts'],
    '#^/calendar-categories#'                                                      => ['calendar'],
    '#^/(service-incident-statuses|service-impact-levels)#'                        => ['service-status'],
    '#^/morning-check-statuses#'                                                   => ['morning-checks'],
    '#^/(workflow-triggers|workflow-actions)#'                                     => ['workflow'],
];

/**
 * The modules a request needs, from its permission resource and its path. A few
 * routes reach a second module through their path (a project's tasks, a problem's
 * changes) and add it here.
 *
 * @param string $resource  the route's permission resource, e.g. 'tasks'
 * @param string $path  the request path, e.g. '/projects/4/tasks'
 * @return string[]
 */
function apiV1ModulesFor(string $resource, string $path): array
{
    if ($resource === 'reference') {
        foreach (API_V1_REFERENCE_PATH_MODULES as $re => $mods) {
            if (preg_match($re, $path)) return $mods;
        }
        return [];
    }
    $mods = API_V1_RESOURCE_MODULES[$resource] ?? [];
    if (preg_match('#^/projects/\d+/tasks#', $path)) $mods[] = 'tasks';
    if (preg_match('#^/problems/\d+/changes#', $path)) $mods[] = 'changes';
    return array_values(array_unique($mods));
}

/**
 * Refuse the request with 403 module_disabled when any module it needs is
 * switched off for the install. Same wording as the analyst pages.
 *
 * @param string $resource  the route's permission resource
 * @param string $path  the request path
 */
function apiV1RequireModulesEnabled(PDO $conn, string $resource, string $path): void
{
    foreach (apiV1ModulesFor($resource, $path) as $module) {
        if (!moduleIsEnabled($conn, $module)) {
            apiError(403, 'module_disabled', moduleDisabledMessage($module));
        }
    }
}
