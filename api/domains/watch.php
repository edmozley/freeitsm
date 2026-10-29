<?php
/**
 * The outside-world watches on one domain.
 *
 * POST {id, action:'scan_ct'}            ask crt.sh now (slow — up to 45s)
 * POST {id, action:'ack_ct'}             mark every certificate as seen
 * POST {id, action:'scan_lookalikes'}    resolve the look-alike candidates now
 * POST {id, action:'dismiss', lookalike_id, dismissed:bool}
 *
 * Manual scans work even when the scheduled watches are off: switching the
 * schedule off is "don't do this for every domain every week", not "never".
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
require_once __DIR__ . '/../../includes/domains/watch.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

domainApiRun(function () use ($conn, $ctx) {
    $in = domainApiBody();
    $row = DomainsService::loadForActor($conn, $ctx, (int)($in['id'] ?? 0));
    $id = (int)$row['id'];
    // GH #203: both scans send the name (or names made from it) outside.
    if (in_array($in['action'] ?? '', ['scan_ct', 'scan_lookalikes'], true) && !domainMayLeaveNetwork($row)) {
        domainApiFail('This is an internal domain: it is never sent outside the network, so it has no watches.');
    }
    switch ($in['action'] ?? '') {
        case 'scan_ct':
            set_time_limit(90);
            $r = domainCtScan($conn, $id, $row['domain_name']);
            if (!$r['ok']) domainApiFail($r['error']);
            $conn->prepare("DELETE FROM domain_audit WHERE domain_id = ? AND field_name = 'ct_scanned'")->execute([$id]);
            DomainsService::audit($conn, $id, null, 'ct_scanned', null, gmdate('Y-m-d'), 'check');
            domainApiOk(['result' => $r]);
        case 'ack_ct':
            $conn->prepare("UPDATE domain_certificates SET acknowledged = 1 WHERE domain_id = ?")->execute([$id]);
            domainApiOk();
        case 'scan_lookalikes':
            set_time_limit(120);
            $r = domainLookalikeScan($conn, $id, $row['domain_name'], domainSetting($conn, 'domain_dns_resolver'), 90);
            $conn->prepare("DELETE FROM domain_audit WHERE domain_id = ? AND field_name = 'lookalikes_scanned'")->execute([$id]);
            DomainsService::audit($conn, $id, null, 'lookalikes_scanned', null, gmdate('Y-m-d'), 'check');
            domainApiOk(['result' => $r]);
        case 'dismiss':
            $conn->prepare("UPDATE domain_lookalikes SET dismissed = ? WHERE id = ? AND domain_id = ?")
                 ->execute([!empty($in['dismissed']) ? 1 : 0, (int)($in['lookalike_id'] ?? 0), $id]);
            domainApiOk();
    }
    domainApiFail('Unknown action.');
});
