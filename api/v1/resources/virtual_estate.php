<?php
/**
 * FreeITSM REST API v1 - the virtual estate (GH #199 Part A).
 *
 *   POST /virtual-estate/{connection}/reports   a collector's report (virtual_estate.create)
 *   GET  /virtual-estate/{connection}            the connection's state: freshness, counts (virtual_estate.read)
 *
 * Every rule is in VirtualEstateService. This adds the one rule that belongs to transport:
 * a report is accepted only from THE key the connection was given (Assets -> Settings ->
 * Hyper-V), so a key for one estate can never write another's - even with the permission.
 *
 * @wiki Hyper-V-Discovery-Developer-Guide
 */
require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/virtual_estate.php';

/** The connection, if this key may see it (its company scope covers the connection's company). 404 otherwise. */
function apiVirtConnection(PDO $conn, array $apiKey, int $id): array {
    try {
        $c = VirtualEstateService::connection($conn, $id);
    } catch (ServiceError $e) { apiError(404, 'not_found', 'Connection not found.'); }
    $tenant = $c['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$c['tenant_id'];
    if (!apiKeyCanAccessTenant($conn, $apiKey, $tenant)) apiError(404, 'not_found', 'Connection not found.');
    return $c;
}

/**
 * POST /virtual-estate/{connection}/reports. 200 when every section applied (or the report
 * was stale or a replay - both safe), 207 when some sections failed and others applied
 * (send the same report again to retry them), 422 when all failed, 409 for a different
 * report at the same observed time.
 */
function apiVirtReportSubmit(PDO $conn, array $apiKey, array $params, array $body): void {
    $c = apiVirtConnection($conn, $apiKey, (int)$params[0]);
    if ((int)($c['api_key_id'] ?? 0) !== (int)$apiKey['id']) {
        apiError(403, 'forbidden', 'This key is not the key for this connection. Use the key shown when the connection was set up (Assets -> Settings -> Hyper-V).');
    }
    try {
        $r = VirtualEstateService::submit($conn, (int)$c['id'], $body, (int)$apiKey['id']);
    } catch (ServiceError $e) { apiFailFromService($e); }
    $status = $r['status'];
    unset($r['status']);
    apiRespond($r, $status);
}

/** GET /virtual-estate/{connection} - is the collector on time, and what does the estate hold? */
function apiVirtConnectionGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $c = apiVirtConnection($conn, $apiKey, (int)$params[0]);
    $count = function (string $table, string $extra = '') use ($conn, $c): int {
        $st = $conn->prepare("SELECT COUNT(*) FROM $table WHERE connection_id = ? AND not_seen_since IS NULL$extra");
        $st->execute([(int)$c['id']]);
        return (int)$st->fetchColumn();
    };
    $reviews = $conn->prepare("SELECT COUNT(*) FROM virt_identity_reviews WHERE connection_id = ? AND status = 'open'");
    $reviews->execute([(int)$c['id']]);
    $storage = VirtualEstateService::storageInventory($conn, [(int)$c['id']]);
    foreach ($storage as &$cluster) {
        $cluster['last_attempted_at'] = apiIsoDate($cluster['last_attempted_at']);
        $cluster['last_observed_at'] = apiIsoDate($cluster['last_observed_at']);
        foreach ($cluster['volumes'] as &$volume) {
            foreach (['first_seen_at', 'last_seen_observed_at', 'not_seen_since', 'not_confirmed_since'] as $column) {
                $volume[$column] = apiIsoDate($volume[$column]);
            }
        }
        unset($volume);
    }
    unset($cluster);
    apiRespond([
        'id' => (int)$c['id'], 'name' => $c['name'], 'source' => $c['source_kind'], 'active' => (bool)$c['is_active'],
        'expected_interval_minutes' => (int)$c['expected_interval_minutes'], 'grace_minutes' => (int)$c['grace_minutes'],
        'freshness' => VirtualEstateService::freshness($c),
        'last_report_at' => apiIsoDate($c['last_report_at']), 'last_observed_at' => apiIsoDate($c['last_observed_at']),
        'last_outcome' => $c['last_outcome'], 'last_summary' => $c['last_summary'],
        'collector' => $c['collector_json'] ? json_decode($c['collector_json'], true) : null,
        'counts' => ['clusters' => $count('virt_clusters'), 'hosts' => $count('virt_hosts'), 'vms' => $count('virt_vms'),
            'storage_volumes' => $count('virt_storage_volumes'),
            'vms_not_confirmed' => $count('virt_vms', ' AND not_confirmed_since IS NOT NULL')],
        'storage' => $storage,
        'identity_reviews_open' => (int)$reviews->fetchColumn(),
    ]);
}
