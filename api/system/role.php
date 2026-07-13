<?php
/**
 * System API: a single RBAC role — detail (GET), update (PUT), delete (DELETE).
 *
 * The update writes the role's identity, its capabilities, AND its assignments
 * (which analysts and teams hold it) in one transaction, so the edit screen saves
 * as a unit. Capability keys are validated against the code registry — a key the
 * app doesn't define is refused, so the DB can never hold a phantom capability.
 *
 * Administrators only (admin_api_guard.php). See docs/design/rbac.md.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

$conn   = connectToDatabase();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    if ($method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT id, name, description, is_active FROM rbac_roles WHERE id = ?");
        $stmt->execute([$id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$role) throw new Exception('Role not found');

        $caps = $conn->prepare("SELECT capability_key FROM rbac_role_capabilities WHERE role_id = ?");
        $caps->execute([$id]);
        $role['capabilities'] = $caps->fetchAll(PDO::FETCH_COLUMN);

        $an = $conn->prepare("SELECT analyst_id FROM rbac_analyst_roles WHERE role_id = ?");
        $an->execute([$id]);
        $role['analyst_ids'] = array_map('intval', $an->fetchAll(PDO::FETCH_COLUMN));

        $tm = $conn->prepare("SELECT team_id FROM rbac_team_roles WHERE role_id = ?");
        $tm->execute([$id]);
        $role['team_ids'] = array_map('intval', $tm->fetchAll(PDO::FETCH_COLUMN));

        echo json_encode(['success' => true, 'data' => $role]);
        exit;
    }

    $id  = (int)($input['id'] ?? 0);
    $verb = $input['_method'] ?? $method;

    if ($verb === 'DELETE') {
        // Capabilities + assignments cascade away with the role (FKs).
        $conn->prepare("DELETE FROM rbac_roles WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($verb === 'PUT') {
        $name = trim($input['name'] ?? '');
        if ($name === '') throw new Exception('A role name is required.');

        // Refuse any capability the code doesn't define — no phantom rows. capFromKey()
        // also maps a retired key through capAliases(), so a role saved before a rename
        // is re-saved under the current key rather than losing the grant.
        $capabilities = [];
        foreach (array_unique((array)($input['capabilities'] ?? [])) as $cap) {
            $resolved = capFromKey((string) $cap);
            if ($resolved === null) throw new Exception('Unknown capability: ' . $cap);
            $capabilities[] = $resolved;
        }
        $capabilities = array_values(array_unique($capabilities));
        $analystIds = array_map('intval', (array)($input['analyst_ids'] ?? []));
        $teamIds    = array_map('intval', (array)($input['team_ids'] ?? []));

        $conn->beginTransaction();

        $conn->prepare("UPDATE rbac_roles SET name = ?, description = ?, is_active = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$name, trim($input['description'] ?? ''), !empty($input['is_active']) ? 1 : 0, $id]);

        // Replace-all for each join — the screen sends the full desired set.
        $conn->prepare("DELETE FROM rbac_role_capabilities WHERE role_id = ?")->execute([$id]);
        $insCap = $conn->prepare("INSERT INTO rbac_role_capabilities (role_id, capability_key) VALUES (?, ?)");
        foreach ($capabilities as $cap) $insCap->execute([$id, $cap]);

        $conn->prepare("DELETE FROM rbac_analyst_roles WHERE role_id = ?")->execute([$id]);
        $insAn = $conn->prepare("INSERT INTO rbac_analyst_roles (analyst_id, role_id, created_datetime) VALUES (?, ?, UTC_TIMESTAMP())");
        foreach (array_unique($analystIds) as $aid) { if ($aid > 0) $insAn->execute([$aid, $id]); }

        $conn->prepare("DELETE FROM rbac_team_roles WHERE role_id = ?")->execute([$id]);
        $insTm = $conn->prepare("INSERT INTO rbac_team_roles (team_id, role_id, created_datetime) VALUES (?, ?, UTC_TIMESTAMP())");
        foreach (array_unique($teamIds) as $tid) { if ($tid > 0) $insTm->execute([$tid, $id]); }

        $conn->commit();
        echo json_encode(['success' => true]);
        exit;
    }

    throw new Exception('Unsupported method');
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
