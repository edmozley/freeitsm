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
require_once __DIR__ . '/resources/domains.php';
require_once __DIR__ . '/resources/cost_centres.php';
require_once __DIR__ . '/resources/projects.php';
require_once __DIR__ . '/resources/calendar.php';
require_once __DIR__ . '/resources/software.php';
require_once __DIR__ . '/resources/service_status.php';
require_once __DIR__ . '/resources/morning_checks.php';
require_once __DIR__ . '/resources/forms.php';
require_once __DIR__ . '/resources/workflows.php';
require_once __DIR__ . '/resources/network_mapper.php'; // needs cmdb.php (above) for apiCmdbClassDefs

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
$routes = require __DIR__ . '/lib/routes.php';

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
            'GET /domains', 'POST /domains', 'GET /domains/{id}', 'PATCH /domains/{id}', 'DELETE /domains/{id}',
            'POST /domains/{id}/lookup', 'POST /domains/{id}/check', 'GET /domains/{id}/findings', 'GET /domains/{id}/history',
            'GET /domain-statuses', 'GET /domain-registrar-accounts',
            'GET /cost-centres', 'POST /cost-centres', 'POST /cost-centres/sync', 'GET /cost-centres/{id}',
            'PATCH /cost-centres/{id}', 'DELETE /cost-centres/{id}',
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
            'GET /morning-checks/checks', 'POST /morning-checks/checks',
            'GET /morning-checks/checks/{id}', 'PATCH /morning-checks/checks/{id}',
            'DELETE /morning-checks/checks/{id}', 'GET /morning-checks/board',
            'GET /morning-checks/results', 'POST /morning-checks/results',
            'GET /morning-checks/results/{id}', 'GET /morning-check-statuses',
            'GET /forms', 'POST /forms', 'GET /forms/{id}', 'PATCH /forms/{id}', 'DELETE /forms/{id}',
            'GET /forms/{id}/versions', 'POST /forms/{id}/versions',
            'GET /forms/{id}/submissions', 'POST /forms/{id}/submissions',
            'GET /forms/{id}/submissions/{submission_id}', 'DELETE /forms/{id}/submissions/{submission_id}',
            'GET /workflows', 'POST /workflows', 'GET /workflows/{id}', 'PATCH /workflows/{id}',
            'DELETE /workflows/{id}', 'POST /workflows/{id}/fire', 'GET /workflows/{id}/executions',
            'GET /workflow-executions', 'GET /workflow-executions/{id}',
            'GET /workflow-triggers', 'GET /workflow-actions',
            'GET /network-diagrams', 'POST /network-diagrams', 'GET /network-diagrams/{id}',
            'PATCH /network-diagrams/{id}', 'DELETE /network-diagrams/{id}',
            'GET /network-diagrams/{id}/versions', 'POST /network-diagrams/{id}/versions',
            'GET /network-diagrams/{id}/suggestions',
            'POST /network-diagrams/{id}/nodes', 'PATCH /network-diagrams/{id}/nodes/{node_id}',
            'DELETE /network-diagrams/{id}/nodes/{node_id}',
            'POST /network-diagrams/{id}/connectors', 'PATCH /network-diagrams/{id}/connectors/{connector_id}',
            'DELETE /network-diagrams/{id}/connectors/{connector_id}', 'GET /cmdb-icons',
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
