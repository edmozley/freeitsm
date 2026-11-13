<?php
/**
 * API: every supplier, with its type and status names, sorted by legal name.
 *
 * @output {success, suppliers: [...]}
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';   // GH #307: tenancyColumnExists()

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('contracts');

try {
    $conn = connectToDatabase();

    $sql = "SELECT s.id, s.legal_name, s.trading_name,
                   s.reg_number, s.vat_number," . (tenancyColumnExists($conn, 'suppliers', 'ticket_email') ? ' s.ticket_email,' : ' NULL AS ticket_email,') . "
                   s.supplier_type_id, st.name AS supplier_type_name,
                   s.supplier_status_id, ss.name AS supplier_status_name,
                   s.address_line_1, s.address_line_2, s.city, s.county, s.postcode, s.country,
                   s.questionnaire_date_issued, s.questionnaire_date_received,
                   s.comments, s.is_active, s.created_datetime
            FROM suppliers s
            LEFT JOIN supplier_types st ON s.supplier_type_id = st.id
            LEFT JOIN supplier_statuses ss ON s.supplier_status_id = ss.id
            ORDER BY s.legal_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($suppliers as &$s) {
        $s['is_active'] = (bool)$s['is_active'];
    }

    echo json_encode(['success' => true, 'suppliers' => $suppliers]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
