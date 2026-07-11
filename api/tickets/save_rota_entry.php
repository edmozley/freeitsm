<?php
/**
 * API Endpoint: Create or update a rota entry
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

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$id = !empty($input['id']) ? (int)$input['id'] : null;
$analystId = !empty($input['analyst_id']) ? (int)$input['analyst_id'] : null;
$rotaDate = trim($input['rota_date'] ?? '');
$shiftId = !empty($input['shift_id']) ? (int)$input['shift_id'] : null;
$locationId = !empty($input['location_id']) ? (int)$input['location_id'] : null;
$isOnCall = isset($input['is_on_call']) ? (int)$input['is_on_call'] : 0;

if (!$analystId || empty($rotaDate) || !$shiftId) {
    echo json_encode(['success' => false, 'error' => 'Analyst, date, and shift are required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Default to the configured default location if none was supplied
    if ($locationId === null) {
        $defStmt = $conn->query("SELECT id FROM rota_locations WHERE is_default = 1 LIMIT 1");
        $locationId = (int)$defStmt->fetchColumn() ?: null;
    }

    if ($id) {
        $sql = "UPDATE ticket_rota_entries
                SET shift_id = ?, location_id = ?, is_on_call = ?, updated_datetime = UTC_TIMESTAMP()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$shiftId, $locationId, $isOnCall, $id]);
    } else {
        // Use INSERT ... ON DUPLICATE KEY UPDATE for the unique analyst+date constraint
        $sql = "INSERT INTO ticket_rota_entries (analyst_id, rota_date, shift_id, location_id, is_on_call, created_datetime, updated_datetime)
                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id), location_id = VALUES(location_id),
                    is_on_call = VALUES(is_on_call), updated_datetime = UTC_TIMESTAMP()";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$analystId, $rotaDate, $shiftId, $locationId, $isOnCall]);
        $id = $conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
