<?php
/**
 * List suppliers invited to an RFP, joined to the suppliers table for
 * legal/trading name and to the supplier_statuses lookup so the page
 * can show status badges. Most-recently-invited first.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// The RFP Builder is part of the Contracts module — its PAGES have always checked
// this, its endpoints never did. Any logged-in analyst could read, edit or delete
// any RFP by calling the API directly. (Found by debug tool D005.)
requireModuleAccessJson('contracts');
try {
    $rfpId = isset($_GET['rfp_id']) ? (int)$_GET['rfp_id'] : 0;
    if ($rfpId <= 0) throw new Exception('Missing or invalid rfp_id');

    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT i.id, i.supplier_id, i.invited_datetime, i.demo_date, i.notes,
                s.legal_name, s.trading_name, s.is_active,
                ss.name AS status_name
           FROM rfp_invited_suppliers i
      INNER JOIN suppliers s          ON i.supplier_id = s.id
       LEFT JOIN supplier_statuses ss ON s.supplier_status_id = ss.id
          WHERE i.rfp_id = ?
       ORDER BY i.invited_datetime DESC, i.id DESC"
    );
    $stmt->execute([$rfpId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['is_active']   = (bool)$r['is_active'];
        $r['display_name'] = $r['trading_name'] ?: $r['legal_name'];
    }
    unset($r);

    echo json_encode(['success' => true, 'invited' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
