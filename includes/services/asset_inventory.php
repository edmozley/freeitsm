<?php
/**
 * Inventory observations - ONE implementation for every source that reports what it saw
 * on a device: the inventory agent and REST tools (GH #194, GH #200 Part B).
 *
 * The rows sit in the inventory tables themselves - software, volumes, network adapters,
 * and for the agent also physical drives and Device Manager - tagged with their source
 * ('agent', or 'rest:<name>' for a REST tool), so every screen, report and API that reads
 * inventory sees them, and no source touches another's rows. Beside them,
 * asset_inventory_sources says which sources report on an asset, and
 * asset_inventory_sections says, per source and section, when the last GOOD data was
 * observed and what the last ATTEMPT came to: succeeded, empty, skipped or failed.
 *
 * Two callers, one set of rules:
 *   - submit()      a REST report (wiki REST-API-Assets). Strict: anything malformed is
 *                   refused, and the rules below on ownership, ordering and replay apply.
 *   - agentReport() the agent's three endpoints (api/external/...). Lenient, as the agent
 *                   always was: values are trimmed to fit rather than refused, because a
 *                   long registry string on one PC must never stop it reporting.
 * Both reconcile through applySection() and record through recordOutcome().
 *
 * REST rules (wiki: API-Inventory-and-Evidence-Design, Part B):
 *   - A source name belongs to ONE analyst per company - the analyst behind the first key
 *     that wrote it (an integration's service account). A rotated key acts as the same
 *     analyst, so it keeps writing; another integration cannot clear this one's rows by
 *     sending its name. Built-in source names are refused.
 *   - (source, source_device_ref) is linked to one asset per company. Naming the same
 *     device on another asset is a 409 naming the asset - never a silent move. Unlinking
 *     (DELETE) is the deliberate re-link.
 *   - Each section is judged on its own: older than the newest applied is STALE (left
 *     alone), the same time and content is REPLAYED (nothing written), the same time and
 *     different content FAILS (observation_conflict). Complete = brought exactly into line,
 *     empty included; partial = added and updated, nothing removed; omitted = untouched.
 *   - Partial success: a section that fails validation or conflicts is rolled back to its
 *     own savepoint, named in the response and recorded as failed; the others apply. A
 *     failed section records no watermark, so sending the same report again retries just
 *     that section - the sections that applied answer "replayed".
 *   - Never touched: assets.last_seen (it means "the agent reported"), the asset's own
 *     fields, other sources' rows.
 *
 * Contributed by @Dr4inder (PR #197); ownership, partial success and reconciliation by
 * row reworked in review - see wiki REST-Inventory-and-Evidence-PR-Review. The agent was
 * moved onto it in GH #200 Part B.
 *
 * @wiki REST-API-Assets
 * @wiki Inventory-Freshness-Developer-Guide
 */
require_once __DIR__ . '/../service_context.php';   // ServiceError, thrown below (GH #288)
require_once __DIR__ . '/assets.php';

final class AssetInventoryService
{
    /** The sections a REST tool may report, and the table each one lives in. */
    private const TABLES = ['software' => 'software_inventory_detail', 'disks' => 'asset_disks', 'network_adapters' => 'asset_network_adapters'];

    /**
     * A REST tool may also report what is physically connected to the device (GH #200
     * part C). Not an inventory table: the rows live in asset_connections, written by
     * AssetConnectionsService.
     */
    private const REST_EXTRA = ['connections'];

    /** The agent reports two more, kept in tables only the agent writes. */
    public const AGENT_SECTIONS = ['software', 'disks', 'physical_disks', 'network_adapters', 'devices'];

    /** Names FreeITSM writes itself, or that read as if it did. */
    private const RESERVED = ['agent', 'intune', 'proxmox', 'vcloud', 'vcenter', 'manual', 'api', 'system', 'freeitsm'];

    /** What an attempt at a section came to (asset_inventory_sections.last_outcome). */
    public const OUTCOMES = ['succeeded', 'empty', 'skipped', 'failed'];

    /** The stored source tag for a REST source name. */
    public static function storedSource(string $source): string
    {
        return 'rest:' . $source;
    }

