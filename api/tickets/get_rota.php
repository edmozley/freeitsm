<?php
/**
 * API Endpoint: Get rota entries for a week
 * GET: ?week=YYYY-MM-DD (any date in the target week; defaults to current week)
 * Returns: analysts, shifts, entries for the Mon–Sun week containing the given date,
 *          plus the rota_include_weekends setting.
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

    // Determine Monday of the requested week
    $dateStr = $_GET['week'] ?? date('Y-m-d');
    $dt = new DateTime($dateStr);
    $dow = (int)$dt->format('N'); // 1=Mon .. 7=Sun
    $dt->modify('-' . ($dow - 1) . ' days'); // go to Monday
    $weekStart = $dt->format('Y-m-d');
    $dt->modify('+6 days');
    $weekEnd = $dt->format('Y-m-d');

    // Get include_weekends setting
    $settingSql = "SELECT setting_value FROM system_settings WHERE setting_key = 'rota_include_weekends'";
    $settingStmt = $conn->prepare($settingSql);
    $settingStmt->execute();
    $settingRow = $settingStmt->fetch(PDO::FETCH_ASSOC);
    $includeWeekends = $settingRow ? (int)$settingRow['setting_value'] : 0;

    // Get active analysts
    $analystSql = "SELECT id, full_name FROM analysts WHERE is_active = 1 ORDER BY full_name";
    $analystStmt = $conn->prepare($analystSql);
    $analystStmt->execute();
    $analysts = $analystStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get active shifts
    $shiftSql = "SELECT id, name, start_time, end_time FROM ticket_rota_shifts WHERE is_active = 1 ORDER BY display_order, id";
    $shiftStmt = $conn->prepare($shiftSql);
    $shiftStmt->execute();
    $shifts = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get active rota locations (used by the entry-edit modal radios)
    $locSql = "SELECT id, name, colour, is_default, display_order
               FROM rota_locations
               WHERE is_active = 1
               ORDER BY display_order, name";
    $locStmt = $conn->prepare($locSql);
    $locStmt->execute();
    $locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($locations as &$loc) {
        $loc['is_default'] = (bool)$loc['is_default'];
    }
    unset($loc);

    // Get rota entries for the week
    $entrySql = "SELECT e.id, e.analyst_id, e.rota_date, e.shift_id, e.location_id, e.is_on_call,
                        s.name as shift_name, s.start_time, s.end_time,
                        l.name as location_name, l.colour as location_colour
                 FROM ticket_rota_entries e
                 INNER JOIN ticket_rota_shifts s ON s.id = e.shift_id
                 LEFT  JOIN rota_locations l    ON l.id = e.location_id
                 WHERE e.rota_date BETWEEN ? AND ?
                 ORDER BY e.rota_date, e.analyst_id";
    $entryStmt = $conn->prepare($entrySql);
    $entryStmt->execute([$weekStart, $weekEnd]);
    $entries = $entryStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
        'include_weekends' => $includeWeekends,
        'analysts' => $analysts,
        'shifts' => $shifts,
        'locations' => $locations,
        'entries' => $entries
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
