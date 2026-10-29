<?php
/**
 * Retired assets - assets that have left the estate (decommissioned, disposed,
 * transferred to another organisation) but whose record must be kept.
 *
 * Asked for by a supporter of the project in public-sector IT, where kit that is
 * no longer used is transferred to another institution with a list of what went
 * and when: once it is gone it must stop showing as an active asset, and the date
 * it left must be on record.
 *
 * THE MODEL
 * ---------
 * - A status can be ticked RETIRED (asset_status_types.is_retired), under Assets ->
 *   Settings -> Asset statuses. Off for every status by default, so nothing
 *   changes on upgrade until somebody decides what "retired" is called here.
 * - An asset moved INTO a retired status is stamped with assets.retired_datetime
 *   (and keeps an optional retired_reason - "Transferred to the Ministry of
 *   Education"); moved OUT of one, both are cleared. AssetsService does that on
 *   every path that changes a status (the panel, the scanner, bulk stocktake,
 *   imports, the REST API).
 *
 * 🔑 retired_datetime IS THE ONE ANSWER to "is this asset retired?". Every list,
 * count and alert asks it - assetNotRetiredSql() - rather than joining the status
 * table, so a status that is renamed, hidden or belongs to another company cannot
 * change the answer, and ticking or unticking a status re-stamps the assets in it
 * (assetRetirementRestamp) so the two never disagree.
 *
 * WHAT RETIRED MEANS
 * ------------------
 * Out of the default asset list and Table (a "Show retired" switch brings them
 * back), the dashboards, Watchtower's counts, warranty and lease alerts and the
 * calendar, and the Report Pack asset figures. Still found by an exact scan or tag
 * lookup, a deep link, and its own page - it is a record, not a deletion. A device
 * agent or Intune still reporting a retired machine does not revive or reshape it
 * (see assetIsRetired() in the ingest paths), and is not given a duplicate.
 */

/** Has Database Verification added the retirement columns yet? */
function assetRetirementReady(PDO $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $conn->query("SELECT retired_datetime, retired_reason FROM assets LIMIT 0");
        $conn->query("SELECT is_retired FROM asset_status_types LIMIT 0");
        return $ok = true;
    } catch (Throwable) { return $ok = false; }
}

/**
 * The SQL that keeps retired assets out of a list or count - " AND a.retired_datetime
 * IS NULL", or nothing before Database Verification.
 *
 * @param string $alias  the assets table's alias in the query ('' for no alias)
 */
function assetNotRetiredSql(PDO $conn, string $alias = 'a'): string
{
    if (!assetRetirementReady($conn)) return '';
    return ' AND ' . ($alias !== '' ? "$alias." : '') . 'retired_datetime IS NULL';
}

/** Is this status ticked as retired? Null (no status) is not. */
function assetStatusIsRetired(PDO $conn, ?int $statusId): bool
{
    static $cache = [];
    if (!$statusId || !assetRetirementReady($conn)) return false;
    if (!array_key_exists($statusId, $cache)) {
        $st = $conn->prepare("SELECT is_retired FROM asset_status_types WHERE id = ?");
        $st->execute([$statusId]);
        $cache[$statusId] = (int)$st->fetchColumn() === 1;
    }
    return $cache[$statusId];
}

/** Is this asset retired? For the ingest paths, which have only its id. */
function assetIsRetired(PDO $conn, int $assetId): bool
{
    if (!assetRetirementReady($conn)) return false;
    $st = $conn->prepare("SELECT retired_datetime IS NOT NULL FROM assets WHERE id = ?");
    $st->execute([$assetId]);
    return (int)$st->fetchColumn() === 1;
}

/**
 * The column changes a status change brings with it: stamp the date when an asset
 * moves into a retired status, clear date and reason when it moves out.
 *
 * @return array{sql:list<string>, retired:?bool}  SQL fragments to add to the UPDATE
 *   (no placeholders), and true/false when the asset's retirement changed, null when not
 */
function assetRetirementOnStatusChange(PDO $conn, ?int $from, ?int $to): array
{
    if (!assetRetirementReady($conn)) return ['sql' => [], 'retired' => null];
    $was = assetStatusIsRetired($conn, $from);
    $is  = assetStatusIsRetired($conn, $to);
    if ($is && !$was) return ['sql' => ['retired_datetime = UTC_TIMESTAMP()'], 'retired' => true];
    if ($was && !$is) return ['sql' => ['retired_datetime = NULL', 'retired_reason = NULL'], 'retired' => false];
    return ['sql' => [], 'retired' => null];
}

/**
 * A status has just been ticked or unticked as retired: bring every asset in it
 * into line, so "is it retired?" has one answer (retired_datetime).
 *
 * Ticked: the assets in it that are not already retired are stamped now - the
 * date they were moved into the status was before anybody said it meant retired,
 * so "now" is the honest date for "counted as retired from". Unticked: those that
 * have no OTHER reason to be retired are cleared (they are in this status, so
 * this status was the reason).
 *
 * @return int  assets changed
 */
function assetRetirementRestamp(PDO $conn, int $statusId, bool $retired): int
{
    if (!assetRetirementReady($conn)) return 0;
    $st = $retired
        ? $conn->prepare("UPDATE assets SET retired_datetime = UTC_TIMESTAMP() WHERE asset_status_id = ? AND retired_datetime IS NULL")
        : $conn->prepare("UPDATE assets SET retired_datetime = NULL, retired_reason = NULL WHERE asset_status_id = ? AND retired_datetime IS NOT NULL");
    $st->execute([$statusId]);
    return $st->rowCount();
}
