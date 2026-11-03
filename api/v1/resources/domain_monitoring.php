<?php
/**
 * FreeITSM REST API v1 - internal monitoring on domains (GH #243, #203 slice 3).
 *
 *   /domains/{id}/endpoints           HTTPS endpoints (and their optional HTTP check)
 *   /domains/{id}/expected-records    expected DNS records
 *   /domain-trust-profiles            the certificate authorities endpoints are checked against
 *
 * Every write goes through DomainsService - the same validation, company check and
 * history rows as the Health tab. Reading a domain's endpoints and expected records
 * needs only domains.read (results, as everybody who can see the domain sees them).
 *
 * 🔴 CHANGING ANY OF IT needs the domains.internal permission on the key AND the
 * Internal monitoring capability on the key's analyst: endpoints decide which internal
 * addresses the server connects to, so a key can never do here what its analyst could
 * not do on screen. Trust profiles are shared by every company, so changing them also
 * needs a key that is not limited to some companies.
 *
 * PATCH is partial: omitted fields keep their stored values, then the full save rules run.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/domains.php';
require_once dirname(__DIR__, 3) . '/includes/capabilities.php';
require_once dirname(__DIR__, 3) . '/includes/rbac.php';   // analystHasCapability()

/** 403 unless the key's analyst holds the Internal monitoring capability (the screen's rule). */
function apiDomainRequireInternal(PDO $conn, array $apiKey): void {
    if (!analystHasCapability($conn, (int)$apiKey['analyst_id'], Cap::DOMAINS_INTERNAL)) {
        apiError(403, 'forbidden', 'The analyst this key acts as does not have the Internal monitoring permission.');
    }
}

/** 422 until Database Verification has made the internal-monitoring tables. */
function apiDomainRequireMonitoringReady(PDO $conn): void {
    if (!domainEndpointsReady($conn)) apiError(422, 'not_ready', 'Run System -> Database Verification first.');
}

/** An endpoint as the API shows it. */
function apiSerializeEndpoint(array $r): array {
    return [
        'id'                  => (int)$r['id'],
        'domain_id'           => (int)$r['domain_id'],
        'host'                => $r['host'],
        'port'                => (int)$r['port'],
        'warn_days'           => (int)$r['warn_days'],
        'trust_profile_id'    => $r['trust_profile_id'] !== null ? (int)$r['trust_profile_id'] : null,
        'http_path'           => $r['http_path'] ?? null,
        'http_expect'         => $r['http_expect'] ?? null,
        'status'              => $r['status'],
        'not_checked_since'   => (bool)(int)$r['incomplete'],
        'detail'              => $r['detail_key'],
        'address'             => $r['address'],
        'certificate'         => $r['cert_json'] ? json_decode($r['cert_json'], true) : null,
        'last_attempt_at'     => apiIsoDate($r['last_attempt_datetime']),
        'last_completed_at'   => apiIsoDate($r['last_completed_datetime']),
        'last_healthy_at'     => apiIsoDate($r['last_healthy_datetime']),
    ];
}

/** An expected record as the API shows it: values as a list, empty when the type only has to exist. */
function apiSerializeExpected(array $r, int $domainId): array {
    return [
        'id'              => (int)$r['id'],
        'domain_id'       => $domainId,
        'host'            => $r['host'],
        'record_type'     => $r['record_type'],
        'expected_values' => $r['expected_values'] !== null && $r['expected_values'] !== '' ? explode("\n", $r['expected_values']) : [],
    ];
}

/** A trust profile as the API shows it: its CAs' names and dates, never the PEM itself. */
function apiSerializeTrustProfile(array $r): array {
    return [
        'id'         => (int)$r['id'],
        'name'       => $r['name'],
        'summary'    => $r['summary'],
        'used_by'    => isset($r['used_by']) ? (int)$r['used_by'] : null,
        'updated_at' => apiIsoDate($r['updated_datetime'] ?? $r['created_datetime'] ?? null),
    ];
}

