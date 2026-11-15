<?php
/**
 * API: Assets -> Settings -> Hyper-V (GH #199 Part A) - the connections a collector reports to,
 * their keys, and the identity changes waiting for a person.
 *
 * GET                                  every connection this analyst's companies reach, with freshness and counts
 * GET  ?reviews=1&connection_id=N      the identity changes waiting for review on one connection
 * POST {action:'save', id?, name, company_id?, interval, grace, retention_days?, is_active}
 *                                      a new connection also gets its key, shown ONCE in the reply
 * POST {action:'new_key', id}          a new key; the old one stops working at once
 * POST {action:'delete', id}           the connection, its estate and its key
 * POST {action:'decide', review_id, decision: same|different}
 *
 * Only for someone holding the Hyper-V settings capability. A connection belongs to one company,
 * and an analyst sees and changes only the connections of companies they can reach.
 *
 * @wiki Hyper-V-Discovery-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/public_url.php';
require_once '../../includes/services/virtual_estate.php';
require_once '../../includes/services/virtual_estate_cmdb.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('assets');
requireCapabilityJson(Cap::ASSETS_HYPERV);

/** Ends the request with a JSON error. */
function hvFail(string $error, ?string $code = null): never
{
    echo json_encode(['success' => false, 'error' => $error] + ($code ? ['code' => $code] : []));
    exit;
}

