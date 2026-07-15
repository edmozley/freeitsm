<?php
/**
 * API Endpoint: Save an asset location (create or update) — multi-tenancy aware.
 *
 * A location is one node in an arbitrary-depth tree, and locations are PER
 * COMPANY (a client's sites are its own — there are no shared locations). A save
 * in the Default context is the Default company's (tenant_id NULL); a save in a
 * client company's context is that company's own. A node's parent must belong to
 * the same company. Re-parenting rejects cycles (a node can't sit inside its own
 * subtree).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('assets');
requireCapabilityJson(Cap::ASSETS_LOCATIONS);   // settings write — see docs/design/rbac.md

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

    // Validate the chosen parent exists AND belongs to the active company (the
    // same data scope as the list). Default also owns NULL-tenant locations.
    if ($parentId !== null) {
        [$pfSql, $pfArgs] = activeTenantFilter($conn, $analystId, '');
        $chk = $conn->prepare("SELECT id FROM asset_locations WHERE id = ?" . $pfSql);
        $chk->execute(array_merge([$parentId], $pfArgs));
        if (!$chk->fetchColumn()) {
            throw new Exception('Selected parent location is not available here');
        }
    }

    if ($id) {
        // Confirm the row exists and that this context owns it.
        $cur = $conn->prepare("SELECT tenant_id FROM asset_locations WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Location not found');
        }
        $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];
        if ($isDefaultCtx) {
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
        // tenant_id is not changed on edit — a node keeps its owner.
        $stmt = $conn->prepare("UPDATE asset_locations SET name = ?, parent_id = ?, display_order = ? WHERE id = ?");
        $stmt->execute([$name, $parentId, $displayOrder, $id]);
        wf_emit('asset_location', 'updated', (int)$id, $name);
        echo json_encode(['success' => true, 'id' => $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO asset_locations (name, parent_id, display_order, tenant_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $parentId, $displayOrder, $scopeTenant]);
        $newId = (int)$conn->lastInsertId();
        wf_emit('asset_location', 'created', $newId, $name);
        echo json_encode(['success' => true, 'id' => $newId]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
