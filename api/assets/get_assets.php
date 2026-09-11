<?php
/**
 * API Endpoint: Get assets list
 * Returns assets with optional search filtering and user counts
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get search parameter
$search = $_GET['search'] ?? '';

try {
    $conn = connectToDatabase();

    // Check if users_assets table exists
    $tableCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'users_assets'");
    $tableCheck->execute([DB_NAME]);
    $tableExists = (int)$tableCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

    // Check if asset lookup tables exist
    $typeTableCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'asset_types'");
    $typeTableCheck->execute([DB_NAME]);
    $typeTableExists = (int)$typeTableCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

    $statusTableCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'asset_status_types'");
    $statusTableCheck->execute([DB_NAME]);
    $statusTableExists = (int)$statusTableCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

    // QR labels (#935): the tag column only exists after Database Verification
    // has run. Naming it unconditionally would turn the whole asset list into
    // "Unknown column" on an install that has pulled the update but not yet run
    // it — the same trap the snooze columns had to dodge in the ticket list.
    require_once '../../includes/asset_labels.php';
    $tagsReady = assetLabelsSchemaReady($conn);
    $tagCol = $tagsReady ? "a.asset_tag," : "NULL AS asset_tag,";

    // The search reaches into locations and contracts, both of which may be
    // absent on an install that has not run Database Verification since the
    // update — same trap as the tag column above.
    $tableCheckFor = function (string $name) use ($conn): bool {
        $q = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
        $q->execute([DB_NAME, $name]);
        return (int)$q->fetchColumn() > 0;
    };
    $locationTableExists       = $tableCheckFor('asset_locations');
    $contractAssetsTableExists = $tableCheckFor('contract_assets') && $tableCheckFor('contracts');

    // Build query with optional search
    if ($tableExists) {
        $sql = "SELECT
                    a.id,
                    a.hostname,
                    a.manufacturer,
                    a.model,
                    a.memory,
                    a.service_tag,
                    a.operating_system,
                    a.feature_release,
                    a.build_number,
                    a.cpu_name,
                    a.speed,
                    a.bios_version,
                    a.location_id,
                    a.purchase_date,
                    a.purchase_cost,
                    a.supplier_id,
                    a.order_number,
                    a.warranty_expiry,
                    /* When the agent last reported. Stored UTC; the client formats it.
                       Discussion #97 — it was written on every report and shown
                       nowhere but the phone scan page. */
                    a.first_seen,
                    a.last_seen,
                    $tagCol";

        if ($typeTableExists) {
            $sql .= "
                    a.asset_type_id,
                    aty.name AS asset_type_name,";
        } else {
            $sql .= "
                    NULL AS asset_type_id,
                    NULL AS asset_type_name,";
        }

        if ($statusTableExists) {
            $sql .= "
                    a.asset_status_id,
                    ast.name AS asset_status_name,";
        } else {
            $sql .= "
                    NULL AS asset_status_id,
                    NULL AS asset_status_name,";
        }

        $sql .= "
                    COUNT(ua.user_id) as user_count
                FROM assets a
                LEFT JOIN users_assets ua ON ua.asset_id = a.id";

        if ($typeTableExists) {
            $sql .= " LEFT JOIN asset_types aty ON aty.id = a.asset_type_id";
        }
        if ($statusTableExists) {
            $sql .= " LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id";
        }
    } else {
        // Table doesn't exist yet, just return assets without user counts
        $sql = "SELECT
                    a.id,
                    a.hostname,
                    a.manufacturer,
                    a.model,
                    a.memory,
                    a.service_tag,
                    a.operating_system,
                    a.feature_release,
                    a.build_number,
                    a.cpu_name,
                    a.speed,
                    a.bios_version,
                    a.location_id,
                    a.purchase_date,
                    a.purchase_cost,
                    a.supplier_id,
                    a.order_number,
                    a.warranty_expiry,
                    /* When the agent last reported. Stored UTC; the client formats it.
                       Discussion #97 — it was written on every report and shown
                       nowhere but the phone scan page. */
                    a.first_seen,
                    a.last_seen,
                    $tagCol
                    NULL AS asset_type_id,
                    NULL AS asset_type_name,
                    NULL AS asset_status_id,
                    NULL AS asset_status_name,
                    0 as user_count
                FROM assets a";
    }

    $params = [];

    // Scope to the active company (multi-tenancy). No-op on a single-company
    // install — activeTenantFilter returns ['', []]. The Default company also
    // sees NULL-tenant (not-yet-assigned) assets.
    [$tenantSql, $tenantParams] = activeTenantFilter($conn, (int)$_SESSION['analyst_id'], 'a');

    $sql .= " WHERE 1=1";
    if (!empty($search)) {
        // Until now this matched HOSTNAME and nothing else, which is thin for an
        // estate of any size and useless for anything without one — a SIM card,
        // a meeting-room television, a monitor. It now matches the things people
        // actually have to hand, and the contract an item is on, which is what
        // discussion #106 asked for as "search assets by contract": type a
        // contract number and the list becomes that contract's equipment.
        //
        // ⚠️ BRACKETED. An unbracketed `AND a OR b OR c` would bind the company
        // filter that follows to the LAST branch only, so every other branch
        // would return assets from every company. That exact mistake has been
        // made in this codebase before.
        //
        // EXISTS rather than JOIN, deliberately: a join to contract_assets would
        // multiply a row by the number of contracts an asset is on, and this
        // query's GROUP BY would then be load-bearing in a way it is not today.
        $like  = '%' . $search . '%';
        $terms = ['a.hostname LIKE ?', 'a.manufacturer LIKE ?', 'a.model LIKE ?', 'a.service_tag LIKE ?'];
        $args  = [$like, $like, $like, $like];

        if ($tagsReady) {
            $terms[] = 'a.asset_tag LIKE ?';
            $args[]  = $like;
        }
        if ($locationTableExists) {
            $terms[] = 'EXISTS (SELECT 1 FROM asset_locations l WHERE l.id = a.location_id AND l.name LIKE ?)';
            $args[]  = $like;
        }
        if ($contractAssetsTableExists) {
            $terms[] = 'EXISTS (SELECT 1 FROM contract_assets ca
                                  JOIN contracts c ON c.id = ca.contract_id
                                 WHERE ca.asset_id = a.id
                                   AND (c.contract_number LIKE ? OR c.title LIKE ?))';
            $args[]  = $like;
            $args[]  = $like;
        }

        $sql   .= ' AND (' . implode(' OR ', $terms) . ')';
        $params = array_merge($params, $args);
    }
    $sql .= $tenantSql;
    $params = array_merge($params, $tenantParams);

    if ($tableExists) {
        $groupBy = " GROUP BY a.id, a.hostname, a.manufacturer, a.model, a.memory, a.service_tag, a.operating_system, a.feature_release, a.build_number, a.cpu_name, a.speed, a.bios_version, a.location_id, a.purchase_date, a.purchase_cost, a.supplier_id, a.order_number, a.warranty_expiry, a.first_seen, a.last_seen";
        if ($typeTableExists) {
            $groupBy .= ", a.asset_type_id, aty.name";
        }
        if ($statusTableExists) {
            $groupBy .= ", a.asset_status_id, ast.name";
        }
        $sql .= $groupBy;
    }

    $sql .= " ORDER BY a.hostname ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach the full location path (e.g. "UK › London › Office 1") for each
    // asset, built in PHP from the location tree so the client doesn't need
    // the tree loaded. Only if the location table exists.
    $locTableCheck = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'asset_locations'");
    $locTableCheck->execute([DB_NAME]);
    if ((int)$locTableCheck->fetchColumn() > 0) {
        $locRows = $conn->query("SELECT id, name, parent_id FROM asset_locations")->fetchAll(PDO::FETCH_ASSOC);
        $locById = [];
        foreach ($locRows as $lr) { $locById[(int)$lr['id']] = $lr; }
        $pathOf = function ($id) use ($locById) {
            $parts = [];
            $guard = 0;
            while ($id !== null && isset($locById[$id]) && $guard++ < 1000) {
                array_unshift($parts, $locById[$id]['name']);
                $id = $locById[$id]['parent_id'] !== null ? (int)$locById[$id]['parent_id'] : null;
            }
            return implode(' › ', $parts);
        };
        foreach ($assets as &$a) {
            $lid = isset($a['location_id']) && $a['location_id'] !== null ? (int)$a['location_id'] : null;
            $a['location_name'] = $lid !== null && isset($locById[$lid]) ? $locById[$lid]['name'] : null;
            $a['location_path'] = $lid !== null ? $pathOf($lid) : null;
        }
        unset($a);
    }

    // Attach the supplier display name (trading name, falling back to legal name)
    // from the shared suppliers registry.
    $supTableCheck = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'suppliers'");
    $supTableCheck->execute([DB_NAME]);
    if ((int)$supTableCheck->fetchColumn() > 0) {
        $supRows = $conn->query("SELECT id, COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) AS name FROM suppliers")->fetchAll(PDO::FETCH_ASSOC);
        $supById = [];
        foreach ($supRows as $sr) { $supById[(int)$sr['id']] = $sr['name']; }
        foreach ($assets as &$a) {
            $sid = isset($a['supplier_id']) && $a['supplier_id'] !== null ? (int)$a['supplier_id'] : null;
            $a['supplier_name'] = $sid !== null && isset($supById[$sid]) ? $supById[$sid] : null;
        }
        unset($a);
    }

    // Which assets have ever been touched by a live import.
    //
    // Drives the rename warning (#1144): an imported asset is recognised by
    // whatever the import matches on, so renaming one here means the next
    // import of the same file will not find it and creates a SECOND record —
    // the same failure as renaming an agent-reported machine, and previously
    // with no warning at all because an imported television has none of the
    // hardware data that flags an agent asset.
    //
    // ONE query for the whole list, as a set — not a per-row lookup.
    try {
        $importedIds = $conn->query(
            "SELECT DISTINCT e.asset_id
               FROM asset_import_run_entries e
               JOIN asset_import_runs r ON r.id = e.run_id
              WHERE r.mode = 'live' AND e.asset_id IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($importedIds) {
            $importedIds = array_flip(array_map('intval', $importedIds));
            foreach ($assets as &$a) {
                if (isset($importedIds[(int)$a['id']])) {
                    $a['from_import'] = true;
                }
            }
            unset($a);
        }
    } catch (Exception $e) {
        // Import tables not present on this install — no flag, no warning.
    }

    // Custom field values for the fields offered as columns
    // (docs/design/flexible-asset-fields.md §8).
    //
    // ⚠️ ONE query for the whole list, not one per asset. 566 assets must cost
    // one extra round trip, which is why TypedFields::readValues() takes a list
    // of ids and has no per-owner variant.
    //
    // 🔑 Only the fields ticked "offer as a column" are fetched — and a value is
    // written onto the row only if it EXISTS. A field left blank stays absent
    // rather than becoming '' or 0, so the table can still tell "not set" apart
    // from "no" or "zero".
    require_once '../../includes/services/asset_fields.php';
    // A yes/no field is sent as the WORD the column filter will group on, so
    // t() is needed here. API endpoints do not bootstrap it (that is a PHP
    // fatal served as HTTP 200 if you forget), so it is loaded explicitly —
    // same as api/assets/email_handover.php.
    require_once '../../includes/i18n.php';
    I18n::initFromSession();
    if ($assets && AssetFieldsService::schemaReady($conn)) {
        $listFields = [];
        foreach (AssetFieldsService::catalogue($conn, (int)$_SESSION['analyst_id']) as $f) {
            if (!empty($f['show_in_list'])) {
                $listFields[$f['field_key']] = [
                    'id'       => (int)$f['id'],
                    'key'      => $f['field_key'],
                    'label'    => $f['label'],
                    'type'     => $f['field_type'],
                    'required' => false,
                    'config'   => $f['config'] ? (json_decode($f['config'], true) ?: []) : [],
                    'ref_kind' => null,
                ];
            }
        }
        if ($listFields) {
            $ids    = array_map(static fn($a) => (int)$a['id'], $assets);
            $values = AssetFieldsService::readForAssets($conn, $ids, $listFields);
            foreach ($assets as &$a) {
                $vals = $values[(int)$a['id']] ?? [];
                foreach ($listFields as $key => $def) {
                    if (!array_key_exists($key, $vals)) {
                        continue;   // absent means NOT SET; see above
                    }
                    $v = $vals[$key];
                    // Booleans become the words the column filter will show, so
                    // "Yes"/"No"/(blank) are three distinguishable buckets.
                    if ($def['type'] === 'boolean') {
                        $v = $v ? t('asset-management.common.yes') : t('asset-management.common.no');
                    }
                    $a['cf_' . $key] = $v;
                }
            }
            unset($a);
        }
    }

    echo json_encode([
        'success' => true,
        'assets' => $assets,
        'users_assets_table_exists' => $tableExists
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