    /**
     * Apply one REST report. Whole-request problems (the envelope, the asset, ownership, a
     * device linked elsewhere) throw ServiceError; a section's own problem is reported in
     * its result, recorded as failed, and the rest still apply.
     *
     * @return array{asset_id:int, source:string, source_device_ref:string, observed_at:string, sections:array<string,array>}
     */
    public static function submit(PDO $conn, ActorContext $ctx, int $assetId, array $in): array
    {
        [$source, $deviceRef, $observed, $rawSections] = self::envelope($in);
        if ($conn->inTransaction()) { throw new LogicException('Inventory submission must own its transaction.'); }
        $stored = self::storedSource($source);
        $discovered = [];
        $conn->beginTransaction();
        try {
            $asset = self::asset($conn, $ctx, $assetId, true);
            $tenant = $asset['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$asset['tenant_id'];
            self::claimSource($conn, $ctx, $tenant, $source);
            $bindingId = self::bind($conn, $assetId, $tenant, $source, $deviceRef);

            $result = ['asset_id' => $assetId, 'source' => $source, 'source_device_ref' => $deviceRef,
                       'observed_at' => self::iso($observed), 'sections' => []];
            $applied = [];
            foreach ($rawSections as $name => $raw) {
                $conn->exec('SAVEPOINT inv_section');
                $discoveredBefore = count($discovered);
                try {
                    $section = self::section($name, $raw);
                    $fingerprint = self::fingerprint($section);
                    $get = $conn->prepare('SELECT observed_at, fingerprint FROM asset_inventory_sections WHERE inventory_source_id = ? AND section_name = ?');
                    $get->execute([$bindingId, $name]);
                    $previous = $get->fetch(PDO::FETCH_ASSOC);
                    // A section on record only for a failed attempt has no good data to compare with.
                    if ($previous && $previous['observed_at'] === null) { $previous = false; }
                    if ($previous && $observed < $previous['observed_at']) {
                        $result['sections'][$name] = ['result' => 'stale', 'newer_observed_at' => self::iso($previous['observed_at'])];
                        $conn->exec('RELEASE SAVEPOINT inv_section');
                        continue;
                    }
                    if ($previous && $observed === $previous['observed_at']) {
                        if ($previous['fingerprint'] !== $fingerprint) {
                            throw new ServiceError('conflict', 'observation_conflict', "A different $name list was already applied for this observation time.");
                        }
                        $result['sections'][$name] = ['result' => 'replayed'];
                        $conn->exec('RELEASE SAVEPOINT inv_section');
                        continue;
                    }
                    if ($name === 'connections') {
                        require_once __DIR__ . '/asset_connections.php';
                        $counts = AssetConnectionsService::applyObserved($conn, $assetId, $asset['tenant_id'] === null ? null : (int)$asset['tenant_id'], $stored, $section, $observed);
                    } else {
                        $counts = self::applySection($conn, $assetId, $stored, $name, $section, $observed, $discovered, 'api', true);
                    }
                    self::recordOutcome($conn, $bindingId, $name, self::appliedOutcome($section), $ctx->actorId, $observed, $fingerprint);
                    $conn->exec('RELEASE SAVEPOINT inv_section');
                    $result['sections'][$name] = ['result' => 'applied'] + $counts;
                    $applied[] = $name;
                } catch (ServiceError $e) {
                    // This section only: back to its savepoint, named in the response, and
                    // recorded as failed beside whatever good data it had before.
                    $conn->exec('ROLLBACK TO SAVEPOINT inv_section');
                    array_splice($discovered, $discoveredBefore);   // their catalogue rows were rolled back too
                    self::recordOutcome($conn, $bindingId, $name, 'failed', $ctx->actorId, null, null, $e->getMessage());
                    $result['sections'][$name] = ['result' => 'failed', 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
                }
            }
            if ($applied) {
                $conn->prepare('INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                    VALUES (?, ?, ?, NULL, ?, UTC_TIMESTAMP())')->execute([$assetId, $ctx->actorId, 'inventory_import',
                    $source . ' (' . implode(', ', $applied) . '), observed ' . $observed . ' UTC']);
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
        self::dispatchDiscovered($discovered);
        return $result;
    }

    /**
     * Apply one report from the inventory agent to an asset it has already been matched to.
     *
     * Each entry of $sections says what the agent did with that section:
     *   ['state' => 'apply', 'items' => [...]]  collected - the list is the whole truth, empty included
     *   ['state' => 'failed', 'error' => '...'] tried and could not; the last good data stays
     *   ['state' => 'skipped']                  not in this report; the last good data stays
     * Items are in the agent's own shapes (see agentItems()) and are trimmed to fit, never
     * refused. A section that throws is rolled back to its savepoint and recorded as failed;
     * the others still apply.
     *
     * TRAP: the endpoint decides 'skipped' vs 'apply' for an EMPTY list, not this method. An
     * agent that predates report_version 2 sends [] when it could not find out, so its empty
     * list must arrive here as 'skipped' (wiki Inventory-Agent, the 226-row Device Manager wipe).
     *
     * @param array<string,array> $sections keyed by AGENT_SECTIONS names
     * @return array<string,array{result:string, added?:int, updated?:int, removed?:int, error?:string}>
     */
    public static function agentReport(PDO $conn, int $assetId, array $sections): array
    {
        if ($conn->inTransaction()) { throw new LogicException('Inventory submission must own its transaction.'); }
        $tracked = self::hasOutcomeColumns($conn);
        $observed = gmdate('Y-m-d H:i:s');   // the agent reports what it sees as it sees it
        $discovered = [];
        $results = [];
        $conn->beginTransaction();
        try {
            $bindingId = $tracked ? self::bindAgent($conn, $assetId) : null;
            foreach (self::AGENT_SECTIONS as $name) {
                $entry = $sections[$name] ?? null;
                if ($entry === null) { continue; }   // not this endpoint's section at all
                $state = $entry['state'] ?? 'skipped';
                if ($state === 'failed') {
                    $error = mb_substr(trim((string)($entry['error'] ?? '')) ?: 'The agent could not collect this section.', 0, 500);
                    if ($bindingId) { self::recordOutcome($conn, $bindingId, $name, 'failed', 0, null, null, $error); }
                    $results[$name] = ['result' => 'failed', 'error' => $error];
                    continue;
                }
                if ($state !== 'apply') {
                    if ($bindingId) { self::recordOutcome($conn, $bindingId, $name, 'skipped', 0); }
                    $results[$name] = ['result' => 'skipped'];
                    continue;
                }
                $conn->exec('SAVEPOINT inv_section');
                $discoveredBefore = count($discovered);
                try {
                    $section = ['complete' => true, 'items' => self::agentItems($name, is_array($entry['items'] ?? null) ? $entry['items'] : [])];
                    if (isset(self::TABLES[$name])) {
                        $counts = self::applySection($conn, $assetId, 'agent', $name, $section, $observed, $discovered, null, false);
                        if ($name === 'disks') {
                            // TRAP: 'intune' too. Intune's volume is only a stand-in until the agent
                            // reports (intuneSyncDisks() skips an asset with agent rows), so the agent
                            // replaces it as it always has. REST sources ('rest:...') are another
                            // tool's view and are left alone.
                            $del = $conn->prepare("DELETE FROM asset_disks WHERE asset_id = ? AND source = 'intune'");
                            $del->execute([$assetId]);
                            $counts['removed'] += $del->rowCount();
                        }
                    } else {
                        $counts = self::replaceAgentOnly($conn, $assetId, $name, $section['items']);
                    }
                    if ($bindingId) { self::recordOutcome($conn, $bindingId, $name, self::appliedOutcome($section), 0, $observed, self::fingerprint($section)); }
                    $conn->exec('RELEASE SAVEPOINT inv_section');
                    $results[$name] = ['result' => 'applied'] + $counts;
                } catch (Throwable $e) {
                    // ⚠️ Any error, not only ServiceError: physical drives' table only exists
                    // after Database Verification, and one missing table must never stop a
                    // whole estate of agents reporting everything else.
                    $conn->exec('ROLLBACK TO SAVEPOINT inv_section');
                    array_splice($discovered, $discoveredBefore);
                    $error = mb_substr($e->getMessage(), 0, 500);
                    if ($bindingId) { self::recordOutcome($conn, $bindingId, $name, 'failed', 0, null, null, $error); }
                    $results[$name] = ['result' => 'failed', 'error' => $error];
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
        self::dispatchDiscovered($discovered);
        return $results;
    }

    /**
     * What one section of an agent report means, for agentReport().
     *
     * An agent that sends report_version 2 says what it did: a list - even an empty one - is
     * what it found, a named collection failure is a failure, and a section left out was not
     * collected. An older agent cannot say, and sends [] both for "nothing there" and for
     * "could not find out", so for it only a non-empty list counts and anything else is
     * skipped (the 226-row Device Manager wipe, wiki Inventory-Agent).
     *
     * @param mixed   $value   the section as the agent sent it
     * @param bool    $v2      the report carried report_version >= 2
     * @param mixed   $failure the agent's message for this section from collection_failures, if any
     */
    public static function agentState($value, bool $v2, $failure = null): array
    {
        if ($v2 && is_string($failure) && trim($failure) !== '') { return ['state' => 'failed', 'error' => $failure]; }
        if ($v2 ? is_array($value) : (is_array($value) && $value)) { return ['state' => 'apply', 'items' => $value]; }
        return ['state' => 'skipped'];
    }

    /**
     * Unlink a source from an asset: its link, its section times and every row it
     * reported here are removed. The deliberate way to move a device reference to
     * another asset (a reimaged machine) - the next report then links it afresh.
     */
    public static function unlink(PDO $conn, ActorContext $ctx, int $assetId, string $source): array
    {
        if ($conn->inTransaction()) { throw new LogicException('Inventory unlink must own its transaction.'); }
        $conn->beginTransaction();
        try {
            $asset = self::asset($conn, $ctx, $assetId, true);
            $tenant = $asset['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$asset['tenant_id'];
            $find = $conn->prepare('SELECT id, source_device_ref FROM asset_inventory_sources WHERE asset_id = ? AND source = ?');
            $find->execute([$assetId, $source]);
            $binding = $find->fetch(PDO::FETCH_ASSOC);
            // The agent's own link is not a REST source's to remove.
            if (!$binding || in_array($source, self::RESERVED, true)) { throw new ServiceError('not_found', 'not_found', 'This source is not linked to this asset.'); }
            self::claimSource($conn, $ctx, $tenant, $source, false);
            $removed = [];
            foreach (self::TABLES as $name => $table) {
                $host = $name === 'software' ? 'host_id' : 'asset_id';
                $del = $conn->prepare("DELETE FROM $table WHERE $host = ? AND source = ?");
                $del->execute([$assetId, self::storedSource($source)]);
                $removed[$name] = $del->rowCount();
            }
            // Its connections from this asset go too (GH #200 part C); a person's never do.
            try {
                $del = $conn->prepare('DELETE FROM asset_connections WHERE asset_id = ? AND source = ?');
                $del->execute([$assetId, self::storedSource($source)]);
                $removed['connections'] = $del->rowCount();
            } catch (PDOException) {
                // asset_connections before Database Verification: there are none.
            }
            $conn->prepare('DELETE FROM asset_inventory_sections WHERE inventory_source_id = ?')->execute([$binding['id']]);
            $conn->prepare('DELETE FROM asset_inventory_sources WHERE id = ?')->execute([$binding['id']]);
            $conn->prepare('INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                VALUES (?, ?, ?, ?, NULL, UTC_TIMESTAMP())')->execute([$assetId, $ctx->actorId, 'inventory_import',
                $source . ' (' . $binding['source_device_ref'] . ') unlinked']);
            $conn->commit();
            return ['asset_id' => $assetId, 'source' => $source, 'removed' => $removed];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
    }

    /**
     * Which sources report on this asset, and for each section: when its last good data was
     * observed and imported, and what the last attempt came to. The agent is listed as
     * 'agent', with no device reference of its own.
     */
    public static function provenance(PDO $conn, ActorContext $ctx, int $assetId): array
    {
        self::asset($conn, $ctx, $assetId, false);
        $tracked = self::hasOutcomeColumns($conn);
        $stmt = $conn->prepare('SELECT s.id, s.source, s.source_device_ref, o.owner_analyst_id
            FROM asset_inventory_sources s
            LEFT JOIN asset_inventory_source_owners o ON o.tenant_id = s.tenant_id AND o.source = s.source
            WHERE s.asset_id = ? ORDER BY s.source = \'agent\' DESC, s.source');
        $stmt->execute([$assetId]);
        $sections = $conn->prepare('SELECT section_name, observed_at, imported_at, imported_by_id'
            . ($tracked ? ', last_outcome, last_error, last_attempt_at' : '')
            . ' FROM asset_inventory_sections WHERE inventory_source_id = ? ORDER BY section_name');
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sections->execute([$row['id']]);
            $state = [];
            foreach ($sections->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $state[$s['section_name']] = [
                    'observed_at'     => self::iso($s['observed_at']),
                    'imported_at'     => self::iso($s['imported_at']),
                    'imported_by_id'  => $s['imported_by_id'] === null ? null : (int)$s['imported_by_id'],
                    // Before Database Verification adds the outcome, a row only ever meant success.
                    'last_outcome'    => $tracked ? $s['last_outcome'] : 'succeeded',
                    'last_error'      => $tracked ? $s['last_error'] : null,
                    'last_attempt_at' => self::iso($tracked ? $s['last_attempt_at'] : $s['imported_at']),
                ];
            }
            $agent = $row['source'] === 'agent';
            $rows[] = ['source' => $row['source'], 'source_device_ref' => $agent ? null : $row['source_device_ref'],
                'owner_analyst_id' => $row['owner_analyst_id'] === null ? null : (int)$row['owner_analyst_id'], 'sections' => (object)$state];
        }
        return $rows;
    }

    /** The setting: after how many days an observation counts as stale on the asset page. */
    public const STALE_SETTING = 'asset_inventory_stale_days';

    /**
     * After how many days an inventory list counts as stale (Assets -> Settings ->
     * Discovery & reconciliation). 7 by default - the same as Last seen and Watchtower.
     */
    public static function staleDays(PDO $conn): int
    {
        try {
            $st = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
            $st->execute([self::STALE_SETTING]);
            $days = (int)$st->fetchColumn();
        } catch (Throwable) {
            $days = 0;
        }
        return $days >= 1 && $days <= 365 ? $days : 7;
    }

    /**
     * Has Database Verification added the outcome columns yet? Asked once per request: an
     * install that has pulled this code but not yet verified must keep taking agent reports.
     */
    public static function hasOutcomeColumns(PDO $conn): bool
    {
        static $has = null;
        if ($has === null) {
            try { $has = (bool)$conn->query("SHOW COLUMNS FROM asset_inventory_sections LIKE 'last_outcome'")->fetch(); }
            catch (Throwable) { $has = false; }
        }
        return $has;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /** The report's envelope: anything wrong here refuses the whole report. */
    private static function envelope(array $in): array
    {
        self::keys($in, ['schema_version', 'source', 'source_device_ref', 'observed_at', 'sections']);
        if (($in['schema_version'] ?? null) !== 1) { self::invalid('Unsupported schema_version; expected integer 1.'); }
        $source = self::text($in, 'source', 48, true);
        if (!preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D', $source)) { self::invalid('source must use lowercase ASCII letters, digits, dots, underscores or hyphens.'); }
        if (in_array($source, self::RESERVED, true)) { self::invalid("'$source' is a source name FreeITSM uses itself; choose your tool's own name."); }
        $deviceRef = self::text($in, 'source_device_ref', 128, true);
        $stamp = self::text($in, 'observed_at', 35, true);
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $stamp)) {
            self::invalid('observed_at must be an RFC3339 timestamp with seconds and an explicit timezone.');
        }
        try { $date = new DateTimeImmutable($stamp); }
        catch (Exception) { self::invalid('Invalid observed_at.'); }
        $errors = DateTimeImmutable::getLastErrors();
        if (($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->getTimestamp() > time() + 300
            || (int)$date->format('Y') < 1000) { self::invalid('observed_at must be a valid observation, at most five minutes in the future.'); }
        $observed = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $sections = $in['sections'] ?? null;
        if (!is_array($sections) || !$sections || array_is_list($sections)) { self::invalid('Supply at least one supported section.'); }
        self::keys($sections, array_merge(array_keys(self::TABLES), self::REST_EXTRA));
        return [$source, $deviceRef, $observed, $sections];
    }

    /** One section, validated and put in a stable order (so its fingerprint ignores ordering). */
    private static function section(string $name, $section): array
    {
        if (!is_array($section)) { self::invalid("$name must be an object."); }
        self::keys($section, ['complete', 'items']);
        if (!isset($section['complete']) || !is_bool($section['complete']) || !isset($section['items'])
            || !is_array($section['items']) || !array_is_list($section['items']) || count($section['items']) > 10000) {
            self::invalid("$name requires boolean complete and an items list (at most 10000).");
        }
        $items = []; $seen = [];
        foreach ($section['items'] as $item) {
            if (!is_array($item)) { self::invalid("$name items must be objects."); }
            $row = self::item($name, $item);
            $key = match ($name) {
                'software'    => mb_strtolower($row['display_name']) . "\0" . mb_strtolower($row['publisher'] ?? ''),
                'connections' => $row['connected_asset_id'] !== null ? 'id:' . $row['connected_asset_id'] : 'serial:' . mb_strtolower($row['serial']),
                default       => $row['external_id'],
            };
            if (isset($seen[$key])) { self::invalid("Duplicate item identity in $name."); }
            $seen[$key] = true; $items[] = $row;
        }
        usort($items, fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
        return ['complete' => $section['complete'], 'items' => $items];
    }

    /**
     * The agent's items, in the shapes applySection() and replaceAgentOnly() take.
     *
     * Lenient on purpose - trimmed to the column, never refused - because that is how the
     * agent's endpoints always behaved. Volumes and adapters are given an identity the
     * agent never sent (the drive letter; the MAC address, or the name), so they reconcile
     * row by row like a REST tool's; two with the same identity are told apart by a suffix
     * rather than one being lost.
     */
    private static function agentItems(string $name, array $raw): array
    {
        $str = static function ($value, int $max): ?string {
            if ($value === null || is_array($value)) { return null; }
            $v = trim((string)$value);
            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        $num = static fn($v) => is_numeric($v) ? (int)$v : null;
        $first = static function ($list, int $max, bool $ipv4 = false) use ($str): ?string {
            foreach (is_array($list) ? $list : [$list] as $v) {
                $v = $str($v, $max);
                if ($v !== null && (!$ipv4 || filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) { return $v; }
            }
            return null;
        };
        $items = []; $ids = [];
        $unique = static function (string $id) use (&$ids): string {
            $key = mb_strtolower($id);
            if (!isset($ids[$key])) { $ids[$key] = 1; return $id; }
            return mb_substr($id, 0, 120) . '#' . (++$ids[$key]);
        };
        foreach ($raw as $in) {
            if (!is_array($in)) { continue; }
            switch ($name) {
                case 'software':
                    $display = $str($in['display_name'] ?? null, 512);
                    if ($display === null) { break; }
                    $items[] = ['display_name' => $display, 'publisher' => $str($in['publisher'] ?? null, 512),
                        'display_version' => $str($in['display_version'] ?? null, 100), 'install_date' => $str($in['install_date'] ?? null, 50),
                        'uninstall_string' => $str($in['uninstall_string'] ?? null, 8192), 'install_location' => $str($in['install_location'] ?? null, 8192),
                        'estimated_size' => $str($in['estimated_size'] ?? null, 100), 'system_component' => empty($in['system_component']) ? 0 : 1];
                    break;
                case 'disks':
                    $drive = (string)($str($in['drive'] ?? null, 10) ?? '');
                    $items[] = ['external_id' => $unique($drive !== '' ? mb_strtoupper($drive) : 'volume'), 'drive' => $drive,
                        'label' => (string)($str($in['label'] ?? null, 100) ?? ''), 'file_system' => (string)($str($in['file_system'] ?? null, 20) ?? ''),
                        'size_bytes' => $num($in['size_bytes'] ?? null), 'free_bytes' => $num($in['free_bytes'] ?? null),
                        'used_percent' => is_numeric($in['used_percent'] ?? null) ? round((float)$in['used_percent'], 1) : null];
                    break;
                case 'network_adapters':
                    $mac = (string)($str($in['mac_address'] ?? null, 17) ?? '');
                    $adapter = (string)($str($in['name'] ?? null, 255) ?? '');
                    $items[] = ['external_id' => $unique($mac !== '' ? mb_strtoupper($mac) : ($adapter !== '' ? mb_substr($adapter, 0, 120) : 'adapter')),
                        'name' => $adapter, 'mac_address' => $mac, 'ip_address' => $first($in['ip_addresses'] ?? null, 45, true),
                        'subnet_mask' => $first($in['subnet_masks'] ?? null, 45), 'gateway' => $first($in['gateway'] ?? null, 45),
                        'dhcp_enabled' => array_key_exists('dhcp_enabled', $in) && $in['dhcp_enabled'] !== null ? (int)(bool)$in['dhcp_enabled'] : null];
                    break;
                case 'physical_disks':
                    $row = ['model' => $str($in['model'] ?? null, 255), 'serial' => $str($in['serial'] ?? null, 100), 'size_bytes' => $num($in['size_bytes'] ?? null),
                        'media_type' => $str($in['media_type'] ?? null, 100), 'interface_type' => $str($in['interface'] ?? null, 50)];
                    // A model with no serial is still worth having, but an entirely empty row is
                    // noise, and VMs produce them.
                    if ($row['model'] !== null || $row['serial'] !== null || $row['size_bytes'] !== null) { $items[] = $row; }
                    break;
                case 'devices':
                    $device = $str($in['device_name'] ?? null, 255);
                    if ($device === null) { break; }
                    $date = $str($in['driver_date'] ?? null, 10);
                    $items[] = ['device_class' => $str($in['device_class'] ?? null, 100), 'device_name' => $device, 'status' => $str($in['status'] ?? null, 20),
                        'manufacturer' => $str($in['manufacturer'] ?? null, 255), 'driver_version' => $str($in['driver_version'] ?? null, 50),
                        'driver_date' => $date !== null && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) ? $date : null];
                    break;
            }
        }
        return $items;
    }

    /**
     * The analyst who owns this source name in this company - claimed by the first write.
     * A rotated key acts as the same analyst, so it keeps the source.
     *
     * TRAP: ownership is per (company, source), not per asset. Per asset, a second
     * integration could clear the first one's rows on every asset it had not reached yet
     * by sending the same name.
     */
    private static function claimSource(PDO $conn, ActorContext $ctx, int $tenant, string $source, bool $claim = true): void
    {
        $find = $conn->prepare('SELECT owner_analyst_id FROM asset_inventory_source_owners WHERE tenant_id = ? AND source = ? FOR UPDATE');
        $find->execute([$tenant, $source]);
        $owner = $find->fetchColumn();
        if ($owner === false && $claim) {
            try {
                $conn->prepare('INSERT INTO asset_inventory_source_owners (tenant_id, source, owner_analyst_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())')
                    ->execute([$tenant, $source, $ctx->actorId]);
                return;
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) { throw $e; }
                $find->execute([$tenant, $source]);   // claimed a moment ago by another request
                $owner = $find->fetchColumn();
            }
        }
        if ($owner !== false && (int)$owner !== $ctx->actorId) {
            throw new ServiceError('forbidden', 'source_owner', "The source '$source' belongs to another integration in this company. Use your tool's own source name.");
        }
    }

    /** Link (source, device ref) to this asset, or confirm the existing link. */
    private static function bind(PDO $conn, int $assetId, int $tenant, string $source, string $deviceRef): int
    {
        $find = $conn->prepare('SELECT id, source_device_ref FROM asset_inventory_sources WHERE asset_id = ? AND source = ?');
        $find->execute([$assetId, $source]);
        if ($binding = $find->fetch(PDO::FETCH_ASSOC)) {
            if ($binding['source_device_ref'] !== $deviceRef) {
                throw new ServiceError('conflict', 'device_ref_conflict', "This asset is linked to the device '{$binding['source_device_ref']}' of this source. Unlink it first to link another.");
            }
            return (int)$binding['id'];
        }
        $elsewhere = $conn->prepare('SELECT asset_id FROM asset_inventory_sources WHERE tenant_id = ? AND source = ? AND source_device_ref = ?');
        $elsewhere->execute([$tenant, $source, $deviceRef]);
        if ($other = $elsewhere->fetchColumn()) {
            throw new ServiceError('conflict', 'device_ref_conflict', "This device is already linked to asset $other. Unlink it there first (DELETE /assets/$other/inventory/$source).");
        }
        try {
            $conn->prepare('INSERT INTO asset_inventory_sources (asset_id, tenant_id, source, source_device_ref) VALUES (?, ?, ?, ?)')
                ->execute([$assetId, $tenant, $source, $deviceRef]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) { throw new ServiceError('conflict', 'device_ref_conflict', 'This device was linked to another asset a moment ago.'); }
            throw $e;
        }
        return (int)$conn->lastInsertId();
    }

    /**
     * The agent's link to this asset, made on its first report. The agent has already
     * matched the machine to the asset (AssetsService::reconcileAsset), so its device
     * reference is simply the asset: 'asset:<id>', which can never collide with another.
     */
    private static function bindAgent(PDO $conn, int $assetId): int
    {
        $find = $conn->prepare("SELECT id FROM asset_inventory_sources WHERE asset_id = ? AND source = 'agent'");
        $find->execute([$assetId]);
        if ($id = $find->fetchColumn()) { return (int)$id; }
        $tenant = $conn->prepare('SELECT tenant_id FROM assets WHERE id = ?');
        $tenant->execute([$assetId]);
        $tenantId = $tenant->fetchColumn();
        $conn->prepare("INSERT INTO asset_inventory_sources (asset_id, tenant_id, source, source_device_ref) VALUES (?, ?, 'agent', ?)")
            ->execute([$assetId, $tenantId === null || $tenantId === false ? getDefaultTenantId($conn) : (int)$tenantId, 'asset:' . $assetId]);
        return (int)$conn->lastInsertId();
    }

    /**
     * Record what an attempt at a section came to.
     *
     * succeeded / empty  the good data moves on: observed_at, the fingerprint and who imported it
     * failed             only the attempt: the good data and its observation time stay as they were
     * skipped            only the attempt, and only for a section this source has reported before -
     *                    a section it has never sent stays "never collected"
     *
     * TRAP: a failure must never touch observed_at or the fingerprint. They are the REST replay
     * watermark, and moving them would make old data look new.
     */
    private static function recordOutcome(PDO $conn, int $bindingId, string $name, string $outcome, int $actorId,
                                          ?string $observed = null, ?string $fingerprint = null, ?string $error = null): void
    {
        if (!self::hasOutcomeColumns($conn)) {
            // Before Database Verification: only success can be written, in the old shape.
            if ($observed !== null) {
                $conn->prepare('INSERT INTO asset_inventory_sections (inventory_source_id, section_name, observed_at, fingerprint, imported_at, imported_by_id)
                    VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)
                    ON DUPLICATE KEY UPDATE observed_at = VALUES(observed_at), fingerprint = VALUES(fingerprint),
                        imported_at = VALUES(imported_at), imported_by_id = VALUES(imported_by_id)')
                    ->execute([$bindingId, $name, $observed, $fingerprint, $actorId]);
            }
            return;
        }
        $by = $actorId > 0 ? $actorId : null;
        if ($outcome === 'succeeded' || $outcome === 'empty') {
            $conn->prepare('INSERT INTO asset_inventory_sections (inventory_source_id, section_name, observed_at, fingerprint, imported_at, imported_by_id, last_outcome, last_error, last_attempt_at)
                VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, NULL, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE observed_at = VALUES(observed_at), fingerprint = VALUES(fingerprint), imported_at = VALUES(imported_at),
                    imported_by_id = VALUES(imported_by_id), last_outcome = VALUES(last_outcome), last_error = NULL, last_attempt_at = VALUES(last_attempt_at)')
                ->execute([$bindingId, $name, $observed, $fingerprint, $by, $outcome]);
        } elseif ($outcome === 'failed') {
            $conn->prepare('INSERT INTO asset_inventory_sections (inventory_source_id, section_name, last_outcome, last_error, last_attempt_at)
                VALUES (?, ?, \'failed\', ?, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE last_outcome = \'failed\', last_error = VALUES(last_error), last_attempt_at = VALUES(last_attempt_at)')
                ->execute([$bindingId, $name, mb_substr((string)$error, 0, 500)]);
        } else {
            $conn->prepare("UPDATE asset_inventory_sections SET last_outcome = 'skipped', last_error = NULL, last_attempt_at = UTC_TIMESTAMP()
                WHERE inventory_source_id = ? AND section_name = ?")->execute([$bindingId, $name]);
        }
    }

    /** A section that applied is 'empty' only when it was complete and had nothing in it. */
    private static function appliedOutcome(array $section): string
    {
        return $section['complete'] && !$section['items'] ? 'empty' : 'succeeded';
    }

    private static function fingerprint(array $section): string
    {
        return hash('sha256', json_encode($section, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * Reconcile this source's rows for one section, row by row: matching rows updated in
     * place (their ids survive), new ones added and - complete sections only - the ones no
     * longer reported removed.
     *
     * @param array   $discovered        collects applications this call added to the catalogue
     * @param ?string $catalogueSource   software_inventory_apps.source for a new application; null = the column's default ('agent')
     * @param bool    $strict            true (REST): two items resolving to one application refuse the section;
     *                                   false (agent): the first is kept, as the agent always did
     * @return array{added:int, updated:int, removed:int}
     */
    private static function applySection(PDO $conn, int $assetId, string $stored, string $name, array $section, string $observed,
                                         array &$discovered, ?string $catalogueSource, bool $strict): array
    {
        $table = self::TABLES[$name];
        $host = $name === 'software' ? 'host_id' : 'asset_id';
        $identity = $name === 'software' ? 'app_id' : 'external_id';
        $existing = $conn->prepare("SELECT id, $identity FROM $table WHERE $host = ? AND source = ?");
        $existing->execute([$assetId, $stored]);
        $have = []; $unnamed = [];
        foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // Rows the agent wrote before it gave volumes and adapters an identity have none;
            // a complete report replaces them.
            if ($r[$identity] === null) { $unnamed[] = (int)$r['id']; continue; }
            $have[(string)$r[$identity]] = (int)$r['id'];
        }

        $counts = ['added' => 0, 'updated' => 0, 'removed' => 0];
        $seen = [];
        $now = gmdate('Y-m-d H:i:s');
        foreach ($section['items'] as $item) {
            if ($name === 'software') {
                $appId = self::appId($conn, $item['display_name'], $item['publisher'], $discovered, $catalogueSource);
                if (isset($seen[(string)$appId])) {
                    if ($strict) { self::invalid('Two software items resolve to the same application in the catalogue.'); }
                    continue;
                }
                unset($item['display_name'], $item['publisher']);
                $item = ['app_id' => $appId] + $item + ['last_seen' => $observed];
            }
            $key = (string)$item[$identity];
            $seen[$key] = true;
            $item += ['observed_at' => $observed, 'imported_at' => $now];
            if (isset($have[$key])) {
                $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($item)));
                $conn->prepare("UPDATE $table SET $set WHERE id = ?")->execute([...array_values($item), $have[$key]]);
                $counts['updated']++;
            } else {
                $row = [$host => $assetId, 'source' => $stored] + $item;
                // In UTC, as the agent always wrote it - the column's own default is server time.
                if ($name === 'software') { $row['created_at'] = $now; }
                $conn->prepare("INSERT INTO $table (" . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')
                    ->execute(array_values($row));
                $counts['added']++;
            }
        }
        if ($section['complete']) {
            $gone = array_merge(array_values(array_diff_key($have, $seen)), $unnamed);
            if ($gone) {
                $conn->prepare("DELETE FROM $table WHERE id IN (" . implode(',', array_fill(0, count($gone), '?')) . ')')->execute($gone);
                $counts['removed'] = count($gone);
            }
        }
        return $counts;
    }

    /**
     * Physical drives and Device Manager: tables only the agent writes, with no source or
     * identity of their own, so a collected list replaces the asset's rows outright.
     *
     * @return array{added:int, updated:int, removed:int}
     */
    private static function replaceAgentOnly(PDO $conn, int $assetId, string $name, array $items): array
    {
        $table = $name === 'physical_disks' ? 'asset_physical_disks' : 'asset_devices';
        $del = $conn->prepare("DELETE FROM $table WHERE asset_id = ?");
        $del->execute([$assetId]);
        foreach ($items as $item) {
            $row = ['asset_id' => $assetId] + $item;
            $conn->prepare("INSERT INTO $table (" . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')
                ->execute(array_values($row));
        }
        return ['added' => count($items), 'updated' => 0, 'removed' => $del->rowCount()];
    }

    /**
     * The catalogue application for a name and publisher, so a curated manual row is adopted,
     * never rewritten (#1549).
     */
    private static function appId(PDO $conn, string $displayName, ?string $publisher, array &$discovered, ?string $catalogueSource): int
    {
        $find = $conn->prepare('SELECT id FROM software_inventory_apps WHERE display_name = ? AND (publisher IS NULL OR publisher = ?)
            ORDER BY (publisher <=> ?) DESC, id LIMIT 1');
        $find->execute([$displayName, $publisher, $publisher]);
        if ($app = $find->fetchColumn()) { return (int)$app; }
        try {
            if ($catalogueSource === null) {
                $conn->prepare('INSERT INTO software_inventory_apps (display_name, publisher) VALUES (?, ?)')->execute([$displayName, $publisher]);
            } else {
                $conn->prepare('INSERT INTO software_inventory_apps (display_name, publisher, source) VALUES (?, ?, ?)')->execute([$displayName, $publisher, $catalogueSource]);
            }
            $id = (int)$conn->lastInsertId();
            $discovered[] = ['id' => $id, 'name' => $displayName, 'publisher' => $publisher];
            return $id;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) { throw $e; }
            // Another ingest path added it since our read; a locking read sees it.
            $latest = $conn->prepare('SELECT id FROM software_inventory_apps WHERE display_name = ? AND publisher <=> ? FOR UPDATE');
            $latest->execute([$displayName, $publisher]);
            if ($app = $latest->fetchColumn()) { return (int)$app; }
            throw new ServiceError('conflict', 'application_conflict', 'Application identity conflicts with the catalogue.');
        }
    }

    /**
     * After the commit: a new application in the catalogue is a workflow trigger, and a
     * workflow must never see a rolled-back app.
     */
    private static function dispatchDiscovered(array $discovered): void
    {
        if (!$discovered) { return; }
        require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';
        foreach ($discovered as $app) {
            try { WorkflowEngine::dispatch('software.application_discovered', ['application' => $app]); }
            catch (Throwable $wfEx) { error_log('Workflow dispatch error in AssetInventoryService: ' . $wfEx->getMessage()); }
        }
    }

    private static function asset(PDO $conn, ActorContext $ctx, int $id, bool $lock): array
    {
        $stmt = $conn->prepare('SELECT id, tenant_id FROM assets WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $tenant = $row && $row['tenant_id'] !== null ? (int)$row['tenant_id'] : getDefaultTenantId($conn);
        if (!$row || ($ctx->companyScope !== null && !in_array($tenant, $ctx->companyScope, true))) {
            throw new ServiceError('not_found', 'not_found', 'Asset not found.');
        }
        return $row;
    }

    private static function iso(?string $utc): ?string
    {
        return $utc === null ? null : str_replace(' ', 'T', $utc) . 'Z';
    }

    private static function item(string $name, array $in): array
    {
        if ($name === 'connections') {
            // The other asset: its FreeITSM id if the tool has already resolved it, or its serial.
            self::keys($in, ['connected_asset_id', 'serial', 'name', 'model', 'connected']);
            $id = $in['connected_asset_id'] ?? null;
            if ($id !== null && (!is_int($id) || $id <= 0)) { self::invalid('connected_asset_id must be a positive integer.'); }
            $serial = self::text($in, 'serial', 100);
            $serial = $serial === null ? null : trim($serial);
            if (($id === null) === ($serial === null || $serial === '')) { self::invalid('Each connection needs exactly one of connected_asset_id or serial.'); }
            $connected = $in['connected'] ?? true;
            if (!is_bool($connected)) { self::invalid('connected must be boolean.'); }
            return ['connected_asset_id' => $id, 'serial' => $id === null ? $serial : null,
                    'name' => self::text($in, 'name', 255), 'model' => self::text($in, 'model', 255), 'connected' => $connected];
        }
        $strings = match ($name) {
            'software' => ['display_name' => 512, 'publisher' => 512, 'display_version' => 100, 'install_date' => 50, 'uninstall_string' => 8192, 'install_location' => 8192, 'estimated_size' => 100],
            'disks' => ['external_id' => 128, 'drive' => 10, 'label' => 100, 'file_system' => 20],
            'network_adapters' => ['external_id' => 128, 'name' => 255, 'mac_address' => 17, 'ip_address' => 45, 'subnet_mask' => 45, 'gateway' => 45],
        };
        $numbers = $name === 'disks' ? ['size_bytes', 'free_bytes', 'used_percent'] : [];
        $booleans = match ($name) { 'software' => ['system_component'], 'network_adapters' => ['dhcp_enabled'], default => [] };
        self::keys($in, array_merge(array_keys($strings), $numbers, $booleans));
        $out = [];
        foreach ($strings as $field => $max) { $out[$field] = self::text($in, $field, $max, $field === ($name === 'software' ? 'display_name' : 'external_id')); }
        if ($name === 'software') { $out['display_name'] = trim($out['display_name']); if ($out['publisher'] === '') { $out['publisher'] = null; } }
        foreach ($booleans as $field) {
            $value = $in[$field] ?? ($field === 'system_component' ? false : null);
            if ($field === 'system_component' && array_key_exists($field, $in) && $in[$field] === null) { self::invalid('system_component must be boolean.'); }
            if ($value !== null && !is_bool($value)) { self::invalid("$field must be boolean."); }
            $out[$field] = $value === null ? null : (int)$value;
        }
        foreach ($numbers as $field) {
            $value = $in[$field] ?? null;
            if ($value !== null && (($field === 'used_percent' ? !is_int($value) && !is_float($value) : !is_int($value))
                || $value < 0 || ($field === 'used_percent' && $value > 100))) { self::invalid("Invalid non-negative $field."); }
            $out[$field] = $value;
        }
        if ($name === 'disks' && $out['size_bytes'] !== null && $out['free_bytes'] !== null && $out['free_bytes'] > $out['size_bytes']) { self::invalid('free_bytes exceeds size_bytes.'); }
        if ($name === 'disks' && $out['used_percent'] === null && $out['size_bytes'] > 0 && $out['free_bytes'] !== null) {
            $out['used_percent'] = round(100 * ($out['size_bytes'] - $out['free_bytes']) / $out['size_bytes'], 1);
        }
        if ($name === 'network_adapters') {
            foreach (['ip_address', 'subnet_mask', 'gateway'] as $key) { if ($out[$key] !== null && !filter_var($out[$key], FILTER_VALIDATE_IP)) { self::invalid("Invalid $key."); } }
            if ($out['mac_address'] !== null && !filter_var($out['mac_address'], FILTER_VALIDATE_MAC)) { self::invalid('Invalid mac_address.'); }
        }
        return $out;
    }

    private static function text(array $in, string $field, int $max, bool $required = false): ?string
    {
        $value = $in[$field] ?? null;
        if ($value === null && !$required) { return null; }
        if (!is_string($value) || mb_strlen($value) > $max || str_contains($value, "\0") || ($required && trim($value) === '')) { self::invalid("Invalid $field (maximum $max characters)."); }
        return $value;
    }

    private static function keys(array $in, array $allowed): void
    {
        if ($unknown = array_diff(array_keys($in), $allowed)) { self::invalid('Unknown fields: ' . implode(', ', $unknown)); }
    }

    private static function invalid(string $message): never
    {
        throw new ServiceError('validation', 'invalid_field', $message);
    }
}
