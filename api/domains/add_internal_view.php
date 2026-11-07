<?php
/**
 * POST { id } - split DNS (#249): add the Internal record for a Public domain's
 * name, in the same company, and run its first (DNS-only, never outside) check.
 * Thin adapter over DomainsService::addInternalView(), which explains why it only
 * goes Public -> Internal.
 *
 * Returns { id } - the new internal record, for the page to open.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
// Creates a record: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();
domainApiRun(function () use ($conn, $ctx, $analystId) {
    $in = domainApiBody();
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0 || !analystCanAccessDomain($conn, $analystId, $id)) domainApiFail('Domain not found.');
    $newId = DomainsService::addInternalView($conn, $ctx, $id);
    // Same as adding an internal domain with New: its check never leaves the
    // network, so it runs straight away and the page opens on a real health.
    if (domainSetting($conn, 'domain_checks_enabled') === '1') {
        try { DomainsService::runChecks($conn, $ctx, $newId); } catch (Throwable $e) { error_log('domains first check: ' . $e->getMessage()); }
    }
    domainApiOk(['id' => $newId]);
});
