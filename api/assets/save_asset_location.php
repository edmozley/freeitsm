<?php
/**
 * API Endpoint: Save an asset location (create or update).
 *
 * A location is one node in an arbitrary-depth tree. parent_id is the node it
 * sits under (NULL = a root/top-level location). On edit, the parent can be
 * changed (re-parenting); we reject any move that would create a cycle
 * (a node can't become its own ancestor/descendant).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('assets');

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

    // Validate the chosen parent exists.
    if ($parentId !== null) {
        $chk = $conn->prepare("SELECT id FROM asset_locations WHERE id = ?");
        $chk->execute([$parentId]);
        if (!$chk->fetchColumn()) {
            throw new Exception('Selected parent location no longer exists');
        }
    }

    if ($id) {
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
                $row = $s->fetch(PDO::FETCH_ASSOC);
                $cursor = ($row && $row['parent_id'] !== null) ? (int)$row['parent_id'] : null;
                if (++$guard > 1000) break; // paranoia against malformed data
            }
        }
        $stmt = $conn->prepare("UPDATE asset_locations SET name = ?, parent_id = ?, display_order = ? WHERE id = ?");
        $stmt->execute([$name, $parentId, $displayOrder, $id]);
        wf_emit('asset_location', 'updated', (int)$id, $name);
        echo json_encode(['success' => true, 'id' => $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO asset_locations (name, parent_id, display_order) VALUES (?, ?, ?)");
        $stmt->execute([$name, $parentId, $displayOrder]);
        $newId = (int)$conn->lastInsertId();
        wf_emit('asset_location', 'created', $newId, $name);
        echo json_encode(['success' => true, 'id' => $newId]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
