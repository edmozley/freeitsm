<?php
/**
 * API Endpoint: Get software licences list
 * Returns all licences with application names and publisher info
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
    $conn = connectToDatabase();

    $sql = "SELECT
                l.id,
                l.app_id,
                a.display_name AS app_name,
                a.publisher AS app_publisher,
                l.licence_type,
                l.licence_key,
                l.quantity,
                CONVERT(VARCHAR(10), l.renewal_date, 23) AS renewal_date,
                l.notice_period_days,
                l.portal_url,
                l.cost,
                l.currency,
                CONVERT(VARCHAR(10), l.purchase_date, 23) AS purchase_date,
                l.vendor_contact,
                l.notes,
                l.status,
                l.created_by,
                an.full_name AS created_by_name,
                CONVERT(VARCHAR(19), l.created_at, 120) AS created_at,
                CONVERT(VARCHAR(19), l.updated_at, 120) AS updated_at
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
