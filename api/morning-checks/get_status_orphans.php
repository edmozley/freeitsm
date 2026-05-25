<?php
/**
 * API: Get orphan status labels — distinct Status string values from
 * morningChecks_Results where StatusID IS NULL, with row counts.
 *
 * Drives:
 *   - The dashboard's "you have N unmapped results" warning banner
 *   - The Settings → Statuses → Normalisation tool listing
 *
 * Returns:
 *   { success, totalOrphans, labels: [{label, count}, ...] }
 *
 * Rows where both StatusID and Status are NULL (a check the analyst
 * never picked a status for) are NOT orphans — those just haven't
 * been answered yet. The filter requires Status IS NOT NULL.
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

    $stmt = $conn->query(
        "SELECT Status AS label, COUNT(*) AS cnt
         FROM morningChecks_Results
         WHERE StatusID IS NULL AND Status IS NOT NULL AND Status <> ''
         GROUP BY Status
         ORDER BY cnt DESC, Status"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $total = 0;
    foreach ($rows as $r) {
        $c = (int)$r['cnt'];
        $labels[] = ['label' => $r['label'], 'count' => $c];
        $total += $c;
    }

    echo json_encode([
        'success' => true,
        'totalOrphans' => $total,
        'labels' => $labels,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
