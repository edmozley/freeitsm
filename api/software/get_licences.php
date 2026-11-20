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
requireModuleAccessJson('software');

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
            LEFT JOIN analysts an ON l.created_by = an.id";

    // Watchtower's Software card links here (discussion #388): ?expiring=N is an
    // Active licence renewing between today and today + N days, ?notice=N one whose
    // notice period STARTS in that window (renewal_date minus notice_period_days -
    // the notice date is not stored). The SAME conditions as the card in
    // includes/watchtower_queries.php, so the list shows what the card counted.
    require_once '../../includes/timezone.php';
    $today = naive_today_sql();
    $where = [];
    $days = (int)($_GET['expiring'] ?? 0);
    if ($days >= 1 && $days <= 366) {
        $where[] = "l.status = 'Active' AND l.renewal_date IS NOT NULL
                    AND l.renewal_date BETWEEN {$today} AND DATE_ADD({$today}, INTERVAL $days DAY)";
    }
    $days = (int)($_GET['notice'] ?? 0);
    if ($days >= 1 && $days <= 366) {
        $where[] = "l.status = 'Active' AND l.renewal_date IS NOT NULL
                    AND l.notice_period_days IS NOT NULL AND l.notice_period_days > 0
                    AND DATE_SUB(l.renewal_date, INTERVAL l.notice_period_days DAY)
                        BETWEEN {$today} AND DATE_ADD({$today}, INTERVAL $days DAY)";
    }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY a.display_name ASC";

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
