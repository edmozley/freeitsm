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
            'DELETE /tickets/{id}/time-entries/{entry_id}', 'GET /users', 'POST /users', 'GET /users/{id}',
            'PATCH /users/{id}', 'GET /analysts', 'GET /companies', 'GET /statuses', 'GET /priorities',
            'GET /ticket-types', 'GET /origins', 'GET /departments',
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
