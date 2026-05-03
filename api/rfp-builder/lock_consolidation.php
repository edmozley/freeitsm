<?php
/**
 * Lock or unlock all consolidated requirements for an RFP. Locking is
 * the gate for Phase 4 (section generation) — once locked the analyst
 * has signed off the consolidated set and downstream generation can
 * trust that the inputs won't shift underneath it. Unlock to re-enter
 * editing mode.
 *
 * Also nudges the parent RFP's status: locking moves it from
 * 'collecting'/'consolidating' to 'generating' (i.e. ready for Phase 4);
 * unlocking moves a status of 'generating' back to 'consolidating' so
 * the analyst's intent is reflected in the RFP list.
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
    $rfpId = isset($data['rfp_id']) ? (int)$data['rfp_id'] : 0;
    $lock  = isset($data['lock']) ? (bool)$data['lock'] : true;
    if ($rfpId <= 0) throw new Exception('Missing or invalid rfp_id');

    $conn = connectToDatabase();

    $rfp = $conn->prepare("SELECT id FROM rfps WHERE id = ?");
    $rfp->execute([$rfpId]);
    if (!$rfp->fetch()) throw new Exception('RFP not found');

    $count = $conn->prepare("SELECT COUNT(*) FROM rfp_consolidated_requirements WHERE rfp_id = ?");
    $count->execute([$rfpId]);
    $rowCount = (int)$count->fetchColumn();
    if ($lock && $rowCount === 0) {
        throw new Exception('No consolidated requirements to lock — run consolidation first');
    }

    $conn->beginTransaction();
    try {
        $upd = $conn->prepare(
            "UPDATE rfp_consolidated_requirements
                SET is_locked = ?, updated_datetime = CURRENT_TIMESTAMP
              WHERE rfp_id = ?"
        );
        $upd->execute([$lock ? 1 : 0, $rfpId]);

        // Status nudges. We only move forward / back from the
        // consolidation-stage statuses; if the user has already
        // progressed further (e.g. 'scoring') we leave them alone.
        if ($lock) {
            $conn->prepare(
                "UPDATE rfps
                    SET status = CASE WHEN status IN ('draft','collecting','consolidating') THEN 'generating' ELSE status END,
                        updated_datetime = CURRENT_TIMESTAMP
                  WHERE id = ?"
            )->execute([$rfpId]);
        } else {
            $conn->prepare(
                "UPDATE rfps
                    SET status = CASE WHEN status = 'generating' THEN 'consolidating' ELSE status END,
                        updated_datetime = CURRENT_TIMESTAMP
                  WHERE id = ?"
            )->execute([$rfpId]);
        }

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    echo json_encode(['success' => true, 'rfp_id' => $rfpId, 'locked' => $lock, 'rows_affected' => $rowCount]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