try {
    $conn = connectToDatabase();
    $analyst = (int)$_SESSION['analyst_id'];
    if (!VirtualEstateService::ready($conn)) { hvFail('Run Database Verification first (System -> Database Verification).', 'not_ready'); }
    $tenantOf = fn(array $c) => $c['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$c['tenant_id'];
    $reach = function (array $c) use ($conn, $analyst, $tenantOf): bool {
        return analystCanAccessTenant($conn, $analyst, $tenantOf($c));
    };
    $load = function (int $id) use ($conn, $reach): array {
        try { $c = VirtualEstateService::connection($conn, $id); } catch (ServiceError $e) { hvFail('Connection not found.'); }
        if (!$reach($c)) { hvFail('Connection not found.'); }
        return $c;
    };

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        if (!empty($_GET['reviews'])) {
            $c = $load((int)($_GET['connection_id'] ?? 0));
            $st = $conn->prepare("SELECT * FROM virt_identity_reviews WHERE connection_id = ? AND status = 'open' ORDER BY raised_at");
            $st->execute([(int)$c['id']]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $table = $r['object_kind'] === 'host' ? 'virt_hosts' : 'virt_vms';
                $get = $conn->prepare("SELECT o.id, o.name, o.external_id, o.first_seen_at, o.last_seen_observed_at, o.not_seen_since"
                    . ($table === 'virt_vms' ? ', h.name AS host_name' : ", NULL AS host_name") . " FROM $table o"
                    . ($table === 'virt_vms' ? ' LEFT JOIN virt_hosts h ON h.id = o.host_id' : '') . ' WHERE o.id = ?');
                $get->execute([(int)$r['old_object_id']]);
                $old = $get->fetch(PDO::FETCH_ASSOC) ?: null;
                $get->execute([(int)$r['new_object_id']]);
                $new = $get->fetch(PDO::FETCH_ASSOC) ?: null;
                $out[] = ['id' => (int)$r['id'], 'kind' => $r['object_kind'], 'name' => $r['name'], 'raised_at' => $r['raised_at'], 'old' => $old, 'new' => $new];
            }
            echo json_encode(['success' => true, 'reviews' => $out]);
            exit;
        }
        $rows = $conn->query('SELECT c.*, k.key_prefix, k.active AS key_active, t.name AS company_name FROM virt_connections c
            LEFT JOIN api_keys k ON k.id = c.api_key_id LEFT JOIN tenants t ON t.id = c.tenant_id ORDER BY c.name')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $c) {
            if (!$reach($c)) { continue; }
            $id = (int)$c['id'];
            $cnt = fn(string $sql) => (int)$conn->query($sql)->fetchColumn();
            $tid = $tenantOf($c);
            $out[] = ['id' => $id, 'name' => $c['name'], 'company_id' => $tid, 'company_name' => $c['company_name'] ?? (getTenantById($conn, $tid)['name'] ?? ''),
                'interval' => (int)$c['expected_interval_minutes'], 'grace' => (int)$c['grace_minutes'],
                'retention_days' => $c['retention_days'] === null ? null : (int)$c['retention_days'], 'is_active' => (bool)$c['is_active'],
                'freshness' => VirtualEstateService::freshness($c), 'last_report_at' => $c['last_report_at'], 'last_observed_at' => $c['last_observed_at'],
                'last_outcome' => $c['last_outcome'], 'last_summary' => $c['last_summary'],
                'collector' => $c['collector_json'] ? json_decode($c['collector_json'], true) : null,
                'key_prefix' => $c['key_prefix'] && (int)$c['key_active'] ? $c['key_prefix'] : null,
                'counts' => ['clusters' => $cnt("SELECT COUNT(*) FROM virt_clusters WHERE connection_id = $id AND not_seen_since IS NULL"),
                    'hosts' => $cnt("SELECT COUNT(*) FROM virt_hosts WHERE connection_id = $id AND not_seen_since IS NULL"),
                    'vms' => $cnt("SELECT COUNT(*) FROM virt_vms WHERE connection_id = $id AND not_seen_since IS NULL"),
                    'not_confirmed' => $cnt("SELECT COUNT(*) FROM virt_vms WHERE connection_id = $id AND not_seen_since IS NULL AND not_confirmed_since IS NOT NULL"),
                    'reviews' => $cnt("SELECT COUNT(*) FROM virt_identity_reviews WHERE connection_id = $id AND status = 'open'")]];
        }
        $companies = [];
        if (isMultiTenant($conn)) {
            foreach (getAllTenants($conn, true) as $t) {
                if (analystCanAccessTenant($conn, $analyst, (int)$t['id'])) { $companies[] = ['id' => (int)$t['id'], 'name' => $t['name']]; }
            }
        }
        echo json_encode(['success' => true, 'connections' => $out, 'companies' => $companies, 'api_url' => publicBaseUrl($conn)]);
        exit;
    }

    csrfRequireWriteMethod();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    switch ($in['action'] ?? '') {
        case 'save':
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) { hvFail('A name is required (at most 150 characters).'); }
            $interval = max(1, min(1440, (int)($in['interval'] ?? 15)));
            $grace = max(0, min(1440, (int)($in['grace'] ?? 15)));
            $retention = isset($in['retention_days']) && $in['retention_days'] !== '' && $in['retention_days'] !== null ? max(1, min(3650, (int)$in['retention_days'])) : null;
            $active = !empty($in['is_active']) ? 1 : 0;
            $tenant = null;
            if (isMultiTenant($conn)) {
                $tenant = (int)($in['company_id'] ?? 0) ?: getActiveTenantId($conn, $analyst);
                if (!analystCanAccessTenant($conn, $analyst, $tenant)) { hvFail('Company not found.'); }
                if ($tenant === getDefaultTenantId($conn)) { $tenant = null; }   // stored as NULL, like assets
            }
            $id = (int)($in['id'] ?? 0);
            if ($id) {
                $c = $load($id);
                // Moving a connection between companies would move its whole estate; refuse it.
                if (($c['tenant_id'] === null ? null : (int)$c['tenant_id']) !== $tenant) {
                    hvFail('A connection cannot move to another company. Make a new connection for that company.');
                }
                $conn->prepare('UPDATE virt_connections SET name = ?, expected_interval_minutes = ?, grace_minutes = ?, retention_days = ?, is_active = ? WHERE id = ?')
                    ->execute([$name, $interval, $grace, $retention, $active, $id]);
                echo json_encode(['success' => true, 'id' => $id]);
                exit;
            }
            $conn->beginTransaction();
            $conn->prepare("INSERT INTO virt_connections (name, source_kind, tenant_id, expected_interval_minutes, grace_minutes, retention_days, is_active, created_by_id, created_datetime)
                VALUES (?, 'hyperv', ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")->execute([$name, $tenant, $interval, $grace, $retention, $active, $analyst]);
            $id = (int)$conn->lastInsertId();
            $key = VirtualEstateService::mintKey($conn, $analyst, $id);
            $conn->commit();
            echo json_encode(['success' => true, 'id' => $id, 'key' => $key]);   // the key, once
            exit;
        case 'new_key':
            $c = $load((int)($in['id'] ?? 0));
            $conn->beginTransaction();
            $key = VirtualEstateService::mintKey($conn, $analyst, (int)$c['id']);
            $conn->commit();
            echo json_encode(['success' => true, 'id' => (int)$c['id'], 'key' => $key]);
            exit;
        case 'delete':
            $c = $load((int)($in['id'] ?? 0));
            $conn->beginTransaction();
            if ($c['api_key_id']) { $conn->prepare('UPDATE api_keys SET active = 0 WHERE id = ?')->execute([(int)$c['api_key_id']]); }
            $conn->prepare('DELETE FROM job_runs WHERE job_key = ?')->execute([VirtualEstateService::jobKey((int)$c['id'])]);
            // Its CIs stay; their bindings end, and the mapping and decisions go (they have no FK).
            VirtualEstateCmdbService::connectionDeleted($conn, ActorContext::fromSession($conn), (int)$c['id']);
            // #296: storage explicitly too, so it never depends on Database Verification having added the FK.
            if (VirtualEstateService::storageReady($conn)) {
                $conn->prepare('DELETE FROM virt_storage_volumes WHERE connection_id = ?')->execute([(int)$c['id']]);
            }
            $conn->prepare('DELETE FROM virt_connections WHERE id = ?')->execute([(int)$c['id']]);   // its estate goes by cascade
            $conn->commit();
            echo json_encode(['success' => true]);
            exit;
        case 'decide':
            $st = $conn->prepare('SELECT connection_id FROM virt_identity_reviews WHERE id = ?');
            $st->execute([(int)($in['review_id'] ?? 0)]);
            $load((int)$st->fetchColumn());   // the case's connection must be one this analyst reaches
            $r = VirtualEstateService::decideReview($conn, ActorContext::fromSession($conn), (int)$in['review_id'], (string)($in['decision'] ?? ''));
            echo json_encode(['success' => true] + $r);
            exit;
    }
    hvFail('Unknown action.');
} catch (ServiceError $e) {
    hvFail($e->getMessage(), $e->errorCode);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) { $conn->rollBack(); }
    error_log('hyperv_connections: ' . $e->getMessage());
    hvFail('The Hyper-V settings could not be loaded or saved.');
}
