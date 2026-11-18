<?php
/**
 * POST { id, company_id } - move a domain to another company (GH #384), with its
 * internal view and subdomains. Thin adapter over DomainsService::moveToCompany(),
 * which holds every rule: both-ends access, what moves together, what is refused
 * and what is cleared. Nothing is decided here, so the REST API and the page
 * cannot disagree about what a move means.
 *
 * Returns { moved, ids, company_name, cleared } - moved is 0 when the domain is
 * already in that company. The button is only drawn when there is more than one
 * company, and the service refuses on a single-company install anyway.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
// Changes a record: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();
domainApiRun(function () use ($conn, $ctx, $analystId) {
    $in       = domainApiBody();
    $id       = (int)($in['id'] ?? 0);
    $targetId = (int)($in['company_id'] ?? 0);
    if ($id <= 0 || !analystCanAccessDomain($conn, $analystId, $id)) domainApiFail('Domain not found.');
    if ($targetId <= 0) domainApiFail('A target company is required.');
    $res = DomainsService::moveToCompany($conn, $ctx, $id, $targetId);
    domainApiOk(['moved' => $res['moved'], 'ids' => $res['ids'], 'company_name' => $res['to'], 'cleared' => $res['cleared']]);
});
