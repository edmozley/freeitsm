<?php
/**
 * AssetImportService — bringing assets in from a spreadsheet.
 *
 * 🔑 CSV AND API ARE THE SAME FEATURE. A source produces rows of
 * [column => value]; everything after that is shared:
 *
 *     source ->  rows  ->  map  ->  reconcile  ->  validate  ->  apply  ->  log
 *     \_ differs _/       \_______________ all of this file _______________/
 *
 * Only readCsv() knows what a file is. When the API puller arrives it supplies
 * rows the same shape and nothing below changes.
 *
 * WHAT MAKES THIS HARD IS NOT THE MAPPING
 * ---------------------------------------
 * The mapping screen is the easy half. These are the parts that quietly ruin a
 * scheduled import, and each has a deliberate answer here:
 *
 *   - RECONCILIATION (§6.4): what makes an incoming row "the same printer"?
 *     Declared per profile as an ORDERED list. Three outcomes, never two:
 *     no match -> create, exactly one -> update, MORE THAN ONE -> conflict,
 *     log it, touch nothing. Guess at the third and run two silently
 *     duplicates 400 printers.
 *   - PREVIEW (§6.5): the same run, stopped before it writes.
 *   - THE HOLDING AREA: a row that cannot be imported is KEPT, with its source
 *     line, to be corrected and retried. Not dropped (data and reason both
 *     lost), not auto-created (which invents an asset type called "Televsion"
 *     the first time somebody typos a spreadsheet). Ed's call, and the right one.
 *   - ROWS THAT VANISH from the source next time (§6.6): an explicit setting.
 *
 * @see docs/design/flexible-asset-fields.md §6
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/asset_fields.php';

class AssetImportService
{
    /** Every outcome a source row can have. Both counters and the log use these. */
    const ACTIONS = ['create', 'update', 'unchanged', 'conflict', 'skip', 'error', 'deactivate'];

    /**
     * The built-in columns an import may WRITE.
     *
     * ⚠️ `asset_tag` is deliberately absent, even though it is an obvious CSV
     * column. It is unique PER COMPANY and that rule lives in
     * api/assets/save_asset_tag.php, not in AssetsService::fieldMap() — so
     * writing it here would either bypass the rule or duplicate it. It can
     * still be MATCHED on (reading is safe); writing it needs the rule
     * extracted to the service first. Anything not in fieldMap() is silently
     * ignored by createAsset/updateFields, which is exactly the kind of quiet
     * nothing this list exists to prevent.
     */
    const CORE_TARGETS = [
        'hostname', 'asset_type_id', 'asset_status_id', 'location_id',
        'manufacturer', 'model', 'service_tag',
        'supplier_id', 'purchase_date', 'purchase_cost', 'order_number', 'warranty_expiry',
        'cost_centre_id',   // GH #160 - by code, see resolveLookups()
        'lease_start', 'lease_expiry',
    ];

    /**
     * Columns that may be used to IDENTIFY a row.
     *
     * ⚠️ Not the same list as CORE_TARGETS, and deliberately short. A match key
     * has to be genuinely unique to one piece of equipment — matching on
     * `model` would merge every identical monitor in the building into one
     * record on the first run.
     *
     * `asset_tag` is here (read-only) but only exists once Database
     * Verification has created it, hence availableMatchKeys().
     */
    const MATCH_KEYS = ['hostname', 'asset_tag', 'service_tag'];

    /**
     * MATCH_KEYS minus anything this install has not got yet.
     *
     * `assets.asset_tag` arrives with the QR-label update; naming it on an
     * install that has pulled the code but not run Verification turns every
     * single row into "Unknown column" — the same trap the asset list had to
     * dodge.
     */
    public static function availableMatchKeys(PDO $conn): array
    {
        static $keys = null;
        if ($keys === null) {
            $keys = [];
            foreach (self::MATCH_KEYS as $k) {
                try {
                    $conn->query("SELECT `{$k}` FROM assets LIMIT 1");
                    $keys[] = $k;
                } catch (Exception) {
                    // column not present on this install
                }
            }
        }
        return $keys;
    }

    const MAX_ROWS = 5000;

    /** True when the asset import tables exist (after Database Verification). Cached per request. */
    public static function schemaReady(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $conn->query("SELECT 1 FROM asset_import_runs LIMIT 1");
                $ready = true;
            } catch (Exception) {
                $ready = false;
            }
        }
        return $ready;
    }

    // ====================================================================
    //  Source: CSV
    // ====================================================================

    /**
     * Read a CSV into [headers, rows]. Rows are [header => value].
     *
     * ⚠️ Strips a UTF-8 BOM. Excel writes one by default, and without this the
     * first column's header arrives as "\xEF\xBB\xBFHostname" — which maps to
     * nothing, so the identity column silently goes missing and every row looks
     * new. It is the single most common reason a first import "does nothing".
     *
     * @param string $path  the CSV file on disk
     * @param array $config  delimiter (default ','), has_header (default true; false names the columns 'Column 1'...)
     */
    public static function readCsv(string $path, array $config = []): array
    {
        $delimiter = $config['delimiter'] ?? ',';
        $hasHeader = !array_key_exists('has_header', $config) || $config['has_header'];

        if (!is_readable($path)) {
            throw new ServiceError('not_found', 'not_found', 'That file could not be read.');
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new ServiceError('not_found', 'not_found', 'That file could not be opened.');
        }

        $headers = [];
        $rows    = [];
        $n       = 0;
        $truncated = false;

        try {
            // ⚠️ The escape character is passed EXPLICITLY as '' — real CSV
            // (RFC 4180) has no escape character; a literal quote is written by
            // doubling it. PHP's historic default of backslash is non-standard,
            // mangles Windows paths like C:\Users\ in a cell, and PHP 8.4
            // deprecates relying on it. Passing '' is both the correct
            // behaviour and future-proof.
            while (($cells = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
                // fgetcsv gives [null] for a blank line.
                if ($cells === [null] || (count($cells) === 1 && trim((string)$cells[0]) === '')) {
                    continue;
                }
                if (!$headers) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cells[0]);
                    if ($hasHeader) {
                        $headers = array_map(static fn($h) => trim((string)$h), $cells);
                        // A duplicate header would silently overwrite its twin.
                        $headers = self::dedupeHeaders($headers);
                        continue;
                    }
                    $headers = array_map(static fn($i) => 'Column ' . ($i + 1), array_keys($cells));
                }
                if (count($rows) >= self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }
                $row = [];
                foreach ($headers as $i => $h) {
                    $row[$h] = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
                }
                $row['__row'] = ++$n + ($hasHeader ? 1 : 0);
                $rows[] = $row;
            }
        } finally {
            fclose($fh);
        }

        if (!$headers) {
            throw new ServiceError('validation', 'invalid_field', 'That file has no readable columns.');
        }

        // ⚠️ A cap must SURFACE, never silently shorten. A run that quietly
        // imported the first 5000 of 8000 rows reads as a complete success.
        return ['headers' => $headers, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * "Serial", "Serial" -> "Serial", "Serial (2)".
     *
     * @param array $headers  the trimmed header cells in column order
     */
    private static function dedupeHeaders(array $headers): array
    {
        $seen = [];
        foreach ($headers as $i => $h) {
            $h = ($h === '') ? 'Column ' . ($i + 1) : $h;
            if (isset($seen[$h])) {
                $seen[$h]++;
                $h .= ' (' . $seen[$h] . ')';
            } else {
                $seen[$h] = 1;
            }
            $headers[$i] = $h;
        }
        return $headers;
    }

    /**
     * Guess a mapping from the column names, so the screen opens mostly done.
     *
     * A suggestion only — every one is shown and overridable. Getting this
     * wrong silently is exactly what the preview exists to catch.
     *
     * @param array $headers  the file's column names, as returned by readCsv()
     */
    public static function suggestMapping(PDO $conn, array $headers, int $analystId): array
    {
        $norm = static fn(string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower($s));

        $core = [
            'hostname' => ['hostname', 'name', 'assetname', 'devicename', 'computername'],
            // These three take a NAME in the file and are resolved to an id by
            // resolveLookups(), so suggesting them is safe and is what anybody
            // exporting from another system will actually have.
            'asset_type_id' => ['type', 'assettype', 'category', 'kind'],
            'asset_status_id' => ['status', 'assetstatus', 'state', 'condition'],
            'location_id' => ['location', 'site', 'office', 'room', 'building'],
            'supplier_id' => ['supplier', 'suppliedby', 'purchasedfrom'],   // NOT 'vendor' — in an asset export that almost always means the manufacturer, which is matched below
            'asset_tag' => ['assettag', 'tag', 'assetnumber', 'assetid'],
            'service_tag' => ['servicetag', 'serial', 'serialnumber', 'sn'],
            'manufacturer' => ['manufacturer', 'make', 'brand', 'vendor'],
            'model' => ['model', 'modelnumber', 'modelno'],
            'purchase_date' => ['purchasedate', 'datepurchased', 'acquired', 'purchased', 'boughton'],
            'purchase_cost' => ['purchasecost', 'cost', 'price', 'value'],
            'order_number' => ['ordernumber', 'ponumber', 'po'],
            'warranty_expiry' => ['warrantyexpiry', 'warranty', 'warrantyend', 'warrantyends', 'warrantyexpires', 'warrantyuntil'],
            'cost_centre_id' => ['costcentre', 'costcenter', 'costcentrecode', 'costcentercode', 'costcode', 'kostenstelle'],
            'lease_start' => ['leasestart', 'leasestartdate', 'leasebegins', 'leasefrom', 'leasestarts'],
            'lease_expiry' => ['leaseend', 'leaseenddate', 'leaseends', 'leaseexpiry', 'leaseexpires', 'leaseto', 'leaseuntil', 'returndate'],
        ];

        $fields = AssetFieldsService::catalogue($conn, $analystId);
        $byNorm = [];
        foreach ($fields as $f) {
            $byNorm[$norm($f['label'])] = $f['field_key'];
            $byNorm[$norm($f['field_key'])] = $f['field_key'];
        }

        $out = [];
        foreach ($headers as $h) {
            $n = $norm($h);
            // A CUSTOM FIELD WINS over a core guess when the name matches it
            // exactly: somebody who created a field called "Model number" meant
            // that field, not the built-in `model`.
            if (isset($byNorm[$n])) {
                $out[$h] = ['target_kind' => 'field', 'target_key' => $byNorm[$n]];
                continue;
            }
            foreach ($core as $key => $aliases) {
                if (in_array($n, $aliases, true)) {
                    $out[$h] = ['target_kind' => 'core', 'target_key' => $key];
                    continue 2;
                }
            }
            // ⚠️ Unmapped is a real state and is returned as one. The screen
            // lists them explicitly ("3 columns ignored") — a column that
            // silently goes nowhere is how half an import disappears.
            $out[$h] = null;
        }
        return $out;
    }

    // ====================================================================
    //  The run
    // ====================================================================

    /**
     * Import rows. `$mode` of 'preview' does every check and writes nothing.
     *
     * @param array $rows  source rows, each [column name => value] plus '__row' (the line number in the file)
     * @param array $mapping  [column name => null | {target_kind: 'core'|'field', target_key}]
     * @param array $opts profile-shaped settings; see asset_import_profiles.
     * @param string $mode  'live' writes; anything else ('preview') checks every row and writes no asset
     * @return array the run row, with counts.
     */
    public static function run(PDO $conn, ActorContext $ctx, array $rows, array $mapping, array $opts, string $mode = 'preview'): array
    {
        if (!self::schemaReady($conn)) {
            throw new ServiceError('conflict', 'schema_not_ready',
                'Asset import needs Database Verification to run first.');
        }
        $live = ($mode === 'live');

        $available = self::availableMatchKeys($conn);
        $matchKeys = array_values(array_filter(
            (array)($opts['match_keys'] ?? ['hostname']),
            static fn($k) => in_array($k, $available, true)
        ));
        if (!$matchKeys) {
            throw new ServiceError('validation', 'missing_field',
                'Choose at least one column that identifies a row — otherwise every run creates duplicates.');
        }

        // 🔴 NORMALISE THE DEFAULT COMPANY TO NULL — the same rule AssetsService
        // and CmdbService follow when they STORE one.
        //
        // Without this the importer is silently broken in the most damaging way
        // possible: getActiveTenantId() returns the Default company's real id,
        // every asset it creates is stored with tenant_id NULL, and the next
        // run's match query looks for `tenant_id = 1` and finds nothing. So
        // every row looks new, every run tries to create, and the only thing
        // standing between the estate and a full duplicate set is the unique
        // hostname refusal — which reports as an error, not as the reconciliation
        // failure it actually is. Caught by a live run; no unit test of
        // findMatch() alone would have seen it.
        $tenantId = getActiveTenantId($conn, $ctx->actorId);
        if ($tenantId !== null && $tenantId === getDefaultTenantId($conn)) {
            $tenantId = null;
        }

        $runId = self::startRun($conn, $ctx, $opts, $mode, $tenantId);
        $counts = array_fill_keys(self::ACTIONS, 0);
        $seen   = 0;
        $touchedIds = [];

        foreach ($rows as $row) {
            $seen++;
            $rowNo = (int)($row['__row'] ?? $seen);

            // 🔑 ONE TRANSACTION PER ROW, so a row applies COMPLETELY or not at
            // all.
            //
            // Without it a row that creates the asset and then fails on a
            // custom field leaves the asset behind while the log records an
            // error — so the holding area says "this row failed", an asset
            // quietly exists, and retrying it silently becomes an update. The
            // log has to be true or the holding area is worthless. Found by a
            // test that ran the same bad row twice.
            //
            // ⚠️ The log entry is written OUTSIDE the transaction: a rollback
            // must undo the asset, never the record of what went wrong.
            $owns = $live && !$conn->inTransaction();
            if ($owns) {
                $conn->beginTransaction();
            }
            try {
                $res = self::processRow($conn, $ctx, $row, $mapping, $opts, $matchKeys, $tenantId, $live);
                if ($owns) {
                    $conn->commit();
                }
                $counts[$res['action']] = ($counts[$res['action']] ?? 0) + 1;
                if (!empty($res['asset_id'])) {
                    $touchedIds[] = (int)$res['asset_id'];
                }
                self::logEntry($conn, $runId, $rowNo, $res['action'], $res['asset_id'] ?? null,
                               $res['source_ref'] ?? null, $res['display_name'] ?? null,
                               $res['detail'] ?? null, $row);
            } catch (Throwable $e) {
                if ($owns && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                $counts['error']++;
                // 🔑 THE HOLDING AREA. The row is kept with its reason and its
                // source line verbatim, so it can be corrected and retried.
                $why = ($e instanceof ServiceError) ? $e->getMessage() : 'Unexpected: ' . $e->getMessage();
                self::logEntry($conn, $runId, $rowNo, 'error', null,
                               self::refOf($row, $mapping, $matchKeys), self::nameOf($row, $mapping),
                               $why, $row);
            }
        }

        // Rows that were here last time and are not now (§6.6). Only meaningful
        // for a saved profile running live — a one-off file has nothing to
        // compare against.
        if ($live && !empty($opts['profile_id']) && ($opts['on_missing'] ?? 'ignore') !== 'ignore') {
            $counts['deactivate'] += self::handleMissing(
                $conn, $runId, (int)$opts['profile_id'], $touchedIds, $opts['on_missing'], $tenantId
            );
        }

        return self::finishRun($conn, $runId, $seen, $counts);
    }

    /**
     * One row: map it, find it, write it.
     *
     * @param array $row  one source row, [column name => value] plus '__row'
     * @param array $mapping  [column name => null | {target_kind: 'core'|'field', target_key}]
     * @param array $opts  the run's profile-shaped settings (defaults, write_mode, on_unknown_option...)
     * @param array $matchKeys  the asset columns to identify a row by, in order, e.g. ['hostname', 'service_tag']
     * @param bool $live  true to write, false for a preview
     * @return array ['action', 'asset_id'?, 'source_ref'?, 'display_name'?, 'detail'?]
     */
    private static function processRow(
        PDO $conn, ActorContext $ctx, array $row, array $mapping, array $opts,
        array $matchKeys, ?int $tenantId, bool $live): array
    {
        [$core, $fields] = self::applyMapping($row, $mapping);

        // A spreadsheet says "Printer", not "20". Resolved here rather than in
        // AssetsService, whose id-only contract is the REST API's and should
        // stay that way — the same split as translating its error messages.
        $core = self::resolveLookups($conn, $core, $tenantId);

        // Defaults fill only what the row did not say.
        if (empty($core['asset_type_id']) && !empty($opts['default_asset_type_id'])) {
            $core['asset_type_id'] = (int)$opts['default_asset_type_id'];
        }
        if (empty($core['asset_status_id']) && !empty($opts['default_status_id'])) {
            $core['asset_status_id'] = (int)$opts['default_status_id'];
        }

        $ref  = self::firstNonEmpty($core, $matchKeys);
        $name = $core['hostname'] ?? $ref;

        if ($ref === null) {
            return ['action' => 'skip', 'source_ref' => null, 'display_name' => $name,
                    'detail' => 'No value in any of the columns that identify a row (' . implode(', ', $matchKeys) . ').'];
        }

        // ---- reconcile ------------------------------------------------
        $match = self::findMatch($conn, $core, $matchKeys, $tenantId);

        if ($match['count'] > 1) {
            // ⚠️ NEVER guess. Two existing assets answering to the same value is
            // a data question for a person, and picking one silently corrupts
            // whichever was wrong.
            return ['action' => 'conflict', 'source_ref' => $ref, 'display_name' => $name,
                    'detail' => "Matches {$match['count']} existing assets on {$match['key']} = \"{$match['value']}\". "
                              . 'Left alone — resolve the duplicates and run again.'];
        }

        if ($match['count'] === 1) {
            return self::applyUpdate($conn, $ctx, (int)$match['id'], $core, $fields, $opts, $ref, $name, $live);
        }
        return self::applyCreate($conn, $ctx, $core, $fields, $opts, $ref, $name, $tenantId, $live);
    }

    /**
     * Turn the NAMES a spreadsheet contains into the ids the columns hold.
     *
     * 🔑 Nobody's export has FreeITSM's internal ids in it. Without this, the
     * single most obvious mapping anybody would make — a "Type" column full of
     * the words Printer, Monitor, Headset — fails on every row with
     * "Unknown asset type id: Printer", which reads as a bug rather than as
     * something the file is missing.
     *
     * ⚠️ An unknown NAME is an error, never an auto-create. Otherwise the first
     * typo in a spreadsheet quietly invents an asset type called "Televsion"
     * and nobody notices until the type list is a mess. It goes to the holding
     * area instead, which is the whole point of having one.
     *
     * A numeric value is still accepted as an id, so an export from another
     * FreeITSM keeps working.
     *
     * @param array $core  [core column => value] from applyMapping(); names in lookup columns become ids
     */
    private static function resolveLookups(PDO $conn, array $core, ?int $tenantId): array
    {
        // A cost centre (GH #160) is matched by its CODE in the asset's company -
        // always, never as an id: codes like "0010" are digits too, and reading one
        // as an id would charge the asset to a different cost centre entirely.
        // An inactive one is refused by AssetsService, with its code in the message.
        if (isset($core['cost_centre_id']) && $core['cost_centre_id'] !== '') {
            require_once __DIR__ . '/../cost_centre_assign.php';
            $raw = trim((string)$core['cost_centre_id']);
            $cc = costCentreByCode($conn, costCentreRecordTenant($conn, $tenantId), $raw);
            if (!$cc) {
                throw new ServiceError('validation', 'invalid_field',
                    "No cost centre with the code \"{$raw}\" in this company. Add it in System -> Cost Centres first, or map that column to nothing.");
            }
            $core['cost_centre_id'] = $cc['id'];
        }

        $lookups = [
            'asset_type_id'   => ['asset_types',        'asset type'],
            'asset_status_id' => ['asset_status_types', 'asset status'],
            'location_id'     => ['asset_locations',    'location'],
            'supplier_id'     => ['suppliers',          'supplier'],
        ];

        foreach ($lookups as $col => [$table, $label]) {
            if (!isset($core[$col]) || $core[$col] === '') {
                continue;
            }
            $raw = trim((string)$core[$col]);
            if (ctype_digit($raw)) {
                continue;   // already an id; AssetsService will validate it
            }

            // Case-insensitive, and scoped to what this company can see —
            // config lists are "global defaults + this company's own".
            $sql = "SELECT id FROM {$table} WHERE LOWER(name) = LOWER(?)";
            $params = [$raw];
            if (self::hasTenantColumn($conn, $table)) {
                $sql .= " AND (tenant_id IS NULL" . ($tenantId !== null ? " OR tenant_id = ?" : "") . ")";
                if ($tenantId !== null) {
                    $params[] = $tenantId;
                }
            }
            // A company's own entry wins over a global default of the same name.
            $sql .= self::hasTenantColumn($conn, $table) ? " ORDER BY tenant_id IS NULL LIMIT 1" : " LIMIT 1";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $id = $stmt->fetchColumn();

            if ($id === false) {
                throw new ServiceError('validation', 'invalid_field',
                    "No {$label} called \"{$raw}\". Create it first, or map that column to nothing.");
            }
            $core[$col] = (int)$id;
        }
        return $core;
    }

    /**
     * Not every lookup table is company-scoped (suppliers may not be).
     *
     * @param string $table  a lookup table name from the fixed list in resolveLookups() - never user input
     */
    private static function hasTenantColumn(PDO $conn, string $table): bool
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            try {
                $conn->query("SELECT tenant_id FROM {$table} LIMIT 1");
                $cache[$table] = true;
            } catch (Exception) {
                $cache[$table] = false;
            }
        }
        return $cache[$table];
    }

    /**
     * Split a source row into core columns and custom field values.
     *
     * @param array $row  one source row, [column name => value]
     * @param array $mapping  [column name => null | {target_kind: 'core'|'field', target_key}]
     */
    private static function applyMapping(array $row, array $mapping): array
    {
        $core = [];
        $fields = [];
        foreach ($mapping as $sourceKey => $target) {
            if (!$target || !isset($row[$sourceKey])) {
                continue;   // unmapped, or a column this row does not have
            }
            $value = trim((string)$row[$sourceKey]);
            if ($value === '') {
                continue;   // an empty cell says nothing; it does not say "clear this"
            }
            if (($target['target_kind'] ?? '') === 'field') {
                $fields[$target['target_key']] = $value;
            } elseif (in_array($target['target_key'] ?? '', self::CORE_TARGETS, true)) {
                $core[$target['target_key']] = $value;
            }
        }
        return [$core, $fields];
    }

    /**
     * Find the existing asset for this row, trying each match key IN ORDER.
     *
     * 🔑 The first key that yields exactly one match wins. A key that yields
     * MANY stops the search and reports the conflict — falling through to the
     * next key would quietly resolve an ambiguity nobody had looked at.
     *
     * @param array $core  the row's [core column => value] after lookups
     * @param array $matchKeys  the asset columns to try, in order - each from MATCH_KEYS, so safe to put in SQL
     */
    private static function findMatch(PDO $conn, array $core, array $matchKeys, ?int $tenantId): array
    {
        foreach ($matchKeys as $key) {
            $value = $core[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            // Matching is ALWAYS within one company — two customers may each
            // hold a "LAPTOP-01", and merging them would be a tenancy breach
            // dressed up as a data merge.
            $sql = "SELECT id FROM assets WHERE `{$key}` = ? AND tenant_id <=> ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$value, $tenantId === null ? null : $tenantId]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) >= 1) {
                return ['count' => count($ids), 'id' => $ids[0], 'key' => $key, 'value' => $value];
            }
        }
        return ['count' => 0, 'id' => null, 'key' => null, 'value' => null];
    }

    /**
     * Creates an asset from one import row, with its custom fields, or describes it on a
     * preview.
     *
     * Throws when nothing is mapped to the asset name.
     *
     * @param array $core  the row's [core column => value] after lookups and defaults
     * @param array $fields  the row's [custom field_key => value]
     * @param array $opts  the run's settings (source_name, apply_field_set_id, on_unknown_option...)
     * @param string $ref  the value the row is identified by, for the log
     * @param ?string $name  the asset name for the log
     * @param bool $live  true to create, false to describe it for a preview
     */
    private static function applyCreate(
        PDO $conn, ActorContext $ctx, array $core, array $fields, array $opts,
        string $ref, ?string $name, ?int $tenantId, bool $live): array
    {
        if (empty($core['hostname'])) {
            // The identity column is the one thing that cannot be defaulted.
            throw new ServiceError('validation', 'missing_field',
                'Cannot create this row: nothing is mapped to the asset name.');
        }
        if (!$live) {
            return ['action' => 'create', 'source_ref' => $ref, 'display_name' => $name,
                    'detail' => self::describe($core, $fields) . ' (preview — nothing written)'];
        }

        $assetId = AssetsService::createAsset($conn, $ctx, $core,
            'Imported (' . ($opts['source_name'] ?? 'file') . ')', $tenantId);

        self::attachProfileSet($conn, $ctx, $assetId, $opts);
        $written = self::writeFields($conn, $ctx, $assetId, $fields, $opts);

        return ['action' => 'create', 'asset_id' => $assetId, 'source_ref' => $ref,
                'display_name' => $name, 'detail' => self::describe($core, $written)];
    }

    /**
     * Updates an existing asset from one import row, or describes it on a preview.
     *
     * Works out the changes first so a preview and a live run report the same thing; in
     * fill mode only empty values are written, and the name is never changed.
     *
     * @param array $core  the row's [core column => value] after lookups and defaults
     * @param array $fields  the row's [custom field_key => value]
     * @param array $opts  the run's settings; write_mode 'fill' (default) only fills blanks, anything else overwrites
     * @param string $ref  the value the row is identified by, for the log
     * @param ?string $name  the asset name for the log
     * @param bool $live  true to write, false to describe the changes for a preview
     */
    private static function applyUpdate(
        PDO $conn, ActorContext $ctx, int $assetId, array $core, array $fields,
        array $opts, string $ref, ?string $name, bool $live): array
    {
        $fill = (($opts['write_mode'] ?? 'fill') === 'fill');

        // What would actually change? Computed BEFORE writing so a preview and a
        // live run report the same thing, and so an unchanged row is reported as
        // unchanged rather than as a pointless update.
        $existing = self::loadCore($conn, $assetId);
        $changes  = [];
        foreach ($core as $k => $v) {
            if ($k === 'hostname') {
                continue;   // the identity is what we matched ON; never rewritten
            }
            $old = $existing[$k] ?? null;
            if ($fill && $old !== null && $old !== '') {
                continue;   // fill mode: leave anything already answered alone
            }
            if ((string)$old !== (string)$v) {
                $changes[$k] = $v;
            }
        }

        $defs      = AssetFieldsService::fieldsForAsset($conn, $assetId, $existing['asset_type_id'] ?? null);
        $current   = $defs ? AssetFieldsService::valuesForAsset($conn, $assetId, $defs) : [];
        $fieldWork = [];
        foreach ($fields as $key => $v) {
            if (!isset($defs[$key])) {
                continue;   // reported by writeFields; not a change to announce here
            }
            $old = $current[$key] ?? null;
            if ($fill && $old !== null && $old !== '') {
                continue;
            }
            if ((string)$old !== (string)$v) {
                $fieldWork[$key] = $v;
            }
        }

        if (!$changes && !$fieldWork) {
            return ['action' => 'unchanged', 'asset_id' => $assetId, 'source_ref' => $ref,
                    'display_name' => $name, 'detail' => 'Already matches the source.'];
        }

        if (!$live) {
            return ['action' => 'update', 'asset_id' => $assetId, 'source_ref' => $ref,
                    'display_name' => $name,
                    'detail' => self::describe($changes, $fieldWork) . ' (preview — nothing written)'];
        }

        if ($changes) {
            AssetsService::updateFields($conn, $ctx, $assetId, $changes);
        }
        self::attachProfileSet($conn, $ctx, $assetId, $opts);
        $written = self::writeFields($conn, $ctx, $assetId, $fieldWork, $opts);

        return ['action' => 'update', 'asset_id' => $assetId, 'source_ref' => $ref,
                'display_name' => $name, 'detail' => self::describe($changes, $written)];
    }

    /**
     * Write the custom fields, honouring on_unknown_option.
     *
     * A field the asset does not carry is REPORTED, not silently dropped — a
     * column mapped to a field that the asset's type does not have is a mapping
     * mistake somebody needs to know about.
     *
     * @param array $fields  [custom field_key => value] to write
     * @param array $opts  the run's settings; on_unknown_option decides unknown dropdown values
     */
    private static function writeFields(PDO $conn, ActorContext $ctx, int $assetId, array $fields, array $opts): array
    {
        if (!$fields) {
            return [];
        }
        $asset = self::loadCore($conn, $assetId);
        $defs  = AssetFieldsService::fieldsForAsset($conn, $assetId, $asset['asset_type_id'] ?? null);

        $writable = [];
        $ignored  = [];
        foreach ($fields as $key => $v) {
            if (!isset($defs[$key])) {
                $ignored[] = $key;
                continue;
            }
            if ($defs[$key]['type'] === 'dropdown') {
                $v = self::resolveOption($conn, $defs[$key], $v, $opts);
                if ($v === null) {
                    $ignored[] = $key;
                    continue;
                }
            }
            $writable[$key] = $v;
        }

        if ($writable) {
            AssetFieldsService::saveValues($conn, $ctx, $assetId, $writable);
        }
        if ($ignored) {
            // Surfaced through the caller's detail line.
            $writable['__ignored'] = implode(', ', $ignored);
        }
        return $writable;
    }

    /**
     * A dropdown value the option list does not have (§6.7).
     * reject -> the row fails; add -> the option is created.
     *
     * @param array $def  the dropdown field's definition, with id and label
     * @param string $value  the value from the source row
     * @param array $opts  the run's settings; on_unknown_option 'add' creates the option, anything else ('reject') throws
     */
    private static function resolveOption(PDO $conn, array $def, string $value, array $opts): ?string
    {
        $stmt = $conn->prepare("SELECT option_value FROM asset_field_options WHERE field_id = ?");
        $stmt->execute([(int)$def['id']]);
        $allowed = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$allowed || in_array($value, $allowed, true)) {
            return $value;
        }
        if (($opts['on_unknown_option'] ?? 'reject') === 'add') {
            $ins = $conn->prepare(
                "INSERT INTO asset_field_options (field_id, option_value, display_order) VALUES (?, ?, ?)"
            );
            $ins->execute([(int)$def['id'], $value, count($allowed)]);
            return $value;
        }
        throw new ServiceError('validation', 'invalid_field',
            "\"{$value}\" is not one of the choices for '{$def['label']}' (" . implode(', ', $allowed) . ').');
    }

    /**
     * The profile's field set, applied to everything it touches.
     *
     * @param array $opts  the run's settings; apply_field_set_id names the set to attach, if any
     */
    private static function attachProfileSet(PDO $conn, ActorContext $ctx, int $assetId, array $opts): void
    {
        if (empty($opts['apply_field_set_id'])) {
            return;
        }
        AssetFieldsService::attachSetToAsset($conn, $ctx, $assetId, (int)$opts['apply_field_set_id']);
    }

    /**
     * Rows present last run and absent now (§6.6).
     *
     * ⚠️ Only ever compares against assets THIS PROFILE has touched before.
     * Comparing against the whole estate would deactivate every asset the
     * spreadsheet was never about.
     *
     * @param int $runId  the asset_import_runs row being finished
     * @param int $profileId  the import profile whose earlier live runs are compared
     * @param array $touchedIds  the asset ids this run created, updated or found unchanged
     * @param string $policy  on_missing: 'deactivate' sets an inactive status, anything else only logs it
     */
    private static function handleMissing(
        PDO $conn, int $runId, int $profileId, array $touchedIds, string $policy, ?int $tenantId): int
    {
        $prev = $conn->prepare(
            "SELECT DISTINCT e.asset_id
               FROM asset_import_run_entries e
               JOIN asset_import_runs r ON r.id = e.run_id
              WHERE r.profile_id = ? AND r.mode = 'live' AND r.id <> ?
                AND e.asset_id IS NOT NULL
                AND e.action IN ('create', 'update', 'unchanged')"
        );
        $prev->execute([$profileId, $runId]);
        $before = array_map('intval', $prev->fetchAll(PDO::FETCH_COLUMN));

        $gone = array_diff($before, $touchedIds);
        if (!$gone) {
            return 0;
        }

        $n = 0;
        foreach ($gone as $assetId) {
            $name = self::loadCore($conn, $assetId)['hostname'] ?? null;
            $detail = 'Was in the source before and is not now.';
            if ($policy === 'deactivate') {
                $status = self::inactiveStatusId($conn, $tenantId);
                if ($status !== null) {
                    $conn->prepare("UPDATE assets SET asset_status_id = ? WHERE id = ?")->execute([$status, $assetId]);
                    // A status ticked Retired retires the asset, as any other move into it does.
                    if (assetStatusIsRetired($conn, $status)) {
                        $conn->prepare("UPDATE assets SET retired_datetime = COALESCE(retired_datetime, UTC_TIMESTAMP()) WHERE id = ?")->execute([$assetId]);
                    }
                    $detail .= ' Marked inactive.';
                } else {
                    // ⚠️ Say so. "Deactivated 12" when nothing was deactivated,
                    // because no such status exists, is the worst kind of lie.
                    $detail .= ' No inactive status is configured, so nothing was changed.';
                }
            } else {
                $detail .= ' Flagged only.';
            }
            self::logEntry($conn, $runId, null, 'deactivate', $assetId, null, $name, $detail, null);
            $n++;
        }
        return $n;
    }

    /**
     * The asset status to give an asset that has dropped out of the source - a status
     * ticked Retired first (it is the one somebody chose to mean "gone"), then, as
     * before, the first active status whose name contains retired, disposed or inactive.
     */
    private static function inactiveStatusId(PDO $conn, ?int $tenantId): ?int
    {
        $stmt = $conn->prepare(
            "SELECT id FROM asset_status_types
              WHERE is_active = 1 AND (tenant_id IS NULL OR tenant_id = ?)
                AND (" . (assetRetirementReady($conn) ? 'is_retired = 1 OR ' : '') . "LOWER(name) LIKE '%retired%' OR LOWER(name) LIKE '%disposed%' OR LOWER(name) LIKE '%inactive%')
              ORDER BY " . (assetRetirementReady($conn) ? 'is_retired DESC, ' : '') . "tenant_id IS NULL, display_order LIMIT 1"
        );
        $stmt->execute([$tenantId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    // ====================================================================
    //  The holding area
    // ====================================================================

    /**
     * Rows that could not be imported and have not been dealt with.
     *
     * @param ?int $runId  only this run's parked rows, or null for every live run
     * @param int $limit  the most rows to return
     */
    public static function unresolved(PDO $conn, ?int $runId = null, int $limit = 200): array
    {
        if (!self::schemaReady($conn)) {
            return [];
        }
        $sql = "SELECT e.*, r.source_name, r.started_datetime, r.profile_id
                  FROM asset_import_run_entries e
                  JOIN asset_import_runs r ON r.id = e.run_id
                 WHERE e.action IN ('error', 'conflict')
                   AND e.resolved_datetime IS NULL
                   AND r.mode = 'live'";
        $params = [];
        if ($runId !== null) {
            $sql .= " AND e.run_id = ?";
            $params[] = $runId;
        }
        $sql .= " ORDER BY e.id DESC LIMIT " . (int)$limit;
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['raw_row'] = $r['raw_row'] ? (json_decode($r['raw_row'], true) ?: []) : [];
        }
        return $rows;
    }

    /**
     * Mark a parked row dealt with — either retried successfully, or dismissed.
     *
     * @param int $entryId  the asset_import_run_entries row to mark dealt with
     */
    public static function resolveEntry(PDO $conn, int $entryId): void
    {
        $conn->prepare("UPDATE asset_import_run_entries SET resolved_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$entryId]);
    }

    // ====================================================================
    //  Run bookkeeping
    // ====================================================================

    /**
     * Records the start of an import run and returns its id.
     *
     * @param array $opts  the run's settings; profile_id, source_name and stored_file are recorded
     * @param string $mode  'live' or 'preview' (anything not 'live' is stored as preview)
     */
    private static function startRun(PDO $conn, ActorContext $ctx, array $opts, string $mode, ?int $tenantId): int
    {
        // ⚠️ started_datetime is written EXPLICITLY as UTC_TIMESTAMP(), not left
        // to the column's CURRENT_TIMESTAMP default — that default is the
        // server's LOCAL time, while finished_datetime is UTC, so a run
        // displayed as having finished an hour before it started. Directory
        // sync names the column for the same reason.
        $stmt = $conn->prepare(
            "INSERT INTO asset_import_runs
                (profile_id, mode, status, source_name, stored_file, triggered_by_analyst_id, tenant_id, started_datetime)
             VALUES (?, ?, 'running', ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([
            !empty($opts['profile_id']) ? (int)$opts['profile_id'] : null,
            $mode === 'live' ? 'live' : 'preview',
            $opts['source_name'] ?? null,
            $opts['stored_file'] ?? null,
            $ctx->actorId ?: null,
            $tenantId,
        ]);
        return (int)$conn->lastInsertId();
    }

    /**
     * Marks an import run finished with its counts, and returns the run.
     *
     * @param int $runId  the asset_import_runs row to finish
     * @param int $seen  how many source rows were read
     * @param array $counts  [action => count] for every ACTIONS key
     */
    private static function finishRun(PDO $conn, int $runId, int $seen, array $counts): array
    {
        $stmt = $conn->prepare(
            "UPDATE asset_import_runs
                SET status = 'ok', finished_datetime = UTC_TIMESTAMP(),
                    seen_count = ?, created_count = ?, updated_count = ?, unchanged_count = ?,
                    conflict_count = ?, skipped_count = ?, error_count = ?
              WHERE id = ?"
        );
        $stmt->execute([
            $seen, $counts['create'], $counts['update'], $counts['unchanged'],
            $counts['conflict'], $counts['skip'], $counts['error'], $runId,
        ]);
        return self::loadRun($conn, $runId);
    }

    /**
     * Loads one import run with its counts as integers; throws not found when it does not exist.
     *
     * @param int $runId  the asset_import_runs row to load
     */
    public static function loadRun(PDO $conn, int $runId): array
    {
        $stmt = $conn->prepare("SELECT * FROM asset_import_runs WHERE id = ?");
        $stmt->execute([$runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Import run not found.');
        }
        foreach (['seen_count','created_count','updated_count','unchanged_count',
                  'conflict_count','skipped_count','error_count'] as $c) {
            $row[$c] = (int)$row[$c];
        }
        return $row;
    }

    /**
     * Every row's outcome for a run.
     *
     * @param int $runId  the asset_import_runs row whose log to read
     * @param ?string $action  only entries with this ACTIONS value, or null for all
     * @param int $limit  the most entries to return
     */
    public static function runEntries(PDO $conn, int $runId, ?string $action = null, int $limit = 500): array
    {
        $sql = "SELECT * FROM asset_import_run_entries WHERE run_id = ?";
        $params = [$runId];
        if ($action) {
            $sql .= " AND action = ?";
            $params[] = $action;
        }
        $sql .= " ORDER BY id LIMIT " . (int)$limit;
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Writes one row of an import run's log.
     *
     * @param int $runId  the asset_import_runs row the entry belongs to
     * @param ?int $rowNo  the source line number; null for an entry with no source row (a missing asset)
     * @param string $action  one of ACTIONS
     * @param ?string $ref  the value the row is identified by (cut to 255 characters)
     * @param ?string $name  the asset name (cut to 255 characters)
     * @param ?string $detail  what happened, in words (cut to 1000 characters)
     * @param ?array $raw  the source row, kept so it can be corrected and retried; '__row' is dropped
     */
    private static function logEntry(
        PDO $conn, int $runId, ?int $rowNo, string $action, ?int $assetId,
        ?string $ref, ?string $name, ?string $detail, ?array $raw): void
    {
        // ⚠️ `row_number` MUST be backticked — it is a reserved word in MySQL 8
        // (the ROW_NUMBER() window function). Without them every single insert
        // here is a syntax error, which means the run log silently records
        // nothing at all.
        $stmt = $conn->prepare(
            "INSERT INTO asset_import_run_entries
                (`run_id`, `row_number`, `action`, `asset_id`, `source_ref`, `display_name`, `detail`, `raw_row`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($raw !== null) {
            unset($raw['__row']);
        }
        $stmt->execute([
            $runId, $rowNo, $action, $assetId,
            $ref !== null ? mb_substr($ref, 0, 255) : null,
            $name !== null ? mb_substr($name, 0, 255) : null,
            $detail !== null ? mb_substr($detail, 0, 1000) : null,
            $raw !== null ? json_encode($raw) : null,
        ]);
    }

    // ====================================================================
    //  Small helpers
    // ====================================================================

    /** An asset's id and core columns, for comparing with an import row. */
    private static function loadCore(PDO $conn, int $assetId): array
    {
        $cols = implode(', ', array_map(static fn($c) => "`{$c}`", self::CORE_TARGETS));
        $stmt = $conn->prepare("SELECT id, {$cols} FROM assets WHERE id = ?");
        $stmt->execute([$assetId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * The first of $keys that has a non-blank value in $core, or null.
     *
     * @param array $core  [core column => value]
     * @param array $keys  the columns to try, in order
     */
    private static function firstNonEmpty(array $core, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($core[$k]) && trim((string)$core[$k]) !== '') {
                return (string)$core[$k];
            }
        }
        return null;
    }

    /**
     * The value an import row is matched on - the first non-blank of the match keys.
     *
     * @param array $row  one source row, [column name => value]
     * @param array $mapping  [column name => null | {target_kind, target_key}]
     * @param array $matchKeys  the asset columns a row is identified by, in order
     */
    private static function refOf(array $row, array $mapping, array $matchKeys): ?string
    {
        [$core] = self::applyMapping($row, $mapping);
        return self::firstNonEmpty($core, $matchKeys);
    }

    /**
     * The asset name an import row maps to.
     *
     * @param array $row  one source row, [column name => value]
     * @param array $mapping  [column name => null | {target_kind, target_key}]
     */
    private static function nameOf(array $row, array $mapping): ?string
    {
        [$core] = self::applyMapping($row, $mapping);
        return $core['hostname'] ?? null;
    }

    /**
     * A human sentence describing what a row did, for the log.
     *
     * @param array $core  [core column => value] written or to be written
     * @param array $fields  [custom field_key => value], possibly with '__ignored' listing keys that went nowhere
     */
    private static function describe(array $core, array $fields): string
    {
        $ignored = $fields['__ignored'] ?? null;
        unset($fields['__ignored']);

        $bits = [];
        foreach ($core as $k => $v) {
            $bits[] = $k . ' = ' . self::short($v);
        }
        foreach ($fields as $k => $v) {
            $bits[] = $k . ' = ' . self::short($v);
        }
        $out = $bits ? implode(', ', $bits) : 'Nothing to change.';
        if ($ignored) {
            // A mapped column that went nowhere must be said out loud.
            $out .= ' — IGNORED (not on this asset type): ' . $ignored;
        }
        return $out;
    }

    /**
     * A value cut to 40 characters for the run log.
     *
     * @param mixed $v  the value to show
     */
    private static function short($v): string
    {
        $s = (string)$v;
        return mb_strlen($s) > 40 ? mb_substr($s, 0, 40) . '…' : $s;
    }
}
