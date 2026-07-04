<?php
/**
 * FreeITSM REST API v1 — service-status resource (services + incidents).
 *
 * Mirrors the module's internal endpoints:
 *   - a service's state is DERIVED, not stored: the worst impact level
 *     (lowest severity_order) across its open incidents, else Operational —
 *     the services list computes it inline with get_dashboard.php's exact
 *     subquery, so status-page builders get the health board in one call;
 *   - incident writes mirror save_incident.php: status by name (or id),
 *     resolved_datetime stamped once on entering a resolved status
 *     (COALESCE-preserved) and cleared on reopen, affected services replaced
 *     as a set with per-service impact levels;
 *   - deletes replicate the UI's manual junction cleanup — but inside a
 *     transaction (the internal endpoints run two bare statements).
 *
 * One deliberate improvement, documented: unknown service/impact ids in the
 * affected-services array are a 422 (save_incident.php silently skips them —
 * machines deserve the error).
 *
 * This is the module monitoring tools want to DRIVE: flip an incident open
 * when a probe fails, append status, resolve on recovery. Everything here is
 * key-authenticated like the rest of v1 — there is no public unauthenticated
 * status feed in the product (yet). Install-wide; no audit trail exists.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/service_status.php';

/** Map a ServiceError raised by the shared service to the API's error response. */
function apiServiceStatusFail(ServiceError $e): void {
    apiError(serviceErrorHttpStatus($e->kind), $e->errorCode, $e->getMessage());
}

// ---------------------------------------------------------------------------
// Derived status + serializers
// ---------------------------------------------------------------------------

/** get_dashboard.php's exact worst-open-impact subquery, as a SQL fragment. */
function apiServiceStatusSubquery(): string {
    return "COALESCE((
        SELECT il.name FROM status_incident_services sis
        JOIN status_incidents si ON sis.incident_id = si.id
        JOIN service_impact_levels il ON il.id = sis.impact_level_id
        LEFT JOIN service_incident_statuses sst ON sst.id = si.status_id
        WHERE sis.service_id = ss.id AND (sst.is_resolved = 0 OR sst.id IS NULL)
        ORDER BY il.severity_order ASC
        LIMIT 1), 'Operational')";
}

function apiServiceStatusImpactMeta(PDO $conn): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($conn->query("SELECT name, colour, severity_order FROM service_impact_levels") as $row) {
            $cache[$row['name']] = ['colour' => $row['colour'], 'severity_order' => (int)$row['severity_order']];
        }
    }
    return $cache;
}

function apiSerializeService(PDO $conn, array $r): array {
    $meta = apiServiceStatusImpactMeta($conn);
    $statusName = $r['current_status'] ?? 'Operational';
    return [
        'id'            => (int)$r['id'],
        'name'          => $r['name'],
        'description'   => $r['description'],
        'is_active'     => (bool)$r['is_active'],
        'display_order' => (int)$r['display_order'],
        'current_status' => [
            'name'           => $statusName,
            'colour'         => $meta[$statusName]['colour'] ?? null,
            'severity_order' => $meta[$statusName]['severity_order'] ?? null,
        ],
    ];
}

