<?php
/**
 * API Endpoint: Create or update a rota shift
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_ROTA);   // settings tab — see docs/design/rbac.md

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$id = !empty($input['id']) ? (int)$input['id'] : null;
$name = trim($input['name'] ?? '');
$startTime = trim($input['start_time'] ?? '');
$endTime = trim($input['end_time'] ?? '');
$displayOrder = (int)($input['display_order'] ?? 0);
$isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Name is required']);
    exit;
}

if (empty($startTime) || empty($endTime)) {
    echo json_encode(['success' => false, 'error' => 'Start and end times are required']);
    exit;
}

try {
    $conn = connectToDatabase();

    if ($id) {
        $sql = "UPDATE ticket_rota_shifts
                SET name = ?, start_time = ?, end_time = ?, display_order = ?, is_active = ?
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $startTime, $endTime, $displayOrder, $isActive, $id]);
    } else {
        $sql = "INSERT INTO ticket_rota_shifts (name, start_time, end_time, display_order, is_active, created_datetime)
                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $startTime, $endTime, $displayOrder, $isActive]);
        $id = $conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
