<?php
/**
 * API Endpoint: Save an asset location (create or update) — multi-tenancy aware.
 *
 * A location is one node in an arbitrary-depth tree, and locations are PER
 * COMPANY (a client's sites are its own). A save in the Default context is the
 * Default company's (tenant_id NULL); a save in a client company's context is
 * that company's own. A node's parent must belong to the same company, or be
 * shared. Re-parenting rejects cycles (a node can't sit inside its own subtree).
 *
 * 2.6.0 SHARED locations (`is_shared`): every company can pick one. The rules
 * are in includes/asset_locations.php; this endpoint enforces them.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/asset_locations.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// GH #311: an administrator (System -> Locations), or Assets plus the assets.locations
// permission (Assets -> Settings -> Locations) - see includes/asset_locations.php.
locationsRequireAccessJson(true);

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id = !empty($data['id']) ? (int)$data['id'] : null;
    $name = trim($data['name'] ?? '');
    $parentId = (isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null)
        ? (int)$data['parent_id'] : null;
    $displayOrder = isset($data['display_order']) ? (int)$data['display_order'] : 0;

    if ($name === '') {
        throw new Exception('Name is required');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    // Scope this location targets: NULL = the Default company, else this company.
    $scopeTenant = $isDefaultCtx ? null : $activeId;

    // Shared (2.6.0). Only read when the column exists, so an install that has
    // not run Database Verification saves exactly as it did before.
    $sharedReady = $multi && assetLocationsSharedReady($conn);
    $wantShared  = $sharedReady && !empty($data['is_shared']);
    $canShare    = $sharedReady && analystHasAllTenantAccess($conn, $analystId);
    if ($wantShared && !$canShare) {
        throw new Exception('Only someone with access to every company can share a location.');
    }

    // Validate the chosen parent exists AND is visible to the active company
    // (its own, or shared). Default also owns NULL-tenant locations.
    if ($parentId !== null) {
        [$pfSql, $pfArgs] = assetLocationScope($conn, $activeId, '');
        $chk = $conn->prepare("SELECT id FROM asset_locations WHERE id = ?" . $pfSql);
        $chk->execute(array_merge([$parentId], $pfArgs));
        if (!$chk->fetchColumn()) {
            throw new Exception('Selected parent location is not available here');
        }
        // A shared location inside a private one would be a child that other
        // companies can see under a parent they cannot: an orphan in their tree.
        if ($wantShared && !assetLocationIsShared($conn, $parentId)) {
            throw new Exception('A shared location must sit inside another shared location, or at the top level.');
        }
    }

    if ($id) {
        // Confirm the row exists and that this context owns it.
        $cur = $conn->prepare("SELECT tenant_id" . ($sharedReady ? ", is_shared" : ", 0 AS is_shared") . " FROM asset_locations WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Location not found');
        }
        $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];
        $wasShared = (int)$row['is_shared'] === 1;
        if ($wasShared) {
            // A shared location belongs to every company, so no single company
            // context "owns" it: the gate is access to all of them.
            if (!$canShare) {
                throw new Exception('That location is shared by every company. Only someone with access to every company can change it.');
            }
            if (!$wantShared) {
                // Unsharing hands it to the Default company. Refused while another
                // company's assets sit there, or they would point at a location
                // their own company can no longer see.
                $u = $conn->prepare("SELECT COUNT(*) FROM assets WHERE location_id = ? AND tenant_id IS NOT NULL AND tenant_id <> ?");
                $u->execute([$id, $defaultId]);
                $inUse = (int)$u->fetchColumn();
                if ($inUse > 0) {
                    throw new Exception("{$inUse} asset(s) in other companies are at this location. Move them first, or keep it shared.");
                }
                $sc = $conn->prepare("SELECT COUNT(*) FROM asset_locations WHERE parent_id = ? AND is_shared = 1");
                $sc->execute([$id]);
                if ((int)$sc->fetchColumn() > 0) {
                    throw new Exception('Shared locations sit inside this one. Unshare those first.');
                }
            }
        } elseif ($isDefaultCtx) {
            if ($owner !== null) {
                throw new Exception("That's a company's own location — switch to that company to edit it.");
            }
        } else {
            if ($owner === null) {
                throw new Exception('That location belongs to the Default company — switch to it to manage that location.');
            }
            if ($owner !== $activeId) {
                throw new Exception('That location belongs to another company.');
            }
        }

        if ($parentId === $id) {
            throw new Exception('A location cannot be its own parent');
        }
        // Cycle guard: walk up from the proposed parent — if we reach this node,
        // the move would put a node inside its own subtree.
        if ($parentId !== null) {
            $cursor = $parentId;
            $guard = 0;
            while ($cursor !== null) {
                if ($cursor === $id) {
                    throw new Exception('That move would nest a location inside itself');
                }
                $s = $conn->prepare("SELECT parent_id FROM asset_locations WHERE id = ?");
                $s->execute([$cursor]);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                $cursor = ($r && $r['parent_id'] !== null) ? (int)$r['parent_id'] : null;
                if (++$guard > 1000) break; // paranoia against malformed data
            }
        }
        if ($sharedReady && ($wantShared || $wasShared)) {
            // Sharing, or unsharing. A shared row belongs to no single company
            // (tenant_id NULL); an unshared one is handed to the Default company,
            // which is also NULL. So tenant_id becomes NULL either way. Moving
            // a private location of a client into "shared" is the one case
            // where it changes.
            $stmt = $conn->prepare("UPDATE asset_locations SET name = ?, parent_id = ?, display_order = ?, is_shared = ?, tenant_id = NULL WHERE id = ?");
            $stmt->execute([$name, $parentId, $displayOrder, $wantShared ? 1 : 0, $id]);
        } else {
            // tenant_id is not changed on edit — a node keeps its owner.
            $stmt = $conn->prepare("UPDATE asset_locations SET name = ?, parent_id = ?, display_order = ? WHERE id = ?");
            $stmt->execute([$name, $parentId, $displayOrder, $id]);
        }
        wf_emit('asset_location', 'updated', (int)$id, $name);
        echo json_encode(['success' => true, 'id' => $id]);
    } else {
        if ($wantShared) {
            $stmt = $conn->prepare("INSERT INTO asset_locations (name, parent_id, display_order, tenant_id, is_shared) VALUES (?, ?, ?, NULL, 1)");
            $stmt->execute([$name, $parentId, $displayOrder]);
        } else {
            $stmt = $conn->prepare("INSERT INTO asset_locations (name, parent_id, display_order, tenant_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $parentId, $displayOrder, $scopeTenant]);
        }
        $newId = (int)$conn->lastInsertId();
        wf_emit('asset_location', 'created', $newId, $name);
        echo json_encode(['success' => true, 'id' => $newId]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
