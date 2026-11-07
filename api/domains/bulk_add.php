<?php
/**
 * POST {text, defaults:{status_id,purpose,owner_analyst_id,tags,…}, company_id?}
 *   — add many domains from a pasted list.
 * POST {rows:[{domain_name, …fields}], company_id?}
 *   — add many from a CSV the browser has already split into rows (the column
 *     mapping happens on screen, where the person can see it).
 *
 * Nothing is looked up here: a few hundred registry lookups do not fit in one
 * request. The screen then calls process.php in small batches and shows the
 * progress, and anything it does not finish the scheduled run picks up
 * (a never-looked-up domain is first in its queue).
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';

domainApiRun(function () use ($conn, $ctx, $analystId) {
    $in = domainApiBody();
    $tenant = domainApiTenantForCreate($conn, $analystId, $in['company_id'] ?? null);
    $defaults = array_intersect_key((array)($in['defaults'] ?? []), DomainsService::fieldMap());

    if (!empty($in['rows']) && is_array($in['rows'])) {
        if (count($in['rows']) > 1000) domainApiFail('Import at most 1,000 rows at a time.');
        $added = []; $skipped = []; $seen = [];
        foreach ($in['rows'] as $i => $row) {
            $raw = trim((string)($row['domain_name'] ?? ''));
            if ($raw === '') { $skipped[] = ['input' => 'row ' . ($i + 2), 'reason' => 'No domain name.']; continue; }
            $n = domainNormalise($raw);
            if (!$n['ok']) { $skipped[] = ['input' => $raw, 'reason' => $n['error']]; continue; }
            $fields = array_merge($defaults, array_intersect_key($row, DomainsService::fieldMap()));
            // Named rather than numbered: a CSV says "Active" and "Jane Smith".
            $fields = domainImportResolveNames($conn, $fields, $row);
            // Split DNS (#249): the same name once Public and once Internal is two
            // records, not a repeat - "listed twice" means same name AND same scope.
            $seenKey = $n['name'] . '|' . (strtolower(trim((string)($fields['scope'] ?? ''))) === 'internal' ? 'internal' : 'public');
            if (isset($seen[$seenKey])) { $skipped[] = ['input' => $raw, 'reason' => 'Listed twice.']; continue; }
            $seen[$seenKey] = true;
            try {
                $id = DomainsService::createDomain($conn, $ctx, array_merge($fields, ['domain_name' => $n['name']]), $tenant, 'import');
                $added[] = ['id' => $id, 'name' => $n['name']];
            } catch (ServiceError $e) {
                $skipped[] = ['input' => $raw, 'reason' => $e->getMessage()];
            }
        }
        domainApiOk(['added' => $added, 'skipped' => $skipped]);
    }

    $text = (string)($in['text'] ?? '');
    if (trim($text) === '') domainApiFail('Paste at least one domain name.');
    domainApiOk(DomainsService::bulkAdd($conn, $ctx, $text, $defaults, $tenant));
});

/**
 * CSV columns carry names; the service wants ids. status / owner / registrar
 * are matched by name (case-insensitive); an unmatched name is dropped rather
 * than failing the row — the domain is still worth having.
 *
 * @param array $fields  the domain fields mapped from the row so far; ids already set are left alone
 * @param array $row  the CSV row keyed by column name - status, owner and registrar are read as names
 */
function domainImportResolveNames(PDO $conn, array $fields, array $row): array
{
    $pairs = [
        'status'    => ['status_id', "SELECT id FROM domain_statuses WHERE LOWER(name) = LOWER(?) LIMIT 1"],
        'owner'     => ['owner_analyst_id', "SELECT id FROM analysts WHERE is_active = 1 AND (LOWER(full_name) = LOWER(?) OR LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)) LIMIT 1"],
        'registrar' => ['registrar_supplier_id', "SELECT id FROM suppliers WHERE LOWER(legal_name) = LOWER(?) OR LOWER(trading_name) = LOWER(?) LIMIT 1"],
    ];
    foreach ($pairs as $key => [$field, $sql]) {
        $v = trim((string)($row[$key] ?? ''));
        if ($v === '' || isset($fields[$field])) continue;
        $st = $conn->prepare($sql);
        $st->execute(array_fill(0, substr_count($sql, '?'), $v));
        $id = $st->fetchColumn();
        if ($id) $fields[$field] = (int)$id;
        elseif ($key === 'registrar' && empty($fields['registrar_name'])) $fields['registrar_name'] = $v;
    }
    foreach (['registration_date', 'expiry_date', 'last_renewed_date'] as $dk) {
        if (!empty($fields[$dk]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fields[$dk])) {
            $t = strtotime((string)$fields[$dk]);
            $fields[$dk] = $t ? gmdate('Y-m-d', $t) : null;
        }
    }
    if (isset($fields['purpose'])) $fields['purpose'] = strtolower(str_replace([' ', '-'], '_', trim((string)$fields['purpose'])));
    // A CSV says "Internal" - the same tidying as purpose (#249: a name can now be
    // imported once as Public and once as Internal, so the scope must be read right).
    if (isset($fields['scope'])) $fields['scope'] = strtolower(trim((string)$fields['scope']));
    if (isset($fields['renewal_mode'])) {
        $m = strtolower(str_replace([' ', '-'], '_', trim((string)$fields['renewal_mode'])));
        $fields['renewal_mode'] = ['yes' => 'auto', 'on' => 'auto', 'true' => 'auto', 'no' => 'manual', 'off' => 'manual', 'false' => 'manual'][$m] ?? $m;
    }
    return $fields;
}
