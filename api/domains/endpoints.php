<?php
/**
 * Internal monitoring (GH #203 slice 2) - HTTPS endpoints on internal domains, and
 * the trust profiles for their certificate authorities.
 *
 * GET  ?domain_id=   the domain's endpoints with their last results - anybody who
 *                    can see the domain - plus the trust profiles' names and
 *                    whether this analyst may change them
 * GET  ?profiles=1   every trust profile with its CA summary (Settings)
 * POST {action:'save', id?, domain_id, host, port, warn_days, trust_profile_id}
 * POST {action:'delete', id}
 * POST {action:'save_profile', id?, name, ca_pem}
 * POST {action:'delete_profile', id}
 * POST {action:'save_expected', id?, domain_id, host, record_type, expected_values}   GH #243
 * POST {action:'delete_expected', id}
 *
 * Every write needs Cap::DOMAINS_INTERNAL: endpoints decide which internal
 * addresses the server connects to. Thin adapter over DomainsService.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';

domainApiRun(function () use ($conn, $ctx, $analystId) {
    $canInternal = domainHasCap($conn, $analystId, Cap::DOMAINS_INTERNAL);
    if (!domainEndpointsReady($conn)) domainApiOk(['ready' => false, 'endpoints' => [], 'profiles' => [], 'can_internal' => $canInternal]);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (!empty($_GET['profiles'])) {
            domainRequireCap($conn, Cap::DOMAINS_INTERNAL);
            $rows = $conn->query("SELECT p.id, p.name, p.summary, p.updated_datetime, p.created_datetime,
                                         (SELECT COUNT(*) FROM domain_endpoints e WHERE e.trust_profile_id = p.id) AS used_by
                                    FROM domain_trust_profiles p ORDER BY p.name")->fetchAll(PDO::FETCH_ASSOC);
            domainApiOk(['ready' => true, 'profiles' => $rows]);
        }
        $domain = DomainsService::loadForActor($conn, $ctx, (int)($_GET['domain_id'] ?? 0));   // company check
        // GH #243: the HTTP check's path and codes, once Database Verification has added them.
        $httpCols = DomainsService::httpCheckReady($conn) ? 'e.http_path, e.http_expect, ' : '';
        $s = $conn->prepare("SELECT e.id, e.host, e.port, e.warn_days, e.trust_profile_id, t.name AS trust_profile_name, $httpCols
                                    e.status, e.incomplete, e.detail_key, e.detail_params, e.address, e.cert_json,
                                    e.last_attempt_datetime, e.last_completed_datetime, e.last_healthy_datetime
                               FROM domain_endpoints e LEFT JOIN domain_trust_profiles t ON t.id = e.trust_profile_id
                              WHERE e.domain_id = ? ORDER BY e.host, e.port");
        $s->execute([(int)$domain['id']]);
        $eps = array_map(function ($r) {
            $r['detail_params'] = $r['detail_params'] ? (json_decode($r['detail_params'], true) ?: []) : [];
            $r['cert'] = $r['cert_json'] ? json_decode($r['cert_json'], true) : null;
            unset($r['cert_json']);
            return $r;
        }, $s->fetchAll(PDO::FETCH_ASSOC));
        $settings = domainSettings($conn);
        domainApiOk([
            'ready'        => true,
            'endpoints'    => $eps,
            // GH #243: what each name should hold. The results are findings on the Health tab.
            'expected'     => DomainsService::expectedRecords($conn, (int)$domain['id']),
            'expected_ready' => DomainsService::expectedReady($conn),
            // The names only, for the endpoint dialog's list - never the certificates.
            'profiles'     => $canInternal ? $conn->query("SELECT id, name FROM domain_trust_profiles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [],
            'can_internal' => $canInternal,
            'networks_set' => (bool)domainInternalNetworks($settings),
            'ports'        => domainInternalPorts($settings),
        ]);
    }

    csrfRequireWriteMethod();
    domainRequireCap($conn, Cap::DOMAINS_INTERNAL);
    $in = domainApiBody();
    switch ((string)($in['action'] ?? '')) {
        case 'save':
            domainApiOk(['endpoint' => DomainsService::saveEndpoint($conn, $ctx, $in)]);
        case 'delete':
            DomainsService::deleteEndpoint($conn, $ctx, (int)($in['id'] ?? 0));
            domainApiOk();
        case 'save_profile':
            domainApiOk(['profile' => DomainsService::saveTrustProfile($conn, $ctx, $in)]);
        case 'save_expected':
            domainApiOk(['expected' => DomainsService::saveExpectedRecord($conn, $ctx, $in)]);
        case 'delete_expected':
            DomainsService::deleteExpectedRecord($conn, $ctx, (int)($in['id'] ?? 0));
            domainApiOk();
        case 'delete_profile':
            DomainsService::deleteTrustProfile($conn, (int)($in['id'] ?? 0));
            domainApiOk();
    }
    domainApiFail('Unknown action.');
});
