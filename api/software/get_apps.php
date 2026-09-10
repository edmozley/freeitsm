<?php
/**
 * API Endpoint: Get software applications list
 * Returns all applications with publisher and install counts
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

    // Optional filter: 'apps' = user-visible only, 'components' = system components only, '' = all
    $filter = $_GET['filter'] ?? '';

    $where = '';
    $params = [];
    if ($filter === 'apps') {
        $where = 'HAVING MAX(d.system_component) = 0 OR MAX(d.system_component) IS NULL';
    } elseif ($filter === 'components') {
        $where = 'HAVING MAX(d.system_component) = 1';
    }

    // ⚠️ `seats` is a SEPARATE NUMBER FROM `install_count`, not a fallback (#1549).
    //
    // A cloud platform is never installed on anything, so its install_count is
    // always 0 — and 0 on screen reads as "nobody uses this", which is the
    // opposite of true for the SaaS somebody has just paid for 40 seats of.
    // The seat count comes off the licences instead, and the two are reported
    // side by side so a reader can tell "not installed anywhere" from
    // "not licensed for anyone".
    //
    // The licence sum is a correlated subquery rather than a second LEFT JOIN:
    // joining two one-to-many tables in one GROUP BY multiplies the rows against
    // each other, and install_count would then be the count of hosts times the
    // number of licences. Exactly the double-counting trap that a many-to-many
    // ticket category would have been.
    $sql = "SELECT
                a.id,
                a.display_name,
                a.publisher,
                a.source,
                a.app_url,
                a.notes,
                COUNT(DISTINCT d.host_id) as install_count,
                (SELECT COALESCE(SUM(l.quantity), 0) FROM software_licences l
                  WHERE l.app_id = a.id AND l.status = 'Active') as seats,
                MAX(d.system_component) as system_component
            FROM software_inventory_apps a
            LEFT JOIN software_inventory_detail d ON d.app_id = a.id
            GROUP BY a.id, a.display_name, a.publisher, a.source, a.app_url, a.notes
            $where
            ORDER BY a.display_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'apps' => $apps
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
