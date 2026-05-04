<?php
/**
 * API Endpoint: Save Calendar Event
 * Creates or updates an event
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$id = isset($input['id']) ? (int)$input['id'] : null;
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$categoryId = isset($input['category_id']) && $input['category_id'] !== '' ? (int)$input['category_id'] : null;
$startDatetime = $input['start_datetime'] ?? null;
$endDatetime = isset($input['end_datetime']) && $input['end_datetime'] !== '' ? $input['end_datetime'] : null;
$allDay = isset($input['all_day']) ? (bool)$input['all_day'] : false;
$location = trim($input['location'] ?? '');
$contractId = isset($input['contract_id']) && $input['contract_id'] !== '' ? (int)$input['contract_id'] : null;

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Event title is required']);
    exit;
}

if (empty($startDatetime)) {
    echo json_encode(['success' => false, 'error' => 'Start date/time is required']);
    exit;
}

// If end_datetime is not set, default to start_datetime
if (!$endDatetime) {
    $endDatetime = $startDatetime;
}

try {
    $conn = connectToDatabase();

    if ($id) {
        // Update existing event
        $sql = "UPDATE calendar_events
                SET title = ?, description = ?, category_id = ?, start_datetime = ?,
                    end_datetime = ?, all_day = ?, location = ?, contract_id = ?, updated_at = UTC_TIMESTAMP()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $title,
            $description,
            $categoryId,
            $startDatetime,
            $endDatetime,
            $allDay ? 1 : 0,
            $location,
            $contractId,
            $id
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Event updated',
            'id' => $id
        ]);
    } else {
        // Create new event
        $sql = "INSERT INTO calendar_events (title, description, category_id, start_datetime, end_datetime, all_day, location, contract_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $title,
            $description,
            $categoryId,
            $startDatetime,
            $endDatetime,
            $allDay ? 1 : 0,
            $location,
            $contractId,
            $_SESSION['analyst_id']
        ]);
        $newId = $conn->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Event created',
            'id' => $newId
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
