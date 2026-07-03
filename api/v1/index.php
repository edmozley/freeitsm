<?php
/**
 * FreeITSM REST API v1 — front controller.
 *
 * Routing: the path comes from mod_rewrite (see .htaccess) or directly via
 * PATH_INFO, so both of these are equivalent:
 *   GET /api/v1/tickets/42
 *   GET /api/v1/index.php/tickets/42     (works with no rewrite module)
 *
 * Authentication: every route requires an API key (System > API) sent as
 *   Authorization: Bearer fitsm_xxxxxxxx...
 * and each route additionally requires the permission listed in its route
 * entry — keys are granular, starting from zero permissions.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/resources/tickets.php';
require_once __DIR__ . '/resources/users.php';
require_once __DIR__ . '/resources/reference.php';
require_once __DIR__ . '/resources/assets.php';
require_once __DIR__ . '/resources/problems.php';
require_once __DIR__ . '/resources/changes.php';
require_once __DIR__ . '/resources/knowledge.php';
require_once __DIR__ . '/resources/tasks.php';
require_once __DIR__ . '/resources/cmdb.php';
require_once __DIR__ . '/resources/contracts.php';
require_once __DIR__ . '/resources/calendar.php';
require_once __DIR__ . '/resources/software.php';
require_once __DIR__ . '/resources/service_status.php';

// --- Resolve the request path ---------------------------------------------
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && isset($_SERVER['ORIG_PATH_INFO'])) {
    $path = $_SERVER['ORIG_PATH_INFO'];
}
if ($path === '' && isset($_GET['path'])) {
    $path = $_GET['path'];
}
$path = '/' . trim($path, '/');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
// Method override for clients that can only send GET/POST.
$override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '';
if ($method === 'POST' && in_array(strtoupper($override), ['PATCH', 'DELETE'], true)) {
    $method = strtoupper($override);
}

// --- Route table -----------------------------------------------------------
// [method, pattern, [resource, action] | null, handler]
$routes = [
    ['GET',    '#^/$#',                                    null,                              'apiHandleRoot'],
    ['GET',    '#^/ping$#',                                null,                              'apiHandlePing'],

    ['GET',    '#^/tickets$#',                             ['tickets', 'read'],               'apiTicketsList'],
    ['POST',   '#^/tickets$#',                             ['tickets', 'create'],             'apiTicketsCreate'],
    ['GET',    '#^/tickets/(\d+)$#',                       ['tickets', 'read'],               'apiTicketsGet'],
    ['PATCH',  '#^/tickets/(\d+)$#',                       ['tickets', 'update'],             'apiTicketsUpdate'],
    ['DELETE', '#^/tickets/(\d+)$#',                       ['tickets', 'delete'],             'apiTicketsDelete'],
    ['POST',   '#^/tickets/(\d+)/restore$#',               ['tickets', 'restore'],            'apiTicketsRestore'],

    ['GET',    '#^/tickets/(\d+)/notes$#',                 ['ticket_notes', 'read'],          'apiTicketNotesList'],
    ['POST',   '#^/tickets/(\d+)/notes$#',                 ['ticket_notes', 'create'],        'apiTicketNotesCreate'],
    ['GET',    '#^/tickets/(\d+)/thread$#',                ['ticket_thread', 'read'],         'apiTicketThreadList'],
    ['GET',    '#^/tickets/(\d+)/audit$#',                 ['ticket_audit', 'read'],          'apiTicketAuditList'],
    ['GET',    '#^/tickets/(\d+)/sla$#',                   ['ticket_sla', 'read'],            'apiTicketSlaGet'],
    ['GET',    '#^/tickets/(\d+)/time-entries$#',          ['ticket_time_entries', 'read'],   'apiTicketTimeEntriesList'],
    ['POST',   '#^/tickets/(\d+)/time-entries$#',          ['ticket_time_entries', 'create'], 'apiTicketTimeEntriesCreate'],
    ['DELETE', '#^/tickets/(\d+)/time-entries/(\d+)$#',    ['ticket_time_entries', 'delete'], 'apiTicketTimeEntriesDelete'],

    ['GET',    '#^/assets$#',                              ['assets', 'read'],                'apiAssetsList'],
    ['POST',   '#^/assets$#',                              ['assets', 'create'],              'apiAssetsCreate'],
    ['GET',    '#^/assets/(\d+)$#',                        ['assets', 'read'],                'apiAssetsGet'],
    ['PATCH',  '#^/assets/(\d+)$#',                        ['assets', 'update'],              'apiAssetsUpdate'],
    ['GET',    '#^/assets/(\d+)/assignments$#',            ['asset_assignments', 'read'],     'apiAssetAssignmentsList'],
    ['POST',   '#^/assets/(\d+)/assignments$#',            ['asset_assignments', 'create'],   'apiAssetAssignmentsCreate'],
    ['DELETE', '#^/assets/(\d+)/assignments/(\d+)$#',      ['asset_assignments', 'delete'],   'apiAssetAssignmentsDelete'],
    ['GET',    '#^/assets/(\d+)/history$#',                ['asset_history', 'read'],         'apiAssetHistoryList'],
    ['GET',    '#^/assets/(\d+)/custody$#',                ['asset_history', 'read'],         'apiAssetCustodyList'],
    ['GET',    '#^/assets/(\d+)/disks$#',                  ['asset_inventory', 'read'],       'apiAssetDisksList'],
    ['GET',    '#^/assets/(\d+)/network-adapters$#',       ['asset_inventory', 'read'],       'apiAssetNetworkAdaptersList'],
    ['GET',    '#^/assets/(\d+)/devices$#',                ['asset_inventory', 'read'],       'apiAssetDevicesList'],
    ['GET',    '#^/assets/(\d+)/software$#',               ['asset_inventory', 'read'],       'apiAssetSoftwareList'],
    ['GET',    '#^/asset-types$#',                         ['reference', 'read'],             'apiAssetTypesList'],
    ['GET',    '#^/asset-statuses$#',                      ['reference', 'read'],             'apiAssetStatusesList'],
    ['GET',    '#^/asset-locations$#',                     ['reference', 'read'],             'apiAssetLocationsList'],
    ['GET',    '#^/suppliers$#',                           ['reference', 'read'],             'apiSuppliersList'],

    ['GET',    '#^/problems$#',                            ['problems', 'read'],              'apiProblemsList'],
    ['POST',   '#^/problems$#',                            ['problems', 'create'],            'apiProblemsCreate'],
    ['GET',    '#^/problems/(\d+)$#',                      ['problems', 'read'],              'apiProblemsGet'],
    ['PATCH',  '#^/problems/(\d+)$#',                      ['problems', 'update'],            'apiProblemsUpdate'],
    ['DELETE', '#^/problems/(\d+)$#',                      ['problems', 'delete'],            'apiProblemsDelete'],
    ['GET',    '#^/problems/(\d+)/notes$#',                ['problem_notes', 'read'],         'apiProblemNotesList'],
    ['POST',   '#^/problems/(\d+)/notes$#',                ['problem_notes', 'create'],       'apiProblemNotesCreate'],
    ['GET',    '#^/problems/(\d+)/audit$#',                ['problem_audit', 'read'],         'apiProblemAuditList'],
    ['POST',   '#^/problems/(\d+)/tickets$#',              ['problem_links', 'create'],       'apiProblemTicketsLink'],
    ['DELETE', '#^/problems/(\d+)/tickets/(\d+)$#',        ['problem_links', 'delete'],       'apiProblemTicketsUnlink'],
    ['POST',   '#^/problems/(\d+)/changes$#',              ['problem_links', 'create'],       'apiProblemChangesLink'],
    ['DELETE', '#^/problems/(\d+)/changes/(\d+)$#',        ['problem_links', 'delete'],       'apiProblemChangesUnlink'],
    ['GET',    '#^/problem-statuses$#',                    ['reference', 'read'],             'apiProblemStatusesList'],
    ['GET',    '#^/problem-priorities$#',                  ['reference', 'read'],             'apiProblemPrioritiesList'],

    ['GET',    '#^/changes$#',                             ['changes', 'read'],               'apiChangesList'],
    ['POST',   '#^/changes$#',                             ['changes', 'create'],             'apiChangesCreate'],
    ['GET',    '#^/changes/(\d+)$#',                       ['changes', 'read'],               'apiChangesGet'],
    ['PATCH',  '#^/changes/(\d+)$#',                       ['changes', 'update'],             'apiChangesUpdate'],
    ['DELETE', '#^/changes/(\d+)$#',                       ['changes', 'delete'],             'apiChangesDelete'],
    ['GET',    '#^/changes/(\d+)/comments$#',              ['change_comments', 'read'],       'apiChangeCommentsList'],
    ['POST',   '#^/changes/(\d+)/comments$#',              ['change_comments', 'create'],     'apiChangeCommentsCreate'],
    ['DELETE', '#^/changes/(\d+)/comments/(\d+)$#',        ['change_comments', 'delete'],     'apiChangeCommentsDelete'],
    ['GET',    '#^/changes/(\d+)/audit$#',                 ['change_audit', 'read'],          'apiChangeAuditList'],
    ['GET',    '#^/changes/(\d+)/cab$#',                   ['change_cab', 'read'],            'apiChangeCabGet'],
    ['POST',   '#^/changes/(\d+)/cab$#',                   ['change_cab', 'manage'],          'apiChangeCabSave'],
    ['POST',   '#^/changes/(\d+)/cab/vote$#',              ['change_cab', 'vote'],            'apiChangeCabVote'],
    ['GET',    '#^/change-statuses$#',                     ['reference', 'read'],             'apiChangeStatusesList'],
    ['GET',    '#^/change-types$#',                        ['reference', 'read'],             'apiChangeTypesList'],
    ['GET',    '#^/change-priorities$#',                   ['reference', 'read'],             'apiChangePrioritiesList'],
    ['GET',    '#^/change-impacts$#',                      ['reference', 'read'],             'apiChangeImpactsList'],
    ['GET',    '#^/change-categories$#',                   ['reference', 'read'],             'apiChangeCategoriesList'],

    ['GET',    '#^/knowledge/articles$#',                  ['knowledge', 'read'],             'apiKnowledgeArticlesList'],
    ['POST',   '#^/knowledge/articles$#',                  ['knowledge', 'create'],           'apiKnowledgeArticlesCreate'],
    ['GET',    '#^/knowledge/articles/(\d+)$#',            ['knowledge', 'read'],             'apiKnowledgeArticlesGet'],
    ['PATCH',  '#^/knowledge/articles/(\d+)$#',            ['knowledge', 'update'],           'apiKnowledgeArticlesUpdate'],
    ['DELETE', '#^/knowledge/articles/(\d+)$#',            ['knowledge', 'delete'],           'apiKnowledgeArticlesDelete'],
    ['POST',   '#^/knowledge/articles/(\d+)/restore$#',    ['knowledge', 'restore'],          'apiKnowledgeArticlesRestore'],
    ['DELETE', '#^/knowledge/articles/(\d+)/permanent$#',  ['knowledge', 'purge'],            'apiKnowledgeArticlesPurge'],
    ['GET',    '#^/knowledge/articles/(\d+)/versions$#',   ['knowledge_versions', 'read'],    'apiKnowledgeVersionsList'],
    ['GET',    '#^/knowledge/articles/(\d+)/versions/(\d+)$#', ['knowledge_versions', 'read'], 'apiKnowledgeVersionsGet'],
    ['GET',    '#^/knowledge/tags$#',                      ['reference', 'read'],             'apiKnowledgeTagsList'],

    ['GET',    '#^/tasks$#',                               ['tasks', 'read'],                 'apiTasksList'],
    ['POST',   '#^/tasks$#',                               ['tasks', 'create'],               'apiTasksCreate'],
    ['GET',    '#^/tasks/(\d+)$#',                         ['tasks', 'read'],                 'apiTasksGet'],
    ['PATCH',  '#^/tasks/(\d+)$#',                         ['tasks', 'update'],               'apiTasksUpdate'],
    ['DELETE', '#^/tasks/(\d+)$#',                         ['tasks', 'delete'],               'apiTasksDelete'],
    ['POST',   '#^/tasks/(\d+)/move$#',                    ['tasks', 'update'],               'apiTasksMove'],
    ['GET',    '#^/tasks/(\d+)/comments$#',                ['task_comments', 'read'],         'apiTaskCommentsList'],
    ['POST',   '#^/tasks/(\d+)/comments$#',                ['task_comments', 'create'],       'apiTaskCommentsCreate'],
    ['GET',    '#^/task-statuses$#',                       ['reference', 'read'],             'apiTaskStatusesList'],
    ['GET',    '#^/task-priorities$#',                     ['reference', 'read'],             'apiTaskPrioritiesList'],
    ['GET',    '#^/task-tags$#',                           ['reference', 'read'],             'apiTaskTagsList'],

    ['GET',    '#^/cmdb/classes$#',                        ['cmdb_classes', 'read'],          'apiCmdbClassesList'],
    ['GET',    '#^/cmdb/classes/(\d+)$#',                  ['cmdb_classes', 'read'],          'apiCmdbClassesGet'],
    ['GET',    '#^/cmdb/objects$#',                        ['cmdb_objects', 'read'],          'apiCmdbObjectsList'],
    ['POST',   '#^/cmdb/objects$#',                        ['cmdb_objects', 'create'],        'apiCmdbObjectsCreate'],
    ['GET',    '#^/cmdb/objects/(\d+)$#',                  ['cmdb_objects', 'read'],          'apiCmdbObjectsGet'],
    ['PATCH',  '#^/cmdb/objects/(\d+)$#',                  ['cmdb_objects', 'update'],        'apiCmdbObjectsUpdate'],
    ['DELETE', '#^/cmdb/objects/(\d+)$#',                  ['cmdb_objects', 'delete'],        'apiCmdbObjectsDelete'],
    ['GET',    '#^/cmdb/objects/(\d+)/impact$#',           ['cmdb_objects', 'read'],          'apiCmdbObjectImpact'],
    ['POST',   '#^/cmdb/objects/(\d+)/relationships$#',    ['cmdb_relationships', 'create'],  'apiCmdbRelationshipsCreate'],
    ['DELETE', '#^/cmdb/objects/(\d+)/relationships/(\d+)$#', ['cmdb_relationships', 'delete'], 'apiCmdbRelationshipsDelete'],
    ['GET',    '#^/cmdb/objects/(\d+)/tickets$#',          ['cmdb_ticket_links', 'read'],     'apiCmdbObjectTicketsList'],
    ['POST',   '#^/cmdb/objects/(\d+)/tickets$#',          ['cmdb_ticket_links', 'create'],   'apiCmdbObjectTicketsLink'],
    ['DELETE', '#^/cmdb/objects/(\d+)/tickets/(\d+)$#',    ['cmdb_ticket_links', 'delete'],   'apiCmdbObjectTicketsUnlink'],
    ['GET',    '#^/cmdb-relationship-types$#',             ['reference', 'read'],             'apiCmdbRelationshipTypesList'],

    ['GET',    '#^/contracts$#',                           ['contracts', 'read'],             'apiContractsList'],
    ['POST',   '#^/contracts$#',                           ['contracts', 'create'],           'apiContractsCreate'],
    ['GET',    '#^/contracts/(\d+)$#',                     ['contracts', 'read'],             'apiContractsGet'],
    ['PATCH',  '#^/contracts/(\d+)$#',                     ['contracts', 'update'],           'apiContractsUpdate'],
    ['DELETE', '#^/contracts/(\d+)$#',                     ['contracts', 'delete'],           'apiContractsDelete'],
    ['GET',    '#^/contracts/(\d+)/terms$#',               ['contract_terms', 'read'],        'apiContractTermsGet'],
    ['POST',   '#^/contracts/(\d+)/terms$#',               ['contract_terms', 'update'],      'apiContractTermsSave'],
    ['POST',   '#^/suppliers$#',                           ['suppliers', 'create'],           'apiSuppliersCreate'],
    ['GET',    '#^/suppliers/(\d+)$#',                     ['suppliers', 'read'],             'apiSuppliersGet'],
    ['PATCH',  '#^/suppliers/(\d+)$#',                     ['suppliers', 'update'],           'apiSuppliersUpdate'],
    ['DELETE', '#^/suppliers/(\d+)$#',                     ['suppliers', 'delete'],           'apiSuppliersDelete'],
    ['GET',    '#^/suppliers/(\d+)/contacts$#',            ['supplier_contacts', 'read'],     'apiSupplierContactsList'],
    ['POST',   '#^/suppliers/(\d+)/contacts$#',            ['supplier_contacts', 'create'],   'apiSupplierContactsCreate'],
    ['PATCH',  '#^/suppliers/(\d+)/contacts/(\d+)$#',      ['supplier_contacts', 'update'],   'apiSupplierContactsUpdate'],
    ['DELETE', '#^/suppliers/(\d+)/contacts/(\d+)$#',      ['supplier_contacts', 'delete'],   'apiSupplierContactsDelete'],
    ['GET',    '#^/contract-statuses$#',                   ['reference', 'read'],             'apiContractStatusesList'],
    ['GET',    '#^/payment-schedules$#',                   ['reference', 'read'],             'apiPaymentSchedulesList'],
    ['GET',    '#^/supplier-types$#',                      ['reference', 'read'],             'apiSupplierTypesList'],
    ['GET',    '#^/supplier-statuses$#',                   ['reference', 'read'],             'apiSupplierStatusesList'],
    ['GET',    '#^/contract-term-tabs$#',                  ['reference', 'read'],             'apiContractTermTabsList'],

    ['GET',    '#^/calendar/events$#',                     ['calendar_events', 'read'],       'apiCalendarEventsList'],
    ['POST',   '#^/calendar/events$#',                     ['calendar_events', 'create'],     'apiCalendarEventsCreate'],
    ['GET',    '#^/calendar/events/(\d+)$#',               ['calendar_events', 'read'],       'apiCalendarEventsGet'],
    ['PATCH',  '#^/calendar/events/(\d+)$#',               ['calendar_events', 'update'],     'apiCalendarEventsUpdate'],
    ['DELETE', '#^/calendar/events/(\d+)$#',               ['calendar_events', 'delete'],     'apiCalendarEventsDelete'],
    ['GET',    '#^/calendar-categories$#',                 ['reference', 'read'],             'apiCalendarCategoriesList'],

    ['GET',    '#^/software/apps$#',                       ['software_inventory', 'read'],    'apiSoftwareAppsList'],
    ['GET',    '#^/software/apps/(\d+)$#',                 ['software_inventory', 'read'],    'apiSoftwareAppsGet'],
    ['GET',    '#^/software/apps/(\d+)/machines$#',        ['software_inventory', 'read'],    'apiSoftwareAppMachinesList'],
    ['GET',    '#^/software/licences$#',                   ['software_licences', 'read'],     'apiSoftwareLicencesList'],
    ['POST',   '#^/software/licences$#',                   ['software_licences', 'create'],   'apiSoftwareLicencesCreate'],
    ['GET',    '#^/software/licences/(\d+)$#',             ['software_licences', 'read'],     'apiSoftwareLicencesGet'],
    ['PATCH',  '#^/software/licences/(\d+)$#',             ['software_licences', 'update'],   'apiSoftwareLicencesUpdate'],
    ['DELETE', '#^/software/licences/(\d+)$#',             ['software_licences', 'delete'],   'apiSoftwareLicencesDelete'],

    ['GET',    '#^/service-status/services$#',             ['services', 'read'],              'apiStatusServicesList'],
    ['POST',   '#^/service-status/services$#',             ['services', 'create'],            'apiStatusServicesCreate'],
    ['GET',    '#^/service-status/services/(\d+)$#',       ['services', 'read'],              'apiStatusServicesGet'],
    ['PATCH',  '#^/service-status/services/(\d+)$#',       ['services', 'update'],            'apiStatusServicesUpdate'],
    ['DELETE', '#^/service-status/services/(\d+)$#',       ['services', 'delete'],            'apiStatusServicesDelete'],
    ['GET',    '#^/service-status/incidents$#',            ['service_incidents', 'read'],     'apiStatusIncidentsList'],
    ['POST',   '#^/service-status/incidents$#',            ['service_incidents', 'create'],   'apiStatusIncidentsCreate'],
    ['GET',    '#^/service-status/incidents/(\d+)$#',      ['service_incidents', 'read'],     'apiStatusIncidentsGet'],
    ['PATCH',  '#^/service-status/incidents/(\d+)$#',      ['service_incidents', 'update'],   'apiStatusIncidentsUpdate'],
    ['DELETE', '#^/service-status/incidents/(\d+)$#',      ['service_incidents', 'delete'],   'apiStatusIncidentsDelete'],
    ['GET',    '#^/service-incident-statuses$#',           ['reference', 'read'],             'apiServiceIncidentStatusesList'],
    ['GET',    '#^/service-impact-levels$#',               ['reference', 'read'],             'apiServiceImpactLevelsList'],

    ['GET',    '#^/users$#',                               ['users', 'read'],                 'apiUsersList'],
    ['POST',   '#^/users$#',                               ['users', 'create'],               'apiUsersCreate'],
    ['GET',    '#^/users/(\d+)$#',                         ['users', 'read'],                 'apiUsersGet'],
    ['PATCH',  '#^/users/(\d+)$#',                         ['users', 'update'],               'apiUsersUpdate'],

    ['GET',    '#^/analysts$#',                            ['analysts', 'read'],              'apiAnalystsList'],
    ['GET',    '#^/companies$#',                           ['companies', 'read'],             'apiCompaniesList'],
    ['GET',    '#^/statuses$#',                            ['reference', 'read'],             'apiStatusesList'],
    ['GET',    '#^/priorities$#',                          ['reference', 'read'],             'apiPrioritiesList'],
    ['GET',    '#^/ticket-types$#',                        ['reference', 'read'],             'apiTicketTypesList'],
    ['GET',    '#^/origins$#',                             ['reference', 'read'],             'apiOriginsList'],
    ['GET',    '#^/departments$#',                         ['reference', 'read'],             'apiDepartmentsList'],
];

// --- Dispatch ---------------------------------------------------------------
try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    apiError(500, 'server_error', 'Database connection failed.');
}

$apiKey = apiAuthenticate($conn);

$allowedForPath = [];
foreach ($routes as [$routeMethod, $pattern, $permission, $handler]) {
    if (!preg_match($pattern, $path, $matches)) {
        continue;
    }
    if ($routeMethod !== $method) {
        $allowedForPath[] = $routeMethod;
        continue;
    }
    if ($permission !== null) {
        apiRequirePermission($apiKey, $permission[0], $permission[1]);
    }
    array_shift($matches); // drop the full-match element
    try {
        $handler($conn, $apiKey, array_map('intval', $matches), apiJsonBody());
    } catch (Exception $e) {
        error_log('API v1 handler error [' . $method . ' ' . $path . ']: ' . $e->getMessage());
        apiError(500, 'server_error', 'An unexpected server error occurred.');
    }
    exit; // handlers respond + exit themselves; belt and braces
}

if ($allowedForPath) {
    header('Allow: ' . implode(', ', array_unique($allowedForPath)));
    apiError(405, 'method_not_allowed', "Method {$method} is not allowed for {$path}.");
}
apiError(404, 'not_found', "Unknown endpoint: {$method} {$path}. See System > API > Documentation.");

// --- Meta handlers -----------------------------------------------------------

/** GET / — version + endpoint index. */
function apiHandleRoot(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond([
        'name'      => 'FreeITSM API',
        'version'   => 1,
        'endpoints' => [
            'GET /ping', 'GET /tickets', 'POST /tickets', 'GET /tickets/{id}', 'PATCH /tickets/{id}',
            'DELETE /tickets/{id}', 'POST /tickets/{id}/restore', 'GET /tickets/{id}/notes',
            'POST /tickets/{id}/notes', 'GET /tickets/{id}/thread', 'GET /tickets/{id}/audit',
            'GET /tickets/{id}/sla', 'GET /tickets/{id}/time-entries', 'POST /tickets/{id}/time-entries',
            'DELETE /tickets/{id}/time-entries/{entry_id}',
            'GET /assets', 'POST /assets', 'GET /assets/{id}', 'PATCH /assets/{id}',
            'GET /assets/{id}/assignments', 'POST /assets/{id}/assignments',
            'DELETE /assets/{id}/assignments/{user_id}', 'GET /assets/{id}/history',
            'GET /assets/{id}/custody', 'GET /assets/{id}/disks', 'GET /assets/{id}/network-adapters',
            'GET /assets/{id}/devices', 'GET /assets/{id}/software',
            'GET /problems', 'POST /problems', 'GET /problems/{id}', 'PATCH /problems/{id}',
            'DELETE /problems/{id}', 'GET /problems/{id}/notes', 'POST /problems/{id}/notes',
            'GET /problems/{id}/audit', 'POST /problems/{id}/tickets',
            'DELETE /problems/{id}/tickets/{ticket_id}', 'POST /problems/{id}/changes',
            'DELETE /problems/{id}/changes/{change_id}',
            'GET /problem-statuses', 'GET /problem-priorities',
            'GET /changes', 'POST /changes', 'GET /changes/{id}', 'PATCH /changes/{id}',
            'DELETE /changes/{id}', 'GET /changes/{id}/comments', 'POST /changes/{id}/comments',
            'DELETE /changes/{id}/comments/{comment_id}', 'GET /changes/{id}/audit',
            'GET /changes/{id}/cab', 'POST /changes/{id}/cab', 'POST /changes/{id}/cab/vote',
            'GET /change-statuses', 'GET /change-types', 'GET /change-priorities',
            'GET /change-impacts', 'GET /change-categories',
            'GET /knowledge/articles', 'POST /knowledge/articles', 'GET /knowledge/articles/{id}',
            'PATCH /knowledge/articles/{id}', 'DELETE /knowledge/articles/{id}',
            'POST /knowledge/articles/{id}/restore', 'DELETE /knowledge/articles/{id}/permanent',
            'GET /knowledge/articles/{id}/versions', 'GET /knowledge/articles/{id}/versions/{version}',
            'GET /knowledge/tags',
            'GET /tasks', 'POST /tasks', 'GET /tasks/{id}', 'PATCH /tasks/{id}', 'DELETE /tasks/{id}',
            'POST /tasks/{id}/move', 'GET /tasks/{id}/comments', 'POST /tasks/{id}/comments',
            'GET /task-statuses', 'GET /task-priorities', 'GET /task-tags',
            'GET /cmdb/classes', 'GET /cmdb/classes/{id}', 'GET /cmdb/objects', 'POST /cmdb/objects',
            'GET /cmdb/objects/{id}', 'PATCH /cmdb/objects/{id}', 'DELETE /cmdb/objects/{id}',
            'GET /cmdb/objects/{id}/impact', 'POST /cmdb/objects/{id}/relationships',
            'DELETE /cmdb/objects/{id}/relationships/{rel_id}', 'GET /cmdb/objects/{id}/tickets',
            'POST /cmdb/objects/{id}/tickets', 'DELETE /cmdb/objects/{id}/tickets/{ticket_id}',
            'GET /cmdb-relationship-types',
            'GET /contracts', 'POST /contracts', 'GET /contracts/{id}', 'PATCH /contracts/{id}',
            'DELETE /contracts/{id}', 'GET /contracts/{id}/terms', 'POST /contracts/{id}/terms',
            'POST /suppliers', 'GET /suppliers/{id}', 'PATCH /suppliers/{id}', 'DELETE /suppliers/{id}',
            'GET /suppliers/{id}/contacts', 'POST /suppliers/{id}/contacts',
            'PATCH /suppliers/{id}/contacts/{contact_id}', 'DELETE /suppliers/{id}/contacts/{contact_id}',
            'GET /contract-statuses', 'GET /payment-schedules', 'GET /supplier-types',
            'GET /supplier-statuses', 'GET /contract-term-tabs',
            'GET /calendar/events', 'POST /calendar/events', 'GET /calendar/events/{id}',
            'PATCH /calendar/events/{id}', 'DELETE /calendar/events/{id}', 'GET /calendar-categories',
            'GET /software/apps', 'GET /software/apps/{id}', 'GET /software/apps/{id}/machines',
            'GET /software/licences', 'POST /software/licences', 'GET /software/licences/{id}',
            'PATCH /software/licences/{id}', 'DELETE /software/licences/{id}',
            'GET /service-status/services', 'POST /service-status/services',
            'GET /service-status/services/{id}', 'PATCH /service-status/services/{id}',
            'DELETE /service-status/services/{id}', 'GET /service-status/incidents',
            'POST /service-status/incidents', 'GET /service-status/incidents/{id}',
            'PATCH /service-status/incidents/{id}', 'DELETE /service-status/incidents/{id}',
            'GET /service-incident-statuses', 'GET /service-impact-levels',
            'GET /users', 'POST /users', 'GET /users/{id}',
            'PATCH /users/{id}', 'GET /analysts', 'GET /companies', 'GET /statuses', 'GET /priorities',
            'GET /ticket-types', 'GET /origins', 'GET /departments',
            'GET /asset-types', 'GET /asset-statuses', 'GET /asset-locations', 'GET /suppliers',
        ],
    ]);
}

/** GET /ping — auth check + what this key can do. */
function apiHandlePing(PDO $conn, array $apiKey, array $params, array $body): void {
    $companies = null; // null = all companies
    if ($apiKey['company_scope'] !== null) {
        $companies = [];
        foreach ($apiKey['company_scope'] as $tid) {
            $t = getTenantById($conn, $tid);
            if ($t) $companies[] = ['id' => $t['id'], 'name' => $t['name']];
        }
    }
    apiRespond([
        'ok'  => true,
        'key' => [
            'name'        => $apiKey['name'],
            'acts_as'     => $apiKey['analyst_name'],
            'permissions' => $apiKey['permissions'],
            'companies'   => $companies,
            'expires_at'  => apiIsoDate($apiKey['expires_at']),
        ],
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}
