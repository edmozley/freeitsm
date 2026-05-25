<?php
/**
 * API: Create or update a business calendar including its weekly hours and
 * holiday list, all in one transaction.
 *
 * POST JSON: {
 *   id?:           int (omit to create)
 *   name:          string (required)
 *   timezone:      string (IANA zone, required, must validate)
 *   is_default:    bool (only one calendar can be default; setting this clears others)
 *   hours:         [{ weekday: 1-7, start_time: 'HH:MM', end_time: 'HH:MM' }]   — absence of a weekday = closed
 *   holidays:      [{ holiday_date: 'YYYY-MM-DD', name?: string }]
 * }
 *
 * Hours and holidays are replaced wholesale (delete-then-insert in a transaction)
 * so the caller sends the complete desired state, not deltas.
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
    $data = json_decode(file_get_contents('php://input'), true);

    $id        = isset($data['id']) ? (int)$data['id'] : null;
    $name      = trim((string)($data['name'] ?? ''));
    $timezone  = trim((string)($data['timezone'] ?? ''));
    $isDefault = !empty($data['is_default']) ? 1 : 0;
    $hours     = is_array($data['hours'] ?? null) ? $data['hours'] : [];
    $holidays  = is_array($data['holidays'] ?? null) ? $data['holidays'] : [];

    if ($name === '') throw new Exception('Name is required');
    if ($timezone === '') throw new Exception('Timezone is required');

    // Validate timezone is real before we hit the DB
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        throw new Exception('Unknown timezone: ' . $timezone);
    }

    // Validate hours rows
    $cleanHours = [];
    $seenWeekdays = [];
    foreach ($hours as $h) {
        $wd = (int)($h['weekday'] ?? 0);
        if ($wd < 1 || $wd > 7) throw new Exception('Invalid weekday');
        if (isset($seenWeekdays[$wd])) throw new Exception('Duplicate weekday in hours');
        $seenWeekdays[$wd] = true;

        $start = trim((string)($h['start_time'] ?? ''));
        $end   = trim((string)($h['end_time'] ?? ''));
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $end)) {
            throw new Exception('Invalid time format (use HH:MM)');
        }
        if (strtotime($start) >= strtotime($end)) throw new Exception('End time must be after start time');
        // Normalise to HH:MM:00
        if (strlen($start) === 5) $start .= ':00';
        if (strlen($end)   === 5) $end   .= ':00';
        $cleanHours[] = ['weekday' => $wd, 'start' => $start, 'end' => $end];
    }

    // Validate holidays
    $cleanHolidays = [];
    $seenDates = [];
    foreach ($holidays as $h) {
        $d = trim((string)($h['holiday_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new Exception('Invalid holiday date');
        if (isset($seenDates[$d])) continue; // silently dedupe
        $seenDates[$d] = true;
        $cleanHolidays[] = ['date' => $d, 'name' => trim((string)($h['name'] ?? ''))];
    }

    $conn = connectToDatabase();
    $conn->beginTransaction();

    // If this is being set as default, clear default from others first
    if ($isDefault) {
        $conn->exec("UPDATE sla_calendars SET is_default = 0");
    }

    if ($id) {
        $stmt = $conn->prepare("UPDATE sla_calendars SET name = ?, timezone = ?, is_default = ? WHERE id = ?");
        $stmt->execute([$name, $timezone, $isDefault, $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO sla_calendars (name, timezone, is_default) VALUES (?, ?, ?)");
        $stmt->execute([$name, $timezone, $isDefault]);
        $id = (int)$conn->lastInsertId();
    }

    // Replace hours wholesale
    $conn->prepare("DELETE FROM sla_calendar_hours WHERE calendar_id = ?")->execute([$id]);
    if (!empty($cleanHours)) {
        $hStmt = $conn->prepare("INSERT INTO sla_calendar_hours (calendar_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?)");
        foreach ($cleanHours as $h) {
            $hStmt->execute([$id, $h['weekday'], $h['start'], $h['end']]);
        }
    }

    // Replace holidays wholesale
    $conn->prepare("DELETE FROM sla_calendar_holidays WHERE calendar_id = ?")->execute([$id]);
    if (!empty($cleanHolidays)) {
        $holStmt = $conn->prepare("INSERT INTO sla_calendar_holidays (calendar_id, holiday_date, name) VALUES (?, ?, ?)");
        foreach ($cleanHolidays as $h) {
            $holStmt->execute([$id, $h['date'], $h['name'] !== '' ? $h['name'] : null]);
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $id]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
