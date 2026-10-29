<?php
/**
 * DomainsService — the write rules for the Domains module (#154), ONCE.
 *
 * Shared by the UI endpoints (api/domains/*.php), the REST API
 * (api/v1/resources/domains.php) and the scheduled run (cron/domains.php). Each
 * caller passes an ActorContext + canonical input; this layer validates, writes,
 * records history and fires events, and returns ids or throws ServiceError. It
 * never emits HTTP.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * COMPANIES
 *
 * A domain is scoped data: it belongs to exactly one company, NULL = the
 * Default company. Every by-id method starts with loadForActor(), which 404s a
 * domain in a company the caller cannot reach — never 403, so a company's
 * domain list cannot be probed by guessing ids. The same name may be held by
 * two companies (an MSP can manage example.com for one client and example.net
 * for another, and nothing stops two clients sharing a name in their records),
 * so the duplicate check is per company.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HISTORY
 *
 * Every field that changes gets a domain_audit row, whoever changed it: a
 * person (analyst_id set), a registry lookup (source 'lookup'), a nightly check
 * (source 'check') or change detection (source 'monitor'). The auth code is the
 * exception that proves the rule: its CHANGE is recorded, its value never is.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../money.php';   // GH #189: currencies are checked against the registry
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../domains/settings.php';
require_once __DIR__ . '/../cost_centre_assign.php';   // GH #188
require_once __DIR__ . '/../domains/names.php';
require_once __DIR__ . '/../domains/lookup.php';
require_once __DIR__ . '/../domains/checks.php';
require_once __DIR__ . '/../domains/monitor.php';
require_once __DIR__ . '/../domains/customer.php';
require_once __DIR__ . '/../domains/subdomains.php';
require_once __DIR__ . '/../contract_party.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class DomainsService
{
    /**
     * The fields a person (or an API key) may set, and how each is validated.
     * domain_name and auth_code are handled separately.
     */
    public static function fieldMap(): array
    {
        return [
            'status_id'             => ['type' => 'lookup', 'table' => 'domain_statuses'],
            'purpose'               => ['type' => 'enum',   'values' => domainPurposes()],
            'scope'                 => ['type' => 'enum',   'values' => array_column(DomainScope::cases(), 'value')],   // GH #203
            'registrar_supplier_id' => ['type' => 'lookup', 'table' => 'suppliers'],
            'registrar_account_id'  => ['type' => 'account'],
            'registrar_name'        => ['type' => 'string', 'max' => 255],
            'registration_date'     => ['type' => 'date'],
            'expiry_date'           => ['type' => 'date'],
            'last_renewed_date'     => ['type' => 'date'],
            'registry_updated_date' => ['type' => 'date'],
            'renewal_mode'          => ['type' => 'enum',   'values' => domainRenewalModes()],
            'transfer_lock'         => ['type' => 'tribool'],
            'registry_lock'         => ['type' => 'tribool'],
            'dnssec'                => ['type' => 'tribool'],
            'registrant_name'       => ['type' => 'string', 'max' => 255],
            'owner_analyst_id'      => ['type' => 'analyst'],
            'tech_contact_id'       => ['type' => 'lookup', 'table' => 'contacts'],
            'tech_analyst_id'       => ['type' => 'analyst'],    // #162 - OR one of your own analysts
            'customer_user_id'      => ['type' => 'customer'],   // #153 - a person in the domain's company
            'customer_supplier_id'  => ['type' => 'lookup', 'table' => 'suppliers'],   // #162 - OR a supplier
            'customer_contact_id'   => ['type' => 'lookup', 'table' => 'contacts'],    // #162 - and one of its contacts
            'nameservers'           => ['type' => 'lines'],
            'dns_provider'          => ['type' => 'string', 'max' => 255],
            'hosting_provider'      => ['type' => 'string', 'max' => 255],
            'ssl_hosts'             => ['type' => 'ssl_hosts'],
            'dkim_selectors'        => ['type' => 'string', 'max' => 255],
            'cost'                  => ['type' => 'money'],
            'currency'              => ['type' => 'currency'],
            'billing_years'         => ['type' => 'int',    'min' => 1, 'max' => 10],
            'cost_centre'           => ['type' => 'string', 'max' => 100],   // the old free text - see normaliseCostCentre()
            'cost_centre_id'        => ['type' => 'cost_centre'],             // GH #188
            'contract_id'           => ['type' => 'lookup', 'table' => 'contracts'],
            'tags'                  => ['type' => 'tags'],
            'notes'                 => ['type' => 'text'],
            'monitoring_enabled'    => ['type' => 'bool'],
            'parent_domain_id'      => ['type' => 'parent'],     // a subdomain's parent - includes/domains/subdomains.php
        ];
    }

    /** Fields the bulk editor may set on many domains at once. */
    public static function bulkFields(): array
    {
        return ['status_id', 'purpose', 'scope', 'owner_analyst_id', 'renewal_mode', 'registrar_supplier_id',
                'registrar_account_id', 'monitoring_enabled', 'cost_centre', 'cost_centre_id', 'tags'];
    }

    // ======================================================================
    //  Create / update / delete
    // ======================================================================

    /**
     * Create (no id) or update (id present) a domain. Returns ['id', 'created'].
     *
     * @param array $in  id (absent = new), domain_name, auth_code and any fieldMap() key
     * @param ?int $tenantId the company a NEW domain belongs to (the adapter
     *        resolves it: the analyst's active company, or the API key's). Ignored
     *        on update — moving a domain between companies is not an edit.
     */
    public static function saveDomain(PDO $conn, ActorContext $ctx, array $in, ?int $tenantId = null): array
    {
        if (!empty($in['id'])) {
            return ['id' => self::updateDomain($conn, $ctx, (int)$in['id'], $in), 'created' => false];
        }
        return ['id' => self::createDomain($conn, $ctx, $in, $tenantId), 'created' => true];
    }

    /**
     * GH #203. TRAP: a field Database Verification has not added yet is normally
     * skipped quietly (fieldReady). Scope must not be: an "internal" that is
     * dropped leaves a PUBLIC domain, which the next step looks up at a registry -
     * the exact leak internal scope exists to stop. So asking for internal before
     * the column exists is refused, loudly.
     */
    private static function assertScopeStorable(PDO $conn, array $in): void
    {
        if (($in['scope'] ?? null) === DomainScope::Internal->value && !domainScopeReady($conn)) {
            throw new ServiceError('validation', 'invalid_field',
                'Internal domains need Database Verification to be run first (System -> Database Verification).');
        }
    }

    /**
     * Adds a domain to the register for a company and returns its id.
     *
     * Validates every field, starts it in the first active status, encrypts any auth code,
     * records it in the history and fires the domain.created workflow event.
     *
     * @param array $in  domain_name (required), auth_code and any fieldMap() key
     * @param string $source  the history row's source; 'app' (default) records 'app' or 'api' from the caller, anything else as given
     */
    public static function createDomain(PDO $conn, ActorContext $ctx, array $in, ?int $tenantId, string $source = 'app'): int
    {
        self::assertScopeStorable($conn, $in);
        $n = domainNormalise((string)($in['domain_name'] ?? ''));
        if (!$n['ok']) {
            throw new ServiceError('validation', empty($in['domain_name']) ? 'missing_field' : 'invalid_field', $n['error']);
        }
        $store = self::storeTenant($conn, $tenantId);
        if ($ctx->companyScope !== null) {
            $effective = $store === null ? getDefaultTenantId($conn) : $store;
            if (!in_array($effective, $ctx->companyScope, true)) {
                throw new ServiceError('forbidden', 'forbidden', 'You cannot add domains for that company.');
            }
        }
        $dup = $conn->prepare("SELECT id FROM domains WHERE domain_name = ? AND tenant_id <=> ?");
        $dup->execute([$n['name'], $store]);
        if (($existing = $dup->fetchColumn()) !== false) {
            throw new ServiceError('conflict', 'conflict', "{$n['name']} is already in the register (id {$existing}).");
        }

        $cols = ['domain_name', 'display_name', 'tenant_id', 'created_by'];
        $vals = [$n['name'], $n['display'], $store, $ctx->actorId > 0 ? $ctx->actorId : null];
        $row  = ['tenant_id' => $store];
        // A subdomain of a domain already in the register is linked to it unless
        // the caller said otherwise - the closest parent, when there are several.
        if (!array_key_exists('parent_domain_id', $in) && domainSubdomainsReady($conn)) {
            $cand = domainParentCandidates($conn, $n['name'], $store);
            if ($cand) $in['parent_domain_id'] = $cand[0]['id'];
        }
        $in   = self::normaliseParties($conn, $in, []);
        $in   = self::normaliseCostCentre($conn, $in, ['tenant_id' => $store]);
        foreach (self::fieldMap() as $field => $def) {
            if (!array_key_exists($field, $in)) continue;
            if (!self::fieldReady($conn, $field)) continue;   // before Database Verification
            $v = self::validateField($conn, $field, $in[$field], $def, $row, $n['name']);
            if ($field === 'contract_id') self::assertContractVisible($conn, $ctx, $v, $store);
            $cols[] = $field;
            $vals[] = $v;
        }
        // A new domain starts in the first "alerts on" status unless told otherwise.
        if (!in_array('status_id', $cols, true)) {
            $sid = $conn->query("SELECT id FROM domain_statuses WHERE is_active = 1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
            if ($sid) { $cols[] = 'status_id'; $vals[] = (int)$sid; }
        }
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $conn->prepare("INSERT INTO domains (" . implode(', ', $cols) . ", created_datetime, updated_datetime)
                        VALUES ($ph, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute($vals);
        $id = (int)$conn->lastInsertId();

        if (!empty($in['auth_code'])) {
            $conn->prepare("UPDATE domains SET auth_code = ? WHERE id = ?")->execute([encryptValue((string)$in['auth_code']), $id]);
        }

        $auditSource = $source === 'app' ? ($ctx->source === 'api' ? 'api' : 'app') : $source;
        self::audit($conn, $id, $ctx->actorId, 'domain_created', null, $n['name'], $auditSource);
        // And the other way round: subdomains added before their parent are
        // linked to it now - unless the new domain is a subdomain itself.
        if (domainSubdomainsReady($conn) && empty($in['parent_domain_id'])) {
            foreach (domainOrphanSubdomains($conn, $id, $n['name'], $store) as $childId) {
                $conn->prepare("UPDATE domains SET parent_domain_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$id, $childId]);
                self::audit($conn, $childId, $ctx->actorId, 'parent_domain_id', null, $n['name'], $auditSource);
            }
        }
        self::afterDateChange($conn);
        self::dispatchCrud($conn, 'created', $id);
        return $id;
    }

    /**
     * Changes a domain's fields, records each change in its history and fires
     * domain.updated when anything changed.
     *
     * A new domain name clears the change-watch baseline.
     *
     * @param int $id  the domain to change
     * @param array $in  domain_name, auth_code and any fieldMap() key; absent keys are left alone
     * @param string $source  the history row's source; '' records 'app' or 'api' from the caller
     */
    public static function updateDomain(PDO $conn, ActorContext $ctx, int $id, array $in, string $source = ''): int
    {
        self::assertScopeStorable($conn, $in);
        $cur = self::loadForActor($conn, $ctx, $id);
        $source = $source ?: ($ctx->source === 'api' ? 'api' : 'app');
        $fields = array_diff_key($in, ['id' => true]);
        if (!$fields) throw new ServiceError('validation', 'missing_field', 'No fields to update.');

        $sets = []; $args = []; $changes = [];

        if (array_key_exists('domain_name', $in)) {
            $n = domainNormalise((string)$in['domain_name']);
            if (!$n['ok']) throw new ServiceError('validation', 'invalid_field', $n['error']);
            if ($n['name'] !== $cur['domain_name']) {
                $dup = $conn->prepare("SELECT id FROM domains WHERE domain_name = ? AND tenant_id <=> ? AND id <> ?");
                $dup->execute([$n['name'], $cur['tenant_id'], $id]);
                if ($dup->fetchColumn() !== false) throw new ServiceError('conflict', 'conflict', "{$n['name']} is already in the register.");
                $sets[] = 'domain_name = ?'; $args[] = $n['name'];
                $sets[] = 'display_name = ?'; $args[] = $n['display'];
                // A different name is a different registration: what change
                // detection knew about the old one means nothing for the new one.
                $sets[] = 'watch_baseline = NULL';
                $changes[] = ['domain_name', $cur['domain_name'], $n['name']];
                // Renamed out from under its parent: no longer its subdomain.
                if (!empty($cur['parent_domain_id']) && !array_key_exists('parent_domain_id', $in)) {
                    $p = $conn->prepare("SELECT domain_name FROM domains WHERE id = ?");
                    $p->execute([(int)$cur['parent_domain_id']]);
                    if (!domainIsUnder($n['name'], (string)$p->fetchColumn())) $in['parent_domain_id'] = null;
                }
                $renamedTo = $n['name'];
            }
        }

        $in = self::normaliseParties($conn, $in, $cur);
        $in = self::normaliseCostCentre($conn, $in, $cur);
        foreach (self::fieldMap() as $field => $def) {
            if (!array_key_exists($field, $in)) continue;
            if (!self::fieldReady($conn, $field)) continue;   // before Database Verification
            $v = self::validateField($conn, $field, $in[$field], $def, $cur, $cur['domain_name']);
            if (self::same($cur[$field], $v)) continue;
            if ($field === 'contract_id') self::assertContractVisible($conn, $ctx, $v, $cur['tenant_id'] === null ? null : (int)$cur['tenant_id']);
            $sets[] = "$field = ?"; $args[] = $v;
            $changes[] = [$field, $cur[$field], $v];
        }

        if (array_key_exists('auth_code', $in)) {
            // Only reachable when the adapter has already checked Cap::DOMAINS_AUTH_CODES.
            $new = trim((string)$in['auth_code']);
            $sets[] = 'auth_code = ?'; $args[] = $new === '' ? null : encryptValue($new);
            self::audit($conn, $id, $ctx->actorId, 'auth_code', null, $new === '' ? '(cleared)' : '(changed)', $source);
        }

        if (!$sets) return $id;
        $args[] = $id;
        $conn->prepare("UPDATE domains SET " . implode(', ', $sets) . ", updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute($args);

        foreach ($changes as [$f, $o, $nv]) {
            self::audit($conn, $id, $ctx->actorId, $f, self::auditDisplay($conn, $f, $o), self::auditDisplay($conn, $f, $nv), $source);
        }
        // A renamed parent keeps only the subdomains still under its new name.
        if (isset($renamedTo) && domainSubdomainsReady($conn)) {
            foreach (domainSubdomains($conn, $id) as $child) {
                if (domainIsUnder($child['domain_name'], $renamedTo)) continue;
                $conn->prepare("UPDATE domains SET parent_domain_id = NULL WHERE id = ?")->execute([$child['id']]);
                self::audit($conn, $child['id'], $ctx->actorId, 'parent_domain_id', $cur['domain_name'], null, $source);
            }
        }
        if (array_intersect(array_column($changes, 0), ['expiry_date', 'domain_name', 'status_id', 'parent_domain_id'])) self::afterDateChange($conn);
        if ($changes) self::dispatchCrud($conn, 'updated', $id);
        return $id;
    }

    /**
     * Delete a domain and everything that hangs off it. Returns the id.
     *
     * @param int $id  the domain to delete, with its history, alerts, checks and links
     */
    public static function deleteDomain(PDO $conn, ActorContext $ctx, int $id): int
    {
        $row = self::loadForActor($conn, $ctx, $id);
        $conn->beginTransaction();
        try {
            // Children by hand: an upgraded install whose FKs failed to add has
            // no cascade to rely on (see the Checklists house-style review §3).
            foreach (['domain_audit', 'domain_alerts_sent', 'domain_lookalikes', 'domain_certificates'] as $t) {
                $conn->prepare("DELETE FROM `$t` WHERE domain_id = ?")->execute([$id]);
            }
            // The 3.0.0 links, by the same rule - each on its own, because before
            // Database Verification the tables are not there, and a missing table
            // must never stop a domain being deleted.
            foreach (['domain_cmdb_objects', 'domain_status_services', 'ticket_domains', 'domain_knowledge_articles', 'domain_status_incidents', 'domain_manual_certificates'] as $t) {
                try { $conn->prepare("DELETE FROM `$t` WHERE domain_id = ?")->execute([$id]); } catch (Throwable) { /* not created yet */ }
            }
            // Its subdomains stay, standing on their own: deleting a parent
            // from the register says nothing about whether they still exist.
            if (domainSubdomainsReady($conn)) {
                $conn->prepare("UPDATE domains SET parent_domain_id = NULL WHERE parent_domain_id = ?")->execute([$id]);
            }
            $conn->prepare("DELETE FROM domains WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        try {
            if (!function_exists('documentsDetachParent') && is_file(__DIR__ . '/../documents.php')) require_once __DIR__ . '/../documents.php';
            if (function_exists('documentsDetachParent')) documentsDetachParent($conn, 'domain', $id);
        } catch (Throwable $e) { error_log('domains: detach documents: ' . $e->getMessage()); }
        self::afterDateChange($conn);
        WorkflowEngine::dispatch('domain.deleted', ['domain' => self::eventPayload($row)]);
        return $id;
    }

    /**
     * Add many domains from a pasted list (one per line, or separated by commas
     * or spaces). Every name goes through the same create path as a single add.
     *
     * @param string $text  the pasted list - names separated by new lines, commas, semicolons, tabs or spaces (at most 1,000)
     * @param array $defaults  fieldMap() values every new domain starts with, e.g. status_id or owner_analyst_id
     * @param string $source  the history row's source, as createDomain()
     * @return array{added:array<int,array{id:int,name:string}>, skipped:array<int,array{input:string,reason:string}>}
     */
    public static function bulkAdd(PDO $conn, ActorContext $ctx, string $text, array $defaults, ?int $tenantId, string $source = 'app'): array
    {
        $added = []; $skipped = []; $seen = [];
        $parts = preg_split('/[\r\n,;\t ]+/', $text);
        if (count(array_filter($parts)) > 1000) {
            throw new ServiceError('validation', 'invalid_field', 'Add at most 1,000 domains at a time.');
        }
        foreach ($parts as $raw) {
            $raw = trim($raw);
            if ($raw === '') continue;
            $n = domainNormalise($raw);
            if (!$n['ok']) { $skipped[] = ['input' => $raw, 'reason' => $n['error']]; continue; }
            if (isset($seen[$n['name']])) { $skipped[] = ['input' => $raw, 'reason' => 'Listed twice.']; continue; }
            $seen[$n['name']] = true;
            try {
                $id = self::createDomain($conn, $ctx, array_merge($defaults, ['domain_name' => $n['name']]), $tenantId, $source);
                $added[] = ['id' => $id, 'name' => $n['name']];
            } catch (ServiceError $e) {
                $skipped[] = ['input' => $raw, 'reason' => $e->getMessage()];
            }
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Set the same fields on many domains. Each goes through updateDomain(), so
     * each gets its own history rows and scope check; a domain the caller cannot
     * reach is reported, not silently skipped.
     *
     * @param array $ids  the domains to change
     * @param array $fields  [field => value]; only bulkFields() keys are used
     */
    public static function bulkUpdate(PDO $conn, ActorContext $ctx, array $ids, array $fields): array
    {
        $allowed = array_intersect_key($fields, array_flip(self::bulkFields()));
        self::assertScopeStorable($conn, $allowed);
        if (!$allowed) throw new ServiceError('validation', 'missing_field', 'Choose at least one field to change.');
        $done = 0; $failed = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id <= 0) continue;
            try { self::updateDomain($conn, $ctx, $id, $allowed); $done++; }
            catch (ServiceError $e) { $failed[] = ['id' => $id, 'reason' => $e->getMessage()]; }
        }
        return ['updated' => $done, 'failed' => $failed];
    }

    // ======================================================================
    //  Registry lookups
    // ======================================================================

    /**
     * Look a domain up now and apply the answer. For the "Refresh" button and the API.
     *
     * @param int $id  the domain to look up
     */
    public static function refreshLookup(PDO $conn, ActorContext $ctx, int $id): array
    {
        $row = self::loadForActor($conn, $ctx, $id);
        // GH #203: an internal domain is never sent to a registry - its name
        // would leave the network. Not a failure: registration does not apply.
        if (!domainMayLeaveNetwork($row)) {
            return ['ok' => false, 'internal' => true, 'changes' => [],
                    'error' => 'This is an internal domain: it is never looked up at a registry.'];
        }
        // A subdomain has no registration of its own: the registry would only
        // answer "not found". Nothing is recorded as a failure.
        if (!empty($row['parent_domain_id'])) {
            $p = $conn->prepare("SELECT domain_name FROM domains WHERE id = ?");
            $p->execute([(int)$row['parent_domain_id']]);
            return ['ok' => false, 'subdomain' => true, 'changes' => [],
                    'error' => 'This is a subdomain: its registration is ' . ($p->fetchColumn() ?: 'its parent') . '\'s - refresh that domain instead.'];
        }
        $r = domainLookup($conn, $row['domain_name']);
        if (!$r['ok']) {
            self::recordLookupError($conn, $id, (string)$r['error']);
            return ['ok' => false, 'error' => $r['error'], 'changes' => []];
        }
        $changes = self::applyLookup($conn, $id, $r, $ctx->actorId > 0 ? $ctx->actorId : null);
        return ['ok' => true, 'source' => $r['source'], 'changes' => $changes, 'expiry_published' => $r['expiry_published']];
    }

    /**
     * Write a lookup result onto a domain, under the operator's overwrite rule.
     *
     * 'always' — the registry is the record for the facts it holds.
     * 'blanks' — it only fills fields nobody has typed into.
     *
     * Registry statuses are never typed by people, so they always follow the
     * registry. A typed expiry date on a registry that publishes none (.de) is
     * never blanked by that registry's silence: a null answer is "not published",
     * not "no date".
     *
     * @param int $id  the domain the answer is for
     * @param array $r  a successful domainLookup() result: registrar, dates, nameservers, locks, statuses, registrant, source
     * @return array<int,array{field:string,old:?string,new:?string}>
     */
    public static function applyLookup(PDO $conn, int $id, array $r, ?int $analystId = null): array
    {
        $cur = self::loadRow($conn, $id);
        $overwrite = domainSetting($conn, 'domain_lookup_overwrite') !== 'blanks';

        $map = [
            'registrar_name'        => $r['registrar'],
            'registration_date'     => $r['registration_date'],
            'expiry_date'           => $r['expiry_date'],
            'registry_updated_date' => $r['updated_date'],
            'last_renewed_date'     => $r['last_renewed_date'],
            'nameservers'           => $r['nameservers'] ? implode("\n", $r['nameservers']) : null,
            'dnssec'                => $r['dnssec'] === null ? null : (int)$r['dnssec'],
            'transfer_lock'         => $r['transfer_lock'] === null ? null : (int)$r['transfer_lock'],
            'registry_lock'         => $r['registry_lock'] === null ? null : (int)$r['registry_lock'],
        ];
        $sets = []; $args = []; $changes = [];
        foreach ($map as $f => $v) {
            if ($v === null || $v === '') continue;                 // silence is not an answer
            $isBlank = $cur[$f] === null || $cur[$f] === '';
            if (!$overwrite && !$isBlank) continue;
            if (self::same($cur[$f], $v)) continue;
            $sets[] = "$f = ?"; $args[] = $v;
            $changes[] = ['field' => $f, 'old' => $cur[$f], 'new' => $v];
        }
        // Registrant only ever fills a blank — the registry's copy is usually
        // redacted, and a person's entry is the better record.
        if (!empty($r['registrant']) && ($cur['registrant_name'] === null || $cur['registrant_name'] === '')) {
            $sets[] = 'registrant_name = ?'; $args[] = $r['registrant'];
            $changes[] = ['field' => 'registrant_name', 'old' => null, 'new' => $r['registrant']];
        }
        // A registry where transfer locks do not exist (.uk): clear a stale
        // "off" rather than keep flagging it — silence here IS the answer.
        if (!empty($r['transfer_lock_na']) && $r['transfer_lock'] === null && $cur['transfer_lock'] !== null) {
            $sets[] = 'transfer_lock = NULL';
            $changes[] = ['field' => 'transfer_lock', 'old' => $cur['transfer_lock'], 'new' => null];
        }
        $statuses = implode(', ', $r['statuses']);
        if ($statuses !== (string)$cur['registry_statuses']) {
            $sets[] = 'registry_statuses = ?'; $args[] = $statuses !== '' ? mb_substr($statuses, 0, 500) : null;
            $changes[] = ['field' => 'registry_statuses', 'old' => $cur['registry_statuses'], 'new' => $statuses];
        }
        // The registrar, matched to a Contracts supplier by name when nobody
        // has chosen one — so the supplier's page lists the domains it holds.
        if (empty($cur['registrar_supplier_id']) && !empty($r['registrar'])) {
            $sid = self::matchSupplier($conn, $r['registrar']);
            if ($sid) {
                $sets[] = 'registrar_supplier_id = ?'; $args[] = $sid;
                $changes[] = ['field' => 'registrar_supplier_id', 'old' => null, 'new' => $sid];
            }
        }
        $sets[] = 'lookup_source = ?';            $args[] = $r['source'];
        $sets[] = 'last_lookup_datetime = UTC_TIMESTAMP()';
        $sets[] = 'last_lookup_error = NULL';
        $args[] = $id;
        $conn->prepare("UPDATE domains SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);

        foreach ($changes as $c) {
            self::audit($conn, $id, $analystId, $c['field'], self::auditDisplay($conn, $c['field'], $c['old']), self::auditDisplay($conn, $c['field'], $c['new']), 'lookup');
        }
        if (array_intersect(array_column($changes, 'field'), ['expiry_date'])) self::afterDateChange($conn);
        return $changes;
    }

    /**
     * Records that the last registration lookup for a domain failed, and why.
     *
     * @param int $id  the domain
     * @param string $error  why the lookup failed (cut to 255 characters)
     */
    public static function recordLookupError(PDO $conn, int $id, string $error): void
    {
        $conn->prepare("UPDATE domains SET last_lookup_datetime = UTC_TIMESTAMP(), last_lookup_error = ? WHERE id = ?")
             ->execute([mb_substr($error, 0, 255), $id]);
    }

    // ======================================================================
    //  Checks + change detection
    // ======================================================================

    /**
     * Run the health/security checks for one domain, store the result, and
     * compare against the last run. Returns the check result plus 'changes'.
     *
     * @param ?ActorContext $ctx null when the scheduled run calls it
     * @param int $id  the domain to check
     */
    public static function runChecks(PDO $conn, ?ActorContext $ctx, int $id): array
    {
        $row = $ctx ? self::loadForActor($conn, $ctx, $id) : self::loadRow($conn, $id);
        // GH #203: an internal domain gets the DNS-only health check, through this
        // server's resolver - never the public checks, which ask public DNS for
        // DS and connect to its hosts for certificates.
        if (!domainMayLeaveNetwork($row)) return self::runInternalChecks($conn, $row, $id);
        // A subdomain's checks need its parent: its name (DMARC and CAA are
        // inherited from it) and its DNSSEC (signed at the parent's zone).
        $checkRow = $row;
        if (!empty($row['parent_domain_id'])) {
            $p = $conn->prepare("SELECT domain_name, dnssec FROM domains WHERE id = ?");
            $p->execute([(int)$row['parent_domain_id']]);
            if ($pr = $p->fetch(PDO::FETCH_ASSOC)) {
                $checkRow['parent_name'] = $pr['domain_name'];
                $checkRow['parent_dnssec'] = $pr['dnssec'];
            }
        }
        $res = domainRunChecks($checkRow, domainSettings($conn));

        $old = $row['watch_baseline'] ? json_decode($row['watch_baseline'], true) : null;
        $new = domainBaseline($row, $res['snapshot']);
        $changes = domainBaselineDiff($old, $new);
        $baseline = domainBaselineMerge($old, $new);

        $conn->prepare(
            "UPDATE domains SET check_results = ?, security_score = ?, security_grade = ?, ssl_expiry_date = ?, ssl_issuer = ?,
                    watch_baseline = ?, last_check_datetime = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([
            json_encode(['findings' => $res['findings'], 'certificates' => $res['certificates'], 'at' => gmdate('Y-m-d H:i:s')]),
            $res['score'], $res['grade'], $res['ssl_expiry_date'], $res['ssl_issuer'] ? mb_substr($res['ssl_issuer'], 0, 255) : null,
            json_encode($baseline), $id,
        ]);

        if ($row['security_grade'] !== null && $row['security_grade'] !== $res['grade']) {
            self::audit($conn, $id, null, 'security_grade', $row['security_grade'], $res['grade'], 'check');
        }
        // A new certificate date moves its calendar entry (domain_cert_surface).
        // Only for a check somebody asked for: the scheduled run checks many
        // domains and syncs the calendar once at the end of its own accord.
        if ($ctx !== null && (string)($row['ssl_expiry_date'] ?? '') !== (string)($res['ssl_expiry_date'] ?? '')) {
            self::afterDateChange($conn);
        }
        // Registry-side fields already got a history row from the lookup that
        // changed them; only what DNS showed is recorded here.
        $dnsFieldNames = ['dns_ns' => 'dns_nameservers', 'mx' => 'dns_mx', 'spf' => 'dns_spf', 'dmarc' => 'dns_dmarc'];
        foreach ($changes as $c) {
            if (isset($dnsFieldNames[$c['field']])) {
                self::audit($conn, $id, null, $dnsFieldNames[$c['field']], $c['old'], $c['new'], 'monitor');
            }
        }
        if ($changes) {
            $serious = array_values(array_filter($changes, 'domainChangeIsSerious'));
            WorkflowEngine::dispatch('domain.changed', [
                'domain'  => self::eventPayload($row),
                'changes' => $changes,
                'serious' => $serious ? 1 : 0,
                'summary' => implode('; ', array_map(fn($c) => "{$c['field']}: {$c['old']} → {$c['new']}", $changes)),
            ]);
            // A possible hijack cannot wait for tomorrow's digest.
            if ($serious) {
                try {
                    require_once __DIR__ . '/../domains/alerts.php';
                    domainAlertOnChange($conn, $row, $serious);
                } catch (Throwable $e) {
                    error_log('domains change alert: ' . $e->getMessage());
                }
            }
        }
        $res['changes'] = $changes;
        return $res;
    }

    /**
     * The check run for an internal domain (GH #203): DNS only, health instead of
     * a grade. See includes/domains/scope.php.
     *
     * A run that completes - every name answered, or a problem confirmed - is a
     * fresh observation: it moves the completed date, and the healthy date when
     * it was healthy. A run that cannot complete (a resolver error) changes
     * neither and only marks the latest attempt incomplete, so the last known
     * state - a problem included - stays on screen beside the "not checked".
     *
     * The public results are cleared: no grade, no certificate, no expiry from
     * when the domain was public, so nothing alerts or counts on them.
     *
     * @param array $row  the domains row, already loaded for the actor
     * @param int   $id   the domain
     */
    private static function runInternalChecks(PDO $conn, array $row, int $id): array
    {
        $res = domainRunInternalChecks($row);
        $status = $res['problem'] ? 'problem' : 'healthy';

        if ($res['completed']) {
            $conn->prepare(
                "UPDATE domains SET check_results = ?, security_score = NULL, security_grade = NULL,
                        ssl_expiry_date = NULL, ssl_issuer = NULL,
                        health_status = ?, health_completed_datetime = UTC_TIMESTAMP(),
                        health_healthy_datetime = IF(? = 'healthy', UTC_TIMESTAMP(), health_healthy_datetime),
                        health_incomplete = 0, last_check_datetime = UTC_TIMESTAMP() WHERE id = ?"
            )->execute([
                json_encode(['findings' => $res['findings'], 'certificates' => [], 'at' => gmdate('Y-m-d H:i:s'), 'scope' => 'internal']),
                $status, $status, $id,
            ]);
            if (($row['health_status'] ?? null) !== $status) {
                self::audit($conn, $id, null, 'health_status', $row['health_status'] ?? null, $status, 'check');
            }
        } else {
            $conn->prepare(
                "UPDATE domains SET check_results = ?, security_score = NULL, security_grade = NULL,
                        ssl_expiry_date = NULL, ssl_issuer = NULL,
                        health_incomplete = 1, last_check_datetime = UTC_TIMESTAMP() WHERE id = ?"
            )->execute([
                json_encode(['findings' => $res['findings'], 'certificates' => [], 'at' => gmdate('Y-m-d H:i:s'), 'scope' => 'internal']),
                $id,
            ]);
        }
        $fresh = self::loadRow($conn, $id);
        return [
            'findings'        => $res['findings'],
            'score'           => null,
            'grade'           => null,
            'ssl_expiry_date' => null,
            'ssl_issuer'      => null,
            'certificates'    => [],
            'health'          => domainHealth($fresh, domainInternalInterval(domainSettings($conn))),
            'changes'         => [],
        ];
    }

    // ======================================================================
    //  Auth codes
    // ======================================================================

    /**
     * The decrypted auth code, for somebody the adapter has already checked
     * holds Cap::DOMAINS_AUTH_CODES. Recorded in the domain's history unless
     * the operator switched that off.
     *
     * @param int $id  the domain whose auth code is shown
     */
    public static function revealAuthCode(PDO $conn, ActorContext $ctx, int $id): ?string
    {
        $row = self::loadForActor($conn, $ctx, $id);
        if ($row['auth_code'] === null || $row['auth_code'] === '') return null;
        $plain = decryptValue($row['auth_code']);
        if (domainSetting($conn, 'domain_auth_code_audit') === '1') {
            self::audit($conn, $id, $ctx->actorId, 'auth_code_viewed', null, $ctx->actorName ?: '(viewed)', $ctx->source === 'api' ? 'api' : 'app');
        }
        return $plain;
    }

    // ======================================================================
    //  Statuses (a settings list)
    // ======================================================================

    /**
     * Adds or updates a domain status (name, colour, alerts on or off, active, order);
     * names must be unique.
     *
     * @param array $in  id (0 or absent = new), name (unique), colour (#rrggbb), alerts_enabled, is_active, display_order
     */
    public static function saveStatus(PDO $conn, array $in): array
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Enter a name.');
        if (mb_strlen($name) > 100) throw new ServiceError('validation', 'invalid_field', 'A name can be at most 100 characters.');
        $colour = trim((string)($in['colour'] ?? ''));
        if ($colour !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) throw new ServiceError('validation', 'invalid_field', 'Choose a colour.');
        $vals = [$name, $colour ?: null, !empty($in['alerts_enabled']) ? 1 : 0, (!isset($in['is_active']) || $in['is_active']) ? 1 : 0, (int)($in['display_order'] ?? 0)];

        $id = (int)($in['id'] ?? 0);
        $dup = $conn->prepare("SELECT id FROM domain_statuses WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $dup->execute([$name, $id]);
        if ($dup->fetchColumn()) throw new ServiceError('conflict', 'conflict', 'There is already a status with that name.');

        if ($id > 0) {
            $conn->prepare("UPDATE domain_statuses SET name = ?, colour = ?, alerts_enabled = ?, is_active = ?, display_order = ? WHERE id = ?")
                 ->execute([...$vals, $id]);
            return ['id' => $id, 'created' => false];
        }
        $conn->prepare("INSERT INTO domain_statuses (name, colour, alerts_enabled, is_active, display_order, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute($vals);
        return ['id' => (int)$conn->lastInsertId(), 'created' => true];
    }

    /**
     * Deletes a domain status; refuses while any domain uses it.
     *
     * @param int $id  the domain_statuses row to delete
     */
    public static function deleteStatus(PDO $conn, int $id): void
    {
        $inUse = $conn->prepare("SELECT COUNT(*) FROM domains WHERE status_id = ?");
        $inUse->execute([$id]);
        if ((int)$inUse->fetchColumn() > 0) {
            throw new ServiceError('conflict', 'in_use', 'Domains still use this status. Move them to another status first, or switch this one off instead.');
        }
        $conn->prepare("DELETE FROM domain_statuses WHERE id = ?")->execute([$id]);
    }

    // ======================================================================
    //  Recorded certificates (and their types)
    // ======================================================================

    /**
     * Adds or updates a certificate recorded by hand on a domain - one its checks
     * cannot reach. Recorded in the domain's history; its expiry joins the
     * certificate alerts, calendar and Watchtower (includes/domains/subdomains.php).
     *
     * @param array $in  id (absent = new), domain_id (required on create), purpose (required),
     *     cert_type_id, supplier_id OR provider_name, expiry_date (required, YYYY-MM-DD), url (http/https), notes
     * @return array{id:int, created:bool}
     */
    public static function saveManualCertificate(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!domainManualCertsReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run Database Verification first.');
        $id = (int)($in['id'] ?? 0);
        $cur = null;
        if ($id > 0) {
            $st = $conn->prepare("SELECT * FROM domain_manual_certificates WHERE id = ?");
            $st->execute([$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new ServiceError('not_found', 'not_found', 'Certificate not found.');
            $domainId = (int)$cur['domain_id'];
        } else {
            $domainId = (int)($in['domain_id'] ?? 0);
        }
        self::loadForActor($conn, $ctx, $domainId);   // out of scope reads as not found

        // One line: it goes into alert e-mail subjects and calendar titles.
        $purpose = trim(preg_replace('/\s+/u', ' ', (string)($in['purpose'] ?? '')));
        if ($purpose === '') throw new ServiceError('validation', 'missing_field', 'Say what the certificate is for.');
        if (mb_strlen($purpose) > 255) throw new ServiceError('validation', 'invalid_field', 'What it is for can be at most 255 characters.');
        $expiry = trim((string)($in['expiry_date'] ?? ''));
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $expiry);
        if (!$d || $d->format('Y-m-d') !== $expiry) throw new ServiceError('validation', 'invalid_field', 'Enter the expiry date.');
        $type = (int)($in['cert_type_id'] ?? 0);
        if ($type > 0) {
            $t = $conn->prepare("SELECT id FROM domain_certificate_types WHERE id = ? AND (is_active = 1 OR id = ?)");
            $t->execute([$type, (int)($cur['cert_type_id'] ?? 0)]);
            if (!$t->fetchColumn()) throw new ServiceError('validation', 'invalid_field', 'That certificate type is not available.');
        }
        // The provider: a Contracts supplier, or plain text when it is not one.
        $supplier = (int)($in['supplier_id'] ?? 0);
        $provider = trim((string)($in['provider_name'] ?? ''));
        if ($supplier > 0) {
            self::lookup($conn, 'suppliers', $supplier, 'supplier');
            $provider = '';
        }
        if (mb_strlen($provider) > 255) throw new ServiceError('validation', 'invalid_field', 'The provider can be at most 255 characters.');
        $url = trim((string)($in['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://\S+$#i', $url)) throw new ServiceError('validation', 'invalid_field', 'The address must start with http:// or https://');
        if (mb_strlen($url) > 500) throw new ServiceError('validation', 'invalid_field', 'The address can be at most 500 characters.');
        $notes = trim((string)($in['notes'] ?? ''));

        $vals = [$purpose, $type ?: null, $supplier ?: null, $provider !== '' ? $provider : null, $expiry,
                 $url !== '' ? $url : null, $notes !== '' ? mb_substr($notes, 0, 20000) : null];
        $source = $ctx->source === 'api' ? 'api' : 'app';
        if ($cur) {
            $conn->prepare("UPDATE domain_manual_certificates SET purpose = ?, cert_type_id = ?, supplier_id = ?, provider_name = ?,
                                   expiry_date = ?, url = ?, notes = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([...$vals, $id]);
            $old = $cur['purpose'] . ' (' . $cur['expiry_date'] . ')';
            $new = $purpose . ' (' . $expiry . ')';
            if ($old !== $new) self::audit($conn, $domainId, $ctx->actorId, 'certificate_changed', $old, $new, $source);
        } else {
            $conn->prepare("INSERT INTO domain_manual_certificates (domain_id, purpose, cert_type_id, supplier_id, provider_name, expiry_date, url, notes,
                                                                    created_by, created_datetime, updated_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                 ->execute([$domainId, ...$vals, $ctx->actorId > 0 ? $ctx->actorId : null]);
            $id = (int)$conn->lastInsertId();
            self::audit($conn, $domainId, $ctx->actorId, 'certificate_added', null, $purpose . ' (' . $expiry . ')', $source);
        }
        if (!$cur || (string)$cur['expiry_date'] !== $expiry || (string)$cur['purpose'] !== $purpose) self::afterDateChange($conn);
        return ['id' => $id, 'created' => $cur === null];
    }

    /**
     * Removes a recorded certificate, recording it in the domain's history.
     *
     * @param int $id  the domain_manual_certificates row
     */
    public static function deleteManualCertificate(PDO $conn, ActorContext $ctx, int $id): void
    {
        if (!domainManualCertsReady($conn)) return;
        $st = $conn->prepare("SELECT * FROM domain_manual_certificates WHERE id = ?");
        $st->execute([$id]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) throw new ServiceError('not_found', 'not_found', 'Certificate not found.');
        self::loadForActor($conn, $ctx, (int)$cur['domain_id']);
        $conn->prepare("DELETE FROM domain_manual_certificates WHERE id = ?")->execute([$id]);
        self::audit($conn, (int)$cur['domain_id'], $ctx->actorId, 'certificate_removed', $cur['purpose'] . ' (' . $cur['expiry_date'] . ')', null,
                    $ctx->source === 'api' ? 'api' : 'app');
        self::afterDateChange($conn);
    }

    /**
     * Adds or updates a certificate type (Domains → Settings); names must be unique.
     *
     * @param array $in  id (0 or absent = new), name (unique), is_active, display_order
     */
    public static function saveCertType(PDO $conn, array $in): array
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Enter a name.');
        if (mb_strlen($name) > 100) throw new ServiceError('validation', 'invalid_field', 'A name can be at most 100 characters.');
        $id = (int)($in['id'] ?? 0);
        $dup = $conn->prepare("SELECT id FROM domain_certificate_types WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $dup->execute([$name, $id]);
        if ($dup->fetchColumn()) throw new ServiceError('conflict', 'conflict', 'There is already a certificate type with that name.');
        $vals = [$name, (!isset($in['is_active']) || $in['is_active']) ? 1 : 0, (int)($in['display_order'] ?? 0)];
        if ($id > 0) {
            $conn->prepare("UPDATE domain_certificate_types SET name = ?, is_active = ?, display_order = ? WHERE id = ?")->execute([...$vals, $id]);
            return ['id' => $id, 'created' => false];
        }
        $conn->prepare("INSERT INTO domain_certificate_types (name, is_active, display_order, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")->execute($vals);
        return ['id' => (int)$conn->lastInsertId(), 'created' => true];
    }

    /**
     * Deletes a certificate type; refuses while a recorded certificate uses it.
     *
     * @param int $id  the domain_certificate_types row
     */
    public static function deleteCertType(PDO $conn, int $id): void
    {
        $inUse = $conn->prepare("SELECT COUNT(*) FROM domain_manual_certificates WHERE cert_type_id = ?");
        $inUse->execute([$id]);
        if ((int)$inUse->fetchColumn() > 0) {
            throw new ServiceError('conflict', 'in_use', 'Recorded certificates still use this type. Change them first, or switch this one off instead.');
        }
        $conn->prepare("DELETE FROM domain_certificate_types WHERE id = ?")->execute([$id]);
    }

    // ======================================================================
    //  Registrar accounts
    // ======================================================================

    /**
     * Adds or updates a registrar account (the login at a registrar) for a company.
     *
     * @param array $in  id (0 or absent = new), account_name (required), supplier_id, account_reference, login_url,
     *     owner_analyst_id, two_factor_holder, notes
     */
    public static function saveRegistrarAccount(PDO $conn, ActorContext $ctx, array $in, ?int $tenantId): array
    {
        $name = trim((string)($in['account_name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give the account a name.');
        $supplier = self::lookup($conn, 'suppliers', $in['supplier_id'] ?? null, 'registrar');
        $owner = null;
        if (!empty($in['owner_analyst_id'])) { $owner = (int)$in['owner_analyst_id']; self::requireAnalyst($conn, $owner); }
        $url = trim((string)($in['login_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) throw new ServiceError('validation', 'invalid_field', 'The login address is not a web address.');
        $vals = [$supplier, mb_substr($name, 0, 150), self::str($in['account_reference'] ?? null, 150), $url ?: null, $owner,
                 self::str($in['two_factor_holder'] ?? null, 255), self::str($in['notes'] ?? null, 5000)];

        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            $row = self::loadAccountForActor($conn, $ctx, $id);
            $conn->prepare("UPDATE domain_registrar_accounts SET supplier_id = ?, account_name = ?, account_reference = ?, login_url = ?,
                            owner_analyst_id = ?, two_factor_holder = ?, notes = ? WHERE id = ?")->execute([...$vals, (int)$row['id']]);
            return ['id' => $id, 'created' => false];
        }
        $store = self::storeTenant($conn, $tenantId);
        if ($ctx->companyScope !== null && !in_array($store ?? getDefaultTenantId($conn), $ctx->companyScope, true)) {
            throw new ServiceError('forbidden', 'forbidden', 'You cannot add accounts for that company.');
        }
        $conn->prepare("INSERT INTO domain_registrar_accounts (supplier_id, account_name, account_reference, login_url, owner_analyst_id,
                        two_factor_holder, notes, tenant_id, created_by, created_datetime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([...$vals, $store, $ctx->actorId > 0 ? $ctx->actorId : null]);
        return ['id' => (int)$conn->lastInsertId(), 'created' => true];
    }

    /**
     * Deletes a registrar account, unlinking it from its domains first.
     *
     * @param int $id  the domain_registrar_accounts row to delete
     */
    public static function deleteRegistrarAccount(PDO $conn, ActorContext $ctx, int $id): void
    {
        self::loadAccountForActor($conn, $ctx, $id);
        $conn->prepare("UPDATE domains SET registrar_account_id = NULL WHERE registrar_account_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM domain_registrar_accounts WHERE id = ?")->execute([$id]);
    }

    // ======================================================================
    //  Loading + scope
    // ======================================================================

    /**
     * Loads one domain; throws not found when it does not exist.
     *
     * @param int $id  the domain to load
     */
    public static function loadRow(PDO $conn, int $id): array
    {
        $st = $conn->prepare("SELECT * FROM domains WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Domain not found.');
        return $row;
    }

    /**
     * Load a domain the caller may touch — out of scope reads as not found.
     *
     * @param int $id  the domain to load
     */
    public static function loadForActor(PDO $conn, ActorContext $ctx, int $id): array
    {
        $row = self::loadRow($conn, $id);
        self::assertScope($conn, $ctx, $row);
        return $row;
    }

    /**
     * Loads a registrar account the actor's companies can reach; throws not found otherwise.
     *
     * @param int $id  the domain_registrar_accounts row to load
     */
    private static function loadAccountForActor(PDO $conn, ActorContext $ctx, int $id): array
    {
        $st = $conn->prepare("SELECT * FROM domain_registrar_accounts WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Registrar account not found.');
        self::assertScope($conn, $ctx, $row);
        return $row;
    }

    /**
     * Throws not found when the row's company is outside the actor's companies.
     *
     * @param array $row  a domain or registrar account row; must include tenant_id
     */
    private static function assertScope(PDO $conn, ActorContext $ctx, array $row): void
    {
        if ($ctx->companyScope === null || !isMultiTenant($conn)) return;
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        if (!in_array($tid, $ctx->companyScope, true)) {
            throw new ServiceError('not_found', 'not_found', 'Domain not found.');
        }
    }

    /** The Default company is stored as NULL, the way every scoped table does. */
    private static function storeTenant(PDO $conn, ?int $tenantId): ?int
    {
        if ($tenantId === null || $tenantId <= 0) return null;
        return $tenantId === getDefaultTenantId($conn) ? null : $tenantId;
    }

    // ======================================================================
    //  Validation
    // ======================================================================

    /**
     * Validates and cleans one domain field according to its type in fieldMap(), and
     * returns the value to store.
     *
     * @param string $field  the fieldMap() key being set
     * @param mixed $v  the value from the request
     * @param array $def  that field's fieldMap() entry (type, table, values, max, min)
     * @param array $row  the domain as stored (or ['tenant_id' => ...] on create), for company checks
     * @param string $domainName  the domain's name, used to read ssl_hosts entries
     */
    private static function validateField(PDO $conn, string $field, $v, array $def, array $row, string $domainName)
    {
        $blank = $v === null || (is_string($v) && trim($v) === '');
        switch ($def['type']) {
            case 'lookup':
                return self::lookup($conn, $def['table'], $v, str_replace('_', ' ', preg_replace('/_id$/', '', $field)));
            case 'cost_centre':
                // An ACTIVE cost centre of the domain's own company - or the one it
                // already has, even if that has since been switched off (GH #160 rules).
                if ($blank) return null;
                return costCentreCheck($conn, costCentreRecordTenant($conn, $row['tenant_id'] ?? null), $v,
                    isset($row['cost_centre_id']) && $row['cost_centre_id'] !== null ? (int)$row['cost_centre_id'] : null);
            case 'customer':
                // A person in the domain's own company (#153): the domain's company
                // IS the customer's company, so there is nothing else to choose.
                if ($blank) return null;
                if (!domainCustomerPersonOk($conn, (int)$v, isset($row['tenant_id']) ? (int)$row['tenant_id'] : null)) {
                    throw new ServiceError('validation', 'invalid_field', 'That person is not in this domain\'s company.');
                }
                return (int)$v;
            case 'account':
                if ($blank) return null;
                $st = $conn->prepare("SELECT tenant_id FROM domain_registrar_accounts WHERE id = ?");
                $st->execute([(int)$v]);
                $acc = $st->fetch(PDO::FETCH_ASSOC);
                if (!$acc) throw new ServiceError('validation', 'invalid_field', "Unknown registrar account id: " . (int)$v);
                // An account belongs to one company; a domain may only use its own
                // company's accounts — otherwise the link itself leaks.
                if (isMultiTenant($conn) && (($acc['tenant_id'] ?? null) <=> ($row['tenant_id'] ?? null)) !== 0) {
                    throw new ServiceError('validation', 'invalid_field', 'That registrar account belongs to a different company.');
                }
                return (int)$v;
            case 'parent':
                // A subdomain's parent: a domain in the SAME company that this
                // name really is under, that is not a subdomain itself (one
                // level), and never the domain itself.
                if ($blank) return null;
                $st = $conn->prepare("SELECT id, domain_name, tenant_id, parent_domain_id FROM domains WHERE id = ?");
                $st->execute([(int)$v]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p) throw new ServiceError('validation', 'invalid_field', 'That parent domain does not exist.');
                if (((isset($p['tenant_id']) ? (int)$p['tenant_id'] : null) <=> (isset($row['tenant_id']) ? (int)$row['tenant_id'] : null)) !== 0) {
                    throw new ServiceError('validation', 'invalid_field', 'The parent domain belongs to a different company.');
                }
                if (!domainIsUnder($domainName, (string)$p['domain_name'])) {
                    throw new ServiceError('validation', 'invalid_field', "$domainName is not a subdomain of {$p['domain_name']}.");
                }
                if (!empty($p['parent_domain_id'])) {
                    throw new ServiceError('validation', 'invalid_field', "{$p['domain_name']} is a subdomain itself - choose the domain it belongs to.");
                }
                if (!empty($row['id'])) {
                    $kids = $conn->prepare("SELECT COUNT(*) FROM domains WHERE parent_domain_id = ?");
                    $kids->execute([(int)$row['id']]);
                    if ((int)$kids->fetchColumn() > 0) {
                        throw new ServiceError('validation', 'invalid_field', 'This domain has subdomains of its own, so it cannot be a subdomain.');
                    }
                }
                return (int)$p['id'];
            case 'analyst':
                if ($blank) return null;
                self::requireAnalyst($conn, (int)$v);
                return (int)$v;
            case 'enum':
                $s = strtolower(trim((string)$v));
                if (!in_array($s, $def['values'], true)) {
                    throw new ServiceError('validation', 'invalid_field', "'$field' must be one of: " . implode(', ', $def['values']) . '.');
                }
                return $s;
            case 'date':
                if ($blank) return null;
                $d = DateTimeImmutable::createFromFormat('Y-m-d', (string)$v);
                if (!$d || $d->format('Y-m-d') !== (string)$v) throw new ServiceError('validation', 'invalid_field', "'$field' must be a date in YYYY-MM-DD format.");
                return (string)$v;
            case 'tribool':
                if ($blank) return null;
                return in_array($v, [1, '1', true, 'true', 'yes', 'on'], true) ? 1 : 0;
            case 'bool':
                return in_array($v, [1, '1', true, 'true', 'yes', 'on'], true) ? 1 : 0;
            case 'string':
                if ($blank) return null;
                $s = trim((string)$v);
                if (mb_strlen($s) > $def['max']) throw new ServiceError('validation', 'invalid_field', "'$field' can be at most {$def['max']} characters.");
                return $s;
            case 'text':
                if ($blank) return null;
                return mb_substr(trim((string)$v), 0, 20000);
            case 'lines':
                if ($blank) return null;
                $lines = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(rtrim(trim($x), '.')), preg_split('/[\r\n,;\s]+/', (string)$v)))));
                sort($lines);
                return $lines ? implode("\n", $lines) : null;
            case 'ssl_hosts':
                if ($blank) return null;
                $hosts = domainParseSslHosts((string)$v, $domainName);
                return $hosts ? mb_substr(implode(', ', $hosts), 0, 500) : null;
            case 'money':
                if ($blank) return null;
                if (!is_numeric($v) || (float)$v < 0) throw new ServiceError('validation', 'invalid_field', "'$field' must be a number.");
                return (string)round((float)$v, 2);
            case 'currency':
                if ($blank) return null;
                return moneyServiceCurrency($v);
            case 'int':
                if ($blank) return $def['min'];
                if (!is_numeric($v) || (int)$v < $def['min'] || (int)$v > $def['max']) {
                    throw new ServiceError('validation', 'invalid_field', "'$field' must be a whole number from {$def['min']} to {$def['max']}.");
                }
                return (int)$v;
            case 'tags':
                if ($blank) return null;
                $tags = [];
                foreach (preg_split('/[,;]+/', (string)$v) as $t) {
                    $t = trim($t);
                    if ($t !== '') $tags[mb_strtolower($t)] ??= $t;   // the first spelling typed wins
                }
                return $tags ? mb_substr(implode(', ', array_values($tags)), 0, 500) : null;
        }
        return $v;
    }

    /**
     * Is this field's column there yet? The #153 and #162 columns arrive with Database Verification.
     *
     * @param string $field  a fieldMap() key
     */
    /**
     * GH #188: a domain's cost centre is a real one (cost_centre_id), as on
     * contracts. Once Database Verification has added the column:
     *  - cost_centre_id chosen: the old free text goes - it has been replaced;
     *  - cost_centre (text) sent without an id - the REST API, the CSV import
     *    and the bulk editor always took text - is a CODE in the domain's
     *    company: linked if it exists, refused if not; '' clears both.
     * Before Verification, nothing changes: the text is stored as it always was.
     *
     * @param array $in   the request fields
     * @param array $cur  the stored domain row on update; on create just ['tenant_id' => ...]
     */
    private static function normaliseCostCentre(PDO $conn, array $in, array $cur): array
    {
        if (!costCentreReady($conn, 'domains')) return $in;
        $tenant = costCentreRecordTenant($conn, $cur['tenant_id'] ?? null);
        if (array_key_exists('cost_centre', $in) && !array_key_exists('cost_centre_id', $in)) {
            $code = trim((string)$in['cost_centre']);
            if ($code === '') {
                $in['cost_centre_id'] = null;
                $in['cost_centre'] = null;
                return $in;
            }
            $cc = costCentreByCode($conn, $tenant, $code);
            if (!$cc) throw new ServiceError('validation', 'invalid_field', "No cost centre with the code \"{$code}\" in this domain's company (System - Cost centres).");
            $in['cost_centre_id'] = (int)$cc['id'];
            $in['cost_centre'] = null;
            return $in;
        }
        if (array_key_exists('cost_centre_id', $in) && $in['cost_centre_id'] !== null && $in['cost_centre_id'] !== '' && (int)$in['cost_centre_id'] > 0) {
            // Chosen: the old text has been replaced. Choosing none keeps it, so
            // saving the form without touching the box never wipes what was typed.
            $in['cost_centre'] = null;
        } else {
            unset($in['cost_centre']);
        }
        return $in;
    }

    private static function fieldReady(PDO $conn, string $field): bool
    {
        if ($field === 'customer_user_id') return domainCustomerReady($conn);
        if (in_array($field, ['customer_supplier_id', 'customer_contact_id', 'tech_analyst_id'], true)) return domainPartiesReady($conn);
        if ($field === 'cost_centre_id') return costCentreReady($conn, 'domains');
        if ($field === 'parent_domain_id') return domainSubdomainsReady($conn);
        if ($field === 'scope') return domainScopeReady($conn);
        return true;
    }

    /**
     * #162: a domain has ONE customer - a person, or a supplier (optionally with
     * one of its contacts) - and ONE technical contact - a supplier contact, or
     * an analyst. Setting one kind clears the other, so a caller (the edit
     * dialog, or a REST PATCH naming just one field) never has to know which
     * kind was there before. Naming both kinds at once is refused rather than
     * guessed. Clearing a field clears only that field, except that clearing the
     * supplier also clears its contact.
     *
     * @param array $in  the request fields; the customer and technical contact keys are adjusted
     * @param array $cur  the stored domain row on update; [] on create
     */
    private static function normaliseParties(PDO $conn, array $in, array $cur): array
    {
        if (!domainPartiesReady($conn)) return $in;
        $given = fn(string $k) => array_key_exists($k, $in) && !($in[$k] === null || $in[$k] === '' || $in[$k] === 0 || $in[$k] === '0');

        if ($given('tech_analyst_id') && $given('tech_contact_id')) {
            throw new ServiceError('validation', 'invalid_field', 'A domain has one technical contact: an analyst or a supplier contact, not both.');
        }
        if ($given('tech_analyst_id')) $in['tech_contact_id'] = null;
        if ($given('tech_contact_id')) $in['tech_analyst_id'] = null;

        $user = $given('customer_user_id');
        $sup  = $given('customer_supplier_id');
        $con  = $given('customer_contact_id');
        if ($user && ($sup || $con)) {
            throw new ServiceError('validation', 'invalid_field', 'A domain\'s customer is a person or a supplier, not both.');
        }
        if ($user) {
            $in['customer_supplier_id'] = null;
            $in['customer_contact_id']  = null;
        } elseif ($con) {
            // A contact brings its supplier with it.
            $st = $conn->prepare("SELECT supplier_id FROM contacts WHERE id = ?");
            $st->execute([(int)$in['customer_contact_id']]);
            $owner = $st->fetchColumn();
            if ($owner !== false) {
                $owner = $owner === null ? null : (int)$owner;
                if ($sup && (int)$in['customer_supplier_id'] !== $owner) {
                    throw new ServiceError('validation', 'invalid_field', 'That contact does not belong to that supplier.');
                }
                $in['customer_supplier_id'] = $owner;
            }
            $in['customer_user_id'] = null;
        } elseif ($sup) {
            $in['customer_user_id'] = null;
            // A contact left over from a different supplier no longer fits.
            if (!array_key_exists('customer_contact_id', $in) && !empty($cur['customer_contact_id'])) {
                $st = $conn->prepare("SELECT supplier_id FROM contacts WHERE id = ?");
                $st->execute([(int)$cur['customer_contact_id']]);
                if ((int)$st->fetchColumn() !== (int)$in['customer_supplier_id']) $in['customer_contact_id'] = null;
            }
        } elseif (array_key_exists('customer_supplier_id', $in) && !array_key_exists('customer_contact_id', $in)) {
            $in['customer_contact_id'] = null;
        }
        return $in;
    }

    /**
     * A validated id from a lookup table, or null for none; throws for an unknown id.
     *
     * @param string $table  the lookup table, written in code - never user input
     * @param mixed $value  the id from the request; null, '', 0 or '0' gives null
     * @param string $label  what the table holds, for the error message
     */
    private static function lookup(PDO $conn, string $table, $value, string $label): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') return null;
        $id = (int)$value;
        $st = $conn->prepare("SELECT id FROM `$table` WHERE id = ?");
        $st->execute([$id]);
        if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', "Unknown {$label} id: {$id}");
        return $id;
    }

    /**
     * Throws unless the id is an active analyst.
     *
     * @param int $id  the analyst to check
     */
    private static function requireAnalyst(PDO $conn, int $id): void
    {
        $st = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND is_active = 1");
        $st->execute([$id]);
        if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$id}");
    }

    /**
     * Trimmed text cut to $max characters, or null when blank.
     *
     * @param mixed $v  the value from the request
     * @param int $max  the most characters to keep
     */
    private static function str($v, int $max): ?string
    {
        $s = trim((string)$v);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /**
     * True when two values are equal, treating null and '' as the same.
     *
     * @param mixed $a  the stored value
     * @param mixed $b  the new value
     */
    private static function same($a, $b): bool
    {
        if (($a === null || $a === '') && ($b === null || $b === '')) return true;
        return (string)$a === (string)$b;
    }

    /**
     * A supplier whose legal or trading name matches the registry's registrar name.
     *
     * @param string $registrar  the registrar's name as the registry gives it
     */
    private static function matchSupplier(PDO $conn, string $registrar): ?int
    {
        $clean = fn($s) => trim(preg_replace('/[,.]|\b(inc|ltd|limited|llc|gmbh|plc|corp|corporation|s\.?a\.?|b\.?v\.?)\b/i', '', mb_strtolower($s)));
        $want = $clean($registrar);
        if ($want === '') return null;
        try {
            $rows = $conn->query("SELECT id, legal_name, trading_name FROM suppliers WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { return null; }
        foreach ($rows as $r) {
            foreach ([$r['legal_name'], $r['trading_name']] as $n) {
                if ($n && $clean($n) === $want) return (int)$r['id'];
            }
        }
        return null;
    }

    // ======================================================================
    //  History, events, side effects
    // ======================================================================

    /**
     * Records one change in a domain's history; a failure is logged, never thrown.
     *
     * @param int $domainId  the domain the entry belongs to
     * @param string $field  the field that changed, or an event such as 'domain_created' or 'auth_code_viewed'
     * @param mixed $old  the value before, as shown (cut to 1000 characters); null for none
     * @param mixed $new  the value after, as shown (cut to 1000 characters); null for none
     * @param string $source  what made the change: 'app', 'api', 'lookup', 'check' or 'monitor'
     * @writes domain_audit - the domain's change history
     */
    public static function audit(PDO $conn, int $domainId, ?int $analystId, string $field, $old, $new, string $source = 'app'): void
    {
        try {
            $conn->prepare("INSERT INTO domain_audit (domain_id, analyst_id, field_name, old_value, new_value, source, created_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$domainId, ($analystId && $analystId > 0) ? $analystId : null, $field,
                            $old === null ? null : mb_substr((string)$old, 0, 1000),
                            $new === null ? null : mb_substr((string)$new, 0, 1000), $source]);
        } catch (Throwable $e) {
            error_log('domains audit: ' . $e->getMessage());
        }
    }

    /**
     * A domain may only be linked to a contract its editor can see - one in a
     * company outside their reach is "unknown", as everywhere else - and, since
     * contracts belong to a company (3.5.0), only to one in the DOMAIN's company.
     * $domainTenant is the domain's stored company (NULL = Default).
     *
     * @param mixed $contractId  the contract being linked; null does nothing
     * @param ?int $domainTenant  the domain's stored company - null for the Default company
     */
    private static function assertContractVisible(PDO $conn, ActorContext $ctx, $contractId, ?int $domainTenant = null): void
    {
        if ($contractId === null) return;
        [$vis, $args] = contractVisibilitySqlForScope($conn, $ctx->companyScope, 'k');
        if ($vis !== '') {
            $st = $conn->prepare("SELECT 1 FROM contracts k WHERE k.id = ?$vis");
            $st->execute(array_merge([(int)$contractId], $args));
            if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', 'Unknown contract id: ' . (int)$contractId);
        }
        if (isMultiTenant($conn) && contractTenantReady($conn)) {
            $mine = $domainTenant ?? getDefaultTenantId($conn);
            if (contractTenantOf($conn, (int)$contractId) !== $mine) {
                throw new ServiceError('validation', 'invalid_field', 'That contract belongs to a different company from this domain.');
            }
        }
    }

    /**
     * Ids stored as names, so the history reads as English a year later.
     *
     * @param string $field  the field the value belongs to
     * @param mixed $v  the stored value - an id for the lookup fields
     */
    private static function auditDisplay(PDO $conn, string $field, $v): ?string
    {
        if ($v === null || $v === '') return null;
        $q = [
            'status_id'             => "SELECT name FROM domain_statuses WHERE id = ?",
            'registrar_supplier_id' => "SELECT COALESCE(NULLIF(trading_name, ''), legal_name) FROM suppliers WHERE id = ?",
            'registrar_account_id'  => "SELECT account_name FROM domain_registrar_accounts WHERE id = ?",
            'owner_analyst_id'      => "SELECT full_name FROM analysts WHERE id = ?",
            'tech_contact_id'       => "SELECT CONCAT(first_name, ' ', surname) FROM contacts WHERE id = ?",
            'tech_analyst_id'       => "SELECT full_name FROM analysts WHERE id = ?",
            'customer_supplier_id'  => "SELECT COALESCE(NULLIF(trading_name, ''), legal_name) FROM suppliers WHERE id = ?",
            'customer_contact_id'   => "SELECT CONCAT(first_name, ' ', surname) FROM contacts WHERE id = ?",
            'contract_id'           => "SELECT CONCAT(contract_number, ' ', title) FROM contracts WHERE id = ?",
            'cost_centre_id'        => "SELECT CONCAT(code, ' ', name) FROM cost_centres WHERE id = ?",
            'customer_user_id'      => "SELECT COALESCE(NULLIF(display_name, ''), email) FROM users WHERE id = ?",
            'parent_domain_id'      => "SELECT domain_name FROM domains WHERE id = ?",
        ][$field] ?? null;
        if ($q) {
            try { $st = $conn->prepare($q); $st->execute([(int)$v]); $n = $st->fetchColumn(); if ($n) return (string)$n; }
            catch (Throwable) {}
        }
        if (in_array($field, ['transfer_lock', 'registry_lock', 'dnssec', 'monitoring_enabled'], true)) return (int)$v ? 'Yes' : 'No';
        return (string)$v;
    }

    /**
     * The payload every domain.* event carries.
     *
     * @param array $row  the domains row
     */
    public static function eventPayload(array $row): array
    {
        return [
            'id'               => (int)$row['id'],
            'name'             => $row['domain_name'],
            'expiry_date'      => $row['expiry_date'],
            'status_id'        => $row['status_id'] !== null ? (int)$row['status_id'] : null,
            'purpose'          => $row['purpose'],
            'owner_analyst_id' => $row['owner_analyst_id'] !== null ? (int)$row['owner_analyst_id'] : null,
            'registrar'        => $row['registrar_name'],
            'company_id'       => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
            'customer_user_id' => isset($row['customer_user_id']) ? (int)$row['customer_user_id'] : null,
            'customer_supplier_id' => isset($row['customer_supplier_id']) ? (int)$row['customer_supplier_id'] : null,
            'customer_contact_id'  => isset($row['customer_contact_id']) ? (int)$row['customer_contact_id'] : null,
            'security_grade'   => $row['security_grade'],
        ];
    }

    /**
     * Fires the domain.<verb> workflow event for a domain; a failure is logged, never thrown.
     *
     * @param string $verb  'created' or 'updated' - the event is domain.<verb>
     * @param int $id  the domain
     */
    private static function dispatchCrud(PDO $conn, string $verb, int $id): void
    {
        try {
            $row = self::loadRow($conn, $id);
            WorkflowEngine::dispatch('domain.' . $verb, ['domain' => self::eventPayload($row)]);
        } catch (Throwable $e) {
            error_log('domains dispatch: ' . $e->getMessage());
        }
    }

    /** Expiry dates feed the calendar; keep it in step. Never fails the write. */
    private static function afterDateChange(PDO $conn): void
    {
        try {
            require_once __DIR__ . '/../domains/calendar.php';
            domainSyncExpiryCalendar($conn);
        } catch (Throwable $e) {
            error_log('domains calendar sync: ' . $e->getMessage());
        }
    }
}
