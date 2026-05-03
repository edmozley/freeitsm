<?php
/**
 * Update a conflict's resolution state. Supports choose-A, choose-B,
 * dismiss, label-as-merged, label-as-split, or re-open. Records the
 * acting analyst and timestamp on every transition.
 *
 * "Merge into one" from the conflicts panel does NOT use this endpoint —
 * it routes through the existing merge_consolidated.php which deletes
 * the underlying rows; the conflict row cascade-deletes itself as a
 * by-product. This endpoint covers the non-destructive resolutions.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('Missing or invalid id');

    $resolution = $data['resolution'] ?? '';
    $valid = ['open', 'chose_a', 'chose_b', 'merged', 'split', 'dismissed'];
    if (!in_array($resolution, $valid, true)) {
        throw new Exception('Invalid resolution');
    }
    $notes = isset($data['notes']) ? trim($data['notes']) : null;
    if ($notes === '') $notes = null;

    $conn = connectToDatabase();

    $row = $conn->prepare("SELECT id, rfp_id FROM rfp_conflicts WHERE id = ?");
    $row->execute([$id]);
    $conflict = $row->fetch(PDO::FETCH_ASSOC);
    if (!$conflict) throw new Exception('Conflict not found');

    if ($resolution === 'open') {
        // Re-open: clear resolution fields entirely.
        $upd = $conn->prepare(
            "UPDATE rfp_conflicts
                SET resolution = 'open',
                    resolution_notes = NULL,
                    resolved_by_analyst_id = NULL,
                    resolved_datetime = NULL
              WHERE id = ?"
        );
        $upd->execute([$id]);
    } else {
        $upd = $conn->prepare(
            "UPDATE rfp_conflicts
                SET resolution = ?,
                    resolution_notes = ?,
                    resolved_by_analyst_id = ?,
                    resolved_datetime = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $upd->execute([$resolution, $notes, (int)$_SESSION['analyst_id'], $id]);
    }

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([(int)$conflict['rfp_id']]);

    echo json_encode(['success' => true, 'id' => $id, 'resolution' => $resolution]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
