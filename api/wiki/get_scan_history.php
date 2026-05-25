<?php
/**
 * API Endpoint: Get scan run history
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT id, started_at, completed_at, status, files_scanned, functions_found, classes_found,
                                   TIMESTAMPDIFF(SECOND, started_at, completed_at) as duration_seconds,
                                   error_message, scanned_by
                            FROM wiki_scan_runs
                            ORDER BY id DESC");
    $stmt->execute();
    $scans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($scans as &$s) {
        $s['id'] = (int)$s['id'];
        $s['files_scanned'] = (int)$s['files_scanned'];
        $s['functions_found'] = (int)$s['functions_found'];
        $s['classes_found'] = (int)$s['classes_found'];
        $s['duration_seconds'] = $s['duration_seconds'] !== null ? (int)$s['duration_seconds'] : null;
    }

    echo json_encode(['success' => true, 'scans' => $scans]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
