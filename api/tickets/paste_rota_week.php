<?php
/**
 * API Endpoint: paste a copied week over another week.
 *
 * POST { week_start: 'YYYY-MM-DD', entries: [ { analyst_id, day_offset, shift_id, location_id, is_on_call } ] }
 *   -> { success, removed, written }
 *
 * ONE CALL, NOT THIRTY-FIVE. A week is up to seven days times however many
 * analysts you have, and firing save_rota_entry.php per cell would leave a
 * half-pasted week behind the first failure. This replaces the week inside a
 * transaction: either the week looks like the one you copied or it is untouched.
 *
 * 🔴 REPLACE MEANS REPLACE, AND THE SCOPE IS WHAT YOU CAN SEE. Pasting a week
 * has to leave the target looking like the source, which means clearing target
 * entries the source did not have — otherwise you get a hybrid week matching
 * neither, which is worse than either. But "clear the week" must never reach
 * beyond the grid the person was looking at:
 *
 *   - ONLY ACTIVE ANALYSTS. get_rota.php lists analysts WHERE is_active = 1, so
 *     a deactivated analyst's entries are already invisible on the page. A
 *     blanket delete would destroy rows the user had no way of knowing existed.
 *   - ONLY THE DAYS ON SCREEN. When rota_include_weekends is off the grid is
 *     Monday to Friday, and Saturday and Sunday entries — which do exist, and
 *     which the week view simply does not draw — must survive a paste aimed at
 *     the working week.
 *
 * Both are derived HERE rather than taken from the request: the client already
 * knows them, but a delete scoped by whatever the caller sent is a delete
 * scoped by whatever the caller sent.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['week_start'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$entries = is_array($input['entries'] ?? null) ? $input['entries'] : [];

try {
    $conn = connectToDatabase();

    // Normalise to the Monday, the same way get_rota.php does, so a date from
    // anywhere in the week lands on the same seven days the grid drew.
    $dt = new DateTime($input['week_start']);
    $dow = (int)$dt->format('N');
    $dt->modify('-' . ($dow - 1) . ' days');
    $weekStart = $dt->format('Y-m-d');

    $setting = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'rota_include_weekends'")->fetchColumn();
    $includeWeekends = $setting !== false ? (int)$setting : 0;
    $numDays = $includeWeekends ? 7 : 5;

    $lastDay = new DateTime($weekStart);
    $lastDay->modify('+' . ($numDays - 1) . ' days');
    $rangeEnd = $lastDay->format('Y-m-d');

    // The analysts on screen. Also the whitelist every incoming row is checked
    // against, so a request naming somebody else writes nothing.
    $activeIds = $conn->query("SELECT id FROM analysts WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    $activeIds = array_map('intval', $activeIds);
    if (!$activeIds) {
        echo json_encode(['success' => false, 'error' => 'No active analysts']);
        exit;
    }
    $activeSet = array_flip($activeIds);
    $inList = implode(',', $activeIds);

    // Shifts must exist. A paste carrying a shift deleted since the copy would
    // otherwise fail on the foreign key halfway through and roll the lot back
    // with nothing said about why.
    $validShifts = array_flip(array_map('intval',
        $conn->query("SELECT id FROM ticket_rota_shifts")->fetchAll(PDO::FETCH_COLUMN)));
    $validLocs = array_flip(array_map('intval',
        $conn->query("SELECT id FROM rota_locations")->fetchAll(PDO::FETCH_COLUMN)));

    $conn->beginTransaction();

    $del = $conn->prepare(
        "DELETE FROM ticket_rota_entries
          WHERE rota_date BETWEEN ? AND ?
            AND analyst_id IN ($inList)"
    );
    $del->execute([$weekStart, $rangeEnd]);
    $removed = $del->rowCount();

    $ins = $conn->prepare(
        "INSERT INTO ticket_rota_entries (analyst_id, rota_date, shift_id, location_id, is_on_call, created_datetime, updated_datetime)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id), location_id = VALUES(location_id),
             is_on_call = VALUES(is_on_call), updated_datetime = UTC_TIMESTAMP()"
    );

    $written = 0;
    $skipped = 0;
    foreach ($entries as $e) {
        $analystId = (int)($e['analyst_id'] ?? 0);
        $offset    = (int)($e['day_offset'] ?? -1);
        $shiftId   = (int)($e['shift_id'] ?? 0);

        // Silently dropping a row would make a paste quietly lose a shift, so
        // anything rejected here is counted and reported back.
        if (!isset($activeSet[$analystId]) || $offset < 0 || $offset >= $numDays || !isset($validShifts[$shiftId])) {
            $skipped++;
            continue;
        }

        $d = new DateTime($weekStart);
        $d->modify('+' . $offset . ' days');

        $locationId = isset($e['location_id']) && $e['location_id'] !== null ? (int)$e['location_id'] : null;
        if ($locationId !== null && !isset($validLocs[$locationId])) {
            $locationId = null;   // the location was retired since the copy
        }

        $ins->execute([
            $analystId,
            $d->format('Y-m-d'),
            $shiftId,
            $locationId,
            !empty($e['is_on_call']) ? 1 : 0,
        ]);
        $written++;
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'removed' => $removed,
        'written' => $written,
        'skipped' => $skipped,
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
