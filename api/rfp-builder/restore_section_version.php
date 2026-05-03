<?php
/**
 * Restore a previous version of a category section from rfp_section_history
 * back into rfp_output_sections. Snapshots the current version first
 * so the restore itself is reversible — every transition lives in
 * the history table.
 *
 * Restore counts as a manual action, so the resulting row is marked
 * is_manually_edited = 1 (the user has explicitly chosen this content
 * over what the AI most recently produced).
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
    $data       = json_decode(file_get_contents('php://input'), true);
    $sectionId  = isset($data['section_id'])  ? (int)$data['section_id']  : 0;
    $historyId  = isset($data['history_id'])  ? (int)$data['history_id']  : 0;
    if ($sectionId <= 0 || $historyId <= 0) {
        throw new Exception('Missing or invalid section_id / history_id');
    }

    $conn = connectToDatabase();

    $cur = $conn->prepare(
        "SELECT id, rfp_id, version, section_content, is_manually_edited
           FROM rfp_output_sections WHERE id = ?"
    );
    $cur->execute([$sectionId]);
    $current = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$current) throw new Exception('Section not found');

    $hist = $conn->prepare(
        "SELECT id, version, section_content, is_manually_edited
           FROM rfp_section_history
          WHERE id = ? AND section_id = ?"
    );
    $hist->execute([$historyId, $sectionId]);
    $target = $hist->fetch(PDO::FETCH_ASSOC);
    if (!$target) throw new Exception('History version not found for this section');

    $conn->beginTransaction();
    try {
        // Snapshot the current version into history before overwriting,
        // unless the current content is empty (nothing useful to keep).
        if ($current['section_content'] !== null) {
            $snap = $conn->prepare(
                "INSERT INTO rfp_section_history
                    (section_id, version, section_content, is_manually_edited)
                 VALUES (?, ?, ?, ?)"
            );
            $snap->execute([
                (int)$current['id'],
                (int)$current['version'],
                $current['section_content'],
                (int)$current['is_manually_edited']
            ]);
        }

        $newVersion = (int)$current['version'] + 1;

        $upd = $conn->prepare(
            "UPDATE rfp_output_sections
                SET section_content    = ?,
                    version            = ?,
                    is_manually_edited = 1,
                    edited_datetime    = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $upd->execute([$target['section_content'], $newVersion, $sectionId]);

        $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
             ->execute([(int)$current['rfp_id']]);

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    echo json_encode([
        'success'         => true,
        'section_id'      => $sectionId,
        'restored_from'   => (int)$target['version'],
        'new_version'     => $newVersion,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
