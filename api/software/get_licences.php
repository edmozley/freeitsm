<?php
/**
 * API Endpoint: Get software licences list
 * Returns all licences with application names and publisher info
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

    $sql = "SELECT
                l.id,
                l.app_id,
                a.display_name AS app_name,
                a.publisher AS app_publisher,
                l.licence_type,
                l.licence_key,
                l.quantity,
                DATE_FORMAT(l.renewal_date, '%Y-%m-%d') AS renewal_date,
                l.notice_period_days,
                l.portal_url,
                l.cost,
                l.currency,
                DATE_FORMAT(l.purchase_date, '%Y-%m-%d') AS purchase_date,
                l.vendor_contact,
                l.notes,
                l.status,
                l.created_by,
                an.full_name AS created_by_name,
                DATE_FORMAT(l.created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
                DATE_FORMAT(l.updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
            FROM software_licences l
            INNER JOIN software_inventory_apps a ON l.app_id = a.id
            LEFT JOIN analysts an ON l.created_by = an.id
            ORDER BY a.display_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $licences = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'licences' => $licences
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
