<?php
/**
 * API: Get all data for the SLA settings tab in one round-trip.
 *
 * Returns:
 *   settings:    { sla_enforce_from, sla_priority_change_behaviour, ... }
 *   priorities:  [{ id, name, sla_response_minutes, sla_resolution_minutes, sla_calendar_id }]
 *   calendars:   [{ id, name, timezone, is_default, hours: [...], holiday_count }]
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

    // Pull the 7 SLA system_settings rows
    $settingKeys = [
        'sla_enforce_from',
        'sla_priority_change_behaviour',
        'sla_reopen_behaviour',
        'sla_warning_threshold_percent',
        'sla_notify_assignee_at_warning',
        'sla_notify_lead_at_breach',
        'sla_first_response_definition',
    ];
    $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($settingKeys);
    $settings = [];
    foreach ($settingKeys as $k) $settings[$k] = null; // default to null
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    // Priorities with their SLA fields
    $stmt = $conn->query("SELECT id, name, colour, display_order, sla_response_minutes, sla_resolution_minutes, sla_calendar_id
                          FROM ticket_priorities WHERE is_active = 1 ORDER BY display_order, id");
    $priorities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calendars with their hours + holiday count
    $stmt = $conn->query("SELECT id, name, timezone, is_default, is_active FROM sla_calendars WHERE is_active = 1 ORDER BY is_default DESC, name");
    $calendars = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($calendars)) {
        $calIds = array_column($calendars, 'id');
        $ph = implode(',', array_fill(0, count($calIds), '?'));

        $hStmt = $conn->prepare("SELECT calendar_id, weekday, start_time, end_time FROM sla_calendar_hours WHERE calendar_id IN ($ph) ORDER BY weekday");
        $hStmt->execute($calIds);
        $hoursByCal = [];
        foreach ($hStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $hoursByCal[$h['calendar_id']][] = ['weekday' => (int)$h['weekday'], 'start_time' => substr($h['start_time'], 0, 5), 'end_time' => substr($h['end_time'], 0, 5)];
        }

        $cStmt = $conn->prepare("SELECT calendar_id, COUNT(*) AS cnt FROM sla_calendar_holidays WHERE calendar_id IN ($ph) GROUP BY calendar_id");
        $cStmt->execute($calIds);
        $countsByCal = [];
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $countsByCal[$c['calendar_id']] = (int)$c['cnt'];
        }

        foreach ($calendars as &$cal) {
            $cal['hours'] = $hoursByCal[$cal['id']] ?? [];
            $cal['holiday_count'] = $countsByCal[$cal['id']] ?? 0;
            $cal['is_default'] = (bool)$cal['is_default'];
        }
    }

    echo json_encode([
        'success'    => true,
        'settings'   => $settings,
        'priorities' => $priorities,
        'calendars'  => $calendars,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
