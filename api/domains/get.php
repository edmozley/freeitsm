<?php
/**
 * GET ?id= — one domain for its page: the record, its check findings, its
 * history, its look-alikes and CT certificates, and whether the viewer may
 * see the auth code. Out of the analyst's companies reads as not found.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
require_once __DIR__ . '/../../includes/domains/read.php';

domainApiRun(function () use ($conn, $analystId) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0 || !analystCanAccessDomain($conn, $analystId, $id)) domainApiFail('Domain not found.');
    $d = domainDetail($conn, $id, $analystId);
    if (!$d) domainApiFail('Domain not found.');

    $hist = $conn->prepare(
        "SELECT h.field_name, h.old_value, h.new_value, h.source, h.created_datetime, a.full_name AS analyst_name
           FROM domain_audit h LEFT JOIN analysts a ON a.id = h.analyst_id
          WHERE h.domain_id = ? AND h.field_name NOT IN ('ct_scanned', 'lookalikes_scanned')
       ORDER BY h.id DESC LIMIT 300"
    );
    $hist->execute([$id]);

    $la = $conn->prepare("SELECT id, lookalike, technique, has_a, has_mx, first_seen_datetime, last_seen_datetime, dismissed
                            FROM domain_lookalikes WHERE domain_id = ? ORDER BY dismissed, has_mx DESC, lookalike");
    $la->execute([$id]);

    $ct = $conn->prepare("SELECT id, crtsh_id, common_name, name_value, issuer, not_before, not_after, first_seen_datetime, acknowledged
                            FROM domain_certificates WHERE domain_id = ? ORDER BY acknowledged, not_before DESC LIMIT 300");
    $ct->execute([$id]);

    $scans = $conn->prepare("SELECT field_name, new_value FROM domain_audit WHERE domain_id = ? AND field_name IN ('ct_scanned', 'lookalikes_scanned')");
    $scans->execute([$id]);

    // Subdomains (its own, or the parents it could belong to) and the
    // certificates recorded by hand - includes/domains/subdomains.php.
    $storeTenant = $d['tenant_id'] === null ? null : (int)$d['tenant_id'];

    domainApiOk([
        'domain'       => $d,
        'history'      => $hist->fetchAll(PDO::FETCH_ASSOC),
        'lookalikes'   => $la->fetchAll(PDO::FETCH_ASSOC),
        'ct'           => $ct->fetchAll(PDO::FETCH_ASSOC),
        'last_scans'   => $scans->fetchAll(PDO::FETCH_KEY_PAIR),
        'subdomains_ready'   => domainSubdomainsReady($conn),
        'subdomains'         => domainSubdomains($conn, $id),
        'parent_candidates'  => domainParentCandidates($conn, $d['domain_name'], $storeTenant, $id, (string)($d['scope'] ?? 'public')),
        // Split DNS (#249): the same name's record in the other scope, if any.
        'twin'               => domainTwin($conn, $id, $d['domain_name'], $storeTenant),
        'scope_ready'        => domainScopeReady($conn),
        'manual_certs_ready' => domainManualCertsReady($conn),
        'manual_certificates'=> domainManualCertificates($conn, $id),
        'cert_types'         => array_values(array_filter(domainCertificateTypes($conn), fn($t) => $t['is_active'])),
        'can_auth_codes' => domainHasCap($conn, $analystId, Cap::DOMAINS_AUTH_CODES),
        'multi_company'  => isMultiTenant($conn),
    ]);
});
