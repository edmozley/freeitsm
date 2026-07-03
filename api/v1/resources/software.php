<?php
/**
 * FreeITSM REST API v1 — software resource (inventory + licences).
 *
 * Inventory (apps + per-host installs) is 100% agent-owned in the product —
 * analysts can't edit any of it — so the API exposes it READ-ONLY, mirroring
 * get_apps.php's install-count query and system-component filters. Licences
 * are the writable half, mirroring save_licence.php (app_id + licence_type
 * mandatory, currency defaults GBP, status defaults Active, created_by
 * stamped on insert and never touched on update).
 *
 * Two deliberate API additions the UI doesn't have, documented:
 *   - COMPLIANCE numbers: licence rows and app detail carry install counts
 *     (distinct non-system-component hosts) next to licensed seats, so
 *     seats-vs-installs reports are one call — the UI never computes this.
 *   - SERVER-SIDE renewal filters + a computed renewal_status
 *     (ok / due_soon / overdue) matching the licence screen's client-side
 *     colour logic (due_soon = within notice_period_days, default 30).
 *
 * The legacy plaintext apikeys management that lives in this module's
 * settings is NOT part of this API (v1 has its own key system).
 * Software tables are install-wide (no tenant_id — matches the UI).
 */

// ---------------------------------------------------------------------------
// Apps (read-only inventory catalogue)
// ---------------------------------------------------------------------------

function apiSoftwareAppsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where  = ['1=1'];
    $args   = [];
    $having = '';

    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(a.display_name LIKE ? OR a.publisher LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like);
    }
    // get_apps.php's exact tabs: apps (non-components), components, all.
    $filter = trim($_GET['filter'] ?? 'all');
    if ($filter === 'apps') {
        $having = 'HAVING (MAX(d.system_component) = 0 OR MAX(d.system_component) IS NULL)';
    } elseif ($filter === 'components') {
        $having = 'HAVING MAX(d.system_component) = 1';
    } elseif ($filter !== 'all') {
        apiError(400, 'invalid_parameter', "'filter' must be apps, components or all.");
    }

    $sortable = ['name' => 'a.display_name', 'publisher' => 'a.publisher', 'install_count' => 'install_count', 'id' => 'a.id'];
    $sortParam = trim($_GET['sort'] ?? 'name');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC') . ', a.display_name ASC';

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) FROM (
            SELECT a.id FROM software_inventory_apps a
            LEFT JOIN software_inventory_detail d ON d.app_id = a.id
            WHERE $whereSql GROUP BY a.id $having
         ) x"
    );
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(
        "SELECT a.id, a.display_name, a.publisher, a.first_detected,
                COUNT(DISTINCT d.host_id) AS install_count,
                MAX(d.system_component) AS system_component,
                (SELECT COUNT(*) FROM software_licences l WHERE l.app_id = a.id) AS licence_count
         FROM software_inventory_apps a
         LEFT JOIN software_inventory_detail d ON d.app_id = a.id
         WHERE $whereSql
         GROUP BY a.id, a.display_name, a.publisher, a.first_detected
         $having
         ORDER BY $orderSql LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($args);
    apiRespond(array_map(function ($r) {
        return [
            'id'               => (int)$r['id'],
            'name'             => $r['display_name'],
            'publisher'        => $r['publisher'],
            'install_count'    => (int)$r['install_count'],
            'system_component' => (bool)$r['system_component'],
            'licence_count'    => (int)$r['licence_count'],
            'first_detected'   => apiIsoDate($r['first_detected']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function apiSoftwareLoadApp(PDO $conn, int $appId): array {
    $stmt = $conn->prepare("SELECT id, display_name, publisher, first_detected FROM software_inventory_apps WHERE id = ?");
    $stmt->execute([$appId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Application not found.');
    }
    return $row;
}

/** Distinct non-system-component hosts for an app — the compliance install count. */
function apiSoftwareInstallCount(PDO $conn, int $appId): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT host_id) FROM software_inventory_detail
         WHERE app_id = ? AND system_component = 0"
    );
    $stmt->execute([$appId]);
    return (int)$stmt->fetchColumn();
}

// GET /software/apps/{id} — app + licences + compliance numbers
function apiSoftwareAppsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $app = apiSoftwareLoadApp($conn, $params[0]);
    $installs = apiSoftwareInstallCount($conn, $params[0]);

    $lStmt = $conn->prepare(
        "SELECT id, licence_type, quantity, renewal_date, status FROM software_licences
         WHERE app_id = ? ORDER BY renewal_date IS NULL, renewal_date"
    );
    $lStmt->execute([$params[0]]);
    $licences = $lStmt->fetchAll(PDO::FETCH_ASSOC);

    $licensedSeats = 0;
    $hasUncounted = false;
    foreach ($licences as $l) {
        if ($l['status'] === 'Active') {
            if ($l['quantity'] === null) {
                $hasUncounted = true; // e.g. a site licence with no seat count
            } else {
                $licensedSeats += (int)$l['quantity'];
            }
        }
    }

    apiRespond([
        'id'             => (int)$app['id'],
        'name'           => $app['display_name'],
        'publisher'      => $app['publisher'],
        'first_detected' => apiIsoDate($app['first_detected']),
        'compliance'     => [
            'installs'            => $installs,
            'licensed_seats'      => $licensedSeats,
            'unmetered_licences'  => $hasUncounted, // an Active licence without a seat count exists
            'seats_available'     => $hasUncounted ? null : $licensedSeats - $installs,
        ],
        'licences'       => array_map(function ($l) {
            return [
                'id'           => (int)$l['id'],
                'licence_type' => $l['licence_type'],
                'quantity'     => $l['quantity'] !== null ? (int)$l['quantity'] : null,
                'renewal_date' => $l['renewal_date'],
                'status'       => $l['status'],
            ];
        }, $licences),
    ]);
}

// GET /software/apps/{id}/machines — mirrors get_app_machines.php (+ asset_id)
function apiSoftwareAppMachinesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiSoftwareLoadApp($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT h.id AS asset_id, h.hostname, d.display_version, d.install_date, d.system_component, d.last_seen
         FROM software_inventory_detail d
         INNER JOIN assets h ON h.id = d.host_id
         WHERE d.app_id = ? ORDER BY h.hostname"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($m) {
        return [
            'asset_id'         => (int)$m['asset_id'],
            'hostname'         => $m['hostname'],
            'version'          => $m['display_version'],
            'install_date'     => $m['install_date'],
            'system_component' => (bool)$m['system_component'],
            'last_seen'        => apiIsoDate($m['last_seen']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------------------------------------------------------------------------
// Licences
// ---------------------------------------------------------------------------

function apiSoftwareLicenceSelect(): string {
    return "SELECT l.*, a.display_name AS app_name, a.publisher AS app_publisher,
                   an.full_name AS created_by_name,
                   (SELECT COUNT(DISTINCT d.host_id) FROM software_inventory_detail d
                     WHERE d.app_id = l.app_id AND d.system_component = 0) AS app_installs
            FROM software_licences l
            JOIN software_inventory_apps a ON a.id = l.app_id
            LEFT JOIN analysts an ON an.id = l.created_by";
}

function apiSerializeLicence(array $r): array {
    // The licence screen's client-side colour logic, computed server-side:
    // overdue = renewal passed; due_soon = within notice period (default 30).
    $renewalStatus = null;
    if ($r['renewal_date'] !== null) {
        $today  = date('Y-m-d');
        $notice = $r['notice_period_days'] !== null ? (int)$r['notice_period_days'] : 30;
        $dueSoonFrom = date('Y-m-d', strtotime($r['renewal_date'] . " -{$notice} days"));
        if ($r['renewal_date'] < $today) {
            $renewalStatus = 'overdue';
        } elseif ($today >= $dueSoonFrom) {
            $renewalStatus = 'due_soon';
        } else {
            $renewalStatus = 'ok';
        }
    }
    return [
        'id'           => (int)$r['id'],
        'app'          => ['id' => (int)$r['app_id'], 'name' => $r['app_name'], 'publisher' => $r['app_publisher']],
        'licence_type' => $r['licence_type'],
        'licence_key'  => $r['licence_key'],
        'quantity'     => $r['quantity'] !== null ? (int)$r['quantity'] : null,
        'app_installs' => (int)($r['app_installs'] ?? 0),
        'renewal_date' => $r['renewal_date'],
        'renewal_status' => $renewalStatus,
        'notice_period_days' => $r['notice_period_days'] !== null ? (int)$r['notice_period_days'] : null,
        'portal_url'   => $r['portal_url'],
        'cost'         => $r['cost'] !== null ? (float)$r['cost'] : null,
        'currency'     => $r['currency'],
        'purchase_date' => $r['purchase_date'],
        'vendor_contact' => $r['vendor_contact'],
        'notes'        => $r['notes'],
        'status'       => $r['status'],
        'created_by'   => $r['created_by'] === null ? null : ['id' => (int)$r['created_by'], 'name' => $r['created_by_name']],
        'created_at'   => apiIsoDate($r['created_at']),
        'updated_at'   => apiIsoDate($r['updated_at']),
    ];
}

function apiLoadLicence(PDO $conn, int $licenceId): array {
    $stmt = $conn->prepare(apiSoftwareLicenceSelect() . " WHERE l.id = ?");
    $stmt->execute([$licenceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Licence not found.');
    }
    return $row;
}

// GET /software/licences
function apiSoftwareLicencesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    if (isset($_GET['app_id']) && $_GET['app_id'] !== '') {
        $where[] = 'l.app_id = ?';
        $args[]  = (int)$_GET['app_id'];
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $where[] = 'l.status = ?';
        $args[]  = trim($_GET['status']);
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(a.display_name LIKE ? OR l.licence_type LIKE ? OR l.vendor_contact LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like, $like);
    }
    // Server-side versions of the licence screen's client-side renewal states.
    if (isset($_GET['renewal_within_days']) && $_GET['renewal_within_days'] !== '') {
        $where[] = 'l.renewal_date IS NOT NULL AND l.renewal_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)';
        $args[]  = max(0, (int)$_GET['renewal_within_days']);
    }
    if (($_GET['renewal_overdue'] ?? '') === 'true') {
        $where[] = 'l.renewal_date IS NOT NULL AND l.renewal_date < CURDATE()';
    }
    if (($_GET['due_soon'] ?? '') === 'true') {
        // Within each licence's own notice period (default 30), not yet overdue.
        $where[] = "l.renewal_date IS NOT NULL AND l.renewal_date >= CURDATE()
                    AND l.renewal_date <= DATE_ADD(CURDATE(), INTERVAL COALESCE(l.notice_period_days, 30) DAY)";
    }

    $sortable = [
        'renewal_date' => 'l.renewal_date', 'app' => 'a.display_name', 'cost' => 'l.cost',
        'created_at' => 'l.created_at', 'id' => 'l.id',
    ];
    $sortParam = trim($_GET['sort'] ?? 'renewal_date');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC') . ', a.display_name ASC';

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) FROM software_licences l JOIN software_inventory_apps a ON a.id = l.app_id WHERE $whereSql"
    );
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiSoftwareLicenceSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    apiRespond(array_map('apiSerializeLicence', $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function apiSoftwareLicencesGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeLicence(apiLoadLicence($conn, $params[0])));
}

/** Shared field collection/validation (mirrors save_licence.php's coercions). */
function apiSoftwareLicenceFields(PDO $conn, array $body, array $current = []): array {
    $get = function (string $key, $default = null) use ($body, $current) {
        return array_key_exists($key, $body) ? $body[$key] : ($current[$key] ?? $default);
    };

    $f = [];
    $f['app_id'] = (int)($get('app_id') ?? 0);
    if ($f['app_id'] <= 0) {
        apiError(422, 'missing_field', "'app_id' is required.");
    }
    $appCheck = $conn->prepare("SELECT id FROM software_inventory_apps WHERE id = ?");
    $appCheck->execute([$f['app_id']]);
    if (!$appCheck->fetchColumn()) {
        apiError(422, 'invalid_field', "Unknown app id: {$f['app_id']}");
    }
    $f['licence_type'] = trim((string)($get('licence_type') ?? ''));
    if ($f['licence_type'] === '') {
        apiError(422, 'missing_field', "'licence_type' is required.");
    }

    $f['licence_key'] = ($v = trim((string)($get('licence_key') ?? ''))) !== '' ? $v : null;
    $f['quantity'] = null;
    if ($get('quantity') !== null && $get('quantity') !== '') {
        if (!is_numeric($get('quantity')) || (int)$get('quantity') < 0) {
            apiError(422, 'invalid_field', "'quantity' must be a non-negative integer.");
        }
        $f['quantity'] = (int)$get('quantity');
    }
    $f['renewal_date']  = apiParseDateOnly($get('renewal_date'), 'renewal_date');
    $f['purchase_date'] = apiParseDateOnly($get('purchase_date'), 'purchase_date');
    $f['notice_period_days'] = null;
    if ($get('notice_period_days') !== null && $get('notice_period_days') !== '') {
        $f['notice_period_days'] = max(0, (int)$get('notice_period_days'));
    }
    $f['portal_url'] = ($v = trim((string)($get('portal_url') ?? ''))) !== '' ? $v : null;
    $f['cost'] = null;
    if ($get('cost') !== null && $get('cost') !== '') {
        if (!is_numeric($get('cost'))) {
            apiError(422, 'invalid_field', "'cost' must be a number.");
        }
        $f['cost'] = (string)round((float)$get('cost'), 2);
    }
    $f['currency'] = trim((string)($get('currency') ?? '')) ?: 'GBP';
    if (mb_strlen($f['currency']) > 10) {
        apiError(422, 'invalid_field', "'currency' must be at most 10 characters.");
    }
    $f['vendor_contact'] = ($v = trim((string)($get('vendor_contact') ?? ''))) !== '' ? $v : null;
    $f['notes']  = ($v = trim((string)($get('notes') ?? ''))) !== '' ? $v : null;
    $f['status'] = trim((string)($get('status') ?? '')) ?: 'Active';

    return $f;
}

// POST /software/licences
function apiSoftwareLicencesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $f = apiSoftwareLicenceFields($conn, $body);
    $ins = $conn->prepare(
        "INSERT INTO software_licences
            (app_id, licence_type, licence_key, quantity, renewal_date,
             notice_period_days, portal_url, cost, currency, purchase_date,
             vendor_contact, notes, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $ins->execute([
        $f['app_id'], $f['licence_type'], $f['licence_key'], $f['quantity'], $f['renewal_date'],
        $f['notice_period_days'], $f['portal_url'], $f['cost'], $f['currency'], $f['purchase_date'],
        $f['vendor_contact'], $f['notes'], $f['status'], (int)$apiKey['analyst_id'],
    ]);
    apiRespond(apiSerializeLicence(apiLoadLicence($conn, (int)$conn->lastInsertId())), 201);
}

// PATCH /software/licences/{id}
function apiSoftwareLicencesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $current = apiLoadLicence($conn, $params[0]);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    $f = apiSoftwareLicenceFields($conn, $body, $current);
    // created_by is never touched on update — save_licence.php parity.
    $upd = $conn->prepare(
        "UPDATE software_licences SET app_id=?, licence_type=?, licence_key=?, quantity=?,
            renewal_date=?, notice_period_days=?, portal_url=?, cost=?, currency=?,
            purchase_date=?, vendor_contact=?, notes=?, status=?, updated_at=UTC_TIMESTAMP()
         WHERE id=?"
    );
    $upd->execute([
        $f['app_id'], $f['licence_type'], $f['licence_key'], $f['quantity'], $f['renewal_date'],
        $f['notice_period_days'], $f['portal_url'], $f['cost'], $f['currency'], $f['purchase_date'],
        $f['vendor_contact'], $f['notes'], $f['status'], $params[0],
    ]);
    apiRespond(apiSerializeLicence(apiLoadLicence($conn, $params[0])));
}

// DELETE /software/licences/{id} — leaf table, mirrors delete_licence.php
function apiSoftwareLicencesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadLicence($conn, $params[0]);
    $conn->prepare("DELETE FROM software_licences WHERE id = ?")->execute([$params[0]]);
    apiRespond(['id' => $params[0], 'deleted' => true]);
}
