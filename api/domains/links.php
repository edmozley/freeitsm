<?php
/**
 * Domains joined to the rest of FreeITSM (3.0.0) - one endpoint for both sides.
 * Every rule lives in includes/domains/links.php; this only routes.
 *
 * GET  ?domain_id=N                     everything linked to a domain, per kind
 * GET  ?domain_id=N&search=KIND&q=      records that could be linked to it
 * GET  ?for=KIND&id=N                   the domains linked to a CI/service/ticket/article
 * GET  ?for=KIND&id=N&pick=1&q=         domains that could be linked to it
 * GET  ?contract_id=N                   the domains under a contract
 * POST {action:'add'|'remove', domain_id, kind, target_id}
 * POST {action:'set_contract', domain_id, contract_id|null}
 *                                       through DomainsService, so it is audited and
 *                                       the contract's visibility rule is checked
 * POST {action:'raise_incident', domain_id}
 *                                       Service Status: raise for the linked services
 *                                       now (needs the Service Status module too)
 *
 * Needs the Domains module (the bootstrap) - from another module's page an
 * analyst without Domains simply gets no domain section at all.
 */
require_once __DIR__ . '/../../includes/service_context.php';   // ServiceError, thrown below (GH #288)
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
require_once __DIR__ . '/../../includes/domains/links.php';
require_once __DIR__ . '/../../includes/domains/status_link.php';

domainApiRun(function () use ($conn, $analystId, $ctx) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (isset($_GET['contract_id'])) {
            domainApiOk(['domains' => domainsForContract($conn, $analystId, (int)$_GET['contract_id'])]);
        }
        if (isset($_GET['for'])) {
            $kind = (string)$_GET['for'];
            $id = (int)($_GET['id'] ?? 0);
            if (!empty($_GET['pick'])) {
                domainApiOk(['domains' => domainLinkPickDomains($conn, $analystId, $kind, $id, (string)($_GET['q'] ?? ''))]);
            }
            domainApiOk(['domains' => domainsLinkedTo($conn, $analystId, $kind, $id)]);
        }
        $domainId = (int)($_GET['domain_id'] ?? 0);
        if (isset($_GET['search'])) {
            domainApiOk(['results' => domainLinkSearch($conn, $analystId, $domainId, (string)$_GET['search'], (string)($_GET['q'] ?? ''))]);
        }
        $links = domainLinks($conn, $analystId, $domainId);
        domainApiOk(['links' => $links, 'ready' => domainLinksReady($conn),
                     'status' => domainStatusState($conn, $analystId, $domainId)]);
    }

    $in = domainApiBody();
    $domainId = (int)($in['domain_id'] ?? 0);
    switch ($in['action'] ?? '') {
        case 'add':
            $added = domainLinkAdd($conn, $analystId, $domainId, (string)($in['kind'] ?? ''), (int)($in['target_id'] ?? 0));
            domainApiOk(['added' => $added]);
        case 'remove':
            domainLinkRemove($conn, $analystId, $domainId, (string)($in['kind'] ?? ''), (int)($in['target_id'] ?? 0));
            domainApiOk();
        case 'raise_incident':
            DomainsService::loadForActor($conn, $ctx, $domainId);
            if (!analystCanAccessModule($conn, $analystId, 'service-status')) {
                throw new ServiceError('forbidden', 'forbidden', 'You need access to Service Status to raise an incident.');
            }
            if (domainSetting($conn, 'domain_status_mode') === 'off') {
                throw new ServiceError('validation', 'off', 'Raising incidents from Domains is switched off in Domains → Settings → Service Status.');
            }
            domainApiOk(['incident_id' => domainStatusRaise($conn, $ctx, $domainId)]);
        case 'set_contract':
            DomainsService::loadForActor($conn, $ctx, $domainId);
            $cid = isset($in['contract_id']) && (int)$in['contract_id'] > 0 ? (int)$in['contract_id'] : null;
            DomainsService::updateDomain($conn, $ctx, $domainId, ['contract_id' => $cid]);
            domainApiOk();
    }
    domainApiFail('Unknown action.');
});
