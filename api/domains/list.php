<?php
/**
 * GET — the domain register, scoped to the analyst's company (or every company
 * they can see, in the "All companies" view). Optional filters: q, status_id,
 * purpose, owner_analyst_id, registrar_supplier_id, tag, expiring_days.
 * Also returns the lookup lists the register's filters and bulk editor use.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
require_once __DIR__ . '/../../includes/domains/read.php';

domainApiRun(function () use ($conn, $analystId) {
    $rows = domainListRows($conn, $analystId, $_GET);

    $lookups = [];
    if (!empty($_GET['with_lookups'])) {
        $lookups = domainApiLookups($conn, $analystId);
    }
    // Watchtower's Domains card links into the register (discussion #388): the ids
    // its count covers, for the page to narrow to. The full register still comes
    // back, so the sidebar views keep their usual counts.
    $out = ['domains' => $rows, 'lookups' => $lookups, 'multi_company' => isMultiTenant($conn)];
    if (isset($_GET['expiring'])) {
        $out['watch_ids'] = domainWatchtowerMatchIds($conn, $analystId, 'expiring', (int)$_GET['expiring']);
    } elseif (isset($_GET['ssl'])) {
        $out['watch_ids'] = domainWatchtowerMatchIds($conn, $analystId, 'certificate');
        $out['watch_days'] = max(1, (int)domainSettings($conn)['domain_ssl_warn_days']);
    }
    domainApiOk($out);
});
