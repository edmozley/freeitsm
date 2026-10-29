<?php
/**
 * Asset expiry dates → Calendar sync.
 *
 * Keeps two sets of auto-generated, all-day calendar events in step with the
 * dates on an asset:
 *
 *   warranty_expiry → "Warranty expiry: HOST", source 'asset_warranty'
 *   lease_expiry    → "Lease ends: HOST",      source 'asset_lease'
 *
 * Each is gated by its own setting (asset_warranty_surface / asset_lease_surface)
 * and cleared and rebuilt on its own `source`, so a full resync never disturbs
 * user-created events, and switching one kind off never removes the other.
 *
 * The file is still named after warranty because warranty is what it did first
 * and the name is referenced from several call sites; what it covers is the
 * list above, not the filename.
 *
 * Called opportunistically when an asset's warranty or lease date changes
 * (update_asset_field.php) and on demand when either setting is saved
 * (sync_warranty_calendar.php). Cheap full resync — these edits are rare and the
 * event set is small.
 */

if (!function_exists('syncAssetWarrantyCalendar')) {
    /**
     * Rebuilds the auto-generated warranty-expiry and lease-end calendar entries
     * from the asset dates, then the lease "give notice" entries.
     *
     * Each kind follows its own setting (asset_warranty_surface, asset_lease_surface)
     * and is skipped quietly while its column has not been added by Database
     * Verification yet.
     *
     * @param PDO $conn
     * @return array{success:bool, synced?:int, error?:string}
     */
    function syncAssetWarrantyCalendar(PDO $conn): array
    {
        // GH #217: nothing at all while the Calendar is switched off - not even the clear-out,
        // so switching it back on finds the entries as they were until the next sync.
        if (!moduleIsEnabled($conn, 'calendar')) return ['success' => true, 'synced' => 0];
        try {
            // The feature depends on schema added alongside it; bail quietly if
            // a DB verification hasn't run yet.
            if (!awcColumnExists($conn, 'calendar_events', 'source')
                || !awcColumnExists($conn, 'assets', 'warranty_expiry')) {
                return ['success' => false, 'error' => 'Schema not ready'];
            }

            // 🔴 lease_expiry is checked SEPARATELY and never added to the
            // guard above. It arrived later than the rest of this file, so on an
            // install that has pulled the update and not yet run Database
            // Verification it is missing - and folding it into that condition
            // would stop warranty entries syncing too, breaking a feature that
            // was working fine to make room for one that is not there yet.
            $leaseReady = awcColumnExists($conn, 'assets', 'lease_expiry');

            // Two independent kinds of entry, each with its own setting, its own
            // category and its own `source` value. They are cleared and rebuilt
            // separately, so switching leases off never disturbs the warranty
            // entries sitting beside them in the same calendar.
            $n = 0;

            $n += awcSyncKind(
                $conn,
                'asset_warranty',
                awcGetSetting($conn, 'asset_warranty_surface', 'dashboard'),
                'warranty_expiry',
                'Warranty',
                '#d13438',
                'Warranty expiry: ',
                'Auto-generated from the asset record. Edit the warranty date on the asset to change this.',
                true
            );

            $n += awcSyncKind(
                $conn,
                'asset_lease',
                awcGetSetting($conn, 'asset_lease_surface', 'dashboard'),
                'lease_expiry',
                'Lease',
                '#8b5cf6',
                'Lease ends: ',
                'Auto-generated from the asset record. Edit the lease end date on the asset to change this.',
                $leaseReady
            );

            // The "give notice" entries for leases (3.5.0) follow the same switch.
            require_once __DIR__ . '/leases.php';
            leaseSyncCalendar($conn);

            return ['success' => true, 'synced' => $n];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Clear and rebuild one kind of auto-generated asset date entry.
     *
     * $ready is the caller's answer to "does the column exist yet" - passed in
     * rather than checked here so the two kinds cannot accidentally gate each
     * other, which is how a missing lease column would otherwise have taken the
     * warranty entries down with it.
     *
     * Clearing happens even when the kind is switched OFF, and even when the
     * column is missing: that is what makes turning it off actually remove the
     * entries instead of leaving the last set behind for ever.
     *
     * @param string $source  the calendar_events.source tag for this kind - 'asset_warranty' or 'asset_lease'
     * @param string $surface  where this kind is shown - off, dashboard, calendar or both; only calendar/both write entries
     * @param string $column  the assets date column to read - warranty_expiry or lease_expiry (interpolated, never user input)
     * @param string $categoryName  the calendar category's default name, used when one has to be found or made
     * @param string $categoryColour  the colour (#rrggbb) for a newly created category only
     * @param string $titlePrefix  put before the hostname in each entry's title, e.g. 'Warranty expiry: '
     * @param string $description  the description stored on every entry of this kind
     * @param bool $ready  whether $column exists yet; false clears the entries and writes none
     */
    function awcSyncKind(
        PDO $conn,
        string $source,
        string $surface,
        string $column,
        string $categoryName,
        string $categoryColour,
        string $titlePrefix,
        string $description,
        bool $ready
    ): int {
        // 🔴 BEFORE the delete, not after. The rows about to be removed are the
        // only record of which category this kind of entry is currently using,
        // so asking afterwards finds nothing and quietly makes a second one.
        awcAdoptExistingCategory($conn, $source);

        $del = $conn->prepare("DELETE FROM calendar_events WHERE source = ?");
        $del->execute([$source]);

        if (!$ready || !in_array($surface, ['calendar', 'both'], true)) {
            return 0;
        }

        $categoryId = awcEnsureCategory($conn, $source, $categoryName, $categoryColour);

        // The column name is interpolated, never bound - a placeholder cannot
        // stand for an identifier. It comes from this file's own two call sites
        // and never from a request, so there is nothing here a caller can steer.
        // A machine already handed back keeps its lease_expiry as a record - no "Lease ends" entry for it.
        $goneBack = '';
        if ($column === 'lease_expiry') {
            require_once __DIR__ . '/leases.php';
            if (leaseReady($conn)) $goneBack = ' AND NOT ' . leaseGoneBackSql('a');
        }
        // A retired asset has left the estate: no warranty or lease entry for it.
        require_once __DIR__ . '/asset_retirement.php';
        $goneBack .= assetNotRetiredSql($conn, 'a');
        $rows = $conn->query(
            "SELECT a.id, a.hostname, a.`{$column}` AS expiry_date
             FROM assets a
             WHERE a.`{$column}` IS NOT NULL{$goneBack}"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return 0;
        }

        $ins = $conn->prepare(
            "INSERT INTO calendar_events
                (title, description, category_id, start_datetime, end_datetime, all_day, created_by, source)
             VALUES (?, ?, ?, ?, ?, 1, 0, ?)"
        );

        $n = 0;
        foreach ($rows as $r) {
            $host = $r['hostname'] !== null && $r['hostname'] !== '' ? $r['hostname'] : ('Asset #' . $r['id']);
            $dt = substr($r['expiry_date'], 0, 10) . ' 00:00:00';
            $ins->execute([$titlePrefix . $host, $description, $categoryId, $dt, $dt, $source]);
            $n++;
        }
        return $n;
    }

    /**
     * Which calendar category should this kind of entry use?
     *
     * 🔴 REMEMBERED BY ID, NEVER BY NAME. Looking it up by name meant that
     * renaming the category - the first thing anybody does, and the ONLY way to
     * get the word in their own language, since these rows are stored data
     * rather than interface text - caused the next sync to miss it, create a
     * second category under the original name, and split the entries across the
     * two with nothing on screen to explain it.
     *
     * Four steps, most specific first:
     *   1. the id recorded last time, if that category still exists;
     *   2. otherwise whatever category the EXISTING entries of this kind are
     *      already in - which adopts a category that was renamed before this
     *      code existed, with no migration to run and nothing for anybody to do;
     *   3. otherwise one matching the default name, for a fresh install whose
     *      categories were seeded;
     *   4. otherwise create it.
     *
     * Colour is only ever set at creation. Changing it afterwards is somebody
     * expressing a preference, and a sync that reset it every night would be
     * quietly overruling them.
     *
     * @param string $source  the entry kind ('asset_warranty' or 'asset_lease'); the category id is remembered under <source>_category_id
     * @param string $name  the default category name to look for, and to create with
     * @param string $colour  the colour (#rrggbb) used only when the category is created
     */
    function awcEnsureCategory(PDO $conn, string $source, string $name, string $colour): ?int
    {
        $settingKey = $source . '_category_id';   // asset_lease_category_id, asset_warranty_category_id

        // 1. the one we recorded, if it is still there
        $recorded = (int)awcGetSetting($conn, $settingKey, '0');
        if ($recorded > 0) {
            $chk = $conn->prepare("SELECT id FROM calendar_categories WHERE id = ?");
            $chk->execute([$recorded]);
            if ($chk->fetchColumn()) { return $recorded; }
        }

        // 2. is done earlier, by awcAdoptExistingCategory, because it has to run
        //    before this kind's entries are cleared. If it found anything, step 1
        //    above has already returned it.

        // 3. a category already carrying the default name
        $sel = $conn->prepare("SELECT id FROM calendar_categories WHERE name = ? LIMIT 1");
        $sel->execute([$name]);
        $id = (int)$sel->fetchColumn();
        if ($id > 0) {
            awcSetSetting($conn, $settingKey, (string)$id);
            return $id;
        }

        // 4. make one
        try {
            $ins = $conn->prepare("INSERT INTO calendar_categories (name, color, is_active) VALUES (?, ?, 1)");
            $ins->execute([$name, $colour]);
            $new = (int)$conn->lastInsertId();
            awcSetSetting($conn, $settingKey, (string)$new);
            return $new;
        } catch (Exception) {
            return null; // category column shape differs / table missing — events just go uncategorised
        }
    }

    /**
     * Adopt whatever category this kind of entry is already filed under.
     *
     * This is what makes renaming safe for an install that renamed the category
     * BEFORE ids were recorded - there is no migration to run and nothing for
     * anybody to do; the entries themselves say where they live.
     *
     * Does nothing once an id is recorded, and nothing if there are no entries.
     *
     * @param string $source  the entry kind ('asset_warranty' or 'asset_lease') whose existing entries are checked
     */
    function awcAdoptExistingCategory(PDO $conn, string $source): void
    {
        $settingKey = $source . '_category_id';
        $recorded = (int)awcGetSetting($conn, $settingKey, '0');
        if ($recorded > 0) {
            $chk = $conn->prepare("SELECT id FROM calendar_categories WHERE id = ?");
            $chk->execute([$recorded]);
            if ($chk->fetchColumn()) { return; }   // already known and still there
        }
        try {
            $used = $conn->prepare(
                "SELECT category_id FROM calendar_events
                  WHERE source = ? AND category_id IS NOT NULL
               GROUP BY category_id ORDER BY COUNT(*) DESC LIMIT 1"
            );
            $used->execute([$source]);
            $adopted = (int)$used->fetchColumn();
            if ($adopted > 0) { awcSetSetting($conn, $settingKey, (string)$adopted); }
        } catch (Exception) { /* the name lookup will cope */ }
    }

    /**
     * Remember a value; best-effort, because losing it only costs one lookup.
     *
     * @param string $key  the system_settings key to write
     * @param string $value  the value to store
     */
    function awcSetSetting(PDO $conn, string $key, string $value): void
    {
        try {
            $conn->prepare(
                "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([$key, $value]);
        } catch (Exception) { /* next sync will work it out again */ }
    }

    /**
     * Reads one system_settings value, returning $default when it is missing, empty or unreadable.
     *
     * @param string $key  the system_settings key to read
     * @param string $default  returned when the row is missing, empty or cannot be read
     */
    function awcGetSetting(PDO $conn, string $key, string $default): string
    {
        try {
            $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null || $v === '') ? $default : (string)$v;
        } catch (Exception) {
            return $default;
        }
    }

    /**
     * True when the table has the column (information_schema), so the sync can wait for Database Verification instead of failing.
     *
     * @param string $table  the table name in the current database
     * @param string $col  the column name to look for
     */
    function awcColumnExists(PDO $conn, string $table, string $col): bool
    {
        try {
            $s = $conn->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
            );
            $s->execute([$table, $col]);
            return (int)$s->fetchColumn() > 0;
        } catch (Exception) {
            return false;
        }
    }
}
