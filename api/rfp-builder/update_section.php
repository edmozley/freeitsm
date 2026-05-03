<?php
/**
 * Manually edit a category section's HTML. Snapshots the current
 * version into rfp_section_history before overwriting, so the
 * version history sidebar always shows every state the section has
 * ever been in. Marks is_manually_edited = 1 so future hash-skip on
 * Generate-all leaves the edit alone (only an explicit Re-generate
 * regenerates a manually-edited section).
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

    $content = $data['section_content'] ?? '';
    if (!is_string($content)) throw new Exception('Section content must be a string');

    $conn = connectToDatabase();

    $row = $conn->prepare(
        "SELECT id, rfp_id, version, section_content, is_manually_edited
           FROM rfp_output_sections WHERE id = ?"
    );
    $row->execute([$id]);
    $existing = $row->fetch(PDO::FETCH_ASSOC);
    if (!$existing) throw new Exception('Section not found');

    $conn->beginTransaction();
    try {
        // Snapshot the version we're about to overwrite. Skip if there's
        // no content there yet (first edit on a freshly-inserted row,
        // unusual but possible).
        if ($existing['section_content'] !== null) {
            $hist = $conn->prepare(
                "INSERT INTO rfp_section_history
                    (section_id, version, section_content, is_manually_edited)
                 VALUES (?, ?, ?, ?)"
            );
            $hist->execute([
                (int)$existing['id'],
                (int)$existing['version'],
                $existing['section_content'],
                (int)$existing['is_manually_edited']
            ]);
        }

        $newVersion = (int)$existing['version'] + 1;

        $upd = $conn->prepare(
            "UPDATE rfp_output_sections
                SET section_content    = ?,
                    version            = ?,
                    is_manually_edited = 1,
                    edited_datetime    = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $upd->execute([$content, $newVersion, $id]);

        $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
             ->execute([(int)$existing['rfp_id']]);

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    echo json_encode(['success' => true, 'id' => $id, 'version' => $newVersion]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