/** A domain's endpoint by id, 404 when it is not this domain's. */
function apiLoadEndpoint(PDO $conn, int $domainId, int $id): array {
    $s = $conn->prepare("SELECT * FROM domain_endpoints WHERE id = ? AND domain_id = ?");
    $s->execute([$id, $domainId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: apiError(404, 'not_found', 'Endpoint not found.');
}

/** A domain's expected record by id, 404 when it is not this domain's. */
function apiLoadExpected(PDO $conn, int $domainId, int $id): array {
    if (!DomainsService::expectedReady($conn)) apiError(422, 'not_ready', 'Run System -> Database Verification first.');
    $s = $conn->prepare("SELECT * FROM domain_expected_records WHERE id = ? AND domain_id = ?");
    $s->execute([$id, $domainId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: apiError(404, 'not_found', 'Expected record not found.');
}

/** expected_values may arrive as a list or as text, one per line. */
function apiExpectedValuesText($v): string {
    return is_array($v) ? implode("\n", array_map('strval', $v)) : (string)($v ?? '');
}

// ---------------------------------------------------------------------------
// /domains/{id}/endpoints
// ---------------------------------------------------------------------------
function apiDomainEndpointsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $d = apiLoadDomain($conn, $apiKey, (int)$params[0]);
    if (!domainEndpointsReady($conn)) apiRespond([]);
    $s = $conn->prepare("SELECT * FROM domain_endpoints WHERE domain_id = ? ORDER BY host, port");
    $s->execute([(int)$d['id']]);
    apiRespond(array_map('apiSerializeEndpoint', $s->fetchAll(PDO::FETCH_ASSOC)));
}

function apiDomainEndpointsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $d = apiLoadDomain($conn, $apiKey, (int)$params[0]);
    apiDomainRequireInternal($conn, $apiKey);
    apiDomainRequireMonitoringReady($conn);
    try {
        $row = DomainsService::saveEndpoint($conn, ActorContext::fromApiKey($apiKey), [
            'domain_id' => (int)$d['id'], 'host' => $body['host'] ?? '', 'port' => $body['port'] ?? 443,
            'warn_days' => $body['warn_days'] ?? 21, 'trust_profile_id' => $body['trust_profile_id'] ?? null,
            'http_path' => $body['http_path'] ?? '', 'http_expect' => $body['http_expect'] ?? '',
        ]);
        apiRespond(apiSerializeEndpoint($row), 201);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

function apiDomainEndpointsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $d = apiLoadDomain($conn, $apiKey, (int)$params[0]);
    apiDomainRequireInternal($conn, $apiKey);
    apiDomainRequireMonitoringReady($conn);
    $cur = apiLoadEndpoint($conn, (int)$d['id'], (int)$params[1]);
    $pick = fn(string $k) => array_key_exists($k, $body) ? $body[$k] : ($cur[$k] ?? null);
    try {
        $row = DomainsService::saveEndpoint($conn, ActorContext::fromApiKey($apiKey), [
            'id' => (int)$cur['id'], 'domain_id' => (int)$d['id'], 'host' => $pick('host'), 'port' => $pick('port'),
            'warn_days' => $pick('warn_days'), 'trust_profile_id' => $pick('trust_profile_id'),
            'http_path' => $pick('http_path') ?? '', 'http_expect' => $pick('http_expect') ?? '',
        ]);
        apiRespond(apiSerializeEndpoint($row));
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

function apiDomainEndpointsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    $d = apiLoadDomain($conn, $apiKey, (int)$params[0]);
    apiDomainRequireInternal($conn, $apiKey);
    apiDomainRequireMonitoringReady($conn);
    $cur = apiLoadEndpoint($conn, (int)$d['id'], (int)$params[1]);
    try {
        DomainsService::deleteEndpoint($conn, ActorContext::fromApiKey($apiKey), (int)$cur['id']);
        apiRespond(['id' => (int)$cur['id'], 'deleted' => true]);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// /domains/{id}/expected-records
// ---------------------------------------------------------------------------
function apiDomainExpectedList(PDO $conn, array $apiKey, array $params, array $body): void {
    $d = apiLoadDomain($conn, $apiKey, (int)$params[0]);
    apiRespond(array_map(fn($r) => apiSerializeExpected($r, (int)$d['id']), DomainsService::expectedRecords($conn, (int)$d['id'])));
}

function apiDomainExpectedCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $d = apiLoadDomain($conn, $apiKey, (int)$params[0]);
    apiDomainRequireInternal($conn, $apiKey);
    try {
        $row = DomainsService::saveExpectedRecord($conn, ActorContext::fromApiKey($apiKey), [
            'domain_id' => (int)$d['id'], 'host' => $body['host'] ?? '', 'record_type' => $body['record_type'] ?? '',
            'expected_values' => apiExpectedValuesText($body['expected_values'] ?? null),
        ]);
        apiRespond(apiSerializeExpected($row, (int)$d['id']), 201);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

function apiDomainExpectedUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $d = apiLoadDomain($conn, $apiKey, (int)$params[0]);
    apiDomainRequireInternal($conn, $apiKey);
    $cur = apiLoadExpected($conn, (int)$d['id'], (int)$params[1]);
    try {
        $row = DomainsService::saveExpectedRecord($conn, ActorContext::fromApiKey($apiKey), [
            'id' => (int)$cur['id'], 'domain_id' => (int)$d['id'],
            'host' => array_key_exists('host', $body) ? $body['host'] : $cur['host'],
            'record_type' => array_key_exists('record_type', $body) ? $body['record_type'] : $cur['record_type'],
            'expected_values' => array_key_exists('expected_values', $body) ? apiExpectedValuesText($body['expected_values']) : (string)$cur['expected_values'],
        ]);
        apiRespond(apiSerializeExpected($row, (int)$d['id']));
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

function apiDomainExpectedDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    $d = apiLoadDomain($conn, $apiKey, (int)$params[0]);
    apiDomainRequireInternal($conn, $apiKey);
    $cur = apiLoadExpected($conn, (int)$d['id'], (int)$params[1]);
    try {
        DomainsService::deleteExpectedRecord($conn, ActorContext::fromApiKey($apiKey), (int)$cur['id']);
        apiRespond(['id' => (int)$cur['id'], 'deleted' => true]);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// /domain-trust-profiles - one list for every company
// ---------------------------------------------------------------------------

/** Trust profiles serve every company, so only a key with no company limit may change them. */
function apiDomainRequireAllCompanies(PDO $conn, array $apiKey): void {
    if (isMultiTenant($conn) && $apiKey['company_scope'] !== null) {
        apiError(403, 'forbidden', 'Trust profiles are shared by every company; only a key that is not limited to some companies may change them.');
    }
}

function apiLoadTrustProfile(PDO $conn, int $id): array {
    $s = $conn->prepare("SELECT p.*, (SELECT COUNT(*) FROM domain_endpoints e WHERE e.trust_profile_id = p.id) AS used_by FROM domain_trust_profiles p WHERE p.id = ?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: apiError(404, 'not_found', 'Trust profile not found.');
}

function apiDomainTrustProfilesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiDomainRequireInternal($conn, $apiKey);
    if (!domainEndpointsReady($conn)) apiRespond([]);
    $rows = $conn->query("SELECT p.*, (SELECT COUNT(*) FROM domain_endpoints e WHERE e.trust_profile_id = p.id) AS used_by
                            FROM domain_trust_profiles p ORDER BY p.name")->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map('apiSerializeTrustProfile', $rows));
}

function apiDomainTrustProfilesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiDomainRequireInternal($conn, $apiKey);
    apiDomainRequireAllCompanies($conn, $apiKey);
    apiDomainRequireMonitoringReady($conn);
    try {
        $row = DomainsService::saveTrustProfile($conn, ActorContext::fromApiKey($apiKey), ['name' => $body['name'] ?? '', 'ca_pem' => $body['ca_pem'] ?? '']);
        apiRespond(apiSerializeTrustProfile(apiLoadTrustProfile($conn, (int)$row['id'])), 201);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

function apiDomainTrustProfilesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiDomainRequireInternal($conn, $apiKey);
    apiDomainRequireAllCompanies($conn, $apiKey);
    apiDomainRequireMonitoringReady($conn);
    $cur = apiLoadTrustProfile($conn, (int)$params[0]);
    try {
        // No ca_pem keeps the certificates, as on screen: a rename only.
        DomainsService::saveTrustProfile($conn, ActorContext::fromApiKey($apiKey), [
            'id' => (int)$cur['id'], 'name' => array_key_exists('name', $body) ? $body['name'] : $cur['name'], 'ca_pem' => $body['ca_pem'] ?? '',
        ]);
        apiRespond(apiSerializeTrustProfile(apiLoadTrustProfile($conn, (int)$cur['id'])));
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

function apiDomainTrustProfilesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiDomainRequireInternal($conn, $apiKey);
    apiDomainRequireAllCompanies($conn, $apiKey);
    apiDomainRequireMonitoringReady($conn);
    $cur = apiLoadTrustProfile($conn, (int)$params[0]);
    try {
        DomainsService::deleteTrustProfile($conn, (int)$cur['id']);
        apiRespond(['id' => (int)$cur['id'], 'deleted' => true]);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}
