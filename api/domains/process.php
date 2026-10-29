<?php
/**
 * POST {ids:[…], lookup?:bool, check?:bool} — look up and/or check up to ten
 * domains now. The screen calls this in small batches after a bulk add, or for
 * "Refresh selected", and draws a progress bar between calls; ten keeps each
 * request well inside a web server's time limit.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';

domainApiRun(function () use ($conn, $ctx) {
    $in = domainApiBody();
    $ids = array_slice(array_unique(array_map('intval', (array)($in['ids'] ?? []))), 0, 10);
    $doLookup = !array_key_exists('lookup', $in) || !empty($in['lookup']);
    $doCheck  = !array_key_exists('check', $in) || !empty($in['check']);
    if (domainSetting($conn, 'domain_checks_enabled') !== '1') $doCheck = false;

    $results = [];
    foreach ($ids as $id) {
        if ($id <= 0) continue;
        $r = ['id' => $id];
        try {
            $row = DomainsService::loadForActor($conn, $ctx, $id);
            // GH #203: asked BEFORE the pace - pacing can fetch IANA's bootstrap
            // file, which is itself a trip outside for this name's registry.
            if ($doLookup && !domainMayLeaveNetwork($row)) {
                $r['lookup_ok'] = false;
                $r['lookup_skipped'] = 'internal';
            } elseif ($doLookup) {
                domainLookupPace($conn, $row['domain_name']);
                $lk = DomainsService::refreshLookup($conn, $ctx, $id);
                $r['lookup_ok'] = $lk['ok'];
                if (!$lk['ok']) $r['lookup_error'] = $lk['error'];
            }
            if ($doCheck) {
                $c = DomainsService::runChecks($conn, $ctx, $id);
                $r['grade'] = $c['grade'];
                if (isset($c['health'])) $r['health'] = $c['health'];   // an internal domain (GH #203)
            }
        } catch (ServiceError $e) {
            $r['error'] = $e->getMessage();
        }
        $results[] = $r;
    }
    domainApiOk(['results' => $results]);
});
