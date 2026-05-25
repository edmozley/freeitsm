<?php
/**
 * API: Load a single calendar with its hours + holidays (for the edit modal).
 *
 * GET ?id=N
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
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) throw new Exception('Calendar id required');

    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT id, name, timezone, is_default, is_active FROM sla_calendars WHERE id = ?");
    $stmt->execute([$id]);
    $calendar = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$calendar) throw new Exception('Calendar not found');
    $calendar['is_default'] = (bool)$calendar['is_default'];

    $hStmt = $conn->prepare("SELECT weekday, start_time, end_time FROM sla_calendar_hours WHERE calendar_id = ? ORDER BY weekday");
    $hStmt->execute([$id]);
    $calendar['hours'] = array_map(function($h) {
        return [
            'weekday'    => (int)$h['weekday'],
            'start_time' => substr($h['start_time'], 0, 5),
            'end_time'   => substr($h['end_time'], 0, 5),
        ];
    }, $hStmt->fetchAll(PDO::FETCH_ASSOC));

    $holStmt = $conn->prepare("SELECT holiday_date, name FROM sla_calendar_holidays WHERE calendar_id = ? ORDER BY holiday_date");
    $holStmt->execute([$id]);
    $calendar['holidays'] = $holStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'calendar' => $calendar]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
