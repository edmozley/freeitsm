<?php
/**
 * Hiding physical drives you do not want to look at (discussion #97).
 *
 * WHY THIS EXISTS. Windows reports mounted virtual disks through the same WMI
 * class as real hardware, so most machines carry a ~31 MB "Microsoft Virtual
 * Disk" beside their actual drive. One of those is a curiosity; six hundred of
 * them is a Drives panel nobody reads. Hiding is a display choice, so the rows
 * stay in the database and stay in the REST API — what changes is whether the
 * asset screen draws them.
 *
 * 🔴 A RULE, NEVER A FLAG ON THE ROW. api/external/system-info/submit/ clears
 * and rewrites asset_physical_disks on EVERY agent report, so a `hidden` column
 * on that table would be wiped by the next scheduled run — hours later, with no
 * error, looking exactly like the button never worked. The row ids are reissued
 * too (watched go 9/10 → 11/12 between two runs of one machine), so remembering
 * a disk by id fails the same way. The rule has to describe the DRIVE, not the
 * row.
 *
 * 🔑 MATCHED EXACTLY, on model AND size, both NULL-safe, with no "any size"
 * wildcard. Ed's reasoning about not normalising drive serials applies here
 * too: a clever rule is one that behaves unpredictably on data nobody has seen
 * yet. If a "Microsoft Virtual Disk" turns up at a different size it stays
 * visible until somebody hides that one as well — which is the answer you can
 * predict from the button you pressed.
 *
 * SCOPE. asset_id NULL is the estate-wide rule ("hide others like this");
 * asset_id set hides the drive on that machine only. tenant_id follows the
 * asset config convention: NULL is the Default company's, which also sees
 * NULL-tenant rows.
 */

require_once __DIR__ . '/tenancy.php';

/**
 * Has Database Verification created the table yet? Everything here answers
 * "nothing is hidden" when it has not, so an install that has pulled the update
 * and not yet verified shows its drives rather than an error.
 */
function assetDiskHideSchemaReady(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $q = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'asset_disk_hide_rules'");
        $q->execute([DB_NAME]);
        return $ready = ((int)$q->fetchColumn() > 0);
    } catch (Exception $e) {
        return $ready = false;
    }
}

/**
 * The rules that could hide a drive on this asset: its own, plus every
 * estate-wide one, scoped to the analyst's active company.
 *
 * @return array<int,array{id:int,asset_id:?int,model:?string,size_bytes:?string}>
 */
function assetDiskHideRulesFor(PDO $conn, int $analystId, int $assetId): array {
    if (!assetDiskHideSchemaReady($conn)) return [];
    [$tenantSql, $tenantArgs] = activeTenantFilter($conn, $analystId, 'r');
    $sql = "SELECT id, asset_id, model, size_bytes
              FROM asset_disk_hide_rules r
             WHERE (r.asset_id IS NULL OR r.asset_id = ?)" . $tenantSql;
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge([$assetId], $tenantArgs));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Does any of $rules hide this drive?
 *
 * ⚠️ Compared as STRINGS after a loose null check, because size_bytes arrives
 * from PDO as a string on one side and may be an int on the other. `==` would
 * make NULL and 0 equal, and a 0-byte drive is a real thing here — that is
 * precisely the Microsoft Virtual Disk this feature was built for.
 */
function assetDiskIsHidden(array $rules, ?string $model, $sizeBytes): bool {
    $sizeKey = $sizeBytes === null ? null : (string)$sizeBytes;
    foreach ($rules as $r) {
        $ruleSize = $r['size_bytes'] === null ? null : (string)$r['size_bytes'];
        if ($r['model'] === $model && $ruleSize === $sizeKey) {
            return true;
        }
    }
    return false;
}

/**
 * How many drives across the estate look like this one, and on how many assets.
 *
 * 🔴 SCOPED TO WHAT THE ANALYST CAN SEE. This count is shown to a person before
 * they press a button, so an unscoped COUNT would tell them how many machines
 * another company has — the exact leak this codebase has shipped once before,
 * by scoping the list and forgetting the count.
 *
 * @return array{drives:int,assets:int}
 */
function assetDiskMatchCount(PDO $conn, int $analystId, ?string $model, $sizeBytes): array {
    [$tenantSql, $tenantArgs] = activeTenantFilter($conn, $analystId, 'a');
    $sql = "SELECT COUNT(*) AS drives, COUNT(DISTINCT pd.asset_id) AS assets
              FROM asset_physical_disks pd
              JOIN assets a ON a.id = pd.asset_id
             WHERE pd.model <=> ? AND pd.size_bytes <=> ?" . $tenantSql;
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge([$model, $sizeBytes], $tenantArgs));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['drives' => (int)($row['drives'] ?? 0), 'assets' => (int)($row['assets'] ?? 0)];
    } catch (Exception $e) {
        return ['drives' => 0, 'assets' => 0];
    }
}
