<?php
/**
 * Return the version history for a single category section, plus the
 * current row's metadata so the UI can show "current" alongside the
 * historical entries. Newest first.
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
    $sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
    if ($sectionId <= 0) throw new Exception('Missing or invalid section_id');

    $conn = connectToDatabase();

    $cur = $conn->prepare(
        "SELECT s.id, s.rfp_id, s.category_id, s.section_title, s.section_content,
                s.version, s.is_manually_edited, s.generated_datetime, s.edited_datetime,
                c.name AS category_name
           FROM rfp_output_sections s
      LEFT JOIN rfp_categories c ON s.category_id = c.id
          WHERE s.id = ?"
    );
    $cur->execute([$sectionId]);
    $current = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$current) throw new Exception('Section not found');
    $current['is_manually_edited'] = (bool)$current['is_manually_edited'];
    $current['version']            = (int)$current['version'];

    $hist = $conn->prepare(
        "SELECT id, version, section_content, is_manually_edited, created_datetime
           FROM rfp_section_history
          WHERE section_id = ?
       ORDER BY version DESC, id DESC"
    );
    $hist->execute([$sectionId]);
    $history = $hist->fetchAll(PDO::FETCH_ASSOC);
    foreach ($history as &$h) {
        $h['version']            = (int)$h['version'];
        $h['is_manually_edited'] = (bool)$h['is_manually_edited'];
    }
    unset($h);

    echo json_encode([
        'success' => true,
        'current' => $current,
        'history' => $history,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
