<?php
/**
 * API Endpoint: Get All Morning Checks
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/morning_check_schedule.php';   // GH #383

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('morning-checks');

try {
    $conn = connectToDatabase();

    // GroupID/AssignedAnalystID (discussion #64) are needed by the settings edit
    // modal to pre-select the pickers. Without them the modal would open showing
    // "no group" for a grouped check and quietly clear the grouping on save.
    $sql = "SELECT CheckID, CheckName, CheckDescription, IsActive, SortOrder,
                   GroupID, AssignedAnalystID, CreatedDate, ModifiedDate,
                   ScheduleType, ScheduleDays, ScheduleMonthWhen, ScheduleMonthDay
            FROM morningChecks_Checks
            ORDER BY SortOrder, CheckName";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert types for JS
    foreach ($checks as &$check) {
        $check['CheckID'] = (int)$check['CheckID'];
        $check['IsActive'] = (bool)$check['IsActive'];
        $check['SortOrder'] = (int)$check['SortOrder'];
        // Cast only when set — null must stay null so the picker shows "no group"
        // rather than selecting whichever option happens to have id 0.
        $check['GroupID'] = $check['GroupID'] !== null ? (int)$check['GroupID'] : null;
        $check['AssignedAnalystID'] = $check['AssignedAnalystID'] !== null ? (int)$check['AssignedAnalystID'] : null;
        // GH #383: the schedule as the edit modal fills it in.
        $check['Schedule'] = MorningCheckSchedule::toArray($check);
        unset($check['ScheduleType'], $check['ScheduleDays'], $check['ScheduleMonthWhen'], $check['ScheduleMonthDay']);
    }

    echo json_encode($checks);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
