<?php
/**
 * "Warranty ending" and "lease ending" - the ONE definition of each.
 *
 * Watchtower's Assets card counts them (includes/watchtower_queries.php) and,
 * since discussion #388, the asset table can be opened showing exactly those
 * assets (?due=warranty / ?due=lease -> api/assets/get_assets.php). Both read
 * the window and the rule from here, so the list can never drift from the
 * number that was clicked: same settings, same day count, same "today", same
 * exclusion of machines already handed back on a lease.
 *
 * Company scope and "not retired" are NOT in these fragments - each caller
 * already applies its own (and must keep doing so).
 */

require_once __DIR__ . '/timezone.php';   // naive_today_sql()

/**
 * Where the alert surfaces and how many days ahead it looks, from Assets ->
 * Settings -> Expiry alerts. Defaults: dashboard, 30 days (warranty) / 60 days
 * (lease - a lease ending is a date you must act on beforehand).
 *
 * @param string $kind  'warranty' or 'lease'
 * @return array{show: bool, days: int}  show = the Watchtower card counts it
 */
function assetExpiryWindow(PDO $conn, string $kind): array
{
    $surfaceKey = $kind === 'lease' ? 'asset_lease_surface' : 'asset_warranty_surface';
    $daysKey    = $kind === 'lease' ? 'asset_lease_days' : 'asset_warranty_days';
    $surface = 'dashboard';
    $days = $kind === 'lease' ? 60 : 30;
    try {
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (?, ?)");
        $stmt->execute([$surfaceKey, $daysKey]);
        $set = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($set[$surfaceKey])) $surface = $set[$surfaceKey];
        if (!empty($set[$daysKey]) && (int)$set[$daysKey] > 0) $days = (int)$set[$daysKey];
    } catch (Exception) { /* defaults */ }
    return ['show' => in_array($surface, ['dashboard', 'both'], true), 'days' => $days];
}

/**
 * The " AND ..." condition for "expired, or expiring within the window", plus its
 * parameters. Expired ones stay in on purpose: an expired warranty is still a
 * thing to deal with, and the card has always counted it.
 *
 * ⚠️ The column may not exist until Database Verification has run (lease_expiry
 * especially) - callers run the query inside a try.
 *
 * @param string $kind   'warranty' or 'lease'
 * @param string $alias  the assets table's alias in the calling query; '' for none
 *                       (the lease rule needs one for its handed-back check, so '' becomes 'assets')
 * @param int $days      the window, from assetExpiryWindow()
 * @return array{0: string, 1: array}
 */
function assetExpiryDueSql(PDO $conn, string $kind, string $alias, int $days): array
{
    $today = naive_today_sql();   // a bare date - the same "today" all day long (GH #126)
    $p = $alias !== '' ? "$alias." : '';
    if ($kind === 'lease') {
        // Machines already handed back keep their lease_expiry as a record - not due back.
        require_once __DIR__ . '/leases.php';
        $goneBack = leaseReady($conn) ? ' AND NOT ' . leaseGoneBackSql($alias !== '' ? $alias : 'assets') : '';
        return [" AND {$p}lease_expiry IS NOT NULL AND {$p}lease_expiry <= DATE_ADD({$today}, INTERVAL ? DAY){$goneBack}", [$days]];
    }
    return [" AND {$p}warranty_expiry IS NOT NULL AND {$p}warranty_expiry <= DATE_ADD({$today}, INTERVAL ? DAY)", [$days]];
}