function apiLoadService(PDO $conn, int $serviceId): array {
    $stmt = $conn->prepare(
        "SELECT ss.*, " . apiServiceStatusSubquery() . " AS current_status
         FROM status_services ss WHERE ss.id = ?"
    );
    $stmt->execute([$serviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Service not found.');
    }
    return $row;
}

function apiServiceIncidentSelect(): string {
    return "SELECT si.*, st.name AS status_name, st.is_resolved AS status_is_resolved, st.colour AS status_colour,
                   a.full_name AS created_by_name
            FROM status_incidents si
            LEFT JOIN service_incident_statuses st ON st.id = si.status_id
            LEFT JOIN analysts a ON a.id = si.created_by_id";
}

function apiSerializeIncident(PDO $conn, array $r): array {
    $svc = $conn->prepare(
        "SELECT sis.service_id, ss.name AS service_name,
                il.id AS impact_id, il.name AS impact_name, il.colour AS impact_colour, il.severity_order
         FROM status_incident_services sis
         JOIN status_services ss ON ss.id = sis.service_id
         LEFT JOIN service_impact_levels il ON il.id = sis.impact_level_id
         WHERE sis.incident_id = ? ORDER BY il.severity_order ASC, ss.name"
    );
    $svc->execute([(int)$r['id']]);
    return [
        'id'      => (int)$r['id'],
        'title'   => $r['title'],
        'status'  => $r['status_id'] === null ? null : [
            'id'          => (int)$r['status_id'],
            'name'        => $r['status_name'],
            'is_resolved' => (bool)$r['status_is_resolved'],
            'colour'      => $r['status_colour'],
        ],
        'comment' => $r['comment'],
        'services' => array_map(function ($s) {
            return [
                'service_id' => (int)$s['service_id'],
                'name'       => $s['service_name'],
                'impact'     => $s['impact_id'] === null ? null : [
                    'id'             => (int)$s['impact_id'],
                    'name'           => $s['impact_name'],
                    'colour'         => $s['impact_colour'],
                    'severity_order' => (int)$s['severity_order'],
                ],
            ];
        }, $svc->fetchAll(PDO::FETCH_ASSOC)),
        'created_by'  => $r['created_by_id'] === null ? null : ['id' => (int)$r['created_by_id'], 'name' => $r['created_by_name']],
        'created_at'  => apiIsoDate($r['created_datetime']),
        'updated_at'  => apiIsoDate($r['updated_datetime']),
        'resolved_at' => apiIsoDate($r['resolved_datetime']),
    ];
}

function apiLoadIncident(PDO $conn, int $incidentId): array {
    $stmt = $conn->prepare(apiServiceIncidentSelect() . " WHERE si.id = ?");
    $stmt->execute([$incidentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Incident not found.');
    }
    return $row;
}

// Incident-status resolution and affected-services validation now live in the
// shared ServiceStatusService (used by the UI too) — see includes/services/.

// ---------------------------------------------------------------------------
// Services
// ---------------------------------------------------------------------------

// GET /service-status/services — the health board (derived current_status inline)
function apiStatusServicesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];
    if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
        $where[] = 'ss.is_active = ?';
        $args[]  = $_GET['is_active'] === 'true' ? 1 : 0;
    } else {
        $where[] = 'ss.is_active = 1'; // the dashboard shows active services
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = 'ss.name LIKE ?';
        $args[]  = '%' . trim($_GET['q']) . '%';
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $conn->prepare(
        "SELECT ss.*, " . apiServiceStatusSubquery() . " AS current_status
         FROM status_services ss WHERE $whereSql
         ORDER BY ss.display_order, ss.name"
    );
    $stmt->execute($args);
    apiRespond(array_map(function ($r) use ($conn) {
        return apiSerializeService($conn, $r);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiStatusServicesGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $service = apiSerializeService($conn, apiLoadService($conn, $params[0]));

    // Open incidents touching this service.
    $inc = $conn->prepare(
        apiServiceIncidentSelect() . "
         JOIN status_incident_services sis ON sis.incident_id = si.id AND sis.service_id = ?
         LEFT JOIN service_incident_statuses gate ON gate.id = si.status_id
         WHERE (gate.is_resolved = 0 OR gate.id IS NULL)
         ORDER BY si.updated_datetime DESC"
    );
    $inc->execute([$params[0]]);
    $service['open_incidents'] = array_map(function ($r) use ($conn) {
        return apiSerializeIncident($conn, $r);
    }, $inc->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($service);
}

function apiStatusServicesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $id = ServiceStatusService::saveService($conn, ActorContext::fromApiKey($apiKey), $body);
        apiRespond(apiSerializeService($conn, apiLoadService($conn, $id)), 201);
    } catch (ServiceError $e) { apiServiceStatusFail($e); }
}

function apiStatusServicesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $id = ServiceStatusService::saveService($conn, ActorContext::fromApiKey($apiKey), array_merge($body, ['id' => (int)$params[0]]));
        apiRespond(apiSerializeService($conn, apiLoadService($conn, $id)));
    } catch (ServiceError $e) { apiServiceStatusFail($e); }
}

function apiStatusServicesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ServiceStatusService::deleteService($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(['id' => $params[0], 'deleted' => true]);
    } catch (ServiceError $e) { apiServiceStatusFail($e); }
}

// ---------------------------------------------------------------------------
// Incidents
// ---------------------------------------------------------------------------

// GET /service-status/incidents
function apiStatusIncidentsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    $state = strtolower(trim($_GET['state'] ?? 'all'));
    if ($state === 'open')     $where[] = "(st.is_resolved = 0 OR st.id IS NULL)";
    if ($state === 'resolved') $where[] = "st.is_resolved = 1";

    if (isset($_GET['service_id']) && $_GET['service_id'] !== '') {
        $where[] = 'si.id IN (SELECT incident_id FROM status_incident_services WHERE service_id = ?)';
        $args[]  = (int)$_GET['service_id'];
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = 'si.title LIKE ?';
        $args[]  = '%' . trim($_GET['q']) . '%';
    }
    foreach (['created_since' => 'si.created_datetime', 'resolved_since' => 'si.resolved_datetime'] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col >= ?";
            $args[]  = apiParseDate($_GET[$param], $param);
        }
    }

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) FROM status_incidents si
         LEFT JOIN service_incident_statuses st ON st.id = si.status_id WHERE $whereSql"
    );
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    // Open-first, then most recently updated — the module's ordering.
    $stmt = $conn->prepare(
        apiServiceIncidentSelect() . " WHERE $whereSql
         ORDER BY COALESCE(st.is_resolved, 0) ASC, si.updated_datetime DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($args);
    apiRespond(array_map(function ($r) use ($conn) {
        return apiSerializeIncident($conn, $r);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function apiStatusIncidentsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeIncident($conn, apiLoadIncident($conn, $params[0])));
}

// POST /service-status/incidents
function apiStatusIncidentsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $id = ServiceStatusService::saveIncident($conn, ActorContext::fromApiKey($apiKey), $body);
        apiRespond(apiSerializeIncident($conn, apiLoadIncident($conn, $id)), 201);
    } catch (ServiceError $e) { apiServiceStatusFail($e); }
}

// PATCH /service-status/incidents/{id}
function apiStatusIncidentsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $id = ServiceStatusService::saveIncident($conn, ActorContext::fromApiKey($apiKey), array_merge($body, ['id' => (int)$params[0]]));
        apiRespond(apiSerializeIncident($conn, apiLoadIncident($conn, $id)));
    } catch (ServiceError $e) { apiServiceStatusFail($e); }
}

function apiStatusIncidentsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ServiceStatusService::deleteIncident($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(['id' => $params[0], 'deleted' => true]);
    } catch (ServiceError $e) { apiServiceStatusFail($e); }
}

// ---------------------------------------------------------------------------
// Reference lookups
// ---------------------------------------------------------------------------
function apiServiceIncidentStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, is_resolved, colour, is_default, is_active
         FROM service_incident_statuses ORDER BY display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($s) {
        return [
            'id'          => (int)$s['id'],
            'name'        => $s['name'],
            'is_resolved' => (bool)$s['is_resolved'],
            'colour'      => $s['colour'],
            'is_default'  => (bool)$s['is_default'],
            'is_active'   => (bool)$s['is_active'],
        ];
    }, $rows));
}

function apiServiceImpactLevelsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, colour, is_default, severity_order, is_active
         FROM service_impact_levels ORDER BY severity_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($l) {
        return [
            'id'             => (int)$l['id'],
            'name'           => $l['name'],
            'colour'         => $l['colour'],
            'is_default'     => (bool)$l['is_default'],
            'severity_order' => (int)$l['severity_order'],
            'is_active'      => (bool)$l['is_active'],
        ];
    }, $rows));
}
