<?php
/**
 * POST — create (no id) or update (id) a domain. Thin adapter over
 * DomainsService::saveDomain().
 *
 * A NEW domain is looked up at its registry and checked straight away (unless
 * lookups are switched off), so the person who added it sees its dates and
 * grade on the page they land on — a second or two, once.
 *
 * An auth_code in the body needs Cap::DOMAINS_AUTH_CODES; without it the key
 * is refused rather than silently ignored.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';

domainApiRun(function () use ($conn, $ctx, $analystId) {
    $in = domainApiBody();
    if (array_key_exists('auth_code', $in) && !domainHasCap($conn, $analystId, Cap::DOMAINS_AUTH_CODES)) {
        domainApiFail('You do not have permission to change auth codes.');
    }
    $isNew = empty($in['id']);
    if (!$isNew && !analystCanAccessDomain($conn, $analystId, (int)$in['id'])) domainApiFail('Domain not found.');

    $tenant = $isNew ? domainApiTenantForCreate($conn, $analystId, $in['company_id'] ?? null) : null;
    unset($in['company_id']);
    $res = DomainsService::saveDomain($conn, $ctx, $in, $tenant);

    $lookup = null;
    if ($res['created'] && !domainMayLeaveNetwork(DomainsService::loadForActor($conn, $ctx, $res['id']))) {
        // GH #203: an internal domain is never looked up at a registry, but its
        // DNS-only check never leaves the network either - so it runs whatever
        // the lookup mode, and the person sees its health straight away.
        if (domainSetting($conn, 'domain_checks_enabled') === '1') {
            try { DomainsService::runChecks($conn, $ctx, $res['id']); } catch (Throwable $e) { error_log('domains first check: ' . $e->getMessage()); }
        }
    } elseif ($res['created']) {
        $mode = domainSetting($conn, 'domain_lookup_mode');
        if ($mode !== 'off') {
            $lookup = DomainsService::refreshLookup($conn, $ctx, $res['id']);
            if (domainSetting($conn, 'domain_checks_enabled') === '1') {
                try { DomainsService::runChecks($conn, $ctx, $res['id']); } catch (Throwable $e) { error_log('domains first check: ' . $e->getMessage()); }
            }
        }
    }
    domainApiOk(['id' => $res['id'], 'created' => $res['created'], 'lookup' => $lookup]);
});
