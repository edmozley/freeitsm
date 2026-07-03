<?php
/**
 * API: Delete an incident
 * POST - JSON body: { id }
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
    $id = $data['id'] ?? null;

    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();

    // Junction cleanup + delete atomically — a mid-way failure would
    // otherwise strand the affected-service rows without their incident.
    $conn->beginTransaction();
    try {
        $conn->prepare("DELETE FROM status_incident_services WHERE incident_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM status_incidents WHERE id = ?")->execute([$id]);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
