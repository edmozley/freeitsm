<?php
/**
 * List active suppliers not yet invited to a given RFP — feeds the
 * "invite supplier" picker.
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
    $rfpId = isset($_GET['rfp_id']) ? (int)$_GET['rfp_id'] : 0;
    if ($rfpId <= 0) throw new Exception('Missing or invalid rfp_id');

    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT s.id, s.legal_name, s.trading_name,
                ss.name AS status_name
           FROM suppliers s
      LEFT JOIN supplier_statuses ss ON s.supplier_status_id = ss.id
          WHERE s.is_active = 1
            AND s.id NOT IN (
                SELECT supplier_id FROM rfp_invited_suppliers WHERE rfp_id = ?
            )
       ORDER BY COALESCE(s.trading_name, s.legal_name)"
    );
    $stmt->execute([$rfpId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['display_name'] = $r['trading_name'] ?: $r['legal_name'];
    }
    unset($r);

    echo json_encode(['success' => true, 'suppliers' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
